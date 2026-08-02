#pragma once

#include <array>
#include <cstdint>
#include <deque>
#include <filesystem>
#include <fstream>
#include <mutex>
#include <optional>
#include <string>
#include <vector>

#include <nlohmann/json.hpp>

namespace maklertour::dual_phone {

enum class CameraSlot : std::size_t { A = 0, B = 1 };

std::string slot_name(CameraSlot slot);
CameraSlot parse_slot(const std::string& value);

struct FrameRecord {
    std::string device_id;
    std::string session_id;
    std::uint64_t sequence = 0;
    std::int64_t sensor_timestamp_ns = 0;
    std::int64_t capture_elapsed_ns = 0;
    std::int64_t received_monotonic_ns = 0;
    std::int64_t pair_timestamp_ns = 0;
    int width = 0;
    int height = 0;
    int rotation_degrees = 0;
    std::uint32_t payload_crc32 = 0;
    std::vector<std::uint8_t> jpeg;
    nlohmann::json header;
};

struct CameraSnapshot {
    bool connected = false;
    std::string device_id;
    std::string remote_address;
    std::uint64_t frames = 0;
    std::uint64_t bytes = 0;
    std::uint64_t crc_errors = 0;
    double fps = 0.0;
    std::optional<FrameRecord> latest;
};

class HostState {
public:
    HostState(std::filesystem::path output_root, std::size_t archive_every);

    bool camera_connected(CameraSlot slot, const std::string& device_id,
                          const std::string& remote_address,
                          const nlohmann::json& hello);
    void camera_disconnected(CameraSlot slot, const std::string& reason);
    void accept_frame(CameraSlot slot, FrameRecord frame);
    void accept_imu(CameraSlot slot, const nlohmann::json& sample);
    void log_event(const std::string& level, const std::string& event,
                   nlohmann::json details = nlohmann::json::object());

    CameraSnapshot camera(CameraSlot slot) const;
    nlohmann::json status_json() const;
    std::vector<nlohmann::json> recent_events() const;
    std::filesystem::path session_directory() const;

private:
    struct MutableCamera {
        bool connected = false;
        std::string device_id;
        std::string remote_address;
        std::uint64_t frames = 0;
        std::uint64_t bytes = 0;
        std::uint64_t crc_errors = 0;
        std::int64_t first_frame_ns = 0;
        std::int64_t last_frame_ns = 0;
        std::optional<FrameRecord> latest;
    };

    void maybe_archive_locked(CameraSlot slot, const FrameRecord& frame);
    void update_pair_locked();
    void append_jsonl_locked(std::ofstream& stream, const nlohmann::json& value);

    mutable std::mutex mutex_;
    std::array<MutableCamera, 2> cameras_;
    std::filesystem::path session_dir_;
    std::size_t archive_every_;
    std::uint64_t pair_count_ = 0;
    std::uint64_t pair_ready_count_ = 0;
    std::uint64_t last_pair_sequence_a_ = 0;
    std::uint64_t last_pair_sequence_b_ = 0;
    double last_pair_delta_ms_ = 0.0;
    std::deque<nlohmann::json> events_;
    std::ofstream events_file_;
    std::ofstream pairs_file_;
    std::ofstream imu_a_file_;
    std::ofstream imu_b_file_;
};

}  // namespace maklertour::dual_phone
