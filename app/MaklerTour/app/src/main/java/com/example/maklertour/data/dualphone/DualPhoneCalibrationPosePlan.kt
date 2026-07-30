package com.maklertour.data.dualphone

data class DualPhoneCalibrationPoseTarget(
    val index: Int,
    val id: String,
    val instruction: String,
    val centreX: Float = 0.5f,
    val centreY: Float = 0.5f,
    val centreToleranceX: Float = 0.24f,
    val centreToleranceY: Float = 0.24f,
    val minAreaFraction: Float = 0.035f,
    val maxAreaFraction: Float = 0.72f,
    val minAbsRollDegrees: Float = 0f,
    val rollSign: Int = 0,
    val minAbsYawSkew: Float = 0f,
    val yawSign: Int = 0,
    val minAbsPitchSkew: Float = 0f,
    val pitchSign: Int = 0,
)

object DualPhoneCalibrationPosePlan {
    val targets: List<DualPhoneCalibrationPoseTarget> = listOf(
        spec("centre_medium", "Hold the ChArUco board in the centre at medium distance", area = 0.10f..0.34f),
        spec("centre_near", "Move the board closer while keeping it fully visible", area = 0.28f..0.65f),
        spec("centre_far", "Move the board farther away, still showing at least 12 corners", area = 0.035f..0.14f),
        spec("upper_left", "Move the board to the upper-left part of the frame", x = 0.27f, y = 0.28f),
        spec("upper_right", "Move the board to the upper-right part of the frame", x = 0.73f, y = 0.28f),
        spec("lower_left", "Move the board to the lower-left part of the frame", x = 0.27f, y = 0.72f),
        spec("lower_right", "Move the board to the lower-right part of the frame", x = 0.73f, y = 0.72f),
        spec("top_edge", "Move the board near the top edge without clipping it", y = 0.22f, toleranceY = 0.18f),
        spec("bottom_edge", "Move the board near the bottom edge without clipping it", y = 0.78f, toleranceY = 0.18f),
        spec("left_edge", "Move the board near the left edge without clipping it", x = 0.20f, toleranceX = 0.18f),
        spec("right_edge", "Move the board near the right edge without clipping it", x = 0.80f, toleranceX = 0.18f),
        spec("yaw_left", "Turn the board left: bring its right edge closer to the cameras", yaw = 0.08f, yawSign = 1),
        spec("yaw_right", "Turn the board right: bring its left edge closer to the cameras", yaw = 0.08f, yawSign = -1),
        spec("pitch_up", "Tilt the board up: bring its lower edge closer to the cameras", pitch = 0.08f, pitchSign = 1),
        spec("pitch_down", "Tilt the board down: bring its upper edge closer to the cameras", pitch = 0.08f, pitchSign = -1),
        spec("roll_counter_clockwise", "Rotate the board about 15 degrees counter-clockwise", roll = 10f, rollSign = -1),
        spec("roll_clockwise", "Rotate the board about 15 degrees clockwise", roll = 10f, rollSign = 1),
        spec("oblique_upper_left", "Upper-left oblique view: shift left/up and tilt the board", x = 0.30f, y = 0.30f, yaw = 0.055f),
        spec("oblique_upper_right", "Upper-right oblique view: shift right/up and tilt the board", x = 0.70f, y = 0.30f, pitch = 0.055f),
        spec("oblique_lower_left", "Lower-left oblique view: shift left/down and tilt the board", x = 0.30f, y = 0.70f, pitch = 0.055f),
        spec("oblique_lower_right", "Lower-right oblique view: shift right/down and tilt the board", x = 0.70f, y = 0.70f, yaw = 0.055f),
        spec("near_oblique", "Bring the board closer and hold a clear oblique angle", area = 0.24f..0.65f, yaw = 0.055f),
        spec("far_oblique", "Move the board farther away and hold a clear oblique angle", area = 0.035f..0.16f, pitch = 0.055f),
        spec("final_centre_oblique", "Final pose: centre the board and hold a different oblique angle", yaw = 0.05f, pitch = 0.05f),
    ).mapIndexed { index, target -> target.copy(index = index) }

    val first: DualPhoneCalibrationPoseTarget get() = targets.first()
    val size: Int get() = targets.size

    fun at(index: Int): DualPhoneCalibrationPoseTarget =
        targets[index.coerceIn(0, targets.lastIndex)]

    fun byId(id: String?): DualPhoneCalibrationPoseTarget =
        targets.firstOrNull { it.id == id } ?: first

    private fun spec(
        id: String,
        instruction: String,
        x: Float = 0.5f,
        y: Float = 0.5f,
        toleranceX: Float = 0.24f,
        toleranceY: Float = 0.24f,
        area: ClosedFloatingPointRange<Float> = 0.055f..0.55f,
        roll: Float = 0f,
        rollSign: Int = 0,
        yaw: Float = 0f,
        yawSign: Int = 0,
        pitch: Float = 0f,
        pitchSign: Int = 0,
    ) = DualPhoneCalibrationPoseTarget(
        index = -1,
        id = id,
        instruction = instruction,
        centreX = x,
        centreY = y,
        centreToleranceX = toleranceX,
        centreToleranceY = toleranceY,
        minAreaFraction = area.start,
        maxAreaFraction = area.endInclusive,
        minAbsRollDegrees = roll,
        rollSign = rollSign,
        minAbsYawSkew = yaw,
        yawSign = yawSign,
        minAbsPitchSkew = pitch,
        pitchSign = pitchSign,
    )
}
