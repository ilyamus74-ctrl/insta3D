package com.maklertour.data.repository

import android.content.Context
import com.maklertour.domain.CapturePoint
import com.maklertour.domain.CaptureStatus
import com.maklertour.domain.Session
import com.maklertour.domain.UploadItem
import com.maklertour.domain.UploadStatus
import org.json.JSONArray
import org.json.JSONObject
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
    fun incrementRetry(uploadId: String)
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


class SharedPrefsSessionRepository(context: Context) : SessionRepository {
    private val prefs = context.getSharedPreferences("maklertour_storage", Context.MODE_PRIVATE)
    private val sessionsKey = "sessions"
    private val _sessions = MutableStateFlow(loadSessions())
    override val sessions: StateFlow<List<Session>> = _sessions

    override fun createSession(name: String, address: String, comment: String) {
        _sessions.update { it + Session(name = name, address = address, comment = comment) }
        persist()
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

    private fun persist() {
        val payload = JSONArray().apply {
            _sessions.value.forEach { session ->
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
    }
}

class SharedPrefsUploadQueueRepository(context: Context) : UploadQueueRepository {
    private val prefs = context.getSharedPreferences("maklertour_storage", Context.MODE_PRIVATE)
    private val queueKey = "upload_queue"
    private val _queue = MutableStateFlow(loadQueue())
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
        persist()
    }

    override fun updateStatus(uploadId: String, status: UploadStatus) {
        _queue.update { items ->
            items.map { item ->
                if (item.id == uploadId) item.copy(status = status, updatedAt = Instant.now()) else item
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

    private fun persist() {
        val payload = JSONArray().apply {
            _queue.value.forEach { item ->
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
                            status = UploadStatus.valueOf(json.getString("status")),
                            retryCount = json.optInt("retryCount", 0),
                            updatedAt = Instant.parse(json.getString("updatedAt")),
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
