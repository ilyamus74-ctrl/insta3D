package com.maklertour.data.phonecamera

import org.json.JSONObject
import java.io.File
import java.time.Instant

class PhoneScanManifestWriter {
    fun write(scanId: String, sessionId: String, videoPath: String, cameraInfoPath: String, imuPath: String, startedAt: Instant, finishedAt: Instant, durationSec: Long, baseDir: File): File {
        val json = JSONObject()
            .put("scan_id", scanId)
            .put("session_id", sessionId)
            .put("source", "PHONE_CAMERA")
            .put("video_path", videoPath)
            .put("camera_info_path", cameraInfoPath)
            .put("imu_path", imuPath)
            .put("started_at", startedAt.toString())
            .put("finished_at", finishedAt.toString())
            .put("duration_sec", durationSec)
        return File(baseDir, "scan_manifest.json").also { it.writeText(json.toString(2)) }
    }
}