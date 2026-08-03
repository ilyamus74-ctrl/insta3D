#pragma once

#include <chrono>
#include <cstdint>
#include <memory>
#include <optional>
#include <string>

#include <nlohmann/json.hpp>
#include <opencv2/core.hpp>

namespace maklertour::dual_phone::detail {

struct StereoDepthBudget {
    std::string selection_mode;
    std::string profile_name;
    int work_width = 0;
    int work_height = 0;
    int min_processing_interval_ms = 0;
    bool enable_left_right_check = false;
    bool allow_upscale = false;
    double target_depth_fps = 0.0;
    std::uint64_t revision = 0;
};

struct StereoDepthResult {
    cv::Mat work_a;
    cv::Mat work_b;
    cv::Mat disparity_preview;
    cv::Mat raw_depth_preview;
    cv::Mat filtered_depth_preview;
    cv::Mat strict_depth_preview;
    cv::Mat confidence_preview;
    cv::Mat geometry_disparity;
    cv::Mat geometry_mask;
    int work_width = 0;
    int work_height = 0;
    bool source_upscaled = false;
    double raw_valid_ratio = 0.0;
    double filtered_valid_ratio = 0.0;
    double dense_coverage_ratio = 0.0;
    double stable_coverage_ratio = 0.0;
    double high_confidence_ratio = 0.0;
    std::optional<double> median_depth_m;
    std::optional<double> depth_jitter_m;
    double motion_score_percent = 0.0;
    std::string temporal_mode = "WAITING";
    double left_right_accepted_percent = 0.0;
    double texture_accepted_percent = 0.0;
    double morphology_accepted_percent = 0.0;
    int min_disparity = 0;
    int num_disparities = 0;
    double focal_px = 0.0;
    double baseline_mm = 0.0;
    double principal_x_px = 0.0;
    double principal_y_px = 0.0;
    double processing_ms = 0.0;
};

class StereoDepthRuntime {
public:
    StereoDepthRuntime();
    ~StereoDepthRuntime();

    StereoDepthRuntime(const StereoDepthRuntime&) = delete;
    StereoDepthRuntime& operator=(const StereoDepthRuntime&) = delete;

    std::optional<StereoDepthBudget> acquire_budget();
    StereoDepthResult process(
        const cv::Mat& rectified_a,
        const cv::Mat& rectified_b,
        bool vertical_rectification,
        double rectified_focal_px,
        double baseline_mm,
        const StereoDepthBudget& budget);

    nlohmann::json select_mode(const std::string& mode);
    nlohmann::json profiles_json() const;
    nlohmann::json status_json() const;
    void reset_geometry();

private:
    struct Impl;
    std::unique_ptr<Impl> impl_;
};

}  // namespace maklertour::dual_phone::detail
