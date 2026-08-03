#include "stereo_apriltag_runtime.hpp"

#include <algorithm>
#include <array>
#include <atomic>
#include <chrono>
#include <cmath>
#include <condition_variable>
#include <cstddef>
#include <cstdint>
#include <deque>
#include <filesystem>
#include <fstream>
#include <iomanip>
#include <limits>
#include <map>
#include <mutex>
#include <numbers>
#include <optional>
#include <set>
#include <sstream>
#include <stdexcept>
#include <string>
#include <system_error>
#include <thread>
#include <utility>
#include <vector>

#include <opencv2/aruco.hpp>
#include <opencv2/calib3d.hpp>
#include <opencv2/imgcodecs.hpp>
#include <opencv2/imgproc.hpp>

namespace maklertour::dual_phone::detail {

namespace {

constexpr double kTagSizeM = 0.160;
constexpr int kMinimumKitId = 1;
constexpr int kMaximumKitId = 30;
constexpr std::chrono::milliseconds kTargetInterval{50};
constexpr std::size_t kTemporalTrackLength = 20;
constexpr std::uint64_t kMaximumMeasurementAgePairs = 40;
constexpr double kMaximumMonoReprojectionErrorPx = 3.0;
constexpr double kMinimumTagDistanceM = 0.20;
constexpr double kMaximumTagDistanceM = 8.0;
constexpr double kMaximumStereoSideErrorRatio = 0.25;
constexpr double kMaximumStereoShapeSpreadRatio = 0.18;
constexpr double kMaximumStereoCornerResidualM = 0.035;
constexpr double kMaximumTemporalTranslationStepM = 0.45;
constexpr double kMaximumTemporalRotationStepDeg = 35.0;
constexpr std::uint64_t kStereoVerifiedFrames = 5;
constexpr std::uint64_t kMappedIndependentViews = 2;
constexpr std::uint64_t kAnchorStereoObservations = 8;
constexpr std::uint64_t kAnchorPairGap = 40;
constexpr double kIndependentViewTranslationM = 0.15;
constexpr double kIndependentViewYawDeg = 10.0;
constexpr double kLandmarkTranslationGateM = 0.40;
constexpr double kLandmarkYawGateDeg = 20.0;
constexpr double kKnownTagConsensusTranslationM = 0.35;
constexpr double kKnownTagConsensusYawDeg = 18.0;
constexpr double kSafeLiveCorrectionM = 0.45;
constexpr double kSafeLiveCorrectionYawDeg = 20.0;
constexpr double kRelocalizationPositionThresholdM = 0.20;
constexpr double kRelocalizationYawThresholdDeg = 8.0;
constexpr double kMinimumLiveConfidence = 0.68;

std::int64_t unix_time_ms() {
    return std::chrono::duration_cast<std::chrono::milliseconds>(
        std::chrono::system_clock::now().time_since_epoch()).count();
}

void write_text_atomic(const std::filesystem::path& destination,
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
            "cannot publish " + destination.string() + ": " + error.message());
    }
}

