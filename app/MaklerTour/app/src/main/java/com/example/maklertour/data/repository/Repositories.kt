package com.maklertour.data.repository

import android.util.Log
import android.content.Context
import com.maklertour.data.local.SyncState
import com.maklertour.data.local.dao.CapturePointDao
import com.maklertour.data.local.dao.CaptureSessionDao
import com.maklertour.data.local.dao.RoomDao
import com.maklertour.data.local.dao.TourDraftConnectionDao
import com.maklertour.data.local.dao.UploadItemDao
import com.maklertour.data.local.entity.CapturePointEntity
import com.maklertour.data.local.entity.CaptureSessionEntity
import com.maklertour.data.local.entity.UploadItemEntity
import com.maklertour.domain.CapturePoint
import com.maklertour.domain.CaptureStatus
import com.maklertour.domain.DeleteState
import com.maklertour.domain.FileLocalState
import com.maklertour.domain.Session
import com.maklertour.domain.ServerUploadState
import com.maklertour.domain.RoomDraft
import com.maklertour.domain.TourDraftConnection
import com.maklertour.domain.UploadItem
import com.maklertour.domain.UploadStatus
import com.maklertour.domain.ScanVideoProcessingState
import com.maklertour.domain.ScanVideoUploadState
import com.maklertour.domain.ScanVideoRole
import com.maklertour.domain.ScanSource
import com.maklertour.domain.ScanVideoDownloadState
import com.maklertour.domain.ScanVideoCaptureStatus
import com.maklertour.data.local.entity.ScanVideoEntity
import com.maklertour.data.local.dao.ScanVideoDao
import com.maklertour.domain.ScanVideo
import org.json.JSONArray
import org.json.JSONObject
import java.time.Instant
import java.io.File
import java.util.UUID
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.SharingStarted
import kotlinx.coroutines.flow.combine
import kotlinx.coroutines.flow.map
import kotlinx.coroutines.flow.stateIn
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

interface SessionRepository {
    val sessions: StateFlow<List<Session>>
    val rooms: StateFlow<List<RoomDraft>>
    val connections: StateFlow<List<TourDraftConnection>>
    val scanVideos: StateFlow<List<ScanVideo>>
    fun createSession(name: String, address: String, comment: String, serverOrderId: Long? = null, orderTitle: String? = null, orderAddress: String? = null): String
    fun addPoint(sessionId: String, point: CapturePoint)
    fun renamePoint(sessionId: String, pointId: String, newName: String)
    fun deletePoint(sessionId: String, pointId: String)
    fun movePoint(sessionId: String, fromIndex: Int, toIndex: Int)
    fun createRoom(sessionId: String, name: String, type: String = "OTHER")
    fun renameRoom(roomId: String, name: String)
    fun deleteRoom(roomId: String)
    fun assignPointToRoom(pointId: String, roomId: String?)
    fun setStartPoint(sessionId: String, pointId: String)
    fun createConnection(sessionId: String, fromPointId: String, toPointId: String)
    fun deleteConnection(connectionId: String)
    fun addScanVideo(scanVideo: ScanVideo)
    fun updateScanVideo(scanVideo: ScanVideo)
    fun updatePointServerUploadState(pointId: String, state: ServerUploadState)
    fun updateScanVideoUploadState(scanVideoId: String, state: ScanVideoUploadState)
    fun deleteScanVideo(scanVideoId: String)
    fun deleteSession(sessionId: String)
    fun updateServerCaptureSessionId(sessionId: String, serverCaptureSessionId: Long)
    fun attachSessionToOrder(sessionId: String, orderId: Long, orderTitle: String?, orderAddress: String?)
}

interface UploadQueueRepository {
    val queue: StateFlow<List<UploadItem>>

    fun enqueue(
        sessionId: String,
        sessionTitle: String?,
        orderId: Long?,
        orderTitle: String?,
        orderAddress: String?,
        bindingId: String?,
        uploadAppSessionUuid: String?,
        serverCaptureSessionId: Long?,
    )
    fun enqueueCaptureBundle(
        sessionId: String,
        sessionTitle: String?,
        orderId: Long?,
        orderTitle: String?,
        orderAddress: String?,
        uploadAppSessionUuid: String?,
        appBundleUuid: String?,
        serverCaptureSessionId: Long?,
        captureType: String,
        localFilePath: String,
        displayName: String,
        mimeType: String = "application/gzip",
    )
    fun updateStatus(uploadId: String, status: UploadStatus)
    fun updateProgress(
        uploadId: String,
        progressPercent: Int,
        bytesUploaded: Long,
        bytesTotal: Long,
        currentFileName: String?,
        currentStep: String?,
    )
    fun markUploadSuccess(
        uploadId: String,
        bytesUploaded: Long,
        bytesTotal: Long,
        currentFileName: String?,
        currentStep: String,
    )
    fun markUploadError(
        uploadId: String,
        currentFileName: String?,
        currentStep: String,
    )
    fun incrementRetry(uploadId: String)
    fun resetForRetry(uploadId: String)
    fun resetQueueItem(uploadId: String)
    fun resetSessionQueueItem(sessionId: String)
    fun resetInterruptedUploadsOnStartup()
    fun updateServerCaptureSessionId(uploadId: String, serverCaptureSessionId: Long)
    fun delete(uploadId: String)
    fun clearAllUploadQueue()
    fun clearCompletedUploadQueue()
    fun clearFailedUploadQueue()
    fun clearUploadQueueForSession(sessionId: String)
    fun clearUploadQueueForVideo(scanVideoId: String)
}

class InMemorySessionRepository : SessionRepository {
    private val _sessions = MutableStateFlow(
        listOf(
            Session(
                name = "Демо: квартира на Ленина",
                address = "Москва, ул. Ленина, 10",
                comment = "Первый прогон MVP",
            )
        )
    )

    override val sessions: StateFlow<List<Session>> = _sessions
    override val rooms: StateFlow<List<RoomDraft>> = MutableStateFlow(emptyList())
    override val connections: StateFlow<List<TourDraftConnection>> = MutableStateFlow(emptyList())
    override val scanVideos: StateFlow<List<ScanVideo>> = MutableStateFlow(emptyList())

    override fun createSession(name: String, address: String, comment: String, serverOrderId: Long?, orderTitle: String?, orderAddress: String?): String {
        val session = Session(name = name, address = address, comment = comment)
        _sessions.update { it + session }
        return session.id
    }

    override fun addPoint(sessionId: String, point: CapturePoint) {
        _sessions.update { sessions ->
            sessions.map { session ->
                if (session.id != sessionId) session else session.copy(points = session.points + point)
            }
        }
    }

    override fun renamePoint(sessionId: String, pointId: String, newName: String) {
        _sessions.update { sessions ->
            sessions.map { session ->
                if (session.id != sessionId) {
                    session
                } else {
                    session.copy(
                        points = session.points.map { point ->
                            if (point.id == pointId) point.copy(name = newName) else point
                        }
                    )
                }
            }
        }
    }

    override fun deletePoint(sessionId: String, pointId: String) {
        _sessions.update { sessions ->
            sessions.map { session ->
                if (session.id != sessionId) session
                else session.copy(points = session.points.filterNot { it.id == pointId })
            }
        }
    }

