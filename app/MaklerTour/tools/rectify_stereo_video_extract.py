#!/usr/bin/env python3
import argparse
import json
import math
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


def safe_frame_count(path: Path) -> int:
    cap = cv2.VideoCapture(str(path))
    if not cap.isOpened():
        raise RuntimeError(f"Cannot open video: {path}")

    n = int(cap.get(cv2.CAP_PROP_FRAME_COUNT))
    if n > 0 and n < 10_000_000:
        cap.release()
        return n

    count = 0
    while True:
        ok, _ = cap.read()
        if not ok:
            break
        count += 1
    cap.release()
    return count


def read_selected_frames(path: Path, indexes):
    wanted = sorted(set(int(i) for i in indexes if i >= 0))
    if not wanted:
        return {}

    cap = cv2.VideoCapture(str(path))
    if not cap.isOpened():
        raise RuntimeError(f"Cannot open video: {path}")

    out = {}
    wanted_set = set(wanted)
    max_idx = max(wanted)
    idx = 0

    while idx <= max_idx:
        ok, frame = cap.read()
        if not ok:
            break
        if idx in wanted_set:
            out[idx] = frame
        idx += 1

    cap.release()
    return out


def load_manifest(capture_dir: Path, name: str):
    p = capture_dir / name
    return load_json(p) if p.exists() else {}


def duration_from_manifest(m0, m1):
    starts = [m.get("start_timestamp_ns") for m in (m0, m1) if m.get("start_timestamp_ns")]
    stops = [m.get("stop_timestamp_ns") for m in (m0, m1) if m.get("stop_timestamp_ns")]
    if starts and stops:
        start = max(starts)
        stop = min(stops)
        if stop > start:
            return (stop - start) / 1_000_000_000.0
    return None


def rotate_frame(frame, rotate):
    if rotate == "none":
        return frame
    if rotate == "cw":
        return cv2.rotate(frame, cv2.ROTATE_90_CLOCKWISE)
    if rotate == "ccw":
        return cv2.rotate(frame, cv2.ROTATE_90_COUNTERCLOCKWISE)
    if rotate == "180":
        return cv2.rotate(frame, cv2.ROTATE_180)
    raise ValueError(f"Unknown rotation: {rotate}")


def fit_frame_to_expected(frame, expected_w, expected_h, rotate_mode, camera):
    h, w = frame.shape[:2]

    if rotate_mode != "auto":
        out = rotate_frame(frame, rotate_mode)
        oh, ow = out.shape[:2]
        if (ow, oh) != (expected_w, expected_h):
            raise RuntimeError(
                f"{camera} size mismatch after rotate={rotate_mode}: got {ow}x{oh}, expected {expected_w}x{expected_h}"
            )
        return out, rotate_mode

    if (w, h) == (expected_w, expected_h):
        return frame, "none"

    for mode in ("cw", "ccw", "180"):
        out = rotate_frame(frame, mode)
        oh, ow = out.shape[:2]
        if (ow, oh) == (expected_w, expected_h):
            return out, mode

    raise RuntimeError(
        f"{camera} size mismatch: got {w}x{h}, expected {expected_w}x{expected_h}. "
        f"Likely video was recorded with another camera mode."
    )


def draw_grid(img, step=40):
    out = img.copy()
    h, w = out.shape[:2]
    for y in range(0, h, step):
        cv2.line(out, (0, y), (w - 1, y), (0, 255, 255), 1)
    return out


def make_disparity(rect0, rect1):
    gray0 = cv2.cvtColor(rect0, cv2.COLOR_BGR2GRAY)
    gray1 = cv2.cvtColor(rect1, cv2.COLOR_BGR2GRAY)

    min_disp = -128
    num_disp = 256
    block_size = 5

    sgbm = cv2.StereoSGBM_create(
        minDisparity=min_disp,
        numDisparities=num_disp,
        blockSize=block_size,
        P1=8 * block_size * block_size,
        P2=32 * block_size * block_size,
        disp12MaxDiff=1,
        uniquenessRatio=8,
        speckleWindowSize=80,
        speckleRange=2,
        preFilterCap=31,
        mode=cv2.STEREO_SGBM_MODE_SGBM_3WAY,
    )

    disp = sgbm.compute(gray0, gray1).astype(np.float32) / 16.0
    valid = (disp > min_disp + 1) & (disp < min_disp + num_disp - 1)

    vis = np.zeros_like(gray0)
    if np.any(valid):
        dmin = float(np.percentile(disp[valid], 2))
        dmax = float(np.percentile(disp[valid], 98))
        norm = np.clip((disp - dmin) / max(dmax - dmin, 1e-6), 0, 1)
        vis = (norm * 255).astype(np.uint8)

    return disp, vis, float(np.mean(valid))


