package com.maklertour.data.local.dao

import androidx.room.Dao
import androidx.room.Query
import androidx.room.Upsert
import com.maklertour.data.local.entity.DiagnosticLogEntity
import kotlinx.coroutines.flow.Flow

@Dao
interface DiagnosticLogDao {
    @Query("SELECT * FROM diagnostic_logs WHERE deletedAtEpochMs IS NULL ORDER BY updatedAtEpochMs DESC")
    fun observeAll(): Flow<List<DiagnosticLogEntity>>

    @Upsert
    suspend fun upsert(item: DiagnosticLogEntity)

    @Upsert
    suspend fun upsert(items: List<DiagnosticLogEntity>)

    @Query("SELECT * FROM diagnostic_logs WHERE syncState IN ('PENDING_CREATE','PENDING_UPDATE','PENDING_DELETE','SYNC_ERROR') ORDER BY updatedAtEpochMs ASC")
    suspend fun getPendingSync(): List<DiagnosticLogEntity>

    @Query("UPDATE diagnostic_logs SET remoteId = :remoteId, syncState = 'SYNCED', lastSyncAtEpochMs = :syncedAtEpochMs, lastSyncError = NULL, updatedAtEpochMs = :syncedAtEpochMs WHERE id = :id")
    suspend fun markSynced(id: String, remoteId: String?, syncedAtEpochMs: Long)

    @Query("UPDATE diagnostic_logs SET syncState = 'SYNC_ERROR', lastSyncError = :error, updatedAtEpochMs = :updatedAtEpochMs WHERE id = :id")
    suspend fun markSyncError(id: String, error: String, updatedAtEpochMs: Long)
}
