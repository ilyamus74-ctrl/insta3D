package com.maklertour.data.sync

class SyncRepository(private val api: SyncApi) {
    suspend fun syncSessionMetadata(sessionId: String): Boolean = api.syncSessionMetadata(sessionId)
    suspend fun markPointServerConfirmed(pointId: String, serverMediaId: String): Boolean =
        api.markPointServerConfirmed(pointId, serverMediaId)
}