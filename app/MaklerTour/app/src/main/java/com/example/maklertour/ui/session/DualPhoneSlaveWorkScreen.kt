package com.example.maklertour.ui.session

import androidx.compose.foundation.Canvas
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.unit.dp
import com.example.maklertour.data.dualphone.DualPhoneApplicationRuntimeSnapshot

/**
 * Locked application surface shown while MASTER owns the SLAVE work mode.
 *
 * LM01A-4 intentionally renders a structural guide and transport diagnostics only.
 * CameraX preview/reduced-frame overlays are attached by the following LM01A slice.
 */
@Composable
fun DualPhoneSlaveWorkScreen(
    snapshot: DualPhoneApplicationRuntimeSnapshot,
    onEmergencyDisconnect: () -> Unit,
    modifier: Modifier = Modifier,
) {
    Surface(modifier = modifier.fillMaxSize()) {
        Box(modifier = Modifier.fillMaxSize()) {
            SlaveStructureGuide(
                modifier = Modifier.fillMaxSize(),
            )
            Column(
                modifier = Modifier
                    .fillMaxSize()
                    .padding(20.dp),
                verticalArrangement = Arrangement.SpaceBetween,
            ) {
                Column(
                    verticalArrangement = Arrangement.spacedBy(8.dp),
                ) {
                    Text(
                        text = "SLAVE · УПРАВЛЯЕТСЯ MASTER",
                        style = MaterialTheme.typography.headlineSmall,
                    )
                    Text("Режим: ${snapshot.applicationMode.name}")
                    Text(
                        "Сессия: ${snapshot.sessionUuid ?: "не определена"}",
                    )
                }

                Card(modifier = Modifier.fillMaxWidth()) {
                    Column(
                        modifier = Modifier.padding(16.dp),
                        verticalArrangement = Arrangement.spacedBy(6.dp),
                    ) {
                        Text("Рабочая структура SLAVE")
                        Text(
                            "Control: ${snapshot.controlPhase.name} · " +
                                if (snapshot.controlConnected) {
                                    "CONNECTED"
                                } else {
                                    "DISCONNECTED"
                                },
                        )
                        Text(
                            "Data channel: ${snapshot.dataChannel.state.name} · " +
                                "TCP/${snapshot.dataChannel.port}",
                        )
                        Text(
                            "Peer: ${snapshot.peerDeviceId ?: "не определён"}",
                        )
                        Text(snapshot.lastMessage)
                        snapshot.lastError?.let {
                            Text("Ошибка: $it")
                        }
                    }
                }

                Row(
                    modifier = Modifier.fillMaxWidth(),
                    horizontalArrangement = Arrangement.Center,
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Button(onClick = onEmergencyDisconnect) {
                        Text("Аварийно отключить SLAVE")
                    }
                }
            }
        }
    }
}

@Composable
private fun SlaveStructureGuide(modifier: Modifier = Modifier) {
    val lineColor = MaterialTheme.colorScheme.outline.copy(alpha = 0.35f)
    val accentColor = MaterialTheme.colorScheme.primary.copy(alpha = 0.55f)
    Canvas(modifier = modifier) {
        val stepX = size.width / 6f
        val stepY = size.height / 10f
        for (column in 1 until 6) {
            val x = stepX * column
            drawLine(
                color = lineColor,
                start = Offset(x, 0f),
                end = Offset(x, size.height),
                strokeWidth = 1f,
            )
        }
        for (row in 1 until 10) {
            val y = stepY * row
            drawLine(
                color = lineColor,
                start = Offset(0f, y),
                end = Offset(size.width, y),
                strokeWidth = 1f,
            )
        }
        val center = Offset(size.width / 2f, size.height / 2f)
        val radius = size.minDimension * 0.12f
        drawCircle(
            color = accentColor,
            radius = radius,
            center = center,
            style = Stroke(width = 3f),
        )
        drawLine(
            color = accentColor,
            start = Offset(center.x - radius * 1.4f, center.y),
            end = Offset(center.x + radius * 1.4f, center.y),
            strokeWidth = 3f,
        )
        drawLine(
            color = accentColor,
            start = Offset(center.x, center.y - radius * 1.4f),
            end = Offset(center.x, center.y + radius * 1.4f),
            strokeWidth = 3f,
        )
    }
}
