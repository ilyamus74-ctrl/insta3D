package com.maklertour.data.phonecamera

import android.content.Context
import android.hardware.Sensor
import android.hardware.SensorEvent
import android.hardware.camera2.CaptureResult
import android.hardware.camera2.TotalCaptureResult
import android.os.SystemClock
import android.util.Log
import com.maklertour.data.tof.TofActiveClockSync
import com.maklertour.data.tof.TofFrameV1
import com.maklertour.data.tof.TofUsbRuntime
import java.util.ArrayDeque
import kotlin.math.abs
import kotlin.math.roundToInt

/**
 * LM03.3.2 diagnostic bridge.
 *
 * LM03.3.2B pairs each mapped CAMERA_A event with the nearest mapped ToF event
 * from a bounded raw-frame history. No hard pairing threshold is applied until
 * the real-device nearest-event distribution is measured.
 */
object SensorTimelineDiagnostics {
    private const val TAG = "SensorTimeline"
    private const val LOG_EVERY_CAMERA_FRAMES = 30L
    private const val MAX_IMU_SAMPLES = 128
    private const val MAX_PAIRING_SAMPLES = 512
    private const val PAIRING_MARGIN_US = 2_000L

    private val lock = Any()
    private val gyroTimestampsNs = ArrayDeque<Long>()
    private val accelTimestampsNs = ArrayDeque<Long>()
    private val pairingAbsDeltaUs = ArrayDeque<Long>()
    private var cameraFrames = 0L
    private var pairedCameraFrames = 0L
    private var rejectedCameraFrames = 0L
    private var unpairedCameraFrames = 0L

    fun observeImu(event: SensorEvent) {
        val target = when (event.sensor.type) {
            Sensor.TYPE_GYROSCOPE -> gyroTimestampsNs
            Sensor.TYPE_ACCELEROMETER -> accelTimestampsNs
            else -> return
        }

        synchronized(lock) {
            target.addLast(event.timestamp)
            while (target.size > MAX_IMU_SAMPLES) {
                target.removeFirst()
            }
        }
    }

    fun onCameraCapture(
        context: Context,
        result: TotalCaptureResult,
    ) {
        val cameraTimestampNs =
            result.get(CaptureResult.SENSOR_TIMESTAMP) ?: return
        val receiveElapsedNs = SystemClock.elapsedRealtimeNanos()

        val snapshot = synchronized(lock) {
            cameraFrames += 1L
            if (
                cameraFrames != 1L &&
                cameraFrames % LOG_EVERY_CAMERA_FRAMES != 0L
            ) {
                return
            }

            Snapshot(
                frameIndex = cameraFrames,
                gyroTimestampNs = nearestTimestamp(
                    gyroTimestampsNs,
                    cameraTimestampNs,
                ),
                accelTimestampNs = nearestTimestamp(
                    accelTimestampsNs,
                    cameraTimestampNs,
                ),
            )
        }

        val tofFrame = TofUsbRuntime.get(context).latestFrame.value
        val tofElapsedNs = tofFrame?.let { frame ->
            TofActiveClockSync.mapRp2040TimestampUsToHostElapsedNs(
                frame.rp2040TimestampUs,
            )
        }

        Log.i(
            TAG,
            "SENSOR_TIMELINE frame=${snapshot.frameIndex} " +
                "cam=$cameraTimestampNs " +
                "camRecvDeltaUs=${deltaUs(receiveElapsedNs, cameraTimestampNs)} " +
                "tof=${tofElapsedNs ?: "-"} " +
                "tofDeltaUs=${deltaUsOrDash(tofElapsedNs, cameraTimestampNs)} " +
                "tofSeq=${tofFrame?.sequence ?: "-"} " +
                "gyro=${snapshot.gyroTimestampNs ?: "-"} " +
                "gyroDeltaUs=${deltaUsOrDash(snapshot.gyroTimestampNs, cameraTimestampNs)} " +
                "accel=${snapshot.accelTimestampNs ?: "-"} " +
                "accelDeltaUs=${deltaUsOrDash(snapshot.accelTimestampNs, cameraTimestampNs)} " +
                "tofClock=${if (tofElapsedNs != null) "READY" else "WARMING_UP"}",
        )
    }

