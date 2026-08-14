package com.maklertour.data.tof

import org.json.JSONArray
import org.json.JSONObject
import kotlin.math.PI
import kotlin.math.abs
import kotlin.math.ceil
import kotlin.math.cos
import kotlin.math.exp
import kotlin.math.ln
import kotlin.math.max
import kotlin.math.min
import kotlin.math.sin
import kotlin.math.sqrt
import kotlin.math.tan

data class TofCameraExtrinsicsSolveResult(
    val successful: Boolean,
    val tofSlot: Int,
    val tofWidth: Int,
    val tofHeight: Int,
    val tofIntrinsics: TofZoneIntrinsics?,
    val rotationToCamera: List<Double>,
    val translationToCameraMm: List<Double>,
    val sampleCount: Int,
    val totalObservationCount: Int,
    val retainedObservationCount: Int,
    val planeRmsMm: Double?,
    val planeP95Mm: Double?,
    val allPlaneRmsMm: Double?,
    val initialCost: Double?,
    val finalCost: Double?,
    val iterations: Int,
    val solver: String,
    val status: String,
) {
    fun toJson(): JSONObject = JSONObject()
        .put("schema_version", SCHEMA_VERSION)
        .put("successful", successful)
        .put("tof_slot", tofSlot)
        .put("tof_width", tofWidth)
        .put("tof_height", tofHeight)
        .put("tof_intrinsics", tofIntrinsics?.toJson() ?: JSONObject.NULL)
        .put("rotation_tof_to_camera", rotationToCamera.toJsonArray())
        .put("translation_tof_to_camera_mm", translationToCameraMm.toJsonArray())
        .put("sample_count", sampleCount)
        .put("total_observation_count", totalObservationCount)
        .put("retained_observation_count", retainedObservationCount)
        .put("plane_rms_mm", planeRmsMm ?: JSONObject.NULL)
        .put("plane_p95_mm", planeP95Mm ?: JSONObject.NULL)
        .put("all_plane_rms_mm", allPlaneRmsMm ?: JSONObject.NULL)
        .put("initial_cost", initialCost ?: JSONObject.NULL)
        .put("final_cost", finalCost ?: JSONObject.NULL)
        .put("iterations", iterations)
        .put("solver", solver)
        .put("status", status)

    fun toProfile(
        rigId: String,
        rigMountRevision: String,
        masterDeviceId: String,
        masterCameraId: String,
        cameraCalibrationProfileId: String,
        createdAtEpochMs: Long,
    ): TofCameraExtrinsicsProfile? {
        val intrinsics = tofIntrinsics ?: return null
        if (!successful) return null
        return TofCameraExtrinsicsProfile(
            rigId = rigId,
            rigMountRevision = rigMountRevision,
            masterDeviceId = masterDeviceId,
            masterCameraId = masterCameraId,
            cameraCalibrationProfileId = cameraCalibrationProfileId,
            tofSlot = tofSlot,
            tofWidth = tofWidth,
            tofHeight = tofHeight,
            tofIntrinsics = intrinsics,
            rotationToCamera = rotationToCamera,
            translationToCameraMm = translationToCameraMm,
            sampleCount = sampleCount,
            planeRmsMm = planeRmsMm,
            imageReprojectionRmsPx = null,
            solver = solver,
            createdAtEpochMs = createdAtEpochMs,
            status = TofCameraExtrinsicsProfile.STATUS_SOLVED,
        ).takeIf { it.structurallyValid }
    }

    companion object {
        private const val SCHEMA_VERSION = 1

        fun fromJson(json: JSONObject): TofCameraExtrinsicsSolveResult? {
            if (json.optInt("schema_version", -1) != SCHEMA_VERSION) return null
            val intrinsics =
                if (json.isNull("tof_intrinsics")) null
                else json.optJSONObject("tof_intrinsics")?.let(TofZoneIntrinsics::fromJson)
            return TofCameraExtrinsicsSolveResult(
                successful = json.optBoolean("successful", false),
                tofSlot = json.optInt("tof_slot", -1),
                tofWidth = json.optInt("tof_width", 0),
                tofHeight = json.optInt("tof_height", 0),
                tofIntrinsics = intrinsics,
                rotationToCamera =
                    json.optJSONArray("rotation_tof_to_camera").toFiniteDoubleList(),
                translationToCameraMm =
                    json.optJSONArray("translation_tof_to_camera_mm").toFiniteDoubleList(),
                sampleCount = json.optInt("sample_count", 0),
                totalObservationCount = json.optInt("total_observation_count", 0),
                retainedObservationCount = json.optInt("retained_observation_count", 0),
                planeRmsMm = json.optFiniteDoubleOrNull("plane_rms_mm"),
                planeP95Mm = json.optFiniteDoubleOrNull("plane_p95_mm"),
                allPlaneRmsMm = json.optFiniteDoubleOrNull("all_plane_rms_mm"),
                initialCost = json.optFiniteDoubleOrNull("initial_cost"),
                finalCost = json.optFiniteDoubleOrNull("final_cost"),
                iterations = json.optInt("iterations", 0),
                solver = json.optString("solver"),
                status = json.optString("status"),
            )
        }
    }
}

