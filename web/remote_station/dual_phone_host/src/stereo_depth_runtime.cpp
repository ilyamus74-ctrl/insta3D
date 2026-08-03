#include "stereo_depth_runtime.hpp"

#include <algorithm>
#include <array>
#include <cmath>
#include <cctype>
#include <deque>
#include <mutex>
#include <stdexcept>
#include <utility>
#include <vector>

#include <opencv2/calib3d.hpp>
#include <opencv2/imgproc.hpp>

namespace maklertour::dual_phone::detail {

namespace {

constexpr std::size_t kMaxProcessingSamples = 30;
constexpr std::size_t kMinimumDecisionSamples = 12;
constexpr int kInitialWarmupSamples = 12;
constexpr int kTransitionWarmupSamples = 6;
constexpr int kDowngradeWindows = 3;
constexpr int kUpgradeWindows = 12;
constexpr int kMaxAdaptiveLevel = 4;
constexpr int kTemporalWindow = 5;
constexpr double kMinimumDisparity = 1.0;
constexpr double kNearMeters = 0.5;
constexpr double kFarMeters = 6.0;

struct Profile {
    const char* name;
    int width;
    int height;
    int interval_ms;
    bool left_right;
    bool allow_upscale;
};

constexpr std::array<Profile, 6> kProfiles{{
    {"ULTRA_960", 960, 540, 400, true, false},
    {"HIGH_640", 640, 360, 250, true, false},
    {"QUALITY_480", 480, 270, 200, true, false},
    {"BALANCED_320", 320, 240, 200, true, false},
    {"THROTTLED_320", 320, 240, 333, false, false},
    {"FHD_1920", 1920, 1080, 1000, true, true},
}};

const Profile& profile_by_name(const std::string& name) {
    const auto match = std::find_if(
        kProfiles.begin(), kProfiles.end(),
        [&name](const Profile& value) { return name == value.name; });
    if (match == kProfiles.end()) {
        throw std::runtime_error("unknown depth profile: " + name);
    }
    return *match;
}

std::string normalize_mode(std::string value) {
    std::transform(value.begin(), value.end(), value.begin(), [](unsigned char ch) {
        return static_cast<char>(std::toupper(ch));
    });
    if (value == "U960") value = "ULTRA_960";
    if (value == "H640") value = "HIGH_640";
    if (value == "Q480") value = "QUALITY_480";
    if (value == "B320") value = "BALANCED_320";
    if (value == "FHD" || value == "FHD1920") value = "FHD_1920";
    return value;
}

int profile_level_for_mode(const std::string& mode) {
    if (mode == "ULTRA_960") return 0;
    if (mode == "HIGH_640") return 1;
    if (mode == "QUALITY_480") return 2;
    if (mode == "BALANCED_320") return 3;
    if (mode == "FHD_1920") return 5;
    if (mode == "AUTO") return -1;
    throw std::runtime_error("depth mode must be AUTO, FHD_1920, ULTRA_960, HIGH_640, QUALITY_480 or BALANCED_320");
}

double percentile(std::deque<double> values, const double fraction) {
    if (values.empty()) return 0.0;
    std::sort(values.begin(), values.end());
    const auto index = static_cast<std::size_t>(
        std::clamp(
            fraction * static_cast<double>(values.size() - 1),
            0.0,
            static_cast<double>(values.size() - 1)));
    return values[index];
}

cv::Size fit_size(const cv::Size source,
                  const Profile& profile,
                  const bool vertical,
                  bool& upscaled) {
    const int max_width = vertical ? profile.height : profile.width;
    const int max_height = vertical ? profile.width : profile.height;
    if (source.width <= 0 || source.height <= 0) {
        throw std::runtime_error("metric depth source size is empty");
    }
    auto scale = std::min(
        static_cast<double>(max_width) / static_cast<double>(source.width),
        static_cast<double>(max_height) / static_cast<double>(source.height));
    if (!profile.allow_upscale) scale = std::min(1.0, scale);
    upscaled = scale > 1.0001;
    return {
        std::max(64, static_cast<int>(std::lround(source.width * scale))),
        std::max(48, static_cast<int>(std::lround(source.height * scale))),
    };
}

int choose_num_disparities(const int width) {
    const int maximum = std::max(16, ((width / 4) / 16) * 16);
    return std::min(64, maximum);
}

cv::Ptr<cv::StereoSGBM> create_matcher(const int min_disparity,
                                       const int num_disparities) {
    constexpr int block_size = 5;
    return cv::StereoSGBM::create(
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
}

double mask_ratio(const cv::Mat& mask) {
    const auto total = static_cast<double>(mask.total());
    return total > 0.0
        ? static_cast<double>(cv::countNonZero(mask)) / total
        : 0.0;
}

cv::Mat disparity_colour(const cv::Mat& disparity,
                         const cv::Mat& valid,
                         const int num_disparities) {
    cv::Mat normalized;
    disparity.convertTo(
        normalized,
        CV_8U,
        255.0 / static_cast<double>(std::max(1, num_disparities)));
    cv::Mat colour;
    cv::applyColorMap(normalized, colour, cv::COLORMAP_TURBO);
    cv::Mat invalid;
    cv::bitwise_not(valid, invalid);
    colour.setTo(cv::Scalar(0, 0, 0), invalid);
    return colour;
}

struct MetricStop {
    double meters;
    cv::Vec3b bgr;
};

const std::array<MetricStop, 6> kMetricStops{{
    {0.5, {0, 0, 255}},
    {1.0, {0, 165, 255}},
    {2.0, {0, 255, 255}},
    {3.0, {0, 255, 0}},
    {4.0, {255, 255, 0}},
    {6.0, {255, 0, 0}},
}};

cv::Vec3b metric_colour(const double meters) {
    const double value = std::clamp(meters, kNearMeters, kFarMeters);
    std::size_t upper = 1;
    while (upper < kMetricStops.size() && value > kMetricStops[upper].meters) {
        ++upper;
    }
    upper = std::min(upper, kMetricStops.size() - 1);
    const auto lower = upper - 1;
    const auto& from = kMetricStops[lower];
    const auto& to = kMetricStops[upper];
    const double ratio = std::clamp(
        (value - from.meters) / std::max(0.0001, to.meters - from.meters),
        0.0,
        1.0);
    cv::Vec3b result;
    for (int channel = 0; channel < 3; ++channel) {
        result[channel] = static_cast<std::uint8_t>(std::lround(
            static_cast<double>(from.bgr[channel]) +
            (static_cast<double>(to.bgr[channel]) -
             static_cast<double>(from.bgr[channel])) * ratio));
    }
    return result;
}

cv::Mat metric_heatmap(const cv::Mat& disparity,
                       const cv::Mat& mask,
                       const double focal_px,
                       const double baseline_mm) {
    cv::Mat output(disparity.size(), CV_8UC3, cv::Scalar(0, 0, 0));
    for (int row = 0; row < disparity.rows; ++row) {
        const auto* disparity_row = disparity.ptr<float>(row);
        const auto* mask_row = mask.ptr<std::uint8_t>(row);
        auto* output_row = output.ptr<cv::Vec3b>(row);
        for (int column = 0; column < disparity.cols; ++column) {
            if (mask_row[column] == 0) continue;
            const double d = disparity_row[column];
            if (d <= kMinimumDisparity) continue;
            const double meters = focal_px * baseline_mm / d / 1000.0;
            if (std::isfinite(meters)) output_row[column] = metric_colour(meters);
        }
    }
    return output;
}

std::optional<double> median_depth(const cv::Mat& disparity,
                                   const cv::Mat& mask,
                                   const double focal_px,
                                   const double baseline_mm) {
    std::vector<double> depths;
    depths.reserve(disparity.total() / 4);
    for (int row = 0; row < disparity.rows; row += 2) {
        const auto* disparity_row = disparity.ptr<float>(row);
        const auto* mask_row = mask.ptr<std::uint8_t>(row);
        for (int column = 0; column < disparity.cols; column += 2) {
            if (mask_row[column] == 0) continue;
            const double d = disparity_row[column];
            if (d <= kMinimumDisparity) continue;
            const double meters = focal_px * baseline_mm / d / 1000.0;
            if (meters >= 0.2 && meters <= 20.0 && std::isfinite(meters)) {
                depths.push_back(meters);
            }
        }
    }
    if (depths.empty()) return std::nullopt;
    const auto middle = depths.begin() + static_cast<std::ptrdiff_t>(depths.size() / 2);
    std::nth_element(depths.begin(), middle, depths.end());
    return *middle;
}

void left_right_mask(const cv::Mat& left,
                     const cv::Mat& right,
                     cv::Mat& output,
                     const float tolerance) {
    output = cv::Mat::zeros(left.size(), CV_8U);
    for (int row = 0; row < left.rows; ++row) {
        const auto* left_row = left.ptr<float>(row);
        const auto* right_row = right.ptr<float>(row);
        auto* mask_row = output.ptr<std::uint8_t>(row);
        for (int column = 0; column < left.cols; ++column) {
            const float value = left_row[column];
            if (value <= static_cast<float>(kMinimumDisparity)) continue;
            const int right_column = static_cast<int>(std::lround(
                static_cast<double>(column) - static_cast<double>(value)));
            if (right_column < 0 || right_column >= right.cols) continue;
            const float reverse = right_row[right_column];
            if (reverse < -static_cast<float>(kMinimumDisparity) &&
                std::abs(value + reverse) <= tolerance) {
                mask_row[column] = 255;
            }
        }
    }
}

}  // namespace

struct StereoDepthRuntime::Impl {
    mutable std::mutex mutex;
    std::string selected_mode = "HIGH_640";
    int adaptive_level = 0;
    int warmup_remaining = kInitialWarmupSamples;
    int slow_windows = 0;
    int fast_windows = 0;
    std::uint64_t revision = 1;
    std::deque<double> durations_ms;
    std::chrono::steady_clock::time_point last_started;
    std::uint64_t budget_skipped = 0;
    std::string active_profile = "HIGH_640";
    double processing_p50_ms = 0.0;
    double processing_p95_ms = 0.0;
    double last_processing_ms = 0.0;
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
    double focal_px = 0.0;
    double baseline_mm = 0.0;
    std::uint64_t engine_revision = 0;
    cv::Mat previous_motion;
    std::deque<cv::Mat> temporal_disparities;
    std::deque<double> median_history;

