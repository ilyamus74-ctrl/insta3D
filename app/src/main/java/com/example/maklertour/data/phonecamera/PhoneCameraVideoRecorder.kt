package com.maklertour.data.phonecamera

import android.content.Context
import android.util.Log
import androidx.camera.core.CameraSelector
import androidx.camera.core.Preview
import androidx.camera.lifecycle.ProcessCameraProvider
import androidx.camera.video.FileOutputOptions
import androidx.camera.video.Quality
import androidx.camera.video.QualitySelector
import androidx.camera.video.Recorder
import androidx.camera.video.Recording
import androidx.camera.video.VideoCapture
import androidx.camera.view.PreviewView
import androidx.core.content.ContextCompat
import androidx.lifecycle.LifecycleOwner
import kotlinx.coroutines.suspendCancellableCoroutine
import java.io.File
import kotlin.coroutines.resume

data class PhoneVideoRecordingResult(val path: String, val durationSec: Long, val fileSizeBytes: Long)

class PhoneCameraVideoRecorder(private val context: Context, private val lifecycleOwner: LifecycleOwner) {
    private var recording: Recording? = null
    private var startedAtMs: Long = 0L
    private var outputFile: File? = null
    private var videoCapture: VideoCapture<Recorder>? = null

    suspend fun bindPreview(previewView: PreviewView) {
        Log.d(TAG, "bindPreview(): start")
        val cameraProvider = getCameraProvider()
        val preview = Preview.Builder().build()
        val recorder = Recorder.Builder().setQualitySelector(QualitySelector.from(Quality.HD)).build()
        val preparedVideoCapture = VideoCapture.withOutput(recorder)
        preview.setSurfaceProvider(previewView.surfaceProvider)
        cameraProvider.unbindAll()
        cameraProvider.bindToLifecycle(
            lifecycleOwner,
            CameraSelector.DEFAULT_BACK_CAMERA,
            preview,
            preparedVideoCapture,
        )
        videoCapture = preparedVideoCapture
        Log.d(TAG, "bindPreview(): success")
    }

    suspend fun startRecording(sessionId: String, scanId: String): File {
        val preparedVideoCapture = videoCapture ?: error("Camera preview is not bound")
        val dir = File(context.filesDir, "sessions/$sessionId/phone_scans/$scanId").apply { mkdirs() }
        val file = File(dir, "video.mp4")
        Log.d(TAG, "startRecording(): output path=${file.absolutePath}")
        recording = preparedVideoCapture.output.prepareRecording(context, FileOutputOptions.Builder(file).build())
            .start(ContextCompat.getMainExecutor(context)) { }
        Log.d(TAG, "startRecording(): started")
        startedAtMs = System.currentTimeMillis()
        outputFile = file
        return dir
    }

    suspend fun stopRecording(): PhoneVideoRecordingResult {
        val file = outputFile ?: error("Phone video recording was not started")
        val durationMs = (System.currentTimeMillis() - startedAtMs).coerceAtLeast(0L)
        val current = recording ?: error("Phone video recording was not started")
        Log.d(TAG, "stopRecording(): stopping")
        return suspendCancellableCoroutine { cont ->
            current.close()
            recording = null
            // CameraX finalizes asynchronously; poll briefly for file metadata in MVP.
            ContextCompat.getMainExecutor(context).execute {
                Log.d(TAG, "stopRecording(): finalized path=${file.absolutePath}, size=${file.length()}")
                cont.resume(PhoneVideoRecordingResult(file.absolutePath, durationMs / 1000L, file.length()))
            }
        }
    }

    private suspend fun getCameraProvider(): ProcessCameraProvider = suspendCancellableCoroutine { cont ->
        val future = ProcessCameraProvider.getInstance(context)
        future.addListener({ cont.resume(future.get()) }, ContextCompat.getMainExecutor(context))
    }

    private companion object {
        const val TAG = "PhoneCameraVideoRecorder"
    }
}
