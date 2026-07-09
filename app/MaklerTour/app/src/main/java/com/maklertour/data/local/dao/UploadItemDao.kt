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

    @Query("UPDATE upload_items SET syncState = 'PENDING_UPDATE', updatedAtEpochMs = :updatedAtEpochMs, status = 'Queued', progressPercent = 0, bytesUploaded = 0, bytesTotal = 0, currentFileName = NULL, currentStep = 'Interrupted, ready to retry' WHERE status = 'Uploading' AND deletedAtEpochMs IS NULL")
    suspend fun resetInterruptedUploads(updatedAtEpochMs: Long)

    @Query("""
        UPDATE upload_items
        SET progressPercent = :progressPercent,
            bytesUploaded = :bytesUploaded,
            bytesTotal = :bytesTotal,
            currentFileName = :currentFileName,
            currentStep = :currentStep,
            updatedAtEpochMs = :now,
            syncState = 'PENDING_UPDATE'
        WHERE id = :uploadId
    """)
    suspend fun updateProgress(
        uploadId: String,
        progressPercent: Int,
        bytesUploaded: Long,
        bytesTotal: Long,
        currentFileName: String?,
        currentStep: String?,
        now: Long,
    )

    @Query("""
        UPDATE upload_items
        SET status = 'Success',
            progressPercent = 100,
            bytesUploaded = :bytesUploaded,
            bytesTotal = :bytesTotal,
            currentFileName = :currentFileName,
            currentStep = :currentStep,
            updatedAtEpochMs = :now,
            syncState = 'PENDING_UPDATE'
        WHERE id = :uploadId
    """)
    suspend fun markUploadSuccess(
        uploadId: String,
        bytesUploaded: Long,
        bytesTotal: Long,
        currentFileName: String?,
        currentStep: String,
        now: Long,
    )

    @Query("""
        UPDATE upload_items
        SET status = 'Error',
            currentFileName = :currentFileName,
            currentStep = :currentStep,
            updatedAtEpochMs = :now,
            syncState = 'PENDING_UPDATE'
        WHERE id = :uploadId
    """)
    suspend fun markUploadError(
        uploadId: String,
        currentFileName: String?,
        currentStep: String,
        now: Long,
    )

    @Query("""
        UPDATE upload_items
        SET retryCount = retryCount + 1,
            updatedAtEpochMs = :now,
            syncState = 'PENDING_UPDATE'
        WHERE id = :uploadId
    """)
    suspend fun incrementRetry(uploadId: String, now: Long)

    @Query("DELETE FROM upload_items WHERE id = :id")
    suspend fun deleteById(id: String)

    @Query("DELETE FROM upload_items")
    suspend fun clearAll()

    @Query("DELETE FROM upload_items WHERE status = 'Success'")
    suspend fun clearCompleted()

    @Query("DELETE FROM upload_items WHERE status = 'Error'")
    suspend fun clearFailed()

    @Query("DELETE FROM upload_items WHERE captureSessionId = :sessionId")
    suspend fun clearForSession(sessionId: String)
}
