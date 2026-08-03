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
constexpr std::size_t kMaximumRegistrationKeyframes = 24;
constexpr std::size_t kRelocalizationCandidates = 8;
constexpr int kOrbFeatures = 1400;
constexpr int kMinimumFeatures = 80;
constexpr int kMinimumCorrespondences = 24;
constexpr int kMinimumPnPInliers = 18;
constexpr double kMinimumInlierRatio = 0.32;
constexpr double kMaximumReprojectionRmse = 4.0;
constexpr double kMinimumKeyframeTranslationMeters = 0.06;
constexpr double kMinimumKeyframeRotationDegrees = 4.0;
constexpr double kMaximumStepTranslationMeters = 1.50;
constexpr double kMaximumStepRotationDegrees = 55.0;

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

struct TrajectorySample {
    std::uint64_t keyframe_id = 0;
    std::uint64_t pair_index = 0;
    std::string state;
    cv::Matx44d world_from_camera = cv::Matx44d::eye();
    int matches = 0;
    int inliers = 0;
    double inlier_ratio = 0.0;
    double reprojection_rmse = 0.0;
    double translation_m = 0.0;
    double rotation_deg = 0.0;
    std::int64_t timestamp_ms = 0;
};

struct PoseEstimate {
    bool valid = false;
    bool relocalized = false;
    std::uint64_t reference_keyframe_id = 0;
    cv::Matx44d world_from_camera = cv::Matx44d::eye();
    int matches = 0;
    int correspondences = 0;
    int inliers = 0;
    double inlier_ratio = 0.0;
    double reprojection_rmse = std::numeric_limits<double>::infinity();
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

PoseEstimate estimate_from_reference(
    const Keyframe& reference,
    const TrackingFrame& current) {
    PoseEstimate estimate;
    estimate.reference_keyframe_id = reference.id;
    if (reference.frame.descriptors.empty() ||
        current.descriptors.empty()) {
        return estimate;
    }

    cv::BFMatcher matcher(cv::NORM_HAMMING, false);
    std::vector<std::vector<cv::DMatch>> neighbours;
    matcher.knnMatch(
        reference.frame.descriptors,
        current.descriptors,
        neighbours,
        2);

    std::vector<cv::Point3f> object_points;
    std::vector<cv::Point2f> image_points;
    object_points.reserve(neighbours.size());
    image_points.reserve(neighbours.size());
    int ratio_matches = 0;
    for (const auto& pair : neighbours) {
        if (pair.size() < 2) continue;
        if (pair[0].distance >= 0.75F * pair[1].distance) continue;
        ++ratio_matches;
        const auto query_index =
            static_cast<std::size_t>(pair[0].queryIdx);
        const auto train_index =
            static_cast<std::size_t>(pair[0].trainIdx);
        if (query_index >= reference.frame.keypoints.size() ||
            train_index >= current.keypoints.size()) {
            continue;
        }
        const auto point = point_from_disparity_cv(
            reference.frame,
            reference.frame.keypoints[query_index].pt);
        if (!point) continue;
        object_points.push_back(*point);
        image_points.push_back(current.keypoints[train_index].pt);
    }
    estimate.matches = ratio_matches;
    estimate.correspondences =
        static_cast<int>(object_points.size());
    if (estimate.correspondences < kMinimumCorrespondences) {
        return estimate;
    }

    const cv::Mat camera_matrix =
        (cv::Mat_<double>(3, 3) <<
            current.focal_px, 0.0, current.principal_x_px,
            0.0, current.focal_px, current.principal_y_px,
            0.0, 0.0, 1.0);
    cv::Mat rotation_vector;
    cv::Mat translation_vector;
    cv::Mat inlier_indices;
    const bool solved = cv::solvePnPRansac(
        object_points,
        image_points,
        camera_matrix,
        cv::noArray(),
        rotation_vector,
        translation_vector,
        false,
        120,
        3.0,
        0.99,
        inlier_indices,
        cv::SOLVEPNP_ITERATIVE);
    if (!solved) return estimate;

    estimate.inliers = inlier_indices.rows;
    estimate.inlier_ratio =
        static_cast<double>(estimate.inliers) /
        static_cast<double>(
            std::max(1, estimate.correspondences));
    if (estimate.inliers < kMinimumPnPInliers ||
        estimate.inlier_ratio < kMinimumInlierRatio) {
        return estimate;
    }

    std::vector<cv::Point3f> inlier_object_points;
    std::vector<cv::Point2f> inlier_image_points;
    inlier_object_points.reserve(
        static_cast<std::size_t>(estimate.inliers));
    inlier_image_points.reserve(
        static_cast<std::size_t>(estimate.inliers));
    for (int row = 0; row < inlier_indices.rows; ++row) {
        const int index = inlier_indices.at<int>(row, 0);
        if (index < 0 ||
            index >= static_cast<int>(object_points.size())) {
            continue;
        }
        inlier_object_points.push_back(
            object_points[static_cast<std::size_t>(index)]);
        inlier_image_points.push_back(
            image_points[static_cast<std::size_t>(index)]);
    }

    std::vector<cv::Point2f> projected;
    cv::projectPoints(
        inlier_object_points,
        rotation_vector,
        translation_vector,
        camera_matrix,
        cv::noArray(),
        projected);
    double squared_error = 0.0;
    const auto count = std::min(
        projected.size(), inlier_image_points.size());
    for (std::size_t index = 0; index < count; ++index) {
        const auto difference =
            projected[index] - inlier_image_points[index];
        squared_error += static_cast<double>(
            difference.dot(difference));
    }
    estimate.reprojection_rmse = count > 0
        ? std::sqrt(
              squared_error / static_cast<double>(count))
        : std::numeric_limits<double>::infinity();
    if (!std::isfinite(estimate.reprojection_rmse) ||
        estimate.reprojection_rmse > kMaximumReprojectionRmse) {
        return estimate;
    }

    cv::Mat rotation_matrix;
    cv::Rodrigues(rotation_vector, rotation_matrix);
    cv::Mat rotation_64;
    cv::Mat translation_64;
    rotation_matrix.convertTo(rotation_64, CV_64F);
    translation_vector.convertTo(translation_64, CV_64F);
    const cv::Matx44d camera_from_reference_cv(
        rotation_64.at<double>(0, 0),
        rotation_64.at<double>(0, 1),
        rotation_64.at<double>(0, 2),
        translation_64.at<double>(0, 0),
        rotation_64.at<double>(1, 0),
        rotation_64.at<double>(1, 1),
        rotation_64.at<double>(1, 2),
        translation_64.at<double>(1, 0),
        rotation_64.at<double>(2, 0),
        rotation_64.at<double>(2, 1),
        rotation_64.at<double>(2, 2),
        translation_64.at<double>(2, 0),
        0.0, 0.0, 0.0, 1.0);
    const auto camera_from_reference_up =
        cv_to_y_up_transform(camera_from_reference_cv);
    estimate.world_from_camera =
        reference.world_from_camera *
        rigid_inverse(camera_from_reference_up);
    estimate.valid = true;
    return estimate;
}

PoseEstimate estimate_pose(
    const std::deque<Keyframe>& references,
    const TrackingFrame& current) {
    PoseEstimate best;
    const std::size_t candidate_count = std::min(
        references.size(), kRelocalizationCandidates);
    for (std::size_t offset = 0;
         offset < candidate_count;
         ++offset) {
        const auto& reference =
            references[references.size() - 1 - offset];
        auto estimate = estimate_from_reference(reference, current);
        if (!estimate.valid) continue;
        estimate.relocalized = offset > 0;
        const bool better =
            !best.valid ||
            estimate.inliers > best.inliers ||
            (estimate.inliers == best.inliers &&
             estimate.reprojection_rmse <
                 best.reprojection_rmse);
        if (better) best = std::move(estimate);
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
        const double scale =
            1.0 / static_cast<double>(voxel.observations);
        const auto position = voxel.position_sum * scale;
        const auto colour = voxel.colour_sum * scale;
        output << position[0] << ' ' << position[1] << ' '
               << position[2] << ' '
               << static_cast<int>(
                      cv::saturate_cast<std::uint8_t>(colour[0]))
               << ' '
               << static_cast<int>(
                      cv::saturate_cast<std::uint8_t>(colour[1]))
               << ' '
               << static_cast<int>(
                      cv::saturate_cast<std::uint8_t>(colour[2]))
               << ' ' << voxel.observations
               << ' ' << voxel.last_keyframe_id << '\n';
    }
    return output.str();
}

std::string trajectory_ply(
    const std::vector<TrajectorySample>& trajectory) {
    const std::size_t edge_count =
        trajectory.size() > 1 ? trajectory.size() - 1 : 0;
    std::ostringstream output;
    output << "ply\nformat ascii 1.0\n"
           << "comment MaklerTour camera trajectory\n"
           << "comment coordinate_system X_right_Y_up_Z_forward_meters\n"
           << "element vertex " << trajectory.size() << "\n"
           << "property float x\nproperty float y\nproperty float z\n"
           << "property uchar red\nproperty uchar green\n"
           << "property uchar blue\n"
           << "property uint keyframe_id\n"
           << "element edge " << edge_count << "\n"
           << "property int vertex1\nproperty int vertex2\n"
           << "end_header\n";
    output << std::fixed << std::setprecision(6);
    for (const auto& sample : trajectory) {
        output << sample.world_from_camera(0, 3) << ' '
               << sample.world_from_camera(1, 3) << ' '
               << sample.world_from_camera(2, 3) << ' '
               << "255 255 0 " << sample.keyframe_id << '\n';
    }
    for (std::size_t index = 1;
         index < trajectory.size();
         ++index) {
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
            {"world_from_camera", pose_json(
                 sample.world_from_camera)},
            {"position_m", nlohmann::json::array({
                 sample.world_from_camera(0, 3),
                 sample.world_from_camera(1, 3),
                 sample.world_from_camera(2, 3),
             })},
            {"matches", sample.matches},
            {"inliers", sample.inliers},
            {"inlier_ratio", sample.inlier_ratio},
            {"reprojection_rmse_px",
             sample.reprojection_rmse},
            {"translation_from_previous_m",
             sample.translation_m},
            {"rotation_from_previous_deg",
             sample.rotation_deg},
            {"timestamp_ms", sample.timestamp_ms},
        });
    }
    return {
        {"schema_version", 1},
        {"coordinate_system",
         "X_right_Y_up_Z_forward_meters"},
        {"samples", std::move(samples)},
    };
}

}  // namespace

