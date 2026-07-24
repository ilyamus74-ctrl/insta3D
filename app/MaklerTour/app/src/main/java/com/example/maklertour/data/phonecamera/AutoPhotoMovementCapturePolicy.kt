package com.maklertour.data.phonecamera

import kotlin.math.abs

data class AutoPhotoMovementCaptureDecision(
    val shouldCapture: Boolean,
    val reason: String,
    val guidance: String,
    val fallback: Boolean = false,
) {
    fun toMetadataMap(): Map<String, Any> = linkedMapOf(
        "should_capture" to shouldCapture,
        "reason" to reason,
        "guidance" to guidance,
        "fallback" to fallback,
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
        if (baseReason != "accepted") {
            return reject(baseReason)
        }

        if (
            !settings.movementCaptureEnabled
            || !settings.visualMovementMetricsEnabled
        ) {
            return accept(
                reason = "accepted_timer",
                guidance = "Фото сохранено по таймеру",
            )
        }

        if (savedCount == 0) {
            return accept(
                reason = "accepted_first_reference",
                guidance = "Первый опорный кадр сохранён",
            )
        }

        val elapsedMs = (nowMs - lastCaptureMs).coerceAtLeast(0L)
        val fallbackDue = elapsedMs >= settings.movementMaxCaptureIntervalMs

        return when (movement.status) {
            AutoPhotoMovementStatus.NO_REFERENCE -> {
                if (fallbackDue) fallback()
                else reject(
                    reason = "movement_reference_missing",
                    guidance = "Ожидание опорного кадра",
                )
            }

            AutoPhotoMovementStatus.DISABLED -> {
                if (fallbackDue) fallback()
                else reject(
                    reason = "movement_unavailable",
                    guidance = "Оценка движения недоступна",
                )
            }

            AutoPhotoMovementStatus.INSUFFICIENT_FEATURES -> {
                if (fallbackDue) fallback()
                else reject(
                    reason = "movement_features_low",
                    guidance = "Наведите камеру на участок с деталями",
                )
            }

            AutoPhotoMovementStatus.TRACKING_FAILED -> {
                if (fallbackDue) fallback()
                else reject(
                    reason = "movement_tracking_failed",
                    guidance = "Двигайтесь медленнее и держите перекрытие",
                )
            }

            AutoPhotoMovementStatus.OK -> decideTracked(
                movement = movement,
                fallbackDue = fallbackDue,
                settings = settings,
            )
        }
    }

    fun guidanceFor(reason: String): String = when (reason) {
        "accepted_first_reference" -> "Первый опорный кадр сохранён"
        "accepted_movement" -> "Фото сохранено — продолжайте плавно"
        "accepted_fallback" -> "Фото сохранено по максимальному интервалу"
        "accepted_timer" -> "Фото сохранено по таймеру"
        "move_camera" -> "Переместите камеру"
        "overlap_too_low" -> "Вернитесь немного назад"
        "movement_features_low" -> "Наведите камеру на участок с деталями"
        "movement_tracking_failed" -> "Двигайтесь медленнее и держите перекрытие"
        "movement_reference_missing" -> "Ожидание опорного кадра"
        "movement_unavailable" -> "Оценка движения недоступна"
        "motion_too_high" -> "Держите телефон спокойнее"
        "not_stable_long_enough" -> "Коротко зафиксируйте телефон"
        "minimum_interval" -> "Плавно продолжайте движение"
        "too_blurry" -> "Кадр размыт — двигайтесь медленнее"
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
        movement: AutoPhotoMovementResult,
        fallbackDue: Boolean,
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
            return reject(
                reason = "overlap_too_low",
                guidance = "Вернитесь немного назад",
            )
        }

        if (
            median < settings.movementMinMedianDisplacementPx
            && rotation < settings.movementMinRotationDeg
        ) {
            return if (fallbackDue) {
                fallback()
            } else {
                reject(
                    reason = "move_camera",
                    guidance = "Переместите камеру",
                )
            }
        }

        return accept(
            reason = "accepted_movement",
            guidance = "Фото сохранено — продолжайте плавно",
        )
    }

    private fun fallback(): AutoPhotoMovementCaptureDecision = accept(
        reason = "accepted_fallback",
        guidance = "Фото сохранено по максимальному интервалу",
        fallback = true,
    )

    private fun accept(
        reason: String,
        guidance: String,
        fallback: Boolean = false,
    ) = AutoPhotoMovementCaptureDecision(
        shouldCapture = true,
        reason = reason,
        guidance = guidance,
        fallback = fallback,
    )

    private fun reject(
        reason: String,
        guidance: String = guidanceFor(reason),
    ) = AutoPhotoMovementCaptureDecision(
        shouldCapture = false,
        reason = reason,
        guidance = guidance,
    )
}
