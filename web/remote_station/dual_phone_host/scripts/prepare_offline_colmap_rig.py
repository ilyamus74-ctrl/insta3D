#!/usr/bin/env python3
"""Prepare synchronized dual-phone frames and a calibrated COLMAP rig config."""

from __future__ import annotations

import argparse
import json
import math
import shutil
from pathlib import Path
from typing import Any


def load_json(path: Path) -> dict[str, Any]:
    with path.open("r", encoding="utf-8") as handle:
        value = json.load(handle)
    if not isinstance(value, dict):
        raise ValueError(f"{path} must contain a JSON object")
    return value


def load_jsonl(path: Path) -> list[dict[str, Any]]:
    result: list[dict[str, Any]] = []
    with path.open("r", encoding="utf-8") as handle:
        for line_number, raw in enumerate(handle, 1):
            line = raw.strip()
            if not line:
                continue
            try:
                value = json.loads(line)
            except json.JSONDecodeError as exc:
                raise ValueError(f"invalid JSONL at {path}:{line_number}: {exc}") from exc
            if not isinstance(value, dict):
                raise ValueError(f"{path}:{line_number} is not an object")
            result.append(value)
    return result


def transpose3(matrix: list[float]) -> list[float]:
    return [matrix[column * 3 + row] for row in range(3) for column in range(3)]


def multiply3_vector(matrix: list[float], vector: list[float]) -> list[float]:
    return [
        sum(matrix[row * 3 + column] * vector[column] for column in range(3))
        for row in range(3)
    ]


def quaternion_from_rotation(matrix: list[float]) -> list[float]:
    if len(matrix) != 9:
        raise ValueError("rotation must contain 9 values")
    m00, m01, m02, m10, m11, m12, m20, m21, m22 = matrix
    trace = m00 + m11 + m22
    if trace > 0.0:
        scale = math.sqrt(trace + 1.0) * 2.0
        qw = 0.25 * scale
        qx = (m21 - m12) / scale
        qy = (m02 - m20) / scale
        qz = (m10 - m01) / scale
    elif m00 > m11 and m00 > m22:
        scale = math.sqrt(1.0 + m00 - m11 - m22) * 2.0
        qw = (m21 - m12) / scale
        qx = 0.25 * scale
        qy = (m01 + m10) / scale
        qz = (m02 + m20) / scale
    elif m11 > m22:
        scale = math.sqrt(1.0 + m11 - m00 - m22) * 2.0
        qw = (m02 - m20) / scale
        qx = (m01 + m10) / scale
        qy = 0.25 * scale
        qz = (m12 + m21) / scale
    else:
        scale = math.sqrt(1.0 + m22 - m00 - m11) * 2.0
        qw = (m10 - m01) / scale
        qx = (m02 + m20) / scale
        qy = (m12 + m21) / scale
        qz = 0.25 * scale
    norm = math.sqrt(qw * qw + qx * qx + qy * qy + qz * qz)
    if not math.isfinite(norm) or norm <= 1e-12:
        raise ValueError("rotation produced an invalid quaternion")
    values = [qw / norm, qx / norm, qy / norm, qz / norm]
    if values[0] < 0.0:
        values = [-value for value in values]
    return values


def camera_params(intrinsics: dict[str, Any]) -> list[float]:
    required = ["fx", "fy", "cx", "cy", "k1", "k2"]
    values = []
    for key in required:
        raw = intrinsics.get(key)
        if not isinstance(raw, (int, float)) or not math.isfinite(float(raw)):
            raise ValueError(f"intrinsics field {key} is missing or invalid")
        values.append(float(raw))
    return [values[0], values[1], values[2], values[3], values[4], values[5], 0.0, 0.0]



def scaled_intrinsics(
    intrinsics: dict[str, Any], target_width: int, target_height: int
) -> dict[str, Any]:
    source_width = int(intrinsics.get("image_width", 0))
    source_height = int(intrinsics.get("image_height", 0))
    if source_width <= 0 or source_height <= 0:
        raise ValueError("calibration intrinsics contain invalid image dimensions")
    if target_width <= 0 or target_height <= 0:
        raise ValueError("archived image dimensions are invalid")
    source_aspect = source_width / source_height
    target_aspect = target_width / target_height
    if abs(source_aspect - target_aspect) > 0.01:
        raise ValueError(
            f"archived image aspect {target_width}x{target_height} does not match "
            f"calibration {source_width}x{source_height}"
        )
    scale_x = target_width / source_width
    scale_y = target_height / source_height
    result = dict(intrinsics)
    result["image_width"] = target_width
    result["image_height"] = target_height
    result["fx"] = float(intrinsics["fx"]) * scale_x
    result["fy"] = float(intrinsics["fy"]) * scale_y
    result["cx"] = (float(intrinsics["cx"]) + 0.5) * scale_x - 0.5
    result["cy"] = (float(intrinsics["cy"]) + 0.5) * scale_y - 0.5
    return result

