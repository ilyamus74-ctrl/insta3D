package com.maklertour.state

import androidx.lifecycle.ViewModel
import android.util.Log
import androidx.camera.view.PreviewView
import androidx.lifecycle.viewModelScope
import com.maklertour.data.camera.MockCameraProvider
import com.maklertour.data.camera.osc.OscFileDownloader
import com.maklertour.data.network.NetworkConfigProvider
import com.maklertour.data.network.UploadApi
import com.maklertour.data.network.UploadApiFactory
import com.maklertour.data.repository.InMemorySessionRepository
import com.maklertour.data.repository.InMemoryUploadQueueRepository
import com.maklertour.data.repository.SessionRepository
import com.maklertour.data.repository.UploadQueueRepository
import com.maklertour.data.sync.LocalOriginalManager
import com.maklertour.data.sync.SyncRepository
import com.maklertour.domain.CameraProvider
import com.maklertour.domain.CameraStatus
import com.maklertour.domain.CapturePoint
import com.maklertour.domain.Session
import com.maklertour.domain.RoomDraft
import com.maklertour.domain.UploadItem
import com.maklertour.domain.UploadStatus
import com.maklertour.domain.TourDraftConnection
import com.maklertour.domain.ScanVideoCaptureStatus
import com.maklertour.domain.ScanVideoDownloadState
import com.maklertour.domain.ScanVideo
import com.maklertour.domain.CaptureStatus
import com.maklertour.domain.VideoScanUiState
import com.maklertour.domain.ServerUploadState
import com.maklertour.domain.ScanVideoUploadState
import com.maklertour.domain.ScanSource
import com.maklertour.domain.ScanVideoRole
import com.maklertour.data.phonecamera.PhoneCameraBindResult
import com.maklertour.data.phonecamera.PhoneCameraScanProvider
import com.maklertour.data.phonecamera.PhoneVideoMode
import com.example.maklertour.auth.MobileOrder
import com.example.maklertour.auth.MobileUploadApi
import com.example.maklertour.data.capture.CaptureBundlePackager
import com.example.maklertour.data.capture.CaptureBundlePreflightException
import org.json.JSONArray
import org.json.JSONObject
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.combine
import kotlinx.coroutines.flow.stateIn
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import java.io.File

data class AppUiState(
    val sessions: List<Session> = emptyList(),
    val cameraStatus: CameraStatus = CameraStatus(),
    val uploadQueue: List<UploadItem> = emptyList(),
    val selectedSessionId: String? = null,
    val selectedSessionName: String? = null,
    val selectedSessionPointsCount: Int = 0,
    val isCapturing: Boolean = false,
    val selectedSessionRooms: List<RoomDraft> = emptyList(),
    val selectedSessionStartPointId: String? = null,
    val selectedSessionConnections: List<TourDraftConnection> = emptyList(),
    val selectedSessionScanVideos: List<ScanVideo> = emptyList(),
    val isRecordingScanVideo: Boolean = false,
    val videoScanUiState: VideoScanUiState = VideoScanUiState.IDLE,
    val selectedOrder: MobileOrder? = null,
    val uploadError: String? = null,
    val captureBundleNotice: CaptureBundleNotice? = null,
    )

sealed interface EnqueueUploadResult {
    data object Enqueued : EnqueueUploadResult
    data object RequeuedNewMedia : EnqueueUploadResult
    data class Rejected(val reason: String) : EnqueueUploadResult
}


private data class DraftStateBundle(
    val sessions: List<Session>,
    val rooms: List<RoomDraft>,
    val connections: List<TourDraftConnection>,
    val scanVideos: List<ScanVideo>,
)

private data class RuntimeStateBundle(
    val camera: CameraStatus,
    val queue: List<UploadItem>,
    val selected: String?,
    val capturing: Boolean,
    val recordingScanVideo: Boolean,
    val videoScanUiState: VideoScanUiState,
    val captureBundleNotice: CaptureBundleNotice? = null,
)

