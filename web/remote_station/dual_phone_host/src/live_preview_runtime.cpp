#include "live_preview_runtime.hpp"

#include "operator_preview_state.hpp"

#include <algorithm>
#include <array>
#include <chrono>
#include <cmath>
#include <cctype>
#include <condition_variable>
#include <cstdint>
#include <deque>
#include <fstream>
#include <iomanip>
#include <mutex>
#include <stdexcept>
#include <string>
#include <system_error>
#include <thread>
#include <utility>

#include <opencv2/calib3d.hpp>
#include <opencv2/imgproc.hpp>

namespace maklertour::dual_phone::detail {

namespace {

constexpr std::chrono::seconds kFpsWindow{5};
constexpr double kMinimumDisparity = 0.5;
constexpr double kNearMeters = 0.5;
constexpr double kFarMeters = 8.0;

struct LiveProfileSpec {
    const char* name;
    int portrait_width;
    int portrait_height;
    int interval_ms;
};

constexpr std::array<LiveProfileSpec, 5> kLiveProfiles{{
    {"FHD_1920", 1080, 1920, 1000},
    {"ULTRA_960", 540, 960, 400},
    {"HIGH_640", 360, 640, 200},
    {"QUALITY_480", 270, 480, 200},
    {"BALANCED_320", 180, 320, 200},
}};

std::string canonical_profile_mode(std::string value) {
    std::transform(
        value.begin(),
        value.end(),
        value.begin(),
        [](const unsigned char ch) {
            return static_cast<char>(std::toupper(ch));
        });
    if (value == "FHD" || value == "1920" || value == "1920X1080") {
        value = "FHD_1920";
    }
    if (value == "960") value = "ULTRA_960";
    if (value == "640") value = "HIGH_640";
    if (value == "480") value = "QUALITY_480";
    if (value == "320") value = "BALANCED_320";
    if (value == "THROTTLED_320") value = "BALANCED_320";
    if (value == "AUTO") return value;
    for (const auto& profile : kLiveProfiles) {
        if (value == profile.name) return value;
    }
    throw std::runtime_error(
        "live preview profile must be AUTO, FHD_1920, ULTRA_960, "
        "HIGH_640, QUALITY_480 or BALANCED_320");
}

const LiveProfileSpec& live_profile_spec(const std::string& requested_mode) {
    const auto resolved_mode =
        requested_mode == "AUTO" ? std::string("HIGH_640") : requested_mode;
    for (const auto& profile : kLiveProfiles) {
        if (resolved_mode == profile.name) return profile;
    }
    throw std::runtime_error("live preview profile is unavailable");
}

std::int64_t unix_time_ms() {
    return std::chrono::duration_cast<std::chrono::milliseconds>(
        std::chrono::system_clock::now().time_since_epoch()).count();
}

struct PendingLivePair {
    StereoPreviewPair pair;
    ResolvedCalibration calibration;
    std::int64_t received_unix_ms = 0;
};

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
    {8.0, {255, 0, 0}},
}};

void write_binary_atomic(const std::filesystem::path& destination,
                         const std::vector<std::uint8_t>& bytes) {
    auto temporary = destination;
    temporary += ".tmp";
    {
        std::ofstream output(temporary, std::ios::binary | std::ios::trunc);
        if (!output) {
            throw std::runtime_error("cannot write " + temporary.string());
        }
        output.write(
            reinterpret_cast<const char*>(bytes.data()),
            static_cast<std::streamsize>(bytes.size()));
        output.flush();
        if (!output) {
            throw std::runtime_error("cannot finish " + temporary.string());
        }
    }
    std::error_code error;
    std::filesystem::rename(temporary, destination, error);
    if (!error) return;
    std::filesystem::remove(destination, error);
    error.clear();
    std::filesystem::rename(temporary, destination, error);
    if (error) {
        std::filesystem::remove(temporary);
        throw std::runtime_error(
            "cannot publish " + destination.string() + ": " + error.message());
    }
}

bool projection_usable(const cv::Mat& value) {
    if (value.rows != 3 || value.cols != 4 || value.type() != CV_64F) {
        return false;
    }
    for (int row = 0; row < value.rows; ++row) {
        for (int column = 0; column < value.cols; ++column) {
            if (!std::isfinite(value.at<double>(row, column))) return false;
        }
    }
    return std::abs(value.at<double>(0, 0)) > 1.0 &&
           std::abs(value.at<double>(1, 1)) > 1.0;
}

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
        (value - from.meters) /
            std::max(0.0001, to.meters - from.meters),
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
            const double value = disparity_row[column];
            if (value <= kMinimumDisparity) continue;
            const double meters = focal_px * baseline_mm / value / 1000.0;
            if (std::isfinite(meters)) {
                output_row[column] = metric_colour(meters);
            }
        }
    }
    return output;
}

