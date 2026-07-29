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
import com.maklertour.data.dualphone.DualPhoneCaptureStopRequest
import com.maklertour.data.dualphone.DualPhoneCaptureStopResult
import com.maklertour.data.dualphone.DualPhoneClockSyncSnapshot
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
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.delay
import kotlinx.coroutines.withContext
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
                existing.request.commandId == request.commandId
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
        val framesFile = File(baseDir, "frames.jsonl")
        val encoderPtsFile = File(baseDir, "encoder_pts.jsonl")
        val imuFile = File(baseDir, "imu.jsonl")
        val cameraInfoFile = File(baseDir, "camera_info.json")
        val clockSyncFile = File(baseDir, "clock_sync.json")
        val eventsFile = File(baseDir, "capture_events.jsonl")
        val clockSyncHistoryFile = File(baseDir, "clock_sync_history.jsonl")
        listOf(
            videoFile,
            manifestFile,
            framesFile,
            encoderPtsFile,
            imuFile,
            cameraInfoFile,
            clockSyncFile,
            eventsFile,
            clockSyncHistoryFile,
        ).forEach { it.delete() }
        cameraInfoCollector.writeCameraInfo(
            baseDir = baseDir,
            selectedVideoInfo = videoRecorder.getSelectedVideoInfo(),
            selectedLens = videoRecorder.getSelectedLensOption(),
            requestedZoomRatio = videoRecorder.getRequestedZoomRatio(),
            effectiveZoomRatio = videoRecorder.getEffectiveZoomRatio(),
            minZoomRatio = videoRecorder.getMinZoomRatio(),
            maxZoomRatio = videoRecorder.getMaxZoomRatio(),
            calibrationResolutionInfo =
                videoRecorder.getCalibrationResolutionInfo(),
        )

        val armedAtNs = android.os.SystemClock.elapsedRealtimeNanos()
        val timeline = DualPhoneCaptureTimeline()
        timeline.open(
            baseDir = baseDir,
            dualCaptureId = request.dualCaptureId,
            role = request.role.name,
            deviceId = request.deviceId,
            armedAtElapsedNs = armedAtNs,
        )
        clockSyncFile.writeText(
            JSONObject()
                .put("schema_version", 2)
                .put("timeline_mode", "ASYNC_PRE_ROLL_POST_ROLL")
                .put("dual_capture_id", request.dualCaptureId)
                .put("role", request.role.name)
                .put("armed_at_elapsed_ns", armedAtNs)
                .put("clock_domain", "CLOCK_BOOTTIME")
                .put("capture_window_start_written", false)
                .toString(2) + "\n",
            Charsets.UTF_8,
        )
        timeline.event(
            name = "ARM_RECEIVED",
            localElapsedNs = armedAtNs,
            commandId = request.commandId,
        )
        imuRecorder.start(
            sessionId = request.dualCaptureId,
            scanId = request.role.name.lowercase(),
            baseDir = baseDir,
            videoStartTNs = armedAtNs,
        )
        val physicalStarted = try {
            videoRecorder.startRecordingToFileWithTelemetry(
                outputFile = videoFile,
                telemetryContext = PhoneVideoTelemetryContext(
                    dualCaptureId = request.dualCaptureId,
                    role = request.role.name,
                    scheduledElapsedRealtimeNs = armedAtNs,
                    clockOffsetNs = null,
                    clockUncertaintyNs = null,
                    clockDriftPpm = null,
                ),
            )
        } catch (error: Throwable) {
            imuRecorder.stop()
            timeline.event(
                name = "ARM_RECORDING_START_FAILED",
                localElapsedNs = android.os.SystemClock.elapsedRealtimeNanos(),
                commandId = request.commandId,
                details = JSONObject().put(
                    "error",
                    error.message ?: error.javaClass.simpleName,
                ),
            )
            timeline.close()
            throw error
        }
        timeline.event(
            name = "PHYSICAL_RECORDING_STARTED",
            localElapsedNs = physicalStarted.startCallElapsedNs,
            commandId = request.commandId,
            details = JSONObject()
                .put("video_path", physicalStarted.path)
                .putNullable(
                    "camerax_start_elapsed_ns",
                    physicalStarted.cameraXStartElapsedNs,
                ),
        )

        val armResult = DualPhoneCaptureArmResult(
            ready = true,
            outputPath = videoFile.absolutePath,
            availableBytes = availableBytes,
            cameraId = readiness.cameraId,
            videoModeId = modeId,
            width = readiness.width,
            height = readiness.height,
            fps = readiness.fps,
            physicalRecordingStarted = true,
            physicalStartCallElapsedRealtimeNs =
                physicalStarted.startCallElapsedNs,
            physicalCameraXStartElapsedRealtimeNs =
                physicalStarted.cameraXStartElapsedNs,
        )
        dualCapture = ActiveDualPhoneCapture(
            request = request,
            armResult = armResult,
            baseDir = baseDir,
            videoFile = videoFile,
            manifestFile = manifestFile,
            framesFile = framesFile,
            encoderPtsFile = encoderPtsFile,
            imuFile = imuFile,
            cameraInfoFile = cameraInfoFile,
            clockSyncFile = clockSyncFile,
            eventsFile = eventsFile,
            clockSyncHistoryFile = clockSyncHistoryFile,
            timeline = timeline,
            armedAtElapsedNs = armedAtNs,
            physicalStarted = physicalStarted,
            imuStarted = true,
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
        current.started?.let { existing ->
            if (current.startRequest?.commandId == request.commandId) {
                return existing
            }
            throw IllegalStateException(
                "Capture window START marker was already written",
            )
        }

        val markerAppliedNs = android.os.SystemClock.elapsedRealtimeNanos()
        current.clockSyncFile.writeText(
            JSONObject()
                .put("schema_version", 2)
                .put("timeline_mode", "ASYNC_PRE_ROLL_POST_ROLL")
                .put("dual_capture_id", request.dualCaptureId)
                .put("role", request.role.name)
                .put("command_id", request.commandId)
                .put(
                    "capture_window_start_target_elapsed_ns",
                    request.scheduledElapsedRealtimeNs,
                )
                .put(
                    "capture_window_start_applied_elapsed_ns",
                    markerAppliedNs,
                )
                .putNullable("clock_offset_ns", request.clockOffsetNs)
                .putNullable(
                    "clock_uncertainty_ns",
                    request.clockUncertaintyNs,
                )
                .putNullable("clock_drift_ppm", request.clockDriftPpm)
                .put("clock_domain", "CLOCK_BOOTTIME")
                .toString(2) + "\n",
            Charsets.UTF_8,
        )
        current.timeline.event(
            name = "CAPTURE_WINDOW_START",
            localElapsedNs = markerAppliedNs,
            commandId = request.commandId,
            commandCreatedMasterNs =
                request.commandCreatedMasterElapsedRealtimeNs,
            scheduledLocalNs = request.scheduledElapsedRealtimeNs,
            details = JSONObject()
                .putNullable(
                    "command_received_local_ns",
                    request.commandReceivedLocalElapsedRealtimeNs,
                )
                .put("marker_delta_ns", markerAppliedNs - request.scheduledElapsedRealtimeNs),
        )
        val result = DualPhoneCaptureStartResult(
            videoPath = current.physicalStarted.path,
            scheduledElapsedRealtimeNs =
                request.scheduledElapsedRealtimeNs,
            startCallElapsedRealtimeNs = markerAppliedNs,
            cameraXStartElapsedRealtimeNs =
                current.physicalStarted.cameraXStartElapsedNs,
            commandId = request.commandId,
            physicalStartCallElapsedRealtimeNs =
                current.physicalStarted.startCallElapsedNs,
            markerAppliedElapsedRealtimeNs = markerAppliedNs,
        )
        dualCapture = current.copy(
            startRequest = request,
            started = result,
        )
        return result
    }

    override suspend fun markStop(
        request: DualPhoneCaptureStopRequest,
    ) {
        val current = dualCapture
            ?: throw IllegalStateException(
                "Dual-phone recorder is not armed",
            )
        require(current.request.dualCaptureId == request.dualCaptureId) {
            "dual_capture_id does not match active capture"
        }
        require(current.request.role == request.role) {
            "Dual-phone role changed before STOP"
        }
        current.stopRequest?.let { existing ->
            if (existing.commandId == request.commandId) return
            throw IllegalStateException(
                "Capture window STOP marker was already written",
            )
        }
        current.timeline.event(
            name = "CAPTURE_WINDOW_STOP",
            localElapsedNs = request.commandReceivedLocalElapsedRealtimeNs,
            commandId = request.commandId,
            commandCreatedMasterNs =
                request.commandCreatedMasterElapsedRealtimeNs,
            details = JSONObject().put("post_roll_ms", request.postRollMs),
        )
        dualCapture = current.copy(stopRequest = request)
    }

    override suspend fun stop(): DualPhoneCaptureStopResult {
        val initial = dualCapture
            ?: throw IllegalStateException(
                "Dual-phone recorder is not armed",
            )
        val request = initial.stopRequest ?: DualPhoneCaptureStopRequest(
            dualCaptureId = initial.request.dualCaptureId,
            role = initial.request.role,
            commandId = "local-stop-${System.currentTimeMillis()}",
            commandCreatedMasterElapsedRealtimeNs = null,
            commandReceivedLocalElapsedRealtimeNs =
                android.os.SystemClock.elapsedRealtimeNanos(),
        ).also { markStop(it) }
        val current = dualCapture ?: initial
        val stopMarkerNs = request.commandReceivedLocalElapsedRealtimeNs
        val postRollMs = request.postRollMs.coerceIn(0L, 10_000L)
        if (postRollMs > 0L) {
            delay(postRollMs)
        }
        current.timeline.event(
            name = "PHYSICAL_RECORDING_FINALIZE_REQUESTED",
            localElapsedNs = android.os.SystemClock.elapsedRealtimeNanos(),
            commandId = request.commandId,
        )

        val recordingResult = try {
            if (videoRecorder.isRecording()) {
                videoRecorder.stopRecording()
            } else {
                null
            }
        } finally {
            if (current.imuStarted) {
                imuRecorder.stop()
            }
        }
        val encoderPtsSummary = if (recordingResult != null) {
            withContext(Dispatchers.IO) {
                Mp4VideoPtsExtractor.extract(
                    current.videoFile,
                    current.encoderPtsFile,
                )
            }
        } else {
            null
        }
        val frameSummary = recordingResult?.frameTelemetrySummary
        val finishedAtElapsedNs =
            android.os.SystemClock.elapsedRealtimeNanos()
        current.timeline.event(
            name = "PHYSICAL_RECORDING_FINALIZED",
            localElapsedNs = finishedAtElapsedNs,
            commandId = request.commandId,
            details = JSONObject()
                .put("captured", recordingResult != null)
                .put("file_size_bytes", recordingResult?.fileSizeBytes ?: 0L),
        )
        val timelinePaths = current.timeline.paths()
        current.timeline.close()
        val manifest = JSONObject()
            .put("schema_version", 3)
            .put("capture_type", "dual_phone_stereo_video_member")
            .put("timeline_mode", "ASYNC_PRE_ROLL_POST_ROLL")
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
            .put("arm_command_id", current.request.commandId)
            .put("armed_at_elapsed_ns", current.armedAtElapsedNs)
            .put("physical_recording_started_during_arm", true)
            .put(
                "physical_start_call_elapsed_ns",
                recordingResult?.startCallElapsedNs
                    ?: current.physicalStarted.startCallElapsedNs,
            )
            .putNullable(
                "physical_camerax_start_elapsed_ns",
                recordingResult?.cameraXStartElapsedNs
                    ?: current.physicalStarted.cameraXStartElapsedNs,
            )
            .putNullable(
                "capture_window_start_command_id",
                current.startRequest?.commandId,
            )
            .putNullable(
                "capture_window_start_target_elapsed_ns",
                current.startRequest?.scheduledElapsedRealtimeNs,
            )
            .putNullable(
                "capture_window_start_applied_elapsed_ns",
                current.started?.markerAppliedElapsedRealtimeNs,
            )
            // DP04.2 compatibility aliases for the existing local validator.
            .putNullable(
                "scheduled_start_elapsed_ns",
                current.startRequest?.scheduledElapsedRealtimeNs,
            )
            .put(
                "start_call_elapsed_ns",
                recordingResult?.startCallElapsedNs
                    ?: current.physicalStarted.startCallElapsedNs,
            )
            .putNullable(
                "camerax_start_elapsed_ns",
                recordingResult?.cameraXStartElapsedNs
                    ?: current.physicalStarted.cameraXStartElapsedNs,
            )
            .put("capture_window_stop_command_id", request.commandId)
            .put(
                "capture_window_stop_received_elapsed_ns",
                stopMarkerNs,
            )
            .put("post_roll_ms", postRollMs)
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
                "finalize_elapsed_ns",
                recordingResult?.finalizeElapsedNs,
            )
            .put("finished_at_elapsed_ns", finishedAtElapsedNs)
            .put("camera_id", current.armResult.cameraId)
            .put("video_mode_id", current.armResult.videoModeId)
            .put("width", current.armResult.width)
            .put("height", current.armResult.height)
            .put("fps_requested", current.armResult.fps)
            .put("camera_info_path", current.cameraInfoFile.absolutePath)
            .put("clock_sync_path", current.clockSyncFile.absolutePath)
            .put("capture_events_path", current.eventsFile.absolutePath)
            .put(
                "clock_sync_history_path",
                current.clockSyncHistoryFile.absolutePath,
            )
            .putNullable(
                "imu_path",
                current.imuFile.takeIf { it.isFile }?.absolutePath,
            )
            .putNullable(
                "frames_path",
                frameSummary?.path,
            )
            .putNullable(
                "encoder_pts_path",
                encoderPtsSummary?.path,
            )
            .putNullable(
                "encoder_pts_status",
                encoderPtsSummary?.status,
            )
            .put("capture_result_count", frameSummary?.frameCount ?: 0L)
            .put(
                "encoded_video_sample_count",
                encoderPtsSummary?.sampleCount ?: 0L,
            )
            .putNullable(
                "capture_result_observed_fps",
                frameSummary?.observedCaptureResultFps,
            )
            .putNullable(
                "encoded_video_observed_fps",
                encoderPtsSummary?.observedFps,
            )
            .put(
                "estimated_missing_capture_results",
                frameSummary?.estimatedMissingCaptureResults ?: 0L,
            )
            .put(
                "frame_timestamp_source",
                "CAMERA2_CAPTURE_RESULT_SENSOR_TIMESTAMP",
            )
            .put(
                "encoder_pts_source",
                "ANDROID_MEDIA_EXTRACTOR_SAMPLE_TIME",
            )
            .put(
                "frame_to_encoder_mapping_status",
                "UNVERIFIED_SEPARATE_TIMELINES",
            )
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
                current.startRequest?.scheduledElapsedRealtimeNs,
            startCallElapsedRealtimeNs =
                recordingResult?.startCallElapsedNs
                    ?: current.physicalStarted.startCallElapsedNs,
            cameraXStartElapsedRealtimeNs =
                recordingResult?.cameraXStartElapsedNs
                    ?: current.physicalStarted.cameraXStartElapsedNs,
            finalizeElapsedRealtimeNs =
                recordingResult?.finalizeElapsedNs,
            captureWindowStartMarkerElapsedRealtimeNs =
                current.started?.markerAppliedElapsedRealtimeNs,
            captureWindowStopMarkerElapsedRealtimeNs = stopMarkerNs,
            captureEventsPath = timelinePaths.first,
            clockSyncHistoryPath = timelinePaths.second,
        )
        dualCapture = null
        return result
    }

    override fun recordClockSync(snapshot: DualPhoneClockSyncSnapshot) {
        dualCapture?.timeline?.clock(snapshot)
    }

    override suspend fun abort(reason: String) {
        val current = dualCapture ?: return
        current.timeline.event(
            name = "CAPTURE_ABORTED",
            localElapsedNs = android.os.SystemClock.elapsedRealtimeNanos(),
            details = JSONObject().put("reason", reason),
        )
        if (videoRecorder.isRecording()) {
            try {
                videoRecorder.stopRecording()
            } catch (_: Throwable) {
                // Preserve abort metadata even if CameraX finalize fails.
            }
        }
        imuRecorder.stop()
        current.timeline.close()
        runCatching {
            current.manifestFile.writeText(
                JSONObject()
                    .put("schema_version", 3)
                    .put("timeline_mode", "ASYNC_PRE_ROLL_POST_ROLL")
                    .put(
                        "dual_capture_id",
                        current.request.dualCaptureId,
                    )
                    .put("role", current.request.role.name)
                    .put("captured", false)
                    .put("aborted", true)
                    .put("reason", reason)
                    .put("capture_events_path", current.eventsFile.absolutePath)
                    .put(
                        "clock_sync_history_path",
                        current.clockSyncHistoryFile.absolutePath,
                    )
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
        val framesFile: File,
        val encoderPtsFile: File,
        val imuFile: File,
        val cameraInfoFile: File,
        val clockSyncFile: File,
        val eventsFile: File,
        val clockSyncHistoryFile: File,
        val timeline: DualPhoneCaptureTimeline,
        val armedAtElapsedNs: Long,
        val physicalStarted: PhoneVideoRecordingStart,
        val startRequest: DualPhoneCaptureStartRequest? = null,
        val started: DualPhoneCaptureStartResult? = null,
        val stopRequest: DualPhoneCaptureStopRequest? = null,
        val imuStarted: Boolean = false,
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
