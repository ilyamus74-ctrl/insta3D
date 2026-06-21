#!/usr/bin/env python3
import argparse,json,math,struct,time
from pathlib import Path

def read_ascii_ply(p):
    lines=Path(p).read_text(errors='replace').splitlines(); n=0; props=[]; end=0
    for i,l in enumerate(lines):
        if l.startswith('element vertex '): n=int(l.split()[2])
        elif l.startswith('property '): props.append(l.split()[-1])
        elif l=='end_header': end=i+1; break
    verts=[]
    for l in lines[end:end+n]:
        vals=l.split();
        if len(vals)>=3:
            try:
                xyz=list(map(float,vals[:3]));
                if all(math.isfinite(x) for x in xyz): verts.append(vals)
            except ValueError: pass
    return props,verts

def main():
    ap=argparse.ArgumentParser(); ap.add_argument('--parent-output-dir',required=True); ap.add_argument('--mode',choices=['preview','hq'],required=True); ap.add_argument('--output-ply',required=True); ap.add_argument('ply',nargs='*'); args=ap.parse_args(); t=time.time()
    chunks=args.ply or [str(p) for p in sorted(Path(args.parent_output_dir).glob('chunks/chunk_*/fused.ply'))]
    allv=[]; warnings=[]; props=None; skipped=0
    for c in chunks:
        try: pr,vs=read_ascii_ply(c)
        except Exception as e: warnings.append(f'{c}: {e}'); skipped+=1; continue
        if len(vs)==0: skipped+=1; continue
        if len(vs)<100: warnings.append(f'{c}: fewer than 100 vertices')
        props=props or pr; allv.extend(vs)
    if not allv: status='ERROR'; merged=[]
    else:
        xs=[float(v[0]) for v in allv]; ys=[float(v[1]) for v in allv]; zs=[float(v[2]) for v in allv]
        diag=math.dist((min(xs),min(ys),min(zs)),(max(xs),max(ys),max(zs))) or 1.0; vox=diag/(2000 if args.mode=='preview' else 5000)
        seen={}; merged=[]
        for v in allv:
            key=(round(float(v[0])/vox),round(float(v[1])/vox),round(float(v[2])/vox))
            if key not in seen: seen[key]=1; merged.append(v)
        status='DONE_WITH_WARNINGS' if skipped > len(chunks)/2 else 'DONE'
    out=Path(args.output_ply); out.parent.mkdir(parents=True,exist_ok=True)
    header=['ply','format ascii 1.0',f'element vertex {len(merged)}'] + [f'property float {x}' for x in (props or ['x','y','z'])] + ['end_header']
    out.write_text('\n'.join(header+[' '.join(v) for v in merged])+'\n')
    res={'status':status,'mode':args.mode,'chunks_total':len(chunks),'chunks_done':len(chunks)-skipped,'chunks_skipped':skipped,'input_vertices_total':len(allv),'merged_vertices':len(merged),'fused_vertices':len(merged),'output_ply':str(out),'duration_sec':round(time.time()-t,3),'warnings':warnings}
    (out.parent/'result.json').write_text(json.dumps(res,indent=2));
    if status=='ERROR': raise SystemExit(2)
if __name__=='__main__': main()