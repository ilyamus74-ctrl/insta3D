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
    private val tofCaptureSidecarRecorder = TofCaptureSidecarRecorder(appContext)
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
        val baseDir = File(
            appContext.filesDir,
            "sessions/$sessionId/phone_scans/$scanId",
        ).apply { mkdirs() }
        val provisionalVideoStartNs = android.os.SystemClock.elapsedRealtimeNanos()
        val imuFile = imuRecorder.start(
            sessionId = sessionId,
            scanId = scanId,
            baseDir = baseDir,
            videoStartTNs = provisionalVideoStartNs,
        )
        tofCaptureSidecarRecorder.start(baseDir)
        writeActiveTofCalibrationSnapshot(appContext, baseDir)
        try {
            videoRecorder.startRecording(sessionId, scanId)
        } catch (error: Throwable) {
            tofCaptureSidecarRecorder.stop()
            imuRecorder.stop()
            throw error
        }
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
        val tofSummary = tofCaptureSidecarRecorder.stop()
        if (tofSummary == null) {
            File(current.baseDir, "tof_calibration.json").delete()
        }
        imuRecorder.stop()
        val videoTimelineStartNs =
            video.cameraXStartElapsedNs ?: video.startCallElapsedNs
        if (videoTimelineStartNs != null) {
            imuRecorder.rebaseVideoTimeline(
                file = current.imuFile,
                videoStartTNs = videoTimelineStartNs,
                anchorSource = "CAMERAX_VIDEO_RECORD_EVENT_START",
            )
        }
        val encoderPtsSummary = withContext(Dispatchers.IO) {
            Mp4VideoPtsExtractor.extract(
                videoFile = File(video.path),
                outputFile = File(current.baseDir, "encoder_pts.jsonl"),
                cameraXStartElapsedNs = video.cameraXStartElapsedNs,
            )
        }
        cameraInfoCollector.updateRuntimeCaptureState(
            current.cameraInfoFile,
            video.frameTelemetrySummary,
        )
        val finishedAt = Instant.now()
        val manifestFile = manifestWriter.write(
            baseDir = current.baseDir,
            scanId = current.scanId,
            sessionId = current.sessionId,
            videoFile = File(video.path),
            cameraInfoFile = current.cameraInfoFile,
            imuFile = current.imuFile,
            framesFile = video.frameTelemetrySummary?.path?.let(::File),
            tofFramesFile = tofSummary?.path?.let(::File),
            tofCalibrationFile = File(current.baseDir, "tof_calibration.json")
                .takeIf { tofSummary != null && it.isFile && it.length() > 0L },
            encoderPtsFile = File(encoderPtsSummary.path)
                .takeIf { it.isFile && it.length() > 0L },
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
            abort(
                "STALE_ARM_REPLACED_BY_NEW_COMMAND: old_capture=" +
                    existing.request.dualCaptureId +
                    " new_capture=${request.dualCaptureId}",
            )
        } else if (videoRecorder.isRecording()) {
            videoRecorder.resetRecorderState(
                "ORPHAN_RECORDER_RECOVERED_BEFORE_ARM",
            )
            imuRecorder.stop()
        }

        var readiness = videoRecorder.ensureRecordingReady(
            preferredVideoModeId = request.preferredVideoModeId,
            forceRebind = true,
            requirePreviewSurface = true,
        )
        var preparationFallbackReason: String? = null
        if (!readiness.ready) {
            val requestedPreparationError = readiness.reason
                ?: "Requested preview-backed CameraX preparation failed"
            val failedModeId = listOf(
                readiness.width,
                readiness.height,
                readiness.fps,
            ).takeIf { values -> values.all { it != null } }
                ?.let { values ->
                    "${values[0]}x${values[1]}@${values[2]}"
                }
            val fallbackModeId = videoRecorder.regular30FpsFallbackModeId(
                request.preferredVideoModeId,
            )
            if (fallbackModeId != null && fallbackModeId != failedModeId) {
                val fallbackReadiness = videoRecorder.ensureRecordingReady(
                    preferredVideoModeId = fallbackModeId,
                    forceRebind = true,
                    requirePreviewSurface = true,
                )
                readiness = if (fallbackReadiness.ready) {
                    preparationFallbackReason =
                        "REQUESTED_MODE_PREVIEW_BIND_FAILED: " +
                            requestedPreparationError
                    fallbackReadiness
                } else {
                    fallbackReadiness.copy(
                        reason = "Requested mode preparation failed: " +
                            requestedPreparationError +
                            "; 30 FPS fallback failed: " +
                            (fallbackReadiness.reason ?: "unknown error"),
                    )
                }
            }
        }
        val root = File(
            appContext.filesDir,
            "dual_phone_captures",
        ).apply { mkdirs() }
        val availableBytes = root.usableSpace
        var modeId = listOf(
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
        val frameEncoderMapFile = File(baseDir, "frame_encoder_map.jsonl")
        val localTimelineReportFile = File(baseDir, "local_timeline_report.json")
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
            frameEncoderMapFile,
            localTimelineReportFile,
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
                .putNullable("clock_quality_at_arm", request.clockQualityAtArm)
                .putNullable("clock_offset_ns_at_arm", request.clockOffsetNsAtArm)
                .putNullable(
                    "clock_uncertainty_ns_at_arm",
                    request.clockUncertaintyNsAtArm,
                )
                .putNullable("clock_drift_ppm_at_arm", request.clockDriftPpmAtArm)
                .put(
                    "clock_accepted_samples_at_arm",
                    request.clockAcceptedSamplesAtArm,
                )
                .put(
                    "clock_total_samples_at_arm",
                    request.clockTotalSamplesAtArm,
                )
                .put("capture_window_start_written", false)
                .toString(2) + "\n",
            Charsets.UTF_8,
        )
        timeline.event(
            name = "ARM_RECEIVED",
            localElapsedNs = armedAtNs,
            commandId = request.commandId,
            details = JSONObject()
                .putNullable("clock_quality_at_arm", request.clockQualityAtArm)
                .putNullable("clock_offset_ns_at_arm", request.clockOffsetNsAtArm)
                .putNullable(
                    "clock_uncertainty_ns_at_arm",
                    request.clockUncertaintyNsAtArm,
                )
                .putNullable("clock_drift_ppm_at_arm", request.clockDriftPpmAtArm)
                .put(
                    "clock_accepted_samples_at_arm",
                    request.clockAcceptedSamplesAtArm,
                )
                .put("clock_total_samples_at_arm", request.clockTotalSamplesAtArm),
        )
        imuRecorder.start(
            sessionId = request.dualCaptureId,
            scanId = request.role.name.lowercase(),
            baseDir = baseDir,
            videoStartTNs = armedAtNs,
        )
        var fallbackReason: String? = preparationFallbackReason
        if (preparationFallbackReason != null) {
            timeline.event(
                name = "MODE_FALLBACK_SELECTED",
                localElapsedNs = android.os.SystemClock.elapsedRealtimeNanos(),
                commandId = request.commandId,
                details = JSONObject()
                    .putNullable(
                        "requested_video_mode_id",
                        request.preferredVideoModeId,
                    )
                    .putNullable("fallback_video_mode_id", modeId)
                    .put("reason", preparationFallbackReason),
            )
        }

        suspend fun startRecorderAttempt(attemptNumber: Int): PhoneVideoRecordingStart {
            videoFile.delete()
            timeline.event(
                name = "RECORDER_ATTEMPT_STARTED",
                localElapsedNs = android.os.SystemClock.elapsedRealtimeNanos(),
                commandId = request.commandId,
                details = JSONObject()
                    .put("attempt_number", attemptNumber)
                    .putNullable("video_mode_id", modeId)
                    .put("binding_required", "DUAL_PHONE_PREVIEW_BACKED"),
            )
            return try {
                videoRecorder.startRecordingToFileWithTelemetry(
                    outputFile = videoFile,
                    telemetryContext = PhoneVideoTelemetryContext(
                        dualCaptureId = request.dualCaptureId,
                        role = request.role.name,
                        scheduledElapsedRealtimeNs = armedAtNs,
                        clockOffsetNs = request.clockOffsetNsAtArm,
                        clockUncertaintyNs = request.clockUncertaintyNsAtArm,
                        clockDriftPpm = request.clockDriftPpmAtArm,
                    ),
                ).also { started ->
                    timeline.event(
                        name = "RECORDER_ATTEMPT_READY",
                        localElapsedNs = android.os.SystemClock.elapsedRealtimeNanos(),
                        commandId = request.commandId,
                        details = JSONObject()
                            .put("attempt_number", attemptNumber)
                            .putNullable("video_mode_id", modeId)
                            .putNullable(
                                "recorder_binding_mode",
                                started.recorderBindingMode,
                            )
                            .put(
                                "status_event_count",
                                started.statusEventCountAtReady,
                            )
                            .put(
                                "encoded_bytes_at_ready",
                                started.recordedBytesAtReady,
                            )
                            .put(
                                "encoded_duration_ns_at_ready",
                                started.recordedDurationNsAtReady,
                            ),
                    )
                }
            } catch (error: Throwable) {
                val preservedPath = preserveFailedVideoAttempt(
                    videoFile = videoFile,
                    baseDir = baseDir,
                    attemptNumber = attemptNumber,
                )
                val details = JSONObject()
                    .put("attempt_number", attemptNumber)
                    .putNullable("video_mode_id", modeId)
                    .put(
                        "error",
                        error.message ?: error.javaClass.simpleName,
                    )
                    .putNullable("preserved_partial_video_path", preservedPath)
                (error as? PhoneVideoNoValidDataException)
                    ?.diagnostics
                    ?.let { diagnostics ->
                        details.put(
                            "recorder_diagnostics",
                            diagnostics.toJson(),
                        )
                    }
                timeline.event(
                    name = "RECORDER_ATTEMPT_FAILED",
                    localElapsedNs = android.os.SystemClock.elapsedRealtimeNanos(),
                    commandId = request.commandId,
                    details = details,
                )
                throw error
            }
        }

        val physicalStarted = try {
            try {
                startRecorderAttempt(attemptNumber = 1)
            } catch (noData: PhoneVideoNoValidDataException) {
                val fallbackModeId = videoRecorder.regular30FpsFallbackModeId(
                    request.preferredVideoModeId,
                )
                if (fallbackModeId == null || fallbackModeId == modeId) {
                    throw noData
                }
                fallbackReason =
                    "REQUESTED_MODE_PRODUCED_NO_VALID_DATA: " +
                        (noData.message ?: "CameraX recorder health timeout")
                timeline.event(
                    name = "MODE_FALLBACK_SELECTED",
                    localElapsedNs = android.os.SystemClock.elapsedRealtimeNanos(),
                    commandId = request.commandId,
                    details = JSONObject()
                        .putNullable("requested_video_mode_id", modeId)
                        .put("fallback_video_mode_id", fallbackModeId)
                        .put("reason", fallbackReason),
                )
                readiness = videoRecorder.ensureRecordingReady(
                    preferredVideoModeId = fallbackModeId,
                    forceRebind = true,
                    requirePreviewSurface = true,
                )
                check(readiness.ready) {
                    readiness.reason
                        ?: "30 FPS preview-backed CameraX fallback preparation failed"
                }
                modeId = listOf(
                    readiness.width,
                    readiness.height,
                    readiness.fps,
                ).takeIf { values -> values.all { it != null } }
                    ?.let { values ->
                        "${values[0]}x${values[1]}@${values[2]}"
                    }
                startRecorderAttempt(attemptNumber = 2)
            }
        } catch (error: Throwable) {
            val resetDiagnostics = videoRecorder.resetRecorderState(
                "ARM_RECORDING_START_FAILED",
            )
            imuRecorder.stop()
            val details = JSONObject().put(
                "error",
                error.message ?: error.javaClass.simpleName,
            )
            resetDiagnostics?.let {
                details.put("recorder_reset_diagnostics", it.toJson())
            }
            timeline.event(
                name = "ARM_RECORDING_START_FAILED",
                localElapsedNs = android.os.SystemClock.elapsedRealtimeNanos(),
                commandId = request.commandId,
                details = details,
            )
            timeline.close()
            dualCapture = null
            throw error
        }
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
        timeline.event(
            name = "PHYSICAL_RECORDING_STARTED",
            localElapsedNs = physicalStarted.startCallElapsedNs,
            commandId = request.commandId,
            details = JSONObject()
                .put("video_path", physicalStarted.path)
                .putNullable(
                    "camerax_start_elapsed_ns",
                    physicalStarted.cameraXStartElapsedNs,
                )
                .put("valid_encoded_data_observed", physicalStarted.validEncodedDataObserved)
                .put("pre_roll_bytes_at_ready", physicalStarted.recordedBytesAtReady)
                .put("pre_roll_duration_ns_at_ready", physicalStarted.recordedDurationNsAtReady)
                .putNullable("mode_fallback_reason", fallbackReason),
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
            requestedVideoModeId = request.preferredVideoModeId,
            modeFallbackReason = fallbackReason,
            physicalRecordingStarted = true,
            physicalStartCallElapsedRealtimeNs =
                physicalStarted.startCallElapsedNs,
            physicalCameraXStartElapsedRealtimeNs =
                physicalStarted.cameraXStartElapsedNs,
            validEncodedDataObserved = physicalStarted.validEncodedDataObserved,
            preRollBytesAtReady = physicalStarted.recordedBytesAtReady,
            preRollDurationNsAtReady = physicalStarted.recordedDurationNsAtReady,
        )
        dualCapture = ActiveDualPhoneCapture(
            request = request,
            armResult = armResult,
            baseDir = baseDir,
            videoFile = videoFile,
            manifestFile = manifestFile,
            framesFile = framesFile,
            encoderPtsFile = encoderPtsFile,
            frameEncoderMapFile = frameEncoderMapFile,
            localTimelineReportFile = localTimelineReportFile,
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
                .put("start_alignment_mode", request.alignmentMode.name)
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
                .put("alignment_mode", request.alignmentMode.name)
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
        if (recordingResult != null) {
            cameraInfoCollector.updateRuntimeCaptureState(
                current.cameraInfoFile,
                frameSummary,
            )
        }
        val localTimelineSummary = if (recordingResult != null) {
            withContext(Dispatchers.IO) {
                DualPhoneLocalTimelineAnalyzer.analyze(
                    framesFile = current.framesFile,
                    encoderPtsFile = current.encoderPtsFile,
                    frameEncoderMapFile = current.frameEncoderMapFile,
                    localTimelineReportFile = current.localTimelineReportFile,
                    cameraXStartElapsedNs = recordingResult.cameraXStartElapsedNs
                        ?: current.physicalStarted.cameraXStartElapsedNs,
                    fallbackWidth = current.armResult.width,
                    fallbackHeight = current.armResult.height,
                )
            }
        } else {
            null
        }
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
            .putNullable(
                "requested_video_mode_id",
                current.armResult.requestedVideoModeId,
            )
            .putNullable(
                "effective_video_mode_id",
                current.armResult.videoModeId,
            )
            .putNullable(
                "mode_fallback_reason",
                current.armResult.modeFallbackReason,
            )
            .put("pre_roll_valid_encoded_data", current.armResult.validEncodedDataObserved)
            .put("pre_roll_bytes_at_ready", current.armResult.preRollBytesAtReady)
            .put("pre_roll_duration_ns_at_ready", current.armResult.preRollDurationNsAtReady)
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
            .putNullable(
                "capture_window_start_alignment_mode",
                current.startRequest?.alignmentMode?.name,
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
            .putNullable(
                "frame_encoder_map_path",
                localTimelineSummary?.frameEncoderMapPath,
            )
            .putNullable(
                "local_timeline_report_path",
                localTimelineSummary?.localTimelineReportPath,
            )
            .put("capture_result_count", frameSummary?.frameCount ?: 0L)
            .put(
                "encoded_video_sample_count",
                encoderPtsSummary?.sampleCount ?: 0L,
            )
            .putNullable(
                "capture_result_observed_fps",
                localTimelineSummary?.captureResultFpsActual
                    ?: frameSummary?.observedCaptureResultFps,
            )
            .putNullable(
                "encoded_video_observed_fps",
                localTimelineSummary?.encoderFpsActual
                    ?: encoderPtsSummary?.observedFps,
            )
            .putNullable(
                "capture_result_fps_actual",
                localTimelineSummary?.captureResultFpsActual,
            )
            .putNullable(
                "encoder_fps_actual",
                localTimelineSummary?.encoderFpsActual,
            )
            .putNullable(
                "effective_video_mode_actual",
                localTimelineSummary?.effectiveVideoModeActual,
            )
            .putNullable(
                "actual_video_width",
                localTimelineSummary?.actualWidth,
            )
            .putNullable(
                "actual_video_height",
                localTimelineSummary?.actualHeight,
            )
            .put(
                "capture_result_gap_count",
                localTimelineSummary?.captureResultGapCount ?: 0L,
            )
            .put(
                "estimated_missing_capture_results",
                localTimelineSummary?.captureResultGapCount ?: 0L,
            )
            .put(
                "encoder_gap_count",
                localTimelineSummary?.encoderGapCount ?: 0L,
            )
            .put(
                "mapped_encoder_samples",
                localTimelineSummary?.mappedSamples ?: 0L,
            )
            .put(
                "unmatched_encoded_samples",
                localTimelineSummary?.unmatchedEncodedSamples ?: 0L,
            )
            .put(
                "unmatched_capture_results",
                localTimelineSummary?.unmatchedCaptureResults ?: 0L,
            )
            .putNullable(
                "mapping_residual_p50_ns",
                localTimelineSummary?.mappingResidualP50Ns,
            )
            .putNullable(
                "mapping_residual_p95_ns",
                localTimelineSummary?.mappingResidualP95Ns,
            )
            .putNullable(
                "mapping_residual_max_ns",
                localTimelineSummary?.mappingResidualMaxNs,
            )
            .putNullable(
                "local_timeline_mapping_quality",
                localTimelineSummary?.mappingQuality,
            )
            .put("video_parseable", localTimelineSummary?.videoParseable == true)
            .put("keyframe_present", localTimelineSummary?.keyframePresent == true)
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
                localTimelineSummary?.mappingStatus ?: "UNAVAILABLE",
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
        val current = dualCapture
        current?.timeline?.event(
            name = "CAPTURE_ABORTED",
            localElapsedNs = android.os.SystemClock.elapsedRealtimeNanos(),
            details = JSONObject().put("reason", reason),
        )
        val resetDiagnostics = videoRecorder.resetRecorderState(reason)
        resetDiagnostics?.let { diagnostics ->
            current?.timeline?.event(
                name = "RECORDER_STATE_RESET",
                localElapsedNs = android.os.SystemClock.elapsedRealtimeNanos(),
                details = diagnostics.toJson().put("reason", reason),
            )
        }
        imuRecorder.stop()
        current?.timeline?.close()
        if (current != null) {
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
                        .put(
                            "capture_events_path",
                            current.eventsFile.absolutePath,
                        )
                        .put(
                            "clock_sync_history_path",
                            current.clockSyncHistoryFile.absolutePath,
                        )
                        .putNullable(
                            "recorder_reset_diagnostics",
                            resetDiagnostics?.toJson(),
                        )
                        .toString(2) + "\n",
                    Charsets.UTF_8,
                )
            }
        }
        dualCapture = null
    }

    private fun preserveFailedVideoAttempt(
        videoFile: File,
        baseDir: File,
        attemptNumber: Int,
    ): String? {
        if (!videoFile.exists() || videoFile.length() <= 0L) {
            videoFile.delete()
            return null
        }
        val target = File(
            baseDir,
            "video_attempt_${attemptNumber}_failed.mp4",
        )
        target.delete()
        val preserved = runCatching {
            if (!videoFile.renameTo(target)) {
                videoFile.copyTo(target, overwrite = true)
                videoFile.delete()
            }
            target.takeIf { it.isFile && it.length() > 0L }
                ?.absolutePath
        }.getOrNull()
        if (preserved == null) {
            videoFile.delete()
        }
        return preserved
    }

    private fun PhoneVideoAttemptDiagnostics.toJson(): JSONObject =
        JSONObject()
            .putNullable("requested_mode_id", requestedModeId)
            .put("recorder_binding_mode", recorderBindingMode)
            .put("camerax_start_observed", cameraXStartObserved)
            .put("status_event_count", statusEventCount)
            .put("last_recorded_bytes", lastRecordedBytes)
            .put("last_recorded_duration_ns", lastRecordedDurationNs)
            .put("file_size_bytes", fileSizeBytes)
            .put("preview_attached", previewAttached)
            .put("preview_width", previewWidth)
            .put("preview_height", previewHeight)
            .put("preview_stream_state", previewStreamState)
            .put("finalize_received", finalizeReceived)
            .putNullable("finalize_error_code", finalizeErrorCode)
            .putNullable("finalize_error_label", finalizeErrorLabel)
            .putNullable("finalize_cause", finalizeCause)

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
        val frameEncoderMapFile: File,
        val localTimelineReportFile: File,
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
