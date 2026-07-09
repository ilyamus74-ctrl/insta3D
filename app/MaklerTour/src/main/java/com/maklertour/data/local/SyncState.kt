package com.maklertour.data.local

enum class SyncState {
    LOCAL_ONLY,
    PENDING_CREATE,
    PENDING_UPDATE,
    PENDING_DELETE,
    SYNCED,
    SYNC_ERROR,
}