/**
 * LM03.4B2 nonlinear point-to-plane solver.
 *
 * Optimized parameters:
 *   log(fx_zones), log(fy_zones),
 *   Rodrigues(rx, ry, rz), tx_mm, ty_mm, tz_mm.
 *
 * The ToF principal point is fixed at the geometric zone-grid centre.
 *
 * The objective is robust and sigma-aware, but no final calibration acceptance
 * threshold is hard-coded here. LM03.4C decides acceptance from real-device
 * hold-out residuals.
 */
class TofCameraExtrinsicsSolver {
    fun solve(
        samples: List<TofCameraPlanarCalibrationSample>,
    ): TofCameraExtrinsicsSolveResult {
        val validSamples = samples.filter { it.structurallyValid }
        if (validSamples.size < MIN_SAMPLES) {
            return failure(
                status = "Need at least $MIN_SAMPLES valid planar samples; got ${validSamples.size}",
                sampleCount = validSamples.size,
            )
        }

        val first = validSamples.first()
        val slot = first.tofSlot
        val width = first.tofWidth
        val height = first.tofHeight
        val compatible = validSamples.filter {
            it.tofSlot == slot &&
                it.tofWidth == width &&
                it.tofHeight == height
        }
        if (compatible.size < MIN_SAMPLES) {
            return failure(
                status = "Not enough samples with one stable ToF geometry",
                sampleCount = compatible.size,
                tofSlot = slot,
                tofWidth = width,
                tofHeight = height,
            )
        }

        val observations = buildObservations(compatible)
        if (observations.size < MIN_OBSERVATIONS) {
            return failure(
                status = "Need at least $MIN_OBSERVATIONS valid ToF observations; got ${observations.size}",
                sampleCount = compatible.size,
                tofSlot = slot,
                tofWidth = width,
                tofHeight = height,
                totalObservationCount = observations.size,
            )
        }

        val nominalFx = nominalFocalZones(width)
        val nominalFy = nominalFocalZones(height)
        val nominalCx = (width - 1).toDouble() / 2.0
        val nominalCy = (height - 1).toDouble() / 2.0
        val nominal = Nominal(
            fx = nominalFx,
            fy = nominalFy,
            cx = nominalCx,
            cy = nominalCy,
        )

        var best: Fit? = null
        for (rollSeed in ROLL_SEEDS) {
            val fit = fit(
                observations = observations,
                width = width,
                height = height,
                nominal = nominal,
                rollSeed = rollSeed,
            )
            if (fit != null && (best == null || fit.finalCost < best.finalCost)) {
                best = fit
            }
        }

        val solved = best ?: return failure(
            status = "LM03.4B2 numerical solve did not produce a finite candidate",
            sampleCount = compatible.size,
            tofSlot = slot,
            tofWidth = width,
            tofHeight = height,
            totalObservationCount = observations.size,
        )

        val p = solved.params
        val intrinsics = TofZoneIntrinsics(
            fxZones = exp(p[IDX_LOG_FX]),
            fyZones = exp(p[IDX_LOG_FY]),
            cxZones = p[IDX_CX],
            cyZones = p[IDX_CY],
        )
        val rotation = rotationMatrix(
            p[IDX_RX],
            p[IDX_RY],
            p[IDX_RZ],
        ).toList()
        val translation = listOf(
            p[IDX_TX],
            p[IDX_TY],
            p[IDX_TZ],
        )

        val rawBySample = compatible.map { sample ->
            sample.zones
                .filter { zone ->
                    zone.distanceMm >=
                        TofCameraPlanarCalibrationSampleBuilder.MIN_CALIBRATION_RANGE_MM
                }
                .map { zone ->
                    abs(
                        rawResidualMm(
                            params = p,
                            sample = sample,
                            zone = zone,
                        ),
                    )
                }
                .sorted()
        }

        val retainedAbs = buildList {
            rawBySample.forEach { residuals ->
                if (residuals.isEmpty()) return@forEach
                val keep = min(
                    residuals.size,
                    max(
                        MIN_RETAINED_PER_SAMPLE.coerceAtMost(residuals.size),
                        ceil(residuals.size * REPORT_RETAIN_FRACTION).toInt(),
                    ),
                )
                addAll(residuals.take(keep))
            }
        }
        val allAbs = rawBySample.flatten()
        val retainedRms = rms(retainedAbs)
        val allRms = rms(allAbs)
        val p95 = percentile(retainedAbs, 0.95)

        val numericalFinite =
            intrinsics.structurallyValid &&
                rotation.size == 9 &&
                rotation.all { it.isFinite() } &&
                translation.all { it.isFinite() } &&
                retainedRms?.isFinite() == true &&
                solved.finalCost.isFinite() &&
                solved.finalCost <= solved.initialCost
        val centerOffset = max(
            abs(intrinsics.cxZones - nominal.cx),
            abs(intrinsics.cyZones - nominal.cy),
        )
        val fxRatio = intrinsics.fxZones / nominal.fx
        val fyRatio = intrinsics.fyZones / nominal.fy
        val focalAspect =
            max(intrinsics.fxZones, intrinsics.fyZones) /
                min(intrinsics.fxZones, intrinsics.fyZones)
        val translationNorm = sqrt(translation.sumOf { it * it })
        val physicallyPlausible =
            centerOffset <= MAX_ACCEPTED_CENTER_OFFSET_ZONES &&
                fxRatio in MIN_ACCEPTED_FOCAL_RATIO..MAX_ACCEPTED_FOCAL_RATIO &&
                fyRatio in MIN_ACCEPTED_FOCAL_RATIO..MAX_ACCEPTED_FOCAL_RATIO &&
                focalAspect <= MAX_ACCEPTED_FOCAL_ASPECT &&
                translationNorm <= MAX_ACCEPTED_TRANSLATION_NORM_MM &&
                (retainedRms ?: Double.POSITIVE_INFINITY) <= MAX_TRAINING_RMS_MM &&
                (p95 ?: Double.POSITIVE_INFINITY) <= MAX_TRAINING_P95_MM
        val accepted = numericalFinite && physicallyPlausible

        return TofCameraExtrinsicsSolveResult(
            successful = accepted,
            tofSlot = slot,
            tofWidth = width,
            tofHeight = height,
            tofIntrinsics = intrinsics,
            rotationToCamera = rotation,
            translationToCameraMm = translation,
            sampleCount = compatible.size,
            totalObservationCount = observations.size,
            retainedObservationCount = retainedAbs.size,
            planeRmsMm = retainedRms,
            planeP95Mm = p95,
            allPlaneRmsMm = allRms,
            initialCost = solved.initialCost,
            finalCost = solved.finalCost,
            iterations = solved.iterations,
            solver = SOLVER_NAME,
            status = if (accepted) {
                "SOLVED_B2_1; LM03.4C hold-out validation required"
            } else {
                "REJECTED_B2_1: numerical=$numericalFinite " +
                    "centerOffset=$centerOffset fxRatio=$fxRatio fyRatio=$fyRatio " +
                    "focalAspect=$focalAspect translationNormMm=$translationNorm " +
                    "rmsMm=$retainedRms p95Mm=$p95"
            },
        )
    }

