#pragma once

#include "stereo_preview.hpp"
#include "stereo_preview_processing.hpp"

#include <cstdint>
#include <filesystem>
#include <memory>
#include <optional>
#include <string>
#include <vector>

#include <nlohmann/json.hpp>
#include <opencv2/core.hpp>

namespace maklertour::dual_phone::detail {

struct StereoAprilTagAnchorResult {
    bool detections_present = false;
    bool anchor_pose_valid = false;
    bool live_correction_allowed = false;
    bool constraint_only = false;
    bool relocalized = false;
    bool stereo_verified = false;
    int detected_tags = 0;
    int mapped_tags_used = 0;
    int stereo_tags_used = 0;
    int rejected_tags = 0;
    std::vector<int> ids;
    std::vector<int> used_anchor_ids;
    cv::Matx44d world_from_camera = cv::Matx44d::eye();
    double position_correction_m = 0.0;
    double yaw_correction_deg = 0.0;
    double confidence = 0.0;
    std::string pose_source = "NONE";
};

class StereoAprilTagRuntime {
public:
    explicit StereoAprilTagRuntime(std::filesystem::path session_directory);
    ~StereoAprilTagRuntime();

    StereoAprilTagRuntime(const StereoAprilTagRuntime&) = delete;
    StereoAprilTagRuntime& operator=(const StereoAprilTagRuntime&) = delete;

    void submit(
        StereoPreviewPair pair,
        ResolvedCalibration calibration);

    StereoAprilTagAnchorResult evaluate(
        std::uint64_t pair_index,
        const std::optional<cv::Matx44d>& preliminary_world_from_camera,
        bool preliminary_translation_trusted);

    void reset();
    nlohmann::json status_json() const;

private:
    struct Impl;
    std::unique_ptr<Impl> impl_;
};

}  // namespace maklertour::dual_phone::detail
