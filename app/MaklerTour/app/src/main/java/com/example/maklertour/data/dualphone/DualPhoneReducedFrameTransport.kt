package com.example.maklertour.data.dualphone

import android.os.SystemClock
import com.maklertour.data.dualphone.DualPhoneRole
import java.io.BufferedInputStream
import java.io.BufferedOutputStream
import java.io.Closeable
import java.io.DataInputStream
import java.io.DataOutputStream
import java.net.InetSocketAddress
import java.net.ServerSocket
import java.net.Socket
import java.nio.charset.StandardCharsets
import java.util.concurrent.Executors
import java.util.concurrent.Semaphore
import java.util.concurrent.ThreadFactory
import java.util.concurrent.atomic.AtomicBoolean
import java.util.concurrent.atomic.AtomicInteger
import java.util.concurrent.atomic.AtomicLong
import java.util.concurrent.atomic.AtomicReference
import java.util.zip.CRC32
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import org.json.JSONObject

enum class DualPhoneReducedFrameTransportState {
    STOPPED,
    LISTENING,
    CONNECTING,
    HANDSHAKING,
    READY,
    RECONNECTING,
    FAILED,
}

data class DualPhoneReducedFrame(
    val schemaVersion: Int = SCHEMA_VERSION,
    val streamId: String,
    val dualCaptureId: String,
    val sessionUuid: String,
    val role: DualPhoneRole,
    val frameSequence: Long,
    val sensorTimestampNs: Long,
    val captureElapsedRealtimeNs: Long,
    val timestampSource: String,
    val clockModelRevision: Long,
    val width: Int,
    val height: Int,
    val rotationAppliedDegrees: Int,
    val imageProxyRotationDegrees: Int,
    val encoding: String = ENCODING_JPEG,
    val senderFramesOffered: Long = 0L,
    val senderFramesReplacedBeforeSend: Long = 0L,
    val senderFramesDroppedOversize: Long = 0L,
    val jpegBytes: ByteArray,
    val payloadCrc32: Long = crc32(jpegBytes),
) {
    fun validate() {
        require(schemaVersion == SCHEMA_VERSION) { "Unsupported frame schema" }
        require(streamId.isNotBlank()) { "stream_id is missing" }
        require(dualCaptureId.isNotBlank()) { "dual_capture_id is missing" }
        require(sessionUuid.isNotBlank()) { "session_uuid is missing" }
        require(role == DualPhoneRole.MASTER || role == DualPhoneRole.SLAVE) {
            "Reduced frame role is invalid"
        }
        require(frameSequence >= 0L) { "frame_sequence is invalid" }
        require(sensorTimestampNs > 0L) { "sensor_timestamp_ns is invalid" }
        require(captureElapsedRealtimeNs > 0L) {
            "capture_elapsed_realtime_ns is invalid"
        }
        require(width in 1..MAX_WIDTH && height in 1..MAX_HEIGHT) {
            "Reduced frame exceeds ${MAX_WIDTH}x${MAX_HEIGHT}"
        }
        require(rotationAppliedDegrees == 0) {
            "Transported pixels must retain raw orientation"
        }
        require(encoding == ENCODING_JPEG) { "Unsupported frame encoding" }
        require(senderFramesOffered >= 0L) { "sender_frames_offered is invalid" }
        require(senderFramesReplacedBeforeSend >= 0L) {
            "sender_frames_replaced_before_send is invalid"
        }
        require(senderFramesDroppedOversize >= 0L) {
            "sender_frames_dropped_oversize is invalid"
        }
        require(jpegBytes.isNotEmpty()) { "JPEG payload is empty" }
        require(jpegBytes.size <= MAX_PAYLOAD_BYTES) { "JPEG payload is oversized" }
        require(crc32(jpegBytes) == payloadCrc32) { "JPEG CRC32 mismatch" }
    }

    internal fun headerJson(): JSONObject = JSONObject()
        .put("schema_version", schemaVersion)
        .put("stream_id", streamId)
        .put("dual_capture_id", dualCaptureId)
        .put("session_uuid", sessionUuid)
        .put("role", role.name)
        .put("frame_sequence", frameSequence)
        .put("sensor_timestamp_ns", sensorTimestampNs)
        .put("capture_elapsed_realtime_ns", captureElapsedRealtimeNs)
        .put("timestamp_source", timestampSource)
        .put("clock_model_revision", clockModelRevision)
        .put("width", width)
        .put("height", height)
        .put("rotation_applied_degrees", rotationAppliedDegrees)
        .put("image_proxy_rotation_degrees", imageProxyRotationDegrees)
        .put("encoding", encoding)
        .put("sender_frames_offered", senderFramesOffered)
        .put(
            "sender_frames_replaced_before_send",
            senderFramesReplacedBeforeSend,
        )
        .put("sender_frames_dropped_oversize", senderFramesDroppedOversize)
        .put("payload_size", jpegBytes.size)
        .put("payload_crc32", payloadCrc32)

    companion object {
        const val SCHEMA_VERSION = 1
        const val MAX_WIDTH = 960
        const val MAX_HEIGHT = 540
        const val MAX_PAYLOAD_BYTES = 512 * 1024
        const val ENCODING_JPEG = "JPEG"

        internal fun fromWire(header: JSONObject, jpegBytes: ByteArray):
            DualPhoneReducedFrame = DualPhoneReducedFrame(
                schemaVersion = header.getInt("schema_version"),
                streamId = header.getString("stream_id"),
                dualCaptureId = header.getString("dual_capture_id"),
                sessionUuid = header.getString("session_uuid"),
                role = DualPhoneRole.valueOf(header.getString("role")),
                frameSequence = header.getLong("frame_sequence"),
                sensorTimestampNs = header.getLong("sensor_timestamp_ns"),
                captureElapsedRealtimeNs =
                    header.getLong("capture_elapsed_realtime_ns"),
                timestampSource = header.getString("timestamp_source"),
                clockModelRevision = header.optLong("clock_model_revision", 0L),
                width = header.getInt("width"),
                height = header.getInt("height"),
                rotationAppliedDegrees =
                    header.getInt("rotation_applied_degrees"),
                imageProxyRotationDegrees =
                    header.optInt("image_proxy_rotation_degrees", 0),
                encoding = header.getString("encoding"),
                senderFramesOffered =
                    header.optLong("sender_frames_offered", 0L),
                senderFramesReplacedBeforeSend = header.optLong(
                    "sender_frames_replaced_before_send",
                    0L,
                ),
                senderFramesDroppedOversize =
                    header.optLong("sender_frames_dropped_oversize", 0L),
                jpegBytes = jpegBytes,
                payloadCrc32 = header.getLong("payload_crc32"),
            ).also { frame ->
                require(header.getInt("payload_size") == jpegBytes.size) {
                    "JPEG payload length mismatch"
                }
                frame.validate()
            }

        internal fun crc32(bytes: ByteArray): Long =
            CRC32().apply { update(bytes) }.value
    }
}

