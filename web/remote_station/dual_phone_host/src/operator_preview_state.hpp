#pragma once

#include <string>

namespace maklertour::dual_phone {

enum class OperatorPreviewMode {
    Disparity,
    DepthRaw,
    DepthFiltered,
    DepthStrict,
    Confidence,
};

OperatorPreviewMode current_operator_preview_mode();
OperatorPreviewMode select_operator_preview_mode(const std::string& value);
const char* operator_preview_mode_name(OperatorPreviewMode mode);

}  // namespace maklertour::dual_phone
