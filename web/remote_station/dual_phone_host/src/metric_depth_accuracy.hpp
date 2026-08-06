#pragma once

#include <algorithm>
#include <cmath>
#include <cstddef>
#include <cstdint>
#include <limits>
#include <optional>
#include <stdexcept>
#include <string>
#include <utility>
#include <vector>

#include <nlohmann/json.hpp>
#include <opencv2/calib3d.hpp>
#include <opencv2/imgproc.hpp>

namespace maklertour::dual_phone::detail::metric_depth_accuracy {

constexpr double kMinimumDisparity = 0.5;
constexpr double kMatcherNearRangeMeters = 0.75;
constexpr double kLeftRightConsistencyThresholdPx = 1.5;
constexpr int kMaximumLiveNumDisparities = 384;

inline double effective_disparity(const double raw_disparity,
                                  const double zero_offset_px) {
    return raw_disparity - zero_offset_px;
}

inline double mask_ratio(const cv::Mat& mask) {
    if (mask.empty() || mask.total() == 0) return 0.0;
    return static_cast<double>(cv::countNonZero(mask)) /
           static_cast<double>(mask.total());
}

inline double consistency_ratio(const cv::Mat& consistency_mask,
                                const cv::Mat& raw_mask) {
    if (consistency_mask.empty() || raw_mask.empty() ||
        consistency_mask.size() != raw_mask.size()) return 0.0;
    const int raw = cv::countNonZero(raw_mask);
    if (raw <= 0) return 0.0;
    cv::Mat accepted;
    cv::bitwise_and(consistency_mask, raw_mask, accepted);
    return static_cast<double>(cv::countNonZero(accepted)) /
           static_cast<double>(raw);
}

inline int num_disparities(const int width,
                           const double focal_px,
                           const double baseline_mm,
                           const double zero_offset_px) {
    if (width < 128 || !std::isfinite(focal_px) || focal_px <= 1.0 ||
        !std::isfinite(baseline_mm) || baseline_mm <= 0.0 ||
        !std::isfinite(zero_offset_px)) {
        throw std::runtime_error(
            "dynamic disparity range received invalid geometry");
    }
    const int width_limit = std::max(64, (width / 2 / 16) * 16);
    const int maximum = std::min(kMaximumLiveNumDisparities, width_limit);
    const double near_disparity =
        focal_px * baseline_mm /
        (kMatcherNearRangeMeters * 1000.0) +
        std::max(0.0, zero_offset_px);
    const int desired = std::max(
        64,
        static_cast<int>(std::ceil(near_disparity)) + 16);
    const int aligned = ((desired + 15) / 16) * 16;
    return std::clamp(aligned, 64, maximum);
}

inline cv::Ptr<cv::StereoSGBM> make_matcher(const int min_disparity,
                                             const int num_disparities) {
    constexpr int block_size = 5;
    return cv::StereoSGBM::create(
        min_disparity,
        num_disparities,
        block_size,
        8 * 3 * block_size * block_size,
        32 * 3 * block_size * block_size,
        1,
        31,
        8,
        50,
        2,
        cv::StereoSGBM::MODE_SGBM_3WAY);
}

inline void build_left_right_consistency(
    const cv::Mat& left_disparity,
    const cv::Mat& right_disparity,
    cv::Mat& consistency_mask,
    cv::Mat& consistency_error) {
    if (left_disparity.type() != CV_32F ||
        right_disparity.type() != CV_32F ||
        left_disparity.size() != right_disparity.size()) {
        throw std::runtime_error(
            "left/right disparity dimensions or types differ");
    }
    consistency_mask = cv::Mat(
        left_disparity.size(), CV_8U, cv::Scalar(0));
    consistency_error = cv::Mat(
        left_disparity.size(), CV_32F,
        cv::Scalar(std::numeric_limits<float>::quiet_NaN()));
    for (int y = 0; y < left_disparity.rows; ++y) {
        const auto* left_row = left_disparity.ptr<float>(y);
        auto* mask_row = consistency_mask.ptr<std::uint8_t>(y);
        auto* error_row = consistency_error.ptr<float>(y);
        for (int x = 0; x < left_disparity.cols; ++x) {
            const double left_value = left_row[x];
            if (!std::isfinite(left_value) ||
                left_value <= kMinimumDisparity) continue;
            const int right_x = static_cast<int>(std::lround(
                static_cast<double>(x) - left_value));
            if (right_x < 0 || right_x >= right_disparity.cols) continue;
            const double right_value =
                right_disparity.at<float>(y, right_x);
            if (!std::isfinite(right_value)) continue;
            const double error = std::abs(left_value + right_value);
            error_row[x] = static_cast<float>(error);
            if (error <= kLeftRightConsistencyThresholdPx) {
                mask_row[x] = 255;
            }
        }
    }
}

struct FrameResult {
    cv::Mat disparity;
    cv::Mat spatial;
    cv::Mat raw_mask;
    cv::Mat dense_mask;
    cv::Mat strict_mask;
    cv::Mat left_right_mask;
    cv::Mat left_right_error;
    cv::Mat disparity_colour;
    cv::Mat confidence;
    int num_disparities = 0;
};

inline FrameResult process(const cv::Mat& normalized_a,
                           const cv::Mat& normalized_b,
                           const int num_disparities,
                           const double zero_offset_px) {
    if (normalized_a.empty() || normalized_b.empty() ||
        normalized_a.type() != CV_8U || normalized_b.type() != CV_8U ||
        normalized_a.size() != normalized_b.size()) {
        throw std::runtime_error("metric depth inputs are invalid");
    }
    FrameResult result;
    result.num_disparities = num_disparities;

    auto left_matcher = make_matcher(0, num_disparities);
    auto right_matcher = make_matcher(-num_disparities + 1,
                                      num_disparities);
    cv::Mat left_16;
    cv::Mat right_16;
    left_matcher->compute(normalized_a, normalized_b, left_16);
    right_matcher->compute(normalized_b, normalized_a, right_16);
    left_16.convertTo(result.disparity, CV_32F, 1.0 / 16.0);
    cv::Mat right_disparity;
    right_16.convertTo(right_disparity, CV_32F, 1.0 / 16.0);

    build_left_right_consistency(
        result.disparity,
        right_disparity,
        result.left_right_mask,
        result.left_right_error);

    cv::Mat minimum_raw_mask;
    cv::Mat maximum_raw_mask;
    cv::Mat minimum_effective_mask;
    cv::compare(
        result.disparity,
        cv::Scalar(kMinimumDisparity),
        minimum_raw_mask,
        cv::CMP_GT);
    cv::compare(
        result.disparity,
        cv::Scalar(num_disparities - 1),
        maximum_raw_mask,
        cv::CMP_LT);
    cv::Mat effective_map;
    cv::subtract(
        result.disparity,
        cv::Scalar(zero_offset_px),
        effective_map);
    cv::compare(
        effective_map,
        cv::Scalar(kMinimumDisparity),
        minimum_effective_mask,
        cv::CMP_GT);
    cv::bitwise_and(
        minimum_raw_mask,
        maximum_raw_mask,
        result.raw_mask);
    cv::bitwise_and(
        result.raw_mask,
        minimum_effective_mask,
        result.raw_mask);

    cv::medianBlur(result.disparity, result.spatial, 5);
    cv::Mat gradient_x_16;
    cv::Mat gradient_y_16;
    cv::Mat gradient_x;
    cv::Mat gradient_y;
    cv::Mat texture;
    cv::Sobel(normalized_a, gradient_x_16, CV_16S, 1, 0, 3);
    cv::Sobel(normalized_a, gradient_y_16, CV_16S, 0, 1, 3);
    cv::convertScaleAbs(gradient_x_16, gradient_x);
    cv::convertScaleAbs(gradient_y_16, gradient_y);
    cv::addWeighted(
        gradient_x, 0.5, gradient_y, 0.5, 0.0, texture);

    cv::Mat dense_texture;
    cv::Mat strict_texture;
    cv::compare(texture, cv::Scalar(5), dense_texture, cv::CMP_GT);
    cv::compare(texture, cv::Scalar(12), strict_texture, cv::CMP_GT);
    cv::bitwise_and(result.raw_mask, dense_texture, result.dense_mask);
    cv::bitwise_and(
        result.dense_mask,
        result.left_right_mask,
        result.dense_mask);
    cv::bitwise_and(result.raw_mask, strict_texture, result.strict_mask);
    cv::bitwise_and(
        result.strict_mask,
        result.left_right_mask,
        result.strict_mask);

    const auto kernel = cv::getStructuringElement(
        cv::MORPH_ELLIPSE, {3, 3});
    cv::morphologyEx(
        result.dense_mask,
        result.dense_mask,
        cv::MORPH_CLOSE,
        kernel);
    cv::morphologyEx(
        result.strict_mask,
        result.strict_mask,
        cv::MORPH_OPEN,
        kernel);
    cv::bitwise_and(
        result.dense_mask,
        result.left_right_mask,
        result.dense_mask);
    cv::bitwise_and(
        result.strict_mask,
        result.left_right_mask,
        result.strict_mask);

    cv::Mat normalized_disparity;
    result.disparity.convertTo(
        normalized_disparity,
        CV_8U,
        255.0 / static_cast<double>(std::max(1, num_disparities)));
    cv::applyColorMap(
        normalized_disparity,
        result.disparity_colour,
        cv::COLORMAP_TURBO);
    cv::Mat invalid_raw;
    cv::bitwise_not(result.raw_mask, invalid_raw);
    result.disparity_colour.setTo(cv::Scalar(0, 0, 0), invalid_raw);

    result.confidence = cv::Mat(
        result.disparity.size(), CV_8UC3, cv::Scalar(0, 0, 0));
    result.confidence.setTo(cv::Scalar(0, 0, 255), result.raw_mask);
    result.confidence.setTo(
        cv::Scalar(0, 165, 255), result.dense_mask);
    result.confidence.setTo(
        cv::Scalar(0, 255, 0), result.strict_mask);
    return result;
}

struct ProbeSnapshot {
    cv::Mat disparity;
    cv::Mat mask;
    cv::Mat left_right_mask;
    cv::Mat left_right_error;
    double focal_px = 0.0;
    double baseline_mm = 0.0;
    double zero_offset_px = 0.0;
    int num_disparities = 0;
    int display_rotation_degrees = 0;
    std::uint64_t sequence = 0;
    std::uint64_t pair_index = 0;
    std::string selected_mode = "WAITING";
    bool ready = false;

