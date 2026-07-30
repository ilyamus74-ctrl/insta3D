package com.maklertour.ui.settings

import androidx.camera.view.PreviewView
import androidx.compose.foundation.layout.aspectRatio
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.unit.dp
import androidx.compose.ui.viewinterop.AndroidView
import com.maklertour.data.dualphone.DualPhoneClockSyncQuality
import com.maklertour.data.dualphone.captureSchedulingAllowed
import com.maklertour.data.dualphone.DualPhoneControlPhase
import com.maklertour.data.dualphone.DualPhoneControlSnapshot
import com.maklertour.data.dualphone.DualPhoneRole
import com.maklertour.data.dualphone.DualPhoneStereoSettings
import com.maklertour.data.dualphone.DualPhoneStereoSettingsStore
import com.maklertour.data.phonecamera.DualPhoneRecorderPreviewRegistry
import java.util.Locale

@Composable
fun DualPhoneControlSettingsCard(
    settings: DualPhoneStereoSettings,
    snapshot: DualPhoneControlSnapshot,
    masterHost: String,
    pairingCode: String,
    onMasterHostChanged: (String) -> Unit,
    onPairingCodeChanged: (String) -> Unit,
    onStartMaster: () -> Unit,
    onConnectSlave: () -> Unit,
    onDisconnect: () -> Unit,
    onArm: () -> Unit,
    onStartTest: () -> Unit,
    onStopCapture: () -> Unit,
) {
    val context = LocalContext.current
    var rigIdInput by remember(settings.rigId) {
        mutableStateOf(settings.rigId)
    }
    var mountRevisionInput by remember(settings.rigMountRevision) {
        mutableStateOf(settings.rigMountRevision)
    }
    var baselineMmInput by remember(settings.operatorLensBaselineMm) {
        mutableStateOf(settings.operatorLensBaselineMm?.toString().orEmpty())
    }
    var rigSaveMessage by remember { mutableStateOf<String?>(null) }

    Card(modifier = Modifier.fillMaxWidth()) {
        Column(
            modifier = Modifier.padding(12.dp),
            verticalArrangement = Arrangement.spacedBy(8.dp),
        ) {
            Text(
                "Dual-phone Wi-Fi control + clock sync (DP03)",
                style = MaterialTheme.typography.titleMedium,
            )
            Text(
                "State: ${snapshot.phase.name}",
                style = MaterialTheme.typography.bodyMedium,
            )
            Text(snapshot.lastMessage, style = MaterialTheme.typography.bodySmall)
            snapshot.lastError?.let { error ->
                Text(error, color = Color.Red, style = MaterialTheme.typography.bodySmall)
            }

            if (settings.role == DualPhoneRole.STANDALONE) {
                Text(
                    "Select Master or Slave above to start the control channel.",
                    style = MaterialTheme.typography.bodySmall,
                )
                return@Column
            }

            if (settings.role == DualPhoneRole.MASTER) {
                Text(
                    "Rigid dual-phone geometry",
                    style = MaterialTheme.typography.titleSmall,
                )
                OutlinedTextField(
                    value = rigIdInput,
                    onValueChange = { rigIdInput = it.take(80) },
                    label = { Text("Rig ID") },
                    supportingText = {
                        Text("Stable identifier of this physical phone mount")
                    },
                    singleLine = true,
                    modifier = Modifier.fillMaxWidth(),
                )
                OutlinedTextField(
                    value = mountRevisionInput,
                    onValueChange = { mountRevisionInput = it.take(80) },
                    label = { Text("Mount revision") },
                    supportingText = {
                        Text("Change it after any mechanical change")
                    },
                    singleLine = true,
                    modifier = Modifier.fillMaxWidth(),
                )
                OutlinedTextField(
                    value = baselineMmInput,
                    onValueChange = { value ->
                        baselineMmInput = value
                            .replace(',', '.')
                            .filterIndexed { index, char ->
                                char.isDigit() || (char == '.' && index > 0)
                            }
                            .take(10)
                    },
                    label = { Text("Lens-center distance, mm") },
                    supportingText = {
                        Text(
                            "Measure optical-center to optical-center. This is an operator " +
                                "prior; accepted stereo calibration remains authoritative.",
                        )
                    },
                    singleLine = true,
                    modifier = Modifier.fillMaxWidth(),
                )
                Button(
                    onClick = {
                        val baselineMm = baselineMmInput.toDoubleOrNull()
                        val error = when {
                            rigIdInput.isBlank() -> "Rig ID is required"
                            mountRevisionInput.isBlank() -> "Mount revision is required"
                            baselineMm == null -> "Enter lens distance in millimetres"
                            baselineMm !in 1.0..1_000.0 -> "Lens distance must be 1–1000 mm"
                            else -> null
                        }
                        if (error != null) {
                            rigSaveMessage = error
                        } else {
                            DualPhoneStereoSettingsStore(context).save(
                                settings.copy(
                                    rigId = rigIdInput.trim(),
                                    rigMountRevision = mountRevisionInput.trim(),
                                    operatorLensBaselineMm = baselineMm,
                                ),
                            )
                            rigSaveMessage = "Rig geometry saved"
                        }
                    },
                    modifier = Modifier.fillMaxWidth(),
                ) {
                    Text("Save rig geometry")
                }
                rigSaveMessage?.let {
                    Text(it, style = MaterialTheme.typography.bodySmall)
                }

                if (snapshot.phase == DualPhoneControlPhase.STOPPED ||
                    snapshot.phase == DualPhoneControlPhase.ERROR
                ) {
                    Button(
                        onClick = onStartMaster,
                        modifier = Modifier.fillMaxWidth(),
                    ) {
                        Text("Start Master pairing server")
                    }
                } else {
                    Text(
                        "Master address: ${snapshot.localHost ?: "unknown"}:" +
                            settings.controlPort,
                    )
                    snapshot.pairingCode?.let {
                        Text("Pairing code: $it")
                    }
                    snapshot.dualCaptureId?.let {
                        Text(
                            "dual_capture_id: $it",
                            style = MaterialTheme.typography.bodySmall,
                        )
                    }
                }
            }

            if (settings.role == DualPhoneRole.SLAVE) {
                OutlinedTextField(
                    value = masterHost,
                    onValueChange = onMasterHostChanged,
                    label = { Text("Master IPv4 address") },
                    singleLine = true,
                    modifier = Modifier.fillMaxWidth(),
                )
                OutlinedTextField(
                    value = pairingCode,
                    onValueChange = {
                        onPairingCodeChanged(
                            it.filter(Char::isDigit).take(6),
                        )
                    },
                    label = { Text("Six-digit pairing code") },
                    singleLine = true,
                    modifier = Modifier.fillMaxWidth(),
                )
                if (!snapshot.connected) {
                    Button(
                        onClick = onConnectSlave,
                        modifier = Modifier.fillMaxWidth(),
                    ) {
                        Text("Connect to Master")
                    }
                }
            }

            if (snapshot.connected) {
                Text("Peer: ${snapshot.peerHost ?: "unknown"}")
                snapshot.peerDeviceId?.let {
                    Text("Peer device: $it", style = MaterialTheme.typography.bodySmall)
                }
                snapshot.peerModel?.let {
                    Text("Peer model: $it", style = MaterialTheme.typography.bodySmall)
                }
                snapshot.peerCameraId?.let {
                    Text("Peer camera ID: $it", style = MaterialTheme.typography.bodySmall)
                }
                snapshot.peerVideoModeId?.let {
                    Text("Peer mode: $it", style = MaterialTheme.typography.bodySmall)
                }
                snapshot.dualCaptureId?.let {
                    Text(
                        "Shared dual_capture_id: $it",
                        style = MaterialTheme.typography.bodySmall,
                    )
                }

                val sync = snapshot.clockSync
                Text(
                    "Clock sync: ${sync.quality.name}",
                    color = sync.quality.displayColor(),
                    style = MaterialTheme.typography.titleSmall,
                )
                Text(
                    "UDP port: ${settings.clockSyncPort} · ${sync.message}",
                    style = MaterialTheme.typography.bodySmall,
                )
                Text(
                    "Bundle transfer port: ${settings.bundleTransferPort}",
                    style = MaterialTheme.typography.bodySmall,
                )
                sync.offsetNs?.let {
                    Text(
                        "Offset Slave−Master: ${formatOffset(it)}",
                        style = MaterialTheme.typography.bodySmall,
                    )
                }
                if (sync.medianRttNs != null || sync.p95RttNs != null) {
                    Text(
                        "RTT median/P95: ${formatMs(sync.medianRttNs)} / " +
                            formatMs(sync.p95RttNs),
                        style = MaterialTheme.typography.bodySmall,
                    )
                }
                sync.uncertaintyNs?.let {
                    Text(
                        "Estimated timing uncertainty: ${formatMs(it)}",
                        style = MaterialTheme.typography.bodySmall,
                    )
                }
                sync.driftPpm?.let {
                    Text(
                        "Clock drift: ${String.format(Locale.US, "%+.2f ppm", it)}",
                        style = MaterialTheme.typography.bodySmall,
                    )
                }
                Text(
                    "Clock samples: ${sync.acceptedSamples}/${sync.totalSamples}",
                    style = MaterialTheme.typography.bodySmall,
                )

                Text(
                    "Recorder preview surface",
                    style = MaterialTheme.typography.titleSmall,
                )
                DualPhoneRecorderPreview(
                    modifier = Modifier
                        .fillMaxWidth()
                        .aspectRatio(16f / 9f),
                )

                val physicalRecordingActive = snapshot.phase in setOf(
                    DualPhoneControlPhase.ARMING,
                    DualPhoneControlPhase.ARMED,
                    DualPhoneControlPhase.START_SCHEDULED,
                    DualPhoneControlPhase.RECORDING,
                )
                if (physicalRecordingActive) {
                    Text(
                        "● REC",
                        color = Color.Red,
                        style = MaterialTheme.typography.titleMedium,
                    )
                }
            }

            if (settings.role == DualPhoneRole.MASTER && snapshot.connected) {
                val canArm = snapshot.phase == DualPhoneControlPhase.CONNECTED
                val canStart = snapshot.phase == DualPhoneControlPhase.ARMED
                val canStop = snapshot.phase in setOf(
                    DualPhoneControlPhase.ARMED,
                    DualPhoneControlPhase.START_SCHEDULED,
                    DualPhoneControlPhase.RECORDING,
                )
                Row(
                    modifier = Modifier.fillMaxWidth(),
                    horizontalArrangement = Arrangement.spacedBy(8.dp),
                ) {
                    Button(
                        onClick = onArm,
                        modifier = Modifier.weight(1f),
                        enabled = canArm,
                    ) {
                        Text("ARM")
                    }
                    Button(
                        onClick = onStartTest,
                        modifier = Modifier.weight(1f),
                        enabled = canStart,
                    ) {
                        Text(
                            if (snapshot.clockSync.captureSchedulingAllowed) {
                                "MARK START +3s"
                            } else {
                                "MARK START NOW (ASYNC)"
                            },
                        )
                    }
                }
                Button(
                    onClick = onStopCapture,
                    modifier = Modifier.fillMaxWidth(),
                    enabled = canStop,
                ) {
                    Text("STOP")
                }
                if (!snapshot.clockSync.captureSchedulingAllowed &&
                    snapshot.phase in setOf(
                        DualPhoneControlPhase.CONNECTED,
                        DualPhoneControlPhase.ARMED,
                        DualPhoneControlPhase.START_SCHEDULED,
                        DualPhoneControlPhase.RECORDING,
                    )
                ) {
                    Text(
                        "ARM is available with ${snapshot.clockSync.quality.name} clock. " +
                            "START will use degraded asynchronous markers and the server " +
                            "must refine the final timeline.",
                        color = Color(0xFFFFA000),
                        style = MaterialTheme.typography.bodySmall,
                    )
                } else if (
                    snapshot.clockSync.quality == DualPhoneClockSyncQuality.FAIR &&
                    snapshot.clockSync.captureSchedulingAllowed &&
                    snapshot.phase == DualPhoneControlPhase.CONNECTED
                ) {
                    Text(
                        "ARM is allowed with stable FAIR clock; frame pairing " +
                            "will use recorded timing metadata.",
                        color = Color(0xFFF9A825),
                        style = MaterialTheme.typography.bodySmall,
                    )
                }
                Text(
                    "DP04.4A3 starts physical pre-roll during ARM regardless of clock " +
                        "quality. START uses the clock model when available or degraded " +
                        "asynchronous markers otherwise. STOP always finalizes both recordings.",
                    style = MaterialTheme.typography.bodySmall,
                )
                val transferBusy = snapshot.aggregateUploadState in setOf(
                    "LOCAL_POST_ROLL_AND_FINALIZE",
                    "WAITING_FOR_MASTER_PACKAGE",
                    "WAITING_FOR_SLAVE_PACKAGE",
                    "TRANSFER_BARRIER_READY",
                    "DOWNLOADING_SLAVE_PACKAGE",
                    "BUILDING_AGGREGATE_BUNDLE",
                    "ENQUEUEING_SERVER_UPLOAD",
                )
                Row(
                    horizontalArrangement = Arrangement.spacedBy(8.dp),
                ) {
                    if (transferBusy) {
                        CircularProgressIndicator(
                            modifier = Modifier.size(20.dp),
                            strokeWidth = 2.dp,
                        )
                    }
                    Column {
                        Text(
                            transferStageLabel(snapshot.aggregateUploadState),
                            style = MaterialTheme.typography.titleSmall,
                        )
                        Text(
                            "Bundle state: ${snapshot.aggregateUploadState}",
                            style = MaterialTheme.typography.bodySmall,
                        )
                    }
                }
                snapshot.localRolePackagePath?.let {
                    Text("Master package: $it", style = MaterialTheme.typography.bodySmall)
                }
                snapshot.peerRolePackagePath?.let {
                    Text("Slave package: $it", style = MaterialTheme.typography.bodySmall)
                }
                snapshot.aggregatePackagePath?.let {
                    Text("Aggregate bundle: $it", style = MaterialTheme.typography.bodySmall)
                }
            }

            if (snapshot.phase != DualPhoneControlPhase.STOPPED) {
                Button(
                    onClick = onDisconnect,
                    modifier = Modifier.fillMaxWidth(),
                ) {
                    Text(
                        if (settings.role == DualPhoneRole.MASTER) {
                            "Stop Master server"
                        } else {
                            "Disconnect"
                        },
                    )
                }
            }
        }
    }
}

