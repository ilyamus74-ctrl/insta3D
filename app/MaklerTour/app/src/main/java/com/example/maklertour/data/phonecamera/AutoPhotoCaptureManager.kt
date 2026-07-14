package com.maklertour.data.phonecamera

import android.content.Context
import android.graphics.BitmapFactory
import android.hardware.Sensor
import android.hardware.SensorEvent
import android.hardware.SensorEventListener
import android.hardware.SensorManager
import android.hardware.camera2.CameraCharacteristics
import android.hardware.camera2.CameraManager
import android.media.AudioManager
import android.media.ExifInterface
import android.media.ToneGenerator
import android.os.SystemClock
import android.os.VibrationEffect
import android.os.Vibrator
import android.util.Log
import android.view.Surface
import androidx.camera.core.Camera
import androidx.camera.core.ImageAnalysis
import androidx.camera.core.ImageCapture
import androidx.camera.core.ImageCaptureException
import androidx.camera.core.ImageProxy
import androidx.camera.core.Preview
import androidx.camera.lifecycle.ProcessCameraProvider
import androidx.camera.view.PreviewView
import androidx.lifecycle.LifecycleOwner
import kotlinx.coroutines.CompletableDeferred
import kotlinx.coroutines.withTimeoutOrNull
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import org.json.JSONArray
import org.json.JSONObject
import java.io.File
import java.time.Instant
import java.util.UUID
import java.util.concurrent.ExecutorService
import java.util.concurrent.Executors
import java.util.concurrent.TimeUnit
import kotlin.math.abs
import kotlin.math.sqrt

private const val TAG = "AutoPhotoCapture"

enum class AutoPhotoState { IDLE, RUNNING, PAUSED, FINISHING, CANCELLING, FINISHED, CANCELLED, ERROR }

data class AutoPhotoSettings(
    val autoPhotoIntervalMs: Long = 1200L,
    val stableGyroThresholdDegSec: Double = 30.0,
    val stableDwellMs: Long = 200L,
    val minSharpness: Double = 18.0,
    val maxPhotos: Int = 600,
    val storageReserveBytes: Long = 100L * 1024L * 1024L,
)

data class AutoPhotoUiState(
    val state: AutoPhotoState = AutoPhotoState.IDLE,
    val captureUuid: String? = null,
    val photosCount: Int = 0,
    val rejectedCount: Int = 0,
    val lastReason: String = "idle",
    val angularVelocityDegSec: Double = 0.0,
    val sharpness: Double = 0.0,
    val lastSavedSequence: Int = 0,
    val lastSavedMessage: String? = null,
    val error: String? = null,
)

data class OrientationSnapshot(
    val timestampMs: Long = System.currentTimeMillis(),
    val gravity: FloatArray? = null,
    val gyro: FloatArray? = null,
    val accel: FloatArray? = null,
    val quat: FloatArray? = null,
    val physicalOrientationLabel: String = "unknown",
    val orientationStale: Boolean = true,
) {
    fun physicalOrientation(): String {
        if (orientationStale) return "unknown"
        if (physicalOrientationLabel != "unknown") return physicalOrientationLabel
        val g = accel ?: return "unknown"
        if (g.size < 3) return "unknown"
        val ax = abs(g[0]); val ay = abs(g[1]); val az = abs(g[2])
        return when {
            az > ax && az > ay && g[2] > 0 -> "face_up"
            az > ax && az > ay -> "face_down"
            ay >= ax && g[1] > 0 -> "portrait_upright"
            ay >= ax -> "portrait_upside_down"
            g[0] > 0 -> "landscape_right"
            else -> "landscape_left"
        }
    }
}

object AutoPhotoCaptureRules {
    fun nextFrameName(sequence: Int): String = "frame_%06d.jpg".format(sequence)

