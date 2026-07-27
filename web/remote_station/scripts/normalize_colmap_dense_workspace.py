#!/usr/bin/env python3
"""Normalize decoded dense-workspace images to COLMAP model dimensions."""

from __future__ import annotations

import argparse
import json
import os
import shutil
import subprocess
import sys
from pathlib import Path
from typing import NoReturn


IMAGE_SUFFIXES = {
    ".jpg",
    ".jpeg",
    ".png",
    ".webp",
    ".tif",
    ".tiff",
    ".bmp",
}


def fail(message: str) -> NoReturn:
    raise RuntimeError(message)


def safe_name(name: str) -> str:
    value = name.strip()
    path = Path(value)
    if not value or path.is_absolute() or ".." in path.parts:
        fail(f"unsafe image name: {name!r}")
    return value


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
        if width <= 0 or height <= 0:
            fail(f"invalid camera dimensions on line {line_number}")
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
        name = safe_name(" ".join(parts[9:]))
        if (
            camera_id in camera_ids
            and Path(name).suffix.lower() in IMAGE_SUFFIXES
        ):
            images[name] = camera_id
    if not images:
        fail("no registered images found")
    return images


def read_patch_match_config(path: Path) -> list[str]:
    lines = [
        line.strip()
        for line in path.read_text(encoding="utf-8").splitlines()
        if line.strip()
    ]
    if len(lines) < 4 or len(lines) % 2 != 0:
        fail(
            "patch-match.cfg must contain reference/source line pairs"
        )
    names: list[str] = []
    seen: set[str] = set()
    for index in range(0, len(lines), 2):
        values = [lines[index]]
        values.extend(lines[index + 1].split(","))
        for raw in values:
            name = safe_name(raw)
            if name not in seen:
                seen.add(name)
                names.append(name)
    if not names:
        fail("patch-match.cfg contains no images")
    return names


def run_checked(
    command: list[str],
    *,
    capture: bool = True,
) -> subprocess.CompletedProcess[str]:
    return subprocess.run(
        command,
        check=True,
        text=True,
        capture_output=capture,
    )


def decoded_size(ffprobe: str, path: Path) -> tuple[int, int]:
    result = run_checked(
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
        ]
    )
    value = result.stdout.strip().splitlines()
    if len(value) != 1 or "x" not in value[0]:
        fail(f"ffprobe returned invalid dimensions for {path}")
    width_text, height_text = value[0].split("x", 1)
    width = int(width_text)
    height = int(height_text)
    if width <= 0 or height <= 0:
        fail(f"ffprobe returned invalid dimensions for {path}")
    return width, height


def normalize_image(
    ffmpeg: str,
    path: Path,
    width: int,
    height: int,
) -> None:
    temporary = path.with_name(
        f"{path.stem}.normalize.{os.getpid()}{path.suffix}"
    )
    try:
        command = [
            ffmpeg,
            "-nostdin",
            "-hide_banner",
            "-loglevel",
            "error",
            "-y",
            "-i",
            str(path),
            "-vf",
            f"scale={width}:{height}:flags=lanczos,setsar=1",
            "-frames:v",
            "1",
        ]
        if path.suffix.lower() in {".jpg", ".jpeg"}:
            command.extend(["-q:v", "2"])
        command.append(str(temporary))
        run_checked(command)
        if not temporary.is_file() or temporary.stat().st_size <= 0:
            fail(f"ffmpeg produced no output for {path}")
        os.replace(temporary, path)
    finally:
        temporary.unlink(missing_ok=True)


