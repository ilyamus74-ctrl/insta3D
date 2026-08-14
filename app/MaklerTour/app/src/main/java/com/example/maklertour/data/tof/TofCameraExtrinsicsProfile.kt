package com.maklertour.data.tof

import com.maklertour.data.calibration.DualPhoneLiveIntrinsicsEstimate
import org.json.JSONArray
import org.json.JSONObject
import kotlin.math.sqrt

/**
 * LM03.4 CAMERA_A <-> ToF spatial calibration contract.
 *
 * Transform convention:
 *   P_camera_mm = R_tof_to_camera * P_tof_mm + t_tof_to_camera_mm
 */
data class TofZoneIntrinsics(
    val fxZones: Double,
    val fyZones: Double,
    val cxZones: Double,
    val cyZones: Double,
) {
    val structurallyValid: Boolean
        get() = listOf(fxZones, fyZones, cxZones, cyZones).all { it.isFinite() } &&
            fxZones > 0.0 &&
            fyZones > 0.0

    fun toJson(): JSONObject = JSONObject()
        .put("fx_zones", fxZones)
        .put("fy_zones", fyZones)
        .put("cx_zones", cxZones)
        .put("cy_zones", cyZones)

    companion object {
        fun fromJson(json: JSONObject): TofZoneIntrinsics? {
            val fx = json.optFiniteDouble("fx_zones") ?: return null
            val fy = json.optFiniteDouble("fy_zones") ?: return null
            val cx = json.optFiniteDouble("cx_zones") ?: return null
            val cy = json.optFiniteDouble("cy_zones") ?: return null
            return TofZoneIntrinsics(
                fxZones = fx,
                fyZones = fy,
                cxZones = cx,
                cyZones = cy,
            ).takeIf { it.structurallyValid }
        }
    }
}

data class TofCameraExtrinsicsProfile(
    val rigId: String,
    val rigMountRevision: String,
    val masterDeviceId: String,
    val masterCameraId: String,
    val cameraCalibrationProfileId: String,
    val tofSlot: Int,
    val tofWidth: Int,
    val tofHeight: Int,
    val tofIntrinsics: TofZoneIntrinsics,
    val rotationToCamera: List<Double>,
    val translationToCameraMm: List<Double>,
    val sampleCount: Int,
    val planeRmsMm: Double? = null,
    val imageReprojectionRmsPx: Double? = null,
    val solver: String,
    val createdAtEpochMs: Long,
    val status: String,
) {
    val structurallyValid: Boolean
        get() = rigId.isNotBlank() &&
            rigMountRevision.isNotBlank() &&
            masterDeviceId.isNotBlank() &&
            masterCameraId.isNotBlank() &&
            cameraCalibrationProfileId.isNotBlank() &&
            tofSlot >= 0 &&
            tofWidth > 0 &&
            tofHeight > 0 &&
            tofIntrinsics.structurallyValid &&
            rotationToCamera.size == 9 &&
            rotationToCamera.all { it.isFinite() } &&
            translationToCameraMm.size == 3 &&
            translationToCameraMm.all { it.isFinite() } &&
            sampleCount >= 0 &&
            solver.isNotBlank() &&
            status.isNotBlank()

    val solved: Boolean
        get() = structurallyValid && status == STATUS_SOLVED

    fun matchesRig(
        rigId: String,
        rigMountRevision: String,
        masterDeviceId: String,
        masterCameraId: String,
    ): Boolean =
        structurallyValid &&
            this.rigId == rigId &&
            this.rigMountRevision == rigMountRevision &&
            this.masterDeviceId == masterDeviceId &&
            this.masterCameraId == masterCameraId

    fun toJson(): JSONObject = JSONObject()
        .put("schema_version", SCHEMA_VERSION)
        .put("rig_id", rigId)
        .put("rig_mount_revision", rigMountRevision)
        .put("master_device_id", masterDeviceId)
        .put("master_camera_id", masterCameraId)
        .put("camera_calibration_profile_id", cameraCalibrationProfileId)
        .put("tof_slot", tofSlot)
        .put("tof_width", tofWidth)
        .put("tof_height", tofHeight)
        .put("tof_intrinsics", tofIntrinsics.toJson())
        .put("rotation_tof_to_camera", rotationToCamera.toJsonArray())
        .put("translation_tof_to_camera_mm", translationToCameraMm.toJsonArray())
        .put("sample_count", sampleCount)
        .put("plane_rms_mm", planeRmsMm ?: JSONObject.NULL)
        .put("image_reprojection_rms_px", imageReprojectionRmsPx ?: JSONObject.NULL)
        .put("solver", solver)
        .put("created_at_epoch_ms", createdAtEpochMs)
        .put("status", status)

    companion object {
        const val SCHEMA_VERSION = 1
        const val STATUS_SOLVED = "solved"
        const val STATUS_UNSOLVED = "unsolved"

        fun fromJson(json: JSONObject): TofCameraExtrinsicsProfile? {
            if (json.optInt("schema_version", -1) != SCHEMA_VERSION) return null
            val intrinsics =
                json.optJSONObject("tof_intrinsics")
                    ?.let(TofZoneIntrinsics::fromJson)
                    ?: return null
            return TofCameraExtrinsicsProfile(
                rigId = json.optString("rig_id"),
                rigMountRevision = json.optString("rig_mount_revision"),
                masterDeviceId = json.optString("master_device_id"),
                masterCameraId = json.optString("master_camera_id"),
                cameraCalibrationProfileId =
                    json.optString("camera_calibration_profile_id"),
                tofSlot = json.optInt("tof_slot", -1),
                tofWidth = json.optInt("tof_width", 0),
                tofHeight = json.optInt("tof_height", 0),
                tofIntrinsics = intrinsics,
                rotationToCamera =
                    json.optJSONArray("rotation_tof_to_camera").toDoubleList(),
                translationToCameraMm =
                    json.optJSONArray("translation_tof_to_camera_mm").toDoubleList(),
                sampleCount = json.optInt("sample_count", 0),
                planeRmsMm = json.optFiniteDouble("plane_rms_mm"),
                imageReprojectionRmsPx =
                    json.optFiniteDouble("image_reprojection_rms_px"),
                solver = json.optString("solver"),
                createdAtEpochMs = json.optLong("created_at_epoch_ms", 0L),
                status = json.optString("status"),
            ).takeIf { it.structurallyValid }
        }
    }
}

