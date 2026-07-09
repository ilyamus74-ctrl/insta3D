package com.maklertour.data.camera.osc.profile

import org.json.JSONObject

class Insta360X4OscProfile : Insta360CameraProfile {
    override fun supports(model: String): Boolean = model.contains("X4", ignoreCase = true)

    override fun parseBatteryPercent(state: JSONObject?): Int? {
        if (state == null) return null
        val value = state.optDouble("batteryLevel", Double.NaN)
        if (value.isNaN()) return null
        return if (value in 0.0..1.0) (value * 100).toInt() else null
    }

    override fun parseFreeStorageMb(state: JSONObject?): Long? {
        if (state == null || !state.has("freeSpace")) return null
        val bytes = state.optLong("freeSpace", -1L)
        return if (bytes > 0L) bytes / 1024L / 1024L else null
    }

    override fun buildTakePicturePayload(): JSONObject = JSONObject().put("name", "camera.takePicture")
    override fun buildSetPhotoModePayloads(): List<JSONObject> = listOf(
        JSONObject()
            .put("name", "camera.setOptions")
            .put(
                "parameters",
                JSONObject().put(
                    "options",
                    JSONObject().put("captureMode", "image")
                )
            ),
    )

    override fun buildSetVideoModePayloads(): List<JSONObject> = listOf(
        JSONObject()
            .put("name", "camera.setOptions")
            .put(
                "parameters",
                JSONObject().put(
                    "options",
                    JSONObject()
                        .put("captureMode", "video")
                        .put("_videoType", "normal")
                )
            ),
    )


    override fun buildListFilesPayload(): JSONObject = JSONObject().put("name", "camera.listFiles")

    override fun parseFileList(response: JSONObject): List<String> {
        val entries = response.optJSONObject("results")?.optJSONArray("entries") ?: return emptyList()
        return List(entries.length()) { idx ->
            entries.optJSONObject(idx)?.optString("name").orEmpty()
        }.filter { it.isNotBlank() }
    }
}