def write_report(path: Path, payload: dict[str, object]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    temporary = path.with_name(
        f"{path.name}.tmp.{os.getpid()}"
    )
    temporary.write_text(
        json.dumps(
            payload,
            ensure_ascii=False,
            indent=2,
            sort_keys=True,
        )
        + "\n",
        encoding="utf-8",
    )
    os.replace(temporary, path)


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("model_text_dir", type=Path)
    parser.add_argument("images_dir", type=Path)
    parser.add_argument("patch_match_config", type=Path)
    parser.add_argument("report_json", type=Path)
    parser.add_argument("--ffprobe-bin", default="ffprobe")
    parser.add_argument("--ffmpeg-bin", default="ffmpeg")
    parser.add_argument(
        "--no-normalize",
        action="store_true",
        help="Report decoded dimension mismatches without rewriting images",
    )
    args = parser.parse_args()

    ffprobe = shutil.which(args.ffprobe_bin)
    ffmpeg = shutil.which(args.ffmpeg_bin)
    if ffprobe is None:
        fail(f"ffprobe not found: {args.ffprobe_bin}")
    if not args.no_normalize and ffmpeg is None:
        fail(f"ffmpeg not found: {args.ffmpeg_bin}")

    cameras = read_cameras(args.model_text_dir / "cameras.txt")
    images = read_images(
        args.model_text_dir / "images.txt",
        set(cameras),
    )
    configured_names = read_patch_match_config(
        args.patch_match_config
    )

    missing_model_images: list[str] = []
    missing_files: list[str] = []
    mismatches_before: list[dict[str, object]] = []
    mismatches_after: list[dict[str, object]] = []
    normalized_images: list[dict[str, object]] = []
    model_dimensions: set[tuple[int, int]] = set()
    decoded_dimensions_before: set[tuple[int, int]] = set()

    for name in configured_names:
        camera_id = images.get(name)
        if camera_id is None:
            missing_model_images.append(name)
            continue

        expected = cameras[camera_id]
        model_dimensions.add(expected)
        image_path = args.images_dir / name
        if not image_path.is_file() or image_path.is_symlink():
            missing_files.append(name)
            continue

        actual_before = decoded_size(ffprobe, image_path)
        decoded_dimensions_before.add(actual_before)
        if actual_before == expected:
            continue

        mismatch = {
            "image": name,
            "camera_id": camera_id,
            "expected_width": expected[0],
            "expected_height": expected[1],
            "decoded_width": actual_before[0],
            "decoded_height": actual_before[1],
        }
        mismatches_before.append(mismatch)

        if not args.no_normalize:
            assert ffmpeg is not None
            normalize_image(
                ffmpeg,
                image_path,
                expected[0],
                expected[1],
            )
            actual_after = decoded_size(ffprobe, image_path)
            normalized_images.append(
                {
                    **mismatch,
                    "normalized_width": actual_after[0],
                    "normalized_height": actual_after[1],
                }
            )
            if actual_after != expected:
                mismatches_after.append(
                    {
                        "image": name,
                        "camera_id": camera_id,
                        "expected_width": expected[0],
                        "expected_height": expected[1],
                        "decoded_width": actual_after[0],
                        "decoded_height": actual_after[1],
                    }
                )
        else:
            mismatches_after.append(mismatch)

    model_max_dimension = max(
        (max(width, height) for width, height in model_dimensions),
        default=1,
    )
    status = (
        "OK"
        if not missing_model_images
        and not missing_files
        and not mismatches_after
        else "ERROR"
    )
    report: dict[str, object] = {
        "status": status,
        "configured_images": len(configured_names),
        "model_dimension_variants": [
            {"width": width, "height": height}
            for width, height in sorted(model_dimensions)
        ],
        "decoded_dimension_variants_before": [
            {"width": width, "height": height}
            for width, height in sorted(decoded_dimensions_before)
        ],
        "mixed_model_dimensions": len(model_dimensions) > 1,
        "model_max_dimension": model_max_dimension,
        "force_patchmatch_bitmap_rescale": True,
        "mismatch_count_before": len(mismatches_before),
        "normalized_image_count": len(normalized_images),
        "mismatch_count_after": len(mismatches_after),
        "mismatches_before": mismatches_before,
        "normalized_images": normalized_images,
        "mismatches_after": mismatches_after,
        "missing_model_images": missing_model_images,
        "missing_files": missing_files,
        "ffprobe_bin": ffprobe,
        "ffmpeg_bin": ffmpeg,
    }
    write_report(args.report_json, report)
    print(model_max_dimension)
    return 0 if status == "OK" else 3


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except subprocess.CalledProcessError as exc:
        stderr = (exc.stderr or "").strip()
        print(
            f"ERROR: command failed ({exc.returncode}): "
            f"{stderr or exc.cmd}",
            file=sys.stderr,
        )
        raise SystemExit(2)
    except Exception as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        raise SystemExit(2)
