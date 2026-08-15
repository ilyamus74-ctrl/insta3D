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

def bool_value(v):
    if isinstance(v, bool): return v
    if isinstance(v, (int, float)): return bool(v)
    if isinstance(v, str):
        t=v.strip().lower()
        if t in ('1','true','yes','on'): return True
        if t in ('0','false','no','off'): return False
    return None

def intrinsics_value(v):
    if not isinstance(v, dict): return None
    keys=('fx','fy','cx','cy')
    parsed={k:num(v.get(k)) for k in keys}
    if any(parsed[k] is None for k in keys): return None
    parsed['skew']=num(v.get('skew')) or 0.0
    parsed['source']=str(v.get('source') or 'UNKNOWN')
    parsed['coordinate_space']=str(v.get('coordinate_space') or 'UNKNOWN')
    return parsed

def distortion_value(v):
    if not isinstance(v, dict): return None
    keys=('k1','k2','k3','p1','p2')
    parsed={k:num(v.get(k)) for k in keys}
    if any(parsed[k] is None for k in keys): return None
    parsed['source']=str(v.get('source') or 'UNKNOWN')
    parsed['model']=str(v.get('model') or 'BROWN_CONRADY')
    return parsed

def colmap_prior_value(v):
    if not isinstance(v, dict): return None
    usable=bool_value(v.get('usable_for_colmap'))
    out={'usable_for_colmap': bool(usable)}
    if v.get('reason') is not None: out['reason']=str(v.get('reason'))
    if v.get('source') is not None: out['source']=str(v.get('source'))
    model=v.get('model')
    params=v.get('params')
    if model is not None: out['model']=str(model)
    if isinstance(params, (list,tuple)):
        parsed=[num(x) for x in params]
        if parsed and all(x is not None for x in parsed):
            out['params']=parsed
    source_resolution=pair(v.get('source_resolution'))
    if source_resolution:
        out['source_resolution']=[
            int(round(source_resolution[0])),
            int(round(source_resolution[1])),
        ]
    for key in (
        'video_intrinsics',
        'runtime_crop_sensor_pixels',
        'stream_crop_sensor_pixels',
        'validation',
    ):
        if isinstance(v.get(key), dict):
            out[key]=v.get(key)
    if v.get('distortion_initialization') is not None:
        out['distortion_initialization']=str(v.get('distortion_initialization'))
    return out

def rect_value(v):
    if not isinstance(v, dict):
        return None
    left=num(v.get('left'))
    top=num(v.get('top'))
    right=num(v.get('right'))
    bottom=num(v.get('bottom'))
    if (
        left is None or top is None or
        right is None or bottom is None or
        right <= left or bottom <= top
    ):
        return None
    return {
        'left': left,
        'top': top,
        'right': right,
        'bottom': bottom,
        'width': right-left,
        'height': bottom-top,
    }

def crop_region_value(v):
    if isinstance(v, dict):
        return rect_value(v)
    if isinstance(v, str):
        values=re.findall(r'-?\d+(?:\.\d+)?', v)
        if len(values) >= 4:
            left,top,right,bottom=(float(x) for x in values[:4])
            return rect_value({
                'left': left,
                'top': top,
                'right': right,
                'bottom': bottom,
            })
    return None

def rects_equal(a, b, tolerance=0.5):
    return bool(
        a and b and
        all(
            abs(a[key]-b[key]) <= tolerance
            for key in ('left','top','right','bottom')
        )
    )

