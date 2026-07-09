package com.maklertour.data.local.dao

import androidx.room.Dao
import androidx.room.Query
import androidx.room.Upsert
import com.maklertour.data.local.entity.RoomEntity
import kotlinx.coroutines.flow.Flow

@Dao
interface RoomDao {
    @Query("SELECT * FROM rooms WHERE deletedAtEpochMs IS NULL ORDER BY updatedAtEpochMs DESC")
    fun observeAll(): Flow<List<RoomEntity>>

    @Query("SELECT * FROM rooms WHERE sessionId = :sessionId AND deletedAtEpochMs IS NULL ORDER BY orderIndex ASC, updatedAtEpochMs DESC")
    fun observeBySession(sessionId: String): Flow<List<RoomEntity>>

    @Upsert
    suspend fun upsert(item: RoomEntity)

    @Upsert
    suspend fun upsert(items: List<RoomEntity>)

    @Query("SELECT * FROM rooms WHERE syncState IN ('PENDING_CREATE','PENDING_UPDATE','PENDING_DELETE','SYNC_ERROR') ORDER BY updatedAtEpochMs ASC")
    suspend fun getPendingSync(): List<RoomEntity>

    @Query("UPDATE rooms SET remoteId = :remoteId, syncState = 'SYNCED', lastSyncAtEpochMs = :syncedAtEpochMs, lastSyncError = NULL, updatedAtEpochMs = :syncedAtEpochMs WHERE id = :id")
    suspend fun markSynced(id: String, remoteId: String?, syncedAtEpochMs: Long)

    @Query("UPDATE rooms SET syncState = 'SYNC_ERROR', lastSyncError = :error, updatedAtEpochMs = :updatedAtEpochMs WHERE id = :id")
    suspend fun markSyncError(id: String, error: String, updatedAtEpochMs: Long)

    @Query("UPDATE rooms SET name = :name, syncState = 'PENDING_UPDATE', updatedAtEpochMs = :updatedAtEpochMs WHERE id = :roomId")
    suspend fun rename(roomId: String, name: String, updatedAtEpochMs: Long)

    @Query("UPDATE rooms SET deletedAtEpochMs = :deletedAtEpochMs, syncState = 'PENDING_DELETE', updatedAtEpochMs = :deletedAtEpochMs WHERE id = :roomId")
    suspend fun softDelete(roomId: String, deletedAtEpochMs: Long)
    @Query("UPDATE rooms SET deletedAtEpochMs = :deletedAtEpochMs, syncState = 'PENDING_DELETE', updatedAtEpochMs = :deletedAtEpochMs WHERE sessionId = :sessionId AND deletedAtEpochMs IS NULL")
    suspend fun deleteBySessionId(sessionId: String, deletedAtEpochMs: Long)
}