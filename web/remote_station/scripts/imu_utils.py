#!/usr/bin/env python3
import bisect, json, math, statistics
from pathlib import Path

SENSORS={'gyro':'gyro','gyroscope':'gyro','accel':'accel','accelerometer':'accel','gravity':'gravity','gravity_vector':'gravity','rotation_vector':'rotation_vector'}

def finite_vec(v,n=None):
    if not isinstance(v,(list,tuple)) or (n and len(v)<n): return None
    try: out=[float(x) for x in (v[:n] if n else v)]
    except Exception: return None
    return out if all(math.isfinite(x) for x in out) else None

def qnorm(q):
    q=finite_vec(q,4)
    if not q: return None
    n=math.sqrt(sum(x*x for x in q))
    return [x/n for x in q] if n and math.isfinite(n) else None

def android_rotation_vector(values):
    v=finite_vec(values,3)
    if not v: return None
    x,y,z=v[:3]
    w=float(values[3]) if len(values)>=4 and math.isfinite(float(values[3])) else math.sqrt(max(0.0,1-x*x-y*y-z*z))
    return qnorm([w,x,y,z])

def quat_angle_deg(a,b):
    a=qnorm(a); b=qnorm(b)
    if not a or not b: return None
    d=abs(sum(a[i]*b[i] for i in range(4))); d=max(-1,min(1,d))
    return math.degrees(2*math.acos(d))

def quat_slerp(a,b,u):
    a=qnorm(a); b=qnorm(b)
    if not a or not b: return None
    d=sum(a[i]*b[i] for i in range(4))
    if d<0: b=[-x for x in b]; d=-d
    d=max(-1,min(1,d)); u=max(0,min(1,u))
    if d>0.9995: return qnorm([a[i]+u*(b[i]-a[i]) for i in range(4)])
    th=math.acos(d); s=math.sin(th)
    return qnorm([(math.sin((1-u)*th)*a[i]+math.sin(u*th)*b[i])/s for i in range(4)])

def q_to_R(q):
    q=qnorm(q) or [1,0,0,0]; w,x,y,z=q
    return [[1-2*y*y-2*z*z,2*x*y-2*z*w,2*x*z+2*y*w],[2*x*y+2*z*w,1-2*x*x-2*z*z,2*y*z-2*x*w],[2*x*z-2*y*w,2*y*z+2*x*w,1-2*x*x-2*y*y]]

def rotate(q,v):
    R=q_to_R(q); return [sum(R[i][j]*v[j] for j in range(3)) for i in range(3)]

class ImuData:
    def __init__(self):
        self.records=[]; self.metadata={}; self.bad_json_lines=0; self.bad_records=0; self.unknown_sensors=0; self.non_monotonic=0; self.sync_info={'method':'unavailable','quality':'unavailable','offset_sec':None}
    def by_sensor(self,s): return [r for r in self.records if r['sensor']==s]
    def counts(self): return {s:len(self.by_sensor(s)) for s in ['gyro','accel','gravity','rotation_vector']}
    def duration(self):
        ts=[r['t_sec'] for r in self.records if r.get('t_sec') is not None]
        return max(ts)-min(ts) if len(ts)>1 else 0.0
    def median_interval_ms(self):
        out={}
        for s in ['gyro','accel','gravity','rotation_vector']:
            ts=[r['t_sec'] for r in self.by_sensor(s)]
            ds=[(b-a)*1000 for a,b in zip(ts,ts[1:]) if b>=a]
            if ds: out[s]=statistics.median(ds)
        return out
    def summary(self):
        ts=[r['t_sec'] for r in self.records if r.get('t_sec') is not None]
        tr=[min(ts),max(ts)] if ts else [None,None]
        return {'available':bool(self.records),'samples':self.counts(),'time_range_sec':tr,'duration_sec':(tr[1]-tr[0]) if ts and tr[0] is not None else 0.0,'bad_lines':self.bad_json_lines,'bad_records':self.bad_records,'unknown_sensors':self.unknown_sensors,'metadata':self.metadata,'sync_method':self.sync_info.get('method','unavailable'),'sync_quality':self.sync_info.get('quality','unavailable')}

def _timestamp(obj,state):
    if 'video_t_sec' in obj:
        return float(obj['video_t_sec']),'video_t_sec','exact',None
    if 'timestamp_sec' in obj or 'time' in obj or 't' in obj:
        return float(obj.get('timestamp_sec',obj.get('time',obj.get('t')))),'legacy','exact',None
    if 't_ns' in obj:
        tns=int(obj['t_ns'])
        if state.get('video_start_t_ns') is not None:
            off=float(state['video_start_t_ns'])/1e9
            return (tns-int(state['video_start_t_ns']))/1e9,'video_start_t_ns','good',off
        if state.get('first_imu_t_ns') is None: state['first_imu_t_ns']=tns
        return (tns-int(state['first_imu_t_ns']))/1e9,'first_imu_sample','approximate',float(state['first_imu_t_ns'])/1e9
    raise ValueError('missing timestamp')

