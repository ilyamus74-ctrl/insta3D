package com.example.maklertour.data.dualphone

import com.maklertour.data.dualphone.DualPhoneRole
import java.io.ByteArrayInputStream
import java.io.ByteArrayOutputStream
import java.io.Closeable
import java.io.DataInputStream
import java.io.DataOutputStream
import java.io.EOFException
import java.io.IOException
import java.net.InetSocketAddress
import java.net.ServerSocket
import java.net.Socket
import java.net.SocketException
import java.net.SocketTimeoutException
import java.nio.charset.StandardCharsets
import java.util.concurrent.ExecutorService
import java.util.concurrent.Executors
import java.util.concurrent.ThreadFactory
import java.util.concurrent.atomic.AtomicBoolean
import java.util.concurrent.atomic.AtomicLong
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow

enum class DualPhoneLiveStreamDataChannelState {
    STOPPED,
    LISTENING,
    CONNECTING,
    HANDSHAKING,
    READY,
    RECONNECTING,
    FAILED,
}

data class DualPhoneLiveStreamDataChannelSnapshot(
    val state: DualPhoneLiveStreamDataChannelState =
        DualPhoneLiveStreamDataChannelState.STOPPED,
    val localRole: DualPhoneRole = DualPhoneRole.STANDALONE,
    val port: Int = DualPhoneLiveStreamDataChannelController.DEFAULT_PORT,
    val peerHost: String? = null,
    val remoteAddress: String? = null,
    val remoteDeviceId: String? = null,
    val connectionAttempts: Long = 0L,
    val reconnects: Long = 0L,
    val packetsSent: Long = 0L,
    val packetsReceived: Long = 0L,
    val bytesSent: Long = 0L,
    val bytesReceived: Long = 0L,
    val lastRoundTripMs: Double? = null,
    val lastHeartbeatEpochMs: Long? = null,
    val lastError: String? = null,
) {
    val ready: Boolean
        get() = state == DualPhoneLiveStreamDataChannelState.READY
}

data class DualPhoneLiveStreamDataChannelConfig(
    val owner: DualPhoneLiveStreamOwner,
    val localDeviceId: String,
    val role: DualPhoneRole,
    val peerHost: String? = null,
    val port: Int = DualPhoneLiveStreamDataChannelController.DEFAULT_PORT,
) {
    init {
        require(localDeviceId.isNotBlank()) { "localDeviceId is required" }
        require(role != DualPhoneRole.STANDALONE) {
            "LM01A data channel requires MASTER or SLAVE role"
        }
        require(owner.localRole == role.name) {
            "Owner role ${owner.localRole} does not match $role"
        }
        require(port in 1..65535) { "Invalid data-channel port: $port" }
        if (role == DualPhoneRole.MASTER) {
            require(!peerHost.isNullOrBlank()) {
                "MASTER requires the SLAVE peer host"
            }
        }
    }

    internal val key: String
        get() = listOf(
            owner.sessionUuid,
            owner.dualCaptureId,
            owner.calibrationIdentity,
            owner.rigMountRevision,
            owner.captureMode.name,
            localDeviceId,
            owner.peerIdentity,
            role.name,
            peerHost.orEmpty(),
            port.toString(),
        ).joinToString("|")

    internal fun hello(): DualPhoneLiveStreamDataChannelHello =
        DualPhoneLiveStreamDataChannelHello(
            sessionUuid = owner.sessionUuid,
            dualCaptureId = owner.dualCaptureId,
            streamId = owner.streamId,
            localDeviceId = localDeviceId,
            expectedPeerDeviceId = owner.peerIdentity,
            role = role,
            calibrationIdentity = owner.calibrationIdentity,
            rigMountRevision = owner.rigMountRevision,
            captureMode = owner.captureMode,
            recordingModeIdentity = owner.recordingModeIdentity,
        )
}

