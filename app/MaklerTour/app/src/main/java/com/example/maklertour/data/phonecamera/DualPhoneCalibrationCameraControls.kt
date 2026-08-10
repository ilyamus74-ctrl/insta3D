package com.maklertour.data.phonecamera

import android.hardware.camera2.CameraCharacteristics
import android.hardware.camera2.CaptureRequest
import android.util.Log
import androidx.camera.camera2.interop.Camera2CameraControl
import androidx.camera.camera2.interop.Camera2CameraInfo
import androidx.camera.camera2.interop.CaptureRequestOptions
import androidx.camera.camera2.interop.ExperimentalCamera2Interop
import androidx.camera.core.Camera
import androidx.camera.core.FocusMeteringAction
import androidx.camera.view.PreviewView
import com.google.common.util.concurrent.ListenableFuture
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.delay
import kotlinx.coroutines.suspendCancellableCoroutine
import kotlinx.coroutines.withContext
import kotlinx.coroutines.withTimeoutOrNull
import java.util.ArrayDeque
import java.util.concurrent.Executor
import kotlin.coroutines.resume

internal class DualPhoneCalibrationTimestampMapper {
    private val offsetsNs = ArrayDeque<Long>()
    var sourceName: String = SOURCE_UNKNOWN
        private set

    fun reset(source: String) {
        sourceName = source
        offsetsNs.clear()
    }

    fun toElapsedRealtimeNs(
        cameraTimestampNs: Long,
        observedElapsedRealtimeNs: Long,
    ): Long {
        if (cameraTimestampNs <= 0L) return observedElapsedRealtimeNs
        if (sourceName == SOURCE_REALTIME) return cameraTimestampNs

        val sampleOffset = observedElapsedRealtimeNs - cameraTimestampNs
        offsetsNs.addLast(sampleOffset)
        while (offsetsNs.size > OFFSET_HISTORY_SIZE) offsetsNs.removeFirst()
        val sorted = offsetsNs.sorted()
        val index = ((sorted.size - 1) * OFFSET_LOW_PERCENTILE).toInt()
            .coerceIn(0, sorted.lastIndex)
        return cameraTimestampNs + sorted[index]
    }

    companion object {
        const val SOURCE_REALTIME = "REALTIME"
        const val SOURCE_UNKNOWN = "UNKNOWN"
        private const val OFFSET_HISTORY_SIZE = 31
        private const val OFFSET_LOW_PERCENTILE = 0.10
    }
}

@OptIn(ExperimentalCamera2Interop::class)
internal object DualPhoneCalibrationCameraControls {
    private val directExecutor = Executor { command -> command.run() }

    fun timestampSource(camera: Camera): String = runCatching {
        when (
            Camera2CameraInfo.from(camera.cameraInfo).getCameraCharacteristic(
                CameraCharacteristics.SENSOR_INFO_TIMESTAMP_SOURCE,
            )
        ) {
            CameraCharacteristics.SENSOR_INFO_TIMESTAMP_SOURCE_REALTIME ->
                DualPhoneCalibrationTimestampMapper.SOURCE_REALTIME
            else -> DualPhoneCalibrationTimestampMapper.SOURCE_UNKNOWN
        }
    }.getOrDefault(DualPhoneCalibrationTimestampMapper.SOURCE_UNKNOWN)

