package com.maklertour.data.repository

import com.maklertour.domain.CapturePoint
import com.maklertour.domain.Session
import com.maklertour.domain.UploadItem
import com.maklertour.domain.UploadStatus
import java.time.Instant
import java.util.UUID
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.update

interface SessionRepository {
    val sessions: StateFlow<List<Session>>
    fun createSession(name: String, address: String, comment: String)
    fun addPoint(sessionId: String, point: CapturePoint)
    fun renamePoint(sessionId: String, pointId: String, newName: String)
    fun deletePoint(sessionId: String, pointId: String)
    fun movePoint(sessionId: String, fromIndex: Int, toIndex: Int)
}

interface UploadQueueRepository {
    val queue: StateFlow<List<UploadItem>>
    fun enqueue(sessionId: String)
    fun updateStatus(uploadId: String, status: UploadStatus)
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

    override fun createSession(name: String, address: String, comment: String) {
        _sessions.update { it + Session(name = name, address = address, comment = comment) }
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
}

class InMemoryUploadQueueRepository : UploadQueueRepository {
    private val _queue = MutableStateFlow<List<UploadItem>>(emptyList())
    override val queue: StateFlow<List<UploadItem>> = _queue

    override fun enqueue(sessionId: String) {
        _queue.update {
            it + UploadItem(
                id = UUID.randomUUID().toString(),
                sessionId = sessionId,
                status = UploadStatus.Queued,
                updatedAt = Instant.now(),
            )
        }
    }

    override fun updateStatus(uploadId: String, status: UploadStatus) {
        _queue.update { items ->
            items.map { item ->
                if (item.id == uploadId) item.copy(status = status, updatedAt = Instant.now()) else item
            }
        }
    }
}