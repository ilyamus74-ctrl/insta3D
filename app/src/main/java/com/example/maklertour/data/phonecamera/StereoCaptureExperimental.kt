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
import android.hardware.usb.UsbDeviceConnection
import android.hardware.usb.UsbDevice
import android.hardware.usb.UsbEndpoint
import android.hardware.usb.UsbInterface
import android.hardware.usb.UsbManager
import android.hardware.usb.UsbRequest
import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.graphics.Color
import android.graphics.Paint
import android.os.Build
import android.os.SystemClock
import android.os.Handler
import android.os.Looper
import android.util.Log
import android.view.TextureView
import androidx.camera.view.PreviewView
import androidx.lifecycle.LifecycleOwner
import com.maklertour.BuildConfig
import org.json.JSONArray
import org.json.JSONObject
import java.io.File
import java.io.ByteArrayOutputStream
import java.nio.ByteBuffer
import java.util.concurrent.CountDownLatch
import java.util.concurrent.TimeUnit
import java.util.concurrent.atomic.AtomicBoolean
import java.time.Instant
import java.time.format.DateTimeFormatter
import java.util.UUID
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow

private const val USB_RECIP_INTERFACE = 0x01

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

enum class UsbUvcStatus { NOT_CONNECTED, DEVICE_FOUND, PERMISSION_MISSING, PERMISSION_REQUESTED, PERMISSION_GRANTED, PERMISSION_DENIED, OPEN_DEVICE_SUCCESS, OPEN_DEVICE_FAILED, UVC_ADAPTER_OPENING, UVC_STREAM_OPENED, UVC_FRAMES_RECEIVING, UVC_PREVIEW_RENDERING, UVC_NO_FRAMES_TIMEOUT, UVC_DECODE_FAILED, UVC_PREVIEW_ACTIVE, UVC_PREVIEW_FAILED, ACTIVE, ERROR }

