#!/usr/bin/env python3
import argparse
import json
import math
from pathlib import Path

import cv2
import numpy as np


def detect_lenses(img):
    h, w = img.shape[:2]
    gray = cv2.cvtColor(img, cv2.COLOR_BGR2GRAY)

    _, mask = cv2.threshold(gray, 15, 255, cv2.THRESH_BINARY)
    mask = cv2.morphologyEx(mask, cv2.MORPH_CLOSE, np.ones((31, 31), np.uint8))
    mask = cv2.morphologyEx(mask, cv2.MORPH_OPEN, np.ones((9, 9), np.uint8))

    contours, _ = cv2.findContours(mask, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)

    circles = []
    for c in contours:
        area = cv2.contourArea(c)
        if area < w * h * 0.05:
            continue
        (cx, cy), r = cv2.minEnclosingCircle(c)
        if r < min(w, h) * 0.20:
            continue
        circles.append({
            "cx": float(cx),
            "cy": float(cy),
            "r": float(r),
            "area": float(area),
            "source": "detected"
        })

    circles = sorted(circles, key=lambda x: x["area"], reverse=True)[:2]
    circles = sorted(circles, key=lambda x: x["cx"])

    if len(circles) == 2:
        return circles[0], circles[1]

    # fallback for 5888x2944 dual-fisheye layout
    r = min(h * 0.492, w * 0.245)
    return (
        {"cx": w * 0.25, "cy": h * 0.5, "r": r, "area": 0, "source": "fallback"},
        {"cx": w * 0.75, "cy": h * 0.5, "r": r, "area": 0, "source": "fallback"},
    )


def lens_maps(out_w, out_h, y0, y1, lens, yaw_deg, roll_deg, fov_deg):
    yy, xx = np.mgrid[y0:y1, 0:out_w].astype(np.float32)

    theta = (xx / out_w) * (2.0 * np.pi) - np.pi
    phi = (0.5 - yy / out_h) * np.pi

    cos_phi = np.cos(phi)

    # world direction: x right, y up, z forward
    dxw = cos_phi * np.sin(theta)
    dyw = np.sin(phi)
    dzw = cos_phi * np.cos(theta)

    yaw = math.radians(yaw_deg)

    # lens forward and right vectors
    fx = math.sin(yaw)
    fz = math.cos(yaw)
    rx = math.cos(yaw)
    rz = -math.sin(yaw)

    # transform world dir into lens local coords
    x = dxw * rx + dzw * rz
    y = dyw
    z = dxw * fx + dzw * fz

    radial = np.sqrt(x * x + y * y)
    alpha = np.arctan2(radial, z)

    half_fov = math.radians(fov_deg / 2.0)
    rr = (alpha / half_fov) * float(lens["r"])

    beta = np.arctan2(y, x) + math.radians(roll_deg)

    map_x = float(lens["cx"]) + rr * np.cos(beta)
    map_y = float(lens["cy"]) - rr * np.sin(beta)

    visible = alpha <= half_fov

    return map_x.astype(np.float32), map_y.astype(np.float32), alpha.astype(np.float32), visible


def blend_weights(alpha_l, vis_l, alpha_r, vis_r, fov_deg, blend_width_deg):
    half_fov = math.radians(fov_deg / 2.0)
    blend = max(math.radians(blend_width_deg), 0.001)

    ml = np.clip((half_fov - alpha_l) / blend, 0, 1)
    mr = np.clip((half_fov - alpha_r) / blend, 0, 1)

    wl = np.where(vis_l, ml * ml, 0).astype(np.float32)
    wr = np.where(vis_r, mr * mr, 0).astype(np.float32)

    s = wl + wr
    wl = np.where(s > 1e-6, wl / s, 0)
    wr = np.where(s > 1e-6, wr / s, 0)

    valid = (vis_l | vis_r)

    return wl[..., None], wr[..., None], valid


def stitch(args):
    img = cv2.imread(args.input, cv2.IMREAD_COLOR)
    if img is None:
        raise RuntimeError(f"Cannot read image: {args.input}")

    src_h, src_w = img.shape[:2]
    left, right = detect_lenses(img)

    out = np.zeros((args.height, args.width, 3), dtype=np.uint8)

    for y0 in range(0, args.height, args.chunk_rows):
        y1 = min(args.height, y0 + args.chunk_rows)

        lmx, lmy, la, lv = lens_maps(
            args.width, args.height, y0, y1,
            left, args.left_yaw, args.left_roll, args.fov
        )
        rmx, rmy, ra, rv = lens_maps(
            args.width, args.height, y0, y1,
            right, args.right_yaw, args.right_roll, args.fov
        )

        limg = cv2.remap(
            img, lmx, lmy,
            interpolation=cv2.INTER_LINEAR,
            borderMode=cv2.BORDER_CONSTANT,
            borderValue=(0, 0, 0)
        )
        rimg = cv2.remap(
            img, rmx, rmy,
            interpolation=cv2.INTER_LINEAR,
            borderMode=cv2.BORDER_CONSTANT,
            borderValue=(0, 0, 0)
        )

        wl, wr, valid = blend_weights(la, lv, ra, rv, args.fov, args.blend_width)

        mixed = limg.astype(np.float32) * wl + rimg.astype(np.float32) * wr
        mixed = np.clip(mixed, 0, 255).astype(np.uint8)
        mixed[~valid] = 0

        out[y0:y1] = mixed

    output = Path(args.output)
    output.parent.mkdir(parents=True, exist_ok=True)

    ok = cv2.imwrite(
        str(output),
        out,
        [int(cv2.IMWRITE_JPEG_QUALITY), int(args.jpeg_quality)]
    )
    if not ok:
        raise RuntimeError(f"Cannot write output: {output}")

    result = {
        "ok": True,
        "input": args.input,
        "output": str(output),
        "source_width": src_w,
        "source_height": src_h,
        "output_width": args.width,
        "output_height": args.height,
        "left": left,
        "right": right,
        "params": {
            "fov": args.fov,
            "blend_width": args.blend_width,
            "left_yaw": args.left_yaw,
            "right_yaw": args.right_yaw,
            "left_roll": args.left_roll,
            "right_roll": args.right_roll,
            "jpeg_quality": args.jpeg_quality
        },
        "output_size_bytes": output.stat().st_size
    }

    if args.debug_json:
        Path(args.debug_json).write_text(json.dumps(result, ensure_ascii=False, indent=2))

    print(json.dumps(result, ensure_ascii=False, indent=2))


def main():
    p = argparse.ArgumentParser()
    p.add_argument("--input", required=True)
    p.add_argument("--output", required=True)
    p.add_argument("--width", type=int, default=4096)
    p.add_argument("--height", type=int, default=2048)
    p.add_argument("--fov", type=float, default=197.0)
    p.add_argument("--blend-width", type=float, default=22.0)
    p.add_argument("--left-yaw", type=float, default=180.0)
    p.add_argument("--right-yaw", type=float, default=0.0)
    p.add_argument("--left-roll", type=float, default=0.0)
    p.add_argument("--right-roll", type=float, default=0.0)
    p.add_argument("--jpeg-quality", type=int, default=92)
    p.add_argument("--chunk-rows", type=int, default=256)
    p.add_argument("--debug-json", default="")
    args = p.parse_args()
    stitch(args)


if __name__ == "__main__":
    main()
