#!/usr/bin/env python3
from __future__ import annotations

import json
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path


root = Path(__file__).resolve().parents[1]
normalizer = (
    root
    / "remote_station"
    / "scripts"
    / "normalize_colmap_dense_workspace.py"
)
ffmpeg = shutil.which("ffmpeg")
ffprobe = shutil.which("ffprobe")
if ffmpeg is None or ffprobe is None:
    print("SKIP: ffmpeg/ffprobe not installed")
    raise SystemExit(0)


def probe(path: Path) -> str:
    return subprocess.run(
        [
            ffprobe,
            "-v",
            "error",
            "-select_streams",
            "v:0",
            "-show_entries",
            "stream=width,height",
            "-of",
            "csv=s=x:p=0",
            str(path),
        ],
        check=True,
        capture_output=True,
        text=True,
    ).stdout.strip()


with tempfile.TemporaryDirectory() as temporary:
    base = Path(temporary)
    model = base / "model"
    images = base / "images"
    model.mkdir()
    images.mkdir()

    (model / "cameras.txt").write_text(
        "# Camera list\n"
        "1 PINHOLE 358 240 1 1 179 120\n"
        "2 PINHOLE 360 240 1 1 180 120\n",
        encoding="utf-8",
    )
    (model / "images.txt").write_text(
        "# Image list\n"
        "1 1 0 0 0 0 0 0 1 narrow.jpg\n"
        "\n"
        "2 1 0 0 0 0 0 0 2 normal.jpg\n"
        "\n",
        encoding="utf-8",
    )
    config = base / "patch-match.cfg"
    config.write_text(
        "narrow.jpg\nnormal.jpg\n"
        "normal.jpg\nnarrow.jpg\n",
        encoding="utf-8",
    )
    report = base / "report.json"

    for name in ["narrow.jpg", "normal.jpg"]:
        subprocess.run(
            [
                ffmpeg,
                "-nostdin",
                "-hide_banner",
                "-loglevel",
                "error",
                "-f",
                "lavfi",
                "-i",
                "color=c=black:s=360x240",
                "-frames:v",
                "1",
                "-q:v",
                "2",
                str(images / name),
            ],
            check=True,
        )

    result = subprocess.run(
        [
            sys.executable,
            str(normalizer),
            str(model),
            str(images),
            str(config),
            str(report),
        ],
        check=False,
        capture_output=True,
        text=True,
    )
    if result.returncode != 0:
        raise RuntimeError(result.stdout + result.stderr)

    payload = json.loads(report.read_text(encoding="utf-8"))
    if payload["status"] != "OK":
        raise RuntimeError("normalizer rejected repaired workspace")
    if payload["mismatch_count_before"] != 1:
        raise RuntimeError("expected one decoded mismatch")
    if payload["normalized_image_count"] != 1:
        raise RuntimeError("expected one normalized image")
    if payload["mismatch_count_after"] != 0:
        raise RuntimeError("normalized mismatch remained")
    if payload["model_max_dimension"] != 360:
        raise RuntimeError("wrong model max dimension")
    if probe(images / "narrow.jpg") != "358x240":
        raise RuntimeError("narrow image was not normalized")
    if probe(images / "normal.jpg") != "360x240":
        raise RuntimeError("matching image was unexpectedly changed")

print("OK")
