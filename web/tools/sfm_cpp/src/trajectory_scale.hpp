Новый
+10-0
#pragma once

#include <string>
#include <nlohmann/json.hpp>

namespace sfm {

nlohmann::json compute_rough_scale(const std::string& poses_path, const std::string& markers_path);

}