data class UsbUvcCameraInfo(
    val status: UsbUvcStatus,
    val vendorId: Int? = null,
    val productId: Int? = null,
    val deviceName: String? = null,
    val productName: String? = null,
    val endpointType: String? = null,
    val error: String? = null,
    val cam1FramesReceived: Long = 0L,
    val cam1PacketsReceived: Long = 0L,
    val cam1FramesAssembled: Long = 0L,
    val cam1FramesDecoded: Long = 0L,
    val cam1FramesRendered: Long = 0L,
    val cam1LastPacketAgeMs: Long? = null,
    val cam1LastFrameAgeMs: Long? = null,
    val cam1DecodeErrors: Long = 0L,
    val cam1RenderErrors: Long = 0L,
    val cam1FpsEstimate: Double = 0.0,
    val selectedPixelFormat: String? = null,
    val selectedResolutionFps: String? = null,
    val selectedAltSetting: Int? = null,
    val selectedMaxPacketSize: Int? = null,
)

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

    fun onCam1PreviewFrameRendered() = usbAdapter.onPreviewFrameRendered()

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
            writeRigAndManifests(current.bundleDir, current.config, current.sessionUuid, usbAdapter.currentInfo(), current.startNs, stopNs, "completed", null)
            val validation = validate(current.bundleDir, current.config)
            if (!validation.ok) {
                writeRigAndManifests(current.bundleDir, current.config, current.sessionUuid, usbAdapter.currentInfo(), current.startNs, stopNs, "failed_unknown", validation.errors.joinToString())
            }
            validation
        } catch (t: Throwable) {
            imuRecorder.stop()
            writeRigAndManifests(current.bundleDir, current.config, current.sessionUuid, usbAdapter.currentInfo(), current.startNs, stopNs, "failed_unknown", t.message)
            File(current.bundleDir, "app_log.txt").appendText("${Instant.now()} Stop failed: ${t.stackTraceToString()}\n")
            validate(current.bundleDir, current.config)
        }
    }

    fun validate(bundleDir: File, config: StereoRigConfig): StereoCaptureValidation {
        val errors = mutableListOf<String>()
        listOf("cam0.mp4", "cam1.mp4").forEach { name -> if (!File(bundleDir, name).let { it.exists() && it.length() > 0L }) errors += "$name missing or empty" }
        if (usbAdapter.recordedFrameCount() <= 0L) errors += "cam1.mp4 has no confirmed cam1 frames during recording"
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
        .put("selected_pixel_format", usb.selectedPixelFormat).put("selected_resolution_fps", usb.selectedResolutionFps)
        .put("selected_alt_setting", usb.selectedAltSetting).put("selected_max_packet_size", usb.selectedMaxPacketSize)
        .put("packets_received", usb.cam1PacketsReceived).put("frames_assembled", usb.cam1FramesAssembled)
        .put("frames_decoded", usb.cam1FramesDecoded).put("frames_rendered", usb.cam1FramesRendered)
        .put("decode_errors", usb.cam1DecodeErrors).put("render_errors", usb.cam1RenderErrors)
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
    private val mainHandler = Handler(Looper.getMainLooper())
    private var firstFrameNs: Long? = null
    private var lastFrameNs: Long? = null
    private var lastPacketNs: Long? = null
    private var firstPacketLogged = false
    private var firstCompleteFrameLogged = false
    private var firstDecodedLogged = false
    private var firstRenderedLogged = false
    private var selectedPixelFormat: String? = null
    private var selectedResolutionFps: String? = null
    private var selectedAltSetting: Int? = null
    private var selectedMaxPacketSize: Int? = null
    private var selectedMode: UvcSelectedMode? = null
    private var receiveThread: Thread? = null
    private var firstPacketLatch: CountDownLatch? = null
    private var streaming = AtomicBoolean(false)
    private var activeConnection: UsbDeviceConnection? = null
    private var activeEndpoint: UsbEndpoint? = null
    private var recordingStartFrameCount: Long = 0L
    private var lastRecordingFrameCount: Long = 0L

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

    fun close() { stopStreamLoop(); unregisterPermissionReceiver() }

    fun currentInfo(): UsbUvcCameraInfo = _state.value

    fun recordedFrameCount(): Long = lastRecordingFrameCount

    private fun stopStreamLoop() {
        streaming.set(false)
        stopReceiveThreadOnly()
        runCatching { activeConnection?.close() }
        activeConnection = null
        activeEndpoint = null
    }

    private fun stopReceiveThreadOnly() {
        streaming.set(false)
        runCatching { receiveThread?.interrupt() }
        runCatching { receiveThread?.join(250L) }
        receiveThread = null
        firstPacketLatch = null
    }

    fun onPreviewFrameRendered() {
        val now = SystemClock.elapsedRealtimeNanos()
        if (firstFrameNs == null) {
            firstFrameNs = now
            append("cam1 first rendered frame timestamp_ns=$now")
            update(info(selectedDevice, UsbUvcStatus.UVC_FRAMES_RECEIVING))
        }
        lastFrameNs = now
        val nextCount = _state.value.cam1FramesRendered + 1L
        val first = firstFrameNs ?: now
        val fps = if (now > first && nextCount > 1L) (nextCount - 1L) * 1_000_000_000.0 / (now - first) else 0.0
        if (!firstRenderedLogged) { firstRenderedLogged = true; append("first rendered frame timestamp_ns=$now") }
        append("cam1 rendered frame count=$nextCount first_frame_timestamp_ns=$first last_frame_timestamp_ns=$now preview render success=true")
        update(info(selectedDevice, UsbUvcStatus.UVC_PREVIEW_ACTIVE).copy(cam1FramesRendered = nextCount, cam1LastFrameAgeMs = 0L, cam1FpsEstimate = fps))
    }

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
        recordingStartFrameCount = _state.value.cam1FramesReceived
        lastRecordingFrameCount = 0L
        append("cam1 recording requested path=${file.absolutePath}; current frame count=${_state.value.cam1FramesReceived}; cam1.mp4 valid only if file exists, size > 0, and frame count > 0")
    }

    fun stopRecording() {
        val file = recordingFile ?: return
        lastRecordingFrameCount = (_state.value.cam1FramesReceived - recordingStartFrameCount).coerceAtLeast(0L)
        if (!file.exists()) file.writeBytes(ByteArray(0))
        append("cam1 recording stopped; file_exists=${file.exists()} size=${file.length()} frame_count=${lastRecordingFrameCount}; cam1.mp4 valid=${file.exists() && file.length() > 0L && lastRecordingFrameCount > 0L}")
        recordingFile = null
    }

    private fun openAfterPermission(device: UsbDevice): UsbUvcCameraInfo {
        stopStreamLoop()
        val manager = usbManager ?: return update(info(device, UsbUvcStatus.ERROR, "UsbManager unavailable"))
        append("device selected")
        update(info(device, UsbUvcStatus.PERMISSION_GRANTED))
        append("permission ok")
        val connection = manager.openDevice(device)
        append("openDevice ok result=${connection != null} device=${device.deviceName}")
        if (connection == null) return update(info(device, UsbUvcStatus.OPEN_DEVICE_FAILED, "openDevice failed"))
        update(info(device, UsbUvcStatus.OPEN_DEVICE_SUCCESS))
        val selection = device.selectUvcInterfaces(::append)
        val videoControl = selection.videoControl
        val alternates = selection.videoStreamingAlternates
            .filter { alt ->
                alt.usbInterface.interfaceClass == 14 &&
                    alt.usbInterface.interfaceSubclass == 2 &&
                    alt.usbInterface.endpointCount > 0 &&
                    alt.endpoint.direction == UsbConstants.USB_DIR_IN &&
                    alt.endpoint.type == UsbConstants.USB_ENDPOINT_XFER_ISOC &&
                    alt.endpoint.address == 130
            }
            .sortedWith(compareBy<UvcStreamingAlternate> { alt ->
                listOf(5120, 4976, 3072, 2848, 1024, 512).indexOf(alt.endpoint.maxPacketSize).let { if (it >= 0) it else Int.MAX_VALUE }
            })
        append("VideoControl selected id=${videoControl?.id ?: "missing"}")
        append("VideoStreaming selected candidates=${alternates.joinToString { "id=${it.usbInterface.id} alt=${it.usbInterface.alternateSetting ?: "unknown"} ep=${it.endpoint.address} max=${it.endpoint.maxPacketSize}" }}")
        val selected = alternates.firstOrNull()
        append("endpoint selected address=${selected?.endpoint?.address ?: "missing"} type=${selected?.endpoint?.type} direction=${selected?.endpoint?.direction} maxPacketSize=${selected?.endpoint?.maxPacketSize}")
        append("UVC discovery state: VideoControl ${if (videoControl == null) "missing" else "found id=${videoControl.id}"}; VideoStreaming alternates found count=${alternates.size}; Isochronous IN endpoint ${if (selected?.endpoint == null) "missing" else "found"}")
        val rawDescriptors = runCatching { connection.rawDescriptors }.getOrNull()
        val parsedModes = parseUvcModes(rawDescriptors, ::append)
        selectedMode = chooseUvcMode(parsedModes)
        selectedPixelFormat = selectedMode?.format ?: device.detectUvcPixelFormat(::append)?.substringBefore(" ") ?: "unknown"
        selectedResolutionFps = selectedMode?.let { "${it.width}x${it.height}@${it.fps}fps interval=${it.frameInterval100ns}" } ?: "640x480@30fps fallback"
        append("selected UVC pixel format=${selectedPixelFormat ?: "unknown"}")
        append("selected resolution/fps=${selectedResolutionFps}")
        update(info(device, UsbUvcStatus.UVC_ADAPTER_OPENING))
        if (videoControl == null) return update(info(device, UsbUvcStatus.UVC_PREVIEW_FAILED, "UVC preview failed: VideoControl missing"))
        if (alternates.isEmpty()) return update(info(device, UsbUvcStatus.UVC_PREVIEW_FAILED, "UVC preview failed: VideoStreaming alternate with endpoint address=130 missing"))
        val claimControl = connection.claimInterface(videoControl, true)
        append("claim VideoControl result=$claimControl id=${videoControl.id}")
        if (!claimControl) return update(info(device, UsbUvcStatus.UVC_PREVIEW_FAILED, "UVC preview failed: claimInterface failed for VideoControl id=${videoControl.id}"))
        if (preview?.isAvailable != true) return update(info(device, UsbUvcStatus.UVC_PREVIEW_FAILED, "UVC stream start failed: preview surface unavailable"))

        for (candidate in alternates) {
            val candidateInterface = candidate.usbInterface
            val endpoint = candidate.endpoint
            val claimStreaming = connection.claimInterface(candidateInterface, true)
            append("claim VideoStreaming result=$claimStreaming id=${candidateInterface.id} alternate=${candidateInterface.alternateSetting ?: "unknown"} endpoint_address=${endpoint.address} max_packet_size=${endpoint.maxPacketSize}")
            if (!claimStreaming) continue
            val setInterface = connection.trySetInterface(candidateInterface, ::append)
            append("setInterface result=$setInterface id=${candidateInterface.id} alternate=${candidateInterface.alternateSetting ?: "unknown"} endpoint_address=${endpoint.address} max_packet_size=${endpoint.maxPacketSize}")
            if (!setInterface) continue
            selectedAltSetting = candidateInterface.alternateSetting
            selectedMaxPacketSize = endpoint.maxPacketSize
            val mode = selectedMode ?: UvcSelectedMode(selectedPixelFormat ?: "unknown", 640, 480, 30, 333333, 640 * 480 * 2, endpoint.maxPacketSize)
            for (legacy in listOf(false, true)) {
                stopReceiveThreadOnly()
                val beforePackets = _state.value.cam1PacketsReceived
                if (legacy) {
                    append("legacy_raw_stream_attempt alternate=${candidateInterface.alternateSetting ?: "unknown"} max_packet_size=${endpoint.maxPacketSize}")
                } else {
                    runCatching { connection.commitProbe(candidateInterface, mode, endpoint, ::append) }
                        .onFailure { append("UVC probe get/set result exception=${it.stackTraceToString()}") }
                }
                activeConnection = connection
                activeEndpoint = endpoint
                startStreamLoop(device, connection, endpoint, mode)
                append("UVC stream opened, waiting for packets alternate=${candidateInterface.alternateSetting ?: "unknown"} max_packet_size=${endpoint.maxPacketSize} legacy=$legacy")
                update(info(device, UsbUvcStatus.UVC_STREAM_OPENED, "UVC stream opened, waiting for packets"))
                firstPacketLatch?.await(1000L, TimeUnit.MILLISECONDS)
                if (_state.value.cam1PacketsReceived > beforePackets) {
                    scheduleNoFramesTimeout(device)
                    return update(info(device, UsbUvcStatus.UVC_STREAM_OPENED))
                }
                append("UVC stream failed: no packets alternate=${candidateInterface.alternateSetting ?: "unknown"} max_packet_size=${endpoint.maxPacketSize} legacy=$legacy")
            }
        }
        stopReceiveThreadOnly()
        return update(info(device, UsbUvcStatus.UVC_NO_FRAMES_TIMEOUT, "UVC stream failed: no packets"))
    }

    private fun startStreamLoop(device: UsbDevice, connection: UsbDeviceConnection, endpoint: UsbEndpoint, mode: UvcSelectedMode) {
        streaming.set(true)
        firstPacketLatch = CountDownLatch(1)
        receiveThread = Thread({
            val requests = (0 until 6).mapNotNull { index ->
                runCatching {
                    UsbRequest().takeIf { req -> req.initialize(connection, endpoint) }?.also { req ->
                        append("UsbRequest allocated index=$index endpoint=${endpoint.address} maxPacketSize=${endpoint.maxPacketSize}")
                        val buffer = ByteBuffer.allocateDirect(endpoint.maxPacketSize.coerceAtLeast(1024))
                        req.clientData = buffer
                        val queued = req.queue(buffer, buffer.capacity())
                        append("UsbRequest queued index=$index result=$queued capacity=${buffer.capacity()}")
                    }
                }.onFailure { append("UsbRequest allocate/queue exception index=$index ${it.stackTraceToString()}") }.getOrNull()
            }
            append("continuous isochronous receive loop started in_flight=${requests.size} endpoint=${endpoint.address} maxPacketSize=${endpoint.maxPacketSize}")
            val assembler = UvcFrameAssembler(mode)
            var lastLog = SystemClock.elapsedRealtime()
            while (streaming.get()) {
                try {
                    val waited = connection.requestWait(1000)
                    append("requestWait returned result=${waited != null}")
                    if (waited == null) {
                        val age = lastPacketNs?.let { (SystemClock.elapsedRealtimeNanos() - it) / 1_000_000L }
                        if (age == null || age > 1000L) update(info(device, UsbUvcStatus.UVC_NO_FRAMES_TIMEOUT, "UVC stream failed: no packets"))
                        continue
                    }
                    val req = waited
                    val buffer = req.clientData as ByteBuffer
                    val bytes = buffer.position().takeIf { it > 0 } ?: buffer.limit().takeIf { it in 1..buffer.capacity() } ?: buffer.capacity()
                    append("bytes received count=$bytes endpoint=${endpoint.address}")
                    buffer.flip()
                    val packet = ByteArray(bytes.coerceAtMost(buffer.remaining()))
                    buffer.get(packet)
                    buffer.clear()
                    onPacket(device, packet)
                    if (packet.isNotEmpty()) {
                        assembler.accept(packet, ::append)?.let { frame ->
                            onFrameAssembled(device)
                            decodeAndRender(device, frame, mode)
                        }
                    }
                    val requeued = req.queue(buffer, buffer.capacity())
                    append("UsbRequest queued requeue result=$requeued capacity=${buffer.capacity()}")
                    val nowMs = SystemClock.elapsedRealtime()
                    if (nowMs - lastLog >= 1000L) {
                        lastLog = nowMs
                        append("cam1 counters packets=${_state.value.cam1PacketsReceived} assembled=${_state.value.cam1FramesAssembled} decoded=${_state.value.cam1FramesDecoded} rendered=${_state.value.cam1FramesRendered}")
                    }
                } catch (t: Throwable) {
                    append("UVC receive loop exception: ${t.stackTraceToString()}")
                    if (_state.value.cam1PacketsReceived > 0L && _state.value.cam1FramesAssembled > 0L) update(info(device, UsbUvcStatus.UVC_DECODE_FAILED, t.message)) else update(info(device, UsbUvcStatus.UVC_NO_FRAMES_TIMEOUT, "UVC stream failed: no packets"))
                }
            }
            requests.forEach { runCatching { it.close() }.onFailure { e -> append("UsbRequest close exception: ${e.stackTraceToString()}") } }
        }, "UsbUvcIsochronousReceive").also { it.isDaemon = true; it.start() }
    }

    private fun onPacket(device: UsbDevice, packet: ByteArray) {
        val now = SystemClock.elapsedRealtimeNanos()
        if (!firstPacketLogged) { firstPacketLogged = true; append("first packet timestamp_ns=$now size=${packet.size} header_len=${packet.firstOrNull()?.toInt()?.and(0xff)} flags=${packet.getOrNull(1)?.toInt()?.and(0xff)}") }
        lastPacketNs = now
        val nextPackets = _state.value.cam1PacketsReceived + 1
        append("packet counter incremented count=$nextPackets size=${packet.size}")
        firstPacketLatch?.countDown()
        update(info(device, UsbUvcStatus.UVC_FRAMES_RECEIVING).copy(cam1PacketsReceived = nextPackets, cam1LastPacketAgeMs = 0L))
    }

    private fun onFrameAssembled(device: UsbDevice) {
        val now = SystemClock.elapsedRealtimeNanos()
        if (!firstCompleteFrameLogged) { firstCompleteFrameLogged = true; append("first complete frame timestamp_ns=$now") }
        update(info(device, UsbUvcStatus.UVC_FRAMES_RECEIVING).copy(cam1FramesAssembled = _state.value.cam1FramesAssembled + 1, cam1FramesReceived = _state.value.cam1FramesReceived + 1))
    }

    private fun decodeAndRender(device: UsbDevice, frame: ByteArray, mode: UvcSelectedMode) {
        val bitmap = try {
            if (mode.format == "YUYV") yuyvToBitmap(frame, mode.width, mode.height) else BitmapFactory.decodeByteArray(frame, 0, frame.size)
        } catch (t: Throwable) {
            append("UVC decode failed: ${t.stackTraceToString()}")
            update(info(device, UsbUvcStatus.UVC_DECODE_FAILED, t.message).copy(cam1DecodeErrors = _state.value.cam1DecodeErrors + 1))
            null
        } ?: run {
            update(info(device, UsbUvcStatus.UVC_DECODE_FAILED, "Bitmap decode returned null").copy(cam1DecodeErrors = _state.value.cam1DecodeErrors + 1))
            return
        }
        if (!firstDecodedLogged) { firstDecodedLogged = true; append("first decoded frame timestamp_ns=${SystemClock.elapsedRealtimeNanos()} size=${bitmap.width}x${bitmap.height}") }
        update(info(device, UsbUvcStatus.UVC_PREVIEW_RENDERING).copy(cam1FramesDecoded = _state.value.cam1FramesDecoded + 1))
        recordingFile?.let { file ->
            runCatching {
                if (!file.exists()) file.parentFile?.mkdirs()
                file.appendBytes(frame)
            }.onFailure { append("cam1 recording frame write failed: ${it.stackTraceToString()}") }
        }
        try {
            val tv = preview ?: return
            val canvas = tv.lockCanvas()
            if (canvas != null) {
                canvas.drawColor(Color.BLACK)
                canvas.drawBitmap(bitmap, null, android.graphics.Rect(0, 0, canvas.width, canvas.height), Paint(Paint.FILTER_BITMAP_FLAG))
                tv.unlockCanvasAndPost(canvas)
                onPreviewFrameRendered()
            }
        } catch (t: Throwable) {
            append("UVC render failed: ${t.stackTraceToString()}")
            update(info(device, UsbUvcStatus.UVC_PREVIEW_FAILED, t.message).copy(cam1RenderErrors = _state.value.cam1RenderErrors + 1))
        }
    }

    private fun scheduleNoFramesTimeout(device: UsbDevice) {
        mainHandler.postDelayed({
            if (_state.value.cam1PacketsReceived == 0L && _state.value.status == UsbUvcStatus.UVC_STREAM_OPENED) {
                append("UVC no packets timeout; packet_count=0 preview render success=false")
                update(info(device, UsbUvcStatus.UVC_NO_FRAMES_TIMEOUT, "UVC stream failed: no packets"))
            }
        }, 3_000L)
    }

    private fun update(info: UsbUvcCameraInfo): UsbUvcCameraInfo {
        val now = SystemClock.elapsedRealtimeNanos()
        val last = lastFrameNs
        val merged = info.copy(
            cam1FramesReceived = if (info.cam1FramesReceived == 0L) _state.value.cam1FramesReceived else info.cam1FramesReceived,
            cam1PacketsReceived = if (info.cam1PacketsReceived == 0L) _state.value.cam1PacketsReceived else info.cam1PacketsReceived,
            cam1FramesAssembled = if (info.cam1FramesAssembled == 0L) _state.value.cam1FramesAssembled else info.cam1FramesAssembled,
            cam1FramesDecoded = if (info.cam1FramesDecoded == 0L) _state.value.cam1FramesDecoded else info.cam1FramesDecoded,
            cam1FramesRendered = if (info.cam1FramesRendered == 0L) _state.value.cam1FramesRendered else info.cam1FramesRendered,
            cam1DecodeErrors = if (info.cam1DecodeErrors == 0L) _state.value.cam1DecodeErrors else info.cam1DecodeErrors,
            cam1RenderErrors = if (info.cam1RenderErrors == 0L) _state.value.cam1RenderErrors else info.cam1RenderErrors,
            cam1LastPacketAgeMs = lastPacketNs?.let { (now - it) / 1_000_000L },
            cam1LastFrameAgeMs = last?.let { (now - it) / 1_000_000L },
            cam1FpsEstimate = if (info.cam1FpsEstimate == 0.0) _state.value.cam1FpsEstimate else info.cam1FpsEstimate,
            selectedPixelFormat = selectedPixelFormat,
            selectedResolutionFps = selectedResolutionFps,
            selectedAltSetting = selectedAltSetting,
            selectedMaxPacketSize = selectedMaxPacketSize,
        )
        _state.value = merged; append("exact error state status=${merged.status} error=${merged.error} frame_count=${merged.cam1FramesReceived}"); return merged
    }
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
private fun UsbDevice.findIsochronousInEndpoint(): UsbEndpoint? = selectUvcInterfaces().videoStreamingAlternates.firstOrNull()?.endpoint
private fun UsbInterface.findIsochronousInEndpoint(): UsbEndpoint? = (0 until endpointCount).map { getEndpoint(it) }.firstOrNull { it.direction == UsbConstants.USB_DIR_IN && it.type == UsbConstants.USB_ENDPOINT_XFER_ISOC }
private fun UsbEndpoint.endpointTypeLabel(): String = when (type) { UsbConstants.USB_ENDPOINT_XFER_ISOC -> "isochronous"; UsbConstants.USB_ENDPOINT_XFER_BULK -> "bulk"; UsbConstants.USB_ENDPOINT_XFER_INT -> "interrupt"; UsbConstants.USB_ENDPOINT_XFER_CONTROL -> "control"; else -> "type_$type" }

