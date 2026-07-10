#!/usr/bin/env python3
from __future__ import annotations

import argparse, csv, json, math
from pathlib import Path
import cv2
import numpy as np


def load_json(path: Path):
    with path.open('r', encoding='utf-8') as f: return json.load(f)

def mat(data, shape=None):
    a=np.asarray(data,dtype=np.float64); return a.reshape(shape) if shape else a

def pick(d,*names):
    for n in names:
        if n in d: return d[n]
    raise KeyError(names[0])

def rotate_90_ccw(img): return cv2.rotate(img, cv2.ROTATE_90_COUNTERCLOCKWISE)
def rotate_90_cw(img): return cv2.rotate(img, cv2.ROTATE_90_CLOCKWISE)
def rotate_for_depth_input(img, mode):
    if mode=='rotate_90_ccw': return rotate_90_ccw(img)
    if mode=='rotate_90_cw': return rotate_90_cw(img)
    return img

def axis_from_p2(P2):
    p2_tx=float(P2[0,3]); p2_ty=float(P2[1,3])
    if abs(p2_tx) >= abs(p2_ty): return p2_tx,p2_ty,'horizontal','x','none'
    return p2_tx,p2_ty,'vertical','y',('rotate_90_ccw' if p2_ty < 0 else 'rotate_90_cw')

def ensure_odd(v):
    v=max(3,int(v)); return v if v%2 else v+1

def ensure_disp(v): return max(16, int(math.ceil(int(v)/16))*16)

def colorize_depth(depth, mask):
    out=np.zeros(depth.shape+(3,),np.uint8)
    if np.any(mask):
        vals=depth[mask]; lo,hi=np.percentile(vals,[2,98])
        norm=np.clip((depth-lo)/max(1e-6,hi-lo),0,1)
        out=cv2.applyColorMap((norm*255).astype(np.uint8), cv2.COLORMAP_TURBO)
        out[~mask]=0
    return out

def put_label(img, lines):
    canvas=img.copy(); y=26
    for line in lines:
        cv2.putText(canvas,line,(12,y),cv2.FONT_HERSHEY_SIMPLEX,0.62,(0,0,0),4,cv2.LINE_AA)
        cv2.putText(canvas,line,(12,y),cv2.FONT_HERSHEY_SIMPLEX,0.62,(255,255,255),2,cv2.LINE_AA)
        y+=25
    return canvas

def fit_h(img,h):
    if img.shape[0]==h: return img
    return cv2.resize(img,(max(1,int(img.shape[1]*h/img.shape[0])),h))

