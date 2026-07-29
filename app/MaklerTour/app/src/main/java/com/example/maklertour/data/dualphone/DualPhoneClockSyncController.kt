package com.maklertour.data.dualphone

import android.os.SystemClock
import android.util.Log
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch
import org.json.JSONObject
import java.io.Closeable
import java.net.DatagramPacket
import java.net.DatagramSocket
import java.net.InetAddress
import java.net.InetSocketAddress
import java.net.SocketTimeoutException
import java.nio.charset.StandardCharsets
import java.util.ArrayDeque
import java.util.Locale
import java.util.concurrent.atomic.AtomicLong

internal class DualPhoneClockSyncController(
    private val scope: CoroutineScope,
    private val onSnapshot: (DualPhoneClockSyncSnapshot) -> Unit,
    private val onStatusForSlave: (JSONObject) -> Unit,
) : Closeable {
    @Volatile
    private var snapshot = DualPhoneClockSyncSnapshot()

    @Volatile
    private var model: DualPhoneClockSyncModel? = null

    private val rounds = ArrayDeque<DualPhoneClockSyncRound>()
    private val probeSequence = AtomicLong(1L)
    private var consecutiveNonReadyRounds = 0
    private var job: Job? = null
    private var datagramSocket: DatagramSocket? = null

    fun currentSnapshot(): DualPhoneClockSyncSnapshot = snapshot

    fun masterToSlaveNs(masterElapsedNs: Long): Long? {
        val current = snapshot
        val updatedAt = current.updatedAtElapsedNs ?: return null
        val ageNs = SystemClock.elapsedRealtimeNanos() - updatedAt
        if (!current.captureSchedulingAllowed || ageNs > MAX_MODEL_AGE_NS) {
            return null
        }
        return model?.masterToSlaveNs(masterElapsedNs)
    }

    fun startMaster(
        peerHost: String,
        port: Int,
        dualCaptureId: String,
    ) {
        stop()
        publish(
            DualPhoneClockSyncSnapshot(
                quality = DualPhoneClockSyncQuality.SYNCING,
                message = "Starting UDP clock synchronization",
            ),
        )
        job = scope.launch {
            try {
                val address = InetAddress.getByName(peerHost)
                val socket = DatagramSocket().apply {
                    soTimeout = PROBE_TIMEOUT_MS
                    connect(InetSocketAddress(address, port))
                }
                datagramSocket = socket
                while (isActive && !socket.isClosed) {
                    val probeCount = if (model == null) {
                        INITIAL_PROBES
                    } else {
                        PERIODIC_PROBES
                    }
                    val samples = runMasterRound(
                        socket = socket,
                        dualCaptureId = dualCaptureId,
                        probeCount = probeCount,
                    )
                    val round = DualPhoneClockSyncMath.estimateRound(
                        samples = samples,
                        totalProbes = probeCount,
                    )
                    if (round != null) {
                        rounds.addLast(round)
                        while (rounds.size > MAX_HISTORY_ROUNDS) {
                            rounds.removeFirst()
                        }
                        val newModel = DualPhoneClockSyncMath.buildModel(
                            rounds.toList(),
                        )
                        if (newModel != null) {
                            val decision = DualPhoneClockSyncMath.stabilizeModel(
                                previous = model,
                                candidate = newModel,
                                consecutiveNonReadyRounds = consecutiveNonReadyRounds,
                            )
                            consecutiveNonReadyRounds =
                                decision.consecutiveNonReadyRounds
                            model = decision.model
                            val nowNs = SystemClock.elapsedRealtimeNanos()
                            val message = if (decision.retainedReadyQuality) {
                                "Transient ${newModel.quality.name} clock round " +
                                    "${decision.consecutiveNonReadyRounds}/" +
                                    "${DualPhoneClockSyncMath.REQUIRED_CONSECUTIVE_NON_READY_ROUNDS}; " +
                                    "keeping ${decision.model.quality.name} readiness"
                            } else {
                                "UDP clock model updated"
                            }
                            val next = decision.model.toSnapshot(
                                updatedAtElapsedNs = nowNs,
                                message = message,
                            )
                            logRound(
                                round = round,
                                responseCount = samples.size,
                                candidate = newModel,
                                decision = decision,
                            )
                            publish(next)
                            runCatching {
                                onStatusForSlave(statusPayload(next))
                            }
                        }
                    } else {
                        Log.w(
                            TAG,
                            "DP03_CLOCK_ROUND incomplete total=$probeCount " +
                                "responses=${samples.size}",
                        )
                        val previous = snapshot
                        val nowNs = SystemClock.elapsedRealtimeNanos()
                        val modelAgeNs = previous.updatedAtElapsedNs?.let {
                            nowNs - it
                        } ?: Long.MAX_VALUE
                        val next = when {
                            model == null -> previous.copy(
                                quality = DualPhoneClockSyncQuality.POOR,
                                ready = false,
                                acceptedSamples = samples.size,
                                totalSamples = probeCount,
                                updatedAtElapsedNs = nowNs,
                                message = "Too few UDP clock responses; retrying",
                            )
                            modelAgeNs > MAX_MODEL_AGE_NS -> previous.copy(
                                quality = DualPhoneClockSyncQuality.POOR,
                                ready = false,
                                message = "Clock model is stale; waiting for UDP responses",
                            )
                            else -> previous.copy(
                                message = "Clock sync round incomplete; keeping previous model",
                            )
                        }
                        publish(next)
                    }
                    delay(
                        if (model == null) {
                            INITIAL_RETRY_INTERVAL_MS
                        } else {
                            PERIODIC_SYNC_INTERVAL_MS
                        },
                    )
                }
            } catch (cancelled: CancellationException) {
                throw cancelled
            } catch (t: Throwable) {
                publishError(
                    "Clock sync Master failed: ${t.message ?: t.javaClass.simpleName}",
                )
            } finally {
                closeSocket()
            }
        }
    }

    fun startSlave(
        port: Int,
        expectedMasterHost: String,
        dualCaptureId: String,
    ) {
        stop()
        publish(
            DualPhoneClockSyncSnapshot(
                quality = DualPhoneClockSyncQuality.SYNCING,
                message = "Waiting for UDP clock probes on port $port",
            ),
        )
        job = scope.launch {
            try {
                val expectedAddress = InetAddress.getByName(expectedMasterHost)
                val socket = DatagramSocket(null).apply {
                    reuseAddress = true
                    soTimeout = SLAVE_RECEIVE_TIMEOUT_MS
                    bind(InetSocketAddress(port))
                }
                datagramSocket = socket
                val buffer = ByteArray(MAX_PACKET_BYTES)
                while (isActive && !socket.isClosed) {
                    val packet = DatagramPacket(buffer, buffer.size)
                    try {
                        socket.receive(packet)
                    } catch (_: SocketTimeoutException) {
                        markRemoteModelStaleIfNeeded()
                        continue
                    }
                    val t2SlaveNs = SystemClock.elapsedRealtimeNanos()
                    if (packet.address != expectedAddress) continue
                    val request = decodePacket(packet) ?: continue
                    if (request.optString("type") != TYPE_REQUEST) continue
                    if (request.optString("dual_capture_id") != dualCaptureId) continue
                    val probeId = request.optLong("probe_id", -1L)
                    val t1MasterNs = request.optLong("t1_master_ns", -1L)
                    if (probeId < 0L || t1MasterNs < 0L) continue

                    val response = JSONObject()
                        .put("version", PROTOCOL_VERSION)
                        .put("type", TYPE_RESPONSE)
                        .put("dual_capture_id", dualCaptureId)
                        .put("probe_id", probeId)
                        .put("t1_master_ns", t1MasterNs)
                        .put("t2_slave_ns", t2SlaveNs)
                    val t3SlaveNs = SystemClock.elapsedRealtimeNanos()
                    response.put("t3_slave_ns", t3SlaveNs)
                    val bytes = response.toString()
                        .toByteArray(StandardCharsets.UTF_8)
                    socket.send(
                        DatagramPacket(
                            bytes,
                            bytes.size,
                            packet.address,
                            packet.port,
                        ),
                    )
                }
            } catch (cancelled: CancellationException) {
                throw cancelled
            } catch (t: Throwable) {
                publishError(
                    "Clock sync Slave failed: ${t.message ?: t.javaClass.simpleName}",
                )
            } finally {
                closeSocket()
            }
        }
    }

    fun applyRemoteStatus(payload: JSONObject) {
        val quality = runCatching {
            DualPhoneClockSyncQuality.valueOf(
                payload.optString("quality", DualPhoneClockSyncQuality.ERROR.name),
            )
        }.getOrDefault(DualPhoneClockSyncQuality.ERROR)
        publish(
            DualPhoneClockSyncSnapshot(
                quality = quality,
                ready = payload.optBoolean("ready", quality.isReady),
                referenceMasterNs = payload.optNullableLong("reference_master_ns"),
                offsetNs = payload.optNullableLong("offset_ns"),
                medianRttNs = payload.optNullableLong("median_rtt_ns"),
                p95RttNs = payload.optNullableLong("p95_rtt_ns"),
                uncertaintyNs = payload.optNullableLong("uncertainty_ns"),
                driftPpm = payload.optNullableDouble("drift_ppm"),
                acceptedSamples = payload.optInt("accepted_samples", 0),
                totalSamples = payload.optInt("total_samples", 0),
                updatedAtElapsedNs = SystemClock.elapsedRealtimeNanos(),
                message = payload.optString(
                    "message",
                    "Clock status received from Master",
                ),
            ),
        )
    }

    fun stop() {
        job?.cancel()
        job = null
        closeSocket()
        rounds.clear()
        consecutiveNonReadyRounds = 0
        model = null
        publish(DualPhoneClockSyncSnapshot())
    }

    override fun close() {
        stop()
    }

    private suspend fun runMasterRound(
        socket: DatagramSocket,
        dualCaptureId: String,
        probeCount: Int,
    ): List<DualPhoneClockSyncSample> {
        val samples = mutableListOf<DualPhoneClockSyncSample>()
        repeat(probeCount) {
            val probeId = probeSequence.getAndIncrement()
            val t1MasterNs = SystemClock.elapsedRealtimeNanos()
            val request = JSONObject()
                .put("version", PROTOCOL_VERSION)
                .put("type", TYPE_REQUEST)
                .put("dual_capture_id", dualCaptureId)
                .put("probe_id", probeId)
                .put("t1_master_ns", t1MasterNs)
            val bytes = request.toString().toByteArray(StandardCharsets.UTF_8)
            socket.send(DatagramPacket(bytes, bytes.size))

            val buffer = ByteArray(MAX_PACKET_BYTES)
            val packet = DatagramPacket(buffer, buffer.size)
            try {
                socket.receive(packet)
                val t4MasterNs = SystemClock.elapsedRealtimeNanos()
                val response = decodePacket(packet)
                if (response != null &&
                    response.optString("type") == TYPE_RESPONSE &&
                    response.optString("dual_capture_id") == dualCaptureId &&
                    response.optLong("probe_id", -1L) == probeId
                ) {
                    samples += DualPhoneClockSyncSample(
                        t1MasterNs = response.optLong(
                            "t1_master_ns",
                            t1MasterNs,
                        ),
                        t2SlaveNs = response.getLong("t2_slave_ns"),
                        t3SlaveNs = response.getLong("t3_slave_ns"),
                        t4MasterNs = t4MasterNs,
                    )
                }
            } catch (_: SocketTimeoutException) {
                // Missing UDP packets are expected and are represented in totalProbes.
            }
            delay(PROBE_INTERVAL_MS)
        }
        return samples
    }

    private fun logRound(
        round: DualPhoneClockSyncRound,
        responseCount: Int,
        candidate: DualPhoneClockSyncModel,
        decision: DualPhoneClockSyncStabilityDecision,
    ) {
        val missing = (round.totalSamples - responseCount).coerceAtLeast(0)
        val invalid = (responseCount - round.validSamples).coerceAtLeast(0)
        val offsetDeltas = round.acceptedOffsetNs.map { it - round.offsetNs }
        Log.i(
            TAG,
            "DP03_CLOCK_ROUND total=${round.totalSamples} " +
                "responses=$responseCount valid=${round.validSamples} " +
                "accepted=${round.acceptedSamples} missing=$missing invalid=$invalid " +
                "candidate=${candidate.quality.name} " +
                "applied=${decision.model.quality.name} " +
                "retained_ready=${decision.retainedReadyQuality} " +
                "non_ready_streak=${decision.consecutiveNonReadyRounds} " +
                "median_rtt_ms=${formatNsMs(round.medianRttNs)} " +
                "p95_rtt_ms=${formatNsMs(round.p95RttNs)} " +
                "uncertainty_ms=${formatNsMs(round.uncertaintyNs)} " +
                "drift_ppm=${String.format(Locale.US, "%+.3f", candidate.driftPpm)} " +
                "accepted_rtt_ms=${formatNsListMs(round.acceptedRttNs)} " +
                "rejected_rtt_ms=${formatNsListMs(round.rejectedRttNs)} " +
                "accepted_offset_delta_ms=${formatNsListMs(offsetDeltas)}",
        )
    }

    private fun formatNsMs(value: Long): String =
        String.format(Locale.US, "%.3f", value.toDouble() / 1_000_000.0)

    private fun formatNsListMs(values: List<Long>): String = values.joinToString(
        prefix = "[",
        postfix = "]",
    ) { formatNsMs(it) }

    private fun statusPayload(value: DualPhoneClockSyncSnapshot): JSONObject =
        JSONObject()
            .put("quality", value.quality.name)
            .put("ready", value.ready)
            .putNullable("reference_master_ns", value.referenceMasterNs)
            .putNullable("offset_ns", value.offsetNs)
            .putNullable("median_rtt_ns", value.medianRttNs)
            .putNullable("p95_rtt_ns", value.p95RttNs)
            .putNullable("uncertainty_ns", value.uncertaintyNs)
            .putNullable("drift_ppm", value.driftPpm)
            .put("accepted_samples", value.acceptedSamples)
            .put("total_samples", value.totalSamples)
            .put("message", value.message)

    private fun markRemoteModelStaleIfNeeded() {
        val updatedAt = snapshot.updatedAtElapsedNs ?: return
        val ageNs = SystemClock.elapsedRealtimeNanos() - updatedAt
        if (ageNs <= MAX_MODEL_AGE_NS || !snapshot.ready) return
        publish(
            snapshot.copy(
                quality = DualPhoneClockSyncQuality.POOR,
                ready = false,
                message = "Clock model is stale; waiting for Master resync",
            ),
        )
    }

    private fun publish(value: DualPhoneClockSyncSnapshot) {
        snapshot = value
        onSnapshot(value)
    }

    private fun publishError(message: String) {
        publish(
            snapshot.copy(
                quality = DualPhoneClockSyncQuality.ERROR,
                ready = false,
                updatedAtElapsedNs = SystemClock.elapsedRealtimeNanos(),
                message = message,
            ),
        )
    }

    private fun closeSocket() {
        runCatching { datagramSocket?.close() }
        datagramSocket = null
    }

    private fun decodePacket(packet: DatagramPacket): JSONObject? = runCatching {
        JSONObject(
            String(
                packet.data,
                packet.offset,
                packet.length,
                StandardCharsets.UTF_8,
            ),
        )
    }.getOrNull()

    private fun JSONObject.putNullable(key: String, value: Any?): JSONObject =
        put(key, value ?: JSONObject.NULL)

    private fun JSONObject.optNullableLong(key: String): Long? =
        if (!has(key) || isNull(key)) null else optLong(key)

    private fun JSONObject.optNullableDouble(key: String): Double? =
        if (!has(key) || isNull(key)) null else optDouble(key)

    companion object {
        private const val TAG = "DualPhoneClockSync"
        private const val PROTOCOL_VERSION = 1
        private const val TYPE_REQUEST = "CLOCK_SYNC_REQUEST"
        private const val TYPE_RESPONSE = "CLOCK_SYNC_RESPONSE"
        private const val INITIAL_PROBES = 16
        private const val PERIODIC_PROBES = 12
        private const val PROBE_TIMEOUT_MS = 250
        private const val SLAVE_RECEIVE_TIMEOUT_MS = 1_000
        private const val PROBE_INTERVAL_MS = 60L
        private const val INITIAL_RETRY_INTERVAL_MS = 1_000L
        private const val PERIODIC_SYNC_INTERVAL_MS = 10_000L
        private const val MAX_MODEL_AGE_NS = 30_000_000_000L
        private const val MAX_HISTORY_ROUNDS = 12
        private const val MAX_PACKET_BYTES = 2_048
    }
}
