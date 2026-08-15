#!/usr/bin/env python3
import argparse, json, math, os, shutil, subprocess, statistics
from pathlib import Path
from imu_utils import parse_imu_jsonl, frame_motion_at
try:
    import cv2
    import numpy as np
except ImportError as exc:
    raise SystemExit(f"OpenCV/numpy required: {exc}")

def run(cmd):
    return subprocess.check_output(cmd, text=True, stderr=subprocess.DEVNULL).strip()

def ffprobe(video):
    data=json.loads(run(['ffprobe','-v','error','-select_streams','v:0','-show_entries','stream=width,height,avg_frame_rate,r_frame_rate:stream_tags=rotate:stream_side_data=rotation','-show_entries','format=duration','-of','json',video]) or '{}')
    st=(data.get('streams') or [{}])[0]; dur=float((data.get('format') or {}).get('duration') or 0)
    def rate(s):
        try:
            a,b=s.split('/'); return float(a)/float(b) if float(b) else 0.0
        except Exception: return 0.0
    fps=rate(st.get('avg_frame_rate') or '') or rate(st.get('r_frame_rate') or '')
    rot=int(float((st.get('tags') or {}).get('rotate') or 0))
    for sd in st.get('side_data_list') or []:
        if 'rotation' in sd: rot=int(float(sd.get('rotation') or 0))
    return {'duration':dur,'fps':fps,'width':int(st.get('width') or 0),'height':int(st.get('height') or 0),'rotation':rot}

def scale_filter(limit, allow):
    if allow:
        return f"scale='if(gte(iw,ih),{limit},-2)':'if(gte(iw,ih),-2,{limit})'"
    return f"scale='if(gte(iw,ih),min(iw,{limit}),-2)':'if(gte(iw,ih),-2,min(ih,{limit}))'"

def extract(video, outdir, count, duration, source_fps, minfps, maxfps, width, allow, q):
    outdir.mkdir(parents=True, exist_ok=True)
    for p in outdir.glob('candidate_*.jpg'): p.unlink()
    fps=count/duration if duration>0 else minfps
    fps=max(minfps, min(maxfps, fps))
    if source_fps>0: fps=min(fps, source_fps)
    vf=f"fps=fps={fps:.8f}:start_time=0,{scale_filter(width, allow)}"
    subprocess.check_call(['ffmpeg','-y','-i',video,'-map','0:v:0','-vf',vf,'-q:v',str(q),str(outdir/'candidate_%06d.jpg')])
    return fps

def metric(path, prev_thumb):
    img=cv2.imread(str(path));
    if img is None: return None
    gray=cv2.cvtColor(img, cv2.COLOR_BGR2GRAY)
    sharp=float(cv2.Laplacian(gray, cv2.CV_64F).var()); mean=float(gray.mean()); std=float(gray.std())
    dark=float((gray<25).mean()); bright=float((gray>245).mean())
    thumb=cv2.resize(gray,(64,36)).astype('float32')/255.0
    dup=0.0 if prev_thumb is None else float(np.mean(np.abs(thumb-prev_thumb)))
    return {'sharpness':sharp,'blur_score':1/(1+sharp),'brightness_mean':mean,'brightness_std':std,'contrast':std,'underexposure_ratio':dark,'overexposure_ratio':bright,'duplicate_similarity':1-dup,'motion_change_score':dup,'_thumb':thumb}

def percentile(vals, pct):
    if not vals: return 0.0
    vals=sorted(vals); k=(len(vals)-1)*pct/100; f=math.floor(k); c=math.ceil(k)
    return vals[int(k)] if f==c else vals[f]*(c-k)+vals[c]*(k-f)

