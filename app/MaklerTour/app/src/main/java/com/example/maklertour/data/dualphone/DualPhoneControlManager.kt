package com.maklertour.data.dualphone

import android.content.Context
import android.os.SystemClock
import com.maklertour.data.calibration.DualPhoneCalibrationProfileResult
import com.maklertour.data.calibration.DualPhoneLiveIntrinsicsEstimate
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.cancel
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch
import kotlinx.coroutines.withTimeoutOrNull
import org.json.JSONObject
import java.io.BufferedReader
import java.io.BufferedWriter
import java.io.Closeable
import java.io.InputStreamReader
import java.io.OutputStreamWriter
import java.net.Inet4Address
import java.net.InetSocketAddress
import java.net.NetworkInterface
import java.net.ServerSocket
import java.net.Socket
import java.util.Collections

enum class DualPhoneControlPhase {
    STOPPED,
    LISTENING,
    CONNECTING,
    CONNECTED,
    ARMING,
    ARMED,
    START_SCHEDULED,
    RECORDING,
    ERROR,
}

data class DualPhoneControlSnapshot(
    val phase: DualPhoneControlPhase = DualPhoneControlPhase.STOPPED,
    val role: DualPhoneRole = DualPhoneRole.STANDALONE,
    val localHost: String? = null,
    val peerHost: String? = null,
    val peerDeviceId: String? = null,
    val peerModel: String? = null,
    val peerCameraId: String? = null,
    val peerVideoModeId: String? = null,
    val pairingCode: String? = null,
    val dualCaptureId: String? = null,
    val connected: Boolean = false,
    val lastMessage: String = "Control channel stopped",
    val lastRxElapsedMs: Long? = null,
    val lastCommand: String? = null,
    val lastError: String? = null,
    val localVideoPath: String? = null,
    val localManifestPath: String? = null,
    val peerVideoPath: String? = null,
    val peerManifestPath: String? = null,
    val localStartLatenessNs: Long? = null,
    val peerStartLatenessNs: Long? = null,
    val localRolePackagePath: String? = null,
    val peerRolePackagePath: String? = null,
    val aggregatePackagePath: String? = null,
    val aggregateUploadState: String = "IDLE",
    val calibrationActive: Boolean = false,
    val calibrationRunId: String? = null,
    val calibrationStage: DualPhoneCalibrationStage =
        DualPhoneCalibrationStage.MASTER_INTRINSICS,
    val calibrationMasterAcceptedPoseCount: Int = 0,
    val calibrationSlaveAcceptedPoseCount: Int = 0,
    val calibrationStereoAcceptedPoseCount: Int = 0,
    val calibrationLastAcceptedStage: DualPhoneCalibrationStage? = null,
    val calibrationLastCompletedStage: DualPhoneCalibrationStage? = null,
    val calibrationInstruction: String =
        DualPhoneCalibrationPosePlan.first.instruction,
    val calibrationAcceptedPoseCount: Int = 0,
    val calibrationTargetPoseCount: Int = 24,
    val calibrationTargetPoseIndex: Int = 0,
    val calibrationTargetPoseId: String = DualPhoneCalibrationPosePlan.first.id,
    val calibrationAcceptanceSerial: Long = 0L,
    val calibrationLastAcceptedPoseIndex: Int? = null,
    val calibrationLastAcceptedPoseId: String? = null,
    val calibrationLastAcceptedLocalFrameSequence: Long? = null,
    val calibrationLastAcceptedPeerFrameSequence: Long? = null,
    val calibrationLocalObservation: DualPhoneCalibrationObservation? = null,
    val calibrationPeerObservation: DualPhoneCalibrationObservation? = null,
    val calibrationLastAcceptedMasterObservation: DualPhoneCalibrationObservation? = null,
    val calibrationLastAcceptedSlaveObservation: DualPhoneCalibrationObservation? = null,
    val calibrationMasterIntrinsics: DualPhoneLiveIntrinsicsEstimate? = null,
    val calibrationSlaveIntrinsics: DualPhoneLiveIntrinsicsEstimate? = null,
    val calibrationFinalResult: DualPhoneCalibrationProfileResult? = null,
    val calibrationCollectionComplete: Boolean = false,
    val clockSync: DualPhoneClockSyncSnapshot = DualPhoneClockSyncSnapshot(),
)

