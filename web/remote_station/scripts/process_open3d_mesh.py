#!/usr/bin/env python3
import argparse, datetime, json, os, sys, time, traceback
from pathlib import Path


def now(): return datetime.datetime.now(datetime.timezone.utc).isoformat()

def write_json(path, payload):
    Path(path).parent.mkdir(parents=True, exist_ok=True)
    tmp = str(path) + '.tmp'
    with open(tmp, 'w', encoding='utf-8') as f: json.dump(payload, f, ensure_ascii=False, indent=2)
    os.replace(tmp, path)

def status_file_for(output_ply):
    p = Path(output_ply)
    # .../output/job_ID/mesh/mesh_open3d.ply -> .../status/job_ID.json
    try:
        job = next(part for part in p.parts if part.startswith('job_'))
        idx = p.parts.index(job)
        parts = p.parts
        base_parts = parts[:idx]
        if idx > 0 and parts[idx - 1] == 'output':
            base_parts = parts[:idx - 1]
        base = Path(*base_parts)
        return base / 'status' / f'{job}.json'
    except Exception:
        return None

def update_status(path, st, progress, message):
    if not path: return
    write_json(path, {'status': st, 'progress_percent': progress, 'message': message, 'updated_at': now()})

def main():
    ap=argparse.ArgumentParser()
    ap.add_argument('--input-ply', required=True); ap.add_argument('--output-ply', required=True); ap.add_argument('--result-json', required=True)
    ap.add_argument('--mode', choices=['preview','hq'], required=True); ap.add_argument('--depth', type=int, default=None); ap.add_argument('--target-faces', type=int, default=None)
    ap.add_argument('--density-quantile', type=float, default=None)
    a=ap.parse_args(); start=time.time(); sf=status_file_for(a.output_ply)
    result={'status':'ERROR','engine':'open3d','message':'unknown error','input_ply':a.input_ply,'output_ply':a.output_ply,'duration_sec':0}
    try:
        import numpy as np
        import open3d as o3d
        mode_defaults={'preview':(7,100000,0.03),'hq':(9,500000,0.01)}
        dflt_depth,dflt_target,dflt_q=mode_defaults[a.mode]
        depth=a.depth or dflt_depth; target=a.target_faces or dflt_target; q=a.density_quantile if a.density_quantile is not None else dflt_q
        update_status(sf,'RUNNING',10,'Validating input point cloud')
        pcd=o3d.io.read_point_cloud(a.input_ply)
        input_points=len(pcd.points)
        if input_points < 100: raise RuntimeError(f'Input point cloud has too few points: {input_points}')
        nb=min(30,max(10,input_points//300))
        pcd,_=pcd.remove_statistical_outlier(nb_neighbors=nb,std_ratio=2.0)
        filtered=len(pcd.points)
        if filtered < 100: raise RuntimeError(f'Filtered point cloud has too few points: {filtered}')
        dists=np.asarray(pcd.compute_nearest_neighbor_distance(), dtype=float)
        med=float(np.median(dists[dists>0])) if np.any(dists>0) else 0.0
        if med <= 0: raise RuntimeError('Median nearest-neighbor distance is zero')
        radius=med*5.0
        update_status(sf,'RUNNING',30,'Estimating normals')
        pcd.estimate_normals(o3d.geometry.KDTreeSearchParamHybrid(radius=radius,max_nn=40))
        pcd.orient_normals_consistent_tangent_plane(40)
        update_status(sf,'RUNNING',55,'Generating Open3D Poisson mesh')
        mesh,dens=o3d.geometry.TriangleMesh.create_from_point_cloud_poisson(pcd, depth=depth)
        vb=len(mesh.vertices); fb=len(mesh.triangles)
        if len(dens)>0:
            dens_np=np.asarray(dens); keep=dens_np >= np.quantile(dens_np, q); mesh.remove_vertices_by_mask(~keep)
        update_status(sf,'RUNNING',80,'Cleaning and simplifying mesh')
        mesh.remove_degenerate_triangles(); mesh.remove_duplicated_triangles(); mesh.remove_duplicated_vertices(); mesh.remove_unreferenced_vertices(); mesh.remove_non_manifold_edges()
        simpl=False
        if len(mesh.triangles) > target:
            mesh=mesh.simplify_quadric_decimation(target_number_of_triangles=target); simpl=True
            mesh.remove_degenerate_triangles(); mesh.remove_duplicated_triangles(); mesh.remove_duplicated_vertices(); mesh.remove_unreferenced_vertices(); mesh.remove_non_manifold_edges()
        mesh.compute_vertex_normals()
        Path(a.output_ply).parent.mkdir(parents=True, exist_ok=True)
        if not o3d.io.write_triangle_mesh(a.output_ply, mesh, write_ascii=False, compressed=False): raise RuntimeError('Open3D failed to write output PLY')
        v=len(mesh.vertices); f=len(mesh.triangles); size=os.path.getsize(a.output_ply)
        if v<=0 or f<=0 or size<=256: raise RuntimeError(f'Invalid output mesh: vertices={v} faces={f} size={size}')
        result={'status':'DONE','engine':'open3d','mode':a.mode,'input_ply':a.input_ply,'output_ply':a.output_ply,'input_points':input_points,'filtered_points':filtered,'median_neighbor_distance':med,'normal_radius':radius,'poisson_depth':depth,'density_quantile':q,'vertices_before_cleanup':vb,'faces_before_cleanup':fb,'vertices':v,'faces':f,'simplification_applied':simpl,'duration_sec':round(time.time()-start,3),'finished_at':now()}
        write_json(a.result_json,result); update_status(sf,'DONE',100,'Mesh generated with Open3D'); return 0
    except Exception as e:
        result.update({'message':str(e),'traceback':traceback.format_exc(),'duration_sec':round(time.time()-start,3),'finished_at':now()})
        write_json(a.result_json,result); update_status(sf,'ERROR',65,'Open3D mesh generation failed: '+str(e)); return 1

if __name__ == '__main__': sys.exit(main())