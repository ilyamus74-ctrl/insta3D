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
<title>SfM 3D Viewer</title>
<link href="/assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
<style>
body,html{height:100%}
#viewer{height:80vh;background:#252b3f;border-radius:8px}
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
    <label for="displayPreset" class="form-label mb-1">Preset</label>
    <select class="form-select form-select-sm mb-2" id="displayPreset">
      <option value="natural">Natural</option><option value="bright">Bright</option><option value="contrast">High contrast</option><option value="meshlab" selected>MeshLab style</option>
    </select>
    <label for="outlierMode" class="form-label mb-1">Outlier filter</label>
    <select class="form-select form-select-sm mb-2" id="outlierMode"><option value="off" selected>Off</option><option value="light">Light (0.5–99.5%)</option><option value="medium">Medium (1–99%)</option><option value="strong">Strong (2–98%)</option></select>
    <label for="pointSize" class="form-label mb-1">Point size: <span id="pointSizeValue">2.25</span> px</label>
    <input type="range" class="form-range" id="pointSize" min="0.5" max="8" step="0.25" value="2.25">
    <label for="exposure" class="form-label mb-1">Exposure: <span id="exposureValue">1.60</span></label>
    <input type="range" class="form-range" id="exposure" min="0.5" max="3" step="0.05" value="1.6">
    <label for="backgroundColor" class="form-label mb-1">Background</label><input type="color" class="form-control form-control-color mb-2" id="backgroundColor" value="#252b3f">
    <div class="d-grid gap-1 mt-2">
      <button class="btn btn-outline-light btn-sm" id="fitAllBtn">Fit all</button>
      <button class="btn btn-outline-light btn-sm" id="fitRouteBtn">Fit route</button>
      <button class="btn btn-outline-light btn-sm" id="fitCloudBtn">Fit cloud</button>
      <button class="btn btn-outline-light btn-sm" id="topViewBtn">Top view</button>
      <button class="btn btn-outline-light btn-sm" id="sideViewBtn">Side view</button>
      <button class="btn btn-outline-info btn-sm" id="cloudBeautyBtn">Cloud beauty</button>
    </div>
    <div class="mt-2"><b>Orientation</b></div>
    <div class="d-grid gap-1 mt-1">
      <button class="btn btn-outline-warning btn-sm" id="flipVerticalBtn">Flip vertical</button>
      <button class="btn btn-outline-light btn-sm" id="rotXpBtn">Rotate X +90°</button><button class="btn btn-outline-light btn-sm" id="rotXmBtn">Rotate X -90°</button>
      <button class="btn btn-outline-light btn-sm" id="rotYpBtn">Rotate Y +90°</button><button class="btn btn-outline-light btn-sm" id="rotYmBtn">Rotate Y -90°</button>
      <button class="btn btn-outline-light btn-sm" id="rotZpBtn">Rotate Z +90°</button><button class="btn btn-outline-light btn-sm" id="rotZmBtn">Rotate Z -90°</button>
      <button class="btn btn-outline-light btn-sm" id="autoOrientBtn">Auto orient</button>
      <button class="btn btn-outline-light btn-sm" id="resetOrientationBtn">Reset orientation</button>
      <button class="btn btn-outline-success btn-sm" id="saveDefaultBtn">Set current orientation as default</button>
    </div>
    <div class="d-flex gap-2 mt-2"><button class="btn btn-outline-light btn-sm" id="setFloorBtn">Set floor here</button><button class="btn btn-outline-light btn-sm" id="raiseFloorBtn">Raise floor</button><button class="btn btn-outline-light btn-sm" id="lowerFloorBtn">Lower floor</button></div>
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
scene.background=new THREE.Color(0x252b3f);
const camera=new THREE.PerspectiveCamera(65,el.clientWidth/el.clientHeight,0.01,10000);
camera.position.set(0,5,10);

