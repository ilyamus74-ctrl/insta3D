#include <apriltag.h>
#include <tag36h11.h>

#include <opencv2/imgcodecs.hpp>
#include <opencv2/imgproc.hpp>

#include <fstream>
#include <iostream>
#include <set>
#include <string>
#include <vector>

#include <nlohmann/json.hpp>

using json = nlohmann::json;

struct CliOptions {
    std::string inputList;
    std::string output;
    std::string tagFamily = "tag36h11";
    int validMin = 1;
    int validMax = 30;
    double markerSize = 0.160;
};

bool parseValidIds(const std::string &value, int &minId, int &maxId) {
    auto pos = value.find('-');
    if (pos == std::string::npos) return false;
    try {
        minId = std::stoi(value.substr(0, pos));
        maxId = std::stoi(value.substr(pos + 1));
    } catch (...) { return false; }
    return minId <= maxId;
}

bool parseArgs(int argc, char **argv, CliOptions &opts, std::string &err) {
    for (int i = 1; i < argc; ++i) {
        std::string a = argv[i];
        auto needValue = [&](const char *name, std::string &out) {
            if (i + 1 >= argc) { err = std::string("Missing value for ") + name; return false; }
            out = argv[++i]; return true;
        };
        if (a == "--input-list") { if (!needValue("--input-list", opts.inputList)) return false; }
        else if (a == "--output") { if (!needValue("--output", opts.output)) return false; }
        else if (a == "--tag-family") { if (!needValue("--tag-family", opts.tagFamily)) return false; }
        else if (a == "--valid-ids") { std::string v; if (!needValue("--valid-ids", v)) return false; if (!parseValidIds(v, opts.validMin, opts.validMax)) { err = "Invalid --valid-ids"; return false; } }
        else if (a == "--marker-size-m") { std::string v; if (!needValue("--marker-size-m", v)) return false; try { opts.markerSize = std::stod(v); } catch (...) { err = "Invalid --marker-size-m"; return false; } }
        else { err = "Unknown argument: " + a; return false; }
    }
    if (opts.inputList.empty() || opts.output.empty()) { err = "--input-list and --output are required"; return false; }
    if (opts.tagFamily != "tag36h11") { err = "Only tag36h11 is supported"; return false; }
    return true;
}

int main(int argc, char **argv) {
    CliOptions opts;
    std::string argErr;
    if (!parseArgs(argc, argv, opts, argErr)) {
        std::cerr << argErr << std::endl;
        return 2;
    }

    json out;
    out["ok"] = true;
    out["detector"] = "apriltag-cpp";
    out["tag_family"] = opts.tagFamily;
    out["marker_size_m"] = opts.markerSize;
    out["detections"] = json::array();
    out["errors"] = json::array();

    try {
        std::ifstream in(opts.inputList);
        if (!in.is_open()) throw std::runtime_error("Failed to open input-list");
        json input = json::parse(in);

        apriltag_family_t *tf = tag36h11_create();
        apriltag_detector_t *td = apriltag_detector_create();
        apriltag_detector_add_family(td, tf);
        td->quad_decimate = 1.0;
        td->quad_sigma = 0.0;
        td->nthreads = 1;
        td->decode_sharpening = 0.25;

        if (!input.contains("items") || !input["items"].is_array()) {
            throw std::runtime_error("Input JSON missing items[]");
        }

        for (const auto &item : input["items"]) {
            std::string absPath = item.value("absolute_path", "");
            if (absPath.empty()) {
                out["errors"].push_back({{"source_id", item.value("source_id", 0)}, {"error", "absolute_path is empty"}});
                continue;
            }

            cv::Mat img = cv::imread(absPath, cv::IMREAD_COLOR);
            if (img.empty()) {
                out["errors"].push_back({{"source_id", item.value("source_id", 0)}, {"absolute_path", absPath}, {"error", "Failed to read image"}});
                continue;
            }

            cv::Mat gray;
            cv::cvtColor(img, gray, cv::COLOR_BGR2GRAY);

            image_u8_t im{.width = gray.cols, .height = gray.rows, .stride = gray.cols, .buf = gray.data};
            zarray_t *detections = apriltag_detector_detect(td, &im);
            for (int i = 0; i < zarray_size(detections); i++) {
                apriltag_detection_t *det;
                zarray_get(detections, i, &det);
                int markerId = det->id;
                if (markerId < opts.validMin || markerId > opts.validMax) continue;

                json corners = json::array();
                for (int c = 0; c < 4; ++c) {
                    corners.push_back({det->p[c][0], det->p[c][1]});
                }

                out["detections"].push_back({
                    {"source_type", item.value("source_type", "")},
                    {"source_id", item.value("source_id", 0)},
                    {"source_path", item.value("source_path", "")},
                    {"frame_index", item.contains("frame_index") ? item["frame_index"] : json(nullptr)},
                    {"timestamp_ms", item.contains("timestamp_ms") ? item["timestamp_ms"] : json(nullptr)},
                    {"marker_id", markerId},
                    {"corners", corners},
                    {"center_x", det->c[0]},
                    {"center_y", det->c[1]},
                    {"confidence", det->decision_margin}
                });
            }
            apriltag_detections_destroy(detections);
        }

        apriltag_detector_destroy(td);
        tag36h11_destroy(tf);

    } catch (const std::exception &e) {
        out = { {"ok", false}, {"error", e.what()} };
    }

    std::ofstream of(opts.output);
    if (!of.is_open()) {
        std::cerr << "Failed to write output JSON" << std::endl;
        return 3;
    }
    of << out.dump(2) << std::endl;

    if (out.contains("ok") && out["ok"].is_boolean() && !out["ok"].get<bool>()) {
        return 1;
    }
    return 0;
}
