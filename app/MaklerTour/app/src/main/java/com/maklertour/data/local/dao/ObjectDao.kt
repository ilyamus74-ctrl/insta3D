package com.maklertour.data.local.dao

import androidx.room.Dao
import androidx.room.Query
import androidx.room.Upsert
import com.maklertour.data.local.entity.ObjectEntity
import kotlinx.coroutines.flow.Flow

@Dao
interface ObjectDao {
    @Query("SELECT * FROM objects WHERE deletedAtEpochMs IS NULL ORDER BY updatedAtEpochMs DESC")
    fun observeAll(): Flow<List<ObjectEntity>>

    @Upsert
    suspend fun upsert(item: ObjectEntity)

    @Upsert
    suspend fun upsert(items: List<ObjectEntity>)

    @Query("SELECT * FROM objects WHERE syncState IN ('PENDING_CREATE','PENDING_UPDATE','PENDING_DELETE','SYNC_ERROR') ORDER BY updatedAtEpochMs ASC")
    suspend fun getPendingSync(): List<ObjectEntity>

    @Query("UPDATE objects SET remoteId = :remoteId, syncState = 'SYNCED', lastSyncAtEpochMs = :syncedAtEpochMs, lastSyncError = NULL, updatedAtEpochMs = :syncedAtEpochMs WHERE id = :id")
    suspend fun markSynced(id: String, remoteId: String?, syncedAtEpochMs: Long)

    @Query("UPDATE objects SET syncState = 'SYNC_ERROR', lastSyncError = :error, updatedAtEpochMs = :updatedAtEpochMs WHERE id = :id")
    suspend fun markSyncError(id: String, error: String, updatedAtEpochMs: Long)
}