private data class UvcStreamingAlternate(val usbInterface: UsbInterface, val endpoint: UsbEndpoint)
private data class UvcInterfaceSelection(val videoControl: UsbInterface?, val videoStreamingAlternates: List<UvcStreamingAlternate>)

private fun UsbDevice.detectUvcPixelFormat(log: (String) -> Unit = {}): String? {
    // Android's public USB API exposes interfaces/endpoints but not parsed UVC VS
    // frame descriptors here. Keep this conservative: report the formats this
    // adapter can diagnose once native frame payload callbacks are wired.
    val advertised = mutableSetOf<String>()
    for (i in 0 until interfaceCount) {
        val intf = getInterface(i)
        if ((intf.interfaceClass == UsbConstants.USB_CLASS_VIDEO || intf.interfaceClass == 14) && intf.interfaceSubclass == 2) {
            advertised += "MJPEG/YUYV descriptor parse pending"
        }
    }
    val result = advertised.firstOrNull()
    log("UVC pixel format detection result=${result ?: "unknown"}; MJPEG decode path expects JPEG payload to Bitmap/Surface, YUYV path expects YUYV to RGB preview conversion when frame callbacks are available")
    return result
}

private fun UsbDevice.selectUvcInterfaces(log: (String) -> Unit = {}): UvcInterfaceSelection {
    var videoControl: UsbInterface? = null
    val streamingAlternates = mutableListOf<UvcStreamingAlternate>()
    for (i in 0 until interfaceCount) {
        val intf = getInterface(i)
        log("UVC interface scan index=$i id=${intf.id} alternate=${intf.alternateSetting ?: "unknown"} class=${intf.interfaceClass} subclass=${intf.interfaceSubclass} protocol=${intf.interfaceProtocol} endpointCount=${intf.endpointCount}")
        for (e in 0 until intf.endpointCount) {
            val ep = intf.getEndpoint(e)
            log(" UVC endpoint scan interface_index=$i endpoint_index=$e number=${ep.endpointNumber} address=${ep.address} direction=${ep.direction} type=${ep.type} attributes=${ep.attributes} max_packet_size=${ep.maxPacketSize}")
        }
        val isVideo = intf.interfaceClass == UsbConstants.USB_CLASS_VIDEO || intf.interfaceClass == 14
        if (isVideo && intf.interfaceSubclass == 1 && videoControl == null) videoControl = intf
        if (isVideo && intf.interfaceSubclass == 2 && intf.endpointCount > 0) {
            intf.findIsochronousInEndpoint()?.let { streamingAlternates += UvcStreamingAlternate(intf, it) }
        }
    }
    return UvcInterfaceSelection(videoControl, streamingAlternates.sortedByDescending { it.endpoint.maxPacketSize })
}

