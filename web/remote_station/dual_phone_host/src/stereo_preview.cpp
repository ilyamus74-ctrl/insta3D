#include "stereo_preview.hpp"

#include "protocol.hpp"
#include "stereo_preview_processing.hpp"

#include <array>
#include <chrono>
#include <condition_variable>
#include <deque>
#include <fstream>
#include <iomanip>
#include <mutex>
#include <optional>
#include <stdexcept>
#include <thread>
#include <utility>

#include <opencv2/calib3d.hpp>
#include <opencv2/imgproc.hpp>

namespace maklertour::dual_phone {

namespace {

constexpr std::chrono::seconds kFpsWindow{5};

}  // namespace

using detail::CalibrationProfile;
using detail::ResolvedCalibration;

using detail::camera_matrix;
using detail::distortion;
using detail::encode_jpeg;
using detail::make_disparity;
using detail::parse_profile;
using detail::prepare_frame;
using detail::resolve_profile;
using detail::rotation_matrix;
using detail::translation_vector;
using detail::with_epipolar_guides;

struct StereoPreview::Impl {
    explicit Impl(std::filesystem::path session_directory)
        : session_directory(std::move(session_directory)),
          diagnostics(this->session_directory / "stereo_preview.jsonl", std::ios::app) {
        if (!diagnostics) throw std::runtime_error("cannot create stereo preview diagnostics");
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
        std::ofstream(session_directory / "stereo_preview_status.json")
            << std::setw(2) << status_json() << '\n';
    }

    void set_camera_identity(const std::size_t slot_index, std::string device_id) {
        if (slot_index >= device_ids.size()) throw std::out_of_range("camera slot index");
        std::scoped_lock lock(mutex);
        device_ids[slot_index] = std::move(device_id);
        resolve_locked();
    }

    void clear_camera_identity(const std::size_t slot_index) {
        if (slot_index >= device_ids.size()) throw std::out_of_range("camera slot index");
        std::scoped_lock lock(mutex);
        device_ids[slot_index].clear();
        resolve_locked();
    }

    void set_calibration_profile(const nlohmann::json& value) {
        std::scoped_lock lock(mutex);
        try {
            profile = parse_profile(value);
            profile_error.clear();
        } catch (const std::exception& error) {
            profile.reset();
            profile_error = error.what();
        }
        resolve_locked();
    }

    void clear_calibration_profile() {
        std::scoped_lock lock(mutex);
        profile.reset();
        profile_error.clear();
        resolve_locked();
    }

    void submit(StereoPreviewPair value) {
        {
            std::scoped_lock lock(mutex);
            submitted += 1;
            if (!resolved) {
                skipped_not_ready += 1;
                return;
            }
            if (pending) queue_replaced += 1;
            pending = std::move(value);
        }
        condition.notify_one();
    }

    nlohmann::json status_json() const {
        std::scoped_lock lock(mutex);
        return status_json_locked();
    }

    std::optional<std::vector<std::uint8_t>> image(const StereoPreviewImage kind) const {
        std::scoped_lock lock(mutex);
        const std::vector<std::uint8_t>* source = nullptr;
        switch (kind) {
            case StereoPreviewImage::RectifiedA: source = &rectified_a_jpeg; break;
            case StereoPreviewImage::RectifiedB: source = &rectified_b_jpeg; break;
            case StereoPreviewImage::Disparity: source = &disparity_jpeg; break;
        }
        if (source->empty()) return std::nullopt;
        return *source;
    }

