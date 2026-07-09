package com.maklertour.data.local.entity

import androidx.room.Entity
import androidx.room.PrimaryKey

@Entity(tableName = "capture_points")
data class CapturePointEntity(
    @PrimaryKey val id: String,
    val remoteId: String? = null,
    val syncState: String,
    val createdAtEpochMs: Long,
    val updatedAtEpochMs: Long,
    val lastSyncAtEpochMs: Long? = null,
    val lastSyncError: String? = null,
    val deletedAtEpochMs: Long? = null,
    val captureSessionId: String,
    val name: String,
    val status: String = "Draft",
    val previewUri: String? = null,
    val cameraFileUrl: String? = null,
    val cameraLocalPath: String? = null,
    val localPreviewPath: String? = null,
    val localOriginalPath: String? = null,
    val localOriginalState: String = "NOT_DOWNLOADED",
    val serverUploadState: String = "NOT_QUEUED",
    val cameraDeleteState: String = "NOT_DELETED",
    val localDeleteState: String = "NOT_DELETED",
    val fileSizeBytes: Long? = null,
    val checksumSha256: String? = null,
    val serverMediaId: String? = null,
    val serverConfirmedAtEpochMs: Long? = null,
    val roomId: String? = null,
)