const renderer=new THREE.WebGLRenderer({antialias:true});
renderer.setSize(el.clientWidth,el.clientHeight);
renderer.outputColorSpace = THREE.SRGBColorSpace;
renderer.toneMapping = THREE.ACESFilmicToneMapping;
renderer.toneMappingExposure = 1.6;
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
let pointsMaterial=new THREE.PointsMaterial({size:2.25,vertexColors:true,sizeAttenuation:false,depthWrite:true,transparent:false});
let originalPointGeometry=null;
let filteredPointGeometry=null;
const filteredGeometryCache={sparse:{},dense:{}};
let pointStats={sparse:{original:0,displayed:0,filtered:0},dense:{original:0,displayed:0,filtered:0}};
let floorOffset=0;
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
  summaryEl.innerHTML=`<b>Summary</b><br>view mode: ${currentViewMode}<br>artifact: ${artifactLabel()}<br>${artifactStatsHtml()}<br>camera_poses_count: ${formatNum(data.summary.camera_poses_count)}<br>keyframe_points_count: ${formatNum(data.summary.keyframe_points_count)}<br>point_size: ${getPointSize().toFixed(2)} px<br>orientation: X=${deg(rootGroup.rotation.x)}°, Y=${deg(rootGroup.rotation.y)}°, Z=${deg(rootGroup.rotation.z)}°<br>selected keyframe: ${selectedText}<br><span class="text-warning">Raw cloud may include outliers.</span>`;
}

function artifactLabel(){return initialArtifact==='dense'?'Dense point cloud':(initialArtifact==='mesh'?'Mesh':'Sparse point cloud');}
function deg(r){return Math.round(THREE.MathUtils.radToDeg(r));}
function getPointSize(){return parseFloat(document.getElementById('pointSize').value)||2.25;}
function artifactStatsHtml(){const obj=initialArtifact==='dense'?denseObject:(initialArtifact==='mesh'?meshObject:pointsMesh); if(initialArtifact==='mesh'&&obj){const p=obj.geometry.getAttribute('position')?.count||0; const f=obj.geometry.index?obj.geometry.index.count/3:(obj.geometry.getAttribute('position')?.count||0)/3; return `vertices: ${formatNum(p)}<br>faces: ${formatNum(Math.floor(f))}`;} if(obj){const c=obj.geometry.getAttribute('position')?.count||0; const rgb=obj.geometry.hasAttribute('color')?'yes':'no'; const st=initialArtifact==='dense'?pointStats.dense:pointStats.sparse; return `points: ${formatNum(c)}<br>has RGB colors: ${rgb}<br>original/displayed/filtered: ${formatNum(st.original)} / ${formatNum(st.displayed)} / ${formatNum(st.filtered)}`;} return `points: ${formatNum(data.summary.points_count||0)}<br>camera_poses_count: ${formatNum(data.summary.camera_poses_count)}<br>keyframe_points_count: ${formatNum(data.summary.keyframe_points_count)}`;}
function percentile(sorted, p){
  if(sorted.length===0) return 0;
  const i=(sorted.length-1)*p;
  const lo=Math.floor(i), hi=Math.ceil(i);
  if(lo===hi) return sorted[lo];
  return sorted[lo]*(hi-i)+sorted[hi]*(i-lo);
}

