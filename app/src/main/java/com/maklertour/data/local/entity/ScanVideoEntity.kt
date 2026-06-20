package com.maklertour.data.local.entity

import androidx.room.Entity
import androidx.room.PrimaryKey

@Entity(tableName = "scan_videos")
data class ScanVideoEntity(
    @PrimaryKey val id: String,
    val syncState: String,
    val createdAtEpochMs: Long,
    val updatedAtEpochMs: Long,
    val objectId: String?,
    val sessionId: String,
    val name: String,
    val sequenceNumber: Int,
    val cameraFileUrl: String?,
    val cameraLocalFileUrl: String?,
    val localPreviewPath: String?,
    val localVideoPath: String?,
    val durationSec: Long?,
    val fileSizeBytes: Long?,
    val markerExpected: Boolean,
    val markerDetected: Boolean,
    val captureStatus: String,
    val downloadState: String,
    val uploadState: String,
    val serverProcessingState: String,
    val source: String = "INSTA360",
    val notes: String?,
)
