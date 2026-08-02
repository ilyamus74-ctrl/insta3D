#pragma once

#include <cstddef>
#include <cstdint>
#include <string>
#include <vector>

#include <nlohmann/json.hpp>

namespace maklertour::dual_phone {

inline constexpr std::uint32_t kProtocolSchema = 1;
inline constexpr std::size_t kMaxHelloBytes = 16U * 1024U;
inline constexpr std::size_t kMaxHeaderBytes = 64U * 1024U;
inline constexpr std::size_t kMaxPayloadBytes = 2U * 1024U * 1024U;

struct WireMessage {
    nlohmann::json header;
    std::vector<std::uint8_t> payload;
};

bool read_exact(int fd, void* destination, std::size_t bytes);
bool write_all(int fd, const void* source, std::size_t bytes);
std::string read_line(int fd, std::size_t max_bytes);
void write_line(int fd, const std::string& value);
std::uint32_t read_u32_be(int fd);
void write_u32_be(int fd, std::uint32_t value);
WireMessage read_message(int fd);
void write_message(int fd, const nlohmann::json& header,
                   const std::vector<std::uint8_t>& payload);
std::uint32_t crc32(const std::uint8_t* data, std::size_t size);
std::uint32_t crc32(const std::vector<std::uint8_t>& data);
std::string utc_iso8601_now();
std::int64_t monotonic_ns();

}  // namespace maklertour::dual_phone
