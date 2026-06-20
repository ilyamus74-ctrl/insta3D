package com.maklertour.data.phonecamera

import org.json.JSONObject
import java.io.File

class PhoneScanManifestWriter {
    fun write(baseDir: File, scanId: String, sessionId: String, videoPath: String, cameraInfoPath: String, imuPath: String, startedAt: String, finishedAt: String, durationSec: Long): File {
        val file = File(baseDir, "scan_manifest.json")
        val json = JSONObject()
            .put("scan_id", scanId)
            .put("session_id", sessionId)
            .put("source", "PHONE_CAMERA")
            .put("video_path", videoPath)
            .put("camera_info_path", cameraInfoPath)
            .put("imu_path", imuPath)
            .put("started_at", startedAt)
            .put("finished_at", finishedAt)
            .put("duration_sec", durationSec)
        file.writeText(json.toString(2))
        return file
    }
}