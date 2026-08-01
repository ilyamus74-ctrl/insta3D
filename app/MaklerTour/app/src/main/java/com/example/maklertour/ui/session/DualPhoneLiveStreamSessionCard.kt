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
import androidx.compose.runtime.LaunchedEffect
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
import com.example.maklertour.data.dualphone.DualPhoneLiveStreamMode
import com.example.maklertour.data.dualphone.DualPhoneLiveStreamSessionBlock
import com.example.maklertour.data.dualphone.DualPhoneLiveStreamSessionCoordinator
import com.example.maklertour.data.dualphone.DualPhoneLiveStreamSessionInput
import com.example.maklertour.data.dualphone.DualPhoneLiveStreamSessionStatus
import com.maklertour.data.calibration.DualPhoneCalibrationProfileStore
import com.maklertour.data.dualphone.DualPhoneControlManager
import com.maklertour.data.dualphone.DualPhoneRole
import com.maklertour.data.dualphone.DualPhoneStereoSettingsStore

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
 * Session-visible LM01A card.
 *
 * It performs identity/calibration gating and exposes PREPARING only. No frame socket
 * or CameraX analysis output is started by this slice.
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
    val coordinator = remember {
        DualPhoneLiveStreamSessionCoordinator()
    }

    var requestedMode by remember {
        mutableStateOf(DualPhoneLiveStreamMode.SYNC_VIDEO)
    }
    var refreshSerial by remember { mutableStateOf(0) }
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
    val input = remember(
        selectedSessionId,
        requestedMode,
        settings,
        controlSnapshot,
        calibrationProfile,
    ) {
        DualPhoneLiveStreamSessionInput(
            sessionUuid = selectedSessionId,
            requestedMode = requestedMode,
            settings = settings,
            control = controlSnapshot,
            calibrationProfile = calibrationProfile,
        )
    }
    var status by remember {
        mutableStateOf(coordinator.currentStatus())
    }

    LaunchedEffect(input) {
        status = coordinator.reconcile(input)
    }
    DisposableEffect(coordinator) {
        onDispose {
            coordinator.release()
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

            Row(
                horizontalArrangement = Arrangement.spacedBy(8.dp),
            ) {
                modeButton(
                    label = "LIVE",
                    selected = requestedMode ==
                        DualPhoneLiveStreamMode.LIVE_METRIC,
                    onClick = {
                        requestedMode = DualPhoneLiveStreamMode.LIVE_METRIC
                    },
                )
                modeButton(
                    label = "HYBRID",
                    selected = requestedMode ==
                        DualPhoneLiveStreamMode.HYBRID,
                    onClick = {
                        requestedMode = DualPhoneLiveStreamMode.HYBRID
                    },
                )
                OutlinedButton(
                    onClick = {
                        requestedMode = DualPhoneLiveStreamMode.SYNC_VIDEO
                    },
                    enabled = requestedMode !=
                        DualPhoneLiveStreamMode.SYNC_VIDEO,
                ) {
                    Text("Выкл.")
                }
            }

            Text("Состояние: ${status.snapshot.state.name}")
            Text(statusText(status))

            if (status.sessionAccepted) {
                Text(
                    "Сессия и калибровка приняты. " +
                        "Канал кадров ещё не подключён.",
                )
                status.recordingModeIdentitySource?.let {
                    Text("Идентичность режима: $it")
                }
            }

            OutlinedButton(
                onClick = { refreshSerial += 1 },
            ) {
                Text("Обновить проверку")
            }
        }
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