struct AccumulatedMapRuntime::Impl {
    explicit Impl(std::filesystem::path session_path)
        : session_directory(std::move(session_path)),
          diagnostics(
              session_directory / "accumulated_map.jsonl",
              std::ios::app) {
        if (!diagnostics) {
            throw std::runtime_error(
                "cannot create accumulated map diagnostics");
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
            now - last_accepted_submission <
                kMinimumSubmissionInterval) {
            ++rejected_interval_frames;
            return false;
        }
        if (depth.work_a.empty() ||
            depth.geometry_disparity.empty() ||
            depth.geometry_mask.empty()) {
            ++rejected_invalid_frames;
            return false;
        }

        MapJob job;
        job.generation = generation;
        job.pair_index = input_pair_index;
        job.source_profile = std::move(input_source_profile);
        // Clone only after backpressure has accepted this frame.
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
            last_translation_m = 0.0;
            last_rotation_deg = 0.0;
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
            {"recommended_profile", "HIGH_640"},
            {"source_profile", source_profile},
            {"last_pair_index", last_pair_index},
            {"last_reference_keyframe_id",
             last_reference_keyframe_id},
            {"keyframe_count", keyframe_count},
            {"trajectory_samples", trajectory_samples},
            {"accumulated_points", accumulated_points},
            {"voxel_size_m", kVoxelMeters},
            {"matches", last_matches},
            {"correspondences", last_correspondences},
            {"inliers", last_inliers},
            {"inlier_ratio", last_inlier_ratio},
            {"reprojection_rmse_px",
             last_reprojection_rmse},
            {"translation_from_previous_m",
             last_translation_m},
            {"rotation_from_previous_deg",
             last_rotation_deg},
            {"processing_ms", last_processing_ms},
            {"submitted_frames", submitted_frames},
            {"accepted_frames", accepted_frames},
            {"processed_frames", processed_frames},
            {"failed_frames", failed_frames},
            {"lost_frames", lost_frames},
            {"stationary_frames", stationary_frames},
            {"relocalizations", relocalizations},
            {"rejected_profile_frames",
             rejected_profile_frames},
            {"rejected_busy_frames", rejected_busy_frames},
            {"rejected_interval_frames",
             rejected_interval_frames},
            {"rejected_invalid_frames",
             rejected_invalid_frames},
            {"generation", generation},
            {"coordinate_system",
             "X_right_Y_up_Z_forward_meters"},
            {"point_cloud_file",
             "point_cloud_accumulated.ply"},
            {"trajectory_file", "camera_trajectory.json"},
            {"trajectory_ply_file",
             "camera_trajectory.ply"},
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
        next_keyframe_id = 1;
        last_publish = {};
        for (const auto* name : {
                 "point_cloud_accumulated.ply",
                 "camera_trajectory.json",
                 "camera_trajectory.ply",
             }) {
            std::error_code error;
            std::filesystem::remove(
                session_directory / name, error);
        }
    }

