package com.maklertour.data.phonecamera

import android.content.Context
import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.graphics.ImageFormat
import android.graphics.Matrix
import android.graphics.Rect
import android.graphics.YuvImage
import android.hardware.camera2.CameraCaptureSession
import android.hardware.camera2.CaptureRequest
import android.hardware.camera2.TotalCaptureResult
import android.os.SystemClock
import android.util.Log
import android.util.Range
import android.util.Size
import android.view.Surface
import androidx.camera.camera2.interop.Camera2Interop
import androidx.camera.camera2.interop.ExperimentalCamera2Interop
import androidx.camera.core.Camera
import androidx.camera.core.ImageAnalysis
import androidx.camera.core.ImageProxy
import androidx.camera.core.Preview
import androidx.camera.core.resolutionselector.AspectRatioStrategy
import androidx.camera.core.resolutionselector.ResolutionSelector
import androidx.camera.core.resolutionselector.ResolutionStrategy
import androidx.camera.lifecycle.ProcessCameraProvider
import androidx.camera.video.FileOutputOptions
import androidx.camera.video.Quality
import androidx.camera.video.QualitySelector
import androidx.camera.video.Recorder
import androidx.camera.video.Recording
import androidx.camera.video.VideoCapture
import androidx.camera.video.VideoRecordEvent
import androidx.camera.view.PreviewView
import androidx.core.content.ContextCompat
import androidx.lifecycle.LifecycleOwner
import kotlinx.coroutines.CompletableDeferred
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.delay
import kotlinx.coroutines.suspendCancellableCoroutine
import kotlinx.coroutines.withContext
import kotlinx.coroutines.withTimeoutOrNull
import java.io.ByteArrayOutputStream
import java.io.File
import java.util.concurrent.ExecutorService
import java.util.concurrent.Executors
import kotlin.coroutines.resume
import kotlin.math.roundToLong

data class PhoneVideoRecordingResult(
    val path: String,
    val durationSec: Long,
    val fileSizeBytes: Long,
    val recordedDurationNs: Long = durationSec * 1_000_000_000L,
    val startCallElapsedNs: Long? = null,
    val cameraXStartElapsedNs: Long? = null,
    val finalizeElapsedNs: Long? = null,
    val frameTelemetrySummary: PhoneFrameTelemetrySummary? = null,
)

data class PhoneVideoRecordingStart(
    val path: String,
    val startCallElapsedNs: Long,
    val cameraXStartElapsedNs: Long?,
    val validEncodedDataObserved: Boolean = false,
    val recordedBytesAtReady: Long = 0L,
    val recordedDurationNsAtReady: Long = 0L,
    val recorderBindingMode: String? = null,
    val statusEventCountAtReady: Int = 0,
)

data class PhoneVideoDataHealth(
    val recordedBytes: Long,
    val recordedDurationNs: Long,
    val observedAtElapsedNs: Long,
    val statusEventCount: Int,
    val recorderBindingMode: String,
)

data class PhoneVideoAttemptDiagnostics(
    val requestedModeId: String?,
    val recorderBindingMode: String,
    val cameraXStartObserved: Boolean,
    val statusEventCount: Int,
    val lastRecordedBytes: Long,
    val lastRecordedDurationNs: Long,
    val fileSizeBytes: Long,
    val previewAttached: Boolean,
    val previewWidth: Int,
    val previewHeight: Int,
    val previewStreamState: String,
    val finalizeReceived: Boolean,
    val finalizeErrorCode: Int?,
    val finalizeErrorLabel: String?,
    val finalizeCause: String?,
) {
    fun summary(): String =
        "mode=${requestedModeId ?: "unknown"}, binding=$recorderBindingMode, " +
            "camerax_start=$cameraXStartObserved, status_events=$statusEventCount, " +
            "last_bytes=$lastRecordedBytes, last_duration_ns=$lastRecordedDurationNs, " +
            "file_size=$fileSizeBytes, preview_attached=$previewAttached, " +
            "preview_size=${previewWidth}x${previewHeight}, " +
            "preview_stream_state=$previewStreamState, " +
            "finalize_received=$finalizeReceived, " +
            "finalize_error=${finalizeErrorLabel ?: finalizeErrorCode ?: "none"}, " +
            "finalize_cause=${finalizeCause ?: "none"}"
}

class PhoneVideoNoValidDataException(
    message: String,
    val diagnostics: PhoneVideoAttemptDiagnostics? = null,
) : IllegalStateException(message)

data class PhoneVideoRecorderReadiness(
    val ready: Boolean,
    val reason: String? = null,
    val cameraId: String? = null,
    val width: Int? = null,
    val height: Int? = null,
    val fps: Int? = null,
)

class PhoneCameraVideoRecorder(private val context: Context, private val lifecycleOwner: LifecycleOwner) {
    private var recording: Recording? = null
    private var startedAtMs: Long = 0L
    private var outputFile: File? = null
    private var videoCapture: VideoCapture<Recorder>? = null
    private var finalizeDeferred: CompletableDeferred<PhoneVideoRecordingResult>? = null
    private var validDataDeferred: CompletableDeferred<PhoneVideoDataHealth>? = null
    private var headlessKeepAliveAnalysis: ImageAnalysis? = null
    private var boundPreviewView: PreviewView? = null
    @Volatile private var recorderBindingMode: String = "UNBOUND"
    @Volatile private var boundPreviewAttached: Boolean = false
    @Volatile private var boundPreviewWidth: Int = 0
    @Volatile private var boundPreviewHeight: Int = 0
    @Volatile private var lastStatusEventCount: Int = 0
    @Volatile private var lastStatusRecordedBytes: Long = 0L
    @Volatile private var lastStatusRecordedDurationNs: Long = 0L
    @Volatile private var lastPreviewStreamState: String = "UNKNOWN"
    @Volatile private var lastFinalizeErrorCode: Int? = null
    @Volatile private var lastFinalizeErrorLabel: String? = null
    @Volatile private var lastFinalizeCause: String? = null
    private val lensRepository = PhoneCameraLensRepository(context)
    private var selectedVideoInfo: SelectedPhoneVideoInfo? = null
    private var selectedLensOption: PhoneCameraLensOption? = null
    private var requestedZoomRatio: Float = lensRepository.getSelectedZoomRatio()
    private var effectiveZoomRatio: Float = requestedZoomRatio
    private var lastZoomApplyResult: String? = null
    private var boundCamera: Camera? = null
    private val latestFrameLock = Any()
    private var latestCalibrationFrame: CalibrationFrame? = null
    private val recentCalibrationFrames = CalibrationFrameRingBuffer(96)
    private val calibrationTimestampMapper = DualPhoneCalibrationTimestampMapper()
    @Volatile
    private var calibrationCameraControlStatus: String = "NOT_PREPARED"
    @Volatile
    private var calibrationMetricReadyAfterElapsedRealtimeNs: Long = Long.MAX_VALUE
    private var latestCalibrationSequence = 0L
    private val analysisExecutor: ExecutorService = Executors.newSingleThreadExecutor { runnable ->
        Thread(runnable, "Cam0CalibrationAnalysis").apply { isDaemon = true }
    }
    private var minZoomRatio: Float? = null
    private var maxZoomRatio: Float? = null
    private var requestedProfileWidth: Int? = null
    private var requestedProfileHeight: Int? = null
    private var requestedCalibrationWidth: Int? = null
    private var requestedCalibrationHeight: Int? = null
    private var calibrationResolutionReason: String? = null
    private var actualCalibrationWidth: Int? = null
    private var actualCalibrationHeight: Int? = null
    private var loggedCalibrationAnalysisFrames = 0L
    private var lastAcceptedCalibrationAnalysisNs = 0L
    private var loggedOversizedCalibrationFrameWarning = false
    private var currentTargetRotation: Int = Surface.ROTATION_0
    @Volatile private var lastStartCallElapsedNs: Long? = null
    @Volatile private var lastCameraXStartElapsedNs: Long? = null
    @Volatile private var lastFinalizeElapsedNs: Long? = null
    private val frameTelemetryRecorder = DualPhoneFrameTelemetryRecorder()
    private val frameCaptureCallback =
        object : CameraCaptureSession.CaptureCallback() {
            override fun onCaptureCompleted(
                session: CameraCaptureSession,
                request: CaptureRequest,
                result: TotalCaptureResult,
            ) {
                frameTelemetryRecorder.record(result)
            }
        }

