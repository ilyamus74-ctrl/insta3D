package com.maklertour.ui.settings

import android.app.Activity
import android.content.Context
import android.content.ContextWrapper
import android.content.pm.ActivityInfo
import android.view.View
import android.view.ViewGroup
import androidx.camera.view.PreviewView
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
import androidx.compose.runtime.rememberUpdatedState
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.unit.dp
import androidx.compose.ui.viewinterop.AndroidView
import androidx.compose.ui.window.Dialog
import androidx.compose.ui.window.DialogProperties
import androidx.lifecycle.compose.LocalLifecycleOwner
import com.maklertour.data.dualphone.DualPhoneControlSnapshot
import com.maklertour.data.dualphone.DualPhoneRole
import com.maklertour.data.phonecamera.DualPhonePreviewBindingRuntime
import com.maklertour.data.phonecamera.DualPhoneRecorderPreviewRegistry

@Composable
internal fun DualPhoneCalibrationFullscreen(
    snapshot: DualPhoneControlSnapshot,
    role: DualPhoneRole,
    onExit: () -> Unit,
) {
    val context = LocalContext.current
    val activity = remember(context) { context.findActivity() }
    var previewStatus by remember(snapshot.calibrationRunId) {
        mutableStateOf("Opening selected camera…")
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
                modifier = Modifier.fillMaxSize(),
                onStatus = { previewStatus = it },
            )

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
                    .fillMaxWidth(0.58f),
                colors = CardDefaults.cardColors(
                    containerColor = Color(0xCC111111),
                    contentColor = Color.White,
                ),
            ) {
                Column(
                    modifier = Modifier.padding(16.dp),
                    verticalArrangement = Arrangement.spacedBy(6.dp),
                ) {
                    Text(
                        "CAL01A · ${role.name}",
                        style = MaterialTheme.typography.titleMedium,
                    )
                    Text(
                        snapshot.calibrationInstruction,
                        style = MaterialTheme.typography.headlineSmall,
                    )
                    Text(
                        "Accepted poses: ${snapshot.calibrationAcceptedPoseCount}/" +
                            snapshot.calibrationTargetPoseCount,
                    )
                    Text(
                        "Run: ${snapshot.calibrationRunId ?: "—"}",
                        style = MaterialTheme.typography.bodySmall,
                    )
                    Text(
                        if (role == DualPhoneRole.MASTER) {
                            "Master coordinates both previews"
                        } else {
                            "Follow the instruction shown by Master"
                        },
                        style = MaterialTheme.typography.bodySmall,
                    )
                    Text(
                        "Preview: $previewStatus",
                        style = MaterialTheme.typography.bodySmall,
                    )
                    Text(
                        "Realtime ChArUco detection and automatic pose acceptance " +
                            "will be enabled in CAL01B.",
                        color = Color(0xFFFFCC80),
                        style = MaterialTheme.typography.bodySmall,
                    )
                }
            }

            Row(
                modifier = Modifier
                    .align(Alignment.BottomEnd)
                    .safeDrawingPadding()
                    .padding(20.dp),
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
                Button(onClick = onExit) {
                    Text("Завершить калибровку")
                }
            }
        }
    }
}

@Composable
private fun CalibrationPreview(
    modifier: Modifier = Modifier,
    onStatus: (String) -> Unit,
) {
    val context = LocalContext.current
    val lifecycleOwner = LocalLifecycleOwner.current
    val currentOnStatus by rememberUpdatedState(onStatus)
    val previewView = remember(context) {
        (DualPhoneRecorderPreviewRegistry.current() ?: PreviewView(context)).apply {
            implementationMode = PreviewView.ImplementationMode.COMPATIBLE
            scaleType = PreviewView.ScaleType.FILL_CENTER
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
    AndroidView(
        factory = {
            (previewView.parent as? ViewGroup)?.removeView(previewView)
            DualPhoneRecorderPreviewRegistry.register(previewView)
            previewView
        },
        update = { DualPhoneRecorderPreviewRegistry.register(it) },
        modifier = modifier,
    )
}

private tailrec fun Context.findActivity(): Activity? = when (this) {
    is Activity -> this
    is ContextWrapper -> baseContext.findActivity()
    else -> null
}
