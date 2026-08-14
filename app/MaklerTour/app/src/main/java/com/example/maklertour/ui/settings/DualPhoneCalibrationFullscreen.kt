package com.maklertour.ui.settings

import android.app.Activity
import android.content.Context
import android.content.ContextWrapper
import android.content.pm.ActivityInfo
import android.view.View
import android.view.ViewGroup
import androidx.camera.view.PreviewView
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.safeDrawingPadding
import androidx.compose.foundation.layout.size
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.rememberUpdatedState
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.unit.dp
import androidx.compose.ui.viewinterop.AndroidView
import androidx.compose.ui.window.Dialog
import androidx.compose.ui.window.DialogProperties
import androidx.lifecycle.compose.LocalLifecycleOwner
import com.maklertour.data.calibration.DualPhoneCalibrationCaptureStore
import com.maklertour.data.calibration.DualPhoneCalibrationProfileResult
import com.maklertour.data.calibration.DualPhoneCalibrationProfileStore
import com.maklertour.data.calibration.DualPhoneCalibrationRealtimeAnalyzer
import com.maklertour.data.calibration.DualPhoneCalibrationRealtimeResult
import com.maklertour.data.calibration.DualPhoneLiveIntrinsicsEstimate
import com.maklertour.data.calibration.DualPhoneLiveIntrinsicsEstimator
import com.maklertour.data.calibration.DualPhoneStereoCoachEstimator
import com.maklertour.data.calibration.DualPhoneStereoCoachSnapshot
import com.maklertour.data.calibration.DualPhoneStereoEstimate
import com.maklertour.data.dualphone.DualPhoneCalibrationMode
import com.maklertour.data.dualphone.DualPhoneCalibrationObservation
import com.maklertour.data.dualphone.DualPhoneCalibrationPosePlan
import com.maklertour.data.dualphone.DualPhoneCalibrationStage
import com.maklertour.data.dualphone.DualPhoneControlManager
import com.maklertour.data.dualphone.DualPhoneControlSnapshot
import com.maklertour.data.dualphone.DualPhoneRole
import com.maklertour.data.dualphone.DualPhoneStereoSettingsStore
import com.maklertour.data.phonecamera.CalibrationFrame
import com.maklertour.data.phonecamera.DualPhonePreviewBindingRuntime
import com.maklertour.data.phonecamera.DualPhoneRecorderPreviewRegistry
import com.maklertour.data.rig.CalibrationSettings
import com.maklertour.data.rig.StereoRigProfileStore
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.delay
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import java.util.Locale
import kotlin.math.abs
import kotlin.math.min

