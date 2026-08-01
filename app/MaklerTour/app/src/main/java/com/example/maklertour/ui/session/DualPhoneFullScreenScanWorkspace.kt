package com.example.maklertour.ui.session

import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.graphics.Paint
import android.graphics.Rect
import android.graphics.RectF
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.background
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.BoxWithConstraints
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Button
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.drawscope.drawIntoCanvas
import androidx.compose.ui.graphics.nativeCanvas
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.window.Dialog
import androidx.compose.ui.window.DialogProperties
import com.example.maklertour.data.dualphone.DualPhoneApplicationRuntime
import com.example.maklertour.data.dualphone.DualPhoneApplicationRuntimeSnapshot
import com.example.maklertour.data.dualphone.DualPhoneLiveDepthProcessor
import com.example.maklertour.data.dualphone.DualPhoneLiveDepthSnapshot
import com.example.maklertour.data.dualphone.DualPhoneLiveStreamMode
import com.example.maklertour.data.dualphone.DualPhoneReducedFrame
import com.maklertour.data.dualphone.DualPhoneControlManager
import java.util.Locale

enum class DualPhoneMasterScanView(val label: String) {
    MASTER("MASTER"),
    SLAVE("SLAVE"),
    SPLIT("SPLIT"),
    OVERLAY("OUTLINE"),
    DEPTH("RAW"),
    FILTERED("DENSE"),
    STRICT("STRICT"),
    CONFIDENCE("CONF"),
}

/**
 * LM02 full-screen MASTER scan surface.
 *
 * LIVE/HYBRID remains owned by DualPhoneApplicationRuntime. Closing this dialog
 * only hides the workspace; the explicit STOP action returns both phones to
 * passive WORK_APP.
 */
@Composable
fun DualPhoneMasterScanDialog(
    snapshot: DualPhoneApplicationRuntimeSnapshot,
    selectedSessionId: String?,
    applicationRuntime: DualPhoneApplicationRuntime,
    onDismiss: () -> Unit,
) {
    val context = LocalContext.current
    val appContext = context.applicationContext
    val controlManager = remember(appContext) {
        DualPhoneControlManager.get(appContext)
    }
    val controlSnapshot by controlManager.state.collectAsState()
    val depthProcessor = remember(appContext) {
        DualPhoneLiveDepthProcessor(appContext)
    }
    val depth by depthProcessor.state.collectAsState()
    var view by remember { mutableStateOf(DualPhoneMasterScanView.OVERLAY) }

    DisposableEffect(depthProcessor) {
        onDispose { depthProcessor.close() }
    }
    LaunchedEffect(
        snapshot.localFrameProducer.latestFrame?.frameSequence,
        snapshot.mediaTransport.latestFrame?.frameSequence,
        snapshot.sessionUuid,
        controlSnapshot.clockSync.updatedAtElapsedNs,
        controlSnapshot.clockSync.ready,
    ) {
        if (snapshot.requestedMode.streamEnabled) {
            depthProcessor.submit(
                masterFrame = snapshot.localFrameProducer.latestFrame,
                slaveFrame = snapshot.mediaTransport.latestFrame,
                clockSync = controlSnapshot.clockSync,
            )
        } else {
            depthProcessor.reset()
        }
    }

    Dialog(
        onDismissRequest = onDismiss,
        properties = DialogProperties(
            usePlatformDefaultWidth = false,
            dismissOnBackPress = true,
            dismissOnClickOutside = false,
        ),
    ) {
        Surface(
            modifier = Modifier.fillMaxSize(),
            color = Color.Black,
        ) {
            Box(modifier = Modifier.fillMaxSize()) {
                MasterScanViewport(
                    view = view,
                    snapshot = snapshot,
                    depth = depth,
                    modifier = Modifier.fillMaxSize(),
                )
                MasterStatusOverlay(
                    snapshot = snapshot,
                    depth = depth,
                    clockReady = controlSnapshot.clockSync.ready,
                    modifier = Modifier
                        .align(Alignment.TopStart)
                        .padding(12.dp),
                )
                MasterScanControls(
                    selectedView = view,
                    selectedMode = snapshot.requestedMode,
                    onView = { view = it },
                    onLive = {
                        applicationRuntime.enterWorkMode(
                            selectedSessionId,
                            DualPhoneLiveStreamMode.LIVE_METRIC,
                        )
                    },
                    onHybrid = {
                        applicationRuntime.enterWorkMode(
                            selectedSessionId,
                            DualPhoneLiveStreamMode.HYBRID,
                        )
                    },
                    onStop = {
                        depthProcessor.reset()
                        applicationRuntime.enterManagedWorkSurface(
                            forcePassive = true,
                        )
                        onDismiss()
                    },
                    onClose = onDismiss,
                    modifier = Modifier
                        .align(Alignment.BottomCenter)
                        .fillMaxWidth()
                        .padding(12.dp),
                )
            }
        }
    }
}

