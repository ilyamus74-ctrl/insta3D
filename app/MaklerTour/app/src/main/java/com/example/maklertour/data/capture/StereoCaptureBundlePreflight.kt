package com.example.maklertour.data.capture

import java.io.File
import kotlin.math.abs
import kotlin.math.sqrt

class CaptureBundlePreflightException(message: String) :
    IllegalArgumentException(message)

data class StereoCapturePairInput(
    val pairIndex: Int,
    val cam0File: String,
    val cam1File: String,
)

data class StereoCalibrationInput(
    val status: String,
    val cam0CameraMatrix: List<Double>,
    val cam0DistCoeffs: List<Double>,
    val cam1CameraMatrix: List<Double>,
    val cam1DistCoeffs: List<Double>,
    val stereoRotation: List<Double>,
    val stereoTranslation: List<Double>,
    val cam0ImageWidth: Int? = null,
    val cam0ImageHeight: Int? = null,
    val cam1ImageWidth: Int? = null,
    val cam1ImageHeight: Int? = null,
    val rigId: String? = null,
)

data class StereoCaptureBundlePreflightInput(
    val captureDir: File,
    val captureType: String,
    val pairs: List<StereoCapturePairInput>,
    val captureRigId: String?,
    val activeRigProfileId: String?,
    val rawWidth: Int,
    val rawHeight: Int,
    val calibrationSessionDir: File,
    val extrinsicsFile: File,
    val calibration: StereoCalibrationInput,
)

data class StereoCaptureBundlePreflightResult(
    val schemaVersion: Int = 1,
    val pairsCount: Int,
    val captureRigId: String?,
    val calibrationStatus: String,
    val baselineMagnitude: Double,
)

object StereoCaptureBundlePreflight {
    fun validate(
        input: StereoCaptureBundlePreflightInput,
    ): StereoCaptureBundlePreflightResult {
        requirePreflight(
            input.captureDir.isDirectory,
            "capture directory is missing: ${input.captureDir.absolutePath}",
        )
        requirePreflight(
            input.captureType == "synced_depth_frames",
            "unsupported capture_type=${input.captureType}",
        )
        requirePreflight(
            input.pairs.isNotEmpty(),
            "synced depth capture has no stereo pairs",
        )

        validatePairs(input.captureDir, input.pairs)

        requirePreflight(
            input.calibrationSessionDir.isDirectory,
            "calibration session directory is missing: " +
                input.calibrationSessionDir.absolutePath,
        )
        requirePreflight(
            input.extrinsicsFile.isFile &&
                input.extrinsicsFile.length() > 0L,
            "stereo_extrinsics.json is missing or empty: " +
                input.extrinsicsFile.absolutePath,
        )

        val calibration = input.calibration
        requirePreflight(
            calibration.status == "success",
            "stereo calibration status must be success, got " +
                calibration.status.ifBlank { "<empty>" },
        )

        validateCameraMatrix(
            "cam0_camera_matrix",
            calibration.cam0CameraMatrix,
        )
        validateDistortion(
            "cam0_dist_coeffs",
            calibration.cam0DistCoeffs,
        )
        validateCameraMatrix(
            "cam1_camera_matrix",
            calibration.cam1CameraMatrix,
        )
        validateDistortion(
            "cam1_dist_coeffs",
            calibration.cam1DistCoeffs,
        )
        validateRotation(calibration.stereoRotation)
        val baselineMagnitude = validateTranslation(
            calibration.stereoTranslation,
        )

        validateRigIds(
            captureRigId = input.captureRigId,
            activeRigProfileId = input.activeRigProfileId,
            calibrationRigId = calibration.rigId,
        )
        validateResolution(
            rawWidth = input.rawWidth,
            rawHeight = input.rawHeight,
            calibration = calibration,
        )

        return StereoCaptureBundlePreflightResult(
            pairsCount = input.pairs.size,
            captureRigId = normalized(input.captureRigId),
            calibrationStatus = calibration.status,
            baselineMagnitude = baselineMagnitude,
        )
    }

    private fun validatePairs(
        captureDir: File,
        pairs: List<StereoCapturePairInput>,
    ) {
        val indexes = mutableSetOf<Int>()
        pairs.forEachIndexed { position, pair ->
            requirePreflight(
                pair.pairIndex >= 0,
                "pair at position $position has invalid pair_index=" +
                    pair.pairIndex,
            )
            requirePreflight(
                indexes.add(pair.pairIndex),
                "duplicate pair_index=${pair.pairIndex}",
            )
            val cam0 = validateCaptureFile(
                captureDir,
                pair.pairIndex,
                "cam0_file",
                pair.cam0File,
            )
            val cam1 = validateCaptureFile(
                captureDir,
                pair.pairIndex,
                "cam1_file",
                pair.cam1File,
            )
            requirePreflight(
                cam0.canonicalPath != cam1.canonicalPath,
                "pair ${pair.pairIndex} references the same file " +
                    "for cam0 and cam1",
            )
        }
    }

    private fun validateCaptureFile(
        captureDir: File,
        pairIndex: Int,
        field: String,
        relativePath: String,
    ): File {
        val cleanPath = relativePath.trim()
        requirePreflight(
            cleanPath.isNotBlank(),
            "pair $pairIndex has empty $field",
        )
        requirePreflight(
            !File(cleanPath).isAbsolute,
            "pair $pairIndex $field must be relative: $cleanPath",
        )

        val root = captureDir.canonicalFile.toPath()
        val resolved = File(captureDir, cleanPath).canonicalFile
        requirePreflight(
            resolved.toPath().startsWith(root),
            "pair $pairIndex $field escapes capture directory: $cleanPath",
        )
        requirePreflight(
            resolved.isFile,
            "pair $pairIndex $field is missing: $cleanPath",
        )
        requirePreflight(
            resolved.length() > 0L,
            "pair $pairIndex $field is empty: $cleanPath",
        )
        return resolved
    }

