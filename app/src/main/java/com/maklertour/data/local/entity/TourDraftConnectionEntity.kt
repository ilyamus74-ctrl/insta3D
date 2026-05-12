package com.maklertour.data.local.entity

import androidx.room.Entity
import androidx.room.PrimaryKey

@Entity(tableName = "tour_draft_connections")
data class TourDraftConnectionEntity(
    @PrimaryKey val id: String,
    val remoteId: String? = null,
    val syncState: String,
    val createdAtEpochMs: Long,
    val updatedAtEpochMs: Long,
    val lastSyncAtEpochMs: Long? = null,
    val lastSyncError: String? = null,
    val deletedAtEpochMs: Long? = null,
    val sessionId: String,
    val fromPointId: String,
    val toPointId: String,
    val connectionType: String = "manual",
)
