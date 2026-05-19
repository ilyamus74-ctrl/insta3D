#pragma once

#include <string>

#include <nlohmann/json.hpp>

namespace sfm {

nlohmann::json detect_apriltags_in_frames(const std::string& frames_dir,
                                          const std::string& camera_profile_path,
                                          double marker_size_m,
                                          const std::string& family);

}
