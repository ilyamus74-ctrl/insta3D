package com.example.maklertour.data.dualphone

import android.content.Context
import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.graphics.ImageFormat
import android.graphics.Rect
import android.graphics.YuvImage
import android.hardware.camera2.CameraCharacteristics
import android.hardware.camera2.CaptureRequest
import android.os.SystemClock
import android.util.Log
import android.util.Size
import androidx.camera.camera2.interop.Camera2CameraControl
import androidx.camera.camera2.interop.Camera2CameraInfo
import androidx.camera.camera2.interop.CaptureRequestOptions
import androidx.camera.camera2.interop.ExperimentalCamera2Interop
import androidx.camera.core.Camera
import androidx.camera.core.CameraSelector
import androidx.camera.core.ImageAnalysis
import androidx.camera.core.ImageProxy
import androidx.camera.core.resolutionselector.AspectRatioStrategy
import androidx.camera.core.resolutionselector.ResolutionSelector
import androidx.camera.core.resolutionselector.ResolutionStrategy
import androidx.camera.lifecycle.ProcessCameraProvider
import androidx.core.content.ContextCompat
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.LifecycleOwner
import androidx.lifecycle.LifecycleRegistry
import com.maklertour.data.dualphone.DualPhoneRole
import com.maklertour.data.phonecamera.DualPhoneCalibrationCameraControls
import com.maklertour.data.phonecamera.DualPhoneCalibrationTimestampMapper
import com.maklertour.data.phonecamera.PhoneCameraFocusMode
import com.maklertour.data.phonecamera.PhoneCameraLensRepository
import com.maklertour.data.phonecamera.SensorTimelineDiagnostics
import java.io.ByteArrayOutputStream
import java.io.Closeable
import java.util.concurrent.ExecutorService
import java.util.concurrent.Executors
import java.util.concurrent.atomic.AtomicBoolean
import java.util.concurrent.atomic.AtomicLong
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow

enum class DualPhoneReducedFrameProducerState {
    STOPPED,
    STARTING,
    STREAMING,
    FAILED,
}

data class DualPhoneReducedFrameProducerSnapshot(
    val state: DualPhoneReducedFrameProducerState =
        DualPhoneReducedFrameProducerState.STOPPED,
    val cameraId: String? = null,
    val analysisSourceWidth: Int = 0,
    val analysisSourceHeight: Int = 0,
    val encodedWidth: Int = 0,
    val encodedHeight: Int = 0,
    val sourceAspectCropped: Boolean = false,
    val framesObserved: Long = 0L,
    val framesThrottled: Long = 0L,
    val framesEncoded: Long = 0L,
    val framesDroppedOversize: Long = 0L,
    val bytesEncoded: Long = 0L,
    val startedElapsedMs: Long? = null,
    val lastFrameElapsedMs: Long? = null,
    val latestFrame: DualPhoneReducedFrame? = null,
    val lastError: String? = null,
) {
    val effectiveFps: Double
        get() {
            val start = startedElapsedMs ?: return 0.0
            val end = lastFrameElapsedMs ?: return 0.0
            val seconds = (end - start).coerceAtLeast(1L) / 1_000.0
            return framesEncoded / seconds
        }
}

/**
 * CameraX ImageAnalysis producer for LM01B.
 *
 * Pixels are scaled and JPEG encoded without applying ImageProxy rotation. The
 * display rotation is metadata only. Binding failure is reported as
 * STREAM_UNAVAILABLE and must not change the selected physical camera or recorder
 * mode.
 */
@OptIn(ExperimentalCamera2Interop::class)
class DualPhoneReducedFrameProducer(context: Context) : Closeable {
    private val appContext = context.applicationContext
    private val lensRepository = PhoneCameraLensRepository(appContext)
    private val cameraTimestampMapper = DualPhoneCalibrationTimestampMapper()
    private val mainExecutor = ContextCompat.getMainExecutor(appContext)
    private val analyzerExecutor: ExecutorService =
        Executors.newSingleThreadExecutor { runnable ->
            Thread(runnable, "lm01b-frame-encoder").apply { isDaemon = true }
        }
    private val active = AtomicBoolean(false)
    private val generation = AtomicLong(0L)
    private val sequence = AtomicLong(0L)
    private val lastAcceptedTimestampNs = AtomicLong(0L)