    void reset() {
        disparity.release();
        mask.release();
        left_right_mask.release();
        left_right_error.release();
        focal_px = 0.0;
        baseline_mm = 0.0;
        zero_offset_px = 0.0;
        num_disparities = 0;
        display_rotation_degrees = 0;
        sequence = 0;
        pair_index = 0;
        selected_mode = "WAITING";
        ready = false;
    }

    void publish(const cv::Mat& source_disparity,
                 const cv::Mat& source_mask,
                 const cv::Mat& source_left_right_mask,
                 const cv::Mat& source_left_right_error,
                 const double source_focal_px,
                 const double source_baseline_mm,
                 const double source_zero_offset_px,
                 const int source_num_disparities,
                 const int source_display_rotation_degrees,
                 const std::uint64_t source_sequence,
                 const std::uint64_t source_pair_index,
                 std::string source_selected_mode) {
        disparity = source_disparity.clone();
        mask = source_mask.clone();
        left_right_mask = source_left_right_mask.clone();
        left_right_error = source_left_right_error.clone();
        focal_px = source_focal_px;
        baseline_mm = source_baseline_mm;
        zero_offset_px = source_zero_offset_px;
        num_disparities = source_num_disparities;
        display_rotation_degrees = source_display_rotation_degrees;
        sequence = source_sequence;
        pair_index = source_pair_index;
        selected_mode = std::move(source_selected_mode);
        ready = true;
    }

