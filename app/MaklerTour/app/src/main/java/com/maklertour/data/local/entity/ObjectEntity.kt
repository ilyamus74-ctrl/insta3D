package com.maklertour.data.local.entity

import androidx.room.Entity
import androidx.room.PrimaryKey

@Entity(tableName = "objects")
data class ObjectEntity(
    @PrimaryKey val id: String,
    val remoteId: String? = null,
    val syncState: String,
    val createdAtEpochMs: Long,
    val updatedAtEpochMs: Long,
    val lastSyncAtEpochMs: Long? = null,
    val lastSyncError: String? = null,
    val deletedAtEpochMs: Long? = null,
    val name: String,
    val address: String,
    val comment: String,
)
