package com.maklertour.data.phonecamera

import android.content.Context
import android.hardware.Sensor
import android.hardware.SensorEvent
import android.hardware.SensorEventListener
import android.hardware.SensorManager
import android.os.SystemClock
import org.json.JSONArray
import org.json.JSONObject
import java.io.BufferedWriter
import java.io.File

class ImuRecorder(context: Context) : SensorEventListener {

    private val sensorManager =
        context.getSystemService(SensorManager::class.java)

    private var writer: BufferedWriter? = null
    private var videoStartNs: Long = 0L
    private var imuStartNs: Long = 0L

    fun start(
        sessionId: String,
        scanId: String,
        baseDir: File,
        videoStartTNs: Long = SystemClock.elapsedRealtimeNanos()
    ): File {
        val file = File(baseDir, "imu.jsonl")

        videoStartNs = videoStartTNs
        imuStartNs = SystemClock.elapsedRealtimeNanos()

        writer = file.bufferedWriter()

        val metadata = JSONObject()
            .put("type", "metadata")
            .put("schema_version", 2)
            .put("session_id", sessionId)
            .put("scan_id", scanId)
            .put("video_start_t_ns", videoStartNs)
            .put("imu_start_t_ns", imuStartNs)
            .put("clock", "CLOCK_BOOTTIME")
            .toString()

        writer?.apply {
            write(metadata)
            newLine()
            flush()
        }

        listOf(
            Sensor.TYPE_GYROSCOPE,
            Sensor.TYPE_ACCELEROMETER,
            Sensor.TYPE_GRAVITY,
            Sensor.TYPE_ROTATION_VECTOR
        ).forEach { type ->
            sensorManager.getDefaultSensor(type)?.also { sensor ->
                sensorManager.registerListener(
                    this,
                    sensor,
                    SensorManager.SENSOR_DELAY_GAME
                )
            }
        }

        return file
    }

    fun stop() {
        sensorManager.unregisterListener(this)

        writer?.apply {
            flush()
            close()
        }

        writer = null
    }

    override fun onSensorChanged(event: SensorEvent) {
        val activeWriter = writer ?: return

        val sensorName = when (event.sensor.type) {
            Sensor.TYPE_GYROSCOPE -> "gyro"
            Sensor.TYPE_ACCELEROMETER -> "accel"
            Sensor.TYPE_GRAVITY -> "gravity"
            Sensor.TYPE_ROTATION_VECTOR -> "rotation_vector"
            else -> return
        }

        SensorTimelineDiagnostics.observeImu(event)

        val values = JSONArray()
        event.values.forEach { value ->
            values.put(value.toDouble())
        }

        val videoTimeSec =
            (event.timestamp - videoStartNs).toDouble() /
                1_000_000_000.0

        val line = JSONObject()
            .put("t_ns", event.timestamp)
            .put("video_t_sec", videoTimeSec)
            .put("sensor", sensorName)
            .put("values", values)
            .toString()

        activeWriter.apply {
            write(line)
            newLine()
        }
    }

    override fun onAccuracyChanged(
        sensor: Sensor?,
        accuracy: Int
    ) = Unit
}