    nlohmann::json query(const double normalized_x,
                         const double normalized_y) const {
        nlohmann::json result = {
            {"schema_version", 2},
            {"valid", false},
            {"sequence", sequence},
            {"pair_index", pair_index},
            {"selected_mode", selected_mode},
            {"normalized_x", normalized_x},
            {"normalized_y", normalized_y},
            {"disparity_zero_offset_px", zero_offset_px},
            {"focal_px", focal_px},
            {"baseline_mm", baseline_mm},
            {"num_disparities", num_disparities},
        };
        if (!ready || disparity.empty() || mask.empty() ||
            disparity.type() != CV_32F || mask.type() != CV_8U ||
            disparity.size() != mask.size()) {
            result["reason"] = "DEPTH_PROBE_NOT_READY";
            return result;
        }
        if (!std::isfinite(normalized_x) || !std::isfinite(normalized_y) ||
            normalized_x < 0.0 || normalized_x > 1.0 ||
            normalized_y < 0.0 || normalized_y > 1.0) {
            result["reason"] = "NORMALIZED_COORDINATE_OUT_OF_RANGE";
            return result;
        }

        const int rotation =
            ((display_rotation_degrees % 360) + 360) % 360;
        const int source_width = disparity.cols;
        const int source_height = disparity.rows;
        const int display_width =
            rotation == 90 || rotation == 270
                ? source_height
                : source_width;
        const int display_height =
            rotation == 90 || rotation == 270
                ? source_width
                : source_height;
        const int display_x = std::clamp(
            static_cast<int>(std::lround(
                normalized_x * static_cast<double>(display_width - 1))),
            0, display_width - 1);
        const int display_y = std::clamp(
            static_cast<int>(std::lround(
                normalized_y * static_cast<double>(display_height - 1))),
            0, display_height - 1);

        int source_x = display_x;
        int source_y = display_y;
        switch (rotation) {
            case 90:
                source_x = display_y;
                source_y = source_height - 1 - display_x;
                break;
            case 180:
                source_x = source_width - 1 - display_x;
                source_y = source_height - 1 - display_y;
                break;
            case 270:
                source_x = source_width - 1 - display_y;
                source_y = display_x;
                break;
            default:
                break;
        }
        source_x = std::clamp(source_x, 0, source_width - 1);
        source_y = std::clamp(source_y, 0, source_height - 1);

        constexpr int kRadius = 2;
        std::vector<double> raw_values;
        std::vector<double> effective_values;
        std::vector<double> depths;
        std::vector<double> left_right_errors;
        std::size_t left_right_consistent_count = 0;
        for (int y = std::max(0, source_y - kRadius);
             y <= std::min(source_height - 1, source_y + kRadius);
             ++y) {
            const auto* disparity_row = disparity.ptr<float>(y);
            const auto* mask_row = mask.ptr<std::uint8_t>(y);
            const auto* lr_mask_row = left_right_mask.empty()
                ? nullptr
                : left_right_mask.ptr<std::uint8_t>(y);
            const auto* lr_error_row = left_right_error.empty()
                ? nullptr
                : left_right_error.ptr<float>(y);
            for (int x = std::max(0, source_x - kRadius);
                 x <= std::min(source_width - 1, source_x + kRadius);
                 ++x) {
                if (mask_row[x] == 0) continue;
                const double raw = disparity_row[x];
                const double effective =
                    effective_disparity(raw, zero_offset_px);
                if (!std::isfinite(raw) || !std::isfinite(effective) ||
                    effective <= kMinimumDisparity) continue;
                const double meters =
                    focal_px * baseline_mm / effective / 1000.0;
                if (!std::isfinite(meters) || meters < 0.2 || meters > 20.0) {
                    continue;
                }
                raw_values.push_back(raw);
                effective_values.push_back(effective);
                depths.push_back(meters);
                if (lr_mask_row != nullptr && lr_mask_row[x] != 0) {
                    ++left_right_consistent_count;
                }
                if (lr_error_row != nullptr &&
                    std::isfinite(lr_error_row[x])) {
                    left_right_errors.push_back(lr_error_row[x]);
                }
            }
        }

        result["display_x_px"] = display_x;
        result["display_y_px"] = display_y;
        result["source_x_px"] = source_x;
        result["source_y_px"] = source_y;
        result["source_width"] = source_width;
        result["source_height"] = source_height;
        result["sample_count"] = depths.size();
        result["left_right_consistent_count"] =
            left_right_consistent_count;
        if (depths.empty()) {
            result["reason"] = "NO_VALID_DEPTH_IN_5X5_WINDOW";
            return result;
        }

        std::sort(raw_values.begin(), raw_values.end());
        std::sort(effective_values.begin(), effective_values.end());
        std::sort(depths.begin(), depths.end());
        std::sort(left_right_errors.begin(), left_right_errors.end());

        const double raw_median = raw_values[raw_values.size() / 2];
        const double effective_median =
            effective_values[effective_values.size() / 2];
        const double disparity_spread =
            effective_values.back() - effective_values.front();
        const double lr_ratio = static_cast<double>(
            left_right_consistent_count) /
            static_cast<double>(depths.size());
        const bool enough_samples = depths.size() >= 5;
        const bool coherent = disparity_spread <=
            std::max(2.5, effective_median * 0.08);
        const bool consistent = lr_ratio >= 0.50;
        const bool reliable = enough_samples && coherent && consistent;
        const bool high_confidence =
            depths.size() >= 9 && lr_ratio >= 0.75 &&
            disparity_spread <= 1.5;

        result["valid"] = true;
        result["reason"] = reliable
            ? "NONE"
            : "AMBIGUOUS_LOCAL_DISPARITY";
        result["measurement_reliable"] = reliable;
        result["measurement_confidence"] = high_confidence
            ? "HIGH"
            : (reliable ? "MEDIUM" : "LOW");
        result["distance_m"] = depths[depths.size() / 2];
        result["minimum_m"] = depths.front();
        result["maximum_m"] = depths.back();
        result["spread_m"] = depths.back() - depths.front();
        result["raw_disparity_px"] = raw_median;
        result["effective_disparity_px"] = effective_median;
        result["minimum_disparity_px"] = effective_values.front();
        result["maximum_disparity_px"] = effective_values.back();
        result["disparity_spread_px"] = disparity_spread;
        result["left_right_consistency_ratio"] = lr_ratio;
        result["left_right_error_median_px"] =
            left_right_errors.empty()
                ? nlohmann::json(nullptr)
                : nlohmann::json(
                      left_right_errors[left_right_errors.size() / 2]);
        return result;
    }
};

}  // namespace maklertour::dual_phone::detail::metric_depth_accuracy