    private fun fit(
        observations: List<Observation>,
        width: Int,
        height: Int,
        nominal: Nominal,
        rollSeed: Double,
    ): Fit? {
        var params = doubleArrayOf(
            ln(nominal.fx),
            ln(nominal.fy),
            nominal.cx,
            nominal.cy,
            0.0,
            0.0,
            rollSeed,
            0.0,
            0.0,
            0.0,
        )
        clamp(params, width, height)

        var residual = residualVector(params, observations, nominal)
        if (residual.any { !it.isFinite() }) return null
        var cost = meanSquare(residual)
        val initialCost = cost
        if (!cost.isFinite()) return null

        var lambda = 1e-3
        var iterations = 0

        repeat(MAX_ITERATIONS) {
            iterations = it + 1
            residual = residualVector(params, observations, nominal)
            val jacobianColumns = Array(PARAM_COUNT) { parameter ->
                val shifted = params.copyOf()
                shifted[parameter] += FINITE_DIFFERENCE_STEP[parameter]
                clamp(shifted, width, height)
                val shiftedResidual = residualVector(
                    shifted,
                    observations,
                    nominal,
                )
                DoubleArray(residual.size) { row ->
                    (shiftedResidual[row] - residual[row]) /
                        FINITE_DIFFERENCE_STEP[parameter]
                }
            }

            val normal = Array(PARAM_COUNT) { DoubleArray(PARAM_COUNT) }
            val gradient = DoubleArray(PARAM_COUNT)
            for (j in 0 until PARAM_COUNT) {
                val columnJ = jacobianColumns[j]
                var gj = 0.0
                for (row in residual.indices) {
                    gj += columnJ[row] * residual[row]
                }
                gradient[j] = gj
                for (k in j until PARAM_COUNT) {
                    val columnK = jacobianColumns[k]
                    var value = 0.0
                    for (row in residual.indices) {
                        value += columnJ[row] * columnK[row]
                    }
                    normal[j][k] = value
                    normal[k][j] = value
                }
            }

            for (j in 0 until PARAM_COUNT) {
                normal[j][j] += lambda * (normal[j][j] + 1.0)
            }

            val step = solveLinearSystem(
                matrix = normal,
                rhs = DoubleArray(PARAM_COUNT) { -gradient[it] },
            )
            if (step == null || step.any { !it.isFinite() }) {
                lambda = min(MAX_LAMBDA, lambda * 10.0)
                return@repeat
            }

            val candidate = params.copyOf()
            for (j in 0 until PARAM_COUNT) {
                candidate[j] += step[j]
            }
            clamp(candidate, width, height)

            val candidateResidual = residualVector(
                candidate,
                observations,
                nominal,
            )
            val candidateCost = meanSquare(candidateResidual)
            if (candidateCost.isFinite() && candidateCost < cost) {
                val improvement = cost - candidateCost
                params = candidate
                cost = candidateCost
                lambda = max(MIN_LAMBDA, lambda * 0.3)
                if (
                    improvement < COST_EPSILON ||
                    vectorNorm(step) < STEP_EPSILON
                ) {
                    return Fit(
                        params = params,
                        initialCost = initialCost,
                        finalCost = cost,
                        iterations = iterations,
                    )
                }
            } else {
                lambda = min(MAX_LAMBDA, lambda * 10.0)
            }
        }

        return Fit(
            params = params,
            initialCost = initialCost,
            finalCost = cost,
            iterations = iterations,
        )
    }

