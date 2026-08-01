package com.example.maklertour.data.dualphone

import com.maklertour.data.calibration.DualPhoneCalibrationProfileResult
import com.maklertour.data.calibration.DualPhoneLiveIntrinsicsEstimate
import com.maklertour.data.dualphone.DualPhoneControlPhase
import com.maklertour.data.dualphone.DualPhoneControlSnapshot
import com.maklertour.data.dualphone.DualPhoneRole
import com.maklertour.data.dualphone.DualPhoneStereoSettings
import java.util.UUID

enum class DualPhoneLiveStreamSessionBlock {
    NONE,
    MODE_DISABLED,
    NO_SESSION,
    STANDALONE_ROLE,
    CONTROL_NOT_CONNECTED,
    CONTROL_PHASE_UNAVAILABLE,
    CALIBRATION_IN_PROGRESS,
    MISSING_DUAL_CAPTURE_ID,
    MISSING_PEER_IDENTITY,
    MISSING_ACTIVE_CALIBRATION,
    CALIBRATION_PROFILE_NOT_FOUND,
    CALIBRATION_PROFILE_REJECTED,
    CALIBRATION_PROFILE_ID_MISMATCH,
    RIG_ID_MISMATCH,
    MOUNT_REVISION_MISMATCH,
    LOCAL_DEVICE_MISMATCH,
    PEER_DEVICE_MISMATCH,
    MISSING_CAMERA_IDENTITY,
    MISSING_CALIBRATION_IMAGE_SIZE,
}

data class DualPhoneLiveStreamSessionInput(
    val sessionUuid: String?,
    val requestedMode: DualPhoneLiveStreamMode,
    val settings: DualPhoneStereoSettings,
    val control: DualPhoneControlSnapshot,
    val calibrationProfile: DualPhoneCalibrationProfileResult?,
)

data class DualPhoneLiveStreamSessionStatus(
    val requestedMode: DualPhoneLiveStreamMode,
    val block: DualPhoneLiveStreamSessionBlock,
    val snapshot: DualPhoneLiveStreamSnapshot,
    val recordingModeIdentitySource: String? = null,
) {
    val sessionAccepted: Boolean
        get() = block == DualPhoneLiveStreamSessionBlock.NONE &&
            snapshot.owner != null

    val transportRequired: Boolean
        get() = sessionAccepted &&
            snapshot.state == DualPhoneLiveStreamState.PREPARING
}

/**
 * LM01A-2 bridge between the selected application session, the existing dual-phone
 * control snapshot and the LM01A stream lifecycle controller.
 *
 * This class does not open sockets and does not bind CameraX. It only accepts a
 * complete, identity-checked owner. The dedicated frame channel is the next slice.
 */
