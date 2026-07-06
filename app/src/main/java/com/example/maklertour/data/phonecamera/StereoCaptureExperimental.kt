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
import android.os.Build
import android.os.SystemClock
import android.os.Handler
import android.os.HandlerThread
import android.os.Looper
import android.util.Log
import android.view.TextureView
import androidx.camera.view.PreviewView
import androidx.lifecycle.LifecycleOwner
import com.maklertour.BuildConfig
import com.maklertour.data.rig.CameraMode
import com.maklertour.data.rig.CameraModeSelection
import org.json.JSONArray
import org.json.JSONObject
import java.io.File
import java.time.Instant
import java.time.format.DateTimeFormatter
import java.util.UUID
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow

private const val DEBUG_JAVA_UVC_BACKEND = false

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

enum class UsbUvcStatus { NOT_CONNECTED, DEVICE_FOUND, PERMISSION_MISSING, PERMISSION_REQUESTED, PERMISSION_GRANTED, PERMISSION_DENIED, OPEN_DEVICE_SUCCESS, OPEN_DEVICE_FAILED, UVC_ADAPTER_OPENING, NATIVE_LIB_MISSING, NATIVE_UVC_INIT_FAILED, NATIVE_UVC_OPEN_FAILED, NATIVE_UVC_STREAM_START_FAILED, UVC_STREAM_STARTING, UVC_STREAM_OPENED, UVC_STREAM_STARTED, UVC_FIRST_FRAME_RECEIVED, UVC_PACKETS_RECEIVING, UVC_FRAMES_ASSEMBLED, UVC_FRAMES_DECODED, UVC_PREVIEW_RENDERING, UVC_STALLED_NO_PACKETS, UVC_STALLED_NO_DECODED_FRAMES, UVC_STALLED_NO_NEW_FRAMES, UVC_DECODE_FAILED, UVC_PREVIEW_ACTIVE, UVC_RENDER_FAILED, UVC_PREVIEW_FAILED, ACTIVE, ERROR }

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
    val receiveLoopRunning: Boolean = false,
    val receiveLoopExitReason: String? = null,
    val requestQueueDepth: Int = 0,
    val lastSuccessfulTransferNs: Long? = null,
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

    fun detectUsbUvcCamera(): UsbUvcCameraInfo = usbAdapter.refreshAndRequestPermission(null, null)

    fun refreshCam1(preferredMode: CameraMode?, textureView: TextureView?): UsbUvcCameraInfo = usbAdapter.refreshAndRequestPermission(textureView, preferredMode)

    fun bindCam1Preview(textureView: TextureView): UsbUvcCameraInfo = usbAdapter.refreshAndRequestPermission(textureView, null)

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
        val cam1Width = usb.selectedWidth() ?: 1920
        val cam1Height = usb.selectedHeight() ?: 1080
        writeCameraManifest(File(bundleDir, "cam0_manifest.json"), "cam0", "phone_back", "cam0.mp4", 1920, 1080, startNs, stopNs, "estimated", Build.MODEL)
        writeCameraManifest(File(bundleDir, "cam1_manifest.json"), "cam1", "usb_uvc", "cam1.mp4", cam1Width, cam1Height, startNs, stopNs, "estimated", usb.deviceName, usb)
        val cameras = JSONArray()
            .put(cameraJson("cam0", "phone_back", config.cam0Label, "cam0.mp4", "cam0_timestamps.json", 1920, 1080, null))
            .put(cameraJson("cam1", "usb_uvc", config.cam1Label, "cam1.mp4", "cam1_timestamps.json", cam1Width, cam1Height, usb))
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
        .put("selected_alt_setting", usb.selectedAltSetting).put("selected_max_packet_size", usb.selectedMaxPacketSize).put("sync_status", "preview_not_hardware_synchronized")
        .put("packets_received", usb.cam1PacketsReceived).put("frames_assembled", usb.cam1FramesAssembled)
        .put("frames_decoded", usb.cam1FramesDecoded).put("frames_rendered", usb.cam1FramesRendered)
        .put("decode_errors", usb.cam1DecodeErrors).put("render_errors", usb.cam1RenderErrors)
    file.writeText(json.toString(2))
}

