package com.maklertour.data.local.entity

import androidx.room.Entity
import androidx.room.PrimaryKey

@Entity(tableName = "upload_items")
data class UploadItemEntity(
    @PrimaryKey val id: String,
    val remoteId: String? = null,
    val syncState: String,
    val createdAtEpochMs: Long,
    val updatedAtEpochMs: Long,
    val lastSyncAtEpochMs: Long? = null,
    val lastSyncError: String? = null,
    val deletedAtEpochMs: Long? = null,
    val captureSessionId: String,
    val status: String,
    val retryCount: Int,
    val progressPercent: Int = 0,
    val bytesUploaded: Long = 0L,
    val bytesTotal: Long = 0L,
    val currentFileName: String? = null,
    val currentStep: String? = null,
    )