data class TofCameraProjection(
    val zoneIndex: Int,
    val distanceMm: Int,
    val tofXmm: Double,
    val tofYmm: Double,
    val tofZmm: Double,
    val cameraXmm: Double,
    val cameraYmm: Double,
    val cameraZmm: Double,
    val uPx: Double,
    val vPx: Double,
)

object TofCameraProjector {
    fun projectZoneCenter(
        zoneIndex: Int,
        distanceMm: Int,
        profile: TofCameraExtrinsicsProfile,
        cameraIntrinsics: DualPhoneLiveIntrinsicsEstimate,
    ): TofCameraProjection? {
        if (!profile.solved || distanceMm <= 0) return null
        if (zoneIndex !in 0 until (profile.tofWidth * profile.tofHeight)) return null

        val fx = cameraIntrinsics.fx ?: return null
        val fy = cameraIntrinsics.fy ?: return null
        val cx = cameraIntrinsics.cx ?: return null
        val cy = cameraIntrinsics.cy ?: return null
        val k1 = cameraIntrinsics.k1 ?: return null
        val k2 = cameraIntrinsics.k2 ?: return null
        if (listOf(fx, fy, cx, cy, k1, k2).any { !it.isFinite() }) return null

        val row = zoneIndex / profile.tofWidth
        val rawColumn = zoneIndex % profile.tofWidth
        val sceneColumn = profile.tofWidth - 1 - rawColumn
        val zi = profile.tofIntrinsics

        val normalizedX =
            (sceneColumn.toDouble() - zi.cxZones) / zi.fxZones
        val normalizedY =
            (row.toDouble() - zi.cyZones) / zi.fyZones

        // distanceMm is VL53L8CX's default R2P-corrected axial Z depth.
        val axialDepthMm = distanceMm.toDouble()
        val tofX = axialDepthMm * normalizedX
        val tofY = axialDepthMm * normalizedY
        val tofZ = axialDepthMm

        val r = profile.rotationToCamera
        val t = profile.translationToCameraMm
        val cameraX = r[0] * tofX + r[1] * tofY + r[2] * tofZ + t[0]
        val cameraY = r[3] * tofX + r[4] * tofY + r[5] * tofZ + t[1]
        val cameraZ = r[6] * tofX + r[7] * tofY + r[8] * tofZ + t[2]
        if (!cameraX.isFinite() || !cameraY.isFinite() ||
            !cameraZ.isFinite() || cameraZ <= 0.0
        ) return null

        val normalizedX = cameraX / cameraZ
        val normalizedY = cameraY / cameraZ
        val r2 = normalizedX * normalizedX + normalizedY * normalizedY
        val radial = 1.0 + k1 * r2 + k2 * r2 * r2
        val u = fx * normalizedX * radial + cx
        val v = fy * normalizedY * radial + cy
        if (!u.isFinite() || !v.isFinite()) return null

        return TofCameraProjection(
            zoneIndex = zoneIndex,
            distanceMm = distanceMm,
            tofXmm = tofX,
            tofYmm = tofY,
            tofZmm = tofZ,
            cameraXmm = cameraX,
            cameraYmm = cameraY,
            cameraZmm = cameraZ,
            uPx = u,
            vPx = v,
        )
    }
}

private fun List<Double>.toJsonArray(): JSONArray =
    JSONArray().also { array -> forEach(array::put) }

private fun JSONArray?.toDoubleList(): List<Double> {
    if (this == null) return emptyList()
    return buildList {
        for (index in 0 until length()) {
            val value = optDouble(index, Double.NaN)
            if (value.isFinite()) add(value)
        }
    }
}

private fun JSONObject.optFiniteDouble(name: String): Double? =
    if (!has(name) || isNull(name)) {
        null
    } else {
        optDouble(name, Double.NaN).takeIf { it.isFinite() }
    }