void write_image_atomic(const std::filesystem::path& destination,
                        const cv::Mat& image) {
    if (image.empty()) return;
    auto temporary = destination;
    temporary += ".tmp.jpg";
    if (!cv::imwrite(temporary.string(), image)) {
        throw std::runtime_error("cannot write " + temporary.string());
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
            "cannot publish " + destination.string() + ": " + error.message());
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

cv::Matx44d cv_to_project_transform(const cv::Matx44d& value) {
    const cv::Matx44d flip(
        1.0, 0.0, 0.0, 0.0,
        0.0, -1.0, 0.0, 0.0,
        0.0, 0.0, 1.0, 0.0,
        0.0, 0.0, 0.0, 1.0);
    return flip * value * flip;
}

cv::Matx44d matx44_from_rt(const cv::Mat& rotation,
                           const cv::Mat& translation) {
    cv::Mat rotation64;
    cv::Mat translation64;
    rotation.convertTo(rotation64, CV_64F);
    translation.convertTo(translation64, CV_64F);
    return {
        rotation64.at<double>(0, 0), rotation64.at<double>(0, 1),
        rotation64.at<double>(0, 2), translation64.at<double>(0, 0),
        rotation64.at<double>(1, 0), rotation64.at<double>(1, 1),
        rotation64.at<double>(1, 2), translation64.at<double>(1, 0),
        rotation64.at<double>(2, 0), rotation64.at<double>(2, 1),
        rotation64.at<double>(2, 2), translation64.at<double>(2, 0),
        0.0, 0.0, 0.0, 1.0,
    };
}

cv::Matx44d matx44_from_rt(const cv::Matx33d& rotation,
                           const cv::Vec3d& translation) {
    return {
        rotation(0, 0), rotation(0, 1), rotation(0, 2), translation[0],
        rotation(1, 0), rotation(1, 1), rotation(1, 2), translation[1],
        rotation(2, 0), rotation(2, 1), rotation(2, 2), translation[2],
        0.0, 0.0, 0.0, 1.0,
    };
}

cv::Vec3d position_of(const cv::Matx44d& pose) {
    return {pose(0, 3), pose(1, 3), pose(2, 3)};
}

cv::Matx33d rotation_of(const cv::Matx44d& pose) {
    return {
        pose(0, 0), pose(0, 1), pose(0, 2),
        pose(1, 0), pose(1, 1), pose(1, 2),
        pose(2, 0), pose(2, 1), pose(2, 2),
    };
}

cv::Vec3d transform_point(const cv::Matx44d& transform,
                          const cv::Vec3d& point) {
    const auto value = transform * cv::Vec4d{point[0], point[1], point[2], 1.0};
    return {value[0], value[1], value[2]};
}

double vector_norm(const cv::Vec3d& value) {
    return std::sqrt(value.dot(value));
}

std::optional<cv::Vec3d> normalized(const cv::Vec3d& value) {
    const double length = vector_norm(value);
    if (!std::isfinite(length) || length < 1e-9) return std::nullopt;
    return value * (1.0 / length);
}

double translation_delta_m(const cv::Matx44d& first,
                           const cv::Matx44d& second) {
    return vector_norm(position_of(first) - position_of(second));
}

double yaw_deg(const cv::Matx44d& pose) {
    return std::atan2(pose(0, 2), pose(2, 2)) *
           180.0 / std::numbers::pi;
}

double yaw_delta_deg(const cv::Matx44d& first,
                     const cv::Matx44d& second) {
    return std::abs(std::remainder(yaw_deg(first) - yaw_deg(second), 360.0));
}

double rotation_delta_deg(const cv::Matx44d& first,
                          const cv::Matx44d& second) {
    const cv::Matx33d relative = rotation_of(first).t() * rotation_of(second);
    const double trace = relative(0, 0) + relative(1, 1) + relative(2, 2);
    const double cosine = std::clamp((trace - 1.0) * 0.5, -1.0, 1.0);
    return std::acos(cosine) * 180.0 / std::numbers::pi;
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

cv::Matx33d project_rotation(const cv::Matx33d& value) {
    cv::Mat matrix(3, 3, CV_64F);
    for (int row = 0; row < 3; ++row) {
        for (int column = 0; column < 3; ++column) {
            matrix.at<double>(row, column) = value(row, column);
        }
    }
    cv::SVD decomposition(matrix, cv::SVD::FULL_UV);
    cv::Mat u = decomposition.u.clone();
    cv::Mat rotation = u * decomposition.vt;
    if (cv::determinant(rotation) < 0.0) {
        u.col(2) *= -1.0;
        rotation = u * decomposition.vt;
    }
    return {
        rotation.at<double>(0, 0), rotation.at<double>(0, 1), rotation.at<double>(0, 2),
        rotation.at<double>(1, 0), rotation.at<double>(1, 1), rotation.at<double>(1, 2),
        rotation.at<double>(2, 0), rotation.at<double>(2, 1), rotation.at<double>(2, 2),
    };
}

cv::Matx44d blend_pose(const cv::Matx44d& current,
                       const cv::Matx44d& observation,
                       const double observation_weight) {
    const double weight = std::clamp(observation_weight, 0.0, 1.0);
    const auto blended_rotation = project_rotation(
        rotation_of(current) * (1.0 - weight) +
        rotation_of(observation) * weight);
    const auto blended_position =
        position_of(current) * (1.0 - weight) +
        position_of(observation) * weight;
    return matx44_from_rt(blended_rotation, blended_position);
}

std::vector<cv::Point3f> tag_object_points() {
    const float half = static_cast<float>(kTagSizeM * 0.5);
    return {
        {-half, half, 0.0F},
        {half, half, 0.0F},
        {half, -half, 0.0F},
        {-half, -half, 0.0F},
    };
}

double marker_perimeter_px(const std::array<cv::Point2f, 4>& corners) {
    double result = 0.0;
    for (std::size_t index = 0; index < corners.size(); ++index) {
        const auto& first = corners[index];
        const auto& second = corners[(index + 1U) % corners.size()];
        const double dx = static_cast<double>(first.x - second.x);
        const double dy = static_cast<double>(first.y - second.y);
        result += std::sqrt(dx * dx + dy * dy);
    }
    return result;
}

double reprojection_error_px(const std::vector<cv::Point3f>& object_points,
                             const std::array<cv::Point2f, 4>& image_points,
                             const cv::Mat& rotation_vector,
                             const cv::Mat& translation_vector,
                             const cv::Mat& camera_matrix_value,
                             const cv::Mat& distortion_value) {
    std::vector<cv::Point2f> projected;
    cv::projectPoints(object_points, rotation_vector, translation_vector,
                      camera_matrix_value, distortion_value, projected);
    if (projected.size() != image_points.size()) {
        return std::numeric_limits<double>::infinity();
    }
    double squared_sum = 0.0;
    for (std::size_t index = 0; index < projected.size(); ++index) {
        const double dx = static_cast<double>(projected[index].x - image_points[index].x);
        const double dy = static_cast<double>(projected[index].y - image_points[index].y);
        squared_sum += dx * dx + dy * dy;
    }
    return std::sqrt(squared_sum / static_cast<double>(projected.size()));
}

struct FastJob {
    StereoPreviewPair pair;
    ResolvedCalibration calibration;
};

struct MonoPoseCandidate {
    cv::Matx44d camera_from_tag = cv::Matx44d::eye();
    double reprojection_error_px = std::numeric_limits<double>::infinity();
};

struct CameraDetection {
    int id = -1;
    std::array<cv::Point2f, 4> corners{};
    std::vector<MonoPoseCandidate> candidates;
    double perimeter_px = 0.0;
};

struct TagMeasurement {
    int id = -1;
    std::uint64_t pair_index = 0;
    cv::Matx44d camera_a_from_tag = cv::Matx44d::eye();
    std::string pose_source = "NONE";
    bool camera_a_seen = false;
    bool camera_b_seen = false;
    bool stereo_geometry_valid = false;
    bool temporally_stable = false;
    std::uint64_t stable_frames = 0;
    double reprojection_error_px = 0.0;
    double distance_m = 0.0;
    double measured_side_m = 0.0;
    double side_error_ratio = 0.0;
    double stereo_corner_residual_m = 0.0;
    double perimeter_a_px = 0.0;
    double perimeter_b_px = 0.0;
};

struct TagTrack {
    std::deque<TagMeasurement> history;
    std::uint64_t stable_frames = 0;
    std::uint64_t stereo_stable_frames = 0;
};

enum class LandmarkState {
    Candidate,
    StereoVerified,
    Mapped,
    Anchor,
};

const char* landmark_state_name(const LandmarkState state) {
    switch (state) {
        case LandmarkState::Candidate: return "CANDIDATE";
        case LandmarkState::StereoVerified: return "STEREO_VERIFIED";
        case LandmarkState::Mapped: return "MAPPED";
        case LandmarkState::Anchor: return "ANCHOR";
    }
    return "CANDIDATE";
}

struct Landmark {
    int id = -1;
    LandmarkState state = LandmarkState::Candidate;
    cv::Matx44d world_from_tag = cv::Matx44d::eye();
    cv::Matx44d last_independent_camera_pose = cv::Matx44d::eye();
    bool has_last_independent_camera_pose = false;
    std::uint64_t observations = 0;
    std::uint64_t stereo_observations = 0;
    std::uint64_t consistent_observations = 0;
    std::uint64_t independent_views = 0;
    std::uint64_t first_pair_index = 0;
    std::uint64_t last_pair_index = 0;
    std::uint64_t repeat_visits = 0;
    double reprojection_error_sum_px = 0.0;
    double translation_residual_sum_m = 0.0;
    double yaw_residual_sum_deg = 0.0;
};

struct PoseCandidate {
    int id = -1;
    LandmarkState state = LandmarkState::Candidate;
    bool stereo_verified = false;
    std::uint64_t stable_frames = 0;
    cv::Matx44d world_from_camera = cv::Matx44d::eye();
    double quality_cost = 0.0;
};

struct RobustPose {
    cv::Matx44d world_from_camera = cv::Matx44d::eye();
    std::vector<int> used_ids;
    int anchor_count = 0;
    int stereo_count = 0;
    double confidence = 0.0;
};

struct StereoBuildResult {
    bool valid = false;
    cv::Matx44d camera_a_from_tag = cv::Matx44d::eye();
    double measured_side_m = 0.0;
    double side_error_ratio = 0.0;
    double corner_residual_m = 0.0;
};

std::vector<CameraDetection> detect_camera_tags(
    const cv::Mat& image,
    const Intrinsics& intrinsics,
    cv::aruco::ArucoDetector& detector,
    int& rejected_quad_candidates) {
    rejected_quad_candidates = 0;
    if (image.empty()) return {};
    cv::Mat gray;
    if (image.channels() == 1) {
        gray = image;
    } else {
        cv::cvtColor(image, gray, cv::COLOR_BGR2GRAY);
    }
    std::vector<int> ids;
    std::vector<std::vector<cv::Point2f>> corners;
    std::vector<std::vector<cv::Point2f>> rejected;
    detector.detectMarkers(gray, corners, ids, rejected);
    rejected_quad_candidates = static_cast<int>(rejected.size());

    const cv::Mat k = camera_matrix(intrinsics);
    const cv::Mat d = distortion(intrinsics);
    const auto object_points = tag_object_points();
    std::vector<CameraDetection> result;
    const std::size_t count = std::min(ids.size(), corners.size());
    result.reserve(count);
    for (std::size_t index = 0; index < count; ++index) {
        if (ids[index] < kMinimumKitId || ids[index] > kMaximumKitId ||
            corners[index].size() != 4) {
            continue;
        }
        CameraDetection detection;
        detection.id = ids[index];
        std::copy_n(corners[index].begin(), 4, detection.corners.begin());
        detection.perimeter_px = marker_perimeter_px(detection.corners);

        std::vector<cv::Mat> rotation_vectors;
        std::vector<cv::Mat> translation_vectors;
        const int solutions = cv::solvePnPGeneric(
            object_points,
            std::vector<cv::Point2f>(detection.corners.begin(), detection.corners.end()),
            k, d, rotation_vectors, translation_vectors, false,
            cv::SOLVEPNP_IPPE_SQUARE);
        const int usable = std::min<int>(
            solutions,
            static_cast<int>(std::min(rotation_vectors.size(), translation_vectors.size())));
        for (int solution = 0; solution < usable; ++solution) {
            cv::Mat rotation;
            cv::Rodrigues(rotation_vectors[static_cast<std::size_t>(solution)], rotation);
            const auto camera_from_tag_cv = matx44_from_rt(
                rotation, translation_vectors[static_cast<std::size_t>(solution)]);
            const auto camera_from_tag = cv_to_project_transform(camera_from_tag_cv);
            const double distance = vector_norm(position_of(camera_from_tag));
            const double error = reprojection_error_px(
                object_points, detection.corners,
                rotation_vectors[static_cast<std::size_t>(solution)],
                translation_vectors[static_cast<std::size_t>(solution)], k, d);
            if (!std::isfinite(distance) || distance < kMinimumTagDistanceM ||
                distance > kMaximumTagDistanceM || !std::isfinite(error) ||
                error > kMaximumMonoReprojectionErrorPx) {
                continue;
            }
            detection.candidates.push_back({camera_from_tag, error});
        }
        if (!detection.candidates.empty()) result.push_back(std::move(detection));
    }
    return result;
}

const CameraDetection* find_detection(
    const std::vector<CameraDetection>& detections,
    const int id) {
    const auto iterator = std::find_if(
        detections.begin(), detections.end(),
        [id](const CameraDetection& value) { return value.id == id; });
    return iterator == detections.end() ? nullptr : &*iterator;
}

std::array<cv::Point2f, 4> undistorted_points(
    const std::array<cv::Point2f, 4>& input,
    const Intrinsics& intrinsics) {
    std::vector<cv::Point2f> source(input.begin(), input.end());
    std::vector<cv::Point2f> destination;
    cv::undistortPoints(source, destination,
                        camera_matrix(intrinsics), distortion(intrinsics));
    std::array<cv::Point2f, 4> result{};
    if (destination.size() == result.size()) {
        std::copy_n(destination.begin(), 4, result.begin());
    }
    return result;
}

StereoBuildResult build_stereo_pose(
    const CameraDetection& detection_a,
    const CameraDetection& detection_b,
    const PreparedFrame& frame_a,
    const PreparedFrame& frame_b,
    const ResolvedCalibration& calibration) {
    StereoBuildResult result;
    const auto normalized_a = undistorted_points(
        detection_a.corners, frame_a.intrinsics);
    const auto normalized_b = undistorted_points(
        detection_b.corners, frame_b.intrinsics);

    const cv::Mat rotation = rotation_matrix(calibration.rotation);
    const cv::Mat translation_mm = translation_vector(calibration.translation_mm);
    cv::Mat translation_m;
    translation_mm.convertTo(translation_m, CV_64F, 0.001);

    const cv::Mat projection_a = (cv::Mat_<double>(3, 4) <<
        1.0, 0.0, 0.0, 0.0,
        0.0, 1.0, 0.0, 0.0,
        0.0, 0.0, 1.0, 0.0);
    cv::Mat projection_b = cv::Mat::zeros(3, 4, CV_64F);
    rotation.copyTo(projection_b(cv::Rect(0, 0, 3, 3)));
    translation_m.copyTo(projection_b(cv::Rect(3, 0, 1, 3)));

    std::vector<cv::Point2f> points_a(normalized_a.begin(), normalized_a.end());
    std::vector<cv::Point2f> points_b(normalized_b.begin(), normalized_b.end());
    cv::Mat homogeneous;
    cv::triangulatePoints(
        projection_a, projection_b, points_a, points_b, homogeneous);
    if (homogeneous.rows != 4 || homogeneous.cols != 4) return result;
    cv::Mat homogeneous64;
    homogeneous.convertTo(homogeneous64, CV_64F);

    std::array<cv::Vec3d, 4> points{};
    cv::Mat rotation64;
    rotation.convertTo(rotation64, CV_64F);
    const cv::Matx33d rotation_b_from_a(
        rotation64.at<double>(0, 0), rotation64.at<double>(0, 1), rotation64.at<double>(0, 2),
        rotation64.at<double>(1, 0), rotation64.at<double>(1, 1), rotation64.at<double>(1, 2),
        rotation64.at<double>(2, 0), rotation64.at<double>(2, 1), rotation64.at<double>(2, 2));
    const cv::Vec3d translation_b_from_a{
        translation_m.at<double>(0, 0),
        translation_m.at<double>(1, 0),
        translation_m.at<double>(2, 0),
    };
    for (int index = 0; index < 4; ++index) {
        const double w = homogeneous64.at<double>(3, index);
        if (!std::isfinite(w) || std::abs(w) < 1e-12) return result;
        points[static_cast<std::size_t>(index)] = {
            homogeneous64.at<double>(0, index) / w,
            homogeneous64.at<double>(1, index) / w,
            homogeneous64.at<double>(2, index) / w,
        };
        const auto point_b = rotation_b_from_a *
            points[static_cast<std::size_t>(index)] + translation_b_from_a;
        if (points[static_cast<std::size_t>(index)][2] <= 0.10 || point_b[2] <= 0.10) {
            return result;
        }
    }

    const auto distance = [](const cv::Vec3d& first, const cv::Vec3d& second) {
        return vector_norm(first - second);
    };
    const std::array<double, 4> sides{
        distance(points[0], points[1]),
        distance(points[1], points[2]),
        distance(points[2], points[3]),
        distance(points[3], points[0]),
    };
    const double mean_side =
        (sides[0] + sides[1] + sides[2] + sides[3]) * 0.25;
    if (!std::isfinite(mean_side) || mean_side <= 0.0) return result;
    double maximum_side_deviation = 0.0;
    for (const double side : sides) {
        maximum_side_deviation = std::max(
            maximum_side_deviation, std::abs(side - mean_side));
    }
    const double side_error_ratio = std::abs(mean_side - kTagSizeM) / kTagSizeM;
    const double shape_spread_ratio = maximum_side_deviation / mean_side;
    if (side_error_ratio > kMaximumStereoSideErrorRatio ||
        shape_spread_ratio > kMaximumStereoShapeSpreadRatio) {
        return result;
    }

    const cv::Vec3d center =
        (points[0] + points[1] + points[2] + points[3]) * 0.25;
    const cv::Vec3d right_center = (points[1] + points[2]) * 0.5;
    const cv::Vec3d left_center = (points[0] + points[3]) * 0.5;
    const cv::Vec3d top_center = (points[0] + points[1]) * 0.5;
    const cv::Vec3d bottom_center = (points[2] + points[3]) * 0.5;
    const auto x_optional = normalized(right_center - left_center);
    const auto y_seed_optional = normalized(top_center - bottom_center);
    if (!x_optional || !y_seed_optional) return result;
    const auto z_optional = normalized(x_optional->cross(*y_seed_optional));
    if (!z_optional) return result;
    const auto y_optional = normalized(z_optional->cross(*x_optional));
    if (!y_optional) return result;

    double residual_sum = 0.0;
    for (const auto& point : points) {
        residual_sum += std::abs((point - center).dot(*z_optional));
    }
    const double corner_residual = residual_sum * 0.25;
    if (corner_residual > kMaximumStereoCornerResidualM) return result;

    const cv::Matx33d rotation_camera_from_tag(
        (*x_optional)[0], (*y_optional)[0], (*z_optional)[0],
        (*x_optional)[1], (*y_optional)[1], (*z_optional)[1],
        (*x_optional)[2], (*y_optional)[2], (*z_optional)[2]);
    result.camera_a_from_tag = cv_to_project_transform(
        matx44_from_rt(rotation_camera_from_tag, center));
    result.measured_side_m = mean_side;
    result.side_error_ratio = side_error_ratio;
    result.corner_residual_m = corner_residual;
    result.valid = true;
    return result;
}

MonoPoseCandidate choose_temporal_candidate(
    const std::vector<MonoPoseCandidate>& candidates,
    const std::optional<cv::Matx44d>& previous_camera_from_tag) {
    MonoPoseCandidate best;
    double best_score = std::numeric_limits<double>::infinity();
    for (const auto& candidate : candidates) {
        double score = candidate.reprojection_error_px;
        if (previous_camera_from_tag) {
            score += translation_delta_m(
                *previous_camera_from_tag, candidate.camera_from_tag) * 4.0;
            score += rotation_delta_deg(
                *previous_camera_from_tag, candidate.camera_from_tag) * 0.03;
        }
        if (score < best_score) {
            best_score = score;
            best = candidate;
        }
    }
    return best;
}

std::string tag_map_ply(const std::map<int, Landmark>& landmarks) {
    struct Vertex {
        cv::Vec3d position;
        int red = 255;
        int green = 255;
        int blue = 255;
        int id = 0;
        int role = 0;
    };
    std::vector<Vertex> vertices;
    std::vector<std::pair<int, int>> edges;
    const double half = kTagSizeM * 0.5;
    const std::array<cv::Vec3d, 5> local_points{
        cv::Vec3d{-half, half, 0.0},
        cv::Vec3d{half, half, 0.0},
        cv::Vec3d{half, -half, 0.0},
        cv::Vec3d{-half, -half, 0.0},
        cv::Vec3d{0.0, 0.0, 0.0},
    };
    for (const auto& [id, landmark] : landmarks) {
        const int base = static_cast<int>(vertices.size());
        int red = 255;
        int green = 170;
        int blue = 0;
        if (landmark.state == LandmarkState::StereoVerified) {
            red = 255;
            green = 255;
            blue = 0;
        } else if (landmark.state == LandmarkState::Mapped) {
            red = 0;
            green = 190;
            blue = 255;
        } else if (landmark.state == LandmarkState::Anchor) {
            red = 0;
            green = 255;
            blue = 0;
        }
        for (std::size_t index = 0; index < local_points.size(); ++index) {
            vertices.push_back({
                transform_point(landmark.world_from_tag, local_points[index]),
                red, green, blue, id, static_cast<int>(index),
            });
        }
        edges.emplace_back(base + 0, base + 1);
        edges.emplace_back(base + 1, base + 2);
        edges.emplace_back(base + 2, base + 3);
        edges.emplace_back(base + 3, base + 0);
        for (int index = 0; index < 4; ++index) {
            edges.emplace_back(base + 4, base + index);
        }
    }
    std::ostringstream output;
    output << "ply\nformat ascii 1.0\n"
           << "comment MaklerTour 20 FPS stereo AprilTag anchor map\n"
           << "comment tag_size_m " << kTagSizeM << "\n"
           << "comment coordinate_system X_right_Y_up_Z_forward_meters\n"
           << "element vertex " << vertices.size() << "\n"
           << "property float x\nproperty float y\nproperty float z\n"
           << "property uchar red\nproperty uchar green\nproperty uchar blue\n"
           << "property int tag_id\nproperty int vertex_role\n"
           << "element edge " << edges.size() << "\n"
           << "property int vertex1\nproperty int vertex2\nend_header\n";
    output << std::fixed << std::setprecision(6);
    for (const auto& vertex : vertices) {
        output << vertex.position[0] << ' ' << vertex.position[1] << ' '
               << vertex.position[2] << ' ' << vertex.red << ' '
               << vertex.green << ' ' << vertex.blue << ' '
               << vertex.id << ' ' << vertex.role << '\n';
    }
    for (const auto& edge : edges) {
        output << edge.first << ' ' << edge.second << '\n';
    }
    return output.str();
}

}  // namespace

struct StereoAprilTagRuntime::Impl {
    explicit Impl(std::filesystem::path path)
        : session_directory(std::move(path)),
          observations(
              session_directory / "apriltag_stereo_observations.jsonl",
              std::ios::app),
          constraints(
              session_directory / "apriltag_constraints.jsonl",
              std::ios::app),
          dictionary(cv::aruco::getPredefinedDictionary(
              cv::aruco::DICT_APRILTAG_36h11)),
          detector_parameters(),
          detector_a(dictionary, detector_parameters),
          detector_b(dictionary, detector_parameters) {
        if (!observations || !constraints) {
            throw std::runtime_error("cannot create stereo AprilTag diagnostics");
        }
        detector_parameters.cornerRefinementMethod =
            cv::aruco::CORNER_REFINE_SUBPIX;
        detector_parameters.cornerRefinementWinSize = 5;
        detector_parameters.cornerRefinementMaxIterations = 30;
        detector_parameters.cornerRefinementMinAccuracy = 0.03;
        detector_parameters.minMarkerPerimeterRate = 0.015;
        detector_a.setDetectorParameters(detector_parameters);
        detector_b.setDetectorParameters(detector_parameters);
        worker = std::thread([this] { worker_loop(); });
    }

    ~Impl() {
        {
            std::scoped_lock lock(input_mutex);
            stopping = true;
            pending.reset();
        }
        condition.notify_all();
        if (worker.joinable()) worker.join();
        try {
            publish_outputs();
        } catch (...) {
        }
    }

    void submit(StereoPreviewPair pair,
                ResolvedCalibration calibration) {
        const auto now = std::chrono::steady_clock::now();
        std::scoped_lock lock(input_mutex);
        ++submitted_pairs;
        if (last_submission.time_since_epoch().count() != 0 &&
            now - last_submission < kTargetInterval) {
            ++rate_limited_pairs;
            return;
        }
        if (pending) ++queue_replaced_pairs;
        pending = FastJob{std::move(pair), std::move(calibration)};
        last_submission = now;
        condition.notify_one();
    }

    std::optional<TagMeasurement> latest_measurement_locked(
        const int id,
        const std::uint64_t pair_index) const {
        const auto iterator = tracks.find(id);
        if (iterator == tracks.end()) return std::nullopt;
        for (auto reverse = iterator->second.history.rbegin();
             reverse != iterator->second.history.rend(); ++reverse) {
            if (reverse->pair_index > pair_index) continue;
            if (pair_index - reverse->pair_index > kMaximumMeasurementAgePairs) {
                return std::nullopt;
            }
            return *reverse;
        }
        return std::nullopt;
    }

    std::vector<TagMeasurement> latest_measurements_locked(
        const std::uint64_t pair_index) const {
        std::vector<TagMeasurement> result;
        for (const auto& [id, track] : tracks) {
            static_cast<void>(track);
            const auto measurement = latest_measurement_locked(id, pair_index);
            if (measurement) result.push_back(*measurement);
        }
        return result;
    }

    std::vector<PoseCandidate> known_pose_candidates_locked(
        const std::vector<TagMeasurement>& measurements) const {
        std::vector<PoseCandidate> result;
        for (const auto& measurement : measurements) {
            const auto iterator = landmarks.find(measurement.id);
            if (iterator == landmarks.end() ||
                (iterator->second.state != LandmarkState::Mapped &&
                 iterator->second.state != LandmarkState::Anchor) ||
                !measurement.stereo_geometry_valid ||
                measurement.stable_frames < kStereoVerifiedFrames) {
                continue;
            }
            const auto world_from_camera =
                iterator->second.world_from_tag *
                rigid_inverse(measurement.camera_a_from_tag);
            result.push_back({
                measurement.id,
                iterator->second.state,
                true,
                measurement.stable_frames,
                world_from_camera,
                measurement.reprojection_error_px +
                    measurement.side_error_ratio * 4.0 +
                    measurement.stereo_corner_residual_m * 20.0,
            });
        }
        return result;
    }

    static std::optional<RobustPose> robust_camera_pose(
        const std::vector<PoseCandidate>& candidates) {
        if (candidates.empty()) return std::nullopt;
        std::size_t best_index = 0;
        double best_cost = std::numeric_limits<double>::infinity();
        for (std::size_t first = 0; first < candidates.size(); ++first) {
            double cost = candidates[first].quality_cost;
            for (std::size_t second = 0; second < candidates.size(); ++second) {
                if (first == second) continue;
                cost += translation_delta_m(
                    candidates[first].world_from_camera,
                    candidates[second].world_from_camera);
                cost += yaw_delta_deg(
                    candidates[first].world_from_camera,
                    candidates[second].world_from_camera) * 0.01;
            }
            if (cost < best_cost) {
                best_cost = cost;
                best_index = first;
            }
        }
        const auto& seed = candidates[best_index];
        std::vector<const PoseCandidate*> inliers;
        for (const auto& candidate : candidates) {
            if (translation_delta_m(seed.world_from_camera,
                                    candidate.world_from_camera) <=
                    kKnownTagConsensusTranslationM &&
                yaw_delta_deg(seed.world_from_camera,
                              candidate.world_from_camera) <=
                    kKnownTagConsensusYawDeg) {
                inliers.push_back(&candidate);
            }
        }
        if (inliers.empty()) return std::nullopt;
        int anchors = 0;
        int stereo = 0;
        cv::Matx44d fused = inliers.front()->world_from_camera;
        double accumulated_weight = 1.0;
        for (std::size_t index = 0; index < inliers.size(); ++index) {
            anchors += inliers[index]->state == LandmarkState::Anchor ? 1 : 0;
            stereo += inliers[index]->stereo_verified ? 1 : 0;
            if (index == 0) continue;
            const double next_weight = 1.0 /
                std::max(0.2, inliers[index]->quality_cost);
            const double blend_weight = next_weight /
                (accumulated_weight + next_weight);
            fused = blend_pose(
                fused, inliers[index]->world_from_camera, blend_weight);
            accumulated_weight += next_weight;
        }
        if (anchors == 0 && inliers.size() < 2) return std::nullopt;
        RobustPose result;
        result.world_from_camera = fused;
        result.anchor_count = anchors;
        result.stereo_count = stereo;
        for (const auto* inlier : inliers) result.used_ids.push_back(inlier->id);
        result.confidence = std::clamp(
            0.48 + static_cast<double>(inliers.size()) * 0.16 +
                static_cast<double>(anchors) * 0.15 +
                static_cast<double>(stereo) * 0.05,
            0.0, 1.0);
        return result;
    }

    void update_landmarks_locked(
        const std::vector<TagMeasurement>& measurements,
        const cv::Matx44d& world_from_camera,
        const bool pose_independent,
        const std::uint64_t pair_index,
        const std::set<int>& excluded_ids) {
        for (const auto& measurement : measurements) {
            if (excluded_ids.contains(measurement.id)) continue;
            if (!measurement.stereo_geometry_valid ||
                measurement.stable_frames < kStereoVerifiedFrames) {
                continue;
            }
            const auto observed_world_from_tag =
                world_from_camera * measurement.camera_a_from_tag;
            auto [iterator, inserted] = landmarks.try_emplace(measurement.id);
            auto& landmark = iterator->second;
            if (inserted) {
                landmark.id = measurement.id;
                landmark.world_from_tag = observed_world_from_tag;
                landmark.first_pair_index = pair_index;
                landmark.last_pair_index = pair_index;
                landmark.observations = 1;
                landmark.stereo_observations = 1;
                landmark.consistent_observations = 1;
                landmark.independent_views = pose_independent ? 1 : 0;
                landmark.last_independent_camera_pose = world_from_camera;
                landmark.has_last_independent_camera_pose = pose_independent;
                landmark.reprojection_error_sum_px =
                    measurement.reprojection_error_px;
                if (measurement.stable_frames >= kStereoVerifiedFrames) {
                    landmark.state = LandmarkState::StereoVerified;
                }
                continue;
            }

            const double translation_residual = translation_delta_m(
                landmark.world_from_tag, observed_world_from_tag);
            const double yaw_residual = yaw_delta_deg(
                landmark.world_from_tag, observed_world_from_tag);
            if (pair_index > landmark.last_pair_index + kAnchorPairGap) {
                ++landmark.repeat_visits;
            }
            landmark.last_pair_index = pair_index;
            ++landmark.observations;
            ++landmark.stereo_observations;
            landmark.reprojection_error_sum_px +=
                measurement.reprojection_error_px;
            landmark.translation_residual_sum_m += translation_residual;
            landmark.yaw_residual_sum_deg += yaw_residual;

            if (translation_residual <= kLandmarkTranslationGateM &&
                yaw_residual <= kLandmarkYawGateDeg) {
                ++landmark.consistent_observations;
                const double weight = 1.0 /
                    static_cast<double>(std::min<std::uint64_t>(
                        20, landmark.consistent_observations));
                landmark.world_from_tag = blend_pose(
                    landmark.world_from_tag, observed_world_from_tag, weight);
            }

            if (pose_independent) {
                bool independent_view = !landmark.has_last_independent_camera_pose;
                if (landmark.has_last_independent_camera_pose) {
                    independent_view =
                        translation_delta_m(
                            landmark.last_independent_camera_pose,
                            world_from_camera) >= kIndependentViewTranslationM ||
                        yaw_delta_deg(
                            landmark.last_independent_camera_pose,
                            world_from_camera) >= kIndependentViewYawDeg;
                }
                if (independent_view) {
                    ++landmark.independent_views;
                    landmark.last_independent_camera_pose = world_from_camera;
                    landmark.has_last_independent_camera_pose = true;
                }
            }

            if (landmark.state == LandmarkState::Candidate &&
                landmark.stereo_observations >= kStereoVerifiedFrames) {
                landmark.state = LandmarkState::StereoVerified;
            }
            if (landmark.state == LandmarkState::StereoVerified &&
                landmark.independent_views >= kMappedIndependentViews &&
                landmark.consistent_observations >= kStereoVerifiedFrames) {
                landmark.state = LandmarkState::Mapped;
            }
            if (landmark.state == LandmarkState::Mapped &&
                landmark.stereo_observations >= kAnchorStereoObservations &&
                landmark.repeat_visits > 0) {
                landmark.state = LandmarkState::Anchor;
            }
        }
    }

    nlohmann::json relation_entry_locked(
        const int first_id,
        const int second_id,
        const double expected_angle_deg,
        const std::string& relation) const {
        const auto first = landmarks.find(first_id);
        const auto second = landmarks.find(second_id);
        if (first == landmarks.end() || second == landmarks.end()) {
            return {
                {"first_id", first_id},
                {"second_id", second_id},
                {"relation", relation},
                {"available", false},
            };
        }
        const cv::Vec3d first_normal{
            first->second.world_from_tag(0, 2),
            first->second.world_from_tag(1, 2),
            first->second.world_from_tag(2, 2),
        };
        const cv::Vec3d second_normal{
            second->second.world_from_tag(0, 2),
            second->second.world_from_tag(1, 2),
            second->second.world_from_tag(2, 2),
        };
        const auto first_unit = normalized(first_normal);
        const auto second_unit = normalized(second_normal);
        if (!first_unit || !second_unit) {
            return {
                {"first_id", first_id},
                {"second_id", second_id},
                {"relation", relation},
                {"available", false},
            };
        }
        const double cosine = std::clamp(
            std::abs(first_unit->dot(*second_unit)), 0.0, 1.0);
        const double angle = std::acos(cosine) *
            180.0 / std::numbers::pi;
        return {
            {"first_id", first_id},
            {"second_id", second_id},
            {"relation", relation},
            {"available", true},
            {"undirected_normal_angle_deg", angle},
            {"expected_angle_deg", expected_angle_deg},
            {"absolute_error_deg", std::abs(angle - expected_angle_deg)},
        };
    }

    nlohmann::json relations_json_locked() const {
        return {
            {"schema_version", 1},
            {"mode", "DIAGNOSTIC_ONLY_NO_HARD_CONSTRAINT"},
            {"relations", nlohmann::json::array({
                relation_entry_locked(8, 3, 90.0, "PERPENDICULAR_WALLS"),
                relation_entry_locked(8, 2, 0.0, "PARALLEL_WALLS"),
            })},
        };
    }

    nlohmann::json map_json_locked() const {
        nlohmann::json tags = nlohmann::json::array();
        for (const auto& [id, landmark] : landmarks) {
            const double count = static_cast<double>(
                std::max<std::uint64_t>(1, landmark.observations));
            tags.push_back({
                {"id", id},
                {"state", landmark_state_name(landmark.state)},
                {"world_from_tag", pose_json(landmark.world_from_tag)},
                {"position_m", nlohmann::json::array({
                    landmark.world_from_tag(0, 3),
                    landmark.world_from_tag(1, 3),
                    landmark.world_from_tag(2, 3)})},
                {"observations", landmark.observations},
                {"stereo_observations", landmark.stereo_observations},
                {"consistent_observations", landmark.consistent_observations},
                {"independent_views", landmark.independent_views},
                {"repeat_visits", landmark.repeat_visits},
                {"first_pair_index", landmark.first_pair_index},
                {"last_pair_index", landmark.last_pair_index},
                {"mean_reprojection_error_px",
                 landmark.reprojection_error_sum_px / count},
                {"mean_translation_residual_m",
                 landmark.translation_residual_sum_m / count},
                {"mean_yaw_residual_deg",
                 landmark.yaw_residual_sum_deg / count},
            });
        }
        return {
            {"schema_version", 2},
            {"mode", "STEREO_APRILTAG_TEMPORAL_ANCHOR_MAP"},
            {"family", "36h11"},
            {"allowed_ids", nlohmann::json::array({1, 30})},
            {"tag_size_m", kTagSizeM},
            {"coordinate_system", "X_right_Y_up_Z_forward_meters"},
            {"origin", "initial_CAMERA_A_pose"},
            {"tags", std::move(tags)},
        };
    }

    nlohmann::json status_json_locked() const {
        std::uint64_t candidates = 0;
        std::uint64_t stereo_verified = 0;
        std::uint64_t mapped = 0;
        std::uint64_t anchors = 0;
        for (const auto& [id, landmark] : landmarks) {
            static_cast<void>(id);
            if (landmark.state == LandmarkState::Candidate) ++candidates;
            if (landmark.state == LandmarkState::StereoVerified) ++stereo_verified;
            if (landmark.state == LandmarkState::Mapped) ++mapped;
            if (landmark.state == LandmarkState::Anchor) ++anchors;
        }
        return {
            {"state", last_error.empty() ? "READY" : "ERROR"},
            {"mode", "20FPS_STEREO_APRILTAG_TEMPORAL_CONSENSUS"},
            {"family", "36h11"},
            {"tag_size_m", kTagSizeM},
            {"target_fps", 20},
            {"minimum_id", kMinimumKitId},
            {"maximum_id", kMaximumKitId},
            {"submitted_pairs", submitted_pairs.load()},
            {"processed_pairs", processed_pairs},
            {"rate_limited_pairs", rate_limited_pairs.load()},
            {"queue_replaced_pairs", queue_replaced_pairs.load()},
            {"pairs_with_any_tag", pairs_with_any_tag},
            {"pairs_with_stereo_tag", pairs_with_stereo_tag},
            {"decoded_detections_a", decoded_detections_a},
            {"decoded_detections_b", decoded_detections_b},
            {"stereo_measurements", stereo_measurements},
            {"mono_fallback_measurements", mono_fallback_measurements},
            {"rejected_stereo_geometry", rejected_stereo_geometry},
            {"rejected_quad_candidates_a", rejected_quad_candidates_a},
            {"rejected_quad_candidates_b", rejected_quad_candidates_b},
            {"candidate_tags", candidates},
            {"stereo_verified_tags", stereo_verified},
            {"mapped_tags", mapped},
            {"anchor_tags", anchors},
            {"anchor_pose_frames", anchor_pose_frames},
            {"live_corrections", live_corrections},
            {"constraint_only_frames", constraint_only_frames},
            {"relocalizations", relocalizations},
            {"last_pair_index", last_pair_index},
            {"last_detected_ids", last_detected_ids},
            {"last_pose_source", last_pose_source},
            {"last_position_correction_m", last_position_correction_m},
            {"last_yaw_correction_deg", last_yaw_correction_deg},
            {"last_confidence", last_confidence},
            {"map_file", "apriltag_map.json"},
            {"map_ply_file", "apriltag_map.ply"},
            {"relations_file", "apriltag_relations.json"},
            {"observations_file", "apriltag_stereo_observations.jsonl"},
            {"constraints_file", "apriltag_constraints.jsonl"},
            {"preview_a_file", "apriltag_latest_a.jpg"},
            {"preview_b_file", "apriltag_latest_b.jpg"},
            {"last_error", last_error},
        };
    }

    void publish_outputs_locked() const {
        write_text_atomic(session_directory / "apriltag_map.json",
                          map_json_locked().dump(2) + "\n");
        write_text_atomic(session_directory / "apriltag_map.ply",
                          tag_map_ply(landmarks));
        write_text_atomic(session_directory / "apriltag_relations.json",
                          relations_json_locked().dump(2) + "\n");
        write_text_atomic(session_directory / "apriltag_status.json",
                          status_json_locked().dump(2) + "\n");
    }

    void publish_outputs() const {
        std::scoped_lock lock(state_mutex);
        publish_outputs_locked();
    }

    void append_constraint_locked(
        const std::uint64_t pair_index,
        const StereoAprilTagAnchorResult& result) {
        if (!result.anchor_pose_valid) return;
        constraints << nlohmann::json{
            {"ts_unix_ms", unix_time_ms()},
            {"pair_index", pair_index},
            {"type", result.live_correction_allowed
                ? (result.relocalized
                    ? "APRILTAG_RELOCALIZATION_ACCEPTED"
                    : "APRILTAG_LIVE_CORRECTION_ACCEPTED")
                : "APRILTAG_CONSTRAINT_REJECTED_LIVE"},
            {"tag_ids", result.used_anchor_ids},
            {"pose_source", result.pose_source},
            {"world_from_camera", pose_json(result.world_from_camera)},
            {"mapped_tags_used", result.mapped_tags_used},
            {"stereo_tags_used", result.stereo_tags_used},
            {"position_correction_m", result.position_correction_m},
            {"yaw_correction_deg", result.yaw_correction_deg},
            {"confidence", result.confidence},
        }.dump() << '\n';
        constraints.flush();
    }

    void update_track_locked(TagMeasurement measurement) {
        auto& track = tracks[measurement.id];
        bool stable = true;
        if (!track.history.empty()) {
            const auto& previous = track.history.back();
            stable = translation_delta_m(
                         previous.camera_a_from_tag,
                         measurement.camera_a_from_tag) <=
                         kMaximumTemporalTranslationStepM &&
                     rotation_delta_deg(
                         previous.camera_a_from_tag,
                         measurement.camera_a_from_tag) <=
                         kMaximumTemporalRotationStepDeg;
        }
        if (stable) {
            ++track.stable_frames;
        } else {
            track.stable_frames = 1;
            track.stereo_stable_frames = 0;
        }
        if (measurement.stereo_geometry_valid && stable) {
            ++track.stereo_stable_frames;
        } else if (!measurement.stereo_geometry_valid) {
            track.stereo_stable_frames = 0;
        }
        measurement.temporally_stable = stable;
        measurement.stable_frames = measurement.stereo_geometry_valid
            ? track.stereo_stable_frames
            : track.stable_frames;
        track.history.push_back(std::move(measurement));
        while (track.history.size() > kTemporalTrackLength) {
            track.history.pop_front();
        }
    }

    std::optional<cv::Matx44d> previous_measurement_pose_locked(
        const int id) const {
        const auto iterator = tracks.find(id);
        if (iterator == tracks.end() || iterator->second.history.empty()) {
            return std::nullopt;
        }
        return iterator->second.history.back().camera_a_from_tag;
    }

    void worker_loop() {
        while (true) {
            FastJob job;
            {
                std::unique_lock lock(input_mutex);
                condition.wait(lock, [this] {
                    return stopping || pending.has_value();
                });
                if (stopping) break;
                job = std::move(*pending);
                pending.reset();
            }
            try {
                const auto frame_a = prepare_frame(
                    job.pair.camera_a, job.calibration.camera_a, "APRILTAG_CAMERA_A");
                const auto frame_b = prepare_frame(
                    job.pair.camera_b, job.calibration.camera_b, "APRILTAG_CAMERA_B");
                int rejected_a = 0;
                int rejected_b = 0;
                auto detections_a = detect_camera_tags(
                    frame_a.image, frame_a.intrinsics, detector_a, rejected_a);
                auto detections_b = detect_camera_tags(
                    frame_b.image, frame_b.intrinsics, detector_b, rejected_b);

                const cv::Mat rotation_b_from_a =
                    rotation_matrix(job.calibration.rotation);
                const cv::Mat translation_b_from_a_mm =
                    translation_vector(job.calibration.translation_mm);
                cv::Mat translation_b_from_a_m;
                translation_b_from_a_mm.convertTo(
                    translation_b_from_a_m, CV_64F, 0.001);
                const auto camera_b_from_camera_a = cv_to_project_transform(
                    matx44_from_rt(
                        rotation_b_from_a, translation_b_from_a_m));
                const auto camera_a_from_camera_b =
                    rigid_inverse(camera_b_from_camera_a);

                std::set<int> all_ids;
                for (const auto& detection : detections_a) all_ids.insert(detection.id);
                for (const auto& detection : detections_b) all_ids.insert(detection.id);
                std::vector<TagMeasurement> measurements;
                measurements.reserve(all_ids.size());

                cv::Mat annotated_a = frame_a.image.clone();
                cv::Mat annotated_b = frame_b.image.clone();
                std::vector<int> draw_ids_a;
                std::vector<int> draw_ids_b;
                std::vector<std::vector<cv::Point2f>> draw_corners_a;
                std::vector<std::vector<cv::Point2f>> draw_corners_b;
                for (const auto& detection : detections_a) {
                    draw_ids_a.push_back(detection.id);
                    draw_corners_a.emplace_back(
                        detection.corners.begin(), detection.corners.end());
                }
                for (const auto& detection : detections_b) {
                    draw_ids_b.push_back(detection.id);
                    draw_corners_b.emplace_back(
                        detection.corners.begin(), detection.corners.end());
                }
                if (!draw_ids_a.empty()) {
                    cv::aruco::drawDetectedMarkers(
                        annotated_a, draw_corners_a, draw_ids_a);
                }
                if (!draw_ids_b.empty()) {
                    cv::aruco::drawDetectedMarkers(
                        annotated_b, draw_corners_b, draw_ids_b);
                }

                for (const int id : all_ids) {
                    const auto* detection_a = find_detection(detections_a, id);
                    const auto* detection_b = find_detection(detections_b, id);
                    TagMeasurement measurement;
                    measurement.id = id;
                    measurement.pair_index = job.pair.pair_index;
                    measurement.camera_a_seen = detection_a != nullptr;
                    measurement.camera_b_seen = detection_b != nullptr;
                    measurement.perimeter_a_px = detection_a
                        ? detection_a->perimeter_px : 0.0;
                    measurement.perimeter_b_px = detection_b
                        ? detection_b->perimeter_px : 0.0;

                    if (detection_a && detection_b) {
                        const auto stereo = build_stereo_pose(
                            *detection_a, *detection_b,
                            frame_a, frame_b, job.calibration);
                        if (stereo.valid) {
                            measurement.camera_a_from_tag =
                                stereo.camera_a_from_tag;
                            measurement.pose_source = "STEREO_4_CORNERS";
                            measurement.stereo_geometry_valid = true;
                            measurement.measured_side_m =
                                stereo.measured_side_m;
                            measurement.side_error_ratio =
                                stereo.side_error_ratio;
                            measurement.stereo_corner_residual_m =
                                stereo.corner_residual_m;
                            const double error_a = detection_a->candidates.empty()
                                ? 0.0
                                : detection_a->candidates.front()
                                      .reprojection_error_px;
                            const double error_b = detection_b->candidates.empty()
                                ? 0.0
                                : detection_b->candidates.front()
                                      .reprojection_error_px;
                            measurement.reprojection_error_px =
                                (error_a + error_b) * 0.5;
                            ++stereo_measurements;
                        } else {
                            ++rejected_stereo_geometry;
                        }
                    }

                    if (!measurement.stereo_geometry_valid) {
                        std::vector<MonoPoseCandidate> candidates;
                        if (detection_a) {
                            candidates.insert(
                                candidates.end(),
                                detection_a->candidates.begin(),
                                detection_a->candidates.end());
                        }
                        if (detection_b) {
                            for (const auto& candidate : detection_b->candidates) {
                                candidates.push_back({
                                    camera_a_from_camera_b *
                                        candidate.camera_from_tag,
                                    candidate.reprojection_error_px,
                                });
                            }
                        }
                        if (candidates.empty()) continue;
                        std::optional<cv::Matx44d> previous;
                        {
                            std::scoped_lock lock(state_mutex);
                            previous = previous_measurement_pose_locked(id);
                        }
                        const auto selected = choose_temporal_candidate(
                            candidates, previous);
                        measurement.camera_a_from_tag =
                            selected.camera_from_tag;
                        measurement.reprojection_error_px =
                            selected.reprojection_error_px;
                        measurement.pose_source = detection_a && detection_b
                            ? "DUAL_MONO_IPPE_TEMPORAL"
                            : (detection_a
                                ? "MONO_A_IPPE_TEMPORAL"
                                : "MONO_B_IPPE_TEMPORAL");
                        ++mono_fallback_measurements;
                    }
                    measurement.distance_m = vector_norm(
                        position_of(measurement.camera_a_from_tag));
                    if (!std::isfinite(measurement.distance_m) ||
                        measurement.distance_m < kMinimumTagDistanceM ||
                        measurement.distance_m > kMaximumTagDistanceM) {
                        continue;
                    }
                    measurements.push_back(std::move(measurement));
                }

                {
                    std::scoped_lock lock(state_mutex);
                    ++processed_pairs;
                    if (!all_ids.empty()) ++pairs_with_any_tag;
                    bool any_stereo = false;
                    for (auto& measurement : measurements) {
                        any_stereo = any_stereo ||
                            measurement.stereo_geometry_valid;
                        update_track_locked(std::move(measurement));
                    }
                    if (any_stereo) ++pairs_with_stereo_tag;
                    decoded_detections_a += detections_a.size();
                    decoded_detections_b += detections_b.size();
                    rejected_quad_candidates_a +=
                        static_cast<std::uint64_t>(std::max(0, rejected_a));
                    rejected_quad_candidates_b +=
                        static_cast<std::uint64_t>(std::max(0, rejected_b));
                    last_pair_index = job.pair.pair_index;
                    last_detected_ids.assign(all_ids.begin(), all_ids.end());
                    last_error.clear();

                    nlohmann::json values = nlohmann::json::array();
                    for (const int id : all_ids) {
                        const auto latest = latest_measurement_locked(
                            id, job.pair.pair_index);
                        if (!latest) continue;
                        values.push_back({
                            {"id", latest->id},
                            {"pose_source", latest->pose_source},
                            {"camera_a_seen", latest->camera_a_seen},
                            {"camera_b_seen", latest->camera_b_seen},
                            {"stereo_geometry_valid",
                             latest->stereo_geometry_valid},
                            {"temporally_stable",
                             latest->temporally_stable},
                            {"stable_frames", latest->stable_frames},
                            {"distance_m", latest->distance_m},
                            {"measured_side_m", latest->measured_side_m},
                            {"side_error_ratio", latest->side_error_ratio},
                            {"stereo_corner_residual_m",
                             latest->stereo_corner_residual_m},
                            {"reprojection_error_px",
                             latest->reprojection_error_px},
                            {"perimeter_a_px", latest->perimeter_a_px},
                            {"perimeter_b_px", latest->perimeter_b_px},
                            {"camera_a_from_tag",
                             pose_json(latest->camera_a_from_tag)},
                        });
                    }
                    observations << nlohmann::json{
                        {"ts_unix_ms", unix_time_ms()},
                        {"pair_index", job.pair.pair_index},
                        {"pair_delta_ms", job.pair.delta_ms},
                        {"sync_mode", job.pair.sync_mode},
                        {"family", "36h11"},
                        {"tag_size_m", kTagSizeM},
                        {"rejected_quad_candidates_a", rejected_a},
                        {"rejected_quad_candidates_b", rejected_b},
                        {"measurements", std::move(values)},
                    }.dump() << '\n';
                    observations.flush();
                    publish_outputs_locked();
                }
                if (!draw_ids_a.empty()) {
                    write_image_atomic(
                        session_directory / "apriltag_latest_a.jpg",
                        annotated_a);
                }
                if (!draw_ids_b.empty()) {
                    write_image_atomic(
                        session_directory / "apriltag_latest_b.jpg",
                        annotated_b);
                }
            } catch (const std::exception& error) {
                std::scoped_lock lock(state_mutex);
                ++processed_pairs;
                last_error = error.what();
                publish_outputs_locked();
            }
        }
    }

    StereoAprilTagAnchorResult evaluate(
        const std::uint64_t pair_index,
        const std::optional<cv::Matx44d>& preliminary_world_from_camera,
        const bool preliminary_translation_trusted) {
        std::scoped_lock lock(state_mutex);
        StereoAprilTagAnchorResult result;
        const auto measurements = latest_measurements_locked(pair_index);
        result.detections_present = !measurements.empty();
        result.detected_tags = static_cast<int>(measurements.size());
        result.ids.reserve(measurements.size());
        for (const auto& measurement : measurements) {
            result.ids.push_back(measurement.id);
            result.stereo_verified = result.stereo_verified ||
                (measurement.stereo_geometry_valid &&
                 measurement.stable_frames >= kStereoVerifiedFrames);
        }

        const auto pose_candidates =
            known_pose_candidates_locked(measurements);
        const auto robust_pose = robust_camera_pose(pose_candidates);
        if (robust_pose) {
            result.anchor_pose_valid = true;
            result.world_from_camera = robust_pose->world_from_camera;
            result.used_anchor_ids = robust_pose->used_ids;
            result.mapped_tags_used =
                static_cast<int>(robust_pose->used_ids.size());
            result.stereo_tags_used = robust_pose->stereo_count;
            result.confidence = robust_pose->confidence;
            result.pose_source = robust_pose->used_ids.size() >= 2
                ? "MULTI_TAG_STEREO_CONSENSUS"
                : "SINGLE_ANCHOR_STEREO";

            if (preliminary_world_from_camera) {
                result.position_correction_m = translation_delta_m(
                    *preliminary_world_from_camera,
                    robust_pose->world_from_camera);
                result.yaw_correction_deg = yaw_delta_deg(
                    *preliminary_world_from_camera,
                    robust_pose->world_from_camera);
            }
            const bool small_correction = preliminary_world_from_camera &&
                result.position_correction_m <= kSafeLiveCorrectionM &&
                result.yaw_correction_deg <= kSafeLiveCorrectionYawDeg &&
                result.confidence >= kMinimumLiveConfidence;
            const bool hard_relocalization =
                !preliminary_translation_trusted &&
                result.confidence >= 0.78 &&
                (robust_pose->anchor_count >= 1 ||
                 robust_pose->used_ids.size() >= 2) &&
                robust_pose->stereo_count >= 1;
            result.live_correction_allowed =
                small_correction || hard_relocalization;
            result.constraint_only = !result.live_correction_allowed;
            result.relocalized = result.live_correction_allowed &&
                (!preliminary_world_from_camera ||
                 result.position_correction_m >=
                     kRelocalizationPositionThresholdM ||
                 result.yaw_correction_deg >=
                     kRelocalizationYawThresholdDeg);
        }

        std::set<int> excluded_ids;
        for (const int id : result.used_anchor_ids) excluded_ids.insert(id);
        if (preliminary_world_from_camera && preliminary_translation_trusted) {
            update_landmarks_locked(
                measurements, *preliminary_world_from_camera, true,
                pair_index, {});
        } else if (result.live_correction_allowed) {
            update_landmarks_locked(
                measurements, result.world_from_camera, true,
                pair_index, excluded_ids);
        } else if (landmarks.empty() && preliminary_world_from_camera) {
            update_landmarks_locked(
                measurements, *preliminary_world_from_camera, true,
                pair_index, {});
        }

        if (result.anchor_pose_valid) ++anchor_pose_frames;
        if (result.live_correction_allowed) ++live_corrections;
        if (result.constraint_only) ++constraint_only_frames;
        if (result.relocalized) ++relocalizations;
        last_pose_source = result.pose_source;
        last_position_correction_m = result.position_correction_m;
        last_yaw_correction_deg = result.yaw_correction_deg;
        last_confidence = result.confidence;
        append_constraint_locked(pair_index, result);
        publish_outputs_locked();
        return result;
    }

    void reset() {
        {
            std::scoped_lock lock(input_mutex);
            pending.reset();
            last_submission = {};
        }
        std::scoped_lock lock(state_mutex);
        tracks.clear();
        landmarks.clear();
        processed_pairs = 0;
        pairs_with_any_tag = 0;
        pairs_with_stereo_tag = 0;
        decoded_detections_a = 0;
        decoded_detections_b = 0;
        stereo_measurements = 0;
        mono_fallback_measurements = 0;
        rejected_stereo_geometry = 0;
        rejected_quad_candidates_a = 0;
        rejected_quad_candidates_b = 0;
        anchor_pose_frames = 0;
        live_corrections = 0;
        constraint_only_frames = 0;
        relocalizations = 0;
        last_pair_index = 0;
        last_detected_ids.clear();
        last_pose_source = "NONE";
        last_position_correction_m = 0.0;
        last_yaw_correction_deg = 0.0;
        last_confidence = 0.0;
        last_error.clear();
        for (const auto* name : {
                 "apriltag_map.json",
                 "apriltag_map.ply",
                 "apriltag_relations.json",
                 "apriltag_status.json",
                 "apriltag_latest_a.jpg",
                 "apriltag_latest_b.jpg",
             }) {
            std::error_code error;
            std::filesystem::remove(session_directory / name, error);
        }
        publish_outputs_locked();
    }

    nlohmann::json status_json() const {
        std::scoped_lock lock(state_mutex);
        return status_json_locked();
    }

    std::filesystem::path session_directory;
    mutable std::mutex input_mutex;
    mutable std::mutex state_mutex;
    std::condition_variable condition;
    bool stopping = false;
    std::optional<FastJob> pending;
    std::chrono::steady_clock::time_point last_submission;
    std::thread worker;

    std::ofstream observations;
    std::ofstream constraints;
    cv::aruco::Dictionary dictionary;
    cv::aruco::DetectorParameters detector_parameters;
    cv::aruco::ArucoDetector detector_a;
    cv::aruco::ArucoDetector detector_b;

    std::map<int, TagTrack> tracks;
    std::map<int, Landmark> landmarks;

    std::atomic<std::uint64_t> submitted_pairs{0};
    std::atomic<std::uint64_t> rate_limited_pairs{0};
    std::atomic<std::uint64_t> queue_replaced_pairs{0};
    std::uint64_t processed_pairs = 0;
    std::uint64_t pairs_with_any_tag = 0;
    std::uint64_t pairs_with_stereo_tag = 0;
    std::uint64_t decoded_detections_a = 0;
    std::uint64_t decoded_detections_b = 0;
    std::uint64_t stereo_measurements = 0;
    std::uint64_t mono_fallback_measurements = 0;
    std::uint64_t rejected_stereo_geometry = 0;
    std::uint64_t rejected_quad_candidates_a = 0;
    std::uint64_t rejected_quad_candidates_b = 0;
    std::uint64_t anchor_pose_frames = 0;
    std::uint64_t live_corrections = 0;
    std::uint64_t constraint_only_frames = 0;
    std::uint64_t relocalizations = 0;
    std::uint64_t last_pair_index = 0;
    std::vector<int> last_detected_ids;
    std::string last_pose_source = "NONE";
    double last_position_correction_m = 0.0;
    double last_yaw_correction_deg = 0.0;
    double last_confidence = 0.0;
    std::string last_error;
};

StereoAprilTagRuntime::StereoAprilTagRuntime(
    std::filesystem::path session_directory)
    : impl_(std::make_unique<Impl>(std::move(session_directory))) {}

StereoAprilTagRuntime::~StereoAprilTagRuntime() = default;

void StereoAprilTagRuntime::submit(
    StereoPreviewPair pair,
    ResolvedCalibration calibration) {
    impl_->submit(std::move(pair), std::move(calibration));
}

StereoAprilTagAnchorResult StereoAprilTagRuntime::evaluate(
    const std::uint64_t pair_index,
    const std::optional<cv::Matx44d>& preliminary_world_from_camera,
    const bool preliminary_translation_trusted) {
    return impl_->evaluate(
        pair_index, preliminary_world_from_camera,
        preliminary_translation_trusted);
}

void StereoAprilTagRuntime::reset() { impl_->reset(); }

nlohmann::json StereoAprilTagRuntime::status_json() const {
    return impl_->status_json();
}

}  // namespace maklertour::dual_phone::detail
