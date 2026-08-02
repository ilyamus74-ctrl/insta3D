#include "host_state.hpp"

#include "protocol.hpp"

#include <algorithm>
#include <cmath>
#include <iomanip>
#include <sstream>
#include <stdexcept>

namespace maklertour::dual_phone {

namespace {

constexpr double kReadyPairDeltaMs = 25.0;
constexpr std::size_t kEventRingSize = 200;

std::string session_stamp() {
    auto value = utc_iso8601_now();
    std::replace(value.begin(), value.end(), ':', '-');
    return value;
}

std::size_t slot_index(const CameraSlot slot) {
    return static_cast<std::size_t>(slot);
}

nlohmann::json frame_metadata(const FrameRecord& frame) {
    return {
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
    : archive_every_(archive_every) {
    session_dir_ = std::move(output_root) / session_stamp();
    std::filesystem::create_directories(session_dir_ / "camera_a");
    std::filesystem::create_directories(session_dir_ / "camera_b");
    events_file_.open(session_dir_ / "events.jsonl", std::ios::app);
    pairs_file_.open(session_dir_ / "pairs.jsonl", std::ios::app);
    imu_a_file_.open(session_dir_ / "imu_a.jsonl", std::ios::app);
    imu_b_file_.open(session_dir_ / "imu_b.jsonl", std::ios::app);
    if (!events_file_ || !pairs_file_ || !imu_a_file_ || !imu_b_file_) {
        throw std::runtime_error("cannot create session log files");
    }
    nlohmann::json session = {
        {"schema_version", 1},
        {"created_at", utc_iso8601_now()},
        {"mode", "LM02.7B.1_CPU_LAPTOP_RECEIVER"},
        {"camera_roles", {"CAMERA_A", "CAMERA_B"}},
        {"archive_every", archive_every_},
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
    cameras_[slot_index(slot)].connected = false;
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
    update_pair_locked();
}

void HostState::accept_imu(const CameraSlot slot, const nlohmann::json& sample) {
    std::scoped_lock lock(mutex_);
    nlohmann::json value = sample;
    value["received_at"] = utc_iso8601_now();
    value["slot"] = slot_name(slot);
    append_jsonl_locked(slot == CameraSlot::A ? imu_a_file_ : imu_b_file_, value);
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
            {"last_delta_ms", last_pair_delta_ms_},
            {"ready_limit_ms", kReadyPairDeltaMs},
        }},
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
    if (!cameras_[0].latest || !cameras_[1].latest) return;
    const auto& a = *cameras_[0].latest;
    const auto& b = *cameras_[1].latest;
    if (a.sequence == last_pair_sequence_a_ && b.sequence == last_pair_sequence_b_) return;
    last_pair_sequence_a_ = a.sequence;
    last_pair_sequence_b_ = b.sequence;
    pair_count_ += 1;
    last_pair_delta_ms_ = std::abs(static_cast<double>(
        a.pair_timestamp_ns - b.pair_timestamp_ns)) / 1'000'000.0;
    const bool ready = last_pair_delta_ms_ <= kReadyPairDeltaMs;
    if (ready) pair_ready_count_ += 1;
    append_jsonl_locked(pairs_file_, {
        {"ts", utc_iso8601_now()}, {"pair_index", pair_count_},
        {"camera_a_sequence", a.sequence}, {"camera_b_sequence", b.sequence},
        {"delta_ms", last_pair_delta_ms_}, {"ready", ready},
    });
}

void HostState::append_jsonl_locked(std::ofstream& stream,
                                    const nlohmann::json& value) {
    stream << value.dump() << '\n';
    stream.flush();
}

}  // namespace maklertour::dual_phone
