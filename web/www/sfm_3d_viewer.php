<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
auth_require_login();
$orderId = (int)($_GET['order_id'] ?? 0);
$sessionId = (int)($_GET['session_id'] ?? 0);
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>SfM Sparse 3D Viewer</title>
<link href="/assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
<style>
body,html{height:100%} #viewer{height:80vh;background:#111;border-radius:8px} .overlay{position:absolute;right:20px;top:110px;z-index:10;max-width:300px}
</style>
</head>
<body class="p-3">
<div class="container-fluid position-relative">
<div class="d-flex gap-2 mb-2">
<a class="btn btn-outline-secondary btn-sm" href="/order.php?id=<?php echo $orderId; ?>">← Back to order</a>
<a class="btn btn-outline-primary btn-sm" href="/sfm_tour_viewer.php?order_id=<?php echo $orderId; ?>&session_id=<?php echo $sessionId; ?>">Open SfM tour</a>
<a class="btn btn-outline-success btn-sm" href="/sfm_viewer.php?order_id=<?php echo $orderId; ?>&session_id=<?php echo $sessionId; ?>">Open diagnostics</a>
</div>
<div id="viewer"></div>
<div class="overlay card"><div class="card-body small"><div id="summary">Loading...</div><hr><div id="selection">Click keyframe sphere.</div></div></div>
</div>
<script type="module">
import * as THREE from 'https://unpkg.com/three@0.160.0/build/three.module.js';
import {OrbitControls} from 'https://unpkg.com/three@0.160.0/examples/jsm/controls/OrbitControls.js';
import {PLYLoader} from 'https://unpkg.com/three@0.160.0/examples/jsm/loaders/PLYLoader.js';
const orderId=<?php echo json_encode($orderId); ?>,sessionId=<?php echo json_encode($sessionId); ?>;
const r=await fetch(`/api/sfm_3d.php?order_id=${orderId}&session_id=${sessionId}`); const data=await r.json(); if(!data.ok) throw new Error(data.error||'load failed');
document.getElementById('summary').innerHTML=`<b>Summary</b><br>points_count: ${data.summary.points_count}<br>camera_poses_count: ${data.summary.camera_poses_count}<br>keyframe_points_count: ${data.summary.keyframe_points_count}`;
const el=document.getElementById('viewer');
const scene=new THREE.Scene(); scene.background=new THREE.Color(0x111111);
const camera=new THREE.PerspectiveCamera(65,el.clientWidth/el.clientHeight,0.01,10000); camera.position.set(0,5,10);
const renderer=new THREE.WebGLRenderer({antialias:true}); renderer.setSize(el.clientWidth,el.clientHeight); el.appendChild(renderer.domElement);
const controls=new OrbitControls(camera, renderer.domElement); controls.enableDamping=true;
scene.add(new THREE.AmbientLight(0xffffff,1.0));
new PLYLoader().load(data.artifacts.sparse_points_ply_url,(g)=>{g.computeVertexNormals(); const m=new THREE.PointsMaterial({size:0.03,vertexColors:true}); scene.add(new THREE.Points(g,m));});
const traj=await (await fetch(data.artifacts.camera_trajectory_url)).json();
const pts=traj.map(p=>new THREE.Vector3(p.x,p.y,p.z));
if(pts.length>1){scene.add(new THREE.Line(new THREE.BufferGeometry().setFromPoints(pts),new THREE.LineBasicMaterial({color:0x66ccff})));}
const key=await (await fetch(data.artifacts.keyframe_points_url)).json();
const spheres=[];
key.forEach((k,i)=>{const s=new THREE.Mesh(new THREE.SphereGeometry(0.08,12,12),new THREE.MeshBasicMaterial({color:0xff5533}));s.position.set(k.x,k.y,k.z);s.userData={...k,i};scene.add(s);spheres.push(s);});
const ray=new THREE.Raycaster(); const mouse=new THREE.Vector2(); let selected=null;
renderer.domElement.addEventListener('click',(ev)=>{const rct=renderer.domElement.getBoundingClientRect();mouse.x=((ev.clientX-rct.left)/rct.width)*2-1;mouse.y=-((ev.clientY-rct.top)/rct.height)*2+1;ray.setFromCamera(mouse,camera);const hit=ray.intersectObjects(spheres)[0];if(!hit)return; if(selected) selected.material.color.set(0xff5533); selected=hit.object; selected.material.color.set(0x00ff88); const k=selected.userData; document.getElementById('selection').innerHTML=`<b>Keyframe ${k.keyframe_index}</b><br>${k.keyframe_name}<br>${k.preview_url?`<a href="${k.preview_url}" target="_blank">Preview</a><br>`:''}<a href="/sfm_tour_viewer.php?order_id=${orderId}&session_id=${sessionId}">Open in SfM tour</a>`;});
addEventListener('resize',()=>{camera.aspect=el.clientWidth/el.clientHeight;camera.updateProjectionMatrix();renderer.setSize(el.clientWidth,el.clientHeight);});
(function anim(){requestAnimationFrame(anim);controls.update();renderer.render(scene,camera);})();
</script>
</body></html>
