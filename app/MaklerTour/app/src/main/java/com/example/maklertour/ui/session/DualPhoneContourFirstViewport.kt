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
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.drawscope.drawIntoCanvas
import androidx.compose.ui.graphics.nativeCanvas
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import com.example.maklertour.data.dualphone.DualPhoneLiveDepthSnapshot
import com.example.maklertour.data.dualphone.DualPhoneLiveDepthState
import com.example.maklertour.data.dualphone.DualPhoneReducedFrame
import kotlinx.coroutines.delay

internal enum class DualPhoneOperatorOverlayMode(val label: String) {
    OUTLINE("OUTLINE"),
    ASSIST("ASSIST"),
    HEATMAP("HEATMAP"),
}

internal fun DualPhoneMasterScanView.toOperatorOverlayMode(): DualPhoneOperatorOverlayMode =
    when (this) {
        DualPhoneMasterScanView.OVERLAY -> DualPhoneOperatorOverlayMode.OUTLINE
        DualPhoneMasterScanView.ASSIST -> DualPhoneOperatorOverlayMode.ASSIST
        DualPhoneMasterScanView.HEATMAP -> DualPhoneOperatorOverlayMode.HEATMAP
        else -> error("$name is not an operator-overlay view")
    }

private enum class ContourDepthFreshness(val label: String) {
    WAITING("WAITING"),
    LIVE("LIVE"),
    HOLD("HOLD"),
    STALE("STALE"),
    EXPIRED("EXPIRED"),
}

/**
 * Contour-first operator surface.
 *
 * OUTLINE keeps the room and object boundaries visually dominant. ASSIST adds a
 * weak metric fill, while HEATMAP exposes the full registered DENSE product for
 * diagnostics. Every registered layer uses the exact paired MASTER frame and
 * one shared center-crop transform, so no layer can stretch independently.
 */