function createFilteredGeometry(geometry, mode='medium', statsKey='sparse'){
  const pos=geometry.getAttribute('position');
  const col=geometry.getAttribute('color');
  if(!pos) return geometry;
  const ranges={light:[0.005,0.995],medium:[0.01,0.99],strong:[0.02,0.98]};
  if(mode==='off'||!ranges[mode]){pointStats[statsKey]={original:pos.count,displayed:pos.count,filtered:0};return geometry;}
  const [loP,hiP]=ranges[mode];
  const xs=[], ys=[], zs=[];
  for(let i=0;i<pos.count;i++){ xs.push(pos.getX(i)); ys.push(pos.getY(i)); zs.push(pos.getZ(i)); }
  xs.sort((a,b)=>a-b); ys.sort((a,b)=>a-b); zs.sort((a,b)=>a-b);
  const xMin=percentile(xs,loP), xMax=percentile(xs,hiP);
  const yMin=percentile(ys,loP), yMax=percentile(ys,hiP);
  const zMin=percentile(zs,loP), zMax=percentile(zs,hiP);
  const newPos=[], newCol=[];
  for(let i=0;i<pos.count;i++){
    const x=pos.getX(i), y=pos.getY(i), z=pos.getZ(i);
    if(x<xMin||x>xMax||y<yMin||y>yMax||z<zMin||z>zMax) continue;
    newPos.push(x,y,z);
    if(col) newCol.push(col.getX(i),col.getY(i),col.getZ(i));
  }
  const g=new THREE.BufferGeometry();
  g.setAttribute('position',new THREE.Float32BufferAttribute(newPos,3));
  if(col) g.setAttribute('color',new THREE.Float32BufferAttribute(newCol,3));
  g.computeBoundingSphere();
  pointStats[statsKey]={original:pos.count,displayed:newPos.length/3,filtered:pos.count-newPos.length/3};
  return g;
}

