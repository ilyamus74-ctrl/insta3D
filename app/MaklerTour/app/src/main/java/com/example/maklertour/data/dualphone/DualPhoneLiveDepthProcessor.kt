package com.example.maklertour.data.dualphone

import android.content.Context
import android.os.SystemClock
import com.maklertour.data.calibration.DualPhoneCalibrationProfileResult
import com.maklertour.data.calibration.DualPhoneCalibrationProfileStore
import com.maklertour.data.calibration.DualPhoneLiveIntrinsicsEstimate
import com.maklertour.data.dualphone.DualPhoneClockSyncSnapshot
import com.maklertour.data.dualphone.DualPhoneStereoSettingsStore
import java.io.Closeable
import java.util.ArrayDeque
import java.util.Locale
import java.util.concurrent.atomic.AtomicBoolean
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.cancel
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch
import org.opencv.android.OpenCVLoader
import org.opencv.calib3d.Calib3d
import org.opencv.calib3d.StereoBM
import org.opencv.core.Core
import org.opencv.core.CvType
import org.opencv.core.Mat
import org.opencv.core.MatOfByte
import org.opencv.core.MatOfDouble
import org.opencv.core.MatOfInt
import org.opencv.core.Scalar
import org.opencv.core.Size
import org.opencv.imgcodecs.Imgcodecs
import org.opencv.imgproc.Imgproc
import kotlin.math.abs
import kotlin.math.roundToLong

enum class DualPhoneLiveDepthState {
    STOPPED,
    WAITING_FRAMES,
    WAITING_CLOCK,
    PAIRING,
    PROCESSING,
    READY,
    LATE_PAIR,
    LOW_TEXTURE,
    BLOCKED,
    FAILED,
}

data class DualPhoneLiveDepthSnapshot(
    val state: DualPhoneLiveDepthState = DualPhoneLiveDepthState.STOPPED,
    val localFrameSequence: Long? = null,
    val remoteFrameSequence: Long? = null,
    val pairDeltaMs: Double? = null,
    val pairQuality: String = "WAITING",
    val processedPairs: Long = 0L,
    val rejectedPairs: Long = 0L,
    val validDisparityPercent: Double = 0.0,
    val medianDepthMeters: Double? = null,
    val baselineAxis: String? = null,
    val depthInputRotation: String = "none",
    val rectifiedMasterJpeg: ByteArray? = null,
    val rectifiedSlaveJpeg: ByteArray? = null,
    val disparityPreviewJpeg: ByteArray? = null,
    val depthPreviewJpeg: ByteArray? = null,
    val processingMs: Long? = null,
    val lastUpdatedElapsedMs: Long? = null,
    val message: String = "Waiting for real MASTER and SLAVE frames",
    val lastError: String? = null,
)

/**
 * LM02 live stereo pairer and first diagnostic depth processor.
 *
 * The processor keeps short bounded frame histories, converts SLAVE elapsed time
 * into the MASTER clock domain, rectifies a real pair with the accepted calibration
 * profile and computes a low-resolution StereoBM disparity/depth preview.
 */
class DualPhoneLiveDepthProcessor(context: Context) : Closeable {
    private data class StereoPair(
        val master: DualPhoneReducedFrame,
        val slave: DualPhoneReducedFrame,
        val deltaNs: Long,
    )

    private data class ProcessedDepth(
        val rectifiedMasterJpeg: ByteArray,
        val rectifiedSlaveJpeg: ByteArray,
        val disparityPreviewJpeg: ByteArray,
        val depthPreviewJpeg: ByteArray,
        val validPercent: Double,
        val medianDepthMeters: Double?,
        val baselineAxis: String,
        val depthInputRotation: String,
    )