    fun shouldCapture(
        running: Boolean,
        captureInFlight: Boolean,
        angularVelocityDegSec: Double,
        stableSinceMs: Long,
        nowMs: Long,
        lastCaptureMs: Long,
        sharpness: Double,
        savedCount: Int,
        freeBytes: Long,
        settings: AutoPhotoSettings,
    ): String = when {
        !running -> "camera_not_ready"
        captureInFlight -> "capture_in_progress"
        angularVelocityDegSec > settings.stableGyroThresholdDegSec -> "motion_too_high"
        stableSinceMs == 0L || nowMs - stableSinceMs < settings.stableDwellMs -> "not_stable_long_enough"
        nowMs - lastCaptureMs < settings.autoPhotoIntervalMs -> "minimum_interval"
        sharpness < settings.minSharpness -> "too_blurry"
        savedCount >= settings.maxPhotos -> "max_photos_reached"
        freeBytes < settings.storageReserveBytes -> "storage_reserve"
        else -> "accepted"
    }
}

private data class CaptureReservation(
    val root: File,
    val photosDir: File,
    val captureUuid: String,
    val sequence: Int,
    val deferred: CompletableDeferred<Unit>,
)

private class AutoPhotoImuTracker(context: Context) : SensorEventListener {
    private val sensorManager = context.getSystemService(SensorManager::class.java)
    @Volatile private var gyro: FloatArray? = null
    @Volatile private var accel: FloatArray? = null
    @Volatile private var quat: FloatArray? = null

    fun start() {
        listOf(Sensor.TYPE_GYROSCOPE, Sensor.TYPE_ACCELEROMETER, Sensor.TYPE_ROTATION_VECTOR).forEach { type ->
            sensorManager?.getDefaultSensor(type)?.let { sensorManager.registerListener(this, it, SensorManager.SENSOR_DELAY_GAME) }
        }
        Log.i("AutoPhotoImu", "started")
    }

    fun stop() {
        sensorManager?.unregisterListener(this)
        Log.i("AutoPhotoImu", "stopped")
    }

    fun snapshot(orientationSample: DeviceOrientationTracker.Sample): OrientationSnapshot {
        val freshGravity = if (!orientationSample.stale && orientationSample.gravityX != null && orientationSample.gravityY != null && orientationSample.gravityZ != null) {
            floatArrayOf(orientationSample.gravityX, orientationSample.gravityY, orientationSample.gravityZ)
        } else null
        return OrientationSnapshot(
            timestampMs = System.currentTimeMillis(),
            gravity = freshGravity,
            gyro = gyro,
            accel = accel,
            quat = quat,
            physicalOrientationLabel = if (orientationSample.stale) "unknown" else orientationSample.physicalOrientation,
            orientationStale = orientationSample.stale,
        )
    }

    fun angularVelocityDegSec(): Double = gyro?.let { sqrt((it[0] * it[0] + it[1] * it[1] + it[2] * it[2]).toDouble()) * 57.2957795 } ?: 0.0

    override fun onSensorChanged(event: SensorEvent) {
        val values = event.values.copyOf()
        when (event.sensor.type) {
            Sensor.TYPE_GYROSCOPE -> gyro = values
            Sensor.TYPE_ACCELEROMETER -> accel = values
            Sensor.TYPE_ROTATION_VECTOR -> { val q = FloatArray(4); SensorManager.getQuaternionFromVector(q, values); quat = q }
        }
    }

    override fun onAccuracyChanged(sensor: Sensor?, accuracy: Int) = Unit
}

