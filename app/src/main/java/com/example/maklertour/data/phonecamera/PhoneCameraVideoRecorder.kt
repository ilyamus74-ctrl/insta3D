package com.maklertour.data.phonecamera

import android.content.Context
import androidx.camera.core.CameraSelector
import androidx.camera.lifecycle.ProcessCameraProvider
import androidx.camera.video.FileOutputOptions
import androidx.camera.video.Quality
import androidx.camera.video.QualitySelector
import androidx.camera.video.Recorder
import androidx.camera.video.Recording
import androidx.camera.video.VideoCapture
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

    suspend fun startRecording(sessionId: String, scanId: String): File {
        val dir = File(context.filesDir, "sessions/$sessionId/phone_scans/$scanId").apply { mkdirs() }
        val file = File(dir, "video.mp4")
        val cameraProvider = suspendCancellableCoroutine<ProcessCameraProvider> { cont ->
            val future = ProcessCameraProvider.getInstance(context)
            future.addListener({ cont.resume(future.get()) }, ContextCompat.getMainExecutor(context))
        }
        val recorder = Recorder.Builder().setQualitySelector(QualitySelector.from(Quality.HD)).build()
        val videoCapture = VideoCapture.withOutput(recorder)
        cameraProvider.unbind(videoCapture)
        cameraProvider.bindToLifecycle(lifecycleOwner, CameraSelector.DEFAULT_BACK_CAMERA, videoCapture)
        recording = videoCapture.output.prepareRecording(context, FileOutputOptions.Builder(file).build())
            .start(ContextCompat.getMainExecutor(context)) { }
        startedAtMs = System.currentTimeMillis()
        outputFile = file
        return dir
    }

    suspend fun stopRecording(): PhoneVideoRecordingResult {
        val file = outputFile ?: error("Phone video recording was not started")
        val durationMs = (System.currentTimeMillis() - startedAtMs).coerceAtLeast(0L)
        val current = recording ?: error("Phone video recording was not started")
        return suspendCancellableCoroutine { cont ->
            current.close()
            recording = null
            // CameraX finalizes asynchronously; poll briefly for file metadata in MVP.
            ContextCompat.getMainExecutor(context).execute {
                cont.resume(PhoneVideoRecordingResult(file.absolutePath, durationMs / 1000L, file.length()))
            }
        }
    }
}