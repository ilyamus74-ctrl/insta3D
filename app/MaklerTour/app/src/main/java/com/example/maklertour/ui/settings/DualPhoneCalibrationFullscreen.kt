package com.maklertour.ui.settings

import android.app.Activity
import android.content.Context
import android.content.ContextWrapper
import android.content.pm.ActivityInfo
import android.view.View
import android.view.ViewGroup
import androidx.camera.view.PreviewView
import androidx.compose.foundation.Canvas
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
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.unit.dp
import androidx.compose.ui.viewinterop.AndroidView
import androidx.compose.ui.window.Dialog
import androidx.compose.ui.window.DialogProperties
import androidx.lifecycle.compose.LocalLifecycleOwner
import com.maklertour.data.calibration.DualPhoneCalibrationCaptureStore
import com.maklertour.data.calibration.DualPhoneCalibrationRealtimeAnalyzer
import com.maklertour.data.calibration.DualPhoneCalibrationRealtimeResult
import com.maklertour.data.dualphone.DualPhoneCalibrationObservation
import com.maklertour.data.dualphone.DualPhoneCalibrationPosePlan
import com.maklertour.data.dualphone.DualPhoneControlManager
import com.maklertour.data.dualphone.DualPhoneControlSnapshot
import com.maklertour.data.dualphone.DualPhoneRole
import com.maklertour.data.dualphone.DualPhoneStereoSettingsStore
import com.maklertour.data.phonecamera.CalibrationFrame
import com.maklertour.data.phonecamera.DualPhonePreviewBindingRuntime
import com.maklertour.data.phonecamera.DualPhoneRecorderPreviewRegistry
import com.maklertour.data.rig.CalibrationSettings
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.delay
import kotlinx.coroutines.isActive
import kotlinx.coroutines.withContext
import java.util.Locale
import kotlin.math.min

