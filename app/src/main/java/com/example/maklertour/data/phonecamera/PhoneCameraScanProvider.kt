package com.maklertour.data.phonecamera

import android.content.Context
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

class PhoneCameraScanProvider(private val context: Context) : CameraProvider {
    private val videoRecorder = PhoneCameraVideoRecorder(context)
    private val imuRecorder = ImuRecorder(context)
    private val infoCollector = PhoneCameraInfoCollector(context)
    private val manifestWriter = PhoneScanManifestWriter()
    private var active: ActiveScan? = null

    data class PhoneScanContext(val sessionId: String, val scanId: String, val sequenceNumber: Int)
    private data class ActiveScan(val context: PhoneScanContext, val name: String, val dir: File, val startedAt: Instant, val cameraInfoPath: String)

    var nextScanContext: PhoneScanContext? = null

    override suspend fun connect(): CameraStatus = CameraStatus(isConnected = true, model = "Phone Camera")
    override suspend fun disconnect(): CameraStatus = CameraStatus(isConnected = false, model = "Phone Camera")
    override suspend fun getStatus(): CameraStatus = CameraStatus(isConnected = true, model = "Phone Camera")
    override suspend fun capture(pointName: String): CapturePoint = CapturePoint(name = pointName, status = CaptureStatus.Failed)
    override suspend fun listFiles(): List<String> = emptyList()

    override suspend fun startVideoScan(scanName: String): ScanVideo {
        val ctx = nextScanContext ?: error("Phone scan context is not configured")
        val dir = File(context.filesDir, "sessions/${ctx.sessionId}/phone_scans/${ctx.scanId}").apply { mkdirs() }
        val startedAt = Instant.now()
        val cameraInfo = infoCollector.collect(ctx.sessionId, ctx.scanId, dir)
        videoRecorder.startRecording(ctx.sessionId, ctx.scanId)
        imuRecorder.start(ctx.sessionId, ctx.scanId, dir)
        active = ActiveScan(ctx, scanName, dir, startedAt, cameraInfo.absolutePath)
        return ScanVideo(id = ctx.scanId, sessionId = ctx.sessionId, name = scanName, sequenceNumber = ctx.sequenceNumber, captureStatus = ScanVideoCaptureStatus.RECORDING, source = ScanSource.PHONE_CAMERA, createdAt = startedAt, updatedAt = startedAt)
    }

    override suspend fun stopVideoScan(): ScanVideo {
        val current = active ?: error("Phone camera scan is not recording")
        val result = videoRecorder.stopRecording()
        imuRecorder.stop()
        val finishedAt = Instant.now()
        val imuPath = File(current.dir, "imu.jsonl").absolutePath
        manifestWriter.write(current.context.scanId, current.context.sessionId, result.path, current.cameraInfoPath, imuPath, current.startedAt, finishedAt, result.durationSec, current.dir)
        active = null
        return ScanVideo(
            id = current.context.scanId,
            sessionId = current.context.sessionId,
            name = current.name,
            sequenceNumber = current.context.sequenceNumber,
            cameraFileUrl = "phone-camera",
            localVideoPath = result.path,
            durationSec = result.durationSec,
            fileSizeBytes = result.fileSizeBytes,
            captureStatus = ScanVideoCaptureStatus.CAPTURED,
            downloadState = ScanVideoDownloadState.DOWNLOADED,
            uploadState = ScanVideoUploadState.LOCAL_ONLY,
            serverProcessingState = ScanVideoProcessingState.NOT_STARTED,
            source = ScanSource.PHONE_CAMERA,
            createdAt = current.startedAt,
            updatedAt = finishedAt,
        )
    }

    override suspend fun deleteFiles(fileUrls: List<String>): CameraDeleteResult = CameraDeleteResult(emptyList(), fileUrls.associateWith { "Phone camera delete is not implemented" })
}