    void resolve_locked() {
        pending.reset();
        resolved.reset();
        maps_ready = false;
        rectified_a_jpeg.clear();
        rectified_b_jpeg.clear();
        disparity_jpeg.clear();
        runtime_width = 0;
        runtime_height = 0;
        last_error.clear();
        configuration_revision += 1;

        if (!profile) {
            calibration_state = profile_error.empty() ? "WAITING_FOR_CALIBRATION" : "ERROR";
            calibration_error = profile_error;
            return;
        }
        if (device_ids[0].empty() || device_ids[1].empty()) {
            calibration_state = "WAITING_FOR_CAMERAS";
            calibration_error.clear();
            return;
        }
        try {
            resolved = resolve_profile(*profile, device_ids[0], device_ids[1],
                                       configuration_revision);
            calibration_state = "READY";
            calibration_error.clear();
        } catch (const std::exception& error) {
            calibration_state = "ERROR";
            calibration_error = error.what();
        }
    }

    nlohmann::json status_json_locked() const {
        nlohmann::json calibration = {
            {"state", calibration_state},
            {"error", calibration_error},
            {"camera_a_device_id", device_ids[0]},
            {"camera_b_device_id", device_ids[1]},
            {"maps_ready", maps_ready},
            {"runtime_width", runtime_width},
            {"runtime_height", runtime_height},
        };
        if (profile) {
            calibration["profile_id"] = profile->profile_id;
            calibration["profile_master_device_id"] = profile->master_device_id;
            calibration["profile_slave_device_id"] = profile->slave_device_id;
            calibration["measured_baseline_mm"] = profile->measured_baseline_mm;
        }
        if (resolved) calibration["roles_reversed"] = resolved->roles_reversed;

        return {
            {"schema_version", 1},
            {"calibration", std::move(calibration)},
            {"processing", {
                {"submitted_pairs", submitted},
                {"processed_pairs", processed},
                {"failed_pairs", failed},
                {"skipped_not_ready", skipped_not_ready},
                {"queue_replaced", queue_replaced},
                {"stale_results_discarded", stale_results_discarded},
                {"fps", processing_fps},
                {"last_duration_ms", last_duration_ms},
                {"last_attempt_pair_index", last_attempt_pair_index},
                {"last_success_pair_index", last_success_pair_index},
                {"last_pair_delta_ms", last_pair_delta_ms},
                {"valid_disparity_ratio", valid_disparity_ratio},
                {"min_disparity", min_disparity},
                {"num_disparities", num_disparities},
                {"last_error", last_error},
            }},
            {"previews", {
                {"rectified_a_ready", !rectified_a_jpeg.empty()},
                {"rectified_b_ready", !rectified_b_jpeg.empty()},
                {"disparity_ready", !disparity_jpeg.empty()},
            }},
        };
    }