private fun UsbDeviceConnection.trySetInterface(usbInterface: UsbInterface, log: (String) -> Unit): Boolean = try {
    setInterface(usbInterface)
} catch (t: Throwable) {
    log("setInterface exception id=${usbInterface.id} alternate=${usbInterface.alternateSetting ?: "unknown"} failure=${t.stackTraceToString()}")
    false
}

private data class UvcSelectedMode(val format: String, val width: Int, val height: Int, val fps: Int, val frameInterval100ns: Int, val maxVideoFrameSize: Int, val maxPayloadTransferSize: Int)

private fun parseUvcModes(raw: ByteArray?, log: (String) -> Unit): List<UvcSelectedMode> {
    if (raw == null) return emptyList()
    val modes = mutableListOf<UvcSelectedMode>()
    var format = "MJPEG"
    var i = 0
    while (i + 2 < raw.size) {
        val len = raw[i].toInt() and 0xff
        if (len < 3 || i + len > raw.size) break
        val type = raw[i + 1].toInt() and 0xff
        val sub = raw[i + 2].toInt() and 0xff
        if (type == 0x24) {
            if (sub == 0x06) format = "MJPEG"
            if (sub == 0x04) {
                val guid = raw.copyOfRange(i + 5, (i + 21).coerceAtMost(i + len)).toString(Charsets.ISO_8859_1)
                format = if (guid.contains("YUY2", ignoreCase = true)) "YUYV" else "UNCOMPRESSED"
            }
            if ((sub == 0x07 || sub == 0x05) && len >= 26) {
                val width = raw.le16(i + 5)
                val height = raw.le16(i + 7)
                val maxFrame = raw.le32(i + 17)
                val interval = raw.le32(i + 21).takeIf { it > 0 } ?: 333333
                val fps = (10_000_000.0 / interval).toInt().coerceAtLeast(1)
                modes += UvcSelectedMode(format, width, height, fps, interval, maxFrame, 0)
            }
        }
        i += len
    }
    modes.forEach { log("parsed UVC mode format=${it.format} resolution=${it.width}x${it.height} fps=${it.fps} interval=${it.frameInterval100ns} max_video_frame_size=${it.maxVideoFrameSize}") }
    return modes
}

