#include "stereo_preview.hpp"

#include "protocol.hpp"
#include "stereo_depth_runtime.hpp"
#include "stereo_preview_processing.hpp"

#include <algorithm>
#include <array>
#include <chrono>
#include <cmath>
#include <condition_variable>
#include <deque>
#include <fstream>
#include <iomanip>
#include <mutex>
#include <optional>
#include <stdexcept>
#include <system_error>
#include <thread>
#include <utility>

#include <opencv2/calib3d.hpp>
#include <opencv2/imgproc.hpp>

namespace maklertour::dual_phone {

namespace {

constexpr std::chrono::seconds kFpsWindow{5};
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

nlohmann::json matrix_json(const cv::Mat& matrix) {
    nlohmann::json rows = nlohmann::json::array();
    if (matrix.empty()) return rows;
    cv::Mat converted;
    matrix.convertTo(converted, CV_64F);
    for (int row = 0; row < converted.rows; ++row) {
        nlohmann::json values = nlohmann::json::array();
        for (int column = 0; column < converted.cols; ++column) {
            values.push_back(converted.at<double>(row, column));
        }
        rows.push_back(std::move(values));
    }
    return rows;
}

}  // namespace

using detail::CalibrationProfile;
using detail::ImageStatistics;
using detail::RectificationAxis;
using detail::ResolvedCalibration;
using detail::StereoDepthRuntime;

using detail::camera_matrix;
using detail::distortion;
using detail::encode_jpeg;
using detail::image_statistics;
using detail::make_disparity;
using detail::map_valid_fraction;
using detail::orient_for_horizontal_disparity;
using detail::parse_profile;
using detail::prepare_frame;
using detail::projection_shift;
using detail::rectification_axis;
using detail::rectification_axis_name;
using detail::require_usable_rectified_image;
using detail::resolve_profile;
using detail::rotation_matrix;
using detail::translation_vector;
using detail::with_epipolar_guides;

struct StereoPreview::Impl {
    explicit Impl(std::filesystem::path session_path)
        : session_directory(std::move(session_path)),
          diagnostics(session_directory / "stereo_preview.jsonl", std::ios::app) {
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

    nlohmann::json select_depth_profile(const std::string& mode) {
        return depth_runtime.select_mode(mode);
    }

    nlohmann::json depth_profiles_json() const {
        return depth_runtime.profiles_json();
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
            case StereoPreviewImage::DepthRaw: source = &depth_raw_jpeg; break;
            case StereoPreviewImage::DepthFiltered: source = &depth_filtered_jpeg; break;
            case StereoPreviewImage::DepthStrict: source = &depth_strict_jpeg; break;
            case StereoPreviewImage::Confidence: source = &confidence_jpeg; break;
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
        depth_raw_jpeg.clear();
        depth_filtered_jpeg.clear();
        depth_strict_jpeg.clear();
        confidence_jpeg.clear();
        runtime_width = 0;
        runtime_height = 0;
        rectification_axis_state = "UNKNOWN";
        processing_rotation_degrees = 0;
        rectified_projection_shift = 0.0;
        map_valid_fraction_a = 0.0;
        map_valid_fraction_b = 0.0;
        raw_a_statistics = {};
        raw_b_statistics = {};
        rectified_a_statistics = {};
        rectified_b_statistics = {};
        last_error.clear();
        configuration_revision += 1;
        depth_runtime.reset_geometry();

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
            {"rectification_axis", rectification_axis_state},
            {"processing_rotation_degrees", processing_rotation_degrees},
            {"rectified_projection_shift", rectified_projection_shift},
            {"map_valid_fraction_a", map_valid_fraction_a},
            {"map_valid_fraction_b", map_valid_fraction_b},
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
            {"depth", depth_runtime.status_json()},
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
                {"raw_a_mean_luma", raw_a_statistics.mean_luma},
                {"raw_b_mean_luma", raw_b_statistics.mean_luma},
                {"raw_a_nonzero_fraction", raw_a_statistics.nonzero_fraction},
                {"raw_b_nonzero_fraction", raw_b_statistics.nonzero_fraction},
                {"rectified_a_mean_luma", rectified_a_statistics.mean_luma},
                {"rectified_b_mean_luma", rectified_b_statistics.mean_luma},
                {"rectified_a_nonzero_fraction", rectified_a_statistics.nonzero_fraction},
                {"rectified_b_nonzero_fraction", rectified_b_statistics.nonzero_fraction},
                {"last_error", last_error},
            }},
            {"previews", {
                {"rectified_a_ready", !rectified_a_jpeg.empty()},
                {"rectified_b_ready", !rectified_b_jpeg.empty()},
                {"disparity_ready", !disparity_jpeg.empty()},
                {"depth_raw_ready", !depth_raw_jpeg.empty()},
                {"depth_filtered_ready", !depth_filtered_jpeg.empty()},
                {"depth_strict_ready", !depth_strict_jpeg.empty()},
                {"confidence_ready", !confidence_jpeg.empty()},
                {"raw_a_latest", "raw_a_latest.jpg"},
                {"raw_b_latest", "raw_b_latest.jpg"},
                {"rectified_a_latest", "rectified_a_latest.jpg"},
                {"rectified_b_latest", "rectified_b_latest.jpg"},
                {"disparity_latest", "disparity_latest.jpg"},
                {"depth_raw_latest", "depth_raw_latest.jpg"},
                {"depth_filtered_latest", "depth_filtered_latest.jpg"},
                {"depth_strict_latest", "depth_strict_latest.jpg"},
                {"confidence_latest", "confidence_latest.jpg"},
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
        RectificationAxis cached_axis = RectificationAxis::Horizontal;
        double cached_projection_shift = 0.0;
        double cached_rectified_focal_px = 0.0;
        double cached_map_valid_fraction_a = 0.0;
        double cached_map_valid_fraction_b = 0.0;
        std::chrono::steady_clock::time_point last_raw_image_write;
        std::chrono::steady_clock::time_point last_processed_image_write;

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

            const auto depth_budget = depth_runtime.acquire_budget();
            if (!depth_budget) continue;
            const auto started = std::chrono::steady_clock::now();
            try {
                auto frame_a = prepare_frame(pair.camera_a, calibration.camera_a, "CAMERA_A");
                auto frame_b = prepare_frame(pair.camera_b, calibration.camera_b, "CAMERA_B");
                if (frame_a.image.size() != frame_b.image.size()) {
                    throw std::runtime_error("CAMERA_A and CAMERA_B prepared dimensions differ");
                }
                const auto current_raw_a_statistics = image_statistics(frame_a.image);
                const auto current_raw_b_statistics = image_statistics(frame_b.image);

                // Preserve raw evidence even when rectification/map construction fails.
                const auto raw_ready = std::chrono::steady_clock::now();
                if (
                    last_raw_image_write.time_since_epoch().count() == 0 ||
                    raw_ready - last_raw_image_write >= std::chrono::seconds{1}
                ) {
                    write_binary_atomic(
                        session_directory / "raw_a_latest.jpg", pair.camera_a.jpeg);
                    write_binary_atomic(
                        session_directory / "raw_b_latest.jpg", pair.camera_b.jpeg);
                    last_raw_image_write = raw_ready;
                }

                if (map_revision != calibration.revision || map_size != frame_a.image.size()) {
                    const auto k_a = camera_matrix(frame_a.intrinsics);
                    const auto k_b = camera_matrix(frame_b.intrinsics);
                    const auto d_a = distortion(frame_a.intrinsics);
                    const auto d_b = distortion(frame_b.intrinsics);
                    const auto rotation = rotation_matrix(calibration.rotation);
                    const auto translation = translation_vector(calibration.translation_mm);
                    cv::Mat rectification_a;
                    cv::Mat rectification_b;
                    cv::Mat projection_a;
                    cv::Mat projection_b;
                    cv::Mat q;
                    cv::Rect roi_a;
                    cv::Rect roi_b;
                    cv::stereoRectify(
                        k_a, d_a, k_b, d_b, frame_a.image.size(), rotation,
                        translation, rectification_a, rectification_b,
                        projection_a, projection_b, q, cv::CALIB_ZERO_DISPARITY,
                        0.0, frame_a.image.size(), &roi_a, &roi_b);

                    const auto projection_usable = [](const cv::Mat& value) {
                        if (value.rows != 3 || value.cols != 4 ||
                            value.type() != CV_64F) {
                            return false;
                        }
                        for (int row = 0; row < value.rows; ++row) {
                            for (int column = 0; column < value.cols; ++column) {
                                if (!std::isfinite(value.at<double>(row, column))) {
                                    return false;
                                }
                            }
                        }
                        return std::abs(value.at<double>(0, 0)) > 1.0 &&
                               std::abs(value.at<double>(1, 1)) > 1.0;
                    };

                    cached_axis = rectification_axis(
                        projection_b,
                        calibration.translation_mm);
                    bool projection_fallback_used = false;

                    // Fedora OpenCV 4.10 may return finite R1/R2 but NaN
                    // focal/principal-point values in P1/P2/Q for this
                    // near-vertical stereo rig. Keep the valid rectification
                    // rotations and synthesize a finite common camera model.
                    if (!projection_usable(projection_a) ||
                        !projection_usable(projection_b)) {
                        projection_fallback_used = true;
                        const auto focal = std::min({
                            frame_a.intrinsics.fx,
                            frame_a.intrinsics.fy,
                            frame_b.intrinsics.fx,
                            frame_b.intrinsics.fy,
                        });
                        const auto cx =
                            (static_cast<double>(frame_a.image.cols) - 1.0) * 0.5;
                        const auto cy =
                            (static_cast<double>(frame_a.image.rows) - 1.0) * 0.5;
                        const cv::Mat common_k = (cv::Mat_<double>(3, 3) <<
                            focal, 0.0, cx,
                            0.0, focal, cy,
                            0.0, 0.0, 1.0);

                        projection_a = cv::Mat::zeros(3, 4, CV_64F);
                        projection_b = cv::Mat::zeros(3, 4, CV_64F);
                        common_k.copyTo(projection_a(cv::Rect(0, 0, 3, 3)));
                        common_k.copyTo(projection_b(cv::Rect(0, 0, 3, 3)));

                        const int axis_row =
                            cached_axis == RectificationAxis::Vertical ? 1 : 0;
                        const auto dominant_translation =
                            calibration.translation_mm[
                                static_cast<std::size_t>(axis_row)];
                        auto baseline_mm = calibration.measured_baseline_mm;
                        if (!std::isfinite(baseline_mm) || baseline_mm <= 0.0) {
                            baseline_mm = std::sqrt(
                                calibration.translation_mm[0] *
                                    calibration.translation_mm[0] +
                                calibration.translation_mm[1] *
                                    calibration.translation_mm[1] +
                                calibration.translation_mm[2] *
                                    calibration.translation_mm[2]);
                        }
                        const auto signed_baseline_mm =
                            std::copysign(baseline_mm, dominant_translation);
                        projection_b.at<double>(axis_row, 3) =
                            focal * signed_baseline_mm;

                        q = cv::Mat::zeros(4, 4, CV_64F);
                        q.at<double>(0, 0) = 1.0;
                        q.at<double>(1, 1) = 1.0;
                        q.at<double>(0, 3) = -cx;
                        q.at<double>(1, 3) = -cy;
                        q.at<double>(2, 3) = focal;
                        q.at<double>(3, 2) = -1.0 / signed_baseline_mm;
                    }

                    cached_projection_shift = projection_shift(
                        projection_b,
                        cached_axis,
                        calibration.translation_mm);
                    cached_rectified_focal_px = std::abs(
                        cached_axis == RectificationAxis::Vertical
                            ? projection_a.at<double>(1, 1)
                            : projection_a.at<double>(0, 0));
                    if (!std::isfinite(cached_rectified_focal_px) ||
                        cached_rectified_focal_px <= 1.0) {
                        throw std::runtime_error("rectified focal length is unavailable");
                    }

                    // Pass complete 3x4 effective P1/P2 matrices, matching the
                    // Android path. They are either native OpenCV output or the
                    // finite common-camera fallback above.
                    cv::initUndistortRectifyMap(
                        k_a, d_a, rectification_a, projection_a,
                        frame_a.image.size(), CV_32FC1, map_a_x, map_a_y);
                    cv::initUndistortRectifyMap(
                        k_b, d_b, rectification_b, projection_b,
                        frame_b.image.size(), CV_32FC1, map_b_x, map_b_y);
                    cached_map_valid_fraction_a =
                        map_valid_fraction(map_a_x, map_a_y, frame_a.image.size());
                    cached_map_valid_fraction_b =
                        map_valid_fraction(map_b_x, map_b_y, frame_b.image.size());

                    append_diagnostic({
                        {"event", "STEREO_RECTIFICATION_MAPS_READY"},
                        {"pair_index", pair.pair_index},
                        {"input_width", frame_a.image.cols},
                        {"input_height", frame_a.image.rows},
                        {"rectification_axis", rectification_axis_name(cached_axis)},
                        {"rectified_projection_shift", cached_projection_shift},
                        {"map_valid_fraction_a", cached_map_valid_fraction_a},
                        {"map_valid_fraction_b", cached_map_valid_fraction_b},
                        {"camera_a_rotation_metadata", pair.camera_a.rotation_degrees},
                        {"camera_b_rotation_metadata", pair.camera_b.rotation_degrees},
                        {"projection_fallback_used", projection_fallback_used},
                        {"R1", matrix_json(rectification_a)},
                        {"R2", matrix_json(rectification_b)},
                        {"P1", matrix_json(projection_a)},
                        {"P2", matrix_json(projection_b)},
                        {"Q", matrix_json(q)},
                    });
                    map_revision = calibration.revision;
                    map_size = frame_a.image.size();
                }

                cv::Mat rectified_native_a;
                cv::Mat rectified_native_b;
                cv::remap(frame_a.image, rectified_native_a, map_a_x, map_a_y,
                          cv::INTER_LINEAR, cv::BORDER_CONSTANT);
                cv::remap(frame_b.image, rectified_native_b, map_b_x, map_b_y,
                          cv::INTER_LINEAR, cv::BORDER_CONSTANT);
                const auto current_rectified_a_statistics =
                    image_statistics(rectified_native_a);
                const auto current_rectified_b_statistics =
                    image_statistics(rectified_native_b);
                try {
                    require_usable_rectified_image(
                        current_rectified_a_statistics, "CAMERA_A");
                    require_usable_rectified_image(
                        current_rectified_b_statistics, "CAMERA_B");
                } catch (const std::exception& error) {
                    throw std::runtime_error(
                        std::string(error.what()) +
                        "; map_valid_fraction_a=" +
                        std::to_string(cached_map_valid_fraction_a) +
                        "; map_valid_fraction_b=" +
                        std::to_string(cached_map_valid_fraction_b));
                }

                // OpenCV supports vertical stereo, while StereoSGBM searches along x.
                // Match Android: P2.y < 0 -> CCW; P2.y > 0 -> CW.
                auto rectified_a = orient_for_horizontal_disparity(
                    rectified_native_a, cached_axis, cached_projection_shift);
                auto rectified_b = orient_for_horizontal_disparity(
                    rectified_native_b, cached_axis, cached_projection_shift);
                if (rectified_a.size() != rectified_b.size()) {
                    throw std::runtime_error("oriented rectified dimensions differ");
                }

                const auto processing_rotation =
                    cached_axis == RectificationAxis::Vertical
                        ? (cached_projection_shift < 0.0 ? -90 : 90)
                        : 0;
                const auto normalize_degrees = [](const int value) {
                    return ((value % 360) + 360) % 360;
                };
                const auto rotate_for_display = [](const cv::Mat& source,
                                                    const int degrees) {
                    cv::Mat result;
                    switch (degrees) {
                        case 0:
                            return source;
                        case 90:
                            cv::rotate(source, result, cv::ROTATE_90_CLOCKWISE);
                            break;
                        case 180:
                            cv::rotate(source, result, cv::ROTATE_180);
                            break;
                        case 270:
                            cv::rotate(source, result,
                                       cv::ROTATE_90_COUNTERCLOCKWISE);
                            break;
                        default:
                            throw std::runtime_error(
                                "unsupported rectified display rotation");
                    }
                    return result;
                };
                const auto display_rotation_a = normalize_degrees(
                    pair.camera_a.rotation_degrees - processing_rotation);
                const auto display_rotation_b = normalize_degrees(
                    pair.camera_b.rotation_degrees - processing_rotation);

                auto depth = depth_runtime.process(
                    rectified_a,
                    rectified_b,
                    cached_axis == RectificationAxis::Vertical,
                    cached_rectified_focal_px,
                    calibration.measured_baseline_mm,
                    *depth_budget);
                auto display_a = rotate_for_display(depth.work_a, display_rotation_a);
                auto display_b = rotate_for_display(depth.work_b, display_rotation_b);
                auto display_disparity = rotate_for_display(
                    depth.disparity_preview, display_rotation_a);
                auto display_depth_raw = rotate_for_display(
                    depth.raw_depth_preview, display_rotation_a);
                auto display_depth_filtered = rotate_for_display(
                    depth.filtered_depth_preview, display_rotation_a);
                auto display_depth_strict = rotate_for_display(
                    depth.strict_depth_preview, display_rotation_a);
                auto display_confidence = rotate_for_display(
                    depth.confidence_preview, display_rotation_a);
                auto preview_a = encode_jpeg(with_epipolar_guides(display_a));
                auto preview_b = encode_jpeg(with_epipolar_guides(display_b));
                auto preview_disparity = encode_jpeg(display_disparity);
                auto preview_depth_raw = encode_jpeg(display_depth_raw);
                auto preview_depth_filtered = encode_jpeg(display_depth_filtered);
                auto preview_depth_strict = encode_jpeg(display_depth_strict);
                auto preview_confidence = encode_jpeg(display_confidence);

                const auto preview_finished = std::chrono::steady_clock::now();
                if (
                    last_processed_image_write.time_since_epoch().count() == 0 ||
                    preview_finished - last_processed_image_write >=
                        std::chrono::seconds{1}
                ) {
                    write_binary_atomic(
                        session_directory / "rectified_a_latest.jpg", preview_a);
                    write_binary_atomic(
                        session_directory / "rectified_b_latest.jpg", preview_b);
                    write_binary_atomic(
                        session_directory / "disparity_latest.jpg", preview_disparity);
                    write_binary_atomic(
                        session_directory / "depth_raw_latest.jpg", preview_depth_raw);
                    write_binary_atomic(
                        session_directory / "depth_filtered_latest.jpg", preview_depth_filtered);
                    write_binary_atomic(
                        session_directory / "depth_strict_latest.jpg", preview_depth_strict);
                    write_binary_atomic(
                        session_directory / "confidence_latest.jpg", preview_confidence);
                    last_processed_image_write = preview_finished;
                }

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
                        depth_raw_jpeg = std::move(preview_depth_raw);
                        depth_filtered_jpeg = std::move(preview_depth_filtered);
                        depth_strict_jpeg = std::move(preview_depth_strict);
                        confidence_jpeg = std::move(preview_confidence);
                        processed += 1;
                        maps_ready = true;
                        runtime_width = depth.work_width;
                        runtime_height = depth.work_height;
                        rectification_axis_state =
                            rectification_axis_name(cached_axis);
                        processing_rotation_degrees = processing_rotation;
                        rectified_projection_shift = cached_projection_shift;
                        map_valid_fraction_a = cached_map_valid_fraction_a;
                        map_valid_fraction_b = cached_map_valid_fraction_b;
                        raw_a_statistics = current_raw_a_statistics;
                        raw_b_statistics = current_raw_b_statistics;
                        rectified_a_statistics = current_rectified_a_statistics;
                        rectified_b_statistics = current_rectified_b_statistics;
                        last_duration_ms = duration_ms;
                        last_attempt_pair_index = pair.pair_index;
                        last_success_pair_index = pair.pair_index;
                        last_pair_delta_ms = pair.delta_ms;
                        valid_disparity_ratio = depth.filtered_valid_ratio;
                        min_disparity = depth.min_disparity;
                        num_disparities = depth.num_disparities;
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
                            {"rectification_axis", rectification_axis_state},
                            {"processing_rotation_degrees", processing_rotation},
                            {"display_rotation_a_degrees", display_rotation_a},
                            {"display_rotation_b_degrees", display_rotation_b},
                            {"rectified_projection_shift", cached_projection_shift},
                            {"map_valid_fraction_a", cached_map_valid_fraction_a},
                            {"map_valid_fraction_b", cached_map_valid_fraction_b},
                            {"raw_a_mean_luma", current_raw_a_statistics.mean_luma},
                            {"raw_b_mean_luma", current_raw_b_statistics.mean_luma},
                            {"rectified_a_mean_luma",
                             current_rectified_a_statistics.mean_luma},
                            {"rectified_b_mean_luma",
                             current_rectified_b_statistics.mean_luma},
                            {"rectified_a_nonzero_fraction",
                             current_rectified_a_statistics.nonzero_fraction},
                            {"rectified_b_nonzero_fraction",
                             current_rectified_b_statistics.nonzero_fraction},
                            {"selection_mode", depth_budget->selection_mode},
                            {"quality_profile", depth_budget->profile_name},
                            {"target_depth_fps", depth_budget->target_depth_fps},
                            {"work_width", depth.work_width},
                            {"work_height", depth.work_height},
                            {"source_upscaled", depth.source_upscaled},
                            {"raw_valid_ratio", depth.raw_valid_ratio},
                            {"filtered_valid_ratio", depth.filtered_valid_ratio},
                            {"dense_coverage_ratio", depth.dense_coverage_ratio},
                            {"stable_coverage_ratio", depth.stable_coverage_ratio},
                            {"high_confidence_ratio", depth.high_confidence_ratio},
                            {"median_depth_m", depth.median_depth_m ? nlohmann::json(*depth.median_depth_m) : nlohmann::json(nullptr)},
                            {"depth_jitter_m", depth.depth_jitter_m ? nlohmann::json(*depth.depth_jitter_m) : nlohmann::json(nullptr)},
                            {"motion_score_percent", depth.motion_score_percent},
                            {"temporal_mode", depth.temporal_mode},
                            {"left_right_accepted_percent", depth.left_right_accepted_percent},
                            {"texture_accepted_percent", depth.texture_accepted_percent},
                            {"morphology_accepted_percent", depth.morphology_accepted_percent},
                            {"focal_px", depth.focal_px},
                            {"baseline_mm", depth.baseline_mm},
                            {"valid_disparity_ratio", depth.filtered_valid_ratio},
                            {"min_disparity", depth.min_disparity},
                            {"num_disparities", depth.num_disparities},
                            {"width", depth.work_width},
                            {"height", depth.work_height},
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
    std::vector<std::uint8_t> depth_raw_jpeg;
    std::vector<std::uint8_t> depth_filtered_jpeg;
    std::vector<std::uint8_t> depth_strict_jpeg;
    std::vector<std::uint8_t> confidence_jpeg;
    StereoDepthRuntime depth_runtime;
    std::uint64_t submitted = 0;
    std::uint64_t processed = 0;
    std::uint64_t failed = 0;
    std::uint64_t skipped_not_ready = 0;
    std::uint64_t queue_replaced = 0;
    std::uint64_t stale_results_discarded = 0;
    bool maps_ready = false;
    int runtime_width = 0;
    int runtime_height = 0;
    std::string rectification_axis_state = "UNKNOWN";
    int processing_rotation_degrees = 0;
    double rectified_projection_shift = 0.0;
    double map_valid_fraction_a = 0.0;
    double map_valid_fraction_b = 0.0;
    ImageStatistics raw_a_statistics;
    ImageStatistics raw_b_statistics;
    ImageStatistics rectified_a_statistics;
    ImageStatistics rectified_b_statistics;
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

nlohmann::json StereoPreview::select_depth_profile(const std::string& mode) {
    return impl_->select_depth_profile(mode);
}

nlohmann::json StereoPreview::depth_profiles_json() const {
    return impl_->depth_profiles_json();
}

nlohmann::json StereoPreview::status_json() const {
    return impl_->status_json();
}

std::optional<std::vector<std::uint8_t>> StereoPreview::image(
    const StereoPreviewImage kind) const {
    return impl_->image(kind);
}

}  // namespace maklertour::dual_phone