std::optional<double> median_depth(const cv::Mat& disparity,
                                   const cv::Mat& mask,
                                   const double focal_px,
                                   const double baseline_mm) {
    std::vector<double> values;
    values.reserve(disparity.total() / 8);
    for (int row = 0; row < disparity.rows; row += 2) {
        const auto* disparity_row = disparity.ptr<float>(row);
        const auto* mask_row = mask.ptr<std::uint8_t>(row);
        for (int column = 0; column < disparity.cols; column += 2) {
            if (mask_row[column] == 0) continue;
            const double value = disparity_row[column];
            if (value <= kMinimumDisparity) continue;
            const double meters = focal_px * baseline_mm / value / 1000.0;
            if (std::isfinite(meters) && meters >= 0.2 && meters <= 20.0) {
                values.push_back(meters);
            }
        }
    }
    if (values.empty()) return std::nullopt;
    const auto middle =
        values.begin() + static_cast<std::ptrdiff_t>(values.size() / 2);
    std::nth_element(values.begin(), middle, values.end());
    return *middle;
}

double mask_ratio(const cv::Mat& mask) {
    if (mask.empty() || mask.total() == 0) return 0.0;
    return static_cast<double>(cv::countNonZero(mask)) /
           static_cast<double>(mask.total());
}

int normalize_degrees(const int value) {
    return ((value % 360) + 360) % 360;
}

cv::Mat rotate_for_display(const cv::Mat& source, const int degrees) {
    cv::Mat result;
    switch (normalize_degrees(degrees)) {
        case 0:
            return source;
        case 90:
            cv::rotate(source, result, cv::ROTATE_90_CLOCKWISE);
            break;
        case 180:
            cv::rotate(source, result, cv::ROTATE_180);
            break;
        case 270:
            cv::rotate(source, result, cv::ROTATE_90_COUNTERCLOCKWISE);
            break;
        default:
            throw std::runtime_error("unsupported live display rotation");
    }
    return result;
}

int live_num_disparities(const int width) {
    const int desired = std::clamp(width / 3, 64, 128);
    return ((desired + 15) / 16) * 16;
}

cv::Ptr<cv::StereoSGBM> live_matcher(const int num_disparities) {
    constexpr int block_size = 5;
    auto matcher = cv::StereoSGBM::create(
        0,
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
    return matcher;
}

}  // namespace

struct LivePreviewRuntime::Impl {
    explicit Impl(std::filesystem::path session_path)
        : session_directory(std::move(session_path)),
          diagnostics(session_directory / "live_preview.jsonl", std::ios::app) {
        if (!diagnostics) {
            throw std::runtime_error("cannot create live preview diagnostics");
        }
        worker = std::thread([this] { worker_loop(); });
    }

    ~Impl() {
        {
            std::scoped_lock lock(mutex);
            stopping = true;
            pending.reset();
        }
        condition.notify_all();
        if (worker.joinable()) worker.join();
        std::ofstream(session_directory / "live_preview_status.json")
            << std::setw(2) << status_json() << '\n';
    }

    void submit(StereoPreviewPair pair, ResolvedCalibration calibration) {
        {
            std::scoped_lock lock(mutex);
            submitted_pairs += 1;
            if (pending) dropped_pending_pairs += 1;
            pending = PendingLivePair{
                std::move(pair),
                std::move(calibration),
                unix_time_ms(),
            };
        }
        condition.notify_one();
    }

    nlohmann::json select_profile(std::string raw_mode) {
        const auto requested = canonical_profile_mode(std::move(raw_mode));
        {
            std::scoped_lock lock(mutex);
            if (requested_profile_mode != requested) {
                requested_profile_mode = requested;
                profile_revision += 1;
                ready = false;
                work_width = 0;
                work_height = 0;
                actual_fps = 0.0;
                success_times.clear();
                last_error.clear();
            }
        }
        condition.notify_all();
        return status_json();
    }

    void reset() {
        {
            std::scoped_lock lock(mutex);
            pending.reset();
            selected_jpeg.clear();
            ready = false;
            pair_index = 0;
            work_width = 0;
            work_height = 0;
            actual_fps = 0.0;
            valid_ratio = 0.0;
            median_depth_m.reset();
            last_compute_ms = 0.0;
            last_encode_ms = 0.0;
            last_total_ms = 0.0;
            last_publish_unix_ms = 0;
            last_source_unix_ms = 0;
            last_source_sequence_a = 0;
            last_source_sequence_b = 0;
            input_replayed = false;
            last_error.clear();
            success_times.clear();
            reset_revision += 1;
        }
        condition.notify_all();
    }

