#!/usr/bin/env python3
import sys
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
SCRIPTS = ROOT / "web/remote_station/scripts"
sys.path.insert(0, str(SCRIPTS))

import measure_tof_sparse_scale as h2


def assert_uv(actual, expected):
    assert actual is not None
    assert len(actual) == 2
    assert abs(actual[0] - expected[0]) < 1e-12, (actual, expected)
    assert abs(actual[1] - expected[1]) < 1e-12, (actual, expected)


def main():
    camera = {
        "model": "PINHOLE",
        "width": 1080,
        "height": 1920,
        "params": [100.0, 100.0, 540.0, 960.0],
    }

    assert h2.camera_a_landscape_to_colmap_camera([1.0, 2.0, 3.0]) == [
        -2.0, 1.0, 3.0,
    ]

    assert_uv(
        h2.project_camera_a_point_to_colmap_image(camera, [0.0, 0.0, 10.0]),
        [540.0, 960.0],
    )
    assert_uv(
        h2.project_camera_a_point_to_colmap_image(camera, [1.0, 0.0, 10.0]),
        [540.0, 970.0],
    )
    assert_uv(
        h2.project_camera_a_point_to_colmap_image(camera, [0.0, 1.0, 10.0]),
        [530.0, 960.0],
    )

    # The generic COLMAP projection accepts an already-rotated COLMAP point and
    # must not perform a second CAMERA_A orientation conversion.
    assert_uv(
        h2.project_colmap_camera_point(camera, [1.0, 2.0, 10.0]),
        [550.0, 980.0],
    )

    affected = {
        "measure_tof_sparse_scale.py": 1,
        "measure_tof_sparse_scale_h21.py": 2,
        "measure_tof_dense_depth_h22.py": 1,
        "analyze_tof_dense_zone_h24.py": 1,
        "analyze_tof_dense_rgb_h25.py": 1,
    }
    for filename, expected_calls in affected.items():
        source = (SCRIPTS / filename).read_text(encoding="utf-8")
        wrapper_calls = source.count(
            "project_camera_a_point_to_colmap_image("
        )
        if filename == "measure_tof_sparse_scale.py":
            # Exclude the wrapper definition itself.
            wrapper_calls -= 1
        assert wrapper_calls == expected_calls, (filename, wrapper_calls)
        if filename != "measure_tof_sparse_scale.py":
            assert "project_colmap_camera_point(" not in source, filename

    h2_source = (SCRIPTS / "measure_tof_sparse_scale.py").read_text(
        encoding="utf-8"
    )
    assert "def project_camera_point(" not in h2_source
    assert h2_source.count("project_colmap_camera_point(") == 2

    for filename in (
        "analyze_tof_dense_error_h23.py",
        "analyze_tof_dense_conditional_h26.py",
        "analyze_tof_dense_frame_h27.py",
        "analyze_tof_dense_quality_h28.py",
        "analyze_tof_dense_quality_decomposition_h29.py",
    ):
        source = (SCRIPTS / filename).read_text(encoding="utf-8")
        assert "camera_a_landscape_to_colmap_camera" not in source, filename
        assert "project_camera_a_point_to_colmap_image" not in source, filename

    print("Result: PASS")


if __name__ == "__main__":
    main()