private fun cameraJson(id: String, role: String, lens: String, video: String, timestamps: String, width: Int, height: Int, usb: UsbUvcCameraInfo?): JSONObject = JSONObject().put("id", id).put("role", role).put("lens", lens).put("video", video).put("timestamps", timestamps).put("width", width).put("height", height).put("fps_target", 30).put("fps_actual", JSONObject.NULL).put("intrinsics_status", "unknown").apply { if (usb != null) { put("usb_vendor_id", usb.vendorId); put("usb_product_id", usb.productId); put("usb_device_name", usb.deviceName); put("vendor_id", usb.vendorId); put("product_id", usb.productId); put("product_name", usb.productName); put("endpoint_type", usb.endpointType) } }

private interface Cam1UvcBackend {
    fun open(deviceInfo: Cam1UvcDeviceInfo, previewSurface: android.view.Surface?)
    fun startPreview()
    fun stopPreview()
    fun startRecording(outputFile: File)
    fun stopRecording()
    fun close()
    fun snapshot(): Cam1UvcBackendState
}

private data class Cam1UvcDeviceInfo(
    val vendorId: Int,
    val productId: Int,
    val deviceName: String,
    val productName: String?,
    val fileDescriptor: Int,
    val preferredFormat: String? = null,
    val preferredWidth: Int? = null,
    val preferredHeight: Int? = null,
    val preferredFps: Int? = null,
    val preferredSelection: CameraModeSelection = CameraModeSelection.AUTO,
)

private data class Cam1UvcBackendState(
    val opened: Boolean = false,
    val previewRunning: Boolean = false,
    val recording: Boolean = false,
    val framesReceived: Long = 0L,
    val framesDecoded: Long = 0L,
    val framesRendered: Long = 0L,
    val fpsEstimate: Double = 0.0,
    val lastFrameAgeMs: Long? = null,
    val selectedFormat: String? = null,
    val selectedResolution: String? = null,
    val selectedFps: Int? = null,
    val recordedFrames: Long = 0L,
    val firstFrameTimestampNs: Long? = null,
    val error: String? = null,
)

private data class UsbPathInfo(val busNum: Int, val devAddr: Int, val usbfs: String)

private fun parseUsbDevicePath(deviceName: String): UsbPathInfo? {
    val match = Regex("^(/dev/bus/usb)/(\\d+)/(\\d+)$").matchEntire(deviceName) ?: return null
    val busNum = match.groupValues[2].toIntOrNull() ?: return null
    val devAddr = match.groupValues[3].toIntOrNull() ?: return null
    return UsbPathInfo(busNum = busNum, devAddr = devAddr, usbfs = match.groupValues[1])
}

private class NativeLibuvcCam1Backend(private val log: (String) -> Unit) : Cam1UvcBackend {
    private val lock = Any()
    private var state = Cam1UvcBackendState()
    private var outputFile: File? = null

    override fun open(deviceInfo: Cam1UvcDeviceInfo, previewSurface: android.view.Surface?) = synchronized(lock) {
        log("selected backend: native libuvc")
        val usbPathInfo = parseUsbDevicePath(deviceInfo.deviceName)
        if (usbPathInfo == null) {
            val error = "NATIVE_UVC_OPEN_FAILED: cannot parse USB device path ${deviceInfo.deviceName}"
            state = state.copy(opened = false, previewRunning = false, error = error, selectedFormat = null, selectedResolution = null, selectedFps = null)
            log(error)
            return@synchronized
        }
        log("native UVC open requested vendor=${deviceInfo.vendorId} product=${deviceInfo.productId} name=${deviceInfo.productName} deviceName=${deviceInfo.deviceName} fd=${deviceInfo.fileDescriptor} usbfs=${usbPathInfo.usbfs} busNum=${usbPathInfo.busNum} devAddr=${usbPathInfo.devAddr} surface=${previewSurface != null}")
        val result = runCatching {
            nativeOpen(deviceInfo.fileDescriptor, deviceInfo.vendorId, deviceInfo.productId, deviceInfo.deviceName, usbPathInfo.busNum, usbPathInfo.devAddr, usbPathInfo.usbfs, previewSurface, deviceInfo.preferredFormat, deviceInfo.preferredWidth ?: 0, deviceInfo.preferredHeight ?: 0, deviceInfo.preferredFps ?: 0, deviceInfo.preferredSelection == CameraModeSelection.AUTO)
        }
        state = if (result.getOrDefault(false)) {
            state.copy(opened = true, previewRunning = false, error = null, selectedFormat = null, selectedResolution = null, selectedFps = null)
        } else {
            state.copy(opened = false, previewRunning = false, error = result.exceptionOrNull()?.message ?: "NATIVE_UVC_OPEN_FAILED: native libuvc backend unavailable or failed to open", selectedFormat = null, selectedResolution = null, selectedFps = null)
        }
        log("native UVC open result opened=${state.opened} error=${state.error ?: "none"}")
    }

