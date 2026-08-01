package com.example.maklertour.ui.session

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.unit.dp
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.LifecycleEventObserver
import androidx.lifecycle.compose.LocalLifecycleOwner
import com.example.maklertour.data.dualphone.DualPhoneApplicationRuntime
import com.example.maklertour.data.dualphone.DualPhoneLiveStreamMode
import com.example.maklertour.data.dualphone.DualPhoneLiveStreamSessionBlock
import com.example.maklertour.data.dualphone.DualPhoneLiveStreamSessionStatus
import com.maklertour.data.calibration.DualPhoneCalibrationCameraIdentityRepair
import com.maklertour.data.calibration.DualPhoneCalibrationProfileStore
import com.maklertour.data.dualphone.DualPhoneControlManager
import com.maklertour.data.dualphone.DualPhoneRole
import com.maklertour.data.dualphone.DualPhoneStereoSettingsStore
import com.maklertour.data.phonecamera.PhoneCameraLensRepository

/**
 * Returns true only for MASTER/SLAVE roles.
 *
 * The role is reloaded when the Camera destination resumes, so changing it in
 * Settings immediately switches the Camera menu on return.
 */
@Composable
fun rememberDualPhoneCaptureSelected(): Boolean {
    val context = LocalContext.current
    val appContext = context.applicationContext
    val lifecycleOwner = LocalLifecycleOwner.current
    val settingsStore = remember(appContext) {
        DualPhoneStereoSettingsStore(appContext)
    }
    var role by remember(settingsStore) {
        mutableStateOf(settingsStore.load().role)
    }

    DisposableEffect(lifecycleOwner, settingsStore) {
        val observer = LifecycleEventObserver { _, event ->
            if (event == Lifecycle.Event.ON_RESUME) {
                role = settingsStore.load().role
            }
        }
        lifecycleOwner.lifecycle.addObserver(observer)
        onDispose {
            lifecycleOwner.lifecycle.removeObserver(observer)
        }
    }

    return role != DualPhoneRole.STANDALONE
}

/**
 * Session-visible dual-phone live card.
 *
 * LM02 launches a separate full-screen scan workspace after LIVE/HYBRID while
 * preserving the application-scoped runtime and the bounded LM01B media pipeline.
 */
