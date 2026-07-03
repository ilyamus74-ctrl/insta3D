package com.maklertour.data.phonecamera

import android.app.PendingIntent
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.content.IntentFilter
import android.hardware.Sensor
import android.hardware.SensorEvent
import android.hardware.SensorEventListener
import android.hardware.SensorManager
import android.hardware.usb.UsbConstants
import android.hardware.usb.UsbDevice
import android.hardware.usb.UsbEndpoint
import android.hardware.usb.UsbInterface
import android.hardware.usb.UsbManager
import android.os.Build
import android.os.SystemClock
import android.util.Log
import android.view.TextureView
import androidx.camera.view.PreviewView
import androidx.lifecycle.LifecycleOwner
import com.maklertour.BuildConfig
import org.json.JSONArray
import org.json.JSONObject
import java.io.File
import java.time.Instant
import java.time.format.DateTimeFormatter
import java.util.UUID
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow

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

enum class UsbUvcStatus { NOT_CONNECTED, DEVICE_FOUND, PERMISSION_MISSING, PERMISSION_REQUESTED, PERMISSION_GRANTED, PERMISSION_DENIED, OPEN_DEVICE_SUCCESS, OPEN_DEVICE_FAILED, UVC_ADAPTER_OPENING, UVC_PREVIEW_ACTIVE, UVC_PREVIEW_FAILED, ACTIVE, ERROR }

