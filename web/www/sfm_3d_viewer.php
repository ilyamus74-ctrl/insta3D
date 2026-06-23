<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
auth_require_login();
$orderId = (int)($_GET['order_id'] ?? 0);
$sessionId = (int)($_GET['session_id'] ?? 0);
$pipelineRunId = (int)($_GET['pipeline_run_id'] ?? 0);
$artifact = in_array((string)($_GET['artifact'] ?? 'sparse'), ['sparse','dense','mesh'], true) ? (string)($_GET['artifact'] ?? 'sparse') : 'sparse';
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
<div id="viewer"><div id="viewerStatus" class="text-light p-3">Loading...</div></div>

<div class="controls-panel card">
  <div class="card-body small">
    <div class="mb-2"><b>Display</b></div>
    <div class="form-check"><input class="form-check-input" type="checkbox" id="togglePoints" checked><label class="form-check-label" for="togglePoints">Show sparse cloud</label></div>
    <div class="form-check"><input class="form-check-input" type="checkbox" id="toggleDenseCloud"><label class="form-check-label" for="toggleDenseCloud">Show dense cloud</label></div>
    <div class="form-check"><input class="form-check-input" type="checkbox" id="toggleMesh"><label class="form-check-label" for="toggleMesh">Show mesh</label></div>
    <div class="form-check"><input class="form-check-input" type="checkbox" id="togglePath" checked><label class="form-check-label" for="togglePath">Show camera path</label></div>
    <div class="form-check"><input class="form-check-input" type="checkbox" id="toggleKeyframes" checked><label class="form-check-label" for="toggleKeyframes">Show keyframes</label></div>
    <div class="form-check"><input class="form-check-input" type="checkbox" id="toggleAxes" checked><label class="form-check-label" for="toggleAxes">Show axes</label></div>
    <div class="form-check"><input class="form-check-input" type="checkbox" id="toggleGrid" checked><label class="form-check-label" for="toggleGrid">Show floor grid</label></div>
    <div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="toggleOutlierFilter"><label class="form-check-label" for="toggleOutlierFilter">Use outlier filter</label></div>
    <label for="pointSize" class="form-label mb-1">Point size: <span id="pointSizeValue">0.025</span></label>
    <input type="range" class="form-range" id="pointSize" min="0.005" max="0.12" step="0.001" value="0.025">
    <div class="d-grid gap-1 mt-2">
      <button class="btn btn-outline-light btn-sm" id="fitAllBtn">Fit all</button>
      <button class="btn btn-outline-light btn-sm" id="fitRouteBtn">Fit route</button>
      <button class="btn btn-outline-light btn-sm" id="fitCloudBtn">Fit cloud</button>
      <button class="btn btn-outline-light btn-sm" id="topViewBtn">Top view</button>
      <button class="btn btn-outline-light btn-sm" id="sideViewBtn">Side view</button>
      <button class="btn btn-outline-info btn-sm" id="cloudBeautyBtn">Cloud beauty</button>
    </div>
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

const orderId=<?php echo json_encode($orderId); ?>,sessionId=<?php echo json_encode($sessionId); ?>,pipelineRunId=<?php echo json_encode($pipelineRunId); ?>,initialArtifact=<?php echo json_encode($artifact); ?>;
const statusEl=document.getElementById('viewerStatus');
function showError(msg){ statusEl.className='text-danger p-3'; statusEl.textContent=msg; }
const apiUrl=pipelineRunId>0 ? `/api/sfm_3d.php?order_id=${orderId}&session_id=${sessionId}&pipeline_run_id=${pipelineRunId}&artifact=${initialArtifact}` : `/api/sfm_3d.php?order_id=${orderId}&session_id=${sessionId}`;
const r=await fetch(apiUrl);
const data=await r.json().catch(()=>({ok:false,error:'PLY load failed'}));
if(!data.ok){ showError(data.error||'Artifact not found'); throw new Error(data.error||'load failed'); }
statusEl.textContent = initialArtifact==='dense' ? 'Loading dense point cloud...' : (initialArtifact==='mesh' ? 'Loading final mesh...' : 'Loading sparse point cloud...');