    private val sensorTimelineCaptureCallback =
        object : CameraCaptureSession.CaptureCallback() {
            override fun onCaptureCompleted(
                session: CameraCaptureSession,
                request: CaptureRequest,
                result: TotalCaptureResult,
            ) {
                SensorTimelineDiagnostics.onCameraCapture(context, result)
            }
        }

    @OptIn(ExperimentalCamera2Interop::class)
    suspend fun bindPreview(
        previewView: PreviewView,
        cameraId: String?,
        zoomRatio: Float = lensRepository.getSelectedZoomRatio(),
        calibrationWidth: Int? = null,
        calibrationHeight: Int? = null,
        videoWidth: Int? = calibrationWidth,
        videoHeight: Int? = calibrationHeight,
        videoFps: Int? = null,
        videoMode: PhoneVideoMode? = null,
        enableVideoCapture: Boolean = true,
        enableCalibrationAnalysis: Boolean = true,
    ): PhoneCameraBindResult {
        requestedZoomRatio = if (enableCalibrationAnalysis) 1.0f else zoomRatio
        if (enableCalibrationAnalysis) {
            calibrationCameraControlStatus = "PREPARING_METRIC_CONTROLS"
            calibrationMetricReadyAfterElapsedRealtimeNs = Long.MAX_VALUE
            synchronized(latestFrameLock) {
                latestCalibrationFrame = null
            }
        }
        requestedProfileWidth = calibrationWidth
        requestedProfileHeight = calibrationHeight
        val profileRequestedSize = requestedSize(calibrationWidth, calibrationHeight)
        if (enableCalibrationAnalysis && profileRequestedSize != null) {
            require(
                profileRequestedSize.width <= METRIC_STEREO_MAX_WIDTH &&
                    profileRequestedSize.height <= METRIC_STEREO_MAX_HEIGHT,
            ) {
                "Metric stereo calibration supports up to " +
                    "${METRIC_STEREO_MAX_WIDTH}x${METRIC_STEREO_MAX_HEIGHT}; " +
                    "requested ${profileRequestedSize.width}x${profileRequestedSize.height}"
            }
        }
        val requestedSize = profileRequestedSize
        requestedCalibrationWidth = requestedSize?.width
        requestedCalibrationHeight = requestedSize?.height
        calibrationResolutionReason = null
        actualCalibrationWidth = null
        actualCalibrationHeight = null
        loggedCalibrationAnalysisFrames = 0L
        lastAcceptedCalibrationAnalysisNs = 0L
        loggedOversizedCalibrationFrameWarning = false
        currentTargetRotation = previewView.display?.rotation ?: Surface.ROTATION_0
        Log.d(TAG, "bindPreview(): start selected_camera_id=$cameraId zoom=$zoomRatio video_capture=$enableVideoCapture target_rotation=$currentTargetRotation requested_profile=${profileRequestedSize?.width}x${profileRequestedSize?.height} requested_calibration=${requestedSize?.width}x${requestedSize?.height} reason=${calibrationResolutionReason ?: "none"}")
        val cameraProvider = getCameraProvider()
        val previewBuilder =
            Preview.Builder().setTargetRotation(currentTargetRotation)
        Camera2Interop.Extender(previewBuilder)
            .setSessionCaptureCallback(sensorTimelineCaptureCallback)
        val preview = previewBuilder.build()
        Log.i(TAG, "SensorTimeline Camera2 callback attached to Preview")
        val options = lensRepository.listBackCameras()
        val requestedLens = cameraId?.let { id -> options.firstOrNull { it.cameraId == id } }
        val fallbackLens = lensRepository.selectedOrDefault().first
        val lens = requestedLens ?: fallbackLens
        val selector = lensRepository.cameraSelectorFor(lens.cameraId)
        Log.d(TAG, "Phone camera bind: selected_camera_id=${lens.cameraId} lens=${lens.lensLabel}")
        val resolvedVideoMode = resolveVideoMode(
            lens = lens,
            requestedMode = videoMode,
            videoWidth = videoWidth,
            videoHeight = videoHeight,
            videoFps = videoFps,
        )
        selectedVideoInfo = SelectedPhoneVideoInfo(
            width = resolvedVideoMode.width,
            height = resolvedVideoMode.height,
            fps = resolvedVideoMode.fps,
        )
        val preparedVideoCapture = if (enableVideoCapture) buildVideoCapture(resolvedVideoMode) else null
        preview.setSurfaceProvider(previewView.surfaceProvider)
        val previousLens = selectedLensOption
        try {
            cameraProvider.unbindAll()
            val camera = bindWithCalibrationFallbacks(
                cameraProvider = cameraProvider,
                selector = selector,
                preview = preview,
                videoCapture = preparedVideoCapture,
                requestedSize = requestedSize,
                enableCalibrationAnalysis = enableCalibrationAnalysis,
            )
            boundCamera = camera
            videoCapture = preparedVideoCapture
            headlessKeepAliveAnalysis = null
            boundPreviewView = previewView
            recorderBindingMode = "APP_PREVIEW_BACKED"
            boundPreviewAttached = previewView.isAttachedToWindow
            boundPreviewWidth = previewView.width
            boundPreviewHeight = previewView.height
            selectedLensOption = lens
            applySelectedZoom(camera)
        } catch (e: Throwable) {
            Log.e(TAG, "bindPreview(): selected camera bind failed selected_camera_id=${lens.cameraId}", e)
            val recoveryLens = previousLens ?: fallbackLens.takeIf { it.cameraId != lens.cameraId }
            if (previousLens != null && previousLens.cameraId != lens.cameraId) {
                runCatching {
                    val previousPreview = Preview.Builder().setTargetRotation(currentTargetRotation).build()
                    val previousVideoCapture = if (enableVideoCapture) buildVideoCapture(resolvedVideoMode) else null
                    previousPreview.setSurfaceProvider(previewView.surfaceProvider)
                    val camera = bindWithCalibrationFallbacks(
                        cameraProvider = cameraProvider,
                        selector = lensRepository.cameraSelectorFor(previousLens.cameraId),
                        preview = previousPreview,
                        videoCapture = previousVideoCapture,
                        requestedSize = requestedSize,
                        enableCalibrationAnalysis = enableCalibrationAnalysis,
                    )
                    boundCamera = camera
                    videoCapture = previousVideoCapture
                    headlessKeepAliveAnalysis = null
                    boundPreviewView = previewView
                    recorderBindingMode = "APP_PREVIEW_BACKED"
                    boundPreviewAttached = previewView.isAttachedToWindow
                    boundPreviewWidth = previewView.width
                    boundPreviewHeight = previewView.height
                    selectedLensOption = previousLens
                    applySelectedZoom(camera)
                    Log.w(TAG, "bindPreview(): kept previous working camera_id=${previousLens.cameraId}")
                }
            } else if (recoveryLens != null) {
                runCatching {
                    val fallbackPreview = Preview.Builder().setTargetRotation(currentTargetRotation).build()
                    val fallbackVideoCapture = if (enableVideoCapture) buildVideoCapture(resolvedVideoMode) else null
                    fallbackPreview.setSurfaceProvider(previewView.surfaceProvider)
                    val camera = bindWithCalibrationFallbacks(
                        cameraProvider = cameraProvider,
                        selector = lensRepository.cameraSelectorFor(recoveryLens.cameraId),
                        preview = fallbackPreview,
                        videoCapture = fallbackVideoCapture,
                        requestedSize = requestedSize,
                        enableCalibrationAnalysis = enableCalibrationAnalysis,
                    )
                    boundCamera = camera
                    videoCapture = fallbackVideoCapture
                    headlessKeepAliveAnalysis = null
                    boundPreviewView = previewView
                    recorderBindingMode = "APP_PREVIEW_BACKED"
                    boundPreviewAttached = previewView.isAttachedToWindow
                    boundPreviewWidth = previewView.width
                    boundPreviewHeight = previewView.height
                    selectedLensOption = recoveryLens
                    applySelectedZoom(camera)
                    Log.w(TAG, "bindPreview(): selected camera failed; fallback camera_id=${recoveryLens.cameraId} lens=${recoveryLens.lensLabel}")
                }
            }
            throw IllegalStateException("preview bind failed: ${e.message}", e)
        }
        if (enableCalibrationAnalysis) {
            val calibrationCamera = boundCamera
            if (calibrationCamera != null) {
                val timestampSource =
                    DualPhoneCalibrationCameraControls.timestampSource(calibrationCamera)
                calibrationTimestampMapper.reset(timestampSource)
                val preparedControlStatus = runCatching {
                    DualPhoneCalibrationCameraControls.prepare(
                        camera = calibrationCamera,
                        previewView = previewView,
                    )
                }.getOrElse { error ->
                    "PREPARE_FAILED:${error.message ?: error.javaClass.simpleName}"
                }
                val cameraId = selectedLensOption?.cameraId
                val focusMode = cameraId?.let(lensRepository::getSelectedFocusMode)
                    ?: PhoneCameraFocusMode.AUTO
                val focusAwareControlStatus =
                    if (
                        preparedControlStatus.startsWith("METRIC_READY") &&
                        focusMode == PhoneCameraFocusMode.INFINITY_FIXED
                    ) {
                        val fixedStatus = runCatching {
                            DualPhoneCalibrationCameraControls.setInfinityFocus(
                                calibrationCamera,
                            )
                        }.getOrElse { error ->
                            "FOCUS_INFINITY_ERROR:" +
                                (error.message ?: error.javaClass.simpleName)
                        }
                        if (fixedStatus == "FOCUS_INFINITY_LOCKED") {
                            "$preparedControlStatus,$fixedStatus"
                        } else {
                            "METRIC_NOT_READY,$fixedStatus"
                        }
                    } else {
                        preparedControlStatus
                    }
                if (focusAwareControlStatus.startsWith("METRIC_READY")) {
                    // Frames produced while zoom/stabilization/focus options were changing
                    // must never enter metric calibration.
                    delay(300L)
                    synchronized(latestFrameLock) {
                        latestCalibrationFrame = null
                    }
                }
                calibrationMetricReadyAfterElapsedRealtimeNs =
                    if (focusAwareControlStatus.startsWith("METRIC_READY")) {
                        SystemClock.elapsedRealtimeNanos()
                    } else {
                        Long.MAX_VALUE
                    }
                calibrationCameraControlStatus = focusAwareControlStatus
                if (calibrationCameraControlStatus.contains("ZOOM_1X_LOCKED")) {
                    requestedZoomRatio = 1.0f
                    effectiveZoomRatio =
                        calibrationCamera.cameraInfo.zoomState.value?.zoomRatio ?: 1.0f
                }
                Log.i(
                    TAG,
                    "calibration camera prepared timestamp_source=$timestampSource " +
                        "focus=$focusMode controls=$calibrationCameraControlStatus",
                )
            }
        } else {
            val regularCamera = boundCamera
            if (
                regularCamera != null &&
                calibrationCameraControlStatus != "NOT_PREPARED"
            ) {
                calibrationCameraControlStatus = runCatching {
                    DualPhoneCalibrationCameraControls.release(regularCamera)
                }.getOrElse { error ->
                    "RELEASE_FAILED:${error.message ?: error.javaClass.simpleName}"
                }
            }
            if (regularCamera != null) {
                val cameraId = selectedLensOption?.cameraId
                val focusMode = cameraId?.let(lensRepository::getSelectedFocusMode)
                    ?: PhoneCameraFocusMode.AUTO
                if (focusMode == PhoneCameraFocusMode.INFINITY_FIXED) {
                    val focusStatus = runCatching {
                        DualPhoneCalibrationCameraControls.setInfinityFocus(
                            regularCamera,
                        )
                    }.getOrElse { error ->
                        "FOCUS_INFINITY_ERROR:" +
                            (error.message ?: error.javaClass.simpleName)
                    }
                    check(focusStatus == "FOCUS_INFINITY_LOCKED") {
                        "Saved fixed focus could not be restored for " +
                            "${cameraId ?: "unknown-camera"}: $focusStatus"
                    }
                }
                Log.i(
                    TAG,
                    "regular camera focus restored camera_id=${cameraId ?: "-"} " +
                        "focus=$focusMode",
                )
            }
        }
        Log.d(TAG, "bindPreview(): success video_mode=${resolvedVideoMode.id}")
        return getBindResult(success = true)
    }