data class DualPhoneReducedFrameTransportConfig(
    val owner: DualPhoneLiveStreamOwner,
    val localDeviceId: String,
    val role: DualPhoneRole,
    val peerHost: String? = null,
    val port: Int = DEFAULT_PORT,
) {
    init {
        require(role == DualPhoneRole.MASTER || role == DualPhoneRole.SLAVE)
        require(localDeviceId.isNotBlank())
        require(owner.localRole == role.name) {
            "Owner role ${owner.localRole} does not match $role"
        }
        require(port in 1..65535)
        if (role == DualPhoneRole.MASTER) {
            require(!peerHost.isNullOrBlank()) { "MASTER media peer host is missing" }
        }
    }

    companion object {
        const val DEFAULT_PORT = 45_832
    }
}

data class DualPhoneReducedFrameTransportSnapshot(
    val state: DualPhoneReducedFrameTransportState =
        DualPhoneReducedFrameTransportState.STOPPED,
    val localRole: DualPhoneRole = DualPhoneRole.STANDALONE,
    val port: Int = DualPhoneReducedFrameTransportConfig.DEFAULT_PORT,
    val peerHost: String? = null,
    val remoteAddress: String? = null,
    val connectionAttempts: Long = 0L,
    val connectionRestarts: Long = 0L,
    val framesOffered: Long = 0L,
    val framesSent: Long = 0L,
    val framesReceived: Long = 0L,
    val framesReplacedBeforeSend: Long = 0L,
    val framesDroppedOversize: Long = 0L,
    val bytesSent: Long = 0L,
    val bytesReceived: Long = 0L,
    val firstFrameSentElapsedMs: Long? = null,
    val firstFrameReceivedElapsedMs: Long? = null,
    val lastFrameSentElapsedMs: Long? = null,
    val lastFrameReceivedElapsedMs: Long? = null,
    val latestFrame: DualPhoneReducedFrame? = null,
    val lastError: String? = null,
) {
    val sendBitrateKbps: Double
        get() = bitrateKbps(
            bytes = bytesSent,
            firstElapsedMs = firstFrameSentElapsedMs,
            lastElapsedMs = lastFrameSentElapsedMs,
        )

    val receiveBitrateKbps: Double
        get() = bitrateKbps(
            bytes = bytesReceived,
            firstElapsedMs = firstFrameReceivedElapsedMs,
            lastElapsedMs = lastFrameReceivedElapsedMs,
        )

    private fun bitrateKbps(
        bytes: Long,
        firstElapsedMs: Long?,
        lastElapsedMs: Long?,
    ): Double {
        val first = firstElapsedMs ?: return 0.0
        val last = lastElapsedMs ?: return 0.0
        val seconds = (last - first).coerceAtLeast(1L) / 1_000.0
        return bytes * 8.0 / 1_000.0 / seconds
    }
}

