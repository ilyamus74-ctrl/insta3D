package com.maklertour.data.tof

import java.util.zip.CRC32

class TofFrameV1Parser {
    private var pending = ByteArray(0)

    fun reset() {
        pending = ByteArray(0)
    }

    fun feed(
        chunk: ByteArray,
        length: Int = chunk.size,
        hostReceivedElapsedRealtimeNs: Long,
    ): TofParseBatch {
        require(length in 0..chunk.size)

        val data = ByteArray(pending.size + length)
        pending.copyInto(data, destinationOffset = 0)
        chunk.copyInto(
            destination = data,
            destinationOffset = pending.size,
            startIndex = 0,
            endIndex = length,
        )

        val frames = mutableListOf<TofFrameV1>()
        var crcErrors = 0
        var malformedHeaders = 0
        var searchOffset = 0

        while (true) {
            val magicOffset = findMagic(data, searchOffset)
            if (magicOffset < 0) {
                pending = tailForMagicResync(data)
                break
            }

            if (data.size - magicOffset < HEADER_BYTES) {
                pending = data.copyOfRange(magicOffset, data.size)
                break
            }

            val version = u8(data[magicOffset + 4])
            val width = u8(data[magicOffset + 6])
            val height = u8(data[magicOffset + 7])
            val zones = u8(data[magicOffset + 10])
            val payloadBytes = u16Le(data, magicOffset + 24)

            val headerValid =
                version == VERSION &&
                    width in VALID_SIDES &&
                    height == width &&
                    zones == width * height &&
                    payloadBytes == zones * BYTES_PER_ZONE

            if (!headerValid) {
                malformedHeaders++
                searchOffset = magicOffset + 1
                continue
            }

            val frameBytes = HEADER_BYTES + payloadBytes + CRC_BYTES
            if (data.size - magicOffset < frameBytes) {
                pending = data.copyOfRange(magicOffset, data.size)
                break
            }

            val expectedCrc = u32Le(data, magicOffset + frameBytes - CRC_BYTES)
            val crc = CRC32().apply {
                update(data, magicOffset, frameBytes - CRC_BYTES)
            }.value and 0xffff_ffffL

            if (crc != expectedCrc) {
                crcErrors++
                searchOffset = magicOffset + 1
                continue
            }

            frames += parseFrame(
                data = data,
                offset = magicOffset,
                hostReceivedElapsedRealtimeNs = hostReceivedElapsedRealtimeNs,
            )
            searchOffset = magicOffset + frameBytes

            if (searchOffset >= data.size) {
                pending = ByteArray(0)
                break
            }
        }

        return TofParseBatch(
            frames = frames,
            crcErrors = crcErrors,
            malformedHeaders = malformedHeaders,
        )
    }

    private fun parseFrame(
        data: ByteArray,
        offset: Int,
        hostReceivedElapsedRealtimeNs: Long,
    ): TofFrameV1 {
        val version = u8(data[offset + 4])
        val slot = u8(data[offset + 5])
        val width = u8(data[offset + 6])
        val height = u8(data[offset + 7])
        val hz = u8(data[offset + 8])
        val temperatureC = data[offset + 9].toInt()
        val zones = u8(data[offset + 10])
        val flags = u8(data[offset + 11])
        val sequence = u32Le(data, offset + 12)
        val timestampUs = u64Le(data, offset + 16)

        var p = offset + HEADER_BYTES

        val distance = IntArray(zones)
        repeat(zones) { z ->
            val raw = u16Le(data, p)
            distance[z] = if (raw >= 0x8000) raw - 0x1_0000 else raw
            p += 2
        }

        val sigma = IntArray(zones)
        repeat(zones) { z ->
            sigma[z] = u16Le(data, p)
            p += 2
        }

        val status = IntArray(zones)
        repeat(zones) { z ->
            status[z] = u8(data[p++])
        }

        val targets = IntArray(zones)
        repeat(zones) { z ->
            targets[z] = u8(data[p++])
        }

        return TofFrameV1(
            protocolVersion = version,
            slot = slot,
            width = width,
            height = height,
            frequencyHz = hz,
            siliconTemperatureC = temperatureC,
            sequence = sequence,
            rp2040TimestampUs = timestampUs,
            irqTimestampValid = flags and FLAG_IRQ_TIMESTAMP != 0,
            distanceMm = distance,
            rangeSigmaMm = sigma,
            targetStatus = status,
            nbTargetDetected = targets,
            hostReceivedElapsedRealtimeNs = hostReceivedElapsedRealtimeNs,
        )
    }

    private fun findMagic(data: ByteArray, start: Int): Int {
        var i = start.coerceAtLeast(0)
        while (i <= data.size - MAGIC.size) {
            var matches = true
            for (j in MAGIC.indices) {
                if (data[i + j] != MAGIC[j]) {
                    matches = false
                    break
                }
            }
            if (matches) return i
            i++
        }
        return -1
    }

    private fun tailForMagicResync(data: ByteArray): ByteArray {
        val keep = minOf(MAGIC.size - 1, data.size)
        return if (keep == 0) ByteArray(0) else data.copyOfRange(data.size - keep, data.size)
    }

    private fun u8(value: Byte): Int = value.toInt() and 0xff

    private fun u16Le(data: ByteArray, offset: Int): Int =
        u8(data[offset]) or (u8(data[offset + 1]) shl 8)

    private fun u32Le(data: ByteArray, offset: Int): Long =
        (u8(data[offset]).toLong()) or
            (u8(data[offset + 1]).toLong() shl 8) or
            (u8(data[offset + 2]).toLong() shl 16) or
            (u8(data[offset + 3]).toLong() shl 24)

    private fun u64Le(data: ByteArray, offset: Int): Long {
        var value = 0L
        for (i in 0 until 8) {
            value = value or (u8(data[offset + i]).toLong() shl (8 * i))
        }
        return value
    }

    private companion object {
        val MAGIC = byteArrayOf('T'.code.toByte(), 'O'.code.toByte(), 'F'.code.toByte(), '1'.code.toByte())
        const val VERSION = 1
        const val HEADER_BYTES = 28
        const val CRC_BYTES = 4
        const val BYTES_PER_ZONE = 6
        const val FLAG_IRQ_TIMESTAMP = 0x01
        val VALID_SIDES = setOf(4, 8)
    }
}