class DualPhoneControlManager private constructor(context: Context) : Closeable {
    private val appContext = context.applicationContext
    private val settingsStore = DualPhoneStereoSettingsStore(appContext)
    private val capabilityProbe = DualPhoneCapabilityProbe(appContext)
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)

    private val mutableState = MutableStateFlow(DualPhoneControlSnapshot())
    val state: StateFlow<DualPhoneControlSnapshot> = mutableState.asStateFlow()
    private val clockSyncController = DualPhoneClockSyncController(
        scope = scope,
        onSnapshot = { clockSync ->
            mutableState.value = mutableState.value.copy(clockSync = clockSync)
            DualPhoneCaptureRuntime.current()?.recordClockSync(clockSync)
        },
        onStatusForSlave = { payload ->
            runCatching {
                send(DualPhoneControlType.CLOCK_SYNC_STATUS, payload)
            }
        },
    )

    private val writeLock = Any()
    private var serverSocket: ServerSocket? = null
    private var socket: Socket? = null
    private var reader: BufferedReader? = null
    private var writer: BufferedWriter? = null
    private var transportJob: Job? = null
    private var heartbeatJob: Job? = null
    private var scheduledStartJob: Job? = null
    private var lastRxElapsedMs = 0L
    private var masterPairingCode: String? = null
    private var masterDualCaptureId: String? = null
    private var localArmResult: DualPhoneCaptureArmResult? = null
    private val bundleCoordinator = DualPhoneBundleCoordinator(appContext, scope)
    private val aggregateTransferLock = Any()
    private var localRolePackage: DualPhoneRolePackage? = null
    private var slaveTransferOffer: DualPhoneTransferOffer? = null
    private var pendingSlaveTransferOffer: DualPhoneTransferOffer? = null
    private var aggregateTransferJob: Job? = null
    private var aggregateTransferCaptureId: String? = null
    private val calibrationObservationLock = Any()
    private var localCalibrationObservation: DualPhoneCalibrationObservation? = null
    private var peerCalibrationObservation: DualPhoneCalibrationObservation? = null
    private var localCalibrationReceivedAtMs: Long = 0L
    private var peerCalibrationReceivedAtMs: Long = 0L

    fun startMaster() {
        val settings = settingsStore.load()
        if (settings.role != DualPhoneRole.MASTER) {
            updateError("Select Master role before starting the server")
            return
        }
        stopTransport("Restarting Master control server")
        transportJob = scope.launch {
            val code = DualPhoneControlProtocol.pairingCode()
            val captureId = DualPhoneControlProtocol.dualCaptureId()
            masterPairingCode = code
            masterDualCaptureId = captureId
            try {
                val server = ServerSocket().apply {
                    reuseAddress = true
                    bind(InetSocketAddress(settings.controlPort))
                }
                serverSocket = server
                mutableState.value = DualPhoneControlSnapshot(
                    phase = DualPhoneControlPhase.LISTENING,
                    role = DualPhoneRole.MASTER,
                    localHost = localIpv4Address(),
                    pairingCode = code,
                    dualCaptureId = captureId,
                    lastMessage = "Waiting for Slave on TCP ${settings.controlPort}",
                )
                while (isActive && !server.isClosed) {
                    val accepted = server.accept()
                    handleMasterClient(accepted, settings, code, captureId)
                }
            } catch (t: Throwable) {
                if (serverSocket?.isClosed != true) {
                    updateError("Master server failed: ${t.message ?: t.javaClass.simpleName}")
                }
            } finally {
                closeConnection()
                closeQuietly(serverSocket)
                serverSocket = null
            }
        }
    }

    fun connectSlave(masterHost: String, pairingCode: String) {
        val settings = settingsStore.load()
        if (settings.role != DualPhoneRole.SLAVE) {
            updateError("Select Slave role before connecting")
            return
        }
        val host = masterHost.trim()
        val code = pairingCode.trim()
        if (host.isBlank() || code.length != 6 || code.any { !it.isDigit() }) {
            updateError("Enter Master IP and six-digit pairing code")
            return
        }
        settingsStore.save(settings.copy(masterHost = host))
        stopTransport("Connecting to Master")
        transportJob = scope.launch {
            mutableState.value = DualPhoneControlSnapshot(
                phase = DualPhoneControlPhase.CONNECTING,
                role = DualPhoneRole.SLAVE,
                peerHost = host,
                lastMessage = "Connecting to $host:${settings.controlPort}",
            )
            try {
                val connectedSocket = Socket()
                connectedSocket.connect(
                    InetSocketAddress(host, settings.controlPort),
                    CONNECT_TIMEOUT_MS,
                )
                installConnection(connectedSocket)
                send(
                    DualPhoneControlType.HELLO,
                    JSONObject()
                        .put("device_id", settings.deviceId)
                        .put("role", DualPhoneRole.SLAVE.name)
                        .put("pairing_code", code)
                        .put(
                            "preferred_video_mode_id",
                            settings.preferredVideoModeId ?: JSONObject.NULL,
                        ),
                )
                val welcome = readRequiredMessage(HANDSHAKE_TIMEOUT_MS)
                if (welcome.optString("type") != DualPhoneControlType.WELCOME) {
                    throw IllegalStateException("Master rejected pairing")
                }
                val payload = welcome.getJSONObject("payload")
                val peerDeviceId = payload.getString("device_id")
                val captureId = payload.getString("dual_capture_id")
                settingsStore.save(
                    settingsStore.load().copy(
                        peerDeviceId = peerDeviceId,
                        masterHost = host,
                    ),
                )
                markRx()
                mutableState.value = mutableState.value.copy(
                    phase = DualPhoneControlPhase.CONNECTED,
                    role = DualPhoneRole.SLAVE,
                    connected = true,
                    peerHost = connectedSocket.inetAddress.hostAddress,
                    peerDeviceId = peerDeviceId,
                    dualCaptureId = captureId,
                    lastMessage = "Paired with Master",
                    lastRxElapsedMs = lastRxElapsedMs,
                )
                clockSyncController.startSlave(
                    port = settings.clockSyncPort,
                    expectedMasterHost = host,
                    dualCaptureId = captureId,
                )
                sendCapabilities()
                startHeartbeat(DualPhoneRole.SLAVE)
                readLoop(DualPhoneRole.SLAVE)
                if (mutableState.value.connected) {
                    mutableState.value = mutableState.value.copy(
                        phase = DualPhoneControlPhase.ERROR,
                        connected = false,
                        lastMessage = "Master disconnected",
                    )
                }
            } catch (t: Throwable) {
                updateError("Slave connection failed: ${t.message ?: t.javaClass.simpleName}")
            } finally {
                closeConnection()
            }
        }
    }

    fun startCalibrationSession() {
        val settings = settingsStore.load()
        val current = mutableState.value
        val baselineMm = settings.operatorLensBaselineMm
        val error = when {
            settings.role != DualPhoneRole.MASTER ->
                "Calibration must be started on Master"
            !current.connected || current.peerDeviceId.isNullOrBlank() ->
                "Connect Slave before calibration"
            current.phase != DualPhoneControlPhase.CONNECTED ->
                "Stop capture before calibration"
            current.calibrationActive ->
                "Calibration session is already active"
            settings.rigId.isBlank() ->
                "Save Rig ID before calibration"
            settings.rigMountRevision.isBlank() ->
                "Save mount revision before calibration"
            baselineMm == null ->
                "Save lens-center distance before calibration"
            else -> null
        }
        if (error != null) {
            mutableState.value = current.copy(
                lastError = error,
                lastMessage = error,
            )
            return
        }

        val runId = DualPhoneControlProtocol.calibrationRunId()
        val stage = DualPhoneCalibrationStage.MASTER_INTRINSICS
        val target = DualPhoneCalibrationPosePlan.first
        val instruction = calibrationInstruction(stage, target)
        resetCalibrationGateLocked()
        mutableState.value = current.copy(
            calibrationActive = true,
            calibrationRunId = runId,
            calibrationStage = stage,
            calibrationMasterAcceptedPoseCount = 0,
            calibrationSlaveAcceptedPoseCount = 0,
            calibrationStereoAcceptedPoseCount = 0,
            calibrationLastAcceptedStage = null,
            calibrationLastCompletedStage = null,
            calibrationInstruction = instruction,
            calibrationAcceptedPoseCount = 0,
            calibrationTargetPoseCount = stage.targetPoseCount,
            calibrationTargetPoseIndex = target.index,
            calibrationTargetPoseId = target.id,
            calibrationAcceptanceSerial = 0L,
            calibrationLastAcceptedPoseIndex = null,
            calibrationLastAcceptedPoseId = null,
            calibrationLastAcceptedLocalFrameSequence = null,
            calibrationLastAcceptedPeerFrameSequence = null,
            calibrationLocalObservation = null,
            calibrationPeerObservation = null,
            calibrationLastAcceptedMasterObservation = null,
            calibrationLastAcceptedSlaveObservation = null,
            calibrationMasterIntrinsics = null,
            calibrationSlaveIntrinsics = null,
            calibrationFinalResult = null,
            calibrationCollectionComplete = false,
            lastCommand = DualPhoneControlType.ENTER_CALIBRATION,
            lastError = null,
            lastMessage = "Stage 1/3: collecting MASTER intrinsics frames",
        )
        scope.launch {
            try {
                send(
                    DualPhoneControlType.ENTER_CALIBRATION,
                    JSONObject()
                        .put("calibration_run_id", runId)
                        .put("rig_id", settings.rigId)
                        .put("rig_mount_revision", settings.rigMountRevision)
                        .put("operator_lens_baseline_mm", baselineMm)
                        .put("stage", stage.wireValue)
                        .put("master_accepted_pose_count", 0)
                        .put("slave_accepted_pose_count", 0)
                        .put("stereo_accepted_pose_count", 0)
                        .put("instruction", instruction)
                        .put("target_pose_index", target.index)
                        .put("target_pose_id", target.id)
                        .put("target_pose_count", stage.targetPoseCount),
                )
            } catch (error: Throwable) {
                leaveCalibrationLocally(
                    "Calibration start failed: ${error.message ?: error.javaClass.simpleName}",
                    error.message ?: error.javaClass.simpleName,
                )
            }
        }
    }

    fun exitCalibrationSession() {
        val current = mutableState.value
        if (!current.calibrationActive) return
        val runId = current.calibrationRunId
        val role = current.role
        scope.launch {
            val payload = JSONObject()
                .put("calibration_run_id", runId ?: JSONObject.NULL)
                .put("reason", "operator_exit")
            val deliveryError = runCatching {
                send(
                    if (role == DualPhoneRole.MASTER) {
                        DualPhoneControlType.EXIT_CALIBRATION
                    } else {
                        DualPhoneControlType.EXIT_CALIBRATION_REQUEST
                    },
                    payload,
                )
            }.exceptionOrNull()
            leaveCalibrationLocally(
                message = if (deliveryError == null) {
                    "Calibration session closed"
                } else {
                    "Calibration closed locally; peer notification failed"
                },
                error = deliveryError?.message,
            )
        }
    }

    fun reportCalibrationObservation(
        observation: DualPhoneCalibrationObservation,
    ) {
        scope.launch {
            val role = settingsStore.load().role
            val statePayload = synchronized(calibrationObservationLock) {
                val current = mutableState.value
                if (
                    !current.calibrationActive ||
                    current.calibrationCollectionComplete ||
                    observation.calibrationRunId != current.calibrationRunId ||
                    observation.calibrationStage != current.calibrationStage ||
                    observation.poseId != current.calibrationTargetPoseId ||
                    !current.calibrationStage.isLocalAnalyzerActive(role)
                ) {
                    return@synchronized null
                }
                localCalibrationObservation = observation
                localCalibrationReceivedAtMs = SystemClock.elapsedRealtime()
                mutableState.value = current.copy(
                    calibrationLocalObservation = observation,
                )
                if (role == DualPhoneRole.MASTER) {
                    evaluateCalibrationGateLocked()
                } else {
                    null
                }
            }

            if (role == DualPhoneRole.SLAVE) {
                runCatching {
                    send(
                        DualPhoneControlType.CALIBRATION_OBSERVATION,
                        observation.toJson(),
                    )
                }
            } else if (role == DualPhoneRole.MASTER && statePayload != null) {
                runCatching {
                    send(
                        DualPhoneControlType.CALIBRATION_STATE,
                        statePayload,
                    )
                }
            }
        }
    }

    fun reportCalibrationIntrinsics(
        calibrationRunId: String,
        stage: DualPhoneCalibrationStage,
        estimate: DualPhoneLiveIntrinsicsEstimate,
    ) {
        val sourceRole = settingsStore.load().role
        val validSource = when (stage) {
            DualPhoneCalibrationStage.MASTER_INTRINSICS ->
                sourceRole == DualPhoneRole.MASTER
            DualPhoneCalibrationStage.SLAVE_INTRINSICS ->
                sourceRole == DualPhoneRole.SLAVE
            else -> false
        }
        val current = mutableState.value
        if (
            !validSource ||
            calibrationRunId != current.calibrationRunId ||
            !current.calibrationActive
        ) {
            return
        }
        val payload = JSONObject()
            .put("calibration_run_id", calibrationRunId)
            .put("source_role", sourceRole.name)
            .put("stage", stage.wireValue)
            .put("estimate", estimate.toJson())
        applyCalibrationIntrinsics(payload)
        scope.launch {
            runCatching {
                send(DualPhoneControlType.CALIBRATION_INTRINSICS, payload)
            }.onFailure { error ->
                mutableState.value = mutableState.value.copy(
                    lastError = "Intrinsics delivery failed: " +
                        (error.message ?: error.javaClass.simpleName),
                )
            }
        }
    }

    fun publishCalibrationResult(result: DualPhoneCalibrationProfileResult) {
        val current = mutableState.value
        if (
            settingsStore.load().role != DualPhoneRole.MASTER ||
            result.calibrationRunId != current.calibrationRunId ||
            !current.calibrationActive
        ) {
            return
        }
        mutableState.value = current.copy(
            calibrationFinalResult = result,
            lastError = result.error,
            lastMessage = if (result.successful) {
                "Calibration profile ${result.profileId} is ready"
            } else {
                "Calibration solve failed: ${result.error ?: "unknown error"}"
            },
        )
        scope.launch {
            runCatching {
                send(
                    DualPhoneControlType.CALIBRATION_RESULT,
                    result.toJson(),
                )
            }.onFailure { error ->
                mutableState.value = mutableState.value.copy(
                    lastError = "Final calibration result delivery failed: " +
                        (error.message ?: error.javaClass.simpleName),
                )
            }
        }
    }

    private fun applyCalibrationIntrinsics(payload: JSONObject) {
        val runId = payload.optString("calibration_run_id")
        val sourceRole = runCatching {
            DualPhoneRole.valueOf(payload.optString("source_role"))
        }.getOrNull() ?: return
        val estimateJson = payload.optJSONObject("estimate") ?: return
        val estimate = DualPhoneLiveIntrinsicsEstimate.fromJson(estimateJson)
        val current = mutableState.value
        if (runId != current.calibrationRunId || !current.calibrationActive) return
        mutableState.value = when (sourceRole) {
            DualPhoneRole.MASTER -> current.copy(
                calibrationMasterIntrinsics = estimate,
            )
            DualPhoneRole.SLAVE -> current.copy(
                calibrationSlaveIntrinsics = estimate,
            )
            DualPhoneRole.STANDALONE -> current
        }
    }

    private fun applyCalibrationResult(payload: JSONObject) {
        val result = DualPhoneCalibrationProfileResult.fromJson(payload) ?: return
        val current = mutableState.value
        if (
            result.calibrationRunId != current.calibrationRunId ||
            !current.calibrationActive
        ) {
            return
        }
        mutableState.value = current.copy(
            calibrationFinalResult = result,
            lastError = result.error,
            lastMessage = if (result.successful) {
                "Calibration profile ${result.profileId} received from Master"
            } else {
                "Calibration solve failed on Master: ${result.error ?: "unknown error"}"
            },
        )
    }

    private fun handleCalibrationObservation(payload: JSONObject) {
        if (settingsStore.load().role != DualPhoneRole.MASTER) return
        val observation = DualPhoneCalibrationObservation.fromJson(payload) ?: return
        val statePayload = synchronized(calibrationObservationLock) {
            val current = mutableState.value
            if (
                !current.calibrationActive ||
                current.calibrationCollectionComplete ||
                !current.calibrationStage.requiresSlaveObservation ||
                observation.calibrationRunId != current.calibrationRunId ||
                observation.calibrationStage != current.calibrationStage ||
                observation.poseId != current.calibrationTargetPoseId
            ) {
                return@synchronized null
            }
            peerCalibrationObservation = observation
            peerCalibrationReceivedAtMs = SystemClock.elapsedRealtime()
            mutableState.value = current.copy(
                calibrationPeerObservation = observation,
            )
            evaluateCalibrationGateLocked()
        }
        if (statePayload != null) {
            send(
                DualPhoneControlType.CALIBRATION_STATE,
                statePayload,
            )
        }
    }

    private fun applyCalibrationState(payload: JSONObject) {
        val runId = payload.optString("calibration_run_id")
        synchronized(calibrationObservationLock) {
            val current = mutableState.value
            if (!current.calibrationActive || runId != current.calibrationRunId) return
            val stage = DualPhoneCalibrationStage.fromWire(
                payload.optString("stage", current.calibrationStage.wireValue),
            )
            val targetCount = payload.optInt(
                "target_pose_count",
                stage.targetPoseCount,
            ).coerceAtLeast(0)
            val acceptedCount = payload.optInt(
                "stage_accepted_pose_count",
                0,
            ).coerceIn(0, targetCount.coerceAtLeast(0))
            val targetIndex = payload.optInt(
                "target_pose_index",
                current.calibrationTargetPoseIndex,
            ).coerceIn(0, DualPhoneCalibrationPosePlan.targets.lastIndex)
            val target = DualPhoneCalibrationPosePlan.at(targetIndex)
            val localRole = settingsStore.load().role
            val masterSequence = payload.optNullableLong("master_frame_sequence")
            val slaveSequence = payload.optNullableLong("slave_frame_sequence")
            val workflowComplete = payload.optBoolean("collection_complete", false)
            val acceptedStage = payload.optNullableString("accepted_stage")?.let {
                DualPhoneCalibrationStage.fromWire(it)
            }
            val completedStage = payload.optNullableString("completed_stage")?.let {
                DualPhoneCalibrationStage.fromWire(it)
            }
            val acceptedMasterObservation =
                payload.optJSONObject("accepted_master_observation")?.let {
                    DualPhoneCalibrationObservation.fromJson(it)
                }
            val acceptedSlaveObservation =
                payload.optJSONObject("accepted_slave_observation")?.let {
                    DualPhoneCalibrationObservation.fromJson(it)
                }
            localCalibrationObservation = null
            peerCalibrationObservation = null
            localCalibrationReceivedAtMs = 0L
            peerCalibrationReceivedAtMs = 0L
            mutableState.value = current.copy(
                calibrationStage = stage,
                calibrationMasterAcceptedPoseCount = payload.optInt(
                    "master_accepted_pose_count",
                    current.calibrationMasterAcceptedPoseCount,
                ),
                calibrationSlaveAcceptedPoseCount = payload.optInt(
                    "slave_accepted_pose_count",
                    current.calibrationSlaveAcceptedPoseCount,
                ),
                calibrationStereoAcceptedPoseCount = payload.optInt(
                    "stereo_accepted_pose_count",
                    current.calibrationStereoAcceptedPoseCount,
                ),
                calibrationLastAcceptedStage = acceptedStage,
                calibrationLastCompletedStage = completedStage,
                calibrationInstruction = payload.optString(
                    "instruction",
                    calibrationInstruction(stage, target),
                ),
                calibrationAcceptedPoseCount = acceptedCount,
                calibrationTargetPoseCount = targetCount,
                calibrationTargetPoseIndex = targetIndex,
                calibrationTargetPoseId = payload.optString(
                    "target_pose_id",
                    target.id,
                ),
                calibrationAcceptanceSerial = payload.optLong(
                    "acceptance_serial",
                    current.calibrationAcceptanceSerial,
                ),
                calibrationLastAcceptedPoseIndex =
                    payload.optNullableInt("accepted_pose_index"),
                calibrationLastAcceptedPoseId =
                    payload.optNullableString("accepted_pose_id"),
                calibrationLastAcceptedLocalFrameSequence =
                    if (localRole == DualPhoneRole.SLAVE) {
                        slaveSequence
                    } else {
                        masterSequence
                    },
                calibrationLastAcceptedPeerFrameSequence =
                    if (localRole == DualPhoneRole.SLAVE) {
                        masterSequence
                    } else {
                        slaveSequence
                    },
                calibrationLocalObservation = null,
                calibrationPeerObservation = null,
                calibrationLastAcceptedMasterObservation =
                    acceptedMasterObservation,
                calibrationLastAcceptedSlaveObservation =
                    acceptedSlaveObservation,
                calibrationCollectionComplete = workflowComplete,
                lastMessage = if (workflowComplete) {
                    "Calibration frame collection completed: MASTER, SLAVE and stereo"
                } else {
                    "Calibration stage ${stage.displayNameRu}: $acceptedCount/$targetCount"
                },
            )
        }
    }

    private fun evaluateCalibrationGateLocked(): JSONObject? {
        val current = mutableState.value
        val stage = current.calibrationStage
        if (stage == DualPhoneCalibrationStage.COMPLETE) return null

        val local = localCalibrationObservation
        val peer = peerCalibrationObservation
        fun observationReady(observation: DualPhoneCalibrationObservation?): Boolean =
            observation != null &&
                observation.qualityReady &&
                observation.calibrationRunId == current.calibrationRunId &&
                observation.calibrationStage == stage &&
                observation.poseId == current.calibrationTargetPoseId

        if (stage.requiresMasterObservation && !observationReady(local)) return null
        if (stage.requiresSlaveObservation && !observationReady(peer)) return null

        val nowMs = SystemClock.elapsedRealtime()
        if (
            stage.requiresMasterObservation &&
            nowMs - localCalibrationReceivedAtMs > CALIBRATION_OBSERVATION_MAX_AGE_MS
        ) return null
        if (
            stage.requiresSlaveObservation &&
            nowMs - peerCalibrationReceivedAtMs > CALIBRATION_OBSERVATION_MAX_AGE_MS
        ) return null
        if (
            stage == DualPhoneCalibrationStage.STEREO_EXTRINSICS &&
            kotlin.math.abs(
                localCalibrationReceivedAtMs - peerCalibrationReceivedAtMs,
            ) > CALIBRATION_OBSERVATION_PAIR_WINDOW_MS
        ) return null

        val acceptedMaster = if (stage.requiresMasterObservation) local else null
        val acceptedSlave = if (stage.requiresSlaveObservation) peer else null
        val acceptedPoseIndex = current.calibrationTargetPoseIndex
        val acceptedPoseId = current.calibrationTargetPoseId
        val acceptedCount = (current.calibrationAcceptedPoseCount + 1)
            .coerceAtMost(stage.targetPoseCount)
        val stageComplete = acceptedCount >= stage.targetPoseCount
        val nextStage = if (stageComplete) stage.next() else stage
        val workflowComplete = nextStage == DualPhoneCalibrationStage.COMPLETE

        val masterCount = if (stage == DualPhoneCalibrationStage.MASTER_INTRINSICS) {
            acceptedCount
        } else {
            current.calibrationMasterAcceptedPoseCount
        }
        val slaveCount = if (stage == DualPhoneCalibrationStage.SLAVE_INTRINSICS) {
            acceptedCount
        } else {
            current.calibrationSlaveAcceptedPoseCount
        }
        val stereoCount = if (stage == DualPhoneCalibrationStage.STEREO_EXTRINSICS) {
            acceptedCount
        } else {
            current.calibrationStereoAcceptedPoseCount
        }
        val nextTargetIndex = if (stageComplete) {
            0
        } else {
            (acceptedPoseIndex + 1).coerceAtMost(
                DualPhoneCalibrationPosePlan.targets.lastIndex,
            )
        }
        val nextTarget = DualPhoneCalibrationPosePlan.at(nextTargetIndex)
        val nextStageAcceptedCount = if (stageComplete) 0 else acceptedCount
        val nextInstruction = if (workflowComplete) {
            "Сбор калибровочных кадров завершён: MASTER, SLAVE и ОБЕ КАМЕРЫ"
        } else {
            calibrationInstruction(nextStage, nextTarget)
        }
        val acceptanceSerial = current.calibrationAcceptanceSerial + 1L
        val masterSequence = acceptedMaster?.frameSequence
        val slaveSequence = acceptedSlave?.frameSequence
        val payload = JSONObject()
            .put("calibration_run_id", current.calibrationRunId)
            .put("stage", nextStage.wireValue)
            .put("accepted_stage", stage.wireValue)
            .put("completed_stage", if (stageComplete) stage.wireValue else JSONObject.NULL)
            .put("stage_accepted_pose_count", nextStageAcceptedCount)
            .put("accepted_stage_pose_count", acceptedCount)
            .put("master_accepted_pose_count", masterCount)
            .put("slave_accepted_pose_count", slaveCount)
            .put("stereo_accepted_pose_count", stereoCount)
            .put("accepted_pose_index", acceptedPoseIndex)
            .put("accepted_pose_id", acceptedPoseId)
            .put("target_pose_index", nextTargetIndex)
            .put("target_pose_id", nextTarget.id)
            .put("target_pose_count", nextStage.targetPoseCount)
            .put("instruction", nextInstruction)
            .put("acceptance_serial", acceptanceSerial)
            .put("master_frame_sequence", masterSequence ?: JSONObject.NULL)
            .put("slave_frame_sequence", slaveSequence ?: JSONObject.NULL)
            .put(
                "accepted_master_observation",
                acceptedMaster?.toJson() ?: JSONObject.NULL,
            )
            .put(
                "accepted_slave_observation",
                acceptedSlave?.toJson() ?: JSONObject.NULL,
            )
            .put("collection_complete", workflowComplete)

        localCalibrationObservation = null
        peerCalibrationObservation = null
        localCalibrationReceivedAtMs = 0L
        peerCalibrationReceivedAtMs = 0L
        mutableState.value = current.copy(
            calibrationStage = nextStage,
            calibrationMasterAcceptedPoseCount = masterCount,
            calibrationSlaveAcceptedPoseCount = slaveCount,
            calibrationStereoAcceptedPoseCount = stereoCount,
            calibrationLastAcceptedStage = stage,
            calibrationLastCompletedStage = if (stageComplete) stage else null,
            calibrationInstruction = nextInstruction,
            calibrationAcceptedPoseCount = nextStageAcceptedCount,
            calibrationTargetPoseCount = nextStage.targetPoseCount,
            calibrationTargetPoseIndex = nextTargetIndex,
            calibrationTargetPoseId = nextTarget.id,
            calibrationAcceptanceSerial = acceptanceSerial,
            calibrationLastAcceptedPoseIndex = acceptedPoseIndex,
            calibrationLastAcceptedPoseId = acceptedPoseId,
            calibrationLastAcceptedLocalFrameSequence = masterSequence,
            calibrationLastAcceptedPeerFrameSequence = slaveSequence,
            calibrationLocalObservation = null,
            calibrationPeerObservation = null,
            calibrationLastAcceptedMasterObservation = acceptedMaster,
            calibrationLastAcceptedSlaveObservation = acceptedSlave,
            calibrationCollectionComplete = workflowComplete,
            lastMessage = when {
                workflowComplete ->
                    "Calibration frame collection completed: MASTER, SLAVE and stereo"
                stageComplete ->
                    "Stage ${stage.displayNameRu} completed; starting ${nextStage.displayNameRu}"
                else ->
                    "Accepted ${stage.displayNameRu} pose $acceptedCount/${stage.targetPoseCount}"
            },
        )
        return payload
    }

    private fun resetCalibrationGateLocked() {
        synchronized(calibrationObservationLock) {
            localCalibrationObservation = null
            peerCalibrationObservation = null
            localCalibrationReceivedAtMs = 0L
            peerCalibrationReceivedAtMs = 0L
        }
    }

    private fun calibrationInstruction(
        stage: DualPhoneCalibrationStage,
        target: DualPhoneCalibrationPoseTarget,
    ): String = when (stage) {
        DualPhoneCalibrationStage.MASTER_INTRINSICS ->
            "MASTER: ${target.instruction}"
        DualPhoneCalibrationStage.SLAVE_INTRINSICS ->
            "SLAVE: ${target.instruction}"
        DualPhoneCalibrationStage.STEREO_EXTRINSICS ->
            "ОБЕ КАМЕРЫ: ${target.instruction}"
        DualPhoneCalibrationStage.COMPLETE ->
            "Сбор калибровочных кадров завершён"
    }

    fun arm() {
        launchMasterCommand(
            command = DualPhoneControlType.ARM,
            allowedPhases = setOf(DualPhoneControlPhase.CONNECTED),
        ) {
            val sync = clockSyncController.currentSnapshot()
            val settings = settingsStore.load()
            val captureId = currentCaptureId()
            val commandId = DualPhoneControlProtocol.commandId("arm")
            slaveTransferOffer?.serverJob?.cancel()
            slaveTransferOffer = null
            synchronized(aggregateTransferLock) {
                aggregateTransferJob?.cancel()
                aggregateTransferJob = null
                aggregateTransferCaptureId = null
                pendingSlaveTransferOffer = null
                localRolePackage = null
            }
            mutableState.value = mutableState.value.copy(
                phase = DualPhoneControlPhase.ARMING,
                lastCommand = DualPhoneControlType.ARM,
                lastError = null,
                lastMessage = "Starting local pre-roll recording",
                localRolePackagePath = null,
                peerRolePackagePath = null,
                aggregatePackagePath = null,
                aggregateUploadState = "IDLE",
            )
            val endpoint = DualPhoneCaptureRuntime.requireEndpoint()
            val local = withTimeoutOrNull(ARM_PREPARE_TIMEOUT_MS) {
                endpoint.arm(
                    DualPhoneCaptureArmRequest(
                        dualCaptureId = captureId,
                        role = DualPhoneRole.MASTER,
                        deviceId = settings.deviceId,
                        peerDeviceId = settings.peerDeviceId,
                        preferredVideoModeId =
                            settings.preferredVideoModeId,
                        commandId = commandId,
                        clockQualityAtArm = sync.quality.name,
                        clockOffsetNsAtArm = sync.offsetNs,
                        clockUncertaintyNsAtArm = sync.uncertaintyNs,
                        clockDriftPpmAtArm = sync.driftPpm,
                        clockAcceptedSamplesAtArm = sync.acceptedSamples,
                        clockTotalSamplesAtArm = sync.totalSamples,
                    ),
                )
            } ?: throw IllegalStateException(
                "Local CameraX ARM preparation timed out after " +
                    "${ARM_PREPARE_TIMEOUT_MS / 1_000L} seconds",
            )
            if (!local.ready) {
                throw IllegalStateException(
                    local.reason ?: "Local recorder rejected ARM",
                )
            }
            localArmResult = local
            try {
                send(
                    DualPhoneControlType.ARM,
                    JSONObject()
                        .put("dual_capture_id", captureId)
                        .put("command_id", commandId)
                        .put(
                            "preferred_video_mode_id",
                            local.videoModeId ?: settings.preferredVideoModeId
                                ?: JSONObject.NULL,
                        )
                        .put(
                            "requested_at_master_ns",
                            SystemClock.elapsedRealtimeNanos(),
                        )
                        .put("clock_quality_at_arm", sync.quality.name)
                        .put(
                            "clock_offset_ns_at_arm",
                            sync.offsetNs ?: JSONObject.NULL,
                        )
                        .put(
                            "clock_uncertainty_ns_at_arm",
                            sync.uncertaintyNs ?: JSONObject.NULL,
                        )
                        .put(
                            "clock_drift_ppm_at_arm",
                            sync.driftPpm ?: JSONObject.NULL,
                        )
                        .put(
                            "clock_accepted_samples_at_arm",
                            sync.acceptedSamples,
                        )
                        .put("clock_total_samples_at_arm", sync.totalSamples),
                )
            } catch (error: Throwable) {
                endpoint.abort("Failed to send ARM to Slave")
                localArmResult = null
                throw error
            }
            mutableState.value = mutableState.value.copy(
                lastCommand = DualPhoneControlType.ARM,
                lastError = null,
                localVideoPath = local.outputPath,
                lastMessage = if (sync.captureSchedulingAllowed) {
                    "Local pre-roll recording active; waiting for Slave ARM_ACK"
                } else {
                    "Local pre-roll recording active with ${sync.quality.name} clock; " +
                        "timeline refinement will be required"
                },
            )
        }
    }

    fun startAfter(delayMs: Long = 3_000L) {
        launchMasterCommand(
            command = DualPhoneControlType.START_AT,
            allowedPhases = setOf(DualPhoneControlPhase.ARMED),
        ) {
            val safeDelay = delayMs.coerceIn(1_000L, 30_000L)
            val commandCreatedMasterNs = SystemClock.elapsedRealtimeNanos()
            val commandId = DualPhoneControlProtocol.commandId("start")
            val sync = clockSyncController.currentSnapshot()
            val requestedStartAt =
                commandCreatedMasterNs + safeDelay * 1_000_000L
            val mappedSlaveStartAt = if (sync.captureSchedulingAllowed) {
                clockSyncController.masterToSlaveNs(requestedStartAt)
            } else {
                null
            }
            val alignmentMode = if (mappedSlaveStartAt != null) {
                DualPhoneStartAlignmentMode.SCHEDULED_CLOCK_MODEL
            } else {
                DualPhoneStartAlignmentMode.DEGRADED_ASYNC_MARKER
            }
            val localStartAt = if (
                alignmentMode == DualPhoneStartAlignmentMode.SCHEDULED_CLOCK_MODEL
            ) {
                requestedStartAt
            } else {
                commandCreatedMasterNs
            }
            val peerDelayMs = if (
                alignmentMode == DualPhoneStartAlignmentMode.SCHEDULED_CLOCK_MODEL
            ) {
                safeDelay
            } else {
                0L
            }
            val localStartRequest = DualPhoneCaptureStartRequest(
                dualCaptureId = currentCaptureId(),
                role = DualPhoneRole.MASTER,
                scheduledElapsedRealtimeNs = localStartAt,
                clockOffsetNs = sync.offsetNs,
                clockUncertaintyNs = sync.uncertaintyNs,
                clockDriftPpm = sync.driftPpm,
                alignmentMode = alignmentMode,
                commandId = commandId,
                commandCreatedMasterElapsedRealtimeNs =
                    commandCreatedMasterNs,
                commandReceivedLocalElapsedRealtimeNs =
                    commandCreatedMasterNs,
            )
            mutableState.value = mutableState.value.copy(
                phase = DualPhoneControlPhase.START_SCHEDULED,
                lastCommand = DualPhoneControlType.START_AT,
                lastError = null,
                lastMessage = if (
                    alignmentMode == DualPhoneStartAlignmentMode.SCHEDULED_CLOCK_MODEL
                ) {
                    "Logical START marker scheduled using ${sync.quality.name} clock model"
                } else {
                    "Logical START marker applied asynchronously; server refinement required"
                },
            )
            scheduleLocalCaptureStart(
                request = localStartRequest,
                notifyPeer = false,
            )
            val deliveryError = runCatching {
                send(
                    DualPhoneControlType.START_AT,
                    JSONObject()
                        .put("dual_capture_id", currentCaptureId())
                        .put("command_id", commandId)
                        .put("command_created_master_ns", commandCreatedMasterNs)
                        .put("master_elapsed_realtime_ns", localStartAt)
                        .put(
                            "slave_elapsed_realtime_ns",
                            mappedSlaveStartAt ?: JSONObject.NULL,
                        )
                        .put("alignment_mode", alignmentMode.name)
                        .put("clock_quality", sync.quality.name)
                        .put(
                            "marker_semantics",
                            if (
                                alignmentMode == DualPhoneStartAlignmentMode.SCHEDULED_CLOCK_MODEL
                            ) "LOGICAL_CAPTURE_WINDOW_START" else
                                "LOGICAL_CAPTURE_WINDOW_START_DEGRADED",
                        )
                        .put(
                            "clock_offset_ns",
                            sync.offsetNs ?: JSONObject.NULL,
                        )
                        .put(
                            "clock_uncertainty_ns",
                            sync.uncertaintyNs ?: JSONObject.NULL,
                        )
                        .put(
                            "clock_drift_ppm",
                            sync.driftPpm ?: JSONObject.NULL,
                        )
                        .put("delay_ms", peerDelayMs),
                )
            }.exceptionOrNull()
            if (deliveryError != null) {
                mutableState.value = mutableState.value.copy(
                    lastError = "Slave START marker delivery failed: " +
                        (deliveryError.message ?: deliveryError.javaClass.simpleName),
                    lastMessage =
                        "Local START marker remains scheduled; Slave delivery failed",
                )
            }
        }
    }

    fun stopCapture() {
        launchMasterCommand(
            command = DualPhoneControlType.STOP,
            allowedPhases = setOf(
                DualPhoneControlPhase.ARMED,
                DualPhoneControlPhase.START_SCHEDULED,
                DualPhoneControlPhase.RECORDING,
            ),
        ) {
            val captureId = currentCaptureId()
            val commandId = DualPhoneControlProtocol.commandId("stop")
            val stopMarkerMasterNs = SystemClock.elapsedRealtimeNanos()
            val localStopRequest = DualPhoneCaptureStopRequest(
                dualCaptureId = captureId,
                role = DualPhoneRole.MASTER,
                commandId = commandId,
                commandCreatedMasterElapsedRealtimeNs = stopMarkerMasterNs,
                commandReceivedLocalElapsedRealtimeNs = stopMarkerMasterNs,
                postRollMs = DUAL_PHONE_DEFAULT_POST_ROLL_MS,
            )
            DualPhoneCaptureRuntime.requireEndpoint().markStop(localStopRequest)
            scheduledStartJob?.cancel()
            scheduledStartJob = null
            mutableState.value = mutableState.value.copy(
                lastCommand = DualPhoneControlType.STOP,
                lastError = null,
                aggregateUploadState = "LOCAL_POST_ROLL_AND_FINALIZE",
                lastMessage =
                    "STOP marker written; finalizing Master independently after post-roll",
            )
            val deliveryError = runCatching {
                send(
                    DualPhoneControlType.STOP,
                    JSONObject()
                        .put("dual_capture_id", captureId)
                        .put("command_id", commandId)
                        .put("command_created_master_ns", stopMarkerMasterNs)
                        .put("marker_semantics", "LOGICAL_CAPTURE_WINDOW_STOP")
                        .put("post_roll_ms", DUAL_PHONE_DEFAULT_POST_ROLL_MS)
                        .put("requested_at_master_ns", stopMarkerMasterNs),
                )
            }.exceptionOrNull()
            val localResult = stopLocalCapture()
            val rolePackage = bundleCoordinator.packageRole(
                stopResult = localResult,
                dualCaptureId = captureId,
                role = DualPhoneRole.MASTER,
            )
            localArmResult = null
            val slaveOfferReady = synchronized(aggregateTransferLock) {
                localRolePackage = rolePackage
                pendingSlaveTransferOffer != null
            }
            mutableState.value = mutableState.value.copy(
                phase = DualPhoneControlPhase.CONNECTED,
                lastCommand = DualPhoneControlType.STOP,
                lastError = deliveryError?.let {
                    "Slave STOP marker delivery failed: " +
                        (it.message ?: it.javaClass.simpleName)
                },
                localVideoPath = localResult.videoPath,
                localManifestPath = localResult.manifestPath,
                localRolePackagePath = rolePackage.file.absolutePath,
                aggregateUploadState = if (slaveOfferReady) {
                    "TRANSFER_BARRIER_READY"
                } else {
                    "WAITING_FOR_SLAVE_PACKAGE"
                },
                lastMessage = if (slaveOfferReady) {
                    "Master and Slave packages are ready; starting transfer"
                } else {
                    "Master finalized independently; waiting for Slave package"
                },
            )
            tryStartAggregateTransfer()
        }
    }

    fun stop() {
        stopTransport("Control channel stopped")
        mutableState.value = DualPhoneControlSnapshot(
            role = settingsStore.load().role,
            lastMessage = "Control channel stopped",
        )
    }

    private suspend fun handleMasterClient(
        accepted: Socket,
        settings: DualPhoneStereoSettings,
        pairingCode: String,
        captureId: String,
    ) {
        closeConnection()
        try {
            installConnection(accepted)
            val hello = readRequiredMessage(HANDSHAKE_TIMEOUT_MS)
            if (hello.optString("type") != DualPhoneControlType.HELLO) {
                throw IllegalStateException("Expected HELLO")
            }
            val payload = hello.getJSONObject("payload")
            if (payload.optString("role") != DualPhoneRole.SLAVE.name) {
                throw IllegalStateException("Peer is not a Slave")
            }
            if (payload.optString("pairing_code") != pairingCode) {
                sendError("PAIRING_CODE_INVALID")
                throw IllegalStateException("Invalid pairing code")
            }
            val peerDeviceId = payload.getString("device_id")
            settingsStore.save(settingsStore.load().copy(peerDeviceId = peerDeviceId))
            send(
                DualPhoneControlType.WELCOME,
                JSONObject()
                    .put("device_id", settings.deviceId)
                    .put("dual_capture_id", captureId)
                    .put("master_elapsed_realtime_ns", SystemClock.elapsedRealtimeNanos())
                    .put(
                        "preferred_video_mode_id",
                        settings.preferredVideoModeId ?: JSONObject.NULL,
                    ),
            )
            markRx()
            mutableState.value = mutableState.value.copy(
                phase = DualPhoneControlPhase.CONNECTED,
                role = DualPhoneRole.MASTER,
                connected = true,
                peerHost = accepted.inetAddress.hostAddress,
                peerDeviceId = peerDeviceId,
                pairingCode = pairingCode,
                dualCaptureId = captureId,
                lastMessage = "Slave paired",
                lastRxElapsedMs = lastRxElapsedMs,
            )
            sendCapabilities()
            clockSyncController.startMaster(
                peerHost = accepted.inetAddress.hostAddress,
                port = settings.clockSyncPort,
                dualCaptureId = captureId,
            )
            startHeartbeat(DualPhoneRole.MASTER)
            readLoop(DualPhoneRole.MASTER)
        } catch (t: Throwable) {
            mutableState.value = mutableState.value.copy(
                phase = DualPhoneControlPhase.LISTENING,
                connected = false,
                peerHost = null,
                peerDeviceId = null,
                peerModel = null,
                peerCameraId = null,
                peerVideoModeId = null,
                lastMessage = "Peer disconnected: ${t.message ?: t.javaClass.simpleName}",
            )
        } finally {
            closeConnection()
            if (serverSocket?.isClosed == false) {
                mutableState.value = mutableState.value.copy(
                    phase = DualPhoneControlPhase.LISTENING,
                    connected = false,
                    lastMessage = "Waiting for Slave reconnect",
                )
            }
        }
    }

    private suspend fun readLoop(localRole: DualPhoneRole) {
        while (scope.isActive && socket?.isClosed == false) {
            val line = reader?.readLine() ?: break
            val message = DualPhoneControlProtocol.decode(line)
            markRx()
            val type = message.getString("type")
            val payload = message.getJSONObject("payload")
            when (type) {
                DualPhoneControlType.PING -> send(
                    DualPhoneControlType.PONG,
                    JSONObject().put(
                        "elapsed_realtime_ns",
                        SystemClock.elapsedRealtimeNanos(),
                    ),
                )
                DualPhoneControlType.PONG -> {
                    mutableState.value = mutableState.value.copy(
                        lastRxElapsedMs = lastRxElapsedMs,
                    )
                }
                DualPhoneControlType.CAPABILITIES -> applyPeerCapabilities(payload)
                DualPhoneControlType.CLOCK_SYNC_STATUS -> {
                    if (localRole == DualPhoneRole.SLAVE) {
                        clockSyncController.applyRemoteStatus(payload)
                    }
                }
                DualPhoneControlType.ENTER_CALIBRATION -> if (
                    localRole == DualPhoneRole.SLAVE
                ) {
                    val runId = payload.optString("calibration_run_id")
                    val accepted = mutableState.value.connected &&
                        mutableState.value.phase == DualPhoneControlPhase.CONNECTED &&
                        runId.isNotBlank()
                    val reason = if (accepted) null else
                        "Slave is not ready for calibration"
                    if (accepted) {
                        val stage = DualPhoneCalibrationStage.fromWire(
                            payload.optString("stage"),
                        )
                        val targetIndex = payload.optInt(
                            "target_pose_index",
                            0,
                        ).coerceIn(0, DualPhoneCalibrationPosePlan.targets.lastIndex)
                        val target = DualPhoneCalibrationPosePlan.at(targetIndex)
                        resetCalibrationGateLocked()
                        mutableState.value = mutableState.value.copy(
                            calibrationActive = true,
                            calibrationRunId = runId,
                            calibrationStage = stage,
                            calibrationMasterAcceptedPoseCount = payload.optInt(
                                "master_accepted_pose_count",
                                0,
                            ),
                            calibrationSlaveAcceptedPoseCount = payload.optInt(
                                "slave_accepted_pose_count",
                                0,
                            ),
                            calibrationStereoAcceptedPoseCount = payload.optInt(
                                "stereo_accepted_pose_count",
                                0,
                            ),
                            calibrationLastAcceptedStage = null,
                            calibrationLastCompletedStage = null,
                            calibrationInstruction = payload.optString(
                                "instruction",
                                calibrationInstruction(stage, target),
                            ),
                            calibrationAcceptedPoseCount = 0,
                            calibrationTargetPoseCount = payload.optInt(
                                "target_pose_count",
                                stage.targetPoseCount,
                            ).coerceAtLeast(0),
                            calibrationTargetPoseIndex = targetIndex,
                            calibrationTargetPoseId = payload.optString(
                                "target_pose_id",
                                target.id,
                            ),
                            calibrationAcceptanceSerial = 0L,
                            calibrationLastAcceptedPoseIndex = null,
                            calibrationLastAcceptedPoseId = null,
                            calibrationLastAcceptedLocalFrameSequence = null,
                            calibrationLastAcceptedPeerFrameSequence = null,
                            calibrationLocalObservation = null,
                            calibrationPeerObservation = null,
                            calibrationLastAcceptedMasterObservation = null,
                            calibrationLastAcceptedSlaveObservation = null,
                            calibrationMasterIntrinsics = null,
                            calibrationSlaveIntrinsics = null,
                            calibrationFinalResult = null,
                            calibrationCollectionComplete = false,
                            lastCommand = DualPhoneControlType.ENTER_CALIBRATION,
                            lastError = null,
                            lastMessage = "Sequential calibration opened by Master",
                        )
                    }
                    send(
                        DualPhoneControlType.ENTER_CALIBRATION_ACK,
                        JSONObject()
                            .put("calibration_run_id", runId)
                            .put("accepted", accepted)
                            .put("reason", reason ?: JSONObject.NULL),
                    )
                }
                DualPhoneControlType.ENTER_CALIBRATION_ACK -> if (
                    localRole == DualPhoneRole.MASTER
                ) {
                    val runId = payload.optString("calibration_run_id")
                    val accepted = payload.optBoolean("accepted", false) &&
                        runId == mutableState.value.calibrationRunId
                    if (accepted) {
                        mutableState.value = mutableState.value.copy(
                            lastCommand = DualPhoneControlType.ENTER_CALIBRATION,
                            lastError = null,
                            lastMessage = "Fullscreen calibration active on both phones",
                        )
                    } else {
                        leaveCalibrationLocally(
                            message = "Slave rejected calibration mode",
                            error = payload.optString(
                                "reason",
                                "Slave rejected calibration mode",
                            ),
                        )
                    }
                }
                DualPhoneControlType.CALIBRATION_OBSERVATION -> if (
                    localRole == DualPhoneRole.MASTER
                ) {
                    handleCalibrationObservation(payload)
                }
                DualPhoneControlType.CALIBRATION_STATE -> if (
                    localRole == DualPhoneRole.SLAVE
                ) {
                    applyCalibrationState(payload)
                }
                DualPhoneControlType.CALIBRATION_INTRINSICS -> {
                    applyCalibrationIntrinsics(payload)
                }
                DualPhoneControlType.CALIBRATION_RESULT -> if (
                    localRole == DualPhoneRole.SLAVE
                ) {
                    applyCalibrationResult(payload)
                }
                DualPhoneControlType.EXIT_CALIBRATION_REQUEST -> if (
                    localRole == DualPhoneRole.MASTER
                ) {
                    val runId = mutableState.value.calibrationRunId
                    runCatching {
                        send(
                            DualPhoneControlType.EXIT_CALIBRATION,
                            JSONObject()
                                .put("calibration_run_id", runId ?: JSONObject.NULL)
                                .put("reason", "slave_requested_exit"),
                        )
                    }
                    leaveCalibrationLocally(
                        "Slave requested calibration exit",
                        null,
                    )
                }
                DualPhoneControlType.EXIT_CALIBRATION -> {
                    val remoteRunId = payload.optNullableString("calibration_run_id")
                    if (
                        remoteRunId == null ||
                        remoteRunId == mutableState.value.calibrationRunId
                    ) {
                        leaveCalibrationLocally(
                            "Calibration session closed by peer",
                            null,
                        )
                    }
                }
                DualPhoneControlType.ARM -> if (localRole == DualPhoneRole.SLAVE) {
                    val settings = settingsStore.load()
                    val result = try {
                        DualPhoneCaptureRuntime.requireEndpoint().arm(
                            DualPhoneCaptureArmRequest(
                                dualCaptureId = payload.getString(
                                    "dual_capture_id",
                                ),
                                role = DualPhoneRole.SLAVE,
                                deviceId = settings.deviceId,
                                peerDeviceId =
                                    settings.peerDeviceId,
                                preferredVideoModeId =
                                    payload.optNullableString(
                                        "preferred_video_mode_id",
                                    ) ?: settings.preferredVideoModeId,
                                commandId = payload.optString(
                                    "command_id",
                                    "legacy-arm",
                                ),
                                clockQualityAtArm = payload.optNullableString("clock_quality_at_arm"),
                                clockOffsetNsAtArm = payload.optNullableLong("clock_offset_ns_at_arm"),
                                clockUncertaintyNsAtArm = payload.optNullableLong("clock_uncertainty_ns_at_arm"),
                                clockDriftPpmAtArm = payload.optNullableDouble("clock_drift_ppm_at_arm"),
                                clockAcceptedSamplesAtArm = payload.optInt("clock_accepted_samples_at_arm", 0),
                                clockTotalSamplesAtArm = payload.optInt("clock_total_samples_at_arm", 0),
                            ),
                        )
                    } catch (error: Throwable) {
                        DualPhoneCaptureArmResult(
                            ready = false,
                            reason = error.message
                                ?: error.javaClass.simpleName,
                        )
                    }
                    mutableState.value = mutableState.value.copy(
                        phase = if (result.ready) {
                            DualPhoneControlPhase.ARMED
                        } else {
                            DualPhoneControlPhase.CONNECTED
                        },
                        localVideoPath = result.outputPath,
                        lastError = result.reason,
                        lastMessage = if (result.ready) {
                            "Slave pre-roll recording active"
                        } else {
                            "Slave ARM rejected: ${result.reason}"
                        },
                    )
                    send(
                        DualPhoneControlType.ARM_ACK,
                        armResultPayload(result),
                    )
                }
                DualPhoneControlType.ARM_ACK -> if (localRole == DualPhoneRole.MASTER) {
                    val ready = payload.optBoolean("ready", false)
                    val local = localArmResult
                    val peerWidth = payload.optNullableInt("width")
                    val peerHeight = payload.optNullableInt("height")
                    val peerFps = payload.optNullableInt("fps")
                    val peerEncodedReady = payload.optBoolean(
                        "valid_encoded_data_observed",
                        false,
                    )
                    val modeMismatch = ready && peerEncodedReady && local != null && (
                        local.width != peerWidth ||
                            local.height != peerHeight
                        )
                    val fpsMismatch =
                        ready && peerEncodedReady && local != null &&
                            local.fps != peerFps
                    if (!ready || !peerEncodedReady || modeMismatch) {
                        val reason = if (modeMismatch) {
                            "VIDEO_MODE_MISMATCH local=" +
                                "${local?.width}x${local?.height}@${local?.fps} " +
                                "peer=${peerWidth}x${peerHeight}@${peerFps}"
                        } else if (ready && !peerEncodedReady) {
                            "SLAVE_NO_VALID_ENCODED_DATA"
                        } else {
                            payload.optString(
                                "reason",
                                "Slave recorder rejected ARM",
                            )
                        }
                        DualPhoneCaptureRuntime.current()?.abort(reason)
                        localArmResult = null
                        mutableState.value = mutableState.value.copy(
                            phase = DualPhoneControlPhase.CONNECTED,
                            lastCommand = DualPhoneControlType.ARM,
                            lastError = reason,
                            lastMessage = "ARM failed: $reason",
                        )
                    } else {
                        mutableState.value = mutableState.value.copy(
                            phase = DualPhoneControlPhase.ARMED,
                            lastCommand = DualPhoneControlType.ARM,
                            lastError = null,
                            peerVideoPath = payload.optNullableString(
                                "output_path",
                            ),
                            peerVideoModeId = payload.optNullableString(
                                "video_mode_id",
                            ) ?: mutableState.value.peerVideoModeId,
                            lastMessage = if (fpsMismatch) {
                                "Both pre-roll recordings are active; " +
                                    "FPS differs local=${local?.fps} peer=$peerFps " +
                                    "and will be aligned by timestamps"
                            } else {
                                "Both pre-roll recordings are active"
                            },
                        )
                    }
                }
                DualPhoneControlType.START_AT -> if (localRole == DualPhoneRole.SLAVE) {
                    handleSlaveStart(payload)
                }
                DualPhoneControlType.START_ACK -> if (localRole == DualPhoneRole.MASTER) {
                    val accepted = payload.optBoolean("accepted", false)
                    val rejectionReason = payload.optString(
                        "reason",
                        "Slave rejected START_AT",
                    )
                    mutableState.value = mutableState.value.copy(
                        phase = if (accepted) {
                            mutableState.value.phase
                        } else {
                            DualPhoneControlPhase.ARMED
                        },
                        lastCommand = DualPhoneControlType.START_AT,
                        lastError = if (accepted) null else rejectionReason,
                        lastMessage = if (accepted) {
                            "Slave accepted logical START marker"
                        } else {
                            "Slave rejected START_AT: $rejectionReason"
                        },
                    )
                }
                DualPhoneControlType.CAPTURE_STARTED -> {
                    if (localRole == DualPhoneRole.MASTER) {
                        val current = mutableState.value
                        val keepStopMessage =
                            current.phase == DualPhoneControlPhase.CONNECTED &&
                                current.lastCommand == DualPhoneControlType.STOP
                        mutableState.value = current.copy(
                            peerStartLatenessNs = payload.optLong(
                                "start_lateness_ns",
                            ),
                            peerVideoPath = payload.optNullableString(
                                "video_path",
                            ),
                            lastMessage = if (keepStopMessage) {
                                current.lastMessage
                            } else {
                                "Slave logical START marker applied"
                            },
                        )
                    }
                }
                DualPhoneControlType.STOP -> if (localRole == DualPhoneRole.SLAVE) {
                    scheduledStartJob?.cancel()
                    scheduledStartJob = null
                    val receivedAtNs = SystemClock.elapsedRealtimeNanos()
                    val captureId = payload.getString("dual_capture_id")
                    val commandId = payload.optString(
                        "command_id",
                        "legacy-stop",
                    )
                    val stopRequest = DualPhoneCaptureStopRequest(
                        dualCaptureId = captureId,
                        role = DualPhoneRole.SLAVE,
                        commandId = commandId,
                        commandCreatedMasterElapsedRealtimeNs =
                            payload.optNullableLong(
                                "command_created_master_ns",
                            ),
                        commandReceivedLocalElapsedRealtimeNs = receivedAtNs,
                        postRollMs = payload.optLong(
                            "post_roll_ms",
                            DUAL_PHONE_DEFAULT_POST_ROLL_MS,
                        ),
                    )
                    DualPhoneCaptureRuntime.requireEndpoint().markStop(stopRequest)
                    mutableState.value = mutableState.value.copy(
                        lastMessage =
                            "STOP marker received; finalizing independently after post-roll",
                    )
                    scope.launch {
                        try {
                            val result = stopLocalCapture()
                            val rolePackage = bundleCoordinator.packageRole(
                                stopResult = result,
                                dualCaptureId = captureId,
                                role = DualPhoneRole.SLAVE,
                            )
                            localRolePackage = rolePackage
                            slaveTransferOffer?.serverJob?.cancel()
                            val offer = bundleCoordinator.serveOnce(
                                rolePackage = rolePackage,
                                port = settingsStore.load().bundleTransferPort,
                            )
                            slaveTransferOffer = offer
                            send(
                                DualPhoneControlType.STOP_ACK,
                                offer.toJson(
                                    stopResultPayload(result)
                                        .put("dual_capture_id", captureId)
                                        .put("role", DualPhoneRole.SLAVE.name),
                                ),
                            )
                            mutableState.value =
                                mutableState.value.copy(
                                    phase =
                                        DualPhoneControlPhase.CONNECTED,
                                    localVideoPath =
                                        result.videoPath,
                                    localManifestPath =
                                        result.manifestPath,
                                    localRolePackagePath =
                                        rolePackage.file.absolutePath,
                                    aggregateUploadState =
                                        "WAITING_FOR_MASTER_PULL",
                                    lastError = null,
                                    lastMessage =
                                        "Slave package ready for automatic Master transfer",
                                )
                        } catch (error: Throwable) {
                            val failure = error.message
                                ?: error.javaClass.simpleName
                            DualPhoneCaptureRuntime.current()?.abort(
                                "STOP failed: $failure",
                            )
                            runCatching {
                                send(
                                    DualPhoneControlType.STOP_ACK,
                                    JSONObject()
                                        .put("dual_capture_id", captureId)
                                        .put("role", DualPhoneRole.SLAVE.name)
                                        .put("captured", false)
                                        .put("finalize_error", failure),
                                )
                            }
                            mutableState.value =
                                mutableState.value.copy(
                                    phase =
                                        DualPhoneControlPhase.CONNECTED,
                                    aggregateUploadState =
                                        "LOCAL_FINALIZE_FAILED",
                                    lastError = failure,
                                    lastMessage =
                                        "Slave finalize failed independently",
                                )
                        }
                    }
                }
                DualPhoneControlType.STOP_ACK -> if (localRole == DualPhoneRole.MASTER) {
                    scheduledStartJob?.cancel()
                    scheduledStartJob = null
                    val captured = payload.optBoolean("captured", false)
                    val finalizeError = payload.optString(
                        "finalize_error",
                        "Slave capture was not finalized",
                    )
                    mutableState.value = mutableState.value.copy(
                        phase = DualPhoneControlPhase.CONNECTED,
                        lastCommand = DualPhoneControlType.STOP,
                        lastError = if (captured) null else finalizeError,
                        peerVideoPath = payload.optNullableString(
                            "video_path",
                        ),
                        peerManifestPath = payload.optNullableString(
                            "manifest_path",
                        ),
                        aggregateUploadState = if (captured) {
                            "SLAVE_PACKAGE_OFFER_RECEIVED"
                        } else {
                            "SLAVE_FINALIZE_FAILED"
                        },
                        lastMessage = if (captured) {
                            "Slave package offer received"
                        } else {
                            "Master finalized; Slave finalize failed independently"
                        },
                    )
                    if (captured) {
                        handleMasterRolePackageOffer(payload)
                    }
                }
                DualPhoneControlType.PACKAGE_RECEIVED -> if (
                    localRole == DualPhoneRole.SLAVE
                ) {
                    val receivedHash = payload.optString("sha256").lowercase()
                    val localHash = localRolePackage?.sha256
                    mutableState.value = mutableState.value.copy(
                        aggregateUploadState = if (receivedHash == localHash) {
                            "TRANSFERRED_TO_MASTER"
                        } else {
                            "MASTER_RECEIPT_HASH_MISMATCH"
                        },
                        lastError = if (receivedHash == localHash) {
                            null
                        } else {
                            "Master package receipt SHA-256 mismatch"
                        },
                        lastMessage = if (receivedHash == localHash) {
                            "Slave package transferred and verified by Master"
                        } else {
                            "Master receipt did not match Slave package"
                        },
                    )
                }
                DualPhoneControlType.ERROR -> {
                    val code = payload.optString(
                        "code",
                        "Peer returned ERROR",
                    )
                    mutableState.value = mutableState.value.copy(
                        lastError = "Peer error: $code",
                        lastMessage = "Peer error: $code",
                    )
                }
            }
        }
    }

    private fun handleMasterRolePackageOffer(payload: JSONObject) {
        val offer = DualPhoneTransferOffer.fromJson(payload)
        if (offer == null) {
            mutableState.value = mutableState.value.copy(
                aggregateUploadState = "SLAVE_PACKAGE_OFFER_MISSING",
                lastError = "Slave finalized recording but did not provide a DP04.3 role package",
                lastMessage = "Both recordings finalized; Slave package offer is missing",
            )
            return
        }
        val expectedCaptureId = runCatching { currentCaptureId() }.getOrNull()
        if (expectedCaptureId != null && offer.dualCaptureId != expectedCaptureId) {
            mutableState.value = mutableState.value.copy(
                aggregateUploadState = "SLAVE_PACKAGE_CAPTURE_MISMATCH",
                lastError = "Slave package capture ID does not match active capture",
                lastMessage = "Rejected stale Slave package offer",
            )
            return
        }
        val masterReady = synchronized(aggregateTransferLock) {
            pendingSlaveTransferOffer = offer
            localRolePackage != null
        }
        mutableState.value = mutableState.value.copy(
            aggregateUploadState = if (masterReady) {
                "TRANSFER_BARRIER_READY"
            } else {
                "WAITING_FOR_MASTER_PACKAGE"
            },
            lastError = null,
            lastMessage = if (masterReady) {
                "Master and Slave packages are ready; starting transfer"
            } else {
                "Slave package ready; waiting for Master package"
            },
        )
        tryStartAggregateTransfer()
    }

    private fun tryStartAggregateTransfer() {
        synchronized(aggregateTransferLock) {
            val offer = pendingSlaveTransferOffer ?: return
            val masterPackage = localRolePackage ?: return
            if (masterPackage.dualCaptureId != offer.dualCaptureId) {
                pendingSlaveTransferOffer = null
                mutableState.value = mutableState.value.copy(
                    aggregateUploadState = "ROLE_PACKAGE_CAPTURE_MISMATCH",
                    lastError = "Master and Slave role packages have different capture IDs",
                    lastMessage = "Aggregate transfer barrier rejected mismatched packages",
                )
                return
            }
            val activeJob = aggregateTransferJob
            if (activeJob?.isActive == true) {
                if (aggregateTransferCaptureId == offer.dualCaptureId) return
                activeJob.cancel()
            }
            val peerHost = mutableState.value.peerHost
            if (peerHost.isNullOrBlank()) {
                mutableState.value = mutableState.value.copy(
                    aggregateUploadState = "PEER_HOST_MISSING",
                    lastError = "Slave host is unavailable for package transfer",
                    lastMessage = "Cannot start Slave package download",
                )
                return
            }
            aggregateTransferCaptureId = offer.dualCaptureId
            mutableState.value = mutableState.value.copy(
                aggregateUploadState = "DOWNLOADING_SLAVE_PACKAGE",
                lastError = null,
                lastMessage = "Downloading Slave role package",
            )
            aggregateTransferJob = scope.launch {
                runAggregateTransfer(
                    masterPackage = masterPackage,
                    offer = offer,
                    peerHost = peerHost,
                )
            }
        }
    }

    private suspend fun runAggregateTransfer(
        masterPackage: DualPhoneRolePackage,
        offer: DualPhoneTransferOffer,
        peerHost: String,
    ) {
        var completed = false
        try {
            val slavePackage = bundleCoordinator.download(
                peerHost = peerHost,
                offer = offer,
            )
            runCatching {
                send(
                    DualPhoneControlType.PACKAGE_RECEIVED,
                    JSONObject()
                        .put("dual_capture_id", offer.dualCaptureId)
                        .put("role", offer.role.name)
                        .put("sha256", slavePackage.sha256)
                        .put("size_bytes", slavePackage.sizeBytes),
                )
            }
            mutableState.value = mutableState.value.copy(
                peerRolePackagePath = slavePackage.file.absolutePath,
                aggregateUploadState = "BUILDING_AGGREGATE_BUNDLE",
                lastMessage = "Slave package verified; building aggregate bundle",
            )
            val aggregate = bundleCoordinator.packageAggregate(
                masterPackage = masterPackage,
                slavePackage = slavePackage,
            )
            mutableState.value = mutableState.value.copy(
                aggregatePackagePath = aggregate.absolutePath,
                aggregateUploadState = "ENQUEUEING_SERVER_UPLOAD",
                lastMessage = "Aggregate bundle ready; enqueueing server upload",
            )
            val upload = DualPhoneAggregateUploadRuntime.enqueue(
                bundleFile = aggregate,
                dualCaptureId = offer.dualCaptureId,
            )
            mutableState.value = mutableState.value.copy(
                aggregateUploadState = if (upload.queued) {
                    "QUEUED_FOR_SERVER"
                } else {
                    "READY_NOT_QUEUED"
                },
                lastError = if (upload.queued) null else upload.message,
                lastMessage = upload.message,
            )
            completed = true
        } catch (error: Throwable) {
            if (error is kotlinx.coroutines.CancellationException) throw error
            mutableState.value = mutableState.value.copy(
                aggregateUploadState = "TRANSFER_OR_PACKAGING_FAILED",
                lastError = error.message ?: error.javaClass.simpleName,
                lastMessage = "Automatic dual-phone bundle transfer failed",
            )
        } finally {
            synchronized(aggregateTransferLock) {
                if (aggregateTransferCaptureId == offer.dualCaptureId) {
                    aggregateTransferJob = null
                    aggregateTransferCaptureId = null
                    if (completed) pendingSlaveTransferOffer = null
                }
            }
        }
    }

    private fun handleSlaveStart(payload: JSONObject) {
        val receivedAtNs = SystemClock.elapsedRealtimeNanos()
        val alignmentMode = runCatching {
            DualPhoneStartAlignmentMode.valueOf(
                payload.optString(
                    "alignment_mode",
                    DualPhoneStartAlignmentMode.SCHEDULED_CLOCK_MODEL.name,
                ),
            )
        }.getOrDefault(DualPhoneStartAlignmentMode.DEGRADED_ASYNC_MARKER)
        val targetSlaveNs = payload.optLong(
            "slave_elapsed_realtime_ns",
            0L,
        )
        val fallbackDelayMs = payload.optLong(
            "delay_ms",
            3_000L,
        ).coerceIn(0L, 30_000L)
        val effectiveTargetNs = if (
            alignmentMode == DualPhoneStartAlignmentMode.DEGRADED_ASYNC_MARKER
        ) {
            receivedAtNs
        } else if (targetSlaveNs > 0L) {
            targetSlaveNs
        } else {
            receivedAtNs + fallbackDelayMs * 1_000_000L
        }
        val remainingNs = effectiveTargetNs - receivedAtNs
        if (mutableState.value.phase != DualPhoneControlPhase.ARMED) {
            send(
                DualPhoneControlType.START_ACK,
                JSONObject()
                    .put("accepted", false)
                    .put("reason", "SLAVE_NOT_ARMED"),
            )
            return
        }
        if (remainingNs < -MAX_START_LATE_NS) {
            val lateByNs = -remainingNs
            mutableState.value = mutableState.value.copy(
                phase = DualPhoneControlPhase.ARMED,
                lastError = "START_AT arrived too late by ${lateByNs / 1_000_000L} ms",
                lastMessage = "START_AT rejected",
            )
            send(
                DualPhoneControlType.START_ACK,
                JSONObject()
                    .put("accepted", false)
                    .put("reason", "START_AT_LATE")
                    .put("late_by_ns", lateByNs)
                    .put("local_received_ns", receivedAtNs),
            )
            return
        }

        val scheduleDelayMs = (
            (remainingNs.coerceAtLeast(0L) + 999_999L) /
                1_000_000L
        ).coerceIn(0L, 30_000L)
        mutableState.value = mutableState.value.copy(
            phase = DualPhoneControlPhase.START_SCHEDULED,
            lastError = null,
            lastMessage = if (
                alignmentMode == DualPhoneStartAlignmentMode.SCHEDULED_CLOCK_MODEL
            ) {
                "START_AT received; local delay ${scheduleDelayMs} ms"
            } else {
                "Degraded asynchronous START marker received"
            },
        )
        send(
            DualPhoneControlType.START_ACK,
            JSONObject()
                .put("accepted", true)
                .put("local_received_ns", receivedAtNs)
                .put("scheduled_slave_ns", effectiveTargetNs)
                .put("scheduled_delay_ms", scheduleDelayMs)
                .put("alignment_mode", alignmentMode.name),
        )
        scheduleLocalCaptureStart(
            request = DualPhoneCaptureStartRequest(
                dualCaptureId = payload.getString(
                    "dual_capture_id",
                ),
                role = DualPhoneRole.SLAVE,
                scheduledElapsedRealtimeNs = effectiveTargetNs,
                clockOffsetNs =
                    payload.optNullableLong("clock_offset_ns"),
                clockUncertaintyNs =
                    payload.optNullableLong(
                        "clock_uncertainty_ns",
                    ),
                clockDriftPpm =
                    payload.optNullableDouble("clock_drift_ppm"),
                alignmentMode = alignmentMode,
                commandId = payload.optString(
                    "command_id",
                    "legacy-start",
                ),
                commandCreatedMasterElapsedRealtimeNs =
                    payload.optNullableLong("command_created_master_ns"),
                commandReceivedLocalElapsedRealtimeNs = receivedAtNs,
            ),
            notifyPeer = true,
        )
    }

    private fun scheduleLocalCaptureStart(
        request: DualPhoneCaptureStartRequest,
        notifyPeer: Boolean,
    ) {
        scheduledStartJob?.cancel()
        scheduledStartJob = scope.launch {
            try {
                delayUntilElapsedRealtimeNs(
                    request.scheduledElapsedRealtimeNs,
                )
                val started =
                    DualPhoneCaptureRuntime.requireEndpoint().start(
                        request,
                    )
                mutableState.value = mutableState.value.copy(
                    phase = DualPhoneControlPhase.RECORDING,
                    localVideoPath = started.videoPath,
                    localStartLatenessNs = started.startLatenessNs,
                    lastError = null,
                    lastMessage =
                        "Local CameraX recording started; lateness " +
                            "${started.startLatenessNs / 1_000_000.0} ms",
                )
                if (notifyPeer) {
                    send(
                        DualPhoneControlType.CAPTURE_STARTED,
                        startResultPayload(started),
                    )
                }
            } catch (t: Throwable) {
                val message =
                    t.message ?: t.javaClass.simpleName
                DualPhoneCaptureRuntime.current()?.abort(
                    "START failed: $message",
                )
                sendError("LOCAL_RECORDING_START_FAILED")
                mutableState.value = mutableState.value.copy(
                    phase = DualPhoneControlPhase.ERROR,
                    lastError = message,
                    lastMessage =
                        "Local recording start failed: $message",
                )
            }
        }
    }

    private suspend fun delayUntilElapsedRealtimeNs(
        targetNs: Long,
    ) {
        while (true) {
            val remainingNs =
                targetNs - SystemClock.elapsedRealtimeNanos()
            if (remainingNs <= 0L) return
            if (remainingNs > 2_000_000L) {
                val sleepMs = (
                    (remainingNs - 1_000_000L) /
                        1_000_000L
                ).coerceAtLeast(1L)
                delay(sleepMs)
            } else {
                Thread.yield()
            }
        }
    }

    private suspend fun stopLocalCapture():
        DualPhoneCaptureStopResult =
        DualPhoneCaptureRuntime.requireEndpoint().stop()

    private fun armResultPayload(
        result: DualPhoneCaptureArmResult,
    ): JSONObject = JSONObject()
        .put("ready", result.ready)
        .putNullable("reason", result.reason)
        .putNullable("output_path", result.outputPath)
        .put("available_bytes", result.availableBytes)
        .putNullable("camera_id", result.cameraId)
        .putNullable("video_mode_id", result.videoModeId)
        .putNullable("width", result.width)
        .putNullable("height", result.height)
        .putNullable("fps", result.fps)
        .putNullable("requested_video_mode_id", result.requestedVideoModeId)
        .putNullable("mode_fallback_reason", result.modeFallbackReason)
        .put("physical_recording_started", result.physicalRecordingStarted)
        .put("valid_encoded_data_observed", result.validEncodedDataObserved)
        .put("pre_roll_bytes_at_ready", result.preRollBytesAtReady)
        .put(
            "pre_roll_duration_ns_at_ready",
            result.preRollDurationNsAtReady,
        )

    private fun startResultPayload(
        result: DualPhoneCaptureStartResult,
    ): JSONObject = JSONObject()
        .put("video_path", result.videoPath)
        .put(
            "scheduled_elapsed_ns",
            result.scheduledElapsedRealtimeNs,
        )
        .put(
            "start_call_elapsed_ns",
            result.startCallElapsedRealtimeNs,
        )
        .putNullable(
            "camerax_start_elapsed_ns",
            result.cameraXStartElapsedRealtimeNs,
        )
        .put("start_lateness_ns", result.startLatenessNs)

    private fun stopResultPayload(
        result: DualPhoneCaptureStopResult,
    ): JSONObject = JSONObject()
        .put("captured", result.captured)
        .putNullable("video_path", result.videoPath)
        .put("manifest_path", result.manifestPath)
        .put("duration_ns", result.durationNs)
        .put("file_size_bytes", result.fileSizeBytes)
        .putNullable(
            "scheduled_elapsed_ns",
            result.scheduledElapsedRealtimeNs,
        )
        .putNullable(
            "start_call_elapsed_ns",
            result.startCallElapsedRealtimeNs,
        )
        .putNullable(
            "camerax_start_elapsed_ns",
            result.cameraXStartElapsedRealtimeNs,
        )
        .putNullable(
            "finalize_elapsed_ns",
            result.finalizeElapsedRealtimeNs,
        )

    private fun JSONObject.putNullable(
        key: String,
        value: Any?,
    ): JSONObject = put(key, value ?: JSONObject.NULL)

    private fun JSONObject.optNullableString(
        key: String,
    ): String? = if (!has(key) || isNull(key)) {
        null
    } else {
        optString(key).takeIf { it.isNotBlank() }
    }

    private fun JSONObject.optNullableInt(
        key: String,
    ): Int? = if (!has(key) || isNull(key)) {
        null
    } else {
        optInt(key)
    }

    private fun JSONObject.optNullableLong(
        key: String,
    ): Long? = if (!has(key) || isNull(key)) {
        null
    } else {
        optLong(key)
    }

    private fun JSONObject.optNullableDouble(
        key: String,
    ): Double? = if (!has(key) || isNull(key)) {
        null
    } else {
        optDouble(key)
    }

    private fun leaveCalibrationLocally(
        message: String,
        error: String?,
    ) {
        resetCalibrationGateLocked()
        mutableState.value = mutableState.value.copy(
            calibrationActive = false,
            calibrationRunId = null,
            calibrationStage = DualPhoneCalibrationStage.MASTER_INTRINSICS,
            calibrationMasterAcceptedPoseCount = 0,
            calibrationSlaveAcceptedPoseCount = 0,
            calibrationStereoAcceptedPoseCount = 0,
            calibrationLastAcceptedStage = null,
            calibrationLastCompletedStage = null,
            calibrationInstruction = DualPhoneCalibrationPosePlan.first.instruction,
            calibrationAcceptedPoseCount = 0,
            calibrationTargetPoseCount =
                DualPhoneCalibrationStage.MASTER_INTRINSICS.targetPoseCount,
            calibrationTargetPoseIndex = 0,
            calibrationTargetPoseId = DualPhoneCalibrationPosePlan.first.id,
            calibrationAcceptanceSerial = 0L,
            calibrationLastAcceptedPoseIndex = null,
            calibrationLastAcceptedPoseId = null,
            calibrationLastAcceptedLocalFrameSequence = null,
            calibrationLastAcceptedPeerFrameSequence = null,
            calibrationLocalObservation = null,
            calibrationPeerObservation = null,
            calibrationCollectionComplete = false,
            lastCommand = DualPhoneControlType.EXIT_CALIBRATION,
            lastError = error,
            lastMessage = message,
        )
    }

    private fun sendCapabilities() {

        val settings = settingsStore.load()
        val report = capabilityProbe.buildReport(settings)
        val preferredModeId = report
            .optString("preferred_video_mode_id")
            .takeIf {
                it.isNotBlank() && it != "null"
            }
        send(
            DualPhoneControlType.CAPABILITIES,
            JSONObject()
                .put("device_id", settings.deviceId)
                .put(
                    "preferred_video_mode_id",
                    preferredModeId ?: JSONObject.NULL,
                )
                .put("report", report),
        )
    }

    private fun applyPeerCapabilities(payload: JSONObject) {
        val report = payload.optJSONObject("report") ?: JSONObject()
        val manufacturer = report.optString("manufacturer")
        val model = report.optString("model")
        val modelLabel = listOf(manufacturer, model)
            .filter { it.isNotBlank() }
            .joinToString(" ")
            .ifBlank { null }
        val cameraId = report.optString("selected_camera_id")
            .takeIf { it.isNotBlank() && it != "null" }
        val mode = payload.optString("preferred_video_mode_id")
            .takeIf { it.isNotBlank() && it != "null" }
        mutableState.value = mutableState.value.copy(
            peerModel = modelLabel,
            peerCameraId = cameraId,
            peerVideoModeId = mode,
            lastMessage = "Peer capabilities received",
        )
    }

    private fun startHeartbeat(localRole: DualPhoneRole) {
        heartbeatJob?.cancel()
        heartbeatJob = scope.launch {
            while (isActive && socket?.isClosed == false) {
                delay(HEARTBEAT_INTERVAL_MS)
                val age = SystemClock.elapsedRealtime() - lastRxElapsedMs
                if (lastRxElapsedMs > 0 && age > HEARTBEAT_TIMEOUT_MS) {
                    closeQuietly(socket)
                    break
                }
                send(
                    DualPhoneControlType.PING,
                    JSONObject()
                        .put("role", localRole.name)
                        .put(
                            "elapsed_realtime_ns",
                            SystemClock.elapsedRealtimeNanos(),
                        ),
                )
            }
        }
    }

    private fun installConnection(value: Socket) {
        value.tcpNoDelay = true
        value.keepAlive = true
        socket = value
        reader = BufferedReader(InputStreamReader(value.getInputStream()))
        writer = BufferedWriter(OutputStreamWriter(value.getOutputStream()))
    }

    private fun readRequiredMessage(timeoutMs: Int): JSONObject {
        val currentSocket = socket ?: error("Socket is not connected")
        currentSocket.soTimeout = timeoutMs
        return try {
            val line = reader?.readLine()
                ?: throw IllegalStateException("Peer closed during handshake")
            DualPhoneControlProtocol.decode(line)
        } finally {
            currentSocket.soTimeout = 0
        }
    }

    private fun send(type: String, payload: JSONObject = JSONObject()) {
        val line = DualPhoneControlProtocol.encode(
            DualPhoneControlProtocol.message(type, payload),
        )
        synchronized(writeLock) {
            val output = writer ?: throw IllegalStateException("Peer is not connected")
            output.write(line)
            output.newLine()
            output.flush()
        }
    }

    private fun sendError(code: String) {
        runCatching {
            send(
                DualPhoneControlType.ERROR,
                JSONObject().put("code", code),
            )
        }
    }

    private fun launchMasterCommand(
        command: String,
        allowedPhases: Set<DualPhoneControlPhase>,
        block: suspend () -> Unit,
    ) {
        scope.launch {
            requireMasterConnection(
                command = command,
                allowedPhases = allowedPhases,
                block = block,
            )
        }
    }

    private suspend fun requireMasterConnection(
        command: String,
        allowedPhases: Set<DualPhoneControlPhase>,
        block: suspend () -> Unit,
    ) {
        if (settingsStore.load().role != DualPhoneRole.MASTER ||
            !mutableState.value.connected
        ) {
            reportCommandError(
                command,
                "Master is not connected to a Slave",
            )
            return
        }
        if (mutableState.value.phase !in allowedPhases) {
            reportCommandError(
                command,
                "$command is not allowed in ${mutableState.value.phase.name}",
            )
            return
        }
        try {
            block()
        } catch (error: Throwable) {
            reportCommandError(
                command,
                "Control command failed: " +
                    (error.message ?: error.javaClass.simpleName),
            )
        }
    }

    private fun reportCommandError(command: String, message: String) {
        val current = mutableState.value
        mutableState.value = current.copy(
            phase = if (current.phase == DualPhoneControlPhase.ARMING) {
                DualPhoneControlPhase.CONNECTED
            } else {
                current.phase
            },
            lastCommand = command,
            lastError = message,
            lastMessage = message,
        )
    }

    private fun currentCaptureId(): String =
        mutableState.value.dualCaptureId
            ?: masterDualCaptureId
            ?: throw IllegalStateException("dual_capture_id is missing")

    private fun markRx() {
        lastRxElapsedMs = SystemClock.elapsedRealtime()
    }

    private fun updateError(message: String) {
        mutableState.value = mutableState.value.copy(
            phase = DualPhoneControlPhase.ERROR,
            connected = false,
            lastMessage = message,
            lastError = message,
        )
    }

    private fun stopTransport(message: String) {
        transportJob?.cancel()
        transportJob = null
        heartbeatJob?.cancel()
        heartbeatJob = null
        scheduledStartJob?.cancel()
        scheduledStartJob = null
        closeConnection()
        closeQuietly(serverSocket)
        serverSocket = null
        masterPairingCode = null
        masterDualCaptureId = null
        localArmResult = null
        slaveTransferOffer?.serverJob?.cancel()
        slaveTransferOffer = null
        synchronized(aggregateTransferLock) {
            aggregateTransferJob?.cancel()
            aggregateTransferJob = null
            aggregateTransferCaptureId = null
            pendingSlaveTransferOffer = null
            localRolePackage = null
        }
        mutableState.value = mutableState.value.copy(
            phase = DualPhoneControlPhase.STOPPED,
            connected = false,
            lastMessage = message,
        )
    }

    private fun closeConnection() {
        clockSyncController.stop()
        heartbeatJob?.cancel()
        heartbeatJob = null
        scheduledStartJob?.cancel()
        scheduledStartJob = null
        closeQuietly(reader)
        closeQuietly(writer)
        closeQuietly(socket)
        reader = null
        writer = null
        socket = null
    }

    override fun close() {
        stopTransport("Control runtime closed")
        scope.cancel()
    }

    companion object {
        private const val CONNECT_TIMEOUT_MS = 5_000
        private const val HANDSHAKE_TIMEOUT_MS = 10_000
        private const val HEARTBEAT_INTERVAL_MS = 2_000L
        private const val HEARTBEAT_TIMEOUT_MS = 8_000L
        private const val MAX_START_LATE_NS = 100_000_000L
        private const val ARM_PREPARE_TIMEOUT_MS = 60_000L
        private const val CALIBRATION_TARGET_POSE_COUNT = 24
        private const val CALIBRATION_OBSERVATION_MAX_AGE_MS = 1_500L
        private const val CALIBRATION_OBSERVATION_PAIR_WINDOW_MS = 1_200L

        @Volatile
        private var instance: DualPhoneControlManager? = null

        fun get(context: Context): DualPhoneControlManager =
            instance ?: synchronized(this) {
                instance ?: DualPhoneControlManager(context).also {
                    instance = it
                }
            }

        private fun localIpv4Address(): String? =
            runCatching {
                Collections.list(NetworkInterface.getNetworkInterfaces())
                    .asSequence()
                    .filter { it.isUp && !it.isLoopback }
                    .flatMap { Collections.list(it.inetAddresses).asSequence() }
                    .filterIsInstance<Inet4Address>()
                    .firstOrNull { !it.isLoopbackAddress && !it.isLinkLocalAddress }
                    ?.hostAddress
            }.getOrNull()

        private fun closeQuietly(value: Closeable?) {
            runCatching { value?.close() }
        }
    }
}