    override fun startPreview() = synchronized(lock) {
        val result = runCatching { nativeStartPreview() }
        val nativeError = result.exceptionOrNull()?.message ?: nativeLastError().takeIf { it.isNotBlank() }
        val error = when {
            nativeError != null -> nativeError
            !result.getOrDefault(false) -> "NATIVE_UVC_STREAM_START_FAILED: nativeStartPreview returned false"
            else -> null
        }
        state = state.copy(previewRunning = result.getOrDefault(false) && error == null, error = error)
        log("preview start result backend=native libuvc running=${state.previewRunning} selected_format=${state.selectedFormat} selected_resolution=${state.selectedResolution} selected_fps=${state.selectedFps} error=${state.error ?: "none"}")
    }

    override fun stopPreview() = synchronized(lock) { runCatching { nativeStopPreview() }; state = state.copy(previewRunning = false) }

    override fun startRecording(outputFile: File) = synchronized(lock) {
        this.outputFile = outputFile
        outputFile.parentFile?.mkdirs()
        val result = runCatching { nativeStartRecording(outputFile.absolutePath) }
        state = state.copy(recording = result.getOrDefault(false), recordedFrames = 0L, error = result.exceptionOrNull()?.message)
        log("cam1 recording requested backend=native libuvc path=${outputFile.absolutePath} started=${state.recording} error=${state.error ?: "none"}")
    }

    override fun stopRecording() = synchronized(lock) {
        runCatching { nativeStopRecording() }
        outputFile?.let { log("cam1 recording stopped path=${it.absolutePath} size=${it.length()} encoded_frames=${state.recordedFrames} valid=${it.exists() && it.length() > 0L && state.recordedFrames > 0L}") }
        state = state.copy(recording = false)
        outputFile = null
    }

    override fun close() = synchronized(lock) { runCatching { nativeClose() }; state = Cam1UvcBackendState() }

    override fun snapshot(): Cam1UvcBackendState = synchronized(lock) {
        val counters = runCatching { nativeSnapshot() }.getOrNull()
        if (counters != null && counters.size >= 8) {
            val now = SystemClock.elapsedRealtimeNanos()
            val lastNs = counters[4].takeIf { it > 0L }
            val streamRunning = counters[7] > 0L
            state = state.copy(
                previewRunning = streamRunning,
                framesReceived = counters[0], framesDecoded = counters[1], framesRendered = counters[2],
                fpsEstimate = java.lang.Double.longBitsToDouble(counters[3]),
                lastFrameAgeMs = lastNs?.let { (now - it) / 1_000_000L },
                firstFrameTimestampNs = counters[5].takeIf { it > 0L }, recordedFrames = counters[6],
                selectedFormat = if (counters.size >= 12) nativeSelectedFormatName().takeIf { it.isNotBlank() } ?: state.selectedFormat else state.selectedFormat,
                selectedResolution = if (counters.size >= 12 && counters[9] > 0L && counters[10] > 0L) "${counters[9]}x${counters[10]}" else state.selectedResolution,
                selectedFps = if (counters.size >= 12 && counters[11] > 0L) counters[11].toInt() else state.selectedFps,
                error = nativeLastError().takeIf { it.isNotBlank() },
            )
        }
        state
    }

