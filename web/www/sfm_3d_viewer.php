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
body,html{height:100%}
#viewer{height:80vh;background:#111;border-radius:8px}
.overlay{position:absolute;right:20px;top:110px;z-index:10;max-width:320px}
.controls-panel{position:absolute;left:20px;top:110px;z-index:10;max-width:320px}
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

<div class="controls-panel card">
  <div class="card-body small">
    <div class="mb-2"><b>Display</b></div>
    <div class="form-check"><input class="form-check-input" type="checkbox" id="togglePoints" checked><label class="form-check-label" for="togglePoints">Show sparse points</label></div>
    <div class="form-check"><input class="form-check-input" type="checkbox" id="togglePath" checked><label class="form-check-label" for="togglePath">Show camera path</label></div>
    <div class="form-check"><input class="form-check-input" type="checkbox" id="toggleKeyframes" checked><label class="form-check-label" for="toggleKeyframes">Show keyframes</label></div>
    <div class="form-check"><input class="form-check-input" type="checkbox" id="toggleAxes" checked><label class="form-check-label" for="toggleAxes">Show axes</label></div>
    <div class="form-check"><input class="form-check-input" type="checkbox" id="toggleGrid" checked><label class="form-check-label" for="toggleGrid">Show floor grid</label></div>
    <div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="toggleOutlierFilter"><label class="form-check-label" for="toggleOutlierFilter">Use outlier filter</label></div>
    <label for="pointSize" class="form-label mb-1">Point size: <span id="pointSizeValue">0.02</span></label>
    <input type="range" class="form-range" id="pointSize" min="0.005" max="0.1" step="0.001" value="0.02">
    <div class="d-flex gap-2 mt-2">
      <button class="btn btn-outline-warning btn-sm" id="hideOutliersBtn">Hide far outliers</button>
      <button class="btn btn-outline-light btn-sm" id="resetViewBtn">Reset view</button>
    </div>
  </div>
</div>
<div class="overlay card"><div class="card-body small"><div id="summary">Loading...</div><hr><div id="selection">Click keyframe sphere.</div></div></div>
</div>
<script type="importmap">
{
  "imports": {
    "three": "https://unpkg.com/three@0.160.0/build/three.module.js",
    "three/addons/": "https://unpkg.com/three@0.160.0/examples/jsm/"
  }
}
</script>
<script type="module">
import * as THREE from 'three';
import {OrbitControls} from 'three/addons/controls/OrbitControls.js';
import {PLYLoader} from 'three/addons/loaders/PLYLoader.js';

const orderId=<?php echo json_encode($orderId); ?>,sessionId=<?php echo json_encode($sessionId); ?>;
const r=await fetch(`/api/sfm_3d.php?order_id=${orderId}&session_id=${sessionId}`);
const data=await r.json();
if(!data.ok) throw new Error(data.error||'load failed');

const el=document.getElementById('viewer');
const summaryEl=document.getElementById('summary');
const selectionEl=document.getElementById('selection');

const scene=new THREE.Scene();
scene.background=new THREE.Color(0x111111);
const camera=new THREE.PerspectiveCamera(65,el.clientWidth/el.clientHeight,0.01,10000);
camera.position.set(0,5,10);

const renderer=new THREE.WebGLRenderer({antialias:true});
renderer.setSize(el.clientWidth,el.clientHeight);
el.appendChild(renderer.domElement);

const controls=new OrbitControls(camera, renderer.domElement);
controls.enableDamping=true;
scene.add(new THREE.AmbientLight(0xffffff,1.0));

const axes = new THREE.AxesHelper(2.0);
scene.add(axes);
const grid = new THREE.GridHelper(20, 40);
grid.rotation.x = 0;
scene.add(grid);

