package com.maklertour.data.phonecamera

import kotlin.math.abs
import kotlin.math.max

enum class AutoPhotoGuidancePhase(val wireValue: String) {
    IDLE("idle"),
    MOVE("move"),
    HOLD("hold"),
    CAPTURED("captured"),
    RECOVER("recover"),
    COMPLETE("complete"),
    ERROR("error"),
}

data class AutoPhotoGhostFrame(
    val width: Int,
    val height: Int,
    val luma: ByteArray,
    val sequence: Int,
) {
    init {
        require(width > 0 && height > 0) { "ghost dimensions must be positive" }
        require(luma.size == width * height) { "ghost luma size mismatch" }
        require(sequence > 0) { "ghost sequence must be positive" }
    }
}

data class AutoPhotoMovementCaptureDecision(
    val shouldCapture: Boolean,
    val reason: String,
    val guidance: String,
    val phase: AutoPhotoGuidancePhase,
    val movementProgressPercent: Int = 0,
    val fallback: Boolean = false,
    val commitReference: Boolean = false,
) {
    fun toMetadataMap(): Map<String, Any> = linkedMapOf(
        "should_capture" to shouldCapture,
        "reason" to reason,
        "guidance" to guidance,
        "phase" to phase.wireValue,
        "movement_progress_percent" to movementProgressPercent,
        "fallback" to fallback,
        "commit_reference" to commitReference,
    )
}

object AutoPhotoMovementCapturePolicy {
    fun decide(
        baseReason: String,
        movement: AutoPhotoMovementResult,
        savedCount: Int,
        nowMs: Long,
        lastCaptureMs: Long,
        settings: AutoPhotoSettings,
    ): AutoPhotoMovementCaptureDecision {
        terminalDecision(baseReason)?.let { return it }

        if (
            !settings.movementCaptureEnabled
            || !settings.visualMovementMetricsEnabled
        ) {
            return if (baseReason == "accepted") {
                accept(
                    reason = "accepted_timer",
                    guidance = "Фото сохранено по таймеру",
                    commitReference = true,
                )
            } else {
                holdForBase(baseReason)
            }
        }

        if (savedCount == 0) {
            return if (baseReason == "accepted") {
                accept(
                    reason = "accepted_first_reference",
                    guidance = "Первый опорный кадр сохраняется",
                    commitReference = true,
                )
            } else {
                holdForBase(baseReason)
            }
        }

        val elapsedMs = (nowMs - lastCaptureMs).coerceAtLeast(0L)
        return when (movement.status) {
            AutoPhotoMovementStatus.NO_REFERENCE -> recover(
                reason = "movement_reference_missing",
                guidance = "Опорный кадр потерян — перезапустите съёмку",
            )

            AutoPhotoMovementStatus.DISABLED -> recover(
                reason = "movement_unavailable",
                guidance = "Оценка движения недоступна",
            )

            AutoPhotoMovementStatus.INSUFFICIENT_FEATURES -> fallbackOrRecover(
                reason = "movement_features_low",
                guidance = "Совместите ghost с участком, где больше деталей",
                elapsedMs = elapsedMs,
                settings = settings,
            )

            AutoPhotoMovementStatus.TRACKING_FAILED -> fallbackOrRecover(
                reason = "movement_tracking_failed",
                guidance = "Перекрытие потеряно — совместите текущий вид с ghost",
                elapsedMs = elapsedMs,
                settings = settings,
            )

            AutoPhotoMovementStatus.OK -> decideTracked(
                baseReason = baseReason,
                movement = movement,
                settings = settings,
            )
        }
    }

    fun guidanceFor(reason: String): String = when (reason) {
        "accepted_first_reference" -> "Первый опорный кадр сохраняется"
        "accepted_movement" -> "Достаточное перемещение — держите телефон"
        "accepted_fallback" -> "Диагностический кадр без смены reference"
        "accepted_timer" -> "Фото сохранено по таймеру"
        "move_camera" -> "Плавно перемещайте камеру"
        "overlap_too_low" -> "Перекрытие потеряно — совместите вид с ghost"
        "movement_features_low" -> "Совместите ghost с участком, где больше деталей"
        "movement_tracking_failed" -> "Перекрытие потеряно — совместите текущий вид с ghost"
        "movement_reference_missing" -> "Опорный кадр потерян — перезапустите съёмку"
        "movement_unavailable" -> "Оценка движения недоступна"
        "motion_too_high" -> "Достаточное перемещение — держите телефон спокойнее"
        "not_stable_long_enough" -> "Достаточное перемещение — зафиксируйте телефон"
        "minimum_interval" -> "Достаточное перемещение — коротко удерживайте телефон"
        "too_blurry" -> "Кадр размыт — зафиксируйте телефон"
        "max_photos_reached" -> "Достигнут лимит фотографий"
        "storage_reserve" -> "Недостаточно свободного места"
        "capture_in_progress" -> "Сохранение фотографии"
        "camera_not_ready" -> "Камера не готова"
        "paused" -> "Съёмка приостановлена"
        "finished" -> "Съёмка завершена"
        "cancelled" -> "Съёмка отменена"
        else -> reason
    }

