#!/usr/bin/env python3
"""Build a dense-only image tree while removing unsafe JPEG APP13 metadata."""

from __future__ import annotations

import json
import os
import shutil
import sys
from pathlib import Path
from typing import NoReturn


APP13 = 0xED
STANDALONE_MARKERS = {0x01, 0xD8, 0xD9, *range(0xD0, 0xD8)}


def fail(message: str) -> NoReturn:
    raise RuntimeError(message)


def is_inside(path: Path, root: Path) -> bool:
    try:
        return os.path.commonpath((str(path), str(root))) == str(root)
    except ValueError:
        return False


def strip_jpeg_app13(data: bytes) -> tuple[bytes, int]:
    if not data.startswith(b"\xff\xd8"):
        fail("JPEG SOI marker is missing")

    out = bytearray(data[:2])
    offset = 2
    removed = 0

    while offset < len(data):
        marker_start = offset
        if data[offset] != 0xFF:
            fail(f"invalid JPEG marker at offset {offset}")

        while offset < len(data) and data[offset] == 0xFF:
            offset += 1
        if offset >= len(data):
            fail("truncated JPEG marker")

        marker = data[offset]
        offset += 1

        if marker == 0x00:
            fail(f"unexpected stuffed marker before SOS at offset {marker_start}")

        if marker == 0xDA:
            if offset + 2 > len(data):
                fail("truncated JPEG SOS length")
            segment_length = int.from_bytes(data[offset : offset + 2], "big")
            if segment_length < 2 or offset + segment_length > len(data):
                fail("invalid JPEG SOS segment")
            out.extend(data[marker_start:])
            return bytes(out), removed

        if marker in STANDALONE_MARKERS:
            out.extend(data[marker_start:offset])
            if marker == 0xD9:
                return bytes(out), removed
            continue

        if offset + 2 > len(data):
            fail(f"truncated JPEG segment length for marker 0x{marker:02x}")

        segment_length = int.from_bytes(data[offset : offset + 2], "big")
        if segment_length < 2:
            fail(f"invalid JPEG segment length for marker 0x{marker:02x}")

        segment_end = offset + segment_length
        if segment_end > len(data):
            fail(f"truncated JPEG segment for marker 0x{marker:02x}")

        if marker == APP13:
            removed += 1
        else:
            out.extend(data[marker_start:segment_end])

        offset = segment_end

    fail("JPEG ended before SOS or EOI")


def listed_images(image_list_path: Path) -> list[Path]:
    names: list[Path] = []
    seen: set[str] = set()

    for line_number, raw in enumerate(
        image_list_path.read_text(encoding="utf-8").splitlines(),
        start=1,
    ):
        name = raw.strip()
        if not name:
            continue
        if "\x00" in name:
            fail(f"NUL byte in image list line {line_number}")

        relative = Path(name)
        if relative.is_absolute() or ".." in relative.parts:
            fail(f"unsafe image path in line {line_number}: {name}")
        if name in seen:
            continue

        seen.add(name)
        names.append(relative)

    if not names:
        fail("image list is empty")

    return names


def main(argv: list[str]) -> int:
    if len(argv) != 5:
        print(
            "Usage: sanitize_dense_images.py "
            "<source_root> <image_list_path> <output_root> <stats_json>",
            file=sys.stderr,
        )
        return 2

    source_root = Path(argv[1]).resolve(strict=True)
    image_list_path = Path(argv[2]).resolve(strict=True)
    output_root = Path(argv[3]).resolve(strict=False)
    stats_path = Path(argv[4]).resolve(strict=False)

    if not source_root.is_dir():
        fail(f"source root is not a directory: {source_root}")
    if output_root == Path("/") or output_root == source_root:
        fail(f"unsafe output root: {output_root}")

    if output_root.is_symlink():
        output_root.unlink()
    elif output_root.exists():
        shutil.rmtree(output_root)
    output_root.mkdir(parents=True, exist_ok=True)

    stats = {
        "images_total": 0,
        "jpeg_images": 0,
        "jpeg_images_sanitized": 0,
        "app13_segments_removed": 0,
        "source_bytes": 0,
        "output_bytes": 0,
    }

    for relative in listed_images(image_list_path):
        source = (source_root / relative).resolve(strict=True)
        if not is_inside(source, source_root):
            fail(f"image escapes source root: {relative}")
        if not source.is_file():
            fail(f"image is not a regular file: {relative}")

        destination = output_root / relative
        destination.parent.mkdir(parents=True, exist_ok=True)

        source_data = source.read_bytes()
        output_data = source_data
        removed = 0

        if source.suffix.lower() in {".jpg", ".jpeg"}:
            stats["jpeg_images"] += 1
            output_data, removed = strip_jpeg_app13(source_data)
            if removed > 0:
                stats["jpeg_images_sanitized"] += 1
                stats["app13_segments_removed"] += removed

        temporary = destination.with_name(destination.name + ".tmp")
        temporary.write_bytes(output_data)
        os.replace(temporary, destination)

        stats["images_total"] += 1
        stats["source_bytes"] += len(source_data)
        stats["output_bytes"] += len(output_data)

    stats_path.parent.mkdir(parents=True, exist_ok=True)
    stats_path.write_text(
        json.dumps(stats, ensure_ascii=False, sort_keys=True) + "\n",
        encoding="utf-8",
    )
    print(json.dumps(stats, ensure_ascii=False, sort_keys=True))
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main(sys.argv))
    except Exception as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        raise SystemExit(1)