class AutoPhotoCaptureManager(private val context: Context, private val lifecycleOwner: LifecycleOwner) {
    private val lensRepository = PhoneCameraLensRepository(context)
    private var analysisExecutor: ExecutorService = newExecutor("AutoPhotoAnalysis")
    private var captureExecutor: ExecutorService = newExecutor("AutoPhotoImageCapture")
    private val orientationTracker = DeviceOrientationTracker(context)
    private val imuTracker = AutoPhotoImuTracker(context)
    private val _uiState = MutableStateFlow(AutoPhotoUiState())
    val uiState: StateFlow<AutoPhotoUiState> = _uiState
    private var imageAnalysis: ImageAnalysis? = null
    private var imageCapture: ImageCapture? = null
    private var boundCamera: Camera? = null
    private var sessionDir: File? = null
    private var photosDir: File? = null
    private var startedAt: String? = null
    private var selectedLens: PhoneCameraLensOption? = null
    private var sensorOrientation: Int? = null
    private var targetRotation: Int = Surface.ROTATION_0
    @Volatile private var captureInFlight = false
    private var inFlightDeferred: CompletableDeferred<Unit>? = null
    private var stableSinceMs = 0L
    private var lastCaptureMs = 0L
    private var savedSequence = 0
    private var rejectedCount = 0
    private var qualityLogSkipCount = 0
    private var captureUuid: String? = null
    private var settings = AutoPhotoSettings()
    private var toneGenerator: ToneGenerator? = null
    private val terminalTransitionLock = Any()

    suspend fun bindPreview(previewView: PreviewView, cameraId: String?, zoomRatio: Float): PhoneCameraBindResult {
        ensureExecutors()
        targetRotation = previewView.display?.rotation ?: Surface.ROTATION_0
        val provider = getCameraProvider()
        val lens = cameraId?.let { id -> lensRepository.listBackCameras().firstOrNull { it.cameraId == id } } ?: lensRepository.selectedOrDefault().first
        selectedLens = lens
        sensorOrientation = cameraSensorOrientation(lens.cameraId)
        val preview = Preview.Builder().setTargetRotation(targetRotation).build().also { it.setSurfaceProvider(previewView.surfaceProvider) }
        imageCapture = ImageCapture.Builder().setCaptureMode(ImageCapture.CAPTURE_MODE_MINIMIZE_LATENCY).setTargetRotation(targetRotation).build()
        imageAnalysis = ImageAnalysis.Builder().setBackpressureStrategy(ImageAnalysis.STRATEGY_KEEP_ONLY_LATEST).setTargetRotation(targetRotation).build().also { analyzer ->
            analyzer.setAnalyzer(analysisExecutor) { analyze(it) }
        }
        return try {
            provider.unbindAll()
            boundCamera = provider.bindToLifecycle(lifecycleOwner, lensRepository.cameraSelectorFor(lens.cameraId), preview, imageAnalysis, imageCapture)
            boundCamera?.cameraControl?.setZoomRatio(zoomRatio)
            Log.i(TAG, "bind success camera_id=${lens.cameraId} zoom=$zoomRatio sensor_orientation=$sensorOrientation profile=Preview+ImageAnalysis+ImageCapture")
            PhoneCameraBindResult(success = true, cameraId = lens.cameraId, activeBoundCameraId = lens.cameraId, requestedZoomRatio = zoomRatio, effectiveZoomRatio = zoomRatio)
        } catch (t: Throwable) {
            Log.e(TAG, "bind failed camera_id=${lens.cameraId}", t)
            _uiState.value = _uiState.value.copy(state = AutoPhotoState.ERROR, error = t.message, lastReason = "camera_not_ready")
            PhoneCameraBindResult(success = false, error = t.message ?: "auto photo bind failed", cameraId = lens.cameraId, requestedZoomRatio = zoomRatio)
        }
    }

    fun start(localSessionId: String, orderId: Long?, baseFilesDir: File, newSettings: AutoPhotoSettings = AutoPhotoSettings()) {
        if (_uiState.value.state in setOf(AutoPhotoState.RUNNING, AutoPhotoState.FINISHING, AutoPhotoState.CANCELLING)) return
        if (_uiState.value.state == AutoPhotoState.ERROR && _uiState.value.lastReason == "capture_timeout") return
        settings = newSettings
        captureUuid = UUID.randomUUID().toString()
        startedAt = Instant.now().toString()
        savedSequence = 0
        rejectedCount = 0
        stableSinceMs = 0L
        lastCaptureMs = 0L
        qualityLogSkipCount = 0
        captureInFlight = false
        inFlightDeferred = null
        val root = File(baseFilesDir, "sessions/$localSessionId/auto_photo_sessions/$captureUuid").also { it.mkdirs() }
        sessionDir = root
        photosDir = File(root, "photos").also { it.mkdirs() }
        writeEvent("started")
        writeManifest(localSessionId, orderId, finished = false, cancelled = false)
        orientationTracker.start()
        imuTracker.start()
        _uiState.value = AutoPhotoUiState(state = AutoPhotoState.RUNNING, captureUuid = captureUuid, lastReason = "started")
        Log.i(TAG, "auto capture started capture_uuid=$captureUuid dir=${root.absolutePath}")
    }

