#include "accumulated_map_runtime.hpp"

#include <algorithm>
#include <array>
#include <chrono>
#include <cmath>
#include <condition_variable>
#include <cstdint>
#include <deque>
#include <fstream>
#include <iomanip>
#include <limits>
#include <mutex>
#include <numbers>
#include <optional>
#include <sstream>
#include <stdexcept>
#include <string>
#include <system_error>
#include <thread>
#include <unordered_map>
#include <utility>
#include <vector>

#include <opencv2/calib3d.hpp>
#include <opencv2/features2d.hpp>
#include <opencv2/imgproc.hpp>

namespace maklertour::dual_phone::detail {

namespace {

constexpr std::chrono::milliseconds kMinimumSubmissionInterval{300};
constexpr std::chrono::milliseconds kMinimumPublishInterval{1000};
constexpr double kNearMeters = 0.45;
constexpr double kFarMeters = 8.0;
constexpr double kVoxelMeters = 0.03;
constexpr std::size_t kMaximumVoxels = 500000;
constexpr std::size_t kMaximumRegistrationKeyframes = 28;
constexpr std::size_t kRelocalizationCandidates = 8;
constexpr int kOrbFeatures = 1700;
constexpr int kMinimumFeatures = 90;
constexpr int kMinimumCorrespondences = 24;
constexpr int kMinimumPnPInliers = 18;
constexpr int kMinimumRotationInliers = 32;
constexpr int kMinimumDepthPairs = 12;
constexpr double kMinimumInlierRatio = 0.32;
constexpr double kMinimumRotationInlierRatio = 0.36;
constexpr double kMaximumReprojectionRmse = 4.0;
constexpr double kMaximumRotationReprojectionRmse = 3.5;
constexpr double kMaximumSparseDepthMedianMeters = 0.50;
constexpr double kMinimumKeyframeTranslationMeters = 0.06;
constexpr double kMinimumKeyframeRotationDegrees = 4.0;
constexpr double kMaximumContinuousTranslationMeters = 0.65;
constexpr double kMaximumContinuousRotationDegrees = 30.0;
constexpr double kMaximumRelocalizedTranslationMeters = 0.40;
constexpr double kMaximumRelocalizedRotationDegrees = 22.0;
constexpr double kPureRotationTranslationMeters = 0.18;
constexpr double kPureRotationMinimumDegrees = 1.25;
constexpr double kRelocalizationRollbackDegrees = 6.0;

std::int64_t unix_time_ms() {
    return std::chrono::duration_cast<std::chrono::milliseconds>(
        std::chrono::system_clock::now().time_since_epoch()).count();
}

struct MapJob {
    std::uint64_t generation = 0;
    std::uint64_t pair_index = 0;
    std::string source_profile;
    cv::Mat colour;
    cv::Mat disparity;
    cv::Mat mask;
    double focal_px = 0.0;
    double baseline_mm = 0.0;
    double principal_x_px = 0.0;
    double principal_y_px = 0.0;
};

struct TrackingFrame {
    cv::Mat gray;
    cv::Mat disparity;
    cv::Mat mask;
    std::vector<cv::KeyPoint> keypoints;
    cv::Mat descriptors;
    double focal_px = 0.0;
    double baseline_mm = 0.0;
    double principal_x_px = 0.0;
    double principal_y_px = 0.0;
};

struct Keyframe {
    std::uint64_t id = 0;
    std::uint64_t pair_index = 0;
    TrackingFrame frame;
    cv::Matx44d world_from_camera = cv::Matx44d::eye();
};

struct MatchSet {
    int ratio_matches = 0;
    std::vector<cv::Point2f> reference_pixels;
    std::vector<cv::Point2f> current_pixels;
    std::vector<cv::Point3f> pnp_object_points;
    std::vector<cv::Point2f> pnp_image_points;
    std::vector<std::pair<cv::Point3f, cv::Point3f>> depth_pairs;
};

struct PoseEstimate {
    bool valid = false;
    bool relocalized = false;
    bool rotation_only = false;
    std::string method = "NONE";
    std::uint64_t reference_keyframe_id = 0;
    cv::Matx44d camera_from_reference_cv = cv::Matx44d::eye();
    cv::Matx44d world_from_camera = cv::Matx44d::eye();
    int matches = 0;
    int correspondences = 0;
    int inliers = 0;
    double inlier_ratio = 0.0;
    double reprojection_rmse = std::numeric_limits<double>::infinity();
    double sparse_depth_median_m = std::numeric_limits<double>::infinity();
};

struct PoseSelection {
    PoseEstimate estimate;
    bool had_candidate = false;
    std::string rejection_reason;
    double translation_m = 0.0;
    double rotation_deg = 0.0;
    double yaw_step_deg = 0.0;
};

struct TrajectorySample {
    std::uint64_t keyframe_id = 0;
    std::uint64_t pair_index = 0;
    std::string state;
    std::string method;
    bool rotation_only = false;
    cv::Matx44d world_from_camera = cv::Matx44d::eye();
    int matches = 0;
    int inliers = 0;
    double inlier_ratio = 0.0;
    double reprojection_rmse = 0.0;
    double sparse_depth_median_m = 0.0;
    double translation_m = 0.0;
    double rotation_deg = 0.0;
    double yaw_step_deg = 0.0;
    double accumulated_yaw_deg = 0.0;
    std::int64_t timestamp_ms = 0;
};

struct VoxelKey {
    int x = 0;
    int y = 0;
    int z = 0;

