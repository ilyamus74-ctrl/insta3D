package com.maklertour.data.phonecamera

import android.content.Context
import android.util.Log
import androidx.camera.core.Camera
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
import java.io.File
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
    private var selectedZoomRatio: Float = lensRepository.getSelectedZoomRatio()
    private var boundCamera: Camera? = null
    private var minZoomRatio: Float? = null
    private var maxZoomRatio: Float? = null

    suspend fun bindPreview(previewView: PreviewView, cameraId: String?, zoomRatio: Float = lensRepository.getSelectedZoomRatio()) {
        selectedZoomRatio = zoomRatio
        Log.d(TAG, "bindPreview(): start selected_camera_id=$cameraId zoom=$zoomRatio")
        val cameraProvider = getCameraProvider()
        val preview = Preview.Builder().build()
        val recorder = Recorder.Builder().setQualitySelector(QualitySelector.from(Quality.HD)).build()
        val options = lensRepository.listBackCameras()
        val requestedLens = cameraId?.let { id -> options.firstOrNull { it.cameraId == id } }
        val fallbackLens = lensRepository.selectedOrDefault().first
        val lens = requestedLens ?: fallbackLens
        val selector = lensRepository.cameraSelectorFor(lens.cameraId)
        Log.d(TAG, "Phone camera bind: selected_camera_id=${lens.cameraId} lens=${lens.lensLabel}")
        selectedVideoInfo = SelectedPhoneVideoInfo(width = 1280, height = 720, fps = null)
        val preparedVideoCapture = VideoCapture.withOutput(recorder)
        preview.setSurfaceProvider(previewView.surfaceProvider)
        val previousLens = selectedLensOption
        try {
            cameraProvider.unbindAll()
            val camera = cameraProvider.bindToLifecycle(
                lifecycleOwner,
                selector,
                preview,
                preparedVideoCapture,
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
                    val camera = cameraProvider.bindToLifecycle(
                        lifecycleOwner,
                        lensRepository.cameraSelectorFor(previousLens.cameraId),
                        previousPreview,
                        previousVideoCapture,
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
                    val camera = cameraProvider.bindToLifecycle(
                        lifecycleOwner,
                        lensRepository.cameraSelectorFor(recoveryLens.cameraId),
                        fallbackPreview,
                        fallbackVideoCapture,
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
    }

    fun getSelectedVideoInfo(): SelectedPhoneVideoInfo? = selectedVideoInfo

    fun getSelectedLensOption(): PhoneCameraLensOption? = selectedLensOption

    fun getSelectedZoomRatio(): Float = selectedZoomRatio

    fun getMinZoomRatio(): Float? = minZoomRatio

    fun getMaxZoomRatio(): Float? = maxZoomRatio

    suspend fun startRecording(sessionId: String, scanId: String): File {
        val preparedVideoCapture = videoCapture ?: error("Camera preview is not bound")
        val lens = selectedLensOption ?: lensRepository.selectedOrDefault().first
        val dir = File(context.filesDir, "sessions/$sessionId/phone_scans/$scanId").apply { mkdirs() }
        val file = File(dir, "video.mp4")
        boundCamera?.let { applySelectedZoom(it) }
        Log.d(TAG, "startRecording(): output path=${file.absolutePath} camera_id=${lens.cameraId} lens=${lens.lensLabel} zoom=$selectedZoomRatio")
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
        return dir
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

    private fun applySelectedZoom(camera: Camera) {
        val zoomState = camera.cameraInfo.zoomState.value
        val min = zoomState?.minZoomRatio ?: 1.0f
        val max = zoomState?.maxZoomRatio ?: 1.0f
        minZoomRatio = min
        maxZoomRatio = max
        val clamped = selectedZoomRatio.coerceIn(min, max)
        selectedZoomRatio = clamped
        selectedLensOption = selectedLensOption?.copy(minZoomRatio = min, maxZoomRatio = max)
        lensRepository.saveSelectedZoomRatio(clamped)
        camera.cameraControl.setZoomRatio(clamped)
        val id = selectedLensOption?.cameraId ?: "—"
        Log.d(TAG, "Phone camera bind: cameraId=$id zoom=$clamped minZoom=$min maxZoom=$max")
    }

    private suspend fun getCameraProvider(): ProcessCameraProvider = suspendCancellableCoroutine { cont ->
        val future = ProcessCameraProvider.getInstance(context)
        future.addListener({ cont.resume(future.get()) }, ContextCompat.getMainExecutor(context))
    }

    private companion object {
        const val TAG = "PhoneCameraVideoRecorder"
    }
}