    void append_diagnostic(nlohmann::json value) {
        value["ts"] = utc_iso8601_now();
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
        cv::Mat cached_translation;

        while (true) {
            StereoPreviewPair pair;
            ResolvedCalibration calibration;
            {
                std::unique_lock lock(mutex);
                condition.wait(lock, [this] { return stopping || pending.has_value(); });
                if (stopping) break;
                pair = std::move(*pending);
                pending.reset();
                if (!resolved) continue;
                calibration = *resolved;
            }

            const auto started = std::chrono::steady_clock::now();
            try {
                auto frame_a = prepare_frame(pair.camera_a, calibration.camera_a, "CAMERA_A");
                auto frame_b = prepare_frame(pair.camera_b, calibration.camera_b, "CAMERA_B");
                if (frame_a.image.size() != frame_b.image.size()) {
                    throw std::runtime_error("CAMERA_A and CAMERA_B prepared dimensions differ");
                }

                if (map_revision != calibration.revision || map_size != frame_a.image.size()) {
                    const auto k_a = camera_matrix(frame_a.intrinsics);
                    const auto k_b = camera_matrix(frame_b.intrinsics);
                    const auto d_a = distortion(frame_a.intrinsics);
                    const auto d_b = distortion(frame_b.intrinsics);
                    const auto rotation = rotation_matrix(calibration.rotation);
                    cached_translation = translation_vector(calibration.translation_mm);
                    cv::Mat rectification_a;
                    cv::Mat rectification_b;
                    cv::Mat projection_a;
                    cv::Mat projection_b;
                    cv::Mat q;
                    cv::Rect roi_a;
                    cv::Rect roi_b;
                    cv::stereoRectify(
                        k_a, d_a, k_b, d_b, frame_a.image.size(), rotation,
                        cached_translation, rectification_a, rectification_b,
                        projection_a, projection_b, q, cv::CALIB_ZERO_DISPARITY,
                        0.0, frame_a.image.size(), &roi_a, &roi_b);
                    cv::initUndistortRectifyMap(
                        k_a, d_a, rectification_a, projection_a,
                        frame_a.image.size(), CV_32FC1, map_a_x, map_a_y);
                    cv::initUndistortRectifyMap(
                        k_b, d_b, rectification_b, projection_b,
                        frame_b.image.size(), CV_32FC1, map_b_x, map_b_y);
                    map_revision = calibration.revision;
                    map_size = frame_a.image.size();
                }

                cv::Mat rectified_a;
                cv::Mat rectified_b;
                cv::remap(frame_a.image, rectified_a, map_a_x, map_a_y,
                          cv::INTER_LINEAR, cv::BORDER_CONSTANT);
                cv::remap(frame_b.image, rectified_b, map_b_x, map_b_y,
                          cv::INTER_LINEAR, cv::BORDER_CONSTANT);
                auto disparity = make_disparity(
                    rectified_a,
                    rectified_b,
                    cached_translation.at<double>(0, 0));
                auto preview_a = encode_jpeg(with_epipolar_guides(rectified_a));
                auto preview_b = encode_jpeg(with_epipolar_guides(rectified_b));
                auto preview_disparity = encode_jpeg(disparity.preview);
                const auto finished = std::chrono::steady_clock::now();
                const auto duration_ms = std::chrono::duration<double, std::milli>(
                    finished - started).count();

                nlohmann::json diagnostic;
                bool stale = false;
                {
                    std::scoped_lock lock(mutex);
                    if (!resolved || resolved->revision != calibration.revision) {
                        stale_results_discarded += 1;
                        stale = true;
                        diagnostic = {
                            {"event", "STEREO_PREVIEW_STALE_DISCARDED"},
                            {"pair_index", pair.pair_index},
                            {"profile_id", calibration.profile_id},
                        };
                    } else {
                        rectified_a_jpeg = std::move(preview_a);
                        rectified_b_jpeg = std::move(preview_b);
                        disparity_jpeg = std::move(preview_disparity);
                        processed += 1;
                        maps_ready = true;
                        runtime_width = rectified_a.cols;
                        runtime_height = rectified_a.rows;
                        last_duration_ms = duration_ms;
                        last_attempt_pair_index = pair.pair_index;
                        last_success_pair_index = pair.pair_index;
                        last_pair_delta_ms = pair.delta_ms;
                        valid_disparity_ratio = disparity.valid_ratio;
                        min_disparity = disparity.min_disparity;
                        num_disparities = disparity.num_disparities;
                        last_error.clear();
                        success_times.push_back(finished);
                        while (!success_times.empty() &&
                               finished - success_times.front() > kFpsWindow) {
                            success_times.pop_front();
                        }
                        if (success_times.size() >= 2) {
                            const auto seconds = std::chrono::duration<double>(
                                success_times.back() - success_times.front()).count();
                            processing_fps = seconds > 0.0
                                ? static_cast<double>(success_times.size() - 1) / seconds
                                : 0.0;
                        }
                        diagnostic = {
                            {"event", "STEREO_PREVIEW_READY"},
                            {"pair_index", pair.pair_index},
                            {"camera_a_sequence", pair.camera_a.sequence},
                            {"camera_b_sequence", pair.camera_b.sequence},
                            {"pair_delta_ms", pair.delta_ms},
                            {"duration_ms", duration_ms},
                            {"valid_disparity_ratio", disparity.valid_ratio},
                            {"min_disparity", disparity.min_disparity},
                            {"num_disparities", disparity.num_disparities},
                            {"width", rectified_a.cols},
                            {"height", rectified_a.rows},
                            {"camera_a_rotation_applied", frame_a.applied_rotation_degrees},
                            {"camera_b_rotation_applied", frame_b.applied_rotation_degrees},
                            {"roles_reversed", calibration.roles_reversed},
                            {"profile_id", calibration.profile_id},
                        };
                    }
                }
                append_diagnostic(std::move(diagnostic));
                if (stale) continue;
            } catch (const std::exception& error) {
                nlohmann::json diagnostic;
                {
                    std::scoped_lock lock(mutex);
                    failed += 1;
                    maps_ready = false;
                    last_error = error.what();
                    last_attempt_pair_index = pair.pair_index;
                    last_pair_delta_ms = pair.delta_ms;
                    last_duration_ms = std::chrono::duration<double, std::milli>(
                        std::chrono::steady_clock::now() - started).count();
                    diagnostic = {
                        {"event", "STEREO_PREVIEW_FAILED"},
                        {"pair_index", pair.pair_index},
                        {"camera_a_sequence", pair.camera_a.sequence},
                        {"camera_b_sequence", pair.camera_b.sequence},
                        {"pair_delta_ms", pair.delta_ms},
                        {"error", last_error},
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
    std::ofstream diagnostics;
    std::thread worker;
    std::array<std::string, 2> device_ids;
    std::optional<CalibrationProfile> profile;
    std::optional<ResolvedCalibration> resolved;
    std::string profile_error;
    std::string calibration_state = "WAITING_FOR_CALIBRATION";
    std::string calibration_error;
    std::uint64_t configuration_revision = 0;
    std::optional<StereoPreviewPair> pending;
    std::vector<std::uint8_t> rectified_a_jpeg;
    std::vector<std::uint8_t> rectified_b_jpeg;
    std::vector<std::uint8_t> disparity_jpeg;
    std::uint64_t submitted = 0;
    std::uint64_t processed = 0;
    std::uint64_t failed = 0;
    std::uint64_t skipped_not_ready = 0;
    std::uint64_t queue_replaced = 0;
    std::uint64_t stale_results_discarded = 0;
    bool maps_ready = false;
    int runtime_width = 0;
    int runtime_height = 0;
    double processing_fps = 0.0;
    double last_duration_ms = 0.0;
    std::uint64_t last_attempt_pair_index = 0;
    std::uint64_t last_success_pair_index = 0;
    double last_pair_delta_ms = 0.0;
    double valid_disparity_ratio = 0.0;
    int min_disparity = 0;
    int num_disparities = 0;
    std::string last_error;
    std::deque<std::chrono::steady_clock::time_point> success_times;
};

StereoPreview::StereoPreview(std::filesystem::path session_directory)
    : impl_(std::make_unique<Impl>(std::move(session_directory))) {}

StereoPreview::~StereoPreview() = default;

void StereoPreview::set_camera_identity(const std::size_t slot_index,
                                        std::string device_id) {
    impl_->set_camera_identity(slot_index, std::move(device_id));
}

void StereoPreview::clear_camera_identity(const std::size_t slot_index) {
    impl_->clear_camera_identity(slot_index);
}

void StereoPreview::set_calibration_profile(const nlohmann::json& profile) {
    impl_->set_calibration_profile(profile);
}

void StereoPreview::clear_calibration_profile() {
    impl_->clear_calibration_profile();
}

void StereoPreview::submit(StereoPreviewPair pair) {
    impl_->submit(std::move(pair));
}

nlohmann::json StereoPreview::status_json() const {
    return impl_->status_json();
}

std::optional<std::vector<std::uint8_t>> StereoPreview::image(
    const StereoPreviewImage kind) const {
    return impl_->image(kind);
}

}  // namespace maklertour::dual_phone