    private fun resolveVideoMode(
        lens: PhoneCameraLensOption,
        requestedMode: PhoneVideoMode?,
        videoWidth: Int?,
        videoHeight: Int?,
        videoFps: Int?,
    ): PhoneVideoMode {
        requestedMode?.let { return it }
        if (videoWidth != null && videoHeight != null) {
            return PhoneVideoMode(
                width = videoWidth,
                height = videoHeight,
                fps = videoFps ?: 30,
                qualityKey = PhoneVideoModePolicy.qualityKeyFor(videoWidth, videoHeight),
            )
        }
        return lensRepository.getSelectedVideoMode(
            lens.cameraId,
            lens.supportedVideoModes,
        ) ?: PhoneVideoMode(1280, 720, 30, "HD")
    }

    @OptIn(ExperimentalCamera2Interop::class)
    private fun buildVideoCapture(mode: PhoneVideoMode): VideoCapture<Recorder> {
        val quality = when (mode.qualityKey) {
            "UHD" -> Quality.UHD
            "FHD" -> Quality.FHD
            else -> Quality.HD
        }
        val recorder = Recorder.Builder()
            .setQualitySelector(QualitySelector.from(quality))
            .build()
        val builder = VideoCapture.Builder(recorder)
            .setTargetRotation(currentTargetRotation)
            .setTargetFrameRate(Range(mode.fps, mode.fps))
        Camera2Interop.Extender(builder)
            .setSessionCaptureCallback(frameCaptureCallback)
        return builder.build()
    }

    private suspend fun awaitDualPhonePreviewView(): PreviewView? =
        withTimeoutOrNull(PREVIEW_SURFACE_TIMEOUT_MS) {
            var ready: PreviewView? = null
            while (ready == null) {
                val candidate = DualPhoneRecorderPreviewRegistry.current()
                if (
                    candidate != null &&
                    candidate.isAttachedToWindow &&
                    candidate.width > 0 &&
                    candidate.height > 0
                ) {
                    ready = candidate
                } else {
                    delay(50L)
                }
            }
            ready
        }

    private suspend fun awaitPreviewStreaming(previewView: PreviewView): Boolean =
        withTimeoutOrNull(PREVIEW_STREAMING_TIMEOUT_MS) {
            while (
                previewView.previewStreamState.value !=
                    PreviewView.StreamState.STREAMING
            ) {
                lastPreviewStreamState =
                    previewView.previewStreamState.value?.name ?: "UNKNOWN"
                delay(50L)
            }
            lastPreviewStreamState = PreviewView.StreamState.STREAMING.name
            true
        } == true

    private fun buildHeadlessKeepAliveAnalysis(): ImageAnalysis =
        ImageAnalysis.Builder()
            .setBackpressureStrategy(ImageAnalysis.STRATEGY_KEEP_ONLY_LATEST)
            .setTargetRotation(currentTargetRotation)
            .setResolutionSelector(
                ResolutionSelector.Builder()
                    .setAspectRatioStrategy(
                        AspectRatioStrategy.RATIO_16_9_FALLBACK_AUTO_STRATEGY,
                    )
                    .setResolutionStrategy(
                        ResolutionStrategy(
                            Size(640, 360),
                            ResolutionStrategy.FALLBACK_RULE_CLOSEST_LOWER_THEN_HIGHER,
                        ),
                    )
                    .build(),
            )
            .build()
            .also { analysis ->
                analysis.setAnalyzer(analysisExecutor) { image -> image.close() }
            }

    fun getLatestCalibrationFrame(): CalibrationFrame? = synchronized(latestFrameLock) { latestCalibrationFrame }

    fun getRecentCalibrationFrames(): List<CalibrationFrame> = recentCalibrationFrames.snapshot()

    private fun bindWithCalibrationFallbacks(
        cameraProvider: ProcessCameraProvider,
        selector: androidx.camera.core.CameraSelector,
        preview: Preview,
        videoCapture: VideoCapture<Recorder>?,
        requestedSize: Size?,
        enableCalibrationAnalysis: Boolean,
    ): Camera {
        if (!enableCalibrationAnalysis) {
            return if (videoCapture != null) {
                cameraProvider.bindToLifecycle(
                    lifecycleOwner,
                    selector,
                    preview,
                    videoCapture,
                )
            } else {
                cameraProvider.bindToLifecycle(
                    lifecycleOwner,
                    selector,
                    preview,
                )
            }
        }
        val sizes = if (requestedSize != null) {
            listOf(requestedSize)
        } else {
            listOf(Size(1280, 720), Size(640, 480))
        }
        var lastError: Throwable? = null
        for (size in sizes) {
            val analysis = buildCalibrationAnalysis(size.width, size.height)
            try {
                val camera = if (videoCapture != null) {
                    cameraProvider.bindToLifecycle(lifecycleOwner, selector, preview, videoCapture, analysis)
                } else {
                    cameraProvider.bindToLifecycle(lifecycleOwner, selector, preview, analysis)
                }
                if (requestedSize != null && (size.width != requestedSize.width || size.height != requestedSize.height)) {
                    Log.w(TAG, "cam0 calibration resolution fallback requested=${requestedSize.width}x${requestedSize.height} selected=${size.width}x${size.height}")
                }
                return camera
            } catch (t: Throwable) {
                lastError = t
                runCatching { cameraProvider.unbindAll() }
                if (requestedSize != null) {
                    Log.w(TAG, "cam0 calibration bind failed requested=${requestedSize.width}x${requestedSize.height} selected=${size.width}x${size.height}: ${t.message}")
                }
            }
        }
        throw lastError ?: IllegalStateException("cam0 calibration bind failed")
    }

