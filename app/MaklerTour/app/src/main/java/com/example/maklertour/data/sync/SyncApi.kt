package com.maklertour.data.sync

interface SyncApi {
    suspend fun syncSessionMetadata(sessionId: String): Boolean
    suspend fun markPointServerConfirmed(pointId: String, serverMediaId: String): Boolean
}

class MockSyncApi : SyncApi {
    override suspend fun syncSessionMetadata(sessionId: String): Boolean = true
    override suspend fun markPointServerConfirmed(pointId: String, serverMediaId: String): Boolean = true
}