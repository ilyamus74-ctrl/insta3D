package com.maklertour.data.phonecamera

import android.Manifest
import android.annotation.SuppressLint
import android.content.Context
import android.content.pm.PackageManager
import android.graphics.ImageFormat
import android.hardware.camera2.CameraCaptureSession
import android.hardware.camera2.CameraCharacteristics
import android.hardware.camera2.CameraDevice
import android.hardware.camera2.CameraManager
import android.hardware.camera2.CaptureRequest
import android.hardware.camera2.params.OutputConfiguration
import android.hardware.camera2.params.SessionConfiguration
import android.media.ImageReader
import android.os.Build
import android.os.Handler
import android.os.HandlerThread
import android.util.Log
import androidx.camera.lifecycle.ProcessCameraProvider
import androidx.core.content.ContextCompat
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.suspendCancellableCoroutine
import kotlinx.coroutines.withContext
import kotlinx.coroutines.withTimeout
import org.json.JSONArray
import org.json.JSONObject
import java.io.File
import java.util.Collections
import java.util.Locale
import java.util.concurrent.Executor
import kotlin.coroutines.resume

class PhoneDualCameraProbe(private val context: Context) {
    private val appContext = context.applicationContext
    private val cameraManager = appContext.getSystemService(CameraManager::class.java)

    suspend fun run(): File {
        unbindCameraX()
        val output = File(appContext.filesDir, OUTPUT_FILE_NAME)
        val root = JSONObject()
        val cameraIds = cameraManager.cameraIdList.toList()
        Log.i(TAG, "camera ids: ${cameraIds.joinToString()}")
        root.put("cameraIds", JSONArray(cameraIds))
        root.put("cameras", JSONArray().also { cameras -> cameraIds.forEach { cameras.put(describeCamera(it)) } })

        val combinations = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.R) {
            cameraManager.concurrentCameraIds.map { it.toList().sorted() }
        } else {
            emptyList()
        }
        Log.i(TAG, "concurrent combinations: $combinations")
        root.put("concurrentCombinations", JSONArray().also { array -> combinations.forEach { array.put(JSONArray(it)) } })

        val pairResults = JSONArray()
        val supportedPairs = mutableListOf<Pair<String, String>>()
        for (combination in combinations) {
            for (i in combination.indices) {
                for (j in i + 1 until combination.size) {
                    val pairJson = checkPair(combination[i], combination[j])
                    pairResults.put(pairJson)
                    if (pairJson.optBoolean("supported")) supportedPairs += combination[i] to combination[j]
                }
            }
        }
        root.put("pairResults", pairResults)
        Log.i(TAG, "supported pair configs: $pairResults")