class AppStateViewModel(
    private val sessionRepository: SessionRepository = InMemorySessionRepository(),
    private val uploadQueueRepository: UploadQueueRepository = InMemoryUploadQueueRepository(),
    private val cameraProvider: CameraProvider = MockCameraProvider(),
    private val phoneCameraScanProvider: PhoneCameraScanProvider? = null,
    private val uploadApi: UploadApi = UploadApiFactory.create(NetworkConfigProvider.fromBuildConfig()),
    private val localOriginalManager: LocalOriginalManager? = null,
    private val syncRepository: SyncRepository? = null,
    private val oscFileDownloader: OscFileDownloader? = null,
    private val mobileUploadApi: MobileUploadApi? = null,
) : ViewModel() {

    private val selectedSessionId = MutableStateFlow<String?>(null)
    private val cameraStatus = MutableStateFlow(CameraStatus())
    private val isCapturing = MutableStateFlow(false)
    private val isRecordingScanVideo = MutableStateFlow(false)
    private val currentRecordingScanVideo = MutableStateFlow<ScanVideo?>(null)
    private val videoScanUiState = MutableStateFlow(VideoScanUiState.IDLE)
    private val selectedOrder = MutableStateFlow<MobileOrder?>(null)
    private val uploadError = MutableStateFlow<String?>(null)
    private val captureBundleNotice = MutableStateFlow<CaptureBundleNotice?>(null)
    private val isAutoUploadRunning = MutableStateFlow(false)

    init {
        uploadQueueRepository.resetInterruptedUploadsOnStartup()
    }

    val uiState: StateFlow<AppUiState> = combine(
        combine(
            sessionRepository.sessions,
            sessionRepository.rooms,
            sessionRepository.connections,
            sessionRepository.scanVideos,
        ) { sessions, rooms, connections, scanVideos ->
            DraftStateBundle(
                sessions = sessions,
                rooms = rooms,
                connections = connections,
                scanVideos = scanVideos,
            )
        },
        combine(
            combine(
                cameraStatus,
                uploadQueueRepository.queue,
                selectedSessionId,
                isCapturing,
                selectedOrder,
            ) { camera, queue, selected, capturing, _ ->
                RuntimeStateBundle(
                    camera = camera,
                    queue = queue,
                    selected = selected,
                    capturing = capturing,
                    recordingScanVideo = false,
                    videoScanUiState = VideoScanUiState.IDLE,
                )
            },
            combine(
                isRecordingScanVideo,
                videoScanUiState,
                captureBundleNotice,
            ) { recordingScanVideo, scanState, bundleNotice ->
                Triple(recordingScanVideo, scanState, bundleNotice)
            },
        ) { runtimeBase, scanRuntime ->
            runtimeBase.copy(
                recordingScanVideo = scanRuntime.first,
                videoScanUiState = scanRuntime.second,
                captureBundleNotice = scanRuntime.third,
            )
        },
    ) { draft, runtime ->
        val resolvedSelectedSessionId = runtime.selected ?: draft.sessions.firstOrNull()?.id
        val selectedSession = draft.sessions.firstOrNull { it.id == resolvedSelectedSessionId }
        AppUiState(
            sessions = draft.sessions,
            cameraStatus = runtime.camera,
            uploadQueue = runtime.queue,
            selectedSessionId = resolvedSelectedSessionId,
            selectedSessionName = selectedSession?.name,
            selectedSessionPointsCount = selectedSession?.points?.size ?: 0,
            isCapturing = runtime.capturing,
            selectedSessionRooms = draft.rooms.filter { it.sessionId == resolvedSelectedSessionId },
            selectedSessionStartPointId = selectedSession?.startPointId,
            selectedSessionConnections = draft.connections.filter { it.sessionId == resolvedSelectedSessionId },
            selectedSessionScanVideos = draft.scanVideos.filter { it.sessionId == resolvedSelectedSessionId },
            isRecordingScanVideo = runtime.recordingScanVideo,
            videoScanUiState = runtime.videoScanUiState,
            selectedOrder = selectedOrder.value,
            uploadError = uploadError.value,
            captureBundleNotice = runtime.captureBundleNotice,
        )
    }.stateIn(
        viewModelScope,
        kotlinx.coroutines.flow.SharingStarted.Eagerly,
        AppUiState(),
    )

    fun selectSession(sessionId: String) {
        selectedSessionId.update { sessionId }
    }
    fun createSession(name: String, address: String, comment: String) {
        val order = selectedOrder.value
        val newSessionId = sessionRepository.createSession(name, address, comment, order?.id, order?.title, order?.address)
        selectedSessionId.value = newSessionId
    }

    fun deleteSession(sessionId: String) {
        val isDeletedSessionSelected = selectedSessionId.value == sessionId
        sessionRepository.deleteSession(sessionId)
        if (isDeletedSessionSelected) {
            selectedSessionId.value = uiState.value.sessions
                .firstOrNull { it.id != sessionId }
                ?.id
        }
    }
    fun selectOrder(order: MobileOrder) { selectedOrder.value = order }
    fun clearSelectedOrder() { selectedOrder.value = null }

    fun attachSessionToOrder(sessionId: String, order: MobileOrder): Boolean {
        sessionRepository.attachSessionToOrder(
            sessionId = sessionId,
            orderId = order.id,
            orderTitle = order.title,
            orderAddress = order.address,
        )
        uploadQueueRepository.resetSessionQueueItem(sessionId)
        return true
    }
    fun connectCamera() {
        viewModelScope.launch {
            Log.d("AppStateViewModel", "connectCamera(): provider=${cameraProvider::class.java.simpleName}")
            val status = cameraProvider.connect()
            Log.d("AppStateViewModel", "connectCamera(): result=$status")
            cameraStatus.value = status
        }
    }

    fun disconnectCamera() {
        viewModelScope.launch { cameraStatus.value = cameraProvider.disconnect() }
    }

    fun refreshCameraStatus() {
        viewModelScope.launch { cameraStatus.value = cameraProvider.getStatus() }
    }

    fun capturePoint(pointName: String) {
        val sessionId = uiState.value.selectedSessionId ?: return
        if (isCapturing.value) return
        viewModelScope.launch {
            isCapturing.value = true
            try {
                Log.d("AppStateViewModel", "capturePoint(): before capture, selectedSessionId=$sessionId, pointName=$pointName")
                val rawPoint: CapturePoint = cameraProvider.capture(pointName)
                Log.d(
                    "AppStateViewModel",
                    "capturePoint(): after cameraProvider.capture, id=${rawPoint.id}, name=${rawPoint.name}, status=${rawPoint.status}, previewUri=${rawPoint.previewUri}, cameraFileUrl=${rawPoint.cameraFileUrl}"

                )
                if (rawPoint.status == CaptureStatus.Failed || rawPoint.cameraFileUrl.isNullOrBlank()) {
                    Log.d("AppStateViewModel", "capturePoint(): capture failed, skip addPoint, pointId=${rawPoint.id}, status=${rawPoint.status}, cameraFileUrl=${rawPoint.cameraFileUrl}")
                    return@launch
                }
                Log.d("AppStateViewModel", "capturePoint(): before sessionRepository.addPoint, pointId=${rawPoint.id}")
                sessionRepository.addPoint(sessionId, rawPoint)
                Log.d(
                    "AppStateViewModel",
                    "capturePoint(): after sessionRepository.addPoint, pointId=${rawPoint.id}"
                )
                Log.d("AppStateViewModel", "capturePoint(): start background preview download, pointId=${rawPoint.id}")
                viewModelScope.launch {
                    runCatching {
                        localOriginalManager?.downloadPreviewForPoint(sessionId, rawPoint)
                    }.onSuccess {
                        Log.d("AppStateViewModel", "capturePoint(): background preview download finished for point=${rawPoint.id}")
                    }.onFailure {
                        Log.e("AppStateViewModel", "capturePoint(): background preview download failed for point=${rawPoint.id}", it)
                    }
                }
            } finally {
                Log.d("AppStateViewModel", "capturePoint(): before isCapturing=false")
                isCapturing.value = false
                Log.d("AppStateViewModel", "capturePoint(): isCapturing=false")
            }
        }
    }

    fun startVideoScan(scanName: String) {
        val sessionId = uiState.value.selectedSessionId
        Log.d("AppStateViewModel", "startVideoScan(): selectedSessionId=$sessionId")
        if (sessionId == null) return
        if (videoScanUiState.value == VideoScanUiState.SWITCHING_MODE || videoScanUiState.value == VideoScanUiState.RECORDING || videoScanUiState.value == VideoScanUiState.STOPPING || isCapturing.value || currentRecordingScanVideo.value != null) return

        viewModelScope.launch {
            Log.d("AppStateViewModel", "startVideoScan(): sessionId=$sessionId, scanName=$scanName")

            val now = java.time.Instant.now()
            val existingVideos = uiState.value.selectedSessionScanVideos.filter { it.sessionId == sessionId }
            val nextSequence = existingVideos.size + 1
            val role = if (existingVideos.none { it.captureStatus == ScanVideoCaptureStatus.CAPTURED }) ScanVideoRole.BACKBONE else ScanVideoRole.DETAIL
            val effectiveName = if (scanName.isBlank()) defaultVideoScanName(role, nextSequence) else scanName
            val scan = ScanVideo(
                sessionId = sessionId,
                name = effectiveName,
                sequenceNumber = nextSequence,
                captureStatus = ScanVideoCaptureStatus.RECORDING,
                role = role,
                createdAt = now,
                updatedAt = now,
            )
            Log.d("AppStateViewModel", "startVideoScan(): create recording scan, scanId=${scan.id}")
            sessionRepository.addScanVideo(scan)
            Log.d("AppStateViewModel", "saved scanVideo sessionId=${scan.sessionId}, scanId=${scan.id}")
            currentRecordingScanVideo.value = scan

            isCapturing.value = true
            videoScanUiState.value = VideoScanUiState.SWITCHING_MODE

            try {
                val result = cameraProvider.startVideoScan(scan.name)
                if (result.captureStatus == ScanVideoCaptureStatus.RECORDING) {
                    Log.d("AppStateViewModel", "startVideoScan(): recording confirmed, scanId=${scan.id}")

                    isRecordingScanVideo.value = true
                    videoScanUiState.value = VideoScanUiState.RECORDING
                } else {
                    sessionRepository.updateScanVideo(scan.copy(captureStatus = ScanVideoCaptureStatus.FAILED, updatedAt = java.time.Instant.now(), notes = result.notes ?: "startVideoScan failed"))
                    currentRecordingScanVideo.value = null
                    isRecordingScanVideo.value = false
                    videoScanUiState.value = VideoScanUiState.FAILED
                }
            } catch (e: Throwable) {
                sessionRepository.updateScanVideo(scan.copy(captureStatus = ScanVideoCaptureStatus.FAILED, updatedAt = java.time.Instant.now(), notes = e.message ?: "startVideoScan failed"))
                currentRecordingScanVideo.value = null
                isRecordingScanVideo.value = false
                videoScanUiState.value = VideoScanUiState.FAILED
            } finally {
                isCapturing.value = false
            }
        }
    }

    fun bindPhoneCameraPreview(
        previewView: PreviewView,
        cameraId: String?,
        zoomRatio: Float,
        videoMode: PhoneVideoMode?,
        onResult: (PhoneCameraBindResult) -> Unit = { _ -> },
    ) {
        val provider = phoneCameraScanProvider
        if (provider == null) {
            onResult(PhoneCameraBindResult(success = false, error = "camera provider unavailable", requestedZoomRatio = zoomRatio))
            return
        }
        viewModelScope.launch {
            try {
                val result = provider.bindPreview(
                    previewView,
                    cameraId,
                    zoomRatio,
                    videoMode,
                )
                onResult(result)
            } catch (e: Throwable) {
                Log.e("AppStateViewModel", "bindPhoneCameraPreview() failed", e)
                onResult(PhoneCameraBindResult(success = false, error = e.message ?: "preview bind failed", requestedZoomRatio = zoomRatio, cameraId = cameraId, activeBoundCameraId = cameraId))
            }
        }
    }

    fun startPhoneVideoScan(scanName: String) {
        val sessionId = uiState.value.selectedSessionId
        val provider = phoneCameraScanProvider
        Log.d("AppStateViewModel", "startPhoneVideoScan(): called selectedSessionId=$sessionId provider=${provider != null}")
        if (sessionId == null || provider == null) return
        if (videoScanUiState.value == VideoScanUiState.SWITCHING_MODE || videoScanUiState.value == VideoScanUiState.RECORDING || videoScanUiState.value == VideoScanUiState.STOPPING || isCapturing.value || currentRecordingScanVideo.value != null) return

        viewModelScope.launch {
            val now = java.time.Instant.now()
            val existingVideos = uiState.value.selectedSessionScanVideos.filter { it.sessionId == sessionId }
            val nextSequence = existingVideos.size + 1
            val role = if (existingVideos.none { it.captureStatus == ScanVideoCaptureStatus.CAPTURED }) ScanVideoRole.BACKBONE else ScanVideoRole.DETAIL
            val effectiveName = if (scanName.isBlank() || scanName == "Scan" || scanName.startsWith("Scan ")) defaultVideoScanName(role, nextSequence) else scanName
            val scan = ScanVideo(
                sessionId = sessionId,
                name = effectiveName,
                sequenceNumber = nextSequence,
                captureStatus = ScanVideoCaptureStatus.RECORDING,
                downloadState = ScanVideoDownloadState.DOWNLOADED,
                uploadState = ScanVideoUploadState.LOCAL_ONLY,
                source = ScanSource.PHONE_CAMERA,
                role = role,
                createdAt = now,
                updatedAt = now,
            )
            sessionRepository.addScanVideo(scan)
            currentRecordingScanVideo.value = scan
            isCapturing.value = true
            videoScanUiState.value = VideoScanUiState.SWITCHING_MODE
            try {
                val result = provider.startVideoScan(scan.name, sessionId, scan.id, scan.sequenceNumber)
                if (result.captureStatus == ScanVideoCaptureStatus.RECORDING) {
                    isRecordingScanVideo.value = true
                    Log.d("AppStateViewModel", "startPhoneVideoScan(): recording confirmed scanId=${scan.id}")
                    videoScanUiState.value = VideoScanUiState.RECORDING
                } else {
                    sessionRepository.updateScanVideo(scan.copy(captureStatus = ScanVideoCaptureStatus.FAILED, updatedAt = java.time.Instant.now(), notes = result.notes ?: "startPhoneVideoScan failed"))
                    currentRecordingScanVideo.value = null
                    isRecordingScanVideo.value = false
                    videoScanUiState.value = VideoScanUiState.FAILED
                }
            } catch (e: Throwable) {
                Log.e("AppStateViewModel", "startPhoneVideoScan(): errors", e)
                sessionRepository.updateScanVideo(scan.copy(captureStatus = ScanVideoCaptureStatus.FAILED, updatedAt = java.time.Instant.now(), notes = e.message ?: "startPhoneVideoScan failed"))
                currentRecordingScanVideo.value = null
                isRecordingScanVideo.value = false
                videoScanUiState.value = VideoScanUiState.FAILED
            } finally {
                isCapturing.value = false
            }
        }
    }

    fun stopPhoneVideoScan() {
        stopVideoScan()
    }

    fun stopVideoScan() {
        val current = currentRecordingScanVideo.value ?: return
        if (!isRecordingScanVideo.value || videoScanUiState.value == VideoScanUiState.STOPPING) return
        viewModelScope.launch {
            Log.d("AppStateViewModel", "stopVideoScan()")
            if (current.source == ScanSource.PHONE_CAMERA) Log.d("AppStateViewModel", "stopPhoneVideoScan(): called")
            videoScanUiState.value = VideoScanUiState.STOPPING
            val result = try {
                if (current.source == ScanSource.PHONE_CAMERA && phoneCameraScanProvider != null) {
                    phoneCameraScanProvider.stopVideoScan()
                } else {
                    cameraProvider.stopVideoScan()
                }
            } catch (e: Throwable) {
                ScanVideo(sessionId = current.sessionId, name = current.name, sequenceNumber = current.sequenceNumber, role = current.role, captureStatus = ScanVideoCaptureStatus.FAILED, notes = e.message ?: "stopVideoScan failed")
            }
            Log.d("AppStateViewModel", "stopVideoScan(): stop result raw=${result.notes}")
            Log.d("AppStateViewModel", "stopVideoScan(): parsed video fileUrl=${result.cameraFileUrl}, localFileUrl=${result.cameraLocalFileUrl}")
            val now = java.time.Instant.now()
            val durationSec = ((now.toEpochMilli() - current.createdAt.toEpochMilli()) / 1000L).coerceAtLeast(0L)
            val updated = if (result.captureStatus == ScanVideoCaptureStatus.CAPTURED) {
                current.copy(
                    cameraFileUrl = result.cameraFileUrl,
                    cameraLocalFileUrl = result.cameraLocalFileUrl,
                    durationSec = result.durationSec ?: durationSec,
                    fileSizeBytes = result.fileSizeBytes,
                    localVideoPath = result.localVideoPath,
                    source = result.source,
                    captureStatus = ScanVideoCaptureStatus.CAPTURED,
                    downloadState = result.downloadState,
                    uploadState = com.maklertour.domain.ScanVideoUploadState.LOCAL_ONLY,
                    serverProcessingState = com.maklertour.domain.ScanVideoProcessingState.NOT_STARTED,
                    updatedAt = now,
                    notes = result.notes,
                )
            } else {
                current.copy(captureStatus = ScanVideoCaptureStatus.FAILED, updatedAt = now, notes = result.notes)
            }
            sessionRepository.updateScanVideo(updated)
            if (updated.source == ScanSource.PHONE_CAMERA) Log.d("AppStateViewModel", "stopPhoneVideoScan(): saved localVideoPath=${updated.localVideoPath}, size=${updated.fileSizeBytes}, errors=${updated.notes}")
            Log.d("AppStateViewModel", "saved scanVideo sessionId=${updated.sessionId}, scanId=${updated.id}, status=${updated.captureStatus}")
            isRecordingScanVideo.value = false
            currentRecordingScanVideo.value = null
            videoScanUiState.value = if (updated.captureStatus == ScanVideoCaptureStatus.CAPTURED) VideoScanUiState.CAPTURED else VideoScanUiState.FAILED
        }
    }
    fun deleteScanVideo(scanVideoId: String) {
        sessionRepository.deleteScanVideo(scanVideoId)

        if (currentRecordingScanVideo.value?.id == scanVideoId) {
            currentRecordingScanVideo.value = null
            isRecordingScanVideo.value = false
        }
    }

    fun downloadVideoScan(scanId: String) {
        val sessionId = uiState.value.selectedSessionId ?: return
        val scan = uiState.value.selectedSessionScanVideos.firstOrNull { it.id == scanId && it.sessionId == sessionId } ?: return
        val cameraFileUrl = scan.cameraFileUrl
        Log.d("AppStateViewModel", "downloadVideoScan(): scanId=$scanId")
        Log.d("AppStateViewModel", "downloadVideoScan(): cameraFileUrl=$cameraFileUrl")
        if (cameraFileUrl.isNullOrBlank()) return
        if (scan.captureStatus != ScanVideoCaptureStatus.CAPTURED) return
        if (scan.downloadState == ScanVideoDownloadState.DOWNLOADING) return
        viewModelScope.launch {
            sessionRepository.updateScanVideo(scan.copy(downloadState = ScanVideoDownloadState.DOWNLOADING, updatedAt = java.time.Instant.now()))
            try {
                val filename = cameraFileUrl.substringAfterLast('/').substringBefore('?').ifBlank { "${scan.id}.mp4" }
                val mp4Name = if (filename.endsWith(".mp4", ignoreCase = true)) filename else "${filename.substringBeforeLast('.')}.mp4"
                val result = oscFileDownloader?.downloadToFile(cameraFileUrl, "sessions/$sessionId/videos/$mp4Name")
                if (result?.error == null && result?.localPath != null) {
                    Log.d("AppStateViewModel", "downloadVideoScan(): localVideoPath=${result.localPath}")
                    sessionRepository.updateScanVideo(
                        scan.copy(
                            localVideoPath = result.localPath,
                            downloadState = ScanVideoDownloadState.DOWNLOADED,
                            updatedAt = java.time.Instant.now(),
                        )
                    )
                } else {
                    Log.e("AppStateViewModel", "downloadVideoScan(): failed error=${result?.error}")
                    sessionRepository.updateScanVideo(scan.copy(downloadState = ScanVideoDownloadState.DOWNLOAD_ERROR, updatedAt = java.time.Instant.now()))
                }
            } catch (e: Throwable) {
                Log.e("AppStateViewModel", "downloadVideoScan(): failed error=${e.message}", e)
                sessionRepository.updateScanVideo(scan.copy(downloadState = ScanVideoDownloadState.DOWNLOAD_ERROR, updatedAt = java.time.Instant.now()))
            }
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

    fun createRoom(name: String, type: String = "OTHER") {
        val sessionId = uiState.value.selectedSessionId ?: return
        if (name.isNotBlank()) sessionRepository.createRoom(sessionId, name, type)
    }
    fun renameRoom(roomId: String, name: String) = sessionRepository.renameRoom(roomId, name)
    fun deleteRoom(roomId: String) = sessionRepository.deleteRoom(roomId)
    fun assignPointToRoom(pointId: String, roomId: String?) = sessionRepository.assignPointToRoom(pointId, roomId)
    fun setStartPoint(pointId: String) {
        val sessionId = uiState.value.selectedSessionId ?: return
        sessionRepository.setStartPoint(sessionId, pointId)
    }
    fun createConnection(fromPointId: String, toPointId: String) {
        val sessionId = uiState.value.selectedSessionId ?: return
        if (fromPointId != toPointId) sessionRepository.createConnection(sessionId, fromPointId, toPointId)
    }
    fun deleteConnection(connectionId: String) = sessionRepository.deleteConnection(connectionId)


    private fun hasPendingUploadMedia(session: Session, scanVideos: List<ScanVideo>): Boolean {
        val hasPendingPhoto = session.points.any { point ->
            point.serverUploadState != ServerUploadState.CONFIRMED || point.serverMediaId.isNullOrBlank()
        }
        val hasPendingVideo = scanVideos.any { scan ->
            val file = scan.localVideoPath?.let { File(it) }
            scan.captureStatus == ScanVideoCaptureStatus.CAPTURED &&
                !scan.localVideoPath.isNullOrBlank() &&
                file?.exists() == true &&
                scan.uploadState !in setOf(ScanVideoUploadState.CONFIRMED, ScanVideoUploadState.UPLOADED)
        }
        return hasPendingPhoto || hasPendingVideo
    }

    fun enqueueUpload(): EnqueueUploadResult = enqueueUploadInternal(false)

    private fun enqueueUploadInternal(forceVideoUpload: Boolean): EnqueueUploadResult {
        val session = uiState.value.sessions.firstOrNull { it.id == uiState.value.selectedSessionId }
            ?: return EnqueueUploadResult.Rejected("Сессия не выбрана.")

        val selectedOrderSnapshot = selectedOrder.value

        val targetOrderId = session.serverOrderId
            ?: selectedOrderSnapshot?.id
            ?: return EnqueueUploadResult.Rejected("Сессия не привязана к заявке. Выберите заявку перед загрузкой.")

        val targetOrderTitle = session.orderTitle ?: selectedOrderSnapshot?.title
        val targetOrderAddress = session.orderAddress ?: selectedOrderSnapshot?.address

        if (
            selectedOrderSnapshot?.id == targetOrderId &&
            (
                    selectedOrderSnapshot.status.uppercase() in setOf("READY", "COMPLETED", "CLOSED") ||
                            selectedOrderSnapshot.operatorClosedAt != null
                    )
        ) {
            return EnqueueUploadResult.Rejected("Заявка закрыта для загрузки.")
        }

        val sessionScanVideos = sessionRepository.scanVideos.value.filter { it.sessionId == session.id }
        if (!forceVideoUpload && !hasPendingUploadMedia(session, sessionScanVideos)) {
            return EnqueueUploadResult.Rejected("Нет новых файлов для загрузки. Все фото/видео уже синхронизированы.")
        }

        val existing = uiState.value.uploadQueue.firstOrNull {
            it.sessionId == session.id && it.orderId == targetOrderId
        }

        if (existing != null) {
            Log.d(
                "UploadQueue",
                "enqueue existing reset for new media sessionId=${session.id} orderId=$targetOrderId"
            )
            uploadQueueRepository.resetQueueItem(existing.id)
            uploadQueueRepository.updateProgress(existing.id, 0, 0L, 0L, null, "Queued new media")
            return EnqueueUploadResult.RequeuedNewMedia
        }

        val uploadAppSessionUuid = "${session.id}_${targetOrderId}"

        Log.d(
            "UploadQueue",
            "enqueue requested sessionId=${session.id} orderId=$targetOrderId"
        )

        uploadQueueRepository.enqueue(
            sessionId = session.id,
            sessionTitle = session.name,
            orderId = targetOrderId,
            orderTitle = targetOrderTitle,
            orderAddress = targetOrderAddress,
            bindingId = null,
            uploadAppSessionUuid = uploadAppSessionUuid,
            serverCaptureSessionId = null,
        )

        return EnqueueUploadResult.Enqueued
    }

    fun enqueueSyncedDepthCaptureBundle(
        context: android.content.Context,
        sessionId: String?,
        captureDir: File,
        calibrationSessionDir: File?,
        activeRigProfileJson: JSONObject?,
    ) {
        val session = uiState.value.sessions.firstOrNull { it.id == sessionId }
        captureBundleNotice.value = null
        viewModelScope.launch {
            try {
                Log.i("CaptureBundle", "packaging started captureDir=${captureDir.absolutePath}")
                val packageFile = CaptureBundlePackager(context.applicationContext).packageSyncedDepthCapture(
                    captureDir = captureDir,
                    calibrationSessionDir = calibrationSessionDir,
                    activeRigProfileJson = activeRigProfileJson,
                    outputRoot = File(context.filesDir, "upload_packages"), // files/upload_packages
                )
                val orderId = session?.serverOrderId ?: selectedOrder.value?.id
                uploadQueueRepository.enqueueCaptureBundle(
                    sessionId = session?.id ?: "pending_capture_bundle",
                    sessionTitle = session?.name,
                    orderId = orderId,
                    orderTitle = session?.orderTitle ?: selectedOrder.value?.title,
                    orderAddress = session?.orderAddress ?: selectedOrder.value?.address,
                    uploadAppSessionUuid = orderId?.let { "${session?.id ?: "pending"}_$it" },
                    appBundleUuid = null,
                    serverCaptureSessionId = session?.serverCaptureSessionId,
                    captureType = "synced_depth_frames",
                    localFilePath = packageFile.absolutePath,
                    displayName = "Synced depth capture bundle",
                    mimeType = "application/gzip",
                )
                captureBundleNotice.value = CaptureBundleNotice(
                    code = CaptureBundleNoticeCode.QUEUED,
                )
            } catch (e: CaptureBundlePreflightException) {
                captureBundleNotice.value = captureBundleNoticeFor(e)
                Log.e(
                    "CaptureBundle",
                    "synced depth preflight rejected bundle",
                    e,
                )
            } catch (e: Exception) {
                captureBundleNotice.value = CaptureBundleNotice(
                    code = CaptureBundleNoticeCode.PACKAGING_FAILED,
                    technicalDetail = e.message
                        ?.trim()
                        ?.takeIf { it.isNotEmpty() }
                        ?: e.javaClass.simpleName,
                )
                Log.e("CaptureBundle", "packaging failed", e)
            }
        }
    }

    fun dismissCaptureBundleNotice() {
        captureBundleNotice.value = null
    }

    fun enqueueLegacyStereoVideoBundle(
        context: android.content.Context,
        sessionId: String?,
        videoSessionDir: File,
        calibrationSessionDir: File?,
        activeRigProfileJson: JSONObject?,
    ) {
        val session = uiState.value.sessions.firstOrNull { it.id == sessionId }
        viewModelScope.launch {
            try {
                Log.i("CaptureBundle", "packaging started legacy video dir=${videoSessionDir.absolutePath}")
                val packageFile = CaptureBundlePackager(context.applicationContext).packageLegacyStereoVideoCapture(
                    videoSessionDir = videoSessionDir,
                    calibrationSessionDir = calibrationSessionDir,
                    activeRigProfileJson = activeRigProfileJson,
                    outputRoot = File(context.filesDir, "upload_packages"), // files/upload_packages
                )
                val orderId = session?.serverOrderId ?: selectedOrder.value?.id
                uploadQueueRepository.enqueueCaptureBundle(
                    sessionId = session?.id ?: "pending_capture_bundle",
                    sessionTitle = session?.name,
                    orderId = orderId,
                    orderTitle = session?.orderTitle ?: selectedOrder.value?.title,
                    orderAddress = session?.orderAddress ?: selectedOrder.value?.address,
                    uploadAppSessionUuid = orderId?.let { "${session?.id ?: "pending"}_$it" },
                    appBundleUuid = null,
                    serverCaptureSessionId = session?.serverCaptureSessionId,
                    captureType = "stereo_video_legacy",
                    localFilePath = packageFile.absolutePath,
                    displayName = "Legacy stereo video capture bundle",
                    mimeType = "application/gzip",
                )
            } catch (e: Exception) {
                Log.e("CaptureBundle", "packaging failed", e)
            }
        }
    }


    fun enqueueAutomaticPhotoSessionBundle(
        context: android.content.Context,
        sessionId: String?,
        captureDir: File,
    ) {
        val session = uiState.value.sessions.firstOrNull { it.id == sessionId }
        viewModelScope.launch {
            try {
                Log.i("AutoPhotoCapture", "packaging auto photo captureDir=${captureDir.absolutePath}")
                val packageFile = CaptureBundlePackager(context.applicationContext).packageAutomaticPhotoSession(
                    captureDir = captureDir,
                    outputRoot = File(context.filesDir, "upload_packages"),
                )
                val manifest = JSONObject(File(captureDir, "manifest.json").readText())
                val captureUuid = manifest.optString("capture_uuid").takeIf { it.isNotBlank() } ?: captureDir.name
                val orderId = session?.serverOrderId ?: selectedOrder.value?.id
                uploadQueueRepository.enqueueCaptureBundle(
                    sessionId = session?.id ?: "pending_auto_photo_bundle",
                    sessionTitle = session?.name,
                    orderId = orderId,
                    orderTitle = session?.orderTitle ?: selectedOrder.value?.title,
                    orderAddress = session?.orderAddress ?: selectedOrder.value?.address,
                    uploadAppSessionUuid = orderId?.let { "${session?.id ?: "pending"}_$it" },
                    appBundleUuid = captureUuid,
                    serverCaptureSessionId = session?.serverCaptureSessionId,
                    captureType = "auto_photo_session",
                    localFilePath = packageFile.absolutePath,
                    displayName = "Auto photo capture bundle",
                    mimeType = "application/gzip",
                )
                Log.i("AutoPhotoCapture", "queued auto photo bundle path=${packageFile.absolutePath} capture_uuid=$captureUuid orderId=$orderId")
            } catch (e: Exception) {
                Log.e("AutoPhotoCapture", "auto photo packaging failed", e)
            }
        }
    }

    fun processUpload(uploadId: String) {
        viewModelScope.launch { processUploadInternal(uploadId) }
    }


    fun clearAllUploadQueue() = uploadQueueRepository.clearAllUploadQueue()
    fun clearCompletedUploadQueue() = uploadQueueRepository.clearCompletedUploadQueue()
    fun clearFailedUploadQueue() = uploadQueueRepository.clearFailedUploadQueue()

    fun clearUploadQueueForSelectedSession() {
        val sessionId = uiState.value.selectedSessionId ?: return
        uploadQueueRepository.clearUploadQueueForSession(sessionId)
    }

    fun requeueVideo(scanVideoId: String): EnqueueUploadResult {
        val scan = sessionRepository.scanVideos.value.firstOrNull { it.id == scanVideoId }
            ?: return EnqueueUploadResult.Rejected("Видео не найдено.")
        val file = scan.localVideoPath?.let { File(it) }
        if (scan.localVideoPath.isNullOrBlank() || file?.exists() != true) {
            return EnqueueUploadResult.Rejected("Локальный файл видео не найден. Сначала скачайте/снимите видео заново.")
        }
        uploadQueueRepository.clearUploadQueueForSession(scan.sessionId)
        sessionRepository.updateScanVideoUploadState(scan.id, ScanVideoUploadState.LOCAL_ONLY)
        val result = enqueueUploadForSession(scan.sessionId, forceVideoUpload = true)
        if (result !is EnqueueUploadResult.Rejected) {
            sessionRepository.updateScanVideoUploadState(scan.id, ScanVideoUploadState.QUEUED)
        }
        return result
    }

    fun requeueAllVideosForSelectedSession(): String {
        val sessionId = uiState.value.selectedSessionId ?: return "Сессия не выбрана."
        val videos = sessionRepository.scanVideos.value.filter {
            it.sessionId == sessionId && it.captureStatus == ScanVideoCaptureStatus.CAPTURED
        }
        var added = 0
        var skipped = 0
        var errors = 0
        uploadQueueRepository.clearUploadQueueForSession(sessionId)
        videos.forEach { scan ->
            val file = scan.localVideoPath?.let { File(it) }
            if (scan.localVideoPath.isNullOrBlank() || file?.exists() != true) {
                skipped++
            } else {
                try {
                    sessionRepository.updateScanVideoUploadState(scan.id, ScanVideoUploadState.LOCAL_ONLY)
                    added++
                } catch (_: Exception) {
                    errors++
                }
            }
        }
        if (added > 0) {
            val result = enqueueUploadForSession(sessionId, forceVideoUpload = true)
            if (result !is EnqueueUploadResult.Rejected) {
                videos.filter { scan ->
                    !scan.localVideoPath.isNullOrBlank() && File(scan.localVideoPath).exists()
                }.forEach { sessionRepository.updateScanVideoUploadState(it.id, ScanVideoUploadState.QUEUED) }
            } else {
                errors++
            }
        }
        return "Добавлено видео: $added, пропущено: $skipped" + if (errors > 0) ", ошибок: $errors" else ""
    }

    private fun enqueueUploadForSession(sessionId: String, forceVideoUpload: Boolean = false): EnqueueUploadResult {
        val previousSelected = uiState.value.selectedSessionId
        return if (previousSelected == sessionId) enqueueUploadInternal(forceVideoUpload) else {
            EnqueueUploadResult.Rejected("Сессия не выбрана.")
        }
    }

    fun deleteUploadQueueItem(uploadId: String) {
        uploadQueueRepository.delete(uploadId)
    }

    fun resetUploadQueueItem(uploadId: String) {
        uploadQueueRepository.resetQueueItem(uploadId)
    }
    fun processQueuedUploadsOnWifi() {
        if (isAutoUploadRunning.value) return
        viewModelScope.launch {
            isAutoUploadRunning.value = true
            try {
                uploadQueueRepository.queue.value
                    .filter { it.status == UploadStatus.Queued }
                    .forEach { processUploadInternal(it.id) }
            } finally {
                isAutoUploadRunning.value = false
            }
        }
    }

    private suspend fun processUploadInternal(uploadId: String) {
        val item = uiState.value.uploadQueue.firstOrNull { it.id == uploadId } ?: return
            Log.d("Upload", "processUpload started uploadId=$uploadId")
            uploadQueueRepository.resetForRetry(uploadId)

            val session = uiState.value.sessions.firstOrNull { it.id == item.sessionId }
            Log.d("Upload", "selectedSessionId=${uiState.value.selectedSessionId}")
            Log.d("Upload", "serverOrderId=${session?.serverOrderId}")
            Log.d("Upload", "serverCaptureSessionId=${session?.serverCaptureSessionId}")
            Log.d("Upload", "points count=${session?.points?.size ?: 0}")
            val scanVideos = sessionRepository.scanVideos.value.filter { it.sessionId == item.sessionId }
            Log.d("Upload", "scanVideos count=${scanVideos.size}")
            scanVideos.forEach { scan ->
                Log.d(
                    "Upload",
                    "scanVideo scanId=${scan.id} source=${scan.source} localVideoPath=${scan.localVideoPath} downloadState=${scan.downloadState} uploadState=${scan.uploadState} captureStatus=${scan.captureStatus}"
                )
            }

        if (item.orderId == null) {
            val error = "В элементе очереди не выбрана заявка"
                uploadError.value = error
                uploadQueueRepository.updateStatus(uploadId, UploadStatus.Error)
                return
            }

        if (session == null) {
            uploadError.value = "Сессия для загрузки не найдена"
            uploadQueueRepository.updateStatus(uploadId, UploadStatus.Error)
            return
        }
        val orderId = item.orderId
        val appSessionUuid = item.uploadAppSessionUuid ?: "${item.sessionId}_${orderId}"
            uploadError.value = null

            val uploader = mobileUploadApi
            if (uploader == null) {
                uploadError.value = "MobileUploadApi is not configured"
                uploadQueueRepository.updateStatus(uploadId, UploadStatus.Error)
                Log.e("Upload", "MobileUploadApi is null; real server upload is unavailable")
                return
            }
            Log.d("Upload", "mobileUploadApi configured=true")

        val captureSessionId = item.serverCaptureSessionId ?: run {
            Log.d("Upload", "create_session request orderId=$orderId appSessionUuid=$appSessionUuid")
            val created = uploader.createSession(orderId = orderId, appSessionUuid = appSessionUuid)
                Log.d("Upload", "create_session response captureSessionId=$created")
                if (created <= 0L) {
                    uploadError.value = "Не удалось создать capture session на сервере"
                    uploadQueueRepository.updateStatus(uploadId, UploadStatus.Error)
                    Log.e("Upload", "create_session failed: captureSessionId=$created")
                    return
                }
            uploadQueueRepository.updateServerCaptureSessionId(uploadId, created)
            if (item.orderId == session.serverOrderId) {
                sessionRepository.updateServerCaptureSessionId(session.id, created)
            }
                created
            }
            Log.d("Upload", "captureSessionId used=$captureSessionId")

            var allUploaded = true
            if (item.uploadType == "CAPTURE_BUNDLE" || item.uploadType == "MAKLERTOUR_CAPTURE_BUNDLE") {
                val bundleFile = item.localFilePath?.let(::File)
                if (bundleFile == null || !bundleFile.exists() || bundleFile.length() <= 0L) {
                    uploadError.value = "Capture bundle file not found"
                    uploadQueueRepository.markUploadError(uploadId, item.displayName ?: "Capture bundle", "Capture bundle packaging failed")
                    Log.e("UploadQueue", "capture bundle file missing uploadId=$uploadId path=${item.localFilePath}")
                    return
                }
                val ok = uploader.uploadCaptureBundle(orderId, captureSessionId, bundleFile, item.captureType ?: "synced_depth_frames", item.appBundleUuid ?: item.uploadAppSessionUuid ?: appSessionUuid) { progress ->
                    val percent = if (progress.bytesTotal > 0) ((progress.bytesUploaded * 100L) / progress.bytesTotal).toInt().coerceIn(0, 100) else 0
                    uploadQueueRepository.updateProgress(uploadId, percent, progress.bytesUploaded, progress.bytesTotal, bundleFile.name, "Uploading Capture bundle ${item.captureType ?: ""}")
                }
                if (ok) {
                    uploadQueueRepository.markUploadSuccess(
                        uploadId = uploadId,
                        bytesUploaded = bundleFile.length(),
                        bytesTotal = bundleFile.length(),
                        currentFileName = bundleFile.name,
                        currentStep = "Uploaded Capture bundle",
                    )
                    Log.i("UploadQueue", "uploaded capture bundle uploadId=$uploadId path=${bundleFile.absolutePath}")
                } else {
                    uploadQueueRepository.markUploadError(uploadId, bundleFile.name, "Capture bundle upload failed")
                    uploadQueueRepository.incrementRetry(uploadId)
                    Log.e("UploadQueue", "capture bundle upload failed uploadId=$uploadId path=${bundleFile.absolutePath}")
                }
                return
            }
            scanVideos.forEach { scan ->
                val file = scan.localVideoPath?.let { path -> File(path) }
                val exists = file?.exists() == true
                val size = if (exists) file?.length() ?: 0L else 0L
                val decision = when {
                    scan.uploadState in setOf(ScanVideoUploadState.CONFIRMED, ScanVideoUploadState.UPLOADED) -> "skip"
                    scan.captureStatus != ScanVideoCaptureStatus.CAPTURED -> "skip"
                    scan.source == ScanSource.PHONE_CAMERA && (scan.localVideoPath.isNullOrBlank() || !exists || size <= 0L) -> "error"
                    !exists || size <= 0L -> "error"
                    else -> "upload"
                }
                Log.d(
                    "Upload",
                    "scanVideo decision scanId=${scan.id} source=${scan.source} captureStatus=${scan.captureStatus} uploadState=${scan.uploadState} localVideoPath=${scan.localVideoPath} exists=$exists length=$size decision=$decision"
                )
                if (decision == "skip") return@forEach

                if (scan.source == ScanSource.PHONE_CAMERA) {
                    if (scan.localVideoPath.isNullOrBlank()) {
                        Log.e("Upload", "Phone video file not found scanId=${scan.id}: localVideoPath is blank")
                        sessionRepository.updateScanVideo(scan.copy(uploadState = ScanVideoUploadState.UPLOAD_ERROR, notes = "Phone video file not found", updatedAt = java.time.Instant.now()))
                        allUploaded = false
                        return@forEach
                    }
                }

                if (!exists || size <= 0L) {
                    val message = if (scan.source == ScanSource.PHONE_CAMERA) "Phone video file not found" else "video file missing"
                    Log.e("Upload", "$message scanId=${scan.id} path=${scan.localVideoPath} exists=$exists size=$size")
                    if (scan.source == ScanSource.PHONE_CAMERA) {
                        sessionRepository.updateScanVideo(scan.copy(uploadState = ScanVideoUploadState.UPLOAD_ERROR, notes = message, updatedAt = java.time.Instant.now()))
                    } else {
                        sessionRepository.updateScanVideoUploadState(
                            scanVideoId = scan.id,
                            state = ScanVideoUploadState.UPLOAD_ERROR,
                        )
                    }
                    allUploaded = false
                    return@forEach
                }
                sessionRepository.updateScanVideoUploadState(
                    scanVideoId = scan.id,
                    state = ScanVideoUploadState.UPLOADING,
                )
                val result = uploader.uploadVideoScan(
                    orderId = orderId,
                    captureSessionId = captureSessionId,
                    scan = scan,
                ) { progress ->
                    val percent = if (progress.bytesTotal > 0) ((progress.bytesUploaded * 100L) / progress.bytesTotal).toInt().coerceIn(0, 100) else 0
                    uploadQueueRepository.updateProgress(uploadId, percent, progress.bytesUploaded, progress.bytesTotal, file?.name ?: scan.name, "Uploading video scan")

                }
                Log.d("Upload", "upload_video_scan result=$result")

                if (result) {
                    sessionRepository.updateScanVideoUploadState(
                        scanVideoId = scan.id,
                        state = ScanVideoUploadState.UPLOADED,
                    )
                } else {
                    sessionRepository.updateScanVideoUploadState(
                        scanVideoId = scan.id,
                        state = ScanVideoUploadState.UPLOAD_ERROR,
                    )
                    allUploaded = false
                }

            }

            session.points.forEach { point ->
                if (point.serverUploadState == ServerUploadState.CONFIRMED && !point.serverMediaId.isNullOrBlank()) {
                    Log.d("Upload", "skip confirmed photo pointId=${point.id}")
                    return@forEach
                }
                val previewFile = point.localPreviewPath?.let { path -> File(path) }
                val originalFile = point.localOriginalPath?.let { path -> File(path) }
                val hasPreview = previewFile?.exists() == true
                val hasOriginal = originalFile?.exists() == true
                Log.d("Upload", "photo files pointId=${point.id} previewExists=$hasPreview originalExists=$hasOriginal")
                if (!hasPreview && !hasOriginal) {
                    Log.e("Upload", "photo files missing pointId=${point.id}")
                    allUploaded = false
                    return@forEach
                }
                sessionRepository.updatePointServerUploadState(
                    pointId = point.id,
                    state = ServerUploadState.UPLOADING,
                )
                val result = uploader.uploadPhotoPoint(
                    orderId = orderId,
                    captureSessionId = captureSessionId,
                    point = point,
                ) { progress ->
                    val percent = if (progress.bytesTotal > 0) ((progress.bytesUploaded * 100L) / progress.bytesTotal).toInt().coerceIn(0, 100) else 0
                    uploadQueueRepository.updateProgress(uploadId, percent, progress.bytesUploaded, progress.bytesTotal, point.name, "Uploading photo point")
                }
                Log.d("Upload", "upload_photo_point result=$result")

                if (result) {
                    sessionRepository.updatePointServerUploadState(
                        pointId = point.id,
                        state = ServerUploadState.CONFIRMED,
                    )
                } else {
                    sessionRepository.updatePointServerUploadState(
                        pointId = point.id,
                        state = ServerUploadState.ERROR,
                    )
                    allUploaded = false
                }
            }

            if (allUploaded) {
                uploadQueueRepository.markUploadSuccess(
                    uploadId = uploadId,
                    bytesUploaded = 0L,
                    bytesTotal = 0L,
                    currentFileName = null,
                    currentStep = "Completed",
                )
                Log.d("Upload", "final Success uploadId=$uploadId")
            } else {
                val latest = uiState.value.uploadQueue.firstOrNull { it.id == uploadId }
                uploadQueueRepository.markUploadError(uploadId, latest?.currentFileName, "Upload failed")
                uploadQueueRepository.incrementRetry(uploadId)
                Log.e("Upload", "final Error uploadId=$uploadId")
            }
    }

    fun completeUpload(uploadId: String) {
        uploadQueueRepository.updateStatus(uploadId, UploadStatus.Success)
    }

    fun failUpload(uploadId: String) {
        uploadQueueRepository.updateStatus(uploadId, UploadStatus.Error)
    }

    fun downloadOriginalsForSession() {
        val sessionId = uiState.value.selectedSessionId ?: return
        viewModelScope.launch { localOriginalManager?.downloadOriginalsForSession(sessionId) }
    }

    fun syncSessionMetadata() {
        val sessionId = uiState.value.selectedSessionId ?: return
        viewModelScope.launch { syncRepository?.syncSessionMetadata(sessionId) }
    }

    fun clearConfirmedLocalOriginals() {
        val sessionId = uiState.value.selectedSessionId ?: return
        viewModelScope.launch { localOriginalManager?.deleteLocalOriginalsAfterServerConfirmed(sessionId) }
    }


    fun exportDiagnosticJson(debugMode: Boolean = false): String {
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
                                            put("cameraFileUrl", point.cameraFileUrl ?: JSONObject.NULL)
                                            put("localPreviewPath", point.localPreviewPath ?: JSONObject.NULL)
                                            put("localOriginalPath", point.localOriginalPath ?: JSONObject.NULL)
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
            put("scanVideos", JSONArray().apply {
                state.selectedSessionScanVideos.forEach { scan ->
                    put(JSONObject().apply {
                        put("id", scan.id)
                        put("sessionId", scan.sessionId)
                        put("name", scan.name)
                        put("sequenceNumber", scan.sequenceNumber)
                        put("captureStatus", scan.captureStatus.name)
                        if (debugMode) put("downloadState", scan.downloadState.name)
                        put("uploadState", scan.uploadState.name)
                        put("serverProcessingState", scan.serverProcessingState.name)
                        put("cameraFileUrl", scan.cameraFileUrl ?: JSONObject.NULL)
                        put("cameraLocalFileUrl", scan.cameraLocalFileUrl ?: JSONObject.NULL)
                        if (debugMode) put("localVideoPath", scan.localVideoPath ?: JSONObject.NULL)
                        put("durationSec", scan.durationSec ?: JSONObject.NULL)
                        put("notes", scan.notes ?: JSONObject.NULL)
                    })
                }
            })
            put("debugMode", debugMode)
        }.toString(2)
    }
}

private fun defaultVideoScanName(role: ScanVideoRole, sequenceNumber: Int): String = when (role) {
    ScanVideoRole.BACKBONE, ScanVideoRole.MAIN_PASS -> "Основной проход"
    ScanVideoRole.DETAIL -> "Детали $sequenceNumber"
}
