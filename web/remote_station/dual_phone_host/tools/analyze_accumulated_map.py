#!/usr/bin/env python3
"""Diagnose whether bad 3D output comes from local stereo depth or frame registration.

The tool is intentionally offline and standard-library only. It never changes the
raw session inputs. It creates filtered diagnostic PLY files and a JSON/text report.
"""

from __future__ import annotations

import argparse
import colorsys
import json
import math
import statistics
import sys
import tempfile
from collections import Counter
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Iterable


@dataclass
class PlyData:
    comments: list[str]
    properties: list[tuple[str, str]]
    rows: list[list[str]]

    @property
    def names(self) -> list[str]:
        return [name for _, name in self.properties]


def read_ascii_vertex_ply(path: Path) -> PlyData:
    with path.open("r", encoding="utf-8", errors="strict") as handle:
        if handle.readline().strip() != "ply":
            raise ValueError(f"{path}: not a PLY file")
        if handle.readline().strip() != "format ascii 1.0":
            raise ValueError(f"{path}: only ASCII PLY is supported")
        comments: list[str] = []
        properties: list[tuple[str, str]] = []
        vertex_count: int | None = None
        current_element = ""
        while True:
            line = handle.readline()
            if not line:
                raise ValueError(f"{path}: truncated header")
            line = line.rstrip("\n")
            if line == "end_header":
                break
            parts = line.split()
            if not parts:
                continue
            if parts[0] == "comment":
                comments.append(line[8:] if len(line) > 8 else "")
            elif parts[:2] == ["element", "vertex"]:
                vertex_count = int(parts[2])
                current_element = "vertex"
            elif parts[0] == "element":
                current_element = parts[1]
            elif parts[0] == "property" and current_element == "vertex":
                if len(parts) != 3:
                    raise ValueError(f"{path}: list properties are unsupported")
                properties.append((parts[1], parts[2]))
        if vertex_count is None:
            raise ValueError(f"{path}: missing vertex element")
        rows: list[list[str]] = []
        for _ in range(vertex_count):
            line = handle.readline()
            if not line:
                raise ValueError(f"{path}: missing vertex rows")
            values = line.split()
            if len(values) != len(properties):
                raise ValueError(f"{path}: vertex/property width mismatch")
            rows.append(values)
    return PlyData(comments=comments, properties=properties, rows=rows)


def write_ascii_vertex_ply(path: Path, data: PlyData, rows: Iterable[list[str]], comment: str) -> int:
    materialized = list(rows)
    with path.open("w", encoding="utf-8", newline="\n") as handle:
        handle.write("ply\nformat ascii 1.0\n")
        for value in data.comments:
            handle.write(f"comment {value}\n")
        handle.write(f"comment {comment}\n")
        handle.write(f"element vertex {len(materialized)}\n")
        for kind, name in data.properties:
            handle.write(f"property {kind} {name}\n")
        handle.write("end_header\n")
        for row in materialized:
            handle.write(" ".join(row) + "\n")
    return len(materialized)


def percentile(values: list[float], fraction: float) -> float | None:
    if not values:
        return None
    ordered = sorted(values)
    position = max(0.0, min(1.0, fraction)) * (len(ordered) - 1)
    lower = int(math.floor(position))
    upper = int(math.ceil(position))
    if lower == upper:
        return ordered[lower]
    weight = position - lower
    return ordered[lower] * (1.0 - weight) + ordered[upper] * weight


