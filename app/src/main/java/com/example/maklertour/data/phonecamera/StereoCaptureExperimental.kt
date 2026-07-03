package com.maklertour.data.phonecamera

import android.content.Context
import android.hardware.Sensor
import android.hardware.SensorEvent
import android.hardware.SensorEventListener
import android.hardware.SensorManager
import android.hardware.usb.UsbConstants
import android.hardware.usb.UsbDevice
import android.hardware.usb.UsbManager
import android.os.Build
import android.os.SystemClock
import androidx.camera.view.PreviewView
import androidx.lifecycle.LifecycleOwner
import com.maklertour.BuildConfig
import org.json.JSONArray
import org.json.JSONObject
import java.io.File
import java.time.Instant
import java.time.format.DateTimeFormatter
import java.util.UUID

/** Experimental, isolated stereo capture MVP for a phone camera + external USB UVC rig. */
data class StereoRigConfig(
    val rigId: String = "phone_usb_v1",
    val cam0Label: String = "phone_back_0_5x",
    val cam1Label: String = "usb_uvc",
    val baselineMm: Double,
    val horizontalOffsetMm: Double? = null,
    val verticalOffsetMm: Double? = null,
    val depthOffsetMm: Double? = null,
    val cam1YawDeg: Double? = null,
    val cam1PitchDeg: Double? = null,
    val cam1RollDeg: Double? = null,
)

enum class UsbUvcStatus { NOT_CONNECTED, CONNECTED, ACTIVE, ERROR }

data class UsbUvcCameraInfo(val status: UsbUvcStatus, val vendorId: Int? = null, val productId: Int? = null, val deviceName: String? = null, val error: String? = null)

data class StereoCaptureValidation(val ok: Boolean, val errors: List<String>, val bundleDir: File)

interface StereoCaptureUploader {
    suspend fun exportOrUpload(orderId: String?, captureSessionId: String, bundleDir: File): Result<File>
}

class LocalStereoCaptureExporter(private val context: Context) : StereoCaptureUploader {
    override suspend fun exportOrUpload(orderId: String?, captureSessionId: String, bundleDir: File): Result<File> = Result.success(bundleDir)
}

class StereoCaptureExperimentalManager(context: Context, lifecycleOwner: LifecycleOwner) {
    private val appContext = context.applicationContext
    private val phoneRecorder = PhoneCameraVideoRecorder(appContext, lifecycleOwner)
    private val imuRecorder = StereoImuJsonlRecorder(appContext)
    private var active: ActiveStereoCapture? = null

    fun detectUsbUvcCamera(): UsbUvcCameraInfo {
        val usbManager = appContext.getSystemService(UsbManager::class.java) ?: return UsbUvcCameraInfo(UsbUvcStatus.ERROR, error = "UsbManager unavailable")
        val device = usbManager.deviceList.values.firstOrNull { it.looksLikeVideoDevice() }
        return if (device == null) UsbUvcCameraInfo(UsbUvcStatus.NOT_CONNECTED) else UsbUvcCameraInfo(
            status = UsbUvcStatus.CONNECTED,
            vendorId = device.vendorId,
            productId = device.productId,
            deviceName = device.deviceName,
        )
    }

    suspend fun bindCam0Preview(previewView: PreviewView, cameraId: String?, zoomRatio: Float): PhoneCameraBindResult = phoneRecorder.bindPreview(previewView, cameraId, zoomRatio)

    suspend fun start(orderId: String?, captureSessionId: String, config: StereoRigConfig): File {
        require(config.baselineMm > 0.0) { "baseline_mm must be > 0" }
        check(active == null) { "Stereo capture is already recording" }
        val sessionUuid = UUID.randomUUID().toString()
        val stamp = DateTimeFormatter.ISO_INSTANT.format(Instant.now()).replace(":", "").replace("-", "")
        val bundleId = "stereo_capture_${orderId ?: "local"}_${captureSessionId}_${stamp}"
        val bundleDir = File(appContext.filesDir, "sessions/$captureSessionId/stereo_captures/$bundleId").apply { mkdirs() }
        val logFile = File(bundleDir, "app_log.txt")
        fun log(message: String) = logFile.appendText("${Instant.now()} $message\n")
        val usbInfo = detectUsbUvcCamera()
        val startNs = SystemClock.elapsedRealtimeNanos()
        try {
            phoneRecorder.startRecording(captureSessionId, "../stereo_captures/$bundleId")
            val generatedCam0 = File(bundleDir, "../stereo_captures/$bundleId/video.mp4").canonicalFile
            val targetCam0 = File(bundleDir, "cam0.mp4")
            active = ActiveStereoCapture(orderId, captureSessionId, sessionUuid, bundleDir, config, usbInfo.copy(status = UsbUvcStatus.ACTIVE), startNs, generatedCam0, targetCam0, imuRecorder.start(bundleDir))
            log("Stereo capture started; cam1 UVC recording adapter is experimental and may be unsupported on this device")
            return bundleDir
        } catch (t: Throwable) {
            writeRigAndManifests(bundleDir, config, sessionUuid, usbInfo, startNs, SystemClock.elapsedRealtimeNanos(), "failed_camera_open", t.message)
            log("Failed to start stereo capture: ${t.message}")
            throw t
        }
    }

