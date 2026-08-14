package com.maklertour.data.tof

import com.maklertour.data.calibration.DualPhoneLiveIntrinsicsEstimate
import org.json.JSONObject
import kotlin.math.abs
import kotlin.math.ceil
import kotlin.math.hypot
import kotlin.math.max
import kotlin.math.min
import kotlin.math.sqrt

data class TofCameraHoldoutValidationResult(
    val successful: Boolean,
    val profileSolver: String,
    val sampleCount: Int,
    val totalObservationCount: Int,
    val retainedObservationCount: Int,
    val retainedZoneCoverageCount: Int,
    val retainedZoneCoveragePercent: Double,
    val planeRmsMm: Double?,
    val planeP95Mm: Double?,
    val allPlaneRmsMm: Double?,
    val reprojectionObservationCount: Int,
    val reprojectionRmsPx: Double?,
    val reprojectionP95Px: Double?,
    val status: String,
) {
    fun toJson(): JSONObject = JSONObject()
        .put("schema_version", SCHEMA_VERSION)
        .put("successful", successful)
        .put("profile_solver", profileSolver)
        .put("sample_count", sampleCount)
        .put("total_observation_count", totalObservationCount)
        .put("retained_observation_count", retainedObservationCount)
        .put("retained_zone_coverage_count", retainedZoneCoverageCount)
        .put("retained_zone_coverage_percent", retainedZoneCoveragePercent)
        .put("plane_rms_mm", planeRmsMm ?: JSONObject.NULL)
        .put("plane_p95_mm", planeP95Mm ?: JSONObject.NULL)
        .put("all_plane_rms_mm", allPlaneRmsMm ?: JSONObject.NULL)
        .put("reprojection_observation_count", reprojectionObservationCount)
        .put("reprojection_rms_px", reprojectionRmsPx ?: JSONObject.NULL)
        .put("reprojection_p95_px", reprojectionP95Px ?: JSONObject.NULL)
        .put("status", status)

    companion object {
        private const val SCHEMA_VERSION = 1

        fun fromJson(json: JSONObject): TofCameraHoldoutValidationResult? {
            if (json.optInt("schema_version", -1) != SCHEMA_VERSION) return null
            return TofCameraHoldoutValidationResult(
                successful = json.optBoolean("successful", false),
                profileSolver = json.optString("profile_solver"),
                sampleCount = json.optInt("sample_count", 0),
                totalObservationCount = json.optInt("total_observation_count", 0),
                retainedObservationCount = json.optInt("retained_observation_count", 0),
                retainedZoneCoverageCount =
                    json.optInt("retained_zone_coverage_count", 0),
                retainedZoneCoveragePercent =
                    json.optDouble("retained_zone_coverage_percent", 0.0),
                planeRmsMm = json.optFiniteDoubleOrNull("plane_rms_mm"),
                planeP95Mm = json.optFiniteDoubleOrNull("plane_p95_mm"),
                allPlaneRmsMm = json.optFiniteDoubleOrNull("all_plane_rms_mm"),
                reprojectionObservationCount =
                    json.optInt("reprojection_observation_count", 0),
                reprojectionRmsPx =
                    json.optFiniteDoubleOrNull("reprojection_rms_px"),
                reprojectionP95Px =
                    json.optFiniteDoubleOrNull("reprojection_p95_px"),
                status = json.optString("status"),
            )
        }
    }
}

/**
 * LM03.4C C1 validator.
 *
 * The profile is read-only: this class never refits ToF intrinsics or R/t.
 */