    fun pause() {
        if (_uiState.value.state == AutoPhotoState.RUNNING) {
            _uiState.value = _uiState.value.copy(state = AutoPhotoState.PAUSED, lastReason = "paused")
            Log.i(TAG, "paused")
        }
    }

    fun resume() {
        if (_uiState.value.state == AutoPhotoState.PAUSED) {
            _uiState.value = _uiState.value.copy(state = AutoPhotoState.RUNNING, lastReason = "continued")
            Log.i(TAG, "continued")
        }
    }

    suspend fun finish(localSessionId: String, orderId: Long?): File? {
        if (!beginTerminalTransition(AutoPhotoState.FINISHING, "finishing")) return null
        return try {
            if (!waitForCaptureInFlight()) {
                _uiState.value = _uiState.value.copy(state = AutoPhotoState.ERROR, lastReason = "capture_timeout", error = "Timeout waiting for ImageCapture")
                return null
            }
            orientationTracker.stop()
            imuTracker.stop()
            writeEvent("finished")
            writeManifest(localSessionId, orderId, true, false)
            _uiState.value = _uiState.value.copy(state = AutoPhotoState.FINISHED, lastReason = "finished")
            sessionDir
        } catch (t: Throwable) {
            Log.e(TAG, "terminal transition failed", t)
            _uiState.value = _uiState.value.copy(state = AutoPhotoState.ERROR, lastReason = "finish_error", error = t.message)
            null
        }
    }

    suspend fun cancel(localSessionId: String, orderId: Long?): File? {
        if (!beginTerminalTransition(AutoPhotoState.CANCELLING, "cancelling")) return null
        return try {
            if (!waitForCaptureInFlight()) {
                _uiState.value = _uiState.value.copy(state = AutoPhotoState.ERROR, lastReason = "capture_timeout", error = "Timeout waiting for ImageCapture")
                return null
            }
            orientationTracker.stop()
            imuTracker.stop()
            writeEvent("cancelled")
            writeManifest(localSessionId, orderId, true, true)
            _uiState.value = _uiState.value.copy(state = AutoPhotoState.CANCELLED, lastReason = "cancelled")
            sessionDir
        } catch (t: Throwable) {
            Log.e(TAG, "terminal transition failed", t)
            _uiState.value = _uiState.value.copy(state = AutoPhotoState.ERROR, lastReason = "cancel_error", error = t.message)
            null
        }
    }

    private fun beginTerminalTransition(targetState: AutoPhotoState, reason: String): Boolean = synchronized(terminalTransitionLock) {
        val current = _uiState.value.state
        if (current in setOf(AutoPhotoState.FINISHING, AutoPhotoState.CANCELLING, AutoPhotoState.FINISHED, AutoPhotoState.CANCELLED)) {
            Log.i(TAG, "ignored duplicate terminal transition current=$current requested=$targetState")
            return@synchronized false
        }
        if (current !in setOf(AutoPhotoState.RUNNING, AutoPhotoState.PAUSED, AutoPhotoState.IDLE)) {
            Log.i(TAG, "ignored terminal transition from current=$current requested=$targetState")
            return@synchronized false
        }
        _uiState.value = _uiState.value.copy(state = targetState, lastReason = reason)
        true
    }

