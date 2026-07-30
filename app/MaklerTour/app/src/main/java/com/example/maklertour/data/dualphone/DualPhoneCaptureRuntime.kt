package com.maklertour.data.dualphone

const val DUAL_PHONE_DEFAULT_POST_ROLL_MS = 1_500L

enum class DualPhoneStartAlignmentMode {
    SCHEDULED_CLOCK_MODEL,
    DEGRADED_ASYNC_MARKER,
}

data class DualPhoneCaptureArmRequest(
    val dualCaptureId: String,
    val role: DualPhoneRole,
    val deviceId: String,
    val peerDeviceId: String?,
    val preferredVideoModeId: String?,
    val commandId: String = "legacy-arm",
    val clockQualityAtArm: String? = null,
    val clockOffsetNsAtArm: Long? = null,
    val clockUncertaintyNsAtArm: Long? = null,
    val clockDriftPpmAtArm: Double? = null,
    val clockAcceptedSamplesAtArm: Int = 0,
    val clockTotalSamplesAtArm: Int = 0,
)

data class DualPhoneCaptureArmResult(
    val ready: Boolean,
    val reason: String? = null,
    val outputPath: String? = null,
    val availableBytes: Long = 0L,
    val cameraId: String? = null,
    val videoModeId: String? = null,
    val width: Int? = null,
    val height: Int? = null,
    val fps: Int? = null,
    val requestedVideoModeId: String? = null,
    val modeFallbackReason: String? = null,
    val physicalRecordingStarted: Boolean = false,
    val physicalStartCallElapsedRealtimeNs: Long? = null,
    val physicalCameraXStartElapsedRealtimeNs: Long? = null,
    val validEncodedDataObserved: Boolean = false,
    val preRollBytesAtReady: Long = 0L,
    val preRollDurationNsAtReady: Long = 0L,
)

data class DualPhoneCaptureStartRequest(
    val dualCaptureId: String,
    val role: DualPhoneRole,
    val scheduledElapsedRealtimeNs: Long,
    val clockOffsetNs: Long?,
    val clockUncertaintyNs: Long?,
    val clockDriftPpm: Double?,
    val alignmentMode: DualPhoneStartAlignmentMode =
        DualPhoneStartAlignmentMode.SCHEDULED_CLOCK_MODEL,
    val commandId: String = "legacy-start",
    val commandCreatedMasterElapsedRealtimeNs: Long? = null,
    val commandReceivedLocalElapsedRealtimeNs: Long? = null,
)

data class DualPhoneCaptureStartResult(
    val videoPath: String,
    val scheduledElapsedRealtimeNs: Long,
    val startCallElapsedRealtimeNs: Long,
    val cameraXStartElapsedRealtimeNs: Long?,
    val commandId: String = "legacy-start",
    val physicalStartCallElapsedRealtimeNs: Long? = null,
    val markerAppliedElapsedRealtimeNs: Long = startCallElapsedRealtimeNs,
) {
    val startLatenessNs: Long
        get() = markerAppliedElapsedRealtimeNs - scheduledElapsedRealtimeNs
}

data class DualPhoneCaptureStopRequest(
    val dualCaptureId: String,
    val role: DualPhoneRole,
    val commandId: String,
    val commandCreatedMasterElapsedRealtimeNs: Long?,
    val commandReceivedLocalElapsedRealtimeNs: Long,
    val postRollMs: Long = DUAL_PHONE_DEFAULT_POST_ROLL_MS,
)

data class DualPhoneCaptureStopResult(
    val captured: Boolean,
    val videoPath: String?,
    val manifestPath: String,
    val durationNs: Long,
    val fileSizeBytes: Long,
    val scheduledElapsedRealtimeNs: Long?,
    val startCallElapsedRealtimeNs: Long?,
    val cameraXStartElapsedRealtimeNs: Long?,
    val finalizeElapsedRealtimeNs: Long?,
    val captureWindowStartMarkerElapsedRealtimeNs: Long? = null,
    val captureWindowStopMarkerElapsedRealtimeNs: Long? = null,
    val captureEventsPath: String? = null,
    val clockSyncHistoryPath: String? = null,
)

interface DualPhoneCaptureEndpoint {
    suspend fun arm(
        request: DualPhoneCaptureArmRequest,
    ): DualPhoneCaptureArmResult

    suspend fun start(
        request: DualPhoneCaptureStartRequest,
    ): DualPhoneCaptureStartResult

    suspend fun markStop(request: DualPhoneCaptureStopRequest) = Unit

    suspend fun stop(): DualPhoneCaptureStopResult

    fun recordClockSync(snapshot: DualPhoneClockSyncSnapshot) = Unit

    suspend fun abort(reason: String)
}

object DualPhoneCaptureRuntime {
    @Volatile
    private var endpoint: DualPhoneCaptureEndpoint? = null

    fun register(value: DualPhoneCaptureEndpoint) {
        endpoint = value
    }

    fun unregister(value: DualPhoneCaptureEndpoint) {
        if (endpoint === value) {
            endpoint = null
        }
    }

    fun current(): DualPhoneCaptureEndpoint? = endpoint

    fun requireEndpoint(): DualPhoneCaptureEndpoint =
        endpoint ?: throw IllegalStateException(
            "Phone recorder endpoint is unavailable",
        )
}
