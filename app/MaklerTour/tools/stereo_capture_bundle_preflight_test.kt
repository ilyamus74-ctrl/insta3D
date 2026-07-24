import com.example.maklertour.data.capture.CaptureBundlePreflightException
import com.example.maklertour.data.capture.StereoCalibrationInput
import com.example.maklertour.data.capture.StereoCaptureBundlePreflight
import com.example.maklertour.data.capture.StereoCaptureBundlePreflightInput
import com.example.maklertour.data.capture.StereoCapturePairInput
import java.io.File
import java.nio.file.Files

private fun check(condition: Boolean, message: String) {
    if (!condition) error(message)
}

private fun expectFailure(
    expectedMessage: String,
    block: () -> Unit,
) {
    val error = runCatching(block).exceptionOrNull()
    check(
        error is CaptureBundlePreflightException,
        "expected CaptureBundlePreflightException, got $error",
    )
    check(
        error?.message?.contains(expectedMessage) == true,
        "expected error containing '$expectedMessage', got '${error?.message}'",
    )
}

private fun calibration(
    status: String = "success",
    rigId: String? = "rig-a",
    translation: List<Double> = listOf(120.0, 0.0, 0.0),
    width: Int? = 1920,
    height: Int? = 1080,
): StereoCalibrationInput = StereoCalibrationInput(
    status = status,
    cam0CameraMatrix = listOf(
        900.0, 0.0, 960.0,
        0.0, 900.0, 540.0,
        0.0, 0.0, 1.0,
    ),
    cam0DistCoeffs = listOf(0.01, -0.02, 0.0, 0.0, 0.0),
    cam1CameraMatrix = listOf(
        905.0, 0.0, 958.0,
        0.0, 904.0, 542.0,
        0.0, 0.0, 1.0,
    ),
    cam1DistCoeffs = listOf(0.01, -0.02, 0.0, 0.0, 0.0),
    stereoRotation = listOf(
        1.0, 0.0, 0.0,
        0.0, 1.0, 0.0,
        0.0, 0.0, 1.0,
    ),
    stereoTranslation = translation,
    cam0ImageWidth = width,
    cam0ImageHeight = height,
    cam1ImageWidth = width,
    cam1ImageHeight = height,
    rigId = rigId,
)

private fun input(
    root: File,
    calibrationDir: File,
    extrinsics: File,
    pairs: List<StereoCapturePairInput>,
    calibration: StereoCalibrationInput = calibration(),
    captureRigId: String? = "rig-a",
    profileRigId: String? = "rig-a",
    width: Int = 1920,
    height: Int = 1080,
): StereoCaptureBundlePreflightInput =
    StereoCaptureBundlePreflightInput(
        captureDir = root,
        captureType = "synced_depth_frames",
        pairs = pairs,
        captureRigId = captureRigId,
        activeRigProfileId = profileRigId,
        rawWidth = width,
        rawHeight = height,
        calibrationSessionDir = calibrationDir,
        extrinsicsFile = extrinsics,
        calibration = calibration,
    )

fun main() {
    val temp = Files.createTempDirectory(
        "stereo_capture_bundle_preflight_",
    ).toFile()
    try {
        val capture = File(temp, "capture").apply { mkdirs() }
        val pairsDir = File(capture, "pairs").apply { mkdirs() }
        File(pairsDir, "cam0_0000.jpg").writeBytes(byteArrayOf(1, 2, 3))
        File(pairsDir, "cam1_0000.jpg").writeBytes(byteArrayOf(4, 5, 6))
        File(pairsDir, "cam0_0001.jpg").writeBytes(byteArrayOf(7, 8, 9))
        File(pairsDir, "cam1_0001.jpg").writeBytes(byteArrayOf(10, 11, 12))

        val calibrationDir = File(temp, "calibration").apply { mkdirs() }
        val extrinsics = File(
            calibrationDir,
            "stereo_extrinsics.json",
        ).apply { writeText("{}") }

        val pairs = listOf(
            StereoCapturePairInput(
                pairIndex = 0,
                cam0File = "pairs/cam0_0000.jpg",
                cam1File = "pairs/cam1_0000.jpg",
            ),
            StereoCapturePairInput(
                pairIndex = 1,
                cam0File = "pairs/cam0_0001.jpg",
                cam1File = "pairs/cam1_0001.jpg",
            ),
        )

        val valid = StereoCaptureBundlePreflight.validate(
            input(capture, calibrationDir, extrinsics, pairs),
        )
        check(valid.schemaVersion == 1, "schema version")
        check(valid.pairsCount == 2, "pairs count")
        check(valid.calibrationStatus == "success", "calibration status")
        check(valid.baselineMagnitude == 120.0, "baseline magnitude")

        expectFailure("no stereo pairs") {
            StereoCaptureBundlePreflight.validate(
                input(capture, calibrationDir, extrinsics, emptyList()),
            )
        }

        expectFailure("duplicate pair_index") {
            StereoCaptureBundlePreflight.validate(
                input(
                    capture,
                    calibrationDir,
                    extrinsics,
                    listOf(pairs[0], pairs[0]),
                ),
            )
        }

        expectFailure("escapes capture directory") {
            val outside = File(temp, "outside.jpg").apply {
                writeBytes(byteArrayOf(1))
            }
            StereoCaptureBundlePreflight.validate(
                input(
                    capture,
                    calibrationDir,
                    extrinsics,
                    listOf(
                        pairs[0].copy(
                            cam0File = "../${outside.name}",
                        ),
                    ),
                ),
            )
        }

        expectFailure("is empty") {
            val empty = File(pairsDir, "empty.jpg").apply {
                writeBytes(byteArrayOf())
            }
            StereoCaptureBundlePreflight.validate(
                input(
                    capture,
                    calibrationDir,
                    extrinsics,
                    listOf(
                        pairs[0].copy(
                            cam0File = "pairs/${empty.name}",
                        ),
                    ),
                ),
            )
        }

        expectFailure("status must be success") {
            StereoCaptureBundlePreflight.validate(
                input(
                    capture,
                    calibrationDir,
                    extrinsics,
                    pairs,
                    calibration = calibration(status = "failed"),
                ),
            )
        }

        expectFailure("baseline magnitude must be positive") {
            StereoCaptureBundlePreflight.validate(
                input(
                    capture,
                    calibrationDir,
                    extrinsics,
                    pairs,
                    calibration = calibration(
                        translation = listOf(0.0, 0.0, 0.0),
                    ),
                ),
            )
        }

        expectFailure("does not match active rig profile") {
            StereoCaptureBundlePreflight.validate(
                input(
                    capture,
                    calibrationDir,
                    extrinsics,
                    pairs,
                    profileRigId = "rig-b",
                ),
            )
        }

        expectFailure("does not match calibration") {
            StereoCaptureBundlePreflight.validate(
                input(
                    capture,
                    calibrationDir,
                    extrinsics,
                    pairs,
                    calibration = calibration(width = 1280, height = 720),
                ),
            )
        }

        val missingExtrinsics = File(calibrationDir, "missing.json")
        expectFailure("stereo_extrinsics.json is missing or empty") {
            StereoCaptureBundlePreflight.validate(
                input(
                    capture,
                    calibrationDir,
                    missingExtrinsics,
                    pairs,
                ),
            )
        }

        val noSizeContract = StereoCaptureBundlePreflight.validate(
            input(
                capture,
                calibrationDir,
                extrinsics,
                pairs,
                calibration = calibration(width = null, height = null),
            ),
        )
        check(noSizeContract.pairsCount == 2, "optional calibration size")
    } finally {
        temp.deleteRecursively()
    }

    println("OK")
}