const el=document.getElementById('viewer');
const summaryEl=document.getElementById('summary');
const selectionEl=document.getElementById('selection');

const scene=new THREE.Scene();
scene.background=new THREE.Color(0x111111);
const camera=new THREE.PerspectiveCamera(65,el.clientWidth/el.clientHeight,0.01,10000);
camera.position.set(0,5,10);

const renderer=new THREE.WebGLRenderer({antialias:true});
renderer.setSize(el.clientWidth,el.clientHeight);
statusEl.remove();
el.appendChild(renderer.domElement);

const controls=new OrbitControls(camera, renderer.domElement);
controls.enableDamping=true;
scene.add(new THREE.AmbientLight(0xffffff,1.0));

const axes = new THREE.AxesHelper(2.0);
scene.add(axes);

const rootGroup = new THREE.Group();
scene.add(rootGroup);
let grid = null;

let pointsMesh=null;
let pointsMaterial=new THREE.PointsMaterial({size:0.025,vertexColors:true});
let originalPointGeometry=null;
let filteredPointGeometry=null;
let trajectoryLine=null;
let denseObject=null;
let meshObject=null;
const keyframeGroup = new THREE.Group();
rootGroup.add(keyframeGroup);
const spheres=[];

let resetCameraPos=new THREE.Vector3(0,5,10);
let resetTarget=new THREE.Vector3(0,0,0);
let selected=null;
let latestCombinedBox = null;
let latestRadius = 5;
let currentViewMode = 'Fit all';

