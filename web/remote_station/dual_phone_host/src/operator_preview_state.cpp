#include "operator_preview_state.hpp"

#include <algorithm>
#include <atomic>
#include <cctype>
#include <stdexcept>

namespace maklertour::dual_phone {

namespace {

std::atomic<OperatorPreviewMode> selected_mode{
    OperatorPreviewMode::DepthFiltered
};

std::string normalized(std::string value) {
    std::transform(value.begin(), value.end(), value.begin(), [](unsigned char ch) {
        return static_cast<char>(std::toupper(ch));
    });
    if (value == "RAW" || value == "RAW_METRIC") value = "DEPTH_RAW";
    if (value == "FILTERED") value = "DEPTH_FILTERED";
    if (value == "STRICT" || value == "TEMPORAL_STRICT") value = "DEPTH_STRICT";
    return value;
}

}  // namespace

OperatorPreviewMode current_operator_preview_mode() {
    return selected_mode.load(std::memory_order_relaxed);
}

OperatorPreviewMode select_operator_preview_mode(const std::string& raw_value) {
    const auto value = normalized(raw_value);
    OperatorPreviewMode result = OperatorPreviewMode::DepthFiltered;
    if (value == "DISPARITY") {
        result = OperatorPreviewMode::Disparity;
    } else if (value == "DEPTH_RAW") {
        result = OperatorPreviewMode::DepthRaw;
    } else if (value == "DEPTH_FILTERED") {
        result = OperatorPreviewMode::DepthFiltered;
    } else if (value == "DEPTH_STRICT") {
        result = OperatorPreviewMode::DepthStrict;
    } else if (value == "CONFIDENCE") {
        result = OperatorPreviewMode::Confidence;
    } else {
        throw std::runtime_error(
            "preview mode must be DISPARITY, DEPTH_RAW, DEPTH_FILTERED, "
            "DEPTH_STRICT or CONFIDENCE");
    }
    selected_mode.store(result, std::memory_order_relaxed);
    return result;
}

const char* operator_preview_mode_name(const OperatorPreviewMode mode) {
    switch (mode) {
        case OperatorPreviewMode::Disparity: return "DISPARITY";
        case OperatorPreviewMode::DepthRaw: return "DEPTH_RAW";
        case OperatorPreviewMode::DepthFiltered: return "DEPTH_FILTERED";
        case OperatorPreviewMode::DepthStrict: return "DEPTH_STRICT";
        case OperatorPreviewMode::Confidence: return "CONFIDENCE";
    }
    return "DEPTH_FILTERED";
}

}  // namespace maklertour::dual_phone
