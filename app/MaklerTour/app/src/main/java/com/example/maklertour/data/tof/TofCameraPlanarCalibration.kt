package com.maklertour.data.tof

import kotlin.math.abs
import kotlin.math.sqrt

/**
 * Plane in CAMERA_A optical coordinates:
 *
 * normal . P_camera_mm + d_mm = 0
 */
data class TofCameraBoardPlane(
    val normalX: Double,
    val normalY: Double,
    val normalZ: Double,
    val dMm: Double,
    val charucoCornersUsed: Int,
) {
    val structurallyValid: Boolean
        get() {
            if (charucoCornersUsed < 4) return false
            if (!listOf(normalX, normalY, normalZ, dMm).all { it.isFinite() }) {
                return false
            }
            val norm = sqrt(
                normalX * normalX +
                    normalY * normalY +
                    normalZ * normalZ,
            )
            return norm in 0.999..1.001
        }

    fun signedDistanceMm(
        cameraXmm: Double,
        cameraYmm: Double,
        cameraZmm: Double,
    ): Double =
        normalX * cameraXmm +
            normalY * cameraYmm +
            normalZ * cameraZmm +
            dMm
}

data class TofZoneRangeObservation(
    val zoneIndex: Int,
    val distanceMm: Int,
    val sigmaMm: Int,
    val targetStatus: Int,
    val nbTargetDetected: Int,
)

data class TofCameraPlanarCalibrationSample(
    val cameraElapsedRealtimeNs: Long,
    val tofMappedElapsedRealtimeNs: Long,
    val tofSequence: Long,
    val pairDeltaUs: Long,
    val pairThresholdUs: Long,
    val tofWidth: Int,
    val tofHeight: Int,
    val boardPlane: TofCameraBoardPlane,
    val zones: List<TofZoneRangeObservation>,
) {
    val validZoneCount: Int
        get() = zones.size

    val structurallyValid: Boolean
        get() =
            cameraElapsedRealtimeNs > 0L &&
                tofMappedElapsedRealtimeNs > 0L &&
                pairThresholdUs > 0L &&
                abs(pairDeltaUs) <= pairThresholdUs &&
                tofWidth > 0 &&
                tofHeight > 0 &&
                boardPlane.structurallyValid &&
                zones.isNotEmpty()
}

object TofCameraPlanarCalibrationSampleBuilder {
    fun fromAcceptedPair(
        cameraElapsedRealtimeNs: Long,
        boardPlane: TofCameraBoardPlane,
        pair: TofCameraFramePair,
    ): TofCameraPlanarCalibrationSample? {
        if (!pair.accepted || !boardPlane.structurallyValid) return null

        val frame = pair.frame
        val zones = buildList {
            for (index in 0 until frame.zoneCount) {
                if (!frame.isZoneValid(index)) continue
                add(
                    TofZoneRangeObservation(
                        zoneIndex = index,
                        distanceMm = frame.distanceMm[index],
                        sigmaMm = frame.rangeSigmaMm[index],
                        targetStatus = frame.targetStatus[index],
                        nbTargetDetected = frame.nbTargetDetected[index],
                    ),
                )
            }
        }
        if (zones.isEmpty()) return null

        return TofCameraPlanarCalibrationSample(
            cameraElapsedRealtimeNs = cameraElapsedRealtimeNs,
            tofMappedElapsedRealtimeNs = pair.mappedElapsedRealtimeNs,
            tofSequence = pair.sequence,
            pairDeltaUs = pair.signedDeltaUs,
            pairThresholdUs = pair.thresholdUs,
            tofWidth = frame.width,
            tofHeight = frame.height,
            boardPlane = boardPlane,
            zones = zones,
        )
    }
}