def cloud_stats(data: PlyData) -> dict[str, Any]:
    names = data.names
    for required in ("x", "y", "z"):
        if required not in names:
            raise ValueError(f"PLY has no {required} property")
    ix, iy, iz = (names.index("x"), names.index("y"), names.index("z"))
    points: list[tuple[float, float, float]] = []
    for row in data.rows:
        x, y, z = float(row[ix]), float(row[iy]), float(row[iz])
        if all(math.isfinite(v) for v in (x, y, z)):
            points.append((x, y, z))
    if not points:
        return {"vertices": 0}
    xs, ys, zs = zip(*points)
    radii = [math.hypot(x, z) for x, _, z in points]
    ranges = [math.sqrt(x * x + y * y + z * z) for x, y, z in points]
    angles = [math.degrees(math.atan2(x, z)) for x, _, z in points]
    return {
        "vertices": len(points),
        "bbox_min_m": [min(xs), min(ys), min(zs)],
        "bbox_max_m": [max(xs), max(ys), max(zs)],
        "bbox_size_m": [max(xs) - min(xs), max(ys) - min(ys), max(zs) - min(zs)],
        "range_median": statistics.median(ranges),
        "range_p95": percentile(ranges, 0.95),
        "horizontal_radius_p95": percentile(radii, 0.95),
        "azimuth_p05_deg": percentile(angles, 0.05),
        "azimuth_p95_deg": percentile(angles, 0.95),
    }


def keyframe_rgb(keyframe_id: int) -> tuple[int, int, int]:
    hue = (keyframe_id * 0.6180339887498949) % 1.0
    r, g, b = colorsys.hsv_to_rgb(hue, 0.82, 1.0)
    return round(r * 255), round(g * 255), round(b * 255)


def filter_accumulated_cloud(session: Path, data: PlyData) -> dict[str, Any]:
    names = data.names
    if "observations" not in names:
        raise ValueError("accumulated PLY has no observations property")
    obs_index = names.index("observations")
    keyframe_index = names.index("keyframe_id") if "keyframe_id" in names else None
    red_index = names.index("red") if "red" in names else None
    green_index = names.index("green") if "green" in names else None
    blue_index = names.index("blue") if "blue" in names else None

    histogram: Counter[int] = Counter()
    keyframes: Counter[int] = Counter()
    confirmed: list[list[str]] = []
    strict: list[list[str]] = []
    coloured: list[list[str]] = []
    for row in data.rows:
        observations = int(row[obs_index])
        histogram[observations] += 1
        keyframe_id = int(row[keyframe_index]) if keyframe_index is not None else 0
        keyframes[keyframe_id] += 1
        if observations >= 2:
            confirmed.append(row)
        if observations >= 3:
            strict.append(row)
        recoloured = list(row)
        if None not in (red_index, green_index, blue_index):
            r, g, b = keyframe_rgb(keyframe_id)
            recoloured[red_index] = str(r)
            recoloured[green_index] = str(g)
            recoloured[blue_index] = str(b)
        coloured.append(recoloured)

    write_ascii_vertex_ply(
        session / "point_cloud_accumulated_confirmed.ply",
        data,
        confirmed,
        "diagnostic filter: observations >= 2",
    )
    write_ascii_vertex_ply(
        session / "point_cloud_accumulated_strict.ply",
        data,
        strict,
        "diagnostic filter: observations >= 3",
    )
    write_ascii_vertex_ply(
        session / "point_cloud_accumulated_keyframe_colors.ply",
        data,
        coloured,
        "diagnostic colour: last contributing keyframe_id",
    )
    total = max(1, len(data.rows))
    return {
        "observation_histogram": {str(k): v for k, v in sorted(histogram.items())},
        "single_observation_vertices": histogram.get(1, 0),
        "confirmed_vertices": len(confirmed),
        "strict_vertices": len(strict),
        "confirmed_fraction": len(confirmed) / total,
        "strict_fraction": len(strict) / total,
        "last_keyframe_vertex_counts": {str(k): v for k, v in sorted(keyframes.items())},
    }


def load_json(path: Path) -> Any:
    with path.open("r", encoding="utf-8") as handle:
        return json.load(handle)