    @Volatile
    private var cameraProvider: ProcessCameraProvider? = null
    @Volatile
    private var analysis: ImageAnalysis? = null
    @Volatile
    private var lifecycleOwner: ProducerLifecycleOwner? = null
    @Volatile
    private var owner: DualPhoneLiveStreamOwner? = null
    @Volatile
    private var role: DualPhoneRole = DualPhoneRole.STANDALONE
    @Volatile
    private var onFrame: ((DualPhoneReducedFrame) -> Unit)? = null
    @Volatile
    private var requestedNativeWidth: Int = 0
    @Volatile
    private var requestedNativeHeight: Int = 0

    private val mutableState = MutableStateFlow(
        DualPhoneReducedFrameProducerSnapshot(),
    )
    val state: StateFlow<DualPhoneReducedFrameProducerSnapshot> =
        mutableState.asStateFlow()
    val snapshot: DualPhoneReducedFrameProducerSnapshot
        get() = mutableState.value

    @Synchronized
    fun start(
        owner: DualPhoneLiveStreamOwner,
        role: DualPhoneRole,
        onFrame: (DualPhoneReducedFrame) -> Unit,
    ) {
        require(role == DualPhoneRole.MASTER || role == DualPhoneRole.SLAVE)
        startInternal(owner, role, onFrame)
    }

    @Synchronized
    fun startLaptop(
        owner: DualPhoneLiveStreamOwner,
        onFrame: (DualPhoneReducedFrame) -> Unit,
    ) {
        // The laptop protocol owns CAMERA_A/CAMERA_B in hello.slot. The shared
        // frame object keeps a neutral value that is never sent to the host.
        startInternal(owner, DualPhoneRole.STANDALONE, onFrame)
    }

    private fun startInternal(
        owner: DualPhoneLiveStreamOwner,
        role: DualPhoneRole,
        onFrame: (DualPhoneReducedFrame) -> Unit,
    ) {
        stopInternal(publishStopped = false)
        this.owner = owner
        this.role = role
        this.onFrame = onFrame
        active.set(true)
        sequence.set(0L)
        lastAcceptedTimestampNs.set(0L)
        val token = generation.incrementAndGet()
        mutableState.value = DualPhoneReducedFrameProducerSnapshot(
            state = DualPhoneReducedFrameProducerState.STARTING,
        )

        val providerFuture = ProcessCameraProvider.getInstance(appContext)
        providerFuture.addListener(
            {
                if (!active.get() || generation.get() != token) return@addListener
                runCatching {
                    bind(providerFuture.get(), token)
                }.onFailure { error ->
                    fail(token, error)
                }
            },
            mainExecutor,
        )
    }

    @Synchronized
    fun stop() {
        stopInternal(publishStopped = true)
    }

    private fun bind(provider: ProcessCameraProvider, token: Long) {
        check(active.get() && generation.get() == token)
        val lens = lensRepository.selectedOrDefault().first
        val cameraId = lens.cameraId
        val selectedMode = lensRepository.getSelectedVideoMode(
            cameraId,
            lens.supportedVideoModes,
        ) ?: throw IllegalStateException(
            "STREAM_UNAVAILABLE: no selected video mode for camera $cameraId",
        )
        require(
            selectedMode.width <= MAX_NATIVE_STEREO_WIDTH &&
                selectedMode.height <= MAX_NATIVE_STEREO_HEIGHT,
        ) {
            "STREAM_UNAVAILABLE: laptop stereo supports up to " +
                "${MAX_NATIVE_STEREO_WIDTH}x${MAX_NATIVE_STEREO_HEIGHT}; " +
                "selected ${selectedMode.width}x${selectedMode.height}"
        }
        requestedNativeWidth = selectedMode.width
        requestedNativeHeight = selectedMode.height
        val selector = CameraSelector.Builder()
            .addCameraFilter { cameraInfos ->
                cameraInfos.filter { cameraInfo ->
                    runCatching {
                        Camera2CameraInfo.from(cameraInfo).cameraId == cameraId
                    }.getOrDefault(false)
                }
            }
            .build()
        require(provider.hasCamera(selector)) {
            "STREAM_UNAVAILABLE: selected camera $cameraId is not available"
        }

        val lifecycle = ProducerLifecycleOwner().also { it.start() }
        val resolutionSelector = ResolutionSelector.Builder()
            .setAspectRatioStrategy(
                AspectRatioStrategy.RATIO_16_9_FALLBACK_AUTO_STRATEGY,
            )
            .setResolutionStrategy(
                ResolutionStrategy(
                    Size(selectedMode.width, selectedMode.height),
                    ResolutionStrategy.FALLBACK_RULE_CLOSEST_HIGHER_THEN_LOWER,
                ),
            )
            .build()
        val imageAnalysis = ImageAnalysis.Builder()
            .setResolutionSelector(resolutionSelector)
            .setBackpressureStrategy(ImageAnalysis.STRATEGY_KEEP_ONLY_LATEST)
            .build()
        imageAnalysis.setAnalyzer(analyzerExecutor) { image ->
            analyze(image, token)
        }
        val camera = provider.bindToLifecycle(lifecycle, selector, imageAnalysis)
        cameraTimestampMapper.reset(
            DualPhoneCalibrationCameraControls.timestampSource(camera),
        )
        applyMetricStereoControls(
            camera = camera,
            cameraId = cameraId,
        )
        cameraProvider = provider
        lifecycleOwner = lifecycle
        analysis = imageAnalysis
        mutableState.value = mutableState.value.copy(
            state = DualPhoneReducedFrameProducerState.STREAMING,
            cameraId = cameraId,
            startedElapsedMs = SystemClock.elapsedRealtime(),
            lastError = null,
        )
    }