    std::optional<std::vector<std::uint8_t>> image() const {
        std::scoped_lock lock(mutex);
        if (selected_jpeg.empty()) return std::nullopt;
        return selected_jpeg;
    }

    nlohmann::json status_json() const {
        std::scoped_lock lock(mutex);
        const auto& profile = live_profile_spec(requested_profile_mode);
        const auto now_ms = unix_time_ms();
        nlohmann::json result = {
            {"state", ready ? "READY" : (last_error.empty() ? "WAITING" : "ERROR")},
            {"ready", ready},
            {"selected_mode",
             operator_preview_mode_name(current_operator_preview_mode())},
            {"sequence", sequence},
            {"heartbeat_sequence",
             static_cast<std::uint64_t>(std::max<std::int64_t>(0, now_ms) / 200)},
            {"pair_index", pair_index},
            {"resolution_policy", "MATCH_PROFILE"},
            {"requested_profile", requested_profile_mode},
            {"active_profile", profile.name},
            {"profile_revision", profile_revision},
            {"target_fps",
             1000.0 / static_cast<double>(profile.interval_ms)},
            {"target_interval_ms", profile.interval_ms},
            {"profile_width", profile.portrait_width},
            {"profile_height", profile.portrait_height},
            {"actual_fps", actual_fps},
            {"work_width", work_width},
            {"work_height", work_height},
            {"compute_ms", last_compute_ms},
            {"jpeg_encode_ms", last_encode_ms},
            {"total_ms", last_total_ms},
            {"valid_ratio", valid_ratio},
            {"input_replayed", input_replayed},
            {"fresh_input_frames", fresh_input_frames},
            {"replayed_input_frames", replayed_input_frames},
            {"stale_profile_results", stale_profile_results},
            {"last_source_sequence_a", last_source_sequence_a},
            {"last_source_sequence_b", last_source_sequence_b},
            {"submitted_pairs", submitted_pairs},
            {"processed_pairs", processed_pairs},
            {"failed_pairs", failed_pairs},
            {"dropped_pending_pairs", dropped_pending_pairs},
            {"reset_revision", reset_revision},
            {"last_error", last_error},
        };
        result["publish_age_ms"] = last_publish_unix_ms > 0
            ? nlohmann::json(std::max<std::int64_t>(
                  0, now_ms - last_publish_unix_ms))
            : nlohmann::json(nullptr);
        result["source_age_ms"] = last_source_unix_ms > 0
            ? nlohmann::json(std::max<std::int64_t>(
                  0, now_ms - last_source_unix_ms))
            : nlohmann::json(nullptr);
        result["median_depth_m"] = median_depth_m
            ? nlohmann::json(*median_depth_m)
            : nlohmann::json(nullptr);
        return result;
    }

    void append_diagnostic(nlohmann::json value) {
        value["ts"] = std::chrono::duration_cast<std::chrono::milliseconds>(
            std::chrono::system_clock::now().time_since_epoch()).count();
        diagnostics << value.dump() << '\n';
        diagnostics.flush();
    }

