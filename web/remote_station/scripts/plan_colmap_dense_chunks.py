#!/usr/bin/env python3
import argparse,json,os,re,shutil,subprocess,sys,tempfile,time
from pathlib import Path

def run_colmap(args):
    mode = os.environ.get('COLMAP_MODE', 'native')
    colmap_bin = os.environ.get('COLMAP_BIN', 'colmap')
    colmap_image = os.environ.get('COLMAP_IMAGE', '')
    base = os.environ.get('STATION_BASE', '/home/makler_storage')

    if mode == 'native':
        cmd = [colmap_bin] + args
    elif mode == 'podman':
        if not colmap_image:
            raise RuntimeError('COLMAP_IMAGE is required for podman mode')
        if shutil.which('podman') is None:
            raise RuntimeError('podman not found')
        cmd = [
            'podman',
            'run',
            '--rm',
            '--device', 'nvidia.com/gpu=all',
            '--security-opt=label=disable',
            '-v', f'{base}:{base}',
            colmap_image,
            'colmap',
        ] + args
    else:
        raise RuntimeError(f'Unsupported COLMAP_MODE: {mode}')

    subprocess.run(cmd, check=True)

def meminfo():
    vals={}
    for line in open('/proc/meminfo'):
        k,v=line.split(':',1); vals[k]=int(v.strip().split()[0])//1024
    return vals.get('MemTotal',0), vals.get('MemAvailable',0)

def frame_key(name):
    m=re.search(r'(\d+)(?=\.[^.]+$)', name)
    return (int(m.group(1)) if m else 10**18, name)

def registered_images(model_dir):
    model=Path(model_dir); txt=model/'images.txt'
    tmp=None
    try:
        if not txt.exists():
            station_base = Path(
                os.environ.get(
                    "STATION_BASE",
                    "/home/makler_storage",
                )
            )

            tmp_root = station_base / "tmp"
            tmp_root.mkdir(
                parents=True,
                exist_ok=True,
            )

            tmp = Path(
                tempfile.mkdtemp(
                    prefix="colmap_model_txt_",
                    dir=str(tmp_root),
                )
            )

            run_colmap([
                "model_converter",
                "--input_path",
                str(model),
                "--output_path",
                str(tmp),
                "--output_type",
                "TXT",
            ])

            txt = tmp / "images.txt"

        lines=[
            line.strip()
            for line in txt.read_text(errors='replace').splitlines()
            if line.strip() and not line.lstrip().startswith('#')
        ]
        imgs=[]; poses={}
        image_ext=re.compile(r'\.(jpg|jpeg|png|webp|tif|tiff|bmp)$', re.IGNORECASE)

        # COLMAP images.txt stores two data lines per image: the camera record
        # followed by the POINTS2D record. Only parse camera records.
        for index in range(0, len(lines), 2):
            line=lines[index]
            parts=line.split()
            if len(parts)<10:
                raise RuntimeError(f'Invalid COLMAP image record at data line {index + 1}: {line[:200]}')
            try:
                int(parts[0])
                float(parts[1]); float(parts[2]); float(parts[3]); float(parts[4])
                float(parts[5]); float(parts[6]); float(parts[7])
                int(parts[8])
            except ValueError as exc:
                raise RuntimeError(f'Invalid COLMAP image header: {line[:200]}') from exc

            name=' '.join(parts[9:]).strip()
            if not name:
                raise RuntimeError('COLMAP image record has empty filename')
            if re.fullmatch(r'[+-]?(?:\d+(?:\.\d*)?|\.\d+)', name):
                raise RuntimeError(f'Unexpected numeric-only COLMAP image filename: {name}')
            if not image_ext.search(name):
                raise RuntimeError(f'Unexpected COLMAP image filename: {name}')

            imgs.append(name)
            poses[name]=' '.join(parts[1:8])

        imgs=sorted(dict.fromkeys(imgs), key=frame_key)
        if not imgs:
            raise RuntimeError('COLMAP model has no registered images')
        if len(poses)!=len(imgs):
            raise RuntimeError(f'COLMAP image parser mismatch: {len(imgs)} images but {len(poses)} poses')
        return imgs, poses
    finally:
        if tmp and tmp.exists():
            shutil.rmtree(tmp, ignore_errors=True)