const formatNum=(v)=>typeof v==='number'?v.toLocaleString():v;
function updateSummary(){
  const selectedText=selected ? selected.userData.keyframe_index : 'none';
  summaryEl.innerHTML=`<b>Summary</b><br>view mode: ${currentViewMode}<br>points_count: ${formatNum(data.summary.points_count)}<br>camera_poses_count: ${formatNum(data.summary.camera_poses_count)}<br>keyframe_points_count: ${formatNum(data.summary.keyframe_points_count)}<br>point_size: ${pointsMaterial.size.toFixed(3)}<br>selected keyframe: ${selectedText}<br><span class="text-warning">Raw cloud may include outliers.</span>`;
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

function getBoxFromObject(obj){
  if(!obj) return null;
  const box=new THREE.Box3().setFromObject(obj);
  return box.isEmpty() ? null : box;
}

function computeCombinedBox(includePoints=true, includeRoute=true, includeKeyframes=true, includeDense=false, includeMesh=false){
  const box = new THREE.Box3();
  let hasAny = false;
  if(includePoints && pointsMesh){
    const pointBox=getBoxFromObject(pointsMesh);
    if(pointBox){ box.union(pointBox); hasAny=true; }
  }
  if(includeDense && denseObject){ const denseBox=getBoxFromObject(denseObject); if(denseBox){ box.union(denseBox); hasAny=true; } }
  if(includeMesh && meshObject){ const meshBox=getBoxFromObject(meshObject); if(meshBox){ box.union(meshBox); hasAny=true; } }
  if(includeRoute && trajectoryLine){
    const routeBox=getBoxFromObject(trajectoryLine);
    if(routeBox){ box.union(routeBox); hasAny=true; }
  }
  if(includeKeyframes && keyframeGroup.children.length){
    const keyBox=getBoxFromObject(keyframeGroup);
    if(keyBox){ box.union(keyBox); hasAny=true; }
  }
  return hasAny ? box : null;
}

function recreateGrid(box, center, radius){
  if(grid) scene.remove(grid);
  const gridSize=Math.max(10, radius * 2);
  const floorY=box.min.y;
  grid=new THREE.GridHelper(gridSize, 40);
  grid.position.set(0, floorY - center.y, 0);
  grid.visible=document.getElementById('toggleGrid').checked;
  scene.add(grid);
}

function fitBox(box){
  if(!box) return;
  const center=new THREE.Vector3();
  box.getCenter(center);
  const size=box.getSize(new THREE.Vector3());
  const radius=Math.max(size.length()*0.5,0.1);
  latestRadius=radius;
  camera.near=Math.max(0.001, radius/1000);
  camera.far=Math.max(1000, radius*20);
  camera.updateProjectionMatrix();
  controls.target.copy(center);
  camera.position.set(center.x + radius*1.3, center.y + radius*0.7, center.z + radius*1.8);
  controls.minDistance=radius*0.02;
  controls.maxDistance=radius*20;
  controls.zoomSpeed=0.6;
  controls.panSpeed=0.6;
  resetCameraPos.copy(camera.position);
  resetTarget.copy(center);
  controls.update();
}

function updateSceneBoundsAndCenter(){
  const box=computeCombinedBox(true, true, true);
  if(!box) return;
  latestCombinedBox=box.clone();
  const center=box.getCenter(new THREE.Vector3());
  const size=box.getSize(new THREE.Vector3());
  const radius=Math.max(size.length()*0.5,0.1);
  rootGroup.position.copy(center.clone().multiplyScalar(-1));
  const centeredBox=box.clone().translate(center.clone().multiplyScalar(-1));
  latestCombinedBox=centeredBox.clone();
  recreateGrid(centeredBox, new THREE.Vector3(0,0,0), radius);
  fitBox(centeredBox);
}

function setViewMode(name){
  currentViewMode=name;
  updateSummary();
  if(data.dense && data.dense.available){ summaryEl.innerHTML += `<br><span class="text-success">Dense model ready</span>`; } else { summaryEl.innerHTML += `<br><span class="text-muted">Dense model not generated</span>`; }
}

function fitAll(){ const box=computeCombinedBox(true,true,true); if(!box) return; const centered=box.clone().translate(rootGroup.position); fitBox(centered); setViewMode('Fit all'); }
function fitRoute(){ const box=computeCombinedBox(false,true,true); if(!box) return; const centered=box.clone().translate(rootGroup.position); fitBox(centered); setViewMode('Fit route'); }
function fitCloud(){ const box=computeCombinedBox(true,false,false,true,false); if(!box) return; const centered=box.clone().translate(rootGroup.position); fitBox(centered); setViewMode('Fit cloud'); }
function fitMesh(){ const box=computeCombinedBox(false,false,false,false,true); if(!box) return; const centered=box.clone().translate(rootGroup.position); fitBox(centered); setViewMode('Fit mesh'); }

function topView(){
  if(!latestCombinedBox) return;
  const center=latestCombinedBox.getCenter(new THREE.Vector3());
  const radius=latestRadius;
  controls.target.copy(center);
  camera.position.set(center.x, center.y + radius*2, center.z + 0.001);
  camera.up.set(0,0,-1);
  camera.lookAt(center);
  controls.update();

  setViewMode('Top view');
}

function sideView(){
  if(!latestCombinedBox) return;
  const center=latestCombinedBox.getCenter(new THREE.Vector3());
  const radius=latestRadius;
  controls.target.copy(center);
  camera.position.set(center.x + radius*2, center.y + radius*0.5, center.z + radius*2);
  camera.up.set(0,1,0);
  camera.lookAt(center);
  controls.update();
  setViewMode('Side view');
}

function addPlyAsObject(url, target) {
  return new Promise((resolve) => {
    new PLYLoader().load(
      url,
      (geometry) => {
        let object = null;

        if (target === 'dense' || target === 'sparse') {
          const hasVertexColors =
            geometry.hasAttribute('color');

          const material =
            new THREE.PointsMaterial({
              size: 0.018,
              vertexColors: hasVertexColors,
              color: hasVertexColors
                ? 0xffffff
                : 0x77ddff,
              sizeAttenuation: true
            });

          object = new THREE.Points(
            geometry,
            material
          );
        } else if (target === 'mesh') {
          geometry.computeVertexNormals();

          const hasVertexColors =
            geometry.hasAttribute('color');

          const material =
            new THREE.MeshStandardMaterial({
              color: hasVertexColors
                ? 0xffffff
                : 0xbfbfbf,
              vertexColors: hasVertexColors,
              metalness: 0.0,
              roughness: 0.9,
              side: THREE.DoubleSide
            });

          object = new THREE.Mesh(
            geometry,
            material
          );
        }

        if (!object) {
          resolve(null);
          return;
        }

        object.visible = false;
        rootGroup.add(object);
        resolve(object);
      },
      undefined,
      (error) => {
        console.error(
          `PLY load failed for ${target}`,
          error
        );

        resolve(null);
      }
    );
  });
}

function cloudBeauty(){
  document.getElementById('togglePoints').checked=true;
  document.getElementById('togglePath').checked=false;
  document.getElementById('toggleKeyframes').checked=false;
  document.getElementById('toggleAxes').checked=false;
  document.getElementById('toggleGrid').checked=false;
  if(pointsMesh) pointsMesh.visible=true;
  if(trajectoryLine) trajectoryLine.visible=false;
  keyframeGroup.visible=false;
  axes.visible=false;
  if(grid) grid.visible=false;
  pointsMaterial.size=0.035;
  pointSizeSlider.value='0.035';
  pointSizeValue.textContent='0.035';
  fitCloud();
  setViewMode('Cloud beauty');
}

new PLYLoader().load(data.artifacts.sparse_points_ply_url,(g)=>{
  originalPointGeometry=g;
  g.computeBoundingBox();
  pointsMesh=new THREE.Points(g,pointsMaterial);
  rootGroup.add(pointsMesh);

  applyOutlierFilter(document.getElementById('toggleOutlierFilter').checked);
  updateSceneBoundsAndCenter();
  updateSummary();
  if(data.dense && data.dense.available){ summaryEl.innerHTML += `<br><span class="text-success">Dense model ready</span>`; } else { summaryEl.innerHTML += `<br><span class="text-muted">Dense model not generated</span>`; }
});

const traj=await (await fetch(data.artifacts.camera_trajectory_url)).json();
const pts=traj.map(p=>new THREE.Vector3(p.x,p.y,p.z));
if(pts.length>1){
  trajectoryLine=new THREE.Line(new THREE.BufferGeometry().setFromPoints(pts),new THREE.LineBasicMaterial({color:0x00aaff}));
  rootGroup.add(trajectoryLine);
}

const key=await (await fetch(data.artifacts.keyframe_points_url)).json();
key.forEach((k,i)=>{
  const s=new THREE.Mesh(new THREE.SphereGeometry(0.08,12,12),new THREE.MeshBasicMaterial({color:0xff5533}));
  s.position.set(k.x,k.y,k.z);
  s.userData={...k,i};
  keyframeGroup.add(s);
  spheres.push(s);
});

if(data.dense && data.dense.available){ denseObject = await addPlyAsObject(data.dense.fused_ply_url, 'dense'); }
if(data.mesh && data.mesh.available){ meshObject = await addPlyAsObject(data.mesh.mesh_ply_url, 'mesh'); } else if(data.dense && data.dense.mesh_ply_url){ meshObject = await addPlyAsObject(data.dense.mesh_ply_url, 'mesh'); }
if(initialArtifact==='dense'){ document.getElementById('togglePoints').checked=false; document.getElementById('toggleDenseCloud').checked=true; document.getElementById('toggleMesh').checked=false; document.getElementById('togglePath').checked=false; if(pointsMesh) pointsMesh.visible=false; if(denseObject) denseObject.visible=true; if(meshObject) meshObject.visible=false; if(trajectoryLine) trajectoryLine.visible=false; fitCloud(); summaryEl.innerHTML=`Loaded ${formatNum(data.selected?.vertices || data.dense?.points || 0)} points`; }
else if(initialArtifact==='mesh'){ document.getElementById('togglePoints').checked=false; document.getElementById('toggleDenseCloud').checked=false; document.getElementById('toggleMesh').checked=true; document.getElementById('togglePath').checked=false; if(pointsMesh) pointsMesh.visible=false; if(denseObject) denseObject.visible=false; if(meshObject) meshObject.visible=true; if(trajectoryLine) trajectoryLine.visible=false; fitMesh(); summaryEl.innerHTML=`Loaded ${formatNum(data.selected?.vertices || data.mesh?.vertices || 0)} vertices / ${formatNum(data.selected?.faces || data.mesh?.faces || 0)} faces`; }
else { updateSceneBoundsAndCenter(); }

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
  if(data.dense && data.dense.available){ summaryEl.innerHTML += `<br><span class="text-success">Dense model ready</span>`; } else { summaryEl.innerHTML += `<br><span class="text-muted">Dense model not generated</span>`; }
});

