package com.maklertour.data.phonecamera

import android.content.Context
import androidx.camera.view.PreviewView
import androidx.lifecycle.LifecycleOwner
import com.maklertour.domain.CameraDeleteResult
import com.maklertour.domain.CameraProvider
import com.maklertour.domain.CameraStatus
import com.maklertour.domain.CapturePoint
import com.maklertour.domain.CaptureStatus
import com.maklertour.domain.ScanSource
import com.maklertour.domain.ScanVideo
import com.maklertour.domain.ScanVideoCaptureStatus
import com.maklertour.domain.ScanVideoDownloadState
import com.maklertour.domain.ScanVideoProcessingState
import com.maklertour.domain.ScanVideoUploadState
import java.io.File
import java.time.Instant

class PhoneCameraScanProvider(
    context: Context,
    lifecycleOwner: LifecycleOwner,
) : CameraProvider {
    private val appContext = context.applicationContext
    private val videoRecorder = PhoneCameraVideoRecorder(appContext, lifecycleOwner)
    private val imuRecorder = ImuRecorder(appContext)
    private val cameraInfoCollector = PhoneCameraInfoCollector(appContext)
    private val manifestWriter = PhoneScanManifestWriter()
    private var active: ActivePhoneScan? = null

    override suspend fun connect(): CameraStatus = CameraStatus(isConnected = true, model = "Phone Camera")
    override suspend fun disconnect(): CameraStatus = CameraStatus(isConnected = false, model = "Phone Camera")
    override suspend fun getStatus(): CameraStatus = CameraStatus(isConnected = true, model = "Phone Camera")

    suspend fun bindPreview(previewView: PreviewView) = videoRecorder.bindPreview(previewView)

    override suspend fun capture(pointName: String): CapturePoint = CapturePoint(
        name = pointName,
        status = CaptureStatus.Failed,
        cameraFileUrl = null,
    )

    override suspend fun listFiles(): List<String> = emptyList()

    override suspend fun startVideoScan(scanName: String): ScanVideo = startVideoScan(
        scanName = scanName,
        sessionId = "phone-session",
        scanId = java.util.UUID.randomUUID().toString(),
        sequenceNumber = 0,
    )

    suspend fun startVideoScan(scanName: String, sessionId: String, scanId: String, sequenceNumber: Int): ScanVideo {
        check(active == null) { "Phone camera scan is already recording" }
        val startedAt = Instant.now()
        val baseDir = videoRecorder.startRecording(sessionId, scanId)
        val imuFile = imuRecorder.start(sessionId, scanId, baseDir)
        val cameraInfoFile = cameraInfoCollector.writeCameraInfo(baseDir)
        active = ActivePhoneScan(scanId, sessionId, scanName, sequenceNumber, baseDir, cameraInfoFile, imuFile, startedAt)
        return ScanVideo(
            id = scanId,
            sessionId = sessionId,
            name = scanName,
            sequenceNumber = sequenceNumber,
            captureStatus = ScanVideoCaptureStatus.RECORDING,
            downloadState = ScanVideoDownloadState.DOWNLOADED,
            uploadState = ScanVideoUploadState.LOCAL_ONLY,
            source = ScanSource.PHONE_CAMERA,
            createdAt = startedAt,
            updatedAt = startedAt,
        )
    }

    override suspend fun stopVideoScan(): ScanVideo {
        val current = active ?: error("Phone camera scan is not recording")
        val video = videoRecorder.stopRecording()
        imuRecorder.stop()
        val finishedAt = Instant.now()
        manifestWriter.write(
            baseDir = current.baseDir,
            scanId = current.scanId,
            sessionId = current.sessionId,
            videoPath = video.path,
            cameraInfoPath = current.cameraInfoFile.absolutePath,
            imuPath = current.imuFile.absolutePath,
            startedAt = current.startedAt.toString(),
            finishedAt = finishedAt.toString(),
            durationSec = video.durationSec,
        )
        active = null
        return ScanVideo(
            id = current.scanId,
            sessionId = current.sessionId,
            name = current.scanName,
            sequenceNumber = current.sequenceNumber,
            cameraLocalFileUrl = "phone-camera",
            localVideoPath = video.path,
            durationSec = video.durationSec,
            fileSizeBytes = video.fileSizeBytes,
            captureStatus = ScanVideoCaptureStatus.CAPTURED,
            downloadState = ScanVideoDownloadState.DOWNLOADED,
            uploadState = ScanVideoUploadState.LOCAL_ONLY,
            serverProcessingState = ScanVideoProcessingState.NOT_STARTED,
            source = ScanSource.PHONE_CAMERA,
            createdAt = current.startedAt,
            updatedAt = finishedAt,
        )
    }

    override suspend fun deleteFiles(fileUrls: List<String>): CameraDeleteResult = CameraDeleteResult(emptyList(), emptyMap())

    private data class ActivePhoneScan(
        val scanId: String,
        val sessionId: String,
        val scanName: String,
        val sequenceNumber: Int,
        val baseDir: File,
        val cameraInfoFile: File,
        val imuFile: File,
        val startedAt: Instant,
    )
}