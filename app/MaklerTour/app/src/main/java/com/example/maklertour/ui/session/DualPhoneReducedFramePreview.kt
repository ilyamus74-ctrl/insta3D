package com.example.maklertour.ui.session

import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.graphics.Paint
import android.graphics.Rect
import android.graphics.RectF
import android.os.SystemClock
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.aspectRatio
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.Card
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.drawscope.drawIntoCanvas
import androidx.compose.ui.graphics.nativeCanvas
import androidx.compose.ui.unit.dp
import com.example.maklertour.data.dualphone.DualPhoneApplicationRuntimeSnapshot
import com.example.maklertour.data.dualphone.DualPhoneReducedFrame
import com.example.maklertour.data.dualphone.DualPhoneReducedFrameProducerState
import com.example.maklertour.data.dualphone.DualPhoneReducedFrameTransportState
import java.util.Locale

/**
 * LM01B diagnostic dual preview.
 *
 * The JPEG pixels remain in raw camera orientation on the wire. Rotation from
 * ImageProxy is applied only by this display surface and never changes the frame
 * used by the following rectification/depth stage.
 */
@Composable
fun DualPhoneLiveDualPreview(
    snapshot: DualPhoneApplicationRuntimeSnapshot,
    modifier: Modifier = Modifier,
) {
    Card(modifier = modifier.fillMaxWidth()) {
        Column(
            modifier = Modifier.padding(12.dp),
            verticalArrangement = Arrangement.spacedBy(8.dp),
        ) {
            Text(
                text = "LM01B · реальные уменьшенные кадры",
                style = MaterialTheme.typography.titleMedium,
            )
            Text(
                "Диагностический preview, без rectification, depth и метрической геометрии.",
            )
            Row(
                modifier = Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.spacedBy(8.dp),
            ) {
                ReducedFramePanel(
                    title = "MASTER · локальная камера",
                    frame = snapshot.localFrameProducer.latestFrame,
                    status = snapshot.localFrameProducer.state.name,
                    modifier = Modifier.weight(1f),
                )
                ReducedFramePanel(
                    title = "SLAVE · TCP/${snapshot.mediaTransport.port}",
                    frame = snapshot.mediaTransport.latestFrame,
                    status = snapshot.mediaTransport.state.name,
                    modifier = Modifier.weight(1f),
                )
            }
            Text(
                "MASTER encoder: ${formatFps(snapshot.localFrameProducer.effectiveFps)} FPS · " +
                    "encoded ${snapshot.localFrameProducer.framesEncoded}",
            )
            Text(
                "SLAVE received: ${snapshot.mediaTransport.framesReceived} · " +
                    "${formatKbps(snapshot.mediaTransport.receiveBitrateKbps)} kbit/s · " +
                    "age ${frameAge(snapshot.mediaTransport.lastFrameReceivedElapsedMs)}",
            )
            val remoteFrame = snapshot.mediaTransport.latestFrame
            Text(
                "SLAVE queue: offered ${remoteFrame?.senderFramesOffered ?: 0L} · " +
                    "replaced ${remoteFrame?.senderFramesReplacedBeforeSend ?: 0L} · " +
                    "oversize ${remoteFrame?.senderFramesDroppedOversize ?: 0L}",
            )
        }
    }
}

