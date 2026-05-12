package com.maklertour.data.local.entity

import androidx.room.Entity
import androidx.room.PrimaryKey

@Entity(tableName = "capture_sessions")
data class CaptureSessionEntity(
    @PrimaryKey val id: String,
    val remoteId: String? = null,
    val syncState: String,
    val createdAtEpochMs: Long,
    val updatedAtEpochMs: Long,
    val lastSyncAtEpochMs: Long? = null,
    val lastSyncError: String? = null,
    val deletedAtEpochMs: Long? = null,
    val objectId: String,
    val title: String,
    val startPointId: String? = null,
    val serverOrderId: Long? = null,
    val serverCaptureSessionId: Long? = null,
    val orderTitle: String? = null,
    val orderAddress: String? = null,
)
