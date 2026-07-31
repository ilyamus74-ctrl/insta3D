package com.example.maklertour.data.dualphone

enum class DualPhoneLiveStreamState {
    DISABLED,
    PREPARING,
    READY,
    STREAMING,
    STOPPING,
    STOPPED,
    DEGRADED,
    FAILED,
    RECONNECTING,
}

enum class DualPhoneLiveStreamMode {
    SYNC_VIDEO,
    LIVE_METRIC,
    HYBRID;

    val streamEnabled: Boolean
        get() = this != SYNC_VIDEO
}

enum class DualPhoneLiveStreamEncoding {
    JPEG,
}
