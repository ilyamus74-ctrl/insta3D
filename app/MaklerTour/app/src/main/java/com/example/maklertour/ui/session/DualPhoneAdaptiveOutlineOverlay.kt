package com.example.maklertour.ui.session

import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.graphics.Paint
import android.graphics.Rect
import android.graphics.RectF
import android.os.SystemClock
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
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

private enum class DualPhoneDepthFreshness(val label: String) {
    WAITING("WAITING"),
    LIVE("LIVE"),
    HOLD("HOLD"),
    STALE("STALE"),
    EXPIRED("EXPIRED"),
}

/**
 * LM02.6 human-readable operator view.
 *
 * The room stays recognizable because the rectified MASTER image remains the
 * base layer. DENSE metric depth is translucent and STRICT depth contributes
 * only green object/plane boundaries. Startup and expired depth fall back to
 * the live MASTER camera instead of a black screen.
 */
@Composable
internal fun DualPhoneAdaptiveOutlineViewport(
    masterFrame: DualPhoneReducedFrame?,
    depth: DualPhoneLiveDepthSnapshot,
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
    val freshness = depthFreshness(
        depth = depth,
        nowElapsedMs = nowElapsedMs,
        publishedInCurrentStream = publishedInCurrentStream,
    )
    val showDepth = freshness == DualPhoneDepthFreshness.LIVE ||
        freshness == DualPhoneDepthFreshness.HOLD ||
        freshness == DualPhoneDepthFreshness.STALE

    // The operator background never switches from the natural camera frame to
    // the rectified processing buffer. Rectification changes crop and geometry,
    // so mixing both as one full-screen layer caused a visible jump and made
    // door frames and room corners hard to recognize.
    val baseBytes = masterFrame?.jpegBytes
    val baseRotation = masterFrame?.imageProxyRotationDegrees ?: 0
    val denseBytes: ByteArray? = null
    val strictBytes: ByteArray? = null

    val baseBitmap = remember(baseBytes) { baseBytes?.decodeAdaptiveBitmap() }
    val denseBitmap = remember(denseBytes) { denseBytes?.decodeAdaptiveBitmap() }
    val strictOutlineBitmap = remember(strictBytes) {
        strictBytes?.decodeAdaptiveBitmap()?.let { strict ->
            try {
                strict.createDepthOutlineBitmap()
            } finally {
                if (!strict.isRecycled) strict.recycle()
            }
        }
    }

    DisposableEffect(baseBitmap) {
        onDispose { if (baseBitmap?.isRecycled == false) baseBitmap.recycle() }
    }
    DisposableEffect(denseBitmap) {
        onDispose { if (denseBitmap?.isRecycled == false) denseBitmap.recycle() }
    }
    DisposableEffect(strictOutlineBitmap) {
        onDispose {
            if (strictOutlineBitmap?.isRecycled == false) {
                strictOutlineBitmap.recycle()
            }
        }
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
                    drawAdaptiveCenterCropBitmap(
                        canvas = canvas,
                        bitmap = bitmap,
                        rotationDegrees = baseRotation,
                        viewportWidth = size.width,
                        viewportHeight = size.height,
                        paint = paint,
                    )
                }
                denseBitmap?.let { bitmap ->
                    paint.alpha = when (freshness) {
                        DualPhoneDepthFreshness.LIVE -> 72
                        DualPhoneDepthFreshness.HOLD -> 52
                        DualPhoneDepthFreshness.STALE -> 32
                        else -> 0
                    }
                    drawAdaptiveCenterCropBitmap(
                        canvas = canvas,
                        bitmap = bitmap,
                        rotationDegrees = depth.displayRotationDegrees,
                        viewportWidth = size.width,
                        viewportHeight = size.height,
                        paint = paint,
                    )
                }
                strictOutlineBitmap?.let { bitmap ->
                    paint.alpha = when (freshness) {
                        DualPhoneDepthFreshness.LIVE -> 255
                        DualPhoneDepthFreshness.HOLD -> 205
                        DualPhoneDepthFreshness.STALE -> 130
                        else -> 0
                    }
                    drawAdaptiveCenterCropBitmap(
                        canvas = canvas,
                        bitmap = bitmap,
                        rotationDegrees = depth.displayRotationDegrees,
                        viewportWidth = size.width,
                        viewportHeight = size.height,
                        paint = paint,
                    )
                }
                paint.alpha = 255
            }
        }

        DualPhoneRectifiedDepthInset(
            depth = depth,
            showDepth = showDepth,
            modifier = Modifier
                .align(Alignment.TopEnd)
                .padding(top = 58.dp, end = 12.dp),
        )

        Surface(
            modifier = Modifier
                .align(Alignment.TopCenter)
                .padding(top = 12.dp),
            color = Color.Black.copy(alpha = 0.72f),
            shape = RoundedCornerShape(10.dp),
        ) {
            Text(
                operatorStatusText(
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

        MetricDepthLegend(
            modifier = Modifier
                .align(Alignment.CenterEnd)
                .padding(12.dp),
        )
    }
}

private fun operatorStatusText(
    depth: DualPhoneLiveDepthSnapshot,
    freshness: DualPhoneDepthFreshness,
    nowElapsedMs: Long,
    publishedInCurrentStream: Boolean,
): String {
    val startup = when (depth.state) {
        DualPhoneLiveDepthState.WAITING_CLOCK -> "CLOCK CALIBRATING"
        DualPhoneLiveDepthState.WAITING_FRAMES -> "WAIT FRAMES"
        DualPhoneLiveDepthState.PAIRING -> "PAIRING"
        DualPhoneLiveDepthState.PROCESSING -> "PROCESSING"
        else -> freshness.label
    }
    return "OUTLINE · $startup · ${inferredSceneProfile(depth)} · " +
        depthAgeLabel(depth, nowElapsedMs, publishedInCurrentStream)
}

private fun Bitmap.createDepthOutlineBitmap(): Bitmap {
    val bitmapWidth = width
    val bitmapHeight = height
    val source = IntArray(bitmapWidth * bitmapHeight)
    val output = IntArray(bitmapWidth * bitmapHeight)
    getPixels(source, 0, bitmapWidth, 0, 0, bitmapWidth, bitmapHeight)

    fun valid(index: Int): Boolean {
        val color = source[index]
        val red = color shr 16 and 0xff
        val green = color shr 8 and 0xff
        val blue = color and 0xff
        return red + green + blue > OUTLINE_VALID_COLOR_SUM
    }

    for (y in 1 until bitmapHeight - 1) {
        for (x in 1 until bitmapWidth - 1) {
            val index = y * bitmapWidth + x
            if (!valid(index)) continue
            val boundary =
                !valid(index - 1) ||
                    !valid(index + 1) ||
                    !valid(index - bitmapWidth) ||
                    !valid(index + bitmapWidth)
            if (boundary) {
                output[index] = android.graphics.Color.argb(245, 0, 255, 70)
            }
        }
    }
    return Bitmap.createBitmap(
        output,
        bitmapWidth,
        bitmapHeight,
        Bitmap.Config.ARGB_8888,
    )
}

@Composable
private fun MetricDepthLegend(modifier: Modifier = Modifier) {
    Surface(
        modifier = modifier,
        color = Color.Black.copy(alpha = 0.66f),
        shape = RoundedCornerShape(10.dp),
    ) {
        Column(modifier = Modifier.padding(8.dp)) {
            DepthLegendItem("0.5 m", Color(0xFFFF0000))
            DepthLegendItem("1 m", Color(0xFFFFA500))
            DepthLegendItem("2 m", Color(0xFFFFFF00))
            DepthLegendItem("3 m", Color(0xFF00FF00))
            DepthLegendItem("4 m", Color(0xFF00FFFF))
            DepthLegendItem("6 m", Color(0xFF0000FF))
            Text("зелёный контур = STRICT", color = Color.White)
        }
    }
}

@Composable
private fun DepthLegendItem(label: String, color: Color) {
    Text(
        label,
        modifier = Modifier
            .padding(vertical = 1.dp)
            .background(color.copy(alpha = 0.85f))
            .padding(horizontal = 6.dp, vertical = 2.dp),
        color = Color.Black,
        fontWeight = FontWeight.Bold,
    )
}

private fun depthFreshness(
    depth: DualPhoneLiveDepthSnapshot,
    nowElapsedMs: Long,
    publishedInCurrentStream: Boolean,
): DualPhoneDepthFreshness {
    if (!publishedInCurrentStream) return DualPhoneDepthFreshness.WAITING
    val updated = depth.lastUpdatedElapsedMs ?: return DualPhoneDepthFreshness.WAITING
    val ageMs = (nowElapsedMs - updated).coerceAtLeast(0L)
    return when {
        ageMs <= LIVE_DEPTH_MAX_AGE_MS -> DualPhoneDepthFreshness.LIVE
        ageMs <= HOLD_DEPTH_MAX_AGE_MS -> DualPhoneDepthFreshness.HOLD
        ageMs <= STALE_DEPTH_MAX_AGE_MS -> DualPhoneDepthFreshness.STALE
        else -> DualPhoneDepthFreshness.EXPIRED
    }
}

private fun depthAgeLabel(
    depth: DualPhoneLiveDepthSnapshot,
    nowElapsedMs: Long,
    publishedInCurrentStream: Boolean,
): String {
    if (!publishedInCurrentStream) return "ожидание новой карты"
    val updated = depth.lastUpdatedElapsedMs ?: return "ожидание depth"
    return "age ${(nowElapsedMs - updated).coerceAtLeast(0L)} ms"
}

private fun inferredSceneProfile(depth: DualPhoneLiveDepthSnapshot): String =
    when {
        depth.motionScorePercent >= 8.0 -> "MOVING"
        depth.rawValidDisparityPercent < 18.0 ||
            depth.denseCoveragePercent < 3.0 -> "LOW_TEXTURE"
        depth.motionScorePercent <= 0.8 &&
            depth.rawValidDisparityPercent >= 25.0 -> "STATIC_REFINE"
        else -> "TEXTURED"
    }

private fun drawAdaptiveCenterCropBitmap(
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
    // FIT_CENTER keeps the full rectified frame and its aspect ratio. All LM02.6
    // layers share the same processing dimensions and rotation, therefore the
    // base image, DENSE depth and STRICT outline use the same visible transform.
    val scale = minOf(
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

private fun ByteArray.decodeAdaptiveBitmap(): Bitmap? =
    BitmapFactory.decodeByteArray(this, 0, size)

private const val OUTLINE_VALID_COLOR_SUM = 45
private const val FRESHNESS_TICK_MS = 100L
private const val LIVE_DEPTH_MAX_AGE_MS = 350L
private const val HOLD_DEPTH_MAX_AGE_MS = 900L
private const val STALE_DEPTH_MAX_AGE_MS = 2_000L
