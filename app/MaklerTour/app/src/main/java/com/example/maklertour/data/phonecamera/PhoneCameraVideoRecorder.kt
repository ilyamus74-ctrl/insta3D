package com.maklertour.data.phonecamera

import android.content.Context
import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.graphics.ImageFormat
import android.graphics.Matrix
import android.graphics.Rect
import android.graphics.YuvImage
import android.util.Log
import android.util.Range
import android.util.Size
import android.view.Surface
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
import kotlinx.coroutines.suspendCancellableCoroutine
import kotlinx.coroutines.withTimeoutOrNull
import java.io.ByteArrayOutputStream
import java.io.File
import java.util.concurrent.ExecutorService
import java.util.concurrent.Executors
import kotlin.coroutines.resume
import kotlin.math.roundToLong

data class PhoneVideoRecordingResult(val path: String, val durationSec: Long, val fileSizeBytes: Long)

class PhoneCameraVideoRecorder(private val context: Context, private val lifecycleOwner: LifecycleOwner) {
    private var recording: Recording? = null
    private var startedAtMs: Long = 0L
    private var outputFile: File? = null
    private var videoCapture: VideoCapture<Recorder>? = null
    private var finalizeDeferred: CompletableDeferred<PhoneVideoRecordingResult>? = null
    private val lensRepository = PhoneCameraLensRepository(context)
    private var selectedVideoInfo: SelectedPhoneVideoInfo? = null
    private var selectedLensOption: PhoneCameraLensOption? = null
    private var requestedZoomRatio: Float = lensRepository.getSelectedZoomRatio()
    private var effectiveZoomRatio: Float = requestedZoomRatio
    private var lastZoomApplyResult: String? = null
    private var boundCamera: Camera? = null
    private val latestFrameLock = Any()
    private var latestCalibrationFrame: CalibrationFrame? = null
    private val recentCalibrationFrames = CalibrationFrameRingBuffer(20)
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
        requestedZoomRatio = zoomRatio
        requestedProfileWidth = calibrationWidth
        requestedProfileHeight = calibrationHeight
        val profileRequestedSize = requestedSize(calibrationWidth, calibrationHeight)
        val requestedSize = cappedCalibrationAnalysisSize(profileRequestedSize)
        requestedCalibrationWidth = requestedSize?.width
        requestedCalibrationHeight = requestedSize?.height
        calibrationResolutionReason = if (profileRequestedSize != null && requestedSize != null && (requestedSize.width != profileRequestedSize.width || requestedSize.height != profileRequestedSize.height)) CALIBRATION_CAP_REASON else null
        actualCalibrationWidth = null
        actualCalibrationHeight = null
        loggedCalibrationAnalysisFrames = 0L
        lastAcceptedCalibrationAnalysisNs = 0L
        loggedOversizedCalibrationFrameWarning = false
        currentTargetRotation = previewView.display?.rotation ?: Surface.ROTATION_0
        Log.d(TAG, "bindPreview(): start selected_camera_id=$cameraId zoom=$zoomRatio video_capture=$enableVideoCapture target_rotation=$currentTargetRotation requested_profile=${profileRequestedSize?.width}x${profileRequestedSize?.height} requested_calibration=${requestedSize?.width}x${requestedSize?.height} reason=${calibrationResolutionReason ?: "none"}")
        val cameraProvider = getCameraProvider()
        val preview = Preview.Builder().setTargetRotation(currentTargetRotation).build()
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
                    selectedLensOption = recoveryLens
                    applySelectedZoom(camera)
                    Log.w(TAG, "bindPreview(): selected camera failed; fallback camera_id=${recoveryLens.cameraId} lens=${recoveryLens.lensLabel}")
                }
            }
            throw IllegalStateException("preview bind failed: ${e.message}", e)
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

    private fun buildVideoCapture(mode: PhoneVideoMode): VideoCapture<Recorder> {
        val quality = when (mode.qualityKey) {
            "UHD" -> Quality.UHD
            "FHD" -> Quality.FHD
            else -> Quality.HD
        }
        val recorder = Recorder.Builder()
            .setQualitySelector(QualitySelector.from(quality))
            .build()
        return VideoCapture.Builder(recorder)
            .setTargetRotation(currentTargetRotation)
            .setTargetFrameRate(Range(mode.fps, mode.fps))
            .build()
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
        val sizes = buildList {
            requestedSize?.let { add(it) }
            add(Size(1280, 720))
            add(Size(640, 480))
        }.distinctBy { it.width to it.height }
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
            val nowNs = android.os.SystemClock.elapsedRealtimeNanos()
            if (lastAcceptedCalibrationAnalysisNs != 0L && nowNs - lastAcceptedCalibrationAnalysisNs < CALIBRATION_ANALYSIS_MIN_INTERVAL_NS) return
            lastAcceptedCalibrationAnalysisNs = nowNs
            actualCalibrationWidth = imageProxy.width
            actualCalibrationHeight = imageProxy.height
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
            val ageMs = (android.os.SystemClock.elapsedRealtimeNanos() - timestampNs) / 1_000_000L
            loggedCalibrationAnalysisFrames += 1L
            if (loggedCalibrationAnalysisFrames <= 10L || loggedCalibrationAnalysisFrames % 30L == 0L) {
                Log.d(TAG, "cam0 analysis actual=${imageProxy.width}x${imageProxy.height} imageProxyRotationDegrees=$imageProxyRotationDegrees targetRotation=$currentTargetRotation before=${rawBitmap.width}x${rawBitmap.height} after=${bitmap.width}x${bitmap.height} requested=${requestedCalibrationWidth}x${requestedCalibrationHeight} age=${ageMs}ms conversion_ms=$conversionMs saved frame cam0=${bitmap.width}x${bitmap.height} rotationApplied=$rotationDegrees")
            }
            synchronized(latestFrameLock) {
                latestCalibrationSequence += 1L
                val frame = CalibrationFrame(bitmap, timestampNs, latestCalibrationSequence, rotationDegreesApplied = rotationDegrees, rawWidth = imageProxy.width, rawHeight = imageProxy.height, savedWidth = bitmap.width, savedHeight = bitmap.height, displayRotationAtCapture = currentTargetRotation, appOrientationAtCapture = if (bitmap.width >= bitmap.height) "landscape" else "portrait")
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

    private suspend fun startRecordingInternal(file: File) {
        val preparedVideoCapture = videoCapture ?: error("Camera preview is not bound")
        val lens = selectedLensOption ?: lensRepository.selectedOrDefault().first
        file.parentFile?.mkdirs()
        boundCamera?.let { applySelectedZoom(it) }
        Log.d(TAG, "startRecording(): output path=${file.absolutePath} camera_id=${lens.cameraId} lens=${lens.lensLabel} requestedZoom=$requestedZoomRatio effectiveZoom=$effectiveZoomRatio")
        val deferred = CompletableDeferred<PhoneVideoRecordingResult>()
        finalizeDeferred = deferred
        startedAtMs = System.currentTimeMillis()
        outputFile = file
        try {
            recording = preparedVideoCapture.output.prepareRecording(context, FileOutputOptions.Builder(file).build())
                .start(ContextCompat.getMainExecutor(context)) { event ->
                    if (event is VideoRecordEvent.Finalize) {
                        val durationMs = (System.currentTimeMillis() - startedAtMs).coerceAtLeast(0L)
                        val size = file.length()
                        Log.d(TAG, "startRecording(): finalize path=${file.absolutePath}, size=$size, error=${event.error}")
                        if (event.hasError()) {
                            deferred.completeExceptionally(IllegalStateException("recording stop failed: ${event.cause?.message ?: event.error}"))
                        } else if (!file.exists() || size <= 0L) {
                            deferred.completeExceptionally(IllegalStateException("output file missing or size == 0"))
                        } else {
                            Log.d(TAG, "Captured phone video with camera_id=${lens.cameraId} lens=${lens.lensLabel}")
                            deferred.complete(PhoneVideoRecordingResult(file.absolutePath, durationMs / 1000L, size))
                        }
                    }
                }
        } catch (e: Throwable) {
            finalizeDeferred = null
            recording = null
            Log.e(TAG, "startRecording(): failed", e)
            throw IllegalStateException("recording start failed: ${e.message}", e)
        }
        Log.d(TAG, "startRecording(): started")
    }

    suspend fun stopRecording(): PhoneVideoRecordingResult {
        val current = recording ?: error("Phone video recording was not started")
        val deferred = finalizeDeferred ?: error("Phone video recording was not started")
        Log.d(TAG, "stopRecording(): stopping")
        current.stop()
        recording = null
        val result = withTimeoutOrNull(10_000L) { deferred.await() } ?: throw IllegalStateException("recording stop failed: CameraX finalize timeout")
        finalizeDeferred = null
        outputFile = null
        Log.d(TAG, "stopRecording(): finalized path=${result.path}, size=${result.fileSizeBytes}")
        return result
    }

    private suspend fun applySelectedZoom(camera: Camera) {
        val before = camera.cameraInfo.zoomState.value
        val min = before?.minZoomRatio ?: 1.0f
        val max = before?.maxZoomRatio ?: 1.0f
        minZoomRatio = min
        maxZoomRatio = max
        val clamped = requestedZoomRatio.coerceIn(min, max)
        selectedLensOption = selectedLensOption?.copy(minZoomRatio = min, maxZoomRatio = max)
        runCatching { awaitZoomSet(camera, clamped) }
            .onFailure { lastZoomApplyResult = "error: ${it.message}" }
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

    private suspend fun getCameraProvider(): ProcessCameraProvider = suspendCancellableCoroutine { cont ->
        val future = ProcessCameraProvider.getInstance(context)
        future.addListener({ cont.resume(future.get()) }, ContextCompat.getMainExecutor(context))
    }

    private companion object {
        const val TAG = "PhoneCameraVideoRecorder"
        private const val CALIBRATION_ANALYSIS_MAX_WIDTH = 1280
        private const val CALIBRATION_ANALYSIS_MAX_HEIGHT = 720
        private const val CALIBRATION_ANALYSIS_MAX_FPS = 8L
        private const val CALIBRATION_ANALYSIS_MAX_PIXELS_FOR_CONVERSION = CALIBRATION_ANALYSIS_MAX_WIDTH * CALIBRATION_ANALYSIS_MAX_HEIGHT * 2
        private const val CALIBRATION_ANALYSIS_MIN_INTERVAL_NS = 1_000_000_000L / CALIBRATION_ANALYSIS_MAX_FPS
        private const val CALIBRATION_CAP_REASON = "calibration_analysis_capped_for_latency"
    }
}
