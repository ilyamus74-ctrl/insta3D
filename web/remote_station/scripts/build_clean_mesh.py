#!/usr/bin/env python3
import argparse, datetime, json, os, sys, time, traceback
from pathlib import Path

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
def main():
    ap=argparse.ArgumentParser()
    ap.add_argument('input_ply'); ap.add_argument('output_ply'); ap.add_argument('mesh_depth',type=int); ap.add_argument('target_faces',type=int)
    ap.add_argument('--result-json'); ap.add_argument('--mode',default='preview')
    ap.add_argument('--statistical-nb-neighbors',type=int,default=20); ap.add_argument('--statistical-std-ratio',type=float,default=2.0)
    ap.add_argument('--radius-nb-points',type=int,default=6); ap.add_argument('--density-quantile',type=float,default=0.07)
    ap.add_argument('--component-min-triangles',type=int,default=100); ap.add_argument('--component-min-largest-ratio',type=float,default=0.01)
    a=ap.parse_args(); start=time.time(); out=Path(a.output_ply); mdir=out.parent; sf=status_file(a.output_ply)
    stats={'status':'ERROR','engine':'open3d','mode':a.mode,'input_ply':a.input_ply,'output_ply':a.output_ply,'mesh_depth':a.mesh_depth,'target_faces':a.target_faces,'density_quantile':a.density_quantile}
    try:
        import numpy as np, open3d as o3d
        status(sf,5,'Loading dense cloud'); pcd=o3d.io.read_point_cloud(a.input_ply); stats['input_points']=len(pcd.points)
        if stats['input_points']<100: raise RuntimeError(f'Input point cloud has too few points: {stats["input_points"]}')
        status(sf,15,'Statistical outlier removal'); pcd,_=pcd.remove_statistical_outlier(nb_neighbors=a.statistical_nb_neighbors,std_ratio=a.statistical_std_ratio)
        stats['points_after_statistical_filter']=len(pcd.points); stats['removed_statistical_outliers']=stats['input_points']-stats['points_after_statistical_filter']
        pts=np.asarray(pcd.points); dists=np.asarray(pcd.compute_nearest_neighbor_distance(),dtype=float); med=float(np.median(dists[dists>0])) if np.any(dists>0) else 0.0
        extent=np.maximum(np.ptp(pts,axis=0),1e-9); adaptive_radius=max(med*4.0, float(np.linalg.norm(extent))*0.005)
        status(sf,25,'Radius outlier removal'); pcd,_=pcd.remove_radius_outlier(nb_points=a.radius_nb_points,radius=adaptive_radius)
        stats['adaptive_radius']=adaptive_radius; stats['points_after_radius_filter']=len(pcd.points); stats['removed_low_density_points']=stats['points_after_statistical_filter']-stats['points_after_radius_filter']
        status(sf,35,'Cropping point cloud'); pts=np.asarray(pcd.points); lo=np.quantile(pts,0.01,axis=0); hi=np.quantile(pts,0.99,axis=0); margin=(hi-lo)*0.08; lo-=margin; hi+=margin
        bbox=o3d.geometry.AxisAlignedBoundingBox(lo,hi); pcd=pcd.crop(bbox); stats['crop_bounds']={'min':lo.tolist(),'max':hi.tolist()}; stats['points_after_crop']=len(pcd.points)
        if len(pcd.points)<100: raise RuntimeError(f'Filtered point cloud has too few points: {len(pcd.points)}')
        normal_radius=max(adaptive_radius*2.0, med*8.0); status(sf,45,'Estimating normals'); pcd.estimate_normals(o3d.geometry.KDTreeSearchParamHybrid(radius=normal_radius,max_nn=30))
        status(sf,55,'Orienting normals')
        try: pcd.orient_normals_consistent_tangent_plane(k=30)
        except Exception as e: stats['normal_orientation_warning']=str(e); pcd.orient_normals_towards_camera_location(pcd.get_center())
        status(sf,65,'Poisson reconstruction'); mesh,dens=o3d.geometry.TriangleMesh.create_from_point_cloud_poisson(pcd,depth=a.mesh_depth,scale=1.05,linear_fit=False)
        stats['poisson_vertices_before_filter']=len(mesh.vertices); stats['poisson_faces_before_filter']=len(mesh.triangles); stats['poisson_vertices']=stats['poisson_vertices_before_filter']; stats['poisson_faces']=stats['poisson_faces_before_filter']; o3d.io.write_triangle_mesh(str(mdir/'mesh_raw_poisson.ply'),mesh)
        status(sf,75,'Density filtering'); dens=np.asarray(dens); thr=float(np.quantile(dens,a.density_quantile)); mask=dens<thr; mesh.remove_vertices_by_mask(mask)
        stats['density_threshold']=thr; stats['vertices_removed_by_density']=int(mask.sum()); stats['vertices_after_density_filter']=len(mesh.vertices); o3d.io.write_triangle_mesh(str(mdir/'mesh_density_filtered.ply'),mesh)
        status(sf,82,'Cropping mesh'); mesh=mesh.crop(bbox); cleanup(mesh); o3d.io.write_triangle_mesh(str(mdir/'mesh_cropped.ply'),mesh)
        status(sf,88,'Removing components'); clusters,ntris,area=mesh.cluster_connected_triangles(); clusters=np.asarray(clusters); ntris=np.asarray(ntris); largest=int(ntris.max()) if len(ntris) else 0; min_keep=max(a.component_min_triangles,int(largest*a.component_min_largest_ratio)); remove=np.array([ntris[c] < min_keep for c in clusters]) if len(clusters) else [] ; mesh.remove_triangles_by_mask(remove); cleanup(mesh)
        stats['component_min_triangles_kept']=min_keep; stats['faces_after_component_filter']=len(mesh.triangles); o3d.io.write_triangle_mesh(str(mdir/'mesh_cleaned.ply'),mesh)
        status(sf,94,'Simplifying mesh');
        if len(mesh.triangles)>a.target_faces: mesh=mesh.simplify_quadric_decimation(target_number_of_triangles=a.target_faces); cleanup(mesh)
        mesh.compute_vertex_normals(); mesh.compute_triangle_normals(); status(sf,98,'Saving mesh'); o3d.io.write_triangle_mesh(str(out),mesh,write_ascii=False,compressed=False)
        stats.update({'status':'DONE','final_vertices':len(mesh.vertices),'final_faces':len(mesh.triangles),'vertices':len(mesh.vertices),'faces':len(mesh.triangles),'duration_sec':round(time.time()-start,3),'finished_at':now()})
        if stats['final_vertices']<=0 or stats['final_faces']<=0: raise RuntimeError('Final mesh is empty')
        write_json(mdir/'mesh_stats.json',stats); write_json(a.result_json or (mdir/'mesh_result.json'),stats); status(sf,100,'Done'); return 0
    except Exception as e:
        stats.update({'message':str(e),'traceback':traceback.format_exc(),'duration_sec':round(time.time()-start,3),'finished_at':now()}); write_json(a.result_json or (mdir/'mesh_result.json'),stats); write_json(mdir/'mesh_stats.json',stats); return 1
if __name__=='__main__': sys.exit(main())