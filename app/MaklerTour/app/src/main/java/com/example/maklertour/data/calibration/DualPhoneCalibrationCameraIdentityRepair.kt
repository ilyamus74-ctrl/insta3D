package com.maklertour.data.calibration

import com.maklertour.data.dualphone.DualPhoneRole

data class DualPhoneCalibrationCameraIdentityRepairResult(
    val profile: DualPhoneCalibrationProfileResult? = null,
    val changed: Boolean = false,
    val message: String,
) {
    val successful: Boolean
        get() = profile != null
}

/**
 * Repairs only missing camera IDs in an already accepted calibration profile.
 *
 * Existing non-blank IDs are never overwritten. A conflicting current camera ID
 * is treated as an error because the profile may belong to another lens.
 */
object DualPhoneCalibrationCameraIdentityRepair {
    fun repair(
        profile: DualPhoneCalibrationProfileResult,
        localRole: DualPhoneRole,
        localCameraId: String?,
        peerCameraId: String?,
    ): DualPhoneCalibrationCameraIdentityRepairResult {
        if (!profile.successful) {
            return failure("Calibration profile is not accepted")
        }
        if (localRole == DualPhoneRole.STANDALONE) {
            return failure("Camera identity repair requires MASTER or SLAVE role")
        }

        val localCandidate = localCameraId.normalizedId()
        val peerCandidate = peerCameraId.normalizedId()
        val currentMaster = profile.masterCameraId.normalizedId()
        val currentSlave = profile.slaveCameraId.normalizedId()

        val masterCandidate = when (localRole) {
            DualPhoneRole.MASTER -> localCandidate
            DualPhoneRole.SLAVE -> peerCandidate
            DualPhoneRole.STANDALONE -> null
        }
        val slaveCandidate = when (localRole) {
            DualPhoneRole.MASTER -> peerCandidate
            DualPhoneRole.SLAVE -> localCandidate
            DualPhoneRole.STANDALONE -> null
        }

        val master = resolveSide(
            label = "MASTER",
            stored = currentMaster,
            candidate = masterCandidate,
        )
        if (master.error != null) return failure(master.error)

        val slave = resolveSide(
            label = "SLAVE",
            stored = currentSlave,
            candidate = slaveCandidate,
        )
        if (slave.error != null) return failure(slave.error)

        val repairedMaster =
            master.value ?: return failure("MASTER camera ID is unavailable")
        val repairedSlave =
            slave.value ?: return failure("SLAVE camera ID is unavailable")

        val changed =
            repairedMaster != currentMaster ||
                repairedSlave != currentSlave
        val repairedProfile = if (changed) {
            profile.copy(
                masterCameraId = repairedMaster,
                slaveCameraId = repairedSlave,
            )
        } else {
            profile
        }

        return DualPhoneCalibrationCameraIdentityRepairResult(
            profile = repairedProfile,
            changed = changed,
            message = if (changed) {
                "Camera IDs restored: MASTER=$repairedMaster; SLAVE=$repairedSlave"
            } else {
                "Camera IDs are already complete"
            },
        )
    }

    private data class SideResolution(
        val value: String?,
        val error: String? = null,
    )

    private fun resolveSide(
        label: String,
        stored: String?,
        candidate: String?,
    ): SideResolution {
        if (stored != null && candidate != null && stored != candidate) {
            return SideResolution(
                value = null,
                error =
                    "$label camera ID conflict: profile=$stored; current=$candidate",
            )
        }
        return SideResolution(value = stored ?: candidate)
    }

    private fun failure(
        message: String,
    ): DualPhoneCalibrationCameraIdentityRepairResult =
        DualPhoneCalibrationCameraIdentityRepairResult(
            profile = null,
            changed = false,
            message = message,
        )

    private fun String?.normalizedId(): String? =
        this?.trim()?.takeIf { it.isNotBlank() }
}