    suspend fun prepare(
        camera: Camera,
        previewView: PreviewView,
    ): String = withContext(Dispatchers.Main.immediate) {
        val info = Camera2CameraInfo.from(camera.cameraInfo)
        val statuses = mutableListOf<String>()

        val zoomLocked = runCatching {
            awaitCompletion(
                camera.cameraControl.setZoomRatio(METRIC_STEREO_ZOOM_RATIO),
                OPTIONS_TIMEOUT_MS,
            )
        }.getOrDefault(false)
        statuses += if (zoomLocked) "ZOOM_1X_LOCKED" else "ZOOM_1X_LOCK_FAILED"

        var layoutWaits = 0
        while (
            (previewView.width <= 1 || previewView.height <= 1) &&
            layoutWaits < PREVIEW_LAYOUT_WAIT_STEPS
        ) {
            delay(PREVIEW_LAYOUT_WAIT_STEP_MS)
            layoutWaits += 1
        }
        val meteringPoint = previewView.meteringPointFactory.createPoint(
            previewView.width.coerceAtLeast(1) / 2f,
            previewView.height.coerceAtLeast(1) / 2f,
        )
        val focusAction = FocusMeteringAction.Builder(
            meteringPoint,
            FocusMeteringAction.FLAG_AF or
                FocusMeteringAction.FLAG_AE or
                FocusMeteringAction.FLAG_AWB,
        )
            .disableAutoCancel()
            .build()
        val focusResult = awaitFuture(
            camera.cameraControl.startFocusAndMetering(focusAction),
            FOCUS_TIMEOUT_MS,
        )
        val focused = focusResult?.isFocusSuccessful == true
        statuses += if (focused) "AF_METERING_LOCKED" else "AF_METERING_TIMEOUT"
        delay(SETTLE_DELAY_MS)

        val options = CaptureRequestOptions.Builder()
        if (
            info.getCameraCharacteristic(
                CameraCharacteristics.CONTROL_AE_LOCK_AVAILABLE,
            ) == true
        ) {
            options.setCaptureRequestOption(CaptureRequest.CONTROL_AE_LOCK, true)
            statuses += "AE_LOCKED"
        } else {
            statuses += "AE_LOCK_UNAVAILABLE"
        }
        if (
            info.getCameraCharacteristic(
                CameraCharacteristics.CONTROL_AWB_LOCK_AVAILABLE,
            ) == true
        ) {
            options.setCaptureRequestOption(CaptureRequest.CONTROL_AWB_LOCK, true)
            statuses += "AWB_LOCKED"
        } else {
            statuses += "AWB_LOCK_UNAVAILABLE"
        }

        val videoModes = info.getCameraCharacteristic(
            CameraCharacteristics.CONTROL_AVAILABLE_VIDEO_STABILIZATION_MODES,
        ) ?: intArrayOf()
        if (
            videoModes.contains(
                CaptureRequest.CONTROL_VIDEO_STABILIZATION_MODE_OFF,
            )
        ) {
            options.setCaptureRequestOption(
                CaptureRequest.CONTROL_VIDEO_STABILIZATION_MODE,
                CaptureRequest.CONTROL_VIDEO_STABILIZATION_MODE_OFF,
            )
            statuses += "EIS_OFF"
        } else {
            statuses += "EIS_OFF_UNAVAILABLE"
        }

        val opticalModes = info.getCameraCharacteristic(
            CameraCharacteristics.LENS_INFO_AVAILABLE_OPTICAL_STABILIZATION,
        ) ?: intArrayOf()
        if (
            opticalModes.contains(
                CaptureRequest.LENS_OPTICAL_STABILIZATION_MODE_OFF,
            )
        ) {
            options.setCaptureRequestOption(
                CaptureRequest.LENS_OPTICAL_STABILIZATION_MODE,
                CaptureRequest.LENS_OPTICAL_STABILIZATION_MODE_OFF,
            )
            statuses += "OIS_OFF"
        } else {
            statuses += "OIS_OFF_UNAVAILABLE"
        }

        val applied = runCatching {
            awaitCompletion(
                Camera2CameraControl.from(camera.cameraControl)
                    .setCaptureRequestOptions(options.build()),
                OPTIONS_TIMEOUT_MS,
            )
        }.getOrDefault(false)
        statuses += if (applied) "CAMERA2_OPTIONS_APPLIED" else
            "CAMERA2_OPTIONS_FAILED"

        val metricReady = zoomLocked && applied
        statuses.add(0, if (metricReady) "METRIC_READY" else "METRIC_NOT_READY")

        statuses.joinToString(",").also {
            Log.i(TAG, "calibration camera controls: $it")
        }
    }