data class DualPhoneLiveStreamDataChannelHello(
    val sessionUuid: String,
    val dualCaptureId: String,
    val streamId: String,
    val localDeviceId: String,
    val expectedPeerDeviceId: String,
    val role: DualPhoneRole,
    val calibrationIdentity: String,
    val rigMountRevision: String,
    val captureMode: DualPhoneLiveStreamMode,
    val recordingModeIdentity: String,
) {
    init {
        require(sessionUuid.isNotBlank())
        require(dualCaptureId.isNotBlank())
        require(streamId.isNotBlank())
        require(localDeviceId.isNotBlank())
        require(expectedPeerDeviceId.isNotBlank())
        require(role != DualPhoneRole.STANDALONE)
        require(calibrationIdentity.isNotBlank())
        require(rigMountRevision.isNotBlank())
        require(captureMode.streamEnabled)
        require(recordingModeIdentity.isNotBlank())
    }

    fun validatePeer(peer: DualPhoneLiveStreamDataChannelHello) {
        require(peer.sessionUuid == sessionUuid) {
            "Session mismatch"
        }
        require(peer.dualCaptureId == dualCaptureId) {
            "dual_capture_id mismatch"
        }
        require(peer.localDeviceId == expectedPeerDeviceId) {
            "Unexpected peer device ${peer.localDeviceId}"
        }
        require(peer.expectedPeerDeviceId == localDeviceId) {
            "Peer expected another local device"
        }
        require(peer.role == role.peerRole()) {
            "Role mismatch: local=$role remote=${peer.role}"
        }
        require(peer.calibrationIdentity == calibrationIdentity) {
            "Calibration identity mismatch"
        }
        require(peer.rigMountRevision == rigMountRevision) {
            "Rig mount revision mismatch"
        }
        require(peer.captureMode == captureMode) {
            "LIVE/HYBRID mode mismatch"
        }
        require(peer.recordingModeIdentity == recordingModeIdentity) {
            "Recording mode identity mismatch"
        }
    }
}

private fun DualPhoneRole.peerRole(): DualPhoneRole = when (this) {
    DualPhoneRole.MASTER -> DualPhoneRole.SLAVE
    DualPhoneRole.SLAVE -> DualPhoneRole.MASTER
    DualPhoneRole.STANDALONE -> DualPhoneRole.STANDALONE
}

/**
 * LM01A-3 dedicated TCP data channel.
 *
 * Connection direction follows the existing dual-phone address configuration:
 * SLAVE listens, MASTER connects to controlSnapshot.peerHost. Future reduced frames travel
 * in the opposite payload direction, SLAVE -> MASTER, over this same full-duplex
 * socket. The command TCP connection and clock-sync UDP socket are not reused.
 */