class TofCameraHoldoutValidator {
    fun validate(
        profile: TofCameraExtrinsicsProfile,
        cameraIntrinsics: DualPhoneLiveIntrinsicsEstimate,
        samples: List<TofCameraPlanarCalibrationSample>,
    ): TofCameraHoldoutValidationResult {
        if (!profile.solved) {
            return failure(profile, "Active ToF profile is not solved")
        }
        if (!cameraIntrinsics.acceptable) {
            return failure(profile, "CAMERA_A intrinsics are not acceptable")
        }

        val compatible = samples.filter { sample ->
            sample.structurallyValid &&
                sample.tofSlot == profile.tofSlot &&
                sample.tofWidth == profile.tofWidth &&
                sample.tofHeight == profile.tofHeight
        }
        if (compatible.size < MIN_HOLDOUT_SAMPLES) {
            return failure(
                profile,
                "Need at least $MIN_HOLDOUT_SAMPLES hold-out samples; got ${compatible.size}",
                sampleCount = compatible.size,
            )
        }

        val bySample = compatible.map { sample ->
            sample.zones
                .asSequence()
                .filter {
                    it.distanceMm >=
                        TofCameraPlanarCalibrationSampleBuilder.MIN_CALIBRATION_RANGE_MM
                }
                .mapNotNull { zone -> evaluate(profile, cameraIntrinsics, sample, zone) }
                .sortedBy { it.planeAbsMm }
                .toList()
        }
        val all = bySample.flatten()
        if (all.size < MIN_RETAINED_OBSERVATIONS) {
            return failure(
                profile,
                "Need at least $MIN_RETAINED_OBSERVATIONS usable hold-out observations; " +
                    "got ${all.size}",
                sampleCount = compatible.size,
                totalObservationCount = all.size,
            )
        }

        val retained = buildList {
            bySample.forEach { observations ->
                if (observations.isEmpty()) return@forEach
                val keep = min(
                    observations.size,
                    max(
                        MIN_RETAINED_PER_SAMPLE.coerceAtMost(observations.size),
                        ceil(observations.size * RETAIN_FRACTION).toInt(),
                    ),
                )
                addAll(observations.take(keep))
            }
        }
        val planeValues = retained.map { it.planeAbsMm }
        val allPlaneValues = all.map { it.planeAbsMm }
        val reprojectionValues = retained.mapNotNull { it.reprojectionPx }
        val coverageCount = retained.map { it.zoneIndex }.toSet().size
        val zoneCount = profile.tofWidth * profile.tofHeight
        val coveragePercent =
            if (zoneCount > 0) coverageCount * 100.0 / zoneCount else 0.0
        val planeRms = rms(planeValues)
        val planeP95 = percentile(planeValues, 0.95)
        val allPlaneRms = rms(allPlaneValues)
        val reprojectionRms = rms(reprojectionValues)
        val reprojectionP95 = percentile(reprojectionValues, 0.95)

        val accepted =
            retained.size >= MIN_RETAINED_OBSERVATIONS &&
                coveragePercent >= MIN_ZONE_COVERAGE_PERCENT &&
                (planeRms ?: Double.POSITIVE_INFINITY) <= MAX_PLANE_RMS_MM &&
                (planeP95 ?: Double.POSITIVE_INFINITY) <= MAX_PLANE_P95_MM

        val status = if (accepted) {
            "HOLDOUT_PASS_C1; RGB reprojection telemetry only"
        } else {
            "HOLDOUT_FAIL_C1: samples=${compatible.size} " +
                "retained=${retained.size} coveragePercent=$coveragePercent " +
                "rmsMm=$planeRms p95Mm=$planeP95"
        }

        return TofCameraHoldoutValidationResult(
            successful = accepted,
            profileSolver = profile.solver,
            sampleCount = compatible.size,
            totalObservationCount = all.size,
            retainedObservationCount = retained.size,
            retainedZoneCoverageCount = coverageCount,
            retainedZoneCoveragePercent = coveragePercent,
            planeRmsMm = planeRms,
            planeP95Mm = planeP95,
            allPlaneRmsMm = allPlaneRms,
            reprojectionObservationCount = reprojectionValues.size,
            reprojectionRmsPx = reprojectionRms,
            reprojectionP95Px = reprojectionP95,
            status = status,
        )
    }