/**
 * LM01B media subchannel.
 *
 * TCP/45831 remains the session/heartbeat data channel. Reduced JPEG payloads use
 * TCP/45832 so a slow decoder cannot block commands, clock sync or heartbeat.
 * The SLAVE owns a one-element latest-frame queue; stale pending frames are
 * replaced explicitly and counted.
 */
class DualPhoneReducedFrameTransport : Closeable {
    private val running = AtomicBoolean(false)
    private val generation = AtomicLong(0L)
    private val worker = Executors.newSingleThreadExecutor(
        ReducedFrameThreadFactory("lm01b-media"),
    )
    private val pendingFrame = AtomicReference<DualPhoneReducedFrame?>(null)
    private val pendingSignal = Semaphore(0)
    private val writeLock = Any()

    @Volatile
    private var config: DualPhoneReducedFrameTransportConfig? = null
    @Volatile
    private var serverSocket: ServerSocket? = null
    @Volatile
    private var activeSocket: Socket? = null

    private val mutableState = MutableStateFlow(
        DualPhoneReducedFrameTransportSnapshot(),
    )
    val state: StateFlow<DualPhoneReducedFrameTransportSnapshot> =
        mutableState.asStateFlow()
    val snapshot: DualPhoneReducedFrameTransportSnapshot
        get() = mutableState.value

    @Synchronized
    fun start(value: DualPhoneReducedFrameTransportConfig) {
        stopInternal(publishStopped = false)
        config = value
        running.set(true)
        val token = generation.incrementAndGet()
        pendingFrame.set(null)
        pendingSignal.drainPermits()
        mutableState.value = DualPhoneReducedFrameTransportSnapshot(
            state = if (value.role == DualPhoneRole.SLAVE) {
                DualPhoneReducedFrameTransportState.LISTENING
            } else {
                DualPhoneReducedFrameTransportState.CONNECTING
            },
            localRole = value.role,
            port = value.port,
            peerHost = value.peerHost,
        )
        worker.execute {
            if (value.role == DualPhoneRole.SLAVE) {
                runSlaveServer(value, token)
            } else {
                runMasterClient(value, token)
            }
        }
    }