    const Profile& current_profile_locked() const {
        if (selected_mode == "AUTO") {
            return kProfiles[static_cast<std::size_t>(adaptive_level)];
        }
        return profile_by_name(selected_mode);
    }

    void clear_temporal() {
        temporal_disparities.clear();
        median_history.clear();
        previous_motion.release();
    }

    void reset_decision_locked(const int warmup) {
        durations_ms.clear();
        warmup_remaining = warmup;
        slow_windows = 0;
        fast_windows = 0;
        processing_p50_ms = 0.0;
        processing_p95_ms = 0.0;
    }

    void record_processing(const double duration_ms) {
        std::scoped_lock lock(mutex);
        last_processing_ms = duration_ms;
        if (warmup_remaining > 0) {
            --warmup_remaining;
            return;
        }
        durations_ms.push_back(std::max(0.0, duration_ms));
        while (durations_ms.size() > kMaxProcessingSamples) durations_ms.pop_front();
        processing_p50_ms = percentile(durations_ms, 0.50);
        processing_p95_ms = percentile(durations_ms, 0.95);
        if (selected_mode != "AUTO" || durations_ms.size() < kMinimumDecisionSamples) {
            return;
        }
        const std::array<double, 4> downgrade{{340.0, 190.0, 150.0, 175.0}};
        const std::array<double, 4> upgrade{{170.0, 95.0, 110.0, 135.0}};
        if (adaptive_level < 4 &&
            processing_p95_ms > downgrade[static_cast<std::size_t>(adaptive_level)]) {
            ++slow_windows;
        } else {
            slow_windows = 0;
        }
        if (adaptive_level > 0 &&
            processing_p95_ms <= upgrade[static_cast<std::size_t>(adaptive_level - 1)]) {
            ++fast_windows;
        } else {
            fast_windows = 0;
        }
        if (slow_windows >= kDowngradeWindows && adaptive_level < kMaxAdaptiveLevel) {
            ++adaptive_level;
            ++revision;
            reset_decision_locked(kTransitionWarmupSamples);
        } else if (fast_windows >= kUpgradeWindows && adaptive_level > 0) {
            --adaptive_level;
            ++revision;
            reset_decision_locked(kTransitionWarmupSamples);
        }
    }

