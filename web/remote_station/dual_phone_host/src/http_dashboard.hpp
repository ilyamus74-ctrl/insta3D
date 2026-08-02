#pragma once

#include <atomic>
#include <filesystem>
#include <string>
#include <thread>

#include "host_state.hpp"

namespace maklertour::dual_phone {

class HttpDashboard {
public:
    HttpDashboard(HostState& state, std::string bind_address, int port,
                  std::filesystem::path web_root);
    ~HttpDashboard();

    void start();
    void stop();

private:
    void run();
    void handle_client(int client_fd);
    void send_response(int client_fd, int status, const std::string& content_type,
                       const std::vector<std::uint8_t>& body,
                       const std::string& cache_control = "no-store");
    void send_text(int client_fd, int status, const std::string& content_type,
                   const std::string& body);

    HostState& state_;
    std::string bind_address_;
    int port_;
    std::filesystem::path web_root_;
    std::atomic<bool> running_{false};
    int server_fd_ = -1;
    std::thread thread_;
};

}  // namespace maklertour::dual_phone
