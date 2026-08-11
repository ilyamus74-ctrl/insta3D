package com.maklertour.data.tof

import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test
import java.util.zip.CRC32

class TofFrameV1ParserTest {
    @Test
    fun fragmentedFrameParsesWithZeroCrcErrors() {
        val raw = buildFrame(sequence = 77, timestampUs = 12_345_678L)
        val parser = TofFrameV1Parser()

        val first = parser.feed(
            chunk = raw.copyOfRange(0, 37),
            hostReceivedElapsedRealtimeNs = 100,
        )
        assertTrue(first.frames.isEmpty())
        assertEquals(0, first.crcErrors)

        val second = parser.feed(
            chunk = raw.copyOfRange(37, raw.size),
            hostReceivedElapsedRealtimeNs = 200,
        )

        assertEquals(1, second.frames.size)
        assertEquals(0, second.crcErrors)

        val frame = second.frames.single()
        assertEquals(77L, frame.sequence)
        assertEquals(12_345_678L, frame.rp2040TimestampUs)
        assertEquals(8, frame.width)
        assertEquals(64, frame.zoneCount)
        assertEquals(1000, frame.distanceMm[0])
        assertEquals(1063, frame.distanceMm[63])
        assertEquals(5, frame.targetStatus[0])
        assertEquals(1, frame.nbTargetDetected[0])
        assertTrue(frame.irqTimestampValid)
        assertEquals(200L, frame.hostReceivedElapsedRealtimeNs)
    }

    @Test
    fun badCrcIsRejected() {
        val raw = buildFrame(sequence = 10, timestampUs = 20)
        raw[40] = (raw[40].toInt() xor 0x01).toByte()

        val batch = TofFrameV1Parser().feed(
            chunk = raw,
            hostReceivedElapsedRealtimeNs = 123,
        )

        assertTrue(batch.frames.isEmpty())
        assertEquals(1, batch.crcErrors)
    }

    @Test
    fun parserResynchronizesAfterTextPrefix() {
        val prefix = "binary stream slot=0 protocol=TOF_FRAME_V1\r\n".toByteArray()
        val frame = buildFrame(sequence = 123, timestampUs = 456)
        val bytes = prefix + frame

        val batch = TofFrameV1Parser().feed(
            chunk = bytes,
            hostReceivedElapsedRealtimeNs = 999,
        )

        assertEquals(1, batch.frames.size)
        assertEquals(123L, batch.frames.single().sequence)
        assertEquals(0, batch.crcErrors)
    }

    private fun buildFrame(sequence: Long, timestampUs: Long): ByteArray {
        val zones = 64
        val payloadBytes = zones * 6
        val bytes = ByteArray(28 + payloadBytes + 4)
        var p = 0

        fun put8(v: Int) {
            bytes[p++] = v.toByte()
        }

        fun put16(v: Int) {
            put8(v)
            put8(v ushr 8)
        }

        fun put32(v: Long) {
            repeat(4) { i -> put8((v ushr (8 * i)).toInt()) }
        }

        fun put64(v: Long) {
            repeat(8) { i -> put8((v ushr (8 * i)).toInt()) }
        }

        "TOF1".toByteArray().copyInto(bytes, 0)
        p = 4
        put8(1)
        put8(0)
        put8(8)
        put8(8)
        put8(15)
        put8(61)
        put8(zones)
        put8(1)
        put32(sequence)
        put64(timestampUs)
        put16(payloadBytes)
        put16(0)

        repeat(zones) { z -> put16(1000 + z) }
        repeat(zones) { z -> put16(10 + z) }
        repeat(zones) { put8(5) }
        repeat(zones) { put8(1) }

        val crc = CRC32().apply {
            update(bytes, 0, bytes.size - 4)
        }.value

        repeat(4) { i ->
            bytes[bytes.size - 4 + i] = (crc ushr (8 * i)).toByte()
        }

        return bytes
    }
}