    private fun residualVector(
        params: DoubleArray,
        observations: List<Observation>,
        nominal: Nominal,
    ): DoubleArray {
        val result =
            DoubleArray(observations.size + PARAM_COUNT + EXTRA_PRIOR_COUNT)
        observations.forEachIndexed { index, observation ->
            val raw = rawResidualMm(
                params = params,
                sample = observation.sample,
                zone = observation.zone,
            )
            val sigma = max(MIN_SIGMA_MM, observation.zone.sigmaMm.toDouble())
            val normalized = raw / sigma
            val robustWeight =
                1.0 / sqrt(1.0 + square(normalized / CAUCHY_SCALE_SIGMA))
            result[index] = normalized * robustWeight
        }

        var cursor = observations.size
        result[cursor++] =
            (params[IDX_LOG_FX] - ln(nominal.fx)) / FOCAL_LOG_PRIOR_SIGMA
        result[cursor++] =
            (params[IDX_LOG_FY] - ln(nominal.fy)) / FOCAL_LOG_PRIOR_SIGMA
        result[cursor++] =
            (params[IDX_CX] - nominal.cx) / CENTER_PRIOR_SIGMA_ZONES
        result[cursor++] =
            (params[IDX_CY] - nominal.cy) / CENTER_PRIOR_SIGMA_ZONES
        result[cursor++] = params[IDX_RX] / 2.0
        result[cursor++] = params[IDX_RY] / 2.0
        result[cursor++] = params[IDX_RZ] / 2.0
        result[cursor++] = params[IDX_TX] / 500.0
        result[cursor++] = params[IDX_TY] / 500.0
        result[cursor++] = params[IDX_TZ] / 500.0
        result[cursor] =
            (params[IDX_LOG_FX] - params[IDX_LOG_FY]) /
                FOCAL_ISOTROPY_LOG_SIGMA

        return result
    }