def parse_imu_jsonl(path):
    data=ImuData()
    if not path or not Path(path).is_file(): return data
    state={'first_imu_t_ns':None,'video_start_t_ns':None}; last={}
    methods=[]
    with open(path,'r',encoding='utf-8',errors='replace') as fh:
        for line in fh:
            try: obj=json.loads(line)
            except Exception: data.bad_json_lines+=1; continue
            if obj.get('type')=='metadata':
                data.metadata.update(obj); state['video_start_t_ns']=obj.get('video_start_t_ns'); continue
            try:
                t,method,quality,offset=_timestamp(obj,state)
                tns=int(obj['t_ns']) if 't_ns' in obj else None
                rec={'t_sec':t,'t_ns':tns,'sensor':'','gyro':None,'accel':None,'gravity':None,'quaternion':None}
                if 'sensor' in obj and 'values' in obj:
                    s=SENSORS.get(str(obj.get('sensor')))
                    if not s: data.unknown_sensors+=1; continue
                    rec['sensor']=s
                    if s=='rotation_vector': rec['quaternion']=android_rotation_vector(obj.get('values'))
                    else: rec[s]=finite_vec(obj.get('values'),3)
                else:
                    found=False
                    for key,s in SENSORS.items():
                        if key in obj and s!='rotation_vector': rec['sensor']=s; rec[s]=finite_vec(obj[key],3); found=True; break
                    q=obj.get('quaternion') or obj.get('orientation') or obj.get('q')
                    if q is not None: rec['sensor']='rotation_vector'; rec['quaternion']=qnorm(q); found=True
                    if not found: data.bad_records+=1; continue
                if not any([rec['gyro'],rec['accel'],rec['gravity'],rec['quaternion']]): data.bad_records+=1; continue
                if rec['sensor'] in last and t < last[rec['sensor']]: data.non_monotonic+=1
                last[rec['sensor']]=t; data.records.append(rec); methods.append((method,quality,offset))
            except Exception: data.bad_records+=1
    data.records.sort(key=lambda r:(r['t_sec'],r['sensor']))
    pref=['video_t_sec','video_start_t_ns','first_imu_sample','legacy']
    if methods:
        m=sorted(methods,key=lambda x:pref.index(x[0]) if x[0] in pref else 99)[0]
        data.sync_info={'method':m[0] if m[0]!='legacy' else 'video_t_sec','quality':m[1],'offset_sec':m[2]}
    return data

def interpolate_quaternion(rows,t,max_gap=0.1):
    qs=[(r['t_sec'],r['quaternion']) for r in rows if r.get('quaternion')]
    ts=[x[0] for x in qs]; i=bisect.bisect_left(ts,t)
    if i<len(qs) and abs(qs[i][0]-t)<=1e-9: return qs[i][1]
    if i==0 or i>=len(qs): return None
    t0,q0=qs[i-1]; t1,q1=qs[i]
    if t-t0>max_gap or t1-t>max_gap or t1<=t0: return None
    return quat_slerp(q0,q1,(t-t0)/(t1-t0))

def integrate_gyro_deg(rows,t0,t1):
    if t1<t0: t0,t1=t1,t0
    gs=[r for r in rows if r.get('gyro') and t0<=r['t_sec']<=t1]
    if len(gs)<2: return None
    s=0.0
    for a,b in zip(gs,gs[1:]):
        dt=max(0,b['t_sec']-a['t_sec']); g=a['gyro']; s+=math.sqrt(sum(x*x for x in g))*dt
    return math.degrees(s)

def frame_motion_at(data,t,window=0.05):
    gy=[r for r in data.by_sensor('gyro') if abs(r['t_sec']-t)<=window]
    ac=[r for r in data.by_sensor('accel') if abs(r['t_sec']-t)<=window]
    gv=max((math.sqrt(sum(x*x for x in r['gyro'])) for r in gy), default=None)
    am=statistics.median([math.sqrt(sum(x*x for x in r['accel'])) for r in ac]) if ac else None
    dev=abs(am-9.80665) if am is not None else None
    return {'angular_velocity_rad_sec':gv,'angular_velocity_deg_sec':math.degrees(gv) if gv is not None else None,'acceleration_magnitude':am,'accel_deviation':dev}

def estimate_gravity(data):
    total=sum(data.counts().values()); source='fallback'; vecs=[]
    if data.by_sensor('gravity'):
        source='imu_gravity'; vecs=[r['gravity'] for r in data.by_sensor('gravity')]
    elif data.by_sensor('rotation_vector'):
        source='imu_rotation_vector'; vecs=[rotate(r['quaternion'],[0,0,-1]) for r in data.by_sensor('rotation_vector') if r.get('quaternion')]
    elif data.by_sensor('accel'):
        source='imu_accel_lowpass'; gyro=data.by_sensor('gyro')
        for r in data.by_sensor('accel'):
            m=math.sqrt(sum(x*x for x in r['accel']))
            if not (7.0<=m<=12.0): continue
            near=[g for g in gyro if abs(g['t_sec']-r['t_sec'])<=0.05]
            if near and max(math.sqrt(sum(x*x for x in g['gyro'])) for g in near)>0.5: continue
            vecs.append(r['accel'])
    if len(vecs)<3: return None
    med=[statistics.median([v[i] for v in vecs]) for i in range(3)]; n=math.sqrt(sum(x*x for x in med)) or 1
    g=[x/n for x in med]; mags=[math.sqrt(sum((v[i]/(math.sqrt(sum(y*y for y in v)) or 1)-g[i])**2 for i in range(3))) for v in vecs]
    std=statistics.pstdev(mags) if len(mags)>1 else 0.0; conf=max(0,min(0.99, len(vecs)/max(20,total or 1) * (1/(1+std*5))))
    return {'source':source,'gravity':g,'samples_total':total,'samples_used':len(vecs),'samples_rejected':max(0,total-len(vecs)),'gravity_stddev':std,'confidence':conf,'sync_quality':data.sync_info.get('quality','unavailable')}