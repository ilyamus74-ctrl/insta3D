package com.maklertour.data.tof

import org.junit.Assert.assertEquals
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertTrue
import org.junit.Test
import kotlin.math.PI
import kotlin.math.abs
import kotlin.math.cos
import kotlin.math.roundToInt
import kotlin.math.sin
import kotlin.math.sqrt

class TofCameraExtrinsicsSolverTest {
    @Test
    fun recoversSyntheticTofRaysAndRigidTransform() {
        val trueIntrinsics = TofZoneIntrinsics(
            fxZones = 8.10,
            fyZones = 8.70,
            cxZones = 3.45,
            cyZones = 3.60,
        )
        val trueRotationVector = doubleArrayOf(
            Math.toRadians(1.0),
            Math.toRadians(-1.5),
            Math.toRadians(0.7),
        )
        val trueRotation = rotationMatrix(trueRotationVector)
        val trueTranslation = doubleArrayOf(8.0, 12.0, -16.0)

        val planes = listOf(
            plane(0.00, 0.00, 1.00, 600.0),
            plane(0.18, 0.00, 1.00, 700.0),
            plane(-0.18, 0.08, 1.00, 800.0),
            plane(0.00, 0.21, 1.00, 900.0),
            plane(0.14, -0.18, 1.00, 1_000.0),
            plane(-0.21, -0.14, 1.00, 1_100.0),
            plane(0.25, 0.18, 1.00, 750.0),
            plane(-0.25, 0.21, 1.00, 950.0),
            plane(0.09, 0.27, 1.00, 1_200.0),
            plane(-0.09, -0.27, 1.00, 650.0),
        )

        val samples = planes.mapIndexed { sampleIndex, boardPlane ->
            syntheticSample(
                sampleIndex = sampleIndex,
                intrinsics = trueIntrinsics,
                rotation = trueRotation,
                translation = trueTranslation,
                boardPlane = boardPlane,
            )
        }

        val result = TofCameraExtrinsicsSolver().solve(samples)

        assertTrue(result.status, result.successful)
        val solvedIntrinsics = result.tofIntrinsics
        assertNotNull(solvedIntrinsics)
        solvedIntrinsics!!
        assertEquals(trueIntrinsics.fxZones, solvedIntrinsics.fxZones, 0.25)
        assertEquals(trueIntrinsics.fyZones, solvedIntrinsics.fyZones, 0.25)
        assertEquals(trueIntrinsics.cxZones, solvedIntrinsics.cxZones, 0.12)
        assertEquals(trueIntrinsics.cyZones, solvedIntrinsics.cyZones, 0.12)

        assertEquals(trueTranslation[0], result.translationToCameraMm[0], 3.0)
        assertEquals(trueTranslation[1], result.translationToCameraMm[1], 3.0)
        assertEquals(trueTranslation[2], result.translationToCameraMm[2], 3.0)
        assertTrue((result.planeRmsMm ?: Double.MAX_VALUE) < 3.0)
        assertTrue((result.planeP95Mm ?: Double.MAX_VALUE) < 5.0)
    }

    private fun syntheticSample(
        sampleIndex: Int,
        intrinsics: TofZoneIntrinsics,
        rotation: DoubleArray,
        translation: DoubleArray,
        boardPlane: TofCameraBoardPlane,
    ): TofCameraPlanarCalibrationSample {
        val zones = buildList {
            for (zoneIndex in 0 until 64) {
                val row = zoneIndex / 8
                val column = zoneIndex % 8
                var x = (column - intrinsics.cxZones) / intrinsics.fxZones
                var y = (row - intrinsics.cyZones) / intrinsics.fyZones
                var z = 1.0
                val norm = sqrt(x * x + y * y + z * z)
                x /= norm
                y /= norm
                z /= norm

                val rx =
                    rotation[0] * x +
                        rotation[1] * y +
                        rotation[2] * z
                val ry =
                    rotation[3] * x +
                        rotation[4] * y +
                        rotation[5] * z
                val rz =
                    rotation[6] * x +
                        rotation[7] * y +
                        rotation[8] * z
                val denominator =
                    boardPlane.normalX * rx +
                        boardPlane.normalY * ry +
                        boardPlane.normalZ * rz
                val numerator = -(
                    boardPlane.normalX * translation[0] +
                        boardPlane.normalY * translation[1] +
                        boardPlane.normalZ * translation[2] +
                        boardPlane.dMm
                    )
                val distance = numerator / denominator
                if (distance > 0.0 && distance.isFinite()) {
                    val measuredDistance =
                        if ((zoneIndex + sampleIndex) % 7 == 0) {
                            distance.roundToInt() + 450
                        } else {
                            distance.roundToInt()
                        }
                    add(
                        TofZoneRangeObservation(
                            zoneIndex = zoneIndex,
                            distanceMm = measuredDistance,
                            sigmaMm = 8,
                            targetStatus = 5,
                            nbTargetDetected = 1,
                        ),
                    )
                }
            }
        }

        return TofCameraPlanarCalibrationSample(
            cameraElapsedRealtimeNs =
                1_000_000_000L + sampleIndex * 100_000_000L,
            tofMappedElapsedRealtimeNs =
                1_000_500_000L + sampleIndex * 100_000_000L,
            tofSequence = sampleIndex.toLong(),
            pairDeltaUs = 500L,
            pairThresholdUs = 35_333L,
            tofSlot = 0,
            tofWidth = 8,
            tofHeight = 8,
            boardPlane = boardPlane,
            zones = zones,
        )
    }

    private fun plane(
        nx: Double,
        ny: Double,
        nz: Double,
        distanceMm: Double,
    ): TofCameraBoardPlane {
        val norm = sqrt(nx * nx + ny * ny + nz * nz)
        return TofCameraBoardPlane(
            normalX = nx / norm,
            normalY = ny / norm,
            normalZ = nz / norm,
            dMm = -distanceMm,
            charucoCornersUsed = 20,
        )
    }

    private fun rotationMatrix(
        vector: DoubleArray,
    ): DoubleArray {
        val rx = vector[0]
        val ry = vector[1]
        val rz = vector[2]
        val theta = sqrt(rx * rx + ry * ry + rz * rz)
        if (theta < 1e-12) {
            return doubleArrayOf(
                1.0, 0.0, 0.0,
                0.0, 1.0, 0.0,
                0.0, 0.0, 1.0,
            )
        }
        val x = rx / theta
        val y = ry / theta
        val z = rz / theta
        val c = cos(theta)
        val s = sin(theta)
        val oneMinusC = 1.0 - c
        return doubleArrayOf(
            c + x * x * oneMinusC,
            x * y * oneMinusC - z * s,
            x * z * oneMinusC + y * s,
            y * x * oneMinusC + z * s,
            c + y * y * oneMinusC,
            y * z * oneMinusC - x * s,
            z * x * oneMinusC - y * s,
            z * y * oneMinusC + x * s,
            c + z * z * oneMinusC,
        )
    }
}