def device_id(path: Path) -> str:
    hello = load_json(path)
    value = hello.get("device_id")
    if not isinstance(value, str) or not value:
        raise ValueError(f"{path} does not contain device_id")
    return value


def resolve_runtime_calibration(
    profile: dict[str, Any], camera_a_device: str, camera_b_device: str
) -> tuple[dict[str, Any], dict[str, Any], list[float], list[float], bool]:
    master_id = profile.get("master_device_id")
    slave_id = profile.get("slave_device_id")
    master = profile.get("master_intrinsics")
    slave = profile.get("slave_intrinsics")
    stereo = profile.get("stereo")
    if not isinstance(master, dict) or not isinstance(slave, dict) or not isinstance(stereo, dict):
        raise ValueError("stereo_calibration.json lacks solved intrinsics/stereo objects")
    rotation = [float(value) for value in stereo.get("rotation", [])]
    translation_mm = [float(value) for value in stereo.get("translation_mm", [])]
    if len(rotation) != 9 or len(translation_mm) != 3:
        raise ValueError("stereo rotation/translation has an invalid size")

    if camera_a_device == master_id and camera_b_device == slave_id:
        return master, slave, rotation, [value / 1000.0 for value in translation_mm], False
    if camera_a_device == slave_id and camera_b_device == master_id:
        inverse_rotation = transpose3(rotation)
        inverse_translation_mm = [
            -value for value in multiply3_vector(inverse_rotation, translation_mm)
        ]
        return (
            slave,
            master,
            inverse_rotation,
            [value / 1000.0 for value in inverse_translation_mm],
            True,
        )
    raise ValueError(
        "CAMERA_A/CAMERA_B device IDs do not match the accepted calibration profile"
    )