    fun offerFrame(frame: DualPhoneReducedFrame): Boolean {
        val currentConfig = config ?: return false
        if (!running.get() || currentConfig.role != DualPhoneRole.SLAVE) return false
        val invalid = runCatching {
            frame.validate()
            require(frame.role == DualPhoneRole.SLAVE) {
                "Only SLAVE frames may enter the media socket"
            }
            require(frame.streamId == currentConfig.owner.streamId)
            require(frame.dualCaptureId == currentConfig.owner.dualCaptureId)
            require(frame.sessionUuid == currentConfig.owner.sessionUuid)
        }.exceptionOrNull()
        if (invalid != null) {
            update { current ->
                current.copy(lastError = invalid.message ?: "Invalid reduced frame")
            }
            return false
        }
        if (frame.jpegBytes.size > DualPhoneReducedFrame.MAX_PAYLOAD_BYTES) {
            update { current ->
                current.copy(
                    framesDroppedOversize = current.framesDroppedOversize + 1L,
                )
            }
            return false
        }
        val previous = pendingFrame.getAndSet(frame)
        update { current ->
            current.copy(
                framesOffered = current.framesOffered + 1L,
                framesReplacedBeforeSend = current.framesReplacedBeforeSend +
                    if (previous != null) 1L else 0L,
            )
        }
        if (previous == null) pendingSignal.release()
        return true
    }

    @Synchronized
    fun stop() {
        stopInternal(publishStopped = true)
    }

    private fun runSlaveServer(
        value: DualPhoneReducedFrameTransportConfig,
        token: Long,
    ) {
        try {
            ServerSocket().use { server ->
                server.reuseAddress = true
                server.bind(InetSocketAddress(value.port))
                serverSocket = server
                while (isCurrent(token)) {
                    update { current ->
                        current.copy(
                            state = DualPhoneReducedFrameTransportState.LISTENING,
                            remoteAddress = null,
                            lastError = null,
                        )
                    }
                    val socket = try {
                        server.accept()
                    } catch (error: Throwable) {
                        if (!isCurrent(token)) break
                        throw error
                    }
                    activeSocket = socket
                    try {
                        configureSocket(socket)
                        val output = DataOutputStream(
                            BufferedOutputStream(socket.getOutputStream()),
                        )
                        val input = DataInputStream(
                            BufferedInputStream(socket.getInputStream()),
                        )
                        update { current ->
                            current.copy(
                                state = DualPhoneReducedFrameTransportState.HANDSHAKING,
                                connectionAttempts = current.connectionAttempts + 1L,
                            )
                        }
                        val localHello = helloFrom(value)
                        val remoteHello = readHello(input)
                        localHello.validatePeer(remoteHello)
                        writeHello(output, localHello)
                        socket.soTimeout = 0
                        update { current ->
                            current.copy(
                                state = DualPhoneReducedFrameTransportState.READY,
                                remoteAddress = socket.remoteSocketAddress?.toString(),
                                lastError = null,
                            )
                        }
                        writeFrames(output, token)
                    } catch (error: Throwable) {
                        if (isCurrent(token)) {
                            update { current ->
                                current.copy(
                                    state = DualPhoneReducedFrameTransportState.RECONNECTING,
                                    connectionRestarts =
                                        current.connectionRestarts + 1L,
                                    lastError =
                                        error.message ?: error.javaClass.simpleName,
                                )
                            }
                        }
                    } finally {
                        closeSocket(socket)
                        activeSocket = null
                    }
                }
            }
        } catch (error: Throwable) {
            if (isCurrent(token)) fail(error)
        } finally {
            serverSocket = null
        }
    }