def derive_colmap_camera_prior(
    ci,
    capture_source,
    resolution,
    factory_intrinsics,
    runtime,
    existing_prior,
    is_wide,
):
    if existing_prior and existing_prior.get('usable_for_colmap'):
        return existing_prior
    if str(capture_source or '').strip().upper() != 'PHONE_CAMERA' or is_wide:
        return existing_prior

    source='CAMERA2_FACTORY_INTRINSICS_RUNTIME_STREAM_CROP'

    def reject(reason):
        return {
            'usable_for_colmap': False,
            'source': source,
            'reason': reason,
        }

    if not isinstance(ci, dict) or not isinstance(factory_intrinsics, dict):
        return reject('Factory Camera2 intrinsics are unavailable.')
    if (
        str(factory_intrinsics.get('coordinate_space') or '') !=
        'SENSOR_PRE_CORRECTION_ACTIVE_ARRAY_PIXELS'
    ):
        return reject(
            'Factory Camera2 intrinsics are not in '
            'pre-correction active-array coordinates.'
        )
    if not resolution or resolution[0] <= 0 or resolution[1] <= 0:
        return reject('Video resolution is unavailable.')

    active=rect_value(ci.get('active_array_size'))
    pre_correction=rect_value(ci.get('pre_correction_active_array_size'))
    if (
        active is None or
        pre_correction is None or
        not rects_equal(active, pre_correction)
    ):
        return reject(
            'Active array and pre-correction active array are unavailable '
            'or differ.'
        )

    if (
        not isinstance(runtime, dict) or
        int(num(runtime.get('capture_result_count')) or 0) < 10
    ):
        return reject('Insufficient runtime Camera2 capture results.')
    observed=runtime.get('observed_runtime')
    if (
        not isinstance(observed, dict) or
        observed.get('geometry_stable') is not True
    ):
        return reject('Runtime Camera2 geometry is not stable.')

    regions=observed.get('crop_regions')
    if not isinstance(regions, list) or len(regions) != 1:
        return reject('Runtime Camera2 crop region is not unique.')
    runtime_crop=crop_region_value(regions[0])
    if (
        runtime_crop is None or
        not rects_equal(runtime_crop, active) or
        not rects_equal(runtime_crop, pre_correction)
    ):
        return reject(
            'Runtime crop is not the full active/pre-correction array.'
        )

    zoom_min=num(observed.get('zoom_ratio_min'))
    zoom_max=num(observed.get('zoom_ratio_max'))
    if (
        zoom_min is None or
        zoom_max is None or
        abs(zoom_min-1.0) > 1e-3 or
        abs(zoom_max-1.0) > 1e-3
    ):
        return reject('Runtime zoom is not fixed at 1.0x.')

    physical_ids=observed.get('active_physical_camera_ids') or []
    if isinstance(physical_ids, list) and physical_ids:
        return reject('Runtime logical multi-camera switched physical cameras.')

    video_stabilization_modes=observed.get('video_stabilization_modes') or []
    if any(int(num(value) or 0) != 0 for value in video_stabilization_modes):
        return reject('Electronic/video stabilization is active.')

    distortion_correction_modes=observed.get('distortion_correction_modes') or []
    if any(int(num(value) or 0) != 0 for value in distortion_correction_modes):
        return reject('Runtime distortion correction is active.')

    output_width=float(resolution[0])
    output_height=float(resolution[1])
    crop_width=runtime_crop['width']
    crop_height=runtime_crop['height']
    output_aspect=output_width/output_height
    crop_aspect=crop_width/crop_height

    if crop_aspect > output_aspect:
        stream_height=crop_height
        stream_width=stream_height*output_aspect
        stream_left=runtime_crop['left']+(crop_width-stream_width)/2.0
        stream_top=runtime_crop['top']
    else:
        stream_width=crop_width
        stream_height=stream_width/output_aspect
        stream_left=runtime_crop['left']
        stream_top=runtime_crop['top']+(crop_height-stream_height)/2.0

    stream_values=(stream_left, stream_top, stream_width, stream_height)
    if any(abs(value-round(value)) > 1e-6 for value in stream_values):
        return reject(
            'Centered stream aspect crop does not resolve to integral '
            'sensor-pixel geometry.'
        )

    stream_right=stream_left+stream_width
    stream_bottom=stream_top+stream_height
    scale_x=output_width/stream_width
    scale_y=output_height/stream_height

    fx=factory_intrinsics['fx']*scale_x
    fy=factory_intrinsics['fy']*scale_y
    cx=(factory_intrinsics['cx']-stream_left)*scale_x
    cy=(factory_intrinsics['cy']-stream_top)*scale_y
    skew=(factory_intrinsics.get('skew') or 0.0)*scale_x
    focal=(fx+fy)/2.0

    if focal <= 0 or abs(fx-fy)/focal > 0.01:
        return reject(
            'Mapped fx/fy are inconsistent with COLMAP SIMPLE_RADIAL.'
        )
    if not (0.0 <= cx <= output_width and 0.0 <= cy <= output_height):
        return reject('Mapped principal point is outside the video frame.')

    return {
        'usable_for_colmap': True,
        'source': source,
        'model': 'SIMPLE_RADIAL',
        'params': [focal, cx, cy, 0.0],
        'source_resolution': [
            int(round(output_width)),
            int(round(output_height)),
        ],
        'video_intrinsics': {
            'fx': fx,
            'fy': fy,
            'cx': cx,
            'cy': cy,
            'skew': skew,
        },
        'runtime_crop_sensor_pixels': runtime_crop,
        'stream_crop_sensor_pixels': {
            'left': stream_left,
            'top': stream_top,
            'right': stream_right,
            'bottom': stream_bottom,
            'width': stream_width,
            'height': stream_height,
        },
        'validation': {
            'geometry_stable': True,
            'zoom_ratio_min': zoom_min,
            'zoom_ratio_max': zoom_max,
            'video_stabilization_modes': video_stabilization_modes,
            'optical_stabilization_modes':
                observed.get('optical_stabilization_modes') or [],
            'distortion_correction_modes': distortion_correction_modes,
            'active_physical_camera_ids': physical_ids,
        },
        'distortion_initialization': 'ZERO_SIMPLE_RADIAL_BA_REFINES',
        'reason': (
            'Validated Camera2 factory intrinsics mapped through stable 1x '
            'runtime crop and centered output aspect crop; SIMPLE_RADIAL k '
            'starts at 0 for COLMAP refinement.'
        ),
    }