    @Suppress("DEPRECATION")
    private fun buildCalibrationAnalysis(width: Int?, height: Int?): ImageAnalysis {
        val builder = ImageAnalysis.Builder()
            .setBackpressureStrategy(ImageAnalysis.STRATEGY_KEEP_ONLY_LATEST)
        if (width != null && height != null && width > 0 && height > 0) {
            builder.setResolutionSelector(
                ResolutionSelector.Builder()
                    .setAspectRatioStrategy(AspectRatioStrategy.RATIO_16_9_FALLBACK_AUTO_STRATEGY)
                    .setResolutionStrategy(ResolutionStrategy(Size(width, height), ResolutionStrategy.FALLBACK_RULE_CLOSEST_LOWER_THEN_HIGHER))
                    .build()
            )
        }
        return builder.setTargetRotation(currentTargetRotation).build()
            .also { analyzer ->
                analyzer.setAnalyzer(analysisExecutor) { imageProxy ->
                    updateLatestCalibrationFrame(imageProxy)
                }
            }
    }

    private fun requestedSize(width: Int?, height: Int?): Size? = if (width != null && height != null && width > 0 && height > 0) Size(width, height) else null

    private fun cappedCalibrationAnalysisSize(size: Size?): Size? {
        if (size == null) return null
        if (size.width <= CALIBRATION_ANALYSIS_MAX_WIDTH && size.height <= CALIBRATION_ANALYSIS_MAX_HEIGHT) return size
        return Size(CALIBRATION_ANALYSIS_MAX_WIDTH, CALIBRATION_ANALYSIS_MAX_HEIGHT)
    }

    private fun updateLatestCalibrationFrame(imageProxy: ImageProxy) {
        try {
            val callbackElapsedRealtimeNs = android.os.SystemClock.elapsedRealtimeNanos()
            if (
                lastAcceptedCalibrationAnalysisNs != 0L &&
                callbackElapsedRealtimeNs - lastAcceptedCalibrationAnalysisNs <
                CALIBRATION_ANALYSIS_MIN_INTERVAL_NS
            ) return
            lastAcceptedCalibrationAnalysisNs = callbackElapsedRealtimeNs
            actualCalibrationWidth = imageProxy.width
            actualCalibrationHeight = imageProxy.height
            val expectedWidth = requestedCalibrationWidth
            val expectedHeight = requestedCalibrationHeight
            if (
                expectedWidth != null &&
                expectedHeight != null &&
                (imageProxy.width != expectedWidth || imageProxy.height != expectedHeight)
            ) {
                calibrationResolutionReason =
                    "actual_resolution_mismatch:" +
                        "${imageProxy.width}x${imageProxy.height}!=" +
                        "${expectedWidth}x${expectedHeight}"
                if (!loggedOversizedCalibrationFrameWarning) {
                    loggedOversizedCalibrationFrameWarning = true
                    Log.e(
                        TAG,
                        "cam0 calibration frame rejected: ${calibrationResolutionReason}",
                    )
                }
                return
            }
            if (imageProxy.width * imageProxy.height > CALIBRATION_ANALYSIS_MAX_PIXELS_FOR_CONVERSION) {
                if (!loggedOversizedCalibrationFrameWarning) {
                    loggedOversizedCalibrationFrameWarning = true
                    Log.w(TAG, "cam0 calibration frame skipped: actual resolution too large actual=${imageProxy.width}x${imageProxy.height} requested=${requestedCalibrationWidth}x${requestedCalibrationHeight}")
                }
                return
            }
            val conversionStartNs = android.os.SystemClock.elapsedRealtimeNanos()
            val rawBitmap = imageProxy.toNv21Bitmap() ?: return
            val imageProxyRotationDegrees = imageProxy.imageInfo.rotationDegrees

            // IMPORTANT:
            // Do not apply CameraX display rotation to saved calibration/depth frames.
            // Preview orientation is a UI concern. Saved stereo frames must preserve the
            // requested camera analysis size, e.g. 1280x720, to match cam1 UVC frames.
            val rotationDegrees = 0
            val bitmap = rawBitmap
            val conversionMs = ((android.os.SystemClock.elapsedRealtimeNanos() - conversionStartNs) / 1_000_000.0).roundToLong()
            val timestampNs = imageProxy.imageInfo.timestamp
            val captureElapsedRealtimeNs = calibrationTimestampMapper.toElapsedRealtimeNs(
                cameraTimestampNs = timestampNs,
                observedElapsedRealtimeNs = callbackElapsedRealtimeNs,
            )
            SensorTimelineDiagnostics.onMappedCameraFrame(
                context = context,
                cameraElapsedRealtimeNs = captureElapsedRealtimeNs,
                rawCameraTimestampNs = timestampNs,
                cameraTimestampSource = calibrationTimestampMapper.sourceName,
                receiveElapsedRealtimeNs = callbackElapsedRealtimeNs,
            )
            val ageMs = (
                android.os.SystemClock.elapsedRealtimeNanos() - captureElapsedRealtimeNs
            ) / 1_000_000L
            val currentControlStatus = calibrationCameraControlStatus
            val frameControlStatus = if (
                currentControlStatus.startsWith("METRIC_READY") &&
                captureElapsedRealtimeNs >= calibrationMetricReadyAfterElapsedRealtimeNs
            ) {
                currentControlStatus
            } else {
                "PREPARING_METRIC_CONTROLS"
            }
            loggedCalibrationAnalysisFrames += 1L
            if (loggedCalibrationAnalysisFrames <= 10L || loggedCalibrationAnalysisFrames % 30L == 0L) {
                Log.d(TAG, "cam0 analysis actual=${imageProxy.width}x${imageProxy.height} imageProxyRotationDegrees=$imageProxyRotationDegrees targetRotation=$currentTargetRotation before=${rawBitmap.width}x${rawBitmap.height} after=${bitmap.width}x${bitmap.height} requested=${requestedCalibrationWidth}x${requestedCalibrationHeight} age=${ageMs}ms conversion_ms=$conversionMs saved frame cam0=${bitmap.width}x${bitmap.height} rotationApplied=$rotationDegrees")
            }
            synchronized(latestFrameLock) {
                latestCalibrationSequence += 1L
                val frame = CalibrationFrame(
                    bitmap = bitmap,
                    timestampNs = timestampNs,
                    sequence = latestCalibrationSequence,
                    captureElapsedRealtimeNs = captureElapsedRealtimeNs,
                    timestampSource = calibrationTimestampMapper.sourceName,
                    cameraControlStatus = frameControlStatus,
                    rotationDegreesApplied = rotationDegrees,
                    imageProxyRotationDegrees = imageProxyRotationDegrees,
                    rawWidth = imageProxy.width,
                    rawHeight = imageProxy.height,
                    savedWidth = bitmap.width,
                    savedHeight = bitmap.height,
                    displayRotationAtCapture = currentTargetRotation,
                    appOrientationAtCapture =
                        if (bitmap.width >= bitmap.height) "landscape" else "portrait",
                )
                latestCalibrationFrame = frame
                recentCalibrationFrames.add(frame)
                if (loggedCalibrationAnalysisFrames <= 10L || loggedCalibrationAnalysisFrames % 30L == 0L) Log.d(TAG, "calibration frame ring-buffer saved size cam0=${frame.savedWidth}x${frame.savedHeight} rotationApplied=${frame.rotationDegreesApplied}")
            }
        } finally {
            imageProxy.close()
        }
    }

    private fun rotateBitmapForDisplay(bitmap: Bitmap, rotationDegrees: Int): Bitmap {
        val normalized = ((rotationDegrees % 360) + 360) % 360
        if (normalized == 0) return bitmap
        val matrix = Matrix().apply { postRotate(normalized.toFloat()) }
        return Bitmap.createBitmap(bitmap, 0, 0, bitmap.width, bitmap.height, matrix, true)
    }