@Composable
fun DualPhoneSlaveScanWorkspace(
    snapshot: DualPhoneApplicationRuntimeSnapshot,
    onEmergencyDisconnect: () -> Unit,
    modifier: Modifier = Modifier,
) {
    var showInfo by remember { mutableStateOf(true) }
    Surface(modifier = modifier.fillMaxSize(), color = Color.Black) {
        Box(modifier = Modifier.fillMaxSize()) {
            FrameViewport(
                frame = snapshot.localFrameProducer.latestFrame,
                emptyText = snapshot.localFrameProducer.state.name,
                modifier = Modifier.fillMaxSize(),
            )
            Surface(
                modifier = Modifier
                    .align(Alignment.TopStart)
                    .padding(12.dp),
                color = Color.Black.copy(alpha = 0.68f),
                shape = RoundedCornerShape(12.dp),
            ) {
                Column(
                    modifier = Modifier.padding(horizontal = 12.dp, vertical = 8.dp),
                    verticalArrangement = Arrangement.spacedBy(2.dp),
                ) {
                    Text(
                        "SLAVE · УПРАВЛЯЕТСЯ MASTER",
                        color = Color.White,
                        fontWeight = FontWeight.Bold,
                    )
                    Text(
                        "${snapshot.requestedMode.name} · " +
                            "${snapshot.localFrameProducer.effectiveFps.format1()} FPS",
                        color = Color.White,
                    )
                    Text(
                        "MEDIA ${snapshot.mediaTransport.state.name} · " +
                            "TCP/${snapshot.mediaTransport.port}",
                        color = Color.White,
                    )
                }
            }
            if (showInfo) {
                Surface(
                    modifier = Modifier
                        .align(Alignment.CenterEnd)
                        .padding(12.dp)
                        .width(210.dp),
                    color = Color.Black.copy(alpha = 0.62f),
                    shape = RoundedCornerShape(12.dp),
                ) {
                    Column(
                        modifier = Modifier.padding(12.dp),
                        verticalArrangement = Arrangement.spacedBy(4.dp),
                    ) {
                        Text("LIVE PREVIEW", color = Color.White)
                        Text(
                            "encoded ${snapshot.localFrameProducer.framesEncoded}",
                            color = Color.White,
                        )
                        Text(
                            "sent ${snapshot.mediaTransport.framesSent}",
                            color = Color.White,
                        )
                        Text(
                            "replaced ${snapshot.mediaTransport.framesReplacedBeforeSend}",
                            color = Color.White,
                        )
                        Text(
                            "${snapshot.mediaTransport.sendBitrateKbps.format1()} kbit/s",
                            color = Color.White,
                        )
                    }
                }
            }
            Row(
                modifier = Modifier
                    .align(Alignment.BottomCenter)
                    .fillMaxWidth()
                    .horizontalScroll(rememberScrollState())
                    .background(Color.Black.copy(alpha = 0.58f))
                    .padding(12.dp),
                horizontalArrangement = Arrangement.spacedBy(8.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                OutlinedButton(onClick = { showInfo = !showInfo }) {
                    Text(if (showInfo) "Скрыть INFO" else "INFO")
                }
                Button(onClick = onEmergencyDisconnect) {
                    Text("Аварийно отключить SLAVE")
                }
            }
        }
    }
}

@Composable
private fun MasterScanViewport(
    view: DualPhoneMasterScanView,
    snapshot: DualPhoneApplicationRuntimeSnapshot,
    depth: DualPhoneLiveDepthSnapshot,
    modifier: Modifier = Modifier,
) {
    val masterFrame = snapshot.localFrameProducer.latestFrame
    val slaveFrame = snapshot.mediaTransport.latestFrame
    Box(modifier = modifier.background(Color.Black)) {
        when (view) {
            DualPhoneMasterScanView.OVERLAY -> {
                DualPhoneAdaptiveOutlineViewport(
                    masterFrame = masterFrame,
                    depth = depth,
                    modifier = Modifier.fillMaxSize(),
                )
            }
            DualPhoneMasterScanView.MASTER -> {
                FrameViewport(masterFrame, "MASTER camera", Modifier.fillMaxSize())
                PictureInPictureFrame(
                    frame = slaveFrame,
                    title = "SLAVE",
                    modifier = Modifier.align(Alignment.TopEnd),
                )
            }
            DualPhoneMasterScanView.SLAVE -> {
                FrameViewport(slaveFrame, "SLAVE camera", Modifier.fillMaxSize())
                PictureInPictureFrame(
                    frame = masterFrame,
                    title = "MASTER",
                    modifier = Modifier.align(Alignment.TopEnd),
                )
            }
            DualPhoneMasterScanView.SPLIT -> {
                SplitFrames(masterFrame, slaveFrame, Modifier.fillMaxSize())
            }
            DualPhoneMasterScanView.DEPTH -> {
                DepthViewport(
                    bytes = depth.rawDepthPreviewJpeg,
                    emptyText = depth.state.name,
                    title = "RAW SGBM",
                    depth = depth,
                )
            }
            DualPhoneMasterScanView.FILTERED -> {
                DepthViewport(
                    bytes = depth.filteredDepthPreviewJpeg,
                    emptyText = depth.state.name,
                    title = "DENSE SPATIAL DEPTH · PREVIEW / TRACKING",
                    depth = depth,
                )
            }
            DualPhoneMasterScanView.STRICT -> {
                DepthViewport(
                    bytes = depth.strictDepthPreviewJpeg,
                    emptyText = depth.state.name,
                    title = "STRICT TEMPORAL DEPTH · GEOMETRY",
                    depth = depth,
                )
            }
            DualPhoneMasterScanView.CONFIDENCE -> {
                DepthViewport(
                    bytes = depth.confidencePreviewJpeg,
                    emptyText = depth.state.name,
                    title = "CONFIDENCE · GREEN HIGH · ORANGE MEDIUM · RED LOW",
                    depth = depth,
                )
            }
        }
    }
}

@Composable
private fun DepthViewport(
    bytes: ByteArray?,
    emptyText: String,
    title: String,
    depth: DualPhoneLiveDepthSnapshot,
) {
    Box(modifier = Modifier.fillMaxSize()) {
        EncodedViewport(
            bytes = bytes,
            emptyText = emptyText,
            modifier = Modifier.fillMaxSize(),
            rotationDegrees = depth.displayRotationDegrees,
        )
        Text(
            title,
            modifier = Modifier
                .align(Alignment.TopCenter)
                .padding(top = 12.dp)
                .background(Color.Black.copy(alpha = 0.62f))
                .padding(horizontal = 10.dp, vertical = 5.dp),
            color = Color.White,
            fontWeight = FontWeight.Bold,
        )
        PictureInPictureBytes(
            bytes = depth.rectifiedMasterJpeg,
            title = "RECT MASTER",
            rotationDegrees = depth.displayRotationDegrees,
            modifier = Modifier.align(Alignment.TopEnd),
        )
        PictureInPictureBytes(
            bytes = depth.rectifiedSlaveJpeg,
            title = "RECT SLAVE",
            rotationDegrees = depth.displayRotationDegrees,
            modifier = Modifier
                .align(Alignment.TopEnd)
                .padding(top = 118.dp),
        )
    }
}

@Composable
private fun SplitFrames(
    master: DualPhoneReducedFrame?,
    slave: DualPhoneReducedFrame?,
    modifier: Modifier = Modifier,
) {
    BoxWithConstraints(modifier = modifier) {
        if (maxWidth > maxHeight) {
            Row(modifier = Modifier.fillMaxSize()) {
                FrameViewport(master, "MASTER", Modifier.weight(1f).fillMaxHeight())
                FrameViewport(slave, "SLAVE", Modifier.weight(1f).fillMaxHeight())
            }
        } else {
            Column(modifier = Modifier.fillMaxSize()) {
                FrameViewport(master, "MASTER", Modifier.weight(1f).fillMaxWidth())
                FrameViewport(slave, "SLAVE", Modifier.weight(1f).fillMaxWidth())
            }
        }
    }
}

@Composable
private fun MasterStatusOverlay(
    snapshot: DualPhoneApplicationRuntimeSnapshot,
    depth: DualPhoneLiveDepthSnapshot,
    clockReady: Boolean,
    modifier: Modifier = Modifier,
) {
    val remoteFrame = snapshot.mediaTransport.latestFrame
    val remoteReplaced = remoteFrame?.senderFramesReplacedBeforeSend ?: 0L
    val oversizeDrops = snapshot.localFrameProducer.framesDroppedOversize +
        (remoteFrame?.senderFramesDroppedOversize ?: 0L)

    Surface(
        modifier = modifier,
        color = Color.Black.copy(alpha = 0.68f),
        shape = RoundedCornerShape(12.dp),
    ) {
        Column(
            modifier = Modifier.padding(horizontal = 12.dp, vertical = 8.dp),
            verticalArrangement = Arrangement.spacedBy(2.dp),
        ) {
            Text(
                "MASTER · FULL-SCREEN LIVE SCAN",
                color = Color.White,
                fontWeight = FontWeight.Bold,
            )
            Text(
                "${snapshot.requestedMode.name} · " +
                    "MEDIA ${snapshot.mediaTransport.state.name} · " +
                    "CLOCK ${if (clockReady) "READY" else "WAIT"}",
                color = Color.White,
            )
            Text(
                "PAIR ${depth.pairQuality} · Δ ${depth.pairDeltaMs.formatMs()} · " +
                    "DEPTH ${depth.state.name}",
                color = Color.White,
            )
            Text(
                "media M ${snapshot.localFrameProducer.effectiveFps.format1()} / " +
                    "S ${snapshot.remoteMediaFps().format1()} FPS · " +
                    "depth ${depth.depthFps.format1()} FPS",
                color = Color.White,
            )
            Text(
                "pairs READY ${depth.readyPairPercent.format1()}% " +
                    "(${depth.readyPairs}) · LATE ${depth.latePairs} · " +
                    "DROP ${depth.rejectedPairs}",
                color = Color.White,
            )
            Text(
                "raw ${depth.rawValidDisparityPercent.format1()}% · " +
                    "dense ${depth.denseCoveragePercent.format1()}% · " +
                    "strict ${depth.filteredValidDisparityPercent.format1()}% / " +
                    "stable ${depth.stableCoveragePercent.format1()}%",
                color = Color.White,
            )
            Text(
                "gates LR ${depth.leftRightAcceptedPercent.format1()} / " +
                    "${depth.denseLeftRightAcceptedPercent.format1()}% · " +
                    "texture ${depth.textureAcceptedPercent.format1()}% · " +
                    "morph ${depth.morphologyAcceptedPercent.format1()}%",
                color = Color.White,
            )
            Text(
                "high ${depth.highConfidencePercent.format1()}% · " +
                    "median ${DualPhoneLiveDepthProcessor.formatMeters(depth.medianDepthMeters)} · " +
                    "jitter ${depth.depthJitterMeters.formatMeters()} · " +
                    "${depth.processingMs ?: 0L} ms",
                color = Color.White,
            )
            Text(
                "profile ${depth.qualityProfile} · ${depth.workWidth}×${depth.workHeight} · " +
                    "target ${depth.targetDepthFps.format1()} FPS · " +
                    "thermal ${depth.thermalState}",
                color = Color.White,
            )
            Text(
                "motion ${depth.motionScorePercent.format1()}% ${depth.temporalMode} · " +
                    "LR ${depth.leftRightAcceptedPercent.format1()}%",
                color = Color.White,
            )
            Text(
                "util ${depth.processingUtilizationPercent.format1()}% · " +
                    "p50 ${depth.processingP50Ms ?: 0L} / " +
                    "p95 ${depth.processingP95Ms ?: 0L} ms · " +
                    "replaced $remoteReplaced · oversize $oversizeDrops",
                color = Color.White,
            )
            Text(
                "display ${depth.displayRotationDegrees}° / " +
                    "processing ${depth.processingRotationDegrees}°",
                color = Color.White,
            )
            depth.lastError?.let { Text(it, color = MaterialTheme.colorScheme.error) }
        }
    }
}

@Composable
private fun MasterScanControls(
    selectedView: DualPhoneMasterScanView,
    selectedMode: DualPhoneLiveStreamMode,
    onView: (DualPhoneMasterScanView) -> Unit,
    onLive: () -> Unit,
    onHybrid: () -> Unit,
    onStop: () -> Unit,
    onClose: () -> Unit,
    modifier: Modifier = Modifier,
) {
    Surface(
        modifier = modifier,
        color = Color.Black.copy(alpha = 0.64f),
        shape = RoundedCornerShape(14.dp),
    ) {
        Column(
            modifier = Modifier.padding(10.dp),
            verticalArrangement = Arrangement.spacedBy(8.dp),
        ) {
            Row(
                modifier = Modifier.horizontalScroll(rememberScrollState()),
                horizontalArrangement = Arrangement.spacedBy(8.dp),
            ) {
                DualPhoneMasterScanView.entries.forEach { item ->
                    if (item == selectedView) {
                        Button(onClick = { onView(item) }) { Text(item.label) }
                    } else {
                        OutlinedButton(onClick = { onView(item) }) { Text(item.label) }
                    }
                }
            }
            Row(
                modifier = Modifier.horizontalScroll(rememberScrollState()),
                horizontalArrangement = Arrangement.spacedBy(8.dp),
            ) {
                if (selectedMode == DualPhoneLiveStreamMode.LIVE_METRIC) {
                    Button(onClick = onLive) { Text("LIVE") }
                } else {
                    OutlinedButton(onClick = onLive) { Text("LIVE") }
                }
                if (selectedMode == DualPhoneLiveStreamMode.HYBRID) {
                    Button(onClick = onHybrid) { Text("HYBRID") }
                } else {
                    OutlinedButton(onClick = onHybrid) { Text("HYBRID") }
                }
                Button(onClick = onStop) { Text("СТОП") }
                OutlinedButton(onClick = onClose) { Text("Свернуть") }
            }
        }
    }
}

@Composable
private fun PictureInPictureFrame(
    frame: DualPhoneReducedFrame?,
    title: String,
    modifier: Modifier = Modifier,
) {
    Surface(
        modifier = modifier.padding(12.dp).size(width = 150.dp, height = 96.dp),
        color = Color.Black,
        shape = RoundedCornerShape(10.dp),
        tonalElevation = 6.dp,
    ) {
        Box {
            FrameViewport(frame, title, Modifier.fillMaxSize())
            Text(
                title,
                modifier = Modifier
                    .align(Alignment.BottomStart)
                    .background(Color.Black.copy(alpha = 0.6f))
                    .padding(horizontal = 6.dp, vertical = 2.dp),
                color = Color.White,
            )
        }
    }
}

@Composable
private fun PictureInPictureBytes(
    bytes: ByteArray?,
    title: String,
    rotationDegrees: Int,
    modifier: Modifier = Modifier,
) {
    Surface(
        modifier = modifier.padding(12.dp).size(width = 150.dp, height = 96.dp),
        color = Color.Black,
        shape = RoundedCornerShape(10.dp),
        tonalElevation = 6.dp,
    ) {
        Box {
            EncodedViewport(
                bytes = bytes,
                emptyText = title,
                modifier = Modifier.fillMaxSize(),
                rotationDegrees = rotationDegrees,
            )
            Text(
                title,
                modifier = Modifier
                    .align(Alignment.BottomStart)
                    .background(Color.Black.copy(alpha = 0.6f))
                    .padding(horizontal = 6.dp, vertical = 2.dp),
                color = Color.White,
            )
        }
    }
}

@Composable
private fun FrameViewport(
    frame: DualPhoneReducedFrame?,
    emptyText: String,
    modifier: Modifier = Modifier,
) {
    val bitmap = remember(frame?.frameSequence, frame?.payloadCrc32) {
        frame?.jpegBytes?.decodeBitmap()
    }
    DisposableEffect(bitmap) {
        onDispose {
            if (bitmap?.isRecycled == false) bitmap.recycle()
        }
    }
    BitmapViewport(
        bitmap = bitmap,
        rotationDegrees = frame?.imageProxyRotationDegrees ?: 0,
        emptyText = emptyText,
        modifier = modifier,
    )
}

@Composable
private fun EncodedViewport(
    bytes: ByteArray?,
    emptyText: String,
    modifier: Modifier = Modifier,
    rotationDegrees: Int = 0,
) {
    val bitmap = remember(bytes) { bytes?.decodeBitmap() }
    DisposableEffect(bitmap) {
        onDispose {
            if (bitmap?.isRecycled == false) bitmap.recycle()
        }
    }
    BitmapViewport(
        bitmap = bitmap,
        rotationDegrees = rotationDegrees,
        emptyText = emptyText,
        modifier = modifier,
    )
}

@Composable
private fun BitmapViewport(
    bitmap: Bitmap?,
    rotationDegrees: Int,
    emptyText: String,
    modifier: Modifier = Modifier,
) {
    Box(
        modifier = modifier.background(Color.Black),
        contentAlignment = Alignment.Center,
    ) {
        if (bitmap == null) {
            Text(emptyText, color = Color.White)
            return@Box
        }
        val paint = remember {
            Paint(Paint.ANTI_ALIAS_FLAG or Paint.FILTER_BITMAP_FLAG)
        }
        Canvas(modifier = Modifier.fillMaxSize()) {
            drawIntoCanvas { canvas ->
                drawCenterCropBitmap(
                    canvas = canvas.nativeCanvas,
                    bitmap = bitmap,
                    rotationDegrees = rotationDegrees,
                    viewportWidth = size.width,
                    viewportHeight = size.height,
                    paint = paint,
                )
            }
        }
    }
}

private fun drawCenterCropBitmap(
    canvas: android.graphics.Canvas,
    bitmap: Bitmap,
    rotationDegrees: Int,
    viewportWidth: Float,
    viewportHeight: Float,
    paint: Paint,
) {
    val normalized = ((rotationDegrees % 360) + 360) % 360
    val quarterTurn = normalized == 90 || normalized == 270
    val displayWidth = if (quarterTurn) bitmap.height else bitmap.width
    val displayHeight = if (quarterTurn) bitmap.width else bitmap.height
    val scale = maxOf(
        viewportWidth / displayWidth.toFloat(),
        viewportHeight / displayHeight.toFloat(),
    )
    val destination = RectF(
        -bitmap.width * scale / 2f,
        -bitmap.height * scale / 2f,
        bitmap.width * scale / 2f,
        bitmap.height * scale / 2f,
    )
    canvas.save()
    canvas.translate(viewportWidth / 2f, viewportHeight / 2f)
    canvas.rotate(normalized.toFloat())
    canvas.drawBitmap(
        bitmap,
        Rect(0, 0, bitmap.width, bitmap.height),
        destination,
        paint,
    )
    canvas.restore()
}

private fun ByteArray.decodeBitmap(): Bitmap? =
    BitmapFactory.decodeByteArray(this, 0, size)

private fun DualPhoneApplicationRuntimeSnapshot.remoteMediaFps(): Double {
    val media = mediaTransport
    if (media.framesReceived < 2L) return 0.0
    val first = media.firstFrameReceivedElapsedMs ?: return 0.0
    val last = media.lastFrameReceivedElapsedMs ?: return 0.0
    val seconds = (last - first).coerceAtLeast(1L) / 1_000.0
    return (media.framesReceived - 1L).toDouble() / seconds
}

private fun Double.format1(): String = String.format(Locale.US, "%.1f", this)

private fun Double?.formatMs(): String = if (this == null) {
    "—"
} else {
    String.format(Locale.US, "%.1f ms", this)
}

private fun Double?.formatMeters(): String = if (this == null) {
    "—"
} else {
    String.format(Locale.US, "%.2f m", this)
}