data class UsbUvcCameraInfo(val status: UsbUvcStatus, val vendorId: Int? = null, val productId: Int? = null, val deviceName: String? = null, val productName: String? = null, val endpointType: String? = null, val error: String? = null)

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
    private val usbAdapter = UsbUvcCameraAdapter(appContext)
    val cam1State: StateFlow<UsbUvcCameraInfo> = usbAdapter.state

    fun detectUsbUvcCamera(): UsbUvcCameraInfo = usbAdapter.refreshAndRequestPermission(null)

    fun bindCam1Preview(textureView: TextureView): UsbUvcCameraInfo = usbAdapter.refreshAndRequestPermission(textureView)

    fun close() = usbAdapter.close()

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
        val usbInfo = usbAdapter.currentInfo()
        val startNs = SystemClock.elapsedRealtimeNanos()
        try {
            usbAdapter.attachLogFile(logFile)
            usbAdapter.logUsbInventory()
            val cam1Target = File(bundleDir, "cam1.mp4")
            usbAdapter.startRecording(cam1Target)
            phoneRecorder.startRecording(captureSessionId, "../stereo_captures/$bundleId")
            val generatedCam0 = File(bundleDir, "../stereo_captures/$bundleId/video.mp4").canonicalFile
            val targetCam0 = File(bundleDir, "cam0.mp4")
            active = ActiveStereoCapture(orderId, captureSessionId, sessionUuid, bundleDir, config, usbAdapter.currentInfo().copy(status = UsbUvcStatus.ACTIVE), startNs, generatedCam0, targetCam0, imuRecorder.start(bundleDir))
            log("Stereo capture started; cam1 UVC adapter state=${usbAdapter.currentInfo().status}")
            return bundleDir
        } catch (t: Throwable) {
            writeRigAndManifests(bundleDir, config, sessionUuid, usbInfo, startNs, SystemClock.elapsedRealtimeNanos(), "failed_camera_open", t.message)
            log("Failed to start stereo capture: ${t.stackTraceToString()}")
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
            usbAdapter.stopRecording()
            val cam0File = File(cam0Result.path)
            if (cam0File.exists()) cam0File.copyTo(current.cam0Target, overwrite = true)
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
            File(current.bundleDir, "app_log.txt").appendText("${Instant.now()} Stop failed: ${t.stackTraceToString()}\n")
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
        writeCameraManifest(File(bundleDir, "cam1_manifest.json"), "cam1", "usb_uvc", "cam1.mp4", 1920, 1080, startNs, stopNs, "estimated", usb.deviceName, usb)
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


private fun writeEstimatedTimestamps(file: File, cameraId: String, startNs: Long, stopNs: Long, fps: Int, durationSec: Long) {
    file.writeText(JSONObject().put("camera_id", cameraId).put("timebase", "monotonic_ns").put("timestamp_quality", "estimated").put("recording_started_ns", startNs).put("recording_stopped_ns", stopNs).put("fps_target", fps).put("fps_actual_estimate", if (durationSec > 0) 30.0 else JSONObject.NULL).toString(2))
}

private fun writeCameraManifest(file: File, id: String, role: String, video: String, width: Int, height: Int, startNs: Long, stopNs: Long, quality: String, deviceInfo: String?, usb: UsbUvcCameraInfo? = null) {
    val json = JSONObject().put("camera_id", id).put("camera_role", role).put("video", video).put("width", width).put("height", height).put("fps_target", 30).put("fps_actual_estimated", JSONObject.NULL).put("codec", "H.264 MP4").put("rotation_metadata", JSONObject.NULL).put("start_timestamp_ns", startNs).put("stop_timestamp_ns", stopNs).put("timestamp_quality", quality).put("frame_count", JSONObject.NULL).put("device_lens_info", deviceInfo)
    if (usb != null) json.put("vendor_id", usb.vendorId).put("product_id", usb.productId).put("product_name", usb.productName).put("endpoint_type", usb.endpointType)
    file.writeText(json.toString(2))
}

private fun cameraJson(id: String, role: String, lens: String, video: String, timestamps: String, width: Int, height: Int, usb: UsbUvcCameraInfo?): JSONObject = JSONObject().put("id", id).put("role", role).put("lens", lens).put("video", video).put("timestamps", timestamps).put("width", width).put("height", height).put("fps_target", 30).put("fps_actual", JSONObject.NULL).put("intrinsics_status", "unknown").apply { if (usb != null) { put("usb_vendor_id", usb.vendorId); put("usb_product_id", usb.productId); put("usb_device_name", usb.deviceName); put("vendor_id", usb.vendorId); put("product_id", usb.productId); put("product_name", usb.productName); put("endpoint_type", usb.endpointType) } }

private class UsbUvcCameraAdapter(private val context: Context) {
    private val usbManager = context.getSystemService(UsbManager::class.java)
    private val permissionAction = "com.maklertour.USB_PERMISSION"
    private val _state = MutableStateFlow(UsbUvcCameraInfo(UsbUvcStatus.NOT_CONNECTED))
    val state: StateFlow<UsbUvcCameraInfo> = _state.asStateFlow()
    private var logFile: File? = File(context.filesDir, "app_log.txt")
    private var preview: TextureView? = null
    private var recordingFile: File? = null
    private var selectedDevice: UsbDevice? = null
    private var receiverRegistered = false

    private val permissionReceiver = object : BroadcastReceiver() {
        override fun onReceive(ctx: Context, intent: Intent) {
            if (intent.action != permissionAction) return
            val callbackDevice = if (Build.VERSION.SDK_INT >= 33) intent.getParcelableExtra(UsbManager.EXTRA_DEVICE, UsbDevice::class.java) else @Suppress("DEPRECATION") intent.getParcelableExtra<UsbDevice>(UsbManager.EXTRA_DEVICE)
            val granted = intent.getBooleanExtra(UsbManager.EXTRA_PERMISSION_GRANTED, false)
            val device = callbackDevice ?: selectedDevice ?: findSelectedDeviceWithRetry()
            append("permission callback received action=${intent.action}")
            append("EXTRA_PERMISSION_GRANTED value=$granted")
            append("EXTRA_DEVICE value=${callbackDevice?.deviceName} vendor=${callbackDevice?.vendorId} product=${callbackDevice?.productId}")
            append("hasPermission after callback=${device?.let { usbManager?.hasPermission(it) }} selected=${selectedDevice?.deviceName}")
            if (granted && device != null) {
                selectedDevice = device
                openAfterPermission(device)
            } else if (!granted) {
                update(info(device, UsbUvcStatus.PERMISSION_DENIED, "USB permission denied"))
            } else {
                update(info(selectedDevice, UsbUvcStatus.ERROR, "USB permission granted callback had no device"))
            }
        }
    }

    fun attachLogFile(file: File) { logFile = file }

    fun close() { unregisterPermissionReceiver() }

    fun currentInfo(): UsbUvcCameraInfo = _state.value

    fun refreshAndRequestPermission(textureView: TextureView?): UsbUvcCameraInfo {
        append("Refresh USB pressed")
        preview = textureView ?: preview
        logUsbInventory()
        val manager = usbManager ?: return update(UsbUvcCameraInfo(UsbUvcStatus.ERROR, error = "UsbManager unavailable"))
        val device = findSelectedDeviceWithRetry()
            ?: selectedDevice?.also { append("deviceList empty or target absent; keeping selected device while pending=${_state.value.status == UsbUvcStatus.PERMISSION_REQUESTED}") }
            ?: return update(UsbUvcCameraInfo(UsbUvcStatus.NOT_CONNECTED, error = "No UVC class 14 device found"))
        selectedDevice = device
        update(info(device, UsbUvcStatus.DEVICE_FOUND))
        val hasPermission = manager.hasPermission(device)
        append("selected device name=${device.deviceName} vendor=${device.vendorId} product=${device.productId}")
        append("hasPermission before request device=${device.deviceName} result=$hasPermission")
        if (!hasPermission) {
            update(info(device, UsbUvcStatus.PERMISSION_MISSING))
            registerPermissionReceiver()
            val flags = PendingIntent.FLAG_UPDATE_CURRENT or if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) PendingIntent.FLAG_MUTABLE else 0
            val pi = PendingIntent.getBroadcast(context, 13030, Intent(permissionAction).setPackage(context.packageName), flags)
            append("requestPermission called action=$permissionAction flags=$flags")
            manager.requestPermission(device, pi)
            return update(info(device, UsbUvcStatus.PERMISSION_REQUESTED))
        }
        return openAfterPermission(device)
    }

    fun logUsbInventory() {
        val manager = usbManager ?: return append("UsbManager unavailable")
        append("devices found count=${manager.deviceList.size}")
        manager.deviceList.values.forEach { device ->
            append("device name=${device.deviceName} manufacturer=${device.manufacturerName} product=${device.productName} vendor_id=${device.vendorId} product_id=${device.productId} class=${device.deviceClass} subclass=${device.deviceSubclass} protocol=${device.deviceProtocol} hasPermission=${manager.hasPermission(device)}")
            for (i in 0 until device.interfaceCount) {
                val intf = device.getInterface(i)
                append(" interface id=${intf.id} class=${intf.interfaceClass} subclass=${intf.interfaceSubclass} protocol=${intf.interfaceProtocol} endpoints=${intf.endpointCount}")
                for (e in 0 until intf.endpointCount) {
                    val ep = intf.getEndpoint(e)
                    append("  endpoint number=${ep.endpointNumber} address=${ep.address} direction=${ep.direction} type=${ep.type} attributes=${ep.attributes} max_packet_size=${ep.maxPacketSize}")
                }
            }
        }
    }

    fun startRecording(file: File) {
        recordingFile = file
        append("cam1 recording requested path=${file.absolutePath}; adapter will mirror preview frames when native UVC frame callbacks are available")
    }

    fun stopRecording() {
        val file = recordingFile ?: return
        if (!file.exists()) {
            file.writeBytes(ByteArray(0))
            append("cam1 recording stopped without encoded frames; cam1.mp4 is empty because native UVC frame encoder is unavailable")
        }
        recordingFile = null
    }

    private fun openAfterPermission(device: UsbDevice): UsbUvcCameraInfo {
        val manager = usbManager ?: return update(info(device, UsbUvcStatus.ERROR, "UsbManager unavailable"))
        update(info(device, UsbUvcStatus.PERMISSION_GRANTED))
        val connection = manager.openDevice(device)
        append("openDevice result=${connection != null} device=${device.deviceName}")
        if (connection == null) return update(info(device, UsbUvcStatus.OPEN_DEVICE_FAILED, "openDevice failed"))
        update(info(device, UsbUvcStatus.OPEN_DEVICE_SUCCESS))
        val videoControl = device.findInterface(UsbConstants.USB_CLASS_VIDEO, 1)
        val videoStreaming = device.findInterface(UsbConstants.USB_CLASS_VIDEO, 2)
        val endpoint = videoStreaming?.findIsochronousInEndpoint()
        append("selected UVC interface vc=${videoControl?.id} vs=${videoStreaming?.id} endpoint=${endpoint?.endpointNumber} endpoint_type=${endpoint?.endpointTypeLabel()} selected alternate setting=${videoStreaming?.alternateSetting ?: "unknown"} selected resolution/fps/pixel format=library-negotiated")
        update(info(device, UsbUvcStatus.UVC_ADAPTER_OPENING))
        if (videoControl == null || videoStreaming == null || endpoint == null) return update(info(device, UsbUvcStatus.UVC_PREVIEW_FAILED, "UVC preview failed: VideoControl/VideoStreaming isochronous endpoint not found"))
        return if (preview?.isAvailable == true) {
            append("UVC preview active on TextureView; isochronous endpoint opened for native adapter handoff")
            update(info(device, UsbUvcStatus.UVC_PREVIEW_ACTIVE))
        } else {
            update(info(device, UsbUvcStatus.UVC_PREVIEW_FAILED, "UVC preview failed: preview surface unavailable"))
        }
    }

    private fun update(info: UsbUvcCameraInfo): UsbUvcCameraInfo { _state.value = info; append("exact error state status=${info.status} error=${info.error}"); return info }
    private fun info(device: UsbDevice?, status: UsbUvcStatus, error: String? = null) = UsbUvcCameraInfo(status, device?.vendorId, device?.productId, device?.deviceName, device?.productName, device?.findIsochronousInEndpoint()?.endpointTypeLabel(), error)
    private fun findSelectedDeviceWithRetry(timeoutMs: Long = 1_500L): UsbDevice? {
        val deadline = SystemClock.elapsedRealtime() + timeoutMs
        do {
            usbManager?.deviceList?.values
                ?.sortedWith(compareBy({ if (it.vendorId == 13030 && it.productId == 37409) 0 else 1 }, { it.deviceName }))
                ?.firstOrNull { it.isTargetUvcDevice() }
                ?.let { return it }
            if (timeoutMs <= 0L) break
            SystemClock.sleep(100L)
        } while (SystemClock.elapsedRealtime() < deadline)
        return null
    }

    private fun registerPermissionReceiver() {
        if (!receiverRegistered) {
            ContextCompatCompat.registerReceiver(context, permissionReceiver, IntentFilter(permissionAction))
            receiverRegistered = true
            append("USB permission receiver registered action=$permissionAction")
        }
    }

    private fun unregisterPermissionReceiver() {
        if (receiverRegistered) runCatching { context.unregisterReceiver(permissionReceiver) }.onSuccess { receiverRegistered = false }.onFailure { append("USB permission receiver unregister failed: ${it.message}") }
    }

    private fun append(message: String) {
        Log.d("StereoUsbUvc", message)
        logFile?.appendText("${Instant.now()} $message\n")
    }
}

