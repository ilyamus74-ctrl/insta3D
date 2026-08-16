#!/usr/bin/env python3
import math
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SCRIPTS = ROOT / "web/remote_station/scripts"
sys.path.insert(0, str(SCRIPTS))

import analyze_tof_dense_quality_h28 as h28


def make_supported_rows():
    h26_rows = []
    h22_rows = []
    sequence = 1
    distances = (350.0, 750.0, 1250.0, 1750.0, 2500.0, 3500.0)
    distance_factors = (0.80, 0.90, 0.98, 1.08, 1.22, 1.36)

    # Each exact distance+zone stratum gets matched +/- residual pairs at every
    # quality severity. Therefore its outcome baseline is exactly zero while
    # quality remains independent of the membership calculation.
    for distance_group, distance_mm in enumerate(distances):
        for zone_index in range(32):
            zone_row = zone_index // 8
            zone_column = zone_index % 8
            row_shape = (zone_row - 1.5) / 3.5
            col_shape = (zone_column - 3.5) / 3.5
            for severity_index in range(16):
                severity = severity_index / 15.0
                q1 = 0.01 + 0.40 * severity
                q2 = 0.02 + 0.55 * severity
                deformation = (
                    1.0
                    + severity * 0.75 * (distance_factors[distance_group] - 1.0)
                    + severity * 0.12 * row_shape
                    - severity * 0.10 * col_shape
                )
                scale = 200.0 * deformation
                tof_z = distance_mm + 20.0
                dense_depth = tof_z / scale

                for sign in (-1.0, 1.0):
                    signed_log = sign * (0.01 + severity * 0.20)
                    image = (
                        f"frame_d{distance_group}_z{zone_index:02d}_"
                        f"s{severity_index:02d}_{'p' if sign > 0 else 'n'}.jpg"
                    )
                    common = {
                        "image": image,
                        "tof_sequence": sequence,
                        "zone_index": zone_index,
                        "zone_row": zone_row,
                        "zone_column": zone_column,
                        "distance_mm": distance_mm,
                        "distance_bucket": h28.distance_bucket(distance_mm),
                        "geometric_local_gradient_fraction": q1,
                        "geometric_photometric_relative_difference": q2,
                        "distance_normalized_ratio": math.exp(signed_log),
                        "absolute_log_residual": abs(signed_log),
                    }
                    h26_rows.append(common)
                    h22_rows.append({
                        "image": image,
                        "tof_sequence": sequence,
                        "zone_index": zone_index,
                        "strategy": h28.PRIMARY_STRATEGY,
                        "distance_mm": distance_mm,
                        "distance_bucket": common["distance_bucket"],
                        "scale_mm_per_colmap_unit": scale,
                        "dense_depth_units": dense_depth,
                        "tof_camera_z_mm": tof_z,
                    })
                    sequence += 1
    return h26_rows, h22_rows


def run_gate(h26_rows, h22_rows):
    thresholds = h28.quality_thresholds(h26_rows)
    assert h28.quality_thresholds_valid(thresholds)
    assigned = h28.assign_quality_groups(h26_rows, thresholds)
    joined, info = h28.join_quality_and_h22(
        assigned, h22_rows, h28.PRIMARY_STRATEGY
    )
    assert info["joined_count"] == len(assigned)

    baseline = h28.build_zone_distance_baseline(joined, 4)
    joined, supported = h28.attach_zone_distance_residuals(joined, baseline)
    assert supported == len(joined)

    clean = [row for row in joined if row["quality_group"] == "CLEAN_DENSE"]
    unstable = [row for row in joined if row["quality_group"] == "UNSTABLE_DENSE"]
    full_eval = h28.evaluate_subset(joined, 8)
    clean_eval = h28.evaluate_subset(clean, 8)
    unstable_eval = h28.evaluate_subset(unstable, 8)
    comparison = h28.compare_evaluations(full_eval, clean_eval, unstable_eval)
    support_ok = clean_eval["count"] >= 150 and unstable_eval["count"] >= 150
    classification = h28.classify_gate(comparison, support_ok)
    return thresholds, assigned, full_eval, clean_eval, unstable_eval, comparison, classification


def main():
    h26_rows, h22_rows = make_supported_rows()
    (
        thresholds,
        assigned,
        full_eval,
        clean_eval,
        unstable_eval,
        comparison,
        classification,
    ) = run_gate(h26_rows, h22_rows)

    assert clean_eval["count"] >= 150
    assert unstable_eval["count"] >= 150
    assert comparison["raw_metric_error"]["improvement_signal"] is True
    assert comparison["zone_distance_conditioned_error"]["improvement_signal"] is True
    assert comparison["deformation_signal_count"] == 3
    assert classification == "DENSE_QUALITY_GATE_SUPPORTED"

    # Critical anti-leakage test: changing metric outcomes while leaving Dense
    # quality unchanged must leave thresholds and membership bit-for-bit equal.
    mutated_h26 = []
    for index, source in enumerate(h26_rows):
        row = dict(source)
        row["absolute_log_residual"] = 10.0 + index
        row["distance_normalized_ratio"] = math.exp(0.1 if index % 2 else -0.1)
        row["scale_mm_per_colmap_unit"] = 999999.0
        mutated_h26.append(row)
    mutated_thresholds = h28.quality_thresholds(mutated_h26)
    mutated_assigned = h28.assign_quality_groups(mutated_h26, mutated_thresholds)
    assert thresholds == mutated_thresholds
    before = {h28.observation_key(row): row["quality_group"] for row in assigned}
    after = {h28.observation_key(row): row["quality_group"] for row in mutated_assigned}
    assert before == after

    # Null outcome: same quality membership, but stable scale and quality-
    # independent residual magnitude. Full support must disappear.
    null_h22 = []
    for index, row in enumerate(h22_rows):
        item = dict(row)
        stable_scale = 200.0 * (1.0 + 0.002 * ((index % 7) - 3))
        item["scale_mm_per_colmap_unit"] = stable_scale
        item["dense_depth_units"] = float(item["tof_camera_z_mm"]) / stable_scale
        null_h22.append(item)

    null_h26 = []
    for index, row in enumerate(h26_rows):
        item = dict(row)
        signed = 0.05 if index % 2 else -0.05
        item["distance_normalized_ratio"] = math.exp(signed)
        item["absolute_log_residual"] = abs(signed)
        null_h26.append(item)

    *_, null_classification = run_gate(null_h26, null_h22)
    assert null_classification != "DENSE_QUALITY_GATE_SUPPORTED"

    assert h28.LOW_QUALITY_QUANTILE == 0.25
    assert h28.HIGH_QUALITY_QUANTILE == 0.75
    print("Result: PASS")


if __name__ == "__main__":
    main()
