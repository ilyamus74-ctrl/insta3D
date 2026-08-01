package com.example.maklertour.data.dualphone

import android.content.Context
import com.maklertour.data.calibration.DualPhoneCalibrationProfileStore
import com.maklertour.data.dualphone.DualPhoneControlManager
import com.maklertour.data.dualphone.DualPhoneControlPhase
import com.maklertour.data.dualphone.DualPhoneControlSnapshot
import com.maklertour.data.dualphone.DualPhoneRole
import com.maklertour.data.dualphone.DualPhoneStereoSettingsStore
import java.io.Closeable
import java.util.UUID
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.cancel
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.collect
import kotlinx.coroutines.launch
import org.json.JSONObject

enum class DualPhoneApplicationMode {
    SETTINGS,
    WORK_APP,
    WORK_LIVE,
    WORK_HYBRID;

    val working: Boolean
        get() = this != SETTINGS

    companion object {
        fun fromStreamMode(mode: DualPhoneLiveStreamMode): DualPhoneApplicationMode =
            when (mode) {
                DualPhoneLiveStreamMode.LIVE_METRIC -> WORK_LIVE
                DualPhoneLiveStreamMode.HYBRID -> WORK_HYBRID
                DualPhoneLiveStreamMode.SYNC_VIDEO -> WORK_APP
            }
    }
}

data class DualPhoneApplicationRuntimeSnapshot(
    val applicationMode: DualPhoneApplicationMode =
        DualPhoneApplicationMode.SETTINGS,
    val requestedMode: DualPhoneLiveStreamMode =
        DualPhoneLiveStreamMode.SYNC_VIDEO,
    val localRole: DualPhoneRole = DualPhoneRole.STANDALONE,
    val masterManaged: Boolean = false,
    val peerAcknowledged: Boolean = false,
    val sessionUuid: String? = null,
    val sessionStatus: DualPhoneLiveStreamSessionStatus =
        DualPhoneLiveStreamSessionStatus(
            requestedMode = DualPhoneLiveStreamMode.SYNC_VIDEO,
            block = DualPhoneLiveStreamSessionBlock.MODE_DISABLED,
            snapshot = DualPhoneLiveStreamSnapshot(),
        ),
    val dataChannel: DualPhoneLiveStreamDataChannelSnapshot =
        DualPhoneLiveStreamDataChannelSnapshot(),
    val controlConnected: Boolean = false,
    val controlPhase: DualPhoneControlPhase = DualPhoneControlPhase.STOPPED,
    val peerDeviceId: String? = null,
    val lastMessage: String = "Dual-phone runtime is in settings mode",
    val lastError: String? = null,
)

/**
 * LM01A-4 application-level owner for the live/hybrid session and its data channel.
 *
 * The controller intentionally outlives Compose destinations. MASTER is the only
 * device allowed to request a work mode. SLAVE accepts that mode through the
 * existing control channel, starts the TCP/45831 listener, and exposes a locked
 * work-screen state until MASTER exits work mode or the control link is lost.
 */
class DualPhoneApplicationRuntime private constructor(context: Context) : Closeable {
    private data class PendingMasterStart(
        val commandId: String,
        val config: DualPhoneLiveStreamDataChannelConfig?,
    )

