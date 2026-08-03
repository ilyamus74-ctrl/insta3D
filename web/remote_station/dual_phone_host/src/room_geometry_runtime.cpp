#include "room_geometry_runtime.hpp"

#include <algorithm>
#include <array>
#include <chrono>
#include <cmath>
#include <condition_variable>
#include <cstdint>
#include <fstream>
#include <iomanip>
#include <limits>
#include <mutex>
#include <optional>
#include <random>
#include <stdexcept>
#include <sstream>
#include <string>
#include <system_error>
#include <thread>
#include <unordered_map>
#include <utility>
#include <vector>

#include <opencv2/core.hpp>

namespace maklertour::dual_phone::detail {

namespace {

constexpr std::chrono::milliseconds kMinimumGeometryInterval{2000};
constexpr double kNearMeters = 0.45;
constexpr double kFarMeters = 8.0;
constexpr double kVoxelMeters = 0.04;
constexpr double kPlaneDistanceMeters = 0.06;
constexpr double kMinimumPlaneAreaM2 = 0.20;
constexpr std::size_t kMaximumPoints = 120000;
constexpr int kMaximumPlanes = 8;
constexpr int kRansacIterations = 320;

struct GeometryJob {
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

struct PointRgb {
    cv::Vec3d position;
    cv::Vec3b rgb;
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
    std::uint32_t count = 0;
};

struct PlaneModel {
    int id = 0;
    std::string type;
    cv::Vec3d normal{0.0, 0.0, 1.0};
    double d = 0.0;
    cv::Vec3d centroid{0.0, 0.0, 0.0};
    cv::Vec3d axis_u{1.0, 0.0, 0.0};
    cv::Vec3d axis_v{0.0, 1.0, 0.0};
    double min_u = 0.0;
    double max_u = 0.0;
    double min_v = 0.0;
    double max_v = 0.0;
    double area_m2 = 0.0;
    double rms_m = 0.0;
    std::vector<std::size_t> inliers;
    std::array<cv::Vec3d, 4> corners{};
};

struct EdgeModel {
    int id = 0;
    int plane_a = 0;
    int plane_b = 0;
    std::string type;
    cv::Vec3d start{0.0, 0.0, 0.0};
    cv::Vec3d end{0.0, 0.0, 0.0};
    double length_m = 0.0;
};

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

nlohmann::json vector_json(const cv::Vec3d& value) {
    return nlohmann::json::array({value[0], value[1], value[2]});
}

double norm(const cv::Vec3d& value) {
    return std::sqrt(value.dot(value));
}

cv::Vec3d normalized(const cv::Vec3d& value) {
    const double length = norm(value);
    if (!std::isfinite(length) || length < 1e-9) {
        return {0.0, 0.0, 0.0};
    }
    return value * (1.0 / length);
}

std::string classify_plane(const cv::Vec3d& normal,
                           const cv::Vec3d& centroid) {
    const double vertical_component = std::abs(normal[1]);
    if (vertical_component >= 0.75) {
        if (centroid[1] <= -0.20) return "FLOOR_CANDIDATE";
        if (centroid[1] >= 0.20) return "CEILING_CANDIDATE";
        return "HORIZONTAL";
    }
    if (vertical_component <= 0.45) return "WALL_CANDIDATE";
    return "SLANTED";
}

std::vector<PointRgb> build_point_cloud(const GeometryJob& job) {
    if (job.colour.empty() || job.disparity.empty() || job.mask.empty() ||
        job.colour.size() != job.disparity.size() ||
        job.colour.size() != job.mask.size() ||
        job.disparity.type() != CV_32F || job.mask.type() != CV_8U) {
        throw std::runtime_error("room geometry requires aligned colour, disparity and mask");
    }
    if (!std::isfinite(job.focal_px) || job.focal_px <= 1.0 ||
        !std::isfinite(job.baseline_mm) || job.baseline_mm <= 0.0) {
        throw std::runtime_error("room geometry requires finite focal and baseline");
    }

    std::unordered_map<VoxelKey, VoxelAccumulator, VoxelKeyHash> voxels;
    voxels.reserve(std::min<std::size_t>(job.disparity.total() / 3, kMaximumPoints));
    const int stride = job.disparity.total() > 1000000 ? 3 : 2;
    for (int row = 0; row < job.disparity.rows; row += stride) {
        const auto* disparity_row = job.disparity.ptr<float>(row);
        const auto* mask_row = job.mask.ptr<std::uint8_t>(row);
        const auto* colour_row = job.colour.ptr<cv::Vec3b>(row);
        for (int column = 0; column < job.disparity.cols; column += stride) {
            if (mask_row[column] == 0) continue;
            const double disparity = static_cast<double>(disparity_row[column]);
            if (!std::isfinite(disparity) || disparity <= 1.0) continue;
            const double z = job.focal_px * job.baseline_mm /
                disparity / 1000.0;
            if (!std::isfinite(z) || z < kNearMeters || z > kFarMeters) continue;
            const double x =
                (static_cast<double>(column) - job.principal_x_px) * z /
                job.focal_px;
            const double y =
                -(static_cast<double>(row) - job.principal_y_px) * z /
                job.focal_px;
            if (!std::isfinite(x) || !std::isfinite(y) ||
                std::abs(x) > 8.0 || std::abs(y) > 5.0) {
                continue;
            }
            const VoxelKey key{
                static_cast<int>(std::floor(x / kVoxelMeters)),
                static_cast<int>(std::floor(y / kVoxelMeters)),
                static_cast<int>(std::floor(z / kVoxelMeters)),
            };
            auto& voxel = voxels[key];
            voxel.position_sum += cv::Vec3d{x, y, z};
            const auto bgr = colour_row[column];
            voxel.colour_sum += cv::Vec3d{
                static_cast<double>(bgr[2]),
                static_cast<double>(bgr[1]),
                static_cast<double>(bgr[0]),
            };
            ++voxel.count;
        }
    }

    std::vector<PointRgb> points;
    points.reserve(std::min<std::size_t>(voxels.size(), kMaximumPoints));
    for (const auto& [key, voxel] : voxels) {
        static_cast<void>(key);
        if (voxel.count == 0) continue;
        const double scale = 1.0 / static_cast<double>(voxel.count);
        const auto colour = voxel.colour_sum * scale;
        points.push_back({
            voxel.position_sum * scale,
            cv::Vec3b{
                cv::saturate_cast<std::uint8_t>(colour[0]),
                cv::saturate_cast<std::uint8_t>(colour[1]),
                cv::saturate_cast<std::uint8_t>(colour[2]),
            },
        });
        if (points.size() >= kMaximumPoints) break;
    }
    return points;
}

std::optional<std::pair<cv::Vec3d, double>> plane_from_three(
    const cv::Vec3d& a,
    const cv::Vec3d& b,
    const cv::Vec3d& c) {
    auto normal = normalized((b - a).cross(c - a));
    if (norm(normal) < 0.5) return std::nullopt;
    double d = -normal.dot(a);
    if (d > 0.0) {
        normal *= -1.0;
        d *= -1.0;
    }
    return std::pair<cv::Vec3d, double>{normal, d};
}

PlaneModel refine_plane(const int id,
                        const std::vector<PointRgb>& points,
                        const std::vector<std::size_t>& candidate_indices,
                        const std::vector<std::size_t>& remaining) {
    cv::Mat data(
        static_cast<int>(candidate_indices.size()),
        3,
        CV_64F);
    for (std::size_t row = 0; row < candidate_indices.size(); ++row) {
        const auto& point = points[candidate_indices[row]].position;
        data.at<double>(static_cast<int>(row), 0) = point[0];
        data.at<double>(static_cast<int>(row), 1) = point[1];
        data.at<double>(static_cast<int>(row), 2) = point[2];
    }
    const cv::PCA pca(data, cv::Mat(), cv::PCA::DATA_AS_ROW);
    PlaneModel plane;
    plane.id = id;
    plane.centroid = {
        pca.mean.at<double>(0, 0),
        pca.mean.at<double>(0, 1),
        pca.mean.at<double>(0, 2),
    };
    plane.normal = normalized({
        pca.eigenvectors.at<double>(2, 0),
        pca.eigenvectors.at<double>(2, 1),
        pca.eigenvectors.at<double>(2, 2),
    });
    plane.d = -plane.normal.dot(plane.centroid);
    if (plane.d > 0.0) {
        plane.normal *= -1.0;
        plane.d *= -1.0;
    }
    plane.inliers.reserve(candidate_indices.size());
    double squared_error = 0.0;
    for (const auto index : remaining) {
        const double distance = std::abs(
            plane.normal.dot(points[index].position) + plane.d);
        if (distance <= kPlaneDistanceMeters) {
            plane.inliers.push_back(index);
            squared_error += distance * distance;
        }
    }
    if (!plane.inliers.empty()) {
        plane.rms_m = std::sqrt(
            squared_error / static_cast<double>(plane.inliers.size()));
    }

    const cv::Vec3d reference = std::abs(plane.normal[1]) > 0.85
        ? cv::Vec3d{1.0, 0.0, 0.0}
        : cv::Vec3d{0.0, 1.0, 0.0};
    plane.axis_u = normalized(plane.normal.cross(reference));
    plane.axis_v = normalized(plane.normal.cross(plane.axis_u));
    plane.min_u = std::numeric_limits<double>::infinity();
    plane.max_u = -std::numeric_limits<double>::infinity();
    plane.min_v = std::numeric_limits<double>::infinity();
    plane.max_v = -std::numeric_limits<double>::infinity();
    for (const auto index : plane.inliers) {
        const auto relative = points[index].position - plane.centroid;
        const double u = relative.dot(plane.axis_u);
        const double v = relative.dot(plane.axis_v);
        plane.min_u = std::min(plane.min_u, u);
        plane.max_u = std::max(plane.max_u, u);
        plane.min_v = std::min(plane.min_v, v);
        plane.max_v = std::max(plane.max_v, v);
    }
    plane.area_m2 = std::max(0.0, plane.max_u - plane.min_u) *
                    std::max(0.0, plane.max_v - plane.min_v);
    plane.type = classify_plane(plane.normal, plane.centroid);
    plane.corners = {
        plane.centroid + plane.axis_u * plane.min_u + plane.axis_v * plane.min_v,
        plane.centroid + plane.axis_u * plane.max_u + plane.axis_v * plane.min_v,
        plane.centroid + plane.axis_u * plane.max_u + plane.axis_v * plane.max_v,
        plane.centroid + plane.axis_u * plane.min_u + plane.axis_v * plane.max_v,
    };
    return plane;
}

std::vector<PlaneModel> extract_planes(const std::vector<PointRgb>& points,
                                       const std::uint64_t seed) {
    std::vector<PlaneModel> planes;
    std::vector<std::size_t> remaining(points.size());
    for (std::size_t index = 0; index < points.size(); ++index) {
        remaining[index] = index;
    }
    const std::size_t initial_minimum = std::max<std::size_t>(
        140,
        static_cast<std::size_t>(
            std::ceil(static_cast<double>(points.size()) * 0.025)));
    std::mt19937_64 random(seed ^ 0x4d4b4c52544f5552ULL);

    for (int plane_id = 0;
         plane_id < kMaximumPlanes && remaining.size() >= initial_minimum;
         ++plane_id) {
        std::uniform_int_distribution<std::size_t> distribution(
            0,
            remaining.size() - 1);
        std::vector<std::size_t> best;
        for (int iteration = 0; iteration < kRansacIterations; ++iteration) {
            const auto a_position = distribution(random);
            auto b_position = distribution(random);
            while (b_position == a_position) b_position = distribution(random);
            auto c_position = distribution(random);
            while (c_position == a_position || c_position == b_position) {
                c_position = distribution(random);
            }
            const auto candidate = plane_from_three(
                points[remaining[a_position]].position,
                points[remaining[b_position]].position,
                points[remaining[c_position]].position);
            if (!candidate) continue;
            std::vector<std::size_t> inliers;
            inliers.reserve(remaining.size() / 3);
            for (const auto index : remaining) {
                const double distance = std::abs(
                    candidate->first.dot(points[index].position) +
                    candidate->second);
                if (distance <= kPlaneDistanceMeters) inliers.push_back(index);
            }
            if (inliers.size() > best.size()) best = std::move(inliers);
        }
        if (best.size() < initial_minimum) break;
        auto plane = refine_plane(plane_id, points, best, remaining);
        if (plane.inliers.size() < initial_minimum ||
            plane.area_m2 < kMinimumPlaneAreaM2) {
            break;
        }
        std::vector<std::uint8_t> remove(points.size(), 0);
        for (const auto index : plane.inliers) remove[index] = 1;
        std::vector<std::size_t> next;
        next.reserve(remaining.size() - plane.inliers.size());
        for (const auto index : remaining) {
            if (remove[index] == 0) next.push_back(index);
        }
        remaining = std::move(next);
        planes.push_back(std::move(plane));
    }
    return planes;
}

std::string edge_type(const PlaneModel& a, const PlaneModel& b) {
    const auto contains = [](const std::string& value, const std::string& token) {
        return value.find(token) != std::string::npos;
    };
    if ((contains(a.type, "FLOOR") && contains(b.type, "WALL")) ||
        (contains(b.type, "FLOOR") && contains(a.type, "WALL"))) {
        return "FLOOR_WALL";
    }
    if ((contains(a.type, "CEILING") && contains(b.type, "WALL")) ||
        (contains(b.type, "CEILING") && contains(a.type, "WALL"))) {
        return "CEILING_WALL";
    }
    if (contains(a.type, "WALL") && contains(b.type, "WALL")) {
        return "WALL_CORNER";
    }
    return "PLANE_INTERSECTION";
}

std::vector<EdgeModel> extract_edges(const std::vector<PointRgb>& points,
                                     const std::vector<PlaneModel>& planes) {
    std::vector<EdgeModel> edges;
    for (std::size_t first = 0; first < planes.size(); ++first) {
        for (std::size_t second = first + 1; second < planes.size(); ++second) {
            const auto& a = planes[first];
            const auto& b = planes[second];
            const auto raw_direction = a.normal.cross(b.normal);
            const double squared_length = raw_direction.dot(raw_direction);
            if (squared_length < 0.06) continue;
            const cv::Vec3d point =
                ((b.d * a.normal - a.d * b.normal).cross(raw_direction)) /
                squared_length;
            const auto direction = normalized(raw_direction);

            const auto interval = [&points, &direction](const PlaneModel& plane) {
                double minimum = std::numeric_limits<double>::infinity();
                double maximum = -std::numeric_limits<double>::infinity();
                for (const auto index : plane.inliers) {
                    const double value = points[index].position.dot(direction);
                    minimum = std::min(minimum, value);
                    maximum = std::max(maximum, value);
                }
                return std::pair<double, double>{minimum, maximum};
            };
            const auto interval_a = interval(a);
            const auto interval_b = interval(b);
            const double minimum = std::max(interval_a.first, interval_b.first);
            const double maximum = std::min(interval_a.second, interval_b.second);
            if (!std::isfinite(minimum) || !std::isfinite(maximum) ||
                maximum - minimum < 0.25 || maximum - minimum > 12.0) {
                continue;
            }
            EdgeModel edge;
            edge.id = static_cast<int>(edges.size());
            edge.plane_a = a.id;
            edge.plane_b = b.id;
            edge.type = edge_type(a, b);
            edge.start = point + direction * minimum;
            edge.end = point + direction * maximum;
            edge.length_m = norm(edge.end - edge.start);
            if (edge.start[2] < 0.1 && edge.end[2] < 0.1) continue;
            edges.push_back(std::move(edge));
        }
    }
    return edges;
}

nlohmann::json plane_json(const PlaneModel& plane) {
    nlohmann::json corners = nlohmann::json::array();
    for (const auto& corner : plane.corners) corners.push_back(vector_json(corner));
    return {
        {"id", plane.id},
        {"type", plane.type},
        {"normal", vector_json(plane.normal)},
        {"d_m", plane.d},
        {"centroid_m", vector_json(plane.centroid)},
        {"area_m2", plane.area_m2},
        {"rms_m", plane.rms_m},
        {"inlier_count", plane.inliers.size()},
        {"corners_m", std::move(corners)},
    };
}

nlohmann::json edge_json(const EdgeModel& edge) {
    return {
        {"id", edge.id},
        {"type", edge.type},
        {"plane_a", edge.plane_a},
        {"plane_b", edge.plane_b},
        {"start_m", vector_json(edge.start)},
        {"end_m", vector_json(edge.end)},
        {"length_m", edge.length_m},
    };
}

std::string point_cloud_ply(const std::vector<PointRgb>& points,
                            const std::vector<PlaneModel>& planes) {
    std::vector<int> plane_ids(points.size(), -1);
    for (const auto& plane : planes) {
        for (const auto index : plane.inliers) {
            if (index < plane_ids.size()) plane_ids[index] = plane.id;
        }
    }
    std::ostringstream output;
    output << "ply\nformat ascii 1.0\n"
           << "comment MaklerTour LM02.7B.5 metric point cloud\n"
           << "comment coordinate_system X_right Y_up Z_forward meters\n"
           << "element vertex " << points.size() << "\n"
           << "property float x\nproperty float y\nproperty float z\n"
           << "property uchar red\nproperty uchar green\nproperty uchar blue\n"
           << "property int plane_id\nend_header\n";
    output << std::fixed << std::setprecision(6);
    for (std::size_t index = 0; index < points.size(); ++index) {
        const auto& point = points[index];
        output << point.position[0] << ' ' << point.position[1] << ' '
               << point.position[2] << ' '
               << static_cast<int>(point.rgb[0]) << ' '
               << static_cast<int>(point.rgb[1]) << ' '
               << static_cast<int>(point.rgb[2]) << ' '
               << plane_ids[index] << '\n';
    }
    return output.str();
}

cv::Vec3b skeleton_colour(const std::string& type) {
    if (type.find("FLOOR") != std::string::npos) return {80, 220, 80};
    if (type.find("CEILING") != std::string::npos) return {100, 180, 255};
    if (type.find("WALL") != std::string::npos) return {255, 180, 50};
    return {230, 90, 230};
}

std::string skeleton_ply(const std::vector<PlaneModel>& planes,
                         const std::vector<EdgeModel>& intersections) {
    struct SkeletonVertex {
        cv::Vec3d position;
        cv::Vec3b rgb;
    };
    std::vector<SkeletonVertex> vertices;
    std::vector<std::pair<int, int>> edges;
    for (const auto& plane : planes) {
        const int base = static_cast<int>(vertices.size());
        const auto colour = skeleton_colour(plane.type);
        for (const auto& corner : plane.corners) {
            vertices.push_back({corner, colour});
        }
        for (int index = 0; index < 4; ++index) {
            edges.emplace_back(base + index, base + ((index + 1) % 4));
        }
    }
    for (const auto& edge : intersections) {
        const int base = static_cast<int>(vertices.size());
        vertices.push_back({edge.start, {255, 255, 255}});
        vertices.push_back({edge.end, {255, 255, 255}});
        edges.emplace_back(base, base + 1);
    }

    std::ostringstream output;
    output << "ply\nformat ascii 1.0\n"
           << "comment MaklerTour LM02.7B.5 room plane skeleton\n"
           << "comment coordinate_system X_right Y_up Z_forward meters\n"
           << "element vertex " << vertices.size() << "\n"
           << "property float x\nproperty float y\nproperty float z\n"
           << "property uchar red\nproperty uchar green\nproperty uchar blue\n"
           << "element edge " << edges.size() << "\n"
           << "property int vertex1\nproperty int vertex2\nend_header\n";
    output << std::fixed << std::setprecision(6);
    for (const auto& vertex : vertices) {
        output << vertex.position[0] << ' ' << vertex.position[1] << ' '
               << vertex.position[2] << ' '
               << static_cast<int>(vertex.rgb[0]) << ' '
               << static_cast<int>(vertex.rgb[1]) << ' '
               << static_cast<int>(vertex.rgb[2]) << '\n';
    }
    for (const auto& edge : edges) {
        output << edge.first << ' ' << edge.second << '\n';
    }
    return output.str();
}

}  // namespace

struct RoomGeometryRuntime::Impl {
    explicit Impl(std::filesystem::path session_path)
        : session_directory(std::move(session_path)),
          diagnostics(session_directory / "room_geometry.jsonl", std::ios::app) {
        if (!diagnostics) {
            throw std::runtime_error("cannot create room geometry diagnostics");
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
        write_status_file();
    }

    bool submit(const std::uint64_t new_pair_index,
                std::string new_source_profile,
                const StereoDepthResult& depth) {
        if (depth.geometry_disparity.empty() || depth.geometry_mask.empty() ||
            depth.work_a.empty()) {
            return false;
        }
        const auto now = std::chrono::steady_clock::now();
        GeometryJob job;
        {
            std::scoped_lock lock(mutex);
            ++submitted_frames;
            if (pending) {
                ++rejected_busy_frames;
                return false;
            }
            if (last_accepted_submit.time_since_epoch().count() != 0 &&
                now - last_accepted_submit < kMinimumGeometryInterval) {
                ++rejected_interval_frames;
                return false;
            }
            last_accepted_submit = now;
            ++accepted_frames;
            job.generation = generation;
            job.pair_index = new_pair_index;
            job.source_profile = std::move(new_source_profile);
            // Clone only after backpressure accepts this job.
            job.colour = depth.work_a.clone();
            job.disparity = depth.geometry_disparity.clone();
            job.mask = depth.geometry_mask.clone();
            job.focal_px = depth.focal_px;
            job.baseline_mm = depth.baseline_mm;
            job.principal_x_px = depth.principal_x_px;
            job.principal_y_px = depth.principal_y_px;
            pending = std::move(job);
        }
        condition.notify_one();
        return true;
    }

    void reset() {
        std::scoped_lock lock(mutex);
        ++generation;
        pending.reset();
        last_accepted_submit = {};
        ready = false;
        source_profile = "WAITING";
        pair_index = 0;
        input_points = 0;
        voxel_points = 0;
        plane_count = 0;
        edge_count = 0;
        processing_ms = 0.0;
        last_error.clear();
    }

    nlohmann::json status_json() const {
        std::scoped_lock lock(mutex);
        return status_json_locked();
    }

    nlohmann::json status_json_locked() const {
        return {
            {"state", ready ? "READY" : (last_error.empty() ? "WAITING" : "ERROR")},
            {"ready", ready},
            {"recommended_profile", "HIGH_640"},
            {"source_profile", source_profile},
            {"pair_index", pair_index},
            {"input_points", input_points},
            {"voxel_points", voxel_points},
            {"plane_count", plane_count},
            {"edge_count", edge_count},
            {"processing_ms", processing_ms},
            {"submitted_frames", submitted_frames},
            {"accepted_frames", accepted_frames},
            {"processed_frames", processed_frames},
            {"failed_frames", failed_frames},
            {"rejected_busy_frames", rejected_busy_frames},
            {"rejected_interval_frames", rejected_interval_frames},
            {"dropped_pending_frames", dropped_pending_frames},
            {"minimum_interval_ms", kMinimumGeometryInterval.count()},
            {"generation", generation},
            {"coordinate_system", "X_right_Y_up_Z_forward_meters"},
            {"point_cloud_file", "point_cloud_latest.ply"},
            {"skeleton_file", "room_skeleton_latest.ply"},
            {"planes_file", "room_planes_latest.json"},
            {"edges_file", "room_edges_latest.json"},
            {"last_error", last_error},
        };
    }

    void append_diagnostic(nlohmann::json value) {
        value["ts_unix_ms"] = std::chrono::duration_cast<std::chrono::milliseconds>(
            std::chrono::system_clock::now().time_since_epoch()).count();
        diagnostics << value.dump() << '\n';
        diagnostics.flush();
    }

    void write_status_file() const noexcept {
        try {
            write_text_atomic(
                session_directory / "room_geometry_status.json",
                status_json().dump(2) + "\n");
        } catch (...) {
            // Status persistence must never terminate the host or its worker.
        }
    }

    void worker_loop() {
        auto last_started = std::chrono::steady_clock::time_point{};
        while (true) {
            GeometryJob job;
            {
                std::unique_lock lock(mutex);
                condition.wait(lock, [this] {
                    return stopping || pending.has_value();
                });
                if (stopping) break;
                if (last_started.time_since_epoch().count() != 0) {
                    const auto due = last_started + kMinimumGeometryInterval;
                    condition.wait_until(lock, due, [this] { return stopping; });
                    if (stopping) break;
                }
                if (!pending) continue;
                job = std::move(*pending);
                pending.reset();
            }
            last_started = std::chrono::steady_clock::now();
            const auto started = last_started;
            try {
                const auto points = build_point_cloud(job);
                const auto planes = extract_planes(points, job.pair_index);
                const auto edges = extract_edges(points, planes);

                nlohmann::json plane_values = nlohmann::json::array();
                for (const auto& plane : planes) {
                    plane_values.push_back(plane_json(plane));
                }
                nlohmann::json edge_values = nlohmann::json::array();
                for (const auto& edge : edges) {
                    edge_values.push_back(edge_json(edge));
                }
                const nlohmann::json common = {
                    {"schema_version", 1},
                    {"pair_index", job.pair_index},
                    {"source_profile", job.source_profile},
                    {"coordinate_system", "X_right_Y_up_Z_forward_meters"},
                    {"focal_px", job.focal_px},
                    {"baseline_mm", job.baseline_mm},
                    {"principal_x_px", job.principal_x_px},
                    {"principal_y_px", job.principal_y_px},
                    {"voxel_size_m", kVoxelMeters},
                    {"plane_distance_threshold_m", kPlaneDistanceMeters},
                };
                auto planes_document = common;
                planes_document["point_count"] = points.size();
                planes_document["planes"] = std::move(plane_values);
                auto edges_document = common;
                edges_document["edges"] = std::move(edge_values);

                write_text_atomic(
                    session_directory / "point_cloud_latest.ply",
                    point_cloud_ply(points, planes));
                write_text_atomic(
                    session_directory / "room_skeleton_latest.ply",
                    skeleton_ply(planes, edges));
                write_text_atomic(
                    session_directory / "room_planes_latest.json",
                    planes_document.dump(2) + "\n");
                write_text_atomic(
                    session_directory / "room_edges_latest.json",
                    edges_document.dump(2) + "\n");

                const double duration_ms =
                    std::chrono::duration<double, std::milli>(
                        std::chrono::steady_clock::now() - started).count();
                nlohmann::json diagnostic;
                {
                    std::scoped_lock lock(mutex);
                    if (job.generation != generation) continue;
                    ready = true;
                    source_profile = job.source_profile;
                    pair_index = job.pair_index;
                    input_points = static_cast<std::uint64_t>(job.disparity.total());
                    voxel_points = static_cast<std::uint64_t>(points.size());
                    plane_count = static_cast<int>(planes.size());
                    edge_count = static_cast<int>(edges.size());
                    processing_ms = duration_ms;
                    last_error.clear();
                    ++processed_frames;
                    diagnostic = {
                        {"event", "ROOM_GEOMETRY_READY"},
                        {"pair_index", pair_index},
                        {"source_profile", source_profile},
                        {"voxel_points", voxel_points},
                        {"plane_count", plane_count},
                        {"edge_count", edge_count},
                        {"processing_ms", processing_ms},
                    };
                }
                append_diagnostic(std::move(diagnostic));
                write_status_file();
            } catch (const std::exception& error) {
                nlohmann::json diagnostic;
                {
                    std::scoped_lock lock(mutex);
                    if (job.generation != generation) continue;
                    ready = false;
                    source_profile = job.source_profile;
                    pair_index = job.pair_index;
                    processing_ms = std::chrono::duration<double, std::milli>(
                        std::chrono::steady_clock::now() - started).count();
                    last_error = error.what();
                    ++failed_frames;
                    diagnostic = {
                        {"event", "ROOM_GEOMETRY_FAILED"},
                        {"pair_index", pair_index},
                        {"source_profile", source_profile},
                        {"processing_ms", processing_ms},
                        {"error", last_error},
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
    std::optional<GeometryJob> pending;
    std::ofstream diagnostics;
    std::thread worker;
    std::uint64_t generation = 1;
    bool ready = false;
    std::string source_profile = "WAITING";
    std::uint64_t pair_index = 0;
    std::uint64_t input_points = 0;
    std::uint64_t voxel_points = 0;
    int plane_count = 0;
    int edge_count = 0;
    double processing_ms = 0.0;
    std::string last_error;
    std::uint64_t submitted_frames = 0;
    std::uint64_t accepted_frames = 0;
    std::uint64_t processed_frames = 0;
    std::uint64_t failed_frames = 0;
    std::uint64_t rejected_busy_frames = 0;
    std::uint64_t rejected_interval_frames = 0;
    std::uint64_t dropped_pending_frames = 0;
    std::chrono::steady_clock::time_point last_accepted_submit;
};

RoomGeometryRuntime::RoomGeometryRuntime(
    std::filesystem::path session_directory)
    : impl_(std::make_unique<Impl>(std::move(session_directory))) {}

RoomGeometryRuntime::~RoomGeometryRuntime() = default;

bool RoomGeometryRuntime::submit(
    const std::uint64_t new_pair_index,
    std::string new_source_profile,
    const StereoDepthResult& depth) {
    return impl_->submit(
        new_pair_index,
        std::move(new_source_profile),
        depth);
}

void RoomGeometryRuntime::reset() {
    impl_->reset();
}

nlohmann::json RoomGeometryRuntime::status_json() const {
    return impl_->status_json();
}

}  // namespace maklertour::dual_phone::detail