@Composable
internal fun DualPhoneContourFirstViewport(
    masterFrame: DualPhoneReducedFrame?,
    depth: DualPhoneLiveDepthSnapshot,
    mode: DualPhoneOperatorOverlayMode,
    modifier: Modifier = Modifier,
) {
    var nowElapsedMs by remember { mutableStateOf(SystemClock.elapsedRealtime()) }
    val streamStartedElapsedMs = remember(masterFrame?.streamId) {
        SystemClock.elapsedRealtime()
    }

    LaunchedEffect(Unit) {
        while (true) {
            nowElapsedMs = SystemClock.elapsedRealtime()
            delay(FRESHNESS_TICK_MS)
        }
    }

    val publishedInCurrentStream =
        (depth.lastUpdatedElapsedMs ?: Long.MIN_VALUE) >= streamStartedElapsedMs
    val freshness = contourFreshness(
        depth = depth,
        nowElapsedMs = nowElapsedMs,
        publishedInCurrentStream = publishedInCurrentStream,
    )
    val showDepth = freshness == ContourDepthFreshness.LIVE ||
        freshness == ContourDepthFreshness.HOLD ||
        freshness == ContourDepthFreshness.STALE

    val needsDense = mode != DualPhoneOperatorOverlayMode.OUTLINE
    val registered = showDepth &&
        depth.registeredMasterJpeg != null &&
        depth.registeredStrictOutlinePng != null &&
        (!needsDense || depth.registeredDenseOverlayPng != null)

    val baseBytes = if (registered) {
        depth.registeredMasterJpeg
    } else {
        masterFrame?.jpegBytes
    }
    val baseRotation = if (registered) {
        depth.registeredRotationDegrees
    } else {
        masterFrame?.imageProxyRotationDegrees ?: 0
    }
    val denseBytes = depth.registeredDenseOverlayPng.takeIf {
        registered && needsDense
    }
    val strictBytes = depth.registeredStrictOutlinePng.takeIf { registered }

    val baseBitmap = remember(baseBytes) { baseBytes?.decodeContourBitmap() }
    val denseBitmap = remember(denseBytes) { denseBytes?.decodeContourBitmap() }
    val strictBitmap = remember(strictBytes) { strictBytes?.decodeContourBitmap() }

    DisposableEffect(baseBitmap) {
        onDispose { if (baseBitmap?.isRecycled == false) baseBitmap.recycle() }
    }
    DisposableEffect(denseBitmap) {
        onDispose { if (denseBitmap?.isRecycled == false) denseBitmap.recycle() }
    }
    DisposableEffect(strictBitmap) {
        onDispose { if (strictBitmap?.isRecycled == false) strictBitmap.recycle() }
    }

    val paint = remember {
        Paint(Paint.ANTI_ALIAS_FLAG or Paint.FILTER_BITMAP_FLAG)
    }

    Box(modifier = modifier.background(Color.Black)) {
        Canvas(modifier = Modifier.fillMaxSize()) {
            drawIntoCanvas { composeCanvas ->
                val canvas = composeCanvas.nativeCanvas
                baseBitmap?.let { bitmap ->
                    paint.alpha = 255
                    drawContourCenterCrop(
                        canvas = canvas,
                        bitmap = bitmap,
                        rotationDegrees = baseRotation,
                        viewportWidth = size.width,
                        viewportHeight = size.height,
                        paint = paint,
                    )
                }
                denseBitmap?.let { bitmap ->
                    paint.alpha = densePaintAlpha(mode, freshness)
                    drawContourCenterCrop(
                        canvas = canvas,
                        bitmap = bitmap,
                        rotationDegrees = baseRotation,
                        viewportWidth = size.width,
                        viewportHeight = size.height,
                        paint = paint,
                    )
                }
                strictBitmap?.let { bitmap ->
                    paint.alpha = strictPaintAlpha(freshness)
                    drawContourCenterCrop(
                        canvas = canvas,
                        bitmap = bitmap,
                        rotationDegrees = baseRotation,
                        viewportWidth = size.width,
                        viewportHeight = size.height,
                        paint = paint,
                    )
                }
                paint.alpha = 255
            }
        }

        Surface(
            modifier = Modifier
                .align(Alignment.TopCenter)
                .padding(top = 12.dp),
            color = Color.Black.copy(alpha = 0.66f),
            shape = RoundedCornerShape(10.dp),
        ) {
            Text(
                contourStatusText(
                    mode = mode,
                    depth = depth,
                    freshness = freshness,
                    nowElapsedMs = nowElapsedMs,
                    publishedInCurrentStream = publishedInCurrentStream,
                ),
                modifier = Modifier.padding(horizontal = 10.dp, vertical = 5.dp),
                color = Color.White,
                fontWeight = FontWeight.Bold,
            )
        }

        if (mode != DualPhoneOperatorOverlayMode.OUTLINE) {
            ContourMetricLegend(
                modifier = Modifier
                    .align(Alignment.CenterEnd)
                    .padding(12.dp),
            )
        }

        if (mode == DualPhoneOperatorOverlayMode.HEATMAP) {
            DualPhoneRectifiedDepthInset(
                depth = depth,
                showDepth = showDepth,
                modifier = Modifier
                    .align(Alignment.TopEnd)
                    .padding(top = 58.dp, end = 12.dp),
            )
        }
    }
}

private fun densePaintAlpha(
    mode: DualPhoneOperatorOverlayMode,
    freshness: ContourDepthFreshness,
): Int {
    val liveAlpha = when (mode) {
        DualPhoneOperatorOverlayMode.OUTLINE -> 0
        DualPhoneOperatorOverlayMode.ASSIST -> 86
        DualPhoneOperatorOverlayMode.HEATMAP -> 255
    }
    return when (freshness) {
        ContourDepthFreshness.LIVE -> liveAlpha
        ContourDepthFreshness.HOLD -> liveAlpha * 3 / 4
        ContourDepthFreshness.STALE -> liveAlpha * 2 / 5
        else -> 0
    }
}