    suspend fun refocusOnBoard(
        camera: Camera,
        previewView: PreviewView,
        normalizedX: Double,
        normalizedY: Double,
    ): String = withContext(Dispatchers.Main.immediate) {
        if (previewView.width <= 1 || previewView.height <= 1) {
            return@withContext "AF_BOARD_PREVIEW_NOT_READY"
        }
        val safeX = normalizedX.coerceIn(0.05, 0.95)
        val safeY = normalizedY.coerceIn(0.05, 0.95)
        val meteringPoint = previewView.meteringPointFactory.createPoint(
            (safeX * previewView.width.toDouble()).toFloat(),
            (safeY * previewView.height.toDouble()).toFloat(),
        )
        val focusAction = FocusMeteringAction.Builder(
            meteringPoint,
            FocusMeteringAction.FLAG_AF,
        )
            .disableAutoCancel()
            .build()
        val focusResult = awaitFuture(
            camera.cameraControl.startFocusAndMetering(focusAction),
            FOCUS_TIMEOUT_MS,
        )
        val focused = focusResult?.isFocusSuccessful == true
        if (focused) {
            delay(BOARD_REFOCUS_SETTLE_MS)
        }
        val status = if (focused) {
            "AF_BOARD_LOCKED"
        } else {
            "AF_BOARD_LOCK_FAILED"
        }
        Log.i(
            TAG,
            "calibration board autofocus: $status x=$safeX y=$safeY",
        )
        status
    }

    suspend fun release(camera: Camera): String =
        withContext(Dispatchers.Main.immediate) {
            val statuses = mutableListOf<String>()
            val focusReleased = runCatching {
                awaitCompletion(
                    camera.cameraControl.cancelFocusAndMetering(),
                    OPTIONS_TIMEOUT_MS,
                )
            }.getOrDefault(false)
            statuses += if (focusReleased) "FOCUS_METERING_RELEASED" else
                "FOCUS_METERING_RELEASE_FAILED"
            val optionsCleared = runCatching {
                awaitCompletion(
                    Camera2CameraControl.from(camera.cameraControl)
                        .clearCaptureRequestOptions(),
                    OPTIONS_TIMEOUT_MS,
                )
            }.getOrDefault(false)
            statuses += if (optionsCleared) "CAMERA2_OPTIONS_CLEARED" else
                "CAMERA2_OPTIONS_CLEAR_FAILED"
            statuses.joinToString(",").also {
                Log.i(TAG, "calibration camera controls released: $it")
            }
        }

    private suspend fun <T> awaitFuture(
        future: ListenableFuture<T>,
        timeoutMs: Long,
    ): T? = withTimeoutOrNull(timeoutMs) {
        suspendCancellableCoroutine { continuation ->
            future.addListener(
                {
                    val value = runCatching { future.get() }.getOrNull()
                    if (continuation.isActive) continuation.resume(value)
                },
                directExecutor,
            )
            continuation.invokeOnCancellation { future.cancel(true) }
        }
    }

    private suspend fun awaitCompletion(
        future: ListenableFuture<*>,
        timeoutMs: Long,
    ): Boolean = withTimeoutOrNull(timeoutMs) {
        suspendCancellableCoroutine { continuation ->
            future.addListener(
                {
                    val completed = runCatching {
                        future.get()
                        true
                    }.getOrDefault(false)
                    if (continuation.isActive) continuation.resume(completed)
                },
                directExecutor,
            )
            continuation.invokeOnCancellation { future.cancel(true) }
        }
    } ?: false

    private const val TAG = "DualPhoneCalibration"
    private const val METRIC_STEREO_ZOOM_RATIO = 1.0f
    private const val FOCUS_TIMEOUT_MS = 2_500L
    private const val OPTIONS_TIMEOUT_MS = 1_500L
    private const val SETTLE_DELAY_MS = 250L
    private const val BOARD_REFOCUS_SETTLE_MS = 220L
    private const val PREVIEW_LAYOUT_WAIT_STEPS = 20
    private const val PREVIEW_LAYOUT_WAIT_STEP_MS = 50L
}
