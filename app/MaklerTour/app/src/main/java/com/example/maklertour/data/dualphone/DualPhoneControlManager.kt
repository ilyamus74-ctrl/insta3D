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
            if (!sync.ready) {
                throw IllegalStateException(
                    "Clock sync is not ready (${sync.quality.name})",
                )
            }
            send(
                DualPhoneControlType.ARM,
                JSONObject()
                    .put("dual_capture_id", currentCaptureId())
                    .put("requested_at_master_ns", SystemClock.elapsedRealtimeNanos()),
            )
            mutableState.value = mutableState.value.copy(
                lastCommand = DualPhoneControlType.ARM,
                lastError = null,
                lastMessage = "ARM sent; waiting for Slave acknowledgement",
            )
        }
    }

    fun startAfter(delayMs: Long = 3_000L) {
        launchMasterCommand(
            command = DualPhoneControlType.START_AT,
            allowedPhases = setOf(DualPhoneControlPhase.ARMED),
        ) {
            val safeDelay = delayMs.coerceIn(1_000L, 30_000L)
            val startAt = SystemClock.elapsedRealtimeNanos() + safeDelay * 1_000_000L
            val slaveStartAt = clockSyncController.masterToSlaveNs(startAt)
                ?: throw IllegalStateException("Clock sync model is unavailable or stale")
            val sync = clockSyncController.currentSnapshot()
            send(
                DualPhoneControlType.START_AT,
                JSONObject()
                    .put("dual_capture_id", currentCaptureId())
                    .put("master_elapsed_realtime_ns", startAt)
                    .put("slave_elapsed_realtime_ns", slaveStartAt)
                    .put("clock_offset_ns", sync.offsetNs ?: JSONObject.NULL)
                    .put("clock_uncertainty_ns", sync.uncertaintyNs ?: JSONObject.NULL)
                    .put("clock_drift_ppm", sync.driftPpm ?: JSONObject.NULL)
                    .put("delay_ms", safeDelay),
            )
            mutableState.value = mutableState.value.copy(
                phase = DualPhoneControlPhase.START_SCHEDULED,
                lastCommand = DualPhoneControlType.START_AT,
                lastError = null,
                lastMessage = "START_AT sent using ${sync.quality.name} clock model",
            )
            scheduledStartJob?.cancel()
            scheduledStartJob = scope.launch {
                val remainingNs = startAt - SystemClock.elapsedRealtimeNanos()
                val remainingMs = (
                    (remainingNs.coerceAtLeast(0L) + 999_999L) / 1_000_000L
                )
                delay(remainingMs)
                if (mutableState.value.connected &&
                    mutableState.value.phase == DualPhoneControlPhase.START_SCHEDULED
                ) {
                    mutableState.value = mutableState.value.copy(
                        phase = DualPhoneControlPhase.RECORDING,
                        lastMessage = "Control test entered RECORDING state",
                    )
                }
            }
        }
    }

    fun stopCapture() {
        launchMasterCommand(
            command = DualPhoneControlType.STOP,
            allowedPhases = setOf(
                DualPhoneControlPhase.CONNECTED,
                DualPhoneControlPhase.ARMED,
                DualPhoneControlPhase.START_SCHEDULED,
                DualPhoneControlPhase.RECORDING,
            ),
        ) {
            send(
                DualPhoneControlType.STOP,
                JSONObject()
                    .put("dual_capture_id", currentCaptureId())
                    .put("requested_at_master_ns", SystemClock.elapsedRealtimeNanos()),
            )
            scheduledStartJob?.cancel()
            mutableState.value = mutableState.value.copy(
                lastCommand = DualPhoneControlType.STOP,
                lastError = null,
                lastMessage = "STOP sent; waiting for Slave acknowledgement",
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
                    mutableState.value = mutableState.value.copy(
                        phase = DualPhoneControlPhase.ARMED,
                        lastMessage = "ARM received; recorder integration is DP04",
                    )
                    send(
                        DualPhoneControlType.ARM_ACK,
                        JSONObject().put("ready", true),
                    )
                }
                DualPhoneControlType.ARM_ACK -> if (localRole == DualPhoneRole.MASTER) {
                    mutableState.value = mutableState.value.copy(
                        phase = DualPhoneControlPhase.ARMED,
                        lastCommand = DualPhoneControlType.ARM,
                        lastError = null,
                        lastMessage = "Slave acknowledged ARM",
                    )
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
                            "Slave scheduled START_AT on its corrected clock"
                        } else {
                            "Slave rejected START_AT: $rejectionReason"
                        },
                    )
                }
                DualPhoneControlType.STOP -> if (localRole == DualPhoneRole.SLAVE) {
                    scheduledStartJob?.cancel()
                    mutableState.value = mutableState.value.copy(
                        phase = DualPhoneControlPhase.CONNECTED,
                        lastMessage = "STOP received",
                    )
                    send(DualPhoneControlType.STOP_ACK)
                }
                DualPhoneControlType.STOP_ACK -> if (localRole == DualPhoneRole.MASTER) {
                    scheduledStartJob?.cancel()
                    mutableState.value = mutableState.value.copy(
                        phase = DualPhoneControlPhase.CONNECTED,
                        lastCommand = DualPhoneControlType.STOP,
                        lastError = null,
                        lastMessage = "Slave acknowledged STOP",
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

    private fun handleSlaveStart(payload: JSONObject) {
        val receivedAtNs = SystemClock.elapsedRealtimeNanos()
        val targetSlaveNs = payload.optLong("slave_elapsed_realtime_ns", 0L)
        val fallbackDelayMs = payload.optLong("delay_ms", 3_000L)
            .coerceIn(0L, 30_000L)
        val remainingNs = if (targetSlaveNs > 0L) {
            targetSlaveNs - receivedAtNs
        } else {
            fallbackDelayMs * 1_000_000L
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
            (remainingNs.coerceAtLeast(0L) + 999_999L) / 1_000_000L
        ).coerceIn(0L, 30_000L)
        scheduledStartJob?.cancel()
        mutableState.value = mutableState.value.copy(
            phase = DualPhoneControlPhase.START_SCHEDULED,
            lastError = null,
            lastMessage = "START_AT received; corrected local delay ${scheduleDelayMs} ms",
        )
        send(
            DualPhoneControlType.START_ACK,
            JSONObject()
                .put("accepted", true)
                .put("local_received_ns", receivedAtNs)
                .put("scheduled_slave_ns", targetSlaveNs)
                .put("scheduled_delay_ms", scheduleDelayMs),
        )
        scheduledStartJob = scope.launch {
            delay(scheduleDelayMs)
            if (mutableState.value.connected &&
                mutableState.value.phase == DualPhoneControlPhase.START_SCHEDULED
            ) {
                mutableState.value = mutableState.value.copy(
                    phase = DualPhoneControlPhase.RECORDING,
                    lastMessage = "Control test entered RECORDING state on corrected clock",
                )
            }
        }
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
        block: () -> Unit,
    ) {
        scope.launch {
            requireMasterConnection(
                command = command,
                allowedPhases = allowedPhases,
                block = block,
            )
        }
    }

    private fun requireMasterConnection(
        command: String,
        allowedPhases: Set<DualPhoneControlPhase>,
        block: () -> Unit,
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
        runCatching(block).onFailure {
            reportCommandError(
                command,
                "Control command failed: " +
                    (it.message ?: it.javaClass.simpleName),
            )
        }
    }

    private fun reportCommandError(command: String, message: String) {
        mutableState.value = mutableState.value.copy(
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
