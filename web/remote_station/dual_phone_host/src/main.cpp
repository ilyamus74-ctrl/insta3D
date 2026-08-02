#include "host_state.hpp"
#include "http_dashboard.hpp"
#include "protocol.hpp"

#include <arpa/inet.h>
#include <atomic>
#include <cerrno>
#include <cstring>
#include <cstdlib>
#include <functional>
#include <csignal>
#include <filesystem>
#include <fstream>
#include <iostream>
#include <memory>
#include <netinet/in.h>
#include <netinet/tcp.h>
#include <optional>
#include <stdexcept>
#include <string>
#include <sys/socket.h>
#include <sys/time.h>
#include <thread>
#include <unistd.h>
#include <vector>

namespace mdp = maklertour::dual_phone;

namespace {

std::atomic<bool> running{true};
std::atomic<int> ingest_server_fd{-1};

struct Options {
    std::string bind = "0.0.0.0";
    std::string http_bind = "127.0.0.1";
    int ingest_port = 48640;
    int http_port = 48641;
    std::filesystem::path output = "./sessions";
    std::filesystem::path web_root = "./web";
    std::filesystem::path session_path_file;
    std::size_t archive_every = 0;
};

void request_shutdown() {
    running.store(false);
    const int fd = ingest_server_fd.exchange(-1);
    if (fd >= 0) ::close(fd);
}

void signal_handler(int) {
    request_shutdown();
}

int parse_port(const std::string& value) {
    const int port = std::stoi(value);
    if (port < 1 || port > 65535) throw std::runtime_error("invalid port");
    return port;
}

Options parse_options(const int argc, char** argv) {
    Options options;
    for (int index = 1; index < argc; ++index) {
        const std::string arg = argv[index];
        auto take = [&]() -> std::string {
            if (++index >= argc) throw std::runtime_error("missing value for " + arg);
            return argv[index];
        };
        if (arg == "--bind") options.bind = take();
        else if (arg == "--http-bind") options.http_bind = take();
        else if (arg == "--ingest-port") options.ingest_port = parse_port(take());
        else if (arg == "--http-port") options.http_port = parse_port(take());
        else if (arg == "--output") options.output = take();
        else if (arg == "--web-root") options.web_root = take();
        else if (arg == "--session-path-file") options.session_path_file = take();
        else if (arg == "--archive-every") options.archive_every = std::stoul(take());
        else if (arg == "--help") {
            std::cout << "maklertour-dual-phone-host\n"
                      << "  --bind 0.0.0.0\n"
                      << "  --http-bind 127.0.0.1\n"
                      << "  --ingest-port 48640\n"
                      << "  --http-port 48641\n"
                      << "  --output ./sessions\n"
                      << "  --web-root ./web\n"
                      << "  --session-path-file /tmp/current-session\n"
                      << "  --archive-every 1 (0 disables JPEG archive)\n";
            std::exit(0);
        } else throw std::runtime_error("unknown argument: " + arg);
    }
    return options;
}

std::string peer_address(const int fd) {
    sockaddr_in address{};
    socklen_t size = sizeof(address);
    if (getpeername(fd, reinterpret_cast<sockaddr*>(&address), &size) != 0) return "unknown";
    char host[INET_ADDRSTRLEN]{};
    inet_ntop(AF_INET, &address.sin_addr, host, sizeof(host));
    return std::string(host) + ':' + std::to_string(ntohs(address.sin_port));
}

void handle_camera(const int fd, mdp::HostState& state) {
    std::optional<mdp::CameraSlot> slot;
    try {
        const auto hello_text = mdp::read_line(fd, mdp::kMaxHelloBytes);
        const auto server_hello_receive_ns = mdp::monotonic_ns();
        if (hello_text.empty()) throw std::runtime_error("empty hello");
        const auto hello = nlohmann::json::parse(hello_text);
        if (hello.value("type", std::string{}) != "hello" ||
            hello.value("schema_version", 0U) != mdp::kProtocolSchema) {
            throw std::runtime_error("unsupported hello");
        }
        const auto requested_slot = mdp::parse_slot(hello.at("slot").get<std::string>());
        const auto device_id = hello.at("device_id").get<std::string>();
        if (!state.camera_connected(requested_slot, device_id, peer_address(fd), hello)) {
            mdp::write_line(fd, nlohmann::json({
                {"type", "hello_ack"}, {"schema_version", mdp::kProtocolSchema},
                {"accepted", false}, {"reason", "slot already connected"},
            }).dump());
            return;
        }
        slot = requested_slot;
        const auto server_hello_send_ns = mdp::monotonic_ns();
        mdp::write_line(fd, nlohmann::json({
            {"type", "hello_ack"},
            {"schema_version", mdp::kProtocolSchema},
            {"accepted", true},
            {"slot", mdp::slot_name(*slot)},
            {"client_monotonic_ns", hello.value("client_monotonic_ns", 0LL)},
            {"server_monotonic_ns", server_hello_send_ns},
            {"server_receive_ns", server_hello_receive_ns},
            {"server_send_ns", server_hello_send_ns},
        }).dump());

        while (running.load()) {
            auto message = mdp::read_message(fd);
            const auto type = message.header.value("type", std::string{});
            if (type == "frame") {
                const auto declared_crc = message.header.at("payload_crc32").get<std::uint32_t>();
                const auto actual_crc = mdp::crc32(message.payload);
                if (declared_crc != actual_crc) {
                    throw std::runtime_error("JPEG CRC32 mismatch");
                }
                mdp::FrameRecord frame;
                frame.device_id = device_id;
                frame.session_id = message.header.value("session_id", "");
                frame.sequence = message.header.at("frame_sequence").get<std::uint64_t>();
                frame.sensor_timestamp_ns = message.header.at("sensor_timestamp_ns").get<std::int64_t>();
                frame.capture_elapsed_ns = message.header.at("capture_elapsed_ns").get<std::int64_t>();
                frame.received_monotonic_ns = mdp::monotonic_ns();
                frame.pair_timestamp_ns = message.header.value(
                    "host_aligned_timestamp_ns", frame.received_monotonic_ns);
                frame.width = message.header.at("width").get<int>();
                frame.height = message.header.at("height").get<int>();
                frame.rotation_degrees = message.header.value("rotation_degrees", 0);
                frame.payload_crc32 = actual_crc;
                frame.jpeg = std::move(message.payload);
                frame.header = std::move(message.header);
                state.accept_frame(*slot, std::move(frame));
            } else if (type == "imu") {
                state.accept_imu(*slot, message.header);
            } else if (type == "clock_probe") {
                const auto server_receive_ns = mdp::monotonic_ns();
                const auto server_send_ns = mdp::monotonic_ns();
                mdp::write_message(fd, {
                    {"type", "clock_probe_ack"},
                    {"schema_version", mdp::kProtocolSchema},
                    {"client_send_ns", message.header.value("client_send_ns", 0LL)},
                    {"server_receive_ns", server_receive_ns},
                    {"server_send_ns", server_send_ns},
                }, {});
            } else if (type == "ping") {
                mdp::write_message(fd, {{"type", "pong"},
                                        {"server_monotonic_ns", mdp::monotonic_ns()}}, {});
            } else {
                throw std::runtime_error("unknown message type: " + type);
            }
        }
    } catch (const std::exception& error) {
        if (slot) state.camera_disconnected(*slot, error.what());
    }
    ::shutdown(fd, SHUT_RDWR);
    ::close(fd);
}

int create_server(const Options& options) {
    const int fd = ::socket(AF_INET, SOCK_STREAM, 0);
    if (fd < 0) throw std::runtime_error("cannot create ingest socket");
    int reuse = 1;
    setsockopt(fd, SOL_SOCKET, SO_REUSEADDR, &reuse, sizeof(reuse));
    sockaddr_in address{};
    address.sin_family = AF_INET;
    address.sin_port = htons(static_cast<std::uint16_t>(options.ingest_port));
    if (inet_pton(AF_INET, options.bind.c_str(), &address.sin_addr) != 1) {
        throw std::runtime_error("invalid bind address");
    }
    if (::bind(fd, reinterpret_cast<sockaddr*>(&address), sizeof(address)) < 0 ||
        ::listen(fd, 8) < 0) {
        const auto message = std::string("cannot bind ingest port: ") + std::strerror(errno);
        ::close(fd);
        throw std::runtime_error(message);
    }
    return fd;
}

}  // namespace

