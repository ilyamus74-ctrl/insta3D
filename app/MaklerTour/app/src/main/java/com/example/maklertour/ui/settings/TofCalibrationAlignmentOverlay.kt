package com.maklertour.ui.settings

import android.os.SystemClock
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.aspectRatio
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.safeDrawingPadding
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.geometry.Size
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.unit.dp
import com.maklertour.data.tof.TofFrameV1
import com.maklertour.data.tof.TofUsbRuntime
import java.util.Locale
import kotlin.math.abs
import kotlin.math.min

/**
 * Pre-solve mechanical aid.
 *
 * This deliberately does NOT pretend that raw ToF zones are already registered
 * to CAMERA_A pixels. The camera centre guide and the raw 8x8 matrix merely help
 * the operator place/fix the sensor before collecting extrinsics samples.
 */
@Composable
internal fun TofCalibrationAlignmentOverlay(
    armed: Boolean,
    detailed: Boolean,
    modifier: Modifier = Modifier,
) {
    val context = LocalContext.current
    val runtime = remember(context) {
        TofUsbRuntime.get(context.applicationContext)
    }
    val frame by runtime.latestFrame.collectAsState()
    val usbState by runtime.state.collectAsState()

    Box(modifier = modifier) {
        Canvas(modifier = Modifier.fillMaxSize()) {
            val side = min(size.width, size.height) * 0.40f
            val left = (size.width - side) / 2f
            val top = (size.height - side) / 2f
            val centre = Offset(size.width / 2f, size.height / 2f)
            val stroke =
                (min(size.width, size.height) * 0.0035f).coerceAtLeast(2f)

            drawRect(
                color = Color(0xCCFFB300),
                topLeft = Offset(left, top),
                size = Size(side, side),
                style = Stroke(width = stroke),
            )
            drawLine(
                color = Color(0xCCFFB300),
                start = Offset(centre.x - side * 0.08f, centre.y),
                end = Offset(centre.x + side * 0.08f, centre.y),
                strokeWidth = stroke,
            )
            drawLine(
                color = Color(0xCCFFB300),
                start = Offset(centre.x, centre.y - side * 0.08f),
                end = Offset(centre.x, centre.y + side * 0.08f),
                strokeWidth = stroke,
            )
        }

        Card(
            modifier = Modifier
                .align(Alignment.TopEnd)
                .safeDrawingPadding()
                .padding(top = 20.dp, end = 20.dp)
                .fillMaxWidth(if (detailed) 0.31f else 0.25f),
            colors = CardDefaults.cardColors(
                containerColor = Color(0xDD101010),
                contentColor = Color.White,
            ),
        ) {
            Column(
                modifier = Modifier.padding(10.dp),
                verticalArrangement = Arrangement.spacedBy(5.dp),
            ) {
                val current = frame
                val validDistances = current.validDistances()
                val centralDistances = current.centralValidDistances()
                val referenceMm =
                    median(centralDistances) ?: median(validDistances)
                val centreValid = centralDistances.size
                val centreTotal =
                    current?.let { centralZoneCount(it.width, it.height) } ?: 16
                val spreadMm =
                    if (centralDistances.isEmpty()) null
                    else centralDistances.maxOrNull()!! -
                        centralDistances.minOrNull()!!
                val ageMs = current?.let {
                    (
                        SystemClock.elapsedRealtimeNanos() -
                            it.hostReceivedElapsedRealtimeNs
                        ).coerceAtLeast(0L) / 1_000_000L
                }

                Text(
                    "TOF ${current?.width ?: 8}×${current?.height ?: 8} LIVE",
                    style = MaterialTheme.typography.titleSmall,
                )

                TofRawMatrix(
                    frame = current,
                    referenceMm = referenceMm,
                    modifier = Modifier
                        .fillMaxWidth()
                        .aspectRatio(1f),
                )

                Text(
                    "valid ${validDistances.size}/${current?.zoneCount ?: 64} · " +
                        "центр 4×4 $centreValid/$centreTotal",
                    color = if (centreValid >= centreTotal * 3 / 4) {
                        Color(0xFF7CFC98)
                    } else {
                        Color(0xFFFFCC80)
                    },
                    style = MaterialTheme.typography.bodySmall,
                )
                Text(
                    "центр " + distanceLabel(referenceMm) +
                        " · spread " + distanceLabel(spreadMm),
                    style = MaterialTheme.typography.bodySmall,
                )
                if (detailed) {
                    Text(
                        "USB ${usbState.status.name} · seq ${current?.sequence ?: "—"} · " +
                            "age ${ageMs?.let { "$it мс" } ?: "—"}",
                        style = MaterialTheme.typography.bodySmall,
                    )
                }
                Text(
                    if (armed) {
                        "СБОР РАЗРЕШЁН ✓ · ToF больше не двигать"
                    } else {
                        "ALIGNMENT · ToF можно двигать; снимки заблокированы"
                    },
                    color = if (armed) {
                        Color(0xFF7CFC98)
                    } else {
                        Color(0xFFFFCC80)
                    },
                    style = MaterialTheme.typography.bodySmall,
                )
                if (detailed) {
                    Text(
                        "Жёлтая рамка = центр CAMERA_A / центральные 4×4 ToF. " +
                            "Цвет ToF = близость к медиане центра. До solve это не " +
                            "точная RGB-проекция.",
                        style = MaterialTheme.typography.bodySmall,
                    )
                }
            }
        }
    }
}