    private external fun nativeOpen(fd: Int, vendorId: Int, productId: Int, deviceName: String, busNum: Int, devAddr: Int, usbfs: String, surface: android.view.Surface?, preferredFormat: String?, preferredWidth: Int, preferredHeight: Int, preferredFps: Int, preferredAuto: Boolean): Boolean
    private external fun nativeStartPreview(): Boolean
    private external fun nativeStopPreview()
    private external fun nativeStartRecording(path: String): Boolean
    private external fun nativeStopRecording()
    private external fun nativeClose()
    private external fun nativeSnapshot(): LongArray
    private external fun nativeSelectedFormatName(): String
    private external fun nativeLastError(): String

    companion object {
        private const val LOAD_TAG = "NativeLibuvcCam1Backend"

        init {
            listOf("usb100", "jpeg-turbo1500", "uvc", "cam1_uvc").forEach { library ->
                try {
                    System.loadLibrary(library)
                    Log.i(LOAD_TAG, "System.loadLibrary success library=$library")
                } catch (e: UnsatisfiedLinkError) {
                    Log.e(LOAD_TAG, "System.loadLibrary failure library=$library error=${e.message}")
                }
            }
        }
    }
}

private class UsbUvcCameraAdapter(private val context: Context) {
    private val usbManager = context.getSystemService(UsbManager::class.java)
    private val permissionAction = "com.maklertour.USB_PERMISSION"
    private val _state = MutableStateFlow(UsbUvcCameraInfo(UsbUvcStatus.NOT_CONNECTED))
    val state: StateFlow<UsbUvcCameraInfo> = _state.asStateFlow()
    private var logFile: File? = File(context.filesDir, "app_log.txt")
    private var preview: TextureView? = null
    private var selectedDevice: UsbDevice? = null
    private var preferredMode: CameraMode? = null
    private var activeConnection: UsbDeviceConnection? = null
    private var receiverRegistered = false
    private val mainHandler = Handler(Looper.getMainLooper())
    private val cam1Thread = HandlerThread("Cam1NativeUvcThread").also { it.start() }
    private val cam1Handler = Handler(cam1Thread.looper)
    private val backend: Cam1UvcBackend = NativeLibuvcCam1Backend(::append)
    @Volatile private var cam1Generation = 0
    @Volatile private var pollGeneration = 0
    private var lastRecordingFrameCount = 0L
    private var recordingStartFrameCount = 0L
    private var recordingFile: File? = null

    private val permissionReceiver = object : BroadcastReceiver() {
        override fun onReceive(ctx: Context, intent: Intent) {
            if (intent.action != permissionAction) return
            val device = if (Build.VERSION.SDK_INT >= 33) intent.getParcelableExtra(UsbManager.EXTRA_DEVICE, UsbDevice::class.java) else @Suppress("DEPRECATION") intent.getParcelableExtra<UsbDevice>(UsbManager.EXTRA_DEVICE)
            val granted = intent.getBooleanExtra(UsbManager.EXTRA_PERMISSION_GRANTED, false)
            append("permission callback received granted=$granted deviceName=${device?.deviceName}")
            if (granted) openAfterPermissionAsync(findSelectedDeviceWithRetry(0L) ?: device ?: selectedDevice) else update(info(device ?: selectedDevice, UsbUvcStatus.PERMISSION_DENIED, "USB permission denied"))
        }
    }

    fun attachLogFile(file: File) { logFile = file }
    fun close() { stopNativeBackend(); unregisterPermissionReceiver(); cam1Thread.quitSafely() }
    fun currentInfo(): UsbUvcCameraInfo = _state.value
    fun recordedFrameCount(): Long = lastRecordingFrameCount
    fun onPreviewFrameRendered() = Unit

    fun refreshAndRequestPermission(textureView: TextureView?, preferredMode: CameraMode?): UsbUvcCameraInfo {
        append("Refresh USB pressed")
        preview = textureView ?: preview
        this.preferredMode = preferredMode
        stopNativeBackend()
        _state.value = UsbUvcCameraInfo(UsbUvcStatus.NOT_CONNECTED)
        logUsbInventory()
        val manager = usbManager ?: return update(UsbUvcCameraInfo(UsbUvcStatus.ERROR, error = "UsbManager unavailable"))
        val device = findSelectedDeviceWithRetry(0L) ?: return update(UsbUvcCameraInfo(UsbUvcStatus.NOT_CONNECTED, error = "No UVC class 14 device found"))
        selectedDevice = device
        update(info(device, UsbUvcStatus.DEVICE_FOUND))
        val hasPermission = manager.hasPermission(device)
        append("selected deviceName=${device.deviceName} vendor=${device.vendorId} product=${device.productId} permission status=$hasPermission")
        if (!hasPermission) return requestPermissionForCurrentDevice(device)
        return openAfterPermissionAsync(device)
    }

