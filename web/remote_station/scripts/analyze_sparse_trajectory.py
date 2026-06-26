#!/usr/bin/env python3
import argparse,json,math,os,re,struct,statistics,time,traceback
from collections import defaultdict,deque
from pathlib import Path
from imu_utils import parse_imu_jsonl, interpolate_quaternion, quat_angle_deg, integrate_gyro_deg

CAMERA_MODELS={0:('SIMPLE_PINHOLE',3),1:('PINHOLE',4),2:('SIMPLE_RADIAL',4),3:('RADIAL',5),4:('OPENCV',8),5:('OPENCV_FISHEYE',8),6:('FULL_OPENCV',12),7:('FOV',5),8:('SIMPLE_RADIAL_FISHEYE',4),9:('RADIAL_FISHEYE',5),10:('THIN_PRISM_FISHEYE',12)}

def unpack(fid,fmt):
    b=fid.read(struct.calcsize(fmt))
    if len(b)!=struct.calcsize(fmt): raise EOFError('unexpected EOF')
    return struct.unpack(fmt,b)
def read_cameras(d):
    p=Path(d)/'cameras.bin'; cams={}
    with open(p,'rb') as f:
        n=unpack(f,'<Q')[0]
        for _ in range(n):
            cid,mid,w,h=unpack(f,'<iiQQ'); name,np=CAMERA_MODELS.get(mid,(f'MODEL_{mid}',0)); params=list(unpack(f,'<'+'d'*np)) if np else []
            cams[cid]={'camera_id':cid,'model_id':mid,'model':name,'width':w,'height':h,'params':params}
    return cams
def read_images(d):
    p=Path(d)/'images.bin'; imgs={}
    with open(p,'rb') as f:
        n=unpack(f,'<Q')[0]
        for _ in range(n):
            iid=unpack(f,'<i')[0]; q=list(unpack(f,'<dddd')); t=list(unpack(f,'<ddd')); cid=unpack(f,'<i')[0]
            name=bytearray()
            while True:
                c=f.read(1)
                if c==b'\x00': break
                if not c: raise EOFError('unterminated image name')
                name.extend(c)
            m=unpack(f,'<Q')[0]; xys=[]; pids=[]
            for _j in range(m):
                x,y,pid=unpack(f,'<ddq'); xys.append((x,y)); pids.append(pid)
            imgs[iid]={'image_id':iid,'qvec':q,'tvec':t,'camera_id':cid,'name':name.decode('utf-8','replace'),'xys':xys,'point3D_ids':pids}
    return imgs
def read_points3d(d):
    p=Path(d)/'points3D.bin'; pts={}; obs=defaultdict(list)
    with open(p,'rb') as f:
        n=unpack(f,'<Q')[0]
        for _ in range(n):
            pid=unpack(f,'<Q')[0]; xyz=list(unpack(f,'<ddd')); rgb=unpack(f,'<BBB'); err=unpack(f,'<d')[0]; tl=unpack(f,'<Q')[0]; track=[]
            for _j in range(tl):
                iid,idx=unpack(f,'<ii'); track.append((iid,idx)); obs[iid].append((pid,idx,err,len(track)))
            pts[pid]={'point3D_id':pid,'xyz':xyz,'rgb':rgb,'error':err,'track':track,'track_length':len(track)}
    return pts,obs
def q_to_R(q):
    w,x,y,z=q; n=math.sqrt(w*w+x*x+y*y+z*z) or 1.0; w,x,y,z=w/n,x/n,y/n,z/n
    return [[1-2*y*y-2*z*z,2*x*y-2*z*w,2*x*z+2*y*w],[2*x*y+2*z*w,1-2*x*x-2*z*z,2*y*z-2*x*w],[2*x*z-2*y*w,2*y*z+2*x*w,1-2*x*x-2*y*y]]
def center(q,t):
    R=q_to_R(q); return [-sum(R[j][i]*t[j] for j in range(3)) for i in range(3)]
def qang(a,b):
    dot=abs(sum(a[i]*b[i] for i in range(4))); dot=max(-1,min(1,dot)); return math.degrees(2*math.acos(dot))
def dist(a,b): return math.sqrt(sum((a[i]-b[i])**2 for i in range(3)))
def pct(vals,p):
    vals=sorted([v for v in vals if v is not None and math.isfinite(v)])
    if not vals: return 0.0
    k=(len(vals)-1)*p/100; f=math.floor(k); c=math.ceil(k)
    return vals[f] if f==c else vals[f]*(c-k)+vals[c]*(k-f)
