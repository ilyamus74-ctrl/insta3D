#pragma once

#include "stereo_preview.hpp"

#include <array>
#include <cstddef>
#include <cstdint>
#include <string>
#include <vector>

#include <opencv2/core.hpp>

namespace maklertour::dual_phone::detail {

struct Intrinsics {
    int width = 0;
    int height = 0;
    double fx = 0.0;
    double fy = 0.0;
    double cx = 0.0;
    double cy = 0.0;
    double k1 = 0.0;
    double k2 = 0.0;
};

struct CalibrationProfile {
    std::string profile_id;
    std::string master_device_id;
    std::string slave_device_id;
    Intrinsics master;
    Intrinsics slave;
    std::array<double, 9> rotation{};
    std::array<double, 3> translation_mm{};
    double measured_baseline_mm = 0.0;
};

struct ResolvedCalibration {
    std::string profile_id;
    std::string camera_a_device_id;
    std::string camera_b_device_id;
    Intrinsics camera_a;
    Intrinsics camera_b;
    std::array<double, 9> rotation{};
    std::array<double, 3> translation_mm{};
    double measured_baseline_mm = 0.0;
    bool roles_reversed = false;
    std::uint64_t revision = 0;
};

struct PreparedFrame {
    cv::Mat image;
    Intrinsics intrinsics;
    int applied_rotation_degrees = 0;
};

enum class RectificationAxis {
    Horizontal,
    Vertical,
};

struct ImageStatistics {
    double mean_luma = 0.0;
    double nonzero_fraction = 0.0;
};

struct DisparityOutput {
    cv::Mat preview;
    double valid_ratio = 0.0;
    int min_disparity = 0;
    int num_disparities = 0;
};

CalibrationProfile parse_profile(const nlohmann::json& profile);
ResolvedCalibration resolve_profile(
    const CalibrationProfile& profile,
    const std::string& camera_a_device_id,
    const std::string& camera_b_device_id,
    std::uint64_t revision);
PreparedFrame prepare_frame(
    const StereoPreviewFrame& frame,
    const Intrinsics& calibration,
    const char* name);
cv::Mat camera_matrix(const Intrinsics& value);
cv::Mat distortion(const Intrinsics& value);
cv::Mat rotation_matrix(const std::array<double, 9>& values);
cv::Mat translation_vector(const std::array<double, 3>& values);
RectificationAxis rectification_axis(
    const cv::Mat& projection_b,
    const std::array<double, 3>& translation_mm);
const char* rectification_axis_name(RectificationAxis axis);
double projection_shift(
    const cv::Mat& projection_b,
    RectificationAxis axis,
    const std::array<double, 3>& translation_mm);
cv::Mat orient_for_horizontal_disparity(
    const cv::Mat& rectified,
    RectificationAxis axis,
    double rectified_projection_shift);
double map_valid_fraction(
    const cv::Mat& map_x,
    const cv::Mat& map_y,
    cv::Size source_size);
ImageStatistics image_statistics(const cv::Mat& image);
void require_usable_rectified_image(
    const ImageStatistics& statistics,
    const char* name);
std::vector<std::uint8_t> encode_jpeg(const cv::Mat& image);
cv::Mat with_epipolar_guides(const cv::Mat& image);
DisparityOutput make_disparity(
    const cv::Mat& rectified_a,
    const cv::Mat& rectified_b,
    double rectified_projection_shift);

}  // namespace maklertour::dual_phone::detail