def main():
    ap=argparse.ArgumentParser(); ap.add_argument('--video',required=True); ap.add_argument('--output-dir',required=True); ap.add_argument('--sampling-mode',default='auto_quality'); ap.add_argument('--target-frames',type=int,default=400); ap.add_argument('--candidate-multiplier',type=float,default=1.5); ap.add_argument('--min-fps',type=float,default=.25); ap.add_argument('--max-fps',type=float,default=10); ap.add_argument('--scale-width',type=int,default=1920); ap.add_argument('--allow-upscale',action='store_true'); ap.add_argument('--jpeg-quality',type=int,default=2); ap.add_argument('--keep-candidates',action='store_true'); ap.add_argument('--bridge-overlap-sampling',action='store_true'); ap.add_argument('--bridge-interval-sec',type=float,default=0.5); ap.add_argument('--bridge-window-sec',type=float,default=2.0); ap.add_argument('--max-allowed-selected-gap-sec',type=float,default=1.5); ap.add_argument('--boundary-frames-per-window',type=int,default=2); ap.add_argument('--bridge-max-frames-multiplier',type=float,default=1.5); ap.add_argument('--imu-jsonl'); ap.add_argument('--imu-settings',default='{}')
    a=ap.parse_args(); out=Path(a.output_dir); cand=out/'candidates'; frames=out/'frames'; qual=out/'quality'
    imu=parse_imu_jsonl(a.imu_jsonl) if a.imu_jsonl else None
    imu_cfg={'enabled':True,'prefer_stable_frames':True,'soft_gyro_threshold_deg_sec':45,'hard_gyro_threshold_deg_sec':120,'accel_deviation_threshold':2.5,'motion_penalty_weight':0.25,'maximum_imu_rejection_ratio':0.20,'allow_coverage_fallback':True}
    try: imu_cfg.update(json.loads(a.imu_settings) if a.imu_settings else {})
    except Exception: pass
    imu_enabled=bool(imu and imu.records and imu.sync_info.get('quality') in ('exact','good'))
    frames.mkdir(parents=True,exist_ok=True); qual.mkdir(parents=True,exist_ok=True)
    for p in frames.glob('frame_*.jpg'): p.unlink()
    info=ffprobe(a.video); duration=max(info['duration'], .001)
    ccount=max(1, int(round(a.target_frames*(1 if a.sampling_mode=='auto_uniform' else a.candidate_multiplier))))
    eff=extract(a.video,cand,ccount,duration,info['fps'],a.min_fps,a.max_fps,a.scale_width,a.allow_upscale,a.jpeg_quality)
    files=sorted(cand.glob('candidate_*.jpg')); actual=len(files); interval=(1.0/eff if eff>0 else duration/max(actual,1))
    prev=None; rows=[]
    for i,p in enumerate(files):
        m=metric(p, prev); 
        if not m: continue
        prev=m['_thumb']; ts=min(duration,max(0.0,i*interval)); m.update({'candidate':p.name,'timestamp_sec':ts,'video_pts_us':int(round(ts*1_000_000.0)),'index':i,'selected':False,'rejected_reason':''})
        mot=frame_motion_at(imu,ts) if imu_enabled else {}
        av=mot.get('angular_velocity_deg_sec'); adev=mot.get('accel_deviation')
        score=max((av or 0)/max(float(imu_cfg.get('hard_gyro_threshold_deg_sec',120)),1), (adev or 0)/max(float(imu_cfg.get('accel_deviation_threshold',2.5)),.1))
        m.update({'imu_available':bool(imu_enabled),'imu_sync_quality':(imu.sync_info.get('quality') if imu else 'unavailable'),'angular_velocity_rad_sec':mot.get('angular_velocity_rad_sec'),'angular_velocity_deg_sec':av,'acceleration_magnitude':mot.get('acceleration_magnitude'),'accel_deviation':adev,'imu_motion_score':score,'imu_penalized':False})
        rows.append(m)
    sharp_thr=percentile([r['sharpness'] for r in rows],15); med=percentile([r['sharpness'] for r in rows],50); mx=max([r['sharpness'] for r in rows] or [0]); mn=min([r['sharpness'] for r in rows] or [0])
    for r in rows:
        reasons=[]
        if a.sampling_mode=='auto_quality':
            if r['sharpness']<sharp_thr: reasons.append('blur')
            if r['brightness_mean']<35 or r['underexposure_ratio']>.70: reasons.append('dark')
            if r['brightness_mean']>225 or r['overexposure_ratio']>.50: reasons.append('overexposed')
            if r['contrast']<12: reasons.append('low_contrast')
            if r['index']>0 and r['motion_change_score']<0.012: reasons.append('duplicate')
            if imu_enabled and imu_cfg.get('enabled',True):
                av=r.get('angular_velocity_deg_sec')
                if av is not None and av>=float(imu_cfg.get('hard_gyro_threshold_deg_sec',120)): reasons.append('imu_motion')
                elif av is not None and av>=float(imu_cfg.get('soft_gyro_threshold_deg_sec',45)): r['imu_penalized']=True
        base=.55*(r['sharpness']/(mx or 1))+.25*(r['contrast']/80)+.20*r['motion_change_score']*10
        if r.get('imu_penalized'): base-=float(imu_cfg.get('motion_penalty_weight',0.25))*r.get('imu_motion_score',0)
        r['quality_score']=max(0,min(1, base))
        r['rejected_reason']=','.join(reasons)
    selected=[]; bins=max(1,min(a.target_frames,len(rows)))
    for b in range(bins):
        lo=duration*b/bins; hi=duration*(b+1)/bins
        bucket=[r for r in rows if lo<=r['timestamp_sec']<hi and r not in selected] or [r for r in rows if r not in selected]
        good=[r for r in bucket if not r['rejected_reason']]
        choice=max(good or bucket, key=lambda r:r['quality_score']); choice['selected']=True; choice['selection_reason']='best_in_time_bin' if not choice['rejected_reason'] else 'fallback_time_coverage'; selected.append(choice)
    selected=sorted(selected,key=lambda r:r['timestamp_sec'])[:a.target_frames]
    quality_ids={id(r) for r in selected}; max_before=max([selected[i]['timestamp_sec']-selected[i-1]['timestamp_sec'] for i in range(1,len(selected))] or [0])
    forced=[]; bridge_ids=set(); boundary_ids=set()
    hard_ok=lambda r: not (r['brightness_mean']<25 or r['underexposure_ratio']>.85 or r['brightness_mean']>240 or r['overexposure_ratio']>.75 or r['sharpness']<max(1.0, sharp_thr*.35))
    if a.bridge_overlap_sampling and selected:
        max_allowed=max(1, int(round(a.target_frames*a.bridge_max_frames_multiplier)))
        chosen={r['candidate'] for r in selected}
        def add_frame(r, reason, gb=0, ga=0):
            if len(selected)>=max_allowed or r['candidate'] in chosen or not hard_ok(r): return False
            r['selected']=True; r['selection_reason']=reason; selected.append(r); chosen.add(r['candidate'])
            if reason.startswith('bridge_gap'): bridge_ids.add(id(r))
            if reason.startswith('boundary_overlap'): boundary_ids.add(id(r))
            forced.append({'timestamp_sec':round(r['timestamp_sec'],4),'reason':reason,'nearest_gap_before':round(gb,4),'nearest_gap_after':round(ga,4)})
            return True
        base=sorted(selected,key=lambda r:r['timestamp_sec'])
        for prev,nxt in zip(base,base[1:]):
            gap=nxt['timestamp_sec']-prev['timestamp_sec']
            if gap<=a.max_allowed_selected_gap_sec: continue
            n=max(1, int(math.floor(gap/max(a.bridge_interval_sec,.001))))
            for k in range(1,n+1):
                target_ts=prev['timestamp_sec'] + gap*k/(n+1)
                pool=[r for r in rows if prev['timestamp_sec']<r['timestamp_sec']<nxt['timestamp_sec'] and r['candidate'] not in chosen]
                if not pool: break
                r=max(pool, key=lambda x: (-(abs(x['timestamp_sec']-target_ts)), x['quality_score']))
                add_frame(r,'bridge_gap',target_ts-prev['timestamp_sec'],nxt['timestamp_sec']-target_ts)
        if a.bridge_window_sec>0 and a.boundary_frames_per_window>0:
            b=a.bridge_window_sec
            t=b
            while t<duration and len(selected)<max_allowed:
                for side, pool in [('before',[r for r in rows if t-b<=r['timestamp_sec']<t and r['candidate'] not in chosen]),('after',[r for r in rows if t<=r['timestamp_sec']<t+b and r['candidate'] not in chosen])]:
                    pool=sorted(pool, key=lambda r:(abs(r['timestamp_sec']-t), -r['quality_score']))[:max(1,a.boundary_frames_per_window*3)]
                    for r in sorted(pool, key=lambda r:r['quality_score'], reverse=True)[:a.boundary_frames_per_window]:
                        add_frame(r,'boundary_overlap_'+side,abs(r['timestamp_sec']-t),0)
                t+=b
    selected=sorted(selected,key=lambda r:r['timestamp_sec'])
    for i,r in enumerate(selected,1):
        name=f'frame_{i:06d}.jpg'; shutil.copy2(cand/r['candidate'], frames/name); r['output']=name
    gaps=[selected[i]['timestamp_sec']-selected[i-1]['timestamp_sec'] for i in range(1,len(selected))]
    first=selected[0]['timestamp_sec'] if selected else 0; last=selected[-1]['timestamp_sec'] if selected else 0
    coverage=(max(0,last-first)/duration*100) if duration else 0
    def pub(r):
        return {k:(round(v,4) if isinstance(v,float) else v) for k,v in r.items() if not k.startswith('_') and k not in ('selected','index')}
    sel={'video_duration_sec':round(duration,4),'source_fps':round(info['fps'],4),'target_frames':a.target_frames,'candidate_frames':actual,'selected_frames':len(selected),'first_timestamp_sec':round(first,4),'last_timestamp_sec':round(last,4),'effective_sampling_fps':round((len(selected)/duration),4),'coverage_percent':round(coverage,2),'frame_selection_mode':('auto_quality_bridge_overlap' if a.bridge_overlap_sampling and a.sampling_mode!='manual' else a.sampling_mode),'bridge_overlap_sampling':bool(a.bridge_overlap_sampling),'selected_quality_frames':sum(id(r) in quality_ids for r in selected),'selected_bridge_frames':sum(id(r) in bridge_ids for r in selected),'selected_boundary_frames':sum(id(r) in boundary_ids for r in selected),'selected_total_frames':len(selected),'max_selected_gap_sec_before_bridge':round(max_before,4),'max_selected_gap_sec_after_bridge':round(max(gaps or [0]),4),'timestamp_ranges':({'first_timestamp_sec':round(first,4),'last_timestamp_sec':round(last,4)}),'forced_bridge_frames':forced,'frames':[pub(r) for r in selected]}
    rejected=[pub(r) for r in rows if not r.get('selected')]
    summary={'status':'DONE','sampling_mode':a.sampling_mode,'frame_selection_mode':('auto_quality_bridge_overlap' if a.bridge_overlap_sampling and a.sampling_mode!='manual' else a.sampling_mode),'bridge_overlap_sampling':bool(a.bridge_overlap_sampling),'video_duration_sec':round(duration,4),'source_fps':round(info['fps'],4),'target_frames':a.target_frames,'candidate_frames':actual,'selected_frames':len(selected),'selected_quality_frames':sum(id(r) in quality_ids for r in selected),'selected_bridge_frames':sum(id(r) in bridge_ids for r in selected),'selected_boundary_frames':sum(id(r) in boundary_ids for r in selected),'selected_total_frames':len(selected),'max_selected_gap_sec_before_bridge':round(max_before,4),'max_selected_gap_sec_after_bridge':round(max(gaps or [0]),4),'timestamp_ranges':({'first_timestamp_sec':round(first,4),'last_timestamp_sec':round(last,4)}),'forced_bridge_frames':forced,'rejected_blur':sum('blur' in r['rejected_reason'] for r in rows),'rejected_dark':sum('dark' in r['rejected_reason'] for r in rows),'rejected_overexposed':sum('overexposed' in r['rejected_reason'] for r in rows),'rejected_duplicate':sum('duplicate' in r['rejected_reason'] for r in rows),'imu':({'available':bool(imu and imu.records),'sync_method':imu.sync_info.get('method'),'sync_quality':imu.sync_info.get('quality'),'counts':imu.counts()} if imu else {'available':False}),'imu_soft_penalized':sum(bool(r.get('imu_penalized')) for r in rows),'imu_hard_rejected':sum('imu_motion' in r['rejected_reason'] for r in rows),'fallback_frames':sum(r.get('selection_reason')=='fallback_time_coverage' for r in selected),'sharpness':{'min':round(mn,3),'median':round(med,3),'max':round(mx,3)},'coverage':{'first_timestamp_sec':round(first,4),'last_timestamp_sec':round(last,4),'coverage_percent':round(coverage,2),'maximum_gap_sec':round(max(gaps or [0]),4)},'source_width':info['width'],'source_height':info['height'],'rotation':info['rotation'],'output_width':a.scale_width,'output_height':None,'upscaled':False}
    (qual/'frame_quality.json').write_text(json.dumps([pub(r) for r in rows],indent=2),encoding='utf-8'); (qual/'selected_frames.json').write_text(json.dumps(sel,indent=2),encoding='utf-8'); (qual/'rejected_frames.json').write_text(json.dumps(rejected,indent=2),encoding='utf-8'); (qual/'quality_summary.json').write_text(json.dumps(summary,indent=2),encoding='utf-8')
    if not a.keep_candidates: shutil.rmtree(cand, ignore_errors=True)
    print(json.dumps(summary))
if __name__=='__main__': main()