    fun release() {
        runCatching { imageAnalysis?.clearAnalyzer() }
        runCatching { getCameraProvider().unbindAll() }
        orientationTracker.stop()
        imuTracker.stop()
        toneGenerator?.release()
        toneGenerator = null
        shutdownExecutor(analysisExecutor)
        shutdownExecutor(captureExecutor)
        imageAnalysis = null
        imageCapture = null
        boundCamera = null
        captureInFlight = false
        inFlightDeferred?.complete(Unit)
        Log.i(TAG, "released")
    }

    private fun analyze(image: ImageProxy) {
        try {
            if (_uiState.value.state != AutoPhotoState.RUNNING) return
            val now = SystemClock.elapsedRealtime()
            val angular = imuTracker.angularVelocityDegSec()
            val sharpness = estimateSharpness(image)
            val running = true
            if (angular <= settings.stableGyroThresholdDegSec && stableSinceMs == 0L) stableSinceMs = now
            if (angular > settings.stableGyroThresholdDegSec) stableSinceMs = 0L
            val reason = AutoPhotoCaptureRules.shouldCapture(
                running = running,
                captureInFlight = captureInFlight,
                angularVelocityDegSec = angular,
                stableSinceMs = stableSinceMs,
                nowMs = now,
                lastCaptureMs = lastCaptureMs,
                sharpness = sharpness,
                savedCount = savedSequence,
                freeBytes = sessionDir?.freeSpace ?: context.filesDir.freeSpace,
                settings = settings,
            )
            if (reason == "accepted") {
                val reservation = reserveCapture() ?: return
                appendQuality(reservation.root, reason, sharpness, angular, force = true)
                takePhoto(reservation, sharpness, angular)
            } else {
                synchronized(terminalTransitionLock) {
                    if (_uiState.value.state != AutoPhotoState.RUNNING) return
                    if (running && reason !in setOf("capture_in_progress", "minimum_interval")) rejectedCount += 1
                    appendQuality(sessionDir ?: return, reason, sharpness, angular, force = reason !in setOf("capture_in_progress", "minimum_interval", "camera_not_ready"))
                    _uiState.value = _uiState.value.copy(rejectedCount = rejectedCount, lastReason = reason, angularVelocityDegSec = angular, sharpness = sharpness)
                }
            }
        } finally {
            image.close()
        }
    }

    private fun reserveCapture(): CaptureReservation? = synchronized(terminalTransitionLock) {
        if (_uiState.value.state != AutoPhotoState.RUNNING || captureInFlight) return@synchronized null
        val root = sessionDir ?: return@synchronized null
        val dir = photosDir ?: return@synchronized null
        val uuid = captureUuid ?: return@synchronized null
        val deferred = CompletableDeferred<Unit>()
        captureInFlight = true
        inFlightDeferred = deferred
        CaptureReservation(root = root, photosDir = dir, captureUuid = uuid, sequence = savedSequence + 1, deferred = deferred)
    }