    void worker_loop() {
        std::uint64_t map_revision = 0;
        cv::Size map_size;
        cv::Mat map_a_x;
        cv::Mat map_a_y;
        cv::Mat map_b_x;
        cv::Mat map_b_y;
        RectificationAxis cached_axis = RectificationAxis::Horizontal;
        double cached_projection_shift = 0.0;
        double cached_focal_px = 0.0;
        auto last_started = std::chrono::steady_clock::time_point{};
        auto last_disk_write = std::chrono::steady_clock::time_point{};
        std::optional<PendingLivePair> last_job;
        std::uint64_t observed_profile_revision = 0;
        std::uint64_t observed_reset_revision = 0;

        while (true) {
            PendingLivePair job;
            LiveProfileSpec live_profile{"HIGH_640", 360, 640, 200};
            std::uint64_t job_profile_revision = 0;
            bool replayed_input_for_job = false;
            {
                std::unique_lock lock(mutex);
                if (observed_reset_revision != reset_revision) {
                    last_job.reset();
                    map_revision = 0;
                    map_size = {};
                    last_started = {};
                    observed_reset_revision = reset_revision;
                }
                if (!pending && !last_job) {
                    condition.wait(lock, [this] {
                        return stopping || pending.has_value();
                    });
                }
                if (stopping) break;

                if (observed_profile_revision != profile_revision) {
                    last_started = {};
                    observed_profile_revision = profile_revision;
                }
                live_profile = live_profile_spec(requested_profile_mode);

                if (last_started.time_since_epoch().count() != 0) {
                    const auto due = last_started +
                        std::chrono::milliseconds(live_profile.interval_ms);
                    condition.wait_until(
                        lock,
                        due,
                        [this, observed_profile_revision, observed_reset_revision] {
                            return stopping ||
                                   profile_revision != observed_profile_revision ||
                                   reset_revision != observed_reset_revision;
                        });
                    if (stopping) break;
                    if (observed_reset_revision != reset_revision) {
                        last_job.reset();
                        map_revision = 0;
                        map_size = {};
                        last_started = {};
                        observed_reset_revision = reset_revision;
                        continue;
                    }
                    if (observed_profile_revision != profile_revision) {
                        last_started = {};
                        observed_profile_revision = profile_revision;
                        live_profile = live_profile_spec(requested_profile_mode);
                    }
                }

                if (pending) {
                    last_job = std::move(*pending);
                    pending.reset();
                    replayed_input_for_job = false;
                    fresh_input_frames += 1;
                } else if (last_job) {
                    replayed_input_for_job = true;
                    replayed_input_frames += 1;
                } else {
                    continue;
                }
                job = *last_job;
                live_profile = live_profile_spec(requested_profile_mode);
                job_profile_revision = profile_revision;
            }

            last_started = std::chrono::steady_clock::now();
            const auto total_started = last_started;
            try {
                auto frame_a = prepare_frame(
                    job.pair.camera_a,
                    job.calibration.camera_a,
                    "LIVE_CAMERA_A");
                auto frame_b = prepare_frame(
                    job.pair.camera_b,
                    job.calibration.camera_b,
                    "LIVE_CAMERA_B");
                if (frame_a.image.size() != frame_b.image.size()) {
                    throw std::runtime_error(
                        "live preview prepared dimensions differ");
                }

                if (map_revision != job.calibration.revision ||
                    map_size != frame_a.image.size()) {
                    const auto k_a = camera_matrix(frame_a.intrinsics);
                    const auto k_b = camera_matrix(frame_b.intrinsics);
                    const auto d_a = distortion(frame_a.intrinsics);
                    const auto d_b = distortion(frame_b.intrinsics);
                    const auto rotation =
                        rotation_matrix(job.calibration.rotation);
                    const auto translation =
                        translation_vector(job.calibration.translation_mm);

                    cv::Mat rectification_a;
                    cv::Mat rectification_b;
                    cv::Mat projection_a;
                    cv::Mat projection_b;
                    cv::Mat q;
                    cv::stereoRectify(
                        k_a,
                        d_a,
                        k_b,
                        d_b,
                        frame_a.image.size(),
                        rotation,
                        translation,
                        rectification_a,
                        rectification_b,
                        projection_a,
                        projection_b,
                        q,
                        cv::CALIB_ZERO_DISPARITY,
                        0.0,
                        frame_a.image.size());

                    cached_axis = rectification_axis(
                        projection_b,
                        job.calibration.translation_mm);

                    if (!projection_usable(projection_a) ||
                        !projection_usable(projection_b)) {
                        const double focal = std::min({
                            frame_a.intrinsics.fx,
                            frame_a.intrinsics.fy,
                            frame_b.intrinsics.fx,
                            frame_b.intrinsics.fy,
                        });
                        if (!std::isfinite(focal) || focal <= 1.0) {
                            throw std::runtime_error(
                                "live rectification focal is unavailable");
                        }
                        const double cx =
                            (static_cast<double>(frame_a.image.cols) - 1.0) * 0.5;
                        const double cy =
                            (static_cast<double>(frame_a.image.rows) - 1.0) * 0.5;
                        const cv::Mat common_k =
                            (cv::Mat_<double>(3, 3) <<
                                focal, 0.0, cx,
                                0.0, focal, cy,
                                0.0, 0.0, 1.0);
                        projection_a = cv::Mat::zeros(3, 4, CV_64F);
                        projection_b = cv::Mat::zeros(3, 4, CV_64F);
                        common_k.copyTo(
                            projection_a(cv::Rect(0, 0, 3, 3)));
                        common_k.copyTo(
                            projection_b(cv::Rect(0, 0, 3, 3)));

                        const int axis_row =
                            cached_axis == RectificationAxis::Vertical ? 1 : 0;
                        const double dominant =
                            job.calibration.translation_mm[
                                static_cast<std::size_t>(axis_row)];
                        double baseline =
                            job.calibration.measured_baseline_mm;
                        if (!std::isfinite(baseline) || baseline <= 0.0) {
                            baseline = std::sqrt(
                                job.calibration.translation_mm[0] *
                                    job.calibration.translation_mm[0] +
                                job.calibration.translation_mm[1] *
                                    job.calibration.translation_mm[1] +
                                job.calibration.translation_mm[2] *
                                    job.calibration.translation_mm[2]);
                        }
                        projection_b.at<double>(axis_row, 3) =
                            focal * std::copysign(baseline, dominant);
                    }

                    cached_projection_shift = projection_shift(
                        projection_b,
                        cached_axis,
                        job.calibration.translation_mm);
                    cached_focal_px = std::abs(
                        cached_axis == RectificationAxis::Vertical
                            ? projection_a.at<double>(1, 1)
                            : projection_a.at<double>(0, 0));
                    if (!std::isfinite(cached_focal_px) ||
                        cached_focal_px <= 1.0) {
                        throw std::runtime_error(
                            "live rectified focal length is unavailable");
                    }

                    cv::initUndistortRectifyMap(
                        k_a,
                        d_a,
                        rectification_a,
                        projection_a,
                        frame_a.image.size(),
                        CV_32FC1,
                        map_a_x,
                        map_a_y);
                    cv::initUndistortRectifyMap(
                        k_b,
                        d_b,
                        rectification_b,
                        projection_b,
                        frame_b.image.size(),
                        CV_32FC1,
                        map_b_x,
                        map_b_y);
                    map_revision = job.calibration.revision;
                    map_size = frame_a.image.size();
                }

                cv::Mat native_a;
                cv::Mat native_b;
                cv::remap(
                    frame_a.image,
                    native_a,
                    map_a_x,
                    map_a_y,
                    cv::INTER_LINEAR,
                    cv::BORDER_CONSTANT);
                cv::remap(
                    frame_b.image,
                    native_b,
                    map_b_x,
                    map_b_y,
                    cv::INTER_LINEAR,
                    cv::BORDER_CONSTANT);
                require_usable_rectified_image(
                    image_statistics(native_a),
                    "LIVE_CAMERA_A");
                require_usable_rectified_image(
                    image_statistics(native_b),
                    "LIVE_CAMERA_B");

                auto oriented_a = orient_for_horizontal_disparity(
                    native_a,
                    cached_axis,
                    cached_projection_shift);
                auto oriented_b = orient_for_horizontal_disparity(
                    native_b,
                    cached_axis,
                    cached_projection_shift);
                if (oriented_a.size() != oriented_b.size()) {
                    throw std::runtime_error(
                        "live oriented dimensions differ");
                }

                cv::Size work_size{
                    live_profile.portrait_width,
                    live_profile.portrait_height,
                };
                if (oriented_a.cols > oriented_a.rows) {
                    work_size = {
                        live_profile.portrait_height,
                        live_profile.portrait_width,
                    };
                }
                const bool source_upscaled =
                    work_size.width > oriented_a.cols ||
                    work_size.height > oriented_a.rows;
                const int resize_interpolation =
                    source_upscaled ? cv::INTER_CUBIC : cv::INTER_AREA;
                cv::Mat work_a;
                cv::Mat work_b;
                cv::resize(
                    oriented_a,
                    work_a,
                    work_size,
                    0.0,
                    0.0,
                    resize_interpolation);
                cv::resize(
                    oriented_b,
                    work_b,
                    work_size,
                    0.0,
                    0.0,
                    resize_interpolation);

                const double focal_px =
                    cached_focal_px *
                    static_cast<double>(work_a.cols) /
                    static_cast<double>(oriented_a.cols);

                const auto compute_started =
                    std::chrono::steady_clock::now();
                cv::Mat gray_a;
                cv::Mat gray_b;
                cv::cvtColor(work_a, gray_a, cv::COLOR_BGR2GRAY);
                cv::cvtColor(work_b, gray_b, cv::COLOR_BGR2GRAY);
                auto clahe = cv::createCLAHE(2.0, {8, 8});
                cv::Mat normalized_a;
                cv::Mat normalized_b;
                clahe->apply(gray_a, normalized_a);
                clahe->apply(gray_b, normalized_b);

                const int num_disparities =
                    live_num_disparities(work_a.cols);
                auto matcher = live_matcher(num_disparities);
                cv::Mat disparity_16;
                matcher->compute(
                    normalized_a,
                    normalized_b,
                    disparity_16);
                cv::Mat disparity;
                disparity_16.convertTo(
                    disparity,
                    CV_32F,
                    1.0 / 16.0);

                cv::Mat minimum_mask;
                cv::Mat maximum_mask;
                cv::Mat raw_mask;
                cv::compare(
                    disparity,
                    cv::Scalar(kMinimumDisparity),
                    minimum_mask,
                    cv::CMP_GT);
                cv::compare(
                    disparity,
                    cv::Scalar(num_disparities - 1),
                    maximum_mask,
                    cv::CMP_LT);
                cv::bitwise_and(
                    minimum_mask,
                    maximum_mask,
                    raw_mask);

                cv::Mat spatial;
                cv::medianBlur(disparity, spatial, 5);
                cv::Mat gradient_x_16;
                cv::Mat gradient_y_16;
                cv::Mat gradient_x;
                cv::Mat gradient_y;
                cv::Mat texture;
                cv::Sobel(
                    normalized_a,
                    gradient_x_16,
                    CV_16S,
                    1,
                    0,
                    3);
                cv::Sobel(
                    normalized_a,
                    gradient_y_16,
                    CV_16S,
                    0,
                    1,
                    3);
                cv::convertScaleAbs(gradient_x_16, gradient_x);
                cv::convertScaleAbs(gradient_y_16, gradient_y);
                cv::addWeighted(
                    gradient_x,
                    0.5,
                    gradient_y,
                    0.5,
                    0.0,
                    texture);

                cv::Mat dense_texture;
                cv::Mat strict_texture;
                cv::compare(
                    texture,
                    cv::Scalar(5),
                    dense_texture,
                    cv::CMP_GT);
                cv::compare(
                    texture,
                    cv::Scalar(12),
                    strict_texture,
                    cv::CMP_GT);

                cv::Mat dense_mask;
                cv::Mat strict_mask;
                cv::bitwise_and(raw_mask, dense_texture, dense_mask);
                cv::bitwise_and(raw_mask, strict_texture, strict_mask);
                const auto kernel = cv::getStructuringElement(
                    cv::MORPH_ELLIPSE,
                    {3, 3});
                cv::morphologyEx(
                    dense_mask,
                    dense_mask,
                    cv::MORPH_CLOSE,
                    kernel);
                cv::morphologyEx(
                    strict_mask,
                    strict_mask,
                    cv::MORPH_OPEN,
                    kernel);

                cv::Mat disparity_colour;
                cv::Mat normalized_disparity;
                disparity.convertTo(
                    normalized_disparity,
                    CV_8U,
                    255.0 /
                        static_cast<double>(
                            std::max(1, num_disparities)));
                cv::applyColorMap(
                    normalized_disparity,
                    disparity_colour,
                    cv::COLORMAP_TURBO);
                cv::Mat invalid_raw;
                cv::bitwise_not(raw_mask, invalid_raw);
                disparity_colour.setTo(
                    cv::Scalar(0, 0, 0),
                    invalid_raw);

                const auto raw_depth = metric_heatmap(
                    disparity,
                    raw_mask,
                    focal_px,
                    job.calibration.measured_baseline_mm);
                const auto filtered_depth = metric_heatmap(
                    spatial,
                    dense_mask,
                    focal_px,
                    job.calibration.measured_baseline_mm);
                const auto strict_depth = metric_heatmap(
                    spatial,
                    strict_mask,
                    focal_px,
                    job.calibration.measured_baseline_mm);

                cv::Mat confidence(
                    disparity.size(),
                    CV_8UC3,
                    cv::Scalar(0, 0, 0));
                confidence.setTo(cv::Scalar(0, 0, 255), raw_mask);
                confidence.setTo(
                    cv::Scalar(0, 165, 255),
                    dense_mask);
                confidence.setTo(
                    cv::Scalar(0, 255, 0),
                    strict_mask);

                const auto mode = current_operator_preview_mode();
                const cv::Mat* selected = &filtered_depth;
                const cv::Mat* selected_mask = &dense_mask;
                switch (mode) {
                    case OperatorPreviewMode::Disparity:
                        selected = &disparity_colour;
                        selected_mask = &raw_mask;
                        break;
                    case OperatorPreviewMode::DepthRaw:
                        selected = &raw_depth;
                        selected_mask = &raw_mask;
                        break;
                    case OperatorPreviewMode::DepthFiltered:
                        selected = &filtered_depth;
                        selected_mask = &dense_mask;
                        break;
                    case OperatorPreviewMode::DepthStrict:
                        selected = &strict_depth;
                        selected_mask = &strict_mask;
                        break;
                    case OperatorPreviewMode::Confidence:
                        selected = &confidence;
                        selected_mask = &strict_mask;
                        break;
                }

                const auto compute_finished =
                    std::chrono::steady_clock::now();
                const int processing_rotation =
                    cached_axis == RectificationAxis::Vertical
                        ? (cached_projection_shift < 0.0 ? -90 : 90)
                        : 0;
                const int display_rotation = normalize_degrees(
                    job.pair.camera_a.rotation_degrees -
                    processing_rotation);
                auto display =
                    rotate_for_display(*selected, display_rotation);
                const auto heartbeat_tick =
                    static_cast<std::uint64_t>(
                        std::max<std::int64_t>(0, unix_time_ms()) / 200);
                const bool heartbeat_phase = (heartbeat_tick % 2U) != 0U;
                const auto heartbeat_colour = replayed_input_for_job
                    ? (heartbeat_phase
                        ? cv::Scalar(0, 165, 255)
                        : cv::Scalar(0, 255, 255))
                    : (heartbeat_phase
                        ? cv::Scalar(0, 255, 0)
                        : cv::Scalar(255, 255, 0));
                cv::circle(
                    display,
                    {
                        std::max(8, display.cols - 12),
                        std::max(8, display.rows - 12),
                    },
                    5,
                    heartbeat_colour,
                    cv::FILLED,
                    cv::LINE_AA);
                cv::putText(
                    display,
                    std::string(replayed_input_for_job ? "REPLAY #" : "LIVE #") +
                        std::to_string(job.pair.pair_index) + " @" +
                        std::to_string(heartbeat_tick % 1000U),
                    {10, std::max(18, display.rows - 10)},
                    cv::FONT_HERSHEY_SIMPLEX,
                    0.42,
                    cv::Scalar(255, 255, 255),
                    1,
                    cv::LINE_AA);

                const auto encode_started =
                    std::chrono::steady_clock::now();
                auto jpeg = encode_jpeg(display);
                const auto finished =
                    std::chrono::steady_clock::now();
                if (last_disk_write.time_since_epoch().count() == 0 ||
                    finished - last_disk_write >= std::chrono::seconds{1}) {
                    write_binary_atomic(
                        session_directory / "selected_preview_latest.jpg",
                        jpeg);
                    last_disk_write = finished;
                }

                const double compute_ms =
                    std::chrono::duration<double, std::milli>(
                        compute_finished - compute_started).count();
                const double encode_ms =
                    std::chrono::duration<double, std::milli>(
                        finished - encode_started).count();
                const double total_ms =
                    std::chrono::duration<double, std::milli>(
                        finished - total_started).count();
                const double ratio = mask_ratio(*selected_mask);
                const auto median = median_depth(
                    spatial,
                    *selected_mask,
                    focal_px,
                    job.calibration.measured_baseline_mm);

                nlohmann::json diagnostic;
                bool stale_profile_result = false;
                {
                    std::scoped_lock lock(mutex);
                    if (job_profile_revision != profile_revision) {
                        stale_profile_results += 1;
                        stale_profile_result = true;
                        diagnostic = {
                            {"event", "LIVE_PREVIEW_STALE_PROFILE_DISCARDED"},
                            {"pair_index", job.pair.pair_index},
                            {"job_profile_revision", job_profile_revision},
                            {"current_profile_revision", profile_revision},
                        };
                    } else {
                        selected_jpeg = std::move(jpeg);
                        ready = true;
                        sequence += 1;
                        pair_index = job.pair.pair_index;
                        work_width = display.cols;
                        work_height = display.rows;
                        valid_ratio = ratio;
                        median_depth_m = median;
                        last_compute_ms = compute_ms;
                        last_encode_ms = encode_ms;
                        last_total_ms = total_ms;
                        last_publish_unix_ms = unix_time_ms();
                        last_source_unix_ms = job.received_unix_ms;
                        last_source_sequence_a = job.pair.camera_a.sequence;
                        last_source_sequence_b = job.pair.camera_b.sequence;
                        input_replayed = replayed_input_for_job;
                        last_error.clear();
                        processed_pairs += 1;
                        success_times.push_back(finished);
                        while (!success_times.empty() &&
                               finished - success_times.front() > kFpsWindow) {
                            success_times.pop_front();
                        }
                        if (success_times.size() >= 2) {
                            const double seconds =
                                std::chrono::duration<double>(
                                    success_times.back() -
                                    success_times.front()).count();
                            actual_fps = seconds > 0.0
                                ? static_cast<double>(
                                      success_times.size() - 1) / seconds
                                : 0.0;
                        }
                        diagnostic = {
                            {"event", "LIVE_PREVIEW_READY"},
                            {"pair_index", pair_index},
                            {"sequence", sequence},
                            {"selected_mode",
                             operator_preview_mode_name(mode)},
                            {"resolution_policy", "MATCH_PROFILE"},
                            {"requested_profile", requested_profile_mode},
                            {"active_profile", live_profile.name},
                            {"profile_revision", profile_revision},
                            {"target_fps",
                             1000.0 /
                                 static_cast<double>(live_profile.interval_ms)},
                            {"actual_fps", actual_fps},
                            {"work_width", work_width},
                            {"work_height", work_height},
                            {"source_upscaled", source_upscaled},
                            {"input_replayed", replayed_input_for_job},
                            {"source_age_ms",
                             std::max<std::int64_t>(
                                 0, last_publish_unix_ms -
                                        job.received_unix_ms)},
                            {"source_sequence_a", last_source_sequence_a},
                            {"source_sequence_b", last_source_sequence_b},
                            {"compute_ms", compute_ms},
                            {"jpeg_encode_ms", encode_ms},
                            {"total_ms", total_ms},
                            {"valid_ratio", ratio},
                            {"median_depth_m",
                             median
                                 ? nlohmann::json(*median)
                                 : nlohmann::json(nullptr)},
                            {"geometry_calibration_revision",
                             job.calibration.revision},
                        };
                    }
                }
                append_diagnostic(std::move(diagnostic));
                if (stale_profile_result) continue;
            } catch (const std::exception& error) {
                nlohmann::json diagnostic;
                {
                    std::scoped_lock lock(mutex);
                    ready = false;
                    failed_pairs += 1;
                    last_error = error.what();
                    last_total_ms =
                        std::chrono::duration<double, std::milli>(
                            std::chrono::steady_clock::now() -
                            total_started).count();
                    diagnostic = {
                        {"event", "LIVE_PREVIEW_FAILED"},
                        {"pair_index", job.pair.pair_index},
                        {"error", last_error},
                        {"total_ms", last_total_ms},
                    };
                }
                append_diagnostic(std::move(diagnostic));
            }
        }
    }