    bool operator==(const VoxelKey& other) const noexcept {
        return x == other.x && y == other.y && z == other.z;
    }
};

struct VoxelKeyHash {
    std::size_t operator()(const VoxelKey& value) const noexcept {
        const auto mix = [](std::size_t seed, const std::size_t part) {
            return seed ^ (part + 0x9e3779b97f4a7c15ULL + (seed << 6U) +
                           (seed >> 2U));
        };
        std::size_t seed = 0;
        seed = mix(seed, std::hash<int>{}(value.x));
        seed = mix(seed, std::hash<int>{}(value.y));
        seed = mix(seed, std::hash<int>{}(value.z));
        return seed;
    }
};

struct VoxelAccumulator {
    cv::Vec3d position_sum{0.0, 0.0, 0.0};
    cv::Vec3d colour_sum{0.0, 0.0, 0.0};
    std::uint32_t observations = 0;
    std::uint64_t last_keyframe_id = 0;
};

void write_text_atomic(
    const std::filesystem::path& destination,
    const std::string& contents) {
    auto temporary = destination;
    temporary += ".tmp";
    {
        std::ofstream output(temporary, std::ios::binary | std::ios::trunc);
        if (!output) {
            throw std::runtime_error("cannot write " + temporary.string());
        }
        output << contents;
        output.flush();
        if (!output) {
            throw std::runtime_error("cannot finish " + temporary.string());
        }
    }
    std::error_code error;
    std::filesystem::rename(temporary, destination, error);
    if (!error) return;
    std::filesystem::remove(destination, error);
    error.clear();
    std::filesystem::rename(temporary, destination, error);
    if (error) {
        std::filesystem::remove(temporary);
        throw std::runtime_error(
            "cannot publish " + destination.string() + ": " +
            error.message());
    }
}

cv::Matx44d rigid_inverse(const cv::Matx44d& value) {
    cv::Matx33d rotation(
        value(0, 0), value(0, 1), value(0, 2),
        value(1, 0), value(1, 1), value(1, 2),
        value(2, 0), value(2, 1), value(2, 2));
    const auto transposed = rotation.t();
    const cv::Vec3d translation{value(0, 3), value(1, 3), value(2, 3)};
    const auto inverse_translation = -(transposed * translation);
    return {
        transposed(0, 0), transposed(0, 1), transposed(0, 2),
            inverse_translation[0],
        transposed(1, 0), transposed(1, 1), transposed(1, 2),
            inverse_translation[1],
        transposed(2, 0), transposed(2, 1), transposed(2, 2),
            inverse_translation[2],
        0.0, 0.0, 0.0, 1.0,
    };
}

cv::Matx44d cv_to_y_up_transform(const cv::Matx44d& value) {
    const cv::Matx44d flip(
        1.0, 0.0, 0.0, 0.0,
        0.0, -1.0, 0.0, 0.0,
        0.0, 0.0, 1.0, 0.0,
        0.0, 0.0, 0.0, 1.0);
    return flip * value * flip;
}

cv::Vec3d transform_point(
    const cv::Matx44d& transform,
    const cv::Vec3d& point) {
    const cv::Vec4d homogeneous{point[0], point[1], point[2], 1.0};
    const auto transformed = transform * homogeneous;
    return {transformed[0], transformed[1], transformed[2]};
}

std::pair<double, double> pose_delta(
    const cv::Matx44d& previous,
    const cv::Matx44d& current) {
    const auto relative = rigid_inverse(previous) * current;
    const cv::Vec3d translation{
        relative(0, 3), relative(1, 3), relative(2, 3)};
    const double translation_m = std::sqrt(translation.dot(translation));
    const double trace =
        relative(0, 0) + relative(1, 1) + relative(2, 2);
    const double cosine = std::clamp((trace - 1.0) * 0.5, -1.0, 1.0);
    const double rotation_deg =
        std::acos(cosine) * 180.0 / std::numbers::pi;
    return {translation_m, rotation_deg};
}

double signed_yaw_delta_deg(
    const cv::Matx44d& previous,
    const cv::Matx44d& current) {
    const auto relative = rigid_inverse(previous) * current;
    return std::atan2(relative(0, 2), relative(2, 2)) *
           180.0 / std::numbers::pi;
}

double pose_yaw_deg(const cv::Matx44d& pose) {
    return std::atan2(pose(0, 2), pose(2, 2)) *
           180.0 / std::numbers::pi;
}

nlohmann::json pose_json(const cv::Matx44d& pose) {
    nlohmann::json rows = nlohmann::json::array();
    for (int row = 0; row < 4; ++row) {
        nlohmann::json values = nlohmann::json::array();
        for (int column = 0; column < 4; ++column) {
            values.push_back(pose(row, column));
        }
        rows.push_back(std::move(values));
    }
    return rows;
}

std::optional<cv::Point3f> point_from_disparity_cv(
    const TrackingFrame& frame,
    const cv::Point2f& pixel) {
    const int column = static_cast<int>(std::lround(pixel.x));
    const int row = static_cast<int>(std::lround(pixel.y));
    if (column < 0 || row < 0 ||
        column >= frame.disparity.cols || row >= frame.disparity.rows) {
        return std::nullopt;
    }
    if (frame.mask.at<std::uint8_t>(row, column) == 0) {
        return std::nullopt;
    }
    const double disparity =
        static_cast<double>(frame.disparity.at<float>(row, column));
    if (!std::isfinite(disparity) || disparity <= 1.0) {
        return std::nullopt;
    }
    const double z =
        frame.focal_px * frame.baseline_mm / disparity / 1000.0;
    if (!std::isfinite(z) || z < kNearMeters || z > kFarMeters) {
        return std::nullopt;
    }
    const double x =
        (static_cast<double>(column) - frame.principal_x_px) * z /
        frame.focal_px;
    const double y_down =
        (static_cast<double>(row) - frame.principal_y_px) * z /
        frame.focal_px;
    return cv::Point3f{
        static_cast<float>(x),
        static_cast<float>(y_down),
        static_cast<float>(z),
    };
}

TrackingFrame make_tracking_frame(const MapJob& job) {
    if (job.colour.empty() || job.disparity.empty() || job.mask.empty() ||
        job.colour.size() != job.disparity.size() ||
        job.colour.size() != job.mask.size() ||
        job.disparity.type() != CV_32F || job.mask.type() != CV_8U) {
        throw std::runtime_error(
            "accumulated map requires aligned colour, disparity and mask");
    }
    if (!std::isfinite(job.focal_px) || job.focal_px <= 1.0 ||
        !std::isfinite(job.baseline_mm) || job.baseline_mm <= 0.0) {
        throw std::runtime_error(
            "accumulated map requires finite focal and baseline");
    }

    TrackingFrame result;
    cv::cvtColor(job.colour, result.gray, cv::COLOR_BGR2GRAY);
    auto orb = cv::ORB::create(
        kOrbFeatures,
        1.2F,
        8,
        31,
        0,
        2,
        cv::ORB::HARRIS_SCORE,
        31,
        12);
    orb->detectAndCompute(
        result.gray,
        job.mask,
        result.keypoints,
        result.descriptors);
    result.disparity = job.disparity;
    result.mask = job.mask;
    result.focal_px = job.focal_px;
    result.baseline_mm = job.baseline_mm;
    result.principal_x_px = job.principal_x_px;
    result.principal_y_px = job.principal_y_px;
    return result;
}

MatchSet collect_matches(
    const TrackingFrame& reference,
    const TrackingFrame& current) {
    MatchSet result;
    if (reference.descriptors.empty() || current.descriptors.empty()) {
        return result;
    }
    cv::BFMatcher matcher(cv::NORM_HAMMING, false);
    std::vector<std::vector<cv::DMatch>> neighbours;
    matcher.knnMatch(reference.descriptors, current.descriptors, neighbours, 2);
    result.reference_pixels.reserve(neighbours.size());
    result.current_pixels.reserve(neighbours.size());
    result.pnp_object_points.reserve(neighbours.size());
    result.pnp_image_points.reserve(neighbours.size());
    result.depth_pairs.reserve(neighbours.size());
    for (const auto& pair : neighbours) {
        if (pair.size() < 2) continue;
        if (pair[0].distance >= 0.75F * pair[1].distance) continue;
        const auto query_index = static_cast<std::size_t>(pair[0].queryIdx);
        const auto train_index = static_cast<std::size_t>(pair[0].trainIdx);
        if (query_index >= reference.keypoints.size() ||
            train_index >= current.keypoints.size()) {
            continue;
        }
        ++result.ratio_matches;
        const auto reference_pixel = reference.keypoints[query_index].pt;
        const auto current_pixel = current.keypoints[train_index].pt;
        result.reference_pixels.push_back(reference_pixel);
        result.current_pixels.push_back(current_pixel);
        const auto reference_point =
            point_from_disparity_cv(reference, reference_pixel);
        if (reference_point) {
            result.pnp_object_points.push_back(*reference_point);
            result.pnp_image_points.push_back(current_pixel);
            const auto current_point =
                point_from_disparity_cv(current, current_pixel);
            if (current_point) {
                result.depth_pairs.emplace_back(
                    *reference_point,
                    *current_point);
            }
        }
    }
    return result;
}

cv::Mat camera_matrix(const TrackingFrame& frame) {
    return (cv::Mat_<double>(3, 3) <<
        frame.focal_px, 0.0, frame.principal_x_px,
        0.0, frame.focal_px, frame.principal_y_px,
        0.0, 0.0, 1.0);
}

cv::Matx44d matx44_from_rt(
    const cv::Mat& rotation,
    const cv::Vec3d& translation) {
    return {
        rotation.at<double>(0, 0), rotation.at<double>(0, 1),
        rotation.at<double>(0, 2), translation[0],
        rotation.at<double>(1, 0), rotation.at<double>(1, 1),
        rotation.at<double>(1, 2), translation[1],
        rotation.at<double>(2, 0), rotation.at<double>(2, 1),
        rotation.at<double>(2, 2), translation[2],
        0.0, 0.0, 0.0, 1.0,
    };
}

double sparse_depth_median(
    const cv::Matx44d& camera_from_reference_cv,
    const std::vector<std::pair<cv::Point3f, cv::Point3f>>& pairs) {
    std::vector<double> residuals;
    residuals.reserve(pairs.size());
    for (const auto& [reference, current] : pairs) {
        const cv::Vec4d reference_h{
            reference.x, reference.y, reference.z, 1.0};
        const auto predicted = camera_from_reference_cv * reference_h;
        const cv::Vec3d difference{
            predicted[0] - static_cast<double>(current.x),
            predicted[1] - static_cast<double>(current.y),
            predicted[2] - static_cast<double>(current.z),
        };
        const double distance = std::sqrt(difference.dot(difference));
        if (std::isfinite(distance) && distance < 1.5) {
            residuals.push_back(distance);
        }
    }
    if (residuals.size() < static_cast<std::size_t>(kMinimumDepthPairs)) {
        return std::numeric_limits<double>::infinity();
    }
    const auto middle = residuals.begin() +
        static_cast<std::ptrdiff_t>(residuals.size() / 2);
    std::nth_element(residuals.begin(), middle, residuals.end());
    return *middle;
}

void refine_translation_from_depth(
    PoseEstimate& estimate,
    const std::vector<std::pair<cv::Point3f, cv::Point3f>>& pairs) {
    if (estimate.rotation_only ||
        pairs.size() < static_cast<std::size_t>(kMinimumDepthPairs)) {
        return;
    }
    std::array<std::vector<double>, 3> residuals;
    for (auto& axis : residuals) axis.reserve(pairs.size());
    for (const auto& [reference, current] : pairs) {
        const cv::Vec4d reference_h{
            reference.x, reference.y, reference.z, 1.0};
        const auto predicted = estimate.camera_from_reference_cv * reference_h;
        const cv::Vec3d delta{
            static_cast<double>(current.x) - predicted[0],
            static_cast<double>(current.y) - predicted[1],
            static_cast<double>(current.z) - predicted[2],
        };
        if (std::sqrt(delta.dot(delta)) > 1.0) continue;
        for (int axis = 0; axis < 3; ++axis) {
            residuals[static_cast<std::size_t>(axis)].push_back(delta[axis]);
        }
    }
    cv::Vec3d correction{0.0, 0.0, 0.0};
    for (int axis = 0; axis < 3; ++axis) {
        auto& values = residuals[static_cast<std::size_t>(axis)];
        if (values.size() < static_cast<std::size_t>(kMinimumDepthPairs)) {
            return;
        }
        const auto middle = values.begin() +
            static_cast<std::ptrdiff_t>(values.size() / 2);
        std::nth_element(values.begin(), middle, values.end());
        correction[axis] = std::clamp(*middle * 0.35, -0.08, 0.08);
    }
    estimate.camera_from_reference_cv(0, 3) += correction[0];
    estimate.camera_from_reference_cv(1, 3) += correction[1];
    estimate.camera_from_reference_cv(2, 3) += correction[2];
}

PoseEstimate estimate_pnp(
    const Keyframe& reference,
    const TrackingFrame& current,
    const MatchSet& matches) {
    PoseEstimate estimate;
    estimate.reference_keyframe_id = reference.id;
    estimate.method = "PNP_DEPTH";
    estimate.matches = matches.ratio_matches;
    estimate.correspondences =
        static_cast<int>(matches.pnp_object_points.size());
    if (estimate.correspondences < kMinimumCorrespondences) {
        return estimate;
    }
    cv::Mat rotation_vector;
    cv::Mat translation_vector;
    cv::Mat inlier_indices;
    const auto intrinsic = camera_matrix(current);
    const bool solved = cv::solvePnPRansac(
        matches.pnp_object_points,
        matches.pnp_image_points,
        intrinsic,
        cv::noArray(),
        rotation_vector,
        translation_vector,
        false,
        140,
        3.0,
        0.995,
        inlier_indices,
        cv::SOLVEPNP_ITERATIVE);
    if (!solved) return estimate;
    estimate.inliers = inlier_indices.rows;
    estimate.inlier_ratio =
        static_cast<double>(estimate.inliers) /
        static_cast<double>(std::max(1, estimate.correspondences));
    if (estimate.inliers < kMinimumPnPInliers ||
        estimate.inlier_ratio < kMinimumInlierRatio) {
        return estimate;
    }

    std::vector<cv::Point3f> inlier_object_points;
    std::vector<cv::Point2f> inlier_image_points;
    inlier_object_points.reserve(static_cast<std::size_t>(estimate.inliers));
    inlier_image_points.reserve(static_cast<std::size_t>(estimate.inliers));
    for (int row = 0; row < inlier_indices.rows; ++row) {
        const int index = inlier_indices.at<int>(row, 0);
        if (index < 0 ||
            index >= static_cast<int>(matches.pnp_object_points.size())) {
            continue;
        }
        inlier_object_points.push_back(
            matches.pnp_object_points[static_cast<std::size_t>(index)]);
        inlier_image_points.push_back(
            matches.pnp_image_points[static_cast<std::size_t>(index)]);
    }
    std::vector<cv::Point2f> projected;
    cv::projectPoints(
        inlier_object_points,
        rotation_vector,
        translation_vector,
        intrinsic,
        cv::noArray(),
        projected);
    double squared_error = 0.0;
    const auto count = std::min(projected.size(), inlier_image_points.size());
    for (std::size_t index = 0; index < count; ++index) {
        const auto difference = projected[index] - inlier_image_points[index];
        squared_error += static_cast<double>(difference.dot(difference));
    }
    estimate.reprojection_rmse = count > 0
        ? std::sqrt(squared_error / static_cast<double>(count))
        : std::numeric_limits<double>::infinity();
    if (!std::isfinite(estimate.reprojection_rmse) ||
        estimate.reprojection_rmse > kMaximumReprojectionRmse) {
        return estimate;
    }

    cv::Mat rotation;
    cv::Rodrigues(rotation_vector, rotation);
    cv::Mat rotation64;
    cv::Mat translation64;
    rotation.convertTo(rotation64, CV_64F);
    translation_vector.convertTo(translation64, CV_64F);
    estimate.camera_from_reference_cv = matx44_from_rt(
        rotation64,
        {translation64.at<double>(0, 0),
         translation64.at<double>(1, 0),
         translation64.at<double>(2, 0)});
    refine_translation_from_depth(estimate, matches.depth_pairs);
    estimate.sparse_depth_median_m = sparse_depth_median(
        estimate.camera_from_reference_cv,
        matches.depth_pairs);
    const auto camera_from_reference_up =
        cv_to_y_up_transform(estimate.camera_from_reference_cv);
    estimate.world_from_camera =
        reference.world_from_camera * rigid_inverse(camera_from_reference_up);
    estimate.valid = true;
    return estimate;
}

PoseEstimate estimate_rotation_only(
    const Keyframe& reference,
    const TrackingFrame& current,
    const MatchSet& matches) {
    PoseEstimate estimate;
    estimate.reference_keyframe_id = reference.id;
    estimate.method = "ROTATION_HOMOGRAPHY";
    estimate.rotation_only = true;
    estimate.matches = matches.ratio_matches;
    estimate.correspondences =
        static_cast<int>(matches.reference_pixels.size());
    if (estimate.correspondences < kMinimumRotationInliers) {
        return estimate;
    }
    cv::Mat inlier_mask;
    const cv::Mat homography = cv::findHomography(
        matches.reference_pixels,
        matches.current_pixels,
        cv::RANSAC,
        2.5,
        inlier_mask,
        2500,
        0.995);
    if (homography.empty() || inlier_mask.empty()) return estimate;
    estimate.inliers = cv::countNonZero(inlier_mask);
    estimate.inlier_ratio =
        static_cast<double>(estimate.inliers) /
        static_cast<double>(std::max(1, estimate.correspondences));
    if (estimate.inliers < kMinimumRotationInliers ||
        estimate.inlier_ratio < kMinimumRotationInlierRatio) {
        return estimate;
    }

    const auto reference_k = camera_matrix(reference.frame);
    const auto current_k = camera_matrix(current);
    cv::Mat raw_rotation = current_k.inv() * homography * reference_k;
    raw_rotation.convertTo(raw_rotation, CV_64F);
    cv::SVD decomposition(raw_rotation, cv::SVD::FULL_UV);
    cv::Mat u = decomposition.u.clone();
    cv::Mat rotation = u * decomposition.vt;
    if (cv::determinant(rotation) < 0.0) {
        u.col(2) *= -1.0;
        rotation = u * decomposition.vt;
    }

    std::vector<cv::Point2f> projected;
    cv::perspectiveTransform(
        matches.reference_pixels,
        projected,
        homography);
    double squared_error = 0.0;
    std::size_t used = 0;
    for (std::size_t index = 0;
         index < projected.size() && index < matches.current_pixels.size();
         ++index) {
        const int mask_value = inlier_mask.rows == 1
            ? static_cast<int>(inlier_mask.at<std::uint8_t>(0, static_cast<int>(index)))
            : static_cast<int>(inlier_mask.at<std::uint8_t>(static_cast<int>(index), 0));
        if (mask_value == 0) continue;
        const auto difference = projected[index] - matches.current_pixels[index];
        squared_error += static_cast<double>(difference.dot(difference));
        ++used;
    }
    estimate.reprojection_rmse = used > 0
        ? std::sqrt(squared_error / static_cast<double>(used))
        : std::numeric_limits<double>::infinity();
    if (!std::isfinite(estimate.reprojection_rmse) ||
        estimate.reprojection_rmse > kMaximumRotationReprojectionRmse) {
        return estimate;
    }

    estimate.camera_from_reference_cv = matx44_from_rt(
        rotation,
        {0.0, 0.0, 0.0});
    estimate.sparse_depth_median_m = sparse_depth_median(
        estimate.camera_from_reference_cv,
        matches.depth_pairs);
    const auto camera_from_reference_up =
        cv_to_y_up_transform(estimate.camera_from_reference_cv);
    estimate.world_from_camera =
        reference.world_from_camera * rigid_inverse(camera_from_reference_up);
    estimate.valid = true;
    return estimate;
}

PoseEstimate estimate_from_reference(
    const Keyframe& reference,
    const TrackingFrame& current) {
    const auto matches = collect_matches(reference.frame, current);
    auto pnp = estimate_pnp(reference, current, matches);
    auto rotation = estimate_rotation_only(reference, current, matches);
    if (!rotation.valid) return pnp;
    if (!pnp.valid) return rotation;
    const auto pnp_motion = pose_delta(
        reference.world_from_camera,
        pnp.world_from_camera);
    const auto rotation_motion = pose_delta(
        reference.world_from_camera,
        rotation.world_from_camera);
    const bool likely_rotation_only =
        rotation_motion.second >= kPureRotationMinimumDegrees &&
        pnp_motion.first <= kPureRotationTranslationMeters &&
        rotation.inlier_ratio >= pnp.inlier_ratio * 0.80;
    if (likely_rotation_only) return rotation;
    return pnp;
}

int dominant_yaw_sign(const std::deque<double>& recent_steps) {
    int positive = 0;
    int negative = 0;
    for (const double value : recent_steps) {
        if (value > 2.0) ++positive;
        if (value < -2.0) ++negative;
    }
    if (positive >= 3 && positive > negative) return 1;
    if (negative >= 3 && negative > positive) return -1;
    return 0;
}

bool continuity_accepts(
    const PoseEstimate& estimate,
    const cv::Matx44d& previous_pose,
    const int yaw_direction,
    std::string& reason,
    double& translation_m,
    double& rotation_deg,
    double& yaw_step_deg) {
    const auto delta = pose_delta(previous_pose, estimate.world_from_camera);
    translation_m = delta.first;
    rotation_deg = delta.second;
    yaw_step_deg = signed_yaw_delta_deg(previous_pose, estimate.world_from_camera);
    const double translation_limit = estimate.relocalized
        ? kMaximumRelocalizedTranslationMeters
        : kMaximumContinuousTranslationMeters;
    const double rotation_limit = estimate.relocalized
        ? kMaximumRelocalizedRotationDegrees
        : kMaximumContinuousRotationDegrees;
    if (translation_m > translation_limit) {
        reason = "TRANSLATION_JUMP";
        return false;
    }
    if (rotation_deg > rotation_limit) {
        reason = "ROTATION_JUMP";
        return false;
    }
    if (estimate.relocalized && yaw_direction != 0 &&
        std::abs(yaw_step_deg) >= kRelocalizationRollbackDegrees &&
        ((yaw_step_deg > 0.0 ? 1 : -1) != yaw_direction)) {
        reason = "RELOCALIZATION_YAW_ROLLBACK";
        return false;
    }
    if (std::isfinite(estimate.sparse_depth_median_m) &&
        estimate.sparse_depth_median_m > kMaximumSparseDepthMedianMeters) {
        reason = "SPARSE_DEPTH_INCONSISTENT";
        return false;
    }
    reason.clear();
    return true;
}

PoseSelection estimate_pose_continuous(
    const std::deque<Keyframe>& references,
    const TrackingFrame& current,
    const std::deque<double>& recent_yaw_steps) {
    PoseSelection selection;
    if (references.empty()) return selection;
    const auto& previous = references.back();
    const int yaw_direction = dominant_yaw_sign(recent_yaw_steps);

    auto latest = estimate_from_reference(previous, current);
    if (latest.valid) {
        selection.had_candidate = true;
        if (continuity_accepts(
                latest,
                previous.world_from_camera,
                yaw_direction,
                selection.rejection_reason,
                selection.translation_m,
                selection.rotation_deg,
                selection.yaw_step_deg)) {
            selection.estimate = std::move(latest);
            return selection;
        }
    }

    PoseSelection best;
    best.had_candidate = selection.had_candidate;
    best.rejection_reason = selection.rejection_reason;
    const std::size_t candidate_count = std::min(
        references.size(), kRelocalizationCandidates);
    for (std::size_t offset = 1; offset < candidate_count; ++offset) {
        const auto& reference =
            references[references.size() - 1 - offset];
        auto candidate = estimate_from_reference(reference, current);
        if (!candidate.valid) continue;
        candidate.relocalized = true;
        best.had_candidate = true;
        std::string reason;
        double translation_m = 0.0;
        double rotation_deg = 0.0;
        double yaw_step_deg = 0.0;
        if (!continuity_accepts(
                candidate,
                previous.world_from_camera,
                yaw_direction,
                reason,
                translation_m,
                rotation_deg,
                yaw_step_deg)) {
            if (best.rejection_reason.empty()) best.rejection_reason = reason;
            continue;
        }
        const bool better =
            !best.estimate.valid ||
            candidate.inliers > best.estimate.inliers ||
            (candidate.inliers == best.estimate.inliers &&
             candidate.reprojection_rmse < best.estimate.reprojection_rmse);
        if (better) {
            best.estimate = std::move(candidate);
            best.translation_m = translation_m;
            best.rotation_deg = rotation_deg;
            best.yaw_step_deg = yaw_step_deg;
            best.rejection_reason.clear();
        }
    }
    return best;
}

std::string accumulated_cloud_ply(
    const std::unordered_map<
        VoxelKey,
        VoxelAccumulator,
        VoxelKeyHash>& voxels) {
    std::ostringstream output;
    output << "ply\nformat ascii 1.0\n"
           << "comment MaklerTour accumulated metric point cloud\n"
           << "comment rotation_safe_continuous_pose_tracking\n"
           << "comment coordinate_system X_right_Y_up_Z_forward_meters\n"
           << "element vertex " << voxels.size() << "\n"
           << "property float x\nproperty float y\nproperty float z\n"
           << "property uchar red\nproperty uchar green\n"
           << "property uchar blue\n"
           << "property uint observations\n"
           << "property uint keyframe_id\n"
           << "end_header\n";
    output << std::fixed << std::setprecision(6);
    for (const auto& [key, voxel] : voxels) {
        static_cast<void>(key);
        if (voxel.observations == 0) continue;
        const double scale = 1.0 / static_cast<double>(voxel.observations);
        const auto position = voxel.position_sum * scale;
        const auto colour = voxel.colour_sum * scale;
        output << position[0] << ' ' << position[1] << ' ' << position[2] << ' '
               << static_cast<int>(cv::saturate_cast<std::uint8_t>(colour[0])) << ' '
               << static_cast<int>(cv::saturate_cast<std::uint8_t>(colour[1])) << ' '
               << static_cast<int>(cv::saturate_cast<std::uint8_t>(colour[2])) << ' '
               << voxel.observations << ' ' << voxel.last_keyframe_id << '\n';
    }
    return output.str();
}

std::string trajectory_ply(const std::vector<TrajectorySample>& trajectory) {
    const std::size_t edge_count = trajectory.size() > 1
        ? trajectory.size() - 1
        : 0;
    std::ostringstream output;
    output << "ply\nformat ascii 1.0\n"
           << "comment MaklerTour camera trajectory\n"
           << "comment coordinate_system X_right_Y_up_Z_forward_meters\n"
           << "element vertex " << trajectory.size() << "\n"
           << "property float x\nproperty float y\nproperty float z\n"
           << "property uchar red\nproperty uchar green\n"
           << "property uchar blue\nproperty uint keyframe_id\n"
           << "element edge " << edge_count << "\n"
           << "property int vertex1\nproperty int vertex2\nend_header\n";
    output << std::fixed << std::setprecision(6);
    for (const auto& sample : trajectory) {
        output << sample.world_from_camera(0, 3) << ' '
               << sample.world_from_camera(1, 3) << ' '
               << sample.world_from_camera(2, 3) << ' '
               << (sample.rotation_only ? "0 255 255 " : "255 255 0 ")
               << sample.keyframe_id << '\n';
    }
    for (std::size_t index = 1; index < trajectory.size(); ++index) {
        output << index - 1 << ' ' << index << '\n';
    }
    return output.str();
}

nlohmann::json trajectory_json(
    const std::vector<TrajectorySample>& trajectory) {
    nlohmann::json samples = nlohmann::json::array();
    for (const auto& sample : trajectory) {
        samples.push_back({
            {"keyframe_id", sample.keyframe_id},
            {"pair_index", sample.pair_index},
            {"state", sample.state},
            {"method", sample.method},
            {"rotation_only", sample.rotation_only},
            {"world_from_camera", pose_json(sample.world_from_camera)},
            {"position_m", nlohmann::json::array({
                 sample.world_from_camera(0, 3),
                 sample.world_from_camera(1, 3),
                 sample.world_from_camera(2, 3),
             })},
            {"yaw_deg", pose_yaw_deg(sample.world_from_camera)},
            {"yaw_step_deg", sample.yaw_step_deg},
            {"accumulated_yaw_deg", sample.accumulated_yaw_deg},
            {"matches", sample.matches},
            {"inliers", sample.inliers},
            {"inlier_ratio", sample.inlier_ratio},
            {"reprojection_rmse_px", sample.reprojection_rmse},
            {"sparse_depth_median_m", sample.sparse_depth_median_m},
            {"translation_from_previous_m", sample.translation_m},
            {"rotation_from_previous_deg", sample.rotation_deg},
            {"timestamp_ms", sample.timestamp_ms},
        });
    }
    return {
        {"schema_version", 2},
        {"tracking", "CONTINUOUS_ROTATION_SAFE"},
        {"coordinate_system", "X_right_Y_up_Z_forward_meters"},
        {"samples", std::move(samples)},
    };
}

}  // namespace

struct AccumulatedMapRuntime::Impl {
    explicit Impl(std::filesystem::path session_path)
        : session_directory(std::move(session_path)),
          diagnostics(session_directory / "accumulated_map.jsonl", std::ios::app) {
        if (!diagnostics) {
            throw std::runtime_error("cannot create accumulated map diagnostics");
        }
        worker = std::thread([this] { worker_loop(); });
    }

