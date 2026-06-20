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

class ImuRecorder(context: Context) : SensorEventListener {
    private val sensorManager = context.getSystemService(SensorManager::class.java)
    private var writer: BufferedWriter? = null

    fun start(sessionId: String, scanId: String, baseDir: File): File {
        val file = File(baseDir, "imu.jsonl")
        writer = file.bufferedWriter()
        listOf(Sensor.TYPE_GYROSCOPE, Sensor.TYPE_ACCELEROMETER, Sensor.TYPE_ROTATION_VECTOR).forEach { type ->
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
            Sensor.TYPE_ROTATION_VECTOR -> "rotation_vector"
            else -> return
        }
        val values = JSONArray()
        event.values.forEach { values.put(it.toDouble()) }
        val line = JSONObject()
            .put("t_ns", event.timestamp)
            .put("sensor", sensorName)
            .put("values", values)
            .toString()
        writer?.apply { write(line); newLine() }
    }

    override fun onAccuracyChanged(sensor: Sensor?, accuracy: Int) = Unit
}