    private fun validateCameraMatrix(
        label: String,
        values: List<Double>,
    ) {
        requirePreflight(
            values.size == 9,
            "$label must contain 9 numeric values",
        )
        requireFinite(label, values)
        requirePreflight(
            values[0] > 0.0 && values[4] > 0.0,
            "$label has non-positive focal length",
        )
        requirePreflight(
            abs(values[8] - 1.0) <= 0.05,
            "$label has invalid homogeneous scale=${values[8]}",
        )
    }

    private fun validateDistortion(
        label: String,
        values: List<Double>,
    ) {
        requirePreflight(
            values.size >= 4,
            "$label must contain at least 4 numeric values",
        )
        requireFinite(label, values)
    }

    private fun validateRotation(values: List<Double>) {
        requirePreflight(
            values.size == 9,
            "stereo_R must contain 9 numeric values",
        )
        requireFinite("stereo_R", values)

        val r0 = values.subList(0, 3)
        val r1 = values.subList(3, 6)
        val r2 = values.subList(6, 9)
        listOf(r0, r1, r2).forEachIndexed { index, row ->
            requirePreflight(
                abs(dot(row, row) - 1.0) <= 0.08,
                "stereo_R row $index is not unit length",
            )
        }
        requirePreflight(
            abs(dot(r0, r1)) <= 0.08 &&
                abs(dot(r0, r2)) <= 0.08 &&
                abs(dot(r1, r2)) <= 0.08,
            "stereo_R rows are not orthogonal",
        )

        val determinant =
            values[0] * (values[4] * values[8] - values[5] * values[7]) -
                values[1] * (
                    values[3] * values[8] -
                        values[5] * values[6]
                    ) +
                values[2] * (
                    values[3] * values[7] -
                        values[4] * values[6]
                    )
        requirePreflight(
            abs(determinant - 1.0) <= 0.08,
            "stereo_R determinant must be near 1, got $determinant",
        )
    }

    private fun validateTranslation(values: List<Double>): Double {
        requirePreflight(
            values.size == 3,
            "stereo_T must contain 3 numeric values",
        )
        requireFinite("stereo_T", values)
        val magnitude = sqrt(values.sumOf { it * it })
        requirePreflight(
            magnitude > 1e-6,
            "stereo_T baseline magnitude must be positive",
        )
        return magnitude
    }

    private fun validateRigIds(
        captureRigId: String?,
        activeRigProfileId: String?,
        calibrationRigId: String?,
    ) {
        val capture = normalized(captureRigId)
        val profile = normalized(activeRigProfileId)
        val calibration = normalized(calibrationRigId)

        if (capture != null && profile != null) {
            requirePreflight(
                capture == profile,
                "capture rig_id=$capture does not match " +
                    "active rig profile=$profile",
            )
        }
        if (capture != null && calibration != null) {
            requirePreflight(
                capture == calibration,
                "capture rig_id=$capture does not match " +
                    "calibration rig_id=$calibration",
            )
        }
        if (profile != null && calibration != null) {
            requirePreflight(
                profile == calibration,
                "active rig profile=$profile does not match " +
                    "calibration rig_id=$calibration",
            )
        }
    }

    private fun validateResolution(
        rawWidth: Int,
        rawHeight: Int,
        calibration: StereoCalibrationInput,
    ) {
        if (rawWidth <= 0 || rawHeight <= 0) return

        val calibrationSizes = listOfNotNull(
            completeSize(
                calibration.cam0ImageWidth,
                calibration.cam0ImageHeight,
            ),
            completeSize(
                calibration.cam1ImageWidth,
                calibration.cam1ImageHeight,
            ),
        )
        calibrationSizes.forEach { size ->
            requirePreflight(
                size.first == rawWidth && size.second == rawHeight,
                "capture resolution ${rawWidth}x$rawHeight does not " +
                    "match calibration ${size.first}x${size.second}",
            )
        }
    }

    private fun completeSize(
        width: Int?,
        height: Int?,
    ): Pair<Int, Int>? {
        if (width == null && height == null) return null
        val resolvedWidth = width ?: throw CaptureBundlePreflightException("calibration image size is incomplete")
        val resolvedHeight = height ?: throw CaptureBundlePreflightException("calibration image size is incomplete")
        requirePreflight(
            resolvedWidth > 0 && resolvedHeight > 0,
            "calibration image size is incomplete",
        )
        return resolvedWidth to resolvedHeight
    }

    private fun requireFinite(
        label: String,
        values: List<Double>,
    ) {
        requirePreflight(
            values.all { it.isFinite() },
            "$label contains non-finite values",
        )
    }

    private fun dot(a: List<Double>, b: List<Double>): Double =
        a.indices.sumOf { index -> a[index] * b[index] }

    private fun normalized(value: String?): String? =
        value?.trim()?.takeIf { it.isNotEmpty() }

    private fun requirePreflight(
        condition: Boolean,
        message: String,
    ) {
        if (!condition) {
            throw CaptureBundlePreflightException(message)
        }
    }
}