    private fun rawResidualMm(
        params: DoubleArray,
        sample: TofCameraPlanarCalibrationSample,
        zone: TofZoneRangeObservation,
    ): Double {
        val fx = exp(params[IDX_LOG_FX])
        val fy = exp(params[IDX_LOG_FY])
        val cx = params[IDX_CX]
        val cy = params[IDX_CY]

        // Keep native zone-index order as the right-handed ToF convention.
        // Any physical sensor rotation is estimated by R.
        val row = zone.zoneIndex / sample.tofWidth
        val column = zone.zoneIndex % sample.tofWidth

        val normalizedX = (column.toDouble() - cx) / fx
        val normalizedY = (row.toDouble() - cy) / fy

        // VL53L8CX default firmware applies radial-to-perpendicular (R2P)
        // correction. distance_mm is therefore axial Z depth, not Euclidean
        // ray length. Do NOT normalize the ray before deprojection.
        val axialDepthMm = zone.distanceMm.toDouble()
        val tofX = axialDepthMm * normalizedX
        val tofY = axialDepthMm * normalizedY
        val tofZ = axialDepthMm

        val rotation = rotationMatrix(
            params[IDX_RX],
            params[IDX_RY],
            params[IDX_RZ],
        )
        val cameraX =
            rotation[0] * tofX +
                rotation[1] * tofY +
                rotation[2] * tofZ +
                params[IDX_TX]
        val cameraY =
            rotation[3] * tofX +
                rotation[4] * tofY +
                rotation[5] * tofZ +
                params[IDX_TY]
        val cameraZ =
            rotation[6] * tofX +
                rotation[7] * tofY +
                rotation[8] * tofZ +
                params[IDX_TZ]

        return sample.boardPlane.signedDistanceMm(
            cameraXmm = cameraX,
            cameraYmm = cameraY,
            cameraZmm = cameraZ,
        )
    }

    private fun buildObservations(
        samples: List<TofCameraPlanarCalibrationSample>,
    ): List<Observation> = buildList {
        samples.forEach { sample ->
            sample.zones.forEach { zone ->
                if (
                    zone.zoneIndex in 0 until (sample.tofWidth * sample.tofHeight) &&
                    zone.distanceMm >=
                        TofCameraPlanarCalibrationSampleBuilder.MIN_CALIBRATION_RANGE_MM
                ) {
                    add(Observation(sample, zone))
                }
            }
        }
    }

    private fun clamp(
        params: DoubleArray,
        width: Int,
        height: Int,
    ) {
        val nominalFx = nominalFocalZones(width)
        val nominalFy = nominalFocalZones(height)
        val nominalCx = (width - 1).toDouble() / 2.0
        val nominalCy = (height - 1).toDouble() / 2.0

        params[IDX_LOG_FX] = params[IDX_LOG_FX].coerceIn(
            ln(nominalFx * MIN_HARD_FOCAL_RATIO),
            ln(nominalFx * MAX_HARD_FOCAL_RATIO),
        )
        params[IDX_LOG_FY] = params[IDX_LOG_FY].coerceIn(
            ln(nominalFy * MIN_HARD_FOCAL_RATIO),
            ln(nominalFy * MAX_HARD_FOCAL_RATIO),
        )
        // Planar point-to-plane data cannot reliably separate principal-point
        // shifts from R/t. Keep the optical centre at the geometric zone-grid
        // centre and estimate only focal geometry plus rigid extrinsics.
        params[IDX_CX] = nominalCx
        params[IDX_CY] = nominalCy
        params[IDX_RX] = params[IDX_RX].coerceIn(-PI, PI)
        params[IDX_RY] = params[IDX_RY].coerceIn(-PI, PI)
        params[IDX_RZ] = params[IDX_RZ].coerceIn(-PI, PI)
        params[IDX_TX] = params[IDX_TX].coerceIn(-300.0, 300.0)
        params[IDX_TY] = params[IDX_TY].coerceIn(-300.0, 300.0)
        params[IDX_TZ] = params[IDX_TZ].coerceIn(-300.0, 300.0)
    }

