package com.maklertour.data.tof

import android.content.Context
import android.os.SystemClock
import android.util.Log
import com.maklertour.data.calibration.DualPhoneCalibrationProfileResult
import com.maklertour.data.calibration.DualPhoneCalibrationProfileStore
import com.maklertour.data.calibration.DualPhoneLiveIntrinsicsEstimate
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow

data class TofRegisteredRgbAnchor(
    val tofSlot: Int,
    val zoneIndex: Int,
    val tofSequence: Long,
    val tofMappedElapsedRealtimeNs: Long,
    val cameraElapsedRealtimeNs: Long,
    val pairDeltaUs: Long,
    val distanceMm: Int,
    val sigmaMm: Int,
    val targetStatus: Int,
    val nbTargetDetected: Int,
    val tofXmm: Double?,
    val tofYmm: Double?,
    val tofZmm: Double?,
    val cameraXmm: Double?,
    val cameraYmm: Double?,
    val cameraZmm: Double?,
    val uPx: Double?,
    val vPx: Double?,
    val insideImage: Boolean,
    val valid: Boolean,
    val rejectReason: String? = null,
)

data class TofRegisteredSlotSnapshot(
    val tofSlot: Int,
    val profileSolver: String,
    val cameraElapsedRealtimeNs: Long,
    val cameraWidth: Int,
    val cameraHeight: Int,
    val tofSequence: Long? = null,
    val tofMappedElapsedRealtimeNs: Long? = null,
    val pairDeltaUs: Long? = null,
    val pairThresholdUs: Long? = null,
    val pairAccepted: Boolean = false,
    val sensorValidZoneCount: Int = 0,
    val registeredAnchorCount: Int = 0,
    val insideImageCount: Int = 0,
    val status: String,
    val anchors: List<TofRegisteredRgbAnchor> = emptyList(),
)

data class TofRegisteredRgbSnapshot(
    val cameraElapsedRealtimeNs: Long,
    val cameraWidth: Int,
    val cameraHeight: Int,
    val cameraId: String,
    val configuredSlotCount: Int,
    val pairedSlotCount: Int,
    val registeredAnchorCount: Int,
    val insideImageCount: Int,
    val slots: List<TofRegisteredSlotSnapshot>,
    val producedAtElapsedRealtimeNs: Long,
)

/**
 * LM03.5A process-scoped CAMERA_A -> ToF registered-anchor producer.
 *
 * The architecture supports slots 0..2. The current RP2040 firmware still sends
 * only `stream 0`; slots 1/2 remain dormant until their own profiles and frames
 * exist. Every slot always owns an independent ToF intrinsics + R/t profile.
 */
class TofRegisteredRgbAnchorRuntime private constructor(context: Context) {
    private val appContext = context.applicationContext
    private val tofRuntime = TofUsbRuntime.get(appContext)
    private val tofProfileStore = TofCameraCalibrationStore(appContext)
    private val cameraProfileStore = DualPhoneCalibrationProfileStore(appContext)

    private val mutableLatest = MutableStateFlow<TofRegisteredRgbSnapshot?>(null)
    val latest: StateFlow<TofRegisteredRgbSnapshot?> = mutableLatest.asStateFlow()

    private val configurationLock = Any()

    @Volatile
    private var cachedConfigurations: List<SlotConfiguration> = emptyList()

    @Volatile
    private var lastConfigurationRefreshNs: Long = 0L

    private var producedSnapshots: Long = 0L

    fun refreshProfiles() {
        synchronized(configurationLock) {
            lastConfigurationRefreshNs = 0L
            cachedConfigurations = emptyList()
        }
    }