@Composable
internal fun DualPhoneCalibrationFullscreen(
    snapshot: DualPhoneControlSnapshot,
    role: DualPhoneRole,
    onExit: () -> Unit,
) {
    val context = LocalContext.current
    val focusScope = rememberCoroutineScope()
    val activity = remember(context) { context.findActivity() }
    val controlManager = remember(context) {
        DualPhoneControlManager.get(context.applicationContext)
    }
    val captureStore = remember(context) {
        DualPhoneCalibrationCaptureStore(context.applicationContext)
    }
    val stereoSettingsStore = remember(context) {
        DualPhoneStereoSettingsStore(context.applicationContext)
    }
    val profileStore = remember(context) {
        DualPhoneCalibrationProfileStore(context.applicationContext)
    }
    val rigProfileStore = remember(context) {
        StereoRigProfileStore(context.applicationContext)
    }
    val localStereoSettings = remember(snapshot.calibrationRunId) {
        stereoSettingsStore.load()
    }
    val rigProfile = remember(snapshot.calibrationRunId) {
        rigProfileStore.loadActiveProfile()
    }
    val localDeviceId = localStereoSettings.deviceId
    val boardSettings = remember(localStereoSettings.calibrationBoard) {
        localStereoSettings.calibrationBoard.toCalibrationSettings(
            DualPhoneCalibrationStage.STEREO_EXTRINSICS.targetPoseCount,
        )
    }
    val analyzer = remember(snapshot.calibrationRunId, snapshot.calibrationStage) {
        DualPhoneCalibrationRealtimeAnalyzer()
    }
    val masterIntrinsicsEstimator = remember(snapshot.calibrationRunId) {
        DualPhoneLiveIntrinsicsEstimator()
    }
    val slaveIntrinsicsEstimator = remember(snapshot.calibrationRunId) {
        DualPhoneLiveIntrinsicsEstimator()
    }
    val stereoEstimator = remember(snapshot.calibrationRunId) {
        DualPhoneStereoCoachEstimator()
    }
    DisposableEffect(
        masterIntrinsicsEstimator,
        slaveIntrinsicsEstimator,
        stereoEstimator,
    ) {
        onDispose {
            masterIntrinsicsEstimator.close()
            slaveIntrinsicsEstimator.close()
            stereoEstimator.close()
        }
    }
    val target = DualPhoneCalibrationPosePlan.byId(snapshot.calibrationTargetPoseId)
    val currentTarget by rememberUpdatedState(target)
    val currentSnapshot by rememberUpdatedState(snapshot)
    var tofAlignmentArmed by remember(snapshot.calibrationRunId) {
        mutableStateOf(false)
    }
    var calibrationOverlayDetailed by remember(snapshot.calibrationRunId) {
        mutableStateOf(false)
    }
    val tofAlignmentRequired =
        role == DualPhoneRole.MASTER &&
            snapshot.calibrationStage ==
                DualPhoneCalibrationStage.MASTER_TOF_EXTRINSICS &&
            !snapshot.calibrationCollectionComplete
    val localAnalyzerActive =
        snapshot.calibrationStage.isLocalAnalyzerActive(role) &&
            !snapshot.calibrationCollectionComplete &&
            (!tofAlignmentRequired || tofAlignmentArmed)
    var previewStatus by remember(snapshot.calibrationRunId) {
        mutableStateOf("Opening selected camera…")
    }
    var manualFocusBusy by remember(snapshot.calibrationRunId) {
        mutableStateOf(false)
    }
    var localAnalysis by remember(snapshot.calibrationRunId) {
        mutableStateOf<DualPhoneCalibrationRealtimeResult?>(null)
    }
    var persistenceStatus by remember(snapshot.calibrationRunId) {
        mutableStateOf("Waiting for the first accepted pose")
    }
    var lastPersistedAcceptanceSerial by remember(snapshot.calibrationRunId) {
        mutableStateOf(0L)
    }
    var acceptanceFeedback by remember(snapshot.calibrationRunId) {
        mutableStateOf<String?>(null)
    }
    var masterLiveIntrinsics by remember(snapshot.calibrationRunId) {
        mutableStateOf<DualPhoneLiveIntrinsicsEstimate?>(null)
    }
    var slaveLiveIntrinsics by remember(snapshot.calibrationRunId) {
        mutableStateOf<DualPhoneLiveIntrinsicsEstimate?>(null)
    }
    var stereoCoach by remember(snapshot.calibrationRunId) {
        mutableStateOf(DualPhoneStereoCoachSnapshot())
    }
    var finalSolveStarted by remember(snapshot.calibrationRunId) {
        mutableStateOf(false)
    }
    var finalSolveStatus by remember(snapshot.calibrationRunId) {
        mutableStateOf("Ожидание завершения сбора кадров")
    }
    var finalProfilePersistenceStatus by remember(snapshot.calibrationRunId) {
        mutableStateOf("")
    }
    val displayedLiveIntrinsics = when (snapshot.calibrationStage) {
        DualPhoneCalibrationStage.MASTER_INTRINSICS -> masterLiveIntrinsics
        DualPhoneCalibrationStage.SLAVE_INTRINSICS -> slaveLiveIntrinsics
        else -> null
    }

    LaunchedEffect(snapshot.calibrationAcceptanceSerial) {
        val serial = snapshot.calibrationAcceptanceSerial
        val acceptedStage = snapshot.calibrationLastAcceptedStage
        if (serial <= 0L || acceptedStage == null) return@LaunchedEffect
        val acceptedCount = acceptedStageCount(acceptedStage, snapshot)
        if (
            acceptedStage == DualPhoneCalibrationStage.STEREO_EXTRINSICS &&
            role == DualPhoneRole.MASTER
        ) {
            val masterObservation =
                snapshot.calibrationLastAcceptedMasterObservation
            val slaveObservation =
                snapshot.calibrationLastAcceptedSlaveObservation
            if (masterObservation != null && slaveObservation != null) {
                val frameDeltaMs = stereoFrameDeltaMs(
                    masterObservation,
                    slaveObservation,
                    snapshot.clockSync.offsetNs,
                )
                val updatedCoach = withContext(Dispatchers.Default) {
                    stereoEstimator.addAcceptedPair(
                        master = masterObservation,
                        slave = slaveObservation,
                        settings = boardSettings,
                        frameDeltaMs = frameDeltaMs,
                    )
                    val masterModel = currentSnapshot.calibrationMasterIntrinsics
                    val slaveModel = currentSnapshot.calibrationSlaveIntrinsics
                    if (masterModel != null && slaveModel != null) {
                        stereoEstimator.snapshot(
                            master = masterModel,
                            slave = slaveModel,
                            operatorBaselineMm =
                                localStereoSettings.operatorLensBaselineMm,
                        )
                    } else {
                        null
                    }
                }
                if (updatedCoach != null) stereoCoach = updatedCoach

                if (
                    acceptedCount >=
                    DualPhoneCalibrationStage.STEREO_EXTRINSICS.targetPoseCount
                ) {
                    val masterModel = currentSnapshot.calibrationMasterIntrinsics
                    val slaveModel = currentSnapshot.calibrationSlaveIntrinsics
                    if (masterModel != null && slaveModel != null) {
                        finalSolveStatus = "ПРОВЕРКА STEREO RMS/EPI ПЕРЕД TOF…"
                        val stereoResult = withContext(Dispatchers.Default) {
                            stereoEstimator.solve(
                                master = masterModel,
                                slave = slaveModel,
                                operatorBaselineMm =
                                    localStereoSettings.operatorLensBaselineMm,
                            )
                        }
                        val preflight = DualPhoneCalibrationProfileResult.build(
                            calibrationRunId =
                                requireNotNull(currentSnapshot.calibrationRunId),
                            rigId = localStereoSettings.rigId,
                            rigMountRevision =
                                localStereoSettings.rigMountRevision,
                            masterDeviceId = localStereoSettings.deviceId,
                            slaveDeviceId =
                                currentSnapshot.peerDeviceId
                                    ?: localStereoSettings.peerDeviceId
                                    ?: "unknown-slave",
                            masterCameraId = rigProfile.cam0CameraId,
                            slaveCameraId =
                                currentSnapshot.peerCameraId
                                    ?: rigProfile.cam1CameraId,
                            masterIntrinsics = masterModel,
                            slaveIntrinsics = slaveModel,
                            stereo = stereoResult,
                        )
                        controlManager.reportStereoQualityGate(preflight)
                    }
                }
            }
        }
        acceptanceFeedback = buildString {
            append("КАДР ЗАСЧИТАН ✓  ")
            append(acceptedStage.displayNameRu)
            append("  ")
            append(acceptedCount)
            append("/")
            append(acceptedStage.targetPoseCount)
            if (snapshot.calibrationLastCompletedStage == acceptedStage) {
                append("\nЭТАП ")
                append(acceptedStage.displayNameRu)
                append(" ЗАВЕРШЁН ✓")
            }
        }
        delay(900L)
        if (snapshot.calibrationAcceptanceSerial == serial) {
            acceptanceFeedback = null
        }
    }

    DisposableEffect(activity) {
        val previousOrientation = activity?.requestedOrientation
        val decorView = activity?.window?.decorView
        val previousSystemUi = decorView?.systemUiVisibility
        activity?.requestedOrientation = ActivityInfo.SCREEN_ORIENTATION_LOCKED
        decorView?.systemUiVisibility =
            View.SYSTEM_UI_FLAG_FULLSCREEN or
                View.SYSTEM_UI_FLAG_HIDE_NAVIGATION or
                View.SYSTEM_UI_FLAG_IMMERSIVE_STICKY or
                View.SYSTEM_UI_FLAG_LAYOUT_FULLSCREEN or
                View.SYSTEM_UI_FLAG_LAYOUT_HIDE_NAVIGATION or
                View.SYSTEM_UI_FLAG_LAYOUT_STABLE
        onDispose {
            if (previousOrientation != null) {
                activity?.requestedOrientation = previousOrientation
            }
            if (previousSystemUi != null) {
                decorView?.systemUiVisibility = previousSystemUi
            }
        }
    }

    LaunchedEffect(
        snapshot.calibrationRunId,
        snapshot.calibrationStage,
        snapshot.calibrationActive,
        localAnalyzerActive,
    ) {
        analyzer.reset()
        localAnalysis = null
        if (!localAnalyzerActive) return@LaunchedEffect
        var lastSequence = -1L
        while (isActive && currentSnapshot.calibrationActive && localAnalyzerActive) {
            val frame = DualPhonePreviewBindingRuntime.latestCalibrationFrame()
            if (frame != null && frame.sequence != lastSequence) {
                lastSequence = frame.sequence
                val activeTarget = currentTarget
                val activeSnapshot = currentSnapshot
                val result = withContext(Dispatchers.Default) {
                    analyzer.analyze(
                        frame = frame,
                        target = activeTarget,
                        settings = boardSettings,
                    )
                }
                localAnalysis = result
                activeSnapshot.calibrationRunId?.let { runId ->
                    controlManager.reportCalibrationObservation(
                        result.toObservation(
                            calibrationRunId = runId,
                            poseId = activeTarget.id,
                            stage = activeSnapshot.calibrationStage,
                        ),
                    )
                }
            }
            delay(55L)
        }
    }

    LaunchedEffect(
        snapshot.calibrationAcceptanceSerial,
        snapshot.calibrationLastAcceptedLocalFrameSequence,
    ) {
        val serial = snapshot.calibrationAcceptanceSerial
        val sequence = snapshot.calibrationLastAcceptedLocalFrameSequence
        val runId = snapshot.calibrationRunId
        val acceptedStage = snapshot.calibrationLastAcceptedStage
        val poseId = snapshot.calibrationLastAcceptedPoseId
        val poseIndex = snapshot.calibrationLastAcceptedPoseIndex
        if (
            serial <= lastPersistedAcceptanceSerial ||
            sequence == null ||
            runId.isNullOrBlank() ||
            acceptedStage == null ||
            poseId.isNullOrBlank() ||
            poseIndex == null
        ) {
            return@LaunchedEffect
        }

        var acceptedFrame: CalibrationFrame? = null
        repeat(20) {
            if (acceptedFrame == null) {
                acceptedFrame = DualPhonePreviewBindingRuntime.calibrationFrame(sequence)
                if (acceptedFrame == null) delay(50L)
            }
        }
        val frame = acceptedFrame
        if (frame == null) {
            persistenceStatus = "Accepted frame $sequence was not found in the local ring buffer"
            return@LaunchedEffect
        }

        val qualityObservation: DualPhoneCalibrationObservation? = localAnalysis
            ?.takeIf { it.frameSequence == sequence }
            ?.toObservation(runId, poseId, stage = acceptedStage)
        runCatching {
            val file = withContext(Dispatchers.IO) {
                captureStore.saveAcceptedFrame(
                    calibrationRunId = runId,
                    deviceId = localDeviceId,
                    stage = acceptedStage,
                    acceptanceSerial = serial,
                    poseIndex = poseIndex,
                    poseId = poseId,
                    frame = frame,
                    observation = qualityObservation,
                )
            }
            val estimate = when (acceptedStage) {
                DualPhoneCalibrationStage.MASTER_INTRINSICS ->
                    withContext(Dispatchers.Default) {
                        masterIntrinsicsEstimator.addAcceptedFrame(frame.bitmap, boardSettings)
                    }
                DualPhoneCalibrationStage.SLAVE_INTRINSICS ->
                    withContext(Dispatchers.Default) {
                        slaveIntrinsicsEstimator.addAcceptedFrame(frame.bitmap, boardSettings)
                    }
                else -> null
            }
            file to estimate
        }.onSuccess { (file, estimate) ->
            lastPersistedAcceptanceSerial = serial
            when (acceptedStage) {
                DualPhoneCalibrationStage.MASTER_INTRINSICS ->
                    masterLiveIntrinsics = estimate
                DualPhoneCalibrationStage.SLAVE_INTRINSICS ->
                    slaveLiveIntrinsics = estimate
                else -> Unit
            }
            estimate?.let {
                controlManager.reportCalibrationIntrinsics(
                    calibrationRunId = runId,
                    stage = acceptedStage,
                    estimate = it,
                )
            }
            persistenceStatus =
                "Сохранён кадр ${acceptedStage.displayNameRu}: ${file.name}"
        }.onFailure { error ->
            persistenceStatus = "Failed to save accepted sample: " +
                (error.message ?: error.javaClass.simpleName)
        }
    }

    LaunchedEffect(
        snapshot.calibrationCollectionComplete,
        snapshot.calibrationRunId,
        snapshot.calibrationMasterIntrinsics,
        snapshot.calibrationSlaveIntrinsics,
        snapshot.calibrationFinalResult,
        role,
    ) {
        if (
            role != DualPhoneRole.MASTER ||
            !snapshot.calibrationCollectionComplete ||
            snapshot.calibrationFinalResult != null ||
            finalSolveStarted
        ) {
            return@LaunchedEffect
        }
        finalSolveStarted = true

        val sourceProfile = snapshot.calibrationSourceProfile
        if (sourceProfile != null) {
            finalSolveStatus =
                "STEREO ${sourceProfile.profileId} сохранён; активируем новый ToF profile…"
            controlManager.publishCalibrationResult(sourceProfile)
            return@LaunchedEffect
        }

        finalSolveStatus = "РАСЧЁТ K/D И STEREO R/T…"

        var masterIntrinsics = currentSnapshot.calibrationMasterIntrinsics
        var slaveIntrinsics = currentSnapshot.calibrationSlaveIntrinsics
        repeat(50) {
            if (masterIntrinsics != null && slaveIntrinsics != null) {
                return@repeat
            }
            delay(100L)
            masterIntrinsics = currentSnapshot.calibrationMasterIntrinsics
            slaveIntrinsics = currentSnapshot.calibrationSlaveIntrinsics
        }
        val masterResult = masterIntrinsics
        val slaveResult = slaveIntrinsics
        if (masterResult == null || slaveResult == null) {
            finalSolveStatus =
                "Ожидание итоговых intrinsics MASTER/SLAVE — расчёт ещё не завершён"
            finalSolveStarted = false
            return@LaunchedEffect
        }

        val expectedStereoPairs =
            currentSnapshot.calibrationStereoAcceptedPoseCount
        repeat(50) {
            if (
                stereoEstimator.pairCount >= expectedStereoPairs ||
                stereoEstimator.pairCount >=
                DualPhoneStereoCoachEstimator.MIN_PAIRS_FOR_FINAL_SOLVE
            ) {
                return@repeat
            }
            delay(100L)
        }

        val stereoResult = withContext(Dispatchers.Default) {
            stereoEstimator.solve(
                master = masterResult,
                slave = slaveResult,
                operatorBaselineMm =
                    localStereoSettings.operatorLensBaselineMm,
            )
        }
        val finalResult = DualPhoneCalibrationProfileResult.build(
            calibrationRunId = requireNotNull(snapshot.calibrationRunId),
            rigId = localStereoSettings.rigId,
            rigMountRevision = localStereoSettings.rigMountRevision,
            masterDeviceId = localStereoSettings.deviceId,
            slaveDeviceId = snapshot.peerDeviceId
                ?: localStereoSettings.peerDeviceId
                ?: "unknown-slave",
            masterCameraId = rigProfile.cam0CameraId,
            slaveCameraId = snapshot.peerCameraId
                ?: rigProfile.cam1CameraId,
            masterIntrinsics = masterResult,
            slaveIntrinsics = slaveResult,
            stereo = stereoResult,
        )
        finalSolveStatus = if (finalResult.successful) {
            "Численная калибровка завершена"
        } else {
            finalResult.error ?: "Численная калибровка завершилась ошибкой"
        }
        controlManager.publishCalibrationResult(finalResult)
    }

    LaunchedEffect(snapshot.calibrationFinalResult?.profileId) {
        val result = snapshot.calibrationFinalResult ?: return@LaunchedEffect
        runCatching {
            withContext(Dispatchers.IO) {
                profileStore.save(result)
            }
        }.onSuccess { file ->
            finalProfilePersistenceStatus = if (result.successful) {
                val marginalEpipolar = result.stereo.meanEpipolarErrorPx?.let {
                    it > DualPhoneStereoEstimate.RECOMMENDED_MEAN_EPIPOLAR_ERROR_PX
                } == true
                val marginalBaseline = result.stereo.baselineDeltaMm?.let { delta ->
                    val expected = result.stereo.operatorBaselineMm ?: 0.0
                    abs(delta) > maxOf(15.0, expected * 0.12)
                } == true
                if (marginalEpipolar || marginalBaseline) {
                    "ПРОФИЛЬ СОХРАНЁН И АКТИВИРОВАН С ПРЕДУПРЕЖДЕНИЕМ ✓  ${file.name}"
                } else {
                    "ПРОФИЛЬ СОХРАНЁН И АКТИВИРОВАН ✓  ${file.name}"
                }
            } else {
                "Диагностика сохранена: ${file.name}"
            }
        }.onFailure { error ->
            finalProfilePersistenceStatus =
                "Ошибка сохранения профиля: " +
                    (error.message ?: error.javaClass.simpleName)
        }
    }

    Dialog(
        onDismissRequest = {},
        properties = DialogProperties(
            dismissOnBackPress = false,
            dismissOnClickOutside = false,
            usePlatformDefaultWidth = false,
        ),
    ) {
        Box(
            modifier = Modifier
                .fillMaxSize()
                .background(Color.Black),
        ) {
            CalibrationPreview(
                analysis = localAnalysis,
                modifier = Modifier.fillMaxSize(),
                onStatus = { previewStatus = it },
            )

            if (tofAlignmentRequired) {
                TofCalibrationAlignmentOverlay(
                    armed = tofAlignmentArmed,
                    detailed = calibrationOverlayDetailed,
                    modifier = Modifier.fillMaxSize(),
                )
            }

            Box(
                modifier = Modifier
                    .fillMaxSize()
                    .safeDrawingPadding()
                    .padding(18.dp)
                    .border(2.dp, Color(0xAAFFFFFF)),
            )

            Card(
                modifier = Modifier
                    .align(Alignment.TopStart)
                    .safeDrawingPadding()
                    .padding(20.dp)
                    .fillMaxWidth(0.66f),
                colors = CardDefaults.cardColors(
                    containerColor = Color(0x24111111),
                    contentColor = Color.White,
                ),
            ) {
                Column(
                    modifier = Modifier.padding(16.dp),
                    verticalArrangement = Arrangement.spacedBy(5.dp),
                ) {
                    Row(
                        horizontalArrangement = Arrangement.spacedBy(8.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Text(
                            "КАЛИБРОВКА · ${role.name} · ${snapshot.calibrationMode.displayNameRu}",
                            style = MaterialTheme.typography.titleMedium,
                        )
                        Button(
                            onClick = {
                                calibrationOverlayDetailed = !calibrationOverlayDetailed
                            },
                        ) {
                            Text(
                                if (calibrationOverlayDetailed) {
                                    "КОМПАКТНО"
                                } else {
                                    "ПОДРОБНО"
                                },
                            )
                        }
                    }
                    CalibrationStageProgress(snapshot)
                    if (snapshot.calibrationCollectionComplete) {
                        Text(
                            "СБОР КАЛИБРОВОЧНЫХ КАДРОВ ЗАВЕРШЁН",
                            color = Color(0xFF7CFC98),
                            style = MaterialTheme.typography.headlineSmall,
                        )
                    } else {
                        Text(
                            "Текущий этап: ${snapshot.calibrationStage.displayNameRu}",
                            style = MaterialTheme.typography.headlineSmall,
                        )
                        Text(
                            stageRoleInstruction(snapshot.calibrationStage, role),
                            color = Color(0xFFFFCC80),
                            style = MaterialTheme.typography.bodySmall,
                        )
                        snapshot.calibrationSourceProfile?.let { source ->
                            Text(
                                "TOF-ONLY · stereo ${source.profileId} сохранён · " +
                                    "K/D и stereo R/t не пересчитываются",
                                color = Color(0xFF7CFC98),
                                style = MaterialTheme.typography.bodySmall,
                            )
                        }
                        if (tofAlignmentRequired) {
                            Text(
                                if (tofAlignmentArmed) {
                                    "TOF ЗАФИКСИРОВАН ✓ · теперь двигайте только ChArUco"
                                } else {
                                    "TOF ALIGNMENT · автоснимки заблокированы. " +
                                        "Выставьте модуль по live 8×8, жёстко закрепите его, " +
                                        "затем разрешите сбор 18 поз."
                                },
                                color = if (tofAlignmentArmed) {
                                    Color(0xFF7CFC98)
                                } else {
                                    Color(0xFFFFCC80)
                                },
                                style = MaterialTheme.typography.bodySmall,
                            )
                            Button(
                                onClick = {
                                    tofAlignmentArmed = true
                                    analyzer.reset()
                                    localAnalysis = null
                                },
                                enabled = !tofAlignmentArmed,
                                modifier = Modifier.fillMaxWidth(),
                            ) {
                                Text(
                                    if (tofAlignmentArmed) {
                                        "TOF ЗАФИКСИРОВАН ✓"
                                    } else {
                                        "TOF ЗАФИКСИРОВАН · НАЧАТЬ 18 ПОЗ"
                                    },
                                )
                            }
                        }
                        Text(
                            if (
                                snapshot.calibrationStage ==
                                DualPhoneCalibrationStage.STEREO_EXTRINSICS &&
                                snapshot.calibrationMode ==
                                DualPhoneCalibrationMode.MANUAL_STEREO
                            ) {
                                "Установите доску, полностью остановите её и нажмите на MASTER " +
                                    "кнопку синхронного снимка."
                            } else if (snapshot.calibrationStage ==
                                DualPhoneCalibrationStage.STEREO_EXTRINSICS
                            ) {
                                "Плавно перемещайте доску так, чтобы её видели обе камеры. " +
                                    "Хорошие новые пары снимаются автоматически."
                            } else {
                                "Плавно двигайте, приближайте и наклоняйте доску. " +
                                    "Хорошие новые ракурсы снимаются автоматически."
                            },
                            style = MaterialTheme.typography.bodyMedium,
                        )
                        Text(
                            (if (
                                snapshot.calibrationMode ==
                                DualPhoneCalibrationMode.MANUAL_STEREO &&
                                snapshot.calibrationStage ==
                                DualPhoneCalibrationStage.STEREO_EXTRINSICS
                            ) {
                                "Ручные синхронные пары: "
                            } else {
                                "Автоснимки этапа: "
                            }) + snapshot.calibrationAcceptedPoseCount + "/" +
                                snapshot.calibrationTargetPoseCount,
                        )
                        if (calibrationOverlayDetailed) {
                            Text(
                                "Доска: ${localStereoSettings.calibrationBoard.summaryRu()}",
                                style = MaterialTheme.typography.bodySmall,
                            )
                        }
                        if (snapshot.calibrationMode ==
                            DualPhoneCalibrationMode.MANUAL_STEREO
                        ) {
                            if (role == DualPhoneRole.MASTER) {
                                Button(
                                    onClick = controlManager::requestManualStereoPair,
                                    modifier = Modifier.fillMaxWidth(),
                                    enabled = snapshot.connected &&
                                        !snapshot.calibrationManualCapturePending,
                                ) {
                                    Text(
                                        if (snapshot.calibrationManualCapturePending) {
                                            "ОЖИДАНИЕ СИНХРОННОЙ ПАРЫ…"
                                        } else {
                                            "СНЯТЬ СИНХРОННУЮ ПАРУ"
                                        },
                                    )
                                }
                                if (snapshot.calibrationManualCapturePending) {
                                    Text(
                                        "СНИМОК ВООРУЖЁН — НЕ ДВИГАЙТЕ ДОСКУ",
                                        color = Color(0xFF7CFC98),
                                        style = MaterialTheme.typography.titleSmall,
                                    )
                                }
                            } else {
                                Text(
                                    "Синхронный снимок запускается кнопкой на MASTER.",
                                    style = MaterialTheme.typography.bodySmall,
                                )
                            }
                        }
                    }
                    Text(
                        localQualityLine(snapshot, role, localAnalysis),
                        color = qualityColor(localAnalysis?.qualityReady == true),
                        style = MaterialTheme.typography.bodySmall,
                    )
                    if (calibrationOverlayDetailed) {
                        Text(
                            peerQualityLine(snapshot, role),
                            color = qualityColor(
                                snapshot.calibrationPeerObservation?.qualityReady == true,
                            ),
                            style = MaterialTheme.typography.bodySmall,
                        )
                    }
                    localAnalysis?.let { analysis ->
                        if (calibrationOverlayDetailed) {
                            Text(
                                "Покрытие ${analysis.coveragePercent}% · " +
                                    "новизна ${formatThree(analysis.noveltyScore)} · " +
                                    "стабильность ${analysis.stableMs} мс",
                                color = if (analysis.qualityReady) {
                                    Color(0xFF7CFC98)
                                } else {
                                    Color.White
                                },
                                style = MaterialTheme.typography.bodySmall,
                            )
                        }
                        Text(
                            analysis.guidance,
                            color = Color(0xFFFFCC80),
                            style = MaterialTheme.typography.bodySmall,
                        )
                        if (calibrationOverlayDetailed) {
                            Text(
                                "углы ${analysis.detection.cornersFound}/" +
                                    "${analysis.detection.expectedCorners} · " +
                                    "резкость ${formatOne(analysis.sharpnessScore)} · " +
                                    "яркость ${formatOne(analysis.meanLuma)}",
                                style = MaterialTheme.typography.bodySmall,
                            )
                        }
                    }
                    if (
                        calibrationOverlayDetailed &&
                        snapshot.calibrationStage ==
                            DualPhoneCalibrationStage.STEREO_EXTRINSICS
                    ) {
                        val commonCorners = stereoCommonCorners(snapshot)
                        val deltaMasterObservation =
                            snapshot.calibrationLocalObservation
                                ?: snapshot.calibrationLastAcceptedMasterObservation
                        val deltaSlaveObservation =
                            snapshot.calibrationPeerObservation
                                ?: snapshot.calibrationLastAcceptedSlaveObservation
                        val currentDelta = stereoFrameDeltaMs(
                            deltaMasterObservation,
                            deltaSlaveObservation,
                            snapshot.clockSync.offsetNs,
                        )
                        val timestampSources = listOfNotNull(
                            deltaMasterObservation?.timestampSource,
                            deltaSlaveObservation?.timestampSource,
                        ).joinToString("/").ifBlank { "—" }
                        val captureRequestLabel =
                            deltaMasterObservation?.captureRequestId
                                ?.takeLast(8)
                                ?.let { " · req $it" }
                                .orEmpty()
                        Text(
                            "STEREO COACH · общих углов $commonCorners · Δt " +
                                (currentDelta?.let { formatOne(it) + " мс" } ?: "—") +
                                " · ts $timestampSources" + captureRequestLabel,
                            color = if (
                                commonCorners >= DualPhoneStereoCoachEstimator.MIN_COMMON_BOARD_IDS &&
                                currentDelta != null && currentDelta <= 80.0
                            ) Color(0xFF7CFC98) else Color(0xFFFFCC80),
                            style = MaterialTheme.typography.titleSmall,
                        )
                        val cameraControlStatuses = listOfNotNull(
                            deltaMasterObservation?.cameraControlStatus,
                            deltaSlaveObservation?.cameraControlStatus,
                        ).joinToString(" | ").ifBlank { "—" }
                        Text(
                            "Camera controls: $cameraControlStatuses",
                            style = MaterialTheme.typography.bodySmall,
                        )
                        Text(
                            stereoCoach.summaryRu(),
                            style = MaterialTheme.typography.bodySmall,
                        )
                        Text(
                            "Покрытие ${stereoCoach.coveragePercent}% · " +
                                stereoCoach.coverageGrid,
                            style = MaterialTheme.typography.bodySmall,
                        )
                        Text(
                            stereoCoach.guidance,
                            color = Color(0xFFFFCC80),
                            style = MaterialTheme.typography.bodySmall,
                        )
                    }
                    if (calibrationOverlayDetailed) {
                        displayedLiveIntrinsics?.let { estimate ->
                            Text(
                                estimate.summary(),
                                color = if (estimate.solved) {
                                    Color(0xFF7CFC98)
                                } else {
                                    Color(0xFFFFCC80)
                                },
                                style = MaterialTheme.typography.bodySmall,
                            )
                        }
                        Text(
                            "Preview: $previewStatus",
                            style = MaterialTheme.typography.bodySmall,
                        )
                        Text(
                            persistenceStatus,
                            color = Color(0xFFFFCC80),
                            style = MaterialTheme.typography.bodySmall,
                        )
                    }
                    if (snapshot.calibrationCollectionComplete) {
                        Text(
                            "MASTER ✓ · SLAVE ✓ · ОБЕ КАМЕРЫ ✓ · " +
                                if (snapshot.calibrationTofAcceptedPoseCount > 0) {
                                    "TOF ✓"
                                } else {
                                    "TOF —"
                                },
                            color = Color(0xFF7CFC98),
                            style = MaterialTheme.typography.titleMedium,
                        )
                    }
                }
            }

            if (snapshot.calibrationCollectionComplete) {
                Card(
                    modifier = Modifier
                        .align(Alignment.Center)
                        .fillMaxWidth(0.72f),
                    colors = CardDefaults.cardColors(
                        containerColor = Color(0xEE12351F),
                        contentColor = Color.White,
                    ),
                ) {
                    Column(
                        modifier = Modifier.padding(24.dp),
                        horizontalAlignment = Alignment.CenterHorizontally,
                        verticalArrangement = Arrangement.spacedBy(10.dp),
                    ) {
                        Text(
                            "КАЛИБРОВКА ЗАВЕРШЕНА",
                            style = MaterialTheme.typography.headlineSmall,
                        )
                        Text(
                            "MASTER ✓   SLAVE ✓   ОБЕ КАМЕРЫ ✓   " +
                                if (snapshot.calibrationTofAcceptedPoseCount > 0) {
                                    "TOF ✓"
                                } else {
                                    "TOF НЕ ИСПОЛЬЗОВАЛСЯ"
                                },
                        )
                        Text("ПОСЛЕДНИЙ КАДР ЗАСЧИТАН ✓")
                        val finalResult = snapshot.calibrationFinalResult
                        if (finalResult == null) {
                            Text(
                                finalSolveStatus,
                                color = Color(0xFFFFCC80),
                                style = MaterialTheme.typography.bodyMedium,
                            )
                        } else {
                            Text(
                                finalIntrinsicsLine(
                                    "MASTER",
                                    finalResult.masterIntrinsics,
                                ),
                                style = MaterialTheme.typography.bodySmall,
                            )
                            Text(
                                finalIntrinsicsLine(
                                    "SLAVE",
                                    finalResult.slaveIntrinsics,
                                ),
                                style = MaterialTheme.typography.bodySmall,
                            )
                            Text(
                                finalResult.stereo.summary(),
                                style = MaterialTheme.typography.bodySmall,
                            )
                            Text(
                                "RAW @${finalResult.stereo.imageWidth}×${finalResult.stereo.imageHeight} " +
                                    "= фактическая калибровка и solve. " +
                                    "QUALITY EQUIV @1280 = только нормализованная шкала качества; " +
                                    "кадры до 1280 не уменьшаются.",
                                color = Color(0xFFB0BEC5),
                                style = MaterialTheme.typography.bodySmall,
                            )
                            finalResult.stereo.operatorBaselineMm?.let {
                                Text(
                                    "Заданный базис: ${formatOne(it)} мм · " +
                                        "расчётный: " +
                                        "${formatOne(finalResult.stereo.baselineMm ?: 0.0)} мм",
                                    style = MaterialTheme.typography.bodySmall,
                                )
                            }
                            Text(
                                if (finalResult.successful) {
                                    val marginalEpipolar =
                                        finalResult.stereo.normalizedMeanEpipolarErrorPx?.let {
                                            it > DualPhoneStereoEstimate
                                                .RECOMMENDED_MEAN_EPIPOLAR_ERROR_PX
                                        } == true
                                    val marginalBaseline =
                                        finalResult.stereo.baselineDeltaMm?.let { delta ->
                                            val expected =
                                                finalResult.stereo.operatorBaselineMm ?: 0.0
                                            abs(delta) > maxOf(15.0, expected * 0.12)
                                        } == true
                                    if (marginalEpipolar || marginalBaseline) {
                                        "КАЛИБРОВОЧНЫЙ ПРОФИЛЬ ПРИНЯТ " +
                                            "С ПРЕДУПРЕЖДЕНИЕМ ✓"
                                    } else {
                                        "КАЛИБРОВОЧНЫЙ ПРОФИЛЬ ПРИНЯТ ✓"
                                    }
                                } else {
                                    "КАЛИБРОВКА НЕ ПРИНЯТА: " +
                                        (finalResult.error ?: "неизвестная ошибка")
                                },
                                color = if (finalResult.successful) {
                                    Color(0xFF7CFC98)
                                } else {
                                    Color.Red
                                },
                                style = MaterialTheme.typography.titleMedium,
                            )
                        }
                        if (finalProfilePersistenceStatus.isNotBlank()) {
                            Text(
                                finalProfilePersistenceStatus,
                                color = Color(0xFF7CFC98),
                                style = MaterialTheme.typography.bodySmall,
                            )
                        }
                        if (
                            role == DualPhoneRole.MASTER &&
                            finalResult != null
                        ) {
                            Button(
                                onClick = {
                                    controlManager.restartStereoCalibration(
                                        DualPhoneCalibrationMode.AUTO,
                                    )
                                },
                                modifier = Modifier.fillMaxWidth(),
                            ) {
                                Text("ПОВТОРИТЬ АВТОКАЛИБРОВКУ")
                            }
                            Button(
                                onClick = {
                                    controlManager.restartStereoCalibration(
                                        DualPhoneCalibrationMode.MANUAL_STEREO,
                                    )
                                },
                                modifier = Modifier.fillMaxWidth(),
                            ) {
                                Text("ПОВТОРИТЬ ВРУЧНУЮ")
                            }
                            Text(
                                "Intrinsics K/D обеих камер сохраняются. В ручном режиме " +
                                    "каждая из 18 stereo-пар фиксируется кнопкой на MASTER.",
                                style = MaterialTheme.typography.bodySmall,
                            )
                        } else if (
                            role == DualPhoneRole.SLAVE &&
                            finalResult != null
                        ) {
                            Text(
                                "Повторную stereo-калибровку запускайте на MASTER.",
                                style = MaterialTheme.typography.bodySmall,
                            )
                        }
                    }
                }
            } else {
                acceptanceFeedback?.let { feedback ->
                    Card(
                        modifier = Modifier
                            .align(Alignment.Center)
                            .fillMaxWidth(0.68f),
                        colors = CardDefaults.cardColors(
                            containerColor = Color(0xEE0B5D2A),
                            contentColor = Color.White,
                        ),
                    ) {
                        Text(
                            feedback,
                            modifier = Modifier.padding(22.dp),
                            style = MaterialTheme.typography.titleMedium,
                        )
                    }
                }
            }

            Column(
                modifier = Modifier
                    .align(Alignment.BottomEnd)
                    .safeDrawingPadding()
                    .padding(20.dp),
                horizontalAlignment = Alignment.End,
                verticalArrangement = Arrangement.spacedBy(8.dp),
            ) {
                Row(
                    horizontalArrangement = Arrangement.spacedBy(8.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Button(
                        onClick = {
                            val detectedBoard = localAnalysis?.takeIf { analysis ->
                                analysis.detection.found && !analysis.boardClipped
                            }
                            val focusX = detectedBoard?.centreX ?: 0.5
                            val focusY = detectedBoard?.centreY ?: 0.5
                            focusScope.launch {
                                manualFocusBusy = true
                                try {
                                    previewStatus = "Ручной автофокус…"
                                    val focusStatus =
                                        DualPhonePreviewBindingRuntime.refreshCalibrationFocus(
                                            normalizedX = focusX,
                                            normalizedY = focusY,
                                        )
                                    analyzer.reset()
                                    localAnalysis = null
                                    previewStatus = if (
                                        focusStatus.contains("AF_BOARD_LOCKED")
                                    ) {
                                        "Автофокус сохранён для этой камеры"
                                    } else {
                                        "Автофокус: $focusStatus"
                                    }
                                } finally {
                                    manualFocusBusy = false
                                }
                            }
                        },
                        enabled = snapshot.calibrationActive &&
                            !snapshot.calibrationCollectionComplete &&
                            !manualFocusBusy,
                    ) {
                        Text(
                            if (manualFocusBusy) {
                                "ФОКУС…"
                            } else {
                                "АВТОФОКУС"
                            },
                        )
                    }

                    Button(
                        onClick = {
                            focusScope.launch {
                                manualFocusBusy = true
                                try {
                                    previewStatus = "Фиксируем фокус на ∞…"
                                    val focusStatus =
                                        DualPhonePreviewBindingRuntime
                                            .setCalibrationInfinityFocus()
                                    analyzer.reset()
                                    localAnalysis = null
                                    previewStatus = when (focusStatus) {
                                        "FOCUS_INFINITY_LOCKED" ->
                                            "Фокус ∞ сохранён для этой камеры"
                                        "FOCUS_INFINITY_UNSUPPORTED" ->
                                            "∞ focus не поддерживается этой камерой"
                                        else ->
                                            "∞ focus: $focusStatus"
                                    }
                                } finally {
                                    manualFocusBusy = false
                                }
                            }
                        },
                        enabled = snapshot.calibrationActive &&
                            !snapshot.calibrationCollectionComplete &&
                            !manualFocusBusy,
                    ) {
                        Text("∞ FIXED")
                    }
                }

                Row(
                    horizontalArrangement = Arrangement.spacedBy(10.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Box(
                        modifier = Modifier
                            .size(12.dp)
                            .background(
                                if (snapshot.connected) Color.Green else Color.Red,
                            ),
                    )
                    Text(
                        if (snapshot.connected) "Peer connected" else "Peer disconnected",
                        color = Color.White,
                    )
                    Button(
                        onClick = onExit,
                        enabled = !snapshot.calibrationCollectionComplete ||
                            snapshot.calibrationFinalResult != null,
                    ) {
                        Text(
                            if (snapshot.calibrationCollectionComplete) {
                                "ГОТОВО"
                            } else {
                                "ПРЕРВАТЬ"
                            },
                        )
                    }
                }
            }
        }
    }
}

@Composable
private fun CalibrationPreview(
    analysis: DualPhoneCalibrationRealtimeResult?,
    modifier: Modifier = Modifier,
    onStatus: (String) -> Unit,
) {
    val context = LocalContext.current
    val lifecycleOwner = LocalLifecycleOwner.current
    val currentOnStatus by rememberUpdatedState(onStatus)
    val previewView = remember(context) {
        (DualPhoneRecorderPreviewRegistry.current() ?: PreviewView(context)).apply {
            implementationMode = PreviewView.ImplementationMode.COMPATIBLE
            scaleType = PreviewView.ScaleType.FIT_CENTER
        }
    }
    DisposableEffect(previewView) {
        DualPhoneRecorderPreviewRegistry.register(previewView)
        onDispose {
            DualPhoneRecorderPreviewRegistry.unregister(previewView)
        }
    }
    LaunchedEffect(previewView, lifecycleOwner) {
        currentOnStatus("Binding selected camera…")
        val result = DualPhonePreviewBindingRuntime.bind(
            context = context,
            lifecycleOwner = lifecycleOwner,
            previewView = previewView,
            calibrationMode = true,
        )
        currentOnStatus(
            if (result.success) {
                buildString {
                    append("LIVE")
                    result.cameraId?.let { append(" · camera ").append(it) }
                    result.effectiveZoomRatio?.let { append(" · zoom ").append(it) }
                }
            } else {
                "ERROR · ${result.error ?: result.bindStatus}"
            },
        )
    }

    Box(modifier = modifier.background(Color.Black)) {
        AndroidView(
            factory = {
                (previewView.parent as? ViewGroup)?.removeView(previewView)
                DualPhoneRecorderPreviewRegistry.register(previewView)
                previewView
            },
            update = { DualPhoneRecorderPreviewRegistry.register(it) },
            modifier = Modifier.fillMaxSize(),
        )
        CalibrationCornerOverlay(
            analysis = analysis,
            modifier = Modifier.fillMaxSize(),
        )
    }
}

@Composable
private fun CalibrationCornerOverlay(
    analysis: DualPhoneCalibrationRealtimeResult?,
    modifier: Modifier = Modifier,
) {
    Canvas(modifier = modifier) {
        val result = analysis ?: return@Canvas
        val width = result.detection.imageWidth
        val height = result.detection.imageHeight
        if (width <= 0 || height <= 0) return@Canvas
        val overlayColor = if (result.qualityReady) {
            Color(0xFF00E676)
        } else {
            Color(0xFFFFD54F)
        }
        val radius = min(size.width, size.height) * 0.008f
        result.detection.normalizedCornerPoints.forEach { point ->
            val mapped = mapRawPointToFitPreview(
                normalizedX = point.x,
                normalizedY = point.y,
                rawWidth = width,
                rawHeight = height,
                rotationDegrees = result.imageProxyRotationDegrees,
                viewportWidth = size.width,
                viewportHeight = size.height,
            )
            drawCircle(
                color = overlayColor,
                radius = radius.coerceAtLeast(4f),
                center = mapped,
                style = Stroke(width = 2.5f),
            )
        }
    }
}

private fun mapRawPointToFitPreview(
    normalizedX: Float,
    normalizedY: Float,
    rawWidth: Int,
    rawHeight: Int,
    rotationDegrees: Int,
    viewportWidth: Float,
    viewportHeight: Float,
): Offset {
    val rotation = ((rotationDegrees % 360) + 360) % 360
    val rotatedX: Float
    val rotatedY: Float
    val sourceWidth: Float
    val sourceHeight: Float
    when (rotation) {
        90 -> {
            rotatedX = 1f - normalizedY
            rotatedY = normalizedX
            sourceWidth = rawHeight.toFloat()
            sourceHeight = rawWidth.toFloat()
        }
        180 -> {
            rotatedX = 1f - normalizedX
            rotatedY = 1f - normalizedY
            sourceWidth = rawWidth.toFloat()
            sourceHeight = rawHeight.toFloat()
        }
        270 -> {
            rotatedX = normalizedY
            rotatedY = 1f - normalizedX
            sourceWidth = rawHeight.toFloat()
            sourceHeight = rawWidth.toFloat()
        }
        else -> {
            rotatedX = normalizedX
            rotatedY = normalizedY
            sourceWidth = rawWidth.toFloat()
            sourceHeight = rawHeight.toFloat()
        }
    }

    val scale = min(
        viewportWidth / sourceWidth.coerceAtLeast(1f),
        viewportHeight / sourceHeight.coerceAtLeast(1f),
    )
    val renderedWidth = sourceWidth * scale
    val renderedHeight = sourceHeight * scale
    val offsetX = (viewportWidth - renderedWidth) / 2f
    val offsetY = (viewportHeight - renderedHeight) / 2f
    return Offset(
        x = offsetX + rotatedX.coerceIn(0f, 1f) * renderedWidth,
        y = offsetY + rotatedY.coerceIn(0f, 1f) * renderedHeight,
    )
}

@Composable
private fun CalibrationStageProgress(snapshot: DualPhoneControlSnapshot) {
    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        CalibrationStageChip(
            stage = DualPhoneCalibrationStage.MASTER_INTRINSICS,
            snapshot = snapshot,
            label = "1 MASTER",
        )
        CalibrationStageChip(
            stage = DualPhoneCalibrationStage.SLAVE_INTRINSICS,
            snapshot = snapshot,
            label = "2 SLAVE",
        )
        CalibrationStageChip(
            stage = DualPhoneCalibrationStage.STEREO_EXTRINSICS,
            snapshot = snapshot,
            label = "3 ОБЕ",
        )
        CalibrationStageChip(
            stage = DualPhoneCalibrationStage.MASTER_TOF_EXTRINSICS,
            snapshot = snapshot,
            label = "4 TOF",
        )
    }
}

@Composable
private fun CalibrationStageChip(
    stage: DualPhoneCalibrationStage,
    snapshot: DualPhoneControlSnapshot,
    label: String,
) {
    val completed = acceptedStageCount(stage, snapshot) >= stage.targetPoseCount
    val active = snapshot.calibrationStage == stage &&
        !snapshot.calibrationCollectionComplete
    Card(
        colors = CardDefaults.cardColors(
            containerColor = when {
                completed -> Color(0xCC0B5D2A)
                active -> Color(0xCC8A5A00)
                else -> Color(0xAA333333)
            },
            contentColor = Color.White,
        ),
    ) {
        Text(
            if (completed) "$label ✓" else label,
            modifier = Modifier.padding(horizontal = 8.dp, vertical = 5.dp),
            style = MaterialTheme.typography.bodySmall,
        )
    }
}

private fun acceptedStageCount(
    stage: DualPhoneCalibrationStage,
    snapshot: DualPhoneControlSnapshot,
): Int = when (stage) {
    DualPhoneCalibrationStage.MASTER_INTRINSICS ->
        snapshot.calibrationMasterAcceptedPoseCount
    DualPhoneCalibrationStage.SLAVE_INTRINSICS ->
        snapshot.calibrationSlaveAcceptedPoseCount
    DualPhoneCalibrationStage.STEREO_EXTRINSICS ->
        snapshot.calibrationStereoAcceptedPoseCount
    DualPhoneCalibrationStage.MASTER_TOF_EXTRINSICS ->
        snapshot.calibrationTofAcceptedPoseCount
    DualPhoneCalibrationStage.COMPLETE -> 0
}

private fun stageRoleInstruction(
    stage: DualPhoneCalibrationStage,
    role: DualPhoneRole,
): String = when (stage) {
    DualPhoneCalibrationStage.MASTER_INTRINSICS -> if (role == DualPhoneRole.MASTER) {
        "MASTER снимает автоматически. Двигайте только доску, а не телефон."
    } else {
        "ОЖИДАНИЕ: MASTER автоматически собирает разнообразные кадры."
    }
    DualPhoneCalibrationStage.SLAVE_INTRINSICS -> if (role == DualPhoneRole.SLAVE) {
        "SLAVE снимает автоматически. Двигайте только доску, а не телефон."
    } else {
        "ОЖИДАНИЕ: SLAVE автоматически собирает разнообразные кадры."
    }
    DualPhoneCalibrationStage.STEREO_EXTRINSICS ->
        "Обе камеры снимают пары автоматически. Доска должна быть видна обеим."
    DualPhoneCalibrationStage.MASTER_TOF_EXTRINSICS -> if (role == DualPhoneRole.MASTER) {
        "MASTER снимает ChArUco; ToF должен быть подключён и иметь READY clock sync."
    } else {
        "ОЖИДАНИЕ: этап MASTER + TOF выполняется только на MASTER."
    }
    DualPhoneCalibrationStage.COMPLETE -> "Все четыре этапа завершены."
}

private fun localQualityLine(
    snapshot: DualPhoneControlSnapshot,
    role: DualPhoneRole,
    analysis: DualPhoneCalibrationRealtimeResult?,
): String = when {
    snapshot.calibrationCollectionComplete -> "Локальная камера: завершено"
    !snapshot.calibrationStage.isLocalAnalyzerActive(role) ->
        "Локальная камера: ОЖИДАНИЕ — на этом этапе кадры не принимает"
    analysis == null -> "Локальная камера: ожидание кадра анализа"
    analysis.qualityReady -> "Локальная камера: ГОТОВА"
    else -> "Локальная камера: ${analysis.status}"
}

private fun peerQualityLine(
    snapshot: DualPhoneControlSnapshot,
    role: DualPhoneRole,
): String {
    if (snapshot.calibrationCollectionComplete) return "Удалённая камера: завершено"
    if (role == DualPhoneRole.SLAVE) {
        return "MASTER координирует этап и переключает следующую позу"
    }
    if (!snapshot.calibrationStage.requiresSlaveObservation) {
        return "SLAVE: ОЖИДАНИЕ — его состояние сейчас не блокирует кадр"
    }
    val observation = snapshot.calibrationPeerObservation
        ?: return "SLAVE: ожидание анализа"
    return if (observation.qualityReady) {
        "SLAVE: ГОТОВ"
    } else {
        "SLAVE: ${observation.status}"
    }
}

private fun stereoCommonCorners(snapshot: DualPhoneControlSnapshot): Int {
    val local = snapshot.calibrationLocalObservation ?: return 0
    val peer = snapshot.calibrationPeerObservation ?: return 0
    return local.charucoCorners.map { it.id }.toSet()
        .intersect(peer.charucoCorners.map { it.id }.toSet())
        .size
}

private fun stereoFrameDeltaMs(
    master: DualPhoneCalibrationObservation?,
    slave: DualPhoneCalibrationObservation?,
    slaveMinusMasterOffsetNs: Long?,
): Double? {
    if (master == null || slave == null) return null
    val masterTarget = master.captureTargetElapsedRealtimeNs
    val slaveTarget = slave.captureTargetElapsedRealtimeNs
    if (
        master.captureRequestId != null &&
        master.captureRequestId == slave.captureRequestId &&
        masterTarget != null &&
        slaveTarget != null
    ) {
        return kotlin.math.abs(
            (master.captureElapsedRealtimeNs - masterTarget) -
                (slave.captureElapsedRealtimeNs - slaveTarget),
        ) / 1_000_000.0
    }
    if (
        slaveMinusMasterOffsetNs != null &&
        master.captureElapsedRealtimeNs > 0L &&
        slave.captureElapsedRealtimeNs > 0L
    ) {
        return kotlin.math.abs(
            master.captureElapsedRealtimeNs -
                (slave.captureElapsedRealtimeNs - slaveMinusMasterOffsetNs),
        ) / 1_000_000.0
    }
    return null
}

private fun qualityColor(ready: Boolean): Color =
    if (ready) Color(0xFF7CFC98) else Color(0xFFFFD166)

private fun finalIntrinsicsLine(
    label: String,
    estimate: DualPhoneLiveIntrinsicsEstimate,
): String = if (
    estimate.rms != null &&
    estimate.fx != null &&
    estimate.fy != null &&
    estimate.k1 != null &&
    estimate.k2 != null
) {
    "$label RMS ${formatThree(estimate.rms)} px · " +
        "fx ${formatOne(estimate.fx)} · fy ${formatOne(estimate.fy)} · " +
        "k1 ${formatThree(estimate.k1)} · k2 ${formatThree(estimate.k2)}"
} else {
    "$label: ${estimate.status}"
}

private fun formatOne(value: Double): String =
    String.format(Locale.US, "%.1f", value)

private fun formatThree(value: Double): String =
    String.format(Locale.US, "%.3f", value)

private tailrec fun Context.findActivity(): Activity? = when (this) {
    is Activity -> this
    is ContextWrapper -> baseContext.findActivity()
    else -> null
}