let modelOffset=new THREE.Vector3(0,0,0);
let pointsMesh=null;
let pointsMaterial=new THREE.PointsMaterial({size:0.02,vertexColors:true});
let originalPointGeometry=null;
let filteredPointGeometry=null;
let trajectoryLine=null;
const keyframeGroup = new THREE.Group();
scene.add(keyframeGroup);
const spheres=[];

let resetCameraPos=new THREE.Vector3(0,5,10);
let resetTarget=new THREE.Vector3(0,0,0);
let selected=null;

const formatNum=(v)=>typeof v==='number'?v.toLocaleString():v;
function updateSummary(){
  const selectedText=selected ? selected.userData.keyframe_index : 'none';
  summaryEl.innerHTML=`<b>Summary</b><br>points_count: ${formatNum(data.summary.points_count)}<br>camera_poses_count: ${formatNum(data.summary.camera_poses_count)}<br>keyframe_points_count: ${formatNum(data.summary.keyframe_points_count)}<br>point_size: ${pointsMaterial.size.toFixed(3)}<br>selected keyframe: ${selectedText}<br><span class="text-warning">Raw cloud may include outliers.</span>`;
}

function percentile(sorted, p){
  if(sorted.length===0) return 0;
  const i=(sorted.length-1)*p;
  const lo=Math.floor(i), hi=Math.ceil(i);
  if(lo===hi) return sorted[lo];
  return sorted[lo]*(hi-i)+sorted[hi]*(i-lo);
}

function createFilteredGeometry(geometry){
  const pos=geometry.getAttribute('position');
  const col=geometry.getAttribute('color');
  if(!pos||!col) return geometry;
  const xs=[], ys=[], zs=[];
  for(let i=0;i<pos.count;i++){ xs.push(pos.getX(i)); ys.push(pos.getY(i)); zs.push(pos.getZ(i)); }
  xs.sort((a,b)=>a-b); ys.sort((a,b)=>a-b); zs.sort((a,b)=>a-b);
  const xMin=percentile(xs,0.01), xMax=percentile(xs,0.99);
  const yMin=percentile(ys,0.01), yMax=percentile(ys,0.99);
  const zMin=percentile(zs,0.01), zMax=percentile(zs,0.99);
  const newPos=[], newCol=[];
  for(let i=0;i<pos.count;i++){
    const x=pos.getX(i), y=pos.getY(i), z=pos.getZ(i);
    if(x<xMin||x>xMax||y<yMin||y>yMax||z<zMin||z>zMax) continue;
    newPos.push(x,y,z);
    newCol.push(col.getX(i),col.getY(i),col.getZ(i));
  }
  const g=new THREE.BufferGeometry();
  g.setAttribute('position',new THREE.Float32BufferAttribute(newPos,3));
  g.setAttribute('color',new THREE.Float32BufferAttribute(newCol,3));
  g.computeBoundingSphere();
  return g;
}

function applyOutlierFilter(on){
  if(!pointsMesh||!originalPointGeometry) return;
  if(on){
    if(!filteredPointGeometry) filteredPointGeometry=createFilteredGeometry(originalPointGeometry);
    pointsMesh.geometry=filteredPointGeometry;
  }else{
    pointsMesh.geometry=originalPointGeometry;
  }
}

new PLYLoader().load(data.artifacts.sparse_points_ply_url,(g)=>{
  originalPointGeometry=g;
  g.computeBoundingBox();
  const box=g.boundingBox;
  const center=new THREE.Vector3();
  box.getCenter(center);
  modelOffset=center.clone().negate();

  pointsMesh=new THREE.Points(g,pointsMaterial);
  pointsMesh.position.copy(modelOffset);
  scene.add(pointsMesh);

  if(trajectoryLine) trajectoryLine.position.copy(modelOffset);
  keyframeGroup.position.copy(modelOffset);

  g.computeBoundingSphere();
  const radius=Math.max(g.boundingSphere?.radius||5,0.1);
  resetCameraPos.set(0,radius*0.8,radius*2.0);
  resetTarget.set(0,0,0);
  camera.position.copy(resetCameraPos);
  controls.target.copy(resetTarget);
  controls.update();

  applyOutlierFilter(document.getElementById('toggleOutlierFilter').checked);
  updateSummary();
});