    double motion_score(const cv::Mat& normalized) {
        cv::Mat reduced;
        cv::resize(normalized, reduced, {80, 60}, 0.0, 0.0, cv::INTER_AREA);
        double score = 0.0;
        if (!previous_motion.empty() && previous_motion.size() == reduced.size()) {
            cv::Mat difference;
            cv::absdiff(previous_motion, reduced, difference);
            score = cv::mean(difference)[0] * 100.0 / 255.0;
        }
        previous_motion = reduced.clone();
        return score;
    }

    std::pair<cv::Mat, cv::Mat> temporal_filter(const cv::Mat& current,
                                                 const cv::Mat& current_mask,
                                                 const std::string& mode) {
        if (mode == "RESET") temporal_disparities.clear();
        if (mode == "MOVING") {
            while (temporal_disparities.size() > 1) temporal_disparities.pop_front();
        }
        cv::Mat stored = current.clone();
        cv::Mat invalid;
        cv::bitwise_not(current_mask, invalid);
        stored.setTo(cv::Scalar(0.0), invalid);
        temporal_disparities.push_back(std::move(stored));
        while (temporal_disparities.size() > kTemporalWindow) {
            temporal_disparities.pop_front();
        }
        const int required = mode == "RESET"
            ? 1
            : (mode == "MOVING"
                ? std::min(2, static_cast<int>(temporal_disparities.size()))
                : (temporal_disparities.size() < 3
                    ? static_cast<int>(temporal_disparities.size())
                    : 3));
        cv::Mat stable = cv::Mat::zeros(current.size(), CV_32F);
        cv::Mat mask = cv::Mat::zeros(current.size(), CV_8U);
        std::array<float, kTemporalWindow> values{};
        for (int row = 0; row < current.rows; ++row) {
            auto* stable_row = stable.ptr<float>(row);
            auto* mask_row = mask.ptr<std::uint8_t>(row);
            for (int column = 0; column < current.cols; ++column) {
                std::size_t count = 0;
                for (const auto& frame : temporal_disparities) {
                    const float value = frame.at<float>(row, column);
                    if (value <= static_cast<float>(kMinimumDisparity)) continue;
                    if (count >= values.size()) break;

                    // The history is bounded to five frames. Keep valid values
                    // ordered during insertion. This avoids GCC 14 expanding
                    // std::sort through its 16-element small-range path and
                    // reporting false -Warray-bounds warnings for this array.
                    std::size_t insertion = count;
                    while (insertion > 0 && values[insertion - 1] > value) {
                        values[insertion] = values[insertion - 1];
                        --insertion;
                    }
                    values[insertion] = value;
                    ++count;
                }
                if (count < static_cast<std::size_t>(required) || count == 0) continue;
                const float median = values[count / 2];
                const float spread = values[count - 1] - values[0];
                const float allowed = std::max(1.5F, median * 0.10F);
                if (mode == "RESET" || spread <= allowed) {
                    stable_row[column] = median;
                    mask_row[column] = 255;
                }
            }
        }
        return {std::move(stable), std::move(mask)};
    }
};

StereoDepthRuntime::StereoDepthRuntime()
    : impl_(std::make_unique<Impl>()) {}
StereoDepthRuntime::~StereoDepthRuntime() = default;

std::optional<StereoDepthBudget> StereoDepthRuntime::acquire_budget() {
    std::scoped_lock lock(impl_->mutex);
    const auto& profile = impl_->current_profile_locked();
    const auto now = std::chrono::steady_clock::now();
    if (impl_->last_started.time_since_epoch().count() != 0 &&
        now - impl_->last_started < std::chrono::milliseconds(profile.interval_ms)) {
        ++impl_->budget_skipped;
        return std::nullopt;
    }
    impl_->last_started = now;
    impl_->active_profile = profile.name;
    return StereoDepthBudget{
        impl_->selected_mode,
        profile.name,
        profile.width,
        profile.height,
        profile.interval_ms,
        profile.left_right,
        profile.allow_upscale,
        1000.0 / static_cast<double>(profile.interval_ms),
        impl_->revision,
    };
}

StereoDepthResult StereoDepthRuntime::process(
    const cv::Mat& rectified_a,
    const cv::Mat& rectified_b,
    const bool vertical_rectification,
    const double rectified_focal_px,
    const double baseline_mm,
    const StereoDepthBudget& budget) {
    const auto started = std::chrono::steady_clock::now();
    if (rectified_a.empty() || rectified_b.empty() ||
        rectified_a.size() != rectified_b.size()) {
        throw std::runtime_error("metric depth requires equal non-empty rectified frames");
    }
    if (!std::isfinite(rectified_focal_px) || rectified_focal_px <= 1.0 ||
        !std::isfinite(baseline_mm) || baseline_mm <= 0.0) {
        throw std::runtime_error("metric depth requires finite focal length and baseline");
    }
    const auto& profile = profile_by_name(budget.profile_name);
    bool upscaled = false;
    const auto work_size = fit_size(
        rectified_a.size(), profile, vertical_rectification, upscaled);
    cv::Mat work_a;
    cv::Mat work_b;
    cv::resize(rectified_a, work_a, work_size, 0.0, 0.0,
               upscaled ? cv::INTER_CUBIC : cv::INTER_AREA);
    cv::resize(rectified_b, work_b, work_size, 0.0, 0.0,
               upscaled ? cv::INTER_CUBIC : cv::INTER_AREA);
    const double focal_px = rectified_focal_px *
        static_cast<double>(work_a.cols) /
        static_cast<double>(rectified_a.cols);

    if (impl_->engine_revision != budget.revision) {
        impl_->clear_temporal();
        impl_->engine_revision = budget.revision;
    }

    cv::Mat gray_a;
    cv::Mat gray_b;
    cv::cvtColor(work_a, gray_a, cv::COLOR_BGR2GRAY);
    cv::cvtColor(work_b, gray_b, cv::COLOR_BGR2GRAY);
    auto clahe = cv::createCLAHE(2.0, {8, 8});
    cv::Mat normalized_a;
    cv::Mat normalized_b;
    clahe->apply(gray_a, normalized_a);
    clahe->apply(gray_b, normalized_b);

    const double motion = impl_->motion_score(normalized_a);
    const std::string temporal_mode = motion >= 8.0
        ? "RESET"
        : (motion >= 2.5 ? "MOVING" : "STATIC");
    const int num_disparities = choose_num_disparities(work_a.cols);
    auto matcher = create_matcher(0, num_disparities);
    cv::Mat disparity_16;
    matcher->compute(normalized_a, normalized_b, disparity_16);
    cv::Mat disparity;
    disparity_16.convertTo(disparity, CV_32F, 1.0 / 16.0);

    cv::Mat minimum_mask;
    cv::Mat maximum_mask;
    cv::Mat raw_mask;
    cv::compare(disparity, cv::Scalar(kMinimumDisparity), minimum_mask, cv::CMP_GT);
    cv::compare(disparity, cv::Scalar(num_disparities - 1), maximum_mask, cv::CMP_LT);
    cv::bitwise_and(minimum_mask, maximum_mask, raw_mask);

    cv::Mat consistent_mask;
    double left_right_accepted = 100.0;
    if (budget.enable_left_right_check) {
        auto reverse_matcher = create_matcher(-num_disparities, num_disparities);
        cv::Mat reverse_16;
        cv::Mat reverse;
        reverse_matcher->compute(normalized_b, normalized_a, reverse_16);
        reverse_16.convertTo(reverse, CV_32F, 1.0 / 16.0);
        cv::Mat lr;
        left_right_mask(disparity, reverse, lr, 3.0F);
        cv::bitwise_and(raw_mask, lr, consistent_mask);
        const int raw_count = cv::countNonZero(raw_mask);
        left_right_accepted = raw_count > 0
            ? 100.0 * static_cast<double>(cv::countNonZero(consistent_mask)) /
                static_cast<double>(raw_count)
            : 0.0;
    } else {
        consistent_mask = raw_mask.clone();
    }

    cv::Mat spatial;
    cv::medianBlur(disparity, spatial, 5);
    cv::Mat gradient_x_16;
    cv::Mat gradient_y_16;
    cv::Mat gradient_x;
    cv::Mat gradient_y;
    cv::Mat texture;
    cv::Sobel(normalized_a, gradient_x_16, CV_16S, 1, 0, 3);
    cv::Sobel(normalized_a, gradient_y_16, CV_16S, 0, 1, 3);
    cv::convertScaleAbs(gradient_x_16, gradient_x);
    cv::convertScaleAbs(gradient_y_16, gradient_y);
    cv::addWeighted(gradient_x, 0.5, gradient_y, 0.5, 0.0, texture);
    cv::Mat dense_texture;
    cv::Mat strict_texture;
    cv::Mat high_texture;
    cv::compare(texture, cv::Scalar(5), dense_texture, cv::CMP_GT);
    cv::compare(texture, cv::Scalar(12), strict_texture, cv::CMP_GT);
    cv::compare(texture, cv::Scalar(30), high_texture, cv::CMP_GT);

    cv::Mat dense_mask;
    cv::Mat strict_mask;
    cv::bitwise_and(consistent_mask, dense_texture, dense_mask);
    cv::bitwise_and(consistent_mask, strict_texture, strict_mask);
    const auto kernel = cv::getStructuringElement(cv::MORPH_ELLIPSE, {3, 3});
    cv::Mat dense_closed;
    cv::Mat strict_opened;
    cv::Mat strict_closed;
    cv::morphologyEx(dense_mask, dense_closed, cv::MORPH_CLOSE, kernel);
    cv::morphologyEx(strict_mask, strict_opened, cv::MORPH_OPEN, kernel);
    cv::morphologyEx(strict_opened, strict_closed, cv::MORPH_CLOSE, kernel);

    cv::Mat dense_disparity = spatial.clone();
    cv::Mat dense_invalid;
    cv::bitwise_not(dense_closed, dense_invalid);
    dense_disparity.setTo(cv::Scalar(0.0), dense_invalid);
    cv::Mat strict_disparity = spatial.clone();
    cv::Mat strict_invalid;
    cv::bitwise_not(strict_closed, strict_invalid);
    strict_disparity.setTo(cv::Scalar(0.0), strict_invalid);

    auto [stable_disparity, stable_mask] = impl_->temporal_filter(
        strict_disparity, strict_closed, temporal_mode);
    cv::Mat high_confidence;
    cv::bitwise_and(stable_mask, high_texture, high_confidence);

    cv::Mat confidence(work_a.size(), CV_8UC3, cv::Scalar(0, 0, 0));
    confidence.setTo(cv::Scalar(0, 0, 255), raw_mask);
    confidence.setTo(cv::Scalar(0, 165, 255), dense_closed);
    confidence.setTo(cv::Scalar(0, 255, 0), high_confidence);

    const auto median = median_depth(
        stable_disparity, stable_mask, focal_px, baseline_mm);
    std::optional<double> jitter;
    if (median) {
        impl_->median_history.push_back(*median);
        while (impl_->median_history.size() > kTemporalWindow) {
            impl_->median_history.pop_front();
        }
        const auto [minimum, maximum] = std::minmax_element(
            impl_->median_history.begin(), impl_->median_history.end());
        jitter = *maximum - *minimum;
    }

    const int consistent_count = cv::countNonZero(consistent_mask);
    const int dense_texture_count = cv::countNonZero(dense_mask);
    const int dense_closed_count = cv::countNonZero(dense_closed);
    StereoDepthResult result;
    result.work_a = std::move(work_a);
    result.work_b = std::move(work_b);
    result.disparity_preview = disparity_colour(disparity, raw_mask, num_disparities);
    result.raw_depth_preview = metric_heatmap(disparity, raw_mask, focal_px, baseline_mm);
    result.filtered_depth_preview = metric_heatmap(
        dense_disparity, dense_closed, focal_px, baseline_mm);
    result.strict_depth_preview = metric_heatmap(
        stable_disparity, stable_mask, focal_px, baseline_mm);
    result.confidence_preview = std::move(confidence);
    result.geometry_disparity = std::move(dense_disparity);
    result.geometry_mask = std::move(dense_closed);
    result.work_width = result.work_a.cols;
    result.work_height = result.work_a.rows;
    result.source_upscaled = upscaled;
    result.raw_valid_ratio = mask_ratio(raw_mask);
    result.filtered_valid_ratio = mask_ratio(strict_closed);
    result.dense_coverage_ratio = mask_ratio(dense_closed);
    result.stable_coverage_ratio = mask_ratio(stable_mask);
    result.high_confidence_ratio = mask_ratio(high_confidence);
    result.median_depth_m = median;
    result.depth_jitter_m = jitter;
    result.motion_score_percent = motion;
    result.temporal_mode = temporal_mode;
    result.left_right_accepted_percent = left_right_accepted;
    result.texture_accepted_percent = consistent_count > 0
        ? 100.0 * static_cast<double>(dense_texture_count) /
            static_cast<double>(consistent_count)
        : 0.0;
    result.morphology_accepted_percent = dense_texture_count > 0
        ? std::min(
            100.0,
            100.0 * static_cast<double>(dense_closed_count) /
                static_cast<double>(dense_texture_count))
        : 0.0;
    result.min_disparity = 0;
    result.num_disparities = num_disparities;
    result.focal_px = focal_px;
    result.baseline_mm = baseline_mm;
    result.principal_x_px =
        (static_cast<double>(result.work_width) - 1.0) * 0.5;
    result.principal_y_px =
        (static_cast<double>(result.work_height) - 1.0) * 0.5;
    result.processing_ms = std::chrono::duration<double, std::milli>(
        std::chrono::steady_clock::now() - started).count();

    impl_->record_processing(result.processing_ms);
    {
        std::scoped_lock lock(impl_->mutex);
        impl_->work_width = result.work_width;
        impl_->work_height = result.work_height;
        impl_->source_upscaled = result.source_upscaled;
        impl_->raw_valid_ratio = result.raw_valid_ratio;
        impl_->filtered_valid_ratio = result.filtered_valid_ratio;
        impl_->dense_coverage_ratio = result.dense_coverage_ratio;
        impl_->stable_coverage_ratio = result.stable_coverage_ratio;
        impl_->high_confidence_ratio = result.high_confidence_ratio;
        impl_->median_depth_m = result.median_depth_m;
        impl_->depth_jitter_m = result.depth_jitter_m;
        impl_->motion_score_percent = result.motion_score_percent;
        impl_->temporal_mode = result.temporal_mode;
        impl_->left_right_accepted_percent = result.left_right_accepted_percent;
        impl_->texture_accepted_percent = result.texture_accepted_percent;
        impl_->morphology_accepted_percent = result.morphology_accepted_percent;
        impl_->focal_px = result.focal_px;
        impl_->baseline_mm = result.baseline_mm;
    }
    return result;
}

nlohmann::json StereoDepthRuntime::select_mode(const std::string& raw_mode) {
    const auto mode = normalize_mode(raw_mode);
    profile_level_for_mode(mode);
    {
        std::scoped_lock lock(impl_->mutex);
        if (impl_->selected_mode != mode) {
            impl_->selected_mode = mode;
            impl_->adaptive_level = 0;
            ++impl_->revision;
            impl_->reset_decision_locked(kInitialWarmupSamples);
            impl_->last_started = {};
        }
    }
    return status_json();
}

nlohmann::json StereoDepthRuntime::profiles_json() const {
    nlohmann::json profiles = nlohmann::json::array();
    profiles.push_back({
        {"mode", "AUTO"},
        {"title", "AUTO"},
        {"description", "Android-compatible adaptive p95 fallback"},
    });
    for (const auto& profile : kProfiles) {
        if (std::string(profile.name) == "THROTTLED_320") continue;
        profiles.push_back({
            {"mode", profile.name},
            {"title", profile.name},
            {"work_width", profile.width},
            {"work_height", profile.height},
            {"target_depth_fps", 1000.0 / static_cast<double>(profile.interval_ms)},
            {"left_right_check", profile.left_right},
            {"allow_upscale", profile.allow_upscale},
            {"experimental", std::string(profile.name) == "FHD_1920"},
        });
    }
    return profiles;
}

nlohmann::json StereoDepthRuntime::status_json() const {
    std::scoped_lock lock(impl_->mutex);
    const auto& profile = impl_->current_profile_locked();
    nlohmann::json result = {
        {"selection_mode", impl_->selected_mode},
        {"active_profile", profile.name},
        {"adaptive_level", impl_->adaptive_level},
        {"work_width", impl_->work_width},
        {"work_height", impl_->work_height},
        {"profile_width", profile.width},
        {"profile_height", profile.height},
        {"target_depth_fps", 1000.0 / static_cast<double>(profile.interval_ms)},
        {"min_processing_interval_ms", profile.interval_ms},
        {"enable_left_right_check", profile.left_right},
        {"allow_upscale", profile.allow_upscale},
        {"source_upscaled", impl_->source_upscaled},
        {"budget_skipped_pairs", impl_->budget_skipped},
        {"processing_p50_ms", impl_->processing_p50_ms},
        {"processing_p95_ms", impl_->processing_p95_ms},
        {"last_depth_processing_ms", impl_->last_processing_ms},
        {"processing_utilization_percent",
         profile.interval_ms > 0
             ? impl_->last_processing_ms * 100.0 /
                 static_cast<double>(profile.interval_ms)
             : 0.0},
        {"thermal_state", "UNSUPPORTED"},
        {"raw_valid_ratio", impl_->raw_valid_ratio},
        {"filtered_valid_ratio", impl_->filtered_valid_ratio},
        {"dense_coverage_ratio", impl_->dense_coverage_ratio},
        {"stable_coverage_ratio", impl_->stable_coverage_ratio},
        {"high_confidence_ratio", impl_->high_confidence_ratio},
        {"motion_score_percent", impl_->motion_score_percent},
        {"temporal_mode", impl_->temporal_mode},
        {"left_right_accepted_percent", impl_->left_right_accepted_percent},
        {"texture_accepted_percent", impl_->texture_accepted_percent},
        {"morphology_accepted_percent", impl_->morphology_accepted_percent},
        {"focal_px", impl_->focal_px},
        {"baseline_mm", impl_->baseline_mm},
        {"revision", impl_->revision},
    };
    result["median_depth_m"] = impl_->median_depth_m
        ? nlohmann::json(*impl_->median_depth_m)
        : nlohmann::json(nullptr);
    result["depth_jitter_m"] = impl_->depth_jitter_m
        ? nlohmann::json(*impl_->depth_jitter_m)
        : nlohmann::json(nullptr);
    return result;
}

void StereoDepthRuntime::reset_geometry() {
    std::scoped_lock lock(impl_->mutex);
    ++impl_->revision;
    impl_->last_started = {};
    impl_->work_width = 0;
    impl_->work_height = 0;
    impl_->source_upscaled = false;
    impl_->raw_valid_ratio = 0.0;
    impl_->filtered_valid_ratio = 0.0;
    impl_->dense_coverage_ratio = 0.0;
    impl_->stable_coverage_ratio = 0.0;
    impl_->high_confidence_ratio = 0.0;
    impl_->median_depth_m.reset();
    impl_->depth_jitter_m.reset();
    impl_->motion_score_percent = 0.0;
    impl_->temporal_mode = "WAITING";
}

}  // namespace maklertour::dual_phone::detail
