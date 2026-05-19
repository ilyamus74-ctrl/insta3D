#pragma once

#include <filesystem>
#include <fstream>
#include <stdexcept>
#include <string>

#include <nlohmann/json.hpp>

namespace sfm {

inline void ensure_parent_dir(const std::string& file_path) {
    std::filesystem::path p(file_path);
    auto parent = p.parent_path();
    if (!parent.empty()) {
        std::filesystem::create_directories(parent);
    }
}

inline void write_json_file(const std::string& out_path, const nlohmann::json& j) {
    ensure_parent_dir(out_path);
    std::ofstream out(out_path);
    if (!out.is_open()) {
        throw std::runtime_error("Failed to open output file: " + out_path);
    }
    out << j.dump(2) << '\n';
}

inline nlohmann::json read_json_file(const std::string& in_path) {
    std::ifstream in(in_path);
    if (!in.is_open()) {
        throw std::runtime_error("Failed to open input file: " + in_path);
    }
    return nlohmann::json::parse(in);
}

}  // namespace sfm
