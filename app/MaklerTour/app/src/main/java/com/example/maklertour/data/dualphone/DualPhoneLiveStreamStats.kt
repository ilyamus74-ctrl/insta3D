package com.example.maklertour.data.dualphone

data class DualPhoneLiveStreamStats(
    val framesProduced: Long = 0L,
    val framesEncoded: Long = 0L,
    val framesSent: Long = 0L,
    val framesReceived: Long = 0L,
    val framesDecoded: Long = 0L,
    val framesReplacedBeforeSend: Long = 0L,
    val framesDroppedOversize: Long = 0L,
    val framesDroppedDecodeBusy: Long = 0L,
    val bytesSent: Long = 0L,
    val streamFps: Double = 0.0,
    val streamBitrateKbps: Double = 0.0,
    val lastFrameAgeMs: Long? = null,
    val lastNetworkReceiveAgeMs: Long? = null,
    val decodeTimeMs: Double? = null,
    val connectionRestarts: Long = 0L,
    val lastError: String? = null,
)