    private fun runMasterClient(
        value: DualPhoneReducedFrameTransportConfig,
        token: Long,
    ) {
        var retryMs = INITIAL_RETRY_MS
        while (isCurrent(token)) {
            val socket = Socket()
            activeSocket = socket
            try {
                update { current ->
                    current.copy(
                        state = if (current.connectionAttempts == 0L) {
                            DualPhoneReducedFrameTransportState.CONNECTING
                        } else {
                            DualPhoneReducedFrameTransportState.RECONNECTING
                        },
                        connectionAttempts = current.connectionAttempts + 1L,
                    )
                }
                configureSocket(socket)
                socket.connect(
                    InetSocketAddress(requireNotNull(value.peerHost), value.port),
                    CONNECT_TIMEOUT_MS,
                )
                val output = DataOutputStream(
                    BufferedOutputStream(socket.getOutputStream()),
                )
                val input = DataInputStream(
                    BufferedInputStream(socket.getInputStream()),
                )
                update { current ->
                    current.copy(
                        state = DualPhoneReducedFrameTransportState.HANDSHAKING,
                    )
                }
                val localHello = helloFrom(value)
                writeHello(output, localHello)
                val remoteHello = readHello(input)
                localHello.validatePeer(remoteHello)
                socket.soTimeout = 0
                update { current ->
                    current.copy(
                        state = DualPhoneReducedFrameTransportState.READY,
                        remoteAddress = socket.remoteSocketAddress?.toString(),
                        lastError = null,
                    )
                }
                retryMs = INITIAL_RETRY_MS
                readFrames(input, value, remoteHello, token)
            } catch (error: Throwable) {
                if (isCurrent(token)) {
                    update { current ->
                        current.copy(
                            state = DualPhoneReducedFrameTransportState.RECONNECTING,
                            connectionRestarts = current.connectionRestarts + 1L,
                            lastError = error.message ?: error.javaClass.simpleName,
                        )
                    }
                    sleepWhileRunning(retryMs, token)
                    retryMs = (retryMs * 2L).coerceAtMost(MAX_RETRY_MS)
                }
            } finally {
                closeSocket(socket)
                activeSocket = null
            }
        }
    }

    private fun writeFrames(output: DataOutputStream, token: Long) {
        while (isCurrent(token) && activeSocket?.isClosed == false) {
            pendingSignal.tryAcquire(FRAME_WAIT_MS, java.util.concurrent.TimeUnit.MILLISECONDS)
            val frame = pendingFrame.getAndSet(null) ?: continue
            val senderSnapshot = mutableState.value
            val wireFrame = frame.copy(
                senderFramesOffered = senderSnapshot.framesOffered,
                senderFramesReplacedBeforeSend =
                    senderSnapshot.framesReplacedBeforeSend,
                senderFramesDroppedOversize =
                    senderSnapshot.framesDroppedOversize,
            )
            synchronized(writeLock) {
                writeFrame(output, wireFrame)
            }
            val nowMs = SystemClock.elapsedRealtime()
            update { current ->
                current.copy(
                    framesSent = current.framesSent + 1L,
                    bytesSent = current.bytesSent + wireFrame.jpegBytes.size,
                    firstFrameSentElapsedMs =
                        current.firstFrameSentElapsedMs ?: nowMs,
                    lastFrameSentElapsedMs = nowMs,
                    lastError = null,
                )
            }
        }
    }

