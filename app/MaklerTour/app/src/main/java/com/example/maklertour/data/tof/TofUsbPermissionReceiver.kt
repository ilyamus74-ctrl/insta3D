package com.maklertour.data.tof

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent

class TofUsbPermissionReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        TofUsbRuntime.get(context).handleUsbPermissionResult(intent)
    }
}
