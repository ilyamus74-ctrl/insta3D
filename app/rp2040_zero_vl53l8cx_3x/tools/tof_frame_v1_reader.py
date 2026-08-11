#!/usr/bin/env python3
import argparse
import binascii
import os
import select
import struct
import termios
import time

MAGIC = b"TOF1"
HEADER = 28


def configure_raw(fd):
    attrs = termios.tcgetattr(fd)
    attrs[0] = 0
    attrs[1] = 0
    attrs[2] |= termios.CLOCAL | termios.CREAD
    attrs[3] = 0
    attrs[4] = termios.B115200
    attrs[5] = termios.B115200
    attrs[6][termios.VMIN] = 0
    attrs[6][termios.VTIME] = 0
    termios.tcsetattr(fd, termios.TCSANOW, attrs)


def valid_zone(frame, z):
    return (
        frame["targets"][z] > 0
        and frame["status"][z] in (5, 6, 9)
        and frame["distance"][z] > 0
    )


def parse_frame(raw):
    version, slot, width, height, hz, temp_u8, zones, flags = struct.unpack_from(
        "<BBBBBBBB", raw, 4
    )
    seq = struct.unpack_from("<I", raw, 12)[0]
    ts_us = struct.unpack_from("<Q", raw, 16)[0]
    payload_len = struct.unpack_from("<H", raw, 24)[0]

    temp = struct.unpack("b", bytes([temp_u8]))[0]
    payload = raw[HEADER:HEADER + payload_len]
    p = 0

    distance = list(struct.unpack_from("<" + "h" * zones, payload, p))
    p += zones * 2
    sigma = list(struct.unpack_from("<" + "H" * zones, payload, p))
    p += zones * 2
    status = list(payload[p:p + zones])
    p += zones
    targets = list(payload[p:p + zones])

    return {
        "version": version,
        "slot": slot,
        "width": width,
        "height": height,
        "hz": hz,
        "temp": temp,
        "zones": zones,
        "flags": flags,
        "seq": seq,
        "ts_us": ts_us,
        "distance": distance,
        "sigma": sigma,
        "status": status,
        "targets": targets,
    }


def show(frame):
    w = frame["width"]
    print(
        f"slot={frame['slot']} seq={frame['seq']} ts_us={frame['ts_us']} "
        f"{w}x{w}@{frame['hz']}Hz temp={frame['temp']}C "
        f"irq_ts={bool(frame['flags'] & 1)}"
    )
    for y in range(w):
        row = []
        for x in range(w):
            z = y * w + x
            row.append(
                f"{frame['distance'][z]:5d}" if valid_zone(frame, z) else " ----"
            )
        print(" ".join(row))
    print()


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("device")
    ap.add_argument("--slot", type=int, default=0, choices=(0, 1, 2))
    ap.add_argument("--print-every", type=float, default=0.5)
    args = ap.parse_args()

    fd = os.open(args.device, os.O_RDWR | os.O_NOCTTY | os.O_NONBLOCK)
    try:
        configure_raw(fd)
        os.write(fd, b"print off\n")
        os.write(fd, f"stream {args.slot}\n".encode())

        buf = bytearray()
        frames_ok = 0
        crc_bad = 0
        next_print = 0.0

        while True:
            ready, _, _ = select.select([fd], [], [], 1.0)
            if ready:
                chunk = os.read(fd, 8192)
                if chunk:
                    buf.extend(chunk)

            while True:
                pos = buf.find(MAGIC)
                if pos < 0:
                    if len(buf) > 3:
                        del buf[:-3]
                    break
                if pos:
                    del buf[:pos]
                if len(buf) < HEADER:
                    break

                version = buf[4]
                zones = buf[10]
                payload_len = struct.unpack_from("<H", buf, 24)[0]
                if version != 1 or zones not in (16, 64) or payload_len != zones * 6:
                    del buf[0]
                    continue

                frame_len = HEADER + payload_len + 4
                if len(buf) < frame_len:
                    break

                raw = bytes(buf[:frame_len])
                del buf[:frame_len]

                expected = struct.unpack_from("<I", raw, frame_len - 4)[0]
                actual = binascii.crc32(raw[:-4]) & 0xffffffff
                if actual != expected:
                    crc_bad += 1
                    continue

                frame = parse_frame(raw)
                frames_ok += 1
                now = time.monotonic()
                if now >= next_print:
                    show(frame)
                    print(f"frames_ok={frames_ok} crc_bad={crc_bad}")
                    next_print = now + args.print_every

    finally:
        try:
            os.write(fd, b"stream off\n")
        except OSError:
            pass
        os.close(fd)


if __name__ == "__main__":
    main()