    private fun requestPermission(device: UsbDevice) {
        registerPermissionReceiver()
        val flags = PendingIntent.FLAG_UPDATE_CURRENT or if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) PendingIntent.FLAG_MUTABLE else 0
        val pi = PendingIntent.getBroadcast(context, 13030, Intent(permissionAction).setPackage(context.packageName), flags)
        usbManager?.requestPermission(device, pi)
        append("requestPermission called deviceName=${device.deviceName}")
    }

    private fun requestPermissionForCurrentDevice(device: UsbDevice): UsbUvcCameraInfo {
        requestPermission(device)
        return update(info(device, UsbUvcStatus.PERMISSION_REQUESTED))
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
        cam1Handler.post { backend.startRecording(file) }
    }

    fun stopRecording() {
        val file = recordingFile
        val done = java.util.concurrent.CountDownLatch(1)
        cam1Handler.post {
            try {
                backend.stopRecording()
                val snap = backend.snapshot()
                lastRecordingFrameCount = (snap.framesReceived - recordingStartFrameCount).coerceAtLeast(0L)
                if (file != null) append("recording file path=${file.absolutePath} size=${file.length()} cam1_frames=${lastRecordingFrameCount}")
            } finally {
                done.countDown()
            }
        }
        done.await(2, java.util.concurrent.TimeUnit.SECONDS)
        recordingFile = null
    }

    private fun openAfterPermissionAsync(device: UsbDevice?): UsbUvcCameraInfo {
        if (device == null) return update(UsbUvcCameraInfo(UsbUvcStatus.ERROR, error = "No current USB device after permission"))
        stopNativeBackend()
        selectedDevice = device
        val generation = cam1Generation
        update(info(device, UsbUvcStatus.UVC_ADAPTER_OPENING, "native UVC opening on background thread"))
        cam1Handler.post {
            if (generation != cam1Generation) return@post
            val connection = usbManager?.openDevice(device)
            append("openDevice(currentDevice) deviceName=${device.deviceName} result=${connection != null}")
            if (connection == null) { update(info(device, UsbUvcStatus.OPEN_DEVICE_FAILED, "openDevice failed")); return@post }
            activeConnection = connection
            update(info(device, UsbUvcStatus.OPEN_DEVICE_SUCCESS))
            val surface = preview?.takeIf { it.isAvailable }?.surfaceTexture?.let { android.view.Surface(it) }
            backend.open(device.toCam1UvcDeviceInfo(connection, preferredMode), surface)
            backend.startPreview()
            val snap = backend.snapshot()
            append("selected UVC format/resolution/fps ${snap.selectedFormat ?: "none"}/${snap.selectedResolution ?: "none"}/${snap.selectedFps ?: "none"}")
            updateFromBackend(device, snap)
            startBackendPolling(device, generation)
        }
        return _state.value
    }

    private fun startBackendPolling(device: UsbDevice, generation: Int) {
        val thisPoll = ++pollGeneration
        mainHandler.post(object : Runnable {
            private var lastRendered = -1L
            private var stalledPolls = 0
            private var restartAttempts = 0
            private var lastRestartMs = 0L
            private var restartInFlight = false

            override fun run() {
                if (generation != cam1Generation || thisPoll != pollGeneration) return
                val snap = backend.snapshot()
                val framesAdvanced = snap.framesRendered > lastRendered
                if (framesAdvanced) {
                    stalledPolls = 0
                    restartInFlight = false
                    updateFromBackend(device, snap)
                } else {
                    if (snap.framesReceived > 0L && snap.framesRendered > 0L && snap.framesRendered == lastRendered) stalledPolls++ else stalledPolls = 0
                    val lastFrameAgeMs = snap.lastFrameAgeMs ?: 0L
                    val nativeStall = snap.error?.contains("NATIVE_UVC_STREAM_STALLED") == true
                    val realStall = nativeStall || (snap.framesReceived > 0L && snap.framesRendered > 0L && (stalledPolls >= 5 || lastFrameAgeMs > 2_000L))
                    if (realStall) {
                        update(stalledInfo(device, snap, if (restartAttempts >= MAX_STALL_RESTART_ATTEMPTS) "UVC stalled after restart attempts" else "UVC stalled: no new frames"))
                        maybeRestartStalledPreview(
                            device,
                            generation,
                            thisPoll,
                            snap,
                            restartAttempts,
                            lastRestartMs,
                            restartInFlight,
                            onRestartPosted = { attempted, startedAtMs ->
                                restartInFlight = attempted
                                if (attempted) {
                                    restartAttempts++
                                    lastRestartMs = startedAtMs
                                }
                            },
                            onRestartCompleted = { restartInFlight = false },
                        )
                    } else {
                        updateFromBackend(device, snap)
                    }
                }
                lastRendered = snap.framesRendered
                mainHandler.postDelayed(this, 200L)
            }
        })
    }

    private fun maybeRestartStalledPreview(
        device: UsbDevice,
        generation: Int,
        poll: Int,
        snap: Cam1UvcBackendState,
        restartAttempts: Int,
        lastRestartMs: Long,
        restartInFlight: Boolean,
        onRestartPosted: (Boolean, Long) -> Unit,
        onRestartCompleted: () -> Unit,
    ) {
        val nowMs = SystemClock.elapsedRealtime()
        if (generation != cam1Generation || poll != pollGeneration || restartInFlight) return
        val nativeStall = snap.error?.contains("NATIVE_UVC_STREAM_STALLED") == true
        if (!nativeStall && (snap.lastFrameAgeMs ?: 0L) <= 2_000L) return
        if (restartAttempts >= MAX_STALL_RESTART_ATTEMPTS) return
        if (nowMs - lastRestartMs < STALL_RESTART_RATE_LIMIT_MS) return
        append("cam1 real stall detected, fully reopening native UVC preview")
        onRestartPosted(true, nowMs)
        cam1Handler.post {
            if (generation != cam1Generation || poll != pollGeneration) return@post
            backend.stopPreview()
            backend.close()
            runCatching { activeConnection?.close() }
            activeConnection = null
            if (generation != cam1Generation || poll != pollGeneration) return@post

            val freshDevice = findSelectedDeviceWithRetry(timeoutMs = 1_500L) ?: device
            selectedDevice = freshDevice

            if (usbManager?.hasPermission(freshDevice) != true) {
                append("reopenDevice(after stall) no permission for fresh device=${freshDevice.deviceName}, requesting permission")
                requestPermission(freshDevice)
                mainHandler.post {
                    if (generation != cam1Generation || poll != pollGeneration) return@post
                    onRestartCompleted()
                    update(info(freshDevice, UsbUvcStatus.PERMISSION_REQUESTED, "USB permission requested during stalled reopen"))
                }
                return@post
            }

            val connection = usbManager?.openDevice(freshDevice)
            append("reopenDevice(after stall) deviceName=${freshDevice.deviceName} result=${connection != null}")
            if (connection == null) {
                mainHandler.post {
                    if (generation != cam1Generation || poll != pollGeneration) return@post
                    onRestartCompleted()
                    update(info(freshDevice, UsbUvcStatus.OPEN_DEVICE_FAILED, "openDevice failed during stalled UVC reopen"))
                }
                return@post
            }
            activeConnection = connection
            val surface = preview?.takeIf { it.isAvailable }?.surfaceTexture?.let { android.view.Surface(it) }
            backend.open(freshDevice.toCam1UvcDeviceInfo(connection, preferredMode), surface)
            backend.startPreview()
            val restartedSnap = backend.snapshot()
            mainHandler.post {
                if (generation != cam1Generation || poll != pollGeneration) return@post
                onRestartCompleted()
                if (restartedSnap.error != null || !restartedSnap.opened) {
                    updateFromBackend(freshDevice, restartedSnap.copy(error = restartedSnap.error ?: "UVC_PREVIEW_FAILED: stalled UVC reopen failed"))
                } else {
                    updateFromBackend(freshDevice, restartedSnap)
                }
            }
        }
    }

    private fun UsbDevice.toCam1UvcDeviceInfo(connection: UsbDeviceConnection, mode: CameraMode?): Cam1UvcDeviceInfo = Cam1UvcDeviceInfo(
        vendorId = vendorId,
        productId = productId,
        deviceName = deviceName,
        productName = productName,
        fileDescriptor = connection.fileDescriptor,
        preferredFormat = mode?.format,
        preferredWidth = mode?.width,
        preferredHeight = mode?.height,
        preferredFps = mode?.fps,
        preferredSelection = mode?.selectedBy ?: CameraModeSelection.AUTO,
    )

    private fun stalledInfo(device: UsbDevice, snap: Cam1UvcBackendState, error: String) = info(device, UsbUvcStatus.UVC_STALLED_NO_NEW_FRAMES, error).copy(
        cam1FramesReceived = snap.framesReceived,
        cam1FramesAssembled = snap.framesReceived,
        cam1FramesDecoded = snap.framesDecoded,
        cam1FramesRendered = snap.framesRendered,
        cam1FpsEstimate = snap.fpsEstimate,
        cam1LastFrameAgeMs = snap.lastFrameAgeMs,
        selectedPixelFormat = snap.selectedFormat,
        selectedResolutionFps = snap.selectedResolution?.let { "$it@${snap.selectedFps ?: 30}fps" },
    )

    private fun updateFromBackend(device: UsbDevice, snap: Cam1UvcBackendState) {
        val status = when {
            snap.error?.contains("NATIVE_LIB_MISSING") == true -> UsbUvcStatus.NATIVE_LIB_MISSING
            snap.error?.contains("NATIVE_UVC_INIT_FAILED") == true -> UsbUvcStatus.NATIVE_UVC_INIT_FAILED
            snap.error?.contains("NATIVE_UVC_OPEN_FAILED") == true -> UsbUvcStatus.NATIVE_UVC_OPEN_FAILED
            snap.error?.contains("NATIVE_UVC_STREAM_START_FAILED") == true -> UsbUvcStatus.NATIVE_UVC_STREAM_START_FAILED
            snap.error != null -> UsbUvcStatus.UVC_PREVIEW_FAILED
            snap.framesRendered > 0L && (snap.lastFrameAgeMs ?: Long.MAX_VALUE) < 1000L -> UsbUvcStatus.UVC_PREVIEW_ACTIVE
            snap.framesReceived > 0L -> UsbUvcStatus.UVC_FIRST_FRAME_RECEIVED
            snap.opened && snap.previewRunning -> UsbUvcStatus.UVC_STREAM_OPENED
            snap.opened -> UsbUvcStatus.UVC_STREAM_STARTING
            else -> UsbUvcStatus.UVC_ADAPTER_OPENING
        }
        update(info(device, status, when { snap.error != null -> snap.error; snap.framesRendered > 0L -> null; snap.framesReceived > 0L -> "native UVC first frame received"; snap.opened && snap.previewRunning && snap.framesReceived == 0L -> "real libuvc stream opened, waiting for frames"; snap.opened -> "native UVC stream starting"; else -> null }).copy(
            cam1FramesReceived = snap.framesReceived, cam1FramesAssembled = snap.framesReceived,
            cam1FramesDecoded = snap.framesDecoded, cam1FramesRendered = snap.framesRendered,
            cam1FpsEstimate = snap.fpsEstimate, cam1LastFrameAgeMs = snap.lastFrameAgeMs,
            selectedPixelFormat = snap.selectedFormat, selectedResolutionFps = snap.selectedResolution?.let { "$it@${snap.selectedFps ?: 30}fps" },
        ))
    }

    private companion object {
        private const val STALL_RESTART_RATE_LIMIT_MS = 3_000L
        private const val MAX_STALL_RESTART_ATTEMPTS = 3
    }

    private fun stopNativeBackend() {
        cam1Generation++
        pollGeneration++
        cam1Handler.post { backend.stopRecording(); backend.stopPreview(); backend.close(); runCatching { activeConnection?.close() }; activeConnection = null }
        append("native UVC stop requested generation=$cam1Generation debug_java_uvc_backend=$DEBUG_JAVA_UVC_BACKEND")
    }

    private fun update(info: UsbUvcCameraInfo): UsbUvcCameraInfo { _state.value = info; append("cam1 state status=${info.status} error=${info.error} frames_received=${info.cam1FramesReceived} frames_decoded=${info.cam1FramesDecoded} frames_rendered=${info.cam1FramesRendered} fps=${info.cam1FpsEstimate}"); return info }
    private fun info(device: UsbDevice?, status: UsbUvcStatus, error: String? = null) = UsbUvcCameraInfo(status, device?.vendorId, device?.productId, device?.deviceName, device?.productName, device?.findIsochronousInEndpoint()?.endpointTypeLabel(), error)
    private fun findSelectedDeviceWithRetry(timeoutMs: Long = 1_500L): UsbDevice? {
        val deadline = SystemClock.elapsedRealtime() + timeoutMs
        do {
            usbManager?.deviceList?.values?.sortedWith(compareBy({ if (it.vendorId == 13030 && it.productId == 37409) 0 else 1 }, { it.deviceName }))?.firstOrNull { it.isTargetUvcDevice() }?.let { return it }
            if (timeoutMs <= 0L) break
            SystemClock.sleep(100L)
        } while (SystemClock.elapsedRealtime() < deadline)
        return null
    }
    private fun registerPermissionReceiver() { if (!receiverRegistered) { ContextCompatCompat.registerReceiver(context, permissionReceiver, IntentFilter(permissionAction)); receiverRegistered = true; append("USB permission receiver registered action=$permissionAction") } }
    private fun unregisterPermissionReceiver() { if (receiverRegistered) runCatching { context.unregisterReceiver(permissionReceiver) }.onSuccess { receiverRegistered = false } }
    private fun append(message: String) { Log.d("StereoUsbUvc", message); runCatching { logFile?.appendText("${Instant.now()} $message\n") } }
}