    private val appContext = context.applicationContext
    private val settingsStore = DualPhoneStereoSettingsStore(appContext)
    private val profileStore = DualPhoneCalibrationProfileStore(appContext)
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.Default)
    private val processing = AtomicBoolean(false)
    private val lock = Any()
    private val masterFrames = ArrayDeque<DualPhoneReducedFrame>()
    private val slaveFrames = ArrayDeque<DualPhoneReducedFrame>()
    private val mutableState = MutableStateFlow(DualPhoneLiveDepthSnapshot())

    val state: StateFlow<DualPhoneLiveDepthSnapshot> = mutableState.asStateFlow()

    private var activeStreamId: String? = null
    private var lastMasterSequence: Long? = null
    private var lastSlaveSequence: Long? = null
    private var lastProcessedKey: String? = null
    private var lastRejectedKey: String? = null
    private var lastProcessingStartedMs: Long = 0L
    private var cachedProfileId: String? = null
    private var cachedProfile: DualPhoneCalibrationProfileResult? = null

    fun submit(
        masterFrame: DualPhoneReducedFrame?,
        slaveFrame: DualPhoneReducedFrame?,
        clockSync: DualPhoneClockSyncSnapshot,
    ) {
        val pair = synchronized(lock) {
            if (masterFrame == null || slaveFrame == null) {
                publishWaiting(
                    DualPhoneLiveDepthState.WAITING_FRAMES,
                    "Waiting for both real camera frames",
                )
                return
            }
            if (masterFrame.streamId != slaveFrame.streamId) {
                resetHistories(masterFrame.streamId)
                publishBlocked("MASTER and SLAVE stream_id do not match")
                return
            }
            if (activeStreamId != masterFrame.streamId) {
                resetHistories(masterFrame.streamId)
            }
            appendIfNew(masterFrames, masterFrame, isMaster = true)
            appendIfNew(slaveFrames, slaveFrame, isMaster = false)

            if (!clockSync.ready || clockSync.offsetNs == null) {
                publishWaiting(
                    DualPhoneLiveDepthState.WAITING_CLOCK,
                    "Clock sync is not ready for stereo pairing",
                )
                return
            }
            findBestPair(clockSync)
        } ?: return

        val pairKey = "${pair.master.frameSequence}:${pair.slave.frameSequence}"
        val nowMs = SystemClock.elapsedRealtime()
        if (pairKey == lastProcessedKey || processing.get()) return
        if (nowMs - lastProcessingStartedMs < MIN_PROCESSING_INTERVAL_MS) return
        if (pair.deltaNs > MAX_PAIR_DELTA_NS) {
            if (lastRejectedKey != pairKey) {
                lastRejectedKey = pairKey
                update { current ->
                    current.copy(
                        state = DualPhoneLiveDepthState.PAIRING,
                        localFrameSequence = pair.master.frameSequence,
                        remoteFrameSequence = pair.slave.frameSequence,
                        pairDeltaMs = pair.deltaNs / 1_000_000.0,
                        pairQuality = "DROPPED",
                        rejectedPairs = current.rejectedPairs + 1L,
                        message = "Closest pair is outside the ${MAX_PAIR_DELTA_NS / 1_000_000L} ms gate",
                        lastError = null,
                    )
                }
            }
            return
        }

        lastProcessedKey = pairKey
        lastProcessingStartedMs = nowMs
        processing.set(true)
        removeConsumed(pair)
        update { current ->
            current.copy(
                state = DualPhoneLiveDepthState.PROCESSING,
                localFrameSequence = pair.master.frameSequence,
                remoteFrameSequence = pair.slave.frameSequence,
                pairDeltaMs = pair.deltaNs / 1_000_000.0,
                pairQuality = pairQuality(pair.deltaNs),
                message = "Rectifying real pair and computing disparity",
                lastError = null,
            )
        }

        scope.launch {
            val startedMs = SystemClock.elapsedRealtime()
            runCatching {
                process(pair)
            }.onSuccess { result ->
                val elapsedMs = SystemClock.elapsedRealtime() - startedMs
                val deltaMs = pair.deltaNs / 1_000_000.0
                val outputState = when {
                    result.validPercent < MIN_VALID_PERCENT ->
                        DualPhoneLiveDepthState.LOW_TEXTURE
                    pair.deltaNs > READY_PAIR_DELTA_NS ->
                        DualPhoneLiveDepthState.LATE_PAIR
                    else -> DualPhoneLiveDepthState.READY
                }
                update { current ->
                    current.copy(
                        state = outputState,
                        pairDeltaMs = deltaMs,
                        pairQuality = pairQuality(pair.deltaNs),
                        processedPairs = current.processedPairs + 1L,
                        validDisparityPercent = result.validPercent,
                        medianDepthMeters = result.medianDepthMeters,
                        baselineAxis = result.baselineAxis,
                        depthInputRotation = result.depthInputRotation,
                        rectifiedMasterJpeg = result.rectifiedMasterJpeg,
                        rectifiedSlaveJpeg = result.rectifiedSlaveJpeg,
                        disparityPreviewJpeg = result.disparityPreviewJpeg,
                        depthPreviewJpeg = result.depthPreviewJpeg,
                        processingMs = elapsedMs,
                        lastUpdatedElapsedMs = SystemClock.elapsedRealtime(),
                        message = when (outputState) {
                            DualPhoneLiveDepthState.READY ->
                                "Real rectified pair and first depth preview are ready"
                            DualPhoneLiveDepthState.LATE_PAIR ->
                                "Depth updated from a late pair; move more slowly"
                            DualPhoneLiveDepthState.LOW_TEXTURE ->
                                "Depth updated, but the scene has too little stereo texture"
                            else -> current.message
                        },
                        lastError = null,
                    )
                }
            }.onFailure { error ->
                update { current ->
                    current.copy(
                        state = if (
                            error.message?.startsWith("DEPTH_BLOCKED:") == true
                        ) {
                            DualPhoneLiveDepthState.BLOCKED
                        } else {
                            DualPhoneLiveDepthState.FAILED
                        },
                        message = "Depth processing stopped",
                        lastError = error.message ?: error.javaClass.simpleName,
                    )
                }
            }
            processing.set(false)
        }
    }

    fun reset() {
        synchronized(lock) {
            resetHistories(null)
        }
        mutableState.value = DualPhoneLiveDepthSnapshot()
    }

    override fun close() {
        reset()
        scope.cancel()
    }

    private fun process(pair: StereoPair): ProcessedDepth {
        check(openCvReady) {
            "DEPTH_BLOCKED: OpenCV is unavailable"
        }
        val profile = loadActiveProfile()
        check(profile.successful) {
            "DEPTH_BLOCKED: active calibration profile is not accepted"
        }

        val masterRaw = decodeFrame(pair.master)
        val slaveRaw = decodeFrame(pair.slave)
        val masterInput = Mat()
        val slaveInput = Mat()
        val cameraMatrixMaster = Mat()
        val cameraMatrixSlave = Mat()
        val distortionMaster = MatOfDouble()
        val distortionSlave = MatOfDouble()
        val stereoRotation = Mat(3, 3, CvType.CV_64F)
        val stereoTranslation = Mat(3, 1, CvType.CV_64F)
        val rectificationMaster = Mat()
        val rectificationSlave = Mat()
        val projectionMaster = Mat()
        val projectionSlave = Mat()
        val q = Mat()
        val mapMasterX = Mat()
        val mapMasterY = Mat()
        val mapSlaveX = Mat()
        val mapSlaveY = Mat()
        val rectifiedMaster = Mat()
        val rectifiedSlave = Mat()
        val depthMaster = Mat()
        val depthSlave = Mat()
        val grayMaster = Mat()
        val graySlave = Mat()
        val disparity16 = Mat()
        val disparity32 = Mat()
        val disparity8 = Mat()
        val validMask = Mat()
        val invalidMask = Mat()
        val heatmap = Mat()

        try {
            val target = targetSize(masterRaw, slaveRaw)
            Imgproc.resize(masterRaw, masterInput, target, 0.0, 0.0, Imgproc.INTER_AREA)
            Imgproc.resize(slaveRaw, slaveInput, target, 0.0, 0.0, Imgproc.INTER_AREA)

            fillCameraMatrix(
                cameraMatrixMaster,
                profile.masterIntrinsics,
                target,
            )
            fillCameraMatrix(
                cameraMatrixSlave,
                profile.slaveIntrinsics,
                target,
            )
            distortionMaster.fromArray(
                profile.masterIntrinsics.k1 ?: 0.0,
                profile.masterIntrinsics.k2 ?: 0.0,
                0.0,
                0.0,
                0.0,
            )
            distortionSlave.fromArray(
                profile.slaveIntrinsics.k1 ?: 0.0,
                profile.slaveIntrinsics.k2 ?: 0.0,
                0.0,
                0.0,
                0.0,
            )
            stereoRotation.put(
                0,
                0,
                *profile.stereo.rotation.toDoubleArray(),
            )
            stereoTranslation.put(
                0,
                0,
                *profile.stereo.translationMm.toDoubleArray(),
            )

            Calib3d.stereoRectify(
                cameraMatrixMaster,
                distortionMaster,
                cameraMatrixSlave,
                distortionSlave,
                target,
                stereoRotation,
                stereoTranslation,
                rectificationMaster,
                rectificationSlave,
                projectionMaster,
                projectionSlave,
                q,
                Calib3d.CALIB_ZERO_DISPARITY,
                0.0,
                target,
            )
            Calib3d.initUndistortRectifyMap(
                cameraMatrixMaster,
                distortionMaster,
                rectificationMaster,
                projectionMaster,
                target,
                CvType.CV_32FC1,
                mapMasterX,
                mapMasterY,
            )
            Calib3d.initUndistortRectifyMap(
                cameraMatrixSlave,
                distortionSlave,
                rectificationSlave,
                projectionSlave,
                target,
                CvType.CV_32FC1,
                mapSlaveX,
                mapSlaveY,
            )
            Imgproc.remap(
                masterInput,
                rectifiedMaster,
                mapMasterX,
                mapMasterY,
                Imgproc.INTER_LINEAR,
            )
            Imgproc.remap(
                slaveInput,
                rectifiedSlave,
                mapSlaveX,
                mapSlaveY,
                Imgproc.INTER_LINEAR,
            )

            val p2Tx = projectionSlave.get(0, 3)?.getOrNull(0) ?: 0.0
            val p2Ty = projectionSlave.get(1, 3)?.getOrNull(0) ?: 0.0
            val vertical = abs(p2Ty) > abs(p2Tx)
            val baselineAxis = if (vertical) "vertical" else "horizontal"
            val rotation = if (vertical) {
                if (p2Ty < 0.0) Core.ROTATE_90_COUNTERCLOCKWISE else Core.ROTATE_90_CLOCKWISE
            } else {
                null
            }
            if (rotation == null) {
                rectifiedMaster.copyTo(depthMaster)
                rectifiedSlave.copyTo(depthSlave)
            } else {
                Core.rotate(rectifiedMaster, depthMaster, rotation)
                Core.rotate(rectifiedSlave, depthSlave, rotation)
            }
            val depthInputRotation = when (rotation) {
                Core.ROTATE_90_COUNTERCLOCKWISE -> "rotate_90_ccw"
                Core.ROTATE_90_CLOCKWISE -> "rotate_90_cw"
                else -> "none"
            }

            Imgproc.cvtColor(depthMaster, grayMaster, Imgproc.COLOR_BGR2GRAY)
            Imgproc.cvtColor(depthSlave, graySlave, Imgproc.COLOR_BGR2GRAY)
            val disparities = chooseNumDisparities(grayMaster.cols())
            val stereo = StereoBM.create(disparities, BLOCK_SIZE)
            try {
                stereo.setTextureThreshold(8)
                stereo.setUniquenessRatio(10)
                stereo.compute(grayMaster, graySlave, disparity16)
            } finally {
                stereo.clear()
            }
            disparity16.convertTo(disparity32, CvType.CV_32F, 1.0 / 16.0)
            Core.compare(
                disparity32,
                Scalar(MIN_VALID_DISPARITY),
                validMask,
                Core.CMP_GT,
            )
            Core.normalize(
                disparity32,
                disparity8,
                0.0,
                255.0,
                Core.NORM_MINMAX,
                CvType.CV_8U,
                validMask,
            )
            Imgproc.applyColorMap(disparity8, heatmap, Imgproc.COLORMAP_TURBO)
            Core.bitwise_not(validMask, invalidMask)
            heatmap.setTo(Scalar(0.0, 0.0, 0.0), invalidMask)

            val focalPx = if (vertical) {
                projectionMaster.get(1, 1)?.getOrNull(0)
            } else {
                projectionMaster.get(0, 0)?.getOrNull(0)
            } ?: error("DEPTH_BLOCKED: rectified focal length is unavailable")
            val baselineMm = profile.stereo.baselineMm
                ?: error("DEPTH_BLOCKED: baseline is unavailable")
            val metrics = depthMetrics(
                disparity32 = disparity32,
                focalPx = focalPx,
                baselineMm = baselineMm,
            )

            return ProcessedDepth(
                rectifiedMasterJpeg = encodeJpeg(depthMaster),
                rectifiedSlaveJpeg = encodeJpeg(depthSlave),
                disparityPreviewJpeg = encodeJpeg(disparity8),
                depthPreviewJpeg = encodeJpeg(heatmap),
                validPercent = metrics.first,
                medianDepthMeters = metrics.second,
                baselineAxis = baselineAxis,
                depthInputRotation = depthInputRotation,
            )
        } finally {
            listOf(
                masterRaw,
                slaveRaw,
                masterInput,
                slaveInput,
                cameraMatrixMaster,
                cameraMatrixSlave,
                distortionMaster,
                distortionSlave,
                stereoRotation,
                stereoTranslation,
                rectificationMaster,
                rectificationSlave,
                projectionMaster,
                projectionSlave,
                q,
                mapMasterX,
                mapMasterY,
                mapSlaveX,
                mapSlaveY,
                rectifiedMaster,
                rectifiedSlave,
                depthMaster,
                depthSlave,
                grayMaster,
                graySlave,
                disparity16,
                disparity32,
                disparity8,
                validMask,
                invalidMask,
                heatmap,
            ).forEach { it.release() }
        }
    }

    private fun loadActiveProfile(): DualPhoneCalibrationProfileResult {
        val profileId = settingsStore.load().activeCalibrationProfileId
            ?: error("DEPTH_BLOCKED: no active calibration profile")
        if (cachedProfileId != profileId || cachedProfile == null) {
            cachedProfileId = profileId
            cachedProfile = profileStore.load(profileId)
        }
        return cachedProfile
            ?: error("DEPTH_BLOCKED: calibration profile $profileId was not found")
    }

    private fun decodeFrame(frame: DualPhoneReducedFrame): Mat {
        val encoded = MatOfByte(*frame.jpegBytes)
        return try {
            Imgcodecs.imdecode(encoded, Imgcodecs.IMREAD_COLOR).also { decoded ->
                check(!decoded.empty()) { "DEPTH_BLOCKED: JPEG decode failed" }
            }
        } finally {
            encoded.release()
        }
    }

    private fun targetSize(master: Mat, slave: Mat): Size {
        val sourceWidth = minOf(master.cols(), slave.cols())
        val sourceHeight = minOf(master.rows(), slave.rows())
        check(sourceWidth > 0 && sourceHeight > 0)
        val scale = minOf(
            1.0,
            WORK_WIDTH.toDouble() / sourceWidth,
            WORK_HEIGHT.toDouble() / sourceHeight,
        )
        val width = (sourceWidth * scale).toInt().coerceAtLeast(64)
        val height = (sourceHeight * scale).toInt().coerceAtLeast(48)
        return Size(width.toDouble(), height.toDouble())
    }

    private fun fillCameraMatrix(
        output: Mat,
        intrinsics: DualPhoneLiveIntrinsicsEstimate,
        target: Size,
    ) {
        check(intrinsics.acceptable) {
            "DEPTH_BLOCKED: camera intrinsics are not accepted"
        }
        val sourceWidth = intrinsics.imageWidth.coerceAtLeast(1)
        val sourceHeight = intrinsics.imageHeight.coerceAtLeast(1)
        val scaleX = target.width / sourceWidth
        val scaleY = target.height / sourceHeight
        val values = doubleArrayOf(
            requireNotNull(intrinsics.fx) * scaleX,
            0.0,
            requireNotNull(intrinsics.cx) * scaleX,
            0.0,
            requireNotNull(intrinsics.fy) * scaleY,
            requireNotNull(intrinsics.cy) * scaleY,
            0.0,
            0.0,
            1.0,
        )
        output.create(3, 3, CvType.CV_64F)
        output.put(0, 0, *values)
    }

    private fun depthMetrics(
        disparity32: Mat,
        focalPx: Double,
        baselineMm: Double,
    ): Pair<Double, Double?> {
        val depths = ArrayList<Double>()
        var sampled = 0
        var valid = 0
        var row = 0
        while (row < disparity32.rows()) {
            var column = 0
            while (column < disparity32.cols()) {
                sampled += 1
                val disparity = disparity32.get(row, column)?.getOrNull(0) ?: 0.0
                if (disparity > MIN_VALID_DISPARITY) {
                    val meters = focalPx * baselineMm / disparity / 1_000.0
                    if (meters in MIN_DEPTH_METERS..MAX_DEPTH_METERS) {
                        valid += 1
                        depths += meters
                    }
                }
                column += METRIC_SAMPLE_STEP
            }
            row += METRIC_SAMPLE_STEP
        }
        depths.sort()
        val median = if (depths.isEmpty()) null else depths[depths.size / 2]
        val percent = if (sampled == 0) 0.0 else valid * 100.0 / sampled
        return percent to median
    }

    private fun encodeJpeg(mat: Mat): ByteArray {
        val output = MatOfByte()
        val parameters = MatOfInt(Imgcodecs.IMWRITE_JPEG_QUALITY, OUTPUT_JPEG_QUALITY)
        return try {
            check(Imgcodecs.imencode(".jpg", mat, output, parameters)) {
                "Depth preview JPEG encode failed"
            }
            output.toArray()
        } finally {
            output.release()
            parameters.release()
        }
    }

    private fun findBestPair(clockSync: DualPhoneClockSyncSnapshot): StereoPair? {
        var best: StereoPair? = null
        for (master in masterFrames) {
            for (slave in slaveFrames) {
                val slaveMasterNs = slaveToMasterTime(
                    slave.captureElapsedRealtimeNs,
                    clockSync,
                )
                val delta = abs(master.captureElapsedRealtimeNs - slaveMasterNs)
                if (best == null || delta < best.deltaNs) {
                    best = StereoPair(master, slave, delta)
                }
            }
        }
        if (best == null) {
            publishWaiting(
                DualPhoneLiveDepthState.PAIRING,
                "Collecting a synchronized frame pair",
            )
        }
        return best
    }

    private fun slaveToMasterTime(
        slaveElapsedNs: Long,
        clockSync: DualPhoneClockSyncSnapshot,
    ): Long {
        val offset = requireNotNull(clockSync.offsetNs)
        val reference = clockSync.referenceMasterNs ?: 0L
        val driftPpm = clockSync.driftPpm ?: 0.0
        val initialMaster = slaveElapsedNs - offset
        val driftNs = (initialMaster - reference).toDouble() * driftPpm / 1_000_000.0
        return slaveElapsedNs - offset - driftNs.roundToLong()
    }

    private fun appendIfNew(
        queue: ArrayDeque<DualPhoneReducedFrame>,
        frame: DualPhoneReducedFrame,
        isMaster: Boolean,
    ) {
        val previous = if (isMaster) lastMasterSequence else lastSlaveSequence
        if (previous == frame.frameSequence) return
        if (isMaster) {
            lastMasterSequence = frame.frameSequence
        } else {
            lastSlaveSequence = frame.frameSequence
        }
        queue.addLast(frame)
        while (queue.size > MAX_HISTORY_FRAMES) queue.removeFirst()
    }

    private fun removeConsumed(pair: StereoPair) {
        synchronized(lock) {
            while (
                masterFrames.isNotEmpty() &&
                masterFrames.first.frameSequence <= pair.master.frameSequence
            ) {
                masterFrames.removeFirst()
            }
            while (
                slaveFrames.isNotEmpty() &&
                slaveFrames.first.frameSequence <= pair.slave.frameSequence
            ) {
                slaveFrames.removeFirst()
            }
        }
    }

    private fun resetHistories(streamId: String?) {
        activeStreamId = streamId
        masterFrames.clear()
        slaveFrames.clear()
        lastMasterSequence = null
        lastSlaveSequence = null
        lastProcessedKey = null
        lastRejectedKey = null
        cachedProfileId = null
        cachedProfile = null
    }

    private fun pairQuality(deltaNs: Long): String = when {
        deltaNs <= READY_PAIR_DELTA_NS -> "READY"
        deltaNs <= MAX_PAIR_DELTA_NS -> "LATE"
        else -> "DROPPED"
    }

    private fun chooseNumDisparities(width: Int): Int {
        val maximum = ((width / 4) / 16 * 16).coerceAtLeast(16)
        return minOf(DEFAULT_NUM_DISPARITIES, maximum)
    }

    private fun publishWaiting(state: DualPhoneLiveDepthState, message: String) {
        if (processing.get()) return
        update { current ->
            current.copy(
                state = state,
                message = message,
                lastError = null,
            )
        }
    }

    private fun publishBlocked(message: String) {
        update { current ->
            current.copy(
                state = DualPhoneLiveDepthState.BLOCKED,
                message = "Depth is blocked",
                lastError = message,
            )
        }
    }

    private fun update(
        transform: (DualPhoneLiveDepthSnapshot) -> DualPhoneLiveDepthSnapshot,
    ) {
        synchronized(mutableState) {
            mutableState.value = transform(mutableState.value)
        }
    }

    companion object {
        private const val MAX_HISTORY_FRAMES = 8
        private const val MIN_PROCESSING_INTERVAL_MS = 450L
        private const val READY_PAIR_DELTA_NS = 35_000_000L
        private const val MAX_PAIR_DELTA_NS = 120_000_000L
        private const val WORK_WIDTH = 320
        private const val WORK_HEIGHT = 240
        private const val DEFAULT_NUM_DISPARITIES = 64
        private const val BLOCK_SIZE = 15
        private const val MIN_VALID_DISPARITY = 1.0
        private const val MIN_VALID_PERCENT = 2.0
        private const val MIN_DEPTH_METERS = 0.20
        private const val MAX_DEPTH_METERS = 20.0
        private const val METRIC_SAMPLE_STEP = 4
        private const val OUTPUT_JPEG_QUALITY = 78

        private val openCvReady: Boolean by lazy {
            runCatching { OpenCVLoader.initDebug() }.getOrDefault(false)
        }

        fun formatMeters(value: Double?): String = if (value == null) {
            "—"
        } else {
            String.format(Locale.US, "%.2f m", value)
        }
    }
}
