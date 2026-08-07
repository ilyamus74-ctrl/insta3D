package com.example.maklertour.data.dualphone

/**
 * Versioned metadata envelope for a future reduced-frame payload.
 *
 * LM01A-1 deliberately defines ownership and validation only. Socket transport and
 * encoded bytes are added in a later slice without changing this raw-orientation
 * contract.
 */
data class DualPhoneLiveStreamFrame(
    val schemaVersion: Int = SCHEMA_VERSION,
    val streamId: String,
    val dualCaptureId: String,
    val sessionUuid: String,
    val role: String,
    val frameSequence: Long,
    val sensorTimestampNs: Long,
    val captureElapsedRealtimeNs: Long,
    val timestampSource: String,
    val clockModelRevision: Long,
    val width: Int,
    val height: Int,
    val rotationAppliedDegrees: Int = 0,
    val imageProxyRotationDegrees: Int,
    val encoding: DualPhoneLiveStreamEncoding = DualPhoneLiveStreamEncoding.JPEG,
    val payloadSizeBytes: Int,
    val payloadCrc32: Long,
) {
    init {
        require(schemaVersion == SCHEMA_VERSION) { "Unsupported stream schema: $schemaVersion" }
        require(streamId.isNotBlank()) { "streamId is required" }
        require(dualCaptureId.isNotBlank()) { "dualCaptureId is required" }
        require(sessionUuid.isNotBlank()) { "sessionUuid is required" }
        require(role.isNotBlank()) { "role is required" }
        require(frameSequence >= 0L) { "frameSequence must be non-negative" }
        require(sensorTimestampNs >= 0L) { "sensorTimestampNs must be non-negative" }
        require(captureElapsedRealtimeNs >= 0L) {
            "captureElapsedRealtimeNs must be non-negative"
        }
        require(timestampSource.isNotBlank()) { "timestampSource is required" }
        require(clockModelRevision >= 0L) { "clockModelRevision must be non-negative" }
        require(width in 1..MAX_WIDTH) { "width must be in 1..$MAX_WIDTH" }
        require(height in 1..MAX_HEIGHT) { "height must be in 1..$MAX_HEIGHT" }
        require(rotationAppliedDegrees == 0) {
            "Reduced stream pixels must remain in raw orientation"
        }
        require(imageProxyRotationDegrees in VALID_ROTATIONS) {
            "imageProxyRotationDegrees must be 0, 90, 180 or 270"
        }
        require(encoding == DualPhoneLiveStreamEncoding.JPEG) {
            "LM01A supports JPEG only"
        }
        require(payloadSizeBytes in 0..MAX_PAYLOAD_BYTES) {
            "payloadSizeBytes exceeds $MAX_PAYLOAD_BYTES"
        }
        require(payloadCrc32 in 0L..CRC32_MAX) { "payloadCrc32 must be unsigned CRC32" }
    }

    companion object {
        const val SCHEMA_VERSION: Int = 1
        const val MAX_WIDTH: Int = 1920
        const val MAX_HEIGHT: Int = 1080
        const val MAX_PAYLOAD_BYTES: Int = 2 * 1024 * 1024
        private const val CRC32_MAX: Long = 0xffff_ffffL
        private val VALID_ROTATIONS: Set<Int> = setOf(0, 90, 180, 270)
    }
}
