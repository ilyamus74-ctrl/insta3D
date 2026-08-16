#!/usr/bin/env python3
import math
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SCRIPTS = ROOT / "web/remote_station/scripts"
sys.path.insert(0, str(SCRIPTS))

import analyze_tof_dense_conditional_h26 as h26


def main():
    rows = []
    for zone in range(16):
        for index in range(20):
            metric = float(index)
            rows.append({
                "zone_index": zone,
                "distance_mm": 800.0,
                "time_quartile": "Q1" if index < 10 else "Q2",
                "absolute_log_residual": 0.05 if index <= 9 else 0.09,
                "geometric_footprint_iqr_fraction": metric,
            })

    result = h26.analyze_metric(
        rows,
        "geometric_footprint_iqr_fraction",
        minimum_stratum_count=12,
        minimum_strata=12,
        effect_ratio_threshold=1.15,
        consistency_threshold=0.65,
        sign_test_p_threshold=0.01,
        include_time=False,
    )

    assert result["supported_stratum_count"] == 16
    assert result["aggregate_high_to_low_error_ratio"] > 1.7
    assert result["positive_effect_consistency"] == 1.0
    assert result["one_sided_sign_test_pvalue"] < 0.01
    assert result["conditional_signal"] is True

    assert h26.distance_bucket(499.0) == "0_0p5m"
    assert h26.distance_bucket(500.0) == "0p5_1m"
    assert h26.distance_bucket(1499.0) == "1_1p5m"
    assert h26.distance_bucket(3000.0) == "3_4m"
    assert h26.distance_bucket(4001.0) == "beyond_4m"

    assert abs(h26.one_sided_sign_test_pvalue(16, 16) - (1 / 65536)) < 1e-12

    print("Result: PASS")


if __name__ == "__main__":
    main()
