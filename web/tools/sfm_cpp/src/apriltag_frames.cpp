#include "apriltag_frames.hpp"

#include "camera_profile.hpp"

#include <apriltag/apriltag.h>
#include <apriltag/tag36h11.h>

#include <opencv2/calib3d.hpp>
#include <opencv2/imgcodecs.hpp>
#include <opencv2/imgproc.hpp>

#include <algorithm>
#include <filesystem>
#include <regex>
#include <stdexcept>
#include <vector>

namespace sfm {

nlohmann::json detect_apriltags_in_frames(const std::string& frames_dir,
                                          const std::string& camera_profile_path,
                                          double marker_size_m,
                                          const std::string& family) {
    if (family != "tag36h11") throw std::runtime_error("Only tag36h11 family is supported");

    CameraProfile cp = load_camera_profile(camera_profile_path);
    cv::Mat cameraMatrix = (cv::Mat_<double>(3, 3) << cp.fx, 0, cp.cx, 0, cp.fy, cp.cy, 0, 0, 1);
    cv::Mat distCoeffs(cp.dist, true);

    apriltag_family_t* tf = tag36h11_create();
    apriltag_detector_t* td = apriltag_detector_create();
    apriltag_detector_add_family(td, tf);

    std::vector<std::filesystem::path> images;
    for (auto const& e : std::filesystem::directory_iterator(frames_dir)) {
        if (!e.is_regular_file()) continue;
        auto ext = e.path().extension().string();
        if (ext == ".jpg" || ext == ".jpeg" || ext == ".png") images.push_back(e.path());
    }
    std::sort(images.begin(), images.end());

    nlohmann::json out;
    out["ok"] = true;
    out["count"] = 0;
    out["observations"] = nlohmann::json::array();

    std::regex frame_re(".*_(\\d+)\\..*");

    for (const auto& path : images) {
        cv::Mat img = cv::imread(path.string(), cv::IMREAD_COLOR);
        if (img.empty()) continue;
        cv::Mat gray;
        cv::cvtColor(img, gray, cv::COLOR_BGR2GRAY);

        image_u8_t im = {
            gray.cols,
            gray.rows,
            static_cast<int32_t>(gray.step),
            gray.data
        };

        zarray_t* detections = apriltag_detector_detect(td, &im);

        for (int i = 0; i < zarray_size(detections); i++) {
            apriltag_detection_t* det;
            zarray_get(detections, i, &det);
            std::vector<cv::Point2f> corners2d;
            nlohmann::json corners = nlohmann::json::array();
            for (int c = 0; c < 4; ++c) {
                corners2d.emplace_back(det->p[c][0], det->p[c][1]);
                corners.push_back({det->p[c][0], det->p[c][1]});
            }

            float h = static_cast<float>(marker_size_m / 2.0);

            std::vector<cv::Point3f> obj = {
                {-h, -h, 0.0f},
                { h, -h, 0.0f},
                { h,  h, 0.0f},
                {-h,  h, 0.0f}
            };
            cv::Mat rvec, tvec;
            bool ok = cv::solvePnP(obj, corners2d, cameraMatrix, distCoeffs, rvec, tvec, false, cv::SOLVEPNP_IPPE_SQUARE);
            if (!ok) continue;

            int frame_idx = -1;
            std::smatch m;
            std::string fname = path.filename().string();
            if (std::regex_match(fname, m, frame_re) && m.size() > 1) frame_idx = std::stoi(m[1].str());

            double dist_m = cv::norm(tvec);
            out["observations"].push_back({
                {"image_name", fname},
                {"frame_index", frame_idx},
                {"marker_family", family},
                {"marker_id", det->id},
                {"marker_size_m", marker_size_m},
                {"corners", corners},
                {"center", {det->c[0], det->c[1]}},
                {"rvec", {rvec.at<double>(0), rvec.at<double>(1), rvec.at<double>(2)}},
                {"tvec", {tvec.at<double>(0), tvec.at<double>(1), tvec.at<double>(2)}},
                {"distance_m", dist_m},
                {"confidence", det->decision_margin}
            });
        }
        apriltag_detections_destroy(detections);
    }

    apriltag_detector_destroy(td);
    tag36h11_destroy(tf);

    out["count"] = out["observations"].size();
    return out;
}

}
