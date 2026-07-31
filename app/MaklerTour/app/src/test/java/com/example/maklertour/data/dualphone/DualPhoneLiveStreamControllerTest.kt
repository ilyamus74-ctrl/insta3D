package com.example.maklertour.data.dualphone

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

class DualPhoneLiveStreamControllerTest {
    @Test
    fun syncVideoDoesNotCreateStreamOwner() {
        val controller = DualPhoneLiveStreamController()

        assertFalse(controller.prepare(owner(mode = DualPhoneLiveStreamMode.SYNC_VIDEO)))
        assertEquals(DualPhoneLiveStreamState.DISABLED, controller.snapshot.state)
        assertNull(controller.snapshot.owner)
    }

    @Test
    fun lifecycleRequiresMatchingStreamId() {
        val controller = DualPhoneLiveStreamController()
        val owner = owner()

        assertTrue(controller.prepare(owner))
        assertEquals(DualPhoneLiveStreamState.PREPARING, controller.snapshot.state)
        assertFalse(controller.markReady("another-stream"))
        assertTrue(controller.markReady(owner.streamId))
        assertTrue(controller.start(owner.streamId))
        assertEquals(DualPhoneLiveStreamState.STREAMING, controller.snapshot.state)
        assertTrue(controller.beginStop(owner.streamId))
        assertTrue(controller.completeStop(owner.streamId))
        assertEquals(DualPhoneLiveStreamState.STOPPED, controller.snapshot.state)

        controller.release()
        assertEquals(DualPhoneLiveStreamState.DISABLED, controller.snapshot.state)
        assertNull(controller.snapshot.owner)
    }

    @Test
    fun sessionChangeReleasesCurrentStream() {
        val controller = DualPhoneLiveStreamController()
        val first = owner()
        val next = first.copy(
            sessionUuid = "session-b",
            dualCaptureId = "capture-b",
            streamId = "stream-b",
        )

        assertTrue(controller.prepare(first))
        assertTrue(controller.markReady(first.streamId))
        assertTrue(controller.reconcileOwner(next))
        assertEquals(DualPhoneLiveStreamState.DISABLED, controller.snapshot.state)
        assertNull(controller.snapshot.owner)
    }

    @Test
    fun reconnectIsBoundToOwnerAndIncrementsCounter() {
        val controller = DualPhoneLiveStreamController()
        val owner = owner()

        assertTrue(controller.prepare(owner))
        assertTrue(controller.markReady(owner.streamId))
        assertTrue(controller.start(owner.streamId))
        assertTrue(controller.markDegraded(owner.streamId, "peer timeout"))
        assertFalse(controller.markReconnecting("wrong-stream"))
        assertTrue(controller.markReconnecting(owner.streamId))
        assertEquals(1L, controller.snapshot.stats.connectionRestarts)
        assertTrue(controller.markReady(owner.streamId))
        assertEquals(DualPhoneLiveStreamState.READY, controller.snapshot.state)
    }

    @Test
    fun frameEnvelopePreservesRawOrientation() {
        val frame = DualPhoneLiveStreamFrame(
            streamId = "stream-a",
            dualCaptureId = "capture-a",
            sessionUuid = "session-a",
            role = "SLAVE",
            frameSequence = 1L,
            sensorTimestampNs = 10L,
            captureElapsedRealtimeNs = 20L,
            timestampSource = "CAMERA2_SENSOR",
            clockModelRevision = 3L,
            width = 640,
            height = 360,
            imageProxyRotationDegrees = 90,
            payloadSizeBytes = 1024,
            payloadCrc32 = 1234L,
        )

        assertEquals(0, frame.rotationAppliedDegrees)
        assertEquals(DualPhoneLiveStreamEncoding.JPEG, frame.encoding)
    }

    @Test(expected = IllegalArgumentException::class)
    fun frameEnvelopeRejectsDisplayRotationAppliedToPixels() {
        DualPhoneLiveStreamFrame(
            streamId = "stream-a",
            dualCaptureId = "capture-a",
            sessionUuid = "session-a",
            role = "SLAVE",
            frameSequence = 1L,
            sensorTimestampNs = 10L,
            captureElapsedRealtimeNs = 20L,
            timestampSource = "CAMERA2_SENSOR",
            clockModelRevision = 3L,
            width = 640,
            height = 360,
            rotationAppliedDegrees = 90,
            imageProxyRotationDegrees = 90,
            payloadSizeBytes = 1024,
            payloadCrc32 = 1234L,
        )
    }

    private fun owner(
        mode: DualPhoneLiveStreamMode = DualPhoneLiveStreamMode.LIVE_METRIC,
    ): DualPhoneLiveStreamOwner = DualPhoneLiveStreamOwner(
        sessionUuid = "session-a",
        dualCaptureId = "capture-a",
        localRole = "MASTER",
        peerIdentity = "peer-b",
        cameraIdentity = "camera-0",
        recordingModeIdentity = "1920x1080@30",
        calibrationIdentity = "calibration-a",
        rigMountRevision = "mount-1",
        captureMode = mode,
        streamId = "stream-a",
    )
}