    std::filesystem::path session_directory;
    mutable std::mutex mutex;
    std::condition_variable condition;
    bool stopping = false;
    std::optional<PendingLivePair> pending;
    std::ofstream diagnostics;
    std::thread worker;
    std::vector<std::uint8_t> selected_jpeg;
    bool ready = false;
    std::uint64_t sequence = 0;
    std::uint64_t pair_index = 0;
    int work_width = 0;
    int work_height = 0;
    double actual_fps = 0.0;
    double valid_ratio = 0.0;
    std::optional<double> median_depth_m;
    double last_compute_ms = 0.0;
    double last_encode_ms = 0.0;
    double last_total_ms = 0.0;
    std::int64_t last_publish_unix_ms = 0;
    std::int64_t last_source_unix_ms = 0;
    std::uint64_t last_source_sequence_a = 0;
    std::uint64_t last_source_sequence_b = 0;
    bool input_replayed = false;
    std::string requested_profile_mode = "HIGH_640";
    std::string last_error;
    std::uint64_t submitted_pairs = 0;
    std::uint64_t processed_pairs = 0;
    std::uint64_t failed_pairs = 0;
    std::uint64_t dropped_pending_pairs = 0;
    std::uint64_t fresh_input_frames = 0;
    std::uint64_t replayed_input_frames = 0;
    std::uint64_t stale_profile_results = 0;
    std::uint64_t profile_revision = 1;
    std::uint64_t reset_revision = 0;
    std::deque<std::chrono::steady_clock::time_point> success_times;
};

LivePreviewRuntime::LivePreviewRuntime(
    std::filesystem::path session_directory)
    : impl_(std::make_unique<Impl>(std::move(session_directory))) {}

LivePreviewRuntime::~LivePreviewRuntime() = default;

void LivePreviewRuntime::submit(
    StereoPreviewPair pair,
    ResolvedCalibration calibration) {
    impl_->submit(std::move(pair), std::move(calibration));
}

nlohmann::json LivePreviewRuntime::select_profile(std::string mode) {
    return impl_->select_profile(std::move(mode));
}

void LivePreviewRuntime::reset() {
    impl_->reset();
}

nlohmann::json LivePreviewRuntime::status_json() const {
    return impl_->status_json();
}

std::optional<std::vector<std::uint8_t>>
LivePreviewRuntime::image() const {
    return impl_->image();
}

}  // namespace maklertour::dual_phone::detail
