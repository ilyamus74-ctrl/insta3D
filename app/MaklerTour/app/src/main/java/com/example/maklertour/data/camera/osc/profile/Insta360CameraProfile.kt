package com.maklertour.data.camera.osc.profile

import org.json.JSONObject

interface Insta360CameraProfile {
    fun supports(model: String): Boolean
    fun parseBatteryPercent(state: JSONObject?): Int?
    fun parseFreeStorageMb(state: JSONObject?): Long?
    fun buildTakePicturePayload(): JSONObject
    fun buildSetPhotoModePayloads(): List<JSONObject>
    fun buildSetVideoModePayloads(): List<JSONObject>
    fun buildListFilesPayload(): JSONObject
    fun parseFileList(response: JSONObject): List<String>
}
