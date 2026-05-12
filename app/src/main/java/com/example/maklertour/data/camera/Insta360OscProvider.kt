package com.maklertour.data.camera

import android.util.Log
import com.maklertour.data.camera.osc.OscHttpClient
import com.maklertour.data.camera.osc.profile.Insta360CameraProfile
import com.maklertour.data.camera.osc.profile.Insta360CameraProfileResolver
import com.maklertour.domain.CameraProvider
import com.maklertour.domain.CameraDeleteResult
import com.maklertour.domain.CameraStatus
import com.maklertour.domain.CapturePoint
import com.maklertour.domain.ScanVideo
import com.maklertour.domain.ScanVideoCaptureStatus
import com.maklertour.domain.CaptureStatus
import com.maklertour.domain.DeleteState
import com.maklertour.domain.FileLocalState
import com.maklertour.domain.ServerUploadState
import kotlinx.coroutines.delay
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock
import org.json.JSONObject

class Insta360OscProvider(
    private val oscHttpClient: OscHttpClient,
    private val profileResolver: Insta360CameraProfileResolver = Insta360CameraProfileResolver(),
) : CameraProvider {
    private val cameraCommandMutex = Mutex()
    private var lastStatus: CameraStatus = CameraStatus()
    private var activeProfile: Insta360CameraProfile = profileResolver.resolve(null)
    private var activeVideoScanName: String? = null
    private var activeVideoScanStartedAtMs: Long? = null

    override suspend fun connect(): CameraStatus {
        return cameraCommandMutex.withLock {
            try {
                val info = oscHttpClient.get("/osc/info")
                Log.d("Insta360OscProvider", "connect info raw=$info")
                val model = info.optString("model", null)
                activeProfile = profileResolver.resolve(model)
                val status = fetchCameraState(model)
                lastStatus = status.copy(isConnected = true, lastError = null)
                lastStatus
            } catch (e: Exception) {
                val disconnected = CameraStatus(isConnected = false, lastError = e.message ?: "OSC connect failed")
                lastStatus = disconnected
                disconnected
            }
        }
    }

    override suspend fun disconnect(): CameraStatus {
        lastStatus = CameraStatus(isConnected = false)
        return lastStatus
    }

    override suspend fun getStatus(): CameraStatus {
        if (!cameraCommandMutex.tryLock()) return lastStatus
        return try {
            val info = oscHttpClient.get("/osc/info")
            Log.d("Insta360OscProvider", "status info raw=$info")
            if (commandName(info) != null) {
                                Log.d("Insta360OscProvider", "status got command response, keeping lastStatus=$lastStatus")
                                lastStatus = lastStatus.copy(isConnected = true, lastError = null)
                                return lastStatus
            }
            val model = info.optString("model", null)
            activeProfile = profileResolver.resolve(model ?: lastStatus.model)
            val status = fetchCameraState(model)
            activeProfile = profileResolver.resolve(status.model)
            lastStatus = status.copy(isConnected = true, lastError = null)
            lastStatus
        } catch (e: Exception) {
            CameraStatus(isConnected = false, lastError = e.message ?: "OSC status failed")
        } finally {
            cameraCommandMutex.unlock()
        }
    }

    override suspend fun capture(pointName: String): CapturePoint {
        return cameraCommandMutex.withLock {
            val photoModeOk = trySwitchToPhotoMode()
            if (!photoModeOk) {
                Log.d("Insta360OscProvider", "capture aborted: photo mode switch failed")
                return@withLock CapturePoint(
                    name = pointName,
                    status = CaptureStatus.Failed,
                    previewUri = null,
                )
            }
            val finalResponse = executeTakePicture()
            if (commandState(finalResponse) != "done") {
                return@withLock CapturePoint(
                    name = pointName,
                    status = CaptureStatus.Failed,
                    previewUri = null,
                )
            }

            val results = finalResponse?.optJSONObject("results")
            val fileUrl = results?.optString("fileUrl")?.takeIf { it.isNotBlank() }
            val localFileUrl = results?.optString("_localFileUrl")?.takeIf { it.isNotBlank() }
            if (fileUrl.isNullOrBlank() && localFileUrl.isNullOrBlank()) {
                return@withLock CapturePoint(
                    name = pointName,
                    status = CaptureStatus.Failed,
                    previewUri = null,
                )
            }
            val pointId = java.util.UUID.randomUUID().toString()
            Log.d("Insta360OscProvider", "captured fileUrl=$fileUrl")
            Log.d("Insta360OscProvider", "captured localFileUrl=$localFileUrl")

            CapturePoint(
                id = pointId,
                name = pointName,
                status = CaptureStatus.Ready,
                previewUri = fileUrl ?: "osc://capture/${System.currentTimeMillis()}",
                cameraFileUrl = fileUrl,
                cameraLocalPath = localFileUrl,
                localPreviewPath = null,
                localOriginalState = FileLocalState.NOT_DOWNLOADED,
                serverUploadState = ServerUploadState.NOT_QUEUED,
                cameraDeleteState = DeleteState.NOT_DELETED,
                localDeleteState = DeleteState.NOT_DELETED,
            )
        }

    }

    override suspend fun listFiles(): List<String> {
        return cameraCommandMutex.withLock {
            try {
                val response = oscHttpClient.post(
                    "/osc/commands/execute",
                    activeProfile.buildListFilesPayload(),
                )
                Log.d("Insta360OscProvider", "listFiles raw=$response")
                activeProfile.parseFileList(response)
            } catch (e: Exception) {
                Log.d("Insta360OscProvider", "listFiles error=${e.message}")
                emptyList()
            }
        }
    }

    override suspend fun startVideoScan(scanName: String): ScanVideo {
        return cameraCommandMutex.withLock {
            try {
                Log.d("Insta360OscProvider", "startVideoScan(): switching to video mode")
                val videoModeOk = trySwitchToVideoMode()
                if (!videoModeOk) {
                    Log.d("Insta360OscProvider", "startVideoScan(): video mode switch failed, camera.startCapture not sent")
                    return@withLock ScanVideo(
                        sessionId = "",
                        name = scanName,
                        sequenceNumber = 0,
                        captureStatus = ScanVideoCaptureStatus.FAILED,
                        notes = "Video mode switch failed; camera.startCapture was not sent",
                    )
                }

                val response = executeVideoStartOnce()
                Log.d("Insta360OscProvider", "startCapture response=$response")

                if (commandState(response) == "error") {
                    Log.d("Insta360OscProvider", "startVideoScan(): start failed, restoring photo mode")
                    runCatching { trySwitchToPhotoMode() }

                    return@withLock ScanVideo(
                        sessionId = "",
                        name = scanName,
                        sequenceNumber = 0,
                        captureStatus = ScanVideoCaptureStatus.FAILED,
                        notes = buildVideoErrorNotes(response),
                    )
                }

                delay(1_000)

                val verification = pollOscStateForCommand(
                    expectedName = "camera.startCapture",
                    attempts = 4,
                    delayMs = 500,
                )
                Log.d("Insta360OscProvider", "startVideoScan(): /osc/state verification=$verification")

                if (commandState(verification) == "error" && commandState(response) != "done") {
                    Log.d("Insta360OscProvider", "startVideoScan(): delayed start error, restoring photo mode")
                    runCatching { trySwitchToPhotoMode() }
                    return@withLock ScanVideo(
                        sessionId = "",
                        name = scanName,
                        sequenceNumber = 0,
                        captureStatus = ScanVideoCaptureStatus.FAILED,
                        notes = buildVideoErrorNotes(verification ?: response),
                    )
                }

                val startAccepted =
                    commandState(response) == "done" ||
                            commandState(response) == "inProgress" ||
                            commandState(verification) == "done" ||
                            commandState(verification) == "inProgress"

                if (!startAccepted) {
                    Log.d("Insta360OscProvider", "startVideoScan(): start not confirmed, restoring photo mode")
                    runCatching { trySwitchToPhotoMode() }

                    return@withLock ScanVideo(
                        sessionId = "",
                        name = scanName,
                        sequenceNumber = 0,
                        captureStatus = ScanVideoCaptureStatus.FAILED,
                        notes = "camera.startCapture was not confirmed. response=$response verification=$verification",
                    )
                }

                activeVideoScanName = scanName
                activeVideoScanStartedAtMs = System.currentTimeMillis()

                Log.d("Insta360OscProvider", "startVideoScan(): recording confirmed")

                ScanVideo(
                    sessionId = "",
                    name = scanName,
                    sequenceNumber = 0,
                    captureStatus = ScanVideoCaptureStatus.RECORDING,
                    notes = "startResponse=$response verification=$verification",
                )
            } catch (e: Exception) {
                Log.d("Insta360OscProvider", "startVideoScan error=${e.message}", e)

                runCatching { trySwitchToPhotoMode() }

                activeVideoScanName = null
                activeVideoScanStartedAtMs = null

                ScanVideo(
                    sessionId = "",
                    name = scanName,
                    sequenceNumber = 0,
                    captureStatus = ScanVideoCaptureStatus.FAILED,
                    notes = e.message ?: "startVideoScan failed",
                )
            }
        }
    }

    override suspend fun stopVideoScan(): ScanVideo {
        return cameraCommandMutex.withLock {
            val scanName = activeVideoScanName ?: "Scan"
            val startedAt = activeVideoScanStartedAtMs
            try {
                val response = executeVideoStopOnce()
                Log.d("Insta360OscProvider", "stopCapture response=$response")

                val finalResponse = when (commandState(response)) {
                    "done" -> response
                    "inProgress" -> pollCommandStatus(response.optString("id")) ?: response
                    "error" -> response
                    else -> {
                        pollOscStateForCommand(
                            expectedName = "camera.stopCapture",
                            attempts = 8,
                            delayMs = 500,
                        ) ?: response
                    }
                }

                val results = finalResponse.optJSONObject("results")

                val fileUrl = results?.optString("fileUrl")?.takeIf { it.isNotBlank() }
                    ?: results?.optJSONArray("fileUrls")
                        ?.let { array ->
                            (0 until array.length())
                                .mapNotNull { idx -> array.optString(idx).takeIf { it.isNotBlank() } }
                                .firstOrNull()
                        }

                val localFileUrl = results?.optString("_localFileUrl")?.takeIf { it.isNotBlank() }
                    ?: results?.optJSONArray("_localFileUrls")
                        ?.let { array ->
                            (0 until array.length())
                                .mapNotNull { idx -> array.optString(idx).takeIf { it.isNotBlank() } }
                                .firstOrNull()
                        }

                Log.d("Insta360OscProvider", "parsed video fileUrl=$fileUrl")
                Log.d("Insta360OscProvider", "parsed video localFileUrl=$localFileUrl")
                Log.d("Insta360OscProvider", "stop video final raw=$finalResponse")

                val durationSec = startedAt?.let {
                    ((System.currentTimeMillis() - it) / 1000L).coerceAtLeast(0L)
                }

                activeVideoScanName = null
                activeVideoScanStartedAtMs = null

                val ok = commandState(finalResponse) == "done" || fileUrl != null || localFileUrl != null
                ScanVideo(
                    sessionId = "",
                    name = scanName,
                    sequenceNumber = 0,
                    cameraFileUrl = fileUrl,
                    cameraLocalFileUrl = localFileUrl,
                    durationSec = durationSec,
                    captureStatus = if (ok) ScanVideoCaptureStatus.CAPTURED else ScanVideoCaptureStatus.FAILED,
                    notes = finalResponse.toString(),
                )
            } catch (e: Exception) {
                Log.d("Insta360OscProvider", "stopVideoScan error=${e.message}", e)
                activeVideoScanName = null
                activeVideoScanStartedAtMs = null
                ScanVideo(
                    sessionId = "",
                    name = scanName,
                    sequenceNumber = 0,
                    captureStatus = ScanVideoCaptureStatus.FAILED,
                    notes = e.message ?: "stopVideoScan failed",
                )
            }
        }
    }

    override suspend fun deleteFiles(fileUrls: List<String>): CameraDeleteResult {
        return cameraCommandMutex.withLock {
            runCatching {
                val payload = JSONObject()
                    .put("name", "camera.delete")
                    .put("parameters", JSONObject().put("fileUrls", org.json.JSONArray(fileUrls)))
                val response = oscHttpClient.post("/osc/commands/execute", payload)
                Log.d("Insta360OscProvider", "deleteFiles raw=$response")
                if (response.optString("state") == "done") CameraDeleteResult(fileUrls, emptyMap())
                else CameraDeleteResult(emptyList(), fileUrls.associateWith { "unsupported_or_failed" })
            }.getOrElse { error ->
                val message = error.message ?: "error"
                Log.d("Insta360OscProvider", "deleteFiles error=$message")
                CameraDeleteResult(
                    deleted = emptyList(),
                    failed = fileUrls.associateWith { message }
                )
            }
        }
    }
    private suspend fun fetchCameraState(modelFromInfo: String?): CameraStatus {
        val stateResponse = oscHttpClient.post(
            "/osc/state",
            JSONObject(),
        )
        Log.d("Insta360OscProvider", "state raw=$stateResponse")

        val stateObj = stateResponse.optJSONObject("state")

        val battery = activeProfile.parseBatteryPercent(stateObj)
        val freeStorageMb = activeProfile.parseFreeStorageMb(stateObj) ?: lastStatus.freeStorageMb
        val modelFromState = stateResponse.optString("model").takeIf { it.isNotBlank() }
        val resolvedModel = modelFromInfo?.takeIf { it.isNotBlank() }
                    ?: modelFromState
                    ?: lastStatus.model
                    ?: "Insta360 OSC"
        return CameraStatus(
            isConnected = true,
            model = resolvedModel,
            batteryPercent = battery ?: lastStatus.batteryPercent,
            freeStorageMb = freeStorageMb,
            lastError = null,
        )
    }




    private suspend fun executeVideoStartOnce(): JSONObject {
        return try {
            val payload = JSONObject().put("name", "camera.startCapture")
            val response = oscHttpClient.post("/osc/commands/execute", payload)
            Log.d("Insta360OscProvider", "start video raw=$response")
            response
        } catch (e: Exception) {
            Log.d("Insta360OscProvider", "start video failed=${e.message}", e)
            JSONObject()
                .put("state", "error")
                .put("error", JSONObject().put("message", e.message ?: "camera.startCapture failed"))
        }
    }

    private suspend fun executeVideoStopOnce(): JSONObject {
        return try {
            val payload = JSONObject().put("name", "camera.stopCapture")
            val response = oscHttpClient.post("/osc/commands/execute", payload)
            Log.d("Insta360OscProvider", "stop video raw=$response")
            response
        } catch (e: Exception) {
            Log.d("Insta360OscProvider", "stop video failed=${e.message}", e)
            JSONObject()
                .put("state", "error")
                .put("error", JSONObject().put("message", e.message ?: "camera.stopCapture failed"))
        }
    }

    private fun buildVideoErrorNotes(response: JSONObject): String {
        val errorCode = response.optJSONObject("error")?.optString("code")
        val errorMessage = response.optJSONObject("error")?.optString("message")
        val base = "code=$errorCode message=$errorMessage raw=$response"
        return if (errorCode == "disabledCommand") {
            "$base. camera.startCapture rejected. Camera may not be in video mode. Video mode was requested, but camera.startCapture was rejected. Photo mode restore was attempted."
        } else {
            base
        }
    }
    private suspend fun trySwitchToPhotoMode(): Boolean {
        repeat(2) { retry ->
            activeProfile.buildSetPhotoModePayloads().forEach { payload ->
                val response = oscHttpClient.post("/osc/commands/execute", payload)
                Log.d("Insta360OscProvider", "set photo mode raw=$response")
                val responseName = commandName(response)
                val responseState = commandState(response)

                if (responseName == "camera.setOptions" && responseState == "done") {
                    val verified = verifyCaptureMode("image")
                    Log.d("Insta360OscProvider", "set photo mode done verified=$verified")
                    if (verified) return true
                }
                if (responseName == "camera.setOptions" && responseState == "inProgress") {
                    val statusResponse = pollCommandStatus(response.optString("id"))

                    if (commandState(statusResponse) == "done") {
                        val verified = verifyCaptureMode("image")
                        Log.d("Insta360OscProvider", "set photo mode done via status verified=$verified")
                        if (verified) return true
                    }
                }
                if (responseName == "camera.setOptions" && responseState == "error") return@forEach
                if (looksLikeCameraInfoOrState(response)) {
                    Log.d("Insta360OscProvider", "set photo mode accepted camera info/state response")
                    val pollResult = pollOscStateForCommand("camera.setOptions")
                    if (commandState(pollResult) != "error") {
                        val verified = verifyCaptureMode("image")
                        Log.d("Insta360OscProvider", "set photo mode accepted via state verified=$verified")
                        if (verified) return true
                    }
                }
                if (!responseName.isNullOrBlank() && responseName != "camera.setOptions") {
                    Log.d("Insta360OscProvider", "set photo mode stale response=$response")
                    if (retry < 1) delay(400)
                }
            }
        }
        return false
    }
private suspend fun trySwitchToVideoMode(): Boolean {
    val payload = JSONObject()
        .put("name", "camera.setOptions")
        .put(
            "parameters",
            JSONObject().put(
                "options",
                JSONObject()
                    .put("captureMode", "video")
                    .put("_videoType", "normal")
            )
        )
    Log.d("Insta360OscProvider", "set video mode payload=$payload")
    repeat(2) { attempt ->
        try {
            val response = oscHttpClient.post("/osc/commands/execute", payload)
            Log.d("Insta360OscProvider", "set video mode raw=$response")

            val state = commandState(response)
            val name = commandName(response)

            when {
                name == "camera.setOptions" && state == "done" -> {
                    val verified = verifyCaptureMode("video", "normal")
                    Log.d("Insta360OscProvider", "set video mode attempt[$attempt] done verified=$verified")
                    if (verified) return true
                }

                name == "camera.setOptions" && state == "inProgress" -> {
                    val statusResponse = pollCommandStatus(response.optString("id"))
                    if (commandState(statusResponse) == "done") {
                        val verified = verifyCaptureMode("video", "normal")
                        Log.d("Insta360OscProvider", "set video mode attempt[$attempt] done via status verified=$verified")
                        if (verified) return true
                    }
                }
                looksLikeCameraInfoOrState(response) -> {
                    val pollResult = pollOscStateForCommand(
                        expectedName = "camera.setOptions",
                        attempts = 8,
                        delayMs = 300,
                    )
                    if (commandState(pollResult) != "error") {
                        val verified = verifyCaptureMode("video", "normal")
                        Log.d("Insta360OscProvider", "set video mode attempt[$attempt] accepted via state verified=$verified")
                        if (verified) return true
                    }
                }

                else -> {
                    Log.d("Insta360OscProvider", "set video mode attempt[$attempt] not accepted response=$response")
                }
            }
        } catch (e: Exception) {
            Log.d("Insta360OscProvider", "set video mode attempt[$attempt] failed=${e.message}", e)
        }
        delay(300)
    }

    return false
}

    private suspend fun pollCommandStatus(commandId: String?): JSONObject? {
        if (commandId.isNullOrBlank()) return null
        repeat(30) {
            val statusResponse = oscHttpClient.post(
                "/osc/commands/status",
                JSONObject().put("id", commandId),
            )
            Log.d("Insta360OscProvider", "command status raw=$statusResponse")
            when (commandState(statusResponse)) {
                "done" -> return statusResponse
                "error" -> return statusResponse
            }
            delay(500)
        }
        return null
    }

    private fun commandName(response: JSONObject?): String? = response?.optString("name")?.takeIf { it.isNotBlank() }

    private fun commandState(response: JSONObject?): String? = response?.opt("state") as? String

    private fun looksLikeCameraInfoOrState(response: JSONObject): Boolean {
                if (commandName(response) != null) return false

                val hasCameraInfo = listOf(
                        "manufacturer",
                        "model",
                        "serialNumber",
                        "firmwareVersion",
                        "supportUrl",
                        "endpoints",
                        "api",
                        "apiLevel",
                        "_sensorModuleType",
                        "_vendorVersion",
                    ).any { response.has(it) }

                val hasCameraState = response.has("fingerprint") || response.optJSONObject("state") != null
                return hasCameraInfo || hasCameraState
    }

    private suspend fun pollOscStateForCommand(
        expectedName: String,
        attempts: Int = 8,
        delayMs: Long = 300,
    ): JSONObject? {
        repeat(attempts) {
            val stateResponse = oscHttpClient.post("/osc/state", JSONObject())
            Log.d("Insta360OscProvider", "pollOscStateForCommand($expectedName) raw=$stateResponse")

            val commandObj = stateResponse.optJSONObject("_latestCommand")
                ?: stateResponse.optJSONObject("latestCommand")
                ?: stateResponse

            if (commandName(commandObj) == expectedName) {
                val state = commandState(commandObj)

                // Важно: возвращаем и inProgress, чтобы не потерять command id.
                if (state == "done" || state == "error" || state == "inProgress") {
                    return commandObj
                }
            }

            delay(delayMs)
        }

        return null
    }

    private suspend fun executeTakePicture(): JSONObject? {
        var staleRetryDone = false

        while (true) {
            val executeResponse = oscHttpClient.post(
                "/osc/commands/execute",
                activeProfile.buildTakePicturePayload(),
            )

            Log.d("Insta360OscProvider", "takePicture raw=$executeResponse")

            val responseName = commandName(executeResponse)
            val responseState = commandState(executeResponse)

            if (responseName == "camera.takePicture") {
                return when (responseState) {
                    "done" -> executeResponse
                    "inProgress" -> {
                        val commandId = executeResponse.optString("id").takeIf { it.isNotBlank() }
                        Log.d("Insta360OscProvider", "takePicture inProgress, commandId=$commandId")
                        pollCommandStatus(commandId)
                    }
                    "error" -> executeResponse
                    else -> null
                }
            }

            if (!responseName.isNullOrBlank()) {
                Log.d("Insta360OscProvider", "takePicture stale response=$executeResponse")

                // Разрешаем только один retry при явно чужом command response.
                // Но не делаем бесконечные повторные takePicture.
                if (!staleRetryDone) {
                    staleRetryDone = true
                    delay(400)
                    continue
                }

                return null
            }

            if (looksLikeCameraInfoOrState(executeResponse)) {
                // Камера могла принять takePicture, но сразу вернуть fingerprint/state.
                // Нельзя повторно слать takePicture — сначала ищем command id/status.
                val stateResponse = pollOscStateForCommand(
                    expectedName = "camera.takePicture",
                    attempts = 12,
                    delayMs = 350,
                )

                return when (commandState(stateResponse)) {
                    "done" -> stateResponse
                    "inProgress" -> {
                        val commandId = stateResponse?.optString("id")?.takeIf { it.isNotBlank() }
                        Log.d("Insta360OscProvider", "takePicture accepted via state, commandId=$commandId")
                        pollCommandStatus(commandId)
                    }
                    "error" -> stateResponse
                    else -> null
                }
            }

            return null
        }
    }

    private suspend fun fetchCaptureModeOptions(): JSONObject? {
        val response = runCatching {
            oscHttpClient.post(
                "/osc/commands/execute",
                JSONObject()
                    .put("name", "camera.getOptions")
                    .put(
                        "parameters",
                        JSONObject().put(
                            "optionNames",
                            org.json.JSONArray(listOf("captureMode", "_videoType"))
                        )
                    ),
            )
        }.getOrNull()
        Log.d("Insta360OscProvider", "getOptions response=$response")
        return commandOptions(response)
    }

    private suspend fun verifyCaptureMode(expectedCaptureMode: String, expectedVideoType: String? = null): Boolean {
        repeat(20) { attempt ->
            val options = fetchCaptureModeOptions()
            val captureMode = options?.optString("captureMode", null)
            val videoType = options?.optString("_videoType", null)
            Log.d(
                "Insta360OscProvider",
                "verifyCaptureMode attempt=${attempt + 1} captureMode=$captureMode videoType=$videoType expectedCaptureMode=$expectedCaptureMode expectedVideoType=$expectedVideoType rawOptions=$options"
            )
            if (captureMode == expectedCaptureMode && (expectedVideoType == null || videoType == expectedVideoType)) {
                Log.d("Insta360OscProvider", "captureMode detected captureMode=$captureMode videoType=$videoType")
                return true
            }
            delay(500)
        }
        return false
    }

    private fun commandOptions(response: JSONObject?): JSONObject? {
        return response
            ?.optJSONObject("results")
            ?.optJSONObject("options")
    }
}
