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
            ).addMigrations(MIGRATION_9_10).build().also { instance = it }
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
}
