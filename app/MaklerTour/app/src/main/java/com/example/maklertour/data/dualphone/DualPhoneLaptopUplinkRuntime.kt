package com.example.maklertour.data.dualphone

import android.content.Context
import android.hardware.Sensor
import android.hardware.SensorEvent
import android.hardware.SensorEventListener
import android.hardware.SensorManager
import android.os.SystemClock
import com.maklertour.data.calibration.DualPhoneCalibrationProfileStore
import com.maklertour.data.dualphone.ApplicationCaptureMode
import com.maklertour.data.dualphone.DualPhoneRole
import com.maklertour.data.dualphone.DualPhoneStereoSettingsStore
import java.io.BufferedInputStream
import java.io.BufferedOutputStream
import java.io.DataInputStream
import java.io.DataOutputStream
import java.io.EOFException
import java.io.IOException
import java.net.InetSocketAddress
import java.net.Socket
import java.nio.charset.StandardCharsets
import java.util.UUID
import java.util.concurrent.ExecutorService
import java.util.concurrent.Executors
import java.util.concurrent.atomic.AtomicBoolean
import java.util.concurrent.atomic.AtomicLong
import java.util.concurrent.atomic.AtomicReference
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import org.json.JSONArray
import org.json.JSONObject

enum class DualPhoneLaptopUplinkState {
    STOPPED,
    CONNECTING,
    HANDSHAKING,
    STREAMING,
    RECONNECTING,
    FAILED,
}

data class DualPhoneLaptopUplinkSnapshot(
    val state: DualPhoneLaptopUplinkState = DualPhoneLaptopUplinkState.STOPPED,
    val host: String = "",
    val port: Int = DualPhoneLaptopUplinkConfig.DEFAULT_PORT,
    val slot: DualPhoneLaptopCameraSlot = DualPhoneLaptopCameraSlot.CAMERA_A,
    val sessionId: String? = null,
    val framesOffered: Long = 0L,
    val framesSent: Long = 0L,
    val framesReplacedBeforeSend: Long = 0L,
    val bytesSent: Long = 0L,
    val reconnects: Long = 0L,
    val clockOffsetNs: Long = 0L,
    val clockRttMs: Double = 0.0,
    val clockSamples: Long = 0L,
    val imuPacketsSent: Long = 0L,
    val calibrationProfileId: String? = null,
    val hostCalibrationProfileId: String? = null,
    val calibrationAccepted: Boolean = false,
    val hostCalibrationReady: Boolean = false,
    val calibrationRevision: Long = 0L,
    val calibrationReason: String = "NOT_CONNECTED",
    val producer: DualPhoneReducedFrameProducerSnapshot =
        DualPhoneReducedFrameProducerSnapshot(),
    val lastError: String? = null,
) {
    val connected: Boolean
        get() = state == DualPhoneLaptopUplinkState.STREAMING
}

class DualPhoneLaptopUplinkRuntime private constructor(context: Context) {
    private data class SensorVector(
        val timestampNs: Long,
        val x: Float,
        val y: Float,
        val z: Float,
    )

    private data class LaptopHandshakeContext(
        val deviceId: String,
        val rigId: String,
        val rigMountRevision: String,
        val recordingModeIdentity: String,
        val calibrationId: String,
        val calibrationProfile: JSONObject?,
    )

    private val appContext = context.applicationContext
    private val producer = DualPhoneReducedFrameProducer(appContext)
    private val calibrationProfileStore =
        DualPhoneCalibrationProfileStore(appContext)
    private val stereoSettingsStore =
        DualPhoneStereoSettingsStore(appContext)
    private val worker: ExecutorService = Executors.newSingleThreadExecutor { runnable ->
        Thread(runnable, "lm02-7b-laptop-uplink").apply { isDaemon = true }
    }
    private val active = AtomicBoolean(false)
    private val generation = AtomicLong(0L)
    private val offered = AtomicLong(0L)
    private val sent = AtomicLong(0L)
    private val replaced = AtomicLong(0L)
    private val bytesSent = AtomicLong(0L)
    private val reconnects = AtomicLong(0L)
    private val clockOffsetNs = AtomicLong(0L)
    private val clockRttNs = AtomicLong(Long.MAX_VALUE)
    private val clockSamples = AtomicLong(0L)
    private val imuPacketsSent = AtomicLong(0L)
    private val pendingFrame = AtomicReference<DualPhoneReducedFrame?>(null)
    private val latestAccelerometer = AtomicReference<SensorVector?>(null)
    private val latestGyroscope = AtomicReference<SensorVector?>(null)

