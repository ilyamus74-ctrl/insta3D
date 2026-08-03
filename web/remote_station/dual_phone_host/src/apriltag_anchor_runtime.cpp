#include "apriltag_anchor_runtime.hpp"

#include <algorithm>
#include <array>
#include <chrono>
#include <cmath>
#include <cstddef>
#include <cstdint>
#include <fstream>
#include <iomanip>
#include <limits>
#include <map>
#include <mutex>
#include <numbers>
#include <optional>
#include <sstream>
#include <stdexcept>
#include <string>
#include <system_error>
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
constexpr double kMaximumReprojectionErrorPx = 3.0;
constexpr double kMinimumTagDistanceM = 0.20;
constexpr double kMaximumTagDistanceM = 8.0;
constexpr double kCandidateTranslationGateM = 0.30;
constexpr double kCandidateYawGateDeg = 18.0;
constexpr std::uint64_t kMappedObservationCount = 3;
constexpr std::uint64_t kAnchorObservationCount = 5;
constexpr std::uint64_t kAnchorPairGap = 24;
constexpr double kKnownTagConsensusTranslationM = 0.35;
constexpr double kKnownTagConsensusYawDeg = 18.0;
constexpr double kRelocalizationPositionThresholdM = 0.20;
constexpr double kRelocalizationYawThresholdDeg = 8.0;

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

cv::Vec3d position_of(const cv::Matx44d& pose) {
    return {pose(0, 3), pose(1, 3), pose(2, 3)};
}

double translation_delta_m(const cv::Matx44d& first,
                           const cv::Matx44d& second) {
    const auto difference = position_of(first) - position_of(second);
    return std::sqrt(difference.dot(difference));
}

double yaw_deg(const cv::Matx44d& pose) {
    return std::atan2(pose(0, 2), pose(2, 2)) *
           180.0 / std::numbers::pi;
}

double yaw_delta_deg(const cv::Matx44d& first,
                     const cv::Matx44d& second) {
    return std::abs(std::remainder(yaw_deg(first) - yaw_deg(second), 360.0));
}

nlohmann::json pose_json(const cv::Matx44d& pose) {
    nlohmann::json rows = nlohmann::json::array();
    for (int row_index = 0; row_index < 4; ++row_index) {
        nlohmann::json values = nlohmann::json::array();
        for (int column_index = 0; column_index < 4; ++column_index) {
            values.push_back(pose(row_index, column_index));
        }
        rows.push_back(std::move(values));
    }
    return rows;
}

cv::Matx33d rotation_of(const cv::Matx44d& pose) {
    return {
        pose(0, 0), pose(0, 1), pose(0, 2),
        pose(1, 0), pose(1, 1), pose(1, 2),
        pose(2, 0), pose(2, 1), pose(2, 2),
    };
}

