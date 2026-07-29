package com.maklertour.data.dualphone

import android.content.Context
import android.os.SystemClock
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.cancel
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch
import kotlinx.coroutines.withTimeoutOrNull
import org.json.JSONObject
import java.io.BufferedReader
import java.io.BufferedWriter
import java.io.Closeable
import java.io.InputStreamReader
import java.io.OutputStreamWriter
import java.net.Inet4Address
import java.net.InetSocketAddress
import java.net.NetworkInterface
import java.net.ServerSocket
import java.net.Socket
import java.util.Collections

enum class DualPhoneControlPhase {
    STOPPED,
    LISTENING,
    CONNECTING,
    CONNECTED,
    ARMING,
    ARMED,
    START_SCHEDULED,
    RECORDING,
    ERROR,
}

data class DualPhoneControlSnapshot(
    val phase: DualPhoneControlPhase = DualPhoneControlPhase.STOPPED,
    val role: DualPhoneRole = DualPhoneRole.STANDALONE,
    val localHost: String? = null,
    val peerHost: String? = null,
    val peerDeviceId: String? = null,
    val peerModel: String? = null,
    val peerCameraId: String? = null,
    val peerVideoModeId: String? = null,
    val pairingCode: String? = null,
    val dualCaptureId: String? = null,
    val connected: Boolean = false,
    val lastMessage: String = "Control channel stopped",
    val lastRxElapsedMs: Long? = null,
    val lastCommand: String? = null,
    val lastError: String? = null,
    val localVideoPath: String? = null,
    val localManifestPath: String? = null,
    val peerVideoPath: String? = null,
    val peerManifestPath: String? = null,
    val localStartLatenessNs: Long? = null,
    val peerStartLatenessNs: Long? = null,
    val localRolePackagePath: String? = null,
    val peerRolePackagePath: String? = null,
    val aggregatePackagePath: String? = null,
    val aggregateUploadState: String = "IDLE",
    val clockSync: DualPhoneClockSyncSnapshot = DualPhoneClockSyncSnapshot(),
)

