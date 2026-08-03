#pragma once

#include <cstdint>
#include <filesystem>
#include <memory>
#include <optional>
#include <vector>

#include <nlohmann/json.hpp>
#include <opencv2/core.hpp>

namespace maklertour::dual_phone::detail {

struct AprilTagAnchorResult {
    bool detections_present = false;
    bool anchor_pose_valid = false;
    bool relocalized = false;
    int detected_tags = 0;
    int mapped_tags_used = 0;
    int rejected_tags = 0;
    std::vector<int> ids;
    cv::Matx44d world_from_camera = cv::Matx44d::eye();
    double position_correction_m = 0.0;
    double yaw_correction_deg = 0.0;
    double confidence = 0.0;
};

class AprilTagAnchorRuntime {
public:
    explicit AprilTagAnchorRuntime(std::filesystem::path session_directory);
    ~AprilTagAnchorRuntime();

    AprilTagAnchorRuntime(const AprilTagAnchorRuntime&) = delete;
    AprilTagAnchorRuntime& operator=(const AprilTagAnchorRuntime&) = delete;

    AprilTagAnchorResult process(
        std::uint64_t pair_index,
        const cv::Mat& camera_a_bgr,
        double focal_px,
        double principal_x_px,
        double principal_y_px,
        const std::optional<cv::Matx44d>& preliminary_world_from_camera);

    void reset();
    nlohmann::json status_json() const;

private:
    struct Impl;
    std::unique_ptr<Impl> impl_;
};

}  // namespace maklertour::dual_phone::detail
