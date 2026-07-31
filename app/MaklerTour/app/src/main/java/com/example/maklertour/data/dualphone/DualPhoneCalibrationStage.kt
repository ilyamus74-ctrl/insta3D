package com.maklertour.data.dualphone

enum class DualPhoneCalibrationStage(
    val wireValue: String,
    val displayNameRu: String,
    val targetPoseCount: Int,
    val captureDirectory: String,
) {
    MASTER_INTRINSICS(
        wireValue = "MASTER_INTRINSICS",
        displayNameRu = "MASTER",
        targetPoseCount = 24,
        captureDirectory = "master_intrinsics",
    ),
    SLAVE_INTRINSICS(
        wireValue = "SLAVE_INTRINSICS",
        displayNameRu = "SLAVE",
        targetPoseCount = 24,
        captureDirectory = "slave_intrinsics",
    ),
    STEREO_EXTRINSICS(
        wireValue = "STEREO_EXTRINSICS",
        displayNameRu = "ОБЕ КАМЕРЫ",
        targetPoseCount = 12,
        captureDirectory = "stereo_extrinsics",
    ),
    COMPLETE(
        wireValue = "COMPLETE",
        displayNameRu = "ЗАВЕРШЕНО",
        targetPoseCount = 0,
        captureDirectory = "complete",
    ),
    ;

    fun isLocalAnalyzerActive(role: DualPhoneRole): Boolean = when (this) {
        MASTER_INTRINSICS -> role == DualPhoneRole.MASTER
        SLAVE_INTRINSICS -> role == DualPhoneRole.SLAVE
        STEREO_EXTRINSICS ->
            role == DualPhoneRole.MASTER || role == DualPhoneRole.SLAVE
        COMPLETE -> false
    }

    val requiresMasterObservation: Boolean
        get() = this == MASTER_INTRINSICS || this == STEREO_EXTRINSICS

    val requiresSlaveObservation: Boolean
        get() = this == SLAVE_INTRINSICS || this == STEREO_EXTRINSICS

    fun next(): DualPhoneCalibrationStage = when (this) {
        MASTER_INTRINSICS -> SLAVE_INTRINSICS
        SLAVE_INTRINSICS -> STEREO_EXTRINSICS
        STEREO_EXTRINSICS, COMPLETE -> COMPLETE
    }

    companion object {
        fun fromWire(value: String?): DualPhoneCalibrationStage =
            values().firstOrNull { it.wireValue == value } ?: MASTER_INTRINSICS
    }
}
