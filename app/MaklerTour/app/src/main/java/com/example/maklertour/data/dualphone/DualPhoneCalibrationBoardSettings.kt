package com.maklertour.data.dualphone

import com.maklertour.data.rig.CalibrationBoardType
import com.maklertour.data.rig.CalibrationSettings
import org.json.JSONObject
import java.util.Locale

data class DualPhoneCalibrationBoardSettings(
    val boardType: CalibrationBoardType = CalibrationBoardType.CHARUCO,
    val checkerboardInnerCols: Int = 8,
    val checkerboardInnerRows: Int = 5,
    val checkerboardSquareSizeMm: Double = 29.0,
    val charucoSquaresX: Int = 9,
    val charucoSquaresY: Int = 6,
    val charucoSquareLengthMm: Double = 29.0,
    val charucoMarkerLengthMm: Double = 21.0,
    val charucoDictionary: String = "DICT_4X4_50",
    val minCharucoCorners: Int = 20,
    val charucoLegacyPattern: Boolean = true,
) {
    fun validationError(): String? = when (boardType) {
        CalibrationBoardType.CHARUCO -> when {
            charucoSquaresX !in 3..30 -> "ChArUco: квадратов X должно быть 3–30"
            charucoSquaresY !in 3..30 -> "ChArUco: квадратов Y должно быть 3–30"
            charucoSquareLengthMm !in 1.0..200.0 ->
                "ChArUco: размер квадрата должен быть 1–200 мм"
            charucoMarkerLengthMm < 0.5 ||
                charucoMarkerLengthMm >= charucoSquareLengthMm ->
                "ChArUco: маркер должен быть меньше квадрата"
            charucoDictionary !in SUPPORTED_DICTIONARIES ->
                "Выберите поддерживаемый словарь ChArUco"
            minCharucoCorners !in 4..maxCharucoCorners ->
                "Минимум углов должен быть 4–$maxCharucoCorners"
            else -> null
        }
        CalibrationBoardType.CHESSBOARD_LEGACY -> when {
            checkerboardInnerCols !in 3..30 ->
                "Шахматка: внутренних углов X должно быть 3–30"
            checkerboardInnerRows !in 3..30 ->
                "Шахматка: внутренних углов Y должно быть 3–30"
            checkerboardSquareSizeMm !in 1.0..200.0 ->
                "Шахматка: размер клетки должен быть 1–200 мм"
            else -> null
        }
    }

    val maxCharucoCorners: Int
        get() = (charucoSquaresX - 1).coerceAtLeast(1) *
            (charucoSquaresY - 1).coerceAtLeast(1)

    fun toCalibrationSettings(requiredPairs: Int): CalibrationSettings =
        CalibrationSettings(
            checkerboardInnerCols = checkerboardInnerCols,
            checkerboardInnerRows = checkerboardInnerRows,
            squareSizeMm = checkerboardSquareSizeMm,
            requiredPairs = requiredPairs,
            boardType = boardType,
            charucoSquaresX = charucoSquaresX,
            charucoSquaresY = charucoSquaresY,
            charucoSquareLengthMm = charucoSquareLengthMm,
            charucoMarkerLengthMm = charucoMarkerLengthMm,
            charucoDictionary = charucoDictionary,
            minCharucoCorners = minCharucoCorners.coerceAtMost(maxCharucoCorners),
            charucoLegacyPattern = charucoLegacyPattern,
        )

    fun summaryRu(): String = when (boardType) {
        CalibrationBoardType.CHARUCO -> String.format(
            Locale.US,
            "ChArUco %dx%d · квадрат %.1f мм · маркер %.1f мм · %s",
            charucoSquaresX,
            charucoSquaresY,
            charucoSquareLengthMm,
            charucoMarkerLengthMm,
            charucoDictionary,
        )
        CalibrationBoardType.CHESSBOARD_LEGACY -> String.format(
            Locale.US,
            "Шахматка %dx%d внутренних углов · клетка %.1f мм",
            checkerboardInnerCols,
            checkerboardInnerRows,
            checkerboardSquareSizeMm,
        )
    }

    fun toJson(): JSONObject = JSONObject()
        .put("board_type", boardType.name)
        .put("checkerboard_inner_cols", checkerboardInnerCols)
        .put("checkerboard_inner_rows", checkerboardInnerRows)
        .put("checkerboard_square_size_mm", checkerboardSquareSizeMm)
        .put("charuco_squares_x", charucoSquaresX)
        .put("charuco_squares_y", charucoSquaresY)
        .put("charuco_square_length_mm", charucoSquareLengthMm)
        .put("charuco_marker_length_mm", charucoMarkerLengthMm)
        .put("charuco_dictionary", charucoDictionary)
        .put("min_charuco_corners", minCharucoCorners)
        .put("charuco_legacy_pattern", charucoLegacyPattern)

    companion object {
        val SUPPORTED_DICTIONARIES = listOf(
            "DICT_4X4_50",
            "DICT_4X4_100",
            "DICT_5X5_50",
            "DICT_5X5_100",
            "DICT_6X6_50",
            "DICT_6X6_100",
        )

        fun fromJson(json: JSONObject): DualPhoneCalibrationBoardSettings =
            DualPhoneCalibrationBoardSettings(
                boardType = runCatching {
                    CalibrationBoardType.valueOf(
                        json.optString(
                            "board_type",
                            CalibrationBoardType.CHARUCO.name,
                        ),
                    )
                }.getOrDefault(CalibrationBoardType.CHARUCO),
                checkerboardInnerCols = json.optInt("checkerboard_inner_cols", 8),
                checkerboardInnerRows = json.optInt("checkerboard_inner_rows", 5),
                checkerboardSquareSizeMm =
                    json.optDouble("checkerboard_square_size_mm", 29.0),
                charucoSquaresX = json.optInt("charuco_squares_x", 9),
                charucoSquaresY = json.optInt("charuco_squares_y", 6),
                charucoSquareLengthMm =
                    json.optDouble("charuco_square_length_mm", 29.0),
                charucoMarkerLengthMm =
                    json.optDouble("charuco_marker_length_mm", 21.0),
                charucoDictionary =
                    json.optString("charuco_dictionary", "DICT_4X4_50"),
                minCharucoCorners = json.optInt("min_charuco_corners", 20),
                charucoLegacyPattern =
                    json.optBoolean("charuco_legacy_pattern", true),
            )
    }
}