    private fun ImageProxy.toNv21Bitmap(): Bitmap? {
        if (format != ImageFormat.YUV_420_888) return null
        val nv21 = yuv420888ToNv21(this)
        val yuv = YuvImage(nv21, ImageFormat.NV21, width, height, null)
        val out = ByteArrayOutputStream()
        yuv.compressToJpeg(Rect(0, 0, width, height), 92, out)
        return BitmapFactory.decodeByteArray(out.toByteArray(), 0, out.size())
    }

    private fun yuv420888ToNv21(image: ImageProxy): ByteArray {
        val ySize = image.width * image.height
        val uvSize = image.width * image.height / 4
        val nv21 = ByteArray(ySize + uvSize * 2)
        val yPlane = image.planes[0]
        val uPlane = image.planes[1]
        val vPlane = image.planes[2]
        var offset = 0
        for (row in 0 until image.height) {
            yPlane.buffer.position(row * yPlane.rowStride)
            yPlane.buffer.get(nv21, offset, image.width)
            offset += image.width
        }
        val chromaHeight = image.height / 2
        val chromaWidth = image.width / 2
        for (row in 0 until chromaHeight) {
            for (col in 0 until chromaWidth) {
                val vuIndex = ySize + row * image.width + col * 2
                vPlane.buffer.position(row * vPlane.rowStride + col * vPlane.pixelStride)
                nv21[vuIndex] = vPlane.buffer.get()
                uPlane.buffer.position(row * uPlane.rowStride + col * uPlane.pixelStride)
                nv21[vuIndex + 1] = uPlane.buffer.get()
            }
        }
        return nv21
    }

    fun getSelectedVideoInfo(): SelectedPhoneVideoInfo? = selectedVideoInfo

    fun getCalibrationResolutionInfo(): PhoneCalibrationResolutionInfo = PhoneCalibrationResolutionInfo(
        requestedWidth = requestedCalibrationWidth,
        requestedHeight = requestedCalibrationHeight,
        actualWidth = actualCalibrationWidth,
        actualHeight = actualCalibrationHeight,
        requestedProfileWidth = requestedProfileWidth,
        requestedProfileHeight = requestedProfileHeight,
        reason = calibrationResolutionReason,
    )

    suspend fun refreshCalibrationFocus(
        normalizedX: Double,
        normalizedY: Double,
    ): String = withContext(Dispatchers.Main.immediate) {
        val camera = boundCamera
            ?: return@withContext "AF_BOARD_NO_BOUND_CAMERA"
        val previewView = boundPreviewView
            ?: return@withContext "AF_BOARD_NO_PREVIEW"
        val previousStatus = calibrationCameraControlStatus
        if (!previousStatus.startsWith("METRIC_READY")) {
            return@withContext previousStatus
        }

        calibrationCameraControlStatus = "AF_REFRESHING"
        calibrationMetricReadyAfterElapsedRealtimeNs = Long.MAX_VALUE
        synchronized(latestFrameLock) {
            latestCalibrationFrame = null
        }

        val focusStatus = runCatching {
            DualPhoneCalibrationCameraControls.restoreAutofocus(
                camera = camera,
                previewView = previewView,
                normalizedX = normalizedX,
                normalizedY = normalizedY,
            )
        }.getOrElse { error ->
            "AF_BOARD_ERROR:${error.message ?: error.javaClass.simpleName}"
        }

        synchronized(latestFrameLock) {
            latestCalibrationFrame = null
        }
        val ready = focusStatus.startsWith("METRIC_READY")
        calibrationMetricReadyAfterElapsedRealtimeNs =
            if (ready) SystemClock.elapsedRealtimeNanos() else Long.MAX_VALUE
        calibrationCameraControlStatus = focusStatus
        if (ready) {
            selectedLensOption?.cameraId?.let { cameraId ->
                lensRepository.saveSelectedFocusMode(
                    cameraId,
                    PhoneCameraFocusMode.AUTO,
                )
            }
        }
        calibrationCameraControlStatus
    }

    suspend fun setCalibrationInfinityFocus(): String =
        withContext(Dispatchers.Main.immediate) {
            val camera = boundCamera
                ?: return@withContext "FOCUS_INFINITY_NO_BOUND_CAMERA"
            val previousStatus = calibrationCameraControlStatus
            if (!previousStatus.startsWith("METRIC_READY")) {
                return@withContext previousStatus
            }

            calibrationCameraControlStatus = "FOCUS_INFINITY_APPLYING"
            calibrationMetricReadyAfterElapsedRealtimeNs = Long.MAX_VALUE
            synchronized(latestFrameLock) {
                latestCalibrationFrame = null
            }

            val focusStatus = runCatching {
                DualPhoneCalibrationCameraControls.setInfinityFocus(camera)
            }.getOrElse { error ->
                "FOCUS_INFINITY_ERROR:${error.message ?: error.javaClass.simpleName}"
            }
            val preservedMetricStatus = previousStatus
                .split(',')
                .filterNot {
                    it.startsWith("AF_") ||
                        it.startsWith("FOCUS_")
                }
                .joinToString(",")
            synchronized(latestFrameLock) {
                latestCalibrationFrame = null
            }
            val ready = focusStatus == "FOCUS_INFINITY_LOCKED"
            calibrationMetricReadyAfterElapsedRealtimeNs =
                if (ready) SystemClock.elapsedRealtimeNanos() else Long.MAX_VALUE
            calibrationCameraControlStatus =
                if (ready) {
                    "$preservedMetricStatus,$focusStatus"
                } else {
                    focusStatus
                }
            if (ready) {
                selectedLensOption?.cameraId?.let { cameraId ->
                    lensRepository.saveSelectedFocusMode(
                        cameraId,
                        PhoneCameraFocusMode.INFINITY_FIXED,
                    )
                }
            }
            calibrationCameraControlStatus
        }

    fun getSelectedLensOption(): PhoneCameraLensOption? = selectedLensOption

    fun getSelectedZoomRatio(): Float = effectiveZoomRatio

    fun getRequestedZoomRatio(): Float = requestedZoomRatio

    fun getEffectiveZoomRatio(): Float = effectiveZoomRatio

    fun getZoomState(): PhoneCameraZoomState = PhoneCameraZoomState(
        requestedZoomRatio = requestedZoomRatio,
        effectiveZoomRatio = effectiveZoomRatio,
        minZoomRatio = minZoomRatio,
        maxZoomRatio = maxZoomRatio,
        cameraId = selectedLensOption?.cameraId,
        bindStatus = if (boundCamera != null) "bound" else "not_bound",
        error = lastZoomApplyResult?.takeIf { it.startsWith("error") },
        cameraXZoomStateCurrent = boundCamera?.cameraInfo?.zoomState?.value?.zoomRatio,
    )

    fun getMinZoomRatio(): Float? = minZoomRatio

    fun getMaxZoomRatio(): Float? = maxZoomRatio

    fun getZoomWarning(): String? = if (kotlin.math.abs(requestedZoomRatio - effectiveZoomRatio) > 0.01f) "requested ${zoomPresetLabel(requestedZoomRatio)} but CameraX applied ${zoomPresetLabel(effectiveZoomRatio)}" else null

    fun getRecordingReadiness(): PhoneVideoRecorderReadiness {
        val info = selectedVideoInfo
        val lens = selectedLensOption
        return when {
            recording != null -> PhoneVideoRecorderReadiness(
                ready = false,
                reason = "Phone camera is already recording",
                cameraId = lens?.cameraId,
                width = info?.width,
                height = info?.height,
                fps = info?.fps,
            )
            boundCamera == null || videoCapture == null ->
                PhoneVideoRecorderReadiness(
                    ready = false,
                    reason =
                        "Camera preview with video capture is not bound",
                    cameraId = lens?.cameraId,
                    width = info?.width,
                    height = info?.height,
                    fps = info?.fps,
                )
            lens == null || info?.width == null ||
                info.height == null || info.fps == null ->
                PhoneVideoRecorderReadiness(
                    ready = false,
                    reason =
                        "Resolved camera/video mode is unavailable",
                    cameraId = lens?.cameraId,
                    width = info?.width,
                    height = info?.height,
                    fps = info?.fps,
                )
            else -> PhoneVideoRecorderReadiness(
                ready = true,
                cameraId = lens.cameraId,
                width = info.width,
                height = info.height,
                fps = info.fps,
            )
        }
    }