    fun onCameraAFrame(
        cameraElapsedRealtimeNs: Long,
        cameraWidth: Int,
        cameraHeight: Int,
        cameraId: String,
    ): TofRegisteredRgbSnapshot {
        require(cameraElapsedRealtimeNs > 0L)
        require(cameraWidth > 0 && cameraHeight > 0)

        val nowNs = SystemClock.elapsedRealtimeNanos()
        val configurations = configurations(nowNs)
        val history = tofRuntime.recentFramesSnapshot()

        val slotSnapshots = configurations.map { configuration ->
            buildSlotSnapshot(
                configuration = configuration,
                cameraElapsedRealtimeNs = cameraElapsedRealtimeNs,
                cameraWidth = cameraWidth,
                cameraHeight = cameraHeight,
                cameraId = cameraId,
                frames = history,
            )
        }

        val snapshot = TofRegisteredRgbSnapshot(
            cameraElapsedRealtimeNs = cameraElapsedRealtimeNs,
            cameraWidth = cameraWidth,
            cameraHeight = cameraHeight,
            cameraId = cameraId,
            configuredSlotCount = slotSnapshots.size,
            pairedSlotCount = slotSnapshots.count { it.pairAccepted },
            registeredAnchorCount = slotSnapshots.sumOf { it.registeredAnchorCount },
            insideImageCount = slotSnapshots.sumOf { it.insideImageCount },
            slots = slotSnapshots,
            producedAtElapsedRealtimeNs = nowNs,
        )
        mutableLatest.value = snapshot

        producedSnapshots++
        if (producedSnapshots == 1L || producedSnapshots % LOG_EVERY_SNAPSHOTS == 0L) {
            Log.i(
                TAG,
                "TOF_RGB_ANCHORS camNs=$cameraElapsedRealtimeNs " +
                    "camera=${cameraWidth}x$cameraHeight id=$cameraId " +
                    "slots=${snapshot.configuredSlotCount} " +
                    "paired=${snapshot.pairedSlotCount} " +
                    "anchors=${snapshot.registeredAnchorCount} " +
                    "inside=${snapshot.insideImageCount} " +
                    snapshot.slots.joinToString(
                        prefix = "[",
                        postfix = "]",
                        separator = " ",
                    ) {
                        "s${it.tofSlot}:${it.status}:" +
                            "${it.registeredAnchorCount}/${it.sensorValidZoneCount}" +
                            ":dt=${it.pairDeltaUs ?: "-"}us"
                    },
            )
        }

        return snapshot
    }

    private fun configurations(nowNs: Long): List<SlotConfiguration> {
        synchronized(configurationLock) {
            if (
                cachedConfigurations.isNotEmpty() &&
                nowNs - lastConfigurationRefreshNs < PROFILE_REFRESH_INTERVAL_NS
            ) {
                return cachedConfigurations
            }

            cachedConfigurations = tofProfileStore.loadActiveProfiles()
                .sortedBy { it.tofSlot }
                .take(TofCameraCalibrationStore.MAX_TOF_SLOTS)
                .map { tofProfile ->
                    val cameraProfile =
                        cameraProfileStore.load(tofProfile.cameraCalibrationProfileId)
                    SlotConfiguration(
                        tofProfile = tofProfile,
                        cameraProfile = cameraProfile,
                    )
                }
            lastConfigurationRefreshNs = nowNs
            return cachedConfigurations
        }
    }

