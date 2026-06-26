#!/usr/bin/env python3
import argparse,json,math
from pathlib import Path
from imu_utils import parse_imu_jsonl, estimate_gravity

def norm(v):
    n=math.sqrt(sum(float(x)*float(x) for x in v)); return [float(x)/n for x in v] if n else None
def cross(a,b): return [a[1]*b[2]-a[2]*b[1],a[2]*b[0]-a[0]*b[2],a[0]*b[1]-a[1]*b[0]]
def dot(a,b): return sum(a[i]*b[i] for i in range(3))
def rot_from_to(a,b):
    a=norm(a); b=norm(b)
    if not a or not b: return [[1,0,0],[0,1,0],[0,0,1]]
    v=cross(a,b); c=max(-1,min(1,dot(a,b))); s=math.sqrt(dot(v,v))
    if s<1e-9: return [[1,0,0],[0,1,0],[0,0,1]] if c>0 else [[1,0,0],[0,-1,0],[0,0,-1]]
    vx=[[0,-v[2],v[1]],[v[2],0,-v[0]],[-v[1],v[0],0]]; k=(1-c)/(s*s)
    return [[(1 if i==j else 0)+vx[i][j]+k*sum(vx[i][m]*vx[m][j] for m in range(3)) for j in range(3)] for i in range(3)]
def mat_quat(R):
    tr=R[0][0]+R[1][1]+R[2][2]
    if tr>0:
        s=math.sqrt(tr+1)*2; return [0.25*s,(R[2][1]-R[1][2])/s,(R[0][2]-R[2][0])/s,(R[1][0]-R[0][1])/s]
    return [1,0,0,0]
def main():
    ap=argparse.ArgumentParser(); ap.add_argument('--model-dir',required=True); ap.add_argument('--camera-trajectory',required=True); ap.add_argument('--imu-jsonl'); ap.add_argument('--dense-ply'); ap.add_argument('--output-json',required=True)
    a=ap.parse_args(); imu=parse_imu_jsonl(a.imu_jsonl) if a.imu_jsonl else None; est=estimate_gravity(imu) if imu else None
    if est and est['confidence']>0.05:
        g=est['gravity']; R=rot_from_to(g,[0,0,-1])
        out={'status':'DONE','source':est['source'],'coordinate_system_from':'COLMAP','coordinate_system_to':'Z_UP','rotation_matrix':R,'quaternion':mat_quat(R),'translation':[0,0,0],'gravity_colmap':g,'gravity_world':[0,0,-1],'samples_total':est['samples_total'],'samples_used':est['samples_used'],'samples_rejected':est['samples_rejected'],'sync_quality':est['sync_quality'],'gravity_stddev':est['gravity_stddev'],'confidence':est['confidence'],'fallback_used':False}
    else:
        out={'status':'UNALIGNED','source':'no_imu','coordinate_system_from':'COLMAP','coordinate_system_to':'Z_UP','rotation_matrix':[[1,0,0],[0,1,0],[0,0,1]],'quaternion':[1,0,0,0],'translation':[0,0,0],'gravity_colmap':None,'gravity_world':[0,0,-1],'samples_total':sum(imu.counts().values()) if imu else 0,'samples_used':0,'samples_rejected':sum(imu.counts().values()) if imu else 0,'sync_quality':imu.sync_info.get('quality','unavailable') if imu else 'unavailable','gravity_stddev':None,'confidence':0.0,'fallback_used':True}
    Path(a.output_json).write_text(json.dumps(out,indent=2))
if __name__=='__main__': main()