function applyOutlierFilter(mode){
  mode=mode||document.getElementById('outlierMode').value;
  if(pointsMesh&&originalPointGeometry){ if(!filteredGeometryCache.sparse[mode]) filteredGeometryCache.sparse[mode]=createFilteredGeometry(originalPointGeometry,mode,'sparse'); pointsMesh.geometry=filteredGeometryCache.sparse[mode]; }
  if(denseObject){ const original=denseObject.userData.originalGeometry||denseObject.geometry; denseObject.userData.originalGeometry=original; if(!filteredGeometryCache.dense[mode]) filteredGeometryCache.dense[mode]=createFilteredGeometry(original,mode,'dense'); denseObject.geometry=filteredGeometryCache.dense[mode]; }
  rebuildAfterTransform(false); updateSummary();
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

function pointPercentileY(p, fallback){ const obj=initialArtifact==='dense'?denseObject:(initialArtifact==='mesh'?meshObject:pointsMesh); const pos=obj?.geometry?.getAttribute('position'); if(!pos) return fallback; const ys=[]; const v=new THREE.Vector3(); for(let i=0;i<pos.count;i++){v.fromBufferAttribute(pos,i); obj.localToWorld(v); ys.push(v.y);} ys.sort((a,b)=>a-b); return percentile(ys,p);}
function recreateGrid(box, center, radius){
  if(grid) scene.remove(grid);
  const gridSize=Math.max(10, radius * 2);
  const floorY=pointPercentileY(0.02, box.min.y) + floorOffset;
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
function fitCloud(){ const box=initialArtifact==='dense'?computeCombinedBox(false,false,false,true,false):(initialArtifact==='mesh'?computeCombinedBox(false,false,false,false,true):computeCombinedBox(true,false,false,false,false)); if(!box) return; const centered=box.clone().translate(rootGroup.position); fitBox(centered); setViewMode('Fit cloud'); }
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
              size: target === 'dense' ? getPointSize() : getPointSize(),
              vertexColors: hasVertexColors,
              color: hasVertexColors
                ? 0xffffff
                : 0x77ddff,
              sizeAttenuation: false,
              depthWrite: true,
              transparent: false
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
  setPointSize(2.75);
  fitCloud();
  setViewMode('Cloud beauty');
}

new PLYLoader().load(data.artifacts.sparse_points_ply_url,(g)=>{
  originalPointGeometry=g;
  g.computeBoundingBox();
  pointsMesh=new THREE.Points(g,pointsMaterial);
  rootGroup.add(pointsMesh);

  applyOutlierFilter(document.getElementById('outlierMode').value);
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
if(initialArtifact==='dense'){ document.getElementById('togglePoints').checked=false; document.getElementById('toggleDenseCloud').checked=true; document.getElementById('toggleMesh').checked=false; document.getElementById('togglePath').checked=false; document.getElementById('toggleKeyframes').checked=false; if(pointsMesh) pointsMesh.visible=false; if(denseObject) denseObject.visible=true; if(meshObject) meshObject.visible=false; if(trajectoryLine) trajectoryLine.visible=false; keyframeGroup.visible=false; applyOutlierFilter(document.getElementById('outlierMode').value); fitCloud(); updateSummary(); }
else if(initialArtifact==='mesh'){ document.getElementById('togglePoints').checked=false; document.getElementById('toggleDenseCloud').checked=false; document.getElementById('toggleMesh').checked=true; document.getElementById('togglePath').checked=false; document.getElementById('toggleKeyframes').checked=false; if(pointsMesh) pointsMesh.visible=false; if(denseObject) denseObject.visible=false; if(meshObject) meshObject.visible=true; if(trajectoryLine) trajectoryLine.visible=false; keyframeGroup.visible=false; fitMesh(); updateSummary(); }
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
function setPointSize(v){ pointsMaterial.size=v; if(denseObject?.material?.isPointsMaterial) denseObject.material.size=v; if(pointsMesh?.material?.isPointsMaterial) pointsMesh.material.size=v; pointSizeSlider.value=String(v); pointSizeValue.textContent=v.toFixed(2);}
pointSizeSlider.addEventListener('input',()=>{
  setPointSize(parseFloat(pointSizeSlider.value));
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
document.getElementById('outlierMode').addEventListener('change',(e)=>applyOutlierFilter(e.target.value));
document.getElementById('hideOutliersBtn').addEventListener('click',()=>{
  const cb=document.getElementById('outlierMode');
  cb.value='medium';
  applyOutlierFilter('medium');
});
const bindClick=(id,handler)=>{
  const btn=document.getElementById(id);
  if(btn) btn.addEventListener('click',handler);
};


const presets={natural:{exposure:1.2,pointSize:1.5,background:'#252b3f'},bright:{exposure:1.7,pointSize:2.25,background:'#252b3f'},contrast:{exposure:2.0,pointSize:2.75,background:'#202437'},meshlab:{exposure:1.5,pointSize:2.0,background:'#29305f'}};
function applyDisplaySettings(sv){ if(!sv) return; if(sv.background){scene.background=new THREE.Color(sv.background); document.getElementById('backgroundColor').value=sv.background;} if(sv.exposure){renderer.toneMappingExposure=Number(sv.exposure); document.getElementById('exposure').value=String(sv.exposure); document.getElementById('exposureValue').textContent=Number(sv.exposure).toFixed(2);} if(sv.point_size){setPointSize(Number(sv.point_size));} if(sv.rotation){rootGroup.rotation.set(Number(sv.rotation.x||0),Number(sv.rotation.y||0),Number(sv.rotation.z||0));} if(sv.preset){document.getElementById('displayPreset').value=sv.preset;} }
async function loadViewerSettings(){ try{ const rr=await fetch(`/api/sfm_viewer_settings.php?order_id=${orderId}&capture_session_id=${sessionId}&pipeline_run_id=${pipelineRunId||''}`); const js=await rr.json(); if(js.ok) applyDisplaySettings(js.settings); }catch(e){ console.warn('settings load failed',e);} }
async function saveViewerSettings(){ const body={order_id:orderId,capture_session_id:sessionId,pipeline_run_id:pipelineRunId||null,settings:{rotation:{x:rootGroup.rotation.x,y:rootGroup.rotation.y,z:rootGroup.rotation.z},point_size:getPointSize(),exposure:renderer.toneMappingExposure,background:'#'+scene.background.getHexString(),use_outlier_filter:document.getElementById('outlierMode').value!=='off',outlier_mode:document.getElementById('outlierMode').value,preset:document.getElementById('displayPreset').value}}; await fetch('/api/sfm_viewer_settings.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)}); }
function rebuildAfterTransform(doFit=true){ const box=computeCombinedBox(initialArtifact==='sparse',false,false,initialArtifact==='dense',initialArtifact==='mesh'); if(!box) return; latestCombinedBox=box.clone(); const size=box.getSize(new THREE.Vector3()); const radius=Math.max(size.length()*0.5,0.1); recreateGrid(box, new THREE.Vector3(), radius); if(doFit) fitCloud(); controls.update(); }
function rotateRoot(axis, radians){ rootGroup.rotateOnAxis(axis,radians); rebuildAfterTransform(true); updateSummary(); }
function autoOrient(){ const obj=denseObject||pointsMesh||meshObject; if(!obj) return; const box=new THREE.Box3().setFromObject(obj); const size=box.getSize(new THREE.Vector3()); const minAxis=size.x<size.y&&size.x<size.z?'x':(size.y<size.z?'y':'z'); if(minAxis==='x') rotateRoot(new THREE.Vector3(0,0,1), Math.PI/2); else if(minAxis==='z') rotateRoot(new THREE.Vector3(1,0,0), Math.PI/2); selectionEl.innerHTML='Auto orient preview applied. Use “Set current orientation as default” to save.'; }

bindClick('resetViewBtn',fitAll);
bindClick('fitAllBtn',fitAll);
bindClick('fitRouteBtn',fitRoute);
bindClick('fitCloudBtn',fitCloud);
bindClick('topViewBtn',topView);
bindClick('sideViewBtn',sideView);
bindClick('cloudBeautyBtn',cloudBeauty);
bindClick('flipVerticalBtn',()=>rotateRoot(new THREE.Vector3(1,0,0),Math.PI));
bindClick('rotXpBtn',()=>rotateRoot(new THREE.Vector3(1,0,0),Math.PI/2)); bindClick('rotXmBtn',()=>rotateRoot(new THREE.Vector3(1,0,0),-Math.PI/2));
bindClick('rotYpBtn',()=>rotateRoot(new THREE.Vector3(0,1,0),Math.PI/2)); bindClick('rotYmBtn',()=>rotateRoot(new THREE.Vector3(0,1,0),-Math.PI/2));
bindClick('rotZpBtn',()=>rotateRoot(new THREE.Vector3(0,0,1),Math.PI/2)); bindClick('rotZmBtn',()=>rotateRoot(new THREE.Vector3(0,0,1),-Math.PI/2));
bindClick('resetOrientationBtn',()=>{rootGroup.rotation.set(0,0,0); rebuildAfterTransform(true); updateSummary();}); bindClick('saveDefaultBtn',saveViewerSettings); bindClick('autoOrientBtn',autoOrient);
bindClick('setFloorBtn',()=>{floorOffset=controls.target.y-pointPercentileY(0.02,0); rebuildAfterTransform(false);}); bindClick('raiseFloorBtn',()=>{floorOffset+=latestRadius*0.02; rebuildAfterTransform(false);}); bindClick('lowerFloorBtn',()=>{floorOffset-=latestRadius*0.02; rebuildAfterTransform(false);});
document.getElementById('displayPreset').addEventListener('change',e=>{const p=presets[e.target.value]; applyDisplaySettings({preset:e.target.value,point_size:p.pointSize,exposure:p.exposure,background:p.background}); updateSummary();});
document.getElementById('exposure').addEventListener('input',e=>{renderer.toneMappingExposure=parseFloat(e.target.value); document.getElementById('exposureValue').textContent=renderer.toneMappingExposure.toFixed(2);});
document.getElementById('backgroundColor').addEventListener('input',e=>{scene.background=new THREE.Color(e.target.value);});

await loadViewerSettings();
rebuildAfterTransform(false);
updateSummary();
addEventListener('resize',()=>{camera.aspect=el.clientWidth/el.clientHeight;camera.updateProjectionMatrix();renderer.setSize(el.clientWidth,el.clientHeight);});
(function anim(){requestAnimationFrame(anim);controls.update();renderer.render(scene,camera);})();
</script>
</body></html>
