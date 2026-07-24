package com.maklertour.data.phonecamera

import kotlin.math.PI
import kotlin.math.atan2
import kotlin.math.ceil
import kotlin.math.floor
import kotlin.math.hypot
import kotlin.math.min

enum class AutoPhotoMovementStatus(val wireValue: String) {
    DISABLED("disabled"),
    NO_REFERENCE("no_reference"),
    INSUFFICIENT_FEATURES("insufficient_features"),
    TRACKING_FAILED("tracking_failed"),
    OK("ok"),
}

data class AutoPhotoMovementFrame(
    val width: Int,
    val height: Int,
    val luma: ByteArray,
    val timestampNs: Long,
) {
    init {
        require(width > 0 && height > 0) { "movement frame dimensions must be positive" }
        require(luma.size == width * height) {
            "movement frame luma size=${luma.size} expected=${width * height}"
        }
    }

    fun immutableCopy(): AutoPhotoMovementFrame = copy(luma = luma.copyOf())
}

data class AutoPhotoTrackedPoint(
    val previousX: Double,
    val previousY: Double,
    val currentX: Double,
    val currentY: Double,
    val valid: Boolean = true,
)

data class AutoPhotoFlowMeasurement(
    val method: String,
    val available: Boolean = true,
    val detectedFeatures: Int = 0,
    val tracks: List<AutoPhotoTrackedPoint> = emptyList(),
    val detail: String? = null,
)

fun interface AutoPhotoFlowEngine {
    fun track(
        reference: AutoPhotoMovementFrame,
        current: AutoPhotoMovementFrame,
        maxFeatures: Int,
    ): AutoPhotoFlowMeasurement
}

data class AutoPhotoMovementResult(
    val status: AutoPhotoMovementStatus,
    val method: String,
    val referenceSequence: Int?,
    val analysisTimestampNs: Long,
    val analysisWidth: Int,
    val analysisHeight: Int,
    val detectedFeatures: Int,
    val trackedFeatures: Int,
    val trackedRatio: Double?,
    val medianDisplacementPx: Double?,
    val p90DisplacementPx: Double?,
    val estimatedRotationDeg: Double?,
    val medianFlowDxPx: Double? = null,
    val medianFlowDyPx: Double? = null,
    val detail: String? = null,
) {
    fun toMetadataMap(): Map<String, Any?> = linkedMapOf(
        "status" to status.wireValue,
        "method" to method,
        "reference_sequence" to referenceSequence,
        "analysis_timestamp_ns" to analysisTimestampNs,
        "analysis_width" to analysisWidth,
        "analysis_height" to analysisHeight,
        "detected_features" to detectedFeatures,
        "tracked_features" to trackedFeatures,
        "tracked_ratio" to trackedRatio.finiteOrNull(),
        "median_displacement_px" to medianDisplacementPx.finiteOrNull(),
        "p90_displacement_px" to p90DisplacementPx.finiteOrNull(),
        "estimated_rotation_deg" to estimatedRotationDeg.finiteOrNull(),
        "median_flow_dx_px" to medianFlowDxPx.finiteOrNull(),
        "median_flow_dy_px" to medianFlowDyPx.finiteOrNull(),
        "detail" to detail,
    )

    companion object {
        fun failure(
            timestampNs: Long,
            width: Int,
            height: Int,
            method: String,
            detail: String,
        ): AutoPhotoMovementResult = AutoPhotoMovementResult(
            status = AutoPhotoMovementStatus.TRACKING_FAILED,
            method = method,
            referenceSequence = null,
            analysisTimestampNs = timestampNs,
            analysisWidth = width.coerceAtLeast(0),
            analysisHeight = height.coerceAtLeast(0),
            detectedFeatures = 0,
            trackedFeatures = 0,
            trackedRatio = null,
            medianDisplacementPx = null,
            p90DisplacementPx = null,
            estimatedRotationDeg = null,
            medianFlowDxPx = null,
            medianFlowDyPx = null,
            detail = detail,
        )
    }
}

data class AutoPhotoMovementAnalysis(
    val result: AutoPhotoMovementResult,
    internal val frame: AutoPhotoMovementFrame?,
)