    private fun readFrames(
        input: DataInputStream,
        value: DualPhoneReducedFrameTransportConfig,
        remoteHello: DualPhoneLiveStreamDataChannelHello,
        token: Long,
    ) {
        while (isCurrent(token) && activeSocket?.isClosed == false) {
            val frame = readFrame(input)
            require(frame.role == DualPhoneRole.SLAVE) { "Peer frame role is not SLAVE" }
            require(frame.streamId == remoteHello.streamId) { "Peer stream_id changed" }
            require(frame.dualCaptureId == value.owner.dualCaptureId) {
                "Peer dual_capture_id changed"
            }
            require(frame.sessionUuid == value.owner.sessionUuid) {
                "Peer session_uuid changed"
            }
            val nowMs = SystemClock.elapsedRealtime()
            update { current ->
                current.copy(
                    framesReceived = current.framesReceived + 1L,
                    bytesReceived = current.bytesReceived + frame.jpegBytes.size,
                    firstFrameReceivedElapsedMs =
                        current.firstFrameReceivedElapsedMs ?: nowMs,
                    lastFrameReceivedElapsedMs = nowMs,
                    latestFrame = frame,
                    lastError = null,
                )
            }
        }
    }

    private fun writeFrame(output: DataOutputStream, frame: DualPhoneReducedFrame) {
        frame.validate()
        val header = frame.headerJson().toString().toByteArray(StandardCharsets.UTF_8)
        require(header.size <= MAX_HEADER_BYTES) { "Frame header is oversized" }
        output.writeInt(FRAME_MAGIC)
        output.writeInt(header.size)
        output.writeInt(frame.jpegBytes.size)
        output.write(header)
        output.write(frame.jpegBytes)
        output.flush()
    }

    private fun readFrame(input: DataInputStream): DualPhoneReducedFrame {
        require(input.readInt() == FRAME_MAGIC) { "Invalid frame magic" }
        val headerSize = input.readInt()
        val payloadSize = input.readInt()
        require(headerSize in 2..MAX_HEADER_BYTES) { "Invalid frame header size" }
        require(payloadSize in 1..DualPhoneReducedFrame.MAX_PAYLOAD_BYTES) {
            "Invalid JPEG payload size"
        }
        val headerBytes = ByteArray(headerSize)
        input.readFully(headerBytes)
        val jpegBytes = ByteArray(payloadSize)
        input.readFully(jpegBytes)
        return DualPhoneReducedFrame.fromWire(
            JSONObject(String(headerBytes, StandardCharsets.UTF_8)),
            jpegBytes,
        )
    }

    private fun writeHello(
        output: DataOutputStream,
        hello: DualPhoneLiveStreamDataChannelHello,
    ) {
        val bytes = helloToJson(hello)
            .toString()
            .toByteArray(StandardCharsets.UTF_8)
        require(bytes.size <= MAX_HELLO_BYTES)
        output.writeInt(bytes.size)
        output.write(bytes)
        output.flush()
    }

    private fun readHello(input: DataInputStream): DualPhoneLiveStreamDataChannelHello {
        val size = input.readInt()
        require(size in 2..MAX_HELLO_BYTES) { "Invalid media hello size" }
        val bytes = ByteArray(size)
        input.readFully(bytes)
        return helloFromJson(
            JSONObject(String(bytes, StandardCharsets.UTF_8)),
        )
    }

    private fun helloToJson(
        hello: DualPhoneLiveStreamDataChannelHello,
    ): JSONObject = JSONObject()
        .put("session_uuid", hello.sessionUuid)
        .put("dual_capture_id", hello.dualCaptureId)
        .put("stream_id", hello.streamId)
        .put("local_device_id", hello.localDeviceId)
        .put("expected_peer_device_id", hello.expectedPeerDeviceId)
        .put("role", hello.role.name)
        .put("calibration_identity", hello.calibrationIdentity)
        .put("rig_mount_revision", hello.rigMountRevision)
        .put("capture_mode", hello.captureMode.name)
        .put("recording_mode_identity", hello.recordingModeIdentity)