@Composable
fun DualPhoneLiveStreamSessionCard(
    selectedSessionId: String?,
    modifier: Modifier = Modifier,
) {
    val context = LocalContext.current
    val appContext = context.applicationContext
    val controlManager = remember(appContext) {
        DualPhoneControlManager.get(appContext)
    }
    val controlSnapshot by controlManager.state.collectAsState()
    val settingsStore = remember(appContext) {
        DualPhoneStereoSettingsStore(appContext)
    }
    val profileStore = remember(appContext) {
        DualPhoneCalibrationProfileStore(appContext)
    }
    val lensRepository = remember(appContext) {
        PhoneCameraLensRepository(appContext)
    }
    val applicationRuntime = remember(appContext) {
        DualPhoneApplicationRuntime.get(appContext)
    }
    val runtimeSnapshot by applicationRuntime.state.collectAsState()
    val requestedMode = runtimeSnapshot.requestedMode
    val status = runtimeSnapshot.sessionStatus
    val dataChannelSnapshot = runtimeSnapshot.dataChannel
    var refreshSerial by remember { mutableStateOf(0) }
    var cameraIdentityRepairStatus by remember {
        mutableStateOf<String?>(null)
    }
    var showScanWorkspace by remember { mutableStateOf(false) }
    val settings = remember(controlSnapshot, refreshSerial) {
        settingsStore.load()
    }
    val calibrationProfile = remember(
        settings.activeCalibrationProfileId,
        refreshSerial,
    ) {
        settings.activeCalibrationProfileId
            ?.let(profileStore::load)
    }
    val localSelectedCameraId = remember(refreshSerial) {
        runCatching {
            lensRepository.selectedOrDefault().first.cameraId
        }.getOrNull()
    }
    val cameraIdentityRepair = remember(
        calibrationProfile,
        settings.role,
        localSelectedCameraId,
        controlSnapshot.peerCameraId,
    ) {
        calibrationProfile?.let { profile ->
            DualPhoneCalibrationCameraIdentityRepair.repair(
                profile = profile,
                localRole = settings.role,
                localCameraId = localSelectedCameraId,
                peerCameraId = controlSnapshot.peerCameraId,
            )
        }
    }

    Card(modifier = modifier.fillMaxWidth()) {
        Column(
            modifier = Modifier.padding(12.dp),
            verticalArrangement = Arrangement.spacedBy(8.dp),
        ) {
            Text("Live metric stream · LM01A")
            Text(
                "Отдельный каркас потока в выбранной сессии. " +
                    "Старая кнопка «Начать видео-скан» к нему не относится.",
            )

            if (settings.role == DualPhoneRole.MASTER) {
                Row(
                    horizontalArrangement = Arrangement.spacedBy(8.dp),
                ) {
                    modeButton(
                        label = "LIVE",
                        selected = requestedMode ==
                            DualPhoneLiveStreamMode.LIVE_METRIC,
                        onClick = {
                            applicationRuntime.enterWorkMode(
                                selectedSessionId,
                                DualPhoneLiveStreamMode.LIVE_METRIC,
                            )
                            showScanWorkspace = true
                        },
                    )
                    modeButton(
                        label = "HYBRID",
                        selected = requestedMode ==
                            DualPhoneLiveStreamMode.HYBRID,
                        onClick = {
                            applicationRuntime.enterWorkMode(
                                selectedSessionId,
                                DualPhoneLiveStreamMode.HYBRID,
                            )
                            showScanWorkspace = true
                        },
                    )
                    OutlinedButton(
                        onClick = {
                            applicationRuntime.enterManagedWorkSurface(
                                forcePassive = true,
                            )
                            showScanWorkspace = false
                        },
                        enabled = requestedMode.streamEnabled,
                    ) {
                        Text("Выкл. LIVE")
                    }
                }
                if (requestedMode.streamEnabled) {
                    OutlinedButton(
                        onClick = { showScanWorkspace = true },
                    ) {
                        Text("Открыть полноэкранный скан")
                    }
                }
            } else {
                Text("LIVE/HYBRID выбираются только на MASTER.")
            }

            Text("App mode: ${runtimeSnapshot.applicationMode.name}")
            Text("Состояние: ${status.snapshot.state.name}")
            Text(statusText(status))
            Text(
                "Data channel: ${dataChannelSnapshot.state.name} · " +
                    "порт ${dataChannelSnapshot.port}",
            )
            dataChannelSnapshot.remoteAddress?.let {
                Text("Peer socket: $it")
            }
            Text(
                "Пакеты: ↑${dataChannelSnapshot.packetsSent} " +
                    "↓${dataChannelSnapshot.packetsReceived}",
            )
            dataChannelSnapshot.lastRoundTripMs?.let {
                Text("RTT: ${"%.1f".format(it)} ms")
            }
            dataChannelSnapshot.lastError?.let {
                Text("Data channel error: $it")
            }
            Text(runtimeSnapshot.lastMessage)
            runtimeSnapshot.lastError?.let {
                Text("Runtime error: $it")
            }
            if (requestedMode.streamEnabled) {
                DualPhoneLiveDualPreview(snapshot = runtimeSnapshot)
            }

            if (
                status.block ==
                    DualPhoneLiveStreamSessionBlock.MISSING_CAMERA_IDENTITY
            ) {
                Text(
                    "Локальная камера: " +
                        (localSelectedCameraId ?: "не определена"),
                )
                Text(
                    "Камера второго телефона: " +
                        (controlSnapshot.peerCameraId ?: "не получена"),
                )
                cameraIdentityRepair?.let { repair ->
                    Text(repair.message)
                    Button(
                        onClick = {
                            val repairedProfile = repair.profile
                            if (repairedProfile == null || !repair.changed) {
                                cameraIdentityRepairStatus = repair.message
                            } else {
                                cameraIdentityRepairStatus = runCatching {
                                    profileStore.save(repairedProfile)
                                    refreshSerial += 1
                                    applicationRuntime.refresh()
                                    "ID камер сохранены в calibration profile"
                                }.getOrElse { error ->
                                    "Не удалось сохранить ID камер: " +
                                        (
                                            error.message
                                                ?: error.javaClass.simpleName
                                        )
                                }
                            }
                        },
                        enabled = repair.profile != null && repair.changed,
                    ) {
                        Text("Восстановить ID камер")
                    }
                }
            }

            cameraIdentityRepairStatus?.let {
                Text(it)
            }

            if (status.sessionAccepted) {
                Text(
                    if (dataChannelSnapshot.ready) {
                        "Отдельный TCP data channel MASTER↔SLAVE подключён. " +
                            "Кадры камеры ещё не передаются."
                    } else {
                        "Сессия и калибровка приняты. " +
                            "Поднимается отдельный TCP data channel."
                    },
                )
                status.recordingModeIdentitySource?.let {
                    Text("Идентичность режима: $it")
                }
            }

            OutlinedButton(
                onClick = {
                    refreshSerial += 1
                    applicationRuntime.refresh()
                },
            ) {
                Text("Обновить проверку")
            }
        }
    }

    if (showScanWorkspace && settings.role == DualPhoneRole.MASTER) {
        DualPhoneMasterScanDialog(
            snapshot = runtimeSnapshot,
            selectedSessionId = selectedSessionId,
            applicationRuntime = applicationRuntime,
            onDismiss = { showScanWorkspace = false },
        )
    }
}

