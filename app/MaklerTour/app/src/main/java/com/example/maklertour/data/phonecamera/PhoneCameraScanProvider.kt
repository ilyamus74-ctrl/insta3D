package com.maklertour.data.phonecamera

import android.content.Context
import androidx.camera.view.PreviewView
import androidx.lifecycle.LifecycleOwner
import com.maklertour.data.dualphone.DualPhoneCaptureArmRequest
import com.maklertour.data.dualphone.DualPhoneCaptureArmResult
import com.maklertour.data.dualphone.DualPhoneCaptureEndpoint
import com.maklertour.data.dualphone.DualPhoneCaptureRuntime
import com.maklertour.data.dualphone.DualPhoneCaptureStartRequest
import com.maklertour.data.dualphone.DualPhoneCaptureStartResult
import com.maklertour.data.dualphone.DualPhoneCaptureStopResult
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
import org.json.JSONObject

class PhoneCameraScanProvider(
    context: Context,
    lifecycleOwner: LifecycleOwner,
) : CameraProvider, DualPhoneCaptureEndpoint {
    private val appContext = context.applicationContext
    private val videoRecorder = PhoneCameraVideoRecorder(appContext, lifecycleOwner)
    private val imuRecorder = ImuRecorder(appContext)
    private val cameraInfoCollector = PhoneCameraInfoCollector(appContext)
    private val manifestWriter = PhoneScanManifestWriter()
    private var active: ActivePhoneScan? = null
    private var dualCapture: ActiveDualPhoneCapture? = null

    init {
        DualPhoneCaptureRuntime.register(this)
    }

    companion object {
        @Volatile private var sessionCalibration: PhoneScanCalibrationMetadata? = null
        private const val DUAL_CAPTURE_MIN_FREE_BYTES =
            256L * 1024L * 1024L


        fun setSessionCalibration(metadata: PhoneScanCalibrationMetadata) {
            sessionCalibration = metadata
        }
    }

    override suspend fun connect(): CameraStatus = CameraStatus(isConnected = true, model = "Phone Camera")
    override suspend fun disconnect(): CameraStatus = CameraStatus(isConnected = false, model = "Phone Camera")
    override suspend fun getStatus(): CameraStatus = CameraStatus(isConnected = true, model = "Phone Camera")

    suspend fun bindPreview(
        previewView: PreviewView,
        cameraId: String?,
        zoomRatio: Float,
        videoMode: PhoneVideoMode?,
    ): PhoneCameraBindResult = videoRecorder.bindPreview(
        previewView = previewView,
        cameraId = cameraId,
        zoomRatio = zoomRatio,
        videoWidth = videoMode?.width,
        videoHeight = videoMode?.height,
        videoFps = videoMode?.fps,
        videoMode = videoMode,
        enableVideoCapture = true,
        enableCalibrationAnalysis = false,
    )

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
        check(dualCapture == null) {
            "Dual-phone capture is armed or recording"
        }
        val startedAt = Instant.now()
        val baseDir = videoRecorder.startRecording(sessionId, scanId)
        val imuFile = imuRecorder.start(sessionId, scanId, baseDir)
        val cameraInfoFile = cameraInfoCollector.writeCameraInfo(baseDir, videoRecorder.getSelectedVideoInfo(), videoRecorder.getSelectedLensOption(), videoRecorder.getRequestedZoomRatio(), videoRecorder.getEffectiveZoomRatio(), videoRecorder.getMinZoomRatio(), videoRecorder.getMaxZoomRatio(), videoRecorder.getCalibrationResolutionInfo())
        check(cameraInfoFile.isFile && cameraInfoFile.length() > 0L) {
            "Phone camera metadata file was not created"
        }
        active = ActivePhoneScan(scanId, sessionId, scanName, sequenceNumber, baseDir, cameraInfoFile, imuFile, startedAt, sessionCalibration)
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
            notes = videoRecorder.getZoomWarning(),
        )
    }

    override suspend fun stopVideoScan(): ScanVideo {
        val current = active ?: error("Phone camera scan is not recording")
        val video = videoRecorder.stopRecording()
        imuRecorder.stop()
        val finishedAt = Instant.now()
        val manifestFile = manifestWriter.write(
            baseDir = current.baseDir,
            scanId = current.scanId,
            sessionId = current.sessionId,
            videoFile = File(video.path),
            cameraInfoFile = current.cameraInfoFile,
            imuFile = current.imuFile,
            createdAt = current.startedAt.toString(),
            finishedAt = finishedAt.toString(),
            durationSec = video.durationSec,
            fileSizeBytes = video.fileSizeBytes,
            calibration = current.calibration,
            selectedVideoInfo = videoRecorder.getSelectedVideoInfo(),
            selectedLens = videoRecorder.getSelectedLensOption(),
            requestedZoomRatio = videoRecorder.getRequestedZoomRatio(),
            effectiveZoomRatio = videoRecorder.getEffectiveZoomRatio(),
            minZoomRatio = videoRecorder.getMinZoomRatio(),
            maxZoomRatio = videoRecorder.getMaxZoomRatio(),
        )
        check(manifestFile.isFile && manifestFile.length() > 0L) {
            "Phone scan manifest file was not created"
        }
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
            notes = videoRecorder.getZoomWarning(),
        )
    }


    override suspend fun arm(
        request: DualPhoneCaptureArmRequest,
    ): DualPhoneCaptureArmResult {
        check(active == null) {
            "Regular phone scan is already recording"
        }

        val existing = dualCapture
        if (existing != null) {
            if (
                existing.request.dualCaptureId == request.dualCaptureId &&
                existing.request.role == request.role &&
                existing.started == null
            ) {
                return existing.armResult
            }
            throw IllegalStateException(
                "Another dual-phone capture is already armed",
            )
        }

        val readiness = videoRecorder.ensureRecordingReady(
            request.preferredVideoModeId,
        )
        val root = File(
            appContext.filesDir,
            "dual_phone_captures",
        ).apply { mkdirs() }
        val availableBytes = root.usableSpace
        val modeId = listOf(
            readiness.width,
            readiness.height,
            readiness.fps,
        ).takeIf { values -> values.all { it != null } }
            ?.let { values ->
                "${values[0]}x${values[1]}@${values[2]}"
            }

        if (!readiness.ready) {
            return DualPhoneCaptureArmResult(
                ready = false,
                reason = readiness.reason,
                availableBytes = availableBytes,
                cameraId = readiness.cameraId,
                videoModeId = modeId,
                width = readiness.width,
                height = readiness.height,
                fps = readiness.fps,
            )
        }
        if (availableBytes < DUAL_CAPTURE_MIN_FREE_BYTES) {
            return DualPhoneCaptureArmResult(
                ready = false,
                reason = "Not enough free space for dual-phone recording",
                availableBytes = availableBytes,
                cameraId = readiness.cameraId,
                videoModeId = modeId,
                width = readiness.width,
                height = readiness.height,
                fps = readiness.fps,
            )
        }

        val safeCaptureId = safeDualCaptureId(request.dualCaptureId)
        val roleName = request.role.name.lowercase()
        val baseDir = File(
            root,
            "$safeCaptureId/$roleName",
        ).apply { mkdirs() }
        val videoFile = File(baseDir, "video.mp4")
        val manifestFile = File(
            baseDir,
            "dual_capture_manifest.json",
        )
        videoFile.delete()
        manifestFile.delete()

        val armResult = DualPhoneCaptureArmResult(
            ready = true,
            outputPath = videoFile.absolutePath,
            availableBytes = availableBytes,
            cameraId = readiness.cameraId,
            videoModeId = modeId,
            width = readiness.width,
            height = readiness.height,
            fps = readiness.fps,
        )
        dualCapture = ActiveDualPhoneCapture(
            request = request,
            armResult = armResult,
            baseDir = baseDir,
            videoFile = videoFile,
            manifestFile = manifestFile,
            armedAtElapsedNs =
                android.os.SystemClock.elapsedRealtimeNanos(),
        )
        return armResult
    }

    override suspend fun start(
        request: DualPhoneCaptureStartRequest,
    ): DualPhoneCaptureStartResult {
        val current = dualCapture
            ?: throw IllegalStateException(
                "Dual-phone recorder is not armed",
            )
        require(
            current.request.dualCaptureId == request.dualCaptureId,
        ) {
            "dual_capture_id does not match armed capture"
        }
        require(current.request.role == request.role) {
            "Dual-phone role changed after ARM"
        }
        check(current.started == null) {
            "Dual-phone recording has already started"
        }

        val started = videoRecorder.startRecordingToFileWithTelemetry(
            current.videoFile,
        )
        val result = DualPhoneCaptureStartResult(
            videoPath = started.path,
            scheduledElapsedRealtimeNs =
                request.scheduledElapsedRealtimeNs,
            startCallElapsedRealtimeNs = started.startCallElapsedNs,
            cameraXStartElapsedRealtimeNs =
                started.cameraXStartElapsedNs,
        )
        dualCapture = current.copy(
            startRequest = request,
            started = result,
        )
        return result
    }

    override suspend fun stop(): DualPhoneCaptureStopResult {
        val current = dualCapture
            ?: throw IllegalStateException(
                "Dual-phone recorder is not armed",
            )

        val recordingResult = if (
            current.started != null &&
            videoRecorder.isRecording()
        ) {
            videoRecorder.stopRecording()
        } else {
            null
        }
        val finishedAtElapsedNs =
            android.os.SystemClock.elapsedRealtimeNanos()
        val manifest = JSONObject()
            .put("schema_version", 1)
            .put("dual_capture_id", current.request.dualCaptureId)
            .put("role", current.request.role.name)
            .put("device_id", current.request.deviceId)
            .putNullable(
                "peer_device_id",
                current.request.peerDeviceId,
            )
            .putNullable(
                "preferred_video_mode_id",
                current.request.preferredVideoModeId,
            )
            .put("armed_at_elapsed_ns", current.armedAtElapsedNs)
            .putNullable(
                "scheduled_start_elapsed_ns",
                current.startRequest
                    ?.scheduledElapsedRealtimeNs,
            )
            .putNullable(
                "clock_offset_ns",
                current.startRequest?.clockOffsetNs,
            )
            .putNullable(
                "clock_uncertainty_ns",
                current.startRequest?.clockUncertaintyNs,
            )
            .putNullable(
                "clock_drift_ppm",
                current.startRequest?.clockDriftPpm,
            )
            .putNullable(
                "start_call_elapsed_ns",
                recordingResult?.startCallElapsedNs
                    ?: current.started
                        ?.startCallElapsedRealtimeNs,
            )
            .putNullable(
                "camerax_start_elapsed_ns",
                recordingResult?.cameraXStartElapsedNs,
            )
            .putNullable(
                "finalize_elapsed_ns",
                recordingResult?.finalizeElapsedNs,
            )
            .put("finished_at_elapsed_ns", finishedAtElapsedNs)
            .put("camera_id", current.armResult.cameraId)
            .put("video_mode_id", current.armResult.videoModeId)
            .put("width", current.armResult.width)
            .put("height", current.armResult.height)
            .put("fps_requested", current.armResult.fps)
            .put("captured", recordingResult != null)
            .putNullable(
                "video_path",
                recordingResult?.path,
            )
            .put(
                "recorded_duration_ns",
                recordingResult?.recordedDurationNs ?: 0L,
            )
            .put(
                "file_size_bytes",
                recordingResult?.fileSizeBytes ?: 0L,
            )
        current.manifestFile.writeText(
            manifest.toString(2) + "\n",
            Charsets.UTF_8,
        )

        val result = DualPhoneCaptureStopResult(
            captured = recordingResult != null,
            videoPath = recordingResult?.path,
            manifestPath = current.manifestFile.absolutePath,
            durationNs =
                recordingResult?.recordedDurationNs ?: 0L,
            fileSizeBytes =
                recordingResult?.fileSizeBytes ?: 0L,
            scheduledElapsedRealtimeNs =
                current.startRequest
                    ?.scheduledElapsedRealtimeNs,
            startCallElapsedRealtimeNs =
                recordingResult?.startCallElapsedNs
                    ?: current.started
                        ?.startCallElapsedRealtimeNs,
            cameraXStartElapsedRealtimeNs =
                recordingResult?.cameraXStartElapsedNs,
            finalizeElapsedRealtimeNs =
                recordingResult?.finalizeElapsedNs,
        )
        dualCapture = null
        return result
    }

    override suspend fun abort(reason: String) {
        val current = dualCapture ?: return
        if (videoRecorder.isRecording()) {
            try {
                videoRecorder.stopRecording()
            } catch (_: Throwable) {
                // Preserve abort metadata even if CameraX finalize fails.
            }
        }
        runCatching {
            current.manifestFile.writeText(
                JSONObject()
                    .put("schema_version", 1)
                    .put(
                        "dual_capture_id",
                        current.request.dualCaptureId,
                    )
                    .put("role", current.request.role.name)
                    .put("captured", false)
                    .put("aborted", true)
                    .put("reason", reason)
                    .toString(2) + "\n",
                Charsets.UTF_8,
            )
        }
        dualCapture = null
    }

    private fun safeDualCaptureId(value: String): String {
        require(
            value.matches(
                Regex("[A-Za-z0-9._-]{8,80}"),
            ),
        ) {
            "Invalid dual_capture_id"
        }
        return value
    }

    private fun JSONObject.putNullable(
        key: String,
        value: Any?,
    ): JSONObject = put(key, value ?: JSONObject.NULL)

    override suspend fun deleteFiles(fileUrls: List<String>): CameraDeleteResult = CameraDeleteResult(emptyList(), emptyMap())

    private data class ActiveDualPhoneCapture(
        val request: DualPhoneCaptureArmRequest,
        val armResult: DualPhoneCaptureArmResult,
        val baseDir: File,
        val videoFile: File,
        val manifestFile: File,
        val armedAtElapsedNs: Long,
        val startRequest: DualPhoneCaptureStartRequest? = null,
        val started: DualPhoneCaptureStartResult? = null,
    )

    private data class ActivePhoneScan(
        val scanId: String,
        val sessionId: String,
        val scanName: String,
        val sequenceNumber: Int,
        val baseDir: File,
        val cameraInfoFile: File,
        val imuFile: File,
        val startedAt: Instant,
        val calibration: PhoneScanCalibrationMetadata?,
    )
}
