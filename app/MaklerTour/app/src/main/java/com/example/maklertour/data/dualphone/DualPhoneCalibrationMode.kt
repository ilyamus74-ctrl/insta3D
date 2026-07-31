package com.maklertour.data.dualphone

enum class DualPhoneCalibrationMode(
    val wireValue: String,
    val displayNameRu: String,
) {
    AUTO("AUTO", "АВТО"),
    MANUAL_STEREO("MANUAL_STEREO", "РУЧНАЯ"),
    ;

    companion object {
        fun fromWire(value: String?): DualPhoneCalibrationMode =
            values().firstOrNull { it.wireValue == value } ?: AUTO
    }
}