    override fun movePoint(sessionId: String, fromIndex: Int, toIndex: Int) {
        _sessions.update { sessions ->
            sessions.map { session ->
                if (session.id != sessionId || fromIndex !in session.points.indices || toIndex !in session.points.indices) {
                    session
                } else {
                    val mutable = session.points.toMutableList()
                    val moved = mutable.removeAt(fromIndex)
                    mutable.add(toIndex, moved)
                    session.copy(points = mutable)
                }
            }
        }
    }
    override fun createRoom(sessionId: String, name: String, type: String) = Unit
    override fun renameRoom(roomId: String, name: String) = Unit
    override fun deleteRoom(roomId: String) = Unit
    override fun assignPointToRoom(pointId: String, roomId: String?) = Unit
    override fun setStartPoint(sessionId: String, pointId: String) = Unit
    override fun createConnection(sessionId: String, fromPointId: String, toPointId: String) = Unit
    override fun deleteConnection(connectionId: String) = Unit
    override fun addScanVideo(scanVideo: ScanVideo) = Unit
    override fun updateScanVideo(scanVideo: ScanVideo) = Unit
    override fun deleteScanVideo(scanVideoId: String) = Unit
    override fun updatePointServerUploadState(pointId: String, state: ServerUploadState) = Unit
    override fun updateScanVideoUploadState(scanVideoId: String, state: ScanVideoUploadState) = Unit
    override fun deleteSession(sessionId: String) {
        _sessions.update { sessions -> sessions.filterNot { it.id == sessionId } }
    }
    override fun updateServerCaptureSessionId(sessionId: String, serverCaptureSessionId: Long) {
        _sessions.update { sessions ->
            sessions.map { session ->
                if (session.id == sessionId) session.copy(serverCaptureSessionId = serverCaptureSessionId) else session
            }
        }
    }

    override fun attachSessionToOrder(sessionId: String, orderId: Long, orderTitle: String?, orderAddress: String?) {
        _sessions.update { sessions ->
            sessions.map { session ->
                if (session.id == sessionId) {
                    session.copy(
                        serverOrderId = orderId,
                        serverCaptureSessionId = null,
                        orderTitle = orderTitle,
                        orderAddress = orderAddress,
                    )
                } else {
                    session
                }
            }
        }
    }
}


class SharedPrefsSessionRepository(context: Context) : SessionRepository {
    private val prefs = context.getSharedPreferences("maklertour_storage", Context.MODE_PRIVATE)
    private val sessionsKey = "sessions"
    private val _sessions = MutableStateFlow(loadSessions())
    override val sessions: StateFlow<List<Session>> = _sessions
    override val rooms: StateFlow<List<RoomDraft>> = MutableStateFlow(emptyList())
    override val connections: StateFlow<List<TourDraftConnection>> = MutableStateFlow(emptyList())

    override val scanVideos: StateFlow<List<ScanVideo>> = MutableStateFlow(emptyList())

    override fun createSession(name: String, address: String, comment: String, serverOrderId: Long?, orderTitle: String?, orderAddress: String?): String {
        val session = Session(name = name, address = address, comment = comment)
        _sessions.update { it + session }
        persist()
        return session.id
    }

    override fun addPoint(sessionId: String, point: CapturePoint) {
        _sessions.update { sessions ->
            sessions.map { session ->
                if (session.id != sessionId) session else session.copy(points = session.points + point)
            }
        }
        persist()
    }

    override fun renamePoint(sessionId: String, pointId: String, newName: String) {
        _sessions.update { sessions ->
            sessions.map { session ->
                if (session.id != sessionId) {
                    session
                } else {
                    session.copy(
                        points = session.points.map { point ->
                            if (point.id == pointId) point.copy(name = newName) else point
                        }
                    )
                }
            }
        }
        persist()
    }

    override fun deletePoint(sessionId: String, pointId: String) {
        _sessions.update { sessions ->
            sessions.map { session ->
                if (session.id != sessionId) session
                else session.copy(points = session.points.filterNot { it.id == pointId })
            }
        }
        persist()
    }

    override fun movePoint(sessionId: String, fromIndex: Int, toIndex: Int) {
        _sessions.update { sessions ->
            sessions.map { session ->
                if (session.id != sessionId || fromIndex !in session.points.indices || toIndex !in session.points.indices) {
                    session
                } else {
                    val mutable = session.points.toMutableList()
                    val moved = mutable.removeAt(fromIndex)
                    mutable.add(toIndex, moved)
                    session.copy(points = mutable)
                }
            }
        }
        persist()
    }


    override fun createRoom(sessionId: String, name: String, type: String) = Unit
    override fun renameRoom(roomId: String, name: String) = Unit
    override fun deleteRoom(roomId: String) = Unit
    override fun assignPointToRoom(pointId: String, roomId: String?) = Unit
    override fun setStartPoint(sessionId: String, pointId: String) = Unit
    override fun createConnection(sessionId: String, fromPointId: String, toPointId: String) = Unit
    override fun deleteConnection(connectionId: String) = Unit
    override fun addScanVideo(scanVideo: ScanVideo) = Unit
    override fun updateScanVideo(scanVideo: ScanVideo) = Unit

    override fun updatePointServerUploadState(pointId: String, state: ServerUploadState) = Unit

    override fun updateScanVideoUploadState(scanVideoId: String, state: ScanVideoUploadState) = Unit

    override fun deleteScanVideo(scanVideoId: String) = Unit

    override fun deleteSession(sessionId: String) {
        _sessions.update { sessions -> sessions.filterNot { it.id == sessionId } }
        persist()
    }

    override fun updateServerCaptureSessionId(sessionId: String, serverCaptureSessionId: Long) {
        _sessions.update { sessions ->
            sessions.map { session ->
                if (session.id == sessionId) session.copy(serverCaptureSessionId = serverCaptureSessionId) else session
            }
        }
        persist()
    }

    override fun attachSessionToOrder(sessionId: String, orderId: Long, orderTitle: String?, orderAddress: String?) {
        _sessions.update { sessions ->
            sessions.map { session ->
                if (session.id == sessionId) {
                    session.copy(
                        serverOrderId = orderId,
                        serverCaptureSessionId = null,
                        orderTitle = orderTitle,
                        orderAddress = orderAddress,
                    )
                } else {
                    session
                }
            }
        }
        persist()
    }