    suspend fun stop(): StereoCaptureValidation {
        val current = active ?: error("Stereo capture is not recording")
        val stopNs = SystemClock.elapsedRealtimeNanos()
        active = null
        return try {
            val cam0Result = phoneRecorder.stopRecording()
            imuRecorder.stop()
            val cam0File = File(cam0Result.path)
            if (cam0File.exists()) cam0File.copyTo(current.cam0Target, overwrite = true)
            // Clean UVC extension point: real UVC MediaCodec/USB host implementation should write cam1.mp4 here.
            writeEstimatedTimestamps(File(current.bundleDir, "cam0_timestamps.json"), "cam0", current.startNs, stopNs, 30, cam0Result.durationSec)
            writeEstimatedTimestamps(File(current.bundleDir, "cam1_timestamps.json"), "cam1", current.startNs, stopNs, 30, cam0Result.durationSec)
            writeRigAndManifests(current.bundleDir, current.config, current.sessionUuid, current.usbInfo, current.startNs, stopNs, "completed", null)
            val validation = validate(current.bundleDir, current.config)
            if (!validation.ok) {
                writeRigAndManifests(current.bundleDir, current.config, current.sessionUuid, current.usbInfo, current.startNs, stopNs, "failed_unknown", validation.errors.joinToString())
            }
            validation
        } catch (t: Throwable) {
            imuRecorder.stop()
            writeRigAndManifests(current.bundleDir, current.config, current.sessionUuid, current.usbInfo, current.startNs, stopNs, "failed_unknown", t.message)
            File(current.bundleDir, "app_log.txt").appendText("${Instant.now()} Stop failed: ${t.message}\n")
            validate(current.bundleDir, current.config)
        }
    }

    fun validate(bundleDir: File, config: StereoRigConfig): StereoCaptureValidation {
        val errors = mutableListOf<String>()
        listOf("cam0.mp4", "cam1.mp4").forEach { name -> if (!File(bundleDir, name).let { it.exists() && it.length() > 0L }) errors += "$name missing or empty" }
        listOf("cam0_manifest.json", "cam1_manifest.json", "cam0_timestamps.json", "cam1_timestamps.json").forEach { if (!File(bundleDir, it).exists()) errors += "$it missing" }
        if (!File(bundleDir, "imu.jsonl").let { it.exists() && it.length() > 0L }) errors += "imu.jsonl missing or empty"
        runCatching { JSONObject(File(bundleDir, "rig.json").readText()) }.onFailure { errors += "rig.json invalid JSON" }
        if (config.baselineMm <= 0.0) errors += "baseline_mm must be > 0"
        if (errors.isNotEmpty()) File(bundleDir, "app_log.txt").appendText("${Instant.now()} Validation failed: ${errors.joinToString()}\n")
        return StereoCaptureValidation(errors.isEmpty(), errors, bundleDir)
    }

    private fun writeRigAndManifests(bundleDir: File, config: StereoRigConfig, sessionUuid: String, usb: UsbUvcCameraInfo, startNs: Long, stopNs: Long, status: String, failure: String?) {
        writeCameraManifest(File(bundleDir, "cam0_manifest.json"), "cam0", "phone_back", "cam0.mp4", 1920, 1080, startNs, stopNs, "estimated", Build.MODEL)
        writeCameraManifest(File(bundleDir, "cam1_manifest.json"), "cam1", "usb_uvc", "cam1.mp4", 1920, 1080, startNs, stopNs, "estimated", usb.deviceName)
        val cameras = JSONArray()
            .put(cameraJson("cam0", "phone_back", config.cam0Label, "cam0.mp4", "cam0_timestamps.json", 1920, 1080, null))
            .put(cameraJson("cam1", "usb_uvc", config.cam1Label, "cam1.mp4", "cam1_timestamps.json", 1920, 1080, usb))
        val rig = JSONObject()
            .put("capture_type", "stereo_rig").put("schema_version", 1).put("rig_id", config.rigId).put("session_uuid", sessionUuid)
            .put("created_at_utc", Instant.now().toString()).put("timebase", "monotonic_ns").put("capture_status", status).put("failure_reason", failure)
            .put("app", JSONObject().put("name", "MaklerTour Capture").put("version", BuildConfig.VERSION_NAME))
            .put("device", JSONObject().put("manufacturer", Build.MANUFACTURER ?: "unknown").put("model", Build.MODEL ?: "unknown").put("android_version", Build.VERSION.RELEASE ?: "unknown"))
            .put("rig_geometry", JSONObject().put("baseline_mm", config.baselineMm).put("cam0_to_cam1_horizontal_offset_mm", config.horizontalOffsetMm).put("cam0_to_cam1_vertical_offset_mm", config.verticalOffsetMm).put("cam0_to_cam1_depth_offset_mm", config.depthOffsetMm).put("cam1_yaw_deg", config.cam1YawDeg).put("cam1_pitch_deg", config.cam1PitchDeg).put("cam1_roll_deg", config.cam1RollDeg).put("calibration_source", "manual_user_input"))
            .put("cameras", cameras).put("imu", JSONObject().put("source", "phone").put("file", "imu.jsonl").put("sensors", JSONArray(listOf("accelerometer", "gyroscope", "rotation_vector"))))
        File(bundleDir, "rig.json").writeText(rig.toString(2))
    }

