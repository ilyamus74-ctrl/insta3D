package com.maklertour.data.local.entity

import androidx.room.Entity
import androidx.room.PrimaryKey

@Entity(tableName = "rooms")
data class RoomEntity(
    @PrimaryKey val id: String,
    val remoteId: String? = null,
    val syncState: String,
    val createdAtEpochMs: Long,
    val updatedAtEpochMs: Long,
    val lastSyncAtEpochMs: Long? = null,
    val lastSyncError: String? = null,
    val deletedAtEpochMs: Long? = null,
    val objectId: String,
    val sessionId: String,
    val name: String,
    val type: String = "OTHER",
    val orderIndex: Int = 0,
    val notes: String? = null,
    val lengthM: Double? = null,
    val widthM: Double? = null,
    val heightM: Double? = null,
)