@Composable
fun DualPhoneSlaveLocalPreview(
    snapshot: DualPhoneApplicationRuntimeSnapshot,
    modifier: Modifier = Modifier,
) {
    Card(modifier = modifier.fillMaxWidth()) {
        Column(
            modifier = Modifier.padding(12.dp),
            verticalArrangement = Arrangement.spacedBy(6.dp),
        ) {
            val activelyStreaming =
                snapshot.localFrameProducer.state ==
                    DualPhoneReducedFrameProducerState.STREAMING &&
                    snapshot.mediaTransport.state ==
                    DualPhoneReducedFrameTransportState.READY
            Text(
                text = if (activelyStreaming) {
                    "SLAVE CAMERA · STREAMING TO MASTER"
                } else {
                    "SLAVE CAMERA · ${snapshot.localFrameProducer.state.name}"
                },
                style = MaterialTheme.typography.titleMedium,
            )
            ReducedFramePanel(
                title = "Локальный уменьшенный preview",
                frame = snapshot.localFrameProducer.latestFrame,
                status = snapshot.localFrameProducer.state.name,
            )
            Text(
                "Producer: ${snapshot.localFrameProducer.state.name} · " +
                    "${formatFps(snapshot.localFrameProducer.effectiveFps)} FPS",
            )
            Text(
                "Encoded ${snapshot.localFrameProducer.framesEncoded} · " +
                    "sent ${snapshot.mediaTransport.framesSent} · " +
                    "replaced ${snapshot.mediaTransport.framesReplacedBeforeSend}",
            )
            Text(
                "Media: ${snapshot.mediaTransport.state.name} · " +
                    "TCP/${snapshot.mediaTransport.port} · " +
                    "${formatKbps(snapshot.mediaTransport.sendBitrateKbps)} kbit/s",
            )
            snapshot.localFrameProducer.lastError?.let { Text("Camera stream: $it") }
            snapshot.mediaTransport.lastError?.let { Text("Media channel: $it") }
        }
    }
}

@Composable
private fun ReducedFramePanel(
    title: String,
    frame: DualPhoneReducedFrame?,
    status: String,
    modifier: Modifier = Modifier,
) {
    Column(
        modifier = modifier,
        verticalArrangement = Arrangement.spacedBy(4.dp),
    ) {
        Text(title, style = MaterialTheme.typography.labelLarge)
        Box(
            modifier = Modifier
                .fillMaxWidth()
                .aspectRatio(16f / 9f)
                .background(Color.Black),
            contentAlignment = Alignment.Center,
        ) {
            if (frame == null) {
                Text(status, color = Color.White)
            } else {
                RawOrientationFrame(frame = frame)
            }
        }
        frame?.let {
            Text(
                "${it.width}×${it.height} · #${it.frameSequence} · " +
                    "display ${it.imageProxyRotationDegrees}°",
                style = MaterialTheme.typography.bodySmall,
            )
        }
    }
}

@Composable
private fun RawOrientationFrame(
    frame: DualPhoneReducedFrame,
    modifier: Modifier = Modifier,
) {
    val bitmap = remember(frame.frameSequence, frame.payloadCrc32) {
        BitmapFactory.decodeByteArray(
            frame.jpegBytes,
            0,
            frame.jpegBytes.size,
        )
    }
    DisposableEffect(bitmap) {
        onDispose {
            if (bitmap?.isRecycled == false) bitmap.recycle()
        }
    }
    if (bitmap == null) {
        Box(
            modifier = modifier.fillMaxWidth(),
            contentAlignment = Alignment.Center,
        ) {
            Text("JPEG decode failed", color = Color.White)
        }
        return
    }

    val paint = remember {
        Paint(Paint.ANTI_ALIAS_FLAG or Paint.FILTER_BITMAP_FLAG)
    }
    Canvas(modifier = modifier.fillMaxWidth().aspectRatio(16f / 9f)) {
        drawIntoCanvas { canvas ->
            drawDisplayRotatedBitmap(
                canvas = canvas.nativeCanvas,
                bitmap = bitmap,
                rotationDegrees = frame.imageProxyRotationDegrees,
                viewportWidth = size.width,
                viewportHeight = size.height,
                paint = paint,
            )
        }
    }
}

private fun drawDisplayRotatedBitmap(
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
    val scale = minOf(
        viewportWidth / displayWidth.toFloat(),
        viewportHeight / displayHeight.toFloat(),
    )
    val drawnWidth = bitmap.width * scale
    val drawnHeight = bitmap.height * scale
    val destination = RectF(
        -drawnWidth / 2f,
        -drawnHeight / 2f,
        drawnWidth / 2f,
        drawnHeight / 2f,
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

private fun frameAge(lastElapsedMs: Long?): String {
    if (lastElapsedMs == null) return "—"
    return "${(SystemClock.elapsedRealtime() - lastElapsedMs).coerceAtLeast(0L)} ms"
}

private fun formatFps(value: Double): String =
    String.format(Locale.US, "%.1f", value)

private fun formatKbps(value: Double): String =
    String.format(Locale.US, "%.1f", value)