private fun chooseUvcMode(modes: List<UvcSelectedMode>): UvcSelectedMode? =
    modes.sortedWith(compareBy<UvcSelectedMode>(
        { if (it.format == "MJPEG") 0 else 1 },
        { kotlin.math.abs(it.width * it.height - 640 * 480) },
        { kotlin.math.abs(it.fps - 30) },
    )).firstOrNull()

private fun ByteArray.le16(i: Int): Int = (getOrNull(i)?.toInt()?.and(0xff) ?: 0) or ((getOrNull(i + 1)?.toInt()?.and(0xff) ?: 0) shl 8)
private fun ByteArray.le32(i: Int): Int = le16(i) or (le16(i + 2) shl 16)

private fun UsbDeviceConnection.commitProbe(vs: UsbInterface?, mode: UvcSelectedMode, endpoint: UsbEndpoint, log: (String) -> Unit) {
    val intf = vs?.id ?: return
    val payload = ByteArray(26)
    payload[2] = 1
    payload[3] = 1
    fun put32(off: Int, v: Int) { payload[off] = v.toByte(); payload[off + 1] = (v shr 8).toByte(); payload[off + 2] = (v shr 16).toByte(); payload[off + 3] = (v shr 24).toByte() }
    put32(4, mode.frameInterval100ns)
    put32(18, mode.maxVideoFrameSize)
    put32(22, endpoint.maxPacketSize)
    val requestType = UsbConstants.USB_TYPE_CLASS or UsbConstants.USB_DIR_OUT or USB_RECIP_INTERFACE
    val probe = controlTransfer(requestType, 0x01, 0x0100, intf, payload, payload.size, 1000)
    log("UVC probe get/set result=$probe interface=$intf selected format=${mode.format} resolution=${mode.width}x${mode.height} fps=${mode.fps} interval=${mode.frameInterval100ns} max_video_frame_size=${mode.maxVideoFrameSize} max_payload_transfer_size=${endpoint.maxPacketSize}")
    val commit = controlTransfer(requestType, 0x01, 0x0200, intf, payload, payload.size, 1000)
    log("UVC commit result=$commit interface=$intf")
}

