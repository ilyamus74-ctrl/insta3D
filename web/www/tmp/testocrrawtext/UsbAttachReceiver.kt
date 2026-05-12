package com.example.testocrrawtext

import android.app.PendingIntent
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.hardware.usb.UsbDevice
import android.hardware.usb.UsbManager
import android.os.Build

class UsbAttachReceiver : BroadcastReceiver() {

    override fun onReceive(context: Context, intent: Intent) {
        if (intent.action != UsbManager.ACTION_USB_DEVICE_ATTACHED) return

        val usbManager = context.getSystemService(Context.USB_SERVICE) as UsbManager

        val device: UsbDevice? = if (Build.VERSION.SDK_INT >= 33) {
            intent.getParcelableExtra(UsbManager.EXTRA_DEVICE, UsbDevice::class.java)
        } else {
            @Suppress("DEPRECATION")
            intent.getParcelableExtra(UsbManager.EXTRA_DEVICE)
        }

        if (device == null) return

        // если уже есть доступ — ничего не делаем
        if (usbManager.hasPermission(device)) return

        val flags = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S)
            PendingIntent.FLAG_MUTABLE
        else 0

        val pi = PendingIntent.getBroadcast(
            context,
            0,
            Intent(USB_PERMISSION_ACTION),
            flags
        )

        usbManager.requestPermission(device, pi)
    }

    companion object {
        const val USB_PERMISSION_ACTION = "com.example.testocrrawtext.USB_PERMISSION"
    }
}
