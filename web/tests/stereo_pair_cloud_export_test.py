#!/usr/bin/env python3
from __future__ import annotations

import importlib.util
import json
import struct
import tempfile
from pathlib import Path

import cv2
import numpy as np


ROOT = Path(__file__).resolve().parents[1]
MODULE_PATH = ROOT / 'remote_station' / 'scripts' / 'dense_depth_from_synced_capture.py'
SPEC = importlib.util.spec_from_file_location('dense_depth_from_synced_capture', MODULE_PATH)
MODULE = importlib.util.module_from_spec(SPEC)
assert SPEC.loader is not None
SPEC.loader.exec_module(MODULE)


def check(condition, message):
    if not condition:
        raise AssertionError(message)


def read_ply(path):
    data=Path(path).read_bytes()
    end=data.index(b'end_header\n')+len(b'end_header\n')
    header=data[:end].decode('ascii')
    count=int(next(line.split()[-1] for line in header.splitlines() if line.startswith('element vertex ')))
    record=struct.Struct('<fffBBB')
    payload=data[end:]
    check(len(payload)==count*record.size,'binary PLY payload length')
    rows=[record.unpack_from(payload,i*record.size) for i in range(count)]
    return header,rows


P1=np.array([[100.0,0.0,1.0,0.0],[0.0,200.0,0.5,0.0],[0.0,0.0,1.0,0.0]],dtype=np.float64)
depth=np.array([[1000.0,1000.0,0.0],[np.nan,2000.0,np.inf]],dtype=np.float32)
valid=np.array([[True,True,False],[True,True,True]])
bgr=np.array([
    [[1,2,3],[4,5,6],[7,8,9]],
    [[10,11,12],[13,14,15],[16,17,18]],
],dtype=np.uint8)

points,rgb=MODULE.backproject_pair_cloud(depth,valid,bgr,P1,stride=1,max_points=100)
check(points.shape==(3,3),'invalid depth excluded')
np.testing.assert_allclose(points[0],[-10.0,-2.5,1000.0],rtol=0,atol=1e-5)
np.testing.assert_allclose(points[1],[0.0,-2.5,1000.0],rtol=0,atol=1e-5)
np.testing.assert_allclose(points[2],[0.0,5.0,2000.0],rtol=0,atol=1e-5)
check(rgb.tolist()==[[3,2,1],[6,5,4],[15,14,13]],'BGR converted to RGB')

original=np.arange(12,dtype=np.float32).reshape(3,4)
for mode in ('rotate_90_ccw','rotate_90_cw','none'):
    rotated=MODULE.rotate_for_depth_input(original,mode)
    restored=MODULE.undo_depth_input_rotation(rotated,mode)
    np.testing.assert_array_equal(restored,original)

many_depth=np.full((10,10),1000.0,np.float32)
many_valid=np.ones((10,10),bool)
many_bgr=np.zeros((10,10,3),np.uint8)
a,_=MODULE.backproject_pair_cloud(many_depth,many_valid,many_bgr,P1,stride=1,max_points=7)
b,_=MODULE.backproject_pair_cloud(many_depth,many_valid,many_bgr,P1,stride=1,max_points=7)
check(len(a)==7,'point cap')
np.testing.assert_array_equal(a,b)

with tempfile.TemporaryDirectory() as td:
    ply=Path(td)/'cloud.ply'
    MODULE.write_binary_ply(ply,points,rgb)
    header,rows=read_ply(ply)
    check('format binary_little_endian 1.0' in header,'binary little-endian header')
    check('element vertex 3' in header,'vertex count header')
    check(rows[0][3:]==(3,2,1),'PLY RGB order')

manifest={
    'schema_version':1,
    'coordinate_system':'rectified_cam0_pair_local',
    'units':'mm',
    'global_fusion_complete':False,
    'pair_cloud_count':1,
    'pair_clouds':[{
        'pair_index':0,
        'cloud_file':'pair_clouds/dense_pair_0000_cloud.ply',
        'point_count':3,
    }],
}
encoded=json.dumps(manifest)
decoded=json.loads(encoded)
check(decoded['global_fusion_complete'] is False,'global fusion must be false')
check(decoded['coordinate_system']=='rectified_cam0_pair_local','coordinate system')
check(decoded['units']=='mm','metric units')
check('transform_cam0_to_world' not in encoded,'F01A must not invent global pose')

process=(ROOT/'remote_station'/'scripts'/'process_maklertour_synced_dense.sh').read_text(encoding='utf-8')
check('--cloud-stride 2' in process,'job enables cloud stride')
check('pair_cloud_manifest.json' in process,'result publishes manifest')
check('"global_fusion_complete":false' in process,'result refuses global claim')

print('OK')
