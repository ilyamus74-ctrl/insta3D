#!/usr/bin/env python3
import argparse, datetime, json, os, sys, time, traceback
from pathlib import Path

MIN_RETAINED_FACE_RATIO = 0.05
MIN_REASONABLE_FINAL_FACES = 5000
MIN_ABSOLUTE_FINAL_FACES = 100


def now(): return datetime.datetime.now(datetime.timezone.utc).isoformat()
def write_json(path, payload):
    Path(path).parent.mkdir(parents=True, exist_ok=True); tmp=str(path)+'.tmp'
    with open(tmp,'w',encoding='utf-8') as f: json.dump(payload,f,ensure_ascii=False,indent=2)
    os.replace(tmp,path)
def status_file(output_ply):
    p=Path(output_ply)
    try:
        job=next(x for x in p.parts if x.startswith('job_')); idx=p.parts.index(job); base=Path(*p.parts[:idx-1]) if idx>0 and p.parts[idx-1]=='output' else Path(*p.parts[:idx])
        return base/'status'/f'{job}.json'
    except Exception: return None
def status(path, pr, msg):
    if path: write_json(path, {'status':'RUNNING' if pr<100 else 'DONE','progress_percent':pr,'message':msg,'updated_at':now()})
def cleanup(mesh):
    mesh.remove_degenerate_triangles(); mesh.remove_duplicated_triangles(); mesh.remove_duplicated_vertices(); mesh.remove_non_manifold_edges(); mesh.remove_unreferenced_vertices(); return mesh
def clone_mesh(mesh):
    import open3d as o3d
    return o3d.geometry.TriangleMesh(mesh)
def mesh_vertex_count(mesh): return len(mesh.vertices) if mesh is not None else 0
def mesh_face_count(mesh): return len(mesh.triangles) if mesh is not None else 0
def minimum_acceptable_faces(previous_faces: int) -> int:
    return max(MIN_ABSOLUTE_FINAL_FACES, min(MIN_REASONABLE_FINAL_FACES, int(previous_faces * MIN_RETAINED_FACE_RATIO)))
def is_non_empty_mesh(mesh): return mesh_vertex_count(mesh) > 0 and mesh_face_count(mesh) > 0
def is_usable_mesh(candidate, previous):
    candidate_faces = mesh_face_count(candidate); previous_faces = mesh_face_count(previous)
    if candidate_faces <= 0 or mesh_vertex_count(candidate) <= 0: return False
    if previous_faces <= 0: return True
    return candidate_faces >= minimum_acceptable_faces(previous_faces)
def save_stage(mdir, name, mesh, stats):
    import open3d as o3d
    if mesh is not None: o3d.io.write_triangle_mesh(str(mdir/f'mesh_{name}.ply'), mesh, write_ascii=False, compressed=False)
    stats[f'{name}_vertices'] = mesh_vertex_count(mesh); stats[f'{name}_faces'] = mesh_face_count(mesh)

