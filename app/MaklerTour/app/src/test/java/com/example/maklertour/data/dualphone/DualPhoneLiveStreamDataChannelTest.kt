package com.example.maklertour.data.dualphone

import com.maklertour.data.dualphone.DualPhoneRole
import java.net.ServerSocket
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

class DualPhoneLiveStreamDataChannelTest {
    @Test
    fun reciprocalIdentityIsAccepted() {
        val master = hello(
            role = DualPhoneRole.MASTER,
            localDeviceId = "master-device",
            expectedPeerDeviceId = "slave-device",
        )
        val slave = hello(
            role = DualPhoneRole.SLAVE,
            localDeviceId = "slave-device",
            expectedPeerDeviceId = "master-device",
        )

        master.validatePeer(slave)
        slave.validatePeer(master)
    }

    @Test(expected = IllegalArgumentException::class)
    fun calibrationMismatchIsRejected() {
        val master = hello(
            role = DualPhoneRole.MASTER,
            localDeviceId = "master-device",
            expectedPeerDeviceId = "slave-device",
        )
        val slave = hello(
            role = DualPhoneRole.SLAVE,
            localDeviceId = "slave-device",
            expectedPeerDeviceId = "master-device",
        ).copy(calibrationIdentity = "other-calibration")

        master.validatePeer(slave)
    }

    @Test
    fun loopbackChannelReachesReadyAndExchangesHeartbeats() {
        val port = freePort()
        val slave = DualPhoneLiveStreamDataChannelController()
        val master = DualPhoneLiveStreamDataChannelController()

        try {
            slave.start(
                config(
                    role = DualPhoneRole.SLAVE,
                    localDeviceId = "slave-device",
                    expectedPeerDeviceId = "master-device",
                    port = port,
                ),
            )
            master.start(
                config(
                    role = DualPhoneRole.MASTER,
                    localDeviceId = "master-device",
                    expectedPeerDeviceId = "slave-device",
                    port = port,
                    peerHost = "127.0.0.1",
                ),
            )

            waitUntil {
                slave.snapshot.ready && master.snapshot.ready
            }
            waitUntil {
                master.snapshot.packetsSent >= 2L &&
                    master.snapshot.packetsReceived >= 2L &&
                    slave.snapshot.packetsSent >= 2L &&
                    slave.snapshot.packetsReceived >= 2L
            }

            assertEquals(
                "slave-device",
                master.snapshot.remoteDeviceId,
            )
            assertEquals(
                "master-device",
                slave.snapshot.remoteDeviceId,
            )
            assertTrue(master.snapshot.lastRoundTripMs != null)
        } finally {
            master.close()
            slave.close()
        }
    }

    private fun config(
        role: DualPhoneRole,
        localDeviceId: String,
        expectedPeerDeviceId: String,
        port: Int,
        peerHost: String? = null,
    ) = DualPhoneLiveStreamDataChannelConfig(
        owner = DualPhoneLiveStreamOwner(
            sessionUuid = "session-a",
            dualCaptureId = "capture-a",
            localRole = role.name,
            peerIdentity = expectedPeerDeviceId,
            cameraIdentity = if (role == DualPhoneRole.MASTER) {
                "master-camera"
            } else {
                "slave-camera"
            },
            recordingModeIdentity = "calibrated_size=640x360",
            calibrationIdentity = "calibration-a",
            rigMountRevision = "rev-a",
            captureMode = DualPhoneLiveStreamMode.LIVE_METRIC,
            streamId = "stream-${role.name.lowercase()}",
        ),
        localDeviceId = localDeviceId,
        role = role,
        peerHost = peerHost,
        port = port,
    )

    private fun hello(
        role: DualPhoneRole,
        localDeviceId: String,
        expectedPeerDeviceId: String,
    ) = DualPhoneLiveStreamDataChannelHello(
        sessionUuid = "session-a",
        dualCaptureId = "capture-a",
        streamId = "stream-${role.name.lowercase()}",
        localDeviceId = localDeviceId,
        expectedPeerDeviceId = expectedPeerDeviceId,
        role = role,
        calibrationIdentity = "calibration-a",
        rigMountRevision = "rev-a",
        captureMode = DualPhoneLiveStreamMode.LIVE_METRIC,
        recordingModeIdentity = "calibrated_size=640x360",
    )

    private fun freePort(): Int =
        ServerSocket(0).use { it.localPort }

    private fun waitUntil(
        timeoutMs: Long = 8_000L,
        condition: () -> Boolean,
    ) {
        val deadline = System.currentTimeMillis() + timeoutMs
        while (System.currentTimeMillis() < deadline) {
            if (condition()) return
            Thread.sleep(25L)
        }
        error("Condition was not met; timeout=${timeoutMs}ms")
    }
}