def place_image(source: Path, destination: Path, copy_images: bool) -> None:
    destination.parent.mkdir(parents=True, exist_ok=True)
    if destination.exists() or destination.is_symlink():
        destination.unlink()
    if copy_images:
        shutil.copy2(source, destination)
    else:
        destination.symlink_to(source.resolve())


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("session_dir", type=Path)
    parser.add_argument("workspace", type=Path)
    parser.add_argument("--pair-step", type=int, default=1)
    parser.add_argument("--max-pairs", type=int, default=0)
    parser.add_argument("--copy-images", action="store_true")
    args = parser.parse_args()

    session = args.session_dir.resolve()
    workspace = args.workspace.resolve()
    if args.pair_step < 1:
        raise SystemExit("--pair-step must be >= 1")
    if args.max_pairs < 0:
        raise SystemExit("--max-pairs must be >= 0")

    pairs_path = session / "colmap_pairs.jsonl"
    calibration_path = session / "stereo_calibration.json"
    if not pairs_path.is_file():
        raise SystemExit(
            f"missing {pairs_path}; this session predates LM02.7B.5.3.1 frame capture"
        )
    if not calibration_path.is_file():
        raise SystemExit(f"missing {calibration_path}")

    profile = load_json(calibration_path)
    camera_a_id = device_id(session / "camera_a_hello.json")
    camera_b_id = device_id(session / "camera_b_hello.json")
    intrinsics_a, intrinsics_b, rotation_b_from_a, translation_b_from_a_m, reversed_roles = (
        resolve_runtime_calibration(profile, camera_a_id, camera_b_id)
    )

    image_root = workspace / "images"
    if image_root.exists():
        shutil.rmtree(image_root)
    (image_root / "CAMERA_A").mkdir(parents=True, exist_ok=True)
    (image_root / "CAMERA_B").mkdir(parents=True, exist_ok=True)

    selected: list[dict[str, Any]] = []
    dimensions_a: tuple[int, int] | None = None
    dimensions_b: tuple[int, int] | None = None
    candidates = load_jsonl(pairs_path)
    for index, pair in enumerate(candidates):
        if index % args.pair_step != 0:
            continue
        image_a_rel = pair.get("image_a")
        image_b_rel = pair.get("image_b")
        if not isinstance(image_a_rel, str) or not isinstance(image_b_rel, str):
            continue
        image_a = session / image_a_rel
        image_b = session / image_b_rel
        if not image_a.is_file() or not image_b.is_file():
            continue
        width_a = int(pair.get("camera_a_width", 0))
        height_a = int(pair.get("camera_a_height", 0))
        width_b = int(pair.get("camera_b_width", 0))
        height_b = int(pair.get("camera_b_height", 0))
        current_a = (width_a, height_a)
        current_b = (width_b, height_b)
        if min(width_a, height_a, width_b, height_b) <= 0:
            raise ValueError(
                "colmap_pairs.jsonl lacks archived image width/height metadata"
            )
        if dimensions_a is None:
            dimensions_a = current_a
            dimensions_b = current_b
        elif dimensions_a != current_a or dimensions_b != current_b:
            raise ValueError(
                "offline COLMAP rig requires constant dimensions within a session"
            )
        pair_index = int(pair.get("pair_index", len(selected) + 1))
        name = f"{pair_index:012d}.jpg"
        place_image(image_a, image_root / "CAMERA_A" / name, args.copy_images)
        place_image(image_b, image_root / "CAMERA_B" / name, args.copy_images)
        selected.append(
            {
                "pair_index": pair_index,
                "name": name,
                "camera_a_sequence": pair.get("camera_a_sequence"),
                "camera_b_sequence": pair.get("camera_b_sequence"),
                "delta_ms": pair.get("delta_ms"),
                "camera_a_width": width_a,
                "camera_a_height": height_a,
                "camera_b_width": width_b,
                "camera_b_height": height_b,
                "source_image_a": image_a_rel,
                "source_image_b": image_b_rel,
            }
        )
        if args.max_pairs and len(selected) >= args.max_pairs:
            break

    if len(selected) < 8:
        raise SystemExit(
            f"only {len(selected)} synchronized image pairs are available; at least 8 are required"
        )

    if dimensions_a is None or dimensions_b is None:
        raise SystemExit("no usable synchronized image dimensions were found")
    intrinsics_a = scaled_intrinsics(intrinsics_a, *dimensions_a)
    intrinsics_b = scaled_intrinsics(intrinsics_b, *dimensions_b)

    quaternion = quaternion_from_rotation(rotation_b_from_a)
    rig_config = [
        {
            "cameras": [
                {
                    "image_prefix": "CAMERA_A/",
                    "ref_sensor": True,
                    "camera_model_name": "OPENCV",
                    "camera_params": camera_params(intrinsics_a),
                },
                {
                    "image_prefix": "CAMERA_B/",
                    "camera_model_name": "OPENCV",
                    "camera_params": camera_params(intrinsics_b),
                    "cam_from_rig_rotation": quaternion,
                    "cam_from_rig_translation": translation_b_from_a_m,
                },
            ]
        }
    ]
    workspace.mkdir(parents=True, exist_ok=True)
    (workspace / "rig_config.json").write_text(
        json.dumps(rig_config, indent=2) + "\n", encoding="utf-8"
    )
    (workspace / "selected_pairs.json").write_text(
        json.dumps(selected, indent=2) + "\n", encoding="utf-8"
    )
    manifest = {
        "schema_version": 1,
        "mode": "LM02.7B.5.3.1_OFFLINE_COLMAP_RIG",
        "session_dir": str(session),
        "workspace": str(workspace),
        "pair_count": len(selected),
        "pair_step": args.pair_step,
        "roles_reversed": reversed_roles,
        "camera_a_device_id": camera_a_id,
        "camera_b_device_id": camera_b_id,
        "camera_a_model": "OPENCV",
        "camera_b_model": "OPENCV",
        "camera_a_dimensions": list(dimensions_a),
        "camera_b_dimensions": list(dimensions_b),
        "camera_a_params": camera_params(intrinsics_a),
        "camera_b_params": camera_params(intrinsics_b),
        "cam_b_from_rig_quaternion": quaternion,
        "cam_b_from_rig_translation_m": translation_b_from_a_m,
        "expected_baseline_m": math.sqrt(
            sum(value * value for value in translation_b_from_a_m)
        ),
        "image_storage": "COPY" if args.copy_images else "SYMLINK",
    }
    (workspace / "prepare_manifest.json").write_text(
        json.dumps(manifest, indent=2) + "\n", encoding="utf-8"
    )
    print(json.dumps(manifest, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