    private fun takePhoto(reservation: CaptureReservation, sharpness: Double, angular: Double) {
        val capture = imageCapture ?: run {
            completeCaptureReservation(reservation)
            return
        }
        val file = File(reservation.photosDir, AutoPhotoCaptureRules.nextFrameName(reservation.sequence))
        val startNs = SystemClock.elapsedRealtimeNanos()
        try {
            capture.takePicture(ImageCapture.OutputFileOptions.Builder(file).build(), captureExecutor, object : ImageCapture.OnImageSavedCallback {
                override fun onImageSaved(output: ImageCapture.OutputFileResults) {
                    val elapsedNs = SystemClock.elapsedRealtimeNanos()
                    lastCaptureMs = SystemClock.elapsedRealtime()
                    val orientationTimeNs = output.savedUri?.let { elapsedNs } ?: elapsedNs
                    val snap = imuTracker.snapshot(orientationTracker.nearestSample(orientationTimeNs))
                    val meta = photoJson(reservation.captureUuid, reservation.sequence, file, sharpness, angular, snap)
                        .put("image_capture_ms", (elapsedNs - startNs) / 1_000_000.0)
                    appendJsonl(reservation.root, "photos_metadata.jsonl", meta)
                    appendJsonl(reservation.root, "imu.jsonl", meta)
                    synchronized(terminalTransitionLock) {
                        val currentState = _uiState.value.state
                        if (currentState in setOf(AutoPhotoState.RUNNING, AutoPhotoState.FINISHING, AutoPhotoState.CANCELLING) &&
                            captureUuid == reservation.captureUuid && savedSequence < reservation.sequence
                        ) {
                            savedSequence = reservation.sequence
                            _uiState.value = _uiState.value.copy(photosCount = savedSequence, lastSavedSequence = savedSequence, lastSavedMessage = "Photo saved #$savedSequence", lastReason = "accepted")
                        }
                        captureInFlight = false
                    }
                    beepAndVibrate()
                    Log.i(TAG, "photo saved filename=${file.name} size=${file.length()} imu=yes sharpness=$sharpness gyro=$angular")
                    reservation.deferred.complete(Unit)
                }

                override fun onError(exception: ImageCaptureException) {
                    runCatching { if (file.exists()) file.delete() }
                    completeCaptureReservation(reservation)
                    _uiState.value = _uiState.value.copy(lastReason = "storage_error", error = exception.message)
                    Log.e(TAG, "photo save failed", exception)
                }
            })
        } catch (t: Throwable) {
            runCatching { if (file.exists()) file.delete() }
            completeCaptureReservation(reservation)
            _uiState.value = _uiState.value.copy(lastReason = "storage_error", error = t.message)
            Log.e(TAG, "takePicture synchronous failure", t)
        }
    }

    private fun completeCaptureReservation(reservation: CaptureReservation) {
        synchronized(terminalTransitionLock) {
            if (inFlightDeferred === reservation.deferred) captureInFlight = false
        }
        reservation.deferred.complete(Unit)
    }


    private fun photoJson(captureUuidValue: String, seq: Int, file: File, sharpness: Double, angular: Double, snap: OrientationSnapshot): JSONObject {
        val dims = jpegDimensions(file)
        val exifOrientation = runCatching { ExifInterface(file.absolutePath).getAttributeInt(ExifInterface.TAG_ORIENTATION, ExifInterface.ORIENTATION_UNDEFINED) }.getOrDefault(ExifInterface.ORIENTATION_UNDEFINED)
        return JSONObject()
            .put("photo_uuid", "$captureUuidValue-$seq")
            .put("sequence", seq)
            .put("file", "photos/${file.name}")
            .put("filename", file.name)
            .put("timestamp_utc", Instant.now().toString())
            .put("timestamp_ms", snap.timestampMs)
            .put("capture_mode", "auto_photo")
            .put("auto_capture_sequence", seq)
            .put("device_rotation_degrees", targetRotationToDegrees(targetRotation))
            .put("physical_orientation", snap.physicalOrientation())
            .put("orientation_stale", snap.orientationStale)
            .put("gravity", vec(snap.gravity))
            .put("gyroscope", vec(snap.gyro))
            .put("accelerometer", vec(snap.accel))
            .put("quaternion", quat(snap.quat))
            .put("camera_sensor_orientation", sensorOrientation ?: JSONObject.NULL)
            .put("display_rotation", targetRotation)
            .put("exif_orientation", if (exifOrientation == ExifInterface.ORIENTATION_UNDEFINED) JSONObject.NULL else exifOrientation)
            .put("image_rotation_degrees_applied", 0)
            .put("image_width", dims.first)
            .put("image_height", dims.second)
            .put("file_size_bytes", file.length())
            .put("sharpness", sharpness)
            .put("duplicate_score", 0)
            .put("angular_velocity_deg_sec", angular)
            .put("acceleration_magnitude", snap.accel?.let { sqrt((it[0] * it[0] + it[1] * it[1] + it[2] * it[2]).toDouble()) } ?: JSONObject.NULL)
            .put("capture_reason", "automatic")
            .put("transition_mode", false)
    }

