package com.maklertour.data.phonecamera

import android.content.Context
import android.util.Log
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

    suspend fun bindPreview(previewView: PreviewView) {
        Log.d(TAG, "bindPreview(): start")
        val cameraProvider = getCameraProvider()
        val preview = Preview.Builder().build()
        val recorder = Recorder.Builder().setQualitySelector(QualitySelector.from(Quality.HD)).build()
        val (selector, lens) = lensRepository.selectedCameraSelector()
        selectedLensOption = lens
        selectedVideoInfo = SelectedPhoneVideoInfo(width = 1280, height = 720, fps = null)
        val preparedVideoCapture = VideoCapture.withOutput(recorder)
        preview.setSurfaceProvider(previewView.surfaceProvider)
        cameraProvider.unbindAll()
        try {
            cameraProvider.bindToLifecycle(
                lifecycleOwner,
                selector,
                preview,
                preparedVideoCapture,
            )
            videoCapture = preparedVideoCapture
        } catch (e: Throwable) {
            videoCapture = null
            Log.e(TAG, "bindPreview(): preview bind failed", e)
            throw IllegalStateException("preview bind failed: ${e.message}", e)
        }
        Log.d(TAG, "bindPreview(): success")
    }

    fun getSelectedVideoInfo(): SelectedPhoneVideoInfo? = selectedVideoInfo

    fun getSelectedLensOption(): PhoneCameraLensOption? = selectedLensOption

    suspend fun startRecording(sessionId: String, scanId: String): File {
        val preparedVideoCapture = videoCapture ?: error("Camera preview is not bound")
        val dir = File(context.filesDir, "sessions/$sessionId/phone_scans/$scanId").apply { mkdirs() }
        val file = File(dir, "video.mp4")
        Log.d(TAG, "startRecording(): output path=${file.absolutePath}")
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

    private suspend fun getCameraProvider(): ProcessCameraProvider = suspendCancellableCoroutine { cont ->
        val future = ProcessCameraProvider.getInstance(context)
        future.addListener({ cont.resume(future.get()) }, ContextCompat.getMainExecutor(context))
    }

    private companion object {
        const val TAG = "PhoneCameraVideoRecorder"
    }
}