const pointSizeSlider=document.getElementById('pointSize');
const pointSizeValue=document.getElementById('pointSizeValue');
pointSizeSlider.addEventListener('input',()=>{
  pointsMaterial.size=parseFloat(pointSizeSlider.value);
  pointSizeValue.textContent=pointsMaterial.size.toFixed(3);
  updateSummary();
  if(data.dense && data.dense.available){ summaryEl.innerHTML += `<br><span class="text-success">Dense model ready</span>`; } else { summaryEl.innerHTML += `<br><span class="text-muted">Dense model not generated</span>`; }
});

document.getElementById('togglePoints').addEventListener('change',(e)=>{if(pointsMesh) pointsMesh.visible=e.target.checked;});
document.getElementById('toggleDenseCloud').addEventListener('change',(e)=>{if(denseObject) denseObject.visible=e.target.checked;});
document.getElementById('toggleMesh').addEventListener('change',(e)=>{if(meshObject) meshObject.visible=e.target.checked;});
document.getElementById('togglePath').addEventListener('change',(e)=>{if(trajectoryLine) trajectoryLine.visible=e.target.checked;});
document.getElementById('toggleKeyframes').addEventListener('change',(e)=>{keyframeGroup.visible=e.target.checked;});
document.getElementById('toggleAxes').addEventListener('change',(e)=>{axes.visible=e.target.checked;});
document.getElementById('toggleGrid').addEventListener('change',(e)=>{if(grid) grid.visible=e.target.checked;});
document.getElementById('toggleOutlierFilter').addEventListener('change',(e)=>applyOutlierFilter(e.target.checked));
document.getElementById('hideOutliersBtn').addEventListener('click',()=>{
  const cb=document.getElementById('toggleOutlierFilter');
  cb.checked=true;
  applyOutlierFilter(true);
});
const bindClick=(id,handler)=>{
  const btn=document.getElementById(id);
  if(btn) btn.addEventListener('click',handler);
};

bindClick('resetViewBtn',fitAll);
bindClick('fitAllBtn',fitAll);
bindClick('fitRouteBtn',fitRoute);
bindClick('fitCloudBtn',fitCloud);
bindClick('topViewBtn',topView);
bindClick('sideViewBtn',sideView);
bindClick('cloudBeautyBtn',cloudBeauty);

updateSummary();
addEventListener('resize',()=>{camera.aspect=el.clientWidth/el.clientHeight;camera.updateProjectionMatrix();renderer.setSize(el.clientWidth,el.clientHeight);});
(function anim(){requestAnimationFrame(anim);controls.update();renderer.render(scene,camera);})();
</script>
</body></html>
