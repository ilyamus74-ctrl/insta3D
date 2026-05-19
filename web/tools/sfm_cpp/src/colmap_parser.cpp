#include "colmap_parser.hpp"

#include <cmath>
#include <fstream>
#include <sstream>
#include <stdexcept>

namespace sfm {

nlohmann::json parse_colmap_images_txt(const std::string& images_txt_path) {
    std::ifstream in(images_txt_path);
    if (!in.is_open()) throw std::runtime_error("Failed to open images.txt");

    nlohmann::json poses = nlohmann::json::array();
    std::string line;
    while (std::getline(in, line)) {
        if (line.empty() || line[0] == '#') continue;
        std::istringstream iss(line);
        int image_id, camera_id;
        double qw, qx, qy, qz, tx, ty, tz;
        std::string image_name;
        if (!(iss >> image_id >> qw >> qx >> qy >> qz >> tx >> ty >> tz >> camera_id >> image_name)) continue;

        // quaternion to rotation matrix (w,x,y,z)
        double r00 = 1 - 2 * (qy * qy + qz * qz);
        double r01 = 2 * (qx * qy - qz * qw);
        double r02 = 2 * (qx * qz + qy * qw);
        double r10 = 2 * (qx * qy + qz * qw);
        double r11 = 1 - 2 * (qx * qx + qz * qz);
        double r12 = 2 * (qy * qz - qx * qw);
        double r20 = 2 * (qx * qz - qy * qw);
        double r21 = 2 * (qy * qz + qx * qw);
        double r22 = 1 - 2 * (qx * qx + qy * qy);

        // C = -R^T * t
        double cx = -(r00 * tx + r10 * ty + r20 * tz);
        double cy = -(r01 * tx + r11 * ty + r21 * tz);
        double cz = -(r02 * tx + r12 * ty + r22 * tz);

        poses.push_back({
            {"image_id", image_id},
            {"camera_id", camera_id},
            {"image_name", image_name},
            {"qvec", {qw, qx, qy, qz}},
            {"tvec", {tx, ty, tz}},
            {"center", {cx, cy, cz}}
        });

        std::getline(in, line); // skip POINTS2D line
    }

    return {
        {"ok", true},
        {"count", poses.size()},
        {"poses", poses}
    };
}

}
