#include "trajectory_scale.hpp"

#include "common.hpp"

#include <algorithm>
#include <cmath>
#include <map>

namespace sfm {

nlohmann::json compute_rough_scale(const std::string& poses_path, const std::string& markers_path) {
    auto poses_j = read_json_file(poses_path);
    auto markers_j = read_json_file(markers_path);

    std::map<std::string, std::array<double, 3>> centers;
    for (const auto& p : poses_j.at("poses")) {
        auto c = p.at("center");
        centers[p.at("image_name").get<std::string>()] = {c[0].get<double>(), c[1].get<double>(), c[2].get<double>()};
    }

    std::map<int, std::vector<nlohmann::json>> by_marker;
    for (const auto& m : markers_j.at("observations")) {
        if (!m.contains("distance_m")) continue;
        by_marker[m.at("marker_id").get<int>()].push_back(m);
    }

    std::vector<double> scales;
    nlohmann::json samples = nlohmann::json::array();
    for (auto& kv : by_marker) {
        auto& obs = kv.second;
        std::sort(obs.begin(), obs.end(), [](const auto& a, const auto& b) { return a.value("frame_index", -1) < b.value("frame_index", -1); });
        for (size_t i = 1; i < obs.size(); ++i) {
            const auto& a = obs[i - 1];
            const auto& b = obs[i];
            double da = a.at("distance_m").get<double>();
            double db = b.at("distance_m").get<double>();
            if (da <= 0 || db <= 0) continue;
            double d_marker = std::abs(db - da);
            if (d_marker < 0.05) continue;

            std::string ia = a.at("image_name").get<std::string>();
            std::string ib = b.at("image_name").get<std::string>();
            if (!centers.count(ia) || !centers.count(ib)) continue;
            auto ca = centers[ia];
            auto cb = centers[ib];
            double dx = cb[0] - ca[0], dy = cb[1] - ca[1], dz = cb[2] - ca[2];
            double d_colmap = std::sqrt(dx * dx + dy * dy + dz * dz);
            if (d_colmap < 0.05) continue;
            double s = d_marker / d_colmap;
            if (!(s > 0.01 && s < 100.0)) continue;

            scales.push_back(s);
            samples.push_back({{"marker_id", kv.first}, {"image_a", ia}, {"image_b", ib}, {"d_marker_m", d_marker}, {"d_colmap", d_colmap}, {"scale", s}});
        }
    }

    if (scales.empty()) return {{"ok", false}, {"error", "No valid scale samples"}};

    std::sort(scales.begin(), scales.end());
    double median = scales[scales.size() / 2];

    nlohmann::json traj = nlohmann::json::array();
    for (const auto& p : poses_j.at("poses")) {
        auto c = p.at("center");
        double x = c[0].get<double>(), y = c[1].get<double>(), z = c[2].get<double>();
        traj.push_back({{"image_name", p.at("image_name")}, {"x", x}, {"y", y}, {"z", z}, {"x_scaled", x * median}, {"y_scaled", y * median}, {"z_scaled", z * median}});
    }

    return {{"ok", true}, {"scale_factor", median}, {"samples_count", samples.size()}, {"samples", samples}, {"trajectory_scaled", traj}};
}

}
