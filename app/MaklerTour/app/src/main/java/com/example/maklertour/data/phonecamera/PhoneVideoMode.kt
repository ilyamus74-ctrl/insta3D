package com.maklertour.data.phonecamera

enum class PhoneVideoModeSupport(val wireValue: String) {
    REGULAR("regular"),
    CAMERA2_HIGH_SPEED("camera2_high_speed"),
}

data class PhoneVideoMode(
    val width: Int,
    val height: Int,
    val fps: Int,
    val qualityKey: String,
    val support: PhoneVideoModeSupport = PhoneVideoModeSupport.REGULAR,
) {
    val id: String
        get() = "${width}x${height}@${fps}"

    val label: String
        get() = buildString {
            append("${width}×${height} · ${fps} FPS")
            if (support == PhoneVideoModeSupport.CAMERA2_HIGH_SPEED) {
                append(" · Camera2 HS")
            }
        }
}

data class PhoneVideoSizeCapability(
    val width: Int,
    val height: Int,
    val maxFps: Int,
    val highSpeedFpsRanges: List<IntRange> = emptyList(),
)

object PhoneVideoModePolicy {
    private data class StandardProfile(
        val qualityKey: String,
        val width: Int,
        val height: Int,
    )

    private val profiles = listOf(
        StandardProfile("UHD", 3840, 2160),
        StandardProfile("FHD", 1920, 1080),
        StandardProfile("HD", 1280, 720),
    )

    fun availableModes(
        sizeCapabilities: List<PhoneVideoSizeCapability>,
        supportedFpsRanges: List<IntRange>,
    ): List<PhoneVideoMode> {
        val capabilities = sizeCapabilities.associateBy {
            it.width to it.height
        }

        return profiles.flatMap { profile ->
            val capability = capabilities[
                profile.width to profile.height
            ] ?: return@flatMap emptyList()

            listOf(30, 60).mapNotNull { fps ->
                val regularSupported =
                    capability.maxFps >= fps &&
                        supportedFpsRanges.any { fps in it }
                val highSpeedSupported =
                    fps > 30 &&
                        capability.highSpeedFpsRanges.any { fps in it }

                if (!regularSupported && !highSpeedSupported) {
                    null
                } else {
                    PhoneVideoMode(
                        width = profile.width,
                        height = profile.height,
                        fps = fps,
                        qualityKey = profile.qualityKey,
                        support = if (regularSupported) {
                            PhoneVideoModeSupport.REGULAR
                        } else {
                            PhoneVideoModeSupport.CAMERA2_HIGH_SPEED
                        },
                    )
                }
            }
        }.sortedWith(
            compareByDescending<PhoneVideoMode> { it.width * it.height }
                .thenByDescending { it.fps },
        )
    }

    fun defaultMode(modes: List<PhoneVideoMode>): PhoneVideoMode? =
        modes.firstOrNull {
            it.width == 1280 && it.height == 720 && it.fps == 30
        } ?: modes.firstOrNull {
            it.width == 1920 && it.height == 1080 && it.fps == 30
        } ?: modes.firstOrNull { it.fps == 30 }
            ?: modes.firstOrNull()

    fun qualityKeyFor(width: Int, height: Int): String = when {
        width >= 3840 && height >= 2160 -> "UHD"
        width >= 1920 && height >= 1080 -> "FHD"
        else -> "HD"
    }
}