private class UvcFrameAssembler(private val mode: UvcSelectedMode) {
    private val out = ByteArrayOutputStream()
    fun accept(packet: ByteArray, log: (String) -> Unit): ByteArray? {
        if (packet.isEmpty()) return null
        val headerLen = (packet[0].toInt() and 0xff).coerceIn(2, packet.size)
        val flags = packet.getOrNull(1)?.toInt()?.and(0xff) ?: 0
        val eof = flags and 0x02 != 0
        val payload = packet.copyOfRange(headerLen, packet.size)
        if (mode.format == "MJPEG" && payload.size >= 2 && payload[0] == 0xff.toByte() && payload[1] == 0xd8.toByte()) out.reset()
        out.write(payload)
        val bytes = out.toByteArray()
        val jpegEoi = mode.format == "MJPEG" && bytes.size >= 2 && bytes[bytes.size - 2] == 0xff.toByte() && bytes[bytes.size - 1] == 0xd9.toByte()
        val yuyvFull = mode.format == "YUYV" && bytes.size >= mode.width * mode.height * 2
        return if (eof || jpegEoi || yuyvFull) {
            log("UVC payload frame boundary header_len=$headerLen flags=$flags eof=$eof frame_size=${bytes.size}")
            out.reset(); bytes
        } else null
    }
}

private fun yuyvToBitmap(data: ByteArray, width: Int, height: Int): Bitmap {
    val pixels = IntArray(width * height)
    var p = 0
    var i = 0
    while (i + 3 < data.size && p + 1 < pixels.size) {
        val y0 = data[i].toInt() and 0xff; val u = (data[i + 1].toInt() and 0xff) - 128; val y1 = data[i + 2].toInt() and 0xff; val v = (data[i + 3].toInt() and 0xff) - 128
        fun rgb(y: Int): Int { val r = (y + 1.402 * v).toInt().coerceIn(0, 255); val g = (y - 0.344136 * u - 0.714136 * v).toInt().coerceIn(0, 255); val b = (y + 1.772 * u).toInt().coerceIn(0, 255); return android.graphics.Color.rgb(r, g, b) }
        pixels[p++] = rgb(y0); pixels[p++] = rgb(y1); i += 4
    }
    return Bitmap.createBitmap(pixels, width, height, Bitmap.Config.ARGB_8888)
}

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