    private fun rotationMatrix(
        rx: Double,
        ry: Double,
        rz: Double,
    ): DoubleArray {
        val theta = sqrt(rx * rx + ry * ry + rz * rz)
        if (theta < 1e-12) {
            return doubleArrayOf(
                1.0, 0.0, 0.0,
                0.0, 1.0, 0.0,
                0.0, 0.0, 1.0,
            )
        }
        val x = rx / theta
        val y = ry / theta
        val z = rz / theta
        val c = cos(theta)
        val s = sin(theta)
        val oneMinusC = 1.0 - c

        return doubleArrayOf(
            c + x * x * oneMinusC,
            x * y * oneMinusC - z * s,
            x * z * oneMinusC + y * s,
            y * x * oneMinusC + z * s,
            c + y * y * oneMinusC,
            y * z * oneMinusC - x * s,
            z * x * oneMinusC - y * s,
            z * y * oneMinusC + x * s,
            c + z * z * oneMinusC,
        )
    }

    private fun solveLinearSystem(
        matrix: Array<DoubleArray>,
        rhs: DoubleArray,
    ): DoubleArray? {
        val size = rhs.size
        val a = Array(size) { row -> matrix[row].copyOf() }
        val b = rhs.copyOf()

        for (column in 0 until size) {
            var pivot = column
            var pivotAbs = abs(a[column][column])
            for (row in column + 1 until size) {
                val candidate = abs(a[row][column])
                if (candidate > pivotAbs) {
                    pivot = row
                    pivotAbs = candidate
                }
            }
            if (!pivotAbs.isFinite() || pivotAbs < 1e-12) return null

            if (pivot != column) {
                val rowSwap = a[pivot]
                a[pivot] = a[column]
                a[column] = rowSwap
                val bSwap = b[pivot]
                b[pivot] = b[column]
                b[column] = bSwap
            }

            val diagonal = a[column][column]
            for (row in column + 1 until size) {
                val factor = a[row][column] / diagonal
                if (!factor.isFinite()) return null
                a[row][column] = 0.0
                for (k in column + 1 until size) {
                    a[row][k] -= factor * a[column][k]
                }
                b[row] -= factor * b[column]
            }
        }

        val result = DoubleArray(size)
        for (row in size - 1 downTo 0) {
            var value = b[row]
            for (column in row + 1 until size) {
                value -= a[row][column] * result[column]
            }
            val diagonal = a[row][row]
            if (!diagonal.isFinite() || abs(diagonal) < 1e-12) return null
            result[row] = value / diagonal
        }
        return result
    }

    private fun failure(
        status: String,
        sampleCount: Int,
        tofSlot: Int = -1,
        tofWidth: Int = 0,
        tofHeight: Int = 0,
        totalObservationCount: Int = 0,
    ): TofCameraExtrinsicsSolveResult =
        TofCameraExtrinsicsSolveResult(
            successful = false,
            tofSlot = tofSlot,
            tofWidth = tofWidth,
            tofHeight = tofHeight,
            tofIntrinsics = null,
            rotationToCamera = emptyList(),
            translationToCameraMm = emptyList(),
            sampleCount = sampleCount,
            totalObservationCount = totalObservationCount,
            retainedObservationCount = 0,
            planeRmsMm = null,
            planeP95Mm = null,
            allPlaneRmsMm = null,
            initialCost = null,
            finalCost = null,
            iterations = 0,
            solver = SOLVER_NAME,
            status = status,
        )

    private fun nominalFocalZones(size: Int): Double {
        val halfSpan = max(0.5, (size - 1).toDouble() / 2.0)
        return halfSpan / tan(NOMINAL_HALF_FOV_RAD)
    }

    private fun rms(values: List<Double>): Double? {
        if (values.isEmpty()) return null
        return sqrt(values.sumOf { it * it } / values.size.toDouble())
    }