    fun onMappedCameraFrame(
        context: Context,
        cameraElapsedRealtimeNs: Long,
        rawCameraTimestampNs: Long,
        cameraTimestampSource: String,
        receiveElapsedRealtimeNs: Long,
    ) {
        val tofRuntime = TofUsbRuntime.get(context)
        val tofHistory = tofRuntime.recentFramesSnapshot()
        val tofPair = nearestMappedTof(
            frames = tofHistory,
            cameraElapsedRealtimeNs = cameraElapsedRealtimeNs,
        )
        val tofPairThresholdUs = pairingThresholdUs(tofPair)
        val tofAccepted =
            tofPair != null &&
                tofPairThresholdUs != null &&
                tofPair.absDeltaUs <= tofPairThresholdUs

        val snapshot = synchronized(lock) {
            cameraFrames += 1L

            if (tofPair != null) {
                pairingAbsDeltaUs.addLast(tofPair.absDeltaUs)
                while (pairingAbsDeltaUs.size > MAX_PAIRING_SAMPLES) {
                    pairingAbsDeltaUs.removeFirst()
                }

                if (tofAccepted) {
                    pairedCameraFrames += 1L
                } else {
                    rejectedCameraFrames += 1L
                }
            } else {
                unpairedCameraFrames += 1L
            }

            if (
                cameraFrames != 1L &&
                cameraFrames % LOG_EVERY_CAMERA_FRAMES != 0L
            ) {
                return
            }

            val sortedPairingUs = pairingAbsDeltaUs.toList().sorted()
            Snapshot(
                frameIndex = cameraFrames,
                gyroTimestampNs = nearestTimestamp(
                    gyroTimestampsNs,
                    cameraElapsedRealtimeNs,
                ),
                accelTimestampNs = nearestTimestamp(
                    accelTimestampsNs,
                    cameraElapsedRealtimeNs,
                ),
                pairedCount = pairedCameraFrames,
                rejectedCount = rejectedCameraFrames,
                unpairedCount = unpairedCameraFrames,
                pairingP50Us = percentileOrNull(sortedPairingUs, 0.50),
                pairingP95Us = percentileOrNull(sortedPairingUs, 0.95),
                pairingP99Us = percentileOrNull(sortedPairingUs, 0.99),
            )
        }

        Log.i(
            TAG,
            "SENSOR_TIMELINE source=IMAGE_ANALYSIS frame=${snapshot.frameIndex} " +
                "cam=$cameraElapsedRealtimeNs " +
                "camRaw=$rawCameraTimestampNs " +
                "camSource=$cameraTimestampSource " +
                "camRecvDeltaUs=${deltaUs(receiveElapsedRealtimeNs, cameraElapsedRealtimeNs)} " +
                "tof=${tofPair?.mappedElapsedRealtimeNs ?: "-"} " +
                "tofDeltaUs=${tofPair?.signedDeltaUs ?: "-"} " +
                "tofAbsDeltaUs=${tofPair?.absDeltaUs ?: "-"} " +
                "tofSeq=${tofPair?.sequence ?: "-"} " +
                "tofHistory=${tofHistory.size} " +
                "tofAccepted=$tofAccepted " +
                "paired=${snapshot.pairedCount} " +
                "rejected=${snapshot.rejectedCount} " +
                "unpaired=${snapshot.unpairedCount} " +
                "tofP50Us=${snapshot.pairingP50Us ?: "-"} " +
                "tofP95Us=${snapshot.pairingP95Us ?: "-"} " +
                "tofP99Us=${snapshot.pairingP99Us ?: "-"} " +
                "gyro=${snapshot.gyroTimestampNs ?: "-"} " +
                "gyroDeltaUs=${deltaUsOrDash(snapshot.gyroTimestampNs, cameraElapsedRealtimeNs)} " +
                "accel=${snapshot.accelTimestampNs ?: "-"} " +
                "accelDeltaUs=${deltaUsOrDash(snapshot.accelTimestampNs, cameraElapsedRealtimeNs)} " +
                "tofClock=${if (tofPair != null) "READY" else "WARMING_UP"} " +
                "tofPairThresholdUs=${tofPairThresholdUs ?: "-"}",
        )
    }

    private fun nearestMappedTof(
        frames: List<TofFrameV1>,
        cameraElapsedRealtimeNs: Long,
    ): TofPair? {
        var best: TofPair? = null

        for (frame in frames) {
            if (!frame.irqTimestampValid) continue
            val mappedNs =
                TofActiveClockSync.mapRp2040TimestampUsToHostElapsedNs(
                    frame.rp2040TimestampUs,
                ) ?: continue
            val signedDeltaNs = mappedNs - cameraElapsedRealtimeNs
            val absDeltaNs = abs(signedDeltaNs)
            val current = best
            if (current == null || absDeltaNs < current.absDeltaNs) {
                best = TofPair(
                    sequence = frame.sequence,
                    mappedElapsedRealtimeNs = mappedNs,
                    signedDeltaNs = signedDeltaNs,
                    absDeltaNs = absDeltaNs,
                    frequencyHz = frame.frequencyHz,
                )
            }
        }

        return best
    }

    private fun pairingThresholdUs(pair: TofPair?): Long? {
        val frequencyHz = pair?.frequencyHz?.takeIf { it > 0 } ?: return null
        return 500_000L / frequencyHz + PAIRING_MARGIN_US
    }

    private fun nearestTimestamp(
        samples: ArrayDeque<Long>,
        targetNs: Long,
    ): Long? {
        var nearest: Long? = null
        var nearestDistance = Long.MAX_VALUE
        for (sample in samples) {
            val distance = abs(sample - targetNs)
            if (distance < nearestDistance) {
                nearestDistance = distance
                nearest = sample
            }
        }
        return nearest
    }

    private fun percentileOrNull(
        sorted: List<Long>,
        fraction: Double,
    ): Long? {
        if (sorted.isEmpty()) return null
        val index = ((sorted.size - 1) * fraction)
            .roundToInt()
            .coerceIn(0, sorted.lastIndex)
        return sorted[index]
    }

    private fun deltaUs(
        valueNs: Long,
        referenceNs: Long,
    ): Long = (valueNs - referenceNs) / 1000L

    private fun deltaUsOrDash(
        valueNs: Long?,
        referenceNs: Long,
    ): Any = valueNs?.let { deltaUs(it, referenceNs) } ?: "-"

    private data class TofPair(
        val sequence: Long,
        val mappedElapsedRealtimeNs: Long,
        val signedDeltaNs: Long,
        val absDeltaNs: Long,
        val frequencyHz: Int,
    ) {
        val signedDeltaUs: Long
            get() = signedDeltaNs / 1000L
        val absDeltaUs: Long
            get() = absDeltaNs / 1000L
    }

    private data class Snapshot(
        val frameIndex: Long,
        val gyroTimestampNs: Long?,
        val accelTimestampNs: Long?,
        val pairedCount: Long = 0L,
        val rejectedCount: Long = 0L,
        val unpairedCount: Long = 0L,
        val pairingP50Us: Long? = null,
        val pairingP95Us: Long? = null,
        val pairingP99Us: Long? = null,
    )
}