@Composable
private fun TofRawMatrix(
    frame: TofFrameV1?,
    referenceMm: Int?,
    modifier: Modifier = Modifier,
) {
    Canvas(modifier = modifier.background(Color(0xAA000000))) {
        val current = frame ?: return@Canvas
        if (current.width <= 0 || current.height <= 0) return@Canvas

        val cellWidth = size.width / current.width.toFloat()
        val cellHeight = size.height / current.height.toFloat()

        for (index in 0 until current.zoneCount) {
            val row = index / current.width
            val rawColumn = index % current.width
            val sceneColumn = current.width - 1 - rawColumn
            val topLeft = Offset(
                x = sceneColumn * cellWidth,
                y = row * cellHeight,
            )
            val valid = current.isZoneValid(index)
            val fill = when {
                !valid -> Color(0xCC7A2020)
                referenceMm == null -> Color(0xCC2E7D32)
                abs(current.distanceMm[index] - referenceMm) <= 80 ->
                    Color(0xCC00A65A)
                abs(current.distanceMm[index] - referenceMm) <= 200 ->
                    Color(0xCCB8860B)
                else -> Color(0xCC315E8A)
            }
            drawRect(
                color = fill,
                topLeft = topLeft,
                size = Size(cellWidth, cellHeight),
            )
            drawRect(
                color = Color(0x88FFFFFF),
                topLeft = topLeft,
                size = Size(cellWidth, cellHeight),
                style = Stroke(width = 1f),
            )
        }

        val startX = centralStart(current.width)
        val startY = centralStart(current.height)
        drawRect(
            color = Color(0xFFFFB300),
            topLeft = Offset(
                startX * cellWidth,
                startY * cellHeight,
            ),
            size = Size(
                centralSpan(current.width) * cellWidth,
                centralSpan(current.height) * cellHeight,
            ),
            style = Stroke(width = 4f),
        )

        val centre = Offset(size.width / 2f, size.height / 2f)
        drawLine(
            color = Color.White,
            start = Offset(centre.x - cellWidth * 0.45f, centre.y),
            end = Offset(centre.x + cellWidth * 0.45f, centre.y),
            strokeWidth = 2.5f,
        )
        drawLine(
            color = Color.White,
            start = Offset(centre.x, centre.y - cellHeight * 0.45f),
            end = Offset(centre.x, centre.y + cellHeight * 0.45f),
            strokeWidth = 2.5f,
        )
    }
}

private fun TofFrameV1?.validDistances(): List<Int> =
    this?.let { current ->
        (0 until current.zoneCount)
            .filter(current::isZoneValid)
            .map { current.distanceMm[it] }
    }.orEmpty()

private fun TofFrameV1?.centralValidDistances(): List<Int> =
    this?.let { current ->
        (0 until current.zoneCount)
            .filter { index ->
                current.isZoneValid(index) &&
                    isCentralZone(current, index)
            }
            .map { current.distanceMm[it] }
    }.orEmpty()

private fun isCentralZone(
    frame: TofFrameV1,
    index: Int,
): Boolean {
    if (index !in 0 until frame.zoneCount) return false
    val row = index / frame.width
    val column = index % frame.width
    val startX = centralStart(frame.width)
    val startY = centralStart(frame.height)
    return column in startX until (startX + centralSpan(frame.width)) &&
        row in startY until (startY + centralSpan(frame.height))
}

private fun centralSpan(size: Int): Int =
    min(4, size.coerceAtLeast(1))

private fun centralStart(size: Int): Int =
    ((size - centralSpan(size)) / 2).coerceAtLeast(0)

private fun centralZoneCount(width: Int, height: Int): Int =
    centralSpan(width) * centralSpan(height)

private fun median(values: List<Int>): Int? {
    if (values.isEmpty()) return null
    val sorted = values.sorted()
    val middle = sorted.size / 2
    return if (sorted.size % 2 == 1) {
        sorted[middle]
    } else {
        (sorted[middle - 1] + sorted[middle]) / 2
    }
}

private fun distanceLabel(valueMm: Int?): String =
    valueMm?.let {
        String.format(Locale.US, "%.1f см", it.toDouble() / 10.0)
    } ?: "—"