class AutoPhotoMovementTracker(
    private val flowEngine: AutoPhotoFlowEngine,
    private val methodName: String = "pyr_lk",
    private val maxFeatures: Int = 120,
    private val minDetectedFeatures: Int = 12,
    private val minTrackedFeatures: Int = 8,
) {
    private var referenceFrame: AutoPhotoMovementFrame? = null
    private var referenceSequence: Int? = null

    init {
        require(maxFeatures > 0) { "maxFeatures must be positive" }
        require(minDetectedFeatures > 0) { "minDetectedFeatures must be positive" }
        require(minTrackedFeatures > 0) { "minTrackedFeatures must be positive" }
        require(minDetectedFeatures <= maxFeatures) {
            "minDetectedFeatures cannot exceed maxFeatures"
        }
    }

    @Synchronized
    fun analyze(
        frame: AutoPhotoMovementFrame,
        enabled: Boolean = true,
    ): AutoPhotoMovementAnalysis {
        val reference = referenceFrame
        val sequence = referenceSequence

        if (!enabled) {
            return analysis(
                frame = frame,
                status = AutoPhotoMovementStatus.DISABLED,
                method = methodName,
                referenceSequence = sequence,
                detail = "visual movement metrics disabled",
            )
        }

        if (reference == null || sequence == null) {
            return analysis(
                frame = frame,
                status = AutoPhotoMovementStatus.NO_REFERENCE,
                method = methodName,
                referenceSequence = null,
                detail = "waiting for first successfully saved photo",
            )
        }

        if (
            reference.width != frame.width
            || reference.height != frame.height
        ) {
            return analysis(
                frame = frame,
                status = AutoPhotoMovementStatus.TRACKING_FAILED,
                method = methodName,
                referenceSequence = sequence,
                detail = "analysis dimensions changed",
            )
        }

        val measurement = try {
            flowEngine.track(reference, frame, maxFeatures)
        } catch (t: Throwable) {
            return analysis(
                frame = frame,
                status = AutoPhotoMovementStatus.TRACKING_FAILED,
                method = methodName,
                referenceSequence = sequence,
                detail = "flow engine failure: ${t.javaClass.simpleName}",
            )
        }

        if (!measurement.available) {
            return analysis(
                frame = frame,
                status = AutoPhotoMovementStatus.DISABLED,
                method = measurement.method.ifBlank { methodName },
                referenceSequence = sequence,
                detail = measurement.detail ?: "flow engine unavailable",
            )
        }

        val detected = measurement.detectedFeatures
            .coerceAtLeast(0)
            .coerceAtMost(maxFeatures)

        if (detected < minDetectedFeatures) {
            return analysis(
                frame = frame,
                status = AutoPhotoMovementStatus.INSUFFICIENT_FEATURES,
                method = measurement.method.ifBlank { methodName },
                referenceSequence = sequence,
                detectedFeatures = detected,
                detail = measurement.detail ?: "not enough reference features",
            )
        }

        val validTracks = measurement.tracks
            .asSequence()
            .take(maxFeatures)
            .filter { track ->
                track.valid
                    && track.previousX.isFinite()
                    && track.previousY.isFinite()
                    && track.currentX.isFinite()
                    && track.currentY.isFinite()
                    && track.previousX >= 0.0
                    && track.previousY >= 0.0
                    && track.currentX >= 0.0
                    && track.currentY >= 0.0
                    && track.previousX < frame.width
                    && track.currentX < frame.width
                    && track.previousY < frame.height
                    && track.currentY < frame.height
            }
            .toList()

        if (validTracks.size < minTrackedFeatures) {
            return analysis(
                frame = frame,
                status = AutoPhotoMovementStatus.TRACKING_FAILED,
                method = measurement.method.ifBlank { methodName },
                referenceSequence = sequence,
                detectedFeatures = detected,
                trackedFeatures = validTracks.size,
                trackedRatio = validTracks.size.toDouble() / detected.toDouble(),
                detail = measurement.detail ?: "not enough valid tracks",
            )
        }

        val displacements = validTracks
            .map { track ->
                hypot(
                    track.currentX - track.previousX,
                    track.currentY - track.previousY,
                )
            }
            .sorted()

        val flowDx = validTracks
            .map { it.currentX - it.previousX }
            .sorted()
        val flowDy = validTracks
            .map { it.currentY - it.previousY }
            .sorted()
        val rotation = estimateRotationDegrees(
            tracks = validTracks,
            width = frame.width,
            height = frame.height,
        )

        return analysis(
            frame = frame,
            status = AutoPhotoMovementStatus.OK,
            method = measurement.method.ifBlank { methodName },
            referenceSequence = sequence,
            detectedFeatures = detected,
            trackedFeatures = validTracks.size,
            trackedRatio = validTracks.size.toDouble() / detected.toDouble(),
            medianDisplacementPx = percentile(displacements, 0.50),
            p90DisplacementPx = percentile(displacements, 0.90),
            estimatedRotationDeg = rotation,
            medianFlowDxPx = percentile(flowDx, 0.50),
            medianFlowDyPx = percentile(flowDy, 0.50),
            detail = measurement.detail,
        )
    }

    @Synchronized
    fun commit(
        analysis: AutoPhotoMovementAnalysis,
        savedSequence: Int,
    ): Boolean {
        val frame = analysis.frame ?: return false
        val existing = referenceSequence
        if (savedSequence <= 0 || (existing != null && savedSequence <= existing)) {
            return false
        }

        referenceFrame = frame.immutableCopy()
        referenceSequence = savedSequence
        return true
    }

    @Synchronized
    fun reset() {
        referenceFrame = null
        referenceSequence = null
    }

    @Synchronized
    fun currentReferenceSequence(): Int? = referenceSequence

    private fun analysis(
        frame: AutoPhotoMovementFrame,
        status: AutoPhotoMovementStatus,
        method: String,
        referenceSequence: Int?,
        detectedFeatures: Int = 0,
        trackedFeatures: Int = 0,
        trackedRatio: Double? = null,
        medianDisplacementPx: Double? = null,
        p90DisplacementPx: Double? = null,
        estimatedRotationDeg: Double? = null,
        medianFlowDxPx: Double? = null,
        medianFlowDyPx: Double? = null,
        detail: String? = null,
    ): AutoPhotoMovementAnalysis = AutoPhotoMovementAnalysis(
        result = AutoPhotoMovementResult(
            status = status,
            method = method,
            referenceSequence = referenceSequence,
            analysisTimestampNs = frame.timestampNs,
            analysisWidth = frame.width,
            analysisHeight = frame.height,
            detectedFeatures = detectedFeatures,
            trackedFeatures = trackedFeatures,
            trackedRatio = trackedRatio.finiteOrNull(),
            medianDisplacementPx = medianDisplacementPx.finiteOrNull(),
            p90DisplacementPx = p90DisplacementPx.finiteOrNull(),
            estimatedRotationDeg = estimatedRotationDeg.finiteOrNull(),
            medianFlowDxPx = medianFlowDxPx.finiteOrNull(),
            medianFlowDyPx = medianFlowDyPx.finiteOrNull(),
            detail = detail,
        ),
        frame = frame,
    )

    private fun estimateRotationDegrees(
        tracks: List<AutoPhotoTrackedPoint>,
        width: Int,
        height: Int,
    ): Double? {
        val centerX = width / 2.0
        val centerY = height / 2.0
        val deltas = tracks.mapNotNull { track ->
            val previousDx = track.previousX - centerX
            val previousDy = track.previousY - centerY
            val currentDx = track.currentX - centerX
            val currentDy = track.currentY - centerY
            val previousRadius = hypot(previousDx, previousDy)
            val currentRadius = hypot(currentDx, currentDy)
            if (min(previousRadius, currentRadius) < 5.0) {
                null
            } else {
                val previousAngle = atan2(previousDy, previousDx)
                val currentAngle = atan2(currentDy, currentDx)
                normalizeRadians(currentAngle - previousAngle) * 180.0 / PI
            }
        }.sorted()

        return if (deltas.isEmpty()) null else percentile(deltas, 0.50)
    }

    private fun normalizeRadians(value: Double): Double {
        var result = value
        while (result > PI) result -= 2.0 * PI
        while (result < -PI) result += 2.0 * PI
        return result
    }

    private fun percentile(sorted: List<Double>, fraction: Double): Double? {
        if (sorted.isEmpty()) return null
        if (sorted.size == 1) return sorted.first()
        val position = (sorted.size - 1) * fraction.coerceIn(0.0, 1.0)
        val lower = floor(position).toInt()
        val upper = ceil(position).toInt()
        if (lower == upper) return sorted[lower]
        val weight = position - lower
        return sorted[lower] * (1.0 - weight) + sorted[upper] * weight
    }
}

private fun Double?.finiteOrNull(): Double? =
    this?.takeIf { it.isFinite() }
