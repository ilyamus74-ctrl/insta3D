package com.maklertour.data.dualphone

import org.json.JSONObject
import java.security.SecureRandom
import java.util.UUID
import java.util.concurrent.atomic.AtomicLong

internal object DualPhoneControlProtocol {
    const val VERSION = 1

    private val sequence = AtomicLong(1)
    private val random = SecureRandom()

    fun pairingCode(): String = "%06d".format(random.nextInt(1_000_000))

    fun dualCaptureId(): String = UUID.randomUUID().toString()

    fun calibrationRunId(): String = "cal-${UUID.randomUUID()}"

    fun commandId(prefix: String): String =
        "${prefix.trim().ifBlank { "command" }}-${UUID.randomUUID()}"

    fun message(
        type: String,
        payload: JSONObject = JSONObject(),
    ): JSONObject = JSONObject()
        .put("protocol_version", VERSION)
        .put("sequence", sequence.getAndIncrement())
        .put("type", type)
        .put("payload", payload)

    fun encode(message: JSONObject): String = message.toString()

    fun decode(line: String): JSONObject {
        val message = JSONObject(line)
        require(message.optInt("protocol_version", -1) == VERSION) {
            "Unsupported dual-phone protocol version"
        }
        require(message.optString("type").isNotBlank()) {
            "Dual-phone message type is missing"
        }
        if (!message.has("payload") || message.isNull("payload")) {
            message.put("payload", JSONObject())
        }
        return message
    }
}

internal object DualPhoneControlType {
    const val HELLO = "HELLO"
    const val WELCOME = "WELCOME"
    const val CAPABILITIES = "CAPABILITIES"
    const val CLOCK_SYNC_STATUS = "CLOCK_SYNC_STATUS"
    const val PING = "PING"
    const val PONG = "PONG"
    const val ENTER_CALIBRATION = "ENTER_CALIBRATION"
    const val ENTER_CALIBRATION_ACK = "ENTER_CALIBRATION_ACK"
    const val CALIBRATION_OBSERVATION = "CALIBRATION_OBSERVATION"
    const val CALIBRATION_STATE = "CALIBRATION_STATE"
    const val CALIBRATION_INTRINSICS = "CALIBRATION_INTRINSICS"
    const val CALIBRATION_RESULT = "CALIBRATION_RESULT"
    const val EXIT_CALIBRATION_REQUEST = "EXIT_CALIBRATION_REQUEST"
    const val EXIT_CALIBRATION = "EXIT_CALIBRATION"
    const val ARM = "ARM"
    const val ARM_ACK = "ARM_ACK"
    const val START_AT = "START_AT"
    const val START_ACK = "START_ACK"
    const val CAPTURE_STARTED = "CAPTURE_STARTED"
    const val STOP = "STOP"
    const val STOP_ACK = "STOP_ACK"
    const val PACKAGE_RECEIVED = "PACKAGE_RECEIVED"
    const val ERROR = "ERROR"
}