@Composable
private fun DualPhoneRecorderPreview(
    modifier: Modifier = Modifier,
) {
    val context = LocalContext.current
    val previewView = remember(context) {
        PreviewView(context).apply {
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

    AndroidView(
        factory = {
            DualPhoneRecorderPreviewRegistry.register(previewView)
            previewView
        },
        update = { DualPhoneRecorderPreviewRegistry.register(it) },
        modifier = modifier,
    )
}

private fun DualPhoneClockSyncQuality.displayColor(): Color = when (this) {
    DualPhoneClockSyncQuality.EXCELLENT,
    DualPhoneClockSyncQuality.GOOD -> Color(0xFF2E7D32)
    DualPhoneClockSyncQuality.FAIR -> Color(0xFFF9A825)
    DualPhoneClockSyncQuality.POOR,
    DualPhoneClockSyncQuality.ERROR -> Color.Red
    DualPhoneClockSyncQuality.UNSYNCED,
    DualPhoneClockSyncQuality.SYNCING -> Color.Gray
}

private fun formatMs(valueNs: Long?): String = valueNs?.let {
    String.format(Locale.US, "%.3f ms", it.toDouble() / 1_000_000.0)
} ?: "—"

private fun formatOffset(valueNs: Long): String {
    val seconds = valueNs.toDouble() / 1_000_000_000.0
    return String.format(Locale.US, "%+.6f s", seconds)
}

private fun transferStageLabel(state: String): String = when (state) {
    "LOCAL_POST_ROLL_AND_FINALIZE" -> "Finalizing local video"
    "WAITING_FOR_MASTER_PACKAGE" -> "Slave package ready; waiting for Master"
    "WAITING_FOR_SLAVE_PACKAGE" -> "Waiting for Slave package"
    "TRANSFER_BARRIER_READY" -> "Both role packages are ready"
    "DOWNLOADING_SLAVE_PACKAGE" -> "Receiving video and telemetry from Slave"
    "BUILDING_AGGREGATE_BUNDLE" -> "Building aggregate bundle"
    "ENQUEUEING_SERVER_UPLOAD" -> "Preparing server upload"
    "QUEUED_FOR_SERVER" -> "Aggregate queued for server"
    "READY_NOT_QUEUED" -> "Aggregate ready; server upload unavailable"
    "TRANSFERRED_TO_MASTER" -> "Slave package verified on Master"
    "TRANSFER_OR_PACKAGING_FAILED" -> "Transfer or packaging failed"
    "SLAVE_FINALIZE_FAILED" -> "Slave finalize failed"
    else -> "Dual-phone bundle"
}
