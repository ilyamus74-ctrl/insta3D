package com.maklertour.data.local

import android.content.Context
import androidx.room.Room

object RoomDatabaseProvider {
    @Volatile
    private var instance: AppDatabase? = null

    fun get(context: Context): AppDatabase {
        return instance ?: synchronized(this) {
            instance ?: Room.databaseBuilder(
                context.applicationContext,
                AppDatabase::class.java,
                "maklertour-local.db",
            ).fallbackToDestructiveMigration().build().also { instance = it }
        }
    }
}
