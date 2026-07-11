#!/usr/bin/env python3
"""Export COLMAP sparse component camera poses and align/merge dense PLYs.

Uses shared image names between COLMAP sparse component models and Umeyama Sim3
(camera-center) alignment to bring dense point clouds into an anchor model frame.
"""
import argparse, json, math, os, struct, subprocess, tempfile
from pathlib import Path
import numpy as np

CAM_MODEL_PARAMS={0:3,1:4,2:4,3:5,4:8,5:8,6:12,7:5,8:4,9:5,10:12}

def qvec_to_rotmat(q):
    w,x,y,z=q
    return np.array([[1-2*y*y-2*z*z,2*x*y-2*w*z,2*z*x+2*w*y],[2*x*y+2*w*z,1-2*x*x-2*z*z,2*y*z-2*w*x],[2*z*x-2*w*y,2*y*z+2*w*x,1-2*x*x-2*y*y]],float)

def read_next(fid, fmt):
    sz=struct.calcsize(fmt); data=fid.read(sz)
    if len(data)!=sz: raise EOFError
    return struct.unpack(fmt,data)

def read_cstr(fid):
    b=bytearray()
    while True:
        c=fid.read(1)
        if not c or c==b'\x00': break
        b.extend(c)
    return b.decode('utf-8','replace')

def parse_images_bin(path):
    out=[]
    with open(path,'rb') as f:
        n=read_next(f,'<Q')[0]
        for _ in range(n):
            vals=read_next(f,'<i7di',)
            iid=vals[0]; q=list(vals[1:5]); t=list(vals[5:8]); cam=vals[8]; name=read_cstr(f)
            pts=read_next(f,'<Q')[0]
            f.seek(24*pts,1)
            R=qvec_to_rotmat(q); C=(-R.T @ np.array(t)).tolist()
            out.append({'image_name':name,'image_id':iid,'camera_id':cam,'qvec':q,'tvec':t,'camera_center':C})
    return out

def parse_images_txt(path):
    out=[]; lines=[l.strip() for l in open(path,encoding='utf-8',errors='replace') if l.strip() and not l.startswith('#')]
    for i in range(0,len(lines),2):
        p=lines[i].split()
        if len(p)<10: continue
        iid=int(p[0]); q=list(map(float,p[1:5])); t=list(map(float,p[5:8])); cam=int(p[8]); name=' '.join(p[9:])
        R=qvec_to_rotmat(q); C=(-R.T @ np.array(t)).tolist()
        out.append({'image_name':name,'image_id':iid,'camera_id':cam,'qvec':q,'tvec':t,'camera_center':C})
    return out

def export_poses(sparse_dir, out_json):
    sparse=Path(sparse_dir); models=[]
    for d in sorted([p for p in sparse.iterdir() if p.is_dir() and p.name.isdigit()], key=lambda p:int(p.name)):
        imgs=[]
        if (d/'images.txt').is_file(): imgs=parse_images_txt(d/'images.txt')
        elif (d/'images.bin').is_file(): imgs=parse_images_bin(d/'images.bin')
        models.append({'model_id':int(d.name),'registered_images':len(imgs),'images':imgs})
    payload={'sparse_dir':str(sparse),'models':models}
    Path(out_json).write_text(json.dumps(payload,indent=2,ensure_ascii=False),encoding='utf-8')
    return payload

def umeyama(src,dst):
    src=np.asarray(src,float); dst=np.asarray(dst,float); n=src.shape[0]
    ms=src.mean(0); md=dst.mean(0); X=src-ms; Y=dst-md
    var=(X*X).sum()/n
    U,S,Vt=np.linalg.svd((Y.T@X)/n)
    D=np.eye(3)
    if np.linalg.det(U@Vt)<0: D[2,2]=-1
    R=U@D@Vt; scale=float(np.trace(np.diag(S)@D)/var) if var>0 else float('nan')
    t=md-scale*(R@ms)
    return scale,R,t

def edge_transform(a,b):
    ai={x['image_name']:x for x in a['images']}; bi={x['image_name']:x for x in b['images']}
    shared=sorted(set(ai)&set(bi))
    if len(shared)<3: return None
    A=np.array([ai[n]['camera_center'] for n in shared],float); B=np.array([bi[n]['camera_center'] for n in shared],float)
    before=float(np.sqrt(np.mean(np.sum((B-A)**2,axis=1))))
    s,R,t=umeyama(B,A); Bt=(s*(R@B.T)).T+t
    after=float(np.sqrt(np.mean(np.sum((Bt-A)**2,axis=1))))
    if not np.isfinite(s) or not np.all(np.isfinite(R)) or not np.all(np.isfinite(t)) or s<=0: return None
    thresh=max(0.5, before*0.10)
    if after>thresh: return None
    return {'from_model_id':b['model_id'],'to_model_id':a['model_id'],'shared_images_count':len(shared),'shared_images':shared,'rms_error_before':before,'rms_error_after':after,'scale':s,'rotation_matrix':R.tolist(),'translation':t.tolist()}

def compose(Tparent, edge):
    s1,R1,t1=Tparent['scale'],np.array(Tparent['rotation_matrix']),np.array(Tparent['translation'])
    s2,R2,t2=edge['scale'],np.array(edge['rotation_matrix']),np.array(edge['translation'])
    return {'scale':s1*s2,'rotation_matrix':(R1@R2).tolist(),'translation':(s1*(R1@t2)+t1).tolist()}