    private fun writeManifest(localSessionId: String, orderId: Long?, finished: Boolean, cancelled: Boolean) {
        val root = sessionDir ?: return
        val manifest = JSONObject()
            .put("schema_version", 1)
            .put("capture_type", "auto_photo_session")
            .put("capture_uuid", captureUuid)
            .put("local_session_id", localSessionId)
            .put("order_id", orderId ?: JSONObject.NULL)
            .put("server_capture_session_id", JSONObject.NULL)
            .put("started_at_utc", startedAt)
            .put("finished_at_utc", if (finished) Instant.now().toString() else JSONObject.NULL)
            .put("cancelled", cancelled)
            .put("photos_count", savedSequence)
            .put("rejected_count", rejectedCount)
            .put("manual_photos_count", 0)
            .put("transition_events_count", 0)
            .put("camera_id", selectedLens?.cameraId ?: JSONObject.NULL)
            .put("lens_label", selectedLens?.lensLabel ?: JSONObject.NULL)
            .put("zoom_ratio", lensRepository.getSelectedZoomRatio().toDouble())
            .put("camera_bind_profile", "Preview+ImageAnalysis+ImageCapture")
            .put("camera", cameraInfoJson())
            .put("settings", JSONObject()
                .put("auto_photo_interval_ms", settings.autoPhotoIntervalMs)
                .put("stable_gyro_threshold_deg_sec", settings.stableGyroThresholdDegSec)
                .put("stable_dwell_ms", settings.stableDwellMs)
                .put("min_sharpness", settings.minSharpness)
                .put("max_photos", settings.maxPhotos)
                .put("storage_reserve_bytes", settings.storageReserveBytes))
            .put("photos_metadata", "photos_metadata.jsonl")
            .put("photos", photoFilesJson())
        atomicWrite(File(root, "manifest.json"), manifest.toString(2))
        atomicWrite(File(root, "camera_info.json"), cameraInfoJson().toString(2))
    }

    private fun cameraInfoJson(): JSONObject {
        val lens = selectedLens
        return JSONObject()
            .put("camera_id", lens?.cameraId ?: JSONObject.NULL)
            .put("lens_label", lens?.lensLabel ?: JSONObject.NULL)
            .put("zoom_ratio", lensRepository.getSelectedZoomRatio().toDouble())
            .put("sensor_orientation", sensorOrientation ?: JSONObject.NULL)
            .put("focal_lengths_mm", JSONArray(lens?.focalLengthsMm ?: emptyList<Float>()))
            .put("physical_camera_ids", JSONArray(lens?.physicalCameraIds ?: emptyList<String>()))
            .put("min_zoom_ratio", lens?.minZoomRatio ?: JSONObject.NULL)
            .put("max_zoom_ratio", lens?.maxZoomRatio ?: JSONObject.NULL)
            .put("bind_profile", "Preview+ImageAnalysis+ImageCapture")
    }

    private fun photoFilesJson(): JSONArray {
        val array = JSONArray()
        val dir = photosDir ?: return array
        dir.listFiles { file -> file.isFile && file.extension.equals("jpg", ignoreCase = true) }
            ?.sortedBy { it.name }
            ?.forEach { array.put("photos/${it.name}") }
        return array
    }

    private fun appendQuality(root: File, reason: String, sharpness: Double, angular: Double, force: Boolean) {
        qualityLogSkipCount += 1
        if (!force && qualityLogSkipCount % 30 != 0) return
        appendJsonl(root, "quality.jsonl", JSONObject().put("t", Instant.now().toString()).put("reason", reason).put("sharpness", sharpness).put("angular_velocity_deg_sec", angular).put("capture_in_flight", captureInFlight))
    }

