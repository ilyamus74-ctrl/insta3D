package com.example.maklertour.ui.session

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
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
    Surface(
        modifier = modifier,
        color = Color.Black.copy(alpha = 0.72f),
        shape = RoundedCornerShape(10.dp),
    ) {
        Column(
            modifier = Modifier.padding(horizontal = 8.dp, vertical = 6.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            Text(
                text = "DEPTH ${selected.shortLabel} · $activeProfile",
                color = Color.White,
                fontWeight = FontWeight.Bold,
            )
            Row(
                modifier = Modifier
                    .padding(top = 4.dp)
                    .horizontalScroll(rememberScrollState()),
            ) {
                DualPhoneDepthProfileMode.values().forEach { mode ->
                    val selectedBackground = if (mode == selected) {
                        Color(0xFF6C5CE7).copy(alpha = 0.92f)
                    } else {
                        Color.White.copy(alpha = 0.10f)
                    }
                    Text(
                        text = mode.shortLabel,
                        modifier = Modifier
                            .padding(horizontal = 2.dp)
                            .background(
                                color = selectedBackground,
                                shape = RoundedCornerShape(8.dp),
                            )
                            .clickable {
                                DualPhoneDepthProfileSelection.select(mode)
                            }
                            .padding(horizontal = 9.dp, vertical = 6.dp),
                        color = Color.White,
                        fontWeight = if (mode == selected) {
                            FontWeight.Bold
                        } else {
                            FontWeight.Normal
                        },
                    )
                }
            }
        }
    }
}