    private fun evaluate(
        profile: TofCameraExtrinsicsProfile,
        cameraIntrinsics: DualPhoneLiveIntrinsicsEstimate,
        sample: TofCameraPlanarCalibrationSample,
        zone: TofZoneRangeObservation,
    ): Observation? {
        val row = zone.zoneIndex / profile.tofWidth
        val column = zone.zoneIndex % profile.tofWidth
        val zi = profile.tofIntrinsics
        val qx = (column.toDouble() - zi.cxZones) / zi.fxZones
        val qy = (row.toDouble() - zi.cyZones) / zi.fyZones
        val qz = 1.0

        val r = profile.rotationToCamera
        val t = profile.translationToCameraMm
        val vx = r[0] * qx + r[1] * qy + r[2] * qz
        val vy = r[3] * qx + r[4] * qy + r[5] * qz
        val vz = r[6] * qx + r[7] * qy + r[8] * qz

        val measuredZ = zone.distanceMm.toDouble()
        val measuredX = measuredZ * vx + t[0]
        val measuredY = measuredZ * vy + t[1]
        val measuredCameraZ = measuredZ * vz + t[2]
        if (
            !measuredX.isFinite() ||
            !measuredY.isFinite() ||
            !measuredCameraZ.isFinite() ||
            measuredCameraZ <= 0.0
        ) return null

        val plane = sample.boardPlane
        val signed = plane.signedDistanceMm(
            cameraXmm = measuredX,
            cameraYmm = measuredY,
            cameraZmm = measuredCameraZ,
        )
        if (!signed.isFinite()) return null

        val denominator =
            plane.normalX * vx +
                plane.normalY * vy +
                plane.normalZ * vz
        val numerator = -(
            plane.normalX * t[0] +
                plane.normalY * t[1] +
                plane.normalZ * t[2] +
                plane.dMm
            )
        val expectedAxialZ =
            if (abs(denominator) > MIN_PLANE_DENOMINATOR) {
                numerator / denominator
            } else {
                Double.NaN
            }

        val reprojection = if (expectedAxialZ.isFinite() && expectedAxialZ > 0.0) {
            val expectedX = expectedAxialZ * vx + t[0]
            val expectedY = expectedAxialZ * vy + t[1]
            val expectedCameraZ = expectedAxialZ * vz + t[2]
            val measuredPixel = projectCameraPoint(
                cameraIntrinsics,
                measuredX,
                measuredY,
                measuredCameraZ,
            )
            val expectedPixel = projectCameraPoint(
                cameraIntrinsics,
                expectedX,
                expectedY,
                expectedCameraZ,
            )
            if (measuredPixel != null && expectedPixel != null) {
                hypot(
                    measuredPixel.first - expectedPixel.first,
                    measuredPixel.second - expectedPixel.second,
                )
            } else {
                null
            }
        } else {
            null
        }

        return Observation(
            zoneIndex = zone.zoneIndex,
            planeAbsMm = abs(signed),
            reprojectionPx = reprojection?.takeIf { it.isFinite() },
        )
    }

    private fun projectCameraPoint(
        intrinsics: DualPhoneLiveIntrinsicsEstimate,
        x: Double,
        y: Double,
        z: Double,
    ): Pair<Double, Double>? {
        if (!z.isFinite() || z <= 0.0) return null
        val fx = intrinsics.fx ?: return null
        val fy = intrinsics.fy ?: return null
        val cx = intrinsics.cx ?: return null
        val cy = intrinsics.cy ?: return null
        val k1 = intrinsics.k1 ?: return null
        val k2 = intrinsics.k2 ?: return null
        val nx = x / z
        val ny = y / z
        val r2 = nx * nx + ny * ny
        val radial = 1.0 + k1 * r2 + k2 * r2 * r2
        val u = fx * nx * radial + cx
        val v = fy * ny * radial + cy
        if (!u.isFinite() || !v.isFinite()) return null
        return u to v
    }

    private fun failure(
        profile: TofCameraExtrinsicsProfile,
        status: String,
        sampleCount: Int = 0,
        totalObservationCount: Int = 0,
    ): TofCameraHoldoutValidationResult =
        TofCameraHoldoutValidationResult(
            successful = false,
            profileSolver = profile.solver,
            sampleCount = sampleCount,
            totalObservationCount = totalObservationCount,
            retainedObservationCount = 0,
            retainedZoneCoverageCount = 0,
            retainedZoneCoveragePercent = 0.0,
            planeRmsMm = null,
            planeP95Mm = null,
            allPlaneRmsMm = null,
            reprojectionObservationCount = 0,
            reprojectionRmsPx = null,
            reprojectionP95Px = null,
            status = status,
        )

    private fun rms(values: List<Double>): Double? {
        if (values.isEmpty()) return null
        return sqrt(values.sumOf { it * it } / values.size.toDouble())
    }

    private fun percentile(values: List<Double>, fraction: Double): Double? {
        if (values.isEmpty()) return null
        val sorted = values.sorted()
        val index =
            ((sorted.size - 1) * fraction)
                .toInt()
                .coerceIn(0, sorted.lastIndex)
        return sorted[index]
    }

    private data class Observation(
        val zoneIndex: Int,
        val planeAbsMm: Double,
        val reprojectionPx: Double?,
    )

    companion object {
        const val MIN_HOLDOUT_SAMPLES = 8
        const val MIN_RETAINED_OBSERVATIONS = 128
        const val MIN_ZONE_COVERAGE_PERCENT = 60.0
        const val MAX_PLANE_RMS_MM = 20.0
        const val MAX_PLANE_P95_MM = 40.0

        private const val RETAIN_FRACTION = 0.70
        private const val MIN_RETAINED_PER_SAMPLE = 8
        private const val MIN_PLANE_DENOMINATOR = 1e-9
    }
}

private fun JSONObject.optFiniteDoubleOrNull(name: String): Double? =
    if (!has(name) || isNull(name)) {
        null
    } else {
        optDouble(name, Double.NaN).takeIf { it.isFinite() }
    }
