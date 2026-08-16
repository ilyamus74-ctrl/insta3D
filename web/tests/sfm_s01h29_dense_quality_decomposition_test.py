#!/usr/bin/env python3
import math
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SCRIPTS = ROOT / "web/remote_station/scripts"
sys.path.insert(0, str(SCRIPTS))

import analyze_tof_dense_quality_decomposition_h29 as h29


def synthetic_rows():
    rows = []
    sequence = 1
    distances = (350.0, 750.0, 1250.0, 1750.0, 2500.0, 3500.0)
    distance_scale = (0.72, 0.82, 0.94, 1.08, 1.25, 1.43)
    for image_index in range(90):
        for zone_index in range(32):
            row_index = zone_index // 8
            column_index = zone_index % 8
            distance_index = (image_index + zone_index) % len(distances)
            distance_mm = distances[distance_index]
            base = distance_scale[distance_index]
            row_effect = 1.0 + 0.025 * (row_index - 1.5)
            column_effect = 1.0 - 0.018 * (column_index - 3.5)
            scale = 200.0 * base * row_effect * column_effect

            # Keep local quality gating beneficial, but deliberately leave the
            # global distance-scale deformation present in CLEAN_DENSE.
            quality_cycle = (image_index * 17 + zone_index * 23) % 20
            if quality_cycle < 4:
                group = "CLEAN_DENSE"
            elif quality_cycle >= 13:
                group = "UNSTABLE_DENSE"
                scale *= 1.0 + 0.08 * ((zone_index % 3) - 1)
            else:
                group = "MIDDLE_DENSE"

            rows.append({
                "type": "tof_dense_h28_quality_gate",
                "image": f"frame_{image_index:03d}.jpg",
                "tof_sequence": sequence,
                "zone_index": zone_index,
                "zone_row": row_index,
                "zone_column": column_index,
                "distance_mm": distance_mm,
                "quality_group": group,
                "scale_mm_per_colmap_unit": scale,
                "dense_depth_units": distance_mm / scale,
                "tof_camera_z_mm": distance_mm,
            })
            sequence += 1
    return rows


def main():
    rows = synthetic_rows()
    clean = [row for row in rows if row["quality_group"] == "CLEAN_DENSE"]
    assert len(clean) >= 150

    full_summary, _ = h29.controlled_decomposition(rows, 15, 8)
    clean_summary, _ = h29.controlled_decomposition(clean, 15, 8)
    assert full_summary["status"] == "MEASURED"
    assert clean_summary["status"] == "MEASURED"

    assert clean_summary["raw_distance"]["scale_spread_ratio"] > 1.8
    assert (
        clean_summary["after_zone_control"]["distance_scale_spread_ratio"]
        > h29.DEFORMATION_THRESHOLD
    )

    bootstrap = h29.image_bootstrap(clean, 15, 100, 1, 8)
    assert bootstrap["status"] == "MEASURED"
    assert (
        bootstrap["metrics"]["zone_normalized_distance_scale_spread_ratio"]["p2p5"]
        > h29.DEFORMATION_THRESHOLD
    )

    h28_report = {
        "decision": {
            "classification": "DENSE_QUALITY_GATE_PARTIAL_SUPPORT"
        }
    }
    decision = h29.classify(h28_report, clean_summary, bootstrap)
    assert decision["classification"] == (
        "MIXED_LOCAL_DENSE_INSTABILITY_AND_SYSTEMATIC_DEPTH_SCALE_DEFORMATION_SUPPORTED"
    )
    assert decision["clean_systematic_distance_deformation_persists"] is True

    # Null distance-deformation case must not claim persistence.
    null_rows = []
    for row in clean:
        item = dict(row)
        item["scale_mm_per_colmap_unit"] = 200.0
        null_rows.append(item)
    null_summary, _ = h29.controlled_decomposition(null_rows, 15, 8)
    null_bootstrap = h29.image_bootstrap(null_rows, 15, 50, 2, 8)
    null_decision = h29.classify(h28_report, null_summary, null_bootstrap)
    assert null_decision["clean_systematic_distance_deformation_persists"] is False

    print("Result: PASS")


if __name__ == "__main__":
    main()
