import com.maklertour.data.phonecamera.PhoneVideoModePolicy
import com.maklertour.data.phonecamera.PhoneVideoSizeCapability

private fun check(condition: Boolean, message: String) {
    if (!condition) error(message)
}

fun main() {
    val modes = PhoneVideoModePolicy.availableModes(
        sizeCapabilities = listOf(
            PhoneVideoSizeCapability(3840, 2160, 30),
            PhoneVideoSizeCapability(1920, 1080, 60),
            PhoneVideoSizeCapability(1280, 720, 60),
            PhoneVideoSizeCapability(640, 480, 120),
        ),
        supportedFpsRanges = listOf(15..30, 30..60),
    )

    check(modes.any { it.id == "1920x1080@60" }, "FHD60 available")
    check(modes.any { it.id == "1280x720@60" }, "HD60 available")
    check(modes.any { it.id == "3840x2160@30" }, "UHD30 available")
    check(
        modes.none { it.id == "3840x2160@60" },
        "UHD60 hidden when per-size max FPS is 30",
    )
    check(
        PhoneVideoModePolicy.defaultMode(modes)?.id == "1280x720@30",
        "compatibility default remains HD30",
    )

    val no60 = PhoneVideoModePolicy.availableModes(
        sizeCapabilities = listOf(
            PhoneVideoSizeCapability(1920, 1080, 30),
            PhoneVideoSizeCapability(1280, 720, 30),
        ),
        supportedFpsRanges = listOf(15..60),
    )
    check(no60.none { it.fps == 60 }, "60 FPS hidden per resolution")

    val onlyFhd = PhoneVideoModePolicy.availableModes(
        sizeCapabilities = listOf(
            PhoneVideoSizeCapability(1920, 1080, 30),
        ),
        supportedFpsRanges = listOf(30..30),
    )
    check(
        PhoneVideoModePolicy.defaultMode(onlyFhd)?.id == "1920x1080@30",
        "FHD30 fallback",
    )

    check(
        PhoneVideoModePolicy.qualityKeyFor(3840, 2160) == "UHD",
        "UHD mapping",
    )
    check(
        PhoneVideoModePolicy.qualityKeyFor(1920, 1080) == "FHD",
        "FHD mapping",
    )
    check(
        PhoneVideoModePolicy.qualityKeyFor(1280, 720) == "HD",
        "HD mapping",
    )

    println("OK")
}
