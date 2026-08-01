package com.example.maklertour.ui.session

import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.graphics.Paint
import android.graphics.Rect
import android.graphics.RectF
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Surface
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
import com.example.maklertour.data.dualphone.DualPhoneLiveDepthSnapshot

/**
 * Pixel-registered rectified diagnostic preview.
 *
 * The main operator view remains the natural MASTER camera. Rectified MASTER,
 * DENSE and STRICT are intentionally kept together in this inset because they
 * share one processing coordinate system. Until inverse rectification is
 * implemented, depth must not be stretched over the natural camera frame.
 */
@Composable
internal fun DualPhoneRectifiedDepthInset(
    depth: DualPhoneLiveDepthSnapshot,
    showDepth: Boolean,
    modifier: Modifier = Modifier,
) {
    val rectifiedBytes = depth.rectifiedMasterJpeg.takeIf { showDepth }
    val denseBytes = depth.filteredDepthPreviewJpeg.takeIf { showDepth }
    val strictBytes = depth.strictDepthPreviewJpeg.takeIf { showDepth }

    val rectified = remember(rectifiedBytes) { rectifiedBytes?.decodeInsetBitmap() }
    val dense = remember(denseBytes) { denseBytes?.decodeInsetBitmap() }
    val strict = remember(strictBytes) {
        strictBytes?.decodeInsetBitmap()?.let { source ->
            try {
                source.createInsetOutline()
            } finally {
                if (!source.isRecycled) source.recycle()
            }
        }
    }

    DisposableEffect(rectified) {
        onDispose { if (rectified?.isRecycled == false) rectified.recycle() }
    }
    DisposableEffect(dense) {
        onDispose { if (dense?.isRecycled == false) dense.recycle() }
    }
    DisposableEffect(strict) {
        onDispose { if (strict?.isRecycled == false) strict.recycle() }
    }

    Surface(
        modifier = modifier
            .width(132.dp)
            .height(188.dp),
        color = Color.Black.copy(alpha = 0.80f),
        shape = RoundedCornerShape(10.dp),
    ) {
        Box(modifier = Modifier.background(Color.Black)) {
            val paint = remember {
                Paint(Paint.ANTI_ALIAS_FLAG or Paint.FILTER_BITMAP_FLAG)
            }
            Canvas(modifier = Modifier.fillMaxSize()) {
                drawIntoCanvas { composeCanvas ->
                    val canvas = composeCanvas.nativeCanvas
                    rectified?.let {
                        paint.alpha = 255
                        drawInsetFitCenter(
                            canvas,
                            it,
                            depth.displayRotationDegrees,
                            size.width,
                            size.height,
                            paint,
                        )
                    }
                    dense?.let {
                        paint.alpha = 76
                        drawInsetFitCenter(
                            canvas,
                            it,
                            depth.displayRotationDegrees,
                            size.width,
                            size.height,
                            paint,
                        )
                    }
                    strict?.let {
                        paint.alpha = 255
                        drawInsetFitCenter(
                            canvas,
                            it,
                            depth.displayRotationDegrees,
                            size.width,
                            size.height,
                            paint,
                        )
                    }
                    paint.alpha = 255
                }
            }
            Text(
                "RECT DEPTH",
                modifier = Modifier.align(Alignment.TopCenter),
                color = Color.White,
            )
        }
    }
}

private fun Bitmap.createInsetOutline(): Bitmap {
    val source = IntArray(width * height)
    val output = IntArray(width * height)
    getPixels(source, 0, width, 0, 0, width, height)
    fun valid(index: Int): Boolean {
        val color = source[index]
        return ((color shr 16) and 0xff) +
            ((color shr 8) and 0xff) +
            (color and 0xff) > 45
    }
    for (y in 1 until height - 1) {
        for (x in 1 until width - 1) {
            val index = y * width + x
            if (!valid(index)) continue
            if (
                !valid(index - 1) ||
                !valid(index + 1) ||
                !valid(index - width) ||
                !valid(index + width)
            ) {
                output[index] = android.graphics.Color.argb(245, 0, 255, 70)
            }
        }
    }
    return Bitmap.createBitmap(output, width, height, Bitmap.Config.ARGB_8888)
}

private fun drawInsetFitCenter(
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

private fun ByteArray.decodeInsetBitmap(): Bitmap? =
    BitmapFactory.decodeByteArray(this, 0, size)