    suspend fun ensureRecordingReady(
        preferredVideoModeId: String?,
        forceRebind: Boolean = false,
        requirePreviewSurface: Boolean = false,
    ): PhoneVideoRecorderReadiness =
        withContext(Dispatchers.Main.immediate) {
            val requiredPreview = if (requirePreviewSurface) {
                awaitDualPhonePreviewView()
            } else {
                null
            }
            if (requirePreviewSurface && requiredPreview == null) {
                val info = selectedVideoInfo
                return@withContext PhoneVideoRecorderReadiness(
                    ready = false,
                    reason = "Dual-phone recorder PreviewView is not attached after " +
                        "$PREVIEW_SURFACE_TIMEOUT_MS ms. Keep the settings screen visible.",
                    cameraId = selectedLensOption?.cameraId,
                    width = info?.width,
                    height = info?.height,
                    fps = info?.fps,
                )
            }
            val current = getRecordingReadiness()
            val existingPreviewMatches = !requirePreviewSurface || (
                recorderBindingMode == "DUAL_PHONE_PREVIEW_BACKED" &&
                    boundPreviewView === requiredPreview &&
                    boundPreviewAttached
                )
            if (
                !forceRebind &&
                existingPreviewMatches &&
                (current.ready || recording != null)
            ) {
                return@withContext current
            }
            check(recording == null) { "Cannot rebind CameraX while recording" }

            try {
                val lens = lensRepository.selectedOrDefault().first
                val modes = lens.supportedVideoModes
                val mode = modes.firstOrNull {
                    it.id == preferredVideoModeId
                } ?: lensRepository.getSelectedVideoMode(
                    lens.cameraId,
                    modes,
                ) ?: throw IllegalStateException(
                    "No supported video mode for camera ${lens.cameraId}",
                )

                if (requiredPreview != null) {
                    currentTargetRotation = requiredPreview.display?.rotation
                        ?: currentTargetRotation
                }
                val cameraProvider = getCameraProvider()
                val preparedVideoCapture = buildVideoCapture(mode)
                val preparedPreview = requiredPreview?.let { previewView ->
                    Preview.Builder()
                        .setTargetRotation(currentTargetRotation)
                        .build()
                        .also { it.setSurfaceProvider(previewView.surfaceProvider) }
                }
                val keepAliveAnalysis = if (preparedPreview == null) {
                    buildHeadlessKeepAliveAnalysis()
                } else {
                    null
                }
                requestedZoomRatio = lensRepository.getSelectedZoomRatio()
                selectedVideoInfo = SelectedPhoneVideoInfo(
                    width = mode.width,
                    height = mode.height,
                    fps = mode.fps,
                )
                selectedLensOption = lens

                cameraProvider.unbindAll()
                val selector = lensRepository.cameraSelectorFor(lens.cameraId)
                val camera = if (preparedPreview != null) {
                    cameraProvider.bindToLifecycle(
                        lifecycleOwner,
                        selector,
                        preparedPreview,
                        preparedVideoCapture,
                    )
                } else {
                    cameraProvider.bindToLifecycle(
                        lifecycleOwner,
                        selector,
                        preparedVideoCapture,
                        requireNotNull(keepAliveAnalysis),
                    )
                }
                boundCamera = camera
                videoCapture = preparedVideoCapture
                headlessKeepAliveAnalysis = keepAliveAnalysis
                boundPreviewView = requiredPreview
                recorderBindingMode = if (preparedPreview != null) {
                    "DUAL_PHONE_PREVIEW_BACKED"
                } else {
                    "HEADLESS_KEEP_ALIVE"
                }
                boundPreviewAttached = requiredPreview?.isAttachedToWindow == true
                boundPreviewWidth = requiredPreview?.width ?: 0
                boundPreviewHeight = requiredPreview?.height ?: 0
                lastPreviewStreamState =
                    requiredPreview?.previewStreamState?.value?.name
                        ?: "NOT_REQUIRED"
                applySelectedZoom(camera)
                if (
                    requiredPreview != null &&
                    !awaitPreviewStreaming(requiredPreview)
                ) {
                    throw IllegalStateException(
                        "Dual-phone PreviewView did not reach STREAMING after " +
                            "$PREVIEW_STREAMING_TIMEOUT_MS ms; " +
                            "state=$lastPreviewStreamState",
                    )
                }
                Log.i(
                    TAG,
                    "ensureRecordingReady(): binding=$recorderBindingMode camera_id=" +
                        "${lens.cameraId} mode=${mode.id} preview=" +
                        "${boundPreviewWidth}x${boundPreviewHeight} " +
                        "attached=$boundPreviewAttached " +
                        "stream_state=$lastPreviewStreamState",
                )
                getRecordingReadiness()
            } catch (error: Throwable) {
                Log.e(TAG, "ensureRecordingReady(): failed", error)
                boundCamera = null
                videoCapture = null
                headlessKeepAliveAnalysis = null
                boundPreviewView = null
                recorderBindingMode = "UNBOUND_AFTER_ERROR"
                boundPreviewAttached = false
                boundPreviewWidth = 0
                boundPreviewHeight = 0
                val info = selectedVideoInfo
                PhoneVideoRecorderReadiness(
                    ready = false,
                    reason = "Automatic CameraX preparation failed: " +
                        (error.message ?: error.javaClass.simpleName),
                    cameraId = selectedLensOption?.cameraId,
                    width = info?.width,
                    height = info?.height,
                    fps = info?.fps,
                )
            }
        }

    fun regular30FpsFallbackModeId(
        preferredVideoModeId: String?,
    ): String? {
        val lens = lensRepository.selectedOrDefault().first
        val modes = lens.supportedVideoModes
        val preferred = modes.firstOrNull { it.id == preferredVideoModeId }
            ?: selectedVideoInfo?.let { selected ->
                modes.firstOrNull {
                    it.width == selected.width &&
                        it.height == selected.height &&
                        it.fps == selected.fps
                }
            }
        return modes.firstOrNull {
            it.support == PhoneVideoModeSupport.REGULAR &&
                it.fps == 30 &&
                preferred != null &&
                it.width == preferred.width &&
                it.height == preferred.height
        }?.id ?: modes.firstOrNull {
            it.support == PhoneVideoModeSupport.REGULAR &&
                it.fps == 30 &&
                it.width == 1920 &&
                it.height == 1080
        }?.id ?: modes.firstOrNull {
            it.support == PhoneVideoModeSupport.REGULAR && it.fps == 30
        }?.id
    }

    fun isRecording(): Boolean = recording != null

    suspend fun startRecording(sessionId: String, scanId: String): File {
        val dir = File(context.filesDir, "sessions/$sessionId/phone_scans/$scanId").apply { mkdirs() }
        val file = File(dir, "video.mp4")
        startRecordingInternal(file)
        return dir
    }

    suspend fun startRecordingToFile(outputFile: File): File {
        startRecordingInternal(outputFile)
        return outputFile.parentFile ?: outputFile
    }

    suspend fun startRecordingToFileWithTelemetry(
        outputFile: File,
        telemetryContext: PhoneVideoTelemetryContext,
    ): PhoneVideoRecordingStart = startRecordingInternal(
        outputFile,
        telemetryContext,
    )

