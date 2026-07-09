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



def rectified_axis(P1, P2):
    p2_tx = float(P2[0, 3])
    p2_ty = float(P2[1, 3])
    if abs(p2_tx) >= abs(p2_ty):
        return p2_tx, p2_ty, 'horizontal', 'x'
    return p2_tx, p2_ty, 'vertical', 'y'


def rotate_for_depth_input(img, depth_input_rotation):
    if depth_input_rotation == 'rotate_90_ccw':
        return cv2.rotate(img, cv2.ROTATE_90_COUNTERCLOCKWISE)
    if depth_input_rotation == 'rotate_90_cw':
        return cv2.rotate(img, cv2.ROTATE_90_CLOCKWISE)
    return img


def put_label(img, lines):
    canvas = img.copy()
    y = 28
    for line in lines:
        cv2.putText(canvas, line, (16, y), cv2.FONT_HERSHEY_SIMPLEX, 0.75, (0, 0, 0), 4, cv2.LINE_AA)
        cv2.putText(canvas, line, (16, y), cv2.FONT_HERSHEY_SIMPLEX, 0.75, (255, 255, 255), 2, cv2.LINE_AA)
        y += 30
    return canvas

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
    ap.add_argument('--new-width', type=int, default=0)
    ap.add_argument('--new-height', type=int, default=0)
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

    first0_raw = cv2.imread(str(cap / selected[0]['cam0_file']))
    first1_raw = cv2.imread(str(cap / selected[0]['cam1_file']))
    if first0_raw is None or first1_raw is None:
        raise SystemExit('failed to read first JPEG pair')
    raw_size = (first0_raw.shape[1], first0_raw.shape[0])
    first0 = maybe_rotate(first0_raw, args.cam0_rotate)
    first1 = maybe_rotate(first1_raw, args.cam1_rotate)
    size = (first0.shape[1], first0.shape[0])
    flags = 0 if args.no_zero_disparity else cv2.CALIB_ZERO_DISPARITY
    new_size = (args.new_width, args.new_height) if args.new_width > 0 and args.new_height > 0 else size
    R0,R1,P0,P1,Q,roi0,roi1 = cv2.stereoRectify(K0,D0,K1,D1,size,R,T,alpha=args.alpha,flags=flags,newImageSize=new_size)
    p2_tx, p2_ty, rectified_baseline_axis, disparity_axis = rectified_axis(P0, P1)
    depth_input_rotation = 'none'
    if rectified_baseline_axis == 'vertical':
        depth_input_rotation = 'rotate_90_ccw' if p2_ty < 0 else 'rotate_90_cw'
    depth_method = 'horizontal_q' if rectified_baseline_axis == 'horizontal' else 'vertical_rotated_manual_z'
    q_valid_for_rotated_disparity = rectified_baseline_axis == 'horizontal'
    m00,m01 = cv2.initUndistortRectifyMap(K0,D0,R0,P0,new_size,cv2.CV_16SC2)
    m10,m11 = cv2.initUndistortRectifyMap(K1,D1,R1,P1,new_size,cv2.CV_16SC2)

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
        if i == 1:
            cv2.imwrite(str(out / 'rectified_left_raw.png'), r0)
            cv2.imwrite(str(out / 'rectified_right_raw.png'), r1)
        d0 = rotate_for_depth_input(r0, depth_input_rotation)
        d1 = rotate_for_depth_input(r1, depth_input_rotation)
        if i == 1:
            cv2.imwrite(str(out / 'depth_input_left_rotated.png'), d0)
            cv2.imwrite(str(out / 'depth_input_right_rotated.png'), d1)
        disp = stereo.compute(cv2.cvtColor(d0, cv2.COLOR_BGR2GRAY), cv2.cvtColor(d1, cv2.COLOR_BGR2GRAY))
        prev = cv2.normalize(disp, None, 0, 255, cv2.NORM_MINMAX).astype(np.uint8)
        cv2.imwrite(str(out / f'sample_{i}_disparity_preview.jpg'), prev)
        if i == 1:
            cv2.imwrite(str(out / 'disparity.png'), prev)
        prev_bgr = cv2.cvtColor(prev, cv2.COLOR_GRAY2BGR)
        h = min(d0.shape[0], d1.shape[0], prev_bgr.shape[0])
        panel = np.hstack([d0[:h], d1[:h], cv2.resize(prev_bgr, (d0.shape[1], h))])
        labels = [f'baseline axis: {rectified_baseline_axis}', f'depth input rotation: {depth_input_rotation}', f'sync delta ms: {p.get("stereo_capture_delta_ms", p.get("delta_ms", "unknown"))}', f'stereo rms: {extr.get("stereo_rms", extr.get("rms", "unknown"))}', f'pairs_used: {extr.get("pairs_used", "unknown")}', f'T vector: {np.ravel(T).tolist()}']
        contact.append(put_label(panel, labels))
        debug_pairs.append({'pair_index': p.get('pair_index'), 'cam0_file': p.get('cam0_file'), 'cam1_file': p.get('cam1_file')})
    cv2.imwrite(str(out / 'contact_rectified_synced.jpg'), np.vstack(contact))
    depth_h, depth_w = rotate_for_depth_input(np.zeros((new_size[1], new_size[0], 3), dtype=np.uint8), depth_input_rotation).shape[:2]
    first_pair = selected[0] if selected else {}
    physical_orientation = first_pair.get('physical_orientation') or manifest.get('first_pair_physical_orientation') or manifest.get('operator_orientation') or first_pair.get('operator_orientation') or 'unknown'
    operator_orientation = manifest.get('operator_orientation') or manifest.get('operator_frame_orientation') or physical_orientation or first_pair.get('app_orientation_at_capture') or 'unknown'
    if operator_orientation in (None, 'unknown'):
        operator_orientation = physical_orientation or first_pair.get('operator_orientation') or first_pair.get('app_orientation_at_capture') or 'unknown'
    display_rotation_degrees = manifest.get('display_rotation_degrees')
    if display_rotation_degrees is None:
        display_rotation_degrees = first_pair.get('display_rotation_degrees', first_pair.get('display_rotation_at_capture', None))

    debug = {
        'samples': debug_pairs, 'size': size, 'new_size': new_size, 'raw_frame_width': raw_size[0], 'raw_frame_height': raw_size[1],
        'saved_rotation_degrees_applied': 0, 'operator_frame_orientation': operator_orientation,
        'physical_orientation': physical_orientation,
        'physical_orientation_source': first_pair.get('physical_orientation_source', manifest.get('physical_orientation_source', 'unknown')),
        'display_rotation_degrees': display_rotation_degrees,
        'config_orientation': first_pair.get('config_orientation', manifest.get('first_pair_config_orientation', manifest.get('config_orientation', 'unknown'))),
        'pair_orientation_timestamp_ns': first_pair.get('pair_orientation_timestamp_ns'),
        'imu_sample_timestamp_ns': first_pair.get('imu_sample_timestamp_ns'),
        'imu_sample_delta_ms': first_pair.get('imu_sample_delta_ms'),
        'imu_gravity_x': first_pair.get('imu_gravity_x'),
        'imu_gravity_y': first_pair.get('imu_gravity_y'),
        'imu_gravity_z': first_pair.get('imu_gravity_z'),
        'stereo_T': np.ravel(T).tolist(), 'P1': P0.tolist(), 'P2': P1.tolist(), 'p2_tx': p2_tx, 'p2_ty': p2_ty,
        'rectified_baseline_axis': rectified_baseline_axis, 'disparity_axis': disparity_axis,
        'depth_input_rotation': depth_input_rotation, 'depth_input_width': depth_w, 'depth_input_height': depth_h, 'depth_disparity_axis': 'x',
        'q_valid_for_rotated_disparity': q_valid_for_rotated_disparity, 'depth_method': depth_method,
        'roi0': list(map(int, roi0)), 'roi1': list(map(int, roi1)), 'alpha': args.alpha, 'zero_disparity': not args.no_zero_disparity
    }
    (out / 'rectification_synced_debug.json').write_text(json.dumps(debug, indent=2), encoding='utf-8')

if __name__ == '__main__':
    main()
