import com.maklertour.data.dualphone.DualPhoneClockSyncMath
import com.maklertour.data.dualphone.DualPhoneClockSyncQuality
import com.maklertour.data.dualphone.DualPhoneClockSyncSample

private fun check(value: Boolean, message: String) {
    if (!value) error(message)
}

private fun burst(
    baseMasterNs: Long,
    trueOffsetNs: Long,
    count: Int = 12,
): List<DualPhoneClockSyncSample> = (0 until count).map { index ->
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
    val periodicEight = DualPhoneClockSyncMath.estimateRound(
        burst(1_000_000_000L, 5_000_000L, count = 8),
        totalProbes = 8,
    ) ?: error("periodic eight-probe round missing")
    check(periodicEight.acceptedSamples == 4, "8 probes must retain four samples")
    check(periodicEight.validSamples == 8, "8-probe valid sample count")
    check(periodicEight.acceptedRttNs.size == 4, "accepted RTT diagnostics")
    check(periodicEight.rejectedRttNs.size == 4, "rejected RTT diagnostics")
    val periodicEightModel = DualPhoneClockSyncMath.buildModel(
        listOf(periodicEight),
    ) ?: error("periodic eight-probe model missing")
    check(
        periodicEightModel.quality == DualPhoneClockSyncQuality.FAIR,
        "8 probes cannot satisfy GOOD accepted-sample gate",
    )

    val periodicTwelve = DualPhoneClockSyncMath.estimateRound(
        burst(2_000_000_000L, 5_000_000L, count = 12),
        totalProbes = 12,
    ) ?: error("periodic twelve-probe round missing")
    check(periodicTwelve.acceptedSamples == 6, "12 probes must retain six samples")
    val periodicTwelveModel = DualPhoneClockSyncMath.buildModel(
        listOf(periodicTwelve),
    ) ?: error("periodic twelve-probe model missing")
    check(periodicTwelveModel.quality.isReady, "12 probes must allow ready quality")

    val degraded = periodicTwelveModel.copy(
        quality = DualPhoneClockSyncQuality.FAIR,
        medianRttNs = 10_000_000L,
        uncertaintyNs = 5_000_000L,
    )
    val heldOnce = DualPhoneClockSyncMath.stabilizeModel(
        previous = periodicTwelveModel,
        candidate = degraded,
        consecutiveNonReadyRounds = 0,
    )
    check(heldOnce.retainedReadyQuality, "first FAIR round must retain readiness")
    check(heldOnce.model.quality.isReady, "first FAIR round must remain ready")
    check(heldOnce.consecutiveNonReadyRounds == 1, "first FAIR streak")

    val heldTwice = DualPhoneClockSyncMath.stabilizeModel(
        previous = heldOnce.model,
        candidate = degraded,
        consecutiveNonReadyRounds = heldOnce.consecutiveNonReadyRounds,
    )
    check(heldTwice.retainedReadyQuality, "second FAIR round must retain readiness")
    check(heldTwice.consecutiveNonReadyRounds == 2, "second FAIR streak")

    val dropped = DualPhoneClockSyncMath.stabilizeModel(
        previous = heldTwice.model,
        candidate = degraded,
        consecutiveNonReadyRounds = heldTwice.consecutiveNonReadyRounds,
    )
    check(!dropped.retainedReadyQuality, "third FAIR round must be applied")
    check(dropped.model.quality == DualPhoneClockSyncQuality.FAIR, "third FAIR quality")
    check(dropped.consecutiveNonReadyRounds == 0, "streak resets after downgrade")

    val recovered = DualPhoneClockSyncMath.stabilizeModel(
        previous = heldTwice.model,
        candidate = periodicTwelveModel,
        consecutiveNonReadyRounds = heldTwice.consecutiveNonReadyRounds,
    )
    check(!recovered.retainedReadyQuality, "ready recovery is applied immediately")
    check(recovered.consecutiveNonReadyRounds == 0, "ready recovery resets streak")

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