    @Volatile
    private var activeSocket: Socket? = null
    @Volatile
    private var sessionId: String? = null
    @Volatile
    private var activeConfig: DualPhoneLaptopUplinkConfig? = null
    @Volatile
    private var calibrationProfileId: String? = null
    @Volatile
    private var hostCalibrationProfileId: String? = null
    @Volatile
    private var calibrationAccepted: Boolean = false
    @Volatile
    private var hostCalibrationReady: Boolean = false
    @Volatile
    private var calibrationRevision: Long = 0L
    @Volatile
    private var calibrationReason: String = "NOT_CONNECTED"

    private val sensorManager =
        appContext.getSystemService(Context.SENSOR_SERVICE) as SensorManager
    private val sensorListener = object : SensorEventListener {
        override fun onSensorChanged(event: SensorEvent) {
            if (!active.get() || event.values.size < 3) return
            val value = SensorVector(
                timestampNs = event.timestamp,
                x = event.values[0],
                y = event.values[1],
                z = event.values[2],
            )
            when (event.sensor.type) {
                Sensor.TYPE_ACCELEROMETER -> latestAccelerometer.set(value)
                Sensor.TYPE_GYROSCOPE -> latestGyroscope.set(value)
            }
        }

        override fun onAccuracyChanged(sensor: Sensor?, accuracy: Int) = Unit
    }

    private val mutableState = MutableStateFlow(DualPhoneLaptopUplinkSnapshot())
    val state: StateFlow<DualPhoneLaptopUplinkSnapshot> = mutableState.asStateFlow()
    val snapshot: DualPhoneLaptopUplinkSnapshot
        get() = mutableState.value

    @Synchronized
    fun start(config: DualPhoneLaptopUplinkConfig) {
        // The UI can keep a stale Compose snapshot after calibration. The
        // transport therefore resolves the current settings/profile itself.
        val initialHandshake = loadHandshakeContext(config)
        stopInternal(publishStopped = false)
        activeConfig = config
        sessionId = UUID.randomUUID().toString()
        offered.set(0L)
        sent.set(0L)
        replaced.set(0L)
        bytesSent.set(0L)
        reconnects.set(0L)
        clockOffsetNs.set(0L)
        clockRttNs.set(Long.MAX_VALUE)
        clockSamples.set(0L)
        imuPacketsSent.set(0L)
        calibrationProfileId = initialHandshake.calibrationId
        hostCalibrationProfileId = null
        calibrationAccepted = false
        hostCalibrationReady = false
        calibrationRevision = 0L
        calibrationReason = "CONNECTING"
        pendingFrame.set(null)
        latestAccelerometer.set(null)
        latestGyroscope.set(null)
        active.set(true)
        val token = generation.incrementAndGet()
        publish(
            state = DualPhoneLaptopUplinkState.CONNECTING,
            error = null,
        )

        registerSensors()

        val currentSession = requireNotNull(sessionId)
        val owner = DualPhoneLiveStreamOwner(
            sessionUuid = currentSession,
            dualCaptureId = initialHandshake.rigId.ifBlank { "laptop-rig" },
            localRole = "LAPTOP_${config.slot.name}",
            peerIdentity = "CPU_LAPTOP_HOST",
            cameraIdentity = "SELECTED_PHONE_CAMERA",
            recordingModeIdentity = initialHandshake.recordingModeIdentity,
            calibrationIdentity = initialHandshake.calibrationId,
            rigMountRevision =
                initialHandshake.rigMountRevision.ifBlank { "unknown" },
            captureMode = DualPhoneLiveStreamMode.LIVE_METRIC,
            streamId = "laptop-${config.slot.name.lowercase()}-$currentSession",
        )
        // DualPhoneReducedFrame still carries the legacy role enum in its frame
        // metadata. Derive it from the laptop camera slot; never read or mutate
        // the persisted phone-to-phone role for laptop transport.
        val producerRole = if (
            config.slot == DualPhoneLaptopCameraSlot.CAMERA_A
        ) {
            DualPhoneRole.MASTER
        } else {
            DualPhoneRole.SLAVE
        }
        producer.start(owner, producerRole) { frame ->
            offered.incrementAndGet()
            if (pendingFrame.getAndSet(frame) != null) {
                replaced.incrementAndGet()
            }
            publish()
        }

        worker.execute {
            connectionLoop(
                token = token,
                config = config,
            )
        }
    }

