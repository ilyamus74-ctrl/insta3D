import com.maklertour.data.dualphone.DualPhoneClockSyncMath
import com.maklertour.data.dualphone.DualPhoneClockSyncQuality
import com.maklertour.data.dualphone.DualPhoneClockSyncSample

private fun check(value: Boolean, message: String) {
    if (!value) error(message)
}

private fun burst(
    baseMasterNs: Long,
    trueOffsetNs: Long,
): List<DualPhoneClockSyncSample> = (0 until 12).map { index ->
    val t1 = baseMasterNs + index * 80_000_000L
    val forward = 800_000L + index * 20_000L
    val reverse = 1_000_000L + (index % 3) * 30_000L
    val processing = 50_000L
    val t2 = t1 + forward + trueOffsetNs
    val t3 = t2 + processing
    val t4 = t3 - trueOffsetNs + reverse
    DualPhoneClockSyncSample(t1, t2, t3, t4)
}

fun main() {
    val round1 = DualPhoneClockSyncMath.estimateRound(
        burst(10_000_000_000L, 5_000_000L),
        totalProbes = 12,
    ) ?: error("round1 missing")
    check(round1.acceptedSamples >= 5, "accepted sample count")
    check(round1.offsetNs in 4_700_000L..5_100_000L, "offset estimate")
    check(round1.medianRttNs < 3_000_000L, "RTT estimate")

    val round2 = DualPhoneClockSyncMath.estimateRound(
        burst(20_000_000_000L, 5_200_000L),
        totalProbes = 12,
    ) ?: error("round2 missing")
    val model = DualPhoneClockSyncMath.buildModel(listOf(round1, round2))
        ?: error("model missing")
    check(model.quality in setOf(
        DualPhoneClockSyncQuality.EXCELLENT,
        DualPhoneClockSyncQuality.GOOD,
    ), "quality must be ready")
    check(model.driftPpm in 15.0..25.0, "drift estimate")

    val targetMaster = 25_000_000_000L
    val targetSlave = model.masterToSlaveNs(targetMaster)
    val predictedOffset = targetSlave - targetMaster
    check(predictedOffset in 5_150_000L..5_260_000L, "future offset")
    println("OK")
}