private fun strictPaintAlpha(freshness: ContourDepthFreshness): Int =
    when (freshness) {
        ContourDepthFreshness.LIVE -> 255
        ContourDepthFreshness.HOLD -> 205
        ContourDepthFreshness.STALE -> 130
        else -> 0
    }

private fun contourFreshness(
    depth: DualPhoneLiveDepthSnapshot,
    nowElapsedMs: Long,
    publishedInCurrentStream: Boolean,
): ContourDepthFreshness {
    if (!publishedInCurrentStream) return ContourDepthFreshness.WAITING
    val updated = depth.lastUpdatedElapsedMs ?: return ContourDepthFreshness.WAITING
    val ageMs = (nowElapsedMs - updated).coerceAtLeast(0L)
    return when {
        ageMs <= LIVE_DEPTH_MAX_AGE_MS -> ContourDepthFreshness.LIVE
        ageMs <= HOLD_DEPTH_MAX_AGE_MS -> ContourDepthFreshness.HOLD
        ageMs <= STALE_DEPTH_MAX_AGE_MS -> ContourDepthFreshness.STALE
        else -> ContourDepthFreshness.EXPIRED
    }
}

private fun contourStatusText(
    mode: DualPhoneOperatorOverlayMode,
    depth: DualPhoneLiveDepthSnapshot,
    freshness: ContourDepthFreshness,
    nowElapsedMs: Long,
    publishedInCurrentStream: Boolean,
): String {
    val state = when (depth.state) {
        DualPhoneLiveDepthState.WAITING_CLOCK -> "CLOCK CALIBRATING"
        DualPhoneLiveDepthState.WAITING_FRAMES -> "WAIT FRAMES"
        DualPhoneLiveDepthState.PAIRING -> "PAIRING"
        DualPhoneLiveDepthState.PROCESSING -> "PROCESSING"
        else -> freshness.label
    }
    val registration = depth.registeredMasterFrameSequence?.let { "REG #$it" }
        ?: "REG WAIT"
    val age = if (!publishedInCurrentStream) {
        "new map wait"
    } else {
        val updated = depth.lastUpdatedElapsedMs
        if (updated == null) "depth wait" else {
            "age ${(nowElapsedMs - updated).coerceAtLeast(0L)} ms"
        }
    }
    return "${mode.label} · $state · $registration · $age"
}

@Composable
private fun ContourMetricLegend(modifier: Modifier = Modifier) {
    Surface(
        modifier = modifier,
        color = Color.Black.copy(alpha = 0.62f),
        shape = RoundedCornerShape(8.dp),
    ) {
        Column(
            modifier = Modifier.padding(8.dp),
            verticalArrangement = Arrangement.spacedBy(3.dp),
        ) {
            legendRow(Color(0xfff44336), "0.5 m")
            legendRow(Color(0xffff9800), "1 m")
            legendRow(Color(0xffffeb3b), "2 m")
            legendRow(Color(0xff4caf50), "3 m")
            legendRow(Color(0xff00bcd4), "4 m")
            legendRow(Color(0xff3f51b5), "6 m")
        }
    }
}

@Composable
private fun legendRow(color: Color, label: String) {
    Row(
        horizontalArrangement = Arrangement.spacedBy(6.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(
            modifier = Modifier
                .size(12.dp)
                .background(color, RoundedCornerShape(2.dp)),
        )
        Text(label, color = Color.White)
    }
}

private fun drawContourCenterCrop(
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

private fun ByteArray.decodeContourBitmap(): Bitmap? =
    BitmapFactory.decodeByteArray(this, 0, size)

private const val FRESHNESS_TICK_MS = 100L
private const val LIVE_DEPTH_MAX_AGE_MS = 350L
private const val HOLD_DEPTH_MAX_AGE_MS = 900L
private const val STALE_DEPTH_MAX_AGE_MS = 2_000L
