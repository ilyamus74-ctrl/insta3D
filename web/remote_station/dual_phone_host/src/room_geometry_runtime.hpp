#pragma once

#include "stereo_depth_runtime.hpp"

#include <cstdint>
#include <filesystem>
#include <memory>
#include <string>

#include <nlohmann/json.hpp>

namespace maklertour::dual_phone::detail {

class RoomGeometryRuntime {
public:
    explicit RoomGeometryRuntime(std::filesystem::path session_directory);
    ~RoomGeometryRuntime();

    RoomGeometryRuntime(const RoomGeometryRuntime&) = delete;
    RoomGeometryRuntime& operator=(const RoomGeometryRuntime&) = delete;

    void submit(
        std::uint64_t pair_index,
        std::string source_profile,
        const StereoDepthResult& depth);
    void reset();
    nlohmann::json status_json() const;

private:
    struct Impl;
    std::unique_ptr<Impl> impl_;
};

}  // namespace maklertour::dual_phone::detail
