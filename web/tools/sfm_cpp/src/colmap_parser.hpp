#pragma once

#include <string>

#include <nlohmann/json.hpp>

namespace sfm {

nlohmann::json parse_colmap_images_txt(const std::string& images_txt_path);

}