    private fun buildSlotSnapshot(
        configuration: SlotConfiguration,
        cameraElapsedRealtimeNs: Long,
        cameraWidth: Int,
        cameraHeight: Int,
        cameraId: String,
        frames: List<TofFrameV1>,
    ): TofRegisteredSlotSnapshot {
        val profile = configuration.tofProfile
        val cameraProfile = configuration.cameraProfile
        val compatibilityError = profileCompatibilityError(
            tofProfile = profile,
            cameraProfile = cameraProfile,
            cameraId = cameraId,
            cameraWidth = cameraWidth,
            cameraHeight = cameraHeight,
        )
        if (compatibilityError != null) {
            return TofRegisteredSlotSnapshot(
                tofSlot = profile.tofSlot,
                profileSolver = profile.solver,
                cameraElapsedRealtimeNs = cameraElapsedRealtimeNs,
                cameraWidth = cameraWidth,
                cameraHeight = cameraHeight,
                status = compatibilityError,
            )
        }

        val intrinsics = requireNotNull(cameraProfile).masterIntrinsics
        val pair = TofCameraFramePairer.nearestForSlot(
            cameraElapsedRealtimeNs = cameraElapsedRealtimeNs,
            frames = frames,
            tofSlot = profile.tofSlot,
        ) ?: return TofRegisteredSlotSnapshot(
            tofSlot = profile.tofSlot,
            profileSolver = profile.solver,
            cameraElapsedRealtimeNs = cameraElapsedRealtimeNs,
            cameraWidth = cameraWidth,
            cameraHeight = cameraHeight,
            status = STATUS_NO_PAIR,
        )

        if (!pair.accepted) {
            return TofRegisteredSlotSnapshot(
                tofSlot = profile.tofSlot,
                profileSolver = profile.solver,
                cameraElapsedRealtimeNs = cameraElapsedRealtimeNs,
                cameraWidth = cameraWidth,
                cameraHeight = cameraHeight,
                tofSequence = pair.sequence,
                tofMappedElapsedRealtimeNs = pair.mappedElapsedRealtimeNs,
                pairDeltaUs = pair.signedDeltaUs,
                pairThresholdUs = pair.thresholdUs,
                pairAccepted = false,
                status = STATUS_PAIR_REJECTED,
            )
        }

        val frame = pair.frame
        if (
            frame.slot != profile.tofSlot ||
            frame.width != profile.tofWidth ||
            frame.height != profile.tofHeight
        ) {
            return TofRegisteredSlotSnapshot(
                tofSlot = profile.tofSlot,
                profileSolver = profile.solver,
                cameraElapsedRealtimeNs = cameraElapsedRealtimeNs,
                cameraWidth = cameraWidth,
                cameraHeight = cameraHeight,
                tofSequence = pair.sequence,
                tofMappedElapsedRealtimeNs = pair.mappedElapsedRealtimeNs,
                pairDeltaUs = pair.signedDeltaUs,
                pairThresholdUs = pair.thresholdUs,
                pairAccepted = true,
                status = STATUS_TOF_GEOMETRY_MISMATCH,
            )
        }

        val anchors = (0 until frame.zoneCount).map { zoneIndex ->
            buildAnchor(
                pair = pair,
                zoneIndex = zoneIndex,
                profile = profile,
                cameraIntrinsics = intrinsics,
                cameraElapsedRealtimeNs = cameraElapsedRealtimeNs,
                cameraWidth = cameraWidth,
                cameraHeight = cameraHeight,
            )
        }

        return TofRegisteredSlotSnapshot(
            tofSlot = profile.tofSlot,
            profileSolver = profile.solver,
            cameraElapsedRealtimeNs = cameraElapsedRealtimeNs,
            cameraWidth = cameraWidth,
            cameraHeight = cameraHeight,
            tofSequence = pair.sequence,
            tofMappedElapsedRealtimeNs = pair.mappedElapsedRealtimeNs,
            pairDeltaUs = pair.signedDeltaUs,
            pairThresholdUs = pair.thresholdUs,
            pairAccepted = true,
            sensorValidZoneCount = anchors.count {
                it.rejectReason != REJECT_ZONE_INVALID
            },
            registeredAnchorCount = anchors.count { it.valid },
            insideImageCount = anchors.count { it.valid && it.insideImage },
            status = STATUS_OK,
            anchors = anchors,
        )
    }

