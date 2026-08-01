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
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.drawscope.drawIntoCanvas
import androidx.compose.ui.graphics.nativeCanvas
import com.example.maklertour.data.dualphone.DualPhoneReducedFrame

/**
 * Shows the complete SLAVE analysis frame without geometric distortion.
 *
 * A dim center-cropped copy fills the background, while the sharp foreground is
 * FIT_CENTER with zero crop. The transported JPEG and stereo pipeline remain
 * unchanged; this is a display-only correction.
 */
@Composable
internal fun DualPhoneSlaveAspectSafePreview(
    frame: DualPhoneReducedFrame?,
    emptyText: String,
    modifier: Modifier = Modifier,
) {
    val bitmap = remember(frame?.frameSequence, frame?.payloadCrc32) {
        frame?.jpegBytes?.let { bytes ->
            BitmapFactory.decodeByteArray(bytes, 0, bytes.size)
        }
    }
    DisposableEffect(bitmap) {
        onDispose { if (bitmap?.isRecycled == false) bitmap.recycle() }
    }

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
            drawIntoCanvas { composeCanvas ->
                val canvas = composeCanvas.nativeCanvas
                paint.alpha = 105
                drawSlaveBitmap(
                    canvas = canvas,
                    bitmap = bitmap,
                    rotationDegrees = frame?.imageProxyRotationDegrees ?: 0,
                    viewportWidth = size.width,
                    viewportHeight = size.height,
                    fitCenter = false,
                    paint = paint,
                )
                canvas.drawColor(android.graphics.Color.argb(145, 0, 0, 0))
                paint.alpha = 255
                drawSlaveBitmap(
                    canvas = canvas,
                    bitmap = bitmap,
                    rotationDegrees = frame?.imageProxyRotationDegrees ?: 0,
                    viewportWidth = size.width,
                    viewportHeight = size.height,
                    fitCenter = true,
                    paint = paint,
                )
            }
        }
    }
}

private fun drawSlaveBitmap(
    canvas: android.graphics.Canvas,
    bitmap: Bitmap,
    rotationDegrees: Int,
    viewportWidth: Float,
    viewportHeight: Float,
    fitCenter: Boolean,
    paint: Paint,
) {
    val normalized = ((rotationDegrees % 360) + 360) % 360
    val quarterTurn = normalized == 90 || normalized == 270
    val displayWidth = if (quarterTurn) bitmap.height else bitmap.width
    val displayHeight = if (quarterTurn) bitmap.width else bitmap.height
    val scale = if (fitCenter) {
        minOf(
            viewportWidth / displayWidth.toFloat(),
            viewportHeight / displayHeight.toFloat(),
        )
    } else {
        maxOf(
            viewportWidth / displayWidth.toFloat(),
            viewportHeight / displayHeight.toFloat(),
        )
    }
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