def build_alignment(poses, anchor):
    models={m['model_id']:m for m in poses['models']}; ids=list(models)
    if anchor is None: anchor=max(ids,key=lambda i:models[i].get('registered_images',0))
    if anchor not in models:
        raise RuntimeError(f'Anchor model {anchor} is not present in selected sparse job poses')
    adj={i:[] for i in ids}; edges=[]
    for i,a in models.items():
        for j,b in models.items():
            if i==j: continue
            e=edge_transform(a,b)
            if e: adj[i].append((j,e)); edges.append(e)
    ident={'scale':1.0,'rotation_matrix':np.eye(3).tolist(),'translation':[0.0,0.0,0.0]}
    transforms={anchor:ident}; path={anchor:[anchor]}; q=[anchor]
    while q:
        cur=q.pop(0)
        for nxt,e in adj[cur]:
            if nxt in transforms: continue
            transforms[nxt]=compose(transforms[cur],e); path[nxt]=path[cur]+[nxt]; q.append(nxt)
    return anchor,transforms,path,edges

PLY_TYPES={'char':('b',1),'uchar':('B',1),'uint8':('B',1),'int8':('b',1),'short':('h',2),'ushort':('H',2),'uint16':('H',2),'int16':('h',2),'int':('i',4),'uint':('I',4),'float':('f',4),'float32':('f',4),'double':('d',8),'float64':('d',8)}
def parse_ply_header(f):
    header=[]; props=[]; fmt=None; vertices=None; in_vertex=False; hb=0
    while True:
        line=f.readline();
        if not line: raise RuntimeError('Invalid PLY header')
        hb+=len(line); s=line.decode('ascii','replace').strip(); header.append(line)
        if s.startswith('format '): fmt=s.split()[1]
        elif s.startswith('element '):
            p=s.split(); in_vertex=p[1]=='vertex';
            if in_vertex: vertices=int(p[2])
            elif p[1]=='face' and int(p[2])>0: raise RuntimeError('Cannot aligned-merge PLY with faces')
            elif p[1] != 'face': raise RuntimeError('Unsupported PLY element '+p[1])
        elif in_vertex and s.startswith('property '):
            p=s.split();
            if p[1]=='list': raise RuntimeError('Unsupported list property in vertex')
            props.append((p[1],p[2]))
        if s=='end_header': break
    if fmt not in ('ascii','binary_little_endian') or vertices is None: raise RuntimeError('Unsupported PLY format/layout')
    names=[p[1] for p in props]
    if names[:3]!=['x','y','z']: raise RuntimeError('Unsupported PLY vertex layout: x y z must be first')
    return fmt,vertices,props,header,hb

def transform_ply_sources(sources,out):
    infos=[]; total=0; sig=None; fmt=None
    for s in sources:
        f=open(s['path'],'rb'); pf,pv,pp,ph,hb=parse_ply_header(f); cs=json.dumps(pp)
        if sig is None: sig=cs; fmt=pf; header=ph; props=pp
        elif sig!=cs or fmt!=pf: raise RuntimeError('Cannot merge PLY files with different vertex layouts')
        infos.append((s,f,pv,hb)); total+=pv
    with open(out,'wb') as o:
        for line in header:
            txt=line.decode('ascii','replace').strip()
            o.write((f'element vertex {total}\n'.encode() if txt.startswith('element vertex ') else line))
        for s,f,n,hb in infos:
            f.seek(hb); T=s['transform_to_anchor']; scale=T['scale']; R=np.array(T['rotation_matrix']); t=np.array(T['translation'])
            if fmt=='ascii':
                for _ in range(n):
                    parts=f.readline().decode('ascii','replace').strip().split(); p=np.array(list(map(float,parts[:3]))); p=scale*(R@p)+t; parts[:3]=[format(float(x),'.9g') for x in p]; o.write((' '.join(parts)+'\n').encode())
            else:
                fm='<'+''.join(PLY_TYPES[p[0]][0] for p in props); sz=struct.calcsize(fm)
                for _ in range(n):
                    vals=list(struct.unpack(fm,f.read(sz))); p=scale*(R@np.array(vals[:3],float))+t; vals[:3]=[float(x) for x in p]; o.write(struct.pack(fm,*vals))
            f.close()
    return total

def main():
    ap=argparse.ArgumentParser(); ap.add_argument('--sparse-dir',required=True); ap.add_argument('--output-json',required=True); ap.add_argument('--merge-spec-json'); ap.add_argument('--output-ply'); ap.add_argument('--anchor-model-id',type=int)
    a=ap.parse_args(); poses=export_poses(a.sparse_dir,a.output_json)
    if a.merge_spec_json:
        spec=json.loads(Path(a.merge_spec_json).read_text()); anchor,trs,path,edges=build_alignment(poses,a.anchor_model_id)
        inc=[]; exc=[]
        for src in spec['sources']:
            mid=int(src['model_id'])
            if mid in trs:
                src['transform_to_anchor']=trs[mid]; src['shared_path_to_anchor']=path[mid]; src['alignment_status']='anchor' if mid==anchor else ('direct to anchor' if len(path[mid])==2 else 'via model '+str(path[mid][-2])); inc.append(src)
            else:
                src['alignment_status']='no_shared_image_path'; exc.append(src)
        if not inc: raise RuntimeError('No selected dense clouds have a shared-image path to anchor')
        total=transform_ply_sources(inc,a.output_ply)
        result={'anchor_model_id':anchor,'alignment_method':'shared_colmap_image_camera_centers_umeyama','edges':edges,'source_jobs':inc,'excluded_jobs':exc,'total_points':total,'output_ply':a.output_ply}
        Path(spec['result_json']).write_text(json.dumps(result,indent=2,ensure_ascii=False),encoding='utf-8')
if __name__=='__main__': main()