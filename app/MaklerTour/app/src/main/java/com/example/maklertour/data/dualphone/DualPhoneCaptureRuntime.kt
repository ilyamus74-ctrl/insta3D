package com.maklertour.data.dualphone

data class DualPhoneCaptureArmRequest(
    val dualCaptureId: String,
    val role: DualPhoneRole,
    val deviceId: String,
    val peerDeviceId: String?,
    val preferredVideoModeId: String?,
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
)

data class DualPhoneCaptureStartRequest(
    val dualCaptureId: String,
    val role: DualPhoneRole,
    val scheduledElapsedRealtimeNs: Long,
    val clockOffsetNs: Long?,
    val clockUncertaintyNs: Long?,
    val clockDriftPpm: Double?,
)

data class DualPhoneCaptureStartResult(
    val videoPath: String,
    val scheduledElapsedRealtimeNs: Long,
    val startCallElapsedRealtimeNs: Long,
    val cameraXStartElapsedRealtimeNs: Long?,
) {
    val startLatenessNs: Long
        get() = startCallElapsedRealtimeNs - scheduledElapsedRealtimeNs
}

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
)

interface DualPhoneCaptureEndpoint {
    suspend fun arm(
        request: DualPhoneCaptureArmRequest,
    ): DualPhoneCaptureArmResult

    suspend fun start(
        request: DualPhoneCaptureStartRequest,
    ): DualPhoneCaptureStartResult

    suspend fun stop(): DualPhoneCaptureStopResult

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
