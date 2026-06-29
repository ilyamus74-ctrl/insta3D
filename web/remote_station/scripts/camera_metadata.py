#!/usr/bin/env python3
import argparse, json, math, os, re
from pathlib import Path

ULTRAWIDE_WARNING = 'Ultrawide lens detected. Fisheye camera model may be required for best geometry.'

def load(path):
    if not path or not Path(path).is_file(): return {}
    try: return json.loads(Path(path).read_text(encoding='utf-8'))
    except Exception as e: return {'_parse_error': str(e), '_path': str(path)}

def find(obj, names):
    if not isinstance(obj, (dict,list)): return None
    if isinstance(obj, dict):
        low={str(k).lower():v for k,v in obj.items()}
        for n in names:
            if n.lower() in low: return low[n.lower()]
        for v in obj.values():
            r=find(v,names)
            if r is not None: return r
    else:
        for v in obj:
            r=find(v,names)
            if r is not None: return r
    return None

def num(v):
    if isinstance(v,(int,float)) and not isinstance(v,bool): return float(v)
    if isinstance(v,str):
        m=re.search(r'-?\d+(?:\.\d+)?', v)
        if m: return float(m.group(0))
    return None

def pair(v):
    if isinstance(v, dict):
        a=num(v.get('width') or v.get('x') or v.get('w')); b=num(v.get('height') or v.get('y') or v.get('h'))
        return [a,b] if a and b else None
    if isinstance(v,(list,tuple)) and len(v)>=2:
        a=num(v[0]); b=num(v[1]); return [a,b] if a and b else None
    if isinstance(v,str):
        ms=re.findall(r'\d+(?:\.\d+)?', v)
        if len(ms)>=2: return [float(ms[0]), float(ms[1])]
    return None

def norm_label(v):
    if v is None: return None
    return str(v).strip().lower().replace(' ', '_')

def collect(ci, mf):
    src={'camera_info':ci,'manifest':mf}
    label=find(src,['lens_label','lensLabel','selected_lens_label','camera_lens','lens','camera_type'])
    focal=num(find(src,['focal_length_mm','focalLengthMm','focal_length','focalLengths','android.lens.info.availableFocalLengths']))
    sensor=pair(find(src,['sensor_physical_size_mm','sensorPhysicalSizeMm','physical_sensor_size','sensor_size_mm','android.sensor.info.physicalSize']))
    fov=num(find(src,['approximate_fov_deg','approximateFovDeg','fov_deg','field_of_view_deg','horizontal_fov_deg']))
    resolution=pair(find(src,['resolution','video_resolution','capture_resolution','size','dimensions']))
    fps=num(find(src,['fps','frame_rate','frameRate','video_fps']))
    selected=find(src,['selected_camera_id','selectedCameraId','camera_id','cameraId','id'])
    stab=find(src,['stabilization_mode','stabilizationMode','video_stabilization_mode','ois_mode','eis_mode'])
    if fov is None and focal and sensor:
        fov=math.degrees(2*math.atan(max(sensor)/(2*focal)))
    lens=norm_label(label)
    text=' '.join(str(x).lower() for x in [label, selected, find(src,['camera_name','name','lens_type'])] if x is not None)
    is_wide=bool(re.search(r'ultra[\s_-]?wide|fisheye|fish[\s_-]?eye|0\.5x|0,5x|wide_angle', text)) or (fov is not None and fov >= 100) or (focal is not None and focal <= 2.2)
    if not lens and is_wide: lens='ultrawide'
    out={
      'selected_camera_id': str(selected) if selected is not None else None,
      'lens_label': lens,
      'focal_length_mm': focal,
      'sensor_physical_size_mm': sensor,
      'approximate_fov_deg': round(fov,2) if fov is not None else None,
      'resolution': [int(resolution[0]), int(resolution[1])] if resolution else None,
      'fps': fps,
      'stabilization_mode': str(stab) if stab is not None else None,
      'is_ultrawide_or_fisheye': is_wide,
      'warnings': [ULTRAWIDE_WARNING] if is_wide else [],
    }
    return {k:v for k,v in out.items() if v is not None and v!=[]}

def main():
    ap=argparse.ArgumentParser(); ap.add_argument('--camera-info'); ap.add_argument('--manifest'); ap.add_argument('--output-json'); ap.add_argument('--merge-json'); ap.add_argument('--print-log', action='store_true'); ap.add_argument('--update-diagnostics')
    a=ap.parse_args(); meta=collect(load(a.camera_info), load(a.manifest))
    if a.output_json: Path(a.output_json).write_text(json.dumps(meta,indent=2),encoding='utf-8')
    if a.merge_json:
        p=Path(a.merge_json); d=json.loads(p.read_text()) if p.exists() and p.stat().st_size else {}; d['camera_metadata']=meta; p.write_text(json.dumps(d,indent=2),encoding='utf-8')
    if a.update_diagnostics:
        p=Path(a.update_diagnostics); d=json.loads(p.read_text()) if p.exists() and p.stat().st_size else {}; d['camera_metadata']=meta; d.setdefault('warnings',[])
        for w in meta.get('warnings',[]): d['warnings'].append({'type':'camera_metadata','message':w})
        p.write_text(json.dumps(d,indent=2),encoding='utf-8')
    if a.print_log:
        print(f"INFO | CAMERA_METADATA | Camera lens: {meta.get('lens_label','unknown')}")
        print(f"INFO | CAMERA_METADATA | FOV: {meta.get('approximate_fov_deg','unknown')}")
        print(f"INFO | CAMERA_METADATA | focal_length_mm: {meta.get('focal_length_mm','unknown')}")
        r=meta.get('resolution') or []
        res=(str(r[0])+'x'+str(r[1])) if len(r)>=2 else 'unknown'
        print(f"INFO | CAMERA_METADATA | resolution/fps: {res}/{meta.get('fps','unknown')}")
        if meta.get('stabilization_mode'): print(f"INFO | CAMERA_METADATA | stabilization: {meta.get('stabilization_mode')}")
        for w in meta.get('warnings',[]): print('WARNING | CAMERA_METADATA | '+w)
if __name__=='__main__': main()