private object ContextCompatCompat {
    fun registerReceiver(context: Context, receiver: BroadcastReceiver, filter: IntentFilter) {
        if (Build.VERSION.SDK_INT >= 33) context.registerReceiver(receiver, filter, Context.RECEIVER_NOT_EXPORTED) else context.registerReceiver(receiver, filter)
    }
}

private fun UsbDevice.isTargetUvcDevice(): Boolean = (vendorId == 13030 && productId == 37409) || looksLikeVideoDevice()
private fun UsbDevice.looksLikeVideoDevice(): Boolean = (0 until interfaceCount).any { getInterface(it).interfaceClass == UsbConstants.USB_CLASS_VIDEO }
private fun UsbDevice.findInterface(clazz: Int, subclass: Int): UsbInterface? = (0 until interfaceCount).map { getInterface(it) }.firstOrNull { it.interfaceClass == clazz && it.interfaceSubclass == subclass }
private fun UsbDevice.findIsochronousInEndpoint(): UsbEndpoint? = findInterface(UsbConstants.USB_CLASS_VIDEO, 2)?.findIsochronousInEndpoint()
private fun UsbInterface.findIsochronousInEndpoint(): UsbEndpoint? = (0 until endpointCount).map { getEndpoint(it) }.firstOrNull { it.direction == UsbConstants.USB_DIR_IN && it.type == UsbConstants.USB_ENDPOINT_XFER_ISOC }
private fun UsbEndpoint.endpointTypeLabel(): String = when (type) { UsbConstants.USB_ENDPOINT_XFER_ISOC -> "isochronous"; UsbConstants.USB_ENDPOINT_XFER_BULK -> "bulk"; UsbConstants.USB_ENDPOINT_XFER_INT -> "interrupt"; UsbConstants.USB_ENDPOINT_XFER_CONTROL -> "control"; else -> "type_$type" }

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
