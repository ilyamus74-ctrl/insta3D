#!/usr/bin/env python3
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SCRIPTS = ROOT / "web/remote_station/scripts"
sys.path.insert(0, str(SCRIPTS))

import analyze_tof_dense_frame_h27 as h27


def main():
    rows = []
    for image_index in range(60):
        quartile = f"Q{1 + (image_index % 4)}"
        image_factor = 0.10 + image_index * 0.001
        for zone in range(32):
            zone_shape = float((zone % 5) - 2)
            local_signal = image_factor * zone_shape
            metric = 0.05 * zone + local_signal
            error = 0.10 + 0.02 * zone + 0.80 * local_signal
            rows.append({
                "image": f"frame_{image_index:03d}.jpg",
                "time_quartile": quartile,
                "zone_index": zone,
                "distance_mm": 800.0,
                "absolute_log_residual": error,
                "geometric_local_gradient_fraction": metric,
            })

    summary = h27.summarize_metric(
        rows,
        "geometric_local_gradient_fraction",
        minimum_rows_per_image=16,
        minimum_images=40,
        sign_consistency_threshold=0.60,
        sign_test_p_threshold=0.01,
        bootstrap_iterations=300,
        seed=1,
    )

    assert summary["supported_image_count"] == 60
    assert summary["median_within_image_spearman"] > 0.9
    assert summary["positive_image_consistency"] == 1.0
    assert summary["one_sided_image_sign_test_pvalue"] < 0.01
    assert summary["bootstrap_median_ci95"]["p2p5"] > 0.0
    assert summary["time_quartile_direction_supported"] is True
    assert summary["frame_fixed_effect_signal"] is True

    assert h27.distance_bucket(499.0) == "0_0p5m"
    assert h27.distance_bucket(500.0) == "0p5_1m"
    assert h27.one_sided_sign_test_pvalue(10, 10) == 1 / 1024

    print("Result: PASS")


if __name__ == "__main__":
    main()
