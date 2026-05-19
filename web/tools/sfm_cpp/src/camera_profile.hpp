#pragma once

#include <string>
#include <vector>

namespace sfm {

struct CameraProfile {
    std::string name;
    int image_width = 0;
    int image_height = 0;
    std::string camera_model;
    double fx = 0.0;
    double fy = 0.0;
    double cx = 0.0;
    double cy = 0.0;
    std::vector<double> dist;
};

CameraProfile load_camera_profile(const std::string& path);

}  // namespace sfm