cv::Matx33d project_rotation(const cv::Matx33d& value) {
    cv::Mat matrix(3, 3, CV_64F);
    for (int row_index = 0; row_index < 3; ++row_index) {
        for (int column_index = 0; column_index < 3; ++column_index) {
            matrix.at<double>(row_index, column_index) =
                value(row_index, column_index);
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
    const auto current_rotation = rotation_of(current);
    const auto observed_rotation = rotation_of(observation);
    const auto blended_rotation = project_rotation(
        current_rotation * (1.0 - weight) + observed_rotation * weight);
    const auto current_position = position_of(current);
    const auto observed_position = position_of(observation);
    const auto blended_position =
        current_position * (1.0 - weight) + observed_position * weight;
    return {
        blended_rotation(0, 0), blended_rotation(0, 1), blended_rotation(0, 2), blended_position[0],
        blended_rotation(1, 0), blended_rotation(1, 1), blended_rotation(1, 2), blended_position[1],
        blended_rotation(2, 0), blended_rotation(2, 1), blended_rotation(2, 2), blended_position[2],
        0.0, 0.0, 0.0, 1.0,
    };
}

cv::Vec3d transform_point(const cv::Matx44d& transform,
                          const cv::Vec3d& point) {
    const auto value = transform * cv::Vec4d{point[0], point[1], point[2], 1.0};
    return {value[0], value[1], value[2]};
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

double marker_perimeter_px(const std::vector<cv::Point2f>& corners) {
    if (corners.size() != 4) return 0.0;
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
                             const std::vector<cv::Point2f>& image_points,
                             const cv::Mat& rotation_vector,
                             const cv::Mat& translation_vector,
                             const cv::Mat& camera_matrix) {
    std::vector<cv::Point2f> projected;
    cv::projectPoints(object_points, rotation_vector, translation_vector,
                      camera_matrix, cv::noArray(), projected);
    if (projected.size() != image_points.size() || projected.empty()) {
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

enum class LandmarkState {
    Candidate,
    Mapped,
    Anchor,
};

const char* landmark_state_name(const LandmarkState state) {
    switch (state) {
        case LandmarkState::Candidate: return "CANDIDATE";
        case LandmarkState::Mapped: return "MAPPED";
        case LandmarkState::Anchor: return "ANCHOR";
    }
    return "CANDIDATE";
}

struct Detection {
    int id = -1;
    std::array<cv::Point2f, 4> corners{};
    cv::Matx44d camera_from_tag = cv::Matx44d::eye();
    double reprojection_error_px = 0.0;
    double distance_m = 0.0;
    double perimeter_px = 0.0;
};

struct Landmark {
    int id = -1;
    LandmarkState state = LandmarkState::Candidate;
    cv::Matx44d world_from_tag = cv::Matx44d::eye();
    std::uint64_t observations = 0;
    std::uint64_t consistent_observations = 0;
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
    cv::Matx44d world_from_camera = cv::Matx44d::eye();
    double reprojection_error_px = 0.0;
};

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
        int green = 180;
        int blue = 0;
        if (landmark.state == LandmarkState::Mapped) {
            red = 0;
            green = 200;
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
           << "comment MaklerTour online AprilTag 36h11 anchor map\n"
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

struct AprilTagAnchorRuntime::Impl {
    explicit Impl(std::filesystem::path path)
        : session_directory(std::move(path)),
          observations(session_directory / "apriltag_observations.jsonl", std::ios::app),
          constraints(session_directory / "apriltag_constraints.jsonl", std::ios::app),
          dictionary(cv::aruco::getPredefinedDictionary(
              cv::aruco::DICT_APRILTAG_36h11)),
          detector_parameters(),
          detector(dictionary, detector_parameters) {
        if (!observations || !constraints) {
            throw std::runtime_error("cannot create AprilTag diagnostics");
        }
        detector_parameters.cornerRefinementMethod =
            cv::aruco::CORNER_REFINE_SUBPIX;
        detector_parameters.cornerRefinementWinSize = 5;
        detector_parameters.cornerRefinementMaxIterations = 30;
        detector_parameters.cornerRefinementMinAccuracy = 0.05;
        detector_parameters.minMarkerPerimeterRate = 0.02;
        detector.setDetectorParameters(detector_parameters);
    }

    ~Impl() {
        try {
            publish_map();
            write_status_file();
        } catch (...) {
        }
    }

    std::vector<Detection> detect(const cv::Mat& camera_a_bgr,
                                  const double focal_px,
                                  const double principal_x_px,
                                  const double principal_y_px,
                                  int& rejected_count) {
        rejected_count = 0;
        if (camera_a_bgr.empty() || !std::isfinite(focal_px) || focal_px <= 1.0 ||
            !std::isfinite(principal_x_px) || !std::isfinite(principal_y_px)) {
            return {};
        }
        cv::Mat gray;
        if (camera_a_bgr.channels() == 1) {
            gray = camera_a_bgr;
        } else {
            cv::cvtColor(camera_a_bgr, gray, cv::COLOR_BGR2GRAY);
        }
        std::vector<int> ids;
        std::vector<std::vector<cv::Point2f>> corners;
        std::vector<std::vector<cv::Point2f>> rejected;
        detector.detectMarkers(gray, corners, ids, rejected);
        rejected_count = static_cast<int>(rejected.size());
        const auto object_points = tag_object_points();
        const cv::Mat camera_matrix = (cv::Mat_<double>(3, 3) <<
            focal_px, 0.0, principal_x_px,
            0.0, focal_px, principal_y_px,
            0.0, 0.0, 1.0);
        std::vector<Detection> result;
        const std::size_t count = std::min(ids.size(), corners.size());
        result.reserve(count);
        for (std::size_t index = 0; index < count; ++index) {
            const int id = ids[index];
            if (id < kMinimumKitId || id > kMaximumKitId ||
                corners[index].size() != 4) {
                ++rejected_count;
                continue;
            }
            cv::Mat rotation_vector;
            cv::Mat translation_vector;
            const bool solved = cv::solvePnP(
                object_points, corners[index], camera_matrix, cv::noArray(),
                rotation_vector, translation_vector, false,
                cv::SOLVEPNP_IPPE_SQUARE);
            if (!solved) {
                ++rejected_count;
                continue;
            }
            const double reprojection = reprojection_error_px(
                object_points, corners[index], rotation_vector,
                translation_vector, camera_matrix);
            cv::Mat rotation;
            cv::Rodrigues(rotation_vector, rotation);
            const auto camera_from_tag_cv = matx44_from_rt(
                rotation, translation_vector);
            const auto camera_from_tag =
                cv_to_project_transform(camera_from_tag_cv);
            const auto translation = position_of(camera_from_tag);
            const double distance = std::sqrt(translation.dot(translation));
            if (!std::isfinite(reprojection) ||
                reprojection > kMaximumReprojectionErrorPx ||
                !std::isfinite(distance) ||
                distance < kMinimumTagDistanceM ||
                distance > kMaximumTagDistanceM) {
                ++rejected_count;
                continue;
            }
            Detection detection;
            detection.id = id;
            std::copy_n(corners[index].begin(), 4, detection.corners.begin());
            detection.camera_from_tag = camera_from_tag;
            detection.reprojection_error_px = reprojection;
            detection.distance_m = distance;
            detection.perimeter_px = marker_perimeter_px(corners[index]);
            result.push_back(std::move(detection));
        }
        return result;
    }

    std::vector<PoseCandidate> known_pose_candidates(
        const std::vector<Detection>& detections) const {
        std::vector<PoseCandidate> result;
        for (const auto& detection : detections) {
            const auto iterator = landmarks.find(detection.id);
            if (iterator == landmarks.end() ||
                iterator->second.state == LandmarkState::Candidate) {
                continue;
            }
            result.push_back({
                detection.id,
                iterator->second.state,
                iterator->second.world_from_tag *
                    rigid_inverse(detection.camera_from_tag),
                detection.reprojection_error_px,
            });
        }
        return result;
    }

    static std::optional<cv::Matx44d> robust_camera_pose(
        const std::vector<PoseCandidate>& candidates,
        int& used_count,
        double& confidence) {
        used_count = 0;
        confidence = 0.0;
        if (candidates.empty()) return std::nullopt;
        std::size_t best_index = 0;
        double best_cost = std::numeric_limits<double>::infinity();
        for (std::size_t first_index = 0;
             first_index < candidates.size(); ++first_index) {
            double cost = candidates[first_index].reprojection_error_px * 0.1;
            for (std::size_t second_index = 0;
                 second_index < candidates.size(); ++second_index) {
                if (first_index == second_index) continue;
                cost += translation_delta_m(
                    candidates[first_index].world_from_camera,
                    candidates[second_index].world_from_camera);
                cost += yaw_delta_deg(
                    candidates[first_index].world_from_camera,
                    candidates[second_index].world_from_camera) * 0.01;
            }
            if (cost < best_cost) {
                best_cost = cost;
                best_index = first_index;
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
        bool has_anchor = false;
        cv::Matx44d fused = inliers.front()->world_from_camera;
        double accumulated_weight = 1.0;
        double reprojection_sum = inliers.front()->reprojection_error_px;
        for (std::size_t index = 0; index < inliers.size(); ++index) {
            has_anchor = has_anchor ||
                inliers[index]->state == LandmarkState::Anchor;
            if (index == 0) continue;
            const double next_weight = 1.0 /
                std::max(0.25, inliers[index]->reprojection_error_px);
            const double blend_weight = next_weight /
                (accumulated_weight + next_weight);
            fused = blend_pose(
                fused, inliers[index]->world_from_camera, blend_weight);
            accumulated_weight += next_weight;
            reprojection_sum += inliers[index]->reprojection_error_px;
        }
        if (!has_anchor && inliers.size() == 1) {
            const auto* only = inliers.front();
            if (only->state != LandmarkState::Mapped ||
                only->reprojection_error_px > 2.0) {
                return std::nullopt;
            }
        }
        used_count = static_cast<int>(inliers.size());
        const double mean_reprojection = reprojection_sum /
            static_cast<double>(inliers.size());
        confidence = std::clamp(
            0.45 + 0.18 * static_cast<double>(inliers.size()) -
                mean_reprojection * 0.08 + (has_anchor ? 0.15 : 0.0),
            0.0, 1.0);
        return fused;
    }

    void update_landmarks(const std::vector<Detection>& detections,
                          const cv::Matx44d& world_from_camera,
                          const std::uint64_t pair_index) {
        for (const auto& detection : detections) {
            const auto observed_world_from_tag =
                world_from_camera * detection.camera_from_tag;
            auto [iterator, inserted] = landmarks.try_emplace(detection.id);
            auto& landmark = iterator->second;
            if (inserted) {
                landmark.id = detection.id;
                landmark.world_from_tag = observed_world_from_tag;
                landmark.first_pair_index = pair_index;
                landmark.last_pair_index = pair_index;
                landmark.observations = 1;
                landmark.consistent_observations = 1;
                landmark.reprojection_error_sum_px =
                    detection.reprojection_error_px;
                continue;
            }
            const double translation_residual = translation_delta_m(
                landmark.world_from_tag, observed_world_from_tag);
            const double yaw_residual = yaw_delta_deg(
                landmark.world_from_tag, observed_world_from_tag);
            ++landmark.observations;
            landmark.reprojection_error_sum_px +=
                detection.reprojection_error_px;
            landmark.translation_residual_sum_m += translation_residual;
            landmark.yaw_residual_sum_deg += yaw_residual;
            if (pair_index > landmark.last_pair_index + kAnchorPairGap) {
                ++landmark.repeat_visits;
            }
            landmark.last_pair_index = pair_index;
            if (translation_residual <= kCandidateTranslationGateM &&
                yaw_residual <= kCandidateYawGateDeg) {
                ++landmark.consistent_observations;
                const double weight = 1.0 /
                    static_cast<double>(std::min<std::uint64_t>(
                        20, landmark.consistent_observations));
                landmark.world_from_tag = blend_pose(
                    landmark.world_from_tag, observed_world_from_tag, weight);
            }
            if (landmark.state == LandmarkState::Candidate &&
                landmark.consistent_observations >= kMappedObservationCount) {
                landmark.state = LandmarkState::Mapped;
            }
            if (landmark.state == LandmarkState::Mapped &&
                landmark.consistent_observations >= kAnchorObservationCount &&
                (landmark.repeat_visits > 0 ||
                 pair_index >= landmark.first_pair_index + kAnchorPairGap)) {
                landmark.state = LandmarkState::Anchor;
            }
        }
    }

    void publish_annotated_preview(
        const cv::Mat& camera_a_bgr,
        const std::vector<Detection>& detections) const {
        if (camera_a_bgr.empty() || detections.empty()) return;
        cv::Mat annotated = camera_a_bgr.clone();
        std::vector<int> ids;
        std::vector<std::vector<cv::Point2f>> corners;
        ids.reserve(detections.size());
        corners.reserve(detections.size());
        for (const auto& detection : detections) {
            ids.push_back(detection.id);
            corners.emplace_back(
                detection.corners.begin(), detection.corners.end());
        }
        cv::aruco::drawDetectedMarkers(annotated, corners, ids);
        write_image_atomic(
            session_directory / "apriltag_latest.jpg", annotated);
    }

    void append_observation_log(const std::uint64_t pair_index,
                                const std::vector<Detection>& detections,
                                const int rejected_count,
                                const AprilTagAnchorResult& result) {
        nlohmann::json values = nlohmann::json::array();
        for (const auto& detection : detections) {
            values.push_back({
                {"id", detection.id},
                {"distance_m", detection.distance_m},
                {"reprojection_error_px", detection.reprojection_error_px},
                {"perimeter_px", detection.perimeter_px},
                {"camera_from_tag", pose_json(detection.camera_from_tag)},
            });
        }
        nlohmann::json entry{
            {"ts_unix_ms", unix_time_ms()},
            {"pair_index", pair_index},
            {"camera", "CAMERA_A_RECTIFIED"},
            {"family", "36h11"},
            {"tag_size_m", kTagSizeM},
            {"detections", std::move(values)},
            {"rejected_candidates", rejected_count},
            {"mapped_tags_used", result.mapped_tags_used},
            {"anchor_pose_valid", result.anchor_pose_valid},
            {"relocalized", result.relocalized},
            {"position_correction_m", result.position_correction_m},
            {"yaw_correction_deg", result.yaw_correction_deg},
            {"confidence", result.confidence},
        };
        observations << entry.dump() << '\n';
        observations.flush();
    }

    void append_constraint_log(const std::uint64_t pair_index,
                               const AprilTagAnchorResult& result) {
        if (!result.anchor_pose_valid) return;
        nlohmann::json ids = nlohmann::json::array();
        for (const int id : result.ids) ids.push_back(id);
        constraints << nlohmann::json{
            {"ts_unix_ms", unix_time_ms()},
            {"pair_index", pair_index},
            {"type", result.relocalized
                ? "APRILTAG_RELOCALIZATION" : "APRILTAG_ANCHOR_POSE"},
            {"tag_ids", std::move(ids)},
            {"world_from_camera", pose_json(result.world_from_camera)},
            {"mapped_tags_used", result.mapped_tags_used},
            {"position_correction_m", result.position_correction_m},
            {"yaw_correction_deg", result.yaw_correction_deg},
            {"confidence", result.confidence},
        }.dump() << '\n';
        constraints.flush();
    }

    nlohmann::json map_json_locked() const {
        nlohmann::json tags = nlohmann::json::array();
        for (const auto& [id, landmark] : landmarks) {
            const double observations_count = static_cast<double>(
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
                {"consistent_observations", landmark.consistent_observations},
                {"repeat_visits", landmark.repeat_visits},
                {"first_pair_index", landmark.first_pair_index},
                {"last_pair_index", landmark.last_pair_index},
                {"mean_reprojection_error_px",
                 landmark.reprojection_error_sum_px / observations_count},
                {"mean_translation_residual_m",
                 landmark.translation_residual_sum_m / observations_count},
                {"mean_yaw_residual_deg",
                 landmark.yaw_residual_sum_deg / observations_count},
            });
        }
        return {
            {"schema_version", 1},
            {"mode", "ONLINE_APRILTAG_ANCHOR_MAP"},
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
        std::uint64_t mapped = 0;
        std::uint64_t anchors = 0;
        for (const auto& [id, landmark] : landmarks) {
            static_cast<void>(id);
            if (landmark.state == LandmarkState::Candidate) ++candidates;
            if (landmark.state == LandmarkState::Mapped) ++mapped;
            if (landmark.state == LandmarkState::Anchor) ++anchors;
        }
        return {
            {"state", last_error.empty() ? "READY" : "ERROR"},
            {"mode", "ONLINE_APRILTAG_ANCHOR_MAP"},
            {"family", "36h11"},
            {"tag_size_m", kTagSizeM},
            {"minimum_id", kMinimumKitId},
            {"maximum_id", kMaximumKitId},
            {"frames_processed", frames_processed},
            {"frames_with_tags", frames_with_tags},
            {"detections_total", detections_total},
            {"rejected_detections", rejected_detections},
            {"candidate_tags", candidates},
            {"mapped_tags", mapped},
            {"anchor_tags", anchors},
            {"anchor_pose_frames", anchor_pose_frames},
            {"relocalizations", relocalizations},
            {"last_pair_index", last_pair_index},
            {"last_detected_ids", last_detected_ids},
            {"last_mapped_tags_used", last_mapped_tags_used},
            {"last_position_correction_m", last_position_correction_m},
            {"last_yaw_correction_deg", last_yaw_correction_deg},
            {"last_confidence", last_confidence},
            {"map_file", "apriltag_map.json"},
            {"map_ply_file", "apriltag_map.ply"},
            {"observations_file", "apriltag_observations.jsonl"},
            {"constraints_file", "apriltag_constraints.jsonl"},
            {"preview_file", "apriltag_latest.jpg"},
            {"last_error", last_error},
        };
    }

    void publish_map() const {
        std::scoped_lock lock(mutex);
        write_text_atomic(session_directory / "apriltag_map.json",
                          map_json_locked().dump(2) + "\n");
        write_text_atomic(session_directory / "apriltag_map.ply",
                          tag_map_ply(landmarks));
    }

    void write_status_file() const {
        write_text_atomic(session_directory / "apriltag_status.json",
                          status_json().dump(2) + "\n");
    }

    AprilTagAnchorResult process(
        const std::uint64_t pair_index,
        const cv::Mat& camera_a_bgr,
        const double focal_px,
        const double principal_x_px,
        const double principal_y_px,
        const std::optional<cv::Matx44d>& preliminary_world_from_camera) {
        AprilTagAnchorResult result;
        int rejected_count = 0;
        try {
            const auto detections = detect(
                camera_a_bgr, focal_px, principal_x_px,
                principal_y_px, rejected_count);
            result.detections_present = !detections.empty();
            result.detected_tags = static_cast<int>(detections.size());
            result.rejected_tags = rejected_count;
            result.ids.reserve(detections.size());
            for (const auto& detection : detections) {
                result.ids.push_back(detection.id);
            }
            std::scoped_lock lock(mutex);
            const auto candidates = known_pose_candidates(detections);
            int used_count = 0;
            double confidence = 0.0;
            const auto anchored_pose = robust_camera_pose(
                candidates, used_count, confidence);
            if (anchored_pose) {
                result.anchor_pose_valid = true;
                result.world_from_camera = *anchored_pose;
                result.mapped_tags_used = used_count;
                result.confidence = confidence;
                if (preliminary_world_from_camera) {
                    result.position_correction_m = translation_delta_m(
                        *preliminary_world_from_camera, *anchored_pose);
                    result.yaw_correction_deg = yaw_delta_deg(
                        *preliminary_world_from_camera, *anchored_pose);
                    result.relocalized =
                        result.position_correction_m >=
                            kRelocalizationPositionThresholdM ||
                        result.yaw_correction_deg >=
                            kRelocalizationYawThresholdDeg;
                } else {
                    result.relocalized = true;
                }
            }
            const std::optional<cv::Matx44d> pose_for_mapping =
                anchored_pose ? anchored_pose : preliminary_world_from_camera;
            if (pose_for_mapping) {
                update_landmarks(detections, *pose_for_mapping, pair_index);
            }
            ++frames_processed;
            if (!detections.empty()) ++frames_with_tags;
            detections_total += static_cast<std::uint64_t>(detections.size());
            rejected_detections += static_cast<std::uint64_t>(
                std::max(0, rejected_count));
            if (result.anchor_pose_valid) ++anchor_pose_frames;
            if (result.relocalized) ++relocalizations;
            last_pair_index = pair_index;
            last_detected_ids = result.ids;
            last_mapped_tags_used = result.mapped_tags_used;
            last_position_correction_m = result.position_correction_m;
            last_yaw_correction_deg = result.yaw_correction_deg;
            last_confidence = result.confidence;
            last_error.clear();
            publish_annotated_preview(camera_a_bgr, detections);
            append_observation_log(
                pair_index, detections, rejected_count, result);
            append_constraint_log(pair_index, result);
            write_text_atomic(session_directory / "apriltag_map.json",
                              map_json_locked().dump(2) + "\n");
            write_text_atomic(session_directory / "apriltag_map.ply",
                              tag_map_ply(landmarks));
        } catch (const std::exception& error) {
            std::scoped_lock lock(mutex);
            ++frames_processed;
            last_pair_index = pair_index;
            last_error = error.what();
        }
        write_status_file();
        return result;
    }

    void reset() {
        {
            std::scoped_lock lock(mutex);
            landmarks.clear();
            frames_processed = 0;
            frames_with_tags = 0;
            detections_total = 0;
            rejected_detections = 0;
            anchor_pose_frames = 0;
            relocalizations = 0;
            last_pair_index = 0;
            last_detected_ids.clear();
            last_mapped_tags_used = 0;
            last_position_correction_m = 0.0;
            last_yaw_correction_deg = 0.0;
            last_confidence = 0.0;
            last_error.clear();
        }
        for (const auto* name : {
                 "apriltag_map.json",
                 "apriltag_map.ply",
                 "apriltag_status.json",
                 "apriltag_latest.jpg",
             }) {
            std::error_code error;
            std::filesystem::remove(session_directory / name, error);
        }
        write_status_file();
    }

    nlohmann::json status_json() const {
        std::scoped_lock lock(mutex);
        return status_json_locked();
    }

    std::filesystem::path session_directory;
    mutable std::mutex mutex;
    std::ofstream observations;
    std::ofstream constraints;
    cv::aruco::Dictionary dictionary;
    cv::aruco::DetectorParameters detector_parameters;
    cv::aruco::ArucoDetector detector;
    std::map<int, Landmark> landmarks;
    std::uint64_t frames_processed = 0;
    std::uint64_t frames_with_tags = 0;
    std::uint64_t detections_total = 0;
    std::uint64_t rejected_detections = 0;
    std::uint64_t anchor_pose_frames = 0;
    std::uint64_t relocalizations = 0;
    std::uint64_t last_pair_index = 0;
    std::vector<int> last_detected_ids;
    int last_mapped_tags_used = 0;
    double last_position_correction_m = 0.0;
    double last_yaw_correction_deg = 0.0;
    double last_confidence = 0.0;
    std::string last_error;
};

AprilTagAnchorRuntime::AprilTagAnchorRuntime(
    std::filesystem::path session_directory)
    : impl_(std::make_unique<Impl>(std::move(session_directory))) {}

AprilTagAnchorRuntime::~AprilTagAnchorRuntime() = default;

AprilTagAnchorResult AprilTagAnchorRuntime::process(
    const std::uint64_t pair_index,
    const cv::Mat& camera_a_bgr,
    const double focal_px,
    const double principal_x_px,
    const double principal_y_px,
    const std::optional<cv::Matx44d>& preliminary_world_from_camera) {
    return impl_->process(
        pair_index, camera_a_bgr, focal_px, principal_x_px,
        principal_y_px, preliminary_world_from_camera);
}

void AprilTagAnchorRuntime::reset() { impl_->reset(); }

nlohmann::json AprilTagAnchorRuntime::status_json() const {
    return impl_->status_json();
}

}  // namespace maklertour::dual_phone::detail