@Composable
internal fun DualPhoneCalibrationFullscreen(
    snapshot: DualPhoneControlSnapshot,
    role: DualPhoneRole,
    onExit: () -> Unit,
) {
    val context = LocalContext.current
    val activity = remember(context) { context.findActivity() }
    val controlManager = remember(context) {
        DualPhoneControlManager.get(context.applicationContext)
    }
    val captureStore = remember(context) {
        DualPhoneCalibrationCaptureStore(context.applicationContext)
    }
    val localDeviceId = remember(snapshot.calibrationRunId) {
        DualPhoneStereoSettingsStore(context.applicationContext).load().deviceId
    }
    val boardSettings = remember {
        CalibrationSettings(
            checkerboardInnerCols = 9,
            checkerboardInnerRows = 6,
            squareSizeMm = 22.0,
            requiredPairs = DualPhoneCalibrationPosePlan.size,
        )
    }
    val analyzer = remember(snapshot.calibrationRunId) {
        DualPhoneCalibrationRealtimeAnalyzer()
    }
    val target = DualPhoneCalibrationPosePlan.byId(snapshot.calibrationTargetPoseId)
    var previewStatus by remember(snapshot.calibrationRunId) {
        mutableStateOf("Opening selected camera…")
    }
    var localAnalysis by remember(snapshot.calibrationRunId) {
        mutableStateOf<DualPhoneCalibrationRealtimeResult?>(null)
    }
    var persistenceStatus by remember(snapshot.calibrationRunId) {
        mutableStateOf("Waiting for the first accepted pose")
    }
    var lastPersistedAcceptanceSerial by remember(snapshot.calibrationRunId) {
        mutableStateOf(0L)
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

    LaunchedEffect(
        snapshot.calibrationRunId,
        snapshot.calibrationTargetPoseId,
        snapshot.calibrationActive,
    ) {
        analyzer.reset()
        localAnalysis = null
        var lastSequence = -1L
        while (isActive && snapshot.calibrationActive) {
            val frame = DualPhonePreviewBindingRuntime.latestCalibrationFrame()
            if (frame != null && frame.sequence != lastSequence) {
                lastSequence = frame.sequence
                val result = withContext(Dispatchers.Default) {
                    analyzer.analyze(
                        frame = frame,
                        target = target,
                        settings = boardSettings,
                    )
                }
                localAnalysis = result
                snapshot.calibrationRunId?.let { runId ->
                    controlManager.reportCalibrationObservation(
                        result.toObservation(
                            calibrationRunId = runId,
                            poseId = target.id,
                        ),
                    )
                }
            }
            delay(80L)
        }
    }

    LaunchedEffect(
        snapshot.calibrationAcceptanceSerial,
        snapshot.calibrationLastAcceptedLocalFrameSequence,
    ) {
        val serial = snapshot.calibrationAcceptanceSerial
        val sequence = snapshot.calibrationLastAcceptedLocalFrameSequence
        val runId = snapshot.calibrationRunId
        val poseId = snapshot.calibrationLastAcceptedPoseId
        val poseIndex = snapshot.calibrationLastAcceptedPoseIndex
        if (
            serial <= lastPersistedAcceptanceSerial ||
            sequence == null ||
            runId.isNullOrBlank() ||
            poseId.isNullOrBlank() ||
            poseIndex == null
        ) {
            return@LaunchedEffect
        }

        var acceptedFrame: CalibrationFrame? = null
        repeat(20) {
            if (acceptedFrame == null) {
                acceptedFrame = DualPhonePreviewBindingRuntime.calibrationFrame(sequence)
                if (acceptedFrame == null) delay(50L)
            }
        }
        val frame = acceptedFrame
        if (frame == null) {
            persistenceStatus = "Accepted frame $sequence was not found in the local ring buffer"
            return@LaunchedEffect
        }

        val qualityObservation: DualPhoneCalibrationObservation? = localAnalysis
            ?.takeIf { it.frameSequence == sequence }
            ?.toObservation(runId, poseId)
        runCatching {
            withContext(Dispatchers.IO) {
                captureStore.saveAcceptedFrame(
                    calibrationRunId = runId,
                    deviceId = localDeviceId,
                    poseIndex = poseIndex,
                    poseId = poseId,
                    frame = frame,
                    observation = qualityObservation,
                )
            }
        }.onSuccess { file ->
            lastPersistedAcceptanceSerial = serial
            persistenceStatus = "Saved raw intrinsics sample: ${file.name}"
        }.onFailure { error ->
            persistenceStatus = "Failed to save accepted sample: " +
                (error.message ?: error.javaClass.simpleName)
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
                analysis = localAnalysis,
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
                    .fillMaxWidth(0.66f),
                colors = CardDefaults.cardColors(
                    containerColor = Color(0xCC111111),
                    contentColor = Color.White,
                ),
            ) {
                Column(
                    modifier = Modifier.padding(16.dp),
                    verticalArrangement = Arrangement.spacedBy(5.dp),
                ) {
                    Text(
                        "CAL01B · ${role.name}",
                        style = MaterialTheme.typography.titleMedium,
                    )
                    Text(
                        snapshot.calibrationInstruction,
                        style = MaterialTheme.typography.headlineSmall,
                    )
                    Text(
                        "Target ${target.index + 1}/${snapshot.calibrationTargetPoseCount}: " +
                            target.id,
                        style = MaterialTheme.typography.bodySmall,
                    )
                    Text(
                        "Accepted poses: ${snapshot.calibrationAcceptedPoseCount}/" +
                            snapshot.calibrationTargetPoseCount,
                    )
                    Text(
                        localQualityLine(localAnalysis),
                        color = qualityColor(localAnalysis?.qualityReady == true),
                        style = MaterialTheme.typography.bodySmall,
                    )
                    Text(
                        peerQualityLine(role, snapshot.calibrationPeerObservation),
                        color = qualityColor(
                            snapshot.calibrationPeerObservation?.qualityReady == true,
                        ),
                        style = MaterialTheme.typography.bodySmall,
                    )
                    localAnalysis?.let { analysis ->
                        Text(
                            "corners ${analysis.detection.cornersFound}/" +
                                "${analysis.detection.expectedCorners} · " +
                                "sharpness ${formatOne(analysis.sharpnessScore)} · " +
                                "luma ${formatOne(analysis.meanLuma)} · " +
                                "stable ${analysis.stableMs} ms",
                            style = MaterialTheme.typography.bodySmall,
                        )
                        Text(
                            "area ${formatThree(analysis.boardAreaFraction)} · " +
                                "roll ${formatOne(analysis.rollDegrees)}° · " +
                                "yaw ${formatThree(analysis.yawSkew)} · " +
                                "pitch ${formatThree(analysis.pitchSkew)}",
                            style = MaterialTheme.typography.bodySmall,
                        )
                    }
                    Text(
                        "Preview: $previewStatus",
                        style = MaterialTheme.typography.bodySmall,
                    )
                    Text(
                        persistenceStatus,
                        color = Color(0xFFFFCC80),
                        style = MaterialTheme.typography.bodySmall,
                    )
                    if (snapshot.calibrationCollectionComplete) {
                        Text(
                            "CAL01B complete on both phones. Raw accepted frames are ready " +
                                "for separate per-camera intrinsics solving in CAL01C.",
                            color = Color(0xFF7CFC98),
                            style = MaterialTheme.typography.bodySmall,
                        )
                    }
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
    analysis: DualPhoneCalibrationRealtimeResult?,
    modifier: Modifier = Modifier,
    onStatus: (String) -> Unit,
) {
    val context = LocalContext.current
    val lifecycleOwner = LocalLifecycleOwner.current
    val currentOnStatus by rememberUpdatedState(onStatus)
    val previewView = remember(context) {
        (DualPhoneRecorderPreviewRegistry.current() ?: PreviewView(context)).apply {
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

    Box(modifier = modifier.background(Color.Black)) {
        AndroidView(
            factory = {
                (previewView.parent as? ViewGroup)?.removeView(previewView)
                DualPhoneRecorderPreviewRegistry.register(previewView)
                previewView
            },
            update = { DualPhoneRecorderPreviewRegistry.register(it) },
            modifier = Modifier.fillMaxSize(),
        )
        CalibrationCornerOverlay(
            analysis = analysis,
            modifier = Modifier.fillMaxSize(),
        )
    }
}

@Composable
private fun CalibrationCornerOverlay(
    analysis: DualPhoneCalibrationRealtimeResult?,
    modifier: Modifier = Modifier,
) {
    Canvas(modifier = modifier) {
        val result = analysis ?: return@Canvas
        val width = result.detection.imageWidth
        val height = result.detection.imageHeight
        if (width <= 0 || height <= 0) return@Canvas
        val overlayColor = if (result.qualityReady) {
            Color(0xFF00E676)
        } else {
            Color(0xFFFFD54F)
        }
        val radius = min(size.width, size.height) * 0.008f
        result.detection.normalizedCornerPoints.forEach { point ->
            val mapped = mapRawPointToFitPreview(
                normalizedX = point.x,
                normalizedY = point.y,
                rawWidth = width,
                rawHeight = height,
                rotationDegrees = result.imageProxyRotationDegrees,
                viewportWidth = size.width,
                viewportHeight = size.height,
            )
            drawCircle(
                color = overlayColor,
                radius = radius.coerceAtLeast(4f),
                center = mapped,
                style = Stroke(width = 2.5f),
            )
        }
    }
}

private fun mapRawPointToFitPreview(
    normalizedX: Float,
    normalizedY: Float,
    rawWidth: Int,
    rawHeight: Int,
    rotationDegrees: Int,
    viewportWidth: Float,
    viewportHeight: Float,
): Offset {
    val rotation = ((rotationDegrees % 360) + 360) % 360
    val rotatedX: Float
    val rotatedY: Float
    val sourceWidth: Float
    val sourceHeight: Float
    when (rotation) {
        90 -> {
            rotatedX = 1f - normalizedY
            rotatedY = normalizedX
            sourceWidth = rawHeight.toFloat()
            sourceHeight = rawWidth.toFloat()
        }
        180 -> {
            rotatedX = 1f - normalizedX
            rotatedY = 1f - normalizedY
            sourceWidth = rawWidth.toFloat()
            sourceHeight = rawHeight.toFloat()
        }
        270 -> {
            rotatedX = normalizedY
            rotatedY = 1f - normalizedX
            sourceWidth = rawHeight.toFloat()
            sourceHeight = rawWidth.toFloat()
        }
        else -> {
            rotatedX = normalizedX
            rotatedY = normalizedY
            sourceWidth = rawWidth.toFloat()
            sourceHeight = rawHeight.toFloat()
        }
    }

    val scale = min(
        viewportWidth / sourceWidth.coerceAtLeast(1f),
        viewportHeight / sourceHeight.coerceAtLeast(1f),
    )
    val renderedWidth = sourceWidth * scale
    val renderedHeight = sourceHeight * scale
    val offsetX = (viewportWidth - renderedWidth) / 2f
    val offsetY = (viewportHeight - renderedHeight) / 2f
    return Offset(
        x = offsetX + rotatedX.coerceIn(0f, 1f) * renderedWidth,
        y = offsetY + rotatedY.coerceIn(0f, 1f) * renderedHeight,
    )
}

private fun localQualityLine(
    analysis: DualPhoneCalibrationRealtimeResult?,
): String = when {
    analysis == null -> "Local: waiting for analysis frame"
    analysis.qualityReady -> "Local: READY"
    else -> "Local: ${analysis.status}"
}

private fun peerQualityLine(
    role: DualPhoneRole,
    observation: DualPhoneCalibrationObservation?,
): String = when {
    role == DualPhoneRole.SLAVE ->
        "Peer: Master validates both phones and advances the shared pose"
    observation == null -> "Peer: waiting for Slave observation"
    observation.qualityReady -> "Peer: READY"
    else -> "Peer: ${observation.status}"
}

private fun qualityColor(ready: Boolean): Color =
    if (ready) Color(0xFF7CFC98) else Color(0xFFFFD166)

private fun formatOne(value: Double): String =
    String.format(Locale.US, "%.1f", value)

private fun formatThree(value: Double): String =
    String.format(Locale.US, "%.3f", value)

private tailrec fun Context.findActivity(): Activity? = when (this) {
    is Activity -> this
    is ContextWrapper -> baseContext.findActivity()
    else -> null
}