    private fun persist() {
        val payload = JSONArray().apply {
            _sessions.value.forEach { session ->
                put(
                    JSONObject().apply {
                        put("id", session.id)
                        put("name", session.name)
                        put("address", session.address)
                        put("comment", session.comment)
                        put("serverOrderId", session.serverOrderId ?: JSONObject.NULL)
                        put("serverCaptureSessionId", session.serverCaptureSessionId ?: JSONObject.NULL)
                        put("orderTitle", session.orderTitle ?: JSONObject.NULL)
                        put("orderAddress", session.orderAddress ?: JSONObject.NULL)
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
        prefs.edit().putString(sessionsKey, payload.toString()).apply()
    }

    private fun loadSessions(): List<Session> {
        val raw = prefs.getString(sessionsKey, null) ?: return defaultSessions()
        return runCatching {
            val array = JSONArray(raw)
            buildList {
                for (i in 0 until array.length()) {
                    val sessionJson = array.getJSONObject(i)
                    add(
                        Session(
                            id = sessionJson.getString("id"),
                            name = sessionJson.getString("name"),
                            address = sessionJson.optString("address", ""),
                            comment = sessionJson.optString("comment", ""),
                            serverOrderId = sessionJson.optLong("serverOrderId").takeIf { !sessionJson.isNull("serverOrderId") },
                            serverCaptureSessionId = sessionJson.optLong("serverCaptureSessionId").takeIf { !sessionJson.isNull("serverCaptureSessionId") },
                            orderTitle = sessionJson.optString("orderTitle").takeIf { it.isNotBlank() && it != "null" },
                            orderAddress = sessionJson.optString("orderAddress").takeIf { it.isNotBlank() && it != "null" },
                            createdAt = Instant.parse(sessionJson.getString("createdAt")),
                            points = sessionJson.getJSONArray("points").toCapturePoints(),
                        )
                    )
                }
            }
        }.getOrElse { defaultSessions() }
    }

    private fun defaultSessions(): List<Session> {
        return listOf(
            Session(
                name = "Демо: квартира на Ленина",
                address = "Москва, ул. Ленина, 10",
                comment = "Первый прогон MVP",
            )
        )
    }
}

class InMemoryUploadQueueRepository : UploadQueueRepository {
    private val _queue = MutableStateFlow<List<UploadItem>>(emptyList())
    override val queue: StateFlow<List<UploadItem>> = _queue

    override fun enqueue(
        sessionId: String,
        sessionTitle: String?,
        orderId: Long?,
        orderTitle: String?,
        orderAddress: String?,
        bindingId: String?,
        uploadAppSessionUuid: String?,
        serverCaptureSessionId: Long?,
    ) {
        val uploadType = if (bindingId.isNullOrBlank()) "MEDIA" else "VIDEO"
        if (_queue.value.any {
                it.sessionId == sessionId &&
                    it.orderId == orderId &&
                    it.uploadType == uploadType &&
                    it.bindingId == bindingId
            }
        ) {
            Log.d(
                "UploadQueue",
                "enqueue duplicate ignored sessionId=$sessionId orderId=$orderId uploadType=$uploadType bindingId=$bindingId",
            )
            return
        }
        _queue.update {
            it + UploadItem(
                id = UUID.randomUUID().toString(),
                sessionId = sessionId,
                sessionTitle = sessionTitle,
                orderId = orderId,
                orderTitle = orderTitle,
                orderAddress = orderAddress,
                bindingId = bindingId,
                uploadAppSessionUuid = uploadAppSessionUuid,
                serverCaptureSessionId = serverCaptureSessionId,
                status = UploadStatus.Queued,
                updatedAt = Instant.now(),
                uploadType = uploadType,
            )
        }
    }

    override fun enqueueCaptureBundle(
        sessionId: String,
        sessionTitle: String?,
        orderId: Long?,
        orderTitle: String?,
        orderAddress: String?,
        uploadAppSessionUuid: String?,
        appBundleUuid: String?,
        serverCaptureSessionId: Long?,
        captureType: String,
        localFilePath: String,
        displayName: String,
        mimeType: String,
    ) {
        _queue.update {
            it + UploadItem(
                id = UUID.randomUUID().toString(),
                sessionId = sessionId,
                sessionTitle = sessionTitle,
                orderId = orderId,
                orderTitle = orderTitle,
                orderAddress = orderAddress,
                uploadAppSessionUuid = uploadAppSessionUuid,
                appBundleUuid = appBundleUuid,
                serverCaptureSessionId = serverCaptureSessionId,
                status = UploadStatus.Queued,
                updatedAt = Instant.now(),
                bytesTotal = File(localFilePath).length().coerceAtLeast(0L),
                currentFileName = File(localFilePath).name,
                currentStep = "Pending upload",
                uploadType = "CAPTURE_BUNDLE",
                captureType = captureType,
                localFilePath = localFilePath,
                displayName = displayName,
                mimeType = mimeType,
            )
        }
        Log.i("UploadQueue", "queued capture bundle path=$localFilePath captureType=$captureType sessionId=$sessionId orderId=$orderId")
    }

    override fun updateStatus(uploadId: String, status: UploadStatus) {
        _queue.update { items ->
            items.map { item ->
                if (item.id == uploadId) item.copy(status = status, updatedAt = Instant.now()) else item
            }
        }
    }

    override fun updateProgress(uploadId: String, progressPercent: Int, bytesUploaded: Long, bytesTotal: Long, currentFileName: String?, currentStep: String?) {
        _queue.update { items ->
            items.map { item ->
                if (item.id == uploadId) {
                    item.copy(
                        progressPercent = progressPercent.coerceIn(0, 100),
                        bytesUploaded = bytesUploaded.coerceAtLeast(0L),
                        bytesTotal = bytesTotal.coerceAtLeast(0L),
                        currentFileName = currentFileName,
                        currentStep = currentStep,
                        updatedAt = Instant.now(),
                    )
                } else item
            }
        }
    }

    override fun markUploadSuccess(uploadId: String, bytesUploaded: Long, bytesTotal: Long, currentFileName: String?, currentStep: String) {
        _queue.update { items ->
            items.map { item ->
                if (item.id == uploadId) {
                    item.copy(
                        status = UploadStatus.Success,
                        progressPercent = 100,
                        bytesUploaded = bytesUploaded.coerceAtLeast(0L),
                        bytesTotal = bytesTotal.coerceAtLeast(0L),
                        currentFileName = currentFileName,
                        currentStep = currentStep,
                        updatedAt = Instant.now(),
                    )
                } else item
            }
        }
    }

    override fun markUploadError(uploadId: String, currentFileName: String?, currentStep: String) {
        _queue.update { items ->
            items.map { item ->
                if (item.id == uploadId) {
                    item.copy(
                        status = UploadStatus.Error,
                        currentFileName = currentFileName,
                        currentStep = currentStep,
                        updatedAt = Instant.now(),
                    )
                } else item
            }
        }
    }

    override fun incrementRetry(uploadId: String) {
        _queue.update { items ->
            items.map { item ->
                if (item.id == uploadId) item.copy(retryCount = item.retryCount + 1, updatedAt = Instant.now()) else item
            }
        }
    }

    override fun resetForRetry(uploadId: String) {
        _queue.update { items ->
            items.map { item ->
                if (item.id == uploadId) {
                    item.copy(
                        status = UploadStatus.Uploading,
                        progressPercent = 0,
                        bytesUploaded = 0L,
                        bytesTotal = 0L,
                        currentStep = "Preparing upload",
                        currentFileName = null,
                        updatedAt = Instant.now(),
                    )
                } else item
            }
        }
    }

    override fun resetQueueItem(uploadId: String) {
        _queue.update { items ->
            items.map { item ->
                if (item.id == uploadId) {
                    item.copy(
                        status = UploadStatus.Queued,
                        progressPercent = 0,
                        bytesUploaded = 0L,
                        bytesTotal = 0L,
                        currentFileName = null,
                        currentStep = "Waiting",
                        updatedAt = Instant.now(),
                    )
                } else item
            }
        }
    }

    override fun resetSessionQueueItem(sessionId: String) {
        _queue.update { items ->
            items.map { item ->
                if (item.sessionId == sessionId) {
                    item.copy(
                        status = UploadStatus.Queued,
                        progressPercent = 0,
                        bytesUploaded = 0L,
                        bytesTotal = 0L,
                        currentFileName = null,
                        currentStep = "Order changed, upload required",
                        updatedAt = Instant.now(),
                    )
                } else item
            }
        }
    }

    override fun resetInterruptedUploadsOnStartup() {
        _queue.update { items ->
            items.map { item ->
                if (item.status == UploadStatus.Uploading) {
                    item.copy(
                        status = UploadStatus.Queued,
                        progressPercent = 0,
                        bytesUploaded = 0L,
                        bytesTotal = 0L,
                        currentFileName = null,
                        currentStep = "Interrupted, ready to retry",
                        updatedAt = Instant.now(),
                    )
                } else item
            }
        }
    }

    override fun updateServerCaptureSessionId(uploadId: String, serverCaptureSessionId: Long) {
        _queue.update { items ->
            items.map { item ->
                if (item.id == uploadId) item.copy(serverCaptureSessionId = serverCaptureSessionId, updatedAt = Instant.now()) else item
            }
        }
    }

    override fun delete(uploadId: String) {
        _queue.update { items -> items.filterNot { it.id == uploadId } }
    }
    override fun clearAllUploadQueue() { _queue.value = emptyList() }
    override fun clearCompletedUploadQueue() { _queue.update { it.filterNot { item -> item.status == UploadStatus.Success } } }
    override fun clearFailedUploadQueue() { _queue.update { it.filterNot { item -> item.status == UploadStatus.Error } } }
    override fun clearUploadQueueForSession(sessionId: String) { _queue.update { it.filterNot { item -> item.sessionId == sessionId } } }
    override fun clearUploadQueueForVideo(scanVideoId: String) {
        _queue.update { items ->
            items.filterNot { item ->
                item.uploadType == "VIDEO" && item.bindingId == scanVideoId
            }
        }
    }
}


class SharedPrefsUploadQueueRepository(context: Context) : UploadQueueRepository {
    private val prefs = context.getSharedPreferences("maklertour_storage", Context.MODE_PRIVATE)
    private val queueKey = "upload_queue"
    private val _queue = MutableStateFlow(loadQueue())
    override val queue: StateFlow<List<UploadItem>> = _queue

    override fun enqueue(
        sessionId: String,
        sessionTitle: String?,
        orderId: Long?,
        orderTitle: String?,
        orderAddress: String?,
        bindingId: String?,
        uploadAppSessionUuid: String?,
        serverCaptureSessionId: Long?,
    ) {
        val uploadType = if (bindingId.isNullOrBlank()) "MEDIA" else "VIDEO"
        if (_queue.value.any {
                it.sessionId == sessionId &&
                    it.orderId == orderId &&
                    it.uploadType == uploadType &&
                    it.bindingId == bindingId
            }
        ) {
            Log.d(
                "UploadQueue",
                "enqueue duplicate ignored sessionId=$sessionId orderId=$orderId uploadType=$uploadType bindingId=$bindingId",
            )
            return
        }
        _queue.update {
            it + UploadItem(
                id = UUID.randomUUID().toString(),
                sessionId = sessionId,
                sessionTitle = sessionTitle,
                orderId = orderId,
                orderTitle = orderTitle,
                orderAddress = orderAddress,
                bindingId = bindingId,
                uploadAppSessionUuid = uploadAppSessionUuid,
                serverCaptureSessionId = serverCaptureSessionId,
                status = UploadStatus.Queued,
                updatedAt = Instant.now(),
                uploadType = uploadType,
            )
        }
        persist()
    }

    override fun enqueueCaptureBundle(
        sessionId: String,
        sessionTitle: String?,
        orderId: Long?,
        orderTitle: String?,
        orderAddress: String?,
        uploadAppSessionUuid: String?,
        appBundleUuid: String?,
        serverCaptureSessionId: Long?,
        captureType: String,
        localFilePath: String,
        displayName: String,
        mimeType: String,
    ) {
        _queue.update {
            it + UploadItem(
                id = UUID.randomUUID().toString(),
                sessionId = sessionId,
                sessionTitle = sessionTitle,
                orderId = orderId,
                orderTitle = orderTitle,
                orderAddress = orderAddress,
                uploadAppSessionUuid = uploadAppSessionUuid,
                appBundleUuid = appBundleUuid,
                serverCaptureSessionId = serverCaptureSessionId,
                status = UploadStatus.Queued,
                updatedAt = Instant.now(),
                bytesTotal = File(localFilePath).length().coerceAtLeast(0L),
                currentFileName = File(localFilePath).name,
                currentStep = "Pending upload",
                uploadType = "CAPTURE_BUNDLE",
                captureType = captureType,
                localFilePath = localFilePath,
                displayName = displayName,
                mimeType = mimeType,
            )
        }
        persist()
        Log.i("UploadQueue", "queued capture bundle path=$localFilePath captureType=$captureType sessionId=$sessionId orderId=$orderId")
    }

    override fun updateStatus(uploadId: String, status: UploadStatus) {
        _queue.update { items ->
            items.map { item ->
                if (item.id == uploadId) item.copy(status = status, updatedAt = Instant.now()) else item
            }
        }
        persist()
    }

    override fun updateProgress(uploadId: String, progressPercent: Int, bytesUploaded: Long, bytesTotal: Long, currentFileName: String?, currentStep: String?) {
        _queue.update { items ->
            items.map { item ->
                if (item.id == uploadId) {
                    item.copy(
                        progressPercent = progressPercent.coerceIn(0, 100),
                        bytesUploaded = bytesUploaded.coerceAtLeast(0L),
                        bytesTotal = bytesTotal.coerceAtLeast(0L),
                        currentFileName = currentFileName,
                        currentStep = currentStep,
                        updatedAt = Instant.now(),
                    )
                } else item
            }
        }
        persist()
    }

    override fun markUploadSuccess(uploadId: String, bytesUploaded: Long, bytesTotal: Long, currentFileName: String?, currentStep: String) {
        _queue.update { items ->
            items.map { item ->
                if (item.id == uploadId) {
                    item.copy(
                        status = UploadStatus.Success,
                        progressPercent = 100,
                        bytesUploaded = bytesUploaded.coerceAtLeast(0L),
                        bytesTotal = bytesTotal.coerceAtLeast(0L),
                        currentFileName = currentFileName,
                        currentStep = currentStep,
                        updatedAt = Instant.now(),
                    )
                } else item
            }
        }
        persist()
    }

    override fun markUploadError(uploadId: String, currentFileName: String?, currentStep: String) {
        _queue.update { items ->
            items.map { item ->
                if (item.id == uploadId) {
                    item.copy(
                        status = UploadStatus.Error,
                        currentFileName = currentFileName,
                        currentStep = currentStep,
                        updatedAt = Instant.now(),
                    )
                } else item
            }
        }
        persist()
    }

    override fun incrementRetry(uploadId: String) {
        _queue.update { items ->
            items.map { item ->
                if (item.id == uploadId) {
                    item.copy(retryCount = item.retryCount + 1, updatedAt = Instant.now())
                } else {
                    item
                }
            }
        }
        persist()
    }

    override fun resetForRetry(uploadId: String) {
        _queue.update { items ->
            items.map { item ->
                if (item.id == uploadId) {
                    item.copy(
                        status = UploadStatus.Uploading,
                        progressPercent = 0,
                        bytesUploaded = 0L,
                        bytesTotal = 0L,
                        currentStep = "Preparing upload",
                        currentFileName = null,
                        updatedAt = Instant.now(),
                    )
                } else item
            }
        }
        persist()
    }

    override fun resetQueueItem(uploadId: String) {
        _queue.update { items ->
            items.map { item ->
                if (item.id == uploadId) {
                    item.copy(
                        status = UploadStatus.Queued,
                        progressPercent = 0,
                        bytesUploaded = 0L,
                        bytesTotal = 0L,
                        currentFileName = null,
                        currentStep = "Waiting",
                        updatedAt = Instant.now(),
                    )
                } else item
            }
        }
        persist()
    }

    override fun resetSessionQueueItem(sessionId: String) {
        _queue.update { items ->
            items.map { item ->
                if (item.sessionId == sessionId) {
                    item.copy(
                        status = UploadStatus.Queued,
                        progressPercent = 0,
                        bytesUploaded = 0L,
                        bytesTotal = 0L,
                        currentFileName = null,
                        currentStep = "Order changed, upload required",
                        updatedAt = Instant.now(),
                    )
                } else item
            }
        }
        persist()
    }

    override fun resetInterruptedUploadsOnStartup() {
        _queue.update { items ->
            items.map { item ->
                if (item.status == UploadStatus.Uploading) {
                    item.copy(
                        status = UploadStatus.Queued,
                        progressPercent = 0,
                        bytesUploaded = 0L,
                        bytesTotal = 0L,
                        currentFileName = null,
                        currentStep = "Interrupted, ready to retry",
                        updatedAt = Instant.now(),
                    )
                } else item
            }
        }
        persist()
    }


    override fun updateServerCaptureSessionId(uploadId: String, serverCaptureSessionId: Long) {
        _queue.update { items ->
            items.map { item ->
                if (item.id == uploadId) item.copy(serverCaptureSessionId = serverCaptureSessionId, updatedAt = Instant.now()) else item
            }
        }
        persist()
    }

    override fun delete(uploadId: String) {
        _queue.update { items -> items.filterNot { it.id == uploadId } }
        persist()
    }
    override fun clearAllUploadQueue() { _queue.value = emptyList(); persist() }
    override fun clearCompletedUploadQueue() { _queue.update { it.filterNot { item -> item.status == UploadStatus.Success } }; persist() }
    override fun clearFailedUploadQueue() { _queue.update { it.filterNot { item -> item.status == UploadStatus.Error } }; persist() }
    override fun clearUploadQueueForSession(sessionId: String) { _queue.update { it.filterNot { item -> item.sessionId == sessionId } }; persist() }
    override fun clearUploadQueueForVideo(scanVideoId: String) {
        _queue.update { items ->
            items.filterNot { item ->
                item.uploadType == "VIDEO" && item.bindingId == scanVideoId
            }
        }
        persist()
    }

    private fun persist() {
        val payload = JSONArray().apply {
            _queue.value.forEach { item ->
                put(
                    JSONObject().apply {
                        put("id", item.id)
                        put("sessionId", item.sessionId)
                        put("sessionTitle", item.sessionTitle)
                        put("orderId", item.orderId)
                        put("orderTitle", item.orderTitle)
                        put("orderAddress", item.orderAddress)
                        put("bindingId", item.bindingId)
                        put("uploadAppSessionUuid", item.uploadAppSessionUuid)
                        put("appBundleUuid", item.appBundleUuid)
                        put("serverCaptureSessionId", item.serverCaptureSessionId)
                        put("status", item.status.name)
                        put("retryCount", item.retryCount)
                        put("updatedAt", item.updatedAt.toString())
                        put("progressPercent", item.progressPercent)
                        put("bytesUploaded", item.bytesUploaded)
                        put("bytesTotal", item.bytesTotal)
                        put("currentFileName", item.currentFileName)
                        put("currentStep", item.currentStep)
                        put("uploadType", item.uploadType)
                        put("captureType", item.captureType)
                        put("localFilePath", item.localFilePath)
                        put("displayName", item.displayName)
                        put("mimeType", item.mimeType)
                    }
                )
            }
        }
        prefs.edit().putString(queueKey, payload.toString()).apply()
    }

    private fun loadQueue(): List<UploadItem> {
        val raw = prefs.getString(queueKey, null) ?: return emptyList()
        return runCatching {
            val array = JSONArray(raw)
            buildList {
                for (i in 0 until array.length()) {
                    val json = array.getJSONObject(i)
                    add(
                        UploadItem(
                            id = json.getString("id"),
                            sessionId = json.getString("sessionId"),
                            sessionTitle = json.optString("sessionTitle").takeIf { it.isNotBlank() && it != "null" },
                            orderId = json.optLong("orderId").takeIf { !json.isNull("orderId") },
                            orderTitle = json.optString("orderTitle").takeIf { it.isNotBlank() && it != "null" },
                            orderAddress = json.optString("orderAddress").takeIf { it.isNotBlank() && it != "null" },
                            bindingId = json.optString("bindingId").takeIf { it.isNotBlank() && it != "null" },
                            uploadAppSessionUuid = json.optString("uploadAppSessionUuid").takeIf { it.isNotBlank() && it != "null" },
                            appBundleUuid = json.optString("appBundleUuid").takeIf { it.isNotBlank() && it != "null" },
                            serverCaptureSessionId = json.optLong("serverCaptureSessionId").takeIf { !json.isNull("serverCaptureSessionId") },
                            status = UploadStatus.valueOf(json.getString("status")),
                            retryCount = json.optInt("retryCount", 0),
                            updatedAt = Instant.parse(json.getString("updatedAt")),
                            progressPercent = json.optInt("progressPercent", 0),
                            bytesUploaded = json.optLong("bytesUploaded", 0L),
                            bytesTotal = json.optLong("bytesTotal", 0L),
                            currentFileName = json.optString("currentFileName").takeIf { it.isNotBlank() && it != "null" },
                            currentStep = json.optString("currentStep").takeIf { it.isNotBlank() && it != "null" },
                            uploadType = json.optString("uploadType", "MEDIA").takeIf { it.isNotBlank() && it != "null" } ?: "MEDIA",
                            captureType = json.optString("captureType").takeIf { it.isNotBlank() && it != "null" },
                            localFilePath = json.optString("localFilePath").takeIf { it.isNotBlank() && it != "null" },
                            displayName = json.optString("displayName").takeIf { it.isNotBlank() && it != "null" },
                            mimeType = json.optString("mimeType").takeIf { it.isNotBlank() && it != "null" },
                        )
                    )
                }
            }
        }.getOrElse { emptyList() }
    }
}

private fun JSONArray.toCapturePoints(): List<CapturePoint> {
    return buildList {
        for (i in 0 until length()) {
            val json = getJSONObject(i)
            add(
                CapturePoint(
                    id = json.getString("id"),
                    name = json.getString("name"),
                    capturedAt = Instant.parse(json.getString("capturedAt")),
                    status = CaptureStatus.valueOf(json.optString("status", CaptureStatus.Draft.name)),
                    previewUri = json.optString("previewUri").takeIf { it.isNotBlank() && it != "null" },
                )
            )
        }
    }
}



class RoomSessionRepository(
    private val captureSessionDao: CaptureSessionDao,
    private val capturePointDao: CapturePointDao,
    private val roomDao: RoomDao,
    private val tourDraftConnectionDao: TourDraftConnectionDao,
    private val scanVideoDao: ScanVideoDao,
) : SessionRepository {
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)

    override val sessions: StateFlow<List<Session>> = combine(
        captureSessionDao.observeAll(),
        capturePointDao.observeAll(),
    ) { sessionEntities, pointEntities ->
        Log.d(
            "RoomSessionRepository",
            "combine(): sessions count=${sessionEntities.size}, pointEntities count=${pointEntities.size}, session ids=${sessionEntities.map { it.id }}, point captureSessionIds=${pointEntities.map { it.captureSessionId }}"
        )
        val pointsBySession = pointEntities.groupBy { it.captureSessionId }
        sessionEntities.map { sessionEntity ->
            Session(
                id = sessionEntity.id,
                name = sessionEntity.title,
                address = "",
                comment = "",
                createdAt = Instant.ofEpochMilli(sessionEntity.createdAtEpochMs),
                startPointId = sessionEntity.startPointId,
                points = pointsBySession[sessionEntity.id].orEmpty().map { it.toDomain() },
                serverOrderId = sessionEntity.serverOrderId,
                serverCaptureSessionId = sessionEntity.serverCaptureSessionId,
                orderTitle = sessionEntity.orderTitle,
                orderAddress = sessionEntity.orderAddress,
            )
        }
    }.stateIn(scope, SharingStarted.Eagerly, emptyList())
    override val rooms: StateFlow<List<RoomDraft>> = roomDao.observeAll().map { items ->
        items.map { it.toDomain() }
    }.stateIn(scope, SharingStarted.Eagerly, emptyList())
    override val connections: StateFlow<List<TourDraftConnection>> = tourDraftConnectionDao.observeAll().map { items ->
        items.map { it.toDomain() }
    }.stateIn(scope, SharingStarted.Eagerly, emptyList())
    override val scanVideos: StateFlow<List<ScanVideo>> = scanVideoDao.observeAll().map { items -> items.map { it.toDomain() } }.stateIn(scope, SharingStarted.Eagerly, emptyList())

    override fun createSession(name: String, address: String, comment: String, serverOrderId: Long?, orderTitle: String?, orderAddress: String?): String {
        val now = Instant.now().toEpochMilli()
        val session = CaptureSessionEntity(
            id = UUID.randomUUID().toString(),
            syncState = SyncState.PENDING_CREATE.name,
            createdAtEpochMs = now,
            updatedAtEpochMs = now,
            objectId = "local",
            title = name,
            startPointId = null,
            serverOrderId = serverOrderId,
            orderTitle = orderTitle,
            orderAddress = orderAddress,
        )
        scope.launch { captureSessionDao.upsert(session) }
        return session.id
    }

    override fun addPoint(sessionId: String, point: CapturePoint) {
        Log.d(
            "RoomSessionRepository",
            "addPoint(): sessionId=$sessionId, point.id=${point.id}, point.name=${point.name}, point.previewUri=${point.previewUri}, point.cameraFileUrl=${point.cameraFileUrl}"
        )
        val epochMs = point.capturedAt.toEpochMilli()
        val entity = CapturePointEntity(
            id = point.id,
            syncState = SyncState.PENDING_CREATE.name,
            createdAtEpochMs = epochMs,
            updatedAtEpochMs = epochMs,
            captureSessionId = sessionId,
            name = point.name,
            status = point.status.name,
            previewUri = point.previewUri,
            cameraFileUrl = point.cameraFileUrl,
            cameraLocalPath = point.cameraLocalPath,
            localPreviewPath = point.localPreviewPath,
            localOriginalPath = point.localOriginalPath,
            localOriginalState = point.localOriginalState.name,
            serverUploadState = point.serverUploadState.name,
            cameraDeleteState = point.cameraDeleteState.name,
            localDeleteState = point.localDeleteState.name,
            fileSizeBytes = point.fileSizeBytes,
            checksumSha256 = point.checksumSha256,
            serverMediaId = point.serverMediaId,
            serverConfirmedAtEpochMs = point.serverConfirmedAtEpochMs,
            roomId = point.roomId,
        )
        scope.launch { capturePointDao.upsert(entity) }
    }

    override fun renamePoint(sessionId: String, pointId: String, newName: String) {
        val now = Instant.now().toEpochMilli()
        scope.launch { capturePointDao.rename(pointId = pointId, newName = newName, updatedAtEpochMs = now) }
    }

    override fun deletePoint(sessionId: String, pointId: String) {
        val now = Instant.now().toEpochMilli()
        scope.launch { capturePointDao.softDelete(pointId = pointId, deletedAtEpochMs = now) }
    }

    override fun movePoint(sessionId: String, fromIndex: Int, toIndex: Int) { }
    override fun createRoom(sessionId: String, name: String, type: String) {
        val now = Instant.now().toEpochMilli()
        scope.launch {
            val nextIndex = rooms.value.count { it.sessionId == sessionId }
            roomDao.upsert(com.maklertour.data.local.entity.RoomEntity(UUID.randomUUID().toString(), syncState = SyncState.PENDING_CREATE.name, createdAtEpochMs = now, updatedAtEpochMs = now, objectId = "local", sessionId = sessionId, name = name, type = type, orderIndex = nextIndex))
        }
    }
    override fun renameRoom(roomId: String, name: String) { scope.launch { roomDao.rename(roomId, name, Instant.now().toEpochMilli()) } }
    override fun deleteRoom(roomId: String) { scope.launch { roomDao.softDelete(roomId, Instant.now().toEpochMilli()) } }
    override fun assignPointToRoom(pointId: String, roomId: String?) { scope.launch { capturePointDao.assignRoom(pointId, roomId, Instant.now().toEpochMilli()) } }
    override fun setStartPoint(sessionId: String, pointId: String) { scope.launch { captureSessionDao.setStartPoint(sessionId, pointId, Instant.now().toEpochMilli()) } }
    override fun createConnection(sessionId: String, fromPointId: String, toPointId: String) {
        val now = Instant.now().toEpochMilli()
        scope.launch {
            tourDraftConnectionDao.upsert(com.maklertour.data.local.entity.TourDraftConnectionEntity(id = UUID.randomUUID().toString(), syncState = SyncState.PENDING_CREATE.name, createdAtEpochMs = now, updatedAtEpochMs = now, sessionId = sessionId, fromPointId = fromPointId, toPointId = toPointId))
        }
    }
    override fun deleteConnection(connectionId: String) { scope.launch { tourDraftConnectionDao.delete(connectionId, Instant.now().toEpochMilli()) }
    }
    override fun addScanVideo(scanVideo: ScanVideo) {
        Log.d("RoomSessionRepository", "add/update scan video sessionId=${scanVideo.sessionId}, scanId=${scanVideo.id}, status=${scanVideo.captureStatus}")
        scope.launch { scanVideoDao.upsert(scanVideo.toEntity()) }
    }

    override fun updateScanVideo(scanVideo: ScanVideo) {
        Log.d("RoomSessionRepository", "add/update scan video sessionId=${scanVideo.sessionId}, scanId=${scanVideo.id}, status=${scanVideo.captureStatus}")
        scope.launch { scanVideoDao.upsert(scanVideo.toEntity()) }
    }

    override fun updatePointServerUploadState(pointId: String, state: ServerUploadState) {
        val now = Instant.now().toEpochMilli()
        val confirmedAt = if (state == ServerUploadState.CONFIRMED) now else null

        scope.launch {
            capturePointDao.updateServerUploadState(
                pointId = pointId,
                serverUploadState = state.name,
                serverConfirmedAtEpochMs = confirmedAt,
                updatedAtEpochMs = now,
            )
        }
    }

    override fun updateScanVideoUploadState(scanVideoId: String, state: ScanVideoUploadState) {
        val now = Instant.now().toEpochMilli()

        scope.launch {
            scanVideoDao.updateUploadState(
                scanVideoId = scanVideoId,
                uploadState = state.name,
                updatedAtEpochMs = now,
            )
        }
    }
    override fun deleteScanVideo(scanVideoId: String) {
        scope.launch { scanVideoDao.deleteById(scanVideoId) }
    }

    override fun deleteSession(sessionId: String) {
        val now = Instant.now().toEpochMilli()
        scope.launch {
            scanVideoDao.deleteBySessionId(sessionId)
            tourDraftConnectionDao.deleteBySessionId(sessionId, now)
            roomDao.deleteBySessionId(sessionId, now)
            capturePointDao.deleteBySessionId(sessionId, now)
            captureSessionDao.deleteById(sessionId, now)
        }
    }

    override fun updateServerCaptureSessionId(sessionId: String, serverCaptureSessionId: Long) {
        scope.launch {
            captureSessionDao.updateServerCaptureSessionId(sessionId, serverCaptureSessionId, Instant.now().toEpochMilli())
        }
    }

    override fun attachSessionToOrder(
        sessionId: String,
        orderId: Long,
        orderTitle: String?,
        orderAddress: String?,
    ) {
        val now = Instant.now().toEpochMilli()

        scope.launch {
            captureSessionDao.attachToOrder(
                sessionId = sessionId,
                orderId = orderId,
                orderTitle = orderTitle,
                orderAddress = orderAddress,
                updatedAtEpochMs = now,
            )
        }
    }
}


class RoomUploadQueueRepository(
    private val uploadItemDao: UploadItemDao,
) : UploadQueueRepository {
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)

    override val queue: StateFlow<List<UploadItem>> = uploadItemDao.observeAll().map { items ->
        items.map { it.toDomain() }
    }.stateIn(scope, SharingStarted.Eagerly, emptyList())

    override fun enqueue(
        sessionId: String,
        sessionTitle: String?,
        orderId: Long?,
        orderTitle: String?,
        orderAddress: String?,
        bindingId: String?,
        uploadAppSessionUuid: String?,
        serverCaptureSessionId: Long?,
    ) {
        val uploadType = if (bindingId.isNullOrBlank()) "MEDIA" else "VIDEO"
        if (queue.value.any {
                it.sessionId == sessionId &&
                    it.orderId == orderId &&
                    it.uploadType == uploadType &&
                    it.bindingId == bindingId
            }
        ) {
            Log.d(
                "UploadQueue",
                "enqueue duplicate ignored sessionId=$sessionId orderId=$orderId uploadType=$uploadType bindingId=$bindingId",
            )
            return
        }
        val now = Instant.now().toEpochMilli()
        scope.launch {
            uploadItemDao.upsert(
                UploadItemEntity(
                    id = UUID.randomUUID().toString(),
                    syncState = SyncState.PENDING_CREATE.name,
                    createdAtEpochMs = now,
                    updatedAtEpochMs = now,
                    captureSessionId = sessionId,
                    sessionTitle = sessionTitle,
                    serverOrderId = orderId,
                    orderTitle = orderTitle,
                    orderAddress = orderAddress,
                    bindingId = bindingId,
                    uploadAppSessionUuid = uploadAppSessionUuid,
                    serverCaptureSessionId = serverCaptureSessionId,
                    status = UploadStatus.Queued.name,
                    retryCount = 0,
                    uploadType = uploadType,
                )
            )
        }
    }

    override fun enqueueCaptureBundle(
        sessionId: String,
        sessionTitle: String?,
        orderId: Long?,
        orderTitle: String?,
        orderAddress: String?,
        uploadAppSessionUuid: String?,
        appBundleUuid: String?,
        serverCaptureSessionId: Long?,
        captureType: String,
        localFilePath: String,
        displayName: String,
        mimeType: String,
    ) {
        val now = Instant.now().toEpochMilli()
        scope.launch {
            uploadItemDao.upsert(
                UploadItemEntity(
                    id = UUID.randomUUID().toString(),
                    syncState = SyncState.PENDING_CREATE.name,
                    createdAtEpochMs = now,
                    updatedAtEpochMs = now,
                    captureSessionId = sessionId,
                    sessionTitle = sessionTitle,
                    serverOrderId = orderId,
                    orderTitle = orderTitle,
                    orderAddress = orderAddress,
                    bindingId = null,
                    uploadAppSessionUuid = uploadAppSessionUuid,
                    appBundleUuid = appBundleUuid,
                    serverCaptureSessionId = serverCaptureSessionId,
                    status = UploadStatus.Queued.name,
                    retryCount = 0,
                    bytesTotal = File(localFilePath).length().coerceAtLeast(0L),
                    currentFileName = File(localFilePath).name,
                    currentStep = "Pending upload",
                    uploadType = "CAPTURE_BUNDLE",
                    captureType = captureType,
                    localFilePath = localFilePath,
                    displayName = displayName,
                    mimeType = mimeType,
                )
            )
        }
        Log.i("UploadQueue", "queued capture bundle path=$localFilePath captureType=$captureType sessionId=$sessionId orderId=$orderId")
    }

    override fun updateStatus(uploadId: String, status: UploadStatus) {
        val current = queue.value.firstOrNull { it.id == uploadId } ?: return
        scope.launch {
            uploadItemDao.upsert(
                UploadItemEntity(
                    id = current.id,
                    syncState = SyncState.PENDING_UPDATE.name,
                    createdAtEpochMs = current.updatedAt.toEpochMilli(),
                    updatedAtEpochMs = Instant.now().toEpochMilli(),
                    captureSessionId = current.sessionId,
                    sessionTitle = current.sessionTitle,
                    serverOrderId = current.orderId,
                    orderTitle = current.orderTitle,
                    orderAddress = current.orderAddress,
                    bindingId = current.bindingId,
                    uploadAppSessionUuid = current.uploadAppSessionUuid,
                    appBundleUuid = current.appBundleUuid,
                    serverCaptureSessionId = current.serverCaptureSessionId,
                    status = status.name,
                    retryCount = current.retryCount,
                    progressPercent = current.progressPercent,
                    bytesUploaded = current.bytesUploaded,
                    bytesTotal = current.bytesTotal,
                    currentFileName = current.currentFileName,
                    currentStep = current.currentStep,
                    uploadType = current.uploadType,
                    captureType = current.captureType,
                    localFilePath = current.localFilePath,
                    displayName = current.displayName,
                    mimeType = current.mimeType,
                )
            )
        }
    }

    override fun updateProgress(uploadId: String, progressPercent: Int, bytesUploaded: Long, bytesTotal: Long, currentFileName: String?, currentStep: String?) {
        scope.launch {
            uploadItemDao.updateProgress(
                uploadId = uploadId,
                progressPercent = progressPercent.coerceIn(0, 100),
                bytesUploaded = bytesUploaded.coerceAtLeast(0L),
                bytesTotal = bytesTotal.coerceAtLeast(0L),
                currentFileName = currentFileName,
                currentStep = currentStep,
                now = Instant.now().toEpochMilli(),
            )
        }
    }

    override fun markUploadSuccess(uploadId: String, bytesUploaded: Long, bytesTotal: Long, currentFileName: String?, currentStep: String) {
        scope.launch {
            uploadItemDao.markUploadSuccess(
                uploadId = uploadId,
                bytesUploaded = bytesUploaded.coerceAtLeast(0L),
                bytesTotal = bytesTotal.coerceAtLeast(0L),
                currentFileName = currentFileName,
                currentStep = currentStep,
                now = Instant.now().toEpochMilli(),
            )
        }
    }

    override fun markUploadError(uploadId: String, currentFileName: String?, currentStep: String) {
        scope.launch {
            uploadItemDao.markUploadError(
                uploadId = uploadId,
                currentFileName = currentFileName,
                currentStep = currentStep,
                now = Instant.now().toEpochMilli(),
            )
        }
    }

    override fun incrementRetry(uploadId: String) {
        scope.launch {
            uploadItemDao.incrementRetry(
                uploadId = uploadId,
                now = Instant.now().toEpochMilli(),
            )
        }
    }

    override fun resetForRetry(uploadId: String) {
        val current = queue.value.firstOrNull { it.id == uploadId } ?: return
        scope.launch {
            uploadItemDao.upsert(
                UploadItemEntity(
                    id = current.id,
                    syncState = SyncState.PENDING_UPDATE.name,
                    createdAtEpochMs = current.updatedAt.toEpochMilli(),
                    updatedAtEpochMs = Instant.now().toEpochMilli(),
                    captureSessionId = current.sessionId,
                    sessionTitle = current.sessionTitle,
                    serverOrderId = current.orderId,
                    orderTitle = current.orderTitle,
                    orderAddress = current.orderAddress,
                    bindingId = current.bindingId,
                    uploadAppSessionUuid = current.uploadAppSessionUuid,
                    appBundleUuid = current.appBundleUuid,
                    serverCaptureSessionId = current.serverCaptureSessionId,
                    status = UploadStatus.Uploading.name,
                    retryCount = current.retryCount,
                    progressPercent = 0,
                    bytesUploaded = 0L,
                    bytesTotal = 0L,
                    currentFileName = null,
                    currentStep = "Preparing upload",
                    uploadType = current.uploadType,
                    captureType = current.captureType,
                    localFilePath = current.localFilePath,
                    displayName = current.displayName,
                    mimeType = current.mimeType,
                )
            )
        }
    }

    override fun resetQueueItem(uploadId: String) {
        val current = queue.value.firstOrNull { it.id == uploadId } ?: return
        scope.launch {
            uploadItemDao.upsert(
                UploadItemEntity(
                    id = current.id,
                    syncState = SyncState.PENDING_UPDATE.name,
                    createdAtEpochMs = current.updatedAt.toEpochMilli(),
                    updatedAtEpochMs = Instant.now().toEpochMilli(),
                    captureSessionId = current.sessionId,
                    sessionTitle = current.sessionTitle,
                    serverOrderId = current.orderId,
                    orderTitle = current.orderTitle,
                    orderAddress = current.orderAddress,
                    bindingId = current.bindingId,
                    uploadAppSessionUuid = current.uploadAppSessionUuid,
                    appBundleUuid = current.appBundleUuid,
                    serverCaptureSessionId = current.serverCaptureSessionId,
                    status = UploadStatus.Queued.name,
                    retryCount = current.retryCount,
                    progressPercent = 0,
                    bytesUploaded = 0L,
                    bytesTotal = 0L,
                    currentFileName = null,
                    currentStep = "Waiting",
                    uploadType = current.uploadType,
                    captureType = current.captureType,
                    localFilePath = current.localFilePath,
                    displayName = current.displayName,
                    mimeType = current.mimeType,
                )
            )
        }
    }

    override fun resetSessionQueueItem(sessionId: String) {
        val current = queue.value.firstOrNull { it.sessionId == sessionId } ?: return
        scope.launch {
            uploadItemDao.upsert(
                UploadItemEntity(
                    id = current.id,
                    syncState = SyncState.PENDING_UPDATE.name,
                    createdAtEpochMs = current.updatedAt.toEpochMilli(),
                    updatedAtEpochMs = Instant.now().toEpochMilli(),
                    captureSessionId = current.sessionId,
                    sessionTitle = current.sessionTitle,
                    serverOrderId = current.orderId,
                    orderTitle = current.orderTitle,
                    orderAddress = current.orderAddress,
                    bindingId = current.bindingId,
                    uploadAppSessionUuid = current.uploadAppSessionUuid,
                    appBundleUuid = current.appBundleUuid,
                    serverCaptureSessionId = current.serverCaptureSessionId,
                    status = UploadStatus.Queued.name,
                    retryCount = current.retryCount,
                    progressPercent = 0,
                    bytesUploaded = 0L,
                    bytesTotal = 0L,
                    currentFileName = null,
                    currentStep = "Order changed, upload required",
                    uploadType = current.uploadType,
                    captureType = current.captureType,
                    localFilePath = current.localFilePath,
                    displayName = current.displayName,
                    mimeType = current.mimeType,
                )
            )
        }
    }

    override fun updateServerCaptureSessionId(uploadId: String, serverCaptureSessionId: Long) {
        val current = queue.value.firstOrNull { it.id == uploadId } ?: return
        scope.launch {
            uploadItemDao.upsert(
                UploadItemEntity(
                    id = current.id,
                    syncState = SyncState.PENDING_UPDATE.name,
                    createdAtEpochMs = current.updatedAt.toEpochMilli(),
                    updatedAtEpochMs = Instant.now().toEpochMilli(),
                    captureSessionId = current.sessionId,
                    sessionTitle = current.sessionTitle,
                    serverOrderId = current.orderId,
                    orderTitle = current.orderTitle,
                    orderAddress = current.orderAddress,
                    bindingId = current.bindingId,
                    uploadAppSessionUuid = current.uploadAppSessionUuid,
                    appBundleUuid = current.appBundleUuid,
                    serverCaptureSessionId = serverCaptureSessionId,
                    status = current.status.name,
                    retryCount = current.retryCount,
                    progressPercent = current.progressPercent,
                    bytesUploaded = current.bytesUploaded,
                    bytesTotal = current.bytesTotal,
                    currentFileName = current.currentFileName,
                    currentStep = current.currentStep,
                    uploadType = current.uploadType,
                    captureType = current.captureType,
                    localFilePath = current.localFilePath,
                    displayName = current.displayName,
                    mimeType = current.mimeType,
                )
            )
        }
    }
    override fun resetInterruptedUploadsOnStartup() {
        scope.launch {
            uploadItemDao.resetInterruptedUploads(Instant.now().toEpochMilli())
        }
    }

    override fun delete(uploadId: String) {
        scope.launch {
            uploadItemDao.deleteById(uploadId)
        }
    }

    override fun clearAllUploadQueue() { scope.launch { uploadItemDao.clearAll() } }
    override fun clearCompletedUploadQueue() { scope.launch { uploadItemDao.clearCompleted() } }
    override fun clearFailedUploadQueue() { scope.launch { uploadItemDao.clearFailed() } }
    override fun clearUploadQueueForSession(sessionId: String) { scope.launch { uploadItemDao.clearForSession(sessionId) } }
    override fun clearUploadQueueForVideo(scanVideoId: String) {
        scope.launch { uploadItemDao.clearForVideo(scanVideoId) }
    }
}

private fun CapturePointEntity.toDomain(): CapturePoint = CapturePoint(
    id = id,
    name = name,
    capturedAt = Instant.ofEpochMilli(createdAtEpochMs),
    status = runCatching { CaptureStatus.valueOf(status) }.getOrDefault(CaptureStatus.Draft),
    previewUri = previewUri,
    cameraFileUrl = cameraFileUrl,
    cameraLocalPath = cameraLocalPath,
    localPreviewPath = localPreviewPath,
    localOriginalPath = localOriginalPath,
    localOriginalState = runCatching { FileLocalState.valueOf(localOriginalState) }.getOrDefault(FileLocalState.NOT_DOWNLOADED),
    serverUploadState = runCatching { ServerUploadState.valueOf(serverUploadState) }.getOrDefault(ServerUploadState.NOT_QUEUED),
    cameraDeleteState = runCatching { DeleteState.valueOf(cameraDeleteState) }.getOrDefault(DeleteState.NOT_DELETED),
    localDeleteState = runCatching { DeleteState.valueOf(localDeleteState) }.getOrDefault(DeleteState.NOT_DELETED),
    fileSizeBytes = fileSizeBytes,
    checksumSha256 = checksumSha256,
    serverMediaId = serverMediaId,
    serverConfirmedAtEpochMs = serverConfirmedAtEpochMs,
    roomId = roomId,
    )

private fun UploadItemEntity.toDomain(): UploadItem = UploadItem(
    id = id,
    sessionId = captureSessionId,
    sessionTitle = sessionTitle,
    orderId = serverOrderId,
    orderTitle = orderTitle,
    orderAddress = orderAddress,
    bindingId = bindingId,
    uploadAppSessionUuid = uploadAppSessionUuid,
    appBundleUuid = appBundleUuid,
    serverCaptureSessionId = serverCaptureSessionId,
    status = runCatching { UploadStatus.valueOf(status) }.getOrDefault(UploadStatus.Queued),
    retryCount = retryCount,
    updatedAt = Instant.ofEpochMilli(updatedAtEpochMs),
    progressPercent = progressPercent,
    bytesUploaded = bytesUploaded,
    bytesTotal = bytesTotal,
    currentFileName = currentFileName,
    currentStep = currentStep,
    uploadType = uploadType,
    captureType = captureType,
    localFilePath = localFilePath,
    displayName = displayName,
    mimeType = mimeType,
)

private fun com.maklertour.data.local.entity.RoomEntity.toDomain(): RoomDraft = RoomDraft(
    id = id, sessionId = sessionId, name = name, type = type, orderIndex = orderIndex, notes = notes, lengthM = lengthM, widthM = widthM, heightM = heightM
)
private fun com.maklertour.data.local.entity.TourDraftConnectionEntity.toDomain(): TourDraftConnection = TourDraftConnection(
    id = id, sessionId = sessionId, fromPointId = fromPointId, toPointId = toPointId, connectionType = connectionType

)
private fun ScanVideo.toEntity(): ScanVideoEntity = ScanVideoEntity(
    id=id, syncState=SyncState.PENDING_UPDATE.name, createdAtEpochMs=createdAt.toEpochMilli(), updatedAtEpochMs=updatedAt.toEpochMilli(),
    objectId=objectId, sessionId=sessionId, name=name, sequenceNumber=sequenceNumber, cameraFileUrl=cameraFileUrl, cameraLocalFileUrl=cameraLocalFileUrl,
    localPreviewPath=localPreviewPath, localVideoPath=localVideoPath, durationSec=durationSec, fileSizeBytes=fileSizeBytes, markerExpected=markerExpected,
    markerDetected=markerDetected, captureStatus=captureStatus.name, downloadState=downloadState.name, uploadState=uploadState.name,
    serverProcessingState=serverProcessingState.name, source=source.name, role=role?.name, notes=notes
)

private fun ScanVideoEntity.toDomain(): ScanVideo = ScanVideo(
    id=id, objectId=objectId, sessionId=sessionId, name=name, sequenceNumber=sequenceNumber, cameraFileUrl=cameraFileUrl, cameraLocalFileUrl=cameraLocalFileUrl,
    localPreviewPath=localPreviewPath, localVideoPath=localVideoPath, durationSec=durationSec, fileSizeBytes=fileSizeBytes, markerExpected=markerExpected,
    markerDetected=markerDetected, captureStatus=runCatching{ScanVideoCaptureStatus.valueOf(captureStatus)}.getOrDefault(ScanVideoCaptureStatus.DRAFT),
    downloadState=runCatching{ScanVideoDownloadState.valueOf(downloadState)}.getOrDefault(ScanVideoDownloadState.CAMERA_ONLY),
    uploadState=runCatching{ScanVideoUploadState.valueOf(uploadState)}.getOrDefault(ScanVideoUploadState.LOCAL_ONLY),
    serverProcessingState=runCatching{ScanVideoProcessingState.valueOf(serverProcessingState)}.getOrDefault(ScanVideoProcessingState.NOT_STARTED),
    source=runCatching{ScanSource.valueOf(source)}.getOrDefault(ScanSource.INSTA360),
    role=role?.let { runCatching{ScanVideoRole.valueOf(it)}.getOrNull() },
    createdAt=Instant.ofEpochMilli(createdAtEpochMs), updatedAt=Instant.ofEpochMilli(updatedAtEpochMs), notes=notes
)
