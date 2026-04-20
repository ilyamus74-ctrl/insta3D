package com.maklertour.state

import androidx.lifecycle.ViewModel
import com.maklertour.domain.CameraStatus
import com.maklertour.domain.CapturePoint
import com.maklertour.domain.Session
import com.maklertour.domain.UploadItem
import com.maklertour.domain.UploadStatus
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.update
import java.time.Instant

data class AppUiState(
    val sessions: List<Session> = emptyList(),
    val cameraStatus: CameraStatus = CameraStatus(),
    val uploadQueue: List<UploadItem> = emptyList(),
)

class AppStateViewModel : ViewModel() {
    private val _uiState = MutableStateFlow(
        AppUiState(
            sessions = listOf(
                Session(
                    name = "Демо: квартира на Ленина",
                    address = "Москва, ул. Ленина, 10",
                    comment = "Первый прогон MVP",
                )
            )
        )
    )

    val uiState: StateFlow<AppUiState> = _uiState

    fun addPoint(sessionId: String, pointName: String) {
        _uiState.update { state ->
            state.copy(
                sessions = state.sessions.map { session ->
                    if (session.id != sessionId) return@map session

                    session.copy(
                        points = session.points + CapturePoint(name = pointName)
                    )
                }
            )
        }
    }

    fun updateCameraStatus(status: CameraStatus) {
        _uiState.update { it.copy(cameraStatus = status) }
    }

    fun enqueueUpload(sessionId: String) {
        _uiState.update {
            it.copy(
                uploadQueue = it.uploadQueue + UploadItem(
                    sessionId = sessionId,
                    status = UploadStatus.Queued,
                    updatedAt = Instant.now(),
                )
            )
        }
    }
}
