package com.maklertour.data.local

import androidx.room.Database
import androidx.room.RoomDatabase
import com.maklertour.data.local.dao.CapturePointDao
import com.maklertour.data.local.dao.CaptureSessionDao
import com.maklertour.data.local.dao.DiagnosticLogDao
import com.maklertour.data.local.dao.ObjectDao
import com.maklertour.data.local.dao.RoomDao
import com.maklertour.data.local.dao.ScanVideoDao
import com.maklertour.data.local.dao.TourDraftConnectionDao
import com.maklertour.data.local.dao.UploadItemDao
import com.maklertour.data.local.entity.CapturePointEntity
import com.maklertour.data.local.entity.CaptureSessionEntity
import com.maklertour.data.local.entity.DiagnosticLogEntity
import com.maklertour.data.local.entity.ObjectEntity
import com.maklertour.data.local.entity.RoomEntity
import com.maklertour.data.local.entity.ScanVideoEntity
import com.maklertour.data.local.entity.TourDraftConnectionEntity
import com.maklertour.data.local.entity.UploadItemEntity

@Database(
    entities = [
        ObjectEntity::class,
        RoomEntity::class,
        CaptureSessionEntity::class,
        CapturePointEntity::class,
        TourDraftConnectionEntity::class,
        ScanVideoEntity::class,
        UploadItemEntity::class,
        DiagnosticLogEntity::class,
    ],
    version = 11,
    exportSchema = false,
)
abstract class AppDatabase : RoomDatabase() {
    abstract fun objectDao(): ObjectDao
    abstract fun roomDao(): RoomDao
    abstract fun captureSessionDao(): CaptureSessionDao
    abstract fun capturePointDao(): CapturePointDao
    abstract fun tourDraftConnectionDao(): TourDraftConnectionDao
    abstract fun scanVideoDao(): ScanVideoDao
    abstract fun uploadItemDao(): UploadItemDao
    abstract fun diagnosticLogDao(): DiagnosticLogDao
}
