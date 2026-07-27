#!/usr/bin/env python3
from __future__ import annotations

import json
import subprocess
import sys
import tempfile
from pathlib import Path


def fake_jpeg(width: int, height: int) -> bytes:
    return (
        b"\xff\xd8"
        + b"\xff\xc0"
        + (17).to_bytes(2, "big")
        + b"\x08"
        + height.to_bytes(2, "big")
        + width.to_bytes(2, "big")
        + b"\x03"
        + b"\x01\x11\x00"
        + b"\x02\x11\x00"
        + b"\x03\x11\x00"
        + b"\xff\xd9"
    )


root = Path(__file__).resolve().parents[1]
validator = (
    root
    / "remote_station"
    / "scripts"
    / "validate_colmap_dense_workspace.py"
)

with tempfile.TemporaryDirectory() as temporary:
    base = Path(temporary)
    model = base / "model"
    images = base / "images"
    model.mkdir()
    images.mkdir()

    (model / "cameras.txt").write_text(
        "# Camera list\n1 PINHOLE 360 240 1 1 1 1\n",
        encoding="utf-8",
    )
    (model / "images.txt").write_text(
        "# Image list\n"
        "1 1 0 0 0 0 0 0 1 frame.jpg\n"
        "\n"
        "2 1 0 0 0 0 0 0 1 other.jpg\n"
        "10.5 20.5 -1\n",
        encoding="utf-8",
    )
    image_list = base / "image_list.txt"
    image_list.write_text("frame.jpg\n", encoding="utf-8")
    stats = base / "stats.json"

    (images / "frame.jpg").write_bytes(fake_jpeg(360, 240))
    ok = subprocess.run(
        [
            sys.executable,
            str(validator),
            str(model),
            str(images),
            str(image_list),
            str(stats),
        ],
        check=False,
        capture_output=True,
        text=True,
    )
    if ok.returncode != 0:
        raise RuntimeError(ok.stdout + ok.stderr)
    result = json.loads(stats.read_text(encoding="utf-8"))
    if result["status"] != "OK":
        raise RuntimeError("valid workspace was rejected")

    (images / "frame.jpg").write_bytes(fake_jpeg(358, 240))
    bad = subprocess.run(
        [
            sys.executable,
            str(validator),
            str(model),
            str(images),
            str(image_list),
            str(stats),
        ],
        check=False,
        capture_output=True,
        text=True,
    )
    if bad.returncode != 3:
        raise RuntimeError(
            f"dimension mismatch returned {bad.returncode}: "
            + bad.stdout
            + bad.stderr
        )
    result = json.loads(stats.read_text(encoding="utf-8"))
    if result["dimension_mismatch_count"] != 1:
        raise RuntimeError("dimension mismatch was not reported")

print("OK")