int main(const int argc, char** argv) {
    try {
        const auto options = parse_options(argc, argv);
        std::signal(SIGINT, signal_handler);
        std::signal(SIGTERM, signal_handler);

        mdp::HostState state(options.output, options.archive_every);
        if (!options.session_path_file.empty()) {
            std::ofstream session_path(options.session_path_file);
            if (!session_path) {
                throw std::runtime_error("cannot write session path file");
            }
            session_path << state.session_directory().string() << '\n';
        }
        mdp::HttpDashboard dashboard(
            state,
            options.http_bind,
            options.http_port,
            options.web_root,
            request_shutdown
        );
        dashboard.start();
        const int server = create_server(options);
        ingest_server_fd.store(server);
        state.log_event("INFO", "INGEST_READY",
                        {{"bind", options.bind}, {"port", options.ingest_port}});

        std::cout << "MaklerTour dual-phone host\n"
                  << "Ingest: " << options.bind << ':' << options.ingest_port << '\n'
                  << "Dashboard: http://" << options.http_bind << ':'
                  << options.http_port << "/\n"
                  << "Session: " << state.session_directory() << '\n';

        std::vector<std::thread> camera_threads;
        while (running.load()) {
            sockaddr_in address{};
            socklen_t size = sizeof(address);
            const int client = ::accept(server, reinterpret_cast<sockaddr*>(&address), &size);
            if (client < 0) {
                if (errno == EINTR) continue;
                if (!running.load()) break;
                continue;
            }
            int keepalive = 1;
            setsockopt(client, SOL_SOCKET, SO_KEEPALIVE, &keepalive, sizeof(keepalive));
            int no_delay = 1;
            setsockopt(client, IPPROTO_TCP, TCP_NODELAY, &no_delay, sizeof(no_delay));
            timeval receive_timeout{};
            receive_timeout.tv_sec = 1;
            setsockopt(
                client,
                SOL_SOCKET,
                SO_RCVTIMEO,
                &receive_timeout,
                sizeof(receive_timeout));
            camera_threads.emplace_back(handle_camera, client, std::ref(state));
        }
        const int open_server = ingest_server_fd.exchange(-1);
        if (open_server >= 0) ::close(open_server);
        dashboard.stop();
        for (auto& camera_thread : camera_threads) {
            if (camera_thread.joinable()) camera_thread.join();
        }
        state.log_event("INFO", "HOST_STOPPED");
        return 0;
    } catch (const std::exception& error) {
        std::cerr << "FATAL: " << error.what() << '\n';
        return 1;
    }
}
