package com.maklertour.ui.settings

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.material3.Button
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.unit.dp
import com.example.maklertour.data.dualphone.DualPhoneLaptopCameraSlot
import com.example.maklertour.data.dualphone.DualPhoneLaptopUplinkConfig
import com.example.maklertour.data.dualphone.DualPhoneLaptopUplinkRuntime
import com.example.maklertour.data.dualphone.DualPhoneLaptopUplinkState
import com.example.maklertour.data.dualphone.DualPhoneLaptopUplinkSettingsStore
import com.maklertour.data.dualphone.DualPhoneRole
import com.maklertour.data.dualphone.DualPhoneStereoSettings
import java.util.Locale

@Composable
fun DualPhoneLaptopUplinkCard(
    settings: DualPhoneStereoSettings,
) {
    val context = LocalContext.current
    val store = remember {
        DualPhoneLaptopUplinkSettingsStore(context)
    }
    val runtime = remember {
        DualPhoneLaptopUplinkRuntime.get(context)
    }
    val snapshot by runtime.state.collectAsState()
    val initial = remember(settings.masterHost) {
        store.load(settings.masterHost)
    }
    var host by remember(initial) { mutableStateOf(initial.host) }
    var port by remember(initial) { mutableStateOf(initial.port.toString()) }
    var slot by remember(initial) { mutableStateOf(initial.slot) }
    var slotMenuExpanded by remember { mutableStateOf(false) }
    var validationError by remember { mutableStateOf<String?>(null) }

    HorizontalDivider()
    Text(
        "CPU laptop stereo host (LM02.7B.5.4.2)",
        style = MaterialTheme.typography.titleMedium,
    )
    Text(
        "Laptop mode uses two independent camera clients. CAMERA_A automatically " +
            "sends the active MASTER calibration profile; CAMERA_B sends frames and IMU.",
        style = MaterialTheme.typography.bodySmall,
    )

    OutlinedTextField(
        value = host,
        onValueChange = { host = it.trim().take(120) },
        label = { Text("Laptop IP / hostname") },
        singleLine = true,
        modifier = Modifier.fillMaxWidth(),
    )
    Row(
        modifier = Modifier.fillMaxWidth(),
        horizontalArrangement = Arrangement.spacedBy(8.dp),
    ) {
        OutlinedTextField(
            value = port,
            onValueChange = { value ->
                port = value.filter(Char::isDigit).take(5)
            },
            label = { Text("TCP port") },
            singleLine = true,
            modifier = Modifier.weight(1f),
        )
        Column(modifier = Modifier.weight(1f)) {
            Button(
                onClick = { slotMenuExpanded = true },
                modifier = Modifier.fillMaxWidth(),
            ) {
                Text(slot.name)
            }
            DropdownMenu(
                expanded = slotMenuExpanded,
                onDismissRequest = { slotMenuExpanded = false },
            ) {
                DualPhoneLaptopCameraSlot.values().forEach { candidate ->
                    DropdownMenuItem(
                        text = { Text(candidate.name) },
                        onClick = {
                            slot = candidate
                            slotMenuExpanded = false
                        },
                    )
                }
            }
        }
    }

    val localRoleReady = settings.role == DualPhoneRole.SLAVE
    if (!localRoleReady) {
        Text(
            "Transitional requirement: select SLAVE for laptop transport. " +
                "CAMERA_A remains the only calibration authority.",
            color = Color.Red,
            style = MaterialTheme.typography.bodySmall,
        )
    }

    Button(
        onClick = {
            if (
                snapshot.connected ||
                snapshot.state in setOf(
                    DualPhoneLaptopUplinkState.CONNECTING,
                    DualPhoneLaptopUplinkState.HANDSHAKING,
                    DualPhoneLaptopUplinkState.RECONNECTING,
                )
            ) {
                runtime.stop()
                validationError = null
            } else {
                val parsedPort = port.toIntOrNull()
                validationError = when {
                    !localRoleReady -> "Local role must be SLAVE"
                    host.isBlank() -> "Laptop address is required"
                    parsedPort == null || parsedPort !in 1..65535 ->
                        "Port must be 1–65535"
                    else -> null
                }
                if (validationError == null) {
                    val config = DualPhoneLaptopUplinkConfig(
                        host = host.trim(),
                        port = requireNotNull(parsedPort),
                        slot = slot,
                    )
                    store.save(config)
                    validationError = runCatching {
                        runtime.start(config)
                    }.exceptionOrNull()?.let { error ->
                        error.message ?: error.javaClass.simpleName
                    }
                }
            }
        },
        enabled = localRoleReady,
        modifier = Modifier.fillMaxWidth(),
    ) {
        Text(
            if (
                snapshot.state == DualPhoneLaptopUplinkState.STOPPED ||
                snapshot.state == DualPhoneLaptopUplinkState.FAILED
            ) {
                "Connect phone to laptop"
            } else {
                "Disconnect from laptop"
            },
        )
    }

    validationError?.let {
        Text(it, color = Color.Red, style = MaterialTheme.typography.bodySmall)
    }
    snapshot.lastError?.let {
        Text(it, color = Color.Red, style = MaterialTheme.typography.bodySmall)
    }

    Text(
        "State: ${snapshot.state.name} · ${snapshot.slot.name} · " +
            "${snapshot.framesSent}/${snapshot.framesOffered} frames · " +
            "${String.format(Locale.US, "%.1f", snapshot.clockRttMs)} ms RTT",
        style = MaterialTheme.typography.bodySmall,
    )
    Text(
        "Producer ${snapshot.producer.state.name} " +
            "${snapshot.producer.encodedWidth}×${snapshot.producer.encodedHeight} · " +
            "replaced ${snapshot.framesReplacedBeforeSend} · " +
            "IMU ${snapshot.imuPacketsSent}",
        style = MaterialTheme.typography.bodySmall,
    )
}