def main():
    ap=argparse.ArgumentParser()
    ap.add_argument('input_ply'); ap.add_argument('output_ply'); ap.add_argument('mesh_depth',type=int); ap.add_argument('target_faces',type=int)
    ap.add_argument('--result-json'); ap.add_argument('--mode',default='preview')
    ap.add_argument('--statistical-nb-neighbors',type=int,default=20); ap.add_argument('--statistical-std-ratio',type=float,default=2.0)
    ap.add_argument('--remove-statistical-outliers',action='store_true',default=True); ap.add_argument('--no-statistical-outliers',dest='remove_statistical_outliers',action='store_false')
    ap.add_argument('--remove-radius-outliers',action='store_true',default=True); ap.add_argument('--no-radius-outliers',dest='remove_radius_outliers',action='store_false')
    ap.add_argument('--radius-nb-points',type=int,default=6); ap.add_argument('--radius-multiplier',type=float,default=3.0); ap.add_argument('--density-quantile',type=float,default=0.12)
    ap.add_argument('--crop-low-percentile',type=float,default=0.01); ap.add_argument('--crop-high-percentile',type=float,default=0.99)
    ap.add_argument('--maximum-triangle-edge-multiplier',type=float,default=20.0)
    ap.add_argument('--component-min-triangles',type=int,default=100); ap.add_argument('--component-min-largest-ratio',type=float,default=0.001); ap.add_argument('--minimum-component-ratio',type=float,dest='component_min_largest_ratio')
    a=ap.parse_args(); start=time.time(); out=Path(a.output_ply); mdir=out.parent; sf=status_file(a.output_ply)
    stats={'status':'ERROR','engine':'open3d','mode':a.mode,'input_ply':a.input_ply,'output_ply':a.output_ply,'mesh_depth':a.mesh_depth,'target_faces':a.target_faces,'requested_mesh_depth':a.mesh_depth,'resolved_mesh_depth':a.mesh_depth,'requested_target_faces':a.target_faces,'resolved_target_faces':a.target_faces,'density_quantile':a.density_quantile,'long_edge_filter_fallback_used':False,'component_filter_fallback_used':False,'crop_fallback_used':False}
    try:
        import numpy as np, open3d as o3d
        status(sf,5,'Loading dense cloud'); pcd=o3d.io.read_point_cloud(a.input_ply); stats['input_points']=len(pcd.points)
        if stats['input_points']<100: raise RuntimeError(f'Input point cloud has too few points: {stats["input_points"]}')
        status(sf,15,'Statistical outlier removal'); before=len(pcd.points)
        if a.remove_statistical_outliers: pcd,_=pcd.remove_statistical_outlier(nb_neighbors=a.statistical_nb_neighbors,std_ratio=a.statistical_std_ratio)
        stats['points_after_statistical_filter']=len(pcd.points); stats['removed_statistical_outliers']=before-stats['points_after_statistical_filter']
        pts=np.asarray(pcd.points); dists=np.asarray(pcd.compute_nearest_neighbor_distance(),dtype=float); med=float(np.median(dists[dists>0])) if np.any(dists>0) else 0.0
        extent=np.maximum(np.ptp(pts,axis=0),1e-9); adaptive_radius=max(med*a.radius_multiplier, float(np.linalg.norm(extent))*0.005)
        status(sf,25,'Radius outlier removal'); before=len(pcd.points)
        if a.remove_radius_outliers: pcd,_=pcd.remove_radius_outlier(nb_points=a.radius_nb_points,radius=adaptive_radius)
        stats['adaptive_radius']=adaptive_radius; stats['points_after_radius_filter']=len(pcd.points); stats['removed_low_density_points']=before-stats['points_after_radius_filter']
        status(sf,35,'Cropping point cloud'); pts=np.asarray(pcd.points); lo=np.quantile(pts,a.crop_low_percentile,axis=0); hi=np.quantile(pts,a.crop_high_percentile,axis=0); margin=(hi-lo)*0.08; lo-=margin; hi+=margin
        bbox=o3d.geometry.AxisAlignedBoundingBox(lo,hi); pcd=pcd.crop(bbox); stats['crop_bounds']={'min':lo.tolist(),'max':hi.tolist()}; stats['points_after_crop']=len(pcd.points)
        if len(pcd.points)<100: raise RuntimeError(f'Filtered point cloud has too few points: {len(pcd.points)}')
        normal_radius=max(adaptive_radius*2.0, med*8.0); status(sf,45,'Estimating normals'); pcd.estimate_normals(o3d.geometry.KDTreeSearchParamHybrid(radius=normal_radius,max_nn=30))
        status(sf,55,'Orienting normals')
        try: pcd.orient_normals_consistent_tangent_plane(k=30)
        except Exception as e: stats['normal_orientation_warning']=str(e); pcd.orient_normals_towards_camera_location(pcd.get_center())
        status(sf,65,'Poisson reconstruction'); mesh_raw_poisson,dens=o3d.geometry.TriangleMesh.create_from_point_cloud_poisson(pcd,depth=a.mesh_depth,scale=1.05,linear_fit=False)
        cleanup(mesh_raw_poisson); stats['poisson_vertices_before_filter']=mesh_vertex_count(mesh_raw_poisson); stats['poisson_faces_before_filter']=mesh_face_count(mesh_raw_poisson); stats['poisson_vertices']=stats['poisson_vertices_before_filter']; stats['poisson_faces']=stats['poisson_faces_before_filter']; stats['raw_poisson_faces']=mesh_face_count(mesh_raw_poisson); save_stage(mdir,'raw_poisson',mesh_raw_poisson,stats)
        if not is_non_empty_mesh(mesh_raw_poisson): raise RuntimeError('Raw Poisson mesh is empty')
        status(sf,75,'Density filtering'); mesh_density_filtered=clone_mesh(mesh_raw_poisson); dens=np.asarray(dens); thr=float(np.quantile(dens,a.density_quantile)); mask=dens<thr; mesh_density_filtered.remove_vertices_by_mask(mask); cleanup(mesh_density_filtered)
        if not is_usable_mesh(mesh_density_filtered, mesh_raw_poisson): mesh_density_filtered=clone_mesh(mesh_raw_poisson); stats['density_filter_fallback_used']=True; stats['density_filter_fallback_reason']='Density filtering retained too few faces'
        stats['density_threshold']=thr; stats['vertices_removed_by_density']=int(mask.sum()); stats['vertices_after_density_filter']=mesh_vertex_count(mesh_density_filtered); save_stage(mdir,'density_filtered',mesh_density_filtered,stats)
        status(sf,80,'Removing long triangles'); mesh_edge_filtered=clone_mesh(mesh_density_filtered)
        verts=np.asarray(mesh_edge_filtered.vertices); tris=np.asarray(mesh_edge_filtered.triangles); edge_threshold=med*a.maximum_triangle_edge_multiplier if med>0 else float('inf'); removed_long=0
        if len(tris) and np.isfinite(edge_threshold):
            e0=np.linalg.norm(verts[tris[:,0]]-verts[tris[:,1]],axis=1); e1=np.linalg.norm(verts[tris[:,1]]-verts[tris[:,2]],axis=1); e2=np.linalg.norm(verts[tris[:,2]]-verts[tris[:,0]],axis=1)
            long_mask=(e0>edge_threshold)|(e1>edge_threshold)|(e2>edge_threshold); mesh_edge_filtered.remove_triangles_by_mask(long_mask); cleanup(mesh_edge_filtered); removed_long=int(long_mask.sum())
        stats['long_edge_threshold']=edge_threshold; stats['triangles_removed_by_long_edge']=removed_long
        if not is_usable_mesh(mesh_edge_filtered, mesh_density_filtered): mesh_edge_filtered=clone_mesh(mesh_density_filtered); stats['long_edge_filter_fallback_used']=True; stats['long_edge_filter_fallback_reason']='Filtered mesh retained too few faces'
        save_stage(mdir,'edge_filtered',mesh_edge_filtered,stats)
        status(sf,88,'Removing components'); mesh_component_candidate=clone_mesh(mesh_edge_filtered); clusters,ntris,area=mesh_component_candidate.cluster_connected_triangles(); clusters=np.asarray(clusters); ntris=np.asarray(ntris); largest=int(ntris.max()) if len(ntris) else 0; min_keep=max(a.component_min_triangles,int(largest*a.component_min_largest_ratio)); remove=np.array([ntris[c] < min_keep for c in clusters]) if len(clusters) else [] ; mesh_component_candidate.remove_triangles_by_mask(remove); cleanup(mesh_component_candidate)
        stats['component_min_triangles_kept']=min_keep; stats['component_filtered_faces_before_fallback']=mesh_face_count(mesh_component_candidate); stats['faces_after_component_filter']=mesh_face_count(mesh_component_candidate)
        if not is_usable_mesh(mesh_component_candidate, mesh_edge_filtered): mesh_component_filtered=clone_mesh(mesh_edge_filtered); stats['component_filter_fallback_used']=True; stats['component_filter_fallback_reason']='Component filtering retained too few faces'
        else: mesh_component_filtered=mesh_component_candidate
        save_stage(mdir,'component_filtered',mesh_component_filtered,stats)
        status(sf,90,'Cropping mesh'); mesh_crop_candidate=mesh_component_filtered.crop(bbox); cleanup(mesh_crop_candidate); stats['cropped_faces_before_fallback']=mesh_face_count(mesh_crop_candidate)
        if not is_usable_mesh(mesh_crop_candidate, mesh_component_filtered): mesh_cropped=clone_mesh(mesh_component_filtered); stats['crop_fallback_used']=True; stats['crop_fallback_reason']='Crop retained too few faces'
        else: mesh_cropped=mesh_crop_candidate
        save_stage(mdir,'cropped',mesh_cropped,stats)
        status(sf,94,'Simplifying mesh')
        stages=[('cropped',mesh_cropped),('component_filtered',mesh_component_filtered),('edge_filtered',mesh_edge_filtered),('density_filtered',mesh_density_filtered),('raw_poisson',mesh_raw_poisson)]
        final_stage=None; final_mesh=None
        for stage_name,candidate in stages:
            if is_non_empty_mesh(candidate): final_stage=stage_name; final_mesh=clone_mesh(candidate); break
        if final_mesh is None: raise RuntimeError('No non-empty mesh stage available')
        raw_faces=mesh_face_count(mesh_raw_poisson)
        if raw_faces > 50000 and mesh_face_count(final_mesh) / raw_faces < MIN_RETAINED_FACE_RATIO:
            current_index = next((i for i, (name, _) in enumerate(stages) if name == final_stage), 0)
            for stage_name,candidate in stages[current_index + 1:]:
                if is_non_empty_mesh(candidate) and mesh_face_count(candidate) > mesh_face_count(final_mesh):
                    final_stage=stage_name; final_mesh=clone_mesh(candidate); stats['final_quality_fallback_used']=True; stats['final_quality_fallback_reason']='Final mesh retained less than 5% of raw Poisson faces'; break
        if mesh_face_count(final_mesh)>a.target_faces: final_mesh=final_mesh.simplify_quadric_decimation(target_number_of_triangles=a.target_faces); cleanup(final_mesh)
        if not is_non_empty_mesh(final_mesh): raise RuntimeError('Final mesh is empty')
        final_mesh.compute_vertex_normals(); final_mesh.compute_triangle_normals(); status(sf,98,'Saving mesh'); o3d.io.write_triangle_mesh(str(out),final_mesh,write_ascii=False,compressed=False)
        final_faces=mesh_face_count(final_mesh); retained_ratio=(final_faces/raw_faces) if raw_faces>0 else 0.0; fallback_used=any(bool(stats.get(k)) for k in ('density_filter_fallback_used','long_edge_filter_fallback_used','component_filter_fallback_used','crop_fallback_used','final_quality_fallback_used'))
        stats.update({'status':'DONE','fallback_used':fallback_used,'selected_final_stage':final_stage,'final_retained_face_ratio':retained_ratio,'final_vertices':mesh_vertex_count(final_mesh),'final_faces':final_faces,'vertices':mesh_vertex_count(final_mesh),'faces':final_faces,'duration_sec':round(time.time()-start,3),'finished_at':now()})
        write_json(mdir/'mesh_stats.json',stats); write_json(a.result_json or (mdir/'mesh_result.json'),stats); status(sf,100,'Done'); return 0
    except Exception as e:
        stats.update({'status':'ERROR','message':str(e),'traceback':traceback.format_exc(),'duration_sec':round(time.time()-start,3),'finished_at':now()}); write_json(a.result_json or (mdir/'mesh_result.json'),stats); write_json(mdir/'mesh_stats.json',stats); return 1
if __name__=='__main__': sys.exit(main())
