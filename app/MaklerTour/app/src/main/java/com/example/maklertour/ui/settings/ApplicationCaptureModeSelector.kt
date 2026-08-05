package com.maklertour.ui.settings

import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.unit.dp
import com.maklertour.data.dualphone.ApplicationCaptureMode
import com.maklertour.data.dualphone.DualPhoneStereoSettings
import com.maklertour.data.dualphone.DualPhoneStereoSettingsStore

@Composable
internal fun ApplicationCaptureModeSelector(
    settings: DualPhoneStereoSettings,
    onModeSelected: (DualPhoneStereoSettings) -> Unit,
) {
    val context = LocalContext.current
    val settingsStore = remember(context.applicationContext) {
        DualPhoneStereoSettingsStore(context.applicationContext)
    }
    var expanded by remember { mutableStateOf(false) }
    val selectedMode = settings.applicationMode

    Card(modifier = Modifier.fillMaxWidth()) {
        Column(modifier = Modifier.padding(12.dp)) {
            Text(
                "Режим работы приложения",
                style = MaterialTheme.typography.titleMedium,
            )
            Button(
                onClick = { expanded = true },
                modifier = Modifier.fillMaxWidth(),
            ) {
                Text(selectedMode.displayNameRu())
            }
            DropdownMenu(
                expanded = expanded,
                onDismissRequest = { expanded = false },
            ) {
                ApplicationCaptureMode.entries.forEach { candidate ->
                    DropdownMenuItem(
                        text = { Text(candidate.displayNameRu()) },
                        onClick = {
                            val updated = settingsStore.load()
                                .withApplicationMode(candidate)
                            settingsStore.save(updated)
                            onModeSelected(updated)
                            expanded = false
                        },
                    )
                }
            }
            Text(
                selectedMode.descriptionRu(),
                style = MaterialTheme.typography.bodySmall,
            )
            Text(
                "Показываются только настройки выбранного режима.",
                style = MaterialTheme.typography.bodySmall,
            )
        }
    }
}

private fun ApplicationCaptureMode.displayNameRu(): String = when (this) {
    ApplicationCaptureMode.STANDALONE_COLMAP ->
        "1. Автономная съёмка — COLMAP/DENSE"
    ApplicationCaptureMode.DUAL_PHONE_MASTER ->
        "2. Два телефона — MASTER"
    ApplicationCaptureMode.DUAL_PHONE_SLAVE ->
        "3. Два телефона — SLAVE"
    ApplicationCaptureMode.LAPTOP_STEREO_CLIENT ->
        "4. Два телефона → ноутбук/ПК"
    ApplicationCaptureMode.PHONE_USB_STEREO ->
        "5. Телефон + USB-камера"
}

private fun ApplicationCaptureMode.descriptionRu(): String = when (this) {
    ApplicationCaptureMode.STANDALONE_COLMAP ->
        "Запись видео для последующей обработки COLMAP и DENSE."
    ApplicationCaptureMode.DUAL_PHONE_MASTER ->
        "MASTER управляет вторым телефоном и считает стереоглубину локально."
    ApplicationCaptureMode.DUAL_PHONE_SLAVE ->
        "Телефон подчиняется MASTER и передаёт ему синхронизированные кадры."
    ApplicationCaptureMode.LAPTOP_STEREO_CLIENT ->
        "Оба телефона передают кадры ноутбуку как CAMERA_A и CAMERA_B."
    ApplicationCaptureMode.PHONE_USB_STEREO ->
        "Камера телефона и USB-камера образуют локальную стереопару."
}
