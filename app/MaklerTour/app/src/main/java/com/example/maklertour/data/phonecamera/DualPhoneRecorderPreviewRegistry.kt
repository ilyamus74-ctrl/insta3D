package com.maklertour.data.phonecamera

import androidx.camera.view.PreviewView
import java.lang.ref.WeakReference

/**
 * Bridges the Compose settings screen and the long-lived phone-camera provider.
 * The registry never owns the View: the composable registers it while visible
 * and unregisters it on disposal.
 */
object DualPhoneRecorderPreviewRegistry {
    private val lock = Any()
    private var previewRef: WeakReference<PreviewView>? = null
    private var generation: Long = 0L

    fun register(previewView: PreviewView): Long = synchronized(lock) {
        val current = previewRef?.get()
        if (current !== previewView) {
            generation += 1L
            previewRef = WeakReference(previewView)
        }
        generation
    }

    fun unregister(previewView: PreviewView) = synchronized(lock) {
        if (previewRef?.get() === previewView) {
            previewRef = null
            generation += 1L
        }
    }

    fun current(): PreviewView? = synchronized(lock) {
        previewRef?.get()
    }

    fun currentGeneration(): Long = synchronized(lock) {
        generation
    }
}