    ~Impl() {
        {
            std::scoped_lock lock(mutex);
            stopping = true;
            pending.reset();
        }
        condition.notify_all();
        if (worker.joinable()) worker.join();
        try {
            publish_outputs(true);
            write_status_file();
        } catch (...) {
            // Session shutdown must not terminate the host.
        }
    }

    bool submit(
        const std::uint64_t input_pair_index,
        std::string input_source_profile,
        const StereoDepthResult& depth) {
        const auto now = std::chrono::steady_clock::now();
        std::scoped_lock lock(mutex);
        ++submitted_frames;
        if (input_source_profile != "HIGH_640") {
            ++rejected_profile_frames;
            return false;
        }
        if (pending) {
            ++rejected_busy_frames;
            return false;
        }
        if (last_accepted_submission.time_since_epoch().count() != 0 &&
            now - last_accepted_submission < kMinimumSubmissionInterval) {
            ++rejected_interval_frames;
            return false;
        }
        if (depth.work_a.empty() || depth.geometry_disparity.empty() ||
            depth.geometry_mask.empty()) {
            ++rejected_invalid_frames;
            return false;
        }
        MapJob job;
        job.generation = generation;
        job.pair_index = input_pair_index;
        job.source_profile = std::move(input_source_profile);
        job.colour = depth.work_a.clone();
        job.disparity = depth.geometry_disparity.clone();
        job.mask = depth.geometry_mask.clone();
        job.focal_px = depth.focal_px;
        job.baseline_mm = depth.baseline_mm;
        job.principal_x_px = depth.principal_x_px;
        job.principal_y_px = depth.principal_y_px;
        pending = std::move(job);
        last_accepted_submission = now;
        ++accepted_frames;
        condition.notify_one();
        return true;
    }