    private fun decideTracked(
        baseReason: String,
        movement: AutoPhotoMovementResult,
        settings: AutoPhotoSettings,
    ): AutoPhotoMovementCaptureDecision {
        val trackedRatio = movement.trackedRatio ?: 0.0
        val median = movement.medianDisplacementPx ?: 0.0
        val p90 = movement.p90DisplacementPx ?: median
        val rotation = abs(movement.estimatedRotationDeg ?: 0.0)

        if (
            trackedRatio < settings.movementMinTrackedRatio
            || median > settings.movementMaxMedianDisplacementPx
            || p90 > settings.movementMaxP90DisplacementPx
            || rotation > settings.movementMaxRotationDeg
        ) {
            return recover(
                reason = "overlap_too_low",
                guidance = recoveryGuidance(movement),
            )
        }

        val movementProgress = movementProgressPercent(
            movement = movement,
            settings = settings,
        )
        val usefulMovement = median >= settings.movementMinMedianDisplacementPx
            || rotation >= settings.movementMinRotationDeg

        if (!usefulMovement) {
            return if (baseReason in HOLD_REASONS) {
                holdForBase(baseReason, movementProgress)
            } else {
                reject(
                    reason = "move_camera",
                    guidance = "Плавно перемещайте камеру",
                    phase = AutoPhotoGuidancePhase.MOVE,
                    movementProgressPercent = movementProgress,
                )
            }
        }

        return if (baseReason == "accepted") {
            accept(
                reason = "accepted_movement",
                guidance = "Достаточное перемещение — держите телефон",
                commitReference = true,
                movementProgressPercent = 100,
            )
        } else {
            holdForBase(baseReason, 100)
        }
    }

    private fun recoveryGuidance(movement: AutoPhotoMovementResult): String {
        val dx = movement.medianFlowDxPx
        val dy = movement.medianFlowDyPx
        if (dx == null || dy == null || max(abs(dx), abs(dy)) < 2.0) {
            return "Перекрытие потеряно — совместите текущий вид с ghost"
        }
        return if (abs(dx) >= abs(dy)) {
            if (dx > 0.0) {
                "Совместите кадр: верните изображение влево ←"
            } else {
                "Совместите кадр: верните изображение вправо →"
            }
        } else {
            if (dy > 0.0) {
                "Совместите кадр: верните изображение вверх ↑"
            } else {
                "Совместите кадр: верните изображение вниз ↓"
            }
        }
    }

    private fun movementProgressPercent(
        movement: AutoPhotoMovementResult,
        settings: AutoPhotoSettings,
    ): Int {
        val displacementProgress = (movement.medianDisplacementPx ?: 0.0) /
            settings.movementMinMedianDisplacementPx.coerceAtLeast(0.1)
        val rotationProgress = abs(movement.estimatedRotationDeg ?: 0.0) /
            settings.movementMinRotationDeg.coerceAtLeast(0.1)
        return (max(displacementProgress, rotationProgress) * 100.0)
            .toInt()
            .coerceIn(0, 100)
    }

    private fun fallbackOrRecover(
        reason: String,
        guidance: String,
        elapsedMs: Long,
        settings: AutoPhotoSettings,
    ): AutoPhotoMovementCaptureDecision {
        if (
            settings.movementFallbackEnabled
            && elapsedMs >= settings.movementMaxCaptureIntervalMs
        ) {
            return accept(
                reason = "accepted_fallback",
                guidance = "Диагностический кадр без смены reference",
                fallback = true,
                commitReference = false,
            )
        }
        return recover(reason, guidance)
    }

    private fun terminalDecision(
        baseReason: String,
    ): AutoPhotoMovementCaptureDecision? = when (baseReason) {
        "max_photos_reached" -> reject(
            reason = baseReason,
            guidance = guidanceFor(baseReason),
            phase = AutoPhotoGuidancePhase.COMPLETE,
        )
        "storage_reserve", "camera_not_ready" -> reject(
            reason = baseReason,
            guidance = guidanceFor(baseReason),
            phase = AutoPhotoGuidancePhase.ERROR,
        )
        else -> null
    }

    private fun holdForBase(
        reason: String,
        movementProgressPercent: Int = 100,
    ): AutoPhotoMovementCaptureDecision = reject(
        reason = reason,
        guidance = guidanceFor(reason),
        phase = AutoPhotoGuidancePhase.HOLD,
        movementProgressPercent = movementProgressPercent,
    )

    private fun recover(
        reason: String,
        guidance: String,
    ): AutoPhotoMovementCaptureDecision = reject(
        reason = reason,
        guidance = guidance,
        phase = AutoPhotoGuidancePhase.RECOVER,
    )

    private fun accept(
        reason: String,
        guidance: String,
        fallback: Boolean = false,
        commitReference: Boolean = false,
        movementProgressPercent: Int = 100,
    ) = AutoPhotoMovementCaptureDecision(
        shouldCapture = true,
        reason = reason,
        guidance = guidance,
        phase = AutoPhotoGuidancePhase.HOLD,
        movementProgressPercent = movementProgressPercent,
        fallback = fallback,
        commitReference = commitReference,
    )

    private fun reject(
        reason: String,
        guidance: String,
        phase: AutoPhotoGuidancePhase,
        movementProgressPercent: Int = 0,
    ) = AutoPhotoMovementCaptureDecision(
        shouldCapture = false,
        reason = reason,
        guidance = guidance,
        phase = phase,
        movementProgressPercent = movementProgressPercent,
    )

    private val HOLD_REASONS = setOf(
        "motion_too_high",
        "not_stable_long_enough",
        "minimum_interval",
        "too_blurry",
        "capture_in_progress",
    )
}