    private val appContext = context.applicationContext
    private val settingsStore = DualPhoneStereoSettingsStore(appContext)
    private val calibrationProfileStore =
        DualPhoneCalibrationProfileStore(appContext)
    private val controlManager = DualPhoneControlManager.get(appContext)
    private val coordinator = DualPhoneLiveStreamSessionCoordinator()
    private val dataChannel = DualPhoneLiveStreamDataChannelController()
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.Default)
    private val mutableState = MutableStateFlow(
        DualPhoneApplicationRuntimeSnapshot(
            localRole = settingsStore.load().role,
            sessionStatus = coordinator.currentStatus(),
        ),
    )

    val state: StateFlow<DualPhoneApplicationRuntimeSnapshot> =
        mutableState.asStateFlow()

    @Volatile
    private var pendingMasterStart: PendingMasterStart? = null

    init {
        scope.launch {
            controlManager.state.collect { handleControlSnapshot(it) }
        }
        scope.launch {
            dataChannel.state.collect { handleDataChannelSnapshot(it) }
        }
    }

    @Synchronized
    fun enterManagedWorkSurface(forcePassive: Boolean = false) {
        val settings = settingsStore.load()
        val control = controlManager.state.value
        if (settings.role != DualPhoneRole.MASTER) {
            return
        }
        if (!control.connected) {
            publishError("Connect SLAVE before opening a managed work section")
            return
        }

        val current = mutableState.value
        if (
            current.applicationMode == DualPhoneApplicationMode.WORK_APP &&
            (current.peerAcknowledged || pendingMasterStart != null)
        ) {
            return
        }
        if (current.applicationMode.working && !forcePassive) {
            return
        }

        val commandId = "work-surface-${UUID.randomUUID()}"
        pendingMasterStart = PendingMasterStart(
            commandId = commandId,
            config = null,
        )
        dataChannel.stop()
        coordinator.release()
        mutableState.value = current.copy(
            applicationMode = DualPhoneApplicationMode.WORK_APP,
            requestedMode = DualPhoneLiveStreamMode.SYNC_VIDEO,
            localRole = DualPhoneRole.MASTER,
            masterManaged = false,
            peerAcknowledged = false,
            sessionUuid = null,
            sessionStatus = coordinator.currentStatus(),
            dataChannel = dataChannel.snapshot,
            lastMessage = "Waiting for SLAVE managed application screen",
            lastError = null,
        )

        controlManager.requestEnterWorkMode(
            JSONObject()
                .put("command_id", commandId)
                .put(
                    "dual_capture_id",
                    control.dualCaptureId ?: JSONObject.NULL,
                )
                .put(
                    "application_mode",
                    DualPhoneApplicationMode.WORK_APP.name,
                )
                .put(
                    "capture_mode",
                    DualPhoneLiveStreamMode.SYNC_VIDEO.name,
                ),
        )
    }

    @Synchronized
    fun enterWorkMode(
        sessionUuid: String?,
        mode: DualPhoneLiveStreamMode,
    ) {
        val settings = settingsStore.load()
        if (settings.role != DualPhoneRole.MASTER) {
            publishError("Only MASTER can select LIVE or HYBRID")
            return
        }
        if (!mode.streamEnabled) {
            enterManagedWorkSurface(forcePassive = true)
            return
        }

        val control = controlManager.state.value
        if (!control.connected) {
            publishError("Connect SLAVE before selecting LIVE or HYBRID")
            return
        }

        val status = reconcile(sessionUuid, mode)
        val owner = status.snapshot.owner
        val peerHost = control.peerHost?.trim()?.takeIf { it.isNotBlank() }
        val config = if (status.sessionAccepted && owner != null && peerHost != null) {
            DualPhoneLiveStreamDataChannelConfig(
                owner = owner,
                localDeviceId = settings.deviceId,
                role = DualPhoneRole.MASTER,
                peerHost = peerHost,
            )
        } else {
            null
        }

        val commandId = "work-${UUID.randomUUID()}"
        pendingMasterStart = PendingMasterStart(commandId, config)
        dataChannel.stop()
        mutableState.value = mutableState.value.copy(
            applicationMode = DualPhoneApplicationMode.fromStreamMode(mode),
            requestedMode = mode,
            localRole = DualPhoneRole.MASTER,
            masterManaged = false,
            peerAcknowledged = false,
            sessionUuid = sessionUuid,
            sessionStatus = status,
            dataChannel = dataChannel.snapshot,
            lastMessage = if (config != null) {
                "Waiting for SLAVE TCP/45831 listener"
            } else {
                "SLAVE is entering managed mode; local transport is blocked"
            },
            lastError = if (config == null) status.block.name else null,
        )

        controlManager.requestEnterWorkMode(
            JSONObject()
                .put("command_id", commandId)
                .put(
                    "dual_capture_id",
                    control.dualCaptureId ?: JSONObject.NULL,
                )
                .put(
                    "application_mode",
                    DualPhoneApplicationMode.fromStreamMode(mode).name,
                )
                .put(
                    "session_uuid",
                    sessionUuid ?: JSONObject.NULL,
                )
                .put("capture_mode", mode.name)
                .put(
                    "calibration_profile_id",
                    owner?.calibrationIdentity ?: JSONObject.NULL,
                )
                .put(
                    "rig_mount_revision",
                    owner?.rigMountRevision ?: JSONObject.NULL,
                )
                .put(
                    "recording_mode_identity",
                    owner?.recordingModeIdentity ?: JSONObject.NULL,
                )
                .put(
                    "master_transport_block",
                    if (config == null) status.block.name else JSONObject.NULL,
                ),
        )
    }

    @Synchronized
    fun exitWorkMode() {
        val current = mutableState.value
        val settings = settingsStore.load()
        if (
            settings.role == DualPhoneRole.MASTER &&
            controlManager.state.value.connected &&
            (current.applicationMode.working || pendingMasterStart != null)
        ) {
            controlManager.requestExitWorkMode(
                JSONObject()
                    .put("command_id", "work-exit-${UUID.randomUUID()}")
                    .put(
                        "dual_capture_id",
                        controlManager.state.value.dualCaptureId ?: JSONObject.NULL,
                    )
                    .put("reason", "master_entered_settings"),
            )
        }
        releaseLocal(
            masterManaged = false,
            message = "Settings mode selected on MASTER",
            error = null,
        )
    }

    @Synchronized
    fun refresh() {
        val current = mutableState.value
        if (!current.requestedMode.streamEnabled) return
        val status = reconcile(current.sessionUuid, current.requestedMode)
        mutableState.value = current.copy(
            sessionStatus = status,
            lastError = if (status.sessionAccepted) null else status.block.name,
            lastMessage = if (status.sessionAccepted) {
                "Work-mode identity check passed"
            } else {
                "Work-mode identity check failed"
            },
        )
    }

    @Synchronized
    fun handleRemoteEnterWorkMode(payload: JSONObject): JSONObject {
        val commandId = payload.optString("command_id")
        val settings = settingsStore.load()
        val control = controlManager.state.value
        val mode = runCatching {
            DualPhoneLiveStreamMode.valueOf(
                payload.optString(
                    "capture_mode",
                    DualPhoneLiveStreamMode.SYNC_VIDEO.name,
                ),
            )
        }.getOrNull()
        val applicationMode = runCatching {
            DualPhoneApplicationMode.valueOf(
                payload.optString("application_mode"),
            )
        }.getOrNull() ?: mode?.let(
            DualPhoneApplicationMode::fromStreamMode,
        )
        val sessionUuid = payload.optString("session_uuid")
            .trim()
            .takeIf { it.isNotBlank() && it != "null" }

        val preconditionError = when {
            settings.role != DualPhoneRole.SLAVE -> "LOCAL_ROLE_IS_NOT_SLAVE"
            !control.connected -> "CONTROL_CHANNEL_NOT_CONNECTED"
            applicationMode?.working != true -> "UNSUPPORTED_APPLICATION_MODE"
            payload.optString("dual_capture_id") != control.dualCaptureId ->
                "DUAL_CAPTURE_ID_MISMATCH"
            else -> null
        }
        if (preconditionError != null || applicationMode == null) {
            return workModeAck(
                commandId = commandId,
                accepted = false,
                reason = preconditionError ?: "APPLICATION_MODE_MISSING",
                transportAccepted = false,
            )
        }

        pendingMasterStart = null
        dataChannel.stop()
        mutableState.value = mutableState.value.copy(
            applicationMode = applicationMode,
            requestedMode = mode ?: DualPhoneLiveStreamMode.SYNC_VIDEO,
            localRole = DualPhoneRole.SLAVE,
            masterManaged = true,
            peerAcknowledged = true,
            sessionUuid = sessionUuid,
            dataChannel = dataChannel.snapshot,
            lastMessage = "SLAVE is working under MASTER control",
            lastError = null,
        )

        if (applicationMode == DualPhoneApplicationMode.WORK_APP) {
            coordinator.release()
            mutableState.value = mutableState.value.copy(
                sessionStatus = coordinator.currentStatus(),
                lastMessage =
                    "SLAVE application is controlled by MASTER; waiting for LIVE/HYBRID",
            )
            return workModeAck(
                commandId = commandId,
                accepted = true,
                reason = null,
                transportAccepted = false,
            )
        }

        val acceptedMode = mode
        val transportPrecondition = when {
            acceptedMode?.streamEnabled != true -> "UNSUPPORTED_WORK_MODE"
            sessionUuid == null -> "SESSION_UUID_MISSING"
            else -> null
        }
        if (transportPrecondition != null || acceptedMode == null) {
            mutableState.value = mutableState.value.copy(
                lastMessage = "MASTER controls SLAVE; data channel is blocked",
                lastError = transportPrecondition ?: "CAPTURE_MODE_MISSING",
            )
            return workModeAck(
                commandId = commandId,
                accepted = true,
                reason = transportPrecondition ?: "CAPTURE_MODE_MISSING",
                transportAccepted = false,
            )
        }

        val acceptedSessionUuid = requireNotNull(sessionUuid)
        val status = reconcile(acceptedSessionUuid, acceptedMode)
        val owner = status.snapshot.owner
        val identityError = when {
            !status.sessionAccepted || owner == null -> status.block.name
            payload.optString("calibration_profile_id") !=
                owner.calibrationIdentity -> "CALIBRATION_PROFILE_MISMATCH"
            payload.optString("rig_mount_revision") !=
                owner.rigMountRevision -> "RIG_MOUNT_REVISION_MISMATCH"
            else -> null
        }
        if (identityError != null || owner == null) {
            mutableState.value = mutableState.value.copy(
                sessionStatus = status,
                lastMessage = "MASTER controls SLAVE; data channel is blocked",
                lastError = identityError ?: "OWNER_NOT_ACCEPTED",
            )
            return workModeAck(
                commandId = commandId,
                accepted = true,
                reason = identityError ?: "OWNER_NOT_ACCEPTED",
                transportAccepted = false,
            )
        }

        val masterRecordingModeIdentity =
            payload.optString("recording_mode_identity")
                .trim()
                .takeIf { it.isNotBlank() && it != "null" }
        if (masterRecordingModeIdentity == null) {
            mutableState.value = mutableState.value.copy(
                sessionStatus = status,
                lastMessage = "MASTER controls SLAVE; data channel is blocked",
                lastError = "MASTER_RECORDING_MODE_IDENTITY_MISSING",
            )
            return workModeAck(
                commandId = commandId,
                accepted = true,
                reason = "MASTER_RECORDING_MODE_IDENTITY_MISSING",
                transportAccepted = false,
            )
        }

        val sessionOwner = owner.copy(
            recordingModeIdentity = masterRecordingModeIdentity,
        )
        val startError = runCatching {
            dataChannel.start(
                DualPhoneLiveStreamDataChannelConfig(
                    owner = sessionOwner,
                    localDeviceId = settings.deviceId,
                    role = DualPhoneRole.SLAVE,
                ),
            )
        }.exceptionOrNull()
        if (startError != null) {
            val reason =
                startError.message ?: "SLAVE_LISTENER_START_FAILED"
            mutableState.value = mutableState.value.copy(
                sessionStatus = status,
                dataChannel = dataChannel.snapshot,
                lastMessage = "MASTER controls SLAVE; listener start failed",
                lastError = reason,
            )
            return workModeAck(
                commandId = commandId,
                accepted = true,
                reason = reason,
                transportAccepted = false,
            )
        }

        mutableState.value = mutableState.value.copy(
            applicationMode = applicationMode,
            requestedMode = acceptedMode,
            localRole = DualPhoneRole.SLAVE,
            masterManaged = true,
            peerAcknowledged = true,
            sessionUuid = acceptedSessionUuid,
            sessionStatus = status,
            dataChannel = dataChannel.snapshot,
            lastMessage = "SLAVE work mode is controlled by MASTER",
            lastError = null,
        )
        return workModeAck(
            commandId = commandId,
            accepted = true,
            reason = null,
            transportAccepted = true,
        )
    }

    @Synchronized
    fun handleRemoteEnterWorkModeAck(payload: JSONObject) {
        val pending = pendingMasterStart ?: return
        if (payload.optString("command_id") != pending.commandId) return

        val accepted = payload.optBoolean("accepted", false)
        val transportAccepted = payload.optBoolean(
            "transport_accepted",
            accepted,
        )
        val reason = payload.optString("reason")
            .trim()
            .takeIf { it.isNotBlank() && it != "null" }

        pendingMasterStart = null
        if (!accepted) {
            dataChannel.stop()
            mutableState.value = mutableState.value.copy(
                peerAcknowledged = false,
                dataChannel = dataChannel.snapshot,
                lastMessage = "SLAVE did not enter managed work mode",
                lastError = reason ?: "SLAVE_REJECTED_WORK_MODE",
            )
            return
        }

        val config = pending.config
        if (!transportAccepted || config == null) {
            dataChannel.stop()
            mutableState.value = mutableState.value.copy(
                peerAcknowledged = true,
                dataChannel = dataChannel.snapshot,
                lastMessage = if (config == null && reason == null) {
                    "SLAVE entered the managed application screen"
                } else {
                    "SLAVE entered managed mode; data channel is blocked"
                },
                lastError = reason ?: mutableState.value.lastError,
            )
            return
        }

        dataChannel.start(config)
        mutableState.value = mutableState.value.copy(
            peerAcknowledged = true,
            dataChannel = dataChannel.snapshot,
            lastMessage = "SLAVE listener acknowledged; MASTER is connecting",
            lastError = null,
        )
    }

    @Synchronized
    fun handleRemoteExitWorkMode(payload: JSONObject): JSONObject {
        val commandId = payload.optString("command_id")
        if (settingsStore.load().role != DualPhoneRole.SLAVE) {
            return workModeAck(commandId, false, "LOCAL_ROLE_IS_NOT_SLAVE")
        }
        releaseLocal(
            masterManaged = true,
            message = "MASTER returned SLAVE to settings mode",
            error = null,
        )
        return workModeAck(commandId, true, null)
    }

    @Synchronized
    fun handleRemoteExitWorkModeAck(payload: JSONObject) {
        if (!payload.optBoolean("accepted", false)) {
            mutableState.value = mutableState.value.copy(
                lastMessage = "SLAVE did not confirm settings mode",
                lastError = payload.optString(
                    "reason",
                    "SLAVE_REJECTED_SETTINGS_MODE",
                ),
            )
            return
        }
        mutableState.value = mutableState.value.copy(
            lastMessage = "SLAVE confirmed settings mode",
            lastError = null,
        )
    }

    @Synchronized
    fun emergencyDisconnect() {
        releaseLocal(
            masterManaged = false,
            message = "SLAVE disconnected from MASTER by operator",
            error = null,
        )
        controlManager.stop()
    }

    @Synchronized
    private fun handleControlSnapshot(control: DualPhoneControlSnapshot) {
        val role = settingsStore.load().role
        if (
            (!control.connected || role == DualPhoneRole.STANDALONE) &&
            (mutableState.value.applicationMode.working ||
                mutableState.value.masterManaged ||
                pendingMasterStart != null)
        ) {
            releaseLocal(
                masterManaged = false,
                message = "Control channel disconnected; work mode stopped",
                error = control.lastError,
            )
        }
        mutableState.value = mutableState.value.copy(
            localRole = role,
            controlConnected = control.connected,
            controlPhase = control.phase,
            peerDeviceId = control.peerDeviceId,
        )
    }

    @Synchronized
    private fun handleDataChannelSnapshot(
        snapshot: DualPhoneLiveStreamDataChannelSnapshot,
    ) {
        when (snapshot.state) {
            DualPhoneLiveStreamDataChannelState.READY ->
                coordinator.markTransportReady()
            DualPhoneLiveStreamDataChannelState.RECONNECTING,
            DualPhoneLiveStreamDataChannelState.FAILED ->
                coordinator.markTransportReconnecting(
                    snapshot.lastError ?: "LM01A data channel unavailable",
                )
            else -> Unit
        }
        val transportError = when {
            snapshot.lastError != null -> snapshot.lastError
            snapshot.state in setOf(
                DualPhoneLiveStreamDataChannelState.LISTENING,
                DualPhoneLiveStreamDataChannelState.CONNECTING,
                DualPhoneLiveStreamDataChannelState.HANDSHAKING,
                DualPhoneLiveStreamDataChannelState.READY,
            ) -> null
            else -> mutableState.value.lastError
        }
        mutableState.value = mutableState.value.copy(
            dataChannel = snapshot,
            sessionStatus = coordinator.currentStatus(),
            lastError = transportError,
        )
    }

    private fun reconcile(
        sessionUuid: String?,
        mode: DualPhoneLiveStreamMode,
    ): DualPhoneLiveStreamSessionStatus {
        val settings = settingsStore.load()
        val control = controlManager.state.value
        val profile = settings.activeCalibrationProfileId
            ?.let(calibrationProfileStore::load)
        return coordinator.reconcile(
            DualPhoneLiveStreamSessionInput(
                sessionUuid = sessionUuid,
                requestedMode = mode,
                settings = settings,
                control = control,
                calibrationProfile = profile,
            ),
        )
    }

    private fun releaseLocal(
        masterManaged: Boolean,
        message: String,
        error: String?,
    ) {
        pendingMasterStart = null
        dataChannel.stop()
        coordinator.release()
        mutableState.value = mutableState.value.copy(
            applicationMode = DualPhoneApplicationMode.SETTINGS,
            requestedMode = DualPhoneLiveStreamMode.SYNC_VIDEO,
            masterManaged = masterManaged,
            peerAcknowledged = false,
            sessionUuid = null,
            sessionStatus = coordinator.currentStatus(),
            dataChannel = dataChannel.snapshot,
            lastMessage = message,
            lastError = error,
        )
    }

    private fun publishError(message: String) {
        mutableState.value = mutableState.value.copy(
            lastMessage = message,
            lastError = message,
        )
    }

    private fun workModeAck(
        commandId: String,
        accepted: Boolean,
        reason: String?,
        transportAccepted: Boolean = accepted,
    ): JSONObject = JSONObject()
        .put("command_id", commandId)
        .put("accepted", accepted)
        .put("transport_accepted", transportAccepted)
        .put("reason", reason ?: JSONObject.NULL)
        .put("application_mode", mutableState.value.applicationMode.name)
        .put("data_channel_state", dataChannel.snapshot.state.name)
        .put("data_channel_port", dataChannel.snapshot.port)

    override fun close() {
        dataChannel.close()
        coordinator.release()
        scope.cancel()
    }

    companion object {
        @Volatile
        private var instance: DualPhoneApplicationRuntime? = null

        fun get(context: Context): DualPhoneApplicationRuntime =
            instance ?: synchronized(this) {
                instance ?: DualPhoneApplicationRuntime(context).also {
                    instance = it
                }
            }
    }
}