    void reset() {
        {
            std::scoped_lock lock(mutex);
            ++generation;
            pending.reset();
            clear_requested = true;
            ready = false;
            tracking_state = "WAITING";
            last_error.clear();
            keyframe_count = 0;
            accumulated_points = 0;
            trajectory_samples = 0;
            last_pair_index = 0;
            last_reference_keyframe_id = 0;
            last_matches = 0;
            last_correspondences = 0;
            last_inliers = 0;
            last_inlier_ratio = 0.0;
            last_reprojection_rmse = 0.0;
            last_sparse_depth_median_m = 0.0;
            last_translation_m = 0.0;
            last_rotation_deg = 0.0;
            last_yaw_step_deg = 0.0;
            accumulated_yaw_deg = 0.0;
            last_tracking_method = "NONE";
            last_rejection_reason.clear();
        }
        condition.notify_all();
    }

    nlohmann::json status_json() const {
        std::scoped_lock lock(mutex);
        return status_json_locked();
    }

    nlohmann::json status_json_locked() const {
        return {
            {"state", tracking_state},
            {"ready", ready},
            {"tracking_mode", "CONTINUOUS_ROTATION_SAFE"},
            {"recommended_profile", "HIGH_640"},
            {"source_profile", source_profile},
            {"last_pair_index", last_pair_index},
            {"last_reference_keyframe_id", last_reference_keyframe_id},
            {"keyframe_count", keyframe_count},
            {"trajectory_samples", trajectory_samples},
            {"accumulated_points", accumulated_points},
            {"voxel_size_m", kVoxelMeters},
            {"tracking_method", last_tracking_method},
            {"rotation_only", last_rotation_only},
            {"matches", last_matches},
            {"correspondences", last_correspondences},
            {"inliers", last_inliers},
            {"inlier_ratio", last_inlier_ratio},
            {"reprojection_rmse_px", last_reprojection_rmse},
            {"sparse_depth_median_m", last_sparse_depth_median_m},
            {"translation_from_previous_m", last_translation_m},
            {"rotation_from_previous_deg", last_rotation_deg},
            {"yaw_step_deg", last_yaw_step_deg},
            {"accumulated_yaw_deg", accumulated_yaw_deg},
            {"last_rejection_reason", last_rejection_reason},
            {"processing_ms", last_processing_ms},
            {"submitted_frames", submitted_frames},
            {"accepted_frames", accepted_frames},
            {"processed_frames", processed_frames},
            {"failed_frames", failed_frames},
            {"lost_frames", lost_frames},
            {"stationary_frames", stationary_frames},
            {"relocalizations", relocalizations},
            {"rotation_only_keyframes", rotation_only_keyframes},
            {"pose_rejected_frames", pose_rejected_frames},
            {"rejected_profile_frames", rejected_profile_frames},
            {"rejected_busy_frames", rejected_busy_frames},
            {"rejected_interval_frames", rejected_interval_frames},
            {"rejected_invalid_frames", rejected_invalid_frames},
            {"generation", generation},
            {"coordinate_system", "X_right_Y_up_Z_forward_meters"},
            {"point_cloud_file", "point_cloud_accumulated.ply"},
            {"trajectory_file", "camera_trajectory.json"},
            {"trajectory_ply_file", "camera_trajectory.ply"},
            {"last_error", last_error},
        };
    }

