import com.maklertour.state.CaptureBundleNotice
import com.maklertour.state.CaptureBundleNoticeCode
import com.maklertour.state.captureBundleNoticeFor

private fun check(condition: Boolean, message: String) {
    if (!condition) error(message)
}

private fun mapped(
    message: String,
    expected: CaptureBundleNoticeCode,
) {
    val notice = captureBundleNoticeFor(
        IllegalArgumentException(message),
    )
    check(
        notice.code == expected,
        "message '$message' mapped to ${notice.code}, expected $expected",
    )
    check(notice.isError, "mapped failures must be errors")
    check(
        notice.technicalDetail == message,
        "technical detail must be preserved",
    )
}

fun main() {
    val queued = CaptureBundleNotice(
        CaptureBundleNoticeCode.QUEUED,
    )
    check(!queued.isError, "QUEUED is not an error")

    mapped(
        "calibration session is not selected",
        CaptureBundleNoticeCode.CALIBRATION_NOT_SELECTED,
    )
    mapped(
        "stereo_extrinsics.json is missing or empty",
        CaptureBundleNoticeCode.CALIBRATION_INVALID,
    )
    mapped(
        "stereo calibration status must be success, got failed",
        CaptureBundleNoticeCode.CALIBRATION_INVALID,
    )
    mapped(
        "synced depth capture has no stereo pairs",
        CaptureBundleNoticeCode.NO_STEREO_PAIRS,
    )
    mapped(
        "pair 3 cam0_file is missing",
        CaptureBundleNoticeCode.PAIR_FILES_INVALID,
    )
    mapped(
        "duplicate pair_index=4",
        CaptureBundleNoticeCode.PAIR_FILES_INVALID,
    )
    mapped(
        "capture rig_id=rig-a does not match active rig profile=rig-b",
        CaptureBundleNoticeCode.RIG_MISMATCH,
    )
    mapped(
        "capture resolution 1920x1080 does not match calibration 1280x720",
        CaptureBundleNoticeCode.RESOLUTION_MISMATCH,
    )
    mapped(
        "unsupported capture_type=legacy",
        CaptureBundleNoticeCode.INVALID_CAPTURE,
    )

    println("OK")
}
