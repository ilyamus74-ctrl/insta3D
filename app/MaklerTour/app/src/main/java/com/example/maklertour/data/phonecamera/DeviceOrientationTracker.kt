package com.maklertour.data.phonecamera

import android.content.Context
import android.hardware.Sensor
import android.hardware.SensorEvent
import android.hardware.SensorEventListener
import android.hardware.SensorManager
import kotlin.math.PI
import kotlin.math.abs
import kotlin.math.atan2
import kotlin.math.hypot
import kotlin.math.sqrt

data class DeviceOrientationClassification(
    val physicalOrientation: String,
    val rollDeg: Float?,
    val pitchDeg: Float?,
    val confidence: Double,
)

data class DeviceImageUp(
    val direction: String,
    val x: Double?,
    val y: Double?,
)

object DeviceOrientationMath {
    fun classify(
        x: Float,
        y: Float,
        z: Float,
    ): DeviceOrientationClassification {
        val magnitude = sqrt((x * x + y * y + z * z).toDouble())
        if (!magnitude.isFinite() || magnitude < 2.0) {
            return DeviceOrientationClassification(
                physicalOrientation = "unknown",
                rollDeg = null,
                pitchDeg = null,
                confidence = 0.0,
            )
        }

        val nx = x / magnitude
        val ny = y / magnitude
        val nz = z / magnitude
        val ax = abs(nx)
        val ay = abs(ny)
        val az = abs(nz)
        val dominant = maxOf(ax, ay, az)
        val orientation = when {
            az >= 0.72 -> if (z > 0f) "face_up" else "face_down"
            ay >= ax -> if (y > 0f) "portrait_upright" else "portrait_upside_down"
            x > 0f -> "landscape_right"
            else -> "landscape_left"
        }

        val roll = atan2(nx, nz) * 180.0 / PI
        val pitch = atan2(-ny, hypot(nx, nz)) * 180.0 / PI

        return DeviceOrientationClassification(
            physicalOrientation = orientation,
            rollDeg = roll.toFloat(),
            pitchDeg = pitch.toFloat(),
            confidence = dominant.coerceIn(0.0, 1.0),
        )
    }

    /**
     * TYPE_GRAVITY points toward world-up in Android device coordinates.
     * This maps that vector into saved-image coordinates, where +X is right
     * and +Y is down.
     */
    fun imageUp(
        x: Float?,
        y: Float?,
        displayRotationDegrees: Int,
    ): DeviceImageUp {
        if (x == null || y == null) return DeviceImageUp("unknown", null, null)

        val mapped = when (normalizeRotation(displayRotationDegrees)) {
            90 -> y.toDouble() to x.toDouble()
            180 -> -x.toDouble() to y.toDouble()
            270 -> -y.toDouble() to -x.toDouble()
            else -> x.toDouble() to -y.toDouble()
        }
        val length = hypot(mapped.first, mapped.second)
        if (!length.isFinite() || length < 1.0) {
            return DeviceImageUp("unknown", null, null)
        }

        val nx = mapped.first / length
        val ny = mapped.second / length
        val direction = if (abs(nx) > abs(ny)) {
            if (nx > 0.0) "right" else "left"
        } else {
            if (ny > 0.0) "bottom" else "top"
        }
        return DeviceImageUp(direction, nx, ny)
    }

    private fun normalizeRotation(degrees: Int): Int =
        ((degrees % 360) + 360) % 360
}

/** Tracks physical device orientation from gravity, with accelerometer fallback. */
class DeviceOrientationTracker(context: Context) : SensorEventListener {
    data class Sample(
        val timestampNs: Long = 0L,
        val gravityX: Float? = null,
        val gravityY: Float? = null,
        val gravityZ: Float? = null,
        val rollDeg: Float? = null,
        val pitchDeg: Float? = null,
        val physicalOrientation: String = "unknown",
        val source: String = "unknown",
        val confidence: Double = 0.0,
        val stale: Boolean = true,
        val sampleDeltaMs: Double? = null,
    )

    private val sensorManager = context.applicationContext
        .getSystemService(Context.SENSOR_SERVICE) as SensorManager
    private val gravitySensor = sensorManager.getDefaultSensor(Sensor.TYPE_GRAVITY)
    private val accelerometerSensor =
        sensorManager.getDefaultSensor(Sensor.TYPE_ACCELEROMETER)
    private val samples = ArrayDeque<Sample>()
    private val lock = Any()
    private var activeSensor: Sensor? = null

    fun start() {
        if (activeSensor != null) return
        synchronized(lock) { samples.clear() }
        val sensor = gravitySensor ?: accelerometerSensor ?: return
        activeSensor = sensor
        sensorManager.registerListener(
            this,
            sensor,
            SensorManager.SENSOR_DELAY_GAME,
        )
    }

    fun stop() {
        sensorManager.unregisterListener(this)
        activeSensor = null
        synchronized(lock) { samples.clear() }
    }

    fun nearestSample(
        timestampNs: Long,
        maxDeltaMs: Long = 500L,
    ): Sample {
        val best = synchronized(lock) {
            samples.minByOrNull { abs(it.timestampNs - timestampNs) }
        } ?: return Sample(sampleDeltaMs = null)

        val deltaMs = abs(best.timestampNs - timestampNs) / 1_000_000.0
        return if (deltaMs > maxDeltaMs) {
            best.copy(
                physicalOrientation = "unknown",
                source = "unknown",
                stale = true,
                sampleDeltaMs = deltaMs,
            )
        } else {
            best.copy(stale = false, sampleDeltaMs = deltaMs)
        }
    }

    override fun onSensorChanged(event: SensorEvent) {
        if (
            event.sensor.type != Sensor.TYPE_GRAVITY
            && event.sensor.type != Sensor.TYPE_ACCELEROMETER
        ) return

        val x = event.values.getOrNull(0) ?: return
        val y = event.values.getOrNull(1) ?: return
        val z = event.values.getOrNull(2) ?: return
        val classification = DeviceOrientationMath.classify(x, y, z)
        val source = if (event.sensor.type == Sensor.TYPE_GRAVITY) {
            "imu_gravity"
        } else {
            "imu_accelerometer"
        }

        val sample = Sample(
            timestampNs = event.timestamp,
            gravityX = x,
            gravityY = y,
            gravityZ = z,
            rollDeg = classification.rollDeg,
            pitchDeg = classification.pitchDeg,
            physicalOrientation = classification.physicalOrientation,
            source = source,
            confidence = classification.confidence,
            stale = false,
            sampleDeltaMs = 0.0,
        )
        synchronized(lock) {
            samples.addLast(sample)
            while (samples.size > MAX_SAMPLES) samples.removeFirst()
        }
    }

    override fun onAccuracyChanged(sensor: Sensor?, accuracy: Int) = Unit

    private companion object {
        const val MAX_SAMPLES = 300
    }
}