    private fun helloFromJson(
        json: JSONObject,
    ): DualPhoneLiveStreamDataChannelHello =
        DualPhoneLiveStreamDataChannelHello(
            sessionUuid = json.getString("session_uuid"),
            dualCaptureId = json.getString("dual_capture_id"),
            streamId = json.getString("stream_id"),
            localDeviceId = json.getString("local_device_id"),
            expectedPeerDeviceId = json.getString("expected_peer_device_id"),
            role = DualPhoneRole.valueOf(json.getString("role")),
            calibrationIdentity = json.getString("calibration_identity"),
            rigMountRevision = json.getString("rig_mount_revision"),
            captureMode = DualPhoneLiveStreamMode.valueOf(
                json.getString("capture_mode"),
            ),
            recordingModeIdentity = json.getString("recording_mode_identity"),
        )

    private fun configureSocket(socket: Socket) {
        socket.tcpNoDelay = true
        socket.keepAlive = true
        socket.soTimeout = SOCKET_READ_TIMEOUT_MS
    }

    private fun sleepWhileRunning(delayMs: Long, token: Long) {
        val deadline = SystemClock.elapsedRealtime() + delayMs
        while (isCurrent(token) && SystemClock.elapsedRealtime() < deadline) {
            try {
                Thread.sleep(100L)
            } catch (_: InterruptedException) {
                Thread.currentThread().interrupt()
                return
            }
        }
    }

    private fun fail(error: Throwable) {
        update { current ->
            current.copy(
                state = DualPhoneReducedFrameTransportState.FAILED,
                lastError = error.message ?: error.javaClass.simpleName,
            )
        }
    }

    private fun update(
        transform: (DualPhoneReducedFrameTransportSnapshot) ->
            DualPhoneReducedFrameTransportSnapshot,
    ) {
        synchronized(mutableState) {
            mutableState.value = transform(mutableState.value)
        }
    }

    private fun isCurrent(token: Long): Boolean =
        running.get() && generation.get() == token

    private fun stopInternal(publishStopped: Boolean) {
        running.set(false)
        generation.incrementAndGet()
        pendingFrame.set(null)
        pendingSignal.release()
        closeSocket(activeSocket)
        activeSocket = null
        runCatching { serverSocket?.close() }
        serverSocket = null
        config = null
        if (publishStopped) {
            mutableState.value = DualPhoneReducedFrameTransportSnapshot()
        }
    }

    private fun closeSocket(socket: Socket?) {
        runCatching { socket?.close() }
    }

    override fun close() {
        stop()
        worker.shutdownNow()
    }

    companion object {
        private const val FRAME_MAGIC = 0x4C4D4631
        private const val MAX_HELLO_BYTES = 16 * 1024
        private const val MAX_HEADER_BYTES = 16 * 1024
        private const val CONNECT_TIMEOUT_MS = 5_000
        private const val SOCKET_READ_TIMEOUT_MS = 15_000
        private const val FRAME_WAIT_MS = 1_000L
        private const val INITIAL_RETRY_MS = 500L
        private const val MAX_RETRY_MS = 5_000L
    }
}

private fun helloFrom(
    config: DualPhoneReducedFrameTransportConfig,
): DualPhoneLiveStreamDataChannelHello = DualPhoneLiveStreamDataChannelHello(
    streamId = config.owner.streamId,
    sessionUuid = config.owner.sessionUuid,
    dualCaptureId = config.owner.dualCaptureId,
    localDeviceId = config.localDeviceId,
    expectedPeerDeviceId = config.owner.peerIdentity,
    role = config.role,
    calibrationIdentity = config.owner.calibrationIdentity,
    rigMountRevision = config.owner.rigMountRevision,
    captureMode = config.owner.captureMode,
    recordingModeIdentity = config.owner.recordingModeIdentity,
)

private class ReducedFrameThreadFactory(
    private val name: String,
) : ThreadFactory {
    private val serial = AtomicInteger(1)

    override fun newThread(runnable: Runnable): Thread = Thread(
        runnable,
        "$name-${serial.getAndIncrement()}",
    ).apply { isDaemon = true }
}