    @Synchronized
    fun stop() {
        stopInternal(publishStopped = true)
    }

    private fun loadHandshakeContext(
        config: DualPhoneLaptopUplinkConfig,
    ): LaptopHandshakeContext {
        val settings = stereoSettingsStore.load()
        require(
            settings.applicationMode == ApplicationCaptureMode.LAPTOP_STEREO_CLIENT
        ) {
            "Select the application mode 'Two phones -> laptop/PC' before laptop capture"
        }

        val missingProfileMessage = if (
            config.slot == DualPhoneLaptopCameraSlot.CAMERA_A
        ) {
            "CAMERA_A requires an active calibration profile created on the MASTER phone"
        } else {
            "CAMERA_B requires the active dual-phone calibration profile ID"
        }
        val calibrationId = requireNotNull(
            settings.activeCalibrationProfileId
                ?.trim()
                ?.takeIf { it.isNotEmpty() },
        ) {
            missingProfileMessage
        }
        val calibrationProfile = if (
            config.slot == DualPhoneLaptopCameraSlot.CAMERA_A
        ) {
            val profile = requireNotNull(
                calibrationProfileStore.load(calibrationId),
            ) {
                "CAMERA_A calibration profile $calibrationId was not found"
            }
            require(profile.successful) {
                "CAMERA_A calibration profile $calibrationId is not successful"
            }
            require(profile.profileId == calibrationId) {
                "CAMERA_A calibration profile ID mismatch"
            }
            require(profile.masterDeviceId == settings.deviceId) {
                "CAMERA_A must use the profile created by this MASTER phone"
            }
            require(profile.rigId == settings.rigId) {
                "CAMERA_A rig ID does not match the active calibration profile"
            }
            require(profile.rigMountRevision == settings.rigMountRevision) {
                "CAMERA_A mount revision does not match the active calibration profile"
            }
            profile.toJson()
        } else {
            null
        }

        return LaptopHandshakeContext(
            deviceId = settings.deviceId,
            rigId = settings.rigId,
            rigMountRevision = settings.rigMountRevision,
            recordingModeIdentity =
                settings.preferredVideoModeId ?: "CAMERAX_960X540",
            calibrationId = calibrationId,
            calibrationProfile = calibrationProfile,
        )
    }

