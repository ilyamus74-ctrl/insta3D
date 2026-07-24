package com.maklertour.state

enum class CaptureBundleNoticeCode {
    QUEUED,
    CALIBRATION_NOT_SELECTED,
    CALIBRATION_INVALID,
    NO_STEREO_PAIRS,
    PAIR_FILES_INVALID,
    RIG_MISMATCH,
    RESOLUTION_MISMATCH,
    INVALID_CAPTURE,
    PACKAGING_FAILED,
}

data class CaptureBundleNotice(
    val code: CaptureBundleNoticeCode,
    val technicalDetail: String? = null,
) {
    val isError: Boolean
        get() = code != CaptureBundleNoticeCode.QUEUED
}

fun captureBundleNoticeFor(error: Throwable): CaptureBundleNotice {
    val detail = error.message
        ?.trim()
        ?.takeIf { it.isNotEmpty() }
        ?: error.javaClass.simpleName
    val normalized = detail.lowercase()

    val code = when {
        "calibration session is not selected" in normalized ->
            CaptureBundleNoticeCode.CALIBRATION_NOT_SELECTED

        "stereo_extrinsics.json" in normalized ||
            "stereo extrinsics" in normalized ||
            "calibration status must be success" in normalized ||
            "camera_matrix" in normalized ||
            "dist_coeffs" in normalized ||
            "stereo_r" in normalized ||
            "stereo_t" in normalized ->
            CaptureBundleNoticeCode.CALIBRATION_INVALID

        "no stereo pairs" in normalized ||
            "has no pairs array" in normalized ->
            CaptureBundleNoticeCode.NO_STEREO_PAIRS

        "pair_index" in normalized ||
            normalized.startsWith("pair ") ||
            "pair entry" in normalized ->
            CaptureBundleNoticeCode.PAIR_FILES_INVALID

        "rig_id" in normalized ||
            "active rig profile" in normalized ->
            CaptureBundleNoticeCode.RIG_MISMATCH

        "capture resolution" in normalized ||
            "calibration image size" in normalized ->
            CaptureBundleNoticeCode.RESOLUTION_MISMATCH

        else -> CaptureBundleNoticeCode.INVALID_CAPTURE
    }

    return CaptureBundleNotice(
        code = code,
        technicalDetail = detail,
    )
}
