#!/usr/bin/env python3
import argparse,json,re
from pathlib import Path
import analyze_sparse_trajectory as ast

def frame_key(name):
    m=re.search(r'(\d+)(?=\.[^.]+$)',name); return (int(m.group(1)) if m else 10**18,name)

def main():
    ap=argparse.ArgumentParser(); ap.add_argument('--model-dir',required=True); ap.add_argument('--diagnostics-json'); ap.add_argument('--output-json',required=True)
    a=ap.parse_args(); model=Path(a.model_dir); diag={}
    if a.diagnostics_json and Path(a.diagnostics_json).exists(): diag=json.loads(Path(a.diagnostics_json).read_text())
    byname={r.get('name'):r for r in diag.get('images',[]) if isinstance(r,dict)}; imgs=ast.read_images(a.model_dir); poses=[]; prev_c=prev_q=None
    for iid,img in sorted(imgs.items(), key=lambda kv: frame_key(kv[1]['name'])):
        R=ast.q_to_R(img['qvec']); C=ast.center(img['qvec'],img['tvec']); d=byname.get(img['name'],{})
        pos=0.0 if prev_c is None else ast.dist(C,prev_c); rot=0.0 if prev_q is None else ast.qang(img['qvec'],prev_q)
        poses.append({'image_id':iid,'name':img['name'],'timestamp_sec':float(d.get('timestamp_sec',len(poses))),'camera_center':C,'quaternion':img['qvec'],'rotation_matrix':R,'position_step':float(d.get('position_step_from_previous',pos)),'rotation_step_deg':float(d.get('rotation_step_deg_from_previous',rot)),'reprojection_error':float(d.get('median_reprojection_error',0.0)),'pose_cluster':int(d.get('pose_cluster',0)),'suspicion_score':float(d.get('suspicion_score',0.0)),'warnings':d.get('warnings',[]) if isinstance(d.get('warnings',[]),list) else []})
        prev_c,prev_q=C,img['qvec']
    Path(a.output_json).write_text(json.dumps({'model_id':int(model.name) if model.name.isdigit() else None,'coordinate_system':'COLMAP','poses':poses},indent=2,ensure_ascii=False))
if __name__=='__main__': main()