    private fun percentile(
        values: List<Double>,
        fraction: Double,
    ): Double? {
        if (values.isEmpty()) return null
        val sorted = values.sorted()
        val index =
            ((sorted.size - 1) * fraction)
                .toInt()
                .coerceIn(0, sorted.lastIndex)
        return sorted[index]
    }

    private fun meanSquare(values: DoubleArray): Double {
        if (values.isEmpty()) return Double.POSITIVE_INFINITY
        var sum = 0.0
        values.forEach { sum += it * it }
        return sum / values.size.toDouble()
    }

    private fun vectorNorm(values: DoubleArray): Double =
        sqrt(values.sumOf { it * it })

    private fun square(value: Double): Double = value * value

    private data class Observation(
        val sample: TofCameraPlanarCalibrationSample,
        val zone: TofZoneRangeObservation,
    )

    private data class Nominal(
        val fx: Double,
        val fy: Double,
        val cx: Double,
        val cy: Double,
    )

    private data class Fit(
        val params: DoubleArray,
        val initialCost: Double,
        val finalCost: Double,
        val iterations: Int,
    )

    companion object {
        const val SOLVER_NAME = "LM03.4B2_5_NEAR_GHOST_FILTER_R2P_LM_V6"
        const val MIN_SAMPLES = 8
        const val MIN_OBSERVATIONS = 128

        private const val PARAM_COUNT = 10
        private const val EXTRA_PRIOR_COUNT = 1
        private const val IDX_LOG_FX = 0
        private const val IDX_LOG_FY = 1
        private const val IDX_CX = 2
        private const val IDX_CY = 3
        private const val IDX_RX = 4
        private const val IDX_RY = 5
        private const val IDX_RZ = 6
        private const val IDX_TX = 7
        private const val IDX_TY = 8
        private const val IDX_TZ = 9

        private const val MIN_SIGMA_MM = 8.0
        private const val CAUCHY_SCALE_SIGMA = 4.0
        private const val FOCAL_LOG_PRIOR_SIGMA = 0.30
        private const val CENTER_PRIOR_SIGMA_ZONES = 0.35
        private const val FOCAL_ISOTROPY_LOG_SIGMA = 0.20
        private const val MIN_HARD_FOCAL_RATIO = 0.55
        private const val MAX_HARD_FOCAL_RATIO = 2.00
        private const val MAX_HARD_CENTER_OFFSET_ZONES = 1.00
        private const val MIN_ACCEPTED_FOCAL_RATIO = 0.65
        private const val MAX_ACCEPTED_FOCAL_RATIO = 1.80
        private const val MAX_ACCEPTED_FOCAL_ASPECT = 1.50
        private const val MAX_ACCEPTED_CENTER_OFFSET_ZONES = 0.90
        private const val MAX_ACCEPTED_TRANSLATION_NORM_MM = 300.0
        private const val MAX_TRAINING_RMS_MM = 50.0
        private const val MAX_TRAINING_P95_MM = 100.0
        private const val MAX_ITERATIONS = 80
        private const val COST_EPSILON = 1e-10
        private const val STEP_EPSILON = 1e-7
        private const val MIN_LAMBDA = 1e-9
        private const val MAX_LAMBDA = 1e12
        private const val REPORT_RETAIN_FRACTION = 0.70
        private const val MIN_RETAINED_PER_SAMPLE = 8
        private val NOMINAL_HALF_FOV_RAD = Math.toRadians(22.5)
        private val ROLL_SEEDS = doubleArrayOf(
            0.0,
            PI / 2.0,
            -PI / 2.0,
            PI,
        )
        private val FINITE_DIFFERENCE_STEP = doubleArrayOf(
            1e-4,
            1e-4,
            1e-3,
            1e-3,
            1e-4,
            1e-4,
            1e-4,
            0.05,
            0.05,
            0.05,
        )
    }
}

private fun List<Double>.toJsonArray(): JSONArray =
    JSONArray().also { array -> forEach(array::put) }

private fun JSONArray?.toFiniteDoubleList(): List<Double> {
    if (this == null) return emptyList()
    return buildList {
        for (index in 0 until length()) {
            val value = optDouble(index, Double.NaN)
            if (value.isFinite()) add(value)
        }
    }
}

private fun JSONObject.optFiniteDoubleOrNull(name: String): Double? =
    if (!has(name) || isNull(name)) null
    else optDouble(name, Double.NaN).takeIf { it.isFinite() }
