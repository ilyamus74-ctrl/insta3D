package com.maklertour.state

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.maklertour.data.camera.MockCameraProvider
import com.maklertour.data.network.NetworkConfigProvider
import com.maklertour.data.network.UploadApi
import com.maklertour.data.network.UploadApiFactory
import com.maklertour.data.repository.InMemorySessionRepository
import com.maklertour.data.repository.InMemoryUploadQueueRepository
import com.maklertour.data.repository.SessionRepository
import com.maklertour.data.repository.UploadQueueRepository
import com.maklertour.domain.CameraProvider
import com.maklertour.domain.CameraStatus
import com.maklertour.domain.CapturePoint
import com.maklertour.domain.Session
import com.maklertour.domain.UploadItem
import com.maklertour.domain.UploadStatus
import org.json.JSONArray
import org.json.JSONObject
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.combine
import kotlinx.coroutines.flow.stateIn
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

data class AppUiState(
    val sessions: List<Session> = emptyList(),
    val cameraStatus: CameraStatus = CameraStatus(),
    val uploadQueue: List<UploadItem> = emptyList(),
    val selectedSessionId: String? = null,
)

sealed interface EnqueueUploadResult {
    data object Enqueued : EnqueueUploadResult
    data class Rejected(val reason: String) : EnqueueUploadResult
}

