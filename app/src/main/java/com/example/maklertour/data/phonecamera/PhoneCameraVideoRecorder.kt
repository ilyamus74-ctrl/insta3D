package com.maklertour.data.phonecamera

import android.content.Context
import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.graphics.ImageFormat
import android.graphics.Rect
import android.graphics.YuvImage
import android.util.Log
import android.util.Size
import androidx.camera.core.Camera
import androidx.camera.core.ImageAnalysis
import androidx.camera.core.ImageProxy
import androidx.camera.core.Preview
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
    private var requestedCalibrationWidth: Int? = null
    private var requestedCalibrationHeight: Int? = null
    private var actualCalibrationWidth: Int? = null
    private var actualCalibrationHeight: Int? = null
    private var loggedFirstCalibrationFrame = false

    suspend fun bindPreview(
        previewView: PreviewView,
        cameraId: String?,
        zoomRatio: Float = lensRepository.getSelectedZoomRatio(),
        calibrationWidth: Int? = null,
        calibrationHeight: Int? = null,
        videoWidth: Int? = calibrationWidth,
        videoHeight: Int? = calibrationHeight,
        videoFps: Int? = null,
    ): PhoneCameraBindResult {
        requestedZoomRatio = zoomRatio
        val requestedSize = requestedSize(calibrationWidth, calibrationHeight)
        requestedCalibrationWidth = requestedSize?.width
        requestedCalibrationHeight = requestedSize?.height
        actualCalibrationWidth = null
        actualCalibrationHeight = null
        loggedFirstCalibrationFrame = false
        Log.d(TAG, "bindPreview(): start selected_camera_id=$cameraId zoom=$zoomRatio requested_calibration=${requestedSize?.width}x${requestedSize?.height}")
        val cameraProvider = getCameraProvider()
        val preview = Preview.Builder().build()
        val recorder = Recorder.Builder().setQualitySelector(QualitySelector.from(Quality.HD)).build()
        val options = lensRepository.listBackCameras()
        val requestedLens = cameraId?.let { id -> options.firstOrNull { it.cameraId == id } }
        val fallbackLens = lensRepository.selectedOrDefault().first
        val lens = requestedLens ?: fallbackLens
        val selector = lensRepository.cameraSelectorFor(lens.cameraId)
        Log.d(TAG, "Phone camera bind: selected_camera_id=${lens.cameraId} lens=${lens.lensLabel}")
        selectedVideoInfo = SelectedPhoneVideoInfo(width = videoWidth ?: requestedSize?.width ?: 1280, height = videoHeight ?: requestedSize?.height ?: 720, fps = videoFps)
        val preparedVideoCapture = VideoCapture.withOutput(recorder)
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
                    val previousPreview = Preview.Builder().build()
                    val previousRecorder = Recorder.Builder().setQualitySelector(QualitySelector.from(Quality.HD)).build()
                    val previousVideoCapture = VideoCapture.withOutput(previousRecorder)
                    previousPreview.setSurfaceProvider(previewView.surfaceProvider)
                    val camera = bindWithCalibrationFallbacks(
                        cameraProvider = cameraProvider,
                        selector = lensRepository.cameraSelectorFor(previousLens.cameraId),
                        preview = previousPreview,
                        videoCapture = previousVideoCapture,
                        requestedSize = requestedSize,
                    )
                    boundCamera = camera
                    videoCapture = previousVideoCapture
                    selectedLensOption = previousLens
                    applySelectedZoom(camera)
                    Log.w(TAG, "bindPreview(): kept previous working camera_id=${previousLens.cameraId}")
                }
            } else if (recoveryLens != null) {
                runCatching {
                    val fallbackPreview = Preview.Builder().build()
                    val fallbackRecorder = Recorder.Builder().setQualitySelector(QualitySelector.from(Quality.HD)).build()
                    val fallbackVideoCapture = VideoCapture.withOutput(fallbackRecorder)
                    fallbackPreview.setSurfaceProvider(previewView.surfaceProvider)
                    val camera = bindWithCalibrationFallbacks(
                        cameraProvider = cameraProvider,
                        selector = lensRepository.cameraSelectorFor(recoveryLens.cameraId),
                        preview = fallbackPreview,
                        videoCapture = fallbackVideoCapture,
                        requestedSize = requestedSize,
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
        Log.d(TAG, "bindPreview(): success")
        return getBindResult(success = true)
    }

    fun getLatestCalibrationFrame(): CalibrationFrame? = synchronized(latestFrameLock) { latestCalibrationFrame }

    fun getRecentCalibrationFrames(): List<CalibrationFrame> = recentCalibrationFrames.snapshot()

    private fun bindWithCalibrationFallbacks(
        cameraProvider: ProcessCameraProvider,
        selector: androidx.camera.core.CameraSelector,
        preview: Preview,
        videoCapture: VideoCapture<Recorder>,
        requestedSize: Size?,
    ): Camera {
        val sizes = buildList {
            requestedSize?.let { add(it) }
            add(Size(1280, 720))
            add(Size(640, 480))
        }.distinctBy { it.width to it.height }
        var lastError: Throwable? = null
        for (size in sizes) {
            val analysis = buildCalibrationAnalysis(size.width, size.height)
            try {
                val camera = cameraProvider.bindToLifecycle(lifecycleOwner, selector, preview, videoCapture, analysis)
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
            builder.setTargetResolution(Size(width, height))
        }
        return builder.build()
            .also { analyzer ->
                analyzer.setAnalyzer(analysisExecutor) { imageProxy ->
                    updateLatestCalibrationFrame(imageProxy)
                }
            }
    }

    private fun requestedSize(width: Int?, height: Int?): Size? = if (width != null && height != null && width > 0 && height > 0) Size(width, height) else null

    private fun updateLatestCalibrationFrame(imageProxy: ImageProxy) {
        try {
            if (!loggedFirstCalibrationFrame) {
                loggedFirstCalibrationFrame = true
                actualCalibrationWidth = imageProxy.width
                actualCalibrationHeight = imageProxy.height
                Log.d(TAG, "cam0 calibration analysis frame ${imageProxy.width}x${imageProxy.height} requested=${requestedCalibrationWidth}x${requestedCalibrationHeight}")
            }
            val bitmap = imageProxy.toNv21Bitmap() ?: return
            val timestampNs = imageProxy.imageInfo.timestamp
            synchronized(latestFrameLock) {
                latestCalibrationSequence += 1L
                val frame = CalibrationFrame(bitmap, timestampNs, latestCalibrationSequence)
                latestCalibrationFrame = frame
                recentCalibrationFrames.add(frame)
            }
        } finally {
            imageProxy.close()
        }
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
    }
}
