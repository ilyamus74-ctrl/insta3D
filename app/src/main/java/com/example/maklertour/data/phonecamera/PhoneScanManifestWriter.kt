package com.maklertour.data.phonecamera

import org.json.JSONArray
import org.json.JSONObject
import java.io.File

class PhoneScanManifestWriter {
    fun write(
        baseDir: File,
        scanId: String,
        sessionId: String,
        videoFile: File,
        cameraInfoFile: File,
        imuFile: File?,
        createdAt: String,
        finishedAt: String,
        durationSec: Long,
        fileSizeBytes: Long,
    ): File {
        val file = File(baseDir, "manifest.json")
        val files = JSONArray()
            .put(JSONObject().put("name", videoFile.name).put("path", videoFile.name).put("file_size_bytes", videoFile.length()))
            .put(JSONObject().put("name", cameraInfoFile.name).put("path", cameraInfoFile.name).put("file_size_bytes", cameraInfoFile.length()))
        if (imuFile != null && imuFile.exists() && imuFile.length() > 0L) {
            files.put(JSONObject().put("name", imuFile.name).put("path", imuFile.name).put("file_size_bytes", imuFile.length()))
        }
        val json = JSONObject()
            .put("scan_id", scanId)
            .put("session_id", sessionId)
            .put("source", "PHONE_CAMERA")
            .put("created_at", createdAt)
            .put("finished_at", finishedAt)
            .put("duration_sec", durationSec)
            .put("file_size_bytes", fileSizeBytes)
            .put("video", videoFile.name)
            .put("camera_info", cameraInfoFile.name)
            .put("imu", if (imuFile != null && imuFile.exists() && imuFile.length() > 0L) imuFile.name else JSONObject.NULL)
            .put("files", files)
        file.writeText(json.toString(2))
        return file
    }
}
