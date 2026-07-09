package com.example.maklertour.auth

import android.content.Context
import androidx.core.content.edit

class AuthStorage(context: Context) {
    private val prefs = context.getSharedPreferences("maklertour_auth", Context.MODE_PRIVATE)

    fun saveToken(token: String) {
        prefs.edit {
            putString(KEY_TOKEN, token)
        }
    }

    fun getToken(): String? {
        return prefs.getString(KEY_TOKEN, null)
    }
    fun saveUserId(userId: Long) {
        prefs.edit {
            putLong(KEY_USER_ID, userId)
        }
    }

    fun getUserId(): Long? {
        return if (prefs.contains(KEY_USER_ID)) prefs.getLong(KEY_USER_ID, 0L) else null
    }
    fun clear() {
        prefs.edit {
            clear()
        }
    }

    fun isLoggedIn(): Boolean {
        return !getToken().isNullOrBlank()
    }

    companion object {
        private const val KEY_TOKEN = "token"
        private const val KEY_USER_ID = "user_id"
    }
}