const traj=await (await fetch(data.artifacts.camera_trajectory_url)).json();
const pts=traj.map(p=>new THREE.Vector3(p.x,p.y,p.z));
if(pts.length>1){
  trajectoryLine=new THREE.Line(new THREE.BufferGeometry().setFromPoints(pts),new THREE.LineBasicMaterial({color:0x00aaff}));
  trajectoryLine.position.copy(modelOffset);
  scene.add(trajectoryLine);
}

const key=await (await fetch(data.artifacts.keyframe_points_url)).json();
key.forEach((k,i)=>{
  const s=new THREE.Mesh(new THREE.SphereGeometry(0.08,12,12),new THREE.MeshBasicMaterial({color:0xff5533}));
  s.position.set(k.x,k.y,k.z);
  s.userData={...k,i};
  keyframeGroup.add(s);
  spheres.push(s);
});

const ray=new THREE.Raycaster();
const mouse=new THREE.Vector2();
renderer.domElement.addEventListener('click',(ev)=>{
  const rct=renderer.domElement.getBoundingClientRect();
  mouse.x=((ev.clientX-rct.left)/rct.width)*2-1;
  mouse.y=-((ev.clientY-rct.top)/rct.height)*2+1;
  ray.setFromCamera(mouse,camera);
  const hit=ray.intersectObjects(spheres)[0];
  if(!hit) return;
  if(selected) selected.material.color.set(0xff5533);
  selected=hit.object;
  selected.material.color.set(0x00ff88);
  const k=selected.userData;
  selectionEl.innerHTML=`<b>Keyframe ${k.keyframe_index}</b><br>${k.keyframe_name}<br>${k.preview_url?`<a href="${k.preview_url}" target="_blank">Preview</a><br>`:''}<a href="/sfm_tour_viewer.php?order_id=${orderId}&session_id=${sessionId}">Open in SfM tour</a>`;
  updateSummary();
});

const pointSizeSlider=document.getElementById('pointSize');
const pointSizeValue=document.getElementById('pointSizeValue');
pointSizeSlider.addEventListener('input',()=>{
  pointsMaterial.size=parseFloat(pointSizeSlider.value);
  pointSizeValue.textContent=pointsMaterial.size.toFixed(3);
  updateSummary();
});

document.getElementById('togglePoints').addEventListener('change',(e)=>{if(pointsMesh) pointsMesh.visible=e.target.checked;});
document.getElementById('togglePath').addEventListener('change',(e)=>{if(trajectoryLine) trajectoryLine.visible=e.target.checked;});
document.getElementById('toggleKeyframes').addEventListener('change',(e)=>{keyframeGroup.visible=e.target.checked;});
document.getElementById('toggleAxes').addEventListener('change',(e)=>{axes.visible=e.target.checked;});
document.getElementById('toggleGrid').addEventListener('change',(e)=>{grid.visible=e.target.checked;});
document.getElementById('toggleOutlierFilter').addEventListener('change',(e)=>applyOutlierFilter(e.target.checked));
document.getElementById('hideOutliersBtn').addEventListener('click',()=>{
  const cb=document.getElementById('toggleOutlierFilter');
  cb.checked=true;
  applyOutlierFilter(true);
});
document.getElementById('resetViewBtn').addEventListener('click',()=>{
  camera.position.copy(resetCameraPos);
  controls.target.copy(resetTarget);
  controls.update();
});

updateSummary();
addEventListener('resize',()=>{camera.aspect=el.clientWidth/el.clientHeight;camera.updateProjectionMatrix();renderer.setSize(el.clientWidth,el.clientHeight);});
(function anim(){requestAnimationFrame(anim);controls.update();renderer.render(scene,camera);})();
</script>
</body></html>
