package com.maklertour.data.phonecamera

import android.content.Context
import androidx.camera.view.PreviewView
import androidx.lifecycle.LifecycleOwner
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.delay
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.withContext
import kotlinx.coroutines.withTimeoutOrNull

/**
 * Explicit CameraX binding bridge for the settings preview and the fullscreen
 * dual-phone calibration preview.
 *
 * Moving a PreviewView between Compose hosts does not reconnect CameraX after
 * the underlying Surface is recreated. Every host transition therefore performs
 * an explicit bind against the currently selected physical camera, zoom and
 * operating mode.
 */
object DualPhonePreviewBindingRuntime {
    private val bindMutex = Mutex()
    @Volatile
    private var recorder: PhoneCameraVideoRecorder? = null
    private var recorderOwner: LifecycleOwner? = null

    suspend fun bind(
        context: Context,
        lifecycleOwner: LifecycleOwner,
        previewView: PreviewView,
        calibrationMode: Boolean,
    ): PhoneCameraBindResult {
        bindMutex.lock()
        return try {
            withContext(Dispatchers.Main.immediate) {
                val attached = withTimeoutOrNull(PREVIEW_ATTACH_TIMEOUT_MS) {
                    while (
                        !previewView.isAttachedToWindow ||
                        previewView.width <= 0 ||
                        previewView.height <= 0
                    ) {
                        delay(50L)
                    }
                    true
                } == true
                val lensRepository = PhoneCameraLensRepository(
                    context.applicationContext,
                )
                val requestedZoom = lensRepository.getSelectedZoomRatio()
                if (!attached) {
                    return@withContext PhoneCameraBindResult(
                        success = false,
                        error = "PreviewView was not attached within " +
                            "$PREVIEW_ATTACH_TIMEOUT_MS ms",
                        requestedZoomRatio = requestedZoom,
                        bindStatus = "preview_not_attached",
                    )
                }

                val lens = lensRepository.selectedOrDefault().first
                val mode = lensRepository.getSelectedVideoMode(
                    lens.cameraId,
                    lens.supportedVideoModes,
                )
                val activeRecorder = if (
                    recorder != null &&
                    recorderOwner === lifecycleOwner
                ) {
                    requireNotNull(recorder)
                } else {
                    PhoneCameraVideoRecorder(
                        context.applicationContext,
                        lifecycleOwner,
                    ).also {
                        recorder = it
                        recorderOwner = lifecycleOwner
                    }
                }

                activeRecorder.bindPreview(
                    previewView = previewView,
                    cameraId = lens.cameraId,
                    zoomRatio = requestedZoom,
                    calibrationWidth = mode?.width,
                    calibrationHeight = mode?.height,
                    videoWidth = mode?.width,
                    videoHeight = mode?.height,
                    videoFps = mode?.fps,
                    videoMode = mode,
                    enableVideoCapture = !calibrationMode,
                    enableCalibrationAnalysis = calibrationMode,
                )
            }
        } catch (error: Throwable) {
            val requestedZoom = runCatching {
                PhoneCameraLensRepository(
                    context.applicationContext,
                ).getSelectedZoomRatio()
            }.getOrDefault(1.0f)
            PhoneCameraBindResult(
                success = false,
                error = error.message ?: error.javaClass.simpleName,
                requestedZoomRatio = requestedZoom,
                bindStatus = "bind_failed",
            )
        } finally {
            bindMutex.unlock()
        }
    }

    fun latestCalibrationFrame(): CalibrationFrame? =
        recorder?.getLatestCalibrationFrame()

    fun calibrationFrame(sequence: Long): CalibrationFrame? =
        recorder?.getRecentCalibrationFrames()
            ?.firstOrNull { it.sequence == sequence }

    suspend fun refreshCalibrationFocus(
        normalizedX: Double,
        normalizedY: Double,
    ): String = recorder?.refreshCalibrationFocus(
        normalizedX = normalizedX,
        normalizedY = normalizedY,
    ) ?: "AF_BOARD_RECORDER_UNAVAILABLE"

    private const val PREVIEW_ATTACH_TIMEOUT_MS = 5_000L
}
