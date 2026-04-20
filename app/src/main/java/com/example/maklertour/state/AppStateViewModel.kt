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

    fun enqueueUpload() {
        val sessionId = uiState.value.selectedSessionId ?: return
        uploadQueueRepository.enqueue(sessionId)
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
}