    private fun buildAnchor(
        pair: TofCameraFramePair,
        zoneIndex: Int,
        profile: TofCameraExtrinsicsProfile,
        cameraIntrinsics: DualPhoneLiveIntrinsicsEstimate,
        cameraElapsedRealtimeNs: Long,
        cameraWidth: Int,
        cameraHeight: Int,
    ): TofRegisteredRgbAnchor {
        val frame = pair.frame
        val distanceMm = frame.distanceMm[zoneIndex]
        val common = AnchorCommon(
            tofSlot = profile.tofSlot,
            zoneIndex = zoneIndex,
            tofSequence = pair.sequence,
            tofMappedElapsedRealtimeNs = pair.mappedElapsedRealtimeNs,
            cameraElapsedRealtimeNs = cameraElapsedRealtimeNs,
            pairDeltaUs = pair.signedDeltaUs,
            distanceMm = distanceMm,
            sigmaMm = frame.rangeSigmaMm[zoneIndex],
            targetStatus = frame.targetStatus[zoneIndex],
            nbTargetDetected = frame.nbTargetDetected[zoneIndex],
        )

        if (!frame.isZoneValid(zoneIndex)) {
            return common.invalid(REJECT_ZONE_INVALID)
        }

        val projection = TofCameraProjector.projectZoneCenter(
            zoneIndex = zoneIndex,
            distanceMm = distanceMm,
            profile = profile,
            cameraIntrinsics = cameraIntrinsics,
        ) ?: return common.invalid(REJECT_PROJECTION_FAILED)

        val insideImage =
            projection.uPx >= 0.0 &&
                projection.uPx < cameraWidth.toDouble() &&
                projection.vPx >= 0.0 &&
                projection.vPx < cameraHeight.toDouble()

        return TofRegisteredRgbAnchor(
            tofSlot = common.tofSlot,
            zoneIndex = common.zoneIndex,
            tofSequence = common.tofSequence,
            tofMappedElapsedRealtimeNs = common.tofMappedElapsedRealtimeNs,
            cameraElapsedRealtimeNs = common.cameraElapsedRealtimeNs,
            pairDeltaUs = common.pairDeltaUs,
            distanceMm = common.distanceMm,
            sigmaMm = common.sigmaMm,
            targetStatus = common.targetStatus,
            nbTargetDetected = common.nbTargetDetected,
            tofXmm = projection.tofXmm,
            tofYmm = projection.tofYmm,
            tofZmm = projection.tofZmm,
            cameraXmm = projection.cameraXmm,
            cameraYmm = projection.cameraYmm,
            cameraZmm = projection.cameraZmm,
            uPx = projection.uPx,
            vPx = projection.vPx,
            insideImage = insideImage,
            valid = true,
            rejectReason = null,
        )
    }

    private fun profileCompatibilityError(
        tofProfile: TofCameraExtrinsicsProfile,
        cameraProfile: DualPhoneCalibrationProfileResult?,
        cameraId: String,
        cameraWidth: Int,
        cameraHeight: Int,
    ): String? = when {
        tofProfile.tofSlot !in 0 until TofCameraCalibrationStore.MAX_TOF_SLOTS ->
            STATUS_UNSUPPORTED_SLOT
        !tofProfile.solved ->
            STATUS_TOF_PROFILE_UNSOLVED
        cameraProfile == null ->
            STATUS_CAMERA_PROFILE_MISSING
        !cameraProfile.successful ->
            STATUS_CAMERA_PROFILE_REJECTED
        cameraProfile.profileId != tofProfile.cameraCalibrationProfileId ->
            STATUS_CAMERA_PROFILE_MISMATCH
        cameraProfile.rigId != tofProfile.rigId ||
            cameraProfile.rigMountRevision != tofProfile.rigMountRevision ||
            cameraProfile.masterDeviceId != tofProfile.masterDeviceId ->
            STATUS_RIG_MISMATCH
        cameraProfile.masterCameraId != tofProfile.masterCameraId ||
            cameraId != tofProfile.masterCameraId ->
            STATUS_CAMERA_ID_MISMATCH
        cameraProfile.masterIntrinsics.imageWidth != cameraWidth ||
            cameraProfile.masterIntrinsics.imageHeight != cameraHeight ->
            STATUS_CAMERA_SIZE_MISMATCH
        !cameraProfile.masterIntrinsics.acceptable ->
            STATUS_CAMERA_INTRINSICS_REJECTED
        else -> null
    }