    private suspend fun startRecordingInternal(
        file: File,
        telemetryContext: PhoneVideoTelemetryContext? = null,
    ): PhoneVideoRecordingStart {
        check(recording == null) {
            "Phone video recording is already active"
        }
        val preparedVideoCapture =
            videoCapture ?: error("Camera preview is not bound")
        val lens =
            selectedLensOption ?: lensRepository.selectedOrDefault().first
        file.parentFile?.mkdirs()
        boundCamera?.let { applySelectedZoom(it) }
        Log.d(TAG, "startRecording(): output path=${file.absolutePath} camera_id=${lens.cameraId} lens=${lens.lensLabel} requestedZoom=$requestedZoomRatio effectiveZoom=$effectiveZoomRatio")
        val deferred = CompletableDeferred<PhoneVideoRecordingResult>()
        finalizeDeferred = deferred
        val healthDeferred = telemetryContext?.let { CompletableDeferred<PhoneVideoDataHealth>() }
        validDataDeferred = healthDeferred
        startedAtMs = System.currentTimeMillis()
        outputFile = file
        val startCallNs = SystemClock.elapsedRealtimeNanos()
        lastStartCallElapsedNs = startCallNs
        lastCameraXStartElapsedNs = null
        lastFinalizeElapsedNs = null
        lastStatusEventCount = 0
        lastStatusRecordedBytes = 0L
        lastStatusRecordedDurationNs = 0L
        lastFinalizeErrorCode = null
        lastFinalizeErrorLabel = null
        lastFinalizeCause = null
        lastPreviewStreamState =
            boundPreviewView
                ?.previewStreamState
                ?.value
                ?.name ?: lastPreviewStreamState
        if (telemetryContext != null) {
            val info = selectedVideoInfo
            frameTelemetryRecorder.start(
                baseDir = file.parentFile ?: file,
                context = telemetryContext,
                cameraId = lens.cameraId,
                videoModeId = info?.let {
                    "${it.width}x${it.height}@${it.fps}"
                },
                width = info?.width,
                height = info?.height,
                fps = info?.fps,
                rotationDegrees = when (currentTargetRotation) {
                    Surface.ROTATION_90 -> 90
                    Surface.ROTATION_180 -> 180
                    Surface.ROTATION_270 -> 270
                    else -> 0
                },
                startCallElapsedNs = startCallNs,
            )
        }
        try {
            recording = preparedVideoCapture.output.prepareRecording(
                context,
                FileOutputOptions.Builder(file).build(),
            ).start(ContextCompat.getMainExecutor(context)) { event ->
                when (event) {
                    is VideoRecordEvent.Start -> {
                        lastCameraXStartElapsedNs =
                            SystemClock.elapsedRealtimeNanos()
                        Log.d(
                            TAG,
                            "startRecording(): CameraX Start path=${file.absolutePath} elapsed_ns=$lastCameraXStartElapsedNs",
                        )
                    }
                    is VideoRecordEvent.Status -> {
                        val bytes = event.recordingStats.numBytesRecorded
                            .coerceAtLeast(0L)
                        val durationNs = event.recordingStats.recordedDurationNanos
                            .coerceAtLeast(0L)
                        lastStatusEventCount += 1
                        lastStatusRecordedBytes = bytes
                        lastStatusRecordedDurationNs = durationNs
                        if (
                            healthDeferred != null &&
                            !healthDeferred.isCompleted &&
                            bytes >= MIN_VALID_ENCODED_BYTES &&
                            durationNs >= MIN_VALID_ENCODED_DURATION_NS
                        ) {
                            val health = PhoneVideoDataHealth(
                                recordedBytes = bytes,
                                recordedDurationNs = durationNs,
                                observedAtElapsedNs =
                                    SystemClock.elapsedRealtimeNanos(),
                                statusEventCount = lastStatusEventCount,
                                recorderBindingMode = recorderBindingMode,
                            )
                            healthDeferred.complete(health)
                            Log.i(
                                TAG,
                                "startRecording(): valid encoded data path=${file.absolutePath} bytes=$bytes duration_ns=$durationNs",
                            )
                        }
                    }
                    is VideoRecordEvent.Finalize -> {
                        val finalizeNs =
                            SystemClock.elapsedRealtimeNanos()
                        lastFinalizeElapsedNs = finalizeNs
                        val durationMs = (
                            System.currentTimeMillis() - startedAtMs
                        ).coerceAtLeast(0L)
                        val cameraXDurationNs =
                            event.recordingStats.recordedDurationNanos
                                .coerceAtLeast(0L)
                        val durationNs =
                            cameraXDurationNs.takeIf { it > 0L }
                                ?: durationMs * 1_000_000L
                        val size = file.length()
                        val errorLabel = finalizeErrorLabel(event.error)
                        lastFinalizeErrorCode = event.error
                        lastFinalizeErrorLabel = errorLabel
                        lastFinalizeCause = event.cause?.let {
                            "${it.javaClass.simpleName}: " +
                                (it.message ?: "no message")
                        }
                        recording = null
                        Log.d(
                            TAG,
                            "startRecording(): finalize path=${file.absolutePath}, " +
                                "size=$size, error=$errorLabel " +
                                "cause=${lastFinalizeCause ?: "none"}",
                        )
                        if (event.hasError()) {
                            val diagnostics = buildAttemptDiagnostics(file)
                            val failure = PhoneVideoNoValidDataException(
                                "recording finalize failed: $errorLabel: " +
                                    (event.cause?.message ?: "CameraX returned no usable MP4 data") +
                                    "; ${diagnostics.summary()}",
                                diagnostics,
                            )
                            if (healthDeferred?.isCompleted == false) {
                                healthDeferred.completeExceptionally(failure)
                            }
                            deferred.completeExceptionally(failure)
                        } else if (!file.exists() || size <= 0L) {
                            val diagnostics = buildAttemptDiagnostics(file)
                            val failure = PhoneVideoNoValidDataException(
                                "output file missing or size == 0; " +
                                    diagnostics.summary(),
                                diagnostics,
                            )
                            if (healthDeferred?.isCompleted == false) {
                                healthDeferred.completeExceptionally(failure)
                            }
                            deferred.completeExceptionally(failure)
                        } else {
                            Log.d(TAG, "Captured phone video with camera_id=${lens.cameraId} lens=${lens.lensLabel}")
                            deferred.complete(
                                PhoneVideoRecordingResult(
                                    path = file.absolutePath,
                                    durationSec =
                                        durationNs / 1_000_000_000L,
                                    fileSizeBytes = size,
                                    recordedDurationNs = durationNs,
                                    startCallElapsedNs =
                                        lastStartCallElapsedNs,
                                    cameraXStartElapsedNs =
                                        lastCameraXStartElapsedNs,
                                    finalizeElapsedNs = finalizeNs,
                                ),
                            )
                        }
                    }
                }
            }
        } catch (e: Throwable) {
            frameTelemetryRecorder.stop()
            validDataDeferred = null
            finalizeDeferred = null
            recording = null
            Log.e(TAG, "startRecording(): failed", e)
            throw IllegalStateException(
                "recording start failed: ${e.message}",
                e,
            )
        }
        val health = if (healthDeferred != null) {
            try {
                withTimeoutOrNull(VALID_ENCODED_DATA_TIMEOUT_MS) {
                    healthDeferred.await()
                }
            } catch (error: Throwable) {
                throw error
            }
        } else {
            null
        }
        if (healthDeferred != null && health == null) {
            Log.e(
                TAG,
                "startRecording(): no valid encoded data within ${VALID_ENCODED_DATA_TIMEOUT_MS}ms path=${file.absolutePath}",
            )
            runCatching { recording?.stop() }
            recording = null
            runCatching {
                withTimeoutOrNull(FAILED_START_FINALIZE_TIMEOUT_MS) {
                    deferred.await()
                }
            }
            frameTelemetryRecorder.stop()
            validDataDeferred = null
            finalizeDeferred = null
            outputFile = null
            val diagnostics = buildAttemptDiagnostics(file)
            throw PhoneVideoNoValidDataException(
                "No valid encoded video data after ${VALID_ENCODED_DATA_TIMEOUT_MS} ms; " +
                    diagnostics.summary(),
                diagnostics,
            )
        }
        Log.d(
            TAG,
            "startRecording(): start requested elapsed_ns=$startCallNs",
        )
        return PhoneVideoRecordingStart(
            path = file.absolutePath,
            startCallElapsedNs = startCallNs,
            cameraXStartElapsedNs = lastCameraXStartElapsedNs,
            validEncodedDataObserved = health != null,
            recordedBytesAtReady = health?.recordedBytes ?: 0L,
            recordedDurationNsAtReady = health?.recordedDurationNs ?: 0L,
            recorderBindingMode = health?.recorderBindingMode ?: recorderBindingMode,
            statusEventCountAtReady = health?.statusEventCount ?: lastStatusEventCount,
        )
    }

    suspend fun stopRecording(): PhoneVideoRecordingResult {
        val current = recording ?: error("Phone video recording was not started")
        val deferred = finalizeDeferred ?: error("Phone video recording was not started")
        Log.d(TAG, "stopRecording(): stopping")
        current.stop()
        recording = null
        var frameSummary: PhoneFrameTelemetrySummary? = null
        val result = try {
            withTimeoutOrNull(10_000L) { deferred.await() }
                ?: throw IllegalStateException(
                    "recording stop failed: CameraX finalize timeout",
                )
        } finally {
            frameSummary = frameTelemetryRecorder.stop()
        }
        validDataDeferred = null
        finalizeDeferred = null
        outputFile = null
        Log.d(TAG, "stopRecording(): finalized path=${result.path}, size=${result.fileSizeBytes}")
        return result.copy(frameTelemetrySummary = frameSummary)
    }

