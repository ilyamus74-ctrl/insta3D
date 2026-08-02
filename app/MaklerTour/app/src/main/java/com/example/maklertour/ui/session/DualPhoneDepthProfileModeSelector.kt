package com.example.maklertour.ui.session

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.runtime.collectAsState
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import com.example.maklertour.data.dualphone.DualPhoneDepthProfileMode
import com.example.maklertour.data.dualphone.DualPhoneDepthProfileSelection

@Composable
internal fun DualPhoneDepthProfileModeSelector(
    activeProfile: String,
    modifier: Modifier = Modifier,
) {
    val selected by DualPhoneDepthProfileSelection.state.collectAsState()
    var expanded by remember { mutableStateOf(false) }

    Box(modifier = modifier) {
        Surface(
            modifier = Modifier.clickable { expanded = true },
            color = Color.Black.copy(alpha = 0.78f),
            shape = RoundedCornerShape(10.dp),
        ) {
            Row(
                modifier = Modifier.padding(horizontal = 10.dp, vertical = 7.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Text(
                    text = "☰",
                    modifier = Modifier.padding(end = 7.dp),
                    color = Color.White,
                    fontWeight = FontWeight.Bold,
                )
                Column {
                    Text(
                        text = "DEPTH ${selected.shortLabel} → $activeProfile",
                        color = Color.White,
                        fontWeight = FontWeight.Bold,
                    )
                    if (
                        selected.requestedProfileName != null &&
                        selected.requestedProfileName != activeProfile
                    ) {
                        Text(
                            text = "thermal/runtime override",
                            color = Color.White.copy(alpha = 0.70f),
                        )
                    }
                }
            }
        }

        DropdownMenu(
            expanded = expanded,
            onDismissRequest = { expanded = false },
        ) {
            DualPhoneDepthProfileMode.values().forEach { mode ->
                DropdownMenuItem(
                    text = {
                        Column {
                            Text(
                                text = buildString {
                                    if (mode == selected) append("✓ ")
                                    append(profileTitle(mode))
                                },
                                fontWeight = if (mode == selected) {
                                    FontWeight.Bold
                                } else {
                                    FontWeight.Normal
                                },
                            )
                            Text(
                                text = profileDescription(mode),
                                color = Color.Gray,
                            )
                        }
                    },
                    onClick = {
                        DualPhoneDepthProfileSelection.select(mode)
                        expanded = false
                    },
                )
            }
        }
    }
}

private fun profileTitle(mode: DualPhoneDepthProfileMode): String = when (mode) {
    DualPhoneDepthProfileMode.AUTO -> "AUTO"
    DualPhoneDepthProfileMode.MANUAL_ULTRA_960 -> "ULTRA 960"
    DualPhoneDepthProfileMode.MANUAL_HIGH_640 -> "HIGH 640"
    DualPhoneDepthProfileMode.MANUAL_QUALITY_480 -> "QUALITY 480"
    DualPhoneDepthProfileMode.MANUAL_BALANCED_320 -> "BALANCED 320"
}

private fun profileDescription(mode: DualPhoneDepthProfileMode): String = when (mode) {
    DualPhoneDepthProfileMode.AUTO -> "Automatic performance fallback"
    DualPhoneDepthProfileMode.MANUAL_ULTRA_960 -> "Maximum detail; slideshow is allowed"
    DualPhoneDepthProfileMode.MANUAL_HIGH_640 -> "High detail"
    DualPhoneDepthProfileMode.MANUAL_QUALITY_480 -> "Balanced detail and speed"
    DualPhoneDepthProfileMode.MANUAL_BALANCED_320 -> "Fast preview"
}