def trajectory_stats(path: Path) -> dict[str, Any]:
    if not path.exists():
        return {"available": False}
    payload = load_json(path)
    samples = payload.get("samples", []) if isinstance(payload, dict) else []
    methods = Counter(str(item.get("method", "UNKNOWN")) for item in samples)
    states = Counter(str(item.get("state", "UNKNOWN")) for item in samples)
    yaws = [float(item.get("yaw_deg", 0.0)) for item in samples]
    accumulated = [float(item.get("accumulated_yaw_deg", 0.0)) for item in samples]
    translations = [float(item.get("translation_from_previous_m", 0.0)) for item in samples]
    rotations = [float(item.get("rotation_from_previous_deg", 0.0)) for item in samples]
    yaw_steps = [float(item.get("yaw_step_deg", 0.0)) for item in samples]
    significant_signs = [1 if value > 2.0 else -1 for value in yaw_steps if abs(value) > 2.0]
    reversals = sum(a != b for a, b in zip(significant_signs, significant_signs[1:]))
    return {
        "available": True,
        "samples": len(samples),
        "methods": dict(methods),
        "states": dict(states),
        "yaw_min_deg": min(yaws) if yaws else None,
        "yaw_max_deg": max(yaws) if yaws else None,
        "yaw_span_deg": (max(yaws) - min(yaws)) if yaws else None,
        "accumulated_yaw_final_deg": accumulated[-1] if accumulated else None,
        "yaw_direction_reversals": reversals,
        "translation_step_median": statistics.median(translations) if translations else None,
        "translation_step_max": max(translations) if translations else None,
        "rotation_step_median_deg": statistics.median(rotations) if rotations else None,
        "rotation_step_max_deg": max(rotations) if rotations else None,
    }


def jsonl_stats(path: Path) -> dict[str, Any]:
    if not path.exists():
        return {"available": False}
    events: Counter[str] = Counter()
    reasons: Counter[str] = Counter()
    malformed = 0
    with path.open("r", encoding="utf-8") as handle:
        for line in handle:
            if not line.strip():
                continue
            try:
                item = json.loads(line)
            except json.JSONDecodeError:
                malformed += 1
                continue
            events[str(item.get("event", "UNKNOWN"))] += 1
            reason = item.get("reason")
            if reason:
                reasons[str(reason)] += 1
    return {
        "available": True,
        "events": dict(events),
        "rejection_reasons": dict(reasons),
        "malformed_lines": malformed,
    }


def classify(report: dict[str, Any]) -> list[dict[str, str]]:
    findings: list[dict[str, str]] = []
    local_vertices = report.get("local_stereo_cloud", {}).get("vertices", 0)
    accumulated_vertices = report.get("accumulated_cloud", {}).get("vertices", 0)
    confirmed_fraction = report.get("accumulation_support", {}).get("confirmed_fraction", 0.0)
    if local_vertices < 500:
        findings.append({
            "code": "LOCAL_STEREO_SPARSE",
            "meaning": "The single-frame stereo cloud is sparse; disparity/mask quality must be improved before registration diagnosis.",
        })
    elif accumulated_vertices > 0 and confirmed_fraction < 0.30:
        findings.append({
            "code": "REGISTRATION_OR_ACCUMULATION_WEAK",
            "meaning": "Local stereo produced a usable cloud, but fewer than 30% of accumulated voxels were observed at least twice.",
        })
    if report.get("trajectory", {}).get("translation_step_max", 0.0) and report["trajectory"]["translation_step_max"] > 0.20:
        findings.append({
            "code": "POSE_TRANSLATION_DRIFT",
            "meaning": "At least one accepted pose step moved more than 20 cm; this is suspicious for a fixed tripod rotation test.",
        })
    if not findings:
        findings.append({
            "code": "NO_SINGLE_DOMINANT_FAILURE",
            "meaning": "The available outputs do not isolate one failure; compare raw, confirmed and keyframe-coloured PLY files visually.",
        })
    return findings