    private fun connectionLoop(
        token: Long,
        config: DualPhoneLaptopUplinkConfig,
    ) {
        var firstAttempt = true
        while (active.get() && generation.get() == token) {
            if (!firstAttempt) {
                reconnects.incrementAndGet()
                publish(
                    state = DualPhoneLaptopUplinkState.RECONNECTING,
                    error = mutableState.value.lastError,
                )
                sleepInterruptibly(RECONNECT_DELAY_MS, token)
            }
            firstAttempt = false
            if (!active.get() || generation.get() != token) break

            try {
                calibrationAccepted = false
                hostCalibrationReady = false
                hostCalibrationProfileId = null
                calibrationReason = "HANDSHAKING"
                publish(
                    state = DualPhoneLaptopUplinkState.CONNECTING,
                    error = null,
                )
                // Re-read the authoritative settings/profile on every
                // handshake and reconnect instead of reusing a UI snapshot.
                val handshake = loadHandshakeContext(config)
                calibrationProfileId = handshake.calibrationId
                val socket = Socket()
                socket.tcpNoDelay = true
                socket.keepAlive = true
                socket.connect(
                    InetSocketAddress(config.host, config.port),
                    CONNECT_TIMEOUT_MS,
                )
                socket.soTimeout = CLOCK_RESPONSE_TIMEOUT_MS
                activeSocket = socket

                val input = DataInputStream(BufferedInputStream(socket.getInputStream()))
                val output = DataOutputStream(
                    BufferedOutputStream(socket.getOutputStream()),
                )
                publish(
                    state = DualPhoneLaptopUplinkState.HANDSHAKING,
                    error = null,
                )

                val helloClientNs = SystemClock.elapsedRealtimeNanos()
                writeLine(
                    output,
                    JSONObject()
                        .put("type", "hello")
                        .put("schema_version", PROTOCOL_SCHEMA)
                        .put("slot", config.slot.name)
                        .put("device_id", handshake.deviceId)
                        .put("session_id", sessionId)
                        .put("capture_mode", "ANDROID_CAMERAX")
                        .put("rig_id", handshake.rigId)
                        .put(
                            "rig_mount_revision",
                            handshake.rigMountRevision,
                        )
                        .put(
                            "calibration_profile_id",
                            handshake.calibrationId,
                        )
                        .put(
                            "calibration_authority",
                            config.slot == DualPhoneLaptopCameraSlot.CAMERA_A,
                        )
                        .put(
                            "calibration_profile",
                            handshake.calibrationProfile ?: JSONObject.NULL,
                        )
                        .put("client_monotonic_ns", helloClientNs)
                        .toString(),
                )

                val ack = JSONObject(readUtf8Line(input, MAX_HELLO_BYTES))
                val helloClientReceiveNs = SystemClock.elapsedRealtimeNanos()
                check(ack.optString("type") == "hello_ack") {
                    "Unexpected laptop hello response"
                }
                val responseCalibrationReason = ack.optString(
                    "calibration_reason",
                    ack.optString("reason", "unknown"),
                )
                calibrationReason = responseCalibrationReason
                check(ack.optBoolean("accepted", false)) {
                    "Laptop rejected hello: ${ack.optString("reason", "unknown")}"
                }
                val reportedProfileId = ack
                    .optString("reported_calibration_profile_id", "")
                    .trim()
                    .takeIf { it.isNotEmpty() }
                val acceptedHostProfileId = ack
                    .optString("host_calibration_profile_id", "")
                    .trim()
                    .takeIf { it.isNotEmpty() }
                val acceptedCalibration =
                    ack.optBoolean("calibration_accepted", false)
                val readyOnHost =
                    ack.optBoolean("host_calibration_ready", false)
                val revision = ack.optLong("calibration_revision", 0L)
                val reason = responseCalibrationReason
                check(acceptedCalibration) {
                    "Laptop rejected calibration: $reason"
                }
                check(reportedProfileId == handshake.calibrationId) {
                    "Laptop acknowledged a different reported calibration profile"
                }
                if (acceptedHostProfileId != null) {
                    check(acceptedHostProfileId == handshake.calibrationId) {
                        "Laptop host calibration profile does not match this phone"
                    }
                }
                if (config.slot == DualPhoneLaptopCameraSlot.CAMERA_A) {
                    check(readyOnHost && acceptedHostProfileId == handshake.calibrationId) {
                        "Laptop did not activate CAMERA_A calibration profile: $reason"
                    }
                }
                calibrationProfileId = handshake.calibrationId
                hostCalibrationProfileId = acceptedHostProfileId
                calibrationAccepted = acceptedCalibration
                hostCalibrationReady = readyOnHost
                calibrationRevision = revision
                calibrationReason = reason
                updateClockFromAck(
                    ack = ack,
                    clientSendNs = helloClientNs,
                    clientReceiveNs = helloClientReceiveNs,
                )
                publish(
                    state = DualPhoneLaptopUplinkState.STREAMING,
                    error = null,
                )
                streamLoop(token, input, output)
            } catch (error: Throwable) {
                if (active.get() && generation.get() == token) {
                    calibrationAccepted = false
                    hostCalibrationReady = false
                    if (calibrationReason == "HANDSHAKING") {
                        calibrationReason = "CONNECTION_OR_HANDSHAKE_FAILED"
                    }
                    publish(
                        state = DualPhoneLaptopUplinkState.RECONNECTING,
                        error = error.message ?: error.javaClass.simpleName,
                    )
                }
            } finally {
                runCatching { activeSocket?.close() }
                activeSocket = null
            }
        }
    }