    private fun writeEvent(event: String) = sessionDir?.let { appendJsonl(it, "events.jsonl", JSONObject().put("t", Instant.now().toString()).put("event", event)) }
    private fun appendJsonl(root: File, name: String, json: JSONObject) { File(root, name).appendText(json.toString() + "\n") }
    private fun atomicWrite(file: File, text: String) { val tmp = File(file.parentFile, "${file.name}.tmp"); tmp.writeText(text); if (file.exists() && !file.delete()) throw IllegalStateException("failed to replace ${file.absolutePath}"); if (!tmp.renameTo(file)) throw IllegalStateException("failed to rename ${tmp.absolutePath} to ${file.absolutePath}") }
    private fun estimateSharpness(image: ImageProxy): Double { val b = image.planes[0].buffer; val step = maxOf(1, b.remaining() / 2048); var prev = 0; var sum = 0.0; var count = 0; var i = 0; while (b.hasRemaining()) { val v = b.get().toInt() and 0xff; if (i % step == 0) { sum += abs(v - prev); prev = v; count++ }; i++ }; return if (count == 0) 0.0 else sum / count }
    private fun getCameraProvider() = ProcessCameraProvider.getInstance(context).get()
    private suspend fun waitForCaptureInFlight(): Boolean {
        val deferred = synchronized(terminalTransitionLock) {
            if (!captureInFlight) return@synchronized null
            inFlightDeferred
        } ?: return true
        val completed = withTimeoutOrNull(10_000L) { deferred.await(); true } ?: false
        if (!completed) Log.e(TAG, "timeout waiting for ImageCapture callback")
        return completed
    }
    private fun beepAndVibrate() { val tone = toneGenerator ?: ToneGenerator(AudioManager.STREAM_SYSTEM, 60).also { toneGenerator = it }; runCatching { tone.startTone(ToneGenerator.TONE_PROP_BEEP, 80) }; runCatching { val v = context.getSystemService(Vibrator::class.java); if (android.os.Build.VERSION.SDK_INT >= 26) v?.vibrate(VibrationEffect.createOneShot(35, VibrationEffect.DEFAULT_AMPLITUDE)) else @Suppress("DEPRECATION") v?.vibrate(35) } }
    private fun cameraSensorOrientation(cameraId: String): Int? = runCatching { (context.getSystemService(Context.CAMERA_SERVICE) as CameraManager).getCameraCharacteristics(cameraId).get(CameraCharacteristics.SENSOR_ORIENTATION) }.getOrNull()
    private fun jpegDimensions(file: File): Pair<Int, Int> { val opts = BitmapFactory.Options().apply { inJustDecodeBounds = true }; BitmapFactory.decodeFile(file.absolutePath, opts); return opts.outWidth to opts.outHeight }
    private fun ensureExecutors() { if (analysisExecutor.isShutdown) analysisExecutor = newExecutor("AutoPhotoAnalysis"); if (captureExecutor.isShutdown) captureExecutor = newExecutor("AutoPhotoImageCapture") }
    private fun shutdownExecutor(executor: ExecutorService) { executor.shutdown(); runCatching { executor.awaitTermination(300, TimeUnit.MILLISECONDS) }; if (!executor.isTerminated) executor.shutdownNow() }

    companion object {
        fun frameName(sequence: Int) = AutoPhotoCaptureRules.nextFrameName(sequence)
        fun targetRotationToDegrees(rotation: Int) = when(rotation){ Surface.ROTATION_90 -> 90; Surface.ROTATION_180 -> 180; Surface.ROTATION_270 -> 270; else -> 0 }
        private fun newExecutor(name: String): ExecutorService = Executors.newSingleThreadExecutor { Thread(it, name).apply { isDaemon = true } }
        private fun vec(v: FloatArray?) = v?.let { JSONObject().put("x", it.getOrNull(0)).put("y", it.getOrNull(1)).put("z", it.getOrNull(2)) } ?: JSONObject.NULL
        private fun quat(v: FloatArray?) = v?.let { JSONObject().put("w", it.getOrNull(0)).put("x", it.getOrNull(1)).put("y", it.getOrNull(2)).put("z", it.getOrNull(3)) } ?: JSONObject.NULL
    }
}
