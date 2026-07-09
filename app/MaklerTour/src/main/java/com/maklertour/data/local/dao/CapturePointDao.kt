package com.maklertour.data.local.dao

import androidx.room.Dao
import androidx.room.Query
import androidx.room.Upsert
import com.maklertour.data.local.entity.CapturePointEntity
import kotlinx.coroutines.flow.Flow

@Dao
interface CapturePointDao {
    @Query("SELECT * FROM capture_points WHERE deletedAtEpochMs IS NULL ORDER BY updatedAtEpochMs DESC")
    fun observeAll(): Flow<List<CapturePointEntity>>
    @Query("SELECT * FROM capture_points WHERE id = :pointId LIMIT 1")
    suspend fun getById(pointId: String): CapturePointEntity?
    @Query("SELECT * FROM capture_points WHERE captureSessionId = :sessionId AND deletedAtEpochMs IS NULL")
    suspend fun getBySessionId(sessionId: String): List<CapturePointEntity>

    @Upsert
    suspend fun upsert(item: CapturePointEntity)

    @Upsert
    suspend fun upsert(items: List<CapturePointEntity>)

    @Query("SELECT * FROM capture_points WHERE syncState IN ('PENDING_CREATE','PENDING_UPDATE','PENDING_DELETE','SYNC_ERROR') ORDER BY updatedAtEpochMs ASC")
    suspend fun getPendingSync(): List<CapturePointEntity>

    @Query("UPDATE capture_points SET remoteId = :remoteId, syncState = 'SYNCED', lastSyncAtEpochMs = :syncedAtEpochMs, lastSyncError = NULL, updatedAtEpochMs = :syncedAtEpochMs WHERE id = :id")
    suspend fun markSynced(id: String, remoteId: String?, syncedAtEpochMs: Long)

    @Query("UPDATE capture_points SET syncState = 'SYNC_ERROR', lastSyncError = :error, updatedAtEpochMs = :updatedAtEpochMs WHERE id = :id")
    suspend fun markSyncError(id: String, error: String, updatedAtEpochMs: Long)

    @Query("UPDATE capture_points SET name = :newName, syncState = 'PENDING_UPDATE', updatedAtEpochMs = :updatedAtEpochMs WHERE id = :pointId")
    suspend fun rename(pointId: String, newName: String, updatedAtEpochMs: Long)

    @Query("UPDATE capture_points SET deletedAtEpochMs = :deletedAtEpochMs, syncState = 'PENDING_DELETE', updatedAtEpochMs = :deletedAtEpochMs WHERE id = :pointId")
    suspend fun softDelete(pointId: String, deletedAtEpochMs: Long)

    @Query("UPDATE capture_points SET deletedAtEpochMs = :deletedAtEpochMs, syncState = 'PENDING_DELETE', updatedAtEpochMs = :deletedAtEpochMs WHERE captureSessionId = :sessionId AND deletedAtEpochMs IS NULL")
    suspend fun deleteBySessionId(sessionId: String, deletedAtEpochMs: Long)

    @Query("UPDATE capture_points SET roomId = :roomId, syncState = 'PENDING_UPDATE', updatedAtEpochMs = :updatedAtEpochMs WHERE id = :pointId")
    suspend fun assignRoom(pointId: String, roomId: String?, updatedAtEpochMs: Long)

    @Query(
        """
        UPDATE capture_points
        SET previewUri = :previewUri,
            localPreviewPath = :localPreviewPath,
            updatedAtEpochMs = :updatedAtEpochMs
        WHERE id = :pointId
        """
    )
    suspend fun updatePreview( pointId: String, previewUri: String?, localPreviewPath: String?, updatedAtEpochMs: Long,)

    @Query(
        """
    UPDATE capture_points
    SET serverUploadState = :serverUploadState,
        serverConfirmedAtEpochMs = :serverConfirmedAtEpochMs,
        updatedAtEpochMs = :updatedAtEpochMs
    WHERE id = :pointId
    """
    )
    suspend fun updateServerUploadState(pointId: String, serverUploadState: String, serverConfirmedAtEpochMs: Long?, updatedAtEpochMs: Long, )
}
