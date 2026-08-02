#include "stereo_preview_processing.hpp"

#include <algorithm>
#include <cmath>
#include <stdexcept>
#include <utility>

#include <opencv2/calib3d.hpp>
#include <opencv2/imgcodecs.hpp>
#include <opencv2/imgproc.hpp>

namespace maklertour::dual_phone::detail {

namespace {

constexpr int kJpegQuality = 88;
constexpr int kGuideStepPixels = 48;

bool finite(const double value) {
    return std::isfinite(value);
}

double required_number(const nlohmann::json& object, const char* key) {
    if (!object.contains(key) || !object.at(key).is_number()) {
        throw std::runtime_error(std::string("missing numeric field: ") + key);
    }
    const auto value = object.at(key).get<double>();
    if (!finite(value)) {
        throw std::runtime_error(std::string("non-finite field: ") + key);
    }
    return value;
}

std::string required_string(const nlohmann::json& object, const char* key) {
    if (!object.contains(key) || !object.at(key).is_string()) {
        throw std::runtime_error(std::string("missing string field: ") + key);
    }
    const auto value = object.at(key).get<std::string>();
    if (value.empty()) throw std::runtime_error(std::string("empty field: ") + key);
    return value;
}

Intrinsics parse_intrinsics(const nlohmann::json& object, const char* name) {
    if (!object.is_object()) {
        throw std::runtime_error(std::string(name) + " must be an object");
    }
    if (!object.value("solved", false)) {
        throw std::runtime_error(std::string(name) + " is not solved");
    }
    Intrinsics result;
    result.width = object.value("image_width", 0);
    result.height = object.value("image_height", 0);
    result.fx = required_number(object, "fx");
    result.fy = required_number(object, "fy");
    result.cx = required_number(object, "cx");
    result.cy = required_number(object, "cy");
    result.k1 = required_number(object, "k1");
    result.k2 = required_number(object, "k2");
    if (result.width <= 0 || result.height <= 0 || result.fx <= 0.0 || result.fy <= 0.0) {
        throw std::runtime_error(std::string(name) + " contains invalid dimensions or focal length");
    }
    return result;
}

template <std::size_t Size>
std::array<double, Size> parse_number_array(const nlohmann::json& value,
                                             const char* name) {
    if (!value.is_array() || value.size() != Size) {
        throw std::runtime_error(std::string(name) + " must contain " +
                                 std::to_string(Size) + " numbers");
    }
    std::array<double, Size> result{};
    for (std::size_t index = 0; index < Size; ++index) {
        if (!value.at(index).is_number()) {
            throw std::runtime_error(std::string(name) + " contains a non-number");
        }
        result[index] = value.at(index).get<double>();
        if (!finite(result[index])) {
            throw std::runtime_error(std::string(name) + " contains a non-finite number");
        }
    }
    return result;
}

}  // namespace

CalibrationProfile parse_profile(const nlohmann::json& profile) {
    if (!profile.is_object()) throw std::runtime_error("calibration profile must be an object");
    if (profile.value("schema_version", 0) != 1) {
        throw std::runtime_error("unsupported calibration schema_version");
    }
    if (profile.value("status", std::string{}) != "success") {
        throw std::runtime_error("calibration profile status is not success");
    }
    CalibrationProfile result;
    result.profile_id = required_string(profile, "profile_id");
    result.master_device_id = required_string(profile, "master_device_id");
    result.slave_device_id = required_string(profile, "slave_device_id");
    if (result.master_device_id == result.slave_device_id) {
        throw std::runtime_error("calibration profile contains the same device twice");
    }
    result.master = parse_intrinsics(profile.at("master_intrinsics"), "master_intrinsics");
    result.slave = parse_intrinsics(profile.at("slave_intrinsics"), "slave_intrinsics");

    const auto& stereo = profile.at("stereo");
    if (!stereo.is_object() || !stereo.value("solved", false)) {
        throw std::runtime_error("stereo calibration is not solved");
    }
    result.rotation = parse_number_array<9>(stereo.at("rotation"), "stereo.rotation");
    result.translation_mm =
        parse_number_array<3>(stereo.at("translation_mm"), "stereo.translation_mm");
    result.measured_baseline_mm = required_number(stereo, "baseline_mm");
    if (result.measured_baseline_mm <= 0.0) {
        throw std::runtime_error("stereo baseline must be positive");
    }
    return result;
}

ResolvedCalibration resolve_profile(const CalibrationProfile& profile,
                                    const std::string& camera_a_device_id,
                                    const std::string& camera_b_device_id,
                                    const std::uint64_t revision) {
    ResolvedCalibration result;
    result.profile_id = profile.profile_id;
    result.camera_a_device_id = camera_a_device_id;
    result.camera_b_device_id = camera_b_device_id;
    result.measured_baseline_mm = profile.measured_baseline_mm;
    result.revision = revision;

    if (camera_a_device_id == profile.master_device_id &&
        camera_b_device_id == profile.slave_device_id) {
        result.camera_a = profile.master;
        result.camera_b = profile.slave;
        result.rotation = profile.rotation;
        result.translation_mm = profile.translation_mm;
        return result;
    }
    if (camera_a_device_id == profile.slave_device_id &&
        camera_b_device_id == profile.master_device_id) {
        result.camera_a = profile.slave;
        result.camera_b = profile.master;
        result.roles_reversed = true;

        for (std::size_t row = 0; row < 3; ++row) {
            for (std::size_t column = 0; column < 3; ++column) {
                result.rotation[row * 3 + column] =
                    profile.rotation[column * 3 + row];
            }
        }
        for (std::size_t row = 0; row < 3; ++row) {
            double value = 0.0;
            for (std::size_t column = 0; column < 3; ++column) {
                value += result.rotation[row * 3 + column] *
                         profile.translation_mm[column];
            }
            result.translation_mm[row] = -value;
        }
        return result;
    }
    throw std::runtime_error(
        "connected CAMERA_A/CAMERA_B device IDs do not match calibration profile");
}

bool compatible_aspect(const cv::Size image_size, const Intrinsics& calibration) {
    const auto image_ratio = static_cast<double>(image_size.width) /
                             static_cast<double>(image_size.height);
    const auto calibration_ratio = static_cast<double>(calibration.width) /
                                   static_cast<double>(calibration.height);
    return std::abs(image_ratio - calibration_ratio) <= 0.01;
}

Intrinsics scale_intrinsics(const Intrinsics& source, const cv::Size target) {
    const auto scale_x = static_cast<double>(target.width) /
                         static_cast<double>(source.width);
    const auto scale_y = static_cast<double>(target.height) /
                         static_cast<double>(source.height);
    Intrinsics result = source;
    result.width = target.width;
    result.height = target.height;
    result.fx *= scale_x;
    result.cx *= scale_x;
    result.fy *= scale_y;
    result.cy *= scale_y;
    return result;
}

PreparedFrame prepare_frame(const StereoPreviewFrame& frame,
                            const Intrinsics& calibration,
                            const char* name) {
    if (frame.jpeg.empty()) throw std::runtime_error(std::string(name) + " JPEG is empty");
    auto decoded = cv::imdecode(frame.jpeg, cv::IMREAD_COLOR);
    if (decoded.empty()) throw std::runtime_error(std::string(name) + " JPEG decode failed");
    if (frame.width > 0 && frame.height > 0 &&
        (decoded.cols != frame.width || decoded.rows != frame.height)) {
        throw std::runtime_error(std::string(name) + " JPEG dimensions differ from header");
    }

    // Match the working Android MASTER/SLAVE pipeline: CameraX rotation is
    // display metadata only. Stereo calibration and stereoRectify operate on
    // the unrotated sensor/JPEG landscape pixels.
    const auto exact_raw = decoded.cols == calibration.width &&
                           decoded.rows == calibration.height;
    if (exact_raw) {
        return {std::move(decoded), calibration, 0};
    }
    if (compatible_aspect(decoded.size(), calibration)) {
        return {std::move(decoded), scale_intrinsics(calibration, decoded.size()), 0};
    }
    throw std::runtime_error(
        std::string(name) + " raw frame aspect/dimensions do not match calibration (frame " +
        std::to_string(decoded.cols) + "x" + std::to_string(decoded.rows) +
        ", rotation metadata " + std::to_string(frame.rotation_degrees) +
        ", calibration " + std::to_string(calibration.width) + "x" +
        std::to_string(calibration.height) + ")");
}

cv::Mat camera_matrix(const Intrinsics& value) {
    return (cv::Mat_<double>(3, 3) <<
        value.fx, 0.0, value.cx,
        0.0, value.fy, value.cy,
        0.0, 0.0, 1.0);
}

cv::Mat distortion(const Intrinsics& value) {
    return (cv::Mat_<double>(1, 5) << value.k1, value.k2, 0.0, 0.0, 0.0);
}

cv::Mat rotation_matrix(const std::array<double, 9>& values) {
    cv::Mat result(3, 3, CV_64F);
    for (std::size_t index = 0; index < values.size(); ++index) {
        result.at<double>(static_cast<int>(index / 3),
                          static_cast<int>(index % 3)) = values[index];
    }
    return result;
}

cv::Mat translation_vector(const std::array<double, 3>& values) {
    return (cv::Mat_<double>(3, 1) << values[0], values[1], values[2]);
}

RectificationAxis rectification_axis(
    const cv::Mat& projection_b,
    const std::array<double, 3>& translation_mm) {
    if (projection_b.rows < 3 || projection_b.cols < 4 ||
        projection_b.type() != CV_64F) {
        throw std::runtime_error("stereo projection matrix must be 3x4 CV_64F");
    }
    constexpr double kMinimumShift = 1e-9;
    const auto horizontal = std::abs(projection_b.at<double>(0, 3));
    const auto vertical = std::abs(projection_b.at<double>(1, 3));
    if (std::max(horizontal, vertical) > kMinimumShift) {
        return vertical > horizontal
            ? RectificationAxis::Vertical
            : RectificationAxis::Horizontal;
    }

    const auto translation_x = std::abs(translation_mm[0]);
    const auto translation_y = std::abs(translation_mm[1]);
    if (std::max(translation_x, translation_y) <= kMinimumShift) {
        throw std::runtime_error(
            "stereo calibration contains no usable X/Y baseline");
    }
    return translation_y > translation_x
        ? RectificationAxis::Vertical
        : RectificationAxis::Horizontal;
}

const char* rectification_axis_name(const RectificationAxis axis) {
    return axis == RectificationAxis::Vertical ? "VERTICAL" : "HORIZONTAL";
}

double projection_shift(
    const cv::Mat& projection_b,
    const RectificationAxis axis,
    const std::array<double, 3>& translation_mm) {
    constexpr double kMinimumShift = 1e-9;
    const int row = axis == RectificationAxis::Vertical ? 1 : 0;
    const auto matrix_shift = projection_b.at<double>(row, 3);
    if (std::abs(matrix_shift) > kMinimumShift) return matrix_shift;

    // StereoSGBM only needs the disparity direction here. Some OpenCV builds
    // return a zero P2 baseline/focal term for this vertical phone geometry,
    // while the accepted calibrated T remains valid and authoritative.
    const auto fallback_shift = translation_mm[static_cast<std::size_t>(row)];
    if (!finite(fallback_shift) || std::abs(fallback_shift) <= kMinimumShift) {
        throw std::runtime_error(
            "stereo projection and calibrated T contain no usable baseline shift");
    }
    return fallback_shift;
}

cv::Mat orient_for_horizontal_disparity(
    const cv::Mat& rectified,
    const RectificationAxis axis,
    const double rectified_projection_shift) {
    if (axis == RectificationAxis::Horizontal) return rectified;
    cv::Mat result;
    const auto rotation = rectified_projection_shift < 0.0
        ? cv::ROTATE_90_COUNTERCLOCKWISE
        : cv::ROTATE_90_CLOCKWISE;
    cv::rotate(rectified, result, rotation);
    return result;
}

double map_valid_fraction(const cv::Mat& map_x,
                          const cv::Mat& map_y,
                          const cv::Size source_size) {
    if (map_x.empty() || map_y.empty() || map_x.size() != map_y.size() ||
        map_x.type() != CV_32FC1 || map_y.type() != CV_32FC1 ||
        source_size.width <= 0 || source_size.height <= 0) {
        return 0.0;
    }
    cv::Mat x_low;
    cv::Mat x_high;
    cv::Mat y_low;
    cv::Mat y_high;
    cv::Mat valid_x;
    cv::Mat valid_y;
    cv::Mat valid;
    cv::compare(map_x, cv::Scalar(0.0), x_low, cv::CMP_GE);
    cv::compare(
        map_x,
        cv::Scalar(static_cast<double>(source_size.width)),
        x_high,
        cv::CMP_LT);
    cv::compare(map_y, cv::Scalar(0.0), y_low, cv::CMP_GE);
    cv::compare(
        map_y,
        cv::Scalar(static_cast<double>(source_size.height)),
        y_high,
        cv::CMP_LT);
    cv::bitwise_and(x_low, x_high, valid_x);
    cv::bitwise_and(y_low, y_high, valid_y);
    cv::bitwise_and(valid_x, valid_y, valid);
    const auto total = static_cast<double>(valid.total());
    return total > 0.0
        ? static_cast<double>(cv::countNonZero(valid)) / total
        : 0.0;
}

ImageStatistics image_statistics(const cv::Mat& image) {
    if (image.empty()) return {};
    cv::Mat gray;
    if (image.channels() == 1) {
        gray = image;
    } else {
        cv::cvtColor(image, gray, cv::COLOR_BGR2GRAY);
    }
    cv::Mat nonzero;
    cv::compare(gray, cv::Scalar(2), nonzero, cv::CMP_GT);
    const auto total = static_cast<double>(gray.total());
    return {
        cv::mean(gray)[0],
        total > 0.0
            ? static_cast<double>(cv::countNonZero(nonzero)) / total
            : 0.0,
    };
}

void require_usable_rectified_image(const ImageStatistics& statistics,
                                    const char* name) {
    if (statistics.mean_luma < 0.5 || statistics.nonzero_fraction < 0.01) {
        throw std::runtime_error(
            std::string(name) +
            " rectification produced an effectively black image");
    }
}

std::vector<std::uint8_t> encode_jpeg(const cv::Mat& image) {
    std::vector<std::uint8_t> output;
    if (!cv::imencode(".jpg", image, output,
                      {cv::IMWRITE_JPEG_QUALITY, kJpegQuality})) {
        throw std::runtime_error("JPEG preview encoding failed");
    }
    return output;
}

cv::Mat with_epipolar_guides(const cv::Mat& image) {
    auto result = image.clone();
    for (int y = kGuideStepPixels; y < result.rows; y += kGuideStepPixels) {
        cv::line(result, {0, y}, {result.cols - 1, y}, {40, 220, 80}, 1,
                 cv::LINE_AA);
    }
    return result;
}

int disparity_range(const int width) {
    const auto requested = std::clamp(width / 6, 16, 160);
    return std::max(16, (requested / 16) * 16);
}

DisparityOutput make_disparity(const cv::Mat& rectified_a,
                               const cv::Mat& rectified_b,
                               const double rectified_projection_shift) {
    cv::Mat gray_a;
    cv::Mat gray_b;
    cv::cvtColor(rectified_a, gray_a, cv::COLOR_BGR2GRAY);
    cv::cvtColor(rectified_b, gray_b, cv::COLOR_BGR2GRAY);

    const auto num_disparities = disparity_range(rectified_a.cols);
    const auto min_disparity =
        rectified_projection_shift > 0.0 ? -num_disparities : 0;
    constexpr int block_size = 5;
    auto matcher = cv::StereoSGBM::create(
        min_disparity,
        num_disparities,
        block_size,
        8 * block_size * block_size,
        32 * block_size * block_size,
        1,
        31,
        10,
        80,
        2,
        cv::StereoSGBM::MODE_SGBM_3WAY);
    cv::Mat disparity_16;
    matcher->compute(gray_a, gray_b, disparity_16);

    cv::Mat normalized(disparity_16.size(), CV_8U, cv::Scalar(0));
    cv::Mat valid_mask(disparity_16.size(), CV_8U, cv::Scalar(0));
    std::uint64_t valid = 0;
    const auto total = static_cast<std::uint64_t>(disparity_16.rows) *
                       static_cast<std::uint64_t>(disparity_16.cols);
    for (int y = 0; y < disparity_16.rows; ++y) {
        for (int x = 0; x < disparity_16.cols; ++x) {
            const auto disparity =
                static_cast<double>(disparity_16.at<std::int16_t>(y, x)) / 16.0;
            if (disparity <= static_cast<double>(min_disparity) ||
                disparity >= static_cast<double>(min_disparity + num_disparities)) {
                continue;
            }
            const auto unit = (disparity - static_cast<double>(min_disparity)) /
                              static_cast<double>(num_disparities);
            normalized.at<std::uint8_t>(y, x) = static_cast<std::uint8_t>(
                std::clamp(unit * 255.0, 0.0, 255.0));
            valid_mask.at<std::uint8_t>(y, x) = 255;
            valid += 1;
        }
    }
    cv::Mat colour;
    cv::applyColorMap(normalized, colour, cv::COLORMAP_TURBO);
    cv::Mat invalid_mask;
    cv::bitwise_not(valid_mask, invalid_mask);
    colour.setTo(cv::Scalar(0, 0, 0), invalid_mask);
    return {
        std::move(colour),
        total > 0 ? static_cast<double>(valid) / static_cast<double>(total) : 0.0,
        min_disparity,
        num_disparities,
    };
}

}  // namespace maklertour::dual_phone::detail
