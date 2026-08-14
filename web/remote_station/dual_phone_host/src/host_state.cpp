#include "host_state.hpp"

#include "protocol.hpp"

#include <algorithm>
#include <chrono>
#include <cmath>
#include <cstdlib>
#include <iomanip>
#include <limits>
#include <sstream>
#include <stdexcept>

namespace maklertour::dual_phone {

namespace {

constexpr double kReadyPairDeltaMs = 25.0;
constexpr double kRelaxedPairDeltaMs = 60.0;
constexpr double kRelaxedPairGraceMs = 250.0;
constexpr std::size_t kEventRingSize = 200;
constexpr std::size_t kPairQueueCapacity = 12;
constexpr std::size_t kDefaultColmapPairStride = 3;
constexpr std::size_t kMaximumColmapPairStride = 60;

std::size_t colmap_pair_stride_from_environment() {
    const char* raw = std::getenv("MAKLER_COLMAP_PAIR_STRIDE");
    if (raw == nullptr || *raw == '\0') return kDefaultColmapPairStride;
    try {
        std::size_t consumed = 0;
        const auto value = std::stoull(raw, &consumed);
        if (consumed != std::string(raw).size()) return kDefaultColmapPairStride;
        return static_cast<std::size_t>(std::min<unsigned long long>(
            value, kMaximumColmapPairStride));
    } catch (...) {
        return kDefaultColmapPairStride;
    }
}

std::int64_t steady_now_ns() {
    return std::chrono::duration_cast<std::chrono::nanoseconds>(
        std::chrono::steady_clock::now().time_since_epoch()).count();
}

std::string session_stamp() {
    auto value = utc_iso8601_now();
    std::replace(value.begin(), value.end(), ':', '-');
    return value;
}

std::size_t slot_index(const CameraSlot slot) {
    return static_cast<std::size_t>(slot);
}

nlohmann::json frame_metadata(const FrameRecord& frame) {
    nlohmann::json value = {
        {"device_id", frame.device_id},
        {"session_id", frame.session_id},
        {"sequence", frame.sequence},
        {"sensor_timestamp_ns", frame.sensor_timestamp_ns},
        {"capture_elapsed_ns", frame.capture_elapsed_ns},
        {"received_monotonic_ns", frame.received_monotonic_ns},
        {"pair_timestamp_ns", frame.pair_timestamp_ns},
        {"width", frame.width},
        {"height", frame.height},
        {"rotation_degrees", frame.rotation_degrees},
        {"payload_bytes", frame.jpeg.size()},
        {"payload_crc32", frame.payload_crc32},
    };
    for (const auto* key : {
             "clock_offset_ns",
             "clock_rtt_ns",
             "clock_samples",
             "sender_frames_offered",
             "sender_frames_replaced_before_send",
         }) {
        if (frame.header.contains(key)) value[key] = frame.header.at(key);
    }
    if (frame.header.contains("tof_registered") &&
        frame.header.at("tof_registered").is_object()) {
        value["tof_registered"] = frame.header.at("tof_registered");
    }
    return value;
}

StereoPreviewFrame preview_frame(FrameRecord frame) {
    StereoPreviewFrame result;
    result.sequence = frame.sequence;
    result.pair_timestamp_ns = frame.pair_timestamp_ns;
    result.width = frame.width;
    result.height = frame.height;
    result.rotation_degrees = frame.rotation_degrees;
    result.jpeg = std::move(frame.jpeg);
    return result;
}

}  // namespace

std::string slot_name(const CameraSlot slot) {
    return slot == CameraSlot::A ? "CAMERA_A" : "CAMERA_B";
}

CameraSlot parse_slot(const std::string& value) {
    if (value == "CAMERA_A" || value == "A") return CameraSlot::A;
    if (value == "CAMERA_B" || value == "B") return CameraSlot::B;
    throw std::runtime_error("camera slot must be CAMERA_A or CAMERA_B");
}

HostState::HostState(std::filesystem::path output_root,
                     const std::size_t archive_every)
    : archive_every_(archive_every),
      colmap_pair_stride_(colmap_pair_stride_from_environment()) {
    session_dir_ = std::move(output_root) / session_stamp();
    std::filesystem::create_directories(session_dir_ / "camera_a");
    std::filesystem::create_directories(session_dir_ / "camera_b");
    std::filesystem::create_directories(
        session_dir_ / "colmap_frames" / "CAMERA_A");
    std::filesystem::create_directories(
        session_dir_ / "colmap_frames" / "CAMERA_B");
    events_file_.open(session_dir_ / "events.jsonl", std::ios::app);
    pairs_file_.open(session_dir_ / "pairs.jsonl", std::ios::app);
    imu_a_file_.open(session_dir_ / "imu_a.jsonl", std::ios::app);
    imu_b_file_.open(session_dir_ / "imu_b.jsonl", std::ios::app);
    colmap_pairs_file_.open(
        session_dir_ / "colmap_pairs.jsonl", std::ios::app);
    if (!events_file_ || !pairs_file_ || !imu_a_file_ || !imu_b_file_ ||
        !colmap_pairs_file_) {
        throw std::runtime_error("cannot create session log files");
    }
    stereo_preview_ = std::make_unique<StereoPreview>(session_dir_);
    nlohmann::json session = {
        {"schema_version", 1},
        {"created_at", utc_iso8601_now()},
        {"mode", "LM02.7B.5.3.1_OFFLINE_COLMAP_RIG"},
        {"camera_roles", {"CAMERA_A", "CAMERA_B"}},
        {"archive_every", archive_every_},
        {"colmap_pair_stride", colmap_pair_stride_},
        {"offline_colmap_capture", "STRICT_SYNCHRONIZED_PAIRS"},
        {"stereo_processing", "OPENCV_RECTIFY_FILTERED_METRIC_DEPTH"},
    };
    std::ofstream(session_dir_ / "session.json") << std::setw(2) << session << '\n';
    log_event("INFO", "HOST_STARTED", {{"session_dir", session_dir_.string()}});
}

bool HostState::camera_connected(const CameraSlot slot,
                                 const std::string& device_id,
                                 const std::string& remote_address,
                                 const nlohmann::json& hello) {
    std::scoped_lock lock(mutex_);
    auto& camera = cameras_[slot_index(slot)];
    if (camera.connected) return false;
    camera.connected = true;
    camera.device_id = device_id;
    camera.remote_address = remote_address;
    stereo_preview_->set_camera_identity(slot_index(slot), device_id);
    stereo_preview_->notify_camera_event(
        slot_index(slot),
        "CONNECTED",
        device_id);

    const auto hello_path = session_dir_ /
        (slot == CameraSlot::A ? "camera_a_hello.json" : "camera_b_hello.json");
    std::ofstream(hello_path) << std::setw(2) << hello << '\n';
    if (slot == CameraSlot::A) {
        if (hello.contains("calibration_profile") &&
            hello.at("calibration_profile").is_object()) {
            const auto& profile = hello.at("calibration_profile");
            std::ofstream(session_dir_ / "stereo_calibration.json")
                << std::setw(2) << profile << '\n';
            stereo_preview_->set_calibration_profile(profile);
        } else {
            stereo_preview_->clear_calibration_profile();
        }
    }

    nlohmann::json event = {
        {"ts", utc_iso8601_now()}, {"level", "INFO"},
        {"event", "CAMERA_CONNECTED"}, {"slot", slot_name(slot)},
        {"device_id", device_id}, {"remote_address", remote_address},
        {"hello", hello},
    };
    append_jsonl_locked(events_file_, event);
    events_.push_back(event);
    while (events_.size() > kEventRingSize) events_.pop_front();
    return true;
}

void HostState::camera_disconnected(const CameraSlot slot,
                                    const std::string& reason) {
    std::scoped_lock lock(mutex_);
    auto& camera = cameras_[slot_index(slot)];
    camera.connected = false;
    camera.pair_frames_dropped += camera.pair_queue.size();
    camera.pair_queue.clear();
    stereo_preview_->notify_camera_event(
        slot_index(slot),
        "DISCONNECTED",
        camera.device_id);
    nlohmann::json event = {
        {"ts", utc_iso8601_now()}, {"level", "WARN"},
        {"event", "CAMERA_DISCONNECTED"}, {"slot", slot_name(slot)},
        {"reason", reason},
    };
    append_jsonl_locked(events_file_, event);
    events_.push_back(event);
    while (events_.size() > kEventRingSize) events_.pop_front();
}

void HostState::accept_frame(const CameraSlot slot, FrameRecord frame) {
    std::scoped_lock lock(mutex_);
    auto& camera = cameras_[slot_index(slot)];
    camera.frames += 1;
    camera.bytes += frame.jpeg.size();
    if (camera.first_frame_ns == 0) camera.first_frame_ns = frame.received_monotonic_ns;
    camera.last_frame_ns = frame.received_monotonic_ns;
    camera.latest = frame;
    maybe_archive_locked(slot, frame);
    camera.pair_queue.push_back(std::move(frame));
    while (camera.pair_queue.size() > kPairQueueCapacity) {
        camera.pair_queue.pop_front();
        camera.pair_frames_dropped += 1;
    }
    update_pair_locked();
}

void HostState::accept_imu(const CameraSlot slot, const nlohmann::json& sample) {
    std::scoped_lock lock(mutex_);
    nlohmann::json value = sample;
    value["received_at"] = utc_iso8601_now();
    value["slot"] = slot_name(slot);
    append_jsonl_locked(slot == CameraSlot::A ? imu_a_file_ : imu_b_file_, value);
    stereo_preview_->accept_imu(slot_index(slot), value);
}

void HostState::log_event(const std::string& level, const std::string& event,
                          nlohmann::json details) {
    std::scoped_lock lock(mutex_);
    nlohmann::json value = {
        {"ts", utc_iso8601_now()}, {"level", level}, {"event", event},
        {"details", std::move(details)},
    };
    append_jsonl_locked(events_file_, value);
    events_.push_back(value);
    while (events_.size() > kEventRingSize) events_.pop_front();
}

CameraSnapshot HostState::camera(const CameraSlot slot) const {
    std::scoped_lock lock(mutex_);
    const auto& source = cameras_[slot_index(slot)];
    CameraSnapshot result;
    result.connected = source.connected;
    result.device_id = source.device_id;
    result.remote_address = source.remote_address;
    result.frames = source.frames;
    result.bytes = source.bytes;
    result.crc_errors = source.crc_errors;
    result.latest = source.latest;
    if (source.first_frame_ns > 0 && source.last_frame_ns > source.first_frame_ns) {
        const auto seconds = static_cast<double>(source.last_frame_ns - source.first_frame_ns) /
                             1'000'000'000.0;
        result.fps = static_cast<double>(source.frames - 1) / seconds;
    }
    return result;
}

nlohmann::json HostState::status_json() const {
    std::scoped_lock lock(mutex_);
    auto camera_json = [](const MutableCamera& camera) {
        double fps = 0.0;
        if (camera.first_frame_ns > 0 && camera.last_frame_ns > camera.first_frame_ns) {
            fps = static_cast<double>(camera.frames - 1) /
                  (static_cast<double>(camera.last_frame_ns - camera.first_frame_ns) /
                   1'000'000'000.0);
        }
        nlohmann::json value = {
            {"connected", camera.connected}, {"device_id", camera.device_id},
            {"remote_address", camera.remote_address}, {"frames", camera.frames},
            {"bytes", camera.bytes}, {"fps", fps},
            {"pair_queue_depth", camera.pair_queue.size()},
            {"pair_frames_dropped", camera.pair_frames_dropped},
        };
        if (camera.latest) value["latest"] = frame_metadata(*camera.latest);
        return value;
    };
    return {
        {"schema_version", 1},
        {"updated_at", utc_iso8601_now()},
        {"session_dir", session_dir_.string()},
        {"camera_a", camera_json(cameras_[0])},
        {"camera_b", camera_json(cameras_[1])},
        {"pairing", {
            {"pairs", pair_count_},
            {"ready_pairs", pair_ready_count_},
            {"relaxed_pairs", pair_relaxed_count_},
            {"last_delta_ms", last_pair_delta_ms_},
            {"last_mode", last_pair_mode_},
            {"ready_limit_ms", kReadyPairDeltaMs},
            {"relaxed_limit_ms", kRelaxedPairDeltaMs},
            {"relaxed_grace_ms", kRelaxedPairGraceMs},
            {"queue_a", cameras_[0].pair_queue.size()},
            {"queue_b", cameras_[1].pair_queue.size()},
            {"dropped_a", cameras_[0].pair_frames_dropped},
            {"dropped_b", cameras_[1].pair_frames_dropped},
            {"colmap_pair_stride", colmap_pair_stride_},
            {"colmap_archived_pairs", colmap_archived_pairs_},
            {"colmap_archive_errors", colmap_archive_errors_},
        }},
        {"stereo_preview", stereo_preview_->status_json()},
    };
}

std::vector<nlohmann::json> HostState::recent_events() const {
    std::scoped_lock lock(mutex_);
    return {events_.begin(), events_.end()};
}

std::filesystem::path HostState::session_directory() const {
    std::scoped_lock lock(mutex_);
    return session_dir_;
}

std::optional<std::vector<std::uint8_t>> HostState::stereo_preview_image(
    const StereoPreviewImage kind) const {
    return stereo_preview_->image(kind);
}

nlohmann::json HostState::live_preview_json() const {
    return stereo_preview_->live_status_json();
}

nlohmann::json HostState::depth_probe(
    const double normalized_x,
    const double normalized_y) const {
    return stereo_preview_->depth_probe(normalized_x, normalized_y);
}

nlohmann::json HostState::select_depth_profile(const std::string& mode) {
    return stereo_preview_->select_depth_profile(mode);
}

nlohmann::json HostState::depth_profiles_json() const {
    return stereo_preview_->depth_profiles_json();
}

void HostState::archive_colmap_pair_locked(
    const std::uint64_t pair_index,
    const FrameRecord& frame_a,
    const FrameRecord& frame_b,
    const double delta_ms) {
    if (colmap_pair_stride_ == 0 ||
        ((pair_ready_count_ - 1) % colmap_pair_stride_) != 0) {
        return;
    }
    std::ostringstream name;
    name << std::setw(12) << std::setfill('0') << pair_index << ".jpg";
    const auto filename = name.str();
    const auto relative_a =
        std::filesystem::path("colmap_frames") / "CAMERA_A" / filename;
    const auto relative_b =
        std::filesystem::path("colmap_frames") / "CAMERA_B" / filename;
    const auto path_a = session_dir_ / relative_a;
    const auto path_b = session_dir_ / relative_b;

    std::ofstream image_a(path_a, std::ios::binary | std::ios::trunc);
    std::ofstream image_b(path_b, std::ios::binary | std::ios::trunc);
    if (!image_a || !image_b) {
        ++colmap_archive_errors_;
        return;
    }
    image_a.write(
        reinterpret_cast<const char*>(frame_a.jpeg.data()),
        static_cast<std::streamsize>(frame_a.jpeg.size()));
    image_b.write(
        reinterpret_cast<const char*>(frame_b.jpeg.data()),
        static_cast<std::streamsize>(frame_b.jpeg.size()));
    image_a.flush();
    image_b.flush();
    if (!image_a || !image_b) {
        image_a.close();
        image_b.close();
        std::error_code error;
        std::filesystem::remove(path_a, error);
        error.clear();
        std::filesystem::remove(path_b, error);
        ++colmap_archive_errors_;
        return;
    }

    append_jsonl_locked(colmap_pairs_file_, {
        {"ts", utc_iso8601_now()},
        {"pair_index", pair_index},
        {"camera_a_sequence", frame_a.sequence},
        {"camera_b_sequence", frame_b.sequence},
        {"camera_a_timestamp_ns", frame_a.pair_timestamp_ns},
        {"camera_b_timestamp_ns", frame_b.pair_timestamp_ns},
        {"camera_a_width", frame_a.width},
        {"camera_a_height", frame_a.height},
        {"camera_b_width", frame_b.width},
        {"camera_b_height", frame_b.height},
        {"camera_a_rotation_degrees", frame_a.rotation_degrees},
        {"camera_b_rotation_degrees", frame_b.rotation_degrees},
        {"delta_ms", delta_ms},
        {"sync_mode", "STRICT"},
        {"image_a", relative_a.generic_string()},
        {"image_b", relative_b.generic_string()},
    });
    ++colmap_archived_pairs_;
}

void HostState::maybe_archive_locked(const CameraSlot slot,
                                     const FrameRecord& frame) {
    if (archive_every_ == 0 || frame.sequence % archive_every_ != 0) return;
    const auto directory = session_dir_ /
        (slot == CameraSlot::A ? "camera_a" : "camera_b");
    std::ostringstream name;
    name << std::setw(12) << std::setfill('0') << frame.sequence;
    const auto stem = name.str();
    std::ofstream image(directory / (stem + ".jpg"), std::ios::binary);
    image.write(reinterpret_cast<const char*>(frame.jpeg.data()),
                static_cast<std::streamsize>(frame.jpeg.size()));
    std::ofstream(directory / (stem + ".json"))
        << std::setw(2) << frame_metadata(frame) << '\n';
}

void HostState::update_pair_locked() {
    auto& queue_a = cameras_[0].pair_queue;
    auto& queue_b = cameras_[1].pair_queue;
    const auto strict_limit_ns = static_cast<std::int64_t>(
        kReadyPairDeltaMs * 1'000'000.0);
    const auto relaxed_limit_ns = static_cast<std::int64_t>(
        kRelaxedPairDeltaMs * 1'000'000.0);
    const auto relaxed_grace_ns = static_cast<std::int64_t>(
        kRelaxedPairGraceMs * 1'000'000.0);

    while (!queue_a.empty() && !queue_b.empty()) {
        std::size_t best_a = 0;
        std::size_t best_b = 0;
        std::int64_t best_delta_ns = std::numeric_limits<std::int64_t>::max();

        for (std::size_t index_a = 0; index_a < queue_a.size(); ++index_a) {
            for (std::size_t index_b = 0; index_b < queue_b.size(); ++index_b) {
                const auto delta_ns = std::abs(
                    queue_a[index_a].pair_timestamp_ns -
                    queue_b[index_b].pair_timestamp_ns);
                if (delta_ns < best_delta_ns) {
                    best_delta_ns = delta_ns;
                    best_a = index_a;
                    best_b = index_b;
                }
            }
        }

        const bool strict_ready = best_delta_ns <= strict_limit_ns;
        bool relaxed_ready = false;
        if (!strict_ready && best_delta_ns <= relaxed_limit_ns) {
            const auto now_ns = steady_now_ns();
            const auto candidate_ready_ns = std::max(
                queue_a[best_a].received_monotonic_ns,
                queue_b[best_b].received_monotonic_ns);
            const auto reference_ns = last_strict_pair_monotonic_ns_ > 0
                ? last_strict_pair_monotonic_ns_
                : candidate_ready_ns;
            relaxed_ready = reference_ns > 0 &&
                now_ns - reference_ns >= relaxed_grace_ns;
            if (!relaxed_ready) {
                break;
            }
        }

        if (strict_ready || relaxed_ready) {
            cameras_[0].pair_frames_dropped += best_a;
            cameras_[1].pair_frames_dropped += best_b;
            queue_a.erase(
                queue_a.begin(),
                queue_a.begin() + static_cast<std::ptrdiff_t>(best_a));
            queue_b.erase(
                queue_b.begin(),
                queue_b.begin() + static_cast<std::ptrdiff_t>(best_b));

            FrameRecord frame_a = std::move(queue_a.front());
            FrameRecord frame_b = std::move(queue_b.front());
            queue_a.pop_front();
            queue_b.pop_front();

            pair_count_ += 1;
            last_pair_delta_ms_ =
                static_cast<double>(best_delta_ns) / 1'000'000.0;
            last_pair_mode_ = strict_ready ? "STRICT" : "RELAXED";
            if (strict_ready) {
                pair_ready_count_ += 1;
                last_strict_pair_monotonic_ns_ = steady_now_ns();
                archive_colmap_pair_locked(
                    pair_count_, frame_a, frame_b, last_pair_delta_ms_);
            } else {
                pair_relaxed_count_ += 1;
            }
            append_jsonl_locked(pairs_file_, {
                {"ts", utc_iso8601_now()},
                {"pair_index", pair_count_},
                {"camera_a_sequence", frame_a.sequence},
                {"camera_b_sequence", frame_b.sequence},
                {"camera_a_timestamp_ns", frame_a.pair_timestamp_ns},
                {"camera_b_timestamp_ns", frame_b.pair_timestamp_ns},
                {"delta_ms", last_pair_delta_ms_},
                {"sync_mode", last_pair_mode_},
                {"ready", strict_ready},
                {"live_only", !strict_ready},
            });

            StereoPreviewPair preview_pair;
            preview_pair.pair_index = pair_count_;
            preview_pair.delta_ms = last_pair_delta_ms_;
            preview_pair.sync_mode = last_pair_mode_;
            preview_pair.camera_a = preview_frame(std::move(frame_a));
            preview_pair.camera_b = preview_frame(std::move(frame_b));
            if (strict_ready) {
                stereo_preview_->submit(std::move(preview_pair));
            } else {
                stereo_preview_->submit_live_only(std::move(preview_pair));
            }
            continue;
        }

        const auto oldest_a = queue_a.front().pair_timestamp_ns;
        const auto oldest_b = queue_b.front().pair_timestamp_ns;
        if (
            oldest_a + relaxed_limit_ns <
            queue_b.back().pair_timestamp_ns
        ) {
            queue_a.pop_front();
            cameras_[0].pair_frames_dropped += 1;
            continue;
        }
        if (
            oldest_b + relaxed_limit_ns <
            queue_a.back().pair_timestamp_ns
        ) {
            queue_b.pop_front();
            cameras_[1].pair_frames_dropped += 1;
            continue;
        }
        break;
    }
}

void HostState::append_jsonl_locked(std::ofstream& stream,
                                    const nlohmann::json& value) {
    stream << value.dump() << '\n';
    stream.flush();
}

}  // namespace maklertour::dual_phone