    private fun streamLoop(
        token: Long,
        input: DataInputStream,
        output: DataOutputStream,
    ) {
        var nextClockProbeNs = 0L
        var nextImuNs = 0L
        while (active.get() && generation.get() == token) {
            val nowNs = SystemClock.elapsedRealtimeNanos()
            var wrote = false

            if (nowNs >= nextClockProbeNs) {
                performClockProbe(input, output)
                nextClockProbeNs = nowNs + CLOCK_PROBE_INTERVAL_NS
                wrote = true
            }

            val frame = pendingFrame.getAndSet(null)
            if (frame != null) {
                writeFrame(output, frame)
                sent.incrementAndGet()
                bytesSent.addAndGet(frame.jpegBytes.size.toLong())
                wrote = true
            }

            if (nowNs >= nextImuNs && writeLatestImu(output)) {
                nextImuNs = nowNs + IMU_INTERVAL_NS
                wrote = true
            }

            if (wrote) {
                output.flush()
                publish(
                    state = DualPhoneLaptopUplinkState.STREAMING,
                    error = null,
                )
            } else {
                Thread.sleep(IDLE_SLEEP_MS)
            }
        }
    }

    private fun performClockProbe(
        input: DataInputStream,
        output: DataOutputStream,
    ) {
        val clientSendNs = SystemClock.elapsedRealtimeNanos()
        writePacket(
            output = output,
            header = JSONObject()
                .put("type", "clock_probe")
                .put("schema_version", PROTOCOL_SCHEMA)
                .put("client_send_ns", clientSendNs),
            payload = EMPTY_PAYLOAD,
        )
        output.flush()
        val response = readPacket(input)
        val clientReceiveNs = SystemClock.elapsedRealtimeNanos()
        check(response.first.optString("type") == "clock_probe_ack") {
            "Unexpected clock response"
        }
        updateClockFromAck(
            ack = response.first,
            clientSendNs = clientSendNs,
            clientReceiveNs = clientReceiveNs,
        )
    }

    private fun updateClockFromAck(
        ack: JSONObject,
        clientSendNs: Long,
        clientReceiveNs: Long,
    ) {
        val serverReceiveNs = ack.optLong(
            "server_receive_ns",
            ack.optLong("server_monotonic_ns", 0L),
        )
        val serverSendNs = ack.optLong(
            "server_send_ns",
            serverReceiveNs,
        )
        if (serverReceiveNs <= 0L || serverSendNs <= 0L) return

        val serverProcessingNs = (serverSendNs - serverReceiveNs).coerceAtLeast(0L)
        val rawRttNs =
            (clientReceiveNs - clientSendNs - serverProcessingNs).coerceAtLeast(0L)
        val clientMidNs = clientSendNs / 2L + clientReceiveNs / 2L
        val serverMidNs = serverReceiveNs / 2L + serverSendNs / 2L
        val candidateOffsetNs = serverMidNs - clientMidNs

        val previousBest = clockRttNs.get()
        if (rawRttNs <= previousBest || clockSamples.get() < 3L) {
            clockRttNs.set(rawRttNs)
            clockOffsetNs.set(candidateOffsetNs)
        }
        clockSamples.incrementAndGet()
    }