class DualPhoneLiveStreamSessionCoordinator(
    private val controller: DualPhoneLiveStreamController =
        DualPhoneLiveStreamController(),
    private val streamIdFactory: () -> String = {
        "lm01a-${UUID.randomUUID()}"
    },
) {
    private data class OwnerKey(
        val sessionUuid: String,
        val dualCaptureId: String,
        val localRole: String,
        val peerIdentity: String,
        val cameraIdentity: String,
        val recordingModeIdentity: String,
        val calibrationIdentity: String,
        val rigMountRevision: String,
        val captureMode: DualPhoneLiveStreamMode,
    )

    private data class RoleIdentity(
        val localDeviceId: String,
        val peerDeviceId: String,
        val localCameraId: String?,
        val localIntrinsics: DualPhoneLiveIntrinsicsEstimate,
    )

    private var currentOwnerKey: OwnerKey? = null
    private var currentStreamId: String? = null
    private var currentRequestedMode: DualPhoneLiveStreamMode =
        DualPhoneLiveStreamMode.SYNC_VIDEO
    private var currentBlock: DualPhoneLiveStreamSessionBlock =
        DualPhoneLiveStreamSessionBlock.MODE_DISABLED
    private var currentRecordingModeIdentitySource: String? = null

    val snapshot: DualPhoneLiveStreamSnapshot
        @Synchronized get() = controller.snapshot

    @Synchronized
    fun currentStatus(): DualPhoneLiveStreamSessionStatus =
        DualPhoneLiveStreamSessionStatus(
            requestedMode = currentRequestedMode,
            block = currentBlock,
            snapshot = controller.snapshot,
            recordingModeIdentitySource = currentRecordingModeIdentitySource,
        )

    @Synchronized
    fun reconcile(
        input: DualPhoneLiveStreamSessionInput,
    ): DualPhoneLiveStreamSessionStatus {
        currentRequestedMode = input.requestedMode

        if (!input.requestedMode.streamEnabled) {
            return block(
                DualPhoneLiveStreamSessionBlock.MODE_DISABLED,
                keepRequestedMode = true,
            )
        }

        val sessionUuid = input.sessionUuid
            ?.trim()
            ?.takeIf { it.isNotBlank() }
            ?: return block(DualPhoneLiveStreamSessionBlock.NO_SESSION)

        val settings = input.settings
        if (settings.role == DualPhoneRole.STANDALONE) {
            return block(DualPhoneLiveStreamSessionBlock.STANDALONE_ROLE)
        }

        val control = input.control
        if (!control.connected) {
            return block(DualPhoneLiveStreamSessionBlock.CONTROL_NOT_CONNECTED)
        }
        if (control.calibrationActive) {
            return block(
                DualPhoneLiveStreamSessionBlock.CALIBRATION_IN_PROGRESS,
            )
        }

        val dualCaptureId = control.dualCaptureId
            ?.trim()
            ?.takeIf { it.isNotBlank() }
            ?: return block(
                DualPhoneLiveStreamSessionBlock.MISSING_DUAL_CAPTURE_ID,
            )

        val connectedPeerId = control.peerDeviceId
            ?.trim()
            ?.takeIf { it.isNotBlank() }
            ?: return block(
                DualPhoneLiveStreamSessionBlock.MISSING_PEER_IDENTITY,
            )

        val activeProfileId = settings.activeCalibrationProfileId
            ?.trim()
            ?.takeIf { it.isNotBlank() }
            ?: return block(
                DualPhoneLiveStreamSessionBlock.MISSING_ACTIVE_CALIBRATION,
            )

        val profile = input.calibrationProfile
            ?: return block(
                DualPhoneLiveStreamSessionBlock.CALIBRATION_PROFILE_NOT_FOUND,
            )

        if (profile.profileId != activeProfileId) {
            return block(
                DualPhoneLiveStreamSessionBlock.CALIBRATION_PROFILE_ID_MISMATCH,
            )
        }
        if (!profile.successful) {
            return block(
                DualPhoneLiveStreamSessionBlock.CALIBRATION_PROFILE_REJECTED,
            )
        }
        if (profile.rigId != settings.rigId) {
            return block(DualPhoneLiveStreamSessionBlock.RIG_ID_MISMATCH)
        }
        if (profile.rigMountRevision != settings.rigMountRevision) {
            return block(
                DualPhoneLiveStreamSessionBlock.MOUNT_REVISION_MISMATCH,
            )
        }

        val roleIdentity = roleIdentity(settings.role, profile)
            ?: return block(
                DualPhoneLiveStreamSessionBlock.STANDALONE_ROLE,
            )

        if (settings.deviceId != roleIdentity.localDeviceId) {
            return block(
                DualPhoneLiveStreamSessionBlock.LOCAL_DEVICE_MISMATCH,
            )
        }
        if (connectedPeerId != roleIdentity.peerDeviceId) {
            return block(
                DualPhoneLiveStreamSessionBlock.PEER_DEVICE_MISMATCH,
            )
        }

        val cameraIdentity = roleIdentity.localCameraId
            ?.trim()
            ?.takeIf { it.isNotBlank() }
            ?: return block(
                DualPhoneLiveStreamSessionBlock.MISSING_CAMERA_IDENTITY,
            )

        val intrinsics = roleIdentity.localIntrinsics
        if (
            !intrinsics.acceptable ||
            intrinsics.imageWidth <= 0 ||
            intrinsics.imageHeight <= 0
        ) {
            return block(
                DualPhoneLiveStreamSessionBlock.MISSING_CALIBRATION_IMAGE_SIZE,
            )
        }

        val calibratedSize = "${intrinsics.imageWidth}x${intrinsics.imageHeight}"
        val preferredMode = settings.preferredVideoModeId
            ?.trim()
            ?.takeIf { it.isNotBlank() }
        val recordingModeIdentity = if (preferredMode != null) {
            "preferred=$preferredMode|calibrated_size=$calibratedSize"
        } else {
            "calibrated_size=$calibratedSize"
        }
        val identitySource = if (preferredMode != null) {
            "preferred_video_mode_and_calibrated_size"
        } else {
            "calibrated_size_only"
        }

        val ownerKey = OwnerKey(
            sessionUuid = sessionUuid,
            dualCaptureId = dualCaptureId,
            localRole = settings.role.name,
            peerIdentity = connectedPeerId,
            cameraIdentity = cameraIdentity,
            recordingModeIdentity = recordingModeIdentity,
            calibrationIdentity = profile.profileId,
            rigMountRevision = profile.rigMountRevision,
            captureMode = input.requestedMode,
        )
        val existingOwnerMatches =
            ownerKey == currentOwnerKey &&
                !currentStreamId.isNullOrBlank()
        if (
            control.phase != DualPhoneControlPhase.CONNECTED &&
            !(existingOwnerMatches && control.phase in ACTIVE_CAPTURE_PHASES)
        ) {
            return block(
                DualPhoneLiveStreamSessionBlock.CONTROL_PHASE_UNAVAILABLE,
            )
        }

        val streamId = if (existingOwnerMatches) {
            currentStreamId
        } else {
            null
        }?.takeIf { it.isNotBlank() } ?: streamIdFactory()
            .trim()
            .takeIf { it.isNotBlank() }
            ?: error("streamIdFactory returned a blank stream ID")

        val owner = DualPhoneLiveStreamOwner(
            sessionUuid = ownerKey.sessionUuid,
            dualCaptureId = ownerKey.dualCaptureId,
            localRole = ownerKey.localRole,
            peerIdentity = ownerKey.peerIdentity,
            cameraIdentity = ownerKey.cameraIdentity,
            recordingModeIdentity = ownerKey.recordingModeIdentity,
            calibrationIdentity = ownerKey.calibrationIdentity,
            rigMountRevision = ownerKey.rigMountRevision,
            captureMode = ownerKey.captureMode,
            streamId = streamId,
        )

        controller.prepare(owner)
        currentOwnerKey = ownerKey
        currentStreamId = streamId
        currentBlock = DualPhoneLiveStreamSessionBlock.NONE
        currentRecordingModeIdentitySource = identitySource
        return currentStatus()
    }

    @Synchronized
    fun markTransportReady(): Boolean {
        val streamId = currentStreamId ?: return false
        return controller.markReady(streamId)
    }

    @Synchronized
    fun markCaptureStarted(): Boolean {
        val streamId = currentStreamId ?: return false
        return controller.start(streamId)
    }

    @Synchronized
    fun markCaptureStopRequested(): Boolean {
        val streamId = currentStreamId ?: return false
        return controller.beginStop(streamId)
    }

    @Synchronized
    fun markCaptureStopped(): Boolean {
        val streamId = currentStreamId ?: return false
        return controller.completeStop(streamId)
    }

    @Synchronized
    fun release() {
        resetOwner()
        currentRequestedMode = DualPhoneLiveStreamMode.SYNC_VIDEO
        currentBlock = DualPhoneLiveStreamSessionBlock.MODE_DISABLED
    }

    private fun block(
        block: DualPhoneLiveStreamSessionBlock,
        keepRequestedMode: Boolean = true,
    ): DualPhoneLiveStreamSessionStatus {
        resetOwner()
        currentBlock = block
        currentRecordingModeIdentitySource = null
        if (!keepRequestedMode) {
            currentRequestedMode = DualPhoneLiveStreamMode.SYNC_VIDEO
        }
        return currentStatus()
    }

    private fun resetOwner() {
        controller.release()
        currentOwnerKey = null
        currentStreamId = null
    }

    private fun roleIdentity(
        role: DualPhoneRole,
        profile: DualPhoneCalibrationProfileResult,
    ): RoleIdentity? = when (role) {
        DualPhoneRole.MASTER -> RoleIdentity(
            localDeviceId = profile.masterDeviceId,
            peerDeviceId = profile.slaveDeviceId,
            localCameraId = profile.masterCameraId,
            localIntrinsics = profile.masterIntrinsics,
        )
        DualPhoneRole.SLAVE -> RoleIdentity(
            localDeviceId = profile.slaveDeviceId,
            peerDeviceId = profile.masterDeviceId,
            localCameraId = profile.slaveCameraId,
            localIntrinsics = profile.slaveIntrinsics,
        )
        DualPhoneRole.STANDALONE -> null
    }

    private companion object {
        val ACTIVE_CAPTURE_PHASES: Set<DualPhoneControlPhase> = setOf(
            DualPhoneControlPhase.ARMING,
            DualPhoneControlPhase.ARMED,
            DualPhoneControlPhase.START_SCHEDULED,
            DualPhoneControlPhase.RECORDING,
        )
    }
}