    void append_diagnostic(nlohmann::json value) {
        value["ts_unix_ms"] = unix_time_ms();
        diagnostics << value.dump() << '\n';
        diagnostics.flush();
    }

    void write_status_file() const {
        write_text_atomic(
            session_directory / "accumulated_map_status.json",
            status_json().dump(2) + "\n");
    }

    void clear_worker_state() {
        registration_keyframes.clear();
        trajectory.clear();
        voxels.clear();
        recent_yaw_steps.clear();
        next_keyframe_id = 1;
        worker_accumulated_yaw_deg = 0.0;
        last_publish = {};
        for (const auto* name : {
                 "point_cloud_accumulated.ply",
                 "camera_trajectory.json",
                 "camera_trajectory.ply",
             }) {
            std::error_code error;
            std::filesystem::remove(session_directory / name, error);
        }
    }

    void merge_keyframe(
        const MapJob& job,
        const cv::Matx44d& world_from_camera,
        const std::uint64_t keyframe_id) {
        const int stride = job.disparity.total() > 1000000 ? 3 : 2;
        for (int row = 0; row < job.disparity.rows; row += stride) {
            const auto* disparity_row = job.disparity.ptr<float>(row);
            const auto* mask_row = job.mask.ptr<std::uint8_t>(row);
            const auto* colour_row = job.colour.ptr<cv::Vec3b>(row);
            for (int column = 0; column < job.disparity.cols; column += stride) {
                if (mask_row[column] == 0) continue;
                const double disparity =
                    static_cast<double>(disparity_row[column]);
                if (!std::isfinite(disparity) || disparity <= 1.0) continue;
                const double z = job.focal_px * job.baseline_mm /
                    disparity / 1000.0;
                if (!std::isfinite(z) || z < kNearMeters || z > kFarMeters) {
                    continue;
                }
                const double x =
                    (static_cast<double>(column) - job.principal_x_px) *
                    z / job.focal_px;
                const double y_up =
                    -(static_cast<double>(row) - job.principal_y_px) *
                    z / job.focal_px;
                const auto world = transform_point(
                    world_from_camera,
                    {x, y_up, z});
                if (!std::isfinite(world[0]) || !std::isfinite(world[1]) ||
                    !std::isfinite(world[2]) || std::abs(world[0]) > 20.0 ||
                    std::abs(world[1]) > 10.0 || std::abs(world[2]) > 20.0) {
                    continue;
                }
                const VoxelKey key{
                    static_cast<int>(std::floor(world[0] / kVoxelMeters)),
                    static_cast<int>(std::floor(world[1] / kVoxelMeters)),
                    static_cast<int>(std::floor(world[2] / kVoxelMeters)),
                };
                auto iterator = voxels.find(key);
                if (iterator == voxels.end()) {
                    if (voxels.size() >= kMaximumVoxels) continue;
                    iterator = voxels.emplace(key, VoxelAccumulator{}).first;
                }
                auto& voxel = iterator->second;
                voxel.position_sum += world;
                const auto bgr = colour_row[column];
                voxel.colour_sum += cv::Vec3d{
                    static_cast<double>(bgr[2]),
                    static_cast<double>(bgr[1]),
                    static_cast<double>(bgr[0]),
                };
                if (voxel.observations <
                    std::numeric_limits<std::uint32_t>::max()) {
                    ++voxel.observations;
                }
                voxel.last_keyframe_id = keyframe_id;
            }
        }
    }