class AppStateViewModel(
    private val sessionRepository: SessionRepository = InMemorySessionRepository(),
    private val uploadQueueRepository: UploadQueueRepository = InMemoryUploadQueueRepository(),
    private val cameraProvider: CameraProvider = MockCameraProvider(),
    private val uploadApi: UploadApi = UploadApiFactory.create(NetworkConfigProvider.fromBuildConfig()),
) : ViewModel() {

    private val selectedSessionId = MutableStateFlow<String?>(null)
    private val cameraStatus = MutableStateFlow(CameraStatus())

    val uiState: StateFlow<AppUiState> = combine(
        sessionRepository.sessions,
        cameraStatus,
        uploadQueueRepository.queue,
        selectedSessionId,
    ) { sessions, camera, queue, selected ->
        AppUiState(
            sessions = sessions,
            cameraStatus = camera,
            uploadQueue = queue,
            selectedSessionId = selected ?: sessions.firstOrNull()?.id,
        )
    }.stateIn(viewModelScope, kotlinx.coroutines.flow.SharingStarted.Eagerly, AppUiState())

    fun selectSession(sessionId: String) {
        selectedSessionId.update { sessionId }
    }
    fun createSession(name: String, address: String, comment: String) {
        sessionRepository.createSession(name, address, comment)
    }

    fun connectCamera() {
        viewModelScope.launch { cameraStatus.value = cameraProvider.connect() }
    }

    fun disconnectCamera() {
        viewModelScope.launch { cameraStatus.value = cameraProvider.disconnect() }
    }

    fun refreshCameraStatus() {
        viewModelScope.launch { cameraStatus.value = cameraProvider.getStatus() }
    }

    fun capturePoint(pointName: String) {
        val sessionId = uiState.value.selectedSessionId ?: return
        viewModelScope.launch {
            val point: CapturePoint = cameraProvider.capture(pointName)
            sessionRepository.addPoint(sessionId, point)
        }
    }

    fun renamePoint(pointId: String, newName: String) {
        val sessionId = uiState.value.selectedSessionId ?: return
        sessionRepository.renamePoint(sessionId, pointId, newName)
    }

    fun deletePoint(pointId: String) {
        val sessionId = uiState.value.selectedSessionId ?: return
        sessionRepository.deletePoint(sessionId, pointId)
    }

    fun movePointUp(index: Int) {
        val sessionId = uiState.value.selectedSessionId ?: return
        if (index > 0) sessionRepository.movePoint(sessionId, index, index - 1)
    }

    fun movePointDown(index: Int) {
        val sessionId = uiState.value.selectedSessionId ?: return
        val pointsSize = uiState.value.sessions.firstOrNull { it.id == sessionId }?.points?.size ?: 0
        if (index < pointsSize - 1) sessionRepository.movePoint(sessionId, index, index + 1)
    }

    fun enqueueUpload(isWifiAvailable: Boolean): EnqueueUploadResult {
        if (!isWifiAvailable) {
            return EnqueueUploadResult.Rejected("Выгрузка доступна только по Wi‑Fi")
        }

        val session = uiState.value.sessions.firstOrNull { it.id == uiState.value.selectedSessionId }
            ?: return EnqueueUploadResult.Rejected("Сессия не выбрана")

        if (session.points.size < 5) {
            return EnqueueUploadResult.Rejected("Нужно минимум 5 точек для отправки")
        }

        uploadQueueRepository.enqueue(session.id)
        return EnqueueUploadResult.Enqueued
    }

    fun processUpload(uploadId: String) {
        viewModelScope.launch {
            val item = uiState.value.uploadQueue.firstOrNull { it.id == uploadId } ?: return@launch
            uploadQueueRepository.updateStatus(uploadId, UploadStatus.Uploading)

            val maxAttempts = 3
            var attempt = item.retryCount + 1
            while (attempt <= maxAttempts) {
                val uploaded = uploadApi.uploadChunk(
                    uploadId = uploadId,
                    sessionId = item.sessionId,
                    attempt = attempt,
                )
                if (uploaded) {
                    val processed = uploadApi.pollProcessingStatus(uploadId)
                    uploadQueueRepository.updateStatus(
                        uploadId,
                        if (processed) UploadStatus.Success else UploadStatus.Error,
                    )
                    return@launch
                }

                uploadQueueRepository.incrementRetry(uploadId)
                attempt += 1
                if (attempt <= maxAttempts) {
                    uploadQueueRepository.updateStatus(uploadId, UploadStatus.Queued)
                }
            }

            uploadQueueRepository.updateStatus(uploadId, UploadStatus.Error)
        }
    }

    fun completeUpload(uploadId: String) {
        uploadQueueRepository.updateStatus(uploadId, UploadStatus.Success)
    }

    fun failUpload(uploadId: String) {
        uploadQueueRepository.updateStatus(uploadId, UploadStatus.Error)
    }


    fun exportDiagnosticJson(): String {
        val state = uiState.value
        val sessionsJson = JSONArray().apply {
            state.sessions.forEach { session ->
                put(
                    JSONObject().apply {
                        put("id", session.id)
                        put("name", session.name)
                        put("address", session.address)
                        put("comment", session.comment)
                        put("createdAt", session.createdAt.toString())
                        put(
                            "points", JSONArray().apply {
                                session.points.forEach { point ->
                                    put(
                                        JSONObject().apply {
                                            put("id", point.id)
                                            put("name", point.name)
                                            put("capturedAt", point.capturedAt.toString())
                                            put("status", point.status.name)
                                            put("previewUri", point.previewUri ?: JSONObject.NULL)
                                        }
                                    )
                                }
                            }
                        )
                    }
                )
            }
        }

        val queueJson = JSONArray().apply {
            state.uploadQueue.forEach { item ->
                put(
                    JSONObject().apply {
                        put("id", item.id)
                        put("sessionId", item.sessionId)
                        put("status", item.status.name)
                        put("retryCount", item.retryCount)
                        put("updatedAt", item.updatedAt.toString())
                    }
                )
            }
        }

        return JSONObject().apply {
            put("generatedAt", java.time.Instant.now().toString())
            put("selectedSessionId", state.selectedSessionId ?: JSONObject.NULL)
            put("cameraStatus", JSONObject().apply {
                put("isConnected", state.cameraStatus.isConnected)
                put("model", state.cameraStatus.model ?: JSONObject.NULL)
                put("batteryPercent", state.cameraStatus.batteryPercent ?: JSONObject.NULL)
                put("freeStorageMb", state.cameraStatus.freeStorageMb ?: JSONObject.NULL)
                put("lastError", state.cameraStatus.lastError ?: JSONObject.NULL)
            })
            put("sessions", sessionsJson)
            put("uploadQueue", queueJson)
        }.toString(2)
    }
}