private object ContextCompatCompat {
    fun registerReceiver(context: Context, receiver: BroadcastReceiver, filter: IntentFilter) {
        if (Build.VERSION.SDK_INT >= 33) context.registerReceiver(receiver, filter, Context.RECEIVER_NOT_EXPORTED) else context.registerReceiver(receiver, filter)
    }
}

private fun UsbDevice.isTargetUvcDevice(): Boolean = (vendorId == 13030 && productId == 37409) || looksLikeVideoDevice()
private fun UsbDevice.looksLikeVideoDevice(): Boolean = (0 until interfaceCount).any { getInterface(it).interfaceClass == UsbConstants.USB_CLASS_VIDEO }
private fun UsbDevice.findIsochronousInEndpoint(): UsbEndpoint? = (0 until interfaceCount).asSequence().map { getInterface(it) }.mapNotNull { it.findIsochronousInEndpoint() }.firstOrNull()
private fun UsbInterface.findIsochronousInEndpoint(): UsbEndpoint? = (0 until endpointCount).map { getEndpoint(it) }.firstOrNull { it.direction == UsbConstants.USB_DIR_IN && it.type == UsbConstants.USB_ENDPOINT_XFER_ISOC }
private fun UsbEndpoint.endpointTypeLabel(): String = when (type) { UsbConstants.USB_ENDPOINT_XFER_ISOC -> "isochronous"; UsbConstants.USB_ENDPOINT_XFER_BULK -> "bulk"; UsbConstants.USB_ENDPOINT_XFER_INT -> "interrupt"; UsbConstants.USB_ENDPOINT_XFER_CONTROL -> "control"; else -> "type_$type" }
private fun UsbUvcCameraInfo.selectedWidth(): Int? = selectedResolutionFps?.substringBefore("@").orEmpty().substringBefore("x").toIntOrNull()
private fun UsbUvcCameraInfo.selectedHeight(): Int? = selectedResolutionFps?.substringBefore("@").orEmpty().substringAfter("x", "").toIntOrNull()

private data class UvcStreamingAlternate(val usbInterface: UsbInterface, val endpoint: UsbEndpoint)
private data class UvcInterfaceSelection(val videoControl: UsbInterface?, val videoStreamingAlternates: List<UvcStreamingAlternate>)

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