    private fun writeFrame(
        output: DataOutputStream,
        frame: DualPhoneReducedFrame,
    ) {
        val offsetNs = clockOffsetNs.get()
        val header = JSONObject()
            .put("type", "frame")
            .put("schema_version", PROTOCOL_SCHEMA)
            .put("session_id", sessionId)
            .put("frame_sequence", frame.frameSequence)
            .put("sensor_timestamp_ns", frame.sensorTimestampNs)
            .put("capture_elapsed_ns", frame.captureElapsedRealtimeNs)
            .put(
                "host_aligned_timestamp_ns",
                frame.captureElapsedRealtimeNs + offsetNs,
            )
            .put("width", frame.width)
            .put("height", frame.height)
            .put("rotation_degrees", frame.imageProxyRotationDegrees)
            .put("encoding", "JPEG")
            .put("payload_crc32", frame.payloadCrc32)
            .put("clock_offset_ns", offsetNs)
            .put(
                "clock_rtt_ns",
                clockRttNs.get().takeUnless { it == Long.MAX_VALUE } ?: 0L,
            )
            .put("clock_samples", clockSamples.get())
            .put("sender_frames_offered", offered.get())
            .put("sender_frames_replaced_before_send", replaced.get())
        writePacket(output, header, frame.jpegBytes)
    }

    private fun writeLatestImu(output: DataOutputStream): Boolean {
        val accelerometer = latestAccelerometer.get()
        val gyroscope = latestGyroscope.get()
        if (accelerometer == null && gyroscope == null) return false
        val captureNs = SystemClock.elapsedRealtimeNanos()
        val sensorNs = maxOf(
            accelerometer?.timestampNs ?: 0L,
            gyroscope?.timestampNs ?: 0L,
        )
        val header = JSONObject()
            .put("type", "imu")
            .put("schema_version", PROTOCOL_SCHEMA)
            .put("session_id", sessionId)
            .put("sensor_timestamp_ns", sensorNs)
            .put("capture_elapsed_ns", captureNs)
            .put(
                "host_aligned_timestamp_ns",
                captureNs + clockOffsetNs.get(),
            )
        accelerometer?.let {
            header.put(
                "accelerometer_mps2",
                JSONArray(listOf(it.x, it.y, it.z)),
            )
        }
        gyroscope?.let {
            header.put(
                "gyroscope_rad_s",
                JSONArray(listOf(it.x, it.y, it.z)),
            )
        }
        writePacket(output, header, EMPTY_PAYLOAD)
        imuPacketsSent.incrementAndGet()
        return true
    }

    private fun registerSensors() {
        sensorManager.getDefaultSensor(Sensor.TYPE_ACCELEROMETER)?.let { sensor ->
            sensorManager.registerListener(
                sensorListener,
                sensor,
                SensorManager.SENSOR_DELAY_GAME,
            )
        }
        sensorManager.getDefaultSensor(Sensor.TYPE_GYROSCOPE)?.let { sensor ->
            sensorManager.registerListener(
                sensorListener,
                sensor,
                SensorManager.SENSOR_DELAY_GAME,
            )
        }
    }

    private fun unregisterSensors() {
        sensorManager.unregisterListener(sensorListener)
    }

    @Synchronized
    private fun stopInternal(publishStopped: Boolean) {
        active.set(false)
        generation.incrementAndGet()
        runCatching {
            activeSocket?.shutdownInput()
            activeSocket?.shutdownOutput()
        }
        runCatching { activeSocket?.close() }
        activeSocket = null
        producer.stop()
        unregisterSensors()
        pendingFrame.set(null)
        calibrationProfileId = null
        hostCalibrationProfileId = null
        calibrationAccepted = false
        hostCalibrationReady = false
        calibrationRevision = 0L
        calibrationReason = "NOT_CONNECTED"
        if (publishStopped) {
            activeConfig = null
            sessionId = null
            mutableState.value = DualPhoneLaptopUplinkSnapshot(
                producer = producer.snapshot,
            )
        }
    }

