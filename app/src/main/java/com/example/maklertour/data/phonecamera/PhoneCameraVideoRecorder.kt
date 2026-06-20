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
import androidx.camera.video.VideoRecordEvent
import androidx.core.content.ContextCompat
import androidx.lifecycle.ProcessLifecycleOwner
import java.io.File
import kotlin.coroutines.resume
import kotlin.coroutines.resumeWithException
import kotlinx.coroutines.CompletableDeferred
import kotlinx.coroutines.suspendCancellableCoroutine

class PhoneCameraVideoRecorder(private val context: Context) {
    data class Result(val path: String, val durationSec: Long, val fileSizeBytes: Long)

    private var recording: Recording? = null
    private var videoFile: File? = null
    private var startedAtMs: Long = 0L
    private var finalizeResult: CompletableDeferred<Result>? = null

    suspend fun startRecording(sessionId: String, scanId: String): File {
        check(recording == null) { "Phone camera recording is already active" }
        val scanDir = File(context.filesDir, "sessions/$sessionId/phone_scans/$scanId").apply { mkdirs() }
        val outputFile = File(scanDir, "video.mp4")
        val cameraProvider = suspendCancellableCoroutine<ProcessCameraProvider> { cont ->
            val future = ProcessCameraProvider.getInstance(context)
            future.addListener({
                try { cont.resume(future.get()) } catch (t: Throwable) { cont.resumeWithException(t) }
            }, ContextCompat.getMainExecutor(context))
        }
        val recorder = Recorder.Builder()
            .setQualitySelector(QualitySelector.from(Quality.FHD))
            .build()
        val videoCapture = VideoCapture.withOutput(recorder)
        cameraProvider.unbind(videoCapture)
        cameraProvider.bindToLifecycle(ProcessLifecycleOwner.get(), CameraSelector.DEFAULT_BACK_CAMERA, videoCapture)
        finalizeResult = CompletableDeferred()
        videoFile = outputFile
        startedAtMs = System.currentTimeMillis()
        recording = videoCapture.output
            .prepareRecording(context, FileOutputOptions.Builder(outputFile).build())
            .start(ContextCompat.getMainExecutor(context)) { event ->
                if (event is VideoRecordEvent.Finalize) {
                    val file = videoFile ?: outputFile
                    val duration = ((System.currentTimeMillis() - startedAtMs) / 1000L).coerceAtLeast(0L)
                    finalizeResult?.complete(Result(file.absolutePath, duration, file.length()))
                    recording = null
                }
            }
        return outputFile
    }

    suspend fun stopRecording(): Result {
        val deferred = finalizeResult ?: error("Phone camera recording was not started")
        recording?.stop()
        return deferred.await()
    }
}