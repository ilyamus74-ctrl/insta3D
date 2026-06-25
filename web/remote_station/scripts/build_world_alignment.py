#!/usr/bin/env python3
import argparse,json,math,statistics
from pathlib import Path

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
def load_imu(path):
    rows=[]
    if not path or not Path(path).exists(): return rows
    for line in Path(path).read_text(errors='replace').splitlines():
        try: r=json.loads(line)
        except Exception: continue
        g=r.get('gravity') or r.get('gravity_vector') or r.get('accel') or r.get('accelerometer')
        if isinstance(g,list) and len(g)>=3:
            m=math.sqrt(sum(float(x)*float(x) for x in g[:3]))
            if 6.5 <= m <= 12.5: rows.append([float(x) for x in g[:3]])
    return rows
def main():
    ap=argparse.ArgumentParser(); ap.add_argument('--model-dir',required=True); ap.add_argument('--camera-trajectory',required=True); ap.add_argument('--imu-jsonl'); ap.add_argument('--dense-ply'); ap.add_argument('--output-json',required=True)
    a=ap.parse_args(); samples=load_imu(a.imu_jsonl)
    if len(samples)>=5:
        med=[statistics.median([v[i] for v in samples]) for i in range(3)]; g=norm(med); R=rot_from_to(g,[0,0,-1]); conf=min(0.99,len(samples)/100.0)
        out={'status':'DONE','source':'imu_gravity','coordinate_system_from':'COLMAP','coordinate_system_to':'Z_UP','rotation_matrix':R,'quaternion':mat_quat(R),'translation':[0,0,0],'gravity_colmap':g,'gravity_world':[0,0,-1],'samples_used':len(samples),'samples_rejected':0,'confidence':conf,'fallback_used':False}
    else:
        out={'status':'UNALIGNED','source':'point_cloud_plane','coordinate_system_from':'COLMAP','coordinate_system_to':'Z_UP','rotation_matrix':[[1,0,0],[0,1,0],[0,0,1]],'quaternion':[1,0,0,0],'translation':[0,0,0],'gravity_colmap':None,'gravity_world':[0,0,-1],'samples_used':0,'samples_rejected':0,'confidence':0.0,'fallback_used':True}
    Path(a.output_json).write_text(json.dumps(out,indent=2))
if __name__=='__main__': main()