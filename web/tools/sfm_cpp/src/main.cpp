#include "apriltag_frames.hpp"
#include "colmap_parser.hpp"
#include "common.hpp"
#include "trajectory_scale.hpp"

#include <iostream>
#include <map>
#include <string>

using sfm::write_json_file;

static std::map<std::string, std::string> parse_kv(int argc, char** argv, int start = 2) {
    std::map<std::string, std::string> m;
    for (int i = start; i < argc; i += 2) {
        if (i + 1 >= argc) throw std::runtime_error("Missing value for argument: " + std::string(argv[i]));
        m[argv[i]] = argv[i + 1];
    }
    return m;
}

int main(int argc, char** argv) {
    if (argc < 2) {
        std::cerr << "Usage: sfm_tool <subcommand> [args]" << std::endl;
        return 2;
    }

    try {
        std::string cmd = argv[1];
        if (cmd == "parse-colmap-images") {
            auto args = parse_kv(argc, argv);
            auto result = sfm::parse_colmap_images_txt(args.at("--images"));
            write_json_file(args.at("--out"), result);
            return result.value("ok", false) ? 0 : 1;
        }
        if (cmd == "detect-apriltag-frames") {
            auto args = parse_kv(argc, argv);
            double marker_size = std::stod(args.at("--marker-size-m"));
            auto result = sfm::detect_apriltags_in_frames(args.at("--frames"), args.at("--camera-profile"), marker_size, args.at("--family"));
            write_json_file(args.at("--out"), result);
            return result.value("ok", false) ? 0 : 1;
        }
        if (cmd == "rough-scale") {
            auto args = parse_kv(argc, argv);
            auto result = sfm::compute_rough_scale(args.at("--poses"), args.at("--markers"));
            write_json_file(args.at("--out"), result);
            return result.value("ok", false) ? 0 : 1;
        }

        std::cerr << "Unknown subcommand: " << cmd << std::endl;
        return 2;
    } catch (const std::exception& e) {
        std::cerr << e.what() << std::endl;
        return 1;
    }
}
