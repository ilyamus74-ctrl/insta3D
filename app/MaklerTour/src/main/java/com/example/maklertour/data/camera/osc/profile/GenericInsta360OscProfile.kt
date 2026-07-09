package com.maklertour.data.camera.osc.profile

import org.json.JSONObject

class GenericInsta360OscProfile : Insta360CameraProfile {
    override fun supports(model: String): Boolean = false

    override fun parseBatteryPercent(state: JSONObject?): Int? {
        if (state == null) return null

        val keys = listOf("batteryLevel", "_batteryLevel", "battery", "_battery")
        for (key in keys) {
            if (!state.has(key)) continue
            val value = state.opt(key)
            when (value) {
                is Number -> return numberToBatteryPercent(value.toDouble())
                is String -> return value.toDoubleOrNull()?.let(::numberToBatteryPercent)
                is JSONObject -> {
                    val nestedKeys = listOf("level", "percent", "percentage", "batteryLevel")
                    for (nestedKey in nestedKeys) {
                        if (!value.has(nestedKey)) continue
                        return numberToBatteryPercent(value.optDouble(nestedKey, -1.0))
                    }
                }
            }
        }
        return null
    }

    override fun parseFreeStorageMb(state: JSONObject?): Long? {
        if (state == null) return null
        val byteKeys = listOf("_freeSpace", "freeSpace", "_remainingSpace", "remainingSpace", "storageFreeSpace")

        for (key in byteKeys) {
            if (!state.has(key)) continue
            val bytes = state.optLong(key, -1L)
            if (bytes > 0L) return bytes / 1024L / 1024L
        }

        return null
    }

    override fun buildTakePicturePayload(): JSONObject = JSONObject().put("name", "camera.takePicture")
    override fun buildSetPhotoModePayloads(): List<JSONObject> = listOf(
        JSONObject()
            .put("name", "camera.setOptions")
            .put("parameters", JSONObject().put("options", JSONObject().put("captureMode", "image"))),
        JSONObject()
            .put("name", "camera.setOptions")
            .put("parameters", JSONObject().put("options", JSONObject().put("captureMode", "photo"))),
        JSONObject()
            .put("name", "camera.setOptions")
            .put("parameters", JSONObject().put("options", JSONObject().put("_captureMode", "image"))),
        JSONObject()
            .put("name", "camera.setOptions")
            .put("parameters", JSONObject().put("options", JSONObject().put("_captureMode", "photo"))),
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
        JSONObject()
            .put("name", "camera.setOptions")
            .put("parameters", JSONObject().put("options", JSONObject().put("_captureMode", "video"))),
        JSONObject()
            .put("name", "camera.setOptions")
            .put("parameters", JSONObject().put("options", JSONObject().put("captureMode", "video"))),
        JSONObject()
            .put("name", "camera.setOptions")
            .put("parameters", JSONObject().put("options", JSONObject().put("mode", "video"))),
    )
    override fun buildListFilesPayload(): JSONObject = JSONObject().put("name", "camera.listFiles")

    override fun parseFileList(response: JSONObject): List<String> {
        val entries = response.optJSONObject("results")?.optJSONArray("entries") ?: return emptyList()
        return List(entries.length()) { idx ->
            entries.optJSONObject(idx)?.optString("name").orEmpty()
        }.filter { it.isNotBlank() }
    }

    private fun numberToBatteryPercent(value: Double): Int? {
        return when {
            value in 0.0..1.0 -> (value * 100).toInt()
            value in 1.0..100.0 -> value.toInt()
            else -> null
        }
    }
}
