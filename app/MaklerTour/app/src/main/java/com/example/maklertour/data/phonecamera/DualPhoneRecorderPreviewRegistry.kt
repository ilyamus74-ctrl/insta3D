package com.maklertour.data.phonecamera

import androidx.camera.view.PreviewView
import java.lang.ref.WeakReference

/**
 * Bridges the Compose settings screen and the long-lived phone-camera provider.
 * The registry keeps only a weak reference to the last PreviewView so the same
 * CameraX-bound surface can move between the settings card and fullscreen dialog.
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
            // Keep the weak reference for the next Compose host. Clearing it here
            // creates a new PreviewView and leaves CameraX bound to a detached one.
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
