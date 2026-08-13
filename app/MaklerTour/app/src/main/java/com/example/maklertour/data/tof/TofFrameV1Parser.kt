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
        chunk.copyInto(data, pending.size, 0, length)

        val frames = mutableListOf<TofFrameV1>()
        val syncReplies = mutableListOf<TofSyncReplyV1>()
        var crcErrors = 0
        var malformedHeaders = 0
        var searchOffset = 0

        while (true) {
            val found = findNextMagic(data, searchOffset)
            if (found == null) {
                pending = tailForMagicResync(data)
                break
            }
            val offset = found.first

            if (found.second == PacketKind.SYNC_REPLY) {
                if (data.size - offset < SYNC_BYTES) {
                    pending = data.copyOfRange(offset, data.size)
                    break
                }
                if (u8(data[offset + 4]) != VERSION) {
                    malformedHeaders++
                    searchOffset = offset + 1
                    continue
                }
                if (!crcValid(data, offset, SYNC_BYTES)) {
                    crcErrors++
                    searchOffset = offset + 1
                    continue
                }

                syncReplies += TofSyncReplyV1(
                    protocolVersion = VERSION,
                    nonce = u32Le(data, offset + 8),
                    rp2040RxTimestampUs = u64Le(data, offset + 12),
                    rp2040TxTimestampUs = u64Le(data, offset + 20),
                    hostReceivedElapsedRealtimeNs = hostReceivedElapsedRealtimeNs,
                )
                searchOffset = offset + SYNC_BYTES
            } else {
                if (data.size - offset < TOF_HEADER_BYTES) {
                    pending = data.copyOfRange(offset, data.size)
                    break
                }

                val version = u8(data[offset + 4])
                val width = u8(data[offset + 6])
                val height = u8(data[offset + 7])
                val zones = u8(data[offset + 10])
                val payloadBytes = u16Le(data, offset + 24)

                if (
                    version != VERSION ||
                    width !in VALID_SIDES ||
                    height != width ||
                    zones != width * height ||
                    payloadBytes != zones * BYTES_PER_ZONE
                ) {
                    malformedHeaders++
                    searchOffset = offset + 1
                    continue
                }

                val frameBytes = TOF_HEADER_BYTES + payloadBytes + CRC_BYTES
                if (data.size - offset < frameBytes) {
                    pending = data.copyOfRange(offset, data.size)
                    break
                }
                if (!crcValid(data, offset, frameBytes)) {
                    crcErrors++
                    searchOffset = offset + 1
                    continue
                }

                frames += parseFrame(data, offset, hostReceivedElapsedRealtimeNs)
                searchOffset = offset + frameBytes
            }

            if (searchOffset >= data.size) {
                pending = ByteArray(0)
                break
            }
        }

        return TofParseBatch(
            frames = frames,
            syncReplies = syncReplies,
            crcErrors = crcErrors,
            malformedHeaders = malformedHeaders,
        )
    }

    private fun parseFrame(
        data: ByteArray,
        offset: Int,
        hostReceivedElapsedRealtimeNs: Long,
    ): TofFrameV1 {
        val width = u8(data[offset + 6])
        val zones = u8(data[offset + 10])
        var p = offset + TOF_HEADER_BYTES

        val distance = IntArray(zones)
        repeat(zones) { z ->
            val raw = u16Le(data, p)
            distance[z] = if (raw >= 0x8000) raw - 0x1_0000 else raw
            p += 2
        }

        val sigma = IntArray(zones)
        repeat(zones) { z -> sigma[z] = u16Le(data, p); p += 2 }

        val status = IntArray(zones)
        repeat(zones) { z -> status[z] = u8(data[p++]) }

        val targets = IntArray(zones)
        repeat(zones) { z -> targets[z] = u8(data[p++]) }

        return TofFrameV1(
            protocolVersion = u8(data[offset + 4]),
            slot = u8(data[offset + 5]),
            width = width,
            height = u8(data[offset + 7]),
            frequencyHz = u8(data[offset + 8]),
            siliconTemperatureC = data[offset + 9].toInt(),
            sequence = u32Le(data, offset + 12),
            rp2040TimestampUs = u64Le(data, offset + 16),
            irqTimestampValid = u8(data[offset + 11]) and FLAG_IRQ_TIMESTAMP != 0,
            distanceMm = distance,
            rangeSigmaMm = sigma,
            targetStatus = status,
            nbTargetDetected = targets,
            hostReceivedElapsedRealtimeNs = hostReceivedElapsedRealtimeNs,
        )
    }

    private fun crcValid(data: ByteArray, offset: Int, packetBytes: Int): Boolean {
        val expected = u32Le(data, offset + packetBytes - CRC_BYTES)
        val actual = CRC32().apply {
            update(data, offset, packetBytes - CRC_BYTES)
        }.value and 0xffff_ffffL
        return expected == actual
    }

    private fun findNextMagic(data: ByteArray, start: Int): Pair<Int, PacketKind>? {
        var i = start.coerceAtLeast(0)
        while (i <= data.size - 4) {
            if (matches(data, i, TOF_MAGIC)) return i to PacketKind.TOF_FRAME
            if (matches(data, i, SYNC_MAGIC)) return i to PacketKind.SYNC_REPLY
            i++
        }
        return null
    }

    private fun matches(data: ByteArray, offset: Int, magic: ByteArray): Boolean =
        magic.indices.all { data[offset + it] == magic[it] }

    private fun tailForMagicResync(data: ByteArray): ByteArray {
        val keep = minOf(3, data.size)
        return if (keep == 0) ByteArray(0) else data.copyOfRange(data.size - keep, data.size)
    }

    private fun u8(value: Byte): Int = value.toInt() and 0xff
    private fun u16Le(data: ByteArray, offset: Int): Int =
        u8(data[offset]) or (u8(data[offset + 1]) shl 8)

    private fun u32Le(data: ByteArray, offset: Int): Long =
        u8(data[offset]).toLong() or
            (u8(data[offset + 1]).toLong() shl 8) or
            (u8(data[offset + 2]).toLong() shl 16) or
            (u8(data[offset + 3]).toLong() shl 24)

    private fun u64Le(data: ByteArray, offset: Int): Long {
        var value = 0L
        repeat(8) { i -> value = value or (u8(data[offset + i]).toLong() shl (8 * i)) }
        return value
    }

    private enum class PacketKind { TOF_FRAME, SYNC_REPLY }

    private companion object {
        val TOF_MAGIC = "TOF1".toByteArray()
        val SYNC_MAGIC = "TSY1".toByteArray()
        const val VERSION = 1
        const val TOF_HEADER_BYTES = 28
        const val SYNC_BYTES = 32
        const val CRC_BYTES = 4
        const val BYTES_PER_ZONE = 6
        const val FLAG_IRQ_TIMESTAMP = 0x01
        val VALID_SIDES = setOf(4, 8)
    }
}
