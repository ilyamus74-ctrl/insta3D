#!/usr/bin/env python3
import argparse,json,os,re,subprocess,sys,time
from pathlib import Path

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
    if not txt.exists():
        tmp=Path(os.environ.get('TMPDIR','/tmp'))/f'colmap_model_txt_{os.getpid()}'
        tmp.mkdir(parents=True,exist_ok=True)
        colmap=os.environ.get('COLMAP_BIN','colmap')
        subprocess.run([colmap,'model_converter','--input_path',str(model),'--output_path',str(tmp),'--output_type','TXT'],check=True)
        txt=tmp/'images.txt'
    imgs=[]; poses={}
    for line in txt.read_text(errors='replace').splitlines():
        if not line or line.startswith('#'): continue
        parts=line.split()
        if len(parts)>=10:
            name=parts[9]; imgs.append(name); poses[name]=' '.join(parts[1:8])
    imgs=sorted(dict.fromkeys(imgs), key=frame_key)
    return imgs, poses

def ram_limit(mode, avail):
    if avail < 6144: raise SystemExit('insufficient available RAM: MemAvailable below 6 GB')
    if mode=='preview':
        return (100,'MemAvailable >= 16 GB') if avail>=16384 else ((70,'MemAvailable 10-16 GB') if avail>=10240 else (45,'MemAvailable 6-10 GB'))
    return (150,'MemAvailable >= 32 GB') if avail>=32768 else ((100,'MemAvailable 16-32 GB') if avail>=16384 else ((60,'MemAvailable 10-16 GB') if avail>=10240 else (35,'MemAvailable 6-10 GB')))

def main():
    ap=argparse.ArgumentParser()
    ap.add_argument('--sparse-model-dir',required=True); ap.add_argument('--model-id',required=True,type=int); ap.add_argument('--mode',choices=['preview','hq'],required=True)
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
    plan={'status':'DONE','mode':args.mode,'sparse_job_id':args.sparse_job_id,'model_id':args.model_id,'registered_images_total':len(images),'target_images_per_chunk':target,'overlap_images':overlap,'total_ram_mb':total,'available_ram_mb':avail,'reserved_ram_mb':args.ram_reserve_mb,'usable_ram_mb':avail-args.ram_reserve_mb,'selected_max_images_per_chunk':selected,'calculation_reason':reason,'poses_by_image':poses,'chunks':chunks,'duration_sec':round(time.time()-started,3)}
    out.write_text(json.dumps(plan,indent=2,ensure_ascii=False))
if __name__=='__main__': main()