    void publish_outputs(const bool force) {
        if (trajectory.empty() || voxels.empty()) return;
        const auto now = std::chrono::steady_clock::now();
        if (!force && last_publish.time_since_epoch().count() != 0 &&
            now - last_publish < kMinimumPublishInterval) {
            return;
        }
        write_text_atomic(
            session_directory / "point_cloud_accumulated.ply",
            accumulated_cloud_ply(voxels));
        write_text_atomic(
            session_directory / "camera_trajectory.json",
            trajectory_json(trajectory).dump(2) + "\n");
        write_text_atomic(
            session_directory / "camera_trajectory.ply",
            trajectory_ply(trajectory));
        last_publish = now;
    }

    void record_lost(
        const MapJob& job,
        const PoseSelection& selection,
        const double processing_ms) {
        nlohmann::json diagnostic;
        {
            std::scoped_lock lock(mutex);
            if (job.generation != generation) return;
            tracking_state = selection.had_candidate
                ? "POSE_REJECTED"
                : "LOST";
            ready = !trajectory.empty();
            source_profile = job.source_profile;
            last_pair_index = job.pair_index;
            last_tracking_method = selection.estimate.method;
            last_rejection_reason = selection.rejection_reason;
            last_translation_m = selection.translation_m;
            last_rotation_deg = selection.rotation_deg;
            last_yaw_step_deg = selection.yaw_step_deg;
            last_processing_ms = processing_ms;
            ++processed_frames;
            if (selection.had_candidate) {
                ++pose_rejected_frames;
            } else {
                ++lost_frames;
            }
            diagnostic = {
                {"event", selection.had_candidate
                    ? "ACCUMULATED_MAP_POSE_REJECTED"
                    : "ACCUMULATED_MAP_LOST"},
                {"pair_index", last_pair_index},
                {"reason", last_rejection_reason},
                {"translation_m", last_translation_m},
                {"rotation_deg", last_rotation_deg},
                {"yaw_step_deg", last_yaw_step_deg},
                {"processing_ms", last_processing_ms},
            };
        }
        append_diagnostic(std::move(diagnostic));
        write_status_file();
    }

