#!/usr/bin/env python3
import json
import sys
from pathlib import Path

import cv2
import numpy as np


def load_json(path: Path):
    with path.open("r", encoding="utf-8") as f:
        return json.load(f)


def mat3(x):
    return np.array(x, dtype=np.float64)


def vec(x):
    return np.array(x, dtype=np.float64).reshape(-1, 1)


def draw_horizontal_grid(img, step=40):
    out = img.copy()
    h, w = out.shape[:2]
    for y in range(0, h, step):
        cv2.line(out, (0, y), (w - 1, y), (0, 255, 255), 1)
    return out


def main():
    if len(sys.argv) != 5:
        print("Usage:")
        print("  rectify_stereo_test.py <calibration_json> <cam0.jpg> <cam1.jpg> <out_dir>")
        sys.exit(2)

    calib_path = Path(sys.argv[1])
    cam0_path = Path(sys.argv[2])
    cam1_path = Path(sys.argv[3])
    out_dir = Path(sys.argv[4])
    out_dir.mkdir(parents=True, exist_ok=True)

    calib = load_json(calib_path)

    img0 = cv2.imread(str(cam0_path), cv2.IMREAD_COLOR)
    img1 = cv2.imread(str(cam1_path), cv2.IMREAD_COLOR)
    if img0 is None:
        raise RuntimeError(f"Failed to read cam0 image: {cam0_path}")
    if img1 is None:
        raise RuntimeError(f"Failed to read cam1 image: {cam1_path}")

    if img0.shape[:2] != img1.shape[:2]:
        raise RuntimeError(f"Image sizes differ: cam0={img0.shape[:2]} cam1={img1.shape[:2]}")

    h, w = img0.shape[:2]
    image_size = (w, h)

    K0 = mat3(calib["cam0_camera_matrix"])
    D0 = vec(calib["cam0_dist_coeffs"])
    K1 = mat3(calib["cam1_camera_matrix"])
    D1 = vec(calib["cam1_dist_coeffs"])
    R = mat3(calib["stereo_R"])
    T = vec(calib["stereo_T"])

    # alpha=0 => crop to valid area, alpha=1 => keep all pixels with black borders.
    R0, R1, P0, P1, Q, roi0, roi1 = cv2.stereoRectify(
        K0, D0, K1, D1, image_size, R, T,
        flags=cv2.CALIB_ZERO_DISPARITY,
        alpha=0
    )

    map0x, map0y = cv2.initUndistortRectifyMap(K0, D0, R0, P0, image_size, cv2.CV_32FC1)
    map1x, map1y = cv2.initUndistortRectifyMap(K1, D1, R1, P1, image_size, cv2.CV_32FC1)

    rect0 = cv2.remap(img0, map0x, map0y, cv2.INTER_LINEAR)
    rect1 = cv2.remap(img1, map1x, map1y, cv2.INTER_LINEAR)

    cv2.imwrite(str(out_dir / "raw_cam0.jpg"), img0)
    cv2.imwrite(str(out_dir / "raw_cam1.jpg"), img1)
    cv2.imwrite(str(out_dir / "rectified_cam0.jpg"), rect0)
    cv2.imwrite(str(out_dir / "rectified_cam1.jpg"), rect1)

    pair = np.hstack([draw_horizontal_grid(rect0), draw_horizontal_grid(rect1)])
    cv2.imwrite(str(out_dir / "rectified_pair_grid.jpg"), pair)

    gray0 = cv2.cvtColor(rect0, cv2.COLOR_BGR2GRAY)
    gray1 = cv2.cvtColor(rect1, cv2.COLOR_BGR2GRAY)

    # Basic SGBM preview. Not final tuning.
    min_disp = 0
    num_disp = 16 * 8
    block_size = 5

    sgbm = cv2.StereoSGBM_create(
        minDisparity=min_disp,
        numDisparities=num_disp,
        blockSize=block_size,
        P1=8 * 1 * block_size * block_size,
        P2=32 * 1 * block_size * block_size,
        disp12MaxDiff=1,
        uniquenessRatio=8,
        speckleWindowSize=80,
        speckleRange=2,
        preFilterCap=31,
        mode=cv2.STEREO_SGBM_MODE_SGBM_3WAY,
    )

    disp = sgbm.compute(gray0, gray1).astype(np.float32) / 16.0
    valid = disp > min_disp

    disp_vis = np.zeros_like(gray0)
    if np.any(valid):
        dmin = np.percentile(disp[valid], 2)
        dmax = np.percentile(disp[valid], 98)
        norm = np.clip((disp - dmin) / max(dmax - dmin, 1e-6), 0, 1)
        disp_vis = (norm * 255).astype(np.uint8)

    cv2.imwrite(str(out_dir / "disparity_preview.jpg"), disp_vis)

    debug = {
        "calibration_json": str(calib_path),
        "cam0": str(cam0_path),
        "cam1": str(cam1_path),
        "image_width": w,
        "image_height": h,
        "calibration_stereo_rms": calib.get("stereo_rms"),
        "initial_stereo_rms": calib.get("initial_stereo_rms"),
        "rejected_pair_indexes": calib.get("rejected_pair_indexes"),
        "board_type": calib.get("board_type"),
        "roi0": list(map(int, roi0)),
        "roi1": list(map(int, roi1)),
        "T": T.reshape(-1).tolist(),
        "baseline_mm_from_T_norm": float(np.linalg.norm(T)),
        "valid_disparity_ratio": float(np.mean(valid)),
    }

    (out_dir / "rectification_debug.json").write_text(json.dumps(debug, indent=2), encoding="utf-8")

    print("OK")
    print(f"out_dir={out_dir}")
    print(f"baseline_mm_from_T_norm={debug['baseline_mm_from_T_norm']:.3f}")
    print(f"valid_disparity_ratio={debug['valid_disparity_ratio']:.3f}")


if __name__ == "__main__":
    main()