def fov_value(v):
    direct=num(v)
    if direct is not None: return direct
    if isinstance(v,dict):
        for key in ('horizontal','diagonal','vertical','x','width'):
            candidate=num(v.get(key))
            if candidate is not None: return candidate
    if isinstance(v,(list,tuple)):
        for item in v:
            candidate=num(item)
            if candidate is not None: return candidate
    return None

def collect(ci, mf):
    src={'camera_info':ci,'manifest':mf}
    label=find(src,['lens_label','lensLabel','selected_lens_label','camera_lens','lens','camera_type'])
    focal=num(find(src,['focal_length_mm','focalLengthMm','focal_length','focalLengths','android.lens.info.availableFocalLengths']))
    sensor=pair(find(src,['sensor_physical_size_mm','sensorPhysicalSizeMm','physical_sensor_size','sensor_size_mm','android.sensor.info.physicalSize']))
    fov=fov_value(find(src,['approximate_fov_deg','approximateFovDeg','fov_deg','field_of_view_deg','horizontal_fov_deg']))
    resolution=pair(find(src,['resolution','video_resolution','capture_resolution','size','dimensions']))
    fps=num(find(src,['fps','frame_rate','frameRate','video_fps']))
    selected=find(src,['selected_camera_id','selectedCameraId','camera_id','cameraId','id'])
    stab=find(src,['stabilization_mode','stabilizationMode','video_stabilization_mode','ois_mode','eis_mode'])

    # `source` is a generic key also used inside Camera2 optical metadata
    # (`CAMERA2_LENS_INTRINSIC_CALIBRATION`, `CAMERA2_LENS_DISTORTION`).
    # Capture identity must therefore prefer explicit top-level manifest/camera
    # fields and must never obtain a generic nested `source` recursively.
    capture_source=None
    if isinstance(mf,dict):
        capture_source=mf.get('source') or mf.get('capture_source') or mf.get('captureSource')
    if capture_source is None and isinstance(ci,dict):
        capture_source=ci.get('capture_source') or ci.get('captureSource')
    if capture_source is None:
        capture_source=find(src,['capture_source','captureSource'])

    capture_mode=None
    if isinstance(mf,dict):
        capture_mode=mf.get('capture_mode') or mf.get('captureMode')
    if capture_mode is None and isinstance(ci,dict):
        capture_mode=ci.get('capture_mode') or ci.get('captureMode')
    if capture_mode is None:
        capture_mode=find(src,['capture_mode','captureMode'])

    focus_mode=find(src,['focus_mode','focusMode'])
    focus_locked=bool_value(find(src,['focus_locked','focusLocked']))
    focus_distance=num(find(src,['focus_distance_diopters','focusDistanceDiopters']))
    intrinsics_source=find(src,['intrinsics_source','intrinsicsSource'])
    calibration_profile_key=find(src,['calibration_profile_key','calibrationProfileKey'])
    calibration_profile_id=find(src,['calibration_profile_id','calibrationProfileId'])
    factory_intrinsics=intrinsics_value(find(src,['camera2_intrinsic_calibration']))
    factory_distortion=distortion_value(find(src,['camera2_lens_distortion']))
    colmap_prior=colmap_prior_value(find(src,['colmap_camera_prior','colmapCameraPrior']))
    camera2_capture_state=ci.get('camera2_capture_state') if isinstance(ci,dict) else None
    tof_capture_state=ci.get('tof_capture_state') if isinstance(ci,dict) else None
    capture_result_telemetry=ci.get('capture_result_telemetry') if isinstance(ci,dict) else None
    if fov is None and focal and sensor:
        fov=math.degrees(2*math.atan(max(sensor)/(2*focal)))
    lens=norm_label(label)
    text=' '.join(str(x).lower() for x in [label, selected, find(src,['camera_name','name','lens_type'])] if x is not None)
    is_wide=bool(re.search(r'ultra[\s_-]?wide|fisheye|fish[\s_-]?eye|0\.5x|0,5x|wide_angle', text)) or (fov is not None and fov >= 100) or (focal is not None and focal <= 2.2)
    if not lens and is_wide: lens='ultrawide'
    colmap_prior=derive_colmap_camera_prior(
        ci=ci,
        capture_source=capture_source,
        resolution=resolution,
        factory_intrinsics=factory_intrinsics,
        runtime=camera2_capture_state,
        existing_prior=colmap_prior,
        is_wide=is_wide,
    )
    out={
      'selected_camera_id': str(selected) if selected is not None else None,
      'lens_label': lens,
      'focal_length_mm': focal,
      'sensor_physical_size_mm': sensor,
      'approximate_fov_deg': round(fov,2) if fov is not None else None,
      'resolution': [int(resolution[0]), int(resolution[1])] if resolution else None,
      'fps': fps,
      'stabilization_mode': str(stab) if stab is not None else None,
      'capture_source': str(capture_source) if capture_source is not None else None,
      'capture_mode': str(capture_mode) if capture_mode is not None else None,
      'focus_mode': str(focus_mode) if focus_mode is not None else None,
      'focus_locked': focus_locked,
      'focus_distance_diopters': focus_distance,
      'intrinsics_source': str(intrinsics_source) if intrinsics_source is not None else None,
      'calibration_profile_key': str(calibration_profile_key) if calibration_profile_key is not None else None,
      'calibration_profile_id': str(calibration_profile_id) if calibration_profile_id is not None else None,
      'camera2_intrinsic_calibration': factory_intrinsics,
      'camera2_lens_distortion': factory_distortion,
      'colmap_camera_prior': colmap_prior,
      'camera2_capture_state': camera2_capture_state,
      'tof_capture_state': tof_capture_state,
      'capture_result_telemetry': capture_result_telemetry,
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
        if meta.get('capture_source') or meta.get('capture_mode'): print(f"INFO | CAMERA_METADATA | capture_source={meta.get('capture_source','unknown')} capture_mode={meta.get('capture_mode','unknown')}")
        if meta.get('focus_mode'): print(f"INFO | CAMERA_METADATA | focus_mode: {meta.get('focus_mode')} locked={meta.get('focus_locked','unknown')}")
        intr=meta.get('camera2_intrinsic_calibration') or {}
        if intr: print(f"INFO | CAMERA_METADATA | Camera2 sensor intrinsics: fx={intr.get('fx')} fy={intr.get('fy')} cx={intr.get('cx')} cy={intr.get('cy')} space={intr.get('coordinate_space')}")
        runtime=meta.get('camera2_capture_state') or {}
        observed=runtime.get('observed_runtime') or {}
        if runtime: print(f"INFO | CAMERA_METADATA | Runtime Camera2 results={runtime.get('capture_result_count',0)} crop_regions={observed.get('crop_regions_count','unknown')} geometry_stable={observed.get('geometry_stable','unknown')}")
        tof=meta.get('tof_capture_state') or {}
        pairing=tof.get('camera2_pairing') or {}
        if tof: print(f"INFO | CAMERA_METADATA | ToF active={tof.get('active',False)} frames_during_capture={tof.get('frames_during_capture',0)} pairing={pairing.get('status','unknown')} accepted={pairing.get('accepted_pairs',0)} rejected={pairing.get('rejected_pairs',0)}")
        prior=meta.get('colmap_camera_prior') or {}
        if prior:
            print(f"INFO | CAMERA_METADATA | COLMAP prior usable={prior.get('usable_for_colmap',False)} source={prior.get('source','unresolved')} reason={prior.get('reason','')}")
            if prior.get('usable_for_colmap'):
                print(f"INFO | CAMERA_METADATA | COLMAP prior model={prior.get('model','unknown')} params={prior.get('params',[])} source_resolution={prior.get('source_resolution',[])}")
        for w in meta.get('warnings',[]): print('WARNING | CAMERA_METADATA | '+w)
if __name__=='__main__': main()
