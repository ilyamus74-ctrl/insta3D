#include "http_dashboard.hpp"

#include "protocol.hpp"

#include <arpa/inet.h>
#include <cerrno>
#include <chrono>
#include <cstring>
#include <fstream>
#include <iterator>
#include <sstream>
#include <stdexcept>
#include <sys/socket.h>
#include <unistd.h>

namespace maklertour::dual_phone {

namespace {

std::vector<std::uint8_t> read_binary_file(const std::filesystem::path& path) {
    std::ifstream input(path, std::ios::binary);
    if (!input) throw std::runtime_error("cannot open " + path.string());
    return {std::istreambuf_iterator<char>(input), std::istreambuf_iterator<char>()};
}

std::string status_text(const int status) {
    switch (status) {
        case 200: return "OK";
        case 202: return "Accepted";
        case 404: return "Not Found";
        default: return "Error";
    }
}

}  // namespace

HttpDashboard::HttpDashboard(HostState& state, std::string bind_address,
                             const int port, std::filesystem::path web_root,
                             std::function<void()> shutdown_callback)
    : state_(state), bind_address_(std::move(bind_address)), port_(port),
      web_root_(std::move(web_root)),
      shutdown_callback_(std::move(shutdown_callback)) {}

HttpDashboard::~HttpDashboard() { stop(); }

void HttpDashboard::start() {
    if (running_.exchange(true)) return;
    thread_ = std::thread([this] { run(); });
}

void HttpDashboard::stop() {
    running_.store(false);
    if (server_fd_ >= 0) {
        ::shutdown(server_fd_, SHUT_RDWR);
        ::close(server_fd_);
        server_fd_ = -1;
    }
    if (thread_.joinable()) thread_.join();
}

void HttpDashboard::run() {
    server_fd_ = ::socket(AF_INET, SOCK_STREAM, 0);
    if (server_fd_ < 0) throw std::runtime_error("HTTP socket failed");
    int reuse = 1;
    setsockopt(server_fd_, SOL_SOCKET, SO_REUSEADDR, &reuse, sizeof(reuse));
    sockaddr_in address{};
    address.sin_family = AF_INET;
    address.sin_port = htons(static_cast<std::uint16_t>(port_));
    if (inet_pton(AF_INET, bind_address_.c_str(), &address.sin_addr) != 1) {
        throw std::runtime_error("invalid HTTP bind address");
    }
    if (::bind(server_fd_, reinterpret_cast<sockaddr*>(&address), sizeof(address)) < 0 ||
        ::listen(server_fd_, 16) < 0) {
        throw std::runtime_error("cannot bind HTTP dashboard");
    }
    state_.log_event("INFO", "HTTP_DASHBOARD_READY",
                     {{"bind", bind_address_}, {"port", port_}});
    while (running_.load()) {
        const int client = ::accept(server_fd_, nullptr, nullptr);
        if (client < 0) {
            if (!running_.load()) break;
            if (errno == EINTR) continue;
            continue;
        }
        std::thread([this, client] {
            try { handle_client(client); } catch (...) {}
            ::close(client);
        }).detach();
    }
}

void HttpDashboard::handle_client(const int client_fd) {
    const auto request = read_line(client_fd, 8192);
    std::istringstream input(request);
    std::string method;
    std::string target;
    std::string version;
    input >> method >> target >> version;
    while (true) {
        const auto header = read_line(client_fd, 8192);
        if (header.empty()) break;
    }

    const auto query = target.find('?');
    const auto path = target.substr(0, query);
    if (method == "POST" && path == "/api/control/stop") {
        state_.log_event("INFO", "GUI_STOP_REQUESTED",
                         {{"action", "STOP_AND_PACK_JSON"}});
        send_text(client_fd, 202, "application/json",
                  R"({"accepted":true,"action":"STOP_AND_PACK_JSON"})");
        const auto callback = shutdown_callback_;
        std::thread([callback] {
            std::this_thread::sleep_for(std::chrono::milliseconds(150));
            if (callback) callback();
        }).detach();
        return;
    }
    if (method != "GET") {
        send_text(client_fd, 404, "text/plain", "GET only\n");
        return;
    }
    if (path == "/" || path == "/index.html") {
        const auto body = read_binary_file(web_root_ / "index.html");
        send_response(client_fd, 200, "text/html; charset=utf-8", body);
        return;
    }
    if (path == "/api/status") {
        send_text(client_fd, 200, "application/json", state_.status_json().dump());
        return;
    }
    if (path == "/api/events") {
        send_text(client_fd, 200, "application/json",
                  nlohmann::json(state_.recent_events()).dump());
        return;
    }
    if (path == "/camera/a.jpg" || path == "/camera/b.jpg") {
        const auto slot = path == "/camera/a.jpg" ? CameraSlot::A : CameraSlot::B;
        const auto camera = state_.camera(slot);
        if (!camera.latest || camera.latest->jpeg.empty()) {
            send_text(client_fd, 404, "text/plain", "frame not ready\n");
            return;
        }
        send_response(client_fd, 200, "image/jpeg", camera.latest->jpeg);
        return;
    }
    send_text(client_fd, 404, "text/plain", "not found\n");
}

void HttpDashboard::send_response(const int client_fd, const int status,
                                  const std::string& content_type,
                                  const std::vector<std::uint8_t>& body,
                                  const std::string& cache_control) {
    std::ostringstream header;
    header << "HTTP/1.1 " << status << ' ' << status_text(status) << "\r\n"
           << "Content-Type: " << content_type << "\r\n"
           << "Content-Length: " << body.size() << "\r\n"
           << "Cache-Control: " << cache_control << "\r\n"
           << "Connection: close\r\n\r\n";
    const auto header_text = header.str();
    write_all(client_fd, header_text.data(), header_text.size());
    if (!body.empty()) write_all(client_fd, body.data(), body.size());
}

void HttpDashboard::send_text(const int client_fd, const int status,
                              const std::string& content_type,
                              const std::string& body) {
    send_response(client_fd, status, content_type,
                  {body.begin(), body.end()});
}

}  // namespace maklertour::dual_phone
