#include "protocol.hpp"

#include <arpa/inet.h>
#include <cerrno>
#include <chrono>
#include <cstring>
#include <iomanip>
#include <sstream>
#include <stdexcept>
#include <sys/socket.h>
#include <unistd.h>

namespace maklertour::dual_phone {

namespace {

[[noreturn]] void throw_socket_error(const std::string& operation) {
    throw std::runtime_error(operation + ": " + std::strerror(errno));
}

}  // namespace

bool read_exact(const int fd, void* destination, const std::size_t bytes) {
    auto* out = static_cast<std::uint8_t*>(destination);
    std::size_t received = 0;
    while (received < bytes) {
        const auto result = ::recv(fd, out + received, bytes - received, 0);
        if (result == 0) return false;
        if (result < 0) {
            if (errno == EINTR) continue;
            throw_socket_error("recv");
        }
        received += static_cast<std::size_t>(result);
    }
    return true;
}

bool write_all(const int fd, const void* source, const std::size_t bytes) {
    const auto* input = static_cast<const std::uint8_t*>(source);
    std::size_t sent = 0;
    while (sent < bytes) {
        const auto result = ::send(fd, input + sent, bytes - sent, MSG_NOSIGNAL);
        if (result == 0) return false;
        if (result < 0) {
            if (errno == EINTR) continue;
            throw_socket_error("send");
        }
        sent += static_cast<std::size_t>(result);
    }
    return true;
}

std::string read_line(const int fd, const std::size_t max_bytes) {
    std::string value;
    value.reserve(256);
    while (value.size() < max_bytes) {
        char character = '\0';
        if (!read_exact(fd, &character, 1)) {
            if (value.empty()) return {};
            throw std::runtime_error("connection closed inside line");
        }
        if (character == '\n') return value;
        if (character != '\r') value.push_back(character);
    }
    throw std::runtime_error("line exceeds protocol limit");
}

void write_line(const int fd, const std::string& value) {
    if (!write_all(fd, value.data(), value.size()) ||
        !write_all(fd, "\n", 1)) {
        throw std::runtime_error("connection closed while writing line");
    }
}

std::uint32_t read_u32_be(const int fd) {
    std::uint32_t network_value = 0;
    if (!read_exact(fd, &network_value, sizeof(network_value))) {
        throw std::runtime_error("connection closed while reading uint32");
    }
    return ntohl(network_value);
}

void write_u32_be(const int fd, const std::uint32_t value) {
    const auto network_value = htonl(value);
    if (!write_all(fd, &network_value, sizeof(network_value))) {
        throw std::runtime_error("connection closed while writing uint32");
    }
}

WireMessage read_message(const int fd) {
    const auto header_size = read_u32_be(fd);
    const auto payload_size = read_u32_be(fd);
    if (header_size < 2 || header_size > kMaxHeaderBytes) {
        throw std::runtime_error("invalid message header size");
    }
    if (payload_size > kMaxPayloadBytes) {
        throw std::runtime_error("message payload exceeds 2 MiB limit");
    }

    std::string header_text(header_size, '\0');
    if (!read_exact(fd, header_text.data(), header_text.size())) {
        throw std::runtime_error("connection closed while reading header");
    }
    WireMessage message;
    message.header = nlohmann::json::parse(header_text);
    message.payload.resize(payload_size);
    if (payload_size > 0 &&
        !read_exact(fd, message.payload.data(), message.payload.size())) {
        throw std::runtime_error("connection closed while reading payload");
    }
    return message;
}

void write_message(const int fd, const nlohmann::json& header,
                   const std::vector<std::uint8_t>& payload) {
    const auto header_text = header.dump();
    if (header_text.size() > kMaxHeaderBytes || payload.size() > kMaxPayloadBytes) {
        throw std::runtime_error("outgoing message exceeds protocol limit");
    }
    write_u32_be(fd, static_cast<std::uint32_t>(header_text.size()));
    write_u32_be(fd, static_cast<std::uint32_t>(payload.size()));
    if (!write_all(fd, header_text.data(), header_text.size()) ||
        (!payload.empty() && !write_all(fd, payload.data(), payload.size()))) {
        throw std::runtime_error("connection closed while writing message");
    }
}

std::uint32_t crc32(const std::uint8_t* data, const std::size_t size) {
    std::uint32_t value = 0xFFFFFFFFU;
    for (std::size_t index = 0; index < size; ++index) {
        value ^= data[index];
        for (int bit = 0; bit < 8; ++bit) {
            const auto mask = static_cast<std::uint32_t>(
                -static_cast<std::int32_t>(value & 1U));
            value = (value >> 1U) ^ (0xEDB88320U & mask);
        }
    }
    return ~value;
}

std::uint32_t crc32(const std::vector<std::uint8_t>& data) {
    return crc32(data.data(), data.size());
}

std::string utc_iso8601_now() {
    const auto now = std::chrono::system_clock::now();
    const auto time = std::chrono::system_clock::to_time_t(now);
    const auto milliseconds = std::chrono::duration_cast<std::chrono::milliseconds>(
        now.time_since_epoch()) % 1000;
    std::tm value{};
    gmtime_r(&time, &value);
    std::ostringstream output;
    output << std::put_time(&value, "%Y-%m-%dT%H:%M:%S") << '.'
           << std::setw(3) << std::setfill('0') << milliseconds.count() << 'Z';
    return output.str();
}

std::int64_t monotonic_ns() {
    return std::chrono::duration_cast<std::chrono::nanoseconds>(
        std::chrono::steady_clock::now().time_since_epoch()).count();
}

}  // namespace maklertour::dual_phone
