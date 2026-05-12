package com.maklertour.data.local.dao

import androidx.room.Dao
import androidx.room.Query
import androidx.room.Upsert
import com.maklertour.data.local.entity.UploadItemEntity
import kotlinx.coroutines.flow.Flow

@Dao
interface UploadItemDao {
    @Query("SELECT * FROM upload_items WHERE deletedAtEpochMs IS NULL ORDER BY updatedAtEpochMs DESC")
    fun observeAll(): Flow<List<UploadItemEntity>>

    @Upsert
    suspend fun upsert(item: UploadItemEntity)

    @Upsert
    suspend fun upsert(items: List<UploadItemEntity>)

    @Query("SELECT * FROM upload_items WHERE syncState IN ('PENDING_CREATE','PENDING_UPDATE','PENDING_DELETE','SYNC_ERROR') ORDER BY updatedAtEpochMs ASC")
    suspend fun getPendingSync(): List<UploadItemEntity>

    @Query("UPDATE upload_items SET remoteId = :remoteId, syncState = 'SYNCED', lastSyncAtEpochMs = :syncedAtEpochMs, lastSyncError = NULL, updatedAtEpochMs = :syncedAtEpochMs WHERE id = :id")
    suspend fun markSynced(id: String, remoteId: String?, syncedAtEpochMs: Long)

    @Query("UPDATE upload_items SET syncState = 'SYNC_ERROR', lastSyncError = :error, updatedAtEpochMs = :updatedAtEpochMs WHERE id = :id")
    suspend fun markSyncError(id: String, error: String, updatedAtEpochMs: Long)
}
