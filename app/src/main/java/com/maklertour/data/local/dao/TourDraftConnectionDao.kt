package com.maklertour.data.local.dao

import androidx.room.Dao
import androidx.room.Query
import androidx.room.Upsert
import com.maklertour.data.local.entity.TourDraftConnectionEntity
import kotlinx.coroutines.flow.Flow

@Dao
interface TourDraftConnectionDao {
    @Query("SELECT * FROM tour_draft_connections WHERE deletedAtEpochMs IS NULL ORDER BY updatedAtEpochMs DESC")
    fun observeAll(): Flow<List<TourDraftConnectionEntity>>

    @Query("SELECT * FROM tour_draft_connections WHERE sessionId = :sessionId AND deletedAtEpochMs IS NULL ORDER BY updatedAtEpochMs DESC")
    fun observeBySession(sessionId: String): Flow<List<TourDraftConnectionEntity>>

    @Upsert
    suspend fun upsert(item: TourDraftConnectionEntity)

    @Upsert
    suspend fun upsert(items: List<TourDraftConnectionEntity>)

    @Query("SELECT * FROM tour_draft_connections WHERE syncState IN ('PENDING_CREATE','PENDING_UPDATE','PENDING_DELETE','SYNC_ERROR') ORDER BY updatedAtEpochMs ASC")
    suspend fun getPendingSync(): List<TourDraftConnectionEntity>

    @Query("UPDATE tour_draft_connections SET remoteId = :remoteId, syncState = 'SYNCED', lastSyncAtEpochMs = :syncedAtEpochMs, lastSyncError = NULL, updatedAtEpochMs = :syncedAtEpochMs WHERE id = :id")
    suspend fun markSynced(id: String, remoteId: String?, syncedAtEpochMs: Long)

    @Query("UPDATE tour_draft_connections SET syncState = 'SYNC_ERROR', lastSyncError = :error, updatedAtEpochMs = :updatedAtEpochMs WHERE id = :id")
    suspend fun markSyncError(id: String, error: String, updatedAtEpochMs: Long)

    @Query("UPDATE tour_draft_connections SET deletedAtEpochMs = :deletedAtEpochMs, syncState = 'PENDING_DELETE', updatedAtEpochMs = :deletedAtEpochMs WHERE id = :connectionId")
    suspend fun delete(connectionId: String, deletedAtEpochMs: Long)

    @Query("UPDATE tour_draft_connections SET deletedAtEpochMs = :deletedAtEpochMs, syncState = 'PENDING_DELETE', updatedAtEpochMs = :deletedAtEpochMs WHERE sessionId = :sessionId AND deletedAtEpochMs IS NULL")
    suspend fun deleteBySessionId(sessionId: String, deletedAtEpochMs: Long)
}