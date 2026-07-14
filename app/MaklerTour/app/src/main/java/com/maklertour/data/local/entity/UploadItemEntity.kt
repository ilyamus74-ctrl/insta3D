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
    val sessionTitle: String? = null,
    val serverOrderId: Long? = null,
    val orderTitle: String? = null,
    val orderAddress: String? = null,
    val bindingId: String? = null,
    val uploadAppSessionUuid: String? = null,
    val appBundleUuid: String? = null,
    val serverCaptureSessionId: Long? = null,
    val status: String,
    val retryCount: Int,
    val progressPercent: Int = 0,
    val bytesUploaded: Long = 0L,
    val bytesTotal: Long = 0L,
    val currentFileName: String? = null,
    val currentStep: String? = null,
    val uploadType: String = "MEDIA",
    val captureType: String? = null,
    val localFilePath: String? = null,
    val displayName: String? = null,
    val mimeType: String? = null,
    )