    private data class ActiveStereoCapture(val orderId: String?, val captureSessionId: String, val sessionUuid: String, val bundleDir: File, val config: StereoRigConfig, val usbInfo: UsbUvcCameraInfo, val startNs: Long, val generatedCam0: File, val cam0Target: File, val imuFile: File)
}

private fun UsbDevice.looksLikeVideoDevice(): Boolean = (0 until interfaceCount).any { getInterface(it).interfaceClass == UsbConstants.USB_CLASS_VIDEO }

private fun writeEstimatedTimestamps(file: File, cameraId: String, startNs: Long, stopNs: Long, fps: Int, durationSec: Long) {
    file.writeText(JSONObject().put("camera_id", cameraId).put("timebase", "monotonic_ns").put("timestamp_quality", "estimated").put("recording_started_ns", startNs).put("recording_stopped_ns", stopNs).put("fps_target", fps).put("fps_actual_estimate", if (durationSec > 0) 30.0 else JSONObject.NULL).toString(2))
}

private fun writeCameraManifest(file: File, id: String, role: String, video: String, width: Int, height: Int, startNs: Long, stopNs: Long, quality: String, deviceInfo: String?) {
    file.writeText(JSONObject().put("camera_id", id).put("camera_role", role).put("video", video).put("width", width).put("height", height).put("fps_target", 30).put("fps_actual_estimated", JSONObject.NULL).put("codec", "H.264 MP4").put("rotation_metadata", JSONObject.NULL).put("start_timestamp_ns", startNs).put("stop_timestamp_ns", stopNs).put("timestamp_quality", quality).put("frame_count", JSONObject.NULL).put("device_lens_info", deviceInfo).toString(2))
}

private fun cameraJson(id: String, role: String, lens: String, video: String, timestamps: String, width: Int, height: Int, usb: UsbUvcCameraInfo?): JSONObject = JSONObject().put("id", id).put("role", role).put("lens", lens).put("video", video).put("timestamps", timestamps).put("width", width).put("height", height).put("fps_target", 30).put("fps_actual", JSONObject.NULL).put("intrinsics_status", "unknown").apply { if (usb != null) { put("usb_vendor_id", usb.vendorId); put("usb_product_id", usb.productId); put("usb_device_name", usb.deviceName) } }

private class StereoImuJsonlRecorder(context: Context) : SensorEventListener {
    private val sensorManager = context.getSystemService(SensorManager::class.java)
    private var writer: java.io.BufferedWriter? = null
    fun start(baseDir: File): File {
        val file = File(baseDir, "imu.jsonl")
        writer = file.bufferedWriter()
        listOf(Sensor.TYPE_ACCELEROMETER, Sensor.TYPE_GYROSCOPE, Sensor.TYPE_ROTATION_VECTOR).forEach { sensorManager?.getDefaultSensor(it)?.also { s -> sensorManager.registerListener(this, s, SensorManager.SENSOR_DELAY_GAME) } }
        return file
    }
    fun stop() { sensorManager?.unregisterListener(this); writer?.flush(); writer?.close(); writer = null }
    override fun onSensorChanged(event: SensorEvent) {
        val sensor = when (event.sensor.type) { Sensor.TYPE_ACCELEROMETER -> "accelerometer"; Sensor.TYPE_GYROSCOPE -> "gyroscope"; Sensor.TYPE_ROTATION_VECTOR -> "rotation_vector"; else -> return }
        val line = JSONObject().put("timestamp_ns", event.timestamp).put("sensor", sensor).put("x", event.values.getOrNull(0)?.toDouble() ?: 0.0).put("y", event.values.getOrNull(1)?.toDouble() ?: 0.0).put("z", event.values.getOrNull(2)?.toDouble() ?: 0.0)
        if (sensor == "rotation_vector") line.put("w", event.values.getOrNull(3)?.toDouble() ?: 1.0)
        writer?.apply { write(line.toString()); newLine() }
    }
    override fun onAccuracyChanged(sensor: Sensor?, accuracy: Int) = Unit
}