def main():
    ap=argparse.ArgumentParser(description='Dense depth from MaklerTour synced depth capture')
    ap.add_argument('stereo_extrinsics_json'); ap.add_argument('synced_depth_capture_dir'); ap.add_argument('out_dir')
    ap.add_argument('--max-pairs',type=int,default=20); ap.add_argument('--pair-index',type=int)
    ap.add_argument('--num-disparities',type=int,default=128); ap.add_argument('--block-size',type=int,default=7); ap.add_argument('--min-disparity',type=int,default=0)
    ap.add_argument('--alpha',type=float,default=0.0); ap.add_argument('--new-width',type=int,default=0); ap.add_argument('--new-height',type=int,default=0)
    ap.add_argument('--max-depth-mm',type=float,default=10000); ap.add_argument('--min-depth-mm',type=float,default=200)
    args=ap.parse_args()
    extr=load_json(Path(args.stereo_extrinsics_json)); cap=Path(args.synced_depth_capture_dir); out=Path(args.out_dir); out.mkdir(parents=True,exist_ok=True)
    manifest=load_json(cap/'synced_depth_manifest.json'); pairs=manifest.get('pairs',[])
    if args.pair_index is not None: selected=[p for p in pairs if int(p.get('pair_index',-1))==args.pair_index]
    else: selected=pairs[:args.max_pairs]
    if not selected: raise SystemExit('no selected pairs')
    K0=mat(pick(extr,'cam0_camera_matrix','camera_matrix_0','K0'),(3,3)); D0=mat(pick(extr,'cam0_dist_coeffs','dist_coeffs_0','D0')).reshape(-1,1)
    K1=mat(pick(extr,'cam1_camera_matrix','camera_matrix_1','K1'),(3,3)); D1=mat(pick(extr,'cam1_dist_coeffs','dist_coeffs_1','D1')).reshape(-1,1)
    R=mat(pick(extr,'stereo_R','R','rotation_matrix'),(3,3)); T=mat(pick(extr,'stereo_T','T','translation_vector')).reshape(3,1)
    first=cv2.imread(str(cap/selected[0]['cam0_file']));
    if first is None: raise SystemExit('failed to read first cam0 image')
    raw_h,raw_w=first.shape[:2]; size=(raw_w,raw_h); new_size=(args.new_width,args.new_height) if args.new_width>0 and args.new_height>0 else size
    R0,R1,P1,P2,Q,roi0,roi1=cv2.stereoRectify(K0,D0,K1,D1,size,R,T,alpha=args.alpha,flags=cv2.CALIB_ZERO_DISPARITY,newImageSize=new_size)
    p2_tx,p2_ty,baseline_axis,disparity_axis,depth_input_rotation=axis_from_p2(P2)
    q_valid_for_rotated_disparity=(baseline_axis=='horizontal'); depth_method='horizontal_manual_z' if baseline_axis=='horizontal' else 'vertical_rotated_manual_z'
    vertical_branch_active = (baseline_axis == 'vertical')
    baseline_magnitude=float(np.linalg.norm(T)); focal_for_depth=float(P1[0,0] if baseline_axis=='horizontal' else P1[1,1])
    m00,m01=cv2.initUndistortRectifyMap(K0,D0,R0,P1,new_size,cv2.CV_16SC2); m10,m11=cv2.initUndistortRectifyMap(K1,D1,R1,P2,new_size,cv2.CV_16SC2)
    nd=ensure_disp(args.num_disparities); bs=ensure_odd(args.block_size); ch=1
    mode=getattr(cv2,'STEREO_SGBM_MODE_SGBM_3WAY',cv2.STEREO_SGBM_MODE_SGBM)
    sgbm=cv2.StereoSGBM_create(minDisparity=args.min_disparity,numDisparities=nd,blockSize=bs,P1=8*ch*bs*bs,P2=32*ch*bs*bs,disp12MaxDiff=1,uniquenessRatio=10,speckleWindowSize=100,speckleRange=2,preFilterCap=31,mode=mode)
    debug_pairs=[]; rows=[]; reviews=[]
    for n,p in enumerate(selected,1):
        idx=int(p.get('pair_index',n-1)); im0=cv2.imread(str(cap/p['cam0_file'])); im1=cv2.imread(str(cap/p['cam1_file']))
        if im0 is None or im1 is None: continue
        r0=cv2.remap(im0,m00,m01,cv2.INTER_LINEAR); r1=cv2.remap(im1,m10,m11,cv2.INTER_LINEAR)
        d0=rotate_for_depth_input(r0,depth_input_rotation); d1=rotate_for_depth_input(r1,depth_input_rotation)
        stem=f'dense_pair_{idx:04d}'; cv2.imwrite(str(out/f'{stem}_rect_cam0.png'),r0); cv2.imwrite(str(out/f'{stem}_rect_cam1.png'),r1); cv2.imwrite(str(out/f'{stem}_depth_input_cam0.png'),d0); cv2.imwrite(str(out/f'{stem}_depth_input_cam1.png'),d1)
        raw=sgbm.compute(cv2.cvtColor(d0,cv2.COLOR_BGR2GRAY),cv2.cvtColor(d1,cv2.COLOR_BGR2GRAY)); disp=raw.astype(np.float32)/16.0; valid_disp=disp>max(0,args.min_disparity)
        np.save(out/f'{stem}_disparity_float.npy',disp); prev=cv2.normalize(np.where(valid_disp,disp,0),None,0,255,cv2.NORM_MINMAX).astype(np.uint8); cv2.imwrite(str(out/f'{stem}_disparity_preview.png'),prev)
        depth=np.zeros_like(disp,np.float32); depth[valid_disp]=(focal_for_depth*baseline_magnitude)/disp[valid_disp]
        valid_depth=valid_disp & np.isfinite(depth) & (depth>=args.min_depth_mm) & (depth<=args.max_depth_mm)
        depth[~valid_depth]=0; np.save(out/f'{stem}_depth_mm.npy',depth); cv2.imwrite(str(out/f'{stem}_depth_mm_16u.png'),np.clip(depth,0,65535).astype(np.uint16)); depth_prev=colorize_depth(depth,valid_depth); cv2.imwrite(str(out/f'{stem}_depth_preview.png'),depth_prev)
        valid_disparity_ratio=float(valid_disp.mean()); valid_depth_ratio=float(valid_depth.mean()); vals=depth[valid_depth]
        stats=(float(vals.min()),float(np.median(vals)),float(vals.max())) if vals.size else (None,None,None)
        info={'pair_index':idx,'stereo_capture_delta_ms':p.get('stereo_capture_delta_ms',p.get('delta_ms')),'physical_orientation':p.get('physical_orientation'),'imu_sample_delta_ms':p.get('imu_sample_delta_ms'),'valid_disparity_ratio':valid_disparity_ratio,'valid_depth_ratio':valid_depth_ratio,'depth_min_mm':stats[0],'depth_median_mm':stats[1],'depth_max_mm':stats[2]}
        debug_pairs.append(info); rows.append(info)
        panel_h=min(360,d0.shape[0]); panel=np.hstack([fit_h(d0,panel_h),fit_h(d1,panel_h),fit_h(cv2.cvtColor(prev,cv2.COLOR_GRAY2BGR),panel_h),fit_h(depth_prev,panel_h)])
        panel=put_label(panel,[f'baseline axis: {baseline_axis}',f'depth method: {depth_method}',f'input rotation: {depth_input_rotation}',f'pair index: {idx}',f'sync delta: {info["stereo_capture_delta_ms"]} ms',f'physical orientation: {info["physical_orientation"]}',f'valid depth ratio: {valid_depth_ratio:.3f}',f'median depth: {stats[1]}'])
        cv2.imwrite(str(out/f'{stem}_review.jpg'),panel); reviews.append(panel)
    if reviews:
        w=max(x.shape[1] for x in reviews); padded=[cv2.copyMakeBorder(x,0,0,0,w-x.shape[1],cv2.BORDER_CONSTANT,value=(0,0,0)) for x in reviews[:20]]; cv2.imwrite(str(out/'contact_dense_depth.jpg'),np.vstack(padded))
    with (out/'dense_depth_summary.csv').open('w',newline='',encoding='utf-8') as f:
        fields=['pair_index','stereo_capture_delta_ms','physical_orientation','imu_sample_delta_ms','valid_disparity_ratio','valid_depth_ratio','depth_min_mm','depth_median_mm','depth_max_mm']; wr=csv.DictWriter(f,fieldnames=fields); wr.writeheader(); wr.writerows(rows)
    valid_depth_ratio_mean=float(np.mean([r['valid_depth_ratio'] for r in rows])) if rows else 0.0
    valid_disparity_ratio_mean=float(np.mean([r['valid_disparity_ratio'] for r in rows])) if rows else 0.0
    debug={'source_capture_dir':str(cap),'samples':debug_pairs,'valid_depth_ratio':valid_depth_ratio_mean,'valid_disparity_ratio':valid_disparity_ratio_mean,'raw_frame_width':raw_w,'raw_frame_height':raw_h,'new_size':list(new_size),'stereo_T':np.ravel(T).tolist(),'baseline_magnitude':baseline_magnitude,'P1':P1.tolist(),'P2':P2.tolist(),'p2_tx':p2_tx,'p2_ty':p2_ty,'rectified_baseline_axis':baseline_axis,'disparity_axis':disparity_axis,'depth_input_rotation':depth_input_rotation,'q_valid_for_rotated_disparity':q_valid_for_rotated_disparity,'depth_method':depth_method,'focal_for_depth':focal_for_depth,'num_disparities':nd,'block_size':bs,'min_disparity':args.min_disparity,'min_depth_mm':args.min_depth_mm,'max_depth_mm':args.max_depth_mm}
    (out/'dense_depth_debug.json').write_text(json.dumps(debug,indent=2),encoding='utf-8')
if __name__=='__main__': main()