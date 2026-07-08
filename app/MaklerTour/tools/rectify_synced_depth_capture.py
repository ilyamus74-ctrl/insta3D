#!/usr/bin/env python3
"""Rectify MaklerTour synced_depth_capture JPEG pairs directly (no video matching)."""
import argparse, json, math, shutil
from pathlib import Path

import cv2
import numpy as np


def load_json(path):
    with open(path, 'r', encoding='utf-8') as f:
        return json.load(f)


def mat(data, shape=None):
    a = np.asarray(data, dtype=np.float64)
    return a.reshape(shape) if shape else a


def pick(d, *names):
    for name in names:
        if name in d:
            return d[name]
    raise KeyError(names[0])


def maybe_rotate(img, mode):
    if mode in (None, 'auto', 'none'):
        return img
    if mode == 'cw':
        return cv2.rotate(img, cv2.ROTATE_90_CLOCKWISE)
    if mode == 'ccw':
        return cv2.rotate(img, cv2.ROTATE_90_COUNTERCLOCKWISE)
    if mode == '180':
        return cv2.rotate(img, cv2.ROTATE_180)
    raise ValueError(f'unsupported rotate mode: {mode}')


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('stereo_extrinsics_json')
    ap.add_argument('synced_depth_capture_dir')
    ap.add_argument('out_dir')
    ap.add_argument('--samples', type=int, default=8)
    ap.add_argument('--cam0-rotate', default='auto', choices=['auto','none','cw','ccw','180'])
    ap.add_argument('--cam1-rotate', default='auto', choices=['auto','none','cw','ccw','180'])
    ap.add_argument('--alpha', type=float, default=0.0)
    ap.add_argument('--no-zero-disparity', action='store_true')
    args = ap.parse_args()

    extr = load_json(args.stereo_extrinsics_json)
    cap = Path(args.synced_depth_capture_dir)
    out = Path(args.out_dir); out.mkdir(parents=True, exist_ok=True)
    manifest = load_json(cap / 'synced_depth_manifest.json')
    pairs = manifest.get('pairs', [])[:]
    if not pairs:
        raise SystemExit('manifest contains no synced pairs')
    step = max(1, math.floor(len(pairs) / max(1, args.samples)))
    selected = pairs[::step][:args.samples]

    K0 = mat(pick(extr, 'cam0_camera_matrix', 'camera_matrix_0', 'K0'), (3,3))
    D0 = mat(pick(extr, 'cam0_dist_coeffs', 'dist_coeffs_0', 'D0')).reshape(-1,1)
    K1 = mat(pick(extr, 'cam1_camera_matrix', 'camera_matrix_1', 'K1'), (3,3))
    D1 = mat(pick(extr, 'cam1_dist_coeffs', 'dist_coeffs_1', 'D1')).reshape(-1,1)
    R = mat(pick(extr, 'stereo_R', 'R', 'rotation_matrix'), (3,3))
    T = mat(pick(extr, 'stereo_T', 'T', 'translation_vector')).reshape(3,1)

    first0 = cv2.imread(str(cap / selected[0]['cam0_file']))
    first1 = cv2.imread(str(cap / selected[0]['cam1_file']))
    if first0 is None or first1 is None:
        raise SystemExit('failed to read first JPEG pair')
    first0 = maybe_rotate(first0, args.cam0_rotate); first1 = maybe_rotate(first1, args.cam1_rotate)
    size = (first0.shape[1], first0.shape[0])
    flags = 0 if args.no_zero_disparity else cv2.CALIB_ZERO_DISPARITY
    R0,R1,P0,P1,Q,roi0,roi1 = cv2.stereoRectify(K0,D0,K1,D1,size,R,T,alpha=args.alpha,flags=flags)
    m00,m01 = cv2.initUndistortRectifyMap(K0,D0,R0,P0,size,cv2.CV_16SC2)
    m10,m11 = cv2.initUndistortRectifyMap(K1,D1,R1,P1,size,cv2.CV_16SC2)

    contact = []
    debug_pairs = []
    stereo = cv2.StereoBM_create(numDisparities=96, blockSize=15)
    for i, p in enumerate(selected, 1):
        im0 = maybe_rotate(cv2.imread(str(cap / p['cam0_file'])), args.cam0_rotate)
        im1 = maybe_rotate(cv2.imread(str(cap / p['cam1_file'])), args.cam1_rotate)
        r0 = cv2.remap(im0, m00, m01, cv2.INTER_LINEAR)
        r1 = cv2.remap(im1, m10, m11, cv2.INTER_LINEAR)
        cv2.imwrite(str(out / f'sample_{i}_rect_cam0.jpg'), r0)
        cv2.imwrite(str(out / f'sample_{i}_rect_cam1.jpg'), r1)
        disp = stereo.compute(cv2.cvtColor(r0, cv2.COLOR_BGR2GRAY), cv2.cvtColor(r1, cv2.COLOR_BGR2GRAY))
        prev = cv2.normalize(disp, None, 0, 255, cv2.NORM_MINMAX).astype(np.uint8)
        cv2.imwrite(str(out / f'sample_{i}_disparity_preview.jpg'), prev)
        contact.append(np.hstack([r0, r1]))
        debug_pairs.append({'pair_index': p.get('pair_index'), 'cam0_file': p.get('cam0_file'), 'cam1_file': p.get('cam1_file')})
    cv2.imwrite(str(out / 'contact_rectified_synced.jpg'), np.vstack(contact))
    (out / 'rectification_synced_debug.json').write_text(json.dumps({'samples': debug_pairs, 'size': size, 'alpha': args.alpha, 'zero_disparity': not args.no_zero_disparity}, indent=2), encoding='utf-8')

if __name__ == '__main__':
    main()