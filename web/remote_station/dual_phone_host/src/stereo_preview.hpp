#pragma once

#include <array>
#include <cstdint>
#include <filesystem>
#include <memory>
#include <optional>
#include <string>
#include <vector>

#include <nlohmann/json.hpp>

namespace maklertour::dual_phone {

struct StereoPreviewFrame {
    std::uint64_t sequence = 0;
    std::int64_t pair_timestamp_ns = 0;
    int width = 0;
    int height = 0;
    int rotation_degrees = 0;
    std::vector<std::uint8_t> jpeg;
};

struct StereoPreviewPair {
    std::uint64_t pair_index = 0;
    double delta_ms = 0.0;
    StereoPreviewFrame camera_a;
    StereoPreviewFrame camera_b;
};

enum class StereoPreviewImage {
    RectifiedA,
    RectifiedB,
    Disparity,
};

class StereoPreview {
public:
    explicit StereoPreview(std::filesystem::path session_directory);
    ~StereoPreview();

    StereoPreview(const StereoPreview&) = delete;
    StereoPreview& operator=(const StereoPreview&) = delete;

    void set_camera_identity(std::size_t slot_index, std::string device_id);
    void clear_camera_identity(std::size_t slot_index);
    void set_calibration_profile(const nlohmann::json& profile);
    void clear_calibration_profile();
    void submit(StereoPreviewPair pair);

    nlohmann::json status_json() const;
    std::optional<std::vector<std::uint8_t>> image(StereoPreviewImage kind) const;

private:
    struct Impl;
    std::unique_ptr<Impl> impl_;
};

}  // namespace maklertour::dual_phone