        val selectedCapturePair = selectCapturePair(supportedPairs)
        root.put("selectedCapturePair", selectedCapturePair?.let { JSONArray(listOf(it.first, it.second)) } ?: JSONObject.NULL)
        if (selectedCapturePair != null && hasCameraPermission()) {
            root.put("captureProbe", capturePair(selectedCapturePair.first, selectedCapturePair.second))
        } else {
            root.put("captureProbe", JSONObject().put("attempted", false).put("reason", if (hasCameraPermission()) "no supported pair" else "missing CAMERA permission"))
        }
        output.writeText(root.toString(2))
        return output
    }

    private suspend fun unbindCameraX() {
        withContext(Dispatchers.Main) {
            runCatching {
                ProcessCameraProvider.getInstance(appContext).get().unbindAll()
            }.onFailure { error ->
                Log.i(TAG, "CameraX unbindAll failed: ${error.message}")
            }
        }
    }

    private fun describeCamera(cameraId: String): JSONObject {
        val c = cameraManager.getCameraCharacteristics(cameraId)
        val map = c.get(CameraCharacteristics.SCALER_STREAM_CONFIGURATION_MAP)
        return JSONObject()
            .put("cameraId", cameraId)
            .put("lensFacing", lensFacingName(c.get(CameraCharacteristics.LENS_FACING)))
            .put("physicalCameraIds", JSONArray(if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) c.physicalCameraIds.toList().sorted() else emptyList<String>()))
            .put("activeArraySize", c.get(CameraCharacteristics.SENSOR_INFO_ACTIVE_ARRAY_SIZE)?.flattenToString())
            .put("availableFocalLengths", JSONArray((c.get(CameraCharacteristics.LENS_INFO_AVAILABLE_FOCAL_LENGTHS) ?: floatArrayOf()).map { it.toDouble() }))
            .put("yuv420888OutputSizes", JSONArray((map?.getOutputSizes(ImageFormat.YUV_420_888) ?: emptyArray()).map { "${it.width}x${it.height}" }))
    }

    private fun checkPair(a: String, b: String): JSONObject {
        val sizeA = supports640x480(a)
        val sizeB = supports640x480(b)
        val json = JSONObject().put("cameraIds", JSONArray(listOf(a, b))).put("hasYuv640x480", sizeA && sizeB)
        if (!sizeA || !sizeB || Build.VERSION.SDK_INT < Build.VERSION_CODES.R) return json.put("supported", false)
        val readerA = ImageReader.newInstance(WIDTH, HEIGHT, ImageFormat.YUV_420_888, 2)
        val readerB = ImageReader.newInstance(WIDTH, HEIGHT, ImageFormat.YUV_420_888, 2)
        try {
            val sessions = mutableMapOf(
                a to SessionConfiguration(SessionConfiguration.SESSION_REGULAR, listOf(OutputConfiguration(readerA.surface)), directExecutor, NoopSessionStateCallback),
                b to SessionConfiguration(SessionConfiguration.SESSION_REGULAR, listOf(OutputConfiguration(readerB.surface)), directExecutor, NoopSessionStateCallback),
            )
            val supported = cameraManager.isConcurrentSessionConfigurationSupported(sessions)
            return json.put("supported", supported)
        } finally {
            readerA.close(); readerB.close()
        }
    }

    private suspend fun capturePair(a: String, b: String): JSONObject = withTimeout(CAPTURE_TIMEOUT_MS) {
        val thread = HandlerThread("PhoneDualCameraProbe").also { it.start() }
        val handler = Handler(thread.looper)
        val executor = Executor { handler.post(it) }
        val readerA = ImageReader.newInstance(WIDTH, HEIGHT, ImageFormat.YUV_420_888, 8)
        val readerB = ImageReader.newInstance(WIDTH, HEIGHT, ImageFormat.YUV_420_888, 8)
        val timestampsA = Collections.synchronizedList(mutableListOf<Long>())
        val timestampsB = Collections.synchronizedList(mutableListOf<Long>())
        readerA.setOnImageAvailableListener({ r -> r.acquireLatestImage()?.use { timestampsA.add(it.timestamp) } }, handler)
        readerB.setOnImageAvailableListener({ r -> r.acquireLatestImage()?.use { timestampsB.add(it.timestamp) } }, handler)
        var deviceA: CameraDevice? = null; var deviceB: CameraDevice? = null
        var sessionA: CameraCaptureSession? = null; var sessionB: CameraCaptureSession? = null
        val json = JSONObject().put("attempted", true).put("cameraIds", JSONArray(listOf(a, b)))
        return try {
            deviceA = openCamera(a, handler); deviceB = openCamera(b, handler)
            sessionA = createSession(deviceA, readerA, executor, handler); sessionB = createSession(deviceB, readerB, executor, handler)
            startRepeating(deviceA, sessionA, readerA); startRepeating(deviceB, sessionB, readerB)
            Log.i(TAG, "open success: $a + $b")
            kotlinx.coroutines.delay(5_000)
            summarize(json.put("openSuccess", true), timestampsA.toList(), timestampsB.toList())
        } catch (t: Throwable) {
            Log.i(TAG, "open failure: $a + $b: ${t.message}")
            json.put("openSuccess", false).put("error", t.message ?: t.javaClass.simpleName)
        } finally {
            runCatching { sessionA?.close() }; runCatching { sessionB?.close() }
            runCatching { deviceA?.close() }; runCatching { deviceB?.close() }
            readerA.close(); readerB.close(); thread.quitSafely()
        }
    }

    private fun selectCapturePair(supportedPairs: List<Pair<String, String>>): Pair<String, String>? =
        supportedPairs.firstOrNull { isBackCamera(it.first) && isBackCamera(it.second) } ?: supportedPairs.firstOrNull()

    private fun isBackCamera(cameraId: String): Boolean =
        cameraManager.getCameraCharacteristics(cameraId).get(CameraCharacteristics.LENS_FACING) == CameraCharacteristics.LENS_FACING_BACK

    private fun summarize(json: JSONObject, a: List<Long>, b: List<Long>): JSONObject {
        val durationA = (a.maxOrNull() ?: 0L) - (a.minOrNull() ?: 0L)
        val durationB = (b.maxOrNull() ?: 0L) - (b.minOrNull() ?: 0L)
        val deltas = a.mapNotNull { ts -> b.minByOrNull { kotlin.math.abs(it - ts) }?.let { kotlin.math.abs(it - ts) / 1_000_000.0 } }
        val fpsA = if (durationA > 0) (a.size - 1) * 1_000_000_000.0 / durationA else 0.0
        val fpsB = if (durationB > 0) (b.size - 1) * 1_000_000_000.0 / durationB else 0.0
        Log.i(TAG, "fps and timestamp delta summary: camA=${fmt(fpsA)} camB=${fmt(fpsB)} deltaMs min/avg/max=${fmt(deltas.minOrNull() ?: 0.0)}/${fmt(deltas.average().takeIf { !it.isNaN() } ?: 0.0)}/${fmt(deltas.maxOrNull() ?: 0.0)}")
        return json.put("framesCamA", a.size).put("framesCamB", b.size).put("timestampsCamA", JSONArray(a)).put("timestampsCamB", JSONArray(b)).put("avgFpsCamA", fpsA).put("avgFpsCamB", fpsB).put("nearestTimestampDeltaMs", JSONObject().put("min", deltas.minOrNull() ?: 0.0).put("avg", deltas.average().takeIf { !it.isNaN() } ?: 0.0).put("max", deltas.maxOrNull() ?: 0.0))
    }

    @SuppressLint("MissingPermission")
    private suspend fun openCamera(id: String, handler: Handler): CameraDevice = suspendCancellableCoroutine { cont ->
        if (!hasCameraPermission()) { cont.resumeWith(Result.failure(SecurityException("Missing CAMERA permission"))); return@suspendCancellableCoroutine }
        cameraManager.openCamera(id, object : CameraDevice.StateCallback() {
            override fun onOpened(camera: CameraDevice) { if (cont.isActive) cont.resume(camera) else camera.close() }
            override fun onDisconnected(camera: CameraDevice) { camera.close(); if (cont.isActive) cont.resumeWith(Result.failure(IllegalStateException("Camera $id disconnected"))) }
            override fun onError(camera: CameraDevice, error: Int) { camera.close(); if (cont.isActive) cont.resumeWith(Result.failure(IllegalStateException("Camera $id error $error"))) }
        }, handler)
    }

    private suspend fun createSession(device: CameraDevice, reader: ImageReader, executor: Executor, handler: Handler): CameraCaptureSession = suspendCancellableCoroutine { cont ->
        val callback = object : CameraCaptureSession.StateCallback() { override fun onConfigured(s: CameraCaptureSession) { if (cont.isActive) cont.resume(s) else s.close() }; override fun onConfigureFailed(s: CameraCaptureSession) { if (cont.isActive) cont.resumeWith(Result.failure(IllegalStateException("session configure failed"))) } }
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) device.createCaptureSession(SessionConfiguration(SessionConfiguration.SESSION_REGULAR, listOf(OutputConfiguration(reader.surface)), executor, callback)) else device.createCaptureSession(listOf(reader.surface), callback, handler)
    }

    private fun startRepeating(device: CameraDevice, session: CameraCaptureSession, reader: ImageReader) {
        val request = device.createCaptureRequest(CameraDevice.TEMPLATE_PREVIEW).apply { addTarget(reader.surface); set(CaptureRequest.CONTROL_MODE, CaptureRequest.CONTROL_MODE_AUTO) }.build()
        session.setRepeatingRequest(request, null, null)
    }

    private fun supports640x480(id: String): Boolean = cameraManager.getCameraCharacteristics(id).get(CameraCharacteristics.SCALER_STREAM_CONFIGURATION_MAP)?.getOutputSizes(ImageFormat.YUV_420_888)?.any { it.width == WIDTH && it.height == HEIGHT } == true
    private fun hasCameraPermission(): Boolean = ContextCompat.checkSelfPermission(appContext, Manifest.permission.CAMERA) == PackageManager.PERMISSION_GRANTED
    private fun lensFacingName(value: Int?): String = when (value) { CameraCharacteristics.LENS_FACING_FRONT -> "FRONT"; CameraCharacteristics.LENS_FACING_BACK -> "BACK"; CameraCharacteristics.LENS_FACING_EXTERNAL -> "EXTERNAL"; else -> "UNKNOWN" }
    private fun fmt(value: Double): String = String.format(Locale.US, "%.2f", value)

    private object NoopSessionStateCallback : CameraCaptureSession.StateCallback() { override fun onConfigured(session: CameraCaptureSession) = Unit; override fun onConfigureFailed(session: CameraCaptureSession) = Unit }

    companion object { private const val TAG = "PhoneDualCameraProbe"; private const val WIDTH = 640; private const val HEIGHT = 480; private const val CAPTURE_TIMEOUT_MS = 10_000L; const val OUTPUT_FILE_NAME = "phone_dual_camera_probe.json"; private val directExecutor = Executor { it.run() } }
}