def med(vals):
    vals=[v for v in vals if v is not None and math.isfinite(v)]; return statistics.median(vals) if vals else 0.0
def mad(vals):
    m=med(vals); return med([abs(v-m) for v in vals])
def frame_key(name):
    m=re.search(r'(\d+)(?=\.[^.]+$)',name); return (int(m.group(1)) if m else 10**18,name)
def timestamps(path):
    mp={}; total=0
    if path and Path(path).exists():
        d=json.loads(Path(path).read_text())
        frames=d.get('frames') if isinstance(d,dict) else d
        total=int(d.get('selected_frames') or len(frames or [])) if isinstance(d,dict) else len(frames or [])
        for i,r in enumerate(frames or []):
            nm=r.get('output') or r.get('name') or r.get('file') or r.get('filename')
            if nm: mp[nm]=float(r.get('timestamp_sec',i))
    return mp,total
def load_imu(path):
    return parse_imu_jsonl(path)
def imu_delta(data,t0,t1,max_gap=0.1):
    if not data or not data.records or t0 is None or t1 is None: return None,None
    rv=data.by_sensor('rotation_vector')
    q0=interpolate_quaternion(rv,t0,max_gap); q1=interpolate_quaternion(rv,t1,max_gap)
    if q0 and q1:
        return quat_angle_deg(q0,q1),'rotation_vector'
    gd=integrate_gyro_deg(data.by_sensor('gyro'),t0,t1)
    return (gd,'gyro') if gd is not None else (None,None)

def imu_summary(data, rec, mism, comparisons, diffs):
    counts=data.counts() if data else {}
    ts=[r['t_sec'] for r in data.records] if data else []
    dur=(max(ts)-min(ts)) if len(ts)>1 else 0.0
    coverage=0.0
    if rec and ts:
        span=max(1e-9, rec[-1]['timestamp_sec']-rec[0]['timestamp_sec'])
        coverage=max(0,min(100,(min(max(ts),rec[-1]['timestamp_sec'])-max(min(ts),rec[0]['timestamp_sec']))/span*100))
    return {'available':bool(data and data.records),'sync_method':(data.sync_info.get('method') if data else 'unavailable'),'sync_quality':(data.sync_info.get('quality') if data else 'unavailable'),'gyro_samples':counts.get('gyro',0),'accel_samples':counts.get('accel',0),'gravity_samples':counts.get('gravity',0),'rotation_vector_samples':counts.get('rotation_vector',0),'duration_sec':dur,'coverage_percent':coverage,'median_sample_interval_ms':(data.median_interval_ms() if data else {}),'rotation_comparisons':comparisons,'rotation_mismatches':len(mism),'mean_rotation_difference_deg':(sum(diffs)/len(diffs) if diffs else 0.0),'p95_rotation_difference_deg':pct(diffs,95),'mismatches':mism}
def clusters(records,radius):
    n=len(records); adj=[[] for _ in range(n)]
    if radius<=0: return [{'id':0,'indices':list(range(n))}] if n else []
    for i in range(n):
        for j in range(i+1,n):
            if dist(records[i]['camera_center'],records[j]['camera_center'])<=radius: adj[i].append(j); adj[j].append(i)
    seen=[False]*n; out=[]
    for i in range(n):
        if seen[i]: continue
        q=deque([i]); seen[i]=True; inds=[]
        while q:
            u=q.popleft(); inds.append(u)
            for v in adj[u]:
                if not seen[v]: seen[v]=True; q.append(v)
        out.append({'id':len(out),'indices':inds})
    return out