class DualPhoneControlManager private constructor(context: Context) : Closeable {
    private val appContext = context.applicationContext
    private val settingsStore = DualPhoneStereoSettingsStore(appContext)
    private val capabilityProbe = DualPhoneCapabilityProbe(appContext)
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)

    private val mutableState = MutableStateFlow(DualPhoneControlSnapshot())
    val state: StateFlow<DualPhoneControlSnapshot> = mutableState.asStateFlow()
    private val clockSyncController = DualPhoneClockSyncController(
        scope = scope,
        onSnapshot = { clockSync ->
            mutableState.value = mutableState.value.copy(clockSync = clockSync)
            DualPhoneCaptureRuntime.current()?.recordClockSync(clockSync)
        },
        onStatusForSlave = { payload ->
            runCatching {
                send(DualPhoneControlType.CLOCK_SYNC_STATUS, payload)
            }
        },
    )

    private val writeLock = Any()
    private var serverSocket: ServerSocket? = null
    private var socket: Socket? = null
    private var reader: BufferedReader? = null
    private var writer: BufferedWriter? = null
    private var transportJob: Job? = null
    private var heartbeatJob: Job? = null
    private var scheduledStartJob: Job? = null
    private var lastRxElapsedMs = 0L
    private var masterPairingCode: String? = null
    private var masterDualCaptureId: String? = null
    private var localArmResult: DualPhoneCaptureArmResult? = null
    private val bundleCoordinator = DualPhoneBundleCoordinator(appContext, scope)
    private var localRolePackage: DualPhoneRolePackage? = null
    private var slaveTransferOffer: DualPhoneTransferOffer? = null

    fun startMaster() {
        val settings = settingsStore.load()
        if (settings.role != DualPhoneRole.MASTER) {
            updateError("Select Master role before starting the server")
            return
        }
        stopTransport("Restarting Master control server")
        transportJob = scope.launch {
            val code = DualPhoneControlProtocol.pairingCode()
            val captureId = DualPhoneControlProtocol.dualCaptureId()
            masterPairingCode = code
            masterDualCaptureId = captureId
            try {
                val server = ServerSocket().apply {
                    reuseAddress = true
                    bind(InetSocketAddress(settings.controlPort))
                }
                serverSocket = server
                mutableState.value = DualPhoneControlSnapshot(
                    phase = DualPhoneControlPhase.LISTENING,
                    role = DualPhoneRole.MASTER,
                    localHost = localIpv4Address(),
                    pairingCode = code,
                    dualCaptureId = captureId,
                    lastMessage = "Waiting for Slave on TCP ${settings.controlPort}",
                )
                while (isActive && !server.isClosed) {
                    val accepted = server.accept()
                    handleMasterClient(accepted, settings, code, captureId)
                }
            } catch (t: Throwable) {
                if (serverSocket?.isClosed != true) {
                    updateError("Master server failed: ${t.message ?: t.javaClass.simpleName}")
                }
            } finally {
                closeConnection()
                closeQuietly(serverSocket)
                serverSocket = null
            }
        }
    }

    fun connectSlave(masterHost: String, pairingCode: String) {
        val settings = settingsStore.load()
        if (settings.role != DualPhoneRole.SLAVE) {
            updateError("Select Slave role before connecting")
            return
        }
        val host = masterHost.trim()
        val code = pairingCode.trim()
        if (host.isBlank() || code.length != 6 || code.any { !it.isDigit() }) {
            updateError("Enter Master IP and six-digit pairing code")
            return
        }
        settingsStore.save(settings.copy(masterHost = host))
        stopTransport("Connecting to Master")
        transportJob = scope.launch {
            mutableState.value = DualPhoneControlSnapshot(
                phase = DualPhoneControlPhase.CONNECTING,
                role = DualPhoneRole.SLAVE,
                peerHost = host,
                lastMessage = "Connecting to $host:${settings.controlPort}",
            )
            try {
                val connectedSocket = Socket()
                connectedSocket.connect(
                    InetSocketAddress(host, settings.controlPort),
                    CONNECT_TIMEOUT_MS,
                )
                installConnection(connectedSocket)
                send(
                    DualPhoneControlType.HELLO,
                    JSONObject()
                        .put("device_id", settings.deviceId)
                        .put("role", DualPhoneRole.SLAVE.name)
                        .put("pairing_code", code)
                        .put(
                            "preferred_video_mode_id",
                            settings.preferredVideoModeId ?: JSONObject.NULL,
                        ),
                )
                val welcome = readRequiredMessage(HANDSHAKE_TIMEOUT_MS)
                if (welcome.optString("type") != DualPhoneControlType.WELCOME) {
                    throw IllegalStateException("Master rejected pairing")
                }
                val payload = welcome.getJSONObject("payload")
                val peerDeviceId = payload.getString("device_id")
                val captureId = payload.getString("dual_capture_id")
                settingsStore.save(
                    settingsStore.load().copy(
                        peerDeviceId = peerDeviceId,
                        masterHost = host,
                    ),
                )
                markRx()
                mutableState.value = mutableState.value.copy(
                    phase = DualPhoneControlPhase.CONNECTED,
                    role = DualPhoneRole.SLAVE,
                    connected = true,
                    peerHost = connectedSocket.inetAddress.hostAddress,
                    peerDeviceId = peerDeviceId,
                    dualCaptureId = captureId,
                    lastMessage = "Paired with Master",
                    lastRxElapsedMs = lastRxElapsedMs,
                )
                clockSyncController.startSlave(
                    port = settings.clockSyncPort,
                    expectedMasterHost = host,
                    dualCaptureId = captureId,
                )
                sendCapabilities()
                startHeartbeat(DualPhoneRole.SLAVE)
                readLoop(DualPhoneRole.SLAVE)
                if (mutableState.value.connected) {
                    mutableState.value = mutableState.value.copy(
                        phase = DualPhoneControlPhase.ERROR,
                        connected = false,
                        lastMessage = "Master disconnected",
                    )
                }
            } catch (t: Throwable) {
                updateError("Slave connection failed: ${t.message ?: t.javaClass.simpleName}")
            } finally {
                closeConnection()
            }
        }
    }

    fun arm() {
        launchMasterCommand(
            command = DualPhoneControlType.ARM,
            allowedPhases = setOf(DualPhoneControlPhase.CONNECTED),
        ) {
            val sync = clockSyncController.currentSnapshot()
            if (!sync.captureSchedulingAllowed) {
                throw IllegalStateException(
                    "Clock sync cannot schedule capture " +
                        "(${sync.quality.name}, samples=" +
                        "${sync.acceptedSamples}/${sync.totalSamples}, " +
                        "median_rtt_ns=${sync.medianRttNs}, " +
                        "uncertainty_ns=${sync.uncertaintyNs})",
                )
            }
            val settings = settingsStore.load()
            val captureId = currentCaptureId()
            val commandId = DualPhoneControlProtocol.commandId("arm")
            slaveTransferOffer?.serverJob?.cancel()
            slaveTransferOffer = null
            localRolePackage = null
            mutableState.value = mutableState.value.copy(
                phase = DualPhoneControlPhase.ARMING,
                lastCommand = DualPhoneControlType.ARM,
                lastError = null,
                lastMessage = "Starting local pre-roll recording",
                localRolePackagePath = null,
                peerRolePackagePath = null,
                aggregatePackagePath = null,
                aggregateUploadState = "IDLE",
            )
            val endpoint = DualPhoneCaptureRuntime.requireEndpoint()
            val local = withTimeoutOrNull(ARM_PREPARE_TIMEOUT_MS) {
                endpoint.arm(
                    DualPhoneCaptureArmRequest(
                        dualCaptureId = captureId,
                        role = DualPhoneRole.MASTER,
                        deviceId = settings.deviceId,
                        peerDeviceId = settings.peerDeviceId,
                        preferredVideoModeId =
                            settings.preferredVideoModeId,
                        commandId = commandId,
                    ),
                )
            } ?: throw IllegalStateException(
                "Local CameraX ARM preparation timed out after " +
                    "${ARM_PREPARE_TIMEOUT_MS / 1_000L} seconds",
            )
            if (!local.ready) {
                throw IllegalStateException(
                    local.reason ?: "Local recorder rejected ARM",
                )
            }
            localArmResult = local
            try {
                send(
                    DualPhoneControlType.ARM,
                    JSONObject()
                        .put("dual_capture_id", captureId)
                        .put("command_id", commandId)
                        .put(
                            "preferred_video_mode_id",
                            settings.preferredVideoModeId
                                ?: JSONObject.NULL,
                        )
                        .put(
                            "requested_at_master_ns",
                            SystemClock.elapsedRealtimeNanos(),
                        ),
                )
            } catch (error: Throwable) {
                endpoint.abort("Failed to send ARM to Slave")
                localArmResult = null
                throw error
            }
            mutableState.value = mutableState.value.copy(
                lastCommand = DualPhoneControlType.ARM,
                lastError = null,
                localVideoPath = local.outputPath,
                lastMessage =
                    "Local pre-roll recording active; waiting for Slave ARM_ACK",
            )
        }
    }

    fun startAfter(delayMs: Long = 3_000L) {
        launchMasterCommand(
            command = DualPhoneControlType.START_AT,
            allowedPhases = setOf(DualPhoneControlPhase.ARMED),
        ) {
            val safeDelay = delayMs.coerceIn(1_000L, 30_000L)
            val commandCreatedMasterNs = SystemClock.elapsedRealtimeNanos()
            val startAt = commandCreatedMasterNs + safeDelay * 1_000_000L
            val commandId = DualPhoneControlProtocol.commandId("start")
            val slaveStartAt =
                clockSyncController.masterToSlaveNs(startAt)
                    ?: throw IllegalStateException(
                        "Clock sync model is unavailable or stale",
                    )
            val sync = clockSyncController.currentSnapshot()
            val localStartRequest = DualPhoneCaptureStartRequest(
                dualCaptureId = currentCaptureId(),
                role = DualPhoneRole.MASTER,
                scheduledElapsedRealtimeNs = startAt,
                clockOffsetNs = sync.offsetNs,
                clockUncertaintyNs = sync.uncertaintyNs,
                clockDriftPpm = sync.driftPpm,
                commandId = commandId,
                commandCreatedMasterElapsedRealtimeNs =
                    commandCreatedMasterNs,
                commandReceivedLocalElapsedRealtimeNs =
                    commandCreatedMasterNs,
            )
            mutableState.value = mutableState.value.copy(
                phase = DualPhoneControlPhase.START_SCHEDULED,
                lastCommand = DualPhoneControlType.START_AT,
                lastError = null,
                lastMessage =
                    "Logical START marker scheduled using ${sync.quality.name} clock model",
            )
            scheduleLocalCaptureStart(
                request = localStartRequest,
                notifyPeer = false,
            )
            val deliveryError = runCatching {
                send(
                    DualPhoneControlType.START_AT,
                    JSONObject()
                        .put("dual_capture_id", currentCaptureId())
                        .put("command_id", commandId)
                        .put("command_created_master_ns", commandCreatedMasterNs)
                        .put("master_elapsed_realtime_ns", startAt)
                        .put("slave_elapsed_realtime_ns", slaveStartAt)
                        .put("marker_semantics", "LOGICAL_CAPTURE_WINDOW_START")
                        .put(
                            "clock_offset_ns",
                            sync.offsetNs ?: JSONObject.NULL,
                        )
                        .put(
                            "clock_uncertainty_ns",
                            sync.uncertaintyNs ?: JSONObject.NULL,
                        )
                        .put(
                            "clock_drift_ppm",
                            sync.driftPpm ?: JSONObject.NULL,
                        )
                        .put("delay_ms", safeDelay),
                )
            }.exceptionOrNull()
            if (deliveryError != null) {
                mutableState.value = mutableState.value.copy(
                    lastError = "Slave START marker delivery failed: " +
                        (deliveryError.message ?: deliveryError.javaClass.simpleName),
                    lastMessage =
                        "Local START marker remains scheduled; Slave delivery failed",
                )
            }
        }
    }

    fun stopCapture() {
        launchMasterCommand(
            command = DualPhoneControlType.STOP,
            allowedPhases = setOf(
                DualPhoneControlPhase.ARMED,
                DualPhoneControlPhase.START_SCHEDULED,
                DualPhoneControlPhase.RECORDING,
            ),
        ) {
            val captureId = currentCaptureId()
            val commandId = DualPhoneControlProtocol.commandId("stop")
            val stopMarkerMasterNs = SystemClock.elapsedRealtimeNanos()
            val localStopRequest = DualPhoneCaptureStopRequest(
                dualCaptureId = captureId,
                role = DualPhoneRole.MASTER,
                commandId = commandId,
                commandCreatedMasterElapsedRealtimeNs = stopMarkerMasterNs,
                commandReceivedLocalElapsedRealtimeNs = stopMarkerMasterNs,
                postRollMs = DUAL_PHONE_DEFAULT_POST_ROLL_MS,
            )
            DualPhoneCaptureRuntime.requireEndpoint().markStop(localStopRequest)
            scheduledStartJob?.cancel()
            scheduledStartJob = null
            mutableState.value = mutableState.value.copy(
                lastCommand = DualPhoneControlType.STOP,
                lastError = null,
                aggregateUploadState = "LOCAL_POST_ROLL_AND_FINALIZE",
                lastMessage =
                    "STOP marker written; finalizing Master independently after post-roll",
            )
            val deliveryError = runCatching {
                send(
                    DualPhoneControlType.STOP,
                    JSONObject()
                        .put("dual_capture_id", captureId)
                        .put("command_id", commandId)
                        .put("command_created_master_ns", stopMarkerMasterNs)
                        .put("marker_semantics", "LOGICAL_CAPTURE_WINDOW_STOP")
                        .put("post_roll_ms", DUAL_PHONE_DEFAULT_POST_ROLL_MS)
                        .put("requested_at_master_ns", stopMarkerMasterNs),
                )
            }.exceptionOrNull()
            val localResult = stopLocalCapture()
            val rolePackage = bundleCoordinator.packageRole(
                stopResult = localResult,
                dualCaptureId = captureId,
                role = DualPhoneRole.MASTER,
            )
            localRolePackage = rolePackage
            localArmResult = null
            mutableState.value = mutableState.value.copy(
                phase = DualPhoneControlPhase.CONNECTED,
                lastCommand = DualPhoneControlType.STOP,
                lastError = deliveryError?.let {
                    "Slave STOP marker delivery failed: " +
                        (it.message ?: it.javaClass.simpleName)
                },
                localVideoPath = localResult.videoPath,
                localManifestPath = localResult.manifestPath,
                localRolePackagePath = rolePackage.file.absolutePath,
                aggregateUploadState = "WAITING_FOR_SLAVE_PACKAGE",
                lastMessage =
                    "Master finalized independently; waiting for Slave package",
            )
        }
    }

    fun stop() {
        stopTransport("Control channel stopped")
        mutableState.value = DualPhoneControlSnapshot(
            role = settingsStore.load().role,
            lastMessage = "Control channel stopped",
        )
    }

    private suspend fun handleMasterClient(
        accepted: Socket,
        settings: DualPhoneStereoSettings,
        pairingCode: String,
        captureId: String,
    ) {
        closeConnection()
        try {
            installConnection(accepted)
            val hello = readRequiredMessage(HANDSHAKE_TIMEOUT_MS)
            if (hello.optString("type") != DualPhoneControlType.HELLO) {
                throw IllegalStateException("Expected HELLO")
            }
            val payload = hello.getJSONObject("payload")
            if (payload.optString("role") != DualPhoneRole.SLAVE.name) {
                throw IllegalStateException("Peer is not a Slave")
            }
            if (payload.optString("pairing_code") != pairingCode) {
                sendError("PAIRING_CODE_INVALID")
                throw IllegalStateException("Invalid pairing code")
            }
            val peerDeviceId = payload.getString("device_id")
            settingsStore.save(settingsStore.load().copy(peerDeviceId = peerDeviceId))
            send(
                DualPhoneControlType.WELCOME,
                JSONObject()
                    .put("device_id", settings.deviceId)
                    .put("dual_capture_id", captureId)
                    .put("master_elapsed_realtime_ns", SystemClock.elapsedRealtimeNanos())
                    .put(
                        "preferred_video_mode_id",
                        settings.preferredVideoModeId ?: JSONObject.NULL,
                    ),
            )
            markRx()
            mutableState.value = mutableState.value.copy(
                phase = DualPhoneControlPhase.CONNECTED,
                role = DualPhoneRole.MASTER,
                connected = true,
                peerHost = accepted.inetAddress.hostAddress,
                peerDeviceId = peerDeviceId,
                pairingCode = pairingCode,
                dualCaptureId = captureId,
                lastMessage = "Slave paired",
                lastRxElapsedMs = lastRxElapsedMs,
            )
            sendCapabilities()
            clockSyncController.startMaster(
                peerHost = accepted.inetAddress.hostAddress,
                port = settings.clockSyncPort,
                dualCaptureId = captureId,
            )
            startHeartbeat(DualPhoneRole.MASTER)
            readLoop(DualPhoneRole.MASTER)
        } catch (t: Throwable) {
            mutableState.value = mutableState.value.copy(
                phase = DualPhoneControlPhase.LISTENING,
                connected = false,
                peerHost = null,
                peerDeviceId = null,
                peerModel = null,
                peerCameraId = null,
                peerVideoModeId = null,
                lastMessage = "Peer disconnected: ${t.message ?: t.javaClass.simpleName}",
            )
        } finally {
            closeConnection()
            if (serverSocket?.isClosed == false) {
                mutableState.value = mutableState.value.copy(
                    phase = DualPhoneControlPhase.LISTENING,
                    connected = false,
                    lastMessage = "Waiting for Slave reconnect",
                )
            }
        }
    }

    private suspend fun readLoop(localRole: DualPhoneRole) {
        while (scope.isActive && socket?.isClosed == false) {
            val line = reader?.readLine() ?: break
            val message = DualPhoneControlProtocol.decode(line)
            markRx()
            val type = message.getString("type")
            val payload = message.getJSONObject("payload")
            when (type) {
                DualPhoneControlType.PING -> send(
                    DualPhoneControlType.PONG,
                    JSONObject().put(
                        "elapsed_realtime_ns",
                        SystemClock.elapsedRealtimeNanos(),
                    ),
                )
                DualPhoneControlType.PONG -> {
                    mutableState.value = mutableState.value.copy(
                        lastRxElapsedMs = lastRxElapsedMs,
                    )
                }
                DualPhoneControlType.CAPABILITIES -> applyPeerCapabilities(payload)
                DualPhoneControlType.CLOCK_SYNC_STATUS -> {
                    if (localRole == DualPhoneRole.SLAVE) {
                        clockSyncController.applyRemoteStatus(payload)
                    }
                }
                DualPhoneControlType.ARM -> if (localRole == DualPhoneRole.SLAVE) {
                    val settings = settingsStore.load()
                    val result = try {
                        DualPhoneCaptureRuntime.requireEndpoint().arm(
                            DualPhoneCaptureArmRequest(
                                dualCaptureId = payload.getString(
                                    "dual_capture_id",
                                ),
                                role = DualPhoneRole.SLAVE,
                                deviceId = settings.deviceId,
                                peerDeviceId =
                                    settings.peerDeviceId,
                                preferredVideoModeId =
                                    payload.optNullableString(
                                        "preferred_video_mode_id",
                                    ) ?: settings.preferredVideoModeId,
                                commandId = payload.optString(
                                    "command_id",
                                    "legacy-arm",
                                ),
                            ),
                        )
                    } catch (error: Throwable) {
                        DualPhoneCaptureArmResult(
                            ready = false,
                            reason = error.message
                                ?: error.javaClass.simpleName,
                        )
                    }
                    mutableState.value = mutableState.value.copy(
                        phase = if (result.ready) {
                            DualPhoneControlPhase.ARMED
                        } else {
                            DualPhoneControlPhase.CONNECTED
                        },
                        localVideoPath = result.outputPath,
                        lastError = result.reason,
                        lastMessage = if (result.ready) {
                            "Slave pre-roll recording active"
                        } else {
                            "Slave ARM rejected: ${result.reason}"
                        },
                    )
                    send(
                        DualPhoneControlType.ARM_ACK,
                        armResultPayload(result),
                    )
                }
                DualPhoneControlType.ARM_ACK -> if (localRole == DualPhoneRole.MASTER) {
                    val ready = payload.optBoolean("ready", false)
                    val local = localArmResult
                    val peerWidth = payload.optNullableInt("width")
                    val peerHeight = payload.optNullableInt("height")
                    val peerFps = payload.optNullableInt("fps")
                    val modeMismatch = ready && local != null && (
                        local.width != peerWidth ||
                            local.height != peerHeight ||
                            local.fps != peerFps
                        )
                    if (!ready || modeMismatch) {
                        val reason = if (modeMismatch) {
                            "VIDEO_MODE_MISMATCH local=" +
                                "${local?.width}x${local?.height}@${local?.fps} " +
                                "peer=${peerWidth}x${peerHeight}@${peerFps}"
                        } else {
                            payload.optString(
                                "reason",
                                "Slave recorder rejected ARM",
                            )
                        }
                        DualPhoneCaptureRuntime.current()?.abort(reason)
                        localArmResult = null
                        mutableState.value = mutableState.value.copy(
                            phase = DualPhoneControlPhase.CONNECTED,
                            lastCommand = DualPhoneControlType.ARM,
                            lastError = reason,
                            lastMessage = "ARM failed: $reason",
                        )
                    } else {
                        mutableState.value = mutableState.value.copy(
                            phase = DualPhoneControlPhase.ARMED,
                            lastCommand = DualPhoneControlType.ARM,
                            lastError = null,
                            peerVideoPath = payload.optNullableString(
                                "output_path",
                            ),
                            lastMessage =
                                "Both pre-roll recordings are active",
                        )
                    }
                }
                DualPhoneControlType.START_AT -> if (localRole == DualPhoneRole.SLAVE) {
                    handleSlaveStart(payload)
                }
                DualPhoneControlType.START_ACK -> if (localRole == DualPhoneRole.MASTER) {
                    val accepted = payload.optBoolean("accepted", false)
                    val rejectionReason = payload.optString(
                        "reason",
                        "Slave rejected START_AT",
                    )
                    mutableState.value = mutableState.value.copy(
                        phase = if (accepted) {
                            mutableState.value.phase
                        } else {
                            DualPhoneControlPhase.ARMED
                        },
                        lastCommand = DualPhoneControlType.START_AT,
                        lastError = if (accepted) null else rejectionReason,
                        lastMessage = if (accepted) {
                            "Slave accepted logical START marker"
                        } else {
                            "Slave rejected START_AT: $rejectionReason"
                        },
                    )
                }
                DualPhoneControlType.CAPTURE_STARTED -> {
                    if (localRole == DualPhoneRole.MASTER) {
                        val current = mutableState.value
                        val keepStopMessage =
                            current.phase == DualPhoneControlPhase.CONNECTED &&
                                current.lastCommand == DualPhoneControlType.STOP
                        mutableState.value = current.copy(
                            peerStartLatenessNs = payload.optLong(
                                "start_lateness_ns",
                            ),
                            peerVideoPath = payload.optNullableString(
                                "video_path",
                            ),
                            lastMessage = if (keepStopMessage) {
                                current.lastMessage
                            } else {
                                "Slave logical START marker applied"
                            },
                        )
                    }
                }
                DualPhoneControlType.STOP -> if (localRole == DualPhoneRole.SLAVE) {
                    scheduledStartJob?.cancel()
                    scheduledStartJob = null
                    val receivedAtNs = SystemClock.elapsedRealtimeNanos()
                    val captureId = payload.getString("dual_capture_id")
                    val commandId = payload.optString(
                        "command_id",
                        "legacy-stop",
                    )
                    val stopRequest = DualPhoneCaptureStopRequest(
                        dualCaptureId = captureId,
                        role = DualPhoneRole.SLAVE,
                        commandId = commandId,
                        commandCreatedMasterElapsedRealtimeNs =
                            payload.optNullableLong(
                                "command_created_master_ns",
                            ),
                        commandReceivedLocalElapsedRealtimeNs = receivedAtNs,
                        postRollMs = payload.optLong(
                            "post_roll_ms",
                            DUAL_PHONE_DEFAULT_POST_ROLL_MS,
                        ),
                    )
                    DualPhoneCaptureRuntime.requireEndpoint().markStop(stopRequest)
                    mutableState.value = mutableState.value.copy(
                        lastMessage =
                            "STOP marker received; finalizing independently after post-roll",
                    )
                    scope.launch {
                        try {
                            val result = stopLocalCapture()
                            val rolePackage = bundleCoordinator.packageRole(
                                stopResult = result,
                                dualCaptureId = captureId,
                                role = DualPhoneRole.SLAVE,
                            )
                            localRolePackage = rolePackage
                            slaveTransferOffer?.serverJob?.cancel()
                            val offer = bundleCoordinator.serveOnce(
                                rolePackage = rolePackage,
                                port = settingsStore.load().bundleTransferPort,
                            )
                            slaveTransferOffer = offer
                            send(
                                DualPhoneControlType.STOP_ACK,
                                offer.toJson(
                                    stopResultPayload(result)
                                        .put("dual_capture_id", captureId)
                                        .put("role", DualPhoneRole.SLAVE.name),
                                ),
                            )
                            mutableState.value =
                                mutableState.value.copy(
                                    phase =
                                        DualPhoneControlPhase.CONNECTED,
                                    localVideoPath =
                                        result.videoPath,
                                    localManifestPath =
                                        result.manifestPath,
                                    localRolePackagePath =
                                        rolePackage.file.absolutePath,
                                    aggregateUploadState =
                                        "WAITING_FOR_MASTER_PULL",
                                    lastError = null,
                                    lastMessage =
                                        "Slave package ready for automatic Master transfer",
                                )
                        } catch (error: Throwable) {
                            val failure = error.message
                                ?: error.javaClass.simpleName
                            DualPhoneCaptureRuntime.current()?.abort(
                                "STOP failed: $failure",
                            )
                            runCatching {
                                send(
                                    DualPhoneControlType.STOP_ACK,
                                    JSONObject()
                                        .put("dual_capture_id", captureId)
                                        .put("role", DualPhoneRole.SLAVE.name)
                                        .put("captured", false)
                                        .put("finalize_error", failure),
                                )
                            }
                            mutableState.value =
                                mutableState.value.copy(
                                    phase =
                                        DualPhoneControlPhase.CONNECTED,
                                    aggregateUploadState =
                                        "LOCAL_FINALIZE_FAILED",
                                    lastError = failure,
                                    lastMessage =
                                        "Slave finalize failed independently",
                                )
                        }
                    }
                }
                DualPhoneControlType.STOP_ACK -> if (localRole == DualPhoneRole.MASTER) {
                    scheduledStartJob?.cancel()
                    scheduledStartJob = null
                    val captured = payload.optBoolean("captured", false)
                    val finalizeError = payload.optString(
                        "finalize_error",
                        "Slave capture was not finalized",
                    )
                    mutableState.value = mutableState.value.copy(
                        phase = DualPhoneControlPhase.CONNECTED,
                        lastCommand = DualPhoneControlType.STOP,
                        lastError = if (captured) null else finalizeError,
                        peerVideoPath = payload.optNullableString(
                            "video_path",
                        ),
                        peerManifestPath = payload.optNullableString(
                            "manifest_path",
                        ),
                        aggregateUploadState = if (captured) {
                            "SLAVE_PACKAGE_OFFER_RECEIVED"
                        } else {
                            "SLAVE_FINALIZE_FAILED"
                        },
                        lastMessage = if (captured) {
                            "Both recordings finalized; fetching Slave package"
                        } else {
                            "Master finalized; Slave finalize failed independently"
                        },
                    )
                    if (captured) {
                        handleMasterRolePackageOffer(payload)
                    }
                }
                DualPhoneControlType.PACKAGE_RECEIVED -> if (
                    localRole == DualPhoneRole.SLAVE
                ) {
                    val receivedHash = payload.optString("sha256").lowercase()
                    val localHash = localRolePackage?.sha256
                    mutableState.value = mutableState.value.copy(
                        aggregateUploadState = if (receivedHash == localHash) {
                            "TRANSFERRED_TO_MASTER"
                        } else {
                            "MASTER_RECEIPT_HASH_MISMATCH"
                        },
                        lastError = if (receivedHash == localHash) {
                            null
                        } else {
                            "Master package receipt SHA-256 mismatch"
                        },
                        lastMessage = if (receivedHash == localHash) {
                            "Slave package transferred and verified by Master"
                        } else {
                            "Master receipt did not match Slave package"
                        },
                    )
                }
                DualPhoneControlType.ERROR -> {
                    val code = payload.optString(
                        "code",
                        "Peer returned ERROR",
                    )
                    mutableState.value = mutableState.value.copy(
                        lastError = "Peer error: $code",
                        lastMessage = "Peer error: $code",
                    )
                }
            }
        }
    }

    private fun handleMasterRolePackageOffer(payload: JSONObject) {
        val offer = DualPhoneTransferOffer.fromJson(payload)
        if (offer == null) {
            mutableState.value = mutableState.value.copy(
                aggregateUploadState = "SLAVE_PACKAGE_OFFER_MISSING",
                lastError = "Slave finalized recording but did not provide a DP04.3 role package",
                lastMessage = "Both recordings finalized; Slave package offer is missing",
            )
            return
        }
        val peerHost = mutableState.value.peerHost
        if (peerHost.isNullOrBlank()) {
            mutableState.value = mutableState.value.copy(
                aggregateUploadState = "PEER_HOST_MISSING",
                lastError = "Slave host is unavailable for package transfer",
            )
            return
        }
        scope.launch {
            try {
                val masterPackage = localRolePackage
                    ?: throw IllegalStateException("Master role package is unavailable")
                mutableState.value = mutableState.value.copy(
                    aggregateUploadState = "DOWNLOADING_SLAVE_PACKAGE",
                    lastMessage = "Downloading Slave role package",
                )
                val slavePackage = bundleCoordinator.download(
                    peerHost = peerHost,
                    offer = offer,
                )
                runCatching {
                    send(
                        DualPhoneControlType.PACKAGE_RECEIVED,
                        JSONObject()
                            .put("dual_capture_id", offer.dualCaptureId)
                            .put("role", offer.role.name)
                            .put("sha256", slavePackage.sha256)
                            .put("size_bytes", slavePackage.sizeBytes),
                    )
                }
                mutableState.value = mutableState.value.copy(
                    peerRolePackagePath = slavePackage.file.absolutePath,
                    aggregateUploadState = "BUILDING_AGGREGATE_BUNDLE",
                    lastMessage = "Slave package verified; building aggregate bundle",
                )
                val aggregate = bundleCoordinator.packageAggregate(
                    masterPackage = masterPackage,
                    slavePackage = slavePackage,
                )
                mutableState.value = mutableState.value.copy(
                    aggregatePackagePath = aggregate.absolutePath,
                    aggregateUploadState = "ENQUEUEING_SERVER_UPLOAD",
                    lastMessage = "Aggregate bundle ready; enqueueing server upload",
                )
                val upload = DualPhoneAggregateUploadRuntime.enqueue(
                    bundleFile = aggregate,
                    dualCaptureId = offer.dualCaptureId,
                )
                mutableState.value = mutableState.value.copy(
                    aggregateUploadState = if (upload.queued) {
                        "QUEUED_FOR_SERVER"
                    } else {
                        "READY_NOT_QUEUED"
                    },
                    lastError = if (upload.queued) null else upload.message,
                    lastMessage = upload.message,
                )
            } catch (error: Throwable) {
                mutableState.value = mutableState.value.copy(
                    aggregateUploadState = "TRANSFER_OR_PACKAGING_FAILED",
                    lastError = error.message ?: error.javaClass.simpleName,
                    lastMessage = "Automatic dual-phone bundle transfer failed",
                )
            }
        }
    }

    private fun handleSlaveStart(payload: JSONObject) {
        val receivedAtNs = SystemClock.elapsedRealtimeNanos()
        val targetSlaveNs = payload.optLong(
            "slave_elapsed_realtime_ns",
            0L,
        )
        val fallbackDelayMs = payload.optLong(
            "delay_ms",
            3_000L,
        ).coerceIn(0L, 30_000L)
        val effectiveTargetNs = if (targetSlaveNs > 0L) {
            targetSlaveNs
        } else {
            receivedAtNs + fallbackDelayMs * 1_000_000L
        }
        val remainingNs = effectiveTargetNs - receivedAtNs
        if (mutableState.value.phase != DualPhoneControlPhase.ARMED) {
            send(
                DualPhoneControlType.START_ACK,
                JSONObject()
                    .put("accepted", false)
                    .put("reason", "SLAVE_NOT_ARMED"),
            )
            return
        }
        if (remainingNs < -MAX_START_LATE_NS) {
            val lateByNs = -remainingNs
            mutableState.value = mutableState.value.copy(
                phase = DualPhoneControlPhase.ARMED,
                lastError = "START_AT arrived too late by ${lateByNs / 1_000_000L} ms",
                lastMessage = "START_AT rejected",
            )
            send(
                DualPhoneControlType.START_ACK,
                JSONObject()
                    .put("accepted", false)
                    .put("reason", "START_AT_LATE")
                    .put("late_by_ns", lateByNs)
                    .put("local_received_ns", receivedAtNs),
            )
            return
        }

        val scheduleDelayMs = (
            (remainingNs.coerceAtLeast(0L) + 999_999L) /
                1_000_000L
        ).coerceIn(0L, 30_000L)
        mutableState.value = mutableState.value.copy(
            phase = DualPhoneControlPhase.START_SCHEDULED,
            lastError = null,
            lastMessage =
                "START_AT received; local delay ${scheduleDelayMs} ms",
        )
        send(
            DualPhoneControlType.START_ACK,
            JSONObject()
                .put("accepted", true)
                .put("local_received_ns", receivedAtNs)
                .put("scheduled_slave_ns", effectiveTargetNs)
                .put("scheduled_delay_ms", scheduleDelayMs),
        )
        scheduleLocalCaptureStart(
            request = DualPhoneCaptureStartRequest(
                dualCaptureId = payload.getString(
                    "dual_capture_id",
                ),
                role = DualPhoneRole.SLAVE,
                scheduledElapsedRealtimeNs = effectiveTargetNs,
                clockOffsetNs =
                    payload.optNullableLong("clock_offset_ns"),
                clockUncertaintyNs =
                    payload.optNullableLong(
                        "clock_uncertainty_ns",
                    ),
                clockDriftPpm =
                    payload.optNullableDouble("clock_drift_ppm"),
            ),
            notifyPeer = true,
        )
    }

    private fun scheduleLocalCaptureStart(
        request: DualPhoneCaptureStartRequest,
        notifyPeer: Boolean,
    ) {
        scheduledStartJob?.cancel()
        scheduledStartJob = scope.launch {
            try {
                delayUntilElapsedRealtimeNs(
                    request.scheduledElapsedRealtimeNs,
                )
                val started =
                    DualPhoneCaptureRuntime.requireEndpoint().start(
                        request,
                    )
                mutableState.value = mutableState.value.copy(
                    phase = DualPhoneControlPhase.RECORDING,
                    localVideoPath = started.videoPath,
                    localStartLatenessNs = started.startLatenessNs,
                    lastError = null,
                    lastMessage =
                        "Local CameraX recording started; lateness " +
                            "${started.startLatenessNs / 1_000_000.0} ms",
                )
                if (notifyPeer) {
                    send(
                        DualPhoneControlType.CAPTURE_STARTED,
                        startResultPayload(started),
                    )
                }
            } catch (t: Throwable) {
                val message =
                    t.message ?: t.javaClass.simpleName
                DualPhoneCaptureRuntime.current()?.abort(
                    "START failed: $message",
                )
                sendError("LOCAL_RECORDING_START_FAILED")
                mutableState.value = mutableState.value.copy(
                    phase = DualPhoneControlPhase.ERROR,
                    lastError = message,
                    lastMessage =
                        "Local recording start failed: $message",
                )
            }
        }
    }

    private suspend fun delayUntilElapsedRealtimeNs(
        targetNs: Long,
    ) {
        while (true) {
            val remainingNs =
                targetNs - SystemClock.elapsedRealtimeNanos()
            if (remainingNs <= 0L) return
            if (remainingNs > 2_000_000L) {
                val sleepMs = (
                    (remainingNs - 1_000_000L) /
                        1_000_000L
                ).coerceAtLeast(1L)
                delay(sleepMs)
            } else {
                Thread.yield()
            }
        }
    }

    private suspend fun stopLocalCapture():
        DualPhoneCaptureStopResult =
        DualPhoneCaptureRuntime.requireEndpoint().stop()

    private fun armResultPayload(
        result: DualPhoneCaptureArmResult,
    ): JSONObject = JSONObject()
        .put("ready", result.ready)
        .putNullable("reason", result.reason)
        .putNullable("output_path", result.outputPath)
        .put("available_bytes", result.availableBytes)
        .putNullable("camera_id", result.cameraId)
        .putNullable("video_mode_id", result.videoModeId)
        .putNullable("width", result.width)
        .putNullable("height", result.height)
        .putNullable("fps", result.fps)

    private fun startResultPayload(
        result: DualPhoneCaptureStartResult,
    ): JSONObject = JSONObject()
        .put("video_path", result.videoPath)
        .put(
            "scheduled_elapsed_ns",
            result.scheduledElapsedRealtimeNs,
        )
        .put(
            "start_call_elapsed_ns",
            result.startCallElapsedRealtimeNs,
        )
        .putNullable(
            "camerax_start_elapsed_ns",
            result.cameraXStartElapsedRealtimeNs,
        )
        .put("start_lateness_ns", result.startLatenessNs)

    private fun stopResultPayload(
        result: DualPhoneCaptureStopResult,
    ): JSONObject = JSONObject()
        .put("captured", result.captured)
        .putNullable("video_path", result.videoPath)
        .put("manifest_path", result.manifestPath)
        .put("duration_ns", result.durationNs)
        .put("file_size_bytes", result.fileSizeBytes)
        .putNullable(
            "scheduled_elapsed_ns",
            result.scheduledElapsedRealtimeNs,
        )
        .putNullable(
            "start_call_elapsed_ns",
            result.startCallElapsedRealtimeNs,
        )
        .putNullable(
            "camerax_start_elapsed_ns",
            result.cameraXStartElapsedRealtimeNs,
        )
        .putNullable(
            "finalize_elapsed_ns",
            result.finalizeElapsedRealtimeNs,
        )

    private fun JSONObject.putNullable(
        key: String,
        value: Any?,
    ): JSONObject = put(key, value ?: JSONObject.NULL)

    private fun JSONObject.optNullableString(
        key: String,
    ): String? = if (!has(key) || isNull(key)) {
        null
    } else {
        optString(key).takeIf { it.isNotBlank() }
    }

    private fun JSONObject.optNullableInt(
        key: String,
    ): Int? = if (!has(key) || isNull(key)) {
        null
    } else {
        optInt(key)
    }

    private fun JSONObject.optNullableLong(
        key: String,
    ): Long? = if (!has(key) || isNull(key)) {
        null
    } else {
        optLong(key)
    }

    private fun JSONObject.optNullableDouble(
        key: String,
    ): Double? = if (!has(key) || isNull(key)) {
        null
    } else {
        optDouble(key)
    }

    private fun sendCapabilities() {

        val settings = settingsStore.load()
        val report = capabilityProbe.buildReport(settings)
        val preferredModeId = report
            .optString("preferred_video_mode_id")
            .takeIf {
                it.isNotBlank() && it != "null"
            }
        send(
            DualPhoneControlType.CAPABILITIES,
            JSONObject()
                .put("device_id", settings.deviceId)
                .put(
                    "preferred_video_mode_id",
                    preferredModeId ?: JSONObject.NULL,
                )
                .put("report", report),
        )
    }

    private fun applyPeerCapabilities(payload: JSONObject) {
        val report = payload.optJSONObject("report") ?: JSONObject()
        val manufacturer = report.optString("manufacturer")
        val model = report.optString("model")
        val modelLabel = listOf(manufacturer, model)
            .filter { it.isNotBlank() }
            .joinToString(" ")
            .ifBlank { null }
        val cameraId = report.optString("selected_camera_id")
            .takeIf { it.isNotBlank() && it != "null" }
        val mode = payload.optString("preferred_video_mode_id")
            .takeIf { it.isNotBlank() && it != "null" }
        mutableState.value = mutableState.value.copy(
            peerModel = modelLabel,
            peerCameraId = cameraId,
            peerVideoModeId = mode,
            lastMessage = "Peer capabilities received",
        )
    }

    private fun startHeartbeat(localRole: DualPhoneRole) {
        heartbeatJob?.cancel()
        heartbeatJob = scope.launch {
            while (isActive && socket?.isClosed == false) {
                delay(HEARTBEAT_INTERVAL_MS)
                val age = SystemClock.elapsedRealtime() - lastRxElapsedMs
                if (lastRxElapsedMs > 0 && age > HEARTBEAT_TIMEOUT_MS) {
                    closeQuietly(socket)
                    break
                }
                send(
                    DualPhoneControlType.PING,
                    JSONObject()
                        .put("role", localRole.name)
                        .put(
                            "elapsed_realtime_ns",
                            SystemClock.elapsedRealtimeNanos(),
                        ),
                )
            }
        }
    }

    private fun installConnection(value: Socket) {
        value.tcpNoDelay = true
        value.keepAlive = true
        socket = value
        reader = BufferedReader(InputStreamReader(value.getInputStream()))
        writer = BufferedWriter(OutputStreamWriter(value.getOutputStream()))
    }

    private fun readRequiredMessage(timeoutMs: Int): JSONObject {
        val currentSocket = socket ?: error("Socket is not connected")
        currentSocket.soTimeout = timeoutMs
        return try {
            val line = reader?.readLine()
                ?: throw IllegalStateException("Peer closed during handshake")
            DualPhoneControlProtocol.decode(line)
        } finally {
            currentSocket.soTimeout = 0
        }
    }

    private fun send(type: String, payload: JSONObject = JSONObject()) {
        val line = DualPhoneControlProtocol.encode(
            DualPhoneControlProtocol.message(type, payload),
        )
        synchronized(writeLock) {
            val output = writer ?: throw IllegalStateException("Peer is not connected")
            output.write(line)
            output.newLine()
            output.flush()
        }
    }

    private fun sendError(code: String) {
        runCatching {
            send(
                DualPhoneControlType.ERROR,
                JSONObject().put("code", code),
            )
        }
    }

    private fun launchMasterCommand(
        command: String,
        allowedPhases: Set<DualPhoneControlPhase>,
        block: suspend () -> Unit,
    ) {
        scope.launch {
            requireMasterConnection(
                command = command,
                allowedPhases = allowedPhases,
                block = block,
            )
        }
    }

    private suspend fun requireMasterConnection(
        command: String,
        allowedPhases: Set<DualPhoneControlPhase>,
        block: suspend () -> Unit,
    ) {
        if (settingsStore.load().role != DualPhoneRole.MASTER ||
            !mutableState.value.connected
        ) {
            reportCommandError(
                command,
                "Master is not connected to a Slave",
            )
            return
        }
        if (mutableState.value.phase !in allowedPhases) {
            reportCommandError(
                command,
                "$command is not allowed in ${mutableState.value.phase.name}",
            )
            return
        }
        try {
            block()
        } catch (error: Throwable) {
            reportCommandError(
                command,
                "Control command failed: " +
                    (error.message ?: error.javaClass.simpleName),
            )
        }
    }

    private fun reportCommandError(command: String, message: String) {
        val current = mutableState.value
        mutableState.value = current.copy(
            phase = if (current.phase == DualPhoneControlPhase.ARMING) {
                DualPhoneControlPhase.CONNECTED
            } else {
                current.phase
            },
            lastCommand = command,
            lastError = message,
            lastMessage = message,
        )
    }

    private fun currentCaptureId(): String =
        mutableState.value.dualCaptureId
            ?: masterDualCaptureId
            ?: throw IllegalStateException("dual_capture_id is missing")

    private fun markRx() {
        lastRxElapsedMs = SystemClock.elapsedRealtime()
    }

    private fun updateError(message: String) {
        mutableState.value = mutableState.value.copy(
            phase = DualPhoneControlPhase.ERROR,
            connected = false,
            lastMessage = message,
            lastError = message,
        )
    }

    private fun stopTransport(message: String) {
        transportJob?.cancel()
        transportJob = null
        heartbeatJob?.cancel()
        heartbeatJob = null
        scheduledStartJob?.cancel()
        scheduledStartJob = null
        closeConnection()
        closeQuietly(serverSocket)
        serverSocket = null
        masterPairingCode = null
        masterDualCaptureId = null
        localArmResult = null
        slaveTransferOffer?.serverJob?.cancel()
        slaveTransferOffer = null
        localRolePackage = null
        mutableState.value = mutableState.value.copy(
            phase = DualPhoneControlPhase.STOPPED,
            connected = false,
            lastMessage = message,
        )
    }

    private fun closeConnection() {
        clockSyncController.stop()
        heartbeatJob?.cancel()
        heartbeatJob = null
        scheduledStartJob?.cancel()
        scheduledStartJob = null
        closeQuietly(reader)
        closeQuietly(writer)
        closeQuietly(socket)
        reader = null
        writer = null
        socket = null
    }

    override fun close() {
        stopTransport("Control runtime closed")
        scope.cancel()
    }

    companion object {
        private const val CONNECT_TIMEOUT_MS = 5_000
        private const val HANDSHAKE_TIMEOUT_MS = 10_000
        private const val HEARTBEAT_INTERVAL_MS = 2_000L
        private const val HEARTBEAT_TIMEOUT_MS = 8_000L
        private const val MAX_START_LATE_NS = 100_000_000L
        private const val ARM_PREPARE_TIMEOUT_MS = 15_000L

        @Volatile
        private var instance: DualPhoneControlManager? = null

        fun get(context: Context): DualPhoneControlManager =
            instance ?: synchronized(this) {
                instance ?: DualPhoneControlManager(context).also {
                    instance = it
                }
            }

        private fun localIpv4Address(): String? =
            runCatching {
                Collections.list(NetworkInterface.getNetworkInterfaces())
                    .asSequence()
                    .filter { it.isUp && !it.isLoopback }
                    .flatMap { Collections.list(it.inetAddresses).asSequence() }
                    .filterIsInstance<Inet4Address>()
                    .firstOrNull { !it.isLoopbackAddress && !it.isLinkLocalAddress }
                    ?.hostAddress
            }.getOrNull()

        private fun closeQuietly(value: Closeable?) {
            runCatching { value?.close() }
        }
    }
}