    private fun analyze(image: ImageProxy, token: Long) {
        try {
            if (!active.get() || generation.get() != token) return
            update { current ->
                current.copy(framesObserved = current.framesObserved + 1L)
            }
            if (
                image.width != requestedNativeWidth ||
                image.height != requestedNativeHeight
            ) {
                throw IllegalStateException(
                    "STREAM_UNAVAILABLE: native stereo resolution mismatch; " +
                        "requested ${requestedNativeWidth}x${requestedNativeHeight}, " +
                        "actual ${image.width}x${image.height}",
                )
            }
            val sensorTimestampNs = image.imageInfo.timestamp
            val analysisReceivedElapsedRealtimeNs = SystemClock.elapsedRealtimeNanos()
            val cameraElapsedRealtimeNs =
                cameraTimestampMapper.toElapsedRealtimeNs(
                    cameraTimestampNs = sensorTimestampNs,
                    observedElapsedRealtimeNs =
                        analysisReceivedElapsedRealtimeNs,
                )
            SensorTimelineDiagnostics.onMappedCameraFrame(
                context = appContext,
                cameraElapsedRealtimeNs = cameraElapsedRealtimeNs,
                rawCameraTimestampNs = sensorTimestampNs,
                cameraTimestampSource = cameraTimestampMapper.sourceName,
                receiveElapsedRealtimeNs = analysisReceivedElapsedRealtimeNs,
            )
            val previousTimestampNs = lastAcceptedTimestampNs.get()
            if (
                previousTimestampNs > 0L &&
                sensorTimestampNs - previousTimestampNs < FRAME_INTERVAL_NS
            ) {
                update { current ->
                    current.copy(framesThrottled = current.framesThrottled + 1L)
                }
                return
            }
            if (!lastAcceptedTimestampNs.compareAndSet(
                    previousTimestampNs,
                    sensorTimestampNs,
                )
            ) {
                return
            }

            val encoded = encodeJpeg(image)
            if (encoded.bytes.size > DualPhoneReducedFrame.MAX_PAYLOAD_BYTES) {
                update { current ->
                    current.copy(
                        framesDroppedOversize =
                            current.framesDroppedOversize + 1L,
                    )
                }
                return
            }
            val activeOwner = owner ?: return
            val activeRole = role
            val frame = DualPhoneReducedFrame(
                streamId = activeOwner.streamId,
                dualCaptureId = activeOwner.dualCaptureId,
                sessionUuid = activeOwner.sessionUuid,
                role = activeRole,
                frameSequence = sequence.getAndIncrement(),
                sensorTimestampNs = sensorTimestampNs,
                captureElapsedRealtimeNs = analysisReceivedElapsedRealtimeNs,
                timestampSource = "CAMERAX_IMAGE_INFO_WITH_ANALYSIS_RECEIVE_ELAPSED_REALTIME",
                clockModelRevision = 0L,
                width = encoded.width,
                height = encoded.height,
                rotationAppliedDegrees = 0,
                imageProxyRotationDegrees = image.imageInfo.rotationDegrees,
                jpegBytes = encoded.bytes,
            )
            val nowMs = SystemClock.elapsedRealtime()
            update { current ->
                current.copy(
                    analysisSourceWidth = encoded.sourceWidth,
                    analysisSourceHeight = encoded.sourceHeight,
                    encodedWidth = encoded.width,
                    encodedHeight = encoded.height,
                    sourceAspectCropped = encoded.sourceAspectCropped,
                    framesEncoded = current.framesEncoded + 1L,
                    bytesEncoded = current.bytesEncoded + encoded.bytes.size,
                    lastFrameElapsedMs = nowMs,
                    latestFrame = frame,
                    lastError = null,
                )
            }
            onFrame?.invoke(frame)
        } catch (error: Throwable) {
            fail(token, error)
        } finally {
            image.close()
        }
    }

