package com.example.maklertour.data.dualphone

import android.content.Context
import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.graphics.ImageFormat
import android.graphics.Rect
import android.graphics.YuvImage
import android.os.SystemClock
import android.util.Size
import androidx.camera.camera2.interop.Camera2CameraInfo
import androidx.camera.camera2.interop.ExperimentalCamera2Interop
import androidx.camera.core.CameraSelector
import androidx.camera.core.ImageAnalysis
import androidx.camera.core.ImageProxy
import androidx.camera.lifecycle.ProcessCameraProvider
import androidx.core.content.ContextCompat
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.LifecycleOwner
import androidx.lifecycle.LifecycleRegistry
import com.maklertour.data.dualphone.DualPhoneRole
import com.maklertour.data.phonecamera.PhoneCameraLensRepository
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
        val cameraId = lensRepository.selectedOrDefault().first.cameraId
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
        val imageAnalysis = ImageAnalysis.Builder()
            .setTargetResolution(
                Size(
                    DualPhoneReducedFrame.MAX_WIDTH,
                    DualPhoneReducedFrame.MAX_HEIGHT,
                ),
            )
            .setBackpressureStrategy(ImageAnalysis.STRATEGY_KEEP_ONLY_LATEST)
            .build()
        imageAnalysis.setAnalyzer(analyzerExecutor) { image ->
            analyze(image, token)
        }
        provider.bindToLifecycle(lifecycle, selector, imageAnalysis)
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
            val sensorTimestampNs = image.imageInfo.timestamp
            val analysisReceivedElapsedRealtimeNs = SystemClock.elapsedRealtimeNanos()
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
        val nv21 = image.toNv21()
        val original = ByteArrayOutputStream().use { output ->
            val success = YuvImage(
                nv21,
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
        val scale = minOf(
            1.0,
            DualPhoneReducedFrame.MAX_WIDTH.toDouble() / sourceWidth,
            DualPhoneReducedFrame.MAX_HEIGHT.toDouble() / sourceHeight,
        )
        if (scale >= 1.0) {
            return EncodedJpeg(sourceWidth, sourceHeight, original)
        }

        val sourceBitmap = BitmapFactory.decodeByteArray(
            original,
            0,
            original.size,
        ) ?: error("JPEG decode before scale failed")
        val targetWidth = (sourceWidth * scale).toInt().coerceAtLeast(1)
        val targetHeight = (sourceHeight * scale).toInt().coerceAtLeast(1)
        val scaled = Bitmap.createScaledBitmap(
            sourceBitmap,
            targetWidth,
            targetHeight,
            true,
        )
        val bytes = ByteArrayOutputStream().use { output ->
            require(scaled.compress(Bitmap.CompressFormat.JPEG, JPEG_QUALITY, output))
            output.toByteArray()
        }
        if (scaled !== sourceBitmap) scaled.recycle()
        sourceBitmap.recycle()
        return EncodedJpeg(targetWidth, targetHeight, bytes)
    }

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
        private const val TARGET_FPS = 5L
        private const val FRAME_INTERVAL_NS = 1_000_000_000L / TARGET_FPS
        private const val JPEG_QUALITY = 65
    }
}