    suspend fun resetRecorderState(
        reason: String,
    ): PhoneVideoAttemptDiagnostics? {
        val file = outputFile
        val currentRecording = recording
        val currentFinalize = finalizeDeferred
        Log.w(
            TAG,
            "resetRecorderState(): reason=$reason " +
                "recording_active=${currentRecording != null} " +
                "binding=$recorderBindingMode " +
                "output=${file?.absolutePath ?: "none"}",
        )
        if (currentRecording != null) {
            runCatching { currentRecording.stop() }
            if (currentFinalize != null) {
                runCatching {
                    withTimeoutOrNull(FAILED_START_FINALIZE_TIMEOUT_MS) {
                        currentFinalize.await()
                    }
                }
            }
        }
        recording = null
        frameTelemetryRecorder.stop()
        val diagnostics = file?.let(::buildAttemptDiagnostics)
        validDataDeferred = null
        finalizeDeferred = null
        outputFile = null
        return diagnostics
    }

    private fun buildAttemptDiagnostics(file: File): PhoneVideoAttemptDiagnostics {
        val info = selectedVideoInfo
        return PhoneVideoAttemptDiagnostics(
            requestedModeId = info?.let { "${it.width}x${it.height}@${it.fps}" },
            recorderBindingMode = recorderBindingMode,
            cameraXStartObserved = lastCameraXStartElapsedNs != null,
            statusEventCount = lastStatusEventCount,
            lastRecordedBytes = lastStatusRecordedBytes,
            lastRecordedDurationNs = lastStatusRecordedDurationNs,
            fileSizeBytes = file.takeIf { it.exists() }?.length() ?: 0L,
            previewAttached = boundPreviewAttached,
            previewWidth = boundPreviewWidth,
            previewHeight = boundPreviewHeight,
            previewStreamState =
                boundPreviewView
                    ?.previewStreamState
                    ?.value
                    ?.name ?: lastPreviewStreamState,
            finalizeReceived = lastFinalizeElapsedNs != null,
            finalizeErrorCode = lastFinalizeErrorCode,
            finalizeErrorLabel = lastFinalizeErrorLabel,
            finalizeCause = lastFinalizeCause,
        )
    }

    private suspend fun applySelectedZoom(camera: Camera) {
        val before = camera.cameraInfo.zoomState.value
        val min = before?.minZoomRatio ?: 1.0f
        val max = before?.maxZoomRatio ?: 1.0f
        minZoomRatio = min
        maxZoomRatio = max
        val clamped = requestedZoomRatio.coerceIn(min, max)
        selectedLensOption = selectedLensOption?.copy(minZoomRatio = min, maxZoomRatio = max)
        lastZoomApplyResult = null
        val currentRatio = before?.zoomRatio
        if (
            currentRatio == null ||
            kotlin.math.abs(currentRatio - clamped) > ZOOM_RATIO_TOLERANCE
        ) {
            val completed = withTimeoutOrNull(ZOOM_APPLY_TIMEOUT_MS) {
                awaitZoomSet(camera, clamped)
                true
            } == true
            if (!completed) {
                lastZoomApplyResult = "error: zoom apply timeout"
                throw IllegalStateException(
                    "Camera zoom apply timed out after " +
                        "$ZOOM_APPLY_TIMEOUT_MS ms",
                )
            }
            lastZoomApplyResult?.takeIf { it.startsWith("error") }?.let {
                throw IllegalStateException(it)
            }
        }
        val after = camera.cameraInfo.zoomState.value
        val effective = after?.zoomRatio ?: clamped
        effectiveZoomRatio = effective
        lensRepository.saveSelectedZoomRatio(requestedZoomRatio)
        val logMessage = "Requested zoom=$requestedZoomRatio, clamped=$clamped, effective=$effective, min=$min, max=$max"
        if (lastZoomApplyResult?.startsWith("error") != true) lastZoomApplyResult = logMessage
        Log.d(TAG, logMessage)
    }

    private suspend fun awaitZoomSet(camera: Camera, ratio: Float) = suspendCancellableCoroutine<Unit> { cont ->
        val future = camera.cameraControl.setZoomRatio(ratio)
        future.addListener({
            runCatching { future.get() }
                .onSuccess { if (cont.isActive) cont.resume(Unit) }
                .onFailure {
                    lastZoomApplyResult = "error: ${it.message}"
                    if (cont.isActive) cont.resume(Unit)
                }
        }, ContextCompat.getMainExecutor(context))
    }

    private fun getBindResult(success: Boolean, error: String? = null): PhoneCameraBindResult = PhoneCameraBindResult(
        success = success,
        error = error,
        requestedZoomRatio = requestedZoomRatio,
        effectiveZoomRatio = effectiveZoomRatio,
        minZoomRatio = minZoomRatio,
        maxZoomRatio = maxZoomRatio,
        cameraId = selectedLensOption?.cameraId,
        activeBoundCameraId = selectedLensOption?.cameraId,
        cameraXZoomStateCurrent = boundCamera?.cameraInfo?.zoomState?.value?.zoomRatio,
        bindStatus = if (success) "bound" else "failed",
    )

    private fun finalizeErrorLabel(error: Int): String = when (error) {
        VideoRecordEvent.Finalize.ERROR_NONE -> "ERROR_NONE(0)"
        VideoRecordEvent.Finalize.ERROR_UNKNOWN -> "ERROR_UNKNOWN($error)"
        VideoRecordEvent.Finalize.ERROR_FILE_SIZE_LIMIT_REACHED ->
            "ERROR_FILE_SIZE_LIMIT_REACHED($error)"
        VideoRecordEvent.Finalize.ERROR_INSUFFICIENT_STORAGE ->
            "ERROR_INSUFFICIENT_STORAGE($error)"
        VideoRecordEvent.Finalize.ERROR_SOURCE_INACTIVE ->
            "ERROR_SOURCE_INACTIVE($error)"
        VideoRecordEvent.Finalize.ERROR_INVALID_OUTPUT_OPTIONS ->
            "ERROR_INVALID_OUTPUT_OPTIONS($error)"
        VideoRecordEvent.Finalize.ERROR_ENCODING_FAILED ->
            "ERROR_ENCODING_FAILED($error)"
        VideoRecordEvent.Finalize.ERROR_RECORDER_ERROR ->
            "ERROR_RECORDER_ERROR($error)"
        VideoRecordEvent.Finalize.ERROR_NO_VALID_DATA ->
            "ERROR_NO_VALID_DATA($error)"
        else -> "ERROR_$error"
    }

    private suspend fun getCameraProvider(): ProcessCameraProvider =
        withTimeoutOrNull(CAMERA_PROVIDER_TIMEOUT_MS) {
            suspendCancellableCoroutine { cont ->
                val future = ProcessCameraProvider.getInstance(context)
                cont.invokeOnCancellation { future.cancel(true) }
                future.addListener(
                    {
                        if (cont.isActive) {
                            cont.resumeWith(runCatching { future.get() })
                        }
                    },
                    ContextCompat.getMainExecutor(context),
                )
            }
        } ?: throw IllegalStateException(
            "CameraProvider initialization timed out after " +
                "$CAMERA_PROVIDER_TIMEOUT_MS ms",
        )

    private companion object {
        const val TAG = "PhoneCameraVideoRecorder"
        private const val METRIC_STEREO_MAX_WIDTH = 1920
        private const val METRIC_STEREO_MAX_HEIGHT = 1080
        private const val CALIBRATION_ANALYSIS_MAX_WIDTH = METRIC_STEREO_MAX_WIDTH
        private const val CALIBRATION_ANALYSIS_MAX_HEIGHT = METRIC_STEREO_MAX_HEIGHT
        private const val CALIBRATION_ANALYSIS_MAX_FPS = 8L
        private const val CALIBRATION_ANALYSIS_MAX_PIXELS_FOR_CONVERSION = CALIBRATION_ANALYSIS_MAX_WIDTH * CALIBRATION_ANALYSIS_MAX_HEIGHT * 2
        private const val CALIBRATION_ANALYSIS_MIN_INTERVAL_NS = 1_000_000_000L / CALIBRATION_ANALYSIS_MAX_FPS
        private const val CAMERA_PROVIDER_TIMEOUT_MS = 5_000L
        private const val ZOOM_APPLY_TIMEOUT_MS = 2_000L
        private const val ZOOM_RATIO_TOLERANCE = 0.01f
        private const val MIN_VALID_ENCODED_BYTES = 4_096L
        private const val MIN_VALID_ENCODED_DURATION_NS = 500_000_000L
        private const val PREVIEW_SURFACE_TIMEOUT_MS = 5_000L
        private const val PREVIEW_STREAMING_TIMEOUT_MS = 8_000L
        private const val VALID_ENCODED_DATA_TIMEOUT_MS = 10_000L
        private const val FAILED_START_FINALIZE_TIMEOUT_MS = 5_000L
    }
}
