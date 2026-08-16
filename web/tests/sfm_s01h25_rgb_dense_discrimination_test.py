#!/usr/bin/env python3
import sys
from pathlib import Path
from types import SimpleNamespace

ROOT = Path(__file__).resolve().parents[2]
SCRIPTS = ROOT / "web/remote_station/scripts"
sys.path.insert(0, str(SCRIPTS))

import analyze_tof_dense_rgb_h25 as h25


def main():
    args = SimpleNamespace(
        focal_fraction_1=0.01,
        focal_fraction_2=0.02,
        principal_shift_px_1=5.0,
        principal_shift_px_2=10.0,
    )

    variants = h25.projection_variant_definitions(0.992, args)
    names = {item["name"] for item in variants}

    assert "baseline" in names
    assert "camera2_focal_ratio" in names
    assert "effective_focal_1pct_minus" in names
    assert "effective_focal_2pct_plus" in names
    assert "effective_cx_5px_minus" in names
    assert "effective_cy_10px_plus" in names
    assert len(variants) == 14

    camera = {
        "model": "PINHOLE",
        "width": 1080,
        "height": 1920,
        "params": [1300.0, 1300.0, 540.0, 960.0],
    }
    changed = h25.apply_projection_variant(
        camera,
        {
            "focal_scale": 0.99,
            "cx_shift_px": 5.0,
            "cy_shift_px": -10.0,
        },
    )
    assert changed is not None
    assert abs(changed["params"][0] - 1287.0) < 1e-9
    assert abs(changed["params"][1] - 1287.0) < 1e-9
    assert abs(changed["params"][2] - 545.0) < 1e-9
    assert abs(changed["params"][3] - 950.0) < 1e-9
    assert camera["params"] == [1300.0, 1300.0, 540.0, 960.0]

    rows = []
    for index in range(200):
        metric = index / 199.0
        rows.append({
            "metric": metric,
            "absolute_log_residual": 0.02 + 0.20 * metric,
        })

    diag = h25.dense_metric_diagnostic(
        rows,
        "metric",
        100,
        0.20,
        1.20,
    )
    assert diag["supported"] is True
    assert diag["correlation_absolute_log_residual"] > 0.99
    assert diag["high_to_low_error_ratio"] > 1.20
    assert diag["signal"] is True

    assert h25.classify(False, False) == "INSUFFICIENT_SUPPORT"
    assert h25.classify(True, False) == "RGB_EFFECTIVE_PROJECTION_PATTERN_SUPPORTED"
    assert h25.classify(False, True) == "DENSE_LOCAL_STRUCTURE_PATTERN_SUPPORTED"
    assert h25.classify(True, True) == "MIXED_PATTERN_SUPPORTED"

    print("Result: PASS")


if __name__ == "__main__":
    main()
