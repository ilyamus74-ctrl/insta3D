#include "camera_profile.hpp"

#include "common.hpp"

namespace sfm {

CameraProfile load_camera_profile(const std::string& path) {
    auto j = read_json_file(path);
    CameraProfile cp;
    cp.name = j.value("name", "");
    cp.image_width = j.value("image_width", 0);
    cp.image_height = j.value("image_height", 0);
    cp.camera_model = j.value("camera_model", "");
    cp.fx = j.value("fx", 0.0);
    cp.fy = j.value("fy", 0.0);
    cp.cx = j.value("cx", 0.0);
    cp.cy = j.value("cy", 0.0);
    cp.dist = j.value("dist", std::vector<double>{0, 0, 0, 0, 0});
    if (cp.dist.size() < 5) cp.dist.resize(5, 0.0);
    return cp;
}

}  // namespace sfm