    private data class SlotConfiguration(
        val tofProfile: TofCameraExtrinsicsProfile,
        val cameraProfile: DualPhoneCalibrationProfileResult?,
    )

    private data class AnchorCommon(
        val tofSlot: Int,
        val zoneIndex: Int,
        val tofSequence: Long,
        val tofMappedElapsedRealtimeNs: Long,
        val cameraElapsedRealtimeNs: Long,
        val pairDeltaUs: Long,
        val distanceMm: Int,
        val sigmaMm: Int,
        val targetStatus: Int,
        val nbTargetDetected: Int,
    ) {
        fun invalid(reason: String): TofRegisteredRgbAnchor =
            TofRegisteredRgbAnchor(
                tofSlot = tofSlot,
                zoneIndex = zoneIndex,
                tofSequence = tofSequence,
                tofMappedElapsedRealtimeNs = tofMappedElapsedRealtimeNs,
                cameraElapsedRealtimeNs = cameraElapsedRealtimeNs,
                pairDeltaUs = pairDeltaUs,
                distanceMm = distanceMm,
                sigmaMm = sigmaMm,
                targetStatus = targetStatus,
                nbTargetDetected = nbTargetDetected,
                tofXmm = null,
                tofYmm = null,
                tofZmm = null,
                cameraXmm = null,
                cameraYmm = null,
                cameraZmm = null,
                uPx = null,
                vPx = null,
                insideImage = false,
                valid = false,
                rejectReason = reason,
            )
    }

    companion object {
        private const val TAG = "TofRegistration"
        private const val PROFILE_REFRESH_INTERVAL_NS = 2_000_000_000L
        private const val LOG_EVERY_SNAPSHOTS = 30L

        const val STATUS_OK = "OK"
        const val STATUS_NO_PAIR = "NO_TOF_PAIR"
        const val STATUS_PAIR_REJECTED = "PAIR_REJECTED"
        const val STATUS_TOF_GEOMETRY_MISMATCH = "TOF_GEOMETRY_MISMATCH"
        const val STATUS_UNSUPPORTED_SLOT = "UNSUPPORTED_TOF_SLOT"
        const val STATUS_TOF_PROFILE_UNSOLVED = "TOF_PROFILE_UNSOLVED"
        const val STATUS_CAMERA_PROFILE_MISSING = "CAMERA_PROFILE_MISSING"
        const val STATUS_CAMERA_PROFILE_REJECTED = "CAMERA_PROFILE_REJECTED"
        const val STATUS_CAMERA_PROFILE_MISMATCH = "CAMERA_PROFILE_MISMATCH"
        const val STATUS_RIG_MISMATCH = "RIG_MISMATCH"
        const val STATUS_CAMERA_ID_MISMATCH = "CAMERA_ID_MISMATCH"
        const val STATUS_CAMERA_SIZE_MISMATCH = "CAMERA_SIZE_MISMATCH"
        const val STATUS_CAMERA_INTRINSICS_REJECTED = "CAMERA_INTRINSICS_REJECTED"

        const val REJECT_ZONE_INVALID = "ZONE_INVALID"
        const val REJECT_PROJECTION_FAILED = "PROJECTION_FAILED"

        @Volatile
        private var instance: TofRegisteredRgbAnchorRuntime? = null

        fun get(context: Context): TofRegisteredRgbAnchorRuntime =
            instance ?: synchronized(this) {
                instance ?: TofRegisteredRgbAnchorRuntime(
                    context.applicationContext,
                ).also { instance = it }
            }
    }
}
