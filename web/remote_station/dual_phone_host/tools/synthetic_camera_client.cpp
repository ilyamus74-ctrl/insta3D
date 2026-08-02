#include "protocol.hpp"

#include <arpa/inet.h>
#include <atomic>
#include <chrono>
#include <csignal>
#include <cstdint>
#include <cstdlib>
#include <filesystem>
#include <fstream>
#include <iostream>
#include <netdb.h>
#include <stdexcept>
#include <string>
#include <sys/socket.h>
#include <thread>
#include <unistd.h>
#include <vector>

namespace mdp = maklertour::dual_phone;

namespace {

std::atomic<bool> running{true};

struct Options {
    std::string host = "127.0.0.1";
    int port = 48640;
    std::string slot;
    std::filesystem::path jpeg;
    double fps = 5.0;
    int width = 960;
    int height = 540;
};

void signal_handler(int) {
    running.store(false);
}

[[noreturn]] void usage(const char* program, int exit_code) {
    std::ostream& out = exit_code == 0 ? std::cout : std::cerr;
    out << "Usage: " << program << " --slot CAMERA_A|CAMERA_B --jpeg FILE [options]\n"
        << "  --host HOST       default 127.0.0.1\n"
        << "  --port PORT       default 48640\n"
        << "  --fps FPS         default 5\n"
        << "  --width PX        metadata width, default 960\n"
        << "  --height PX       metadata height, default 540\n";
    std::exit(exit_code);
}

int parse_int(const std::string& value, const char* name, int minimum, int maximum) {
    const int parsed = std::stoi(value);
    if (parsed < minimum || parsed > maximum) {
        throw std::runtime_error(std::string("invalid ") + name);
    }
    return parsed;
}

Options parse_options(int argc, char** argv) {
    Options options;
    for (int index = 1; index < argc; ++index) {
        const std::string arg = argv[index];
        auto take = [&]() -> std::string {
            if (++index >= argc) throw std::runtime_error("missing value for " + arg);
            return argv[index];
        };
        if (arg == "--host") options.host = take();
        else if (arg == "--port") options.port = parse_int(take(), "port", 1, 65535);
        else if (arg == "--slot") options.slot = take();
        else if (arg == "--jpeg") options.jpeg = take();
        else if (arg == "--fps") {
            options.fps = std::stod(take());
            if (options.fps <= 0.0 || options.fps > 60.0) {
                throw std::runtime_error("invalid fps");
            }
        } else if (arg == "--width") {
            options.width = parse_int(take(), "width", 1, 16384);
        } else if (arg == "--height") {
            options.height = parse_int(take(), "height", 1, 16384);
        } else if (arg == "--help") {
            usage(argv[0], 0);
        } else {
            throw std::runtime_error("unknown argument: " + arg);
        }
    }
    if (options.slot != "CAMERA_A" && options.slot != "CAMERA_B") {
        throw std::runtime_error("--slot must be CAMERA_A or CAMERA_B");
    }
    if (options.jpeg.empty()) {
        throw std::runtime_error("--jpeg is required");
    }
    return options;
}

std::vector<std::uint8_t> read_file(const std::filesystem::path& path) {
    std::ifstream input(path, std::ios::binary);
    if (!input) throw std::runtime_error("cannot open JPEG: " + path.string());
    input.seekg(0, std::ios::end);
    const auto length = input.tellg();
    if (length <= 0 || static_cast<std::uint64_t>(length) > mdp::kMaxPayloadBytes) {
        throw std::runtime_error("JPEG is empty or exceeds protocol payload limit");
    }
    input.seekg(0, std::ios::beg);
    std::vector<std::uint8_t> bytes(static_cast<std::size_t>(length));
    input.read(reinterpret_cast<char*>(bytes.data()), length);
    if (!input) throw std::runtime_error("cannot read JPEG: " + path.string());
    return bytes;
}

int connect_tcp(const std::string& host, int port) {
    addrinfo hints{};
    hints.ai_family = AF_UNSPEC;
    hints.ai_socktype = SOCK_STREAM;
    addrinfo* result = nullptr;
    const std::string service = std::to_string(port);
    const int status = getaddrinfo(host.c_str(), service.c_str(), &hints, &result);
    if (status != 0) {
        throw std::runtime_error(std::string("getaddrinfo: ") + gai_strerror(status));
    }

    int connected = -1;
    for (addrinfo* current = result; current != nullptr; current = current->ai_next) {
        const int fd = ::socket(current->ai_family, current->ai_socktype, current->ai_protocol);
        if (fd < 0) continue;
        if (::connect(fd, current->ai_addr, current->ai_addrlen) == 0) {
            connected = fd;
            break;
        }
        ::close(fd);
    }
    freeaddrinfo(result);
    if (connected < 0) throw std::runtime_error("cannot connect to host");
    return connected;
}

std::string make_session_id(const std::string& slot) {
    const auto now = std::chrono::system_clock::now().time_since_epoch();
    const auto micros = std::chrono::duration_cast<std::chrono::microseconds>(now).count();
    return "synthetic-cpp-" + slot + '-' + std::to_string(::getpid()) + '-' +
           std::to_string(micros);
}

}  // namespace

int main(int argc, char** argv) {
    try {
        const Options options = parse_options(argc, argv);
        const auto jpeg = read_file(options.jpeg);
        const int fd = connect_tcp(options.host, options.port);
        std::signal(SIGINT, signal_handler);
        std::signal(SIGTERM, signal_handler);

        const std::string session_id = make_session_id(options.slot);
        mdp::write_line(fd, nlohmann::json({
            {"type", "hello"},
            {"schema_version", mdp::kProtocolSchema},
            {"slot", options.slot},
            {"device_id", "synthetic-cpp-" + options.slot},
            {"session_id", session_id},
            {"capture_mode", "SYNTHETIC_CPP"},
        }).dump());

        const auto ack_text = mdp::read_line(fd, mdp::kMaxHelloBytes);
        if (ack_text.empty()) throw std::runtime_error("server closed during hello");
        const auto ack = nlohmann::json::parse(ack_text);
        if (!ack.value("accepted", false)) {
            throw std::runtime_error("host rejected hello: " + ack.dump());
        }

        std::cout << "Connected as " << options.slot << " to " << options.host << ':'
                  << options.port << " at " << options.fps << " FPS\n";

        std::uint64_t sequence = 0;
        const auto frame_interval = std::chrono::duration<double>(1.0 / options.fps);
        auto next_frame = std::chrono::steady_clock::now();
        while (running.load()) {
            const std::int64_t now_ns = mdp::monotonic_ns();
            nlohmann::json header = {
                {"type", "frame"},
                {"schema_version", mdp::kProtocolSchema},
                {"session_id", session_id},
                {"frame_sequence", sequence},
                {"sensor_timestamp_ns", now_ns},
                {"capture_elapsed_ns", now_ns},
                {"host_aligned_timestamp_ns", now_ns},
                {"width", options.width},
                {"height", options.height},
                {"rotation_degrees", 0},
                {"encoding", "JPEG"},
                {"payload_crc32", mdp::crc32(jpeg)},
            };
            mdp::write_message(fd, header, jpeg);
            ++sequence;
            next_frame += std::chrono::duration_cast<std::chrono::steady_clock::duration>(
                frame_interval);
            std::this_thread::sleep_until(next_frame);
        }

        ::shutdown(fd, SHUT_RDWR);
        ::close(fd);
        return 0;
    } catch (const std::exception& error) {
        std::cerr << "FATAL: " << error.what() << '\n';
        return 1;
    }
}
