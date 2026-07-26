#!/usr/bin/env python3
from __future__ import annotations

import importlib.util
import sys
import json
import math
import subprocess
import tempfile
from pathlib import Path

import cv2
import numpy as np

SCRIPT = Path(__file__).resolve().parents[1] / 'remote_station' / 'scripts' / 'apply_apriltag_metric_alignment.py'


def rot_y(angle: float) -> np.ndarray:
    c, s = math.cos(angle), math.sin(angle)
    return np.array([[c,0,s],[0,1,0],[-s,0,c]], dtype=float)


def rot_z(angle: float) -> np.ndarray:
    c, s = math.cos(angle), math.sin(angle)
    return np.array([[c,-s,0],[s,c,0],[0,0,1]], dtype=float)


def look_at(camera_center: np.ndarray, target=np.zeros(3)) -> np.ndarray:
    # World/tag to camera, OpenCV convention: x right, y down, z forward.
    forward = target - camera_center
    forward = forward / np.linalg.norm(forward)
    up_world = np.array([0.0, 0.0, 1.0])
    right = np.cross(forward, up_world)
    if np.linalg.norm(right) < 1e-6:
        up_world = np.array([0.0, 1.0, 0.0])
        right = np.cross(forward, up_world)
    right /= np.linalg.norm(right)
    down = np.cross(forward, right)
    down /= np.linalg.norm(down)
    return np.vstack([right, down, forward])


def rot_to_q(R: np.ndarray) -> np.ndarray:
    spec = importlib.util.spec_from_file_location('align_module', SCRIPT)
    mod = importlib.util.module_from_spec(spec)
    assert spec.loader
    sys.modules['align_module'] = mod
    spec.loader.exec_module(mod)
    return mod.rotmat_to_qvec(R)


def create_component(root: Path, comp: int, scale: float, R_tc: np.ndarray, t_tc: np.ndarray, image_start: int):
    d = root / str(comp) / 'txt'
    d.mkdir(parents=True)
    f = 900.0; cx=640.0; cy=360.0
    (d/'cameras.txt').write_text(f"1 PINHOLE 1280 720 {f} {f} {cx} {cy}\n")
    size=0.16; h=size/2
    obj=np.array([[-h,h,0],[h,h,0],[h,-h,0],[-h,-h,0]],float)
    centers_tag=[
        np.array([0.35, -0.10, 0.45]),
        np.array([0.15, -0.35, 0.40]),
        np.array([-0.20, -0.30, 0.50]),
        np.array([-0.35, 0.05, 0.42]),
        np.array([0.05, 0.35, 0.48]),
    ]
    images=[]; detections=[]
    R_ctag_to_comp = R_tc
    for i,C_tag in enumerate(centers_tag):
        # tag = scale*R_tc*comp + t_tc
        C_comp = R_tc.T @ ((C_tag - t_tc)/scale)
        R_cam_tag = look_at(C_tag)
        R_cam_comp = R_cam_tag @ R_tc
        t_cam_comp = -R_cam_comp @ C_comp
        q=rot_to_q(R_cam_comp)
        iid=image_start+i
        name=f"frame_{iid:06d}.jpg"
        images.append(' '.join([str(iid), *[f'{v:.17g}' for v in q], *[f'{v:.17g}' for v in t_cam_comp], '1', name]))
        images.append('')
        t_cam_tag=-R_cam_tag@C_tag
        rvec,_=cv2.Rodrigues(R_cam_tag)
        pts,_=cv2.projectPoints(obj,rvec,t_cam_tag,np.array([[f,0,cx],[0,f,cy],[0,0,1.]],float),None)
        detections.append({'marker_id':7,'image_name':name,'components':[str(comp)],'corners':pts.reshape(-1,2).tolist()})
    (d/'images.txt').write_text('\n'.join(images)+'\n')
    (d/'points3D.txt').write_text('')
    return detections, centers_tag


with tempfile.TemporaryDirectory() as tmp:
    base=Path(tmp); sparse=base/'sparse'; frames=base/'frames'; frames.mkdir()
    det0,_=create_component(sparse,0,2.5,rot_z(0.35)@rot_y(-0.1),np.array([0.2,-0.1,0.05]),1)
    det1,_=create_component(sparse,1,1.7,rot_z(-0.5)@rot_y(0.2),np.array([-0.15,0.18,-0.02]),101)
    for det in det0+det1:
        (frames/det['image_name']).touch()
    assist={'status':'MARKERS_READY','detections':det0+det1}
    assist_path=base/'assist.json'; assist_path.write_text(json.dumps(assist))
    cp=subprocess.run(['python3',str(SCRIPT),'--frames-dir',str(frames),'--sparse-dir',str(sparse),'--assist-json',str(assist_path),'--marker-size-m','0.16','--min-observations','3','--alignment-max-error-m','0.01','--min-baseline-m','0.05','--apply'],text=True,capture_output=True)
    print(cp.stdout,cp.stderr)
    assert cp.returncode==0
    report=json.loads(assist_path.read_text())
    assert report['status']=='METRIC_ALIGNED_AND_STITCHED', report
    assert report['sim3_applied'] is True
    assert report['components_stitched']==1
    assert report['models_after']==1
    assert (sparse/'0'/'images.txt').is_file()
    # Parse final model and verify camera centers are metric tag coordinates.
    spec=importlib.util.spec_from_file_location('align_module2', SCRIPT)
    mod=importlib.util.module_from_spec(spec); assert spec.loader; sys.modules['align_module2']=mod; spec.loader.exec_module(mod)
    model=mod.load_models(sparse)['0']
    centers={img.name:mod.camera_center(img) for img in model.images.values()}
    expected_names=[d['image_name'] for d in det0+det1]
    assert len(centers)==10
    # Both components should land in same tag frame; compare corresponding trajectory centers.
    for i in range(5):
        a=centers[det0[i]['image_name']]
        b=centers[det1[i]['image_name']]
        assert np.linalg.norm(a-b)<1e-5,(a,b)
    scales=sorted(round(e['scale_m_per_model_unit'],6) for e in report['alignment_edges'])
    assert scales==[1.7,2.5],scales
    print('OK')
