package com.maklertour.data.local.dao

import androidx.room.Dao
import androidx.room.Insert
import androidx.room.OnConflictStrategy
import androidx.room.Query
import com.maklertour.data.local.entity.ScanVideoEntity
import kotlinx.coroutines.flow.Flow

@Dao
interface ScanVideoDao {
    @Insert(onConflict = OnConflictStrategy.REPLACE)
    suspend fun upsert(item: ScanVideoEntity)

    @Query("SELECT * FROM scan_videos ORDER BY createdAtEpochMs DESC")
    fun observeAll(): Flow<List<ScanVideoEntity>>

    @Query("SELECT * FROM scan_videos WHERE sessionId = :sessionId ORDER BY sequenceNumber ASC, createdAtEpochMs ASC")
    fun observeBySession(sessionId: String): Flow<List<ScanVideoEntity>>

    @Query("DELETE FROM scan_videos WHERE id = :scanVideoId")
    suspend fun deleteById(scanVideoId: String)
    @Query("DELETE FROM scan_videos WHERE sessionId = :sessionId")
    suspend fun deleteBySessionId(sessionId: String)
}
