package com.maklertour.data.dualphone

import org.json.JSONObject
import java.util.ArrayDeque
import kotlin.math.abs

internal data class DualPhoneManualStereoCaptureRequest(
    val requestId: String,
    val calibrationRunId: String,
    val targetMasterElapsedRealtimeNs: Long,
    val targetSlaveElapsedRealtimeNs: Long,
    val expiresMasterElapsedRealtimeNs: Long,
    val expiresSlaveElapsedRealtimeNs: Long,
) {
    fun targetFor(role: DualPhoneRole): Long = when (role) {
        DualPhoneRole.SLAVE -> targetSlaveElapsedRealtimeNs
        else -> targetMasterElapsedRealtimeNs
    }

    fun expiresFor(role: DualPhoneRole): Long = when (role) {
        DualPhoneRole.SLAVE -> expiresSlaveElapsedRealtimeNs
        else -> expiresMasterElapsedRealtimeNs
    }

    fun tag(
        observation: DualPhoneCalibrationObservation,
        role: DualPhoneRole,
    ): DualPhoneCalibrationObservation {
        val captureNs = observation.captureElapsedRealtimeNs
        val targetNs = targetFor(role)
        val expiresNs = expiresFor(role)
        return if (captureNs in targetNs..expiresNs) {
            observation.copy(
                captureRequestId = requestId,
                captureTargetElapsedRealtimeNs = targetNs,
            )
        } else {
            observation.copy(
                captureRequestId = null,
                captureTargetElapsedRealtimeNs = null,
            )
        }
    }

    fun toJson(): JSONObject = JSONObject()
        .put("calibration_run_id", calibrationRunId)
        .put("capture_request_id", requestId)
        .put("target_master_elapsed_realtime_ns", targetMasterElapsedRealtimeNs)
        .put("target_slave_elapsed_realtime_ns", targetSlaveElapsedRealtimeNs)
        .put("expires_master_elapsed_realtime_ns", expiresMasterElapsedRealtimeNs)
        .put("expires_slave_elapsed_realtime_ns", expiresSlaveElapsedRealtimeNs)

    companion object {
        fun fromJson(json: JSONObject): DualPhoneManualStereoCaptureRequest? {
            val requestId = json.optString("capture_request_id").trim()
            val runId = json.optString("calibration_run_id").trim()
            val targetMaster = json.optLong("target_master_elapsed_realtime_ns", 0L)
            val targetSlave = json.optLong("target_slave_elapsed_realtime_ns", 0L)
            val expiresMaster = json.optLong("expires_master_elapsed_realtime_ns", 0L)
            val expiresSlave = json.optLong("expires_slave_elapsed_realtime_ns", 0L)
            if (
                requestId.isBlank() ||
                runId.isBlank() ||
                targetMaster <= 0L ||
                targetSlave <= 0L ||
                expiresMaster <= targetMaster ||
                expiresSlave <= targetSlave
            ) {
                return null
            }
            return DualPhoneManualStereoCaptureRequest(
                requestId = requestId,
                calibrationRunId = runId,
                targetMasterElapsedRealtimeNs = targetMaster,
                targetSlaveElapsedRealtimeNs = targetSlave,
                expiresMasterElapsedRealtimeNs = expiresMaster,
                expiresSlaveElapsedRealtimeNs = expiresSlave,
            )
        }
    }
}

internal data class DualPhoneStereoPairSelection(
    val master: DualPhoneCalibrationObservation,
    val slave: DualPhoneCalibrationObservation,
    val deltaMs: Double,
    val commonCorners: Int,
    val timestampSources: String,
    val usedCaptureTimeline: Boolean,
    val bufferAgeMs: Long,
    val masterCandidateCount: Int,
    val slaveCandidateCount: Int,
)

