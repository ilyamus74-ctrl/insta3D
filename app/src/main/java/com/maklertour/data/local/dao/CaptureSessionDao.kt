package com.maklertour.data.local.dao

import androidx.room.Dao
import androidx.room.Query
import androidx.room.Upsert
import com.maklertour.data.local.entity.CaptureSessionEntity
import kotlinx.coroutines.flow.Flow

@Dao
interface CaptureSessionDao {
    @Query("SELECT * FROM capture_sessions WHERE deletedAtEpochMs IS NULL ORDER BY updatedAtEpochMs DESC")
    fun observeAll(): Flow<List<CaptureSessionEntity>>

    @Upsert
    suspend fun upsert(item: CaptureSessionEntity)

    @Upsert
    suspend fun upsert(items: List<CaptureSessionEntity>)

    @Query("SELECT * FROM capture_sessions WHERE syncState IN ('PENDING_CREATE','PENDING_UPDATE','PENDING_DELETE','SYNC_ERROR') ORDER BY updatedAtEpochMs ASC")
    suspend fun getPendingSync(): List<CaptureSessionEntity>

    @Query("UPDATE capture_sessions SET remoteId = :remoteId, syncState = 'SYNCED', lastSyncAtEpochMs = :syncedAtEpochMs, lastSyncError = NULL, updatedAtEpochMs = :syncedAtEpochMs WHERE id = :id")
    suspend fun markSynced(id: String, remoteId: String?, syncedAtEpochMs: Long)

    @Query("UPDATE capture_sessions SET syncState = 'SYNC_ERROR', lastSyncError = :error, updatedAtEpochMs = :updatedAtEpochMs WHERE id = :id")
    suspend fun markSyncError(id: String, error: String, updatedAtEpochMs: Long)

    @Query("UPDATE capture_sessions SET startPointId = :pointId, syncState = 'PENDING_UPDATE', updatedAtEpochMs = :updatedAtEpochMs WHERE id = :sessionId")
    suspend fun setStartPoint(sessionId: String, pointId: String?, updatedAtEpochMs: Long)

    @Query("""
        UPDATE capture_sessions
        SET serverCaptureSessionId = :serverCaptureSessionId,
            updatedAtEpochMs = :updatedAtEpochMs
        WHERE id = :sessionId
    """)
    suspend fun updateServerCaptureSessionId(
        sessionId: String,
        serverCaptureSessionId: Long,
        updatedAtEpochMs: Long
    )

    @Query(
        """
        UPDATE capture_sessions
        SET serverOrderId = :orderId,
            orderTitle = :orderTitle,
            orderAddress = :orderAddress,
            serverCaptureSessionId = NULL,
            syncState = 'PENDING_UPDATE',
            updatedAtEpochMs = :updatedAtEpochMs
        WHERE id = :sessionId
        """
    )
    suspend fun attachToOrder(sessionId: String, orderId: Long, orderTitle: String?, orderAddress: String?, updatedAtEpochMs: Long, )
    @Query("UPDATE capture_sessions SET deletedAtEpochMs = :deletedAtEpochMs, syncState = 'PENDING_DELETE', updatedAtEpochMs = :deletedAtEpochMs WHERE id = :sessionId")
    suspend fun deleteById(sessionId: String, deletedAtEpochMs: Long)
}