def ram_limit(mode, avail):
    if avail < 6144:
        raise SystemExit(
            'insufficient available RAM: MemAvailable below 6 GB'
        )

    if mode == 'preview':
        if avail >= 16384:
            return 100, 'Preview: MemAvailable >= 16 GB'
        if avail >= 10240:
            return 70, 'Preview: MemAvailable 10-16 GB'
        return 45, 'Preview: MemAvailable 6-10 GB'

    if mode == 'standard':
        if avail >= 32768:
            return 100, 'Standard: MemAvailable >= 32 GB'
        if avail >= 16384:
            return 70, 'Standard: MemAvailable 16-32 GB'
        if avail >= 10240:
            return 45, 'Standard: MemAvailable 10-16 GB'
        return 30, 'Standard: MemAvailable 6-10 GB'

    if mode in ('fullhd', 'hq'):
        if avail >= 32768:
            return 80, 'Full HD/HQ: MemAvailable >= 32 GB'
        if avail >= 16384:
            return 55, 'Full HD/HQ: MemAvailable 16-32 GB'
        if avail >= 10240:
            return 35, 'Full HD/HQ: MemAvailable 10-16 GB'
        return 20, 'Full HD/HQ: MemAvailable 6-10 GB'

    raise SystemExit(f'unsupported mode: {mode}')

def main():
    ap=argparse.ArgumentParser()
    ap.add_argument('--sparse-model-dir', required=True)
    ap.add_argument('--model-id', required=True, type=int)
    ap.add_argument(
        '--mode',
        choices=['preview', 'standard', 'fullhd', 'hq'],
        required=True
    )
    ap.add_argument('--output-plan',required=True); ap.add_argument('--target-images-per-chunk',type=int,required=True); ap.add_argument('--max-images-per-chunk',type=int,required=True); ap.add_argument('--overlap-images',type=int,required=True)
    ap.add_argument('--sparse-job-id',type=int,default=0); ap.add_argument('--ram-reserve-mb',type=int,default=3000)
    args=ap.parse_args(); started=time.time(); total,avail=meminfo(); safe,reason=ram_limit(args.mode, avail)
    selected=max(1,min(args.max_images_per_chunk,safe)); target=max(1,min(args.target_images_per_chunk,selected)); overlap=max(0,min(args.overlap_images, selected-1))
    images,poses=registered_images(args.sparse_model_dir)
    chunks=[]; start=0; cid=0; step=max(1,target-overlap)
    while start < len(images):
        end=min(len(images), start+target); chunk=images[start:end]
        chunks.append({'chunk_id':cid,'start_index':start,'end_index':end-1,'image_count':len(chunk),'image_list_path':str(Path(args.output_plan).parent/'chunks'/f'chunk_{cid}'/'image_list.txt'),'images':chunk})
        if end>=len(images): break
        start += step; cid += 1
    out=Path(args.output_plan); (out.parent/'chunks').mkdir(parents=True,exist_ok=True)
    for c in chunks:
        p=Path(c['image_list_path']); p.parent.mkdir(parents=True,exist_ok=True); p.write_text('\n'.join(c['images'])+'\n')
    plan={'status':'DONE','mode':args.mode,'sparse_job_id':args.sparse_job_id,'model_id':args.model_id,'registered_images_total':len(images),'parser_validated':True,'target_images_per_chunk':target,'overlap_images':overlap,'total_ram_mb':total,'available_ram_mb':avail,'reserved_ram_mb':args.ram_reserve_mb,'usable_ram_mb':avail-args.ram_reserve_mb,'selected_max_images_per_chunk':selected,'calculation_reason':reason,'poses_by_image':poses,'chunks':chunks,'duration_sec':round(time.time()-started,3)}
    out.write_text(json.dumps(plan,indent=2,ensure_ascii=False))
if __name__=='__main__': main()
