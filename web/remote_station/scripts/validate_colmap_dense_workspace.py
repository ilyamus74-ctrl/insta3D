#!/usr/bin/env python3
"""Validate image dimensions in a COLMAP dense workspace before PatchMatch."""

from __future__ import annotations

import argparse
import json
import struct
import sys
from pathlib import Path
from typing import NoReturn


JPEG_SOF_MARKERS = {
    0xC0, 0xC1, 0xC2, 0xC3,
    0xC5, 0xC6, 0xC7,
    0xC9, 0xCA, 0xCB,
    0xCD, 0xCE, 0xCF,
}


def fail(message: str) -> NoReturn:
    raise RuntimeError(message)


def image_size(path: Path) -> tuple[int, int]:
    data = path.read_bytes()
    if data.startswith(b"\x89PNG\r\n\x1a\n"):
        if len(data) < 24:
            fail(f"truncated PNG: {path}")
        width, height = struct.unpack(">II", data[16:24])
        return int(width), int(height)

    if not data.startswith(b"\xff\xd8"):
        fail(f"unsupported image format: {path}")

    offset = 2
    while offset + 1 < len(data):
        while offset < len(data) and data[offset] != 0xFF:
            offset += 1
        while offset < len(data) and data[offset] == 0xFF:
            offset += 1
        if offset >= len(data):
            break

        marker = data[offset]
        offset += 1
        if marker in {0x01, 0xD8, 0xD9, *range(0xD0, 0xD8)}:
            continue
        if offset + 2 > len(data):
            break

        length = int.from_bytes(data[offset : offset + 2], "big")
        if length < 2 or offset + length > len(data):
            fail(f"invalid JPEG segment in {path}")

        if marker in JPEG_SOF_MARKERS:
            if length < 7:
                fail(f"invalid JPEG SOF in {path}")
            height = int.from_bytes(data[offset + 3 : offset + 5], "big")
            width = int.from_bytes(data[offset + 5 : offset + 7], "big")
            return width, height

        offset += length

    fail(f"JPEG dimensions not found: {path}")


def read_cameras(path: Path) -> dict[int, tuple[int, int]]:
    cameras: dict[int, tuple[int, int]] = {}
    for line_number, raw in enumerate(
        path.read_text(encoding="utf-8").splitlines(),
        start=1,
    ):
        line = raw.strip()
        if not line or line.startswith("#"):
            continue
        parts = line.split()
        if len(parts) < 4:
            fail(f"bad cameras.txt line {line_number}")
        camera_id = int(parts[0])
        width = int(parts[2])
        height = int(parts[3])
        cameras[camera_id] = (width, height)
    if not cameras:
        fail("no cameras found")
    return cameras


def read_images(
    path: Path,
    camera_ids: set[int],
) -> dict[str, int]:
    images: dict[str, int] = {}
    for raw in path.read_text(encoding="utf-8").splitlines():
        line = raw.strip()
        if not line or line.startswith("#"):
            continue
        parts = line.split()
        if len(parts) < 10:
            continue
        try:
            int(parts[0])
            for value in parts[1:8]:
                float(value)
            camera_id = int(parts[8])
        except ValueError:
            continue
        name = " ".join(parts[9:])
        if camera_id not in camera_ids:
            continue
        if Path(name).suffix.lower() not in {
            ".jpg", ".jpeg", ".png", ".webp", ".tif", ".tiff", ".bmp"
        }:
            continue
        images[name] = camera_id
    if not images:
        fail("no images found")
    return images


def read_image_list(path: Path) -> list[str]:
    names: list[str] = []
    seen: set[str] = set()
    for raw in path.read_text(encoding="utf-8").splitlines():
        name = raw.strip()
        if not name or name in seen:
            continue
        if Path(name).is_absolute() or ".." in Path(name).parts:
            fail(f"unsafe image name: {name}")
        seen.add(name)
        names.append(name)
    if not names:
        fail("image list is empty")
    return names


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("model_text_dir", type=Path)
    parser.add_argument("images_dir", type=Path)
    parser.add_argument("image_list", type=Path)
    parser.add_argument("stats_json", type=Path)
    args = parser.parse_args()

    cameras = read_cameras(args.model_text_dir / "cameras.txt")
    images = read_images(
        args.model_text_dir / "images.txt",
        set(cameras),
    )
    listed = read_image_list(args.image_list)

    mismatches: list[dict[str, object]] = []
    missing_files: list[str] = []
    missing_model_images: list[str] = []
    checked = 0

    for name in listed:
        camera_id = images.get(name)
        if camera_id is None:
            missing_model_images.append(name)
            continue

        expected = cameras.get(camera_id)
        if expected is None:
            fail(f"camera {camera_id} missing for image {name}")

        image_path = args.images_dir / name
        if not image_path.is_file():
            missing_files.append(name)
            continue

        actual = image_size(image_path)
        checked += 1
        if actual != expected:
            mismatches.append(
                {
                    "image": name,
                    "camera_id": camera_id,
                    "expected_width": expected[0],
                    "expected_height": expected[1],
                    "actual_width": actual[0],
                    "actual_height": actual[1],
                }
            )

    result = {
        "status": (
            "OK"
            if not mismatches and not missing_files and not missing_model_images
            else "ERROR"
        ),
        "listed_images": len(listed),
        "checked_images": checked,
        "dimension_mismatch_count": len(mismatches),
        "missing_file_count": len(missing_files),
        "missing_model_image_count": len(missing_model_images),
        "dimension_mismatches": mismatches,
        "missing_files": missing_files,
        "missing_model_images": missing_model_images,
    }

    args.stats_json.parent.mkdir(parents=True, exist_ok=True)
    args.stats_json.write_text(
        json.dumps(result, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
    )
    print(json.dumps(result, ensure_ascii=False))

    return 0 if result["status"] == "OK" else 3


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except Exception as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        raise SystemExit(2)