    private fun encodeJpeg(image: ImageProxy): EncodedJpeg {
        require(image.format == ImageFormat.YUV_420_888) {
            "STREAM_UNAVAILABLE: unsupported ImageAnalysis format ${image.format}"
        }
        val sourceWidth = image.width
        val sourceHeight = image.height
        val sourceNv21 = image.toNv21()
        val bytes = ByteArrayOutputStream().use { output ->
            val success = YuvImage(
                sourceNv21,
                ImageFormat.NV21,
                sourceWidth,
                sourceHeight,
                null,
            ).compressToJpeg(
                Rect(0, 0, sourceWidth, sourceHeight),
                JPEG_QUALITY,
                output,
            )
            require(success) { "JPEG compression failed" }
            output.toByteArray()
        }
        return EncodedJpeg(
            width = sourceWidth,
            height = sourceHeight,
            bytes = bytes,
            sourceWidth = sourceWidth,
            sourceHeight = sourceHeight,
            sourceAspectCropped = false,
        )
    }

    private fun centerCrop16By9(
        sourceWidth: Int,
        sourceHeight: Int,
    ): CropRegion {
        require(sourceWidth >= 2 && sourceHeight >= 2)
        val sourceIsWiderThan16By9 =
            sourceWidth.toLong() * 9L > sourceHeight.toLong() * 16L
        val sourceIsNarrowerThan16By9 =
            sourceWidth.toLong() * 9L < sourceHeight.toLong() * 16L
        val cropWidth: Int
        val cropHeight: Int
        when {
            sourceIsWiderThan16By9 -> {
                cropHeight = evenDimension(sourceHeight)
                cropWidth = evenDimension(cropHeight * 16 / 9)
                    .coerceAtMost(evenDimension(sourceWidth))
            }
            sourceIsNarrowerThan16By9 -> {
                cropWidth = evenDimension(sourceWidth)
                cropHeight = evenDimension(cropWidth * 9 / 16)
                    .coerceAtMost(evenDimension(sourceHeight))
            }
            else -> {
                cropWidth = evenDimension(sourceWidth)
                cropHeight = evenDimension(sourceHeight)
            }
        }
        val left = (((sourceWidth - cropWidth) / 2) / 2) * 2
        val top = (((sourceHeight - cropHeight) / 2) / 2) * 2
        return CropRegion(
            left = left,
            top = top,
            width = cropWidth,
            height = cropHeight,
        )
    }

    private fun cropNv21(
        source: ByteArray,
        sourceWidth: Int,
        sourceHeight: Int,
        crop: CropRegion,
    ): ByteArray {
        require(sourceWidth % 2 == 0 && sourceHeight % 2 == 0)
        require(crop.left % 2 == 0 && crop.top % 2 == 0)
        require(crop.width % 2 == 0 && crop.height % 2 == 0)
        require(crop.left + crop.width <= sourceWidth)
        require(crop.top + crop.height <= sourceHeight)
        val output = ByteArray(crop.width * crop.height * 3 / 2)
        for (row in 0 until crop.height) {
            val sourceOffset = (crop.top + row) * sourceWidth + crop.left
            val outputOffset = row * crop.width
            System.arraycopy(source, sourceOffset, output, outputOffset, crop.width)
        }
        val sourceChromaOffset = sourceWidth * sourceHeight
        val outputChromaOffset = crop.width * crop.height
        for (row in 0 until crop.height / 2) {
            val sourceOffset =
                sourceChromaOffset +
                    (crop.top / 2 + row) * sourceWidth +
                    crop.left
            val outputOffset = outputChromaOffset + row * crop.width
            System.arraycopy(source, sourceOffset, output, outputOffset, crop.width)
        }
        return output
    }