    void merge_keyframe(
        const MapJob& job,
        const cv::Matx44d& world_from_camera,
        const std::uint64_t keyframe_id) {
        const int stride =
            job.disparity.total() > 1000000 ? 3 : 2;
        for (int row = 0;
             row < job.disparity.rows;
             row += stride) {
            const auto* disparity_row =
                job.disparity.ptr<float>(row);
            const auto* mask_row =
                job.mask.ptr<std::uint8_t>(row);
            const auto* colour_row =
                job.colour.ptr<cv::Vec3b>(row);
            for (int column = 0;
                 column < job.disparity.cols;
                 column += stride) {
                if (mask_row[column] == 0) continue;
                const double disparity =
                    static_cast<double>(
                        disparity_row[column]);
                if (!std::isfinite(disparity) ||
                    disparity <= 1.0) {
                    continue;
                }
                const double z =
                    job.focal_px * job.baseline_mm /
                    disparity / 1000.0;
                if (!std::isfinite(z) ||
                    z < kNearMeters || z > kFarMeters) {
                    continue;
                }
                const double x =
                    (static_cast<double>(column) -
                     job.principal_x_px) *
                    z / job.focal_px;
                const double y_up =
                    -(static_cast<double>(row) -
                      job.principal_y_px) *
                    z / job.focal_px;
                const auto world = transform_point(
                    world_from_camera,
                    {x, y_up, z});
                if (!std::isfinite(world[0]) ||
                    !std::isfinite(world[1]) ||
                    !std::isfinite(world[2]) ||
                    std::abs(world[0]) > 20.0 ||
                    std::abs(world[1]) > 10.0 ||
                    std::abs(world[2]) > 20.0) {
                    continue;
                }
                const VoxelKey key{
                    static_cast<int>(
                        std::floor(world[0] /
                                   kVoxelMeters)),
                    static_cast<int>(
                        std::floor(world[1] /
                                   kVoxelMeters)),
                    static_cast<int>(
                        std::floor(world[2] /
                                   kVoxelMeters)),
                };
                auto iterator = voxels.find(key);
                if (iterator == voxels.end()) {
                    if (voxels.size() >=
                        kMaximumVoxels) {
                        continue;
                    }
                    iterator = voxels.emplace(
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
                if (voxel.observations <
                    std::numeric_limits<
                        std::uint32_t>::max()) {
                    ++voxel.observations;
                }
                voxel.last_keyframe_id = keyframe_id;
            }
        }
    }

    void publish_outputs(const bool force) {
        if (trajectory.empty() || voxels.empty()) return;
        const auto now = std::chrono::steady_clock::now();
        if (!force &&
            last_publish.time_since_epoch().count() != 0 &&
            now - last_publish < kMinimumPublishInterval) {
            return;
        }
        write_text_atomic(
            session_directory /
                "point_cloud_accumulated.ply",
            accumulated_cloud_ply(voxels));
        write_text_atomic(
            session_directory / "camera_trajectory.json",
            trajectory_json(trajectory).dump(2) + "\n");
        write_text_atomic(
            session_directory / "camera_trajectory.ply",
            trajectory_ply(trajectory));
        last_publish = now;
    }

    void worker_loop() {
        while (true) {
            MapJob job;
            {
                std::unique_lock lock(mutex);
                condition.wait(lock, [this] {
                    return stopping || clear_requested ||
                           pending.has_value();
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

            const auto started =
                std::chrono::steady_clock::now();
            try {
                const auto current =
                    make_tracking_frame(job);
                if (current.keypoints.size() <
                        static_cast<std::size_t>(
                            kMinimumFeatures) ||
                    current.descriptors.empty()) {
                    throw std::runtime_error(
                        "insufficient ORB features for registration");
                }

                PoseEstimate estimate;
                cv::Matx44d world_from_camera =
                    cv::Matx44d::eye();
                bool first_keyframe =
                    registration_keyframes.empty();
                if (!first_keyframe) {
                    estimate = estimate_pose(
                        registration_keyframes,
                        current);
                    if (!estimate.valid) {
                        const double processing_ms =
                            std::chrono::duration<
                                double, std::milli>(
                                std::chrono::steady_clock::now() -
                                started).count();
                        nlohmann::json diagnostic;
                        {
                            std::scoped_lock lock(mutex);
                            if (job.generation != generation) {
                                continue;
                            }
                            tracking_state = "LOST";
                            ready = !trajectory.empty();
                            source_profile =
                                job.source_profile;
                            last_pair_index =
                                job.pair_index;
                            last_matches =
                                estimate.matches;
                            last_correspondences =
                                estimate.correspondences;
                            last_inliers = estimate.inliers;
                            last_inlier_ratio =
                                estimate.inlier_ratio;
                            last_reprojection_rmse =
                                std::isfinite(
                                    estimate.reprojection_rmse)
                                    ? estimate.reprojection_rmse
                                    : 0.0;
                            last_processing_ms =
                                processing_ms;
                            ++processed_frames;
                            ++lost_frames;
                            diagnostic = {
                                {"event", "ACCUMULATED_MAP_LOST"},
                                {"pair_index", last_pair_index},
                                {"matches", last_matches},
                                {"correspondences",
                                 last_correspondences},
                                {"inliers", last_inliers},
                                {"processing_ms",
                                 last_processing_ms},
                            };
                        }
                        append_diagnostic(
                            std::move(diagnostic));
                        write_status_file();
                        continue;
                    }
                    world_from_camera =
                        estimate.world_from_camera;
                }

                double translation_m = 0.0;
                double rotation_deg = 0.0;
                bool accept_keyframe = first_keyframe;
                if (!first_keyframe) {
                    const auto delta = pose_delta(
                        registration_keyframes.back()
                            .world_from_camera,
                        world_from_camera);
                    translation_m = delta.first;
                    rotation_deg = delta.second;
                    if (translation_m >
                            kMaximumStepTranslationMeters ||
                        rotation_deg >
                            kMaximumStepRotationDegrees) {
                        throw std::runtime_error(
                            "registration step exceeds motion sanity limits");
                    }
                    accept_keyframe =
                        translation_m >=
                            kMinimumKeyframeTranslationMeters ||
                        rotation_deg >=
                            kMinimumKeyframeRotationDegrees;
                }

                const double processing_ms =
                    std::chrono::duration<double, std::milli>(
                        std::chrono::steady_clock::now() -
                        started).count();
                if (!accept_keyframe) {
                    nlohmann::json diagnostic;
                    {
                        std::scoped_lock lock(mutex);
                        if (job.generation != generation) {
                            continue;
                        }
                        tracking_state =
                            "TRACKING_STATIONARY";
                        ready = !trajectory.empty();
                        source_profile =
                            job.source_profile;
                        last_pair_index = job.pair_index;
                        last_reference_keyframe_id =
                            estimate.reference_keyframe_id;
                        last_matches = estimate.matches;
                        last_correspondences =
                            estimate.correspondences;
                        last_inliers = estimate.inliers;
                        last_inlier_ratio =
                            estimate.inlier_ratio;
                        last_reprojection_rmse =
                            estimate.reprojection_rmse;
                        last_translation_m =
                            translation_m;
                        last_rotation_deg = rotation_deg;
                        last_processing_ms =
                            processing_ms;
                        ++processed_frames;
                        ++stationary_frames;
                        diagnostic = {
                            {"event",
                             "ACCUMULATED_MAP_STATIONARY"},
                            {"pair_index", last_pair_index},
                            {"translation_m",
                             last_translation_m},
                            {"rotation_deg",
                             last_rotation_deg},
                            {"inliers", last_inliers},
                            {"processing_ms",
                             last_processing_ms},
                        };
                    }
                    append_diagnostic(
                        std::move(diagnostic));
                    write_status_file();
                    continue;
                }

                const std::uint64_t keyframe_id =
                    next_keyframe_id++;
                merge_keyframe(
                    job,
                    world_from_camera,
                    keyframe_id);

                Keyframe keyframe;
                keyframe.id = keyframe_id;
                keyframe.pair_index = job.pair_index;
                keyframe.frame = current;
                keyframe.world_from_camera =
                    world_from_camera;
                registration_keyframes.push_back(
                    std::move(keyframe));
                while (registration_keyframes.size() >
                       kMaximumRegistrationKeyframes) {
                    registration_keyframes.pop_front();
                }

                const std::string state =
                    first_keyframe
                    ? "TRACKING_INITIALIZED"
                    : (estimate.relocalized
                       ? "RELOCALIZED"
                       : "TRACKING");
                trajectory.push_back({
                    keyframe_id,
                    job.pair_index,
                    state,
                    world_from_camera,
                    estimate.matches,
                    estimate.inliers,
                    estimate.inlier_ratio,
                    std::isfinite(
                        estimate.reprojection_rmse)
                        ? estimate.reprojection_rmse
                        : 0.0,
                    translation_m,
                    rotation_deg,
                    unix_time_ms(),
                });
                publish_outputs(false);

                nlohmann::json diagnostic;
                {
                    std::scoped_lock lock(mutex);
                    if (job.generation != generation) {
                        continue;
                    }
                    ready = true;
                    tracking_state = state;
                    source_profile = job.source_profile;
                    last_pair_index = job.pair_index;
                    last_reference_keyframe_id =
                        estimate.reference_keyframe_id;
                    keyframe_count =
                        trajectory.size();
                    trajectory_samples =
                        trajectory.size();
                    accumulated_points =
                        voxels.size();
                    last_matches = estimate.matches;
                    last_correspondences =
                        estimate.correspondences;
                    last_inliers = estimate.inliers;
                    last_inlier_ratio =
                        estimate.inlier_ratio;
                    last_reprojection_rmse =
                        std::isfinite(
                            estimate.reprojection_rmse)
                            ? estimate.reprojection_rmse
                            : 0.0;
                    last_translation_m =
                        translation_m;
                    last_rotation_deg = rotation_deg;
                    last_processing_ms =
                        processing_ms;
                    last_error.clear();
                    ++processed_frames;
                    if (estimate.relocalized) {
                        ++relocalizations;
                    }
                    diagnostic = {
                        {"event",
                         "ACCUMULATED_MAP_KEYFRAME"},
                        {"state", tracking_state},
                        {"pair_index", last_pair_index},
                        {"keyframe_id", keyframe_id},
                        {"reference_keyframe_id",
                         last_reference_keyframe_id},
                        {"matches", last_matches},
                        {"correspondences",
                         last_correspondences},
                        {"inliers", last_inliers},
                        {"inlier_ratio",
                         last_inlier_ratio},
                        {"reprojection_rmse_px",
                         last_reprojection_rmse},
                        {"translation_m",
                         last_translation_m},
                        {"rotation_deg",
                         last_rotation_deg},
                        {"accumulated_points",
                         accumulated_points},
                        {"processing_ms",
                         last_processing_ms},
                    };
                }
                append_diagnostic(std::move(diagnostic));
                write_status_file();
            } catch (const std::exception& error) {
                nlohmann::json diagnostic;
                {
                    std::scoped_lock lock(mutex);
                    if (job.generation != generation) {
                        continue;
                    }
                    tracking_state = "ERROR";
                    source_profile = job.source_profile;
                    last_pair_index = job.pair_index;
                    last_processing_ms =
                        std::chrono::duration<
                            double, std::milli>(
                            std::chrono::steady_clock::now() -
                            started).count();
                    last_error = error.what();
                    ++failed_frames;
                    diagnostic = {
                        {"event", "ACCUMULATED_MAP_FAILED"},
                        {"pair_index", last_pair_index},
                        {"error", last_error},
                        {"processing_ms",
                         last_processing_ms},
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
    std::chrono::steady_clock::time_point
        last_accepted_submission;
    std::chrono::steady_clock::time_point last_publish;

    std::deque<Keyframe> registration_keyframes;
    std::vector<TrajectorySample> trajectory;
    std::unordered_map<
        VoxelKey,
        VoxelAccumulator,
        VoxelKeyHash> voxels;
    std::uint64_t next_keyframe_id = 1;

    bool ready = false;
    std::string tracking_state = "WAITING";
    std::string source_profile = "WAITING";
    std::uint64_t last_pair_index = 0;
    std::uint64_t last_reference_keyframe_id = 0;
    std::uint64_t keyframe_count = 0;
    std::uint64_t trajectory_samples = 0;
    std::uint64_t accumulated_points = 0;
    int last_matches = 0;
    int last_correspondences = 0;
    int last_inliers = 0;
    double last_inlier_ratio = 0.0;
    double last_reprojection_rmse = 0.0;
    double last_translation_m = 0.0;
    double last_rotation_deg = 0.0;
    double last_processing_ms = 0.0;
    std::string last_error;

    std::uint64_t submitted_frames = 0;
    std::uint64_t accepted_frames = 0;
    std::uint64_t processed_frames = 0;
    std::uint64_t failed_frames = 0;
    std::uint64_t lost_frames = 0;
    std::uint64_t stationary_frames = 0;
    std::uint64_t relocalizations = 0;
    std::uint64_t rejected_profile_frames = 0;
    std::uint64_t rejected_busy_frames = 0;
    std::uint64_t rejected_interval_frames = 0;
    std::uint64_t rejected_invalid_frames = 0;
};

AccumulatedMapRuntime::AccumulatedMapRuntime(
    std::filesystem::path session_directory)
    : impl_(std::make_unique<Impl>(
          std::move(session_directory))) {}

AccumulatedMapRuntime::~AccumulatedMapRuntime() = default;

bool AccumulatedMapRuntime::submit(
    const std::uint64_t pair_index,
    std::string source_profile,
    const StereoDepthResult& depth) {
    return impl_->submit(
        pair_index,
        std::move(source_profile),
        depth);
}

void AccumulatedMapRuntime::reset() {
    impl_->reset();
}

nlohmann::json AccumulatedMapRuntime::status_json() const {
    return impl_->status_json();
}

}  // namespace maklertour::dual_phone::detail