def main():
    ap=argparse.ArgumentParser(); ap.add_argument('--model-dir',required=True); ap.add_argument('--selected-frames-json'); ap.add_argument('--imu-jsonl'); ap.add_argument('--output-json',required=True); ap.add_argument('--absolute-reprojection-warning-px',type=float,default=3.0); ap.add_argument('--angular-velocity-warning-deg-sec',type=float,default=120.0); ap.add_argument('--imu-mismatch-threshold-deg',type=float,default=35.0)
    a=ap.parse_args(); started=time.time()
    try:
        cams=read_cameras(a.model_dir); imgs=read_images(a.model_dir); pts,obs=read_points3d(a.model_dir); tsmap,selected=timestamps(a.selected_frames_json)
        rec=[]; all_err=[]
        for iid,img in sorted(imgs.items(),key=lambda kv:frame_key(kv[1]['name'])):
            o=obs.get(iid,[]); errs=[x[2] for x in o]; tls=[pts[x[0]]['track_length'] for x in o if x[0] in pts]; all_err+=errs
            nm=img['name']; idx=len(rec); t=tsmap.get(nm,float(idx))
            rec.append({'image_id':iid,'name':nm,'timestamp_sec':t,'camera_center':center(img['qvec'],img['tvec']),'quaternion':img['qvec'],'translation':img['tvec'],'registered_points2d':len(img['point3D_ids']),'observed_points3d':len(errs),'mean_reprojection_error':sum(errs)/len(errs) if errs else 0.0,'median_reprojection_error':med(errs),'p95_reprojection_error':pct(errs,95),'track_length_mean':sum(tls)/len(tls) if tls else 0.0,'track_length_median':med(tls),'position_step_from_previous':0.0,'rotation_step_deg_from_previous':0.0,'speed_proxy':0.0,'suspicion_score':0.0,'pose_cluster':0,'warnings':[]})
        gmed=med(all_err); gmad=mad(all_err); high_thr=max(a.absolute_reprojection_warning_px,gmed+3*gmad)
        steps=[]; rots=[]; events=[]
        for i in range(1,len(rec)):
            s=dist(rec[i]['camera_center'],rec[i-1]['camera_center']); r=qang(rec[i]['quaternion'],rec[i-1]['quaternion']); dt=(rec[i]['timestamp_sec']-rec[i-1]['timestamp_sec']) if rec[i].get('timestamp_sec') is not None else 0
            rec[i]['position_step_from_previous']=s; rec[i]['rotation_step_deg_from_previous']=r; rec[i]['speed_proxy']=s/dt if dt and dt>0 else s; steps.append(s); rots.append(r)
        mstep=med(steps); madstep=mad(steps); p95step=pct(steps,95); mrot=med(rots); p95rot=pct(rots,95)
        for i in range(len(rec)):
            if rec[i]['median_reprojection_error']>high_thr and rec[i]['observed_points3d']>0: rec[i]['warnings'].append('HIGH_REPROJECTION_ERROR')
            if i>0:
                s=rec[i]['position_step_from_previous']; ratio=s/mstep if mstep>0 else (float('inf') if s>0 else 0)
                if (madstep>0 and s>mstep+6*madstep) or (mstep>0 and s>mstep*8): rec[i]['warnings'].append('POSITION_JUMP'); events.append({'type':'POSITION_JUMP','image':rec[i]['name'],'previous_image':rec[i-1]['name'],'step':s,'median_step':mstep,'ratio':ratio})
                r=rec[i]['rotation_step_deg_from_previous']; dt=rec[i]['timestamp_sec']-rec[i-1]['timestamp_sec']
                if r>45: rec[i]['warnings'].append('ROTATION_JUMP'); events.append({'type':'ROTATION_JUMP','image':rec[i]['name'],'previous_image':rec[i-1]['name'],'rotation_deg':r})
                if dt>0 and dt<1 and r/dt>a.angular_velocity_warning_deg_sec: rec[i]['warnings'].append('HIGH_ANGULAR_VELOCITY'); events.append({'type':'HIGH_ANGULAR_VELOCITY','image':rec[i]['name'],'previous_image':rec[i-1]['name'],'rotation_speed_deg_sec':r/dt})
        cls=clusters(rec,(mstep or 1.0)*5.0); sizes=sorted([len(c['indices']) for c in cls],reverse=True)
        for c in cls:
            for idx in c['indices']: rec[idx]['pose_cluster']=c['id']
        largest=sizes[0] if sizes else 0
        for r in rec:
            if r['pose_cluster']!=0 and len(cls)>1: r['warnings'].append('SECONDARY_POSE_CLUSTER')
            score=0.0; score+=.35*('POSITION_JUMP' in r['warnings']); score+=.20*('ROTATION_JUMP' in r['warnings']); score+=.20*('HIGH_REPROJECTION_ERROR' in r['warnings'])
            score+=.10*(r['observed_points3d']<max(20,med([x['observed_points3d'] for x in rec])*.35)); score+=.10*(r['track_length_median']<3); score+=.20*('SECONDARY_POSE_CLUSTER' in r['warnings']); r['suspicion_score']=min(1.0,score)
        gaps=[]
        if tsmap:
            names=sorted(tsmap,key=frame_key); reg=set(x['name'] for x in rec); start_gap=None; prev_t=None
            for nm in names:
                if nm not in reg and start_gap is None: start_gap=tsmap[nm]
                if nm in reg and start_gap is not None: gaps.append({'from_sec':start_gap,'to_sec':prev_t if prev_t is not None else tsmap[nm],'duration_sec':(prev_t if prev_t is not None else tsmap[nm])-start_gap}); start_gap=None
                prev_t=tsmap[nm]
        imu_rows=load_imu(a.imu_jsonl); mism=[]; comparisons=0; diffs=[]
        allow_mismatch = bool(imu_rows.records) and imu_rows.sync_info.get('quality') != 'unavailable'
        for i in range(1,len(rec)):
            ideg,src=imu_delta(imu_rows,rec[i-1]['timestamp_sec'],rec[i]['timestamp_sec'])
            if ideg is not None:
                comparisons+=1; diff=abs(rec[i]['rotation_step_deg_from_previous']-ideg); diffs.append(diff)
                if allow_mismatch and diff>a.imu_mismatch_threshold_deg:
                    rec[i]['warnings'].append('VISUAL_IMU_ROTATION_MISMATCH'); mism.append({'type':'VISUAL_IMU_ROTATION_MISMATCH','image':rec[i]['name'],'previous_image':rec[i-1]['name'],'from_sec':rec[i-1]['timestamp_sec'],'to_sec':rec[i]['timestamp_sec'],'visual_rotation_deg':rec[i]['rotation_step_deg_from_previous'],'imu_rotation_deg':ideg,'difference_deg':diff,'imu_source':src})
        warnings=[]
        if len(cls)>1 and (sum('POSITION_JUMP' in r['warnings'] or 'ROTATION_JUMP' in r['warnings'] for r in rec)>=1): warnings.append({'type':'POSSIBLE_FALSE_MODEL_MERGE','severity':'HIGH','message':'A secondary camera cluster is linked through suspicious pose jumps.'})
        out={'status':'DONE','model_id':int(Path(a.model_dir).name) if Path(a.model_dir).name.isdigit() else None,'registered_images':len(rec),'selected_frames':selected or len(tsmap) or None,'registration_ratio':(len(rec)/(selected or len(tsmap))) if (selected or len(tsmap)) else None,'reprojection':{'mean_px':sum(all_err)/len(all_err) if all_err else 0.0,'median_px':gmed,'p95_px':pct(all_err,95),'high_error_images':sum('HIGH_REPROJECTION_ERROR' in r['warnings'] for r in rec),'high_error_threshold_px':high_thr},'trajectory':{'median_position_step':mstep,'p95_position_step':p95step,'max_position_step':max(steps or [0]),'position_jumps':sum('POSITION_JUMP' in r['warnings'] for r in rec),'median_rotation_step_deg':mrot,'p95_rotation_step_deg':p95rot,'max_rotation_step_deg':max(rots or [0]),'rotation_jumps':sum('ROTATION_JUMP' in r['warnings'] for r in rec),'pose_clusters':len(cls),'largest_cluster_images':largest,'secondary_cluster_images':sum(sizes[1:]) if len(sizes)>1 else 0,'clusters':[{'cluster_id':c['id'],'images':len(c['indices']),'start_sec':rec[min(c['indices'])]['timestamp_sec'],'end_sec':rec[max(c['indices'])]['timestamp_sec']} for c in cls]},'registration_gaps':{'count':len(gaps),'maximum_gap_sec':max([g['duration_sec'] for g in gaps] or [0]),'longest_unregistered_interval_sec':max([g['duration_sec'] for g in gaps] or [0]),'items':gaps},'imu':imu_summary(imu_rows, rec, mism, comparisons, diffs),'warnings':warnings,'events':events,'suspicious_images':[r for r in rec if r['suspicion_score']>=0.35 or r['warnings']],'images':rec,'duration_sec':round(time.time()-started,3)}
    except Exception as e:
        out={'status':'ERROR','message':str(e),'traceback':traceback.format_exc()}
    Path(a.output_json).parent.mkdir(parents=True,exist_ok=True); Path(a.output_json).write_text(json.dumps(out,indent=2,ensure_ascii=False))
    return 0 if out.get('status')=='DONE' else 1
if __name__=='__main__': raise SystemExit(main())