    void worker_loop() {
        while (true) {
            MapJob job;
            {
                std::unique_lock lock(mutex);
                condition.wait(lock, [this] {
                    return stopping || clear_requested || pending.has_value();
                });
                if (stopping) break;
                if (clear_requested) {
                    clear_requested = false;
                    lock.unlock();
                    clear_worker_state();
                    write_status_file();
                    continue;
                }
                job = std::move(*pending);
                pending.reset();
            }

            const auto started = std::chrono::steady_clock::now();
            try {
                const auto current = make_tracking_frame(job);
                if (current.keypoints.size() <
                        static_cast<std::size_t>(kMinimumFeatures) ||
                    current.descriptors.empty()) {
                    throw std::runtime_error(
                        "insufficient ORB features for registration");
                }

                const bool first_keyframe = registration_keyframes.empty();
                PoseSelection selection;
                cv::Matx44d world_from_camera = cv::Matx44d::eye();
                if (!first_keyframe) {
                    selection = estimate_pose_continuous(
                        registration_keyframes,
                        current,
                        recent_yaw_steps);
                    if (!selection.estimate.valid) {
                        const double processing_ms =
                            std::chrono::duration<double, std::milli>(
                                std::chrono::steady_clock::now() - started).count();
                        record_lost(job, selection, processing_ms);
                        continue;
                    }
                    world_from_camera = selection.estimate.world_from_camera;
                }

                const double translation_m = first_keyframe
                    ? 0.0
                    : selection.translation_m;
                const double rotation_deg = first_keyframe
                    ? 0.0
                    : selection.rotation_deg;
                const double yaw_step_deg = first_keyframe
                    ? 0.0
                    : selection.yaw_step_deg;
                const bool accept_keyframe = first_keyframe ||
                    translation_m >= kMinimumKeyframeTranslationMeters ||
                    rotation_deg >= kMinimumKeyframeRotationDegrees;
                const double processing_ms =
                    std::chrono::duration<double, std::milli>(
                        std::chrono::steady_clock::now() - started).count();

                if (!accept_keyframe) {
                    nlohmann::json diagnostic;
                    {
                        std::scoped_lock lock(mutex);
                        if (job.generation != generation) continue;
                        tracking_state = "TRACKING_STATIONARY";
                        ready = !trajectory.empty();
                        source_profile = job.source_profile;
                        last_pair_index = job.pair_index;
                        last_reference_keyframe_id =
                            selection.estimate.reference_keyframe_id;
                        last_tracking_method = selection.estimate.method;
                        last_rotation_only = selection.estimate.rotation_only;
                        last_matches = selection.estimate.matches;
                        last_correspondences = selection.estimate.correspondences;
                        last_inliers = selection.estimate.inliers;
                        last_inlier_ratio = selection.estimate.inlier_ratio;
                        last_reprojection_rmse =
                            selection.estimate.reprojection_rmse;
                        last_sparse_depth_median_m = std::isfinite(
                            selection.estimate.sparse_depth_median_m)
                            ? selection.estimate.sparse_depth_median_m
                            : 0.0;
                        last_translation_m = translation_m;
                        last_rotation_deg = rotation_deg;
                        last_yaw_step_deg = yaw_step_deg;
                        last_processing_ms = processing_ms;
                        last_rejection_reason.clear();
                        ++processed_frames;
                        ++stationary_frames;
                        diagnostic = {
                            {"event", "ACCUMULATED_MAP_STATIONARY"},
                            {"pair_index", last_pair_index},
                            {"method", last_tracking_method},
                            {"translation_m", last_translation_m},
                            {"rotation_deg", last_rotation_deg},
                            {"yaw_step_deg", last_yaw_step_deg},
                            {"processing_ms", last_processing_ms},
                        };
                    }
                    append_diagnostic(std::move(diagnostic));
                    write_status_file();
                    continue;
                }

                const std::uint64_t keyframe_id = next_keyframe_id++;
                merge_keyframe(job, world_from_camera, keyframe_id);
                Keyframe keyframe;
                keyframe.id = keyframe_id;
                keyframe.pair_index = job.pair_index;
                keyframe.frame = current;
                keyframe.world_from_camera = world_from_camera;
                registration_keyframes.push_back(std::move(keyframe));
                while (registration_keyframes.size() >
                       kMaximumRegistrationKeyframes) {
                    registration_keyframes.pop_front();
                }

                if (!first_keyframe) {
                    recent_yaw_steps.push_back(yaw_step_deg);
                    while (recent_yaw_steps.size() > 5) {
                        recent_yaw_steps.pop_front();
                    }
                    worker_accumulated_yaw_deg += yaw_step_deg;
                }
                const std::string state = first_keyframe
                    ? "TRACKING_INITIALIZED"
                    : (selection.estimate.rotation_only
                        ? "TRACKING_ROTATION"
                        : (selection.estimate.relocalized
                            ? "RELOCALIZED_CONTINUOUS"
                            : "TRACKING"));
                const auto& estimate = selection.estimate;
                trajectory.push_back({
                    keyframe_id,
                    job.pair_index,
                    state,
                    first_keyframe ? "IDENTITY" : estimate.method,
                    !first_keyframe && estimate.rotation_only,
                    world_from_camera,
                    first_keyframe ? 0 : estimate.matches,
                    first_keyframe ? 0 : estimate.inliers,
                    first_keyframe ? 0.0 : estimate.inlier_ratio,
                    first_keyframe || !std::isfinite(estimate.reprojection_rmse)
                        ? 0.0
                        : estimate.reprojection_rmse,
                    first_keyframe || !std::isfinite(estimate.sparse_depth_median_m)
                        ? 0.0
                        : estimate.sparse_depth_median_m,
                    translation_m,
                    rotation_deg,
                    yaw_step_deg,
                    worker_accumulated_yaw_deg,
                    unix_time_ms(),
                });
                publish_outputs(false);

                nlohmann::json diagnostic;
                {
                    std::scoped_lock lock(mutex);
                    if (job.generation != generation) continue;
                    ready = true;
                    tracking_state = state;
                    source_profile = job.source_profile;
                    last_pair_index = job.pair_index;
                    last_reference_keyframe_id = first_keyframe
                        ? 0
                        : estimate.reference_keyframe_id;
                    keyframe_count = trajectory.size();
                    trajectory_samples = trajectory.size();
                    accumulated_points = voxels.size();
                    last_tracking_method = first_keyframe
                        ? "IDENTITY"
                        : estimate.method;
                    last_rotation_only = !first_keyframe && estimate.rotation_only;
                    last_matches = first_keyframe ? 0 : estimate.matches;
                    last_correspondences = first_keyframe
                        ? 0
                        : estimate.correspondences;
                    last_inliers = first_keyframe ? 0 : estimate.inliers;
                    last_inlier_ratio = first_keyframe
                        ? 0.0
                        : estimate.inlier_ratio;
                    last_reprojection_rmse = first_keyframe ||
                        !std::isfinite(estimate.reprojection_rmse)
                        ? 0.0
                        : estimate.reprojection_rmse;
                    last_sparse_depth_median_m = first_keyframe ||
                        !std::isfinite(estimate.sparse_depth_median_m)
                        ? 0.0
                        : estimate.sparse_depth_median_m;
                    last_translation_m = translation_m;
                    last_rotation_deg = rotation_deg;
                    last_yaw_step_deg = yaw_step_deg;
                    accumulated_yaw_deg = worker_accumulated_yaw_deg;
                    last_processing_ms = processing_ms;
                    last_error.clear();
                    last_rejection_reason.clear();
                    ++processed_frames;
                    if (!first_keyframe && estimate.relocalized) {
                        ++relocalizations;
                    }
                    if (!first_keyframe && estimate.rotation_only) {
                        ++rotation_only_keyframes;
                    }
                    diagnostic = {
                        {"event", "ACCUMULATED_MAP_KEYFRAME"},
                        {"state", tracking_state},
                        {"pair_index", last_pair_index},
                        {"keyframe_id", keyframe_id},
                        {"reference_keyframe_id", last_reference_keyframe_id},
                        {"method", last_tracking_method},
                        {"rotation_only", last_rotation_only},
                        {"matches", last_matches},
                        {"correspondences", last_correspondences},
                        {"inliers", last_inliers},
                        {"inlier_ratio", last_inlier_ratio},
                        {"reprojection_rmse_px", last_reprojection_rmse},
                        {"sparse_depth_median_m", last_sparse_depth_median_m},
                        {"translation_m", last_translation_m},
                        {"rotation_deg", last_rotation_deg},
                        {"yaw_step_deg", last_yaw_step_deg},
                        {"accumulated_yaw_deg", accumulated_yaw_deg},
                        {"accumulated_points", accumulated_points},
                        {"processing_ms", last_processing_ms},
                    };
                }
                append_diagnostic(std::move(diagnostic));
                write_status_file();
            } catch (const std::exception& error) {
                nlohmann::json diagnostic;
                {
                    std::scoped_lock lock(mutex);
                    if (job.generation != generation) continue;
                    tracking_state = "ERROR";
                    source_profile = job.source_profile;
                    last_pair_index = job.pair_index;
                    last_processing_ms =
                        std::chrono::duration<double, std::milli>(
                            std::chrono::steady_clock::now() - started).count();
                    last_error = error.what();
                    ++failed_frames;
                    diagnostic = {
                        {"event", "ACCUMULATED_MAP_FAILED"},
                        {"pair_index", last_pair_index},
                        {"error", last_error},
                        {"processing_ms", last_processing_ms},
                    };
                }
                append_diagnostic(std::move(diagnostic));
                write_status_file();
            }
        }
    }

