package com.maklertour.ui.settings

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.unit.dp
import com.maklertour.data.dualphone.DualPhoneControlPhase
import com.maklertour.data.dualphone.DualPhoneControlSnapshot
import com.maklertour.data.dualphone.DualPhoneRole
import com.maklertour.data.dualphone.DualPhoneStereoSettings

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
    Card(modifier = Modifier.fillMaxWidth()) {
        Column(
            modifier = Modifier.padding(12.dp),
            verticalArrangement = Arrangement.spacedBy(8.dp),
        ) {
            Text(
                "Dual-phone Wi-Fi control (DP02)",
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
                        Text("START +3s")
                    }
                }
                Button(
                    onClick = onStopCapture,
                    modifier = Modifier.fillMaxWidth(),
                    enabled = canStop,
                ) {
                    Text("STOP")
                }
                Text(
                    "DP02 changes protocol state only. Camera recording is connected in DP04.",
                    style = MaterialTheme.typography.bodySmall,
                )
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
