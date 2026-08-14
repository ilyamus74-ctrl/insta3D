package com.maklertour.data.tof

import org.json.JSONArray
import org.json.JSONObject
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

    fun toJson(): JSONObject = JSONObject()
        .put("normal_x", normalX)
        .put("normal_y", normalY)
        .put("normal_z", normalZ)
        .put("d_mm", dMm)
        .put("charuco_corners_used", charucoCornersUsed)

    companion object {
        fun fromJson(json: JSONObject): TofCameraBoardPlane? =
            TofCameraBoardPlane(
                normalX = json.optDouble("normal_x", Double.NaN),
                normalY = json.optDouble("normal_y", Double.NaN),
                normalZ = json.optDouble("normal_z", Double.NaN),
                dMm = json.optDouble("d_mm", Double.NaN),
                charucoCornersUsed = json.optInt("charuco_corners_used", 0),
            ).takeIf { it.structurallyValid }
    }
}

data class TofZoneRangeObservation(
    val zoneIndex: Int,
    val distanceMm: Int,
    val sigmaMm: Int,
    val targetStatus: Int,
    val nbTargetDetected: Int,
) {
    fun toJson(): JSONObject = JSONObject()
        .put("zone_index", zoneIndex)
        .put("distance_mm", distanceMm)
        .put("sigma_mm", sigmaMm)
        .put("target_status", targetStatus)
        .put("nb_target_detected", nbTargetDetected)

    companion object {
        fun fromJson(json: JSONObject): TofZoneRangeObservation? {
            val zoneIndex = json.optInt("zone_index", -1)
            val distanceMm = json.optInt("distance_mm", 0)
            if (zoneIndex < 0 || distanceMm <= 0) return null
            return TofZoneRangeObservation(
                zoneIndex = zoneIndex,
                distanceMm = distanceMm,
                sigmaMm = json.optInt("sigma_mm", 0),
                targetStatus = json.optInt("target_status", 0),
                nbTargetDetected = json.optInt("nb_target_detected", 0),
            )
        }
    }
}

data class TofCameraPlanarCalibrationSample(
    val cameraElapsedRealtimeNs: Long,
    val tofMappedElapsedRealtimeNs: Long,
    val tofSequence: Long,
    val pairDeltaUs: Long,
    val pairThresholdUs: Long,
    val tofSlot: Int = 0,
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
                tofSlot >= 0 &&
                tofWidth > 0 &&
                tofHeight > 0 &&
                boardPlane.structurallyValid &&
                zones.isNotEmpty() &&
                zones.all { it.zoneIndex in 0 until (tofWidth * tofHeight) }

    fun toJson(): JSONObject = JSONObject()
        .put("schema_version", SCHEMA_VERSION)
        .put("camera_elapsed_realtime_ns", cameraElapsedRealtimeNs)
        .put("tof_mapped_elapsed_realtime_ns", tofMappedElapsedRealtimeNs)
        .put("tof_sequence", tofSequence)
        .put("pair_delta_us", pairDeltaUs)
        .put("pair_threshold_us", pairThresholdUs)
        .put("tof_slot", tofSlot)
        .put("tof_width", tofWidth)
        .put("tof_height", tofHeight)
        .put("board_plane", boardPlane.toJson())
        .put(
            "zones",
            JSONArray().also { array ->
                zones.forEach { array.put(it.toJson()) }
            },
        )

    companion object {
        private const val SCHEMA_VERSION = 1

        fun fromJson(json: JSONObject): TofCameraPlanarCalibrationSample? {
            if (json.optInt("schema_version", -1) != SCHEMA_VERSION) return null
            val boardPlane =
                json.optJSONObject("board_plane")
                    ?.let(TofCameraBoardPlane::fromJson)
                    ?: return null
            val zones = buildList {
                val array = json.optJSONArray("zones") ?: JSONArray()
                for (index in 0 until array.length()) {
                    val zone =
                        array.optJSONObject(index)
                            ?.let(TofZoneRangeObservation::fromJson)
                    if (zone != null) add(zone)
                }
            }
            return TofCameraPlanarCalibrationSample(
                cameraElapsedRealtimeNs =
                    json.optLong("camera_elapsed_realtime_ns", 0L),
                tofMappedElapsedRealtimeNs =
                    json.optLong("tof_mapped_elapsed_realtime_ns", 0L),
                tofSequence = json.optLong("tof_sequence", -1L),
                pairDeltaUs = json.optLong("pair_delta_us", Long.MAX_VALUE),
                pairThresholdUs = json.optLong("pair_threshold_us", 0L),
                tofSlot = json.optInt("tof_slot", -1),
                tofWidth = json.optInt("tof_width", 0),
                tofHeight = json.optInt("tof_height", 0),
                boardPlane = boardPlane,
                zones = zones,
            ).takeIf { it.structurallyValid }
        }
    }
}

object TofCameraPlanarCalibrationSampleBuilder {
    const val MIN_CALIBRATION_RANGE_MM = 100

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
                // Calibration board poses are never intended to be in the
                // sensor's near field. Reject cover-glass/obstruction ghosts
                // without changing normal runtime ToF semantics.
                if (frame.distanceMm[index] < MIN_CALIBRATION_RANGE_MM) continue
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
            tofSlot = frame.slot,
            tofWidth = frame.width,
            tofHeight = frame.height,
            boardPlane = boardPlane,
            zones = zones,
        )
    }
}