    std::filesystem::path session_directory;
    mutable std::mutex mutex;
    std::condition_variable condition;
    bool stopping = false;
    bool clear_requested = false;
    std::optional<MapJob> pending;
    std::ofstream diagnostics;
    std::thread worker;
    std::uint64_t generation = 1;
    std::chrono::steady_clock::time_point last_accepted_submission;
    std::chrono::steady_clock::time_point last_publish;

    std::deque<Keyframe> registration_keyframes;
    std::vector<TrajectorySample> trajectory;
    std::deque<double> recent_yaw_steps;
    std::unordered_map<VoxelKey, VoxelAccumulator, VoxelKeyHash> voxels;
    std::uint64_t next_keyframe_id = 1;
    double worker_accumulated_yaw_deg = 0.0;

    bool ready = false;
    std::string tracking_state = "WAITING";
    std::string source_profile = "WAITING";
    std::uint64_t last_pair_index = 0;
    std::uint64_t last_reference_keyframe_id = 0;
    std::uint64_t keyframe_count = 0;
    std::uint64_t trajectory_samples = 0;
    std::uint64_t accumulated_points = 0;
    std::string last_tracking_method = "NONE";
    bool last_rotation_only = false;
    int last_matches = 0;
    int last_correspondences = 0;
    int last_inliers = 0;
    double last_inlier_ratio = 0.0;
    double last_reprojection_rmse = 0.0;
    double last_sparse_depth_median_m = 0.0;
    double last_translation_m = 0.0;
    double last_rotation_deg = 0.0;
    double last_yaw_step_deg = 0.0;
    double accumulated_yaw_deg = 0.0;
    double last_processing_ms = 0.0;
    std::string last_rejection_reason;
    std::string last_error;

    std::uint64_t submitted_frames = 0;
    std::uint64_t accepted_frames = 0;
    std::uint64_t processed_frames = 0;
    std::uint64_t failed_frames = 0;
    std::uint64_t lost_frames = 0;
    std::uint64_t stationary_frames = 0;
    std::uint64_t relocalizations = 0;
    std::uint64_t rotation_only_keyframes = 0;
    std::uint64_t pose_rejected_frames = 0;
    std::uint64_t rejected_profile_frames = 0;
    std::uint64_t rejected_busy_frames = 0;
    std::uint64_t rejected_interval_frames = 0;
    std::uint64_t rejected_invalid_frames = 0;
};

AccumulatedMapRuntime::AccumulatedMapRuntime(
    std::filesystem::path session_directory)
    : impl_(std::make_unique<Impl>(std::move(session_directory))) {}

AccumulatedMapRuntime::~AccumulatedMapRuntime() = default;

bool AccumulatedMapRuntime::submit(
    const std::uint64_t pair_index,
    std::string source_profile,
    const StereoDepthResult& depth) {
    return impl_->submit(pair_index, std::move(source_profile), depth);
}

void AccumulatedMapRuntime::reset() {
    impl_->reset();
}

nlohmann::json AccumulatedMapRuntime::status_json() const {
    return impl_->status_json();
}

}  // namespace maklertour::dual_phone::detail
