package com.maklertour.data.phonecamera

import android.content.Context
import android.hardware.Sensor
import android.hardware.SensorEvent
import android.hardware.camera2.CaptureResult
import android.hardware.camera2.TotalCaptureResult
import android.os.SystemClock
import android.util.Log
import com.maklertour.data.tof.TofActiveClockSync
import com.maklertour.data.tof.TofUsbRuntime
import java.util.ArrayDeque
import kotlin.math.abs

/**
 * LM03.3.2A diagnostic bridge.
 *
 * This does not change capture timing. It only projects the latest ToF IRQ timestamp
 * into Android elapsed-realtime using the accepted active RP2040 clock fit and logs
 * how Camera2, IMU and ToF line up on the local phone timeline.
 */
object SensorTimelineDiagnostics {
    private const val TAG = "SensorTimeline"
    private const val LOG_EVERY_CAMERA_FRAMES = 30L
    private const val MAX_IMU_SAMPLES = 128

    private val lock = Any()
    private val gyroTimestampsNs = ArrayDeque<Long>()
    private val accelTimestampsNs = ArrayDeque<Long>()
    private var cameraFrames = 0L

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

    private fun deltaUs(
        valueNs: Long,
        referenceNs: Long,
    ): Long = (valueNs - referenceNs) / 1000L

    private fun deltaUsOrDash(
        valueNs: Long?,
        referenceNs: Long,
    ): Any = valueNs?.let { deltaUs(it, referenceNs) } ?: "-"

    private data class Snapshot(
        val frameIndex: Long,
        val gyroTimestampNs: Long?,
        val accelTimestampNs: Long?,
    )
}
