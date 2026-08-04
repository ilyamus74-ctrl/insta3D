#include "accumulated_map_runtime.hpp"
#include "stereo_apriltag_runtime.hpp"

#include <algorithm>
#include <array>
#include <chrono>
#include <cmath>
#include <condition_variable>
#include <cstdint>
#include <deque>
#include <filesystem>
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
#include <unordered_set>
#include <utility>
#include <vector>

#include <opencv2/calib3d.hpp>
#include <opencv2/features2d.hpp>
#include <opencv2/imgproc.hpp>

namespace maklertour::dual_phone::detail {

namespace {

constexpr std::chrono::milliseconds kMinimumSubmissionInterval{180};
constexpr std::chrono::milliseconds kMinimumPublishInterval{1000};
constexpr double kNearMeters = 0.45;
constexpr double kFarMeters = 8.0;
constexpr double kVoxelMeters = 0.03;
constexpr std::size_t kMaximumVoxels = 500000;
constexpr std::size_t kMaximumRegistrationKeyframes = 64;
constexpr std::size_t kMaximumTrackingBufferFrames = 24;
constexpr std::size_t kMaximumRecoveryAttempts = 12;
constexpr int kOrbFeatures = 1900;
constexpr int kMinimumFeatures = 70;
constexpr int kMinimumMatches = 28;
constexpr int kMinimumPnPPoints = 20;
constexpr int kMinimumPnPInliers = 16;
constexpr int kMinimumWalkPnPInliers = 24;
constexpr double kMinimumPnPInlierRatio = 0.30;
constexpr double kMinimumWalkPnPInlierRatio = 0.40;
constexpr double kMaximumPnPRmsePx = 4.0;
constexpr double kMaximumSparseDepthMedianM = 0.65;
constexpr double kMaximumWalkSparseDepthMedianM = 0.35;
constexpr double kMinimumKeyframeYawDeg = 3.0;
constexpr double kMinimumKeyframeTranslationM = 0.06;
constexpr double kMaximumVisualYawStepDeg = 35.0;
constexpr double kMaximumGyroYawStepDeg = 60.0;
constexpr double kMaximumRecoveryGyroStepDeg = 175.0;
constexpr double kMaximumYawDisagreementDeg = 14.0;
constexpr double kMaximumWalkTranslationStepM = 0.65;
constexpr double kMinimumWalkTranslationStepM = 0.035;
constexpr std::size_t kMinimumStereoTranslationPairs = 14;
constexpr int kMinimumStereoTranslationInliers = 10;
constexpr double kMinimumStereoTranslationInlierRatio = 0.35;
constexpr double kMaximumStereoTranslationResidualM = 0.18;
constexpr double kMinimumStereoTranslationResidualGateM = 0.045;
constexpr double kMaximumStereoTranslationResidualGateM = 0.20;
constexpr int kStereoSe3RansacIterations = 192;
constexpr double kStereoSe3RansacThresholdM = 0.14;
constexpr double kMaximumStereoSe3RotationDeg = 55.0;
constexpr double kMaximumStereoSe3GyroYawErrorDeg = 20.0;
constexpr double kMaximumStereoSe3VerticalStepM = 0.30;
constexpr double kMaximumPnpStereoPoseDisagreementM = 0.22;
constexpr double kMaximumPnpStereoYawDisagreementDeg = 18.0;
constexpr std::uint32_t kProvisionalStepsToPromote = 2;
constexpr double kTripodPivotRadiusM = 0.12;
constexpr double kTripodHorizontalSmoothing = 0.35;
constexpr double kTripodMaximumHorizontalStepM = 0.025;
constexpr double kTripodHorizontalStepToleranceM = 0.006;
constexpr double kTripodReturnPositionToleranceM = 0.015;
constexpr double kMinimumAccelerationMotionMps2 = 0.18;
constexpr int kMotionVotesToSwitch = 3;
constexpr std::uint64_t kGyroBiasCalibrationSamples = 80;
constexpr double kGyroBiasCalibrationMaximumRateRadS = 0.06;
constexpr double kGyroBiasCalibrationMaximumAccelerationDeltaMps2 = 0.25;
constexpr double kGyroMaximumDtSeconds = 0.15;
constexpr std::uint32_t kMinimumMultiviewKeyframes = 2;

std::int64_t unix_time_ms() {
    return std::chrono::duration_cast<std::chrono::milliseconds>(
        std::chrono::system_clock::now().time_since_epoch()).count();
}

struct MapJob {
    std::uint64_t generation = 0;
    std::uint64_t pair_index = 0;
    std::uint64_t segment_id = 1;
    std::string source_profile;
    cv::Mat colour;
    cv::Mat disparity;
    cv::Mat mask;
    cv::Mat strict_disparity;
    cv::Mat strict_mask;
    double focal_px = 0.0;
    double baseline_mm = 0.0;
    double principal_x_px = 0.0;
    double principal_y_px = 0.0;
    bool gyro_valid = false;
    bool gyro_bias_ready = false;
    double gyro_bias_rad_s = 0.0;
    double gyro_yaw_uncalibrated_deg = 0.0;
    double gyro_yaw_raw_deg = 0.0;
    std::uint64_t gyro_bias_calibration_samples = 0;
    std::uint64_t gyro_samples = 0;
    bool accelerometer_valid = false;
    double acceleration_motion_mps2 = 0.0;
    bool segment_resume = false;
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
    std::uint64_t segment_id = 1;
    TrackingFrame frame;
    cv::Matx44d world_from_camera = cv::Matx44d::eye();
    bool gyro_valid = false;
    double gyro_yaw_uncalibrated_deg = 0.0;
    double gyro_yaw_raw_deg = 0.0;
    bool pose_trusted = true;
    std::uint32_t provisional_chain_length = 0;
};

struct MatchSet {
    int ratio_matches = 0;
    int mutual_matches = 0;
    int geometric_inliers = 0;
    std::vector<cv::Point2f> reference_pixels;
    std::vector<cv::Point2f> current_pixels;
    std::vector<cv::Point3f> pnp_object_points;
    std::vector<cv::Point2f> pnp_image_points;
    std::vector<std::pair<cv::Point3f, cv::Point3f>> depth_pairs;
};

struct VisualEstimate {
    bool homography_valid = false;
    bool pnp_valid = false;
    bool stereo_translation_attempted = false;
    bool stereo_translation_valid = false;
    bool stereo_gyro_yaw_checked = false;
    bool pnp_stereo_confirmed = false;
    int matches = 0;
    int mutual_matches = 0;
    int geometric_inliers = 0;
    int homography_inliers = 0;
    int pnp_inliers = 0;
    int stereo_translation_pairs = 0;
    int stereo_translation_inliers = 0;
    double homography_inlier_ratio = 0.0;
    double pnp_inlier_ratio = 0.0;
    double stereo_translation_inlier_ratio = 0.0;
    double homography_rmse_px = 0.0;
    double pnp_rmse_px = 0.0;
    double visual_yaw_deg = 0.0;
    double translation_m = 0.0;
    double sparse_depth_median_m = 0.0;
    double stereo_translation_m = 0.0;
    double stereo_translation_residual_m =
        std::numeric_limits<double>::infinity();
    double stereo_rotation_deg = 0.0;
    double stereo_yaw_deg = 0.0;
    double stereo_gyro_yaw_error_deg =
        std::numeric_limits<double>::infinity();
    cv::Matx44d pnp_world_from_camera = cv::Matx44d::eye();
    cv::Matx44d stereo_world_from_camera = cv::Matx44d::eye();
    std::vector<std::pair<cv::Point3f, cv::Point3f>> depth_pairs;
};

enum class MotionMode {
    Unknown,
    Rotation,
    Walk,
};

const char* motion_mode_name(const MotionMode mode) {
    switch (mode) {
        case MotionMode::Rotation: return "AUTO_ROTATION";
        case MotionMode::Walk: return "AUTO_WALK";
        case MotionMode::Unknown: return "AUTO_UNKNOWN";
    }
    return "AUTO_UNKNOWN";
}

struct PoseDecision {
    bool valid = false;
    bool keyframe = false;
    bool rotation_only = false;
    bool used_gyro = false;
    bool used_visual = false;
    bool recovered = false;
    bool translation_trusted = false;
    bool geometry_suppressed = false;
    bool reference_pose_trusted = true;
    bool provisional_promoted = false;
    std::uint32_t provisional_chain_length = 0;
    std::string state = "LOST";
    std::string method = "NONE";
    std::string translation_source = "NONE";
    std::string rejection_reason;
    cv::Matx44d world_from_camera = cv::Matx44d::eye();
    cv::Matx44d reference_world_from_camera = cv::Matx44d::eye();
    double gyro_yaw_step_deg = 0.0;
    double gyro_uncalibrated_yaw_step_deg = 0.0;
    double gyro_bias_removed_step_deg = 0.0;
    double visual_yaw_step_deg = 0.0;
    double fused_yaw_step_deg = 0.0;
    double translation_m = 0.0;
    bool rotation_translation_applied = false;
    double rotation_translation_candidate_m = 0.0;
    double rotation_translation_limit_m = 0.0;
    double rotation_translation_horizontal_candidate_m = 0.0;
    double rotation_translation_horizontal_step_m = 0.0;
    double rotation_translation_horizontal_step_limit_m = 0.0;
    double rotation_translation_total_radius_m = 0.0;
    double rotation_translation_total_limit_m = 0.0;
    double rotation_translation_yaw_from_anchor_deg = 0.0;
    double rotation_translation_locked_y_m = 0.0;
    cv::Vec3d rotation_translation_vector_world_m{0.0, 0.0, 0.0};
    std::string rotation_translation_rejection_reason = "NOT_EVALUATED";
    double rotation_deg = 0.0;
    double translation_from_last_keyframe_m = 0.0;
    double yaw_from_last_keyframe_deg = 0.0;
    MotionMode motion_evidence = MotionMode::Unknown;
    std::uint64_t reference_keyframe_id = 0;
    std::uint64_t reference_pair_index = 0;
    int recovery_attempts = 0;
    VisualEstimate visual;
};

struct TrajectorySample {
    std::uint64_t keyframe_id = 0;
    std::uint64_t pair_index = 0;
    std::uint64_t segment_id = 1;
    std::string state;
    std::string method;
    cv::Matx44d world_from_camera = cv::Matx44d::eye();
    double gyro_yaw_step_deg = 0.0;
    double visual_yaw_step_deg = 0.0;
    double fused_yaw_step_deg = 0.0;
    double accumulated_yaw_deg = 0.0;
    double translation_m = 0.0;
    int matches = 0;
    int inliers = 0;
    double inlier_ratio = 0.0;
    double reprojection_rmse_px = 0.0;
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
        std::size_t seed = std::hash<int>{}(value.x);
        const auto mix = [&seed](const std::size_t part) {
            seed ^= part + 0x9e3779b97f4a7c15ULL + (seed << 6U) + (seed >> 2U);
        };
        mix(std::hash<int>{}(value.y));
        mix(std::hash<int>{}(value.z));
        return seed;
    }
};

struct VoxelAccumulator {
    cv::Vec3d position_sum{0.0, 0.0, 0.0};
    cv::Vec3d colour_sum{0.0, 0.0, 0.0};
    std::uint32_t pixel_samples = 0;
    std::uint32_t keyframe_observations = 0;
    std::uint64_t last_keyframe_id = 0;
    std::uint64_t last_segment_id = 1;
};

void write_text_atomic(const std::filesystem::path& destination,
                       const std::string& contents) {
    auto temporary = destination;
    temporary += ".tmp";
    {
        std::ofstream output(temporary, std::ios::binary | std::ios::trunc);
        if (!output) throw std::runtime_error("cannot write " + temporary.string());
        output << contents;
        output.flush();
        if (!output) throw std::runtime_error("cannot finish " + temporary.string());
    }
    std::error_code error;
    std::filesystem::rename(temporary, destination, error);
    if (!error) return;
    std::filesystem::remove(destination, error);
    error.clear();
    std::filesystem::rename(temporary, destination, error);
    if (error) {
        std::filesystem::remove(temporary);
        throw std::runtime_error("cannot publish " + destination.string() + ": " + error.message());
    }
}

cv::Matx44d rigid_inverse(const cv::Matx44d& value) {
    const cv::Matx33d rotation(
        value(0, 0), value(0, 1), value(0, 2),
        value(1, 0), value(1, 1), value(1, 2),
        value(2, 0), value(2, 1), value(2, 2));
    const auto transposed = rotation.t();
    const cv::Vec3d translation{value(0, 3), value(1, 3), value(2, 3)};
    const auto inverse_translation = -(transposed * translation);
    return {
        transposed(0, 0), transposed(0, 1), transposed(0, 2), inverse_translation[0],
        transposed(1, 0), transposed(1, 1), transposed(1, 2), inverse_translation[1],
        transposed(2, 0), transposed(2, 1), transposed(2, 2), inverse_translation[2],
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

cv::Vec3d transform_point(const cv::Matx44d& transform,
                          const cv::Vec3d& point) {
    const auto result = transform * cv::Vec4d{point[0], point[1], point[2], 1.0};
    return {result[0], result[1], result[2]};
}

cv::Matx44d yaw_rotation_deg(const double degrees) {
    const double radians = degrees * std::numbers::pi / 180.0;
    const double cosine = std::cos(radians);
    const double sine = std::sin(radians);
    return {
        cosine, 0.0, sine, 0.0,
        0.0, 1.0, 0.0, 0.0,
        -sine, 0.0, cosine, 0.0,
        0.0, 0.0, 0.0, 1.0,
    };
}

double signed_yaw_delta_deg(const cv::Matx44d& previous,
                            const cv::Matx44d& current) {
    const auto relative = rigid_inverse(previous) * current;
    return std::atan2(relative(0, 2), relative(2, 2)) *
           180.0 / std::numbers::pi;
}

double translation_delta_m(const cv::Matx44d& previous,
                           const cv::Matx44d& current) {
    const auto relative = rigid_inverse(previous) * current;
    const cv::Vec3d translation{relative(0, 3), relative(1, 3), relative(2, 3)};
    return std::sqrt(translation.dot(translation));
}

double pose_yaw_deg(const cv::Matx44d& pose) {
    return std::atan2(pose(0, 2), pose(2, 2)) *
           180.0 / std::numbers::pi;
}

nlohmann::json pose_json(const cv::Matx44d& pose) {
    nlohmann::json rows = nlohmann::json::array();
    for (int row = 0; row < 4; ++row) {
        nlohmann::json values = nlohmann::json::array();
        for (int column = 0; column < 4; ++column) values.push_back(pose(row, column));
        rows.push_back(std::move(values));
    }
    return rows;
}

std::optional<cv::Point3f> point_from_disparity(const TrackingFrame& frame,
                                                 const cv::Point2f& pixel) {
    const int column = static_cast<int>(std::lround(pixel.x));
    const int row = static_cast<int>(std::lround(pixel.y));
    if (column < 0 || row < 0 || column >= frame.disparity.cols ||
        row >= frame.disparity.rows || frame.mask.at<std::uint8_t>(row, column) == 0) {
        return std::nullopt;
    }
    const double disparity = static_cast<double>(frame.disparity.at<float>(row, column));
    if (!std::isfinite(disparity) || disparity <= 1.0) return std::nullopt;
    const double z = frame.focal_px * frame.baseline_mm / disparity / 1000.0;
    if (!std::isfinite(z) || z < kNearMeters || z > kFarMeters) return std::nullopt;
    const double x = (static_cast<double>(column) - frame.principal_x_px) * z /
                     frame.focal_px;
    const double y = (static_cast<double>(row) - frame.principal_y_px) * z /
                     frame.focal_px;
    return cv::Point3f{static_cast<float>(x), static_cast<float>(y),
                       static_cast<float>(z)};
}

TrackingFrame make_tracking_frame(const MapJob& job) {
    if (job.colour.empty() || job.disparity.empty() || job.mask.empty() ||
        job.colour.size() != job.disparity.size() ||
        job.colour.size() != job.mask.size() || job.disparity.type() != CV_32F ||
        job.mask.type() != CV_8U) {
        throw std::runtime_error("accumulated map requires aligned colour, disparity and mask");
    }
    TrackingFrame result;
    cv::cvtColor(job.colour, result.gray, cv::COLOR_BGR2GRAY);
    auto orb = cv::ORB::create(kOrbFeatures, 1.2F, 8, 31, 0, 2,
                               cv::ORB::HARRIS_SCORE, 31, 12);
    orb->detectAndCompute(result.gray, job.mask, result.keypoints, result.descriptors);
    result.disparity = job.disparity;
    result.mask = job.mask;
    result.focal_px = job.focal_px;
    result.baseline_mm = job.baseline_mm;
    result.principal_x_px = job.principal_x_px;
    result.principal_y_px = job.principal_y_px;
    return result;
}

MatchSet collect_matches(const TrackingFrame& reference,
                         const TrackingFrame& current) {
    struct Candidate {
        cv::Point2f reference_pixel;
        cv::Point2f current_pixel;
    };

    MatchSet result;
    if (reference.descriptors.empty() || current.descriptors.empty()) return result;
    cv::BFMatcher matcher(cv::NORM_HAMMING, false);
    std::vector<std::vector<cv::DMatch>> forward_neighbours;
    std::vector<std::vector<cv::DMatch>> reverse_neighbours;
    matcher.knnMatch(
        reference.descriptors, current.descriptors,
        forward_neighbours, 2);
    matcher.knnMatch(
        current.descriptors, reference.descriptors,
        reverse_neighbours, 2);

    std::vector<int> reverse_reference(
        static_cast<std::size_t>(current.descriptors.rows), -1);
    for (const auto& pair : reverse_neighbours) {
        if (pair.size() < 2 || pair[0].distance >= 0.75F * pair[1].distance) continue;
        if (pair[0].queryIdx < 0 || pair[0].trainIdx < 0 ||
            pair[0].queryIdx >= current.descriptors.rows) continue;
        reverse_reference[static_cast<std::size_t>(pair[0].queryIdx)] =
            pair[0].trainIdx;
    }

    std::vector<Candidate> candidates;
    for (const auto& pair : forward_neighbours) {
        if (pair.size() < 2 || pair[0].distance >= 0.75F * pair[1].distance) continue;
        if (pair[0].queryIdx < 0 || pair[0].trainIdx < 0) continue;
        const auto query = static_cast<std::size_t>(pair[0].queryIdx);
        const auto train = static_cast<std::size_t>(pair[0].trainIdx);
        if (query >= reference.keypoints.size() ||
            train >= current.keypoints.size() ||
            train >= reverse_reference.size() ||
            reverse_reference[train] != pair[0].queryIdx) continue;
        candidates.push_back({
            reference.keypoints[query].pt,
            current.keypoints[train].pt,
        });
    }
    result.mutual_matches = static_cast<int>(candidates.size());
    if (candidates.empty()) return result;

    std::vector<cv::Point2f> reference_pixels;
    std::vector<cv::Point2f> current_pixels;
    reference_pixels.reserve(candidates.size());
    current_pixels.reserve(candidates.size());
    for (const auto& candidate : candidates) {
        reference_pixels.push_back(candidate.reference_pixel);
        current_pixels.push_back(candidate.current_pixel);
    }

    cv::Mat geometric_mask;
    bool geometric_filter_valid = false;
    if (candidates.size() >= 8) {
        const auto fundamental = cv::findFundamentalMat(
            reference_pixels, current_pixels,
            cv::FM_RANSAC, 1.5, 0.995, geometric_mask);
        geometric_filter_valid = !fundamental.empty() &&
            !geometric_mask.empty() &&
            geometric_mask.total() == candidates.size();
    }

    for (std::size_t index = 0; index < candidates.size(); ++index) {
        if (geometric_filter_valid &&
            geometric_mask.at<std::uint8_t>(static_cast<int>(index)) == 0) {
            continue;
        }
        const auto& candidate = candidates[index];
        ++result.geometric_inliers;
        result.reference_pixels.push_back(candidate.reference_pixel);
        result.current_pixels.push_back(candidate.current_pixel);
        const auto reference_point = point_from_disparity(
            reference, candidate.reference_pixel);
        if (!reference_point) continue;
        result.pnp_object_points.push_back(*reference_point);
        result.pnp_image_points.push_back(candidate.current_pixel);
        const auto current_point = point_from_disparity(
            current, candidate.current_pixel);
        if (current_point) {
            result.depth_pairs.emplace_back(*reference_point, *current_point);
        }
    }
    result.ratio_matches = result.geometric_inliers;
    return result;
}

cv::Mat camera_matrix(const TrackingFrame& frame) {
    return (cv::Mat_<double>(3, 3) <<
        frame.focal_px, 0.0, frame.principal_x_px,
        0.0, frame.focal_px, frame.principal_y_px,
        0.0, 0.0, 1.0);
}

cv::Matx44d matx44_from_rt(const cv::Mat& rotation,
                           const cv::Vec3d& translation) {
    return {
        rotation.at<double>(0, 0), rotation.at<double>(0, 1), rotation.at<double>(0, 2), translation[0],
        rotation.at<double>(1, 0), rotation.at<double>(1, 1), rotation.at<double>(1, 2), translation[1],
        rotation.at<double>(2, 0), rotation.at<double>(2, 1), rotation.at<double>(2, 2), translation[2],
        0.0, 0.0, 0.0, 1.0,
    };
}

double median_depth_residual(const cv::Matx44d& camera_from_reference,
                             const std::vector<std::pair<cv::Point3f, cv::Point3f>>& pairs) {
    std::vector<double> residuals;
    residuals.reserve(pairs.size());
    for (const auto& [reference, current] : pairs) {
        const auto predicted = camera_from_reference *
            cv::Vec4d{reference.x, reference.y, reference.z, 1.0};
        const cv::Vec3d difference{
            predicted[0] - static_cast<double>(current.x),
            predicted[1] - static_cast<double>(current.y),
            predicted[2] - static_cast<double>(current.z),
        };
        const double distance = std::sqrt(difference.dot(difference));
        if (std::isfinite(distance) && distance < 2.0) residuals.push_back(distance);
    }
    if (residuals.size() < 10) return std::numeric_limits<double>::infinity();
    auto middle = residuals.begin() + static_cast<std::ptrdiff_t>(residuals.size() / 2);
    std::nth_element(residuals.begin(), middle, residuals.end());
    return *middle;
}

double median_scalar(std::vector<double> values) {
    if (values.empty()) return std::numeric_limits<double>::infinity();
    auto middle = values.begin() +
        static_cast<std::ptrdiff_t>(values.size() / 2);
    std::nth_element(values.begin(), middle, values.end());
    return *middle;
}

bool fit_rigid_transform(
    const std::vector<std::pair<cv::Point3f, cv::Point3f>>& pairs,
    const std::vector<std::size_t>& indices,
    cv::Matx44d& camera_from_reference) {
    if (indices.size() < 3) return false;
    cv::Vec3d reference_centroid{0.0, 0.0, 0.0};
    cv::Vec3d current_centroid{0.0, 0.0, 0.0};
    for (const auto index : indices) {
        if (index >= pairs.size()) return false;
        const auto& [reference_point, current_point] = pairs[index];
        reference_centroid += cv::Vec3d{
            reference_point.x, reference_point.y, reference_point.z};
        current_centroid += cv::Vec3d{
            current_point.x, current_point.y, current_point.z};
    }
    const double inverse_count = 1.0 / static_cast<double>(indices.size());
    reference_centroid *= inverse_count;
    current_centroid *= inverse_count;

    cv::Matx33d covariance = cv::Matx33d::zeros();
    for (const auto index : indices) {
        const auto& [reference_point, current_point] = pairs[index];
        const cv::Vec3d reference_delta = cv::Vec3d{
            reference_point.x, reference_point.y, reference_point.z} -
            reference_centroid;
        const cv::Vec3d current_delta = cv::Vec3d{
            current_point.x, current_point.y, current_point.z} -
            current_centroid;
        for (int row = 0; row < 3; ++row) {
            for (int column = 0; column < 3; ++column) {
                covariance(row, column) +=
                    reference_delta[row] * current_delta[column];
            }
        }
    }

    cv::Mat covariance_matrix(3, 3, CV_64F);
    for (int row = 0; row < 3; ++row) {
        for (int column = 0; column < 3; ++column) {
            covariance_matrix.at<double>(row, column) =
                covariance(row, column);
        }
    }
    cv::SVD decomposition(covariance_matrix, cv::SVD::FULL_UV);
    cv::Mat v = decomposition.vt.t();
    cv::Mat rotation = v * decomposition.u.t();
    if (cv::determinant(rotation) < 0.0) {
        v.col(2) *= -1.0;
        rotation = v * decomposition.u.t();
    }
    const cv::Matx33d rotation_value(
        rotation.at<double>(0, 0), rotation.at<double>(0, 1), rotation.at<double>(0, 2),
        rotation.at<double>(1, 0), rotation.at<double>(1, 1), rotation.at<double>(1, 2),
        rotation.at<double>(2, 0), rotation.at<double>(2, 1), rotation.at<double>(2, 2));
    const cv::Vec3d translation =
        current_centroid - rotation_value * reference_centroid;
    if (!std::isfinite(translation[0]) ||
        !std::isfinite(translation[1]) ||
        !std::isfinite(translation[2])) return false;
    camera_from_reference = matx44_from_rt(rotation, translation);
    return true;
}

double rigid_residual_m(
    const cv::Matx44d& camera_from_reference,
    const std::pair<cv::Point3f, cv::Point3f>& pair) {
    const auto& [reference_point, current_point] = pair;
    const auto predicted = camera_from_reference * cv::Vec4d{
        reference_point.x, reference_point.y, reference_point.z, 1.0};
    const cv::Vec3d delta{
        predicted[0] - static_cast<double>(current_point.x),
        predicted[1] - static_cast<double>(current_point.y),
        predicted[2] - static_cast<double>(current_point.z),
    };
    return std::sqrt(delta.dot(delta));
}

double rigid_rotation_deg(const cv::Matx44d& transform) {
    const double cosine = std::clamp(
        (transform(0, 0) + transform(1, 1) + transform(2, 2) - 1.0) * 0.5,
        -1.0, 1.0);
    return std::acos(cosine) * 180.0 / std::numbers::pi;
}

void estimate_stereo_se3(
    const Keyframe& reference,
    const std::optional<double> gyro_yaw_step_deg,
    VisualEstimate& estimate) {
    // Supersedes estimate_known_yaw_stereo_translation(: yaw is a check,
    // never a hard rotation prior. Legacy diagnostic token: STEREO_3D3D_KNOWN_YAW.
    estimate.stereo_translation_pairs =
        static_cast<int>(estimate.depth_pairs.size());
    estimate.stereo_translation_attempted =
        estimate.depth_pairs.size() >= kMinimumStereoTranslationPairs;
    if (!estimate.stereo_translation_attempted) return;

    const std::size_t pair_count = estimate.depth_pairs.size();
    cv::RNG random(
        static_cast<std::uint64_t>(reference.pair_index * 2654435761ULL) ^
        static_cast<std::uint64_t>(pair_count));
    std::vector<std::size_t> best_inliers;
    double best_residual = std::numeric_limits<double>::infinity();
    for (int iteration = 0; iteration < kStereoSe3RansacIterations; ++iteration) {
        std::vector<std::size_t> sample;
        sample.reserve(3);
        while (sample.size() < 3) {
            const auto index = static_cast<std::size_t>(
                random.uniform(0, static_cast<int>(pair_count)));
            if (std::find(sample.begin(), sample.end(), index) == sample.end()) {
                sample.push_back(index);
            }
        }
        const auto& first = estimate.depth_pairs[sample[0]];
        const auto& second = estimate.depth_pairs[sample[1]];
        const auto& third = estimate.depth_pairs[sample[2]];
        const cv::Vec3d reference_edge_a = cv::Vec3d{
            second.first.x - first.first.x,
            second.first.y - first.first.y,
            second.first.z - first.first.z};
        const cv::Vec3d reference_edge_b = cv::Vec3d{
            third.first.x - first.first.x,
            third.first.y - first.first.y,
            third.first.z - first.first.z};
        const cv::Vec3d current_edge_a = cv::Vec3d{
            second.second.x - first.second.x,
            second.second.y - first.second.y,
            second.second.z - first.second.z};
        const cv::Vec3d current_edge_b = cv::Vec3d{
            third.second.x - first.second.x,
            third.second.y - first.second.y,
            third.second.z - first.second.z};
        if (cv::norm(reference_edge_a.cross(reference_edge_b)) < 0.002 ||
            cv::norm(current_edge_a.cross(current_edge_b)) < 0.002) continue;

        cv::Matx44d candidate;
        if (!fit_rigid_transform(estimate.depth_pairs, sample, candidate)) continue;
        std::vector<std::size_t> inliers;
        std::vector<double> residuals;
        for (std::size_t index = 0; index < pair_count; ++index) {
            const double residual = rigid_residual_m(
                candidate, estimate.depth_pairs[index]);
            if (!std::isfinite(residual) ||
                residual > kStereoSe3RansacThresholdM) continue;
            inliers.push_back(index);
            residuals.push_back(residual);
        }
        const double residual = median_scalar(std::move(residuals));
        if (inliers.size() > best_inliers.size() ||
            (inliers.size() == best_inliers.size() &&
             residual < best_residual)) {
            best_inliers = std::move(inliers);
            best_residual = residual;
        }
    }

    estimate.stereo_translation_inliers =
        static_cast<int>(best_inliers.size());
    estimate.stereo_translation_inlier_ratio =
        static_cast<double>(best_inliers.size()) /
        static_cast<double>(std::max<std::size_t>(1, pair_count));
    if (estimate.stereo_translation_inliers <
            kMinimumStereoTranslationInliers ||
        estimate.stereo_translation_inlier_ratio <
            kMinimumStereoTranslationInlierRatio) return;

    cv::Matx44d camera_from_reference;
    if (!fit_rigid_transform(
            estimate.depth_pairs, best_inliers,
            camera_from_reference)) return;
    std::vector<double> refined_residuals;
    refined_residuals.reserve(best_inliers.size());
    for (const auto index : best_inliers) {
        refined_residuals.push_back(rigid_residual_m(
            camera_from_reference, estimate.depth_pairs[index]));
    }
    estimate.stereo_translation_residual_m =
        median_scalar(std::move(refined_residuals));
    estimate.stereo_rotation_deg = rigid_rotation_deg(camera_from_reference);
    estimate.stereo_world_from_camera = reference.world_from_camera *
        rigid_inverse(cv_to_y_up_transform(camera_from_reference));
    estimate.stereo_translation_m = translation_delta_m(
        reference.world_from_camera,
        estimate.stereo_world_from_camera);
    estimate.stereo_yaw_deg = signed_yaw_delta_deg(
        reference.world_from_camera,
        estimate.stereo_world_from_camera);
    if (gyro_yaw_step_deg && std::isfinite(*gyro_yaw_step_deg)) {
        estimate.stereo_gyro_yaw_checked = true;
        estimate.stereo_gyro_yaw_error_deg = std::abs(std::remainder(
            estimate.stereo_yaw_deg - *gyro_yaw_step_deg, 360.0));
    }
    const double vertical_step_m = std::abs(
        estimate.stereo_world_from_camera(1, 3) -
        reference.world_from_camera(1, 3));
    estimate.stereo_translation_valid =
        std::isfinite(estimate.stereo_translation_m) &&
        estimate.stereo_translation_m >= kMinimumWalkTranslationStepM &&
        estimate.stereo_translation_m <= kMaximumWalkTranslationStepM &&
        std::isfinite(estimate.stereo_translation_residual_m) &&
        estimate.stereo_translation_residual_m <=
            kMaximumStereoTranslationResidualM &&
        std::isfinite(estimate.stereo_rotation_deg) &&
        estimate.stereo_rotation_deg <= kMaximumStereoSe3RotationDeg &&
        vertical_step_m <= kMaximumStereoSe3VerticalStepM &&
        (!estimate.stereo_gyro_yaw_checked ||
         estimate.stereo_gyro_yaw_error_deg <=
            kMaximumStereoSe3GyroYawErrorDeg);
}

VisualEstimate estimate_visual(const Keyframe& reference,
                               const TrackingFrame& current) {
    VisualEstimate result;
    const auto matches = collect_matches(reference.frame, current);
    result.matches = matches.ratio_matches;
    result.mutual_matches = matches.mutual_matches;
    result.geometric_inliers = matches.geometric_inliers;
    result.depth_pairs = matches.depth_pairs;
    result.stereo_translation_pairs =
        static_cast<int>(matches.depth_pairs.size());
    if (matches.reference_pixels.size() >= static_cast<std::size_t>(kMinimumMatches)) {
        cv::Mat inlier_mask;
        const auto homography = cv::findHomography(
            matches.reference_pixels, matches.current_pixels,
            cv::RANSAC, 2.5, inlier_mask, 2500, 0.995);
        if (!homography.empty() && !inlier_mask.empty()) {
            result.homography_inliers = cv::countNonZero(inlier_mask);
            result.homography_inlier_ratio =
                static_cast<double>(result.homography_inliers) /
                static_cast<double>(std::max<std::size_t>(1, matches.reference_pixels.size()));
            if (result.homography_inliers >= kMinimumMatches &&
                result.homography_inlier_ratio >= 0.35) {
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
                const cv::Matx44d camera_from_reference =
                    matx44_from_rt(rotation, {0.0, 0.0, 0.0});
                const auto visual_pose = reference.world_from_camera *
                    rigid_inverse(cv_to_y_up_transform(camera_from_reference));
                result.visual_yaw_deg = signed_yaw_delta_deg(
                    reference.world_from_camera, visual_pose);
                result.homography_valid =
                    std::isfinite(result.visual_yaw_deg) &&
                    std::abs(result.visual_yaw_deg) <= kMaximumVisualYawStepDeg;
            }
        }
    }

    if (matches.pnp_object_points.size() >= static_cast<std::size_t>(kMinimumPnPPoints)) {
        cv::Mat rvec;
        cv::Mat tvec;
        cv::Mat inliers;
        const bool solved = cv::solvePnPRansac(
            matches.pnp_object_points, matches.pnp_image_points,
            camera_matrix(current), cv::noArray(), rvec, tvec, false,
            140, 3.0, 0.995, inliers, cv::SOLVEPNP_ITERATIVE);
        if (solved) {
            result.pnp_inliers = inliers.rows;
            result.pnp_inlier_ratio = static_cast<double>(result.pnp_inliers) /
                static_cast<double>(std::max<std::size_t>(1, matches.pnp_object_points.size()));
            if (result.pnp_inliers >= kMinimumPnPInliers &&
                result.pnp_inlier_ratio >= kMinimumPnPInlierRatio) {
                cv::Mat rotation;
                cv::Rodrigues(rvec, rotation);
                cv::Mat rotation64;
                cv::Mat translation64;
                rotation.convertTo(rotation64, CV_64F);
                tvec.convertTo(translation64, CV_64F);
                const auto camera_from_reference = matx44_from_rt(
                    rotation64,
                    {translation64.at<double>(0, 0),
                     translation64.at<double>(1, 0),
                     translation64.at<double>(2, 0)});
                result.sparse_depth_median_m = median_depth_residual(
                    camera_from_reference, matches.depth_pairs);
                result.pnp_world_from_camera = reference.world_from_camera *
                    rigid_inverse(cv_to_y_up_transform(camera_from_reference));
                result.translation_m = translation_delta_m(
                    reference.world_from_camera, result.pnp_world_from_camera);
                result.pnp_valid = result.translation_m <= 0.80 &&
                    (!std::isfinite(result.sparse_depth_median_m) ||
                     result.sparse_depth_median_m <= kMaximumSparseDepthMedianM);
            }
        }
    }
    return result;
}

std::string cloud_ply(
    const std::unordered_map<VoxelKey, VoxelAccumulator, VoxelKeyHash>& voxels,
    const std::uint32_t minimum_keyframes,
    const std::string& comment) {
    std::size_t count = 0;
    for (const auto& [key, voxel] : voxels) {
        static_cast<void>(key);
        if (voxel.pixel_samples > 0 && voxel.keyframe_observations >= minimum_keyframes) ++count;
    }
    std::ostringstream output;
    output << "ply\nformat ascii 1.0\n"
           << "comment MaklerTour " << comment << "\n"
           << "comment coordinate_system X_right_Y_up_Z_forward_meters\n"
           << "element vertex " << count << "\n"
           << "property float x\nproperty float y\nproperty float z\n"
           << "property uchar red\nproperty uchar green\nproperty uchar blue\n"
           << "property uint pixel_samples\n"
           << "property uint keyframe_observations\n"
           << "property uint keyframe_id\n"
           << "property uint segment_id\n"
           << "end_header\n";
    output << std::fixed << std::setprecision(6);
    for (const auto& [key, voxel] : voxels) {
        static_cast<void>(key);
        if (voxel.pixel_samples == 0 || voxel.keyframe_observations < minimum_keyframes) continue;
        const double scale = 1.0 / static_cast<double>(voxel.pixel_samples);
        const auto position = voxel.position_sum * scale;
        const auto colour = voxel.colour_sum * scale;
        output << position[0] << ' ' << position[1] << ' ' << position[2] << ' '
               << static_cast<int>(cv::saturate_cast<std::uint8_t>(colour[0])) << ' '
               << static_cast<int>(cv::saturate_cast<std::uint8_t>(colour[1])) << ' '
               << static_cast<int>(cv::saturate_cast<std::uint8_t>(colour[2])) << ' '
               << voxel.pixel_samples << ' ' << voxel.keyframe_observations << ' '
               << voxel.last_keyframe_id << ' ' << voxel.last_segment_id << '\n';
    }
    return output.str();
}

std::string local_keyframe_ply(const MapJob& job, const bool world_space,
                               const cv::Matx44d& world_from_camera,
                               const std::uint64_t keyframe_id) {
    struct Point { cv::Vec3d position; cv::Vec3b colour; };
    std::vector<Point> points;
    const int stride = 2;
    for (int row = 0; row < job.disparity.rows; row += stride) {
        const auto* disparity_row = job.disparity.ptr<float>(row);
        const auto* mask_row = job.mask.ptr<std::uint8_t>(row);
        const auto* colour_row = job.colour.ptr<cv::Vec3b>(row);
        for (int column = 0; column < job.disparity.cols; column += stride) {
            if (mask_row[column] == 0) continue;
            const double disparity = static_cast<double>(disparity_row[column]);
            if (!std::isfinite(disparity) || disparity <= 1.0) continue;
            const double z = job.focal_px * job.baseline_mm / disparity / 1000.0;
            if (!std::isfinite(z) || z < kNearMeters || z > kFarMeters) continue;
            cv::Vec3d point{
                (static_cast<double>(column) - job.principal_x_px) * z / job.focal_px,
                -(static_cast<double>(row) - job.principal_y_px) * z / job.focal_px,
                z,
            };
            if (world_space) point = transform_point(world_from_camera, point);
            points.push_back({point, colour_row[column]});
        }
    }
    std::ostringstream output;
    output << "ply\nformat ascii 1.0\n"
           << "comment MaklerTour keyframe " << keyframe_id << (world_space ? " world" : " local") << "\n"
           << "element vertex " << points.size() << "\n"
           << "property float x\nproperty float y\nproperty float z\n"
           << "property uchar red\nproperty uchar green\nproperty uchar blue\nend_header\n";
    output << std::fixed << std::setprecision(6);
    for (const auto& point : points) {
        output << point.position[0] << ' ' << point.position[1] << ' ' << point.position[2] << ' '
               << static_cast<int>(point.colour[2]) << ' '
               << static_cast<int>(point.colour[1]) << ' '
               << static_cast<int>(point.colour[0]) << '\n';
    }
    return output.str();
}

nlohmann::json trajectory_json(const std::vector<TrajectorySample>& trajectory) {
    nlohmann::json samples = nlohmann::json::array();
    for (const auto& sample : trajectory) {
        samples.push_back({
            {"keyframe_id", sample.keyframe_id},
            {"pair_index", sample.pair_index},
            {"segment_id", sample.segment_id},
            {"state", sample.state},
            {"method", sample.method},
            {"world_from_camera", pose_json(sample.world_from_camera)},
            {"position_m", nlohmann::json::array({
                sample.world_from_camera(0, 3), sample.world_from_camera(1, 3),
                sample.world_from_camera(2, 3)})},
            {"yaw_deg", pose_yaw_deg(sample.world_from_camera)},
            {"gyro_yaw_step_deg", sample.gyro_yaw_step_deg},
            {"visual_yaw_step_deg", sample.visual_yaw_step_deg},
            {"fused_yaw_step_deg", sample.fused_yaw_step_deg},
            {"accumulated_yaw_deg", sample.accumulated_yaw_deg},
            {"translation_from_previous_m", sample.translation_m},
            {"matches", sample.matches},
            {"inliers", sample.inliers},
            {"inlier_ratio", sample.inlier_ratio},
            {"reprojection_rmse_px", sample.reprojection_rmse_px},
            {"timestamp_ms", sample.timestamp_ms},
        });
    }
    return {
        {"schema_version", 3},
        {"tracking", "GYRO_ASSISTED_RECONNECT_SAFE"},
        {"coordinate_system", "X_right_Y_up_Z_forward_meters"},
        {"samples", std::move(samples)},
    };
}

std::string trajectory_ply(const std::vector<TrajectorySample>& trajectory) {
    const std::size_t edge_count = trajectory.size() > 1 ? trajectory.size() - 1 : 0;
    std::ostringstream output;
    output << "ply\nformat ascii 1.0\n"
           << "comment MaklerTour gyro assisted camera trajectory\n"
           << "element vertex " << trajectory.size() << "\n"
           << "property float x\nproperty float y\nproperty float z\n"
           << "property uchar red\nproperty uchar green\nproperty uchar blue\n"
           << "property uint keyframe_id\nproperty uint segment_id\n"
           << "element edge " << edge_count << "\n"
           << "property int vertex1\nproperty int vertex2\nend_header\n";
    for (const auto& sample : trajectory) {
        output << sample.world_from_camera(0, 3) << ' '
               << sample.world_from_camera(1, 3) << ' '
               << sample.world_from_camera(2, 3) << ' '
               << (sample.method.find("GYRO") != std::string::npos ? "0 255 255 " : "255 255 0 ")
               << sample.keyframe_id << ' ' << sample.segment_id << '\n';
    }
    for (std::size_t index = 1; index < trajectory.size(); ++index) {
        output << index - 1 << ' ' << index << '\n';
    }
    return output.str();
}

}  // namespace

struct AccumulatedMapRuntime::Impl {
    explicit Impl(std::filesystem::path path)
        : session_directory(std::move(path)),
          diagnostics(session_directory / "accumulated_map.jsonl", std::ios::app),
          pose_validation(session_directory / "pose_validation.jsonl", std::ios::app),
          apriltag_anchors(session_directory) {
        if (!diagnostics || !pose_validation) {
            throw std::runtime_error("cannot create accumulated map diagnostics");
        }
        std::filesystem::create_directories(session_directory / "keyframes");
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
        }
    }

    void accept_imu(const nlohmann::json& sample) {
        try {
            if (!sample.contains("gyroscope_rad_s") ||
                !sample.at("gyroscope_rad_s").is_array() ||
                sample.at("gyroscope_rad_s").size() != 3) {
                std::scoped_lock lock(mutex);
                ++imu_invalid_samples;
                return;
            }
            const auto& gyro = sample.at("gyroscope_rad_s");
            const auto timestamp = sample.value(
                "host_aligned_timestamp_ns", std::int64_t{0});
            if (timestamp <= 0) {
                std::scoped_lock lock(mutex);
                ++imu_invalid_samples;
                return;
            }
            int axis = 1;
            std::optional<double> acceleration_delta_mps2;
            if (sample.contains("accelerometer_mps2") &&
                sample.at("accelerometer_mps2").is_array() &&
                sample.at("accelerometer_mps2").size() == 3) {
                const auto& acceleration = sample.at("accelerometer_mps2");
                double largest = 0.0;
                double norm_squared = 0.0;
                for (int index = 0; index < 3; ++index) {
                    const double component =
                        acceleration.at(static_cast<std::size_t>(index)).get<double>();
                    const double value = std::abs(component);
                    norm_squared += component * component;
                    if (value > largest) {
                        largest = value;
                        axis = index;
                    }
                }
                const double magnitude = std::sqrt(norm_squared);
                if (std::isfinite(magnitude)) {
                    acceleration_delta_mps2 = std::abs(magnitude - 9.80665);
                }
            }
            const double rate =
                gyro.at(static_cast<std::size_t>(axis)).get<double>();
            if (!std::isfinite(rate)) {
                std::scoped_lock lock(mutex);
                ++imu_invalid_samples;
                return;
            }
            const std::string session = sample.value(
                "session_id", std::string{});
            std::scoped_lock lock(mutex);
            if (!session.empty() && session != imu_session_id) {
                imu_session_id = session;
                last_imu_timestamp_ns = 0;
                imu_ready = false;
                gyro_bias_ready = false;
                gyro_bias_calibration_sum_rad_s = 0.0;
                gyro_bias_calibration_samples = 0;
                gyro_bias_rad_s = 0.0;
                ++imu_session_changes;
            }
            if (acceleration_delta_mps2) {
                acceleration_motion_mps2 = acceleration_motion_mps2 * 0.85 +
                    *acceleration_delta_mps2 * 0.15;
                accelerometer_valid = true;
            }
            if (!gyro_bias_ready) {
                const bool acceleration_safe =
                    !acceleration_delta_mps2 ||
                    *acceleration_delta_mps2 <=
                        kGyroBiasCalibrationMaximumAccelerationDeltaMps2;
                if (acceleration_safe &&
                    std::abs(rate) <= kGyroBiasCalibrationMaximumRateRadS) {
                    gyro_bias_calibration_sum_rad_s += rate;
                    ++gyro_bias_calibration_samples;
                    gyro_bias_rad_s = gyro_bias_calibration_sum_rad_s /
                        static_cast<double>(gyro_bias_calibration_samples);
                    if (gyro_bias_calibration_samples >=
                        kGyroBiasCalibrationSamples) {
                        gyro_bias_ready = true;
                        imu_ready = true;
                    }
                }
                last_imu_timestamp_ns = timestamp;
                last_gyro_rate_rad_s = rate;
                imu_axis = axis;
                ++imu_samples;
                return;
            }
            if (last_imu_timestamp_ns > 0) {
                const double dt = static_cast<double>(
                    timestamp - last_imu_timestamp_ns) / 1'000'000'000.0;
                if (dt > 0.0 && dt <= kGyroMaximumDtSeconds) {
                    gyro_yaw_uncalibrated_deg += rate * dt *
                        180.0 / std::numbers::pi;
                    gyro_yaw_raw_deg += (rate - gyro_bias_rad_s) * dt *
                        180.0 / std::numbers::pi;
                    imu_ready = true;
                }
            }
            last_imu_timestamp_ns = timestamp;
            last_gyro_rate_rad_s = rate;
            imu_axis = axis;
            ++imu_samples;
        } catch (...) {
            std::scoped_lock lock(mutex);
            ++imu_invalid_samples;
        }
    }

    void notify_camera_event(const std::size_t slot_index,
                             const std::string& event,
                             const std::string& device_id) {
        nlohmann::json diagnostic;
        {
            std::scoped_lock lock(mutex);
            if (slot_index >= camera_connected.size()) return;
            if (event == "DISCONNECTED") {
                if (camera_connected[slot_index]) {
                    camera_connected[slot_index] = false;
                    ++segment_id;
                    segment_resume_pending = true;
                    ++disconnect_boundaries;
                }
            } else if (event == "CONNECTED") {
                camera_connected[slot_index] = true;
                camera_device_ids[slot_index] = device_id;
            }
            diagnostic = {
                {"event", "ACCUMULATED_MAP_CAMERA_EVENT"},
                {"camera_slot", slot_index},
                {"camera_event", event},
                {"device_id", device_id},
                {"segment_id", segment_id},
                {"preserved_keyframes", keyframe_count},
                {"preserved_voxels", accumulated_points_raw},
            };
        }
        append_diagnostic(std::move(diagnostic));
    }

    bool submit(const std::uint64_t pair_index,
                std::string source_profile,
                const StereoDepthResult& depth) {
        const auto now = std::chrono::steady_clock::now();
        std::scoped_lock lock(mutex);
        ++submitted_frames;
        if (source_profile != "HIGH_640") { ++rejected_profile_frames; return false; }
        if (pending) { ++rejected_busy_frames; return false; }
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
        job.pair_index = pair_index;
        job.segment_id = segment_id;
        job.source_profile = std::move(source_profile);
        job.colour = depth.work_a.clone();
        job.disparity = depth.geometry_disparity.clone();
        job.mask = depth.geometry_mask.clone();
        if (!depth.strict_geometry_disparity.empty() &&
            !depth.strict_geometry_mask.empty()) {
            job.strict_disparity = depth.strict_geometry_disparity.clone();
            job.strict_mask = depth.strict_geometry_mask.clone();
        }
        job.focal_px = depth.focal_px;
        job.baseline_mm = depth.baseline_mm;
        job.principal_x_px = depth.principal_x_px;
        job.principal_y_px = depth.principal_y_px;
        job.gyro_valid = imu_ready;
        job.gyro_bias_ready = gyro_bias_ready;
        job.gyro_bias_rad_s = gyro_bias_rad_s;
        job.gyro_yaw_uncalibrated_deg = gyro_yaw_uncalibrated_deg;
        job.gyro_yaw_raw_deg = gyro_yaw_raw_deg;
        job.gyro_bias_calibration_samples =
            gyro_bias_calibration_samples;
        job.gyro_samples = imu_samples;
        job.accelerometer_valid = accelerometer_valid;
        job.acceleration_motion_mps2 = acceleration_motion_mps2;
        job.segment_resume = segment_resume_pending;
        pending = std::move(job);
        last_accepted_submission = now;
        ++accepted_frames;
        condition.notify_one();
        return true;
    }

    void submit_apriltag_pair(
        StereoPreviewPair pair,
        ResolvedCalibration calibration) {
        apriltag_anchors.submit(
            std::move(pair), std::move(calibration));
    }

    void reset() {
        {
            std::scoped_lock lock(mutex);
            ++generation;
            pending.reset();
            clear_requested = true;
            ready = false;
            state = "WAITING";
            last_error.clear();
        }
        condition.notify_all();
    }

    nlohmann::json status_json() const {
        std::scoped_lock lock(mutex);
        return status_json_locked();
    }

    nlohmann::json status_json_locked() const {
        return {
            {"state", state},
            {"ready", ready},
            {"tracking_mode", "AUTO_MOTION_FULL_STEREO_SE3_PROVISIONAL_BRIDGE_APRILTAG_ANCHORS"},
            {"apriltag_anchor", apriltag_anchors.status_json()},
            {"motion_mode", motion_mode_name(motion_mode)},
            {"motion_rotation_votes", rotation_motion_votes},
            {"motion_walk_votes", walk_motion_votes},
            {"tracking_buffer_frames", tracking_buffer_frames},
            {"recovery_attempts", recovery_attempts_total},
            {"recovery_successes", recovery_successes},
            {"last_recovery_reference_pair", last_recovery_reference_pair},
            {"coasting_frames", coasting_frames},
            {"translation_uncertain_frames", translation_uncertain_frames},
            {"geometry_suppressed_frames", geometry_suppressed_frames},
            {"stereo_translation_attempts", stereo_translation_attempts},
            {"stereo_translation_successes", stereo_translation_successes},
            {"provisional_tracking_frames", provisional_tracking_frames},
            {"provisional_promotions", provisional_promotions},
            {"maximum_provisional_chain", maximum_provisional_chain},
            {"recommended_profile", "HIGH_640"},
            {"source_profile", source_profile},
            {"segment_id", segment_id},
            {"disconnect_boundaries", disconnect_boundaries},
            {"keyframe_count", keyframe_count},
            {"trajectory_samples", trajectory_samples},
            {"accumulated_points_raw", accumulated_points_raw},
            {"accumulated_points_multiview", accumulated_points_multiview},
            {"temporal_strict_points_raw", temporal_strict_points_raw},
            {"temporal_strict_points_multiview",
             temporal_strict_points_multiview},
            {"voxel_size_m", kVoxelMeters},
            {"tracking_method", last_method},
            {"last_pair_index", last_pair_index},
            {"last_reference_keyframe_id", last_reference_keyframe_id},
            {"matches", last_matches},
            {"inliers", last_inliers},
            {"inlier_ratio", last_inlier_ratio},
            {"gyro_axis", imu_axis},
            {"gyro_samples", imu_samples},
            {"gyro_invalid_samples", imu_invalid_samples},
            {"gyro_session_changes", imu_session_changes},
            {"gyro_bias_mode", "INITIAL_STILLNESS_FREEZE"},
            {"gyro_bias_ready", gyro_bias_ready},
            {"gyro_bias_calibration_samples",
             gyro_bias_calibration_samples},
            {"gyro_bias_calibration_required_samples",
             kGyroBiasCalibrationSamples},
            {"gyro_bias_rad_s", gyro_bias_rad_s},
            {"gyro_yaw_uncalibrated_deg",
             gyro_yaw_uncalibrated_deg},
            {"gyro_yaw_raw_deg", gyro_yaw_raw_deg},
            {"gyro_yaw_bias_removed_deg",
             gyro_yaw_uncalibrated_deg - gyro_yaw_raw_deg},
            {"accelerometer_valid", accelerometer_valid},
            {"acceleration_motion_mps2", acceleration_motion_mps2},
            {"gyro_to_camera_sign", gyro_to_camera_sign},
            {"gyro_sign_locked", gyro_sign_locked},
            {"gyro_yaw_step_deg", last_gyro_yaw_step_deg},
            {"visual_yaw_step_deg", last_visual_yaw_step_deg},
            {"fused_yaw_step_deg", last_fused_yaw_step_deg},
            {"accumulated_yaw_deg", accumulated_yaw_deg},
            {"translation_from_previous_m", last_translation_m},
            {"last_rejection_reason", last_rejection_reason},
            {"processing_ms", last_processing_ms},
            {"submitted_frames", submitted_frames},
            {"accepted_frames", accepted_frames},
            {"processed_frames", processed_frames},
            {"failed_frames", failed_frames},
            {"lost_frames", lost_frames},
            {"stationary_frames", stationary_frames},
            {"gyro_only_keyframes", gyro_only_keyframes},
            {"gyro_visual_keyframes", gyro_visual_keyframes},
            {"segment_resume_keyframes", segment_resume_keyframes},
            {"rejected_profile_frames", rejected_profile_frames},
            {"rejected_busy_frames", rejected_busy_frames},
            {"rejected_interval_frames", rejected_interval_frames},
            {"rejected_invalid_frames", rejected_invalid_frames},
            {"generation", generation},
            {"coordinate_system", "X_right_Y_up_Z_forward_meters"},
            {"point_cloud_file", "point_cloud_accumulated_raw.ply"},
            {"multiview_point_cloud_file", "point_cloud_accumulated_multiview.ply"},
            {"temporal_strict_point_cloud_file",
             "point_cloud_accumulated_temporal_strict_raw.ply"},
            {"temporal_strict_multiview_point_cloud_file",
             "point_cloud_accumulated_temporal_strict_multiview.ply"},
            {"trajectory_file", "camera_trajectory.json"},
            {"last_error", last_error},
        };
    }

    void append_diagnostic(nlohmann::json value) {
        value["ts_unix_ms"] = unix_time_ms();
        std::scoped_lock lock(output_mutex);
        diagnostics << value.dump() << '\n';
        diagnostics.flush();
    }

    void append_pose_validation(nlohmann::json value) {
        value["ts_unix_ms"] = unix_time_ms();
        std::scoped_lock lock(output_mutex);
        pose_validation << value.dump() << '\n';
        pose_validation.flush();
    }

    void write_status_file() const {
        write_text_atomic(session_directory / "accumulated_map_status.json",
                          status_json().dump(2) + "\n");
    }

    void clear_worker_state() {
        registration_keyframes.clear();
        tracking_buffer.clear();
        trajectory.clear();
        voxels.clear();
        temporal_strict_voxels.clear();
        apriltag_anchors.reset();
        next_keyframe_id = 1;
        worker_accumulated_yaw_deg = 0.0;
        reset_tripod_constraint();
        gyro_to_camera_sign = 1.0;
        gyro_sign_locked = false;
        {
            std::scoped_lock lock(mutex);
            motion_mode = MotionMode::Unknown;
            rotation_motion_votes = 0;
            walk_motion_votes = 0;
            tracking_buffer_frames = 0;
            last_recovery_reference_pair = 0;
            translation_uncertain_frames = 0;
            geometry_suppressed_frames = 0;
            stereo_translation_attempts = 0;
            stereo_translation_successes = 0;
            provisional_tracking_frames = 0;
            provisional_promotions = 0;
            maximum_provisional_chain = 0;
        }
        segment_id = 1;
        segment_resume_pending = false;
        last_publish = {};
        std::error_code error;
        std::filesystem::remove_all(session_directory / "keyframes", error);
        std::filesystem::create_directories(session_directory / "keyframes", error);
        for (const auto* name : {
                 "point_cloud_accumulated.ply",
                 "point_cloud_accumulated_raw.ply",
                 "point_cloud_accumulated_multiview.ply",
                 "point_cloud_accumulated_temporal_strict_raw.ply",
                 "point_cloud_accumulated_temporal_strict_multiview.ply",
                 "camera_trajectory.json",
                 "camera_trajectory.ply",
             }) {
            error.clear();
            std::filesystem::remove(session_directory / name, error);
        }
    }

    void merge_keyframe(
        const MapJob& job,
        const cv::Matx44d& world_from_camera,
        const std::uint64_t keyframe_id,
        std::unordered_map<VoxelKey, VoxelAccumulator, VoxelKeyHash>&
            target_voxels) {
        const int stride = job.disparity.total() > 1000000 ? 3 : 2;
        std::unordered_set<VoxelKey, VoxelKeyHash> observed_this_keyframe;
        for (int row = 0; row < job.disparity.rows; row += stride) {
            const auto* disparity_row = job.disparity.ptr<float>(row);
            const auto* mask_row = job.mask.ptr<std::uint8_t>(row);
            const auto* colour_row = job.colour.ptr<cv::Vec3b>(row);
            for (int column = 0; column < job.disparity.cols; column += stride) {
                if (mask_row[column] == 0) continue;
                const double disparity = static_cast<double>(disparity_row[column]);
                if (!std::isfinite(disparity) || disparity <= 1.0) continue;
                const double z = job.focal_px * job.baseline_mm / disparity / 1000.0;
                if (!std::isfinite(z) || z < kNearMeters || z > kFarMeters) continue;
                const cv::Vec3d camera_point{
                    (static_cast<double>(column) - job.principal_x_px) * z / job.focal_px,
                    -(static_cast<double>(row) - job.principal_y_px) * z / job.focal_px,
                    z,
                };
                const auto world = transform_point(world_from_camera, camera_point);
                if (!std::isfinite(world[0]) || !std::isfinite(world[1]) ||
                    !std::isfinite(world[2]) || std::abs(world[0]) > 20.0 ||
                    std::abs(world[1]) > 10.0 || std::abs(world[2]) > 20.0) continue;
                const VoxelKey key{
                    static_cast<int>(std::floor(world[0] / kVoxelMeters)),
                    static_cast<int>(std::floor(world[1] / kVoxelMeters)),
                    static_cast<int>(std::floor(world[2] / kVoxelMeters)),
                };
                auto iterator = target_voxels.find(key);
                if (iterator == target_voxels.end()) {
                    if (target_voxels.size() >= kMaximumVoxels) continue;
                    iterator = target_voxels.emplace(
                        key, VoxelAccumulator{}).first;
                }
                auto& voxel = iterator->second;
                voxel.position_sum += world;
                const auto bgr = colour_row[column];
                voxel.colour_sum += cv::Vec3d{
                    static_cast<double>(bgr[2]),
                    static_cast<double>(bgr[1]),
                    static_cast<double>(bgr[0]),
                };
                if (voxel.pixel_samples < std::numeric_limits<std::uint32_t>::max()) {
                    ++voxel.pixel_samples;
                }
                if (observed_this_keyframe.insert(key).second) {
                    if (voxel.keyframe_observations <
                        std::numeric_limits<std::uint32_t>::max()) {
                        ++voxel.keyframe_observations;
                    }
                    voxel.last_keyframe_id = keyframe_id;
                    voxel.last_segment_id = job.segment_id;
                }
            }
        }
    }

    void publish_outputs(const bool force) {
        if (trajectory.empty() || voxels.empty()) return;
        const auto now = std::chrono::steady_clock::now();
        if (!force && last_publish.time_since_epoch().count() != 0 &&
            now - last_publish < kMinimumPublishInterval) return;
        const auto raw = cloud_ply(voxels, 1, "gyro assisted raw accumulated metric cloud");
        const auto multiview = cloud_ply(
            voxels, kMinimumMultiviewKeyframes,
            "gyro assisted multi-keyframe confirmed metric cloud");
        write_text_atomic(session_directory / "point_cloud_accumulated_raw.ply", raw);
        write_text_atomic(session_directory / "point_cloud_accumulated.ply", raw);
        write_text_atomic(session_directory / "point_cloud_accumulated_multiview.ply", multiview);
        if (!temporal_strict_voxels.empty()) {
            write_text_atomic(
                session_directory /
                    "point_cloud_accumulated_temporal_strict_raw.ply",
                cloud_ply(
                    temporal_strict_voxels, 1,
                    "TEMPORAL STRICT raw accumulated metric cloud"));
            write_text_atomic(
                session_directory /
                    "point_cloud_accumulated_temporal_strict_multiview.ply",
                cloud_ply(
                    temporal_strict_voxels, kMinimumMultiviewKeyframes,
                    "TEMPORAL STRICT multi-keyframe overlap cloud"));
        }
        write_text_atomic(session_directory / "camera_trajectory.json",
                          trajectory_json(trajectory).dump(2) + "\n");
        write_text_atomic(session_directory / "camera_trajectory.ply",
                          trajectory_ply(trajectory));
        last_publish = now;
    }

    MotionMode motion_mode_snapshot() const {
        std::scoped_lock lock(mutex);
        return motion_mode;
    }

    static double tripod_translation_limit(const double yaw_deg) {
        const double chord = 2.0 * kTripodPivotRadiusM *
            std::sin(std::abs(yaw_deg) * std::numbers::pi / 360.0);
        return std::max(0.04, chord * 1.8);
    }

    static bool pnp_walk_translation_safe(const VisualEstimate& visual) {
        return visual.pnp_valid && std::isfinite(visual.translation_m) &&
            visual.translation_m >= kMinimumWalkTranslationStepM &&
            visual.translation_m <= kMaximumWalkTranslationStepM &&
            visual.pnp_inliers >= kMinimumWalkPnPInliers &&
            visual.pnp_inlier_ratio >= kMinimumWalkPnPInlierRatio &&
            (!std::isfinite(visual.sparse_depth_median_m) ||
             visual.sparse_depth_median_m <= kMaximumWalkSparseDepthMedianM);
    }

    static bool stereo_walk_translation_safe(const VisualEstimate& visual) {
        return visual.stereo_translation_valid &&
            std::isfinite(visual.stereo_translation_m) &&
            visual.stereo_translation_m >= kMinimumWalkTranslationStepM &&
            visual.stereo_translation_m <= kMaximumWalkTranslationStepM &&
            visual.stereo_translation_inliers >=
                kMinimumStereoTranslationInliers &&
            visual.stereo_translation_inlier_ratio >=
                kMinimumStereoTranslationInlierRatio &&
            std::isfinite(visual.stereo_translation_residual_m) &&
            visual.stereo_translation_residual_m <=
                kMaximumStereoTranslationResidualM;
    }

    static bool pnp_confirms_stereo(VisualEstimate& visual) {
        if (!pnp_walk_translation_safe(visual) ||
            !stereo_walk_translation_safe(visual)) return false;
        const double pose_disagreement_m = translation_delta_m(
            visual.pnp_world_from_camera,
            visual.stereo_world_from_camera);
        const double yaw_disagreement_deg = std::abs(signed_yaw_delta_deg(
            visual.pnp_world_from_camera,
            visual.stereo_world_from_camera));
        visual.pnp_stereo_confirmed =
            pose_disagreement_m <= kMaximumPnpStereoPoseDisagreementM &&
            yaw_disagreement_deg <=
                kMaximumPnpStereoYawDisagreementDeg;
        return visual.pnp_stereo_confirmed;
    }

    static bool walk_translation_safe(const VisualEstimate& visual) {
        return stereo_walk_translation_safe(visual);
    }

    static double walk_translation_m(const VisualEstimate& visual) {
        return visual.stereo_translation_m;
    }

    static cv::Matx44d walk_world_from_camera(
        const VisualEstimate& visual) {
        return visual.stereo_world_from_camera;
    }

    static const char* walk_translation_source(
        const VisualEstimate& visual) {
        return visual.pnp_stereo_confirmed
            ? "STEREO_SE3_PNP_CONFIRMED"
            : "STEREO_SE3_RANSAC";
    }

    static double tripod_horizontal_step_limit(const double yaw_deg) {
        const double chord = 2.0 * kTripodPivotRadiusM *
            std::sin(std::abs(yaw_deg) * std::numbers::pi / 360.0);
        return std::min(
            kTripodMaximumHorizontalStepM,
            chord * 1.8 + kTripodHorizontalStepToleranceM);
    }

    static double tripod_total_position_limit(const double yaw_from_anchor_deg) {
        const double wrapped_yaw = std::abs(
            std::remainder(yaw_from_anchor_deg, 360.0));
        const double chord = 2.0 * kTripodPivotRadiusM *
            std::sin(wrapped_yaw * std::numbers::pi / 360.0);
        return std::min(
            2.0 * kTripodPivotRadiusM,
            chord + kTripodReturnPositionToleranceM);
    }

    static bool rotation_pnp_translation_safe(
        const VisualEstimate& visual,
        const double candidate_translation_m,
        const double maximum_translation_m,
        std::string& rejection_reason) {
        if (!visual.pnp_valid) {
            rejection_reason = "PNP_POSE_INVALID";
            return false;
        }
        if (!std::isfinite(candidate_translation_m)) {
            rejection_reason = "PNP_TRANSLATION_NONFINITE";
            return false;
        }
        if (visual.pnp_inliers < kMinimumPnPInliers ||
            visual.pnp_inlier_ratio < kMinimumPnPInlierRatio) {
            rejection_reason = "PNP_SUPPORT_TOO_WEAK";
            return false;
        }
        if (std::isfinite(visual.sparse_depth_median_m) &&
            visual.sparse_depth_median_m > kMaximumSparseDepthMedianM) {
            rejection_reason = "PNP_DEPTH_RESIDUAL_TOO_LARGE";
            return false;
        }
        if (candidate_translation_m > maximum_translation_m) {
            rejection_reason = "PNP_TRANSLATION_EXCEEDS_TRIPOD_BOUND";
            return false;
        }
        rejection_reason.clear();
        return true;
    }

    void reset_tripod_constraint() {
        tripod_constraint_active = false;
        tripod_constraint_segment_id = 0;
        tripod_anchor_position_world_m = {0.0, 0.0, 0.0};
        tripod_anchor_yaw_deg = 0.0;
    }

    void apply_tripod_rotation_constraint(
        PoseDecision& decision,
        const MapJob& job) {
        if (!decision.valid) return;
        if (!decision.rotation_only ||
            decision.method == "AUTO_WALK_GYRO_COAST") {
            reset_tripod_constraint();
            if (decision.method == "AUTO_WALK_GYRO_COAST") {
                decision.rotation_translation_rejection_reason =
                    "ACTIVE_WALK_MODE";
            }
            return;
        }

        const auto& reference = decision.reference_world_from_camera;
        if (!tripod_constraint_active ||
            tripod_constraint_segment_id != job.segment_id) {
            tripod_constraint_active = true;
            tripod_constraint_segment_id = job.segment_id;
            tripod_anchor_position_world_m = {
                reference(0, 3), reference(1, 3), reference(2, 3)};
            tripod_anchor_yaw_deg = pose_yaw_deg(reference);
        }

        decision.rotation_translation_locked_y_m =
            tripod_anchor_position_world_m[1];
        decision.rotation_translation_yaw_from_anchor_deg = std::remainder(
            pose_yaw_deg(decision.world_from_camera) - tripod_anchor_yaw_deg,
            360.0);
        decision.rotation_translation_limit_m = tripod_translation_limit(
            decision.fused_yaw_step_deg);
        decision.rotation_translation_horizontal_step_limit_m =
            tripod_horizontal_step_limit(decision.fused_yaw_step_deg);
        decision.rotation_translation_total_limit_m =
            tripod_total_position_limit(
                decision.rotation_translation_yaw_from_anchor_deg);

        if (decision.visual.pnp_valid) {
            decision.rotation_translation_vector_world_m = {
                decision.visual.pnp_world_from_camera(0, 3) - reference(0, 3),
                decision.visual.pnp_world_from_camera(1, 3) - reference(1, 3),
                decision.visual.pnp_world_from_camera(2, 3) - reference(2, 3),
            };
            decision.rotation_translation_candidate_m = std::sqrt(
                decision.rotation_translation_vector_world_m.dot(
                    decision.rotation_translation_vector_world_m));
            decision.rotation_translation_horizontal_candidate_m = std::hypot(
                decision.rotation_translation_vector_world_m[0],
                decision.rotation_translation_vector_world_m[2]);
        }

        if (decision.motion_evidence != MotionMode::Rotation) {
            decision.rotation_translation_rejection_reason =
                "NO_ROTATION_EVIDENCE";
            return;
        }
        if (!rotation_pnp_translation_safe(
                decision.visual,
                decision.rotation_translation_candidate_m,
                decision.rotation_translation_limit_m,
                decision.rotation_translation_rejection_reason)) {
            return;
        }

        double target_x = reference(0, 3) +
            (decision.visual.pnp_world_from_camera(0, 3) - reference(0, 3)) *
                kTripodHorizontalSmoothing;
        double target_z = reference(2, 3) +
            (decision.visual.pnp_world_from_camera(2, 3) - reference(2, 3)) *
                kTripodHorizontalSmoothing;

        double step_x = target_x - reference(0, 3);
        double step_z = target_z - reference(2, 3);
        double step_m = std::hypot(step_x, step_z);
        if (step_m > decision.rotation_translation_horizontal_step_limit_m &&
            step_m > 0.0) {
            const double scale =
                decision.rotation_translation_horizontal_step_limit_m / step_m;
            step_x *= scale;
            step_z *= scale;
            target_x = reference(0, 3) + step_x;
            target_z = reference(2, 3) + step_z;
        }

        double anchor_x = target_x - tripod_anchor_position_world_m[0];
        double anchor_z = target_z - tripod_anchor_position_world_m[2];
        double radius_m = std::hypot(anchor_x, anchor_z);
        if (radius_m > decision.rotation_translation_total_limit_m &&
            radius_m > 0.0) {
            const double scale =
                decision.rotation_translation_total_limit_m / radius_m;
            anchor_x *= scale;
            anchor_z *= scale;
            target_x = tripod_anchor_position_world_m[0] + anchor_x;
            target_z = tripod_anchor_position_world_m[2] + anchor_z;
        }

        decision.world_from_camera(0, 3) = target_x;
        decision.world_from_camera(1, 3) =
            tripod_anchor_position_world_m[1];
        decision.world_from_camera(2, 3) = target_z;
        decision.rotation_translation_horizontal_step_m = std::hypot(
            target_x - reference(0, 3),
            target_z - reference(2, 3));
        decision.rotation_translation_total_radius_m = std::hypot(
            target_x - tripod_anchor_position_world_m[0],
            target_z - tripod_anchor_position_world_m[2]);
        decision.translation_m = decision.rotation_translation_horizontal_step_m;
        decision.rotation_translation_applied = true;
        decision.rotation_translation_rejection_reason.clear();
        decision.method += "_PNP_TRANSLATION_XZ_CONSTRAINED";
    }

    static MotionMode classify_motion_evidence(const PoseDecision& decision,
                                                const MapJob& job) {
        const bool rotation_available = decision.used_gyro || decision.used_visual;
        const bool walk_safe = walk_translation_safe(decision.visual);
        const double translation_m = walk_safe
            ? walk_translation_m(decision.visual)
            : 0.0;
        const double pivot_limit = tripod_translation_limit(
            decision.fused_yaw_step_deg);
        const bool inertial_walk = job.accelerometer_valid &&
            job.acceleration_motion_mps2 >= kMinimumAccelerationMotionMps2;
        if (inertial_walk) return MotionMode::Walk;
        if (walk_safe && translation_m > pivot_limit * 1.6) {
            return MotionMode::Walk;
        }
        if (rotation_available &&
            (!walk_safe || translation_m <= pivot_limit)) {
            return MotionMode::Rotation;
        }
        return walk_safe ? MotionMode::Walk : MotionMode::Unknown;
    }

    void commit_motion_mode(const MotionMode evidence) {
        std::scoped_lock lock(mutex);
        if (evidence == MotionMode::Walk) {
            walk_motion_votes = std::min(8, walk_motion_votes + 1);
            rotation_motion_votes = std::max(0, rotation_motion_votes - 1);
        } else if (evidence == MotionMode::Rotation) {
            rotation_motion_votes = std::min(8, rotation_motion_votes + 1);
            walk_motion_votes = std::max(0, walk_motion_votes - 1);
        } else {
            rotation_motion_votes = std::max(0, rotation_motion_votes - 1);
            walk_motion_votes = std::max(0, walk_motion_votes - 1);
        }
        if (walk_motion_votes >= kMotionVotesToSwitch &&
            walk_motion_votes > rotation_motion_votes) {
            motion_mode = MotionMode::Walk;
        } else if (rotation_motion_votes >= kMotionVotesToSwitch &&
                   rotation_motion_votes > walk_motion_votes) {
            motion_mode = MotionMode::Rotation;
        }
    }

    void push_tracking_reference(const TrackingFrame& frame,
                                 const cv::Matx44d& world_from_camera,
                                 const MapJob& job,
                                 const std::uint64_t keyframe_id,
                                 const bool pose_trusted = true,
                                 const std::uint32_t provisional_chain_length = 0) {
        Keyframe reference;
        reference.id = keyframe_id;
        reference.pair_index = job.pair_index;
        reference.segment_id = job.segment_id;
        reference.frame = frame;
        reference.world_from_camera = world_from_camera;
        reference.gyro_valid = job.gyro_valid;
        reference.gyro_yaw_uncalibrated_deg =
            job.gyro_yaw_uncalibrated_deg;
        reference.gyro_yaw_raw_deg = job.gyro_yaw_raw_deg;
        reference.pose_trusted = pose_trusted;
        reference.provisional_chain_length = provisional_chain_length;
        tracking_buffer.push_back(std::move(reference));
        while (tracking_buffer.size() > kMaximumTrackingBufferFrames) {
            tracking_buffer.pop_front();
        }
        std::scoped_lock lock(mutex);
        tracking_buffer_frames = tracking_buffer.size();
    }

    PoseDecision decide_pose(const Keyframe& reference,
                             const TrackingFrame& current,
                             const MapJob& job,
                             const bool recovery_attempt) {
        PoseDecision decision;
        decision.reference_keyframe_id = reference.id;
        decision.reference_pair_index = reference.pair_index;
        decision.reference_world_from_camera = reference.world_from_camera;
        decision.reference_pose_trusted = reference.pose_trusted;
        decision.provisional_chain_length =
            reference.pose_trusted
                ? 0
                : reference.provisional_chain_length;
        decision.visual = estimate_visual(reference, current);
        decision.visual_yaw_step_deg = decision.visual.homography_valid
            ? decision.visual.visual_yaw_deg : 0.0;
        const bool gyro_pair_valid = job.gyro_valid && reference.gyro_valid;
        double gyro_step = gyro_pair_valid
            ? std::remainder(job.gyro_yaw_raw_deg - reference.gyro_yaw_raw_deg, 360.0)
            : 0.0;
        double gyro_uncalibrated_step = gyro_pair_valid
            ? std::remainder(
                  job.gyro_yaw_uncalibrated_deg -
                      reference.gyro_yaw_uncalibrated_deg,
                  360.0)
            : 0.0;
        if (gyro_pair_valid && decision.visual.homography_valid && !gyro_sign_locked &&
            std::abs(gyro_step) >= 0.5 &&
            std::abs(decision.visual_yaw_step_deg) >= 0.5) {
            const double same_error = std::abs(gyro_step - decision.visual_yaw_step_deg);
            const double flipped_error = std::abs(-gyro_step - decision.visual_yaw_step_deg);
            gyro_to_camera_sign = flipped_error < same_error ? -1.0 : 1.0;
            gyro_sign_locked = true;
        }
        gyro_step *= gyro_to_camera_sign;
        gyro_uncalibrated_step *= gyro_to_camera_sign;
        decision.gyro_yaw_step_deg = gyro_step;
        decision.gyro_uncalibrated_yaw_step_deg =
            gyro_uncalibrated_step;
        decision.gyro_bias_removed_step_deg =
            gyro_uncalibrated_step - gyro_step;
        const double maximum_gyro_step = recovery_attempt
            ? kMaximumRecoveryGyroStepDeg : kMaximumGyroYawStepDeg;
        const bool gyro_valid = gyro_pair_valid && std::isfinite(gyro_step) &&
            std::abs(gyro_step) <= maximum_gyro_step;
        const bool visual_valid = decision.visual.homography_valid;

        if (gyro_valid && visual_valid) {
            const double disagreement = std::abs(gyro_step - decision.visual_yaw_step_deg);
            if (disagreement <= kMaximumYawDisagreementDeg) {
                decision.fused_yaw_step_deg = gyro_step * 0.75 +
                    decision.visual_yaw_step_deg * 0.25;
                decision.used_gyro = true;
                decision.used_visual = true;
                decision.method = "GYRO_VISUAL_FUSED";
            } else if (std::abs(gyro_step) >= kMinimumKeyframeYawDeg) {
                decision.fused_yaw_step_deg = gyro_step;
                decision.used_gyro = true;
                decision.method = "GYRO_PRIOR_VISUAL_DISAGREE";
            }
        } else if (gyro_valid) {
            decision.fused_yaw_step_deg = gyro_step;
            decision.used_gyro = true;
            decision.method = gyro_sign_locked
                ? "GYRO_ONLY_ROTATION" : "GYRO_PROVISIONAL_ROTATION";
        } else if (visual_valid) {
            decision.fused_yaw_step_deg = decision.visual_yaw_step_deg;
            decision.used_visual = true;
            decision.method = "VISUAL_HOMOGRAPHY";
        }

        const bool rotation_available = decision.used_gyro || decision.used_visual;
        const auto active_mode = motion_mode_snapshot();
        const bool inertial_walk = job.accelerometer_valid &&
            job.acceleration_motion_mps2 >= kMinimumAccelerationMotionMps2;
        if (inertial_walk || active_mode == MotionMode::Walk ||
            !pnp_walk_translation_safe(decision.visual) ||
            decision.visual.depth_pairs.size() >=
                kMinimumStereoTranslationPairs) {
            estimate_stereo_se3(
                reference,
                gyro_valid
                    ? std::optional<double>(gyro_step)
                    : std::nullopt,
                decision.visual);
        }
        pnp_confirms_stereo(decision.visual);
        decision.motion_evidence = classify_motion_evidence(decision, job);
        const bool walk_safe = walk_translation_safe(decision.visual);
        const bool walk_candidate = walk_safe &&
            (decision.motion_evidence == MotionMode::Walk ||
             active_mode == MotionMode::Walk || inertial_walk);

        if (walk_candidate) {
            decision.world_from_camera = walk_world_from_camera(decision.visual);
            decision.translation_m = walk_translation_m(decision.visual);
            decision.rotation_deg = decision.visual.stereo_rotation_deg;
            decision.fused_yaw_step_deg = signed_yaw_delta_deg(
                reference.world_from_camera, decision.world_from_camera);
            decision.translation_source =
                walk_translation_source(decision.visual);
            decision.provisional_chain_length =
                reference.pose_trusted
                    ? 0
                    : reference.provisional_chain_length + 1;
            decision.provisional_promoted =
                !reference.pose_trusted &&
                decision.provisional_chain_length >=
                    kProvisionalStepsToPromote;
            decision.translation_trusted =
                reference.pose_trusted || decision.provisional_promoted;
            decision.geometry_suppressed = !decision.translation_trusted;
            decision.method = decision.provisional_promoted
                ? "AUTO_WALK_STEREO_SE3_PROVISIONAL_PROMOTED"
                : (decision.translation_trusted
                    ? "AUTO_WALK_STEREO_SE3_RANSAC"
                    : "AUTO_WALK_STEREO_SE3_PROVISIONAL");
            decision.state = decision.geometry_suppressed
                ? "TRACKING_PROVISIONAL_SE3"
                : "TRACKING_WALK";
            decision.used_visual = true;
            decision.valid = true;
            if (decision.geometry_suppressed) {
                decision.keyframe = false;
                decision.rejection_reason =
                    "PROVISIONAL_SE3_WAITING_FOR_CONFIRMATION";
                return decision;
            }
        } else if (inertial_walk || active_mode == MotionMode::Walk) {
            decision.world_from_camera = rotation_available
                ? reference.world_from_camera *
                    yaw_rotation_deg(decision.fused_yaw_step_deg)
                : reference.world_from_camera;
            decision.rotation_deg = std::abs(decision.fused_yaw_step_deg);
            decision.rotation_only = false;
            decision.translation_m = 0.0;
            decision.translation_trusted = false;
            decision.geometry_suppressed = true;
            decision.provisional_chain_length =
                reference.pose_trusted
                    ? 1
                    : reference.provisional_chain_length + 1;
            decision.valid = true;
            decision.keyframe = false;
            decision.state = "TRACKING_TRANSLATION_UNCERTAIN";
            decision.method = "AUTO_WALK_TRANSLATION_UNCERTAIN";
            decision.rejection_reason =
                decision.visual.stereo_translation_attempted
                    ? "STEREO_3D3D_TRANSLATION_REJECTED"
                    : "NO_STEREO_3D3D_TRANSLATION_SUPPORT";
            return decision;
        } else if (rotation_available) {
            decision.rotation_only = true;
            decision.world_from_camera = reference.world_from_camera *
                yaw_rotation_deg(decision.fused_yaw_step_deg);
            decision.rotation_deg = std::abs(decision.fused_yaw_step_deg);
            decision.valid = true;
            decision.method = "AUTO_ROTATION_" + decision.method;
            decision.rotation_translation_rejection_reason =
                "PENDING_TRIPOD_CONSTRAINT";
        } else if (walk_safe) {
            decision.world_from_camera = walk_world_from_camera(decision.visual);
            decision.translation_m = walk_translation_m(decision.visual);
            decision.rotation_deg = decision.visual.stereo_rotation_deg;
            decision.fused_yaw_step_deg = signed_yaw_delta_deg(
                reference.world_from_camera, decision.world_from_camera);
            decision.translation_source =
                walk_translation_source(decision.visual);
            decision.translation_trusted = reference.pose_trusted;
            decision.geometry_suppressed = !reference.pose_trusted;
            decision.method = reference.pose_trusted
                ? "AUTO_WALK_STEREO_SE3_RANSAC"
                : "AUTO_WALK_STEREO_SE3_PROVISIONAL";
            decision.used_visual = true;
            decision.valid = true;
        }

        if (!decision.valid) {
            decision.rejection_reason = "NO_SAFE_AUTO_MOTION_POSE";
            return decision;
        }
        decision.keyframe = decision.translation_m >= kMinimumKeyframeTranslationM ||
            std::abs(decision.fused_yaw_step_deg) >= kMinimumKeyframeYawDeg;
        decision.state = decision.keyframe
            ? (decision.rotation_only ? "TRACKING_ROTATION" : "TRACKING_WALK")
            : "TRACKING_STATIONARY";
        return decision;
    }

    static double decision_score(const PoseDecision& decision) {
        if (!decision.valid) return -std::numeric_limits<double>::infinity();
        const int inliers = std::max({
            decision.visual.homography_inliers,
            decision.visual.pnp_inliers,
            decision.visual.stereo_translation_inliers,
        });
        const double ratio = std::max({
            decision.visual.homography_inlier_ratio,
            decision.visual.pnp_inlier_ratio,
            decision.visual.stereo_translation_inlier_ratio,
        });
        double score = static_cast<double>(inliers) + ratio * 100.0;
        if (decision.visual.pnp_stereo_confirmed) score += 25.0;
        if (decision.visual.stereo_translation_valid) score += 35.0;
        if (decision.provisional_promoted) score += 12.0;
        if (decision.used_gyro && decision.used_visual) score += 10.0;
        if (decision.geometry_suppressed) score -= 500.0;
        const double translation_penalty = decision.rotation_only
            ? decision.visual.translation_m
            : decision.translation_m;
        score -= translation_penalty * 8.0;
        return score;
    }

    PoseDecision decide_pose_with_retries(const TrackingFrame& current,
                                          const MapJob& job) {
        PoseDecision best;
        std::unordered_set<std::uint64_t> attempted_pairs;
        int attempts = 0;
        auto consider = [&](const Keyframe& reference,
                                  const bool recovery_attempt,
                                  PoseDecision& selected) mutable {
            if (!attempted_pairs.insert(reference.pair_index).second) return;
            auto candidate = decide_pose(reference, current, job, recovery_attempt);
            if (recovery_attempt) ++attempts;
            const auto anchor_rank = [](const PoseDecision& value) {
                if (!value.valid) return 0;
                if (value.translation_trusted &&
                    value.reference_pose_trusted) return 3;
                if (value.translation_trusted &&
                    value.provisional_promoted) return 2;
                return 1;
            };
            const int candidate_rank = anchor_rank(candidate);
            const int selected_rank = anchor_rank(selected);
            if (attempted_pairs.size() == 1 ||
                candidate_rank > selected_rank ||
                (candidate_rank == selected_rank &&
                 decision_score(candidate) > decision_score(selected))) {
                selected = std::move(candidate);
            }
        };

        if (!tracking_buffer.empty()) {
            consider(tracking_buffer.back(), false, best);
        } else if (!registration_keyframes.empty()) {
            consider(registration_keyframes.back(), false, best);
        }
        if (!best.valid || best.geometry_suppressed) {
            for (auto iterator = tracking_buffer.rbegin();
                 iterator != tracking_buffer.rend() &&
                 attempts < static_cast<int>(kMaximumRecoveryAttempts); ++iterator) {
                consider(*iterator, true, best);
            }
            for (auto iterator = registration_keyframes.rbegin();
                 iterator != registration_keyframes.rend() &&
                 attempts < static_cast<int>(kMaximumRecoveryAttempts); ++iterator) {
                consider(*iterator, true, best);
            }
        }
        best.recovery_attempts = attempts;
        if (best.valid && !best.geometry_suppressed && attempts > 0) {
            best.recovered = true;
            best.method = "RECOVERED_" + best.method;
        }
        return best;
    }

    void apply_cumulative_keyframe_gate(PoseDecision& decision) const {
        if (!decision.valid || registration_keyframes.empty() ||
            decision.geometry_suppressed) {
            return;
        }
        const auto& last_keyframe_pose =
            registration_keyframes.back().world_from_camera;
        decision.translation_from_last_keyframe_m = translation_delta_m(
            last_keyframe_pose, decision.world_from_camera);
        decision.yaw_from_last_keyframe_deg = signed_yaw_delta_deg(
            last_keyframe_pose, decision.world_from_camera);
        const bool forced_relocalization =
            decision.method.find("RELOCALIZED") != std::string::npos;
        decision.keyframe =
            forced_relocalization ||
            decision.translation_from_last_keyframe_m >=
                kMinimumKeyframeTranslationM ||
            std::abs(decision.yaw_from_last_keyframe_deg) >=
                kMinimumKeyframeYawDeg;
        if (!decision.keyframe) {
            decision.state = "TRACKING_STATIONARY";
        } else if (decision.method.find("APRILTAG") != std::string::npos) {
            decision.state = "TRACKING_APRILTAG";
        } else {
            decision.state = decision.rotation_only
                ? "TRACKING_ROTATION"
                : "TRACKING_WALK";
        }
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
                const bool features_usable =
                    current.keypoints.size() >=
                        static_cast<std::size_t>(kMinimumFeatures) &&
                    !current.descriptors.empty();
                const bool first = registration_keyframes.empty();
                PoseDecision decision;
                cv::Matx44d pose = cv::Matx44d::eye();
                StereoAprilTagAnchorResult apriltag_result;
                if (first) {
                    apriltag_result = apriltag_anchors.evaluate(
                        job.pair_index,
                        std::optional<cv::Matx44d>(pose),
                        true);
                } else {
                    decision = decide_pose_with_retries(current, job);
                    apply_tripod_rotation_constraint(decision, job);
                    const std::optional<cv::Matx44d> preliminary_pose =
                        decision.valid
                            ? std::optional<cv::Matx44d>(
                                  decision.world_from_camera)
                            : std::nullopt;
                    const bool preliminary_translation_trusted =
                        decision.valid && decision.translation_trusted;
                    apriltag_result = apriltag_anchors.evaluate(
                        job.pair_index, preliminary_pose,
                        preliminary_translation_trusted);
                    if (apriltag_result.anchor_pose_valid &&
                        apriltag_result.live_correction_allowed) {
                        const cv::Matx44d reference_pose =
                            !tracking_buffer.empty()
                                ? tracking_buffer.back().world_from_camera
                                : registration_keyframes.back()
                                      .world_from_camera;
                        reset_tripod_constraint();
                        decision.world_from_camera =
                            apriltag_result.world_from_camera;
                        decision.translation_m = translation_delta_m(
                            reference_pose, decision.world_from_camera);
                        decision.fused_yaw_step_deg = signed_yaw_delta_deg(
                            reference_pose, decision.world_from_camera);
                        decision.rotation_deg =
                            std::abs(decision.fused_yaw_step_deg);
                        decision.rotation_only = false;
                        decision.used_visual = true;
                        decision.translation_trusted = true;
                        decision.translation_source = "APRILTAG_STEREO";
                        decision.geometry_suppressed = false;
                        decision.valid = true;
                        decision.keyframe = apriltag_result.relocalized ||
                            decision.translation_m >=
                                kMinimumKeyframeTranslationM ||
                            std::abs(decision.fused_yaw_step_deg) >=
                                kMinimumKeyframeYawDeg;
                        decision.state = decision.keyframe
                            ? "TRACKING_APRILTAG"
                            : "TRACKING_STATIONARY";
                        decision.method = apriltag_result.relocalized
                            ? "APRILTAG_STEREO_RELOCALIZED"
                            : "APRILTAG_STEREO_ANCHORED";
                        decision.recovered =
                            decision.recovered ||
                            apriltag_result.relocalized;
                        decision.rejection_reason.clear();
                        if (decision.reference_pair_index == 0) {
                            decision.reference_pair_index =
                                !tracking_buffer.empty()
                                    ? tracking_buffer.back().pair_index
                                    : registration_keyframes.back()
                                          .pair_index;
                        }
                    } else if (!features_usable && !decision.valid) {
                        decision.rejection_reason =
                            apriltag_result.constraint_only
                                ? "APRILTAG_CONSTRAINT_REJECTED_LIVE"
                                : "NO_ORB_FEATURES_AND_NO_STEREO_APRILTAG_ANCHOR";
                    }
                    apply_cumulative_keyframe_gate(decision);
                    commit_motion_mode(decision.motion_evidence);
                    append_pose_validation({
                        {"pair_index", job.pair_index},
                        {"segment_id", job.segment_id},
                        {"reference_keyframe_id", decision.reference_keyframe_id},
                        {"reference_pair_index", decision.reference_pair_index},
                        {"gyro_valid", job.gyro_valid},
                        {"gyro_bias_ready", job.gyro_bias_ready},
                        {"gyro_bias_rad_s", job.gyro_bias_rad_s},
                        {"gyro_bias_calibration_samples",
                         job.gyro_bias_calibration_samples},
                        {"gyro_yaw_uncalibrated_deg",
                         job.gyro_yaw_uncalibrated_deg},
                        {"gyro_yaw_corrected_deg",
                         job.gyro_yaw_raw_deg},
                        {"gyro_yaw_step_deg", decision.gyro_yaw_step_deg},
                        {"gyro_uncalibrated_yaw_step_deg",
                         decision.gyro_uncalibrated_yaw_step_deg},
                        {"gyro_bias_removed_step_deg",
                         decision.gyro_bias_removed_step_deg},
                        {"visual_yaw_step_deg", decision.visual_yaw_step_deg},
                        {"fused_yaw_step_deg", decision.fused_yaw_step_deg},
                        {"rotation_translation_applied",
                         decision.rotation_translation_applied},
                        {"rotation_translation_candidate_m",
                         decision.rotation_translation_candidate_m},
                        {"rotation_translation_limit_m",
                         decision.rotation_translation_limit_m},
                        {"rotation_translation_vector_world_m",
                         nlohmann::json::array({
                             decision.rotation_translation_vector_world_m[0],
                             decision.rotation_translation_vector_world_m[1],
                             decision.rotation_translation_vector_world_m[2],
                         })},
                        {"rotation_translation_horizontal_candidate_m",
                         decision.rotation_translation_horizontal_candidate_m},
                        {"rotation_translation_horizontal_step_m",
                         decision.rotation_translation_horizontal_step_m},
                        {"rotation_translation_horizontal_step_limit_m",
                         decision.rotation_translation_horizontal_step_limit_m},
                        {"rotation_translation_total_radius_m",
                         decision.rotation_translation_total_radius_m},
                        {"rotation_translation_total_limit_m",
                         decision.rotation_translation_total_limit_m},
                        {"rotation_translation_yaw_from_anchor_deg",
                         decision.rotation_translation_yaw_from_anchor_deg},
                        {"rotation_translation_locked_y_m",
                         decision.rotation_translation_locked_y_m},
                        {"rotation_translation_rejection_reason",
                         decision.rotation_translation_rejection_reason},
                        {"translation_from_last_keyframe_m",
                         decision.translation_from_last_keyframe_m},
                        {"yaw_from_last_keyframe_deg",
                         decision.yaw_from_last_keyframe_deg},
                        {"motion_mode", motion_mode_name(motion_mode_snapshot())},
                        {"motion_evidence", motion_mode_name(decision.motion_evidence)},
                        {"accelerometer_motion_mps2", job.acceleration_motion_mps2},
                        {"method", decision.method},
                        {"recovered", decision.recovered},
                        {"recovery_attempts", decision.recovery_attempts},
                        {"features_usable", features_usable},
                        {"apriltag_ids", apriltag_result.ids},
                        {"apriltag_anchor_pose_valid",
                         apriltag_result.anchor_pose_valid},
                        {"apriltag_live_correction_allowed",
                         apriltag_result.live_correction_allowed},
                        {"apriltag_constraint_only",
                         apriltag_result.constraint_only},
                        {"apriltag_stereo_verified",
                         apriltag_result.stereo_verified},
                        {"apriltag_pose_source",
                         apriltag_result.pose_source},
                        {"apriltag_relocalized",
                         apriltag_result.relocalized},
                        {"apriltag_mapped_tags_used",
                         apriltag_result.mapped_tags_used},
                        {"apriltag_stereo_tags_used",
                         apriltag_result.stereo_tags_used},
                        {"apriltag_position_correction_m",
                         apriltag_result.position_correction_m},
                        {"apriltag_yaw_correction_deg",
                         apriltag_result.yaw_correction_deg},
                        {"apriltag_confidence",
                         apriltag_result.confidence},
                        {"matches", decision.visual.matches},
                        {"mutual_matches", decision.visual.mutual_matches},
                        {"geometric_inliers", decision.visual.geometric_inliers},
                        {"homography_inliers", decision.visual.homography_inliers},
                        {"pnp_inliers", decision.visual.pnp_inliers},
                        {"pnp_translation_m", decision.visual.translation_m},
                        {"stereo_translation_attempted",
                         decision.visual.stereo_translation_attempted},
                        {"stereo_translation_valid",
                         decision.visual.stereo_translation_valid},
                        {"stereo_translation_pairs",
                         decision.visual.stereo_translation_pairs},
                        {"stereo_translation_inliers",
                         decision.visual.stereo_translation_inliers},
                        {"stereo_translation_inlier_ratio",
                         decision.visual.stereo_translation_inlier_ratio},
                        {"stereo_translation_residual_m",
                         decision.visual.stereo_translation_residual_m},
                        {"stereo_translation_m",
                         decision.visual.stereo_translation_m},
                        {"stereo_rotation_deg",
                         decision.visual.stereo_rotation_deg},
                        {"stereo_yaw_deg",
                         decision.visual.stereo_yaw_deg},
                        {"stereo_gyro_yaw_checked",
                         decision.visual.stereo_gyro_yaw_checked},
                        {"stereo_gyro_yaw_error_deg",
                         decision.visual.stereo_gyro_yaw_error_deg},
                        {"pnp_stereo_confirmed",
                         decision.visual.pnp_stereo_confirmed},
                        {"reference_pose_trusted",
                         decision.reference_pose_trusted},
                        {"provisional_chain_length",
                         decision.provisional_chain_length},
                        {"provisional_promoted",
                         decision.provisional_promoted},
                        {"translation_source", decision.translation_source},
                        {"translation_trusted", decision.translation_trusted},
                        {"geometry_suppressed", decision.geometry_suppressed},
                        {"translation_m", decision.translation_m},
                        {"accepted", decision.valid && decision.keyframe},
                        {"reason", decision.rejection_reason},
                    });
                    if (!decision.valid) {
                        const double duration = std::chrono::duration<double, std::milli>(
                            std::chrono::steady_clock::now() - started).count();
                        {
                            std::scoped_lock lock(mutex);
                            ++processed_frames;
                            ++lost_frames;
                            if (decision.visual.stereo_translation_attempted) {
                                ++stereo_translation_attempts;
                            }
                            if (decision.visual.stereo_translation_valid) {
                                ++stereo_translation_successes;
                            }
                            recovery_attempts_total +=
                                static_cast<std::uint64_t>(decision.recovery_attempts);
                            state = "LOST_RETRY_BUFFER_EXHAUSTED";
                            source_profile = job.source_profile;
                            last_pair_index = job.pair_index;
                            last_method = decision.method;
                            last_rejection_reason = decision.rejection_reason;
                            last_processing_ms = duration;
                        }
                        write_status_file();
                        continue;
                    }
                    pose = decision.world_from_camera;
                    if (!decision.keyframe) {
                        if (!decision.geometry_suppressed) {
                            push_tracking_reference(
                                current, pose, job,
                                decision.reference_keyframe_id,
                                true, 0);
                        } else if (features_usable) {
                            push_tracking_reference(
                                current, pose, job,
                                decision.reference_keyframe_id,
                                false,
                                decision.provisional_chain_length);
                        }
                        const double duration = std::chrono::duration<double, std::milli>(
                            std::chrono::steady_clock::now() - started).count();
                        {
                            std::scoped_lock lock(mutex);
                            ++processed_frames;
                            if (decision.visual.stereo_translation_attempted) {
                                ++stereo_translation_attempts;
                            }
                            if (decision.visual.stereo_translation_valid) {
                                ++stereo_translation_successes;
                            }
                            if (decision.geometry_suppressed) {
                                ++translation_uncertain_frames;
                                ++geometry_suppressed_frames;
                                ++provisional_tracking_frames;
                                maximum_provisional_chain = std::max(
                                    maximum_provisional_chain,
                                    static_cast<std::uint64_t>(
                                        decision.provisional_chain_length));
                            } else if (decision.state == "TRACKING_COASTING") {
                                ++coasting_frames;
                            } else {
                                ++stationary_frames;
                            }
                            recovery_attempts_total +=
                                static_cast<std::uint64_t>(decision.recovery_attempts);
                            if (decision.recovered) {
                                ++recovery_successes;
                                last_recovery_reference_pair =
                                    decision.reference_pair_index;
                            }
                            state = decision.state;
                            ready = !trajectory.empty();
                            source_profile = job.source_profile;
                            last_pair_index = job.pair_index;
                            last_reference_keyframe_id =
                                decision.reference_keyframe_id;
                            last_method = decision.method;
                            last_matches = decision.visual.matches;
                            last_inliers = std::max(
                                decision.visual.homography_inliers,
                                decision.visual.pnp_inliers);
                            last_inlier_ratio = std::max(
                                decision.visual.homography_inlier_ratio,
                                decision.visual.pnp_inlier_ratio);
                            last_gyro_yaw_step_deg = decision.gyro_yaw_step_deg;
                            last_visual_yaw_step_deg = decision.visual_yaw_step_deg;
                            last_fused_yaw_step_deg = decision.fused_yaw_step_deg;
                            last_translation_m = decision.translation_m;
                            last_processing_ms = duration;
                                last_rejection_reason =
                                    decision.rejection_reason;
                        }
                        write_status_file();
                        continue;
                    }
                }

                const std::uint64_t keyframe_id = next_keyframe_id++;
                merge_keyframe(job, pose, keyframe_id, voxels);
                write_text_atomic(
                    session_directory / "keyframes" /
                        ("keyframe_" + std::to_string(keyframe_id) + "_local.ply"),
                    local_keyframe_ply(job, false, pose, keyframe_id));
                write_text_atomic(
                    session_directory / "keyframes" /
                        ("keyframe_" + std::to_string(keyframe_id) + "_world.ply"),
                    local_keyframe_ply(job, true, pose, keyframe_id));

                if (!job.strict_disparity.empty() &&
                    !job.strict_mask.empty()) {
                    MapJob strict_job = job;
                    strict_job.disparity = job.strict_disparity;
                    strict_job.mask = job.strict_mask;
                    merge_keyframe(
                        strict_job, pose, keyframe_id,
                        temporal_strict_voxels);
                    write_text_atomic(
                        session_directory / "keyframes" /
                            ("keyframe_" + std::to_string(keyframe_id) +
                             "_local_temporal_strict.ply"),
                        local_keyframe_ply(
                            strict_job, false, pose, keyframe_id));
                    write_text_atomic(
                        session_directory / "keyframes" /
                            ("keyframe_" + std::to_string(keyframe_id) +
                             "_world_temporal_strict.ply"),
                        local_keyframe_ply(
                            strict_job, true, pose, keyframe_id));
                }

                Keyframe keyframe;
                keyframe.id = keyframe_id;
                keyframe.pair_index = job.pair_index;
                keyframe.segment_id = job.segment_id;
                keyframe.frame = current;
                keyframe.world_from_camera = pose;
                keyframe.gyro_valid = job.gyro_valid;
                keyframe.gyro_yaw_uncalibrated_deg =
                    job.gyro_yaw_uncalibrated_deg;
                keyframe.gyro_yaw_raw_deg = job.gyro_yaw_raw_deg;
                keyframe.pose_trusted = true;
                keyframe.provisional_chain_length = 0;
                registration_keyframes.push_back(std::move(keyframe));
                while (registration_keyframes.size() > kMaximumRegistrationKeyframes) {
                    registration_keyframes.pop_front();
                }
                push_tracking_reference(
                    current, pose, job, keyframe_id,
                    true, 0);

                const double gyro_step = first ? 0.0 : decision.gyro_yaw_step_deg;
                const double visual_step = first ? 0.0 : decision.visual_yaw_step_deg;
                const double fused_step = first
                    ? 0.0
                    : decision.yaw_from_last_keyframe_deg;
                if (!first) worker_accumulated_yaw_deg += fused_step;
                const bool resumed = job.segment_resume;
                const std::string keyframe_state = first
                    ? "TRACKING_INITIALIZED"
                    : (resumed ? "SEGMENT_RESUMED" : decision.state);
                const std::string keyframe_method = first ? "IDENTITY" : decision.method;
                const int inliers = first ? 0 : std::max(
                    decision.visual.homography_inliers,
                    decision.visual.pnp_inliers);
                const double inlier_ratio = first ? 0.0 : std::max(
                    decision.visual.homography_inlier_ratio,
                    decision.visual.pnp_inlier_ratio);
                trajectory.push_back({
                    keyframe_id,
                    job.pair_index,
                    job.segment_id,
                    keyframe_state,
                    keyframe_method,
                    pose,
                    gyro_step,
                    visual_step,
                    fused_step,
                    worker_accumulated_yaw_deg,
                    first
                        ? 0.0
                        : decision.translation_from_last_keyframe_m,
                    first ? 0 : decision.visual.matches,
                    inliers,
                    inlier_ratio,
                    first ? 0.0 : std::min(
                        decision.visual.homography_rmse_px,
                        decision.visual.pnp_rmse_px),
                    unix_time_ms(),
                });
                {
                    std::scoped_lock lock(mutex);
                    if (job.segment_id == segment_id) segment_resume_pending = false;
                }
                publish_outputs(false);

                std::size_t multiview_count = 0;
                for (const auto& [key, voxel] : voxels) {
                    static_cast<void>(key);
                    if (voxel.keyframe_observations >= kMinimumMultiviewKeyframes) {
                        ++multiview_count;
                    }
                }
                std::size_t temporal_strict_multiview_count = 0;
                for (const auto& [key, voxel] : temporal_strict_voxels) {
                    static_cast<void>(key);
                    if (voxel.keyframe_observations >=
                        kMinimumMultiviewKeyframes) {
                        ++temporal_strict_multiview_count;
                    }
                }
                const double duration = std::chrono::duration<double, std::milli>(
                    std::chrono::steady_clock::now() - started).count();
                nlohmann::json diagnostic;
                {
                    std::scoped_lock lock(mutex);
                    ready = true;
                    state = keyframe_state;
                    source_profile = job.source_profile;
                    last_pair_index = job.pair_index;
                    last_reference_keyframe_id = first ? 0 :
                        decision.reference_keyframe_id;
                    keyframe_count = trajectory.size();
                    trajectory_samples = trajectory.size();
                    accumulated_points_raw = voxels.size();
                    accumulated_points_multiview = multiview_count;
                    temporal_strict_points_raw =
                        temporal_strict_voxels.size();
                    temporal_strict_points_multiview =
                        temporal_strict_multiview_count;
                    last_method = keyframe_method;
                    last_matches = first ? 0 : decision.visual.matches;
                    last_inliers = inliers;
                    last_inlier_ratio = inlier_ratio;
                    last_gyro_yaw_step_deg = gyro_step;
                    last_visual_yaw_step_deg = visual_step;
                    last_fused_yaw_step_deg = fused_step;
                    accumulated_yaw_deg = worker_accumulated_yaw_deg;
                    last_translation_m = first
                        ? 0.0
                        : decision.translation_from_last_keyframe_m;
                    last_processing_ms = duration;
                    last_rejection_reason.clear();
                    last_error.clear();
                    ++processed_frames;
                    if (!first &&
                        decision.visual.stereo_translation_attempted) {
                        ++stereo_translation_attempts;
                    }
                    if (!first &&
                        decision.visual.stereo_translation_valid) {
                        ++stereo_translation_successes;
                    }
                    if (!first && decision.provisional_promoted) {
                        ++provisional_promotions;
                    }
                    recovery_attempts_total +=
                        static_cast<std::uint64_t>(decision.recovery_attempts);
                    if (!first && decision.recovered) {
                        ++recovery_successes;
                        last_recovery_reference_pair =
                            decision.reference_pair_index;
                    }
                    if (!first && decision.method.find("GYRO_ONLY_ROTATION") !=
                            std::string::npos) ++gyro_only_keyframes;
                    if (!first && decision.method.find("GYRO_VISUAL_FUSED") !=
                            std::string::npos) ++gyro_visual_keyframes;
                    if (resumed) ++segment_resume_keyframes;
                    diagnostic = {
                        {"event", "ACCUMULATED_MAP_KEYFRAME"},
                        {"state", state},
                        {"pair_index", last_pair_index},
                        {"keyframe_id", keyframe_id},
                        {"segment_id", job.segment_id},
                        {"method", last_method},
                        {"motion_mode", motion_mode_name(motion_mode)},
                        {"recovered", decision.recovered},
                        {"recovery_attempts", decision.recovery_attempts},
                        {"reference_pair_index", decision.reference_pair_index},
                        {"gyro_yaw_step_deg", gyro_step},
                        {"visual_yaw_step_deg", visual_step},
                        {"fused_yaw_step_deg", fused_step},
                        {"accumulated_yaw_deg", accumulated_yaw_deg},
                        {"accumulated_points_raw", accumulated_points_raw},
                        {"accumulated_points_multiview", accumulated_points_multiview},
                        {"temporal_strict_points_raw",
                         temporal_strict_points_raw},
                        {"temporal_strict_points_multiview",
                         temporal_strict_points_multiview},
                        {"temporal_strict_overlap_fraction",
                         temporal_strict_points_raw > 0
                             ? static_cast<double>(
                                   temporal_strict_points_multiview) /
                                   static_cast<double>(
                                       temporal_strict_points_raw)
                             : 0.0},
                        {"processing_ms", last_processing_ms},
                    };
                }
                append_diagnostic(std::move(diagnostic));
                write_status_file();
            } catch (const std::exception& error) {
                const double duration = std::chrono::duration<double, std::milli>(
                    std::chrono::steady_clock::now() - started).count();
                {
                    std::scoped_lock lock(mutex);
                    ++processed_frames;
                    ++failed_frames;
                    state = "ERROR";
                    source_profile = job.source_profile;
                    last_pair_index = job.pair_index;
                    last_processing_ms = duration;
                    last_error = error.what();
                }
                append_diagnostic({
                    {"event", "ACCUMULATED_MAP_FAILED"},
                    {"pair_index", job.pair_index},
                    {"error", error.what()},
                    {"processing_ms", duration},
                });
                write_status_file();
            }
        }
    }

    std::filesystem::path session_directory;
    mutable std::mutex mutex;
    mutable std::mutex output_mutex;
    std::condition_variable condition;
    bool stopping = false;
    bool clear_requested = false;
    std::optional<MapJob> pending;
    std::ofstream diagnostics;
    std::ofstream pose_validation;
    StereoAprilTagRuntime apriltag_anchors;
    std::thread worker;
    std::uint64_t generation = 1;
    std::chrono::steady_clock::time_point last_accepted_submission;
    std::chrono::steady_clock::time_point last_publish;

    std::deque<Keyframe> registration_keyframes;
    std::deque<Keyframe> tracking_buffer;
    std::vector<TrajectorySample> trajectory;
    std::unordered_map<VoxelKey, VoxelAccumulator, VoxelKeyHash> voxels;
    std::unordered_map<VoxelKey, VoxelAccumulator, VoxelKeyHash>
        temporal_strict_voxels;
    std::uint64_t next_keyframe_id = 1;
    double worker_accumulated_yaw_deg = 0.0;
    bool tripod_constraint_active = false;
    std::uint64_t tripod_constraint_segment_id = 0;
    cv::Vec3d tripod_anchor_position_world_m{0.0, 0.0, 0.0};
    double tripod_anchor_yaw_deg = 0.0;

    std::array<bool, 2> camera_connected{false, false};
    std::array<std::string, 2> camera_device_ids;
    std::uint64_t segment_id = 1;
    bool segment_resume_pending = false;
    std::uint64_t disconnect_boundaries = 0;

    bool imu_ready = false;
    std::string imu_session_id;
    std::int64_t last_imu_timestamp_ns = 0;
    int imu_axis = 1;
    double last_gyro_rate_rad_s = 0.0;
    bool gyro_bias_ready = false;
    double gyro_bias_calibration_sum_rad_s = 0.0;
    std::uint64_t gyro_bias_calibration_samples = 0;
    double gyro_bias_rad_s = 0.0;
    double gyro_yaw_uncalibrated_deg = 0.0;
    double gyro_yaw_raw_deg = 0.0;
    bool accelerometer_valid = false;
    double acceleration_motion_mps2 = 0.0;
    double gyro_to_camera_sign = 1.0;
    bool gyro_sign_locked = false;
    std::uint64_t imu_samples = 0;
    std::uint64_t imu_invalid_samples = 0;
    std::uint64_t imu_session_changes = 0;

    bool ready = false;
    std::string state = "WAITING";
    std::string source_profile = "WAITING";
    std::uint64_t last_pair_index = 0;
    std::uint64_t last_reference_keyframe_id = 0;
    std::uint64_t keyframe_count = 0;
    std::uint64_t trajectory_samples = 0;
    std::uint64_t accumulated_points_raw = 0;
    std::uint64_t accumulated_points_multiview = 0;
    std::uint64_t temporal_strict_points_raw = 0;
    std::uint64_t temporal_strict_points_multiview = 0;
    std::string last_method = "NONE";
    int last_matches = 0;
    int last_inliers = 0;
    double last_inlier_ratio = 0.0;
    double last_gyro_yaw_step_deg = 0.0;
    double last_visual_yaw_step_deg = 0.0;
    double last_fused_yaw_step_deg = 0.0;
    double accumulated_yaw_deg = 0.0;
    double last_translation_m = 0.0;
    double last_processing_ms = 0.0;
    std::string last_rejection_reason;
    std::string last_error;
    MotionMode motion_mode = MotionMode::Unknown;
    int rotation_motion_votes = 0;
    int walk_motion_votes = 0;
    std::size_t tracking_buffer_frames = 0;
    std::uint64_t recovery_attempts_total = 0;
    std::uint64_t recovery_successes = 0;
    std::uint64_t last_recovery_reference_pair = 0;
    std::uint64_t coasting_frames = 0;
    std::uint64_t translation_uncertain_frames = 0;
    std::uint64_t geometry_suppressed_frames = 0;
    std::uint64_t stereo_translation_attempts = 0;
    std::uint64_t stereo_translation_successes = 0;
    std::uint64_t provisional_tracking_frames = 0;
    std::uint64_t provisional_promotions = 0;
    std::uint64_t maximum_provisional_chain = 0;

    std::uint64_t submitted_frames = 0;
    std::uint64_t accepted_frames = 0;
    std::uint64_t processed_frames = 0;
    std::uint64_t failed_frames = 0;
    std::uint64_t lost_frames = 0;
    std::uint64_t stationary_frames = 0;
    std::uint64_t gyro_only_keyframes = 0;
    std::uint64_t gyro_visual_keyframes = 0;
    std::uint64_t segment_resume_keyframes = 0;
    std::uint64_t rejected_profile_frames = 0;
    std::uint64_t rejected_busy_frames = 0;
    std::uint64_t rejected_interval_frames = 0;
    std::uint64_t rejected_invalid_frames = 0;
};

AccumulatedMapRuntime::AccumulatedMapRuntime(std::filesystem::path session_directory)
    : impl_(std::make_unique<Impl>(std::move(session_directory))) {}

AccumulatedMapRuntime::~AccumulatedMapRuntime() = default;

bool AccumulatedMapRuntime::submit(const std::uint64_t pair_index,
                                   std::string source_profile,
                                   const StereoDepthResult& depth) {
    return impl_->submit(pair_index, std::move(source_profile), depth);
}

void AccumulatedMapRuntime::submit_apriltag_pair(
    StereoPreviewPair pair,
    ResolvedCalibration calibration) {
    impl_->submit_apriltag_pair(
        std::move(pair), std::move(calibration));
}

void AccumulatedMapRuntime::accept_imu(const nlohmann::json& sample) {
    impl_->accept_imu(sample);
}

void AccumulatedMapRuntime::notify_camera_event(
    const std::size_t slot_index,
    const std::string& event,
    const std::string& device_id) {
    impl_->notify_camera_event(slot_index, event, device_id);
}

void AccumulatedMapRuntime::reset() { impl_->reset(); }

nlohmann::json AccumulatedMapRuntime::status_json() const {
    return impl_->status_json();
}

}  // namespace maklertour::dual_phone::detail