    private fun publish(
        state: DualPhoneLaptopUplinkState? = null,
        error: String? = mutableState.value.lastError,
    ) {
        synchronized(mutableState) {
            val config = activeConfig
            mutableState.value = mutableState.value.copy(
                state = state ?: mutableState.value.state,
                host = config?.host.orEmpty(),
                port = config?.port ?: DualPhoneLaptopUplinkConfig.DEFAULT_PORT,
                slot = config?.slot ?: DualPhoneLaptopCameraSlot.CAMERA_A,
                sessionId = sessionId,
                framesOffered = offered.get(),
                framesSent = sent.get(),
                framesReplacedBeforeSend = replaced.get(),
                bytesSent = bytesSent.get(),
                reconnects = reconnects.get(),
                clockOffsetNs = clockOffsetNs.get(),
                clockRttMs = (
                    clockRttNs.get()
                        .takeUnless { it == Long.MAX_VALUE }
                        ?: 0L
                    ) / 1_000_000.0,
                clockSamples = clockSamples.get(),
                imuPacketsSent = imuPacketsSent.get(),
                calibrationProfileId = calibrationProfileId,
                hostCalibrationProfileId = hostCalibrationProfileId,
                calibrationAccepted = calibrationAccepted,
                hostCalibrationReady = hostCalibrationReady,
                calibrationRevision = calibrationRevision,
                calibrationReason = calibrationReason,
                producer = producer.snapshot,
                lastError = error,
            )
        }
    }

    private fun sleepInterruptibly(durationMs: Long, token: Long) {
        val deadline = SystemClock.elapsedRealtime() + durationMs
        while (
            active.get() &&
            generation.get() == token &&
            SystemClock.elapsedRealtime() < deadline
        ) {
            Thread.sleep(100L)
        }
    }

    private fun writeLine(output: DataOutputStream, value: String) {
        output.write(value.toByteArray(StandardCharsets.UTF_8))
        output.writeByte('\n'.code)
        output.flush()
    }

    private fun readUtf8Line(
        input: DataInputStream,
        maxBytes: Int,
    ): String {
        val buffer = ArrayList<Byte>()
        while (buffer.size < maxBytes) {
            val value = input.read()
            if (value < 0) throw EOFException("Socket closed while reading line")
            if (value == '\n'.code) {
                return String(buffer.toByteArray(), StandardCharsets.UTF_8)
            }
            if (value != '\r'.code) buffer.add(value.toByte())
        }
        throw IOException("Line exceeds $maxBytes bytes")
    }

    private fun writePacket(
        output: DataOutputStream,
        header: JSONObject,
        payload: ByteArray,
    ) {
        val headerBytes = header.toString().toByteArray(StandardCharsets.UTF_8)
        require(headerBytes.size <= MAX_HEADER_BYTES)
        require(payload.size <= MAX_PAYLOAD_BYTES)
        output.writeInt(headerBytes.size)
        output.writeInt(payload.size)
        output.write(headerBytes)
        output.write(payload)
    }

    private fun readPacket(
        input: DataInputStream,
    ): Pair<JSONObject, ByteArray> {
        val headerLength = input.readInt()
        val payloadLength = input.readInt()
        require(headerLength in 1..MAX_HEADER_BYTES)
        require(payloadLength in 0..MAX_PAYLOAD_BYTES)
        val headerBytes = ByteArray(headerLength)
        input.readFully(headerBytes)
        val payload = ByteArray(payloadLength)
        input.readFully(payload)
        return JSONObject(String(headerBytes, StandardCharsets.UTF_8)) to payload
    }

    companion object {
        private const val PROTOCOL_SCHEMA = 1
        private const val MAX_HELLO_BYTES = 256 * 1024
        private const val MAX_HEADER_BYTES = 64 * 1024
        private const val MAX_PAYLOAD_BYTES = 2 * 1024 * 1024
        private const val CONNECT_TIMEOUT_MS = 3_000
        private const val CLOCK_RESPONSE_TIMEOUT_MS = 5_000
        private const val RECONNECT_DELAY_MS = 1_000L
        private const val IDLE_SLEEP_MS = 5L
        private const val CLOCK_PROBE_INTERVAL_NS = 5_000_000_000L
        private const val IMU_INTERVAL_NS = 20_000_000L
        private val EMPTY_PAYLOAD = ByteArray(0)

        @Volatile
        private var instance: DualPhoneLaptopUplinkRuntime? = null

        fun get(context: Context): DualPhoneLaptopUplinkRuntime =
            instance ?: synchronized(this) {
                instance ?: DualPhoneLaptopUplinkRuntime(context).also {
                    instance = it
                }
            }
    }
}