def resize_for_contact(img, width=480):
    h, w = img.shape[:2]
    scale = width / float(w)
    return cv2.resize(img, (width, int(h * scale)), interpolation=cv2.INTER_AREA)


def put_label(img, text):
    out = img.copy()
    cv2.putText(out, text, (12, 28), cv2.FONT_HERSHEY_SIMPLEX, 0.8, (0, 255, 255), 2, cv2.LINE_AA)
    return out


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("calibration_json")
    ap.add_argument("capture_dir")
    ap.add_argument("out_dir")
    ap.add_argument("--samples", type=int, default=8)
    ap.add_argument("--start-sec", type=float, default=0.25)
    ap.add_argument("--end-margin-sec", type=float, default=0.25)
    ap.add_argument("--cam0-rotate", choices=["auto", "none", "cw", "ccw", "180"], default="auto")
    ap.add_argument("--cam1-rotate", choices=["auto", "none", "cw", "ccw", "180"], default="auto")
    args = ap.parse_args()

    calib_path = Path(args.calibration_json)
    capture_dir = Path(args.capture_dir)
    out_dir = Path(args.out_dir)
    out_dir.mkdir(parents=True, exist_ok=True)

    calib = load_json(calib_path)

    cam0_video = capture_dir / "cam0.mp4"
    cam1_video = capture_dir / "cam1.mjpeg"

    if not cam0_video.exists():
        raise RuntimeError(f"Missing cam0.mp4 in {capture_dir}")
    if not cam1_video.exists():
        raise RuntimeError(f"Missing cam1.mjpeg in {capture_dir}")

    m0 = load_manifest(capture_dir, "cam0_manifest.json")
    m1 = load_manifest(capture_dir, "cam1_manifest.json")

    n0 = safe_frame_count(cam0_video)
    n1 = safe_frame_count(cam1_video)

    duration = duration_from_manifest(m0, m1)
    if not duration or duration <= 0:
        fps0_guess = 30.0
        fps1_guess = 30.0
        duration = min(n0 / fps0_guess, n1 / fps1_guess)

    fps0 = n0 / duration
    fps1 = n1 / duration

    exp0_w = int(calib["cam0_image_width"])
    exp0_h = int(calib["cam0_image_height"])
    exp1_w = int(calib["cam1_image_width"])
    exp1_h = int(calib["cam1_image_height"])

    if (exp0_w, exp0_h) != (exp1_w, exp1_h):
        raise RuntimeError(f"Calibration image sizes differ: cam0={exp0_w}x{exp0_h}, cam1={exp1_w}x{exp1_h}")

    image_size = (exp0_w, exp0_h)

    K0 = mat3(calib["cam0_camera_matrix"])
    D0 = vec(calib["cam0_dist_coeffs"])
    K1 = mat3(calib["cam1_camera_matrix"])
    D1 = vec(calib["cam1_dist_coeffs"])
    R = mat3(calib["stereo_R"])
    T = vec(calib["stereo_T"])

    R0, R1, P0, P1, Q, roi0, roi1 = cv2.stereoRectify(
        K0,
        D0,
        K1,
        D1,
        image_size,
        R,
        T,
        flags=cv2.CALIB_ZERO_DISPARITY,
        alpha=0,
    )

    map0x, map0y = cv2.initUndistortRectifyMap(K0, D0, R0, P0, image_size, cv2.CV_32FC1)
    map1x, map1y = cv2.initUndistortRectifyMap(K1, D1, R1, P1, image_size, cv2.CV_32FC1)

    t0 = max(0.0, args.start_sec)
    t1 = max(t0, duration - args.end_margin_sec)
    if args.samples <= 1:
        times = [(t0 + t1) / 2.0]
    else:
        times = np.linspace(t0, t1, args.samples).tolist()

    idx0 = [min(n0 - 1, max(0, int(round(t * fps0)))) for t in times]
    idx1 = [min(n1 - 1, max(0, int(round(t * fps1)))) for t in times]

    frames0 = read_selected_frames(cam0_video, idx0)
    frames1 = read_selected_frames(cam1_video, idx1)

    contact_rows = []
    sample_debug = []

    for sample_i, (t_sec, i0, i1) in enumerate(zip(times, idx0, idx1), start=1):
        if i0 not in frames0 or i1 not in frames1:
            continue

        raw0, rot0 = fit_frame_to_expected(frames0[i0], exp0_w, exp0_h, args.cam0_rotate, "cam0")
        raw1, rot1 = fit_frame_to_expected(frames1[i1], exp1_w, exp1_h, args.cam1_rotate, "cam1")

        rect0 = cv2.remap(raw0, map0x, map0y, cv2.INTER_LINEAR)
        rect1 = cv2.remap(raw1, map1x, map1y, cv2.INTER_LINEAR)

        disp, disp_vis, valid_ratio = make_disparity(rect0, rect1)

        prefix = f"sample_{sample_i:02d}_t{t_sec:.2f}s"
        cv2.imwrite(str(out_dir / f"{prefix}_raw_cam0.jpg"), raw0)
        cv2.imwrite(str(out_dir / f"{prefix}_raw_cam1.jpg"), raw1)
        cv2.imwrite(str(out_dir / f"{prefix}_rect_cam0.jpg"), rect0)
        cv2.imwrite(str(out_dir / f"{prefix}_rect_cam1.jpg"), rect1)
        cv2.imwrite(str(out_dir / f"{prefix}_rect_pair_grid.jpg"), np.hstack([draw_grid(rect0), draw_grid(rect1)]))
        cv2.imwrite(str(out_dir / f"{prefix}_disparity_preview.jpg"), disp_vis)

        disp_color = cv2.applyColorMap(disp_vis, cv2.COLORMAP_TURBO)
        row = np.hstack([
            put_label(resize_for_contact(draw_grid(rect0)), f"{sample_i} cam0 rect"),
            put_label(resize_for_contact(draw_grid(rect1)), f"{sample_i} cam1 rect"),
            put_label(resize_for_contact(disp_color), f"{sample_i} disparity"),
        ])
        contact_rows.append(row)

        sample_debug.append({
            "sample": sample_i,
            "t_sec": t_sec,
            "cam0_index": i0,
            "cam1_index": i1,
            "cam0_rotate_applied": rot0,
            "cam1_rotate_applied": rot1,
            "valid_disparity_ratio": valid_ratio,
        })

    if contact_rows:
        contact = np.vstack(contact_rows)
        cv2.imwrite(str(out_dir / "contact_rectified_video.jpg"), contact)

    debug = {
        "calibration_json": str(calib_path),
        "capture_dir": str(capture_dir),
        "board_type": calib.get("board_type"),
        "stereo_rms": calib.get("stereo_rms"),
        "initial_stereo_rms": calib.get("initial_stereo_rms"),
        "rejected_pair_indexes": calib.get("rejected_pair_indexes"),
        "calibration_image_size": [exp0_w, exp0_h],
        "cam0_video_frames": n0,
        "cam1_video_frames": n1,
        "duration_sec": duration,
        "cam0_fps_est": fps0,
        "cam1_fps_est": fps1,
        "baseline_from_T_norm_mm": float(np.linalg.norm(T)),
        "T": T.reshape(-1).tolist(),
        "roi0": list(map(int, roi0)),
        "roi1": list(map(int, roi1)),
        "samples": sample_debug,
    }

    (out_dir / "rectification_video_debug.json").write_text(json.dumps(debug, indent=2), encoding="utf-8")

    print("OK")
    print(f"out_dir={out_dir}")
    print(f"stereo_rms={calib.get('stereo_rms')}")
    print(f"cam0 frames={n0}, cam1 frames={n1}, duration={duration:.3f}s")
    print(f"fps est: cam0={fps0:.2f}, cam1={fps1:.2f}")
    print(f"baseline_from_T_norm_mm={float(np.linalg.norm(T)):.3f}")
    print(f"contact={out_dir / 'contact_rectified_video.jpg'}")


if __name__ == "__main__":
    main()
