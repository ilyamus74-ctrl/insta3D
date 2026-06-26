package com.maklertour.data.phonecamera

import android.content.Context
import android.hardware.Sensor
import android.hardware.SensorEvent
import android.hardware.SensorEventListener
import android.hardware.SensorManager
import org.json.JSONArray
import org.json.JSONObject
import java.io.BufferedWriter
import java.io.File
import android.os.SystemClock

class ImuRecorder(context: Context) : SensorEventListener {
    private val sensorManager = context.getSystemService(SensorManager::class.java)
    private var writer: BufferedWriter? = null
    private var videoStartNs: Long = 0L

    fun start(sessionId: String, scanId: String, baseDir: File, videoStartTNs: Long = SystemClock.elapsedRealtimeNanos()): File {
        val file = File(baseDir, "imu.jsonl")
        videoStartNs = videoStartTNs
        writer = file.bufferedWriter()
        writer?.apply {
            write(JSONObject().put("type", "metadata").put("schema_version", 2).put("video_start_t_ns", videoStartNs).put("imu_start_t_ns", SystemClock.elapsedRealtimeNanos()).put("clock", "CLOCK_BOOTTIME").toString())
            newLine()
        }
        listOf(Sensor.TYPE_GYROSCOPE, Sensor.TYPE_ACCELEROMETER, Sensor.TYPE_GRAVITY, Sensor.TYPE_ROTATION_VECTOR).forEach { type ->
            sensorManager.getDefaultSensor(type)?.also { sensor ->
                sensorManager.registerListener(this, sensor, SensorManager.SENSOR_DELAY_GAME)
            }
        }
        return file
    }

    fun stop() {
        sensorManager.unregisterListener(this)
        writer?.flush()
        writer?.close()
        writer = null
    }

    override fun onSensorChanged(event: SensorEvent) {
        val sensorName = when (event.sensor.type) {
            Sensor.TYPE_GYROSCOPE -> "gyro"
            Sensor.TYPE_ACCELEROMETER -> "accel"
            Sensor.TYPE_GRAVITY -> "gravity"
            Sensor.TYPE_ROTATION_VECTOR -> "rotation_vector"
            else -> return
        }
        val values = JSONArray()
        event.values.forEach { values.put(it.toDouble()) }
        val line = JSONObject()
            .put("t_ns", event.timestamp)
            .put("video_t_sec", (event.timestamp - videoStartNs).toDouble() / 1_000_000_000.0)
            .put("sensor", sensorName)
            .put("values", values)
            .toString()
        writer?.apply { write(line); newLine() }
    }

    override fun onAccuracyChanged(sensor: Sensor?, accuracy: Int) = Unit
}
