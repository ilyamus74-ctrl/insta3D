package com.maklertour.data.phonecamera

import android.content.Context
import android.hardware.Sensor
import android.hardware.SensorEvent
import android.hardware.SensorEventListener
import android.hardware.SensorManager
import kotlin.math.abs
import kotlin.math.max

/** Tracks the device's physical orientation from IMU gravity/accelerometer data.
 *
 * Android sensor axes are used directly: +X points to the device right edge, +Y to the top edge,
 * and +Z out of the screen. At rest, a screen-up phone reports approximately +9.81 on Z.
 */
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

    private val sensorManager = context.applicationContext.getSystemService(Context.SENSOR_SERVICE) as SensorManager
    private val gravitySensor = sensorManager.getDefaultSensor(Sensor.TYPE_GRAVITY)
    private val accelerometerSensor = sensorManager.getDefaultSensor(Sensor.TYPE_ACCELEROMETER)
    private val samples = ArrayDeque<Sample>()
    private val lock = Any()
    private var activeSensor: Sensor? = null

    fun start() {
        if (activeSensor != null) return
        val sensor = gravitySensor ?: accelerometerSensor ?: return
        activeSensor = sensor
        sensorManager.registerListener(this, sensor, SensorManager.SENSOR_DELAY_UI)
    }

    fun stop() {
        sensorManager.unregisterListener(this)
        activeSensor = null
    }

    fun nearestSample(timestampNs: Long, maxDeltaMs: Long = 1_000L): Sample {
        val best = synchronized(lock) {
            samples.minByOrNull { kotlin.math.abs(it.timestampNs - timestampNs) }
        } ?: return Sample(sampleDeltaMs = null)
        val deltaMs = kotlin.math.abs(best.timestampNs - timestampNs) / 1_000_000.0
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
        if (event.sensor.type != Sensor.TYPE_GRAVITY && event.sensor.type != Sensor.TYPE_ACCELEROMETER) return
        val x = event.values.getOrNull(0) ?: return
        val y = event.values.getOrNull(1) ?: return
        val z = event.values.getOrNull(2) ?: return
        val ax = abs(x)
        val ay = abs(y)
        val az = abs(z)
        val maxAxis = max(ax, max(ay, az))
        val orientation = when {
            az > 7.0f && az > ax && az > ay -> if (z > 0f) "face_up" else "face_down"
            ax > ay -> if (x > 0f) "landscape_right" else "landscape_left"
            else -> if (y > 0f) "portrait_upright" else "portrait_upside_down"
        }
        val source = if (event.sensor.type == Sensor.TYPE_GRAVITY) "imu_gravity" else "imu_accelerometer"
        val sample = Sample(
            timestampNs = event.timestamp,
            gravityX = x,
            gravityY = y,
            gravityZ = z,
            physicalOrientation = orientation,
            source = source,
            confidence = (maxAxis / 9.81f).toDouble().coerceIn(0.0, 1.5),
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