    private fun downscaleNv21(
        source: ByteArray,
        sourceWidth: Int,
        sourceHeight: Int,
        targetWidth: Int,
        targetHeight: Int,
    ): ByteArray {
        require(sourceWidth % 2 == 0 && sourceHeight % 2 == 0)
        require(targetWidth % 2 == 0 && targetHeight % 2 == 0)
        val output = ByteArray(targetWidth * targetHeight * 3 / 2)

        for (targetY in 0 until targetHeight) {
            val sourceY = targetY * sourceHeight / targetHeight
            val sourceRow = sourceY * sourceWidth
            val targetRow = targetY * targetWidth
            for (targetX in 0 until targetWidth) {
                val sourceX = targetX * sourceWidth / targetWidth
                output[targetRow + targetX] = source[sourceRow + sourceX]
            }
        }

        val sourceChromaOffset = sourceWidth * sourceHeight
        val targetChromaOffset = targetWidth * targetHeight
        val sourceChromaHeight = sourceHeight / 2
        val targetChromaHeight = targetHeight / 2
        for (targetY in 0 until targetChromaHeight) {
            val sourceY = targetY * sourceChromaHeight / targetChromaHeight
            val sourceRow = sourceChromaOffset + sourceY * sourceWidth
            val targetRow = targetChromaOffset + targetY * targetWidth
            for (targetX in 0 until targetWidth / 2) {
                val sourceX = targetX * (sourceWidth / 2) / (targetWidth / 2)
                val sourceIndex = sourceRow + sourceX * 2
                val targetIndex = targetRow + targetX * 2
                output[targetIndex] = source[sourceIndex]
                output[targetIndex + 1] = source[sourceIndex + 1]
            }
        }
        return output
    }

    private fun evenDimension(value: Int): Int =
        (value.coerceAtLeast(2) / 2) * 2

    private fun ImageProxy.toNv21(): ByteArray {
        val output = ByteArray(width * height * 3 / 2)
        copyPlane(planes[0], width, height, output, 0, 1)
        val chromaWidth = width / 2
        val chromaHeight = height / 2
        copyPlane(
            plane = planes[2],
            planeWidth = chromaWidth,
            planeHeight = chromaHeight,
            output = output,
            outputOffset = width * height,
            outputPixelStride = 2,
        )
        copyPlane(
            plane = planes[1],
            planeWidth = chromaWidth,
            planeHeight = chromaHeight,
            output = output,
            outputOffset = width * height + 1,
            outputPixelStride = 2,
        )
        return output
    }

    private fun copyPlane(
        plane: ImageProxy.PlaneProxy,
        planeWidth: Int,
        planeHeight: Int,
        output: ByteArray,
        outputOffset: Int,
        outputPixelStride: Int,
    ) {
        val buffer = plane.buffer.duplicate().apply { rewind() }
        var target = outputOffset
        for (row in 0 until planeHeight) {
            val rowStart = row * plane.rowStride
            for (column in 0 until planeWidth) {
                val source = rowStart + column * plane.pixelStride
                if (source < buffer.limit()) {
                    output[target] = buffer.get(source)
                }
                target += outputPixelStride
            }
        }
    }