def analyze_session(session: Path) -> dict[str, Any]:
    session = session.resolve()
    local_path = session / "point_cloud_latest.ply"
    accumulated_path = session / "point_cloud_accumulated.ply"
    if not local_path.exists():
        raise FileNotFoundError(f"missing {local_path}")
    if not accumulated_path.exists():
        raise FileNotFoundError(f"missing {accumulated_path}")

    local = read_ascii_vertex_ply(local_path)
    accumulated = read_ascii_vertex_ply(accumulated_path)
    support = filter_accumulated_cloud(session, accumulated)
    report: dict[str, Any] = {
        "schema_version": 1,
        "purpose": "separate local stereo geometry quality from multi-frame registration quality",
        "session": str(session),
        "local_stereo_cloud": cloud_stats(local),
        "accumulated_cloud": cloud_stats(accumulated),
        "accumulation_support": support,
        "trajectory": trajectory_stats(session / "camera_trajectory.json"),
        "tracking_log": jsonl_stats(session / "accumulated_map.jsonl"),
        "generated_files": [
            "point_cloud_accumulated_confirmed.ply",
            "point_cloud_accumulated_strict.ply",
            "point_cloud_accumulated_keyframe_colors.ply",
        ],
    }
    report["findings"] = classify(report)
    (session / "accumulated_diagnostics.json").write_text(
        json.dumps(report, indent=2, ensure_ascii=False) + "\n",
        encoding="utf-8",
    )
    lines = [
        "MaklerTour accumulated map diagnostics",
        f"Session: {session}",
        f"Local stereo vertices: {report['local_stereo_cloud'].get('vertices', 0)}",
        f"Accumulated vertices: {report['accumulated_cloud'].get('vertices', 0)}",
        f"Confirmed >=2: {support['confirmed_vertices']} ({support['confirmed_fraction']:.1%})",
        f"Strict >=3: {support['strict_vertices']} ({support['strict_fraction']:.1%})",
        "Findings:",
    ]
    lines.extend(f"- {item['code']}: {item['meaning']}" for item in report["findings"])
    (session / "accumulated_diagnostics.txt").write_text(
        "\n".join(lines) + "\n",
        encoding="utf-8",
    )
    return report


def write_test_ply(path: Path, accumulated: bool) -> None:
    properties = [
        ("float", "x"), ("float", "y"), ("float", "z"),
        ("uchar", "red"), ("uchar", "green"), ("uchar", "blue"),
    ]
    if accumulated:
        properties += [("uint", "observations"), ("uint", "keyframe_id")]
    rows: list[list[str]] = []
    for index in range(12):
        row = [str(index * 0.03), "0", "2", "10", "20", "30"]
        if accumulated:
            row += [str(1 + index % 3), str(1 + index % 4)]
        rows.append(row)
    write_ascii_vertex_ply(path, PlyData([], properties, []), rows, "self-test")


def self_test() -> int:
    with tempfile.TemporaryDirectory(prefix="maklertour-map-diagnostic-") as temporary:
        session = Path(temporary)
        write_test_ply(session / "point_cloud_latest.ply", accumulated=False)
        write_test_ply(session / "point_cloud_accumulated.ply", accumulated=True)
        (session / "camera_trajectory.json").write_text(
            json.dumps({"samples": [{"yaw_deg": 0, "method": "IDENTITY"}, {"yaw_deg": 10, "method": "ROTATION_HOMOGRAPHY", "yaw_step_deg": 10}]}) + "\n",
            encoding="utf-8",
        )
        report = analyze_session(session)
        assert report["accumulation_support"]["confirmed_vertices"] == 8
        assert (session / "point_cloud_accumulated_confirmed.ply").exists()
        assert (session / "accumulated_diagnostics.json").exists()
    print("OK")
    return 0


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("session", nargs="?", type=Path)
    parser.add_argument("--self-test", action="store_true")
    args = parser.parse_args(argv)
    if args.self_test:
        return self_test()
    if args.session is None:
        parser.error("SESSION_DIR is required unless --self-test is used")
    report = analyze_session(args.session)
    print(json.dumps(report, indent=2, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except (OSError, ValueError, KeyError) as error:
        print(f"ERROR: {error}", file=sys.stderr)
        raise SystemExit(1)
