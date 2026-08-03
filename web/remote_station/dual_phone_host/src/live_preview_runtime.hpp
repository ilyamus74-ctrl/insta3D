#pragma once

#include "stereo_preview.hpp"
#include "stereo_preview_processing.hpp"

#include <filesystem>
#include <memory>
#include <optional>
#include <string>
#include <vector>

#include <nlohmann/json.hpp>

namespace maklertour::dual_phone::detail {

class LivePreviewRuntime {
public:
    explicit LivePreviewRuntime(std::filesystem::path session_directory);
    ~LivePreviewRuntime();

    LivePreviewRuntime(const LivePreviewRuntime&) = delete;
    LivePreviewRuntime& operator=(const LivePreviewRuntime&) = delete;

    void submit(StereoPreviewPair pair, ResolvedCalibration calibration);
    nlohmann::json select_profile(std::string mode);
    void reset();

    nlohmann::json status_json() const;
    std::optional<std::vector<std::uint8_t>> image() const;

private:
    struct Impl;
    std::unique_ptr<Impl> impl_;
};

}  // namespace maklertour::dual_phone::detail