    private fun applyMetricStereoControls(
        camera: Camera,
        cameraId: String,
    ) {
        val zoomFuture = camera.cameraControl.setZoomRatio(METRIC_STEREO_ZOOM_RATIO)
        zoomFuture.addListener(
            {
                runCatching { zoomFuture.get() }
                    .onFailure { error ->
                        Log.e(TAG, "failed to lock metric stereo zoom at 1.0x", error)
                    }
            },
            mainExecutor,
        )

        val info = Camera2CameraInfo.from(camera.cameraInfo)
        val options = CaptureRequestOptions.Builder()
        val videoModes = info.getCameraCharacteristic(
            CameraCharacteristics.CONTROL_AVAILABLE_VIDEO_STABILIZATION_MODES,
        ) ?: intArrayOf()
        if (videoModes.contains(CaptureRequest.CONTROL_VIDEO_STABILIZATION_MODE_OFF)) {
            options.setCaptureRequestOption(
                CaptureRequest.CONTROL_VIDEO_STABILIZATION_MODE,
                CaptureRequest.CONTROL_VIDEO_STABILIZATION_MODE_OFF,
            )
        }
        val opticalModes = info.getCameraCharacteristic(
            CameraCharacteristics.LENS_INFO_AVAILABLE_OPTICAL_STABILIZATION,
        ) ?: intArrayOf()
        if (opticalModes.contains(CaptureRequest.LENS_OPTICAL_STABILIZATION_MODE_OFF)) {
            options.setCaptureRequestOption(
                CaptureRequest.LENS_OPTICAL_STABILIZATION_MODE,
                CaptureRequest.LENS_OPTICAL_STABILIZATION_MODE_OFF,
            )
        }

        val focusMode = lensRepository.getSelectedFocusMode(cameraId)
        if (focusMode == PhoneCameraFocusMode.INFINITY_FIXED) {
            val availableAfModes =
                info.getCameraCharacteristic(
                    CameraCharacteristics.CONTROL_AF_AVAILABLE_MODES,
                ) ?: intArrayOf()
            require(
                availableAfModes.contains(CaptureRequest.CONTROL_AF_MODE_OFF),
            ) {
                "STREAM_UNAVAILABLE: saved fixed focus is unsupported for " +
                    "camera $cameraId"
            }
            options.setCaptureRequestOption(
                CaptureRequest.CONTROL_AF_MODE,
                CaptureRequest.CONTROL_AF_MODE_OFF,
            )
            options.setCaptureRequestOption(
                CaptureRequest.LENS_FOCUS_DISTANCE,
                0.0f,
            )
        }

        val optionsFuture = Camera2CameraControl.from(camera.cameraControl)
            .setCaptureRequestOptions(options.build())
        optionsFuture.addListener(
            {
                runCatching { optionsFuture.get() }
                    .onSuccess {
                        Log.i(
                            TAG,
                            "metric stereo controls applied camera_id=$cameraId " +
                                "focus=$focusMode",
                        )
                    }
                    .onFailure { error ->
                        Log.e(
                            TAG,
                            "failed to apply metric stereo controls " +
                                "camera_id=$cameraId focus=$focusMode",
                            error,
                        )
                    }
            },
            mainExecutor,
        )
    }

    @Synchronized
    private fun fail(token: Long, error: Throwable) {
        if (generation.get() != token) return
        stopInternal(publishStopped = false)
        mutableState.value = mutableState.value.copy(
            state = DualPhoneReducedFrameProducerState.FAILED,
            lastError = error.message ?: error.javaClass.simpleName,
        )
    }

    private fun update(
        transform: (DualPhoneReducedFrameProducerSnapshot) ->
            DualPhoneReducedFrameProducerSnapshot,
    ) {
        synchronized(mutableState) {
            mutableState.value = transform(mutableState.value)
        }
    }

    private fun stopInternal(publishStopped: Boolean) {
        active.set(false)
        generation.incrementAndGet()
        onFrame = null
        owner = null
        role = DualPhoneRole.STANDALONE
        requestedNativeWidth = 0
        requestedNativeHeight = 0
        val oldAnalysis = analysis
        val oldProvider = cameraProvider
        val oldLifecycle = lifecycleOwner
        analysis = null
        cameraProvider = null
        lifecycleOwner = null
        mainExecutor.execute {
            runCatching { oldAnalysis?.clearAnalyzer() }
            runCatching {
                if (oldAnalysis != null) oldProvider?.unbind(oldAnalysis)
            }
            oldLifecycle?.stop()
        }
        if (publishStopped) {
            mutableState.value = DualPhoneReducedFrameProducerSnapshot()
        }
    }

    override fun close() {
        stop()
        analyzerExecutor.shutdownNow()
    }

    private data class EncodedJpeg(
        val width: Int,
        val height: Int,
        val bytes: ByteArray,
        val sourceWidth: Int,
        val sourceHeight: Int,
        val sourceAspectCropped: Boolean,
    )

    private data class CropRegion(
        val left: Int,
        val top: Int,
        val width: Int,
        val height: Int,
    )

    private class ProducerLifecycleOwner : LifecycleOwner {
        private val registry = LifecycleRegistry.createUnsafe(this)
        override val lifecycle: Lifecycle
            get() = registry

        fun start() {
            registry.currentState = Lifecycle.State.CREATED
            registry.currentState = Lifecycle.State.STARTED
            registry.currentState = Lifecycle.State.RESUMED
        }

        fun stop() {
            registry.currentState = Lifecycle.State.DESTROYED
        }
    }

    companion object {
        private const val TAG = "DualPhoneReducedFrame"
        private const val MAX_NATIVE_STEREO_WIDTH = 1_920
        private const val MAX_NATIVE_STEREO_HEIGHT = 1_080
        private const val METRIC_STEREO_ZOOM_RATIO = 1.0f
        private const val TARGET_FPS = 15L
        private const val FRAME_INTERVAL_NS = 1_000_000_000L / TARGET_FPS
        private const val JPEG_QUALITY = 85
    }
}
