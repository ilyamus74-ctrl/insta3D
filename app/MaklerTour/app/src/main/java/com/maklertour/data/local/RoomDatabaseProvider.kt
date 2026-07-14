package com.maklertour.data.local

import android.content.Context
import androidx.room.Room
import androidx.room.migration.Migration
import androidx.sqlite.db.SupportSQLiteDatabase

object RoomDatabaseProvider {
    @Volatile
    private var instance: AppDatabase? = null

    fun get(context: Context): AppDatabase {
        return instance ?: synchronized(this) {
            instance ?: Room.databaseBuilder(
                context.applicationContext,
                AppDatabase::class.java,
                "maklertour-local.db",
            ).addMigrations(MIGRATION_9_10, MIGRATION_10_11, MIGRATION_11_12, MIGRATION_12_13, MIGRATION_13_14).build().also { instance = it }
        }
    }

    private val MIGRATION_9_10 = object : Migration(9, 10) {
        override fun migrate(db: SupportSQLiteDatabase) {
            db.execSQL("ALTER TABLE upload_items ADD COLUMN sessionTitle TEXT")
            db.execSQL("ALTER TABLE upload_items ADD COLUMN serverOrderId INTEGER")
            db.execSQL("ALTER TABLE upload_items ADD COLUMN orderTitle TEXT")
            db.execSQL("ALTER TABLE upload_items ADD COLUMN orderAddress TEXT")
            db.execSQL("ALTER TABLE upload_items ADD COLUMN bindingId TEXT")
            db.execSQL("ALTER TABLE upload_items ADD COLUMN uploadAppSessionUuid TEXT")
            db.execSQL("ALTER TABLE upload_items ADD COLUMN serverCaptureSessionId INTEGER")
        }
    }

    private val MIGRATION_10_11 = object : Migration(10, 11) {
        override fun migrate(db: SupportSQLiteDatabase) {
            db.execSQL("ALTER TABLE scan_videos ADD COLUMN source TEXT NOT NULL DEFAULT 'INSTA360'")
        }
    }

    private val MIGRATION_11_12 = object : Migration(11, 12) {
        override fun migrate(db: SupportSQLiteDatabase) {
            db.execSQL("ALTER TABLE scan_videos ADD COLUMN role TEXT")
        }
    }

    private val MIGRATION_12_13 = object : Migration(12, 13) {
        override fun migrate(db: SupportSQLiteDatabase) {
            db.execSQL("ALTER TABLE upload_items ADD COLUMN uploadType TEXT NOT NULL DEFAULT 'MEDIA'")
            db.execSQL("ALTER TABLE upload_items ADD COLUMN captureType TEXT")
            db.execSQL("ALTER TABLE upload_items ADD COLUMN localFilePath TEXT")
            db.execSQL("ALTER TABLE upload_items ADD COLUMN displayName TEXT")
            db.execSQL("ALTER TABLE upload_items ADD COLUMN mimeType TEXT")
        }
    }

    private val MIGRATION_13_14 = object : Migration(13, 14) {
        override fun migrate(db: SupportSQLiteDatabase) {
            db.execSQL("ALTER TABLE upload_items ADD COLUMN appBundleUuid TEXT")
        }
    }
}