@Composable
private fun modeButton(
    label: String,
    selected: Boolean,
    onClick: () -> Unit,
) {
    if (selected) {
        Button(onClick = onClick, enabled = false) {
            Text(label)
        }
    } else {
        OutlinedButton(onClick = onClick) {
            Text(label)
        }
    }
}

private fun statusText(
    status: DualPhoneLiveStreamSessionStatus,
): String = when (status.block) {
    DualPhoneLiveStreamSessionBlock.NONE ->
        "Каркас привязан к выбранной сессии и dual_capture_id."
    DualPhoneLiveStreamSessionBlock.MODE_DISABLED ->
        "Reduced stream выключен. SYNC_VIDEO работает без изменений."
    DualPhoneLiveStreamSessionBlock.NO_SESSION ->
        "Сначала выберите съёмочную сессию."
    DualPhoneLiveStreamSessionBlock.STANDALONE_ROLE ->
        "Выберите роль MASTER или SLAVE."
    DualPhoneLiveStreamSessionBlock.CONTROL_NOT_CONNECTED ->
        "Подключите второй телефон."
    DualPhoneLiveStreamSessionBlock.CONTROL_PHASE_UNAVAILABLE ->
        "Поток нужно включить до ARM/START существующей записи."
    DualPhoneLiveStreamSessionBlock.CALIBRATION_IN_PROGRESS ->
        "Завершите или отмените активную калибровку."
    DualPhoneLiveStreamSessionBlock.MISSING_DUAL_CAPTURE_ID ->
        "Нет dual_capture_id от control channel."
    DualPhoneLiveStreamSessionBlock.MISSING_PEER_IDENTITY ->
        "Нет подтверждённой identity второго телефона."
    DualPhoneLiveStreamSessionBlock.MISSING_ACTIVE_CALIBRATION ->
        "Нет активного dual-phone calibration profile."
    DualPhoneLiveStreamSessionBlock.CALIBRATION_PROFILE_NOT_FOUND ->
        "Активный calibration profile не найден в локальном хранилище."
    DualPhoneLiveStreamSessionBlock.CALIBRATION_PROFILE_REJECTED ->
        "Активный calibration profile не прошёл acceptance."
    DualPhoneLiveStreamSessionBlock.CALIBRATION_PROFILE_ID_MISMATCH ->
        "Загружен не тот calibration profile."
    DualPhoneLiveStreamSessionBlock.RIG_ID_MISMATCH ->
        "Rig ID не совпадает с calibration profile."
    DualPhoneLiveStreamSessionBlock.MOUNT_REVISION_MISMATCH ->
        "Mount revision не совпадает с calibration profile."
    DualPhoneLiveStreamSessionBlock.LOCAL_DEVICE_MISMATCH ->
        "Локальный телефон не совпадает с ролью в calibration profile."
    DualPhoneLiveStreamSessionBlock.PEER_DEVICE_MISMATCH ->
        "Подключённый peer не совпадает с calibration profile."
    DualPhoneLiveStreamSessionBlock.MISSING_CAMERA_IDENTITY ->
        "В calibration profile отсутствует physical camera ID."
    DualPhoneLiveStreamSessionBlock.MISSING_CALIBRATION_IMAGE_SIZE ->
        "В calibration profile отсутствует принятый image size."
}