internal class DualPhoneStereoObservationBuffer(
    private val capacityPerSide: Int = 96,
) {
    private data class BufferedObservation(
        val observation: DualPhoneCalibrationObservation,
        val receivedAtMasterElapsedMs: Long,
    )

    private val master = ArrayDeque<BufferedObservation>()
    private val slave = ArrayDeque<BufferedObservation>()

    fun clear() {
        master.clear()
        slave.clear()
    }

    fun addMaster(
        observation: DualPhoneCalibrationObservation,
        receivedAtMasterElapsedMs: Long,
    ) {
        add(master, observation, receivedAtMasterElapsedMs)
    }

    fun addSlave(
        observation: DualPhoneCalibrationObservation,
        receivedAtMasterElapsedMs: Long,
    ) {
        add(slave, observation, receivedAtMasterElapsedMs)
    }

    fun bestPair(
        calibrationRunId: String,
        poseId: String,
        mode: DualPhoneCalibrationMode,
        manualRequest: DualPhoneManualStereoCaptureRequest?,
        masterToSlaveNs: ((Long) -> Long?)?,
        nowMasterElapsedMs: Long,
    ): DualPhoneStereoPairSelection? {
        trimExpired(nowMasterElapsedMs)
        val masterCandidates = master.filter { entry ->
            entry.matches(calibrationRunId, poseId) &&
                entry.observation.stableMs >= MIN_STABLE_MS &&
                manualMatch(
                    observation = entry.observation,
                    role = DualPhoneRole.MASTER,
                    mode = mode,
                    request = manualRequest,
                )
        }
        val slaveCandidates = slave.filter { entry ->
            entry.matches(calibrationRunId, poseId) &&
                entry.observation.stableMs >= MIN_STABLE_MS &&
                manualMatch(
                    observation = entry.observation,
                    role = DualPhoneRole.SLAVE,
                    mode = mode,
                    request = manualRequest,
                )
        }
        val oldestCandidateReceivedAtMs = (masterCandidates + slaveCandidates)
            .minOfOrNull { it.receivedAtMasterElapsedMs }
        val bufferAgeMs = oldestCandidateReceivedAtMs
            ?.let { (nowMasterElapsedMs - it).coerceAtLeast(0L) }
            ?: 0L

        var best: DualPhoneStereoPairSelection? = null
        for (masterEntry in masterCandidates) {
            for (slaveEntry in slaveCandidates) {
                val masterObservation = masterEntry.observation
                val slaveObservation = slaveEntry.observation
                val commonCorners = masterObservation.charucoCorners
                    .map { it.id }
                    .toSet()
                    .intersect(slaveObservation.charucoCorners.map { it.id }.toSet())
                    .size
                if (commonCorners < MIN_COMMON_CORNERS) continue

                val mappedSlaveCaptureNs = if (
                    mode != DualPhoneCalibrationMode.MANUAL_STEREO &&
                    masterObservation.captureElapsedRealtimeNs > 0L &&
                    slaveObservation.captureElapsedRealtimeNs > 0L
                ) {
                    masterToSlaveNs?.invoke(
                        masterObservation.captureElapsedRealtimeNs,
                    )
                } else {
                    null
                }
                val delta = if (
                    mode == DualPhoneCalibrationMode.MANUAL_STEREO &&
                    manualRequest != null
                ) {
                    targetRelativeDeltaMs(
                        masterObservation = masterObservation,
                        slaveObservation = slaveObservation,
                        request = manualRequest,
                    )
                } else if (mappedSlaveCaptureNs != null) {
                    abs(
                        slaveObservation.captureElapsedRealtimeNs -
                            mappedSlaveCaptureNs,
                    ) / 1_000_000.0
                } else {
                    abs(
                        masterEntry.receivedAtMasterElapsedMs -
                            slaveEntry.receivedAtMasterElapsedMs,
                    ).toDouble()
                }

                val candidate = DualPhoneStereoPairSelection(
                    master = masterObservation,
                    slave = slaveObservation,
                    deltaMs = delta,
                    commonCorners = commonCorners,
                    timestampSources =
                        "${masterObservation.timestampSource}/" +
                            slaveObservation.timestampSource,
                    usedCaptureTimeline =
                        mode == DualPhoneCalibrationMode.MANUAL_STEREO ||
                            mappedSlaveCaptureNs != null,
                    bufferAgeMs = bufferAgeMs,
                    masterCandidateCount = masterCandidates.size,
                    slaveCandidateCount = slaveCandidates.size,
                )
                if (
                    best == null ||
                    candidate.deltaMs < requireNotNull(best).deltaMs ||
                    (
                        candidate.deltaMs == requireNotNull(best).deltaMs &&
                            candidate.commonCorners > requireNotNull(best).commonCorners
                        )
                ) {
                    best = candidate
                }
            }
        }
        return best
    }

    private fun add(
        buffer: ArrayDeque<BufferedObservation>,
        observation: DualPhoneCalibrationObservation,
        receivedAtMasterElapsedMs: Long,
    ) {
        if (buffer.lastOrNull()?.observation?.frameSequence == observation.frameSequence) {
            return
        }
        buffer.addLast(
            BufferedObservation(
                observation = observation,
                receivedAtMasterElapsedMs = receivedAtMasterElapsedMs,
            ),
        )
        while (buffer.size > capacityPerSide) buffer.removeFirst()
    }

    private fun trimExpired(nowMasterElapsedMs: Long) {
        while (
            master.firstOrNull()?.receivedAtMasterElapsedMs?.let {
                nowMasterElapsedMs - it > MAX_BUFFER_AGE_MS
            } == true
        ) {
            master.removeFirst()
        }
        while (
            slave.firstOrNull()?.receivedAtMasterElapsedMs?.let {
                nowMasterElapsedMs - it > MAX_BUFFER_AGE_MS
            } == true
        ) {
            slave.removeFirst()
        }
    }

    private fun BufferedObservation.matches(
        calibrationRunId: String,
        poseId: String,
    ): Boolean =
        observation.calibrationRunId == calibrationRunId &&
            observation.calibrationStage ==
            DualPhoneCalibrationStage.STEREO_EXTRINSICS &&
            observation.poseId == poseId &&
            observation.qualityReady

    private fun manualMatch(
        observation: DualPhoneCalibrationObservation,
        role: DualPhoneRole,
        mode: DualPhoneCalibrationMode,
        request: DualPhoneManualStereoCaptureRequest?,
    ): Boolean {
        if (mode != DualPhoneCalibrationMode.MANUAL_STEREO) return true
        val activeRequest = request ?: return false
        if (observation.captureRequestId != activeRequest.requestId) return false
        val captureNs = observation.captureElapsedRealtimeNs
        return captureNs in activeRequest.targetFor(role)..activeRequest.expiresFor(role)
    }

    companion object {
        const val MIN_COMMON_CORNERS = 20
        const val MIN_STABLE_MS = 450L
        private const val MAX_BUFFER_AGE_MS = 4_500L

        fun targetRelativeDeltaMs(
            masterObservation: DualPhoneCalibrationObservation,
            slaveObservation: DualPhoneCalibrationObservation,
            request: DualPhoneManualStereoCaptureRequest,
        ): Double {
            val masterRelativeNs =
                masterObservation.captureElapsedRealtimeNs -
                    request.targetMasterElapsedRealtimeNs
            val slaveRelativeNs =
                slaveObservation.captureElapsedRealtimeNs -
                    request.targetSlaveElapsedRealtimeNs
            return abs(masterRelativeNs - slaveRelativeNs) / 1_000_000.0
        }
    }
}