class DualPhoneLiveStreamDataChannelController(
    private val nowEpochMs: () -> Long = System::currentTimeMillis,
    private val nowNanoTime: () -> Long = System::nanoTime,
    private val sleeper: (Long) -> Unit = Thread::sleep,
    private val executor: ExecutorService =
        Executors.newSingleThreadExecutor(DataChannelThreadFactory()),
) : Closeable {
    private val active = AtomicBoolean(false)
    private val generation = AtomicLong(0L)
    private val mutableState =
        MutableStateFlow(DualPhoneLiveStreamDataChannelSnapshot())

    val state: StateFlow<DualPhoneLiveStreamDataChannelSnapshot> =
        mutableState.asStateFlow()

    val snapshot: DualPhoneLiveStreamDataChannelSnapshot
        get() = mutableState.value

    @Volatile
    private var currentKey: String? = null

    @Volatile
    private var serverSocket: ServerSocket? = null

    @Volatile
    private var dataSocket: Socket? = null

    @Synchronized
    fun start(config: DualPhoneLiveStreamDataChannelConfig) {
        if (active.get() && currentKey == config.key) {
            return
        }

        stopInternal(publishStopped = false)
        val workerGeneration = generation.incrementAndGet()
        currentKey = config.key
        active.set(true)
        mutableState.value = DualPhoneLiveStreamDataChannelSnapshot(
            state = if (config.role == DualPhoneRole.SLAVE) {
                DualPhoneLiveStreamDataChannelState.LISTENING
            } else {
                DualPhoneLiveStreamDataChannelState.CONNECTING
            },
            localRole = config.role,
            port = config.port,
            peerHost = config.peerHost?.trim()?.takeIf { it.isNotBlank() },
        )

        executor.execute {
            runCatching {
                when (config.role) {
                    DualPhoneRole.SLAVE ->
                        runSlaveServer(config, workerGeneration)
                    DualPhoneRole.MASTER ->
                        runMasterClient(config, workerGeneration)
                    DualPhoneRole.STANDALONE ->
                        error("STANDALONE cannot own a data channel")
                }
            }.onFailure { error ->
                if (isCurrent(workerGeneration)) {
                    publish(
                        workerGeneration,
                        state = DualPhoneLiveStreamDataChannelState.FAILED,
                        error = error.message ?: error.javaClass.simpleName,
                    )
                }
            }
        }
    }

    @Synchronized
    fun stop() {
        stopInternal(publishStopped = true)
    }

    override fun close() {
        stop()
        executor.shutdownNow()
    }

    private fun runSlaveServer(
        config: DualPhoneLiveStreamDataChannelConfig,
        workerGeneration: Long,
    ) {
        val localHello = config.hello()
        ServerSocket().use { listener ->
            listener.reuseAddress = true
            listener.soTimeout = ACCEPT_TIMEOUT_MS
            listener.bind(InetSocketAddress(config.port))
            serverSocket = listener

            while (isCurrent(workerGeneration)) {
                publish(
                    workerGeneration,
                    state = DualPhoneLiveStreamDataChannelState.LISTENING,
                    error = null,
                )

                val accepted = try {
                    listener.accept()
                } catch (_: SocketTimeoutException) {
                    continue
                }

                accepted.use { socket ->
                    dataSocket = socket
                    configureSocket(socket)
                    incrementAttempt(workerGeneration)
                    publish(
                        workerGeneration,
                        state = DualPhoneLiveStreamDataChannelState.HANDSHAKING,
                        remoteAddress = socket.remoteSocketAddress?.toString(),
                        error = null,
                    )

                    try {
                        val remoteHello = readHello(
                            socket = socket,
                            expectedType = PacketType.HELLO,
                            workerGeneration = workerGeneration,
                        )
                        localHello.validatePeer(remoteHello)
                        writeHello(
                            socket = socket,
                            type = PacketType.HELLO_ACK,
                            hello = localHello,
                            workerGeneration = workerGeneration,
                        )
                        publishReady(
                            workerGeneration = workerGeneration,
                            remoteAddress =
                                socket.remoteSocketAddress?.toString(),
                            remoteDeviceId = remoteHello.localDeviceId,
                        )
                        serveHeartbeats(socket, workerGeneration)
                    } catch (error: Exception) {
                        if (isCurrent(workerGeneration)) {
                            publishReconnect(
                                workerGeneration,
                                error.message ?: error.javaClass.simpleName,
                            )
                        }
                    } finally {
                        dataSocket = null
                    }
                }
            }
        }
        serverSocket = null
    }

    private fun runMasterClient(
        config: DualPhoneLiveStreamDataChannelConfig,
        workerGeneration: Long,
    ) {
        val peerHost = requireNotNull(
            config.peerHost?.trim()?.takeIf { it.isNotBlank() },
        )
        val localHello = config.hello()

        while (isCurrent(workerGeneration)) {
            incrementAttempt(workerGeneration)
            publish(
                workerGeneration,
                state = if (snapshot.connectionAttempts <= 1L) {
                    DualPhoneLiveStreamDataChannelState.CONNECTING
                } else {
                    DualPhoneLiveStreamDataChannelState.RECONNECTING
                },
                error = null,
            )

            try {
                Socket().use { socket ->
                    dataSocket = socket
                    socket.connect(
                        InetSocketAddress(peerHost, config.port),
                        CONNECT_TIMEOUT_MS,
                    )
                    configureSocket(socket)
                    publish(
                        workerGeneration,
                        state =
                            DualPhoneLiveStreamDataChannelState.HANDSHAKING,
                        remoteAddress =
                            socket.remoteSocketAddress?.toString(),
                        error = null,
                    )

                    writeHello(
                        socket = socket,
                        type = PacketType.HELLO,
                        hello = localHello,
                        workerGeneration = workerGeneration,
                    )
                    val remoteHello = readHello(
                        socket = socket,
                        expectedType = PacketType.HELLO_ACK,
                        workerGeneration = workerGeneration,
                    )
                    localHello.validatePeer(remoteHello)
                    publishReady(
                        workerGeneration = workerGeneration,
                        remoteAddress =
                            socket.remoteSocketAddress?.toString(),
                        remoteDeviceId = remoteHello.localDeviceId,
                    )
                    runHeartbeatClient(socket, workerGeneration)
                }
            } catch (error: Exception) {
                if (isCurrent(workerGeneration)) {
                    publishReconnect(
                        workerGeneration,
                        error.message ?: error.javaClass.simpleName,
                    )
                    sleeper(RECONNECT_DELAY_MS)
                }
            } finally {
                dataSocket = null
            }
        }
    }

    private fun serveHeartbeats(
        socket: Socket,
        workerGeneration: Long,
    ) {
        while (isCurrent(workerGeneration) && !socket.isClosed) {
            val packet = readPacket(socket, workerGeneration)
            require(packet.type == PacketType.PING) {
                "Unexpected data-channel packet ${packet.type}"
            }
            require(packet.payload.size == Long.SIZE_BYTES) {
                "Invalid PING payload size"
            }
            writePacket(
                socket = socket,
                packet = Packet(PacketType.PONG, packet.payload),
                workerGeneration = workerGeneration,
            )
            publish(
                workerGeneration,
                heartbeatEpochMs = nowEpochMs(),
                error = null,
            )
        }
    }

    private fun runHeartbeatClient(
        socket: Socket,
        workerGeneration: Long,
    ) {
        while (isCurrent(workerGeneration) && !socket.isClosed) {
            val startedNs = nowNanoTime()
            val payload = ByteArrayOutputStream(Long.SIZE_BYTES).use {
                DataOutputStream(it).use { output ->
                    output.writeLong(startedNs)
                }
                it.toByteArray()
            }
            writePacket(
                socket = socket,
                packet = Packet(PacketType.PING, payload),
                workerGeneration = workerGeneration,
            )
            val response = readPacket(socket, workerGeneration)
            require(response.type == PacketType.PONG) {
                "Expected PONG, received ${response.type}"
            }
            require(response.payload.contentEquals(payload)) {
                "PONG payload mismatch"
            }
            val roundTripMs =
                (nowNanoTime() - startedNs).coerceAtLeast(0L) / 1_000_000.0
            publish(
                workerGeneration,
                roundTripMs = roundTripMs,
                heartbeatEpochMs = nowEpochMs(),
                error = null,
            )
            sleeper(HEARTBEAT_INTERVAL_MS)
        }
    }

    private fun configureSocket(socket: Socket) {
        socket.tcpNoDelay = true
        socket.keepAlive = true
        socket.soTimeout = READ_TIMEOUT_MS
    }

    private fun writeHello(
        socket: Socket,
        type: PacketType,
        hello: DualPhoneLiveStreamDataChannelHello,
        workerGeneration: Long,
    ) {
        val payload = Protocol.encodeHello(hello)
        writePacket(
            socket = socket,
            packet = Packet(type, payload),
            workerGeneration = workerGeneration,
        )
    }

    private fun readHello(
        socket: Socket,
        expectedType: PacketType,
        workerGeneration: Long,
    ): DualPhoneLiveStreamDataChannelHello {
        val packet = readPacket(socket, workerGeneration)
        require(packet.type == expectedType) {
            "Expected $expectedType, received ${packet.type}"
        }
        return Protocol.decodeHello(packet.payload)
    }

    private fun writePacket(
        socket: Socket,
        packet: Packet,
        workerGeneration: Long,
    ) {
        val written = Protocol.write(
            output = DataOutputStream(socket.getOutputStream()),
            packet = packet,
        )
        incrementSent(workerGeneration, written)
    }

    private fun readPacket(
        socket: Socket,
        workerGeneration: Long,
    ): Packet {
        val result = Protocol.read(
            input = DataInputStream(socket.getInputStream()),
        )
        incrementReceived(workerGeneration, result.bytesRead)
        return result.packet
    }

    @Synchronized
    private fun stopInternal(publishStopped: Boolean) {
        active.set(false)
        generation.incrementAndGet()
        closeQuietly(dataSocket)
        closeQuietly(serverSocket)
        dataSocket = null
        serverSocket = null
        currentKey = null
        if (publishStopped) {
            mutableState.value =
                DualPhoneLiveStreamDataChannelSnapshot()
        }
    }

    private fun closeQuietly(closeable: Closeable?) {
        runCatching { closeable?.close() }
    }

    private fun isCurrent(workerGeneration: Long): Boolean =
        active.get() && generation.get() == workerGeneration

    private fun publishReady(
        workerGeneration: Long,
        remoteAddress: String?,
        remoteDeviceId: String,
    ) {
        if (!isCurrent(workerGeneration)) return
        mutableState.value = mutableState.value.copy(
            state = DualPhoneLiveStreamDataChannelState.READY,
            remoteAddress = remoteAddress,
            remoteDeviceId = remoteDeviceId,
            lastError = null,
        )
    }

    private fun publishReconnect(
        workerGeneration: Long,
        error: String,
    ) {
        if (!isCurrent(workerGeneration)) return
        val old = mutableState.value
        mutableState.value = old.copy(
            state = DualPhoneLiveStreamDataChannelState.RECONNECTING,
            reconnects = old.reconnects + 1L,
            lastError = error,
        )
    }

    private fun publish(
        workerGeneration: Long,
        state: DualPhoneLiveStreamDataChannelState? = null,
        remoteAddress: String? = mutableState.value.remoteAddress,
        error: String? = mutableState.value.lastError,
        roundTripMs: Double? = mutableState.value.lastRoundTripMs,
        heartbeatEpochMs: Long? =
            mutableState.value.lastHeartbeatEpochMs,
    ) {
        if (!isCurrent(workerGeneration)) return
        val old = mutableState.value
        mutableState.value = old.copy(
            state = state ?: old.state,
            remoteAddress = remoteAddress,
            lastError = error,
            lastRoundTripMs = roundTripMs,
            lastHeartbeatEpochMs = heartbeatEpochMs,
        )
    }

    private fun incrementAttempt(workerGeneration: Long) {
        if (!isCurrent(workerGeneration)) return
        val old = mutableState.value
        mutableState.value = old.copy(
            connectionAttempts = old.connectionAttempts + 1L,
        )
    }

    private fun incrementSent(
        workerGeneration: Long,
        bytes: Int,
    ) {
        if (!isCurrent(workerGeneration)) return
        val old = mutableState.value
        mutableState.value = old.copy(
            packetsSent = old.packetsSent + 1L,
            bytesSent = old.bytesSent + bytes,
        )
    }

    private fun incrementReceived(
        workerGeneration: Long,
        bytes: Int,
    ) {
        if (!isCurrent(workerGeneration)) return
        val old = mutableState.value
        mutableState.value = old.copy(
            packetsReceived = old.packetsReceived + 1L,
            bytesReceived = old.bytesReceived + bytes,
        )
    }

    private data class Packet(
        val type: PacketType,
        val payload: ByteArray,
    )

    private data class ReadPacketResult(
        val packet: Packet,
        val bytesRead: Int,
    )

    private enum class PacketType(val wireValue: Int) {
        HELLO(1),
        HELLO_ACK(2),
        PING(3),
        PONG(4);

        companion object {
            fun fromWire(value: Int): PacketType =
                entries.firstOrNull { it.wireValue == value }
                    ?: throw IOException("Unknown packet type: $value")
        }
    }

    private object Protocol {
        private const val MAGIC = 0x4C4D3031
        private const val SCHEMA_VERSION = 1
        private const val HEADER_BYTES = 13
        private const val MAX_PACKET_BYTES = 64 * 1024
        private const val MAX_STRING_BYTES = 4 * 1024

        fun write(
            output: DataOutputStream,
            packet: Packet,
        ): Int {
            require(packet.payload.size <= MAX_PACKET_BYTES) {
                "Packet exceeds $MAX_PACKET_BYTES bytes"
            }
            output.writeInt(MAGIC)
            output.writeInt(SCHEMA_VERSION)
            output.writeByte(packet.type.wireValue)
            output.writeInt(packet.payload.size)
            output.write(packet.payload)
            output.flush()
            return HEADER_BYTES + packet.payload.size
        }

        fun read(input: DataInputStream): ReadPacketResult {
            val magic = try {
                input.readInt()
            } catch (error: EOFException) {
                throw SocketException("Data channel closed")
            }
            require(magic == MAGIC) { "Invalid data-channel magic" }
            require(input.readInt() == SCHEMA_VERSION) {
                "Unsupported data-channel schema"
            }
            val type = PacketType.fromWire(input.readUnsignedByte())
            val size = input.readInt()
            require(size in 0..MAX_PACKET_BYTES) {
                "Invalid packet payload size: $size"
            }
            val payload = ByteArray(size)
            input.readFully(payload)
            return ReadPacketResult(
                packet = Packet(type, payload),
                bytesRead = HEADER_BYTES + size,
            )
        }

        fun encodeHello(
            hello: DualPhoneLiveStreamDataChannelHello,
        ): ByteArray = ByteArrayOutputStream().use { bytes ->
            DataOutputStream(bytes).use { output ->
                writeString(output, hello.sessionUuid)
                writeString(output, hello.dualCaptureId)
                writeString(output, hello.streamId)
                writeString(output, hello.localDeviceId)
                writeString(output, hello.expectedPeerDeviceId)
                writeString(output, hello.role.name)
                writeString(output, hello.calibrationIdentity)
                writeString(output, hello.rigMountRevision)
                writeString(output, hello.captureMode.name)
                writeString(output, hello.recordingModeIdentity)
            }
            bytes.toByteArray()
        }

        fun decodeHello(
            payload: ByteArray,
        ): DualPhoneLiveStreamDataChannelHello =
            DataInputStream(ByteArrayInputStream(payload)).use { input ->
                val hello = DualPhoneLiveStreamDataChannelHello(
                    sessionUuid = readString(input),
                    dualCaptureId = readString(input),
                    streamId = readString(input),
                    localDeviceId = readString(input),
                    expectedPeerDeviceId = readString(input),
                    role = DualPhoneRole.valueOf(readString(input)),
                    calibrationIdentity = readString(input),
                    rigMountRevision = readString(input),
                    captureMode =
                        DualPhoneLiveStreamMode.valueOf(readString(input)),
                    recordingModeIdentity = readString(input),
                )
                require(input.available() == 0) {
                    "Trailing HELLO payload bytes"
                }
                hello
            }

        private fun writeString(
            output: DataOutputStream,
            value: String,
        ) {
            val bytes = value.toByteArray(StandardCharsets.UTF_8)
            require(bytes.size <= MAX_STRING_BYTES) {
                "Protocol string is too long"
            }
            output.writeInt(bytes.size)
            output.write(bytes)
        }

        private fun readString(input: DataInputStream): String {
            val size = input.readInt()
            require(size in 0..MAX_STRING_BYTES) {
                "Invalid protocol string size: $size"
            }
            val bytes = ByteArray(size)
            input.readFully(bytes)
            return bytes.toString(StandardCharsets.UTF_8)
        }
    }

    companion object {
        const val DEFAULT_PORT: Int = 45831

        private const val CONNECT_TIMEOUT_MS = 1_500
        private const val ACCEPT_TIMEOUT_MS = 750
        private const val READ_TIMEOUT_MS = 4_000
        private const val RECONNECT_DELAY_MS = 750L
        private const val HEARTBEAT_INTERVAL_MS = 1_000L
    }

    private class DataChannelThreadFactory : ThreadFactory {
        override fun newThread(runnable: Runnable): Thread =
            Thread(runnable, "lm01a-data-channel").apply {
                isDaemon = true
            }
    }
}
