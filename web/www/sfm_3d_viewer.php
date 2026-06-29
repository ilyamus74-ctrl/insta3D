<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__) . '/libs/sfm_debug_public_lib.php';
$debugToken = (string)($_GET['debug_token'] ?? '');
$debugPublic = null;
if ($debugToken !== '') {
    sfm_debug_public_headers();
    $debugPublic = sfm_debug_public_validate($dbcnx, $debugToken, true);
} else {
    auth_require_login();
}
$orderId = (int)($_GET['order_id'] ?? ($debugPublic['order_id'] ?? 0));
$sessionId = (int)($_GET['session_id'] ?? ($debugPublic['capture_session_id'] ?? 0));
$pipelineRunId = (int)($_GET['pipeline_run_id'] ?? 0);
$videoScanId = (int)($_GET['video_scan_id'] ?? 0);
$artifact = in_array((string)($_GET['artifact'] ?? 'sparse'), ['sparse','dense','mesh'], true) ? (string)($_GET['artifact'] ?? 'sparse') : 'sparse';
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>SfM 3D Viewer</title>
<link href="/assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
<style>
body,html{height:100%;margin:0}
body{overflow:hidden}
.viewer-shell{height:calc(100vh - 92px);min-height:520px;position:relative}
#viewer{height:100%;background:#252b3f;border-radius:8px;overflow:hidden}
.overlay,.controls-panel{position:absolute;top:12px;z-index:10;max-width:320px;max-height:calc(100vh - 120px);overflow-y:auto}
.controls-panel{left:12px}
.overlay{right:12px}
.control-section{border-top:1px solid rgba(255,255,255,.12);padding-top:.65rem;margin-top:.65rem}
.control-section:first-child{border-top:0;padding-top:0;margin-top:0}
@media (max-width: 900px){body{overflow:auto}.viewer-shell{height:auto;min-height:70vh}.controls-panel,.overlay{position:relative;left:auto;right:auto;top:auto;max-width:none;max-height:none;margin:.5rem 0}#viewer{height:70vh}}
</style>
</head>
<body class="p-3">
<div class="container-fluid position-relative">
<div class="d-flex gap-2 mb-2">
<?php if (!$debugPublic): ?><a class="btn btn-outline-secondary btn-sm" href="/order.php?id=<?php echo $orderId; ?>">← Back to order</a>
<a class="btn btn-outline-primary btn-sm" href="/sfm_tour_viewer.php?order_id=<?php echo $orderId; ?>&session_id=<?php echo $sessionId; ?>">Open SfM tour</a>
<a class="btn btn-outline-success btn-sm" href="/sfm_viewer.php?order_id=<?php echo $orderId; ?>&session_id=<?php echo $sessionId; ?>">Open diagnostics</a><?php else: ?><span class="badge bg-warning text-dark">Read-only debug public access</span><?php endif; ?>
</div>
<div class="alert alert-light border small" id="sourceHeader">Source video: loading…</div>
<div class="viewer-shell">
<div id="viewer"><div id="viewerStatus" class="text-light p-3">Loading...</div></div>

<div class="controls-panel card bg-dark text-light">
  <div class="card-body small">
    <div class="control-section"><div class="mb-2"><b>Display</b></div>
    <div class="form-check"><input class="form-check-input" type="checkbox" id="togglePoints" checked><label class="form-check-label" for="togglePoints">Show sparse cloud</label></div>
    <div class="form-check"><input class="form-check-input" type="checkbox" id="toggleDenseCloud"><label class="form-check-label" for="toggleDenseCloud">Show dense cloud</label></div>
    <div class="form-check"><input class="form-check-input" type="checkbox" id="toggleMesh"><label class="form-check-label" for="toggleMesh">Show mesh</label></div>
    <div class="form-check"><input class="form-check-input" type="checkbox" id="togglePath" checked><label class="form-check-label" for="togglePath">Show camera path</label></div>
    <div class="form-check"><input class="form-check-input" type="checkbox" id="toggleKeyframes" checked><label class="form-check-label" for="toggleKeyframes">Show keyframes</label></div>
    <div class="form-check"><input class="form-check-input" type="checkbox" id="toggleSuspicious" checked><label class="form-check-label" for="toggleSuspicious">Show suspicious poses</label></div>
    <div class="form-check"><input class="form-check-input" type="checkbox" id="toggleClusters" checked><label class="form-check-label" for="toggleClusters">Show pose clusters</label></div>
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
    </div>
    <div class="control-section"><div class="mb-2"><b>Camera views</b></div>
    <div class="d-grid gap-1 mt-2">
      <button class="btn btn-outline-light btn-sm" id="fitAllBtn">Fit all</button>
      <button class="btn btn-outline-light btn-sm" id="fitRouteBtn">Fit route</button>
      <button class="btn btn-outline-light btn-sm" id="fitCloudBtn">Fit cloud</button>
      <button class="btn btn-outline-light btn-sm" id="frontViewBtn">Front view</button>
      <button class="btn btn-outline-light btn-sm" id="backViewBtn">Back view</button>
      <button class="btn btn-outline-light btn-sm" id="leftViewBtn">Left view</button>
      <button class="btn btn-outline-light btn-sm" id="rightViewBtn">Right view</button>
      <button class="btn btn-outline-light btn-sm" id="topViewBtn">Top view</button>
      <button class="btn btn-outline-light btn-sm" id="sideViewBtn">Side view</button>
      <button class="btn btn-outline-info btn-sm" id="cloudBeautyBtn">Cloud beauty</button>
    </div>
    </div>
    <div class="control-section"><div class="mt-2"><b>Orientation</b></div>
    <div class="form-check mt-1"><input class="form-check-input" type="checkbox" id="autoLevelOnLoad" checked><label class="form-check-label" for="autoLevelOnLoad">Auto level on load</label></div>
    <div class="d-grid gap-1 mt-1">
      <button class="btn btn-outline-light btn-sm" id="floorPlaneBtn">Auto level<br><small class="text-muted">Fit floor plane</small></button>
      <button class="btn btn-outline-light btn-sm" id="invertLevelBtn">Invert level</button>
      <button class="btn btn-outline-light btn-sm" id="rotYpBtn">Yaw left +90°</button><button class="btn btn-outline-light btn-sm" id="rotYmBtn">Yaw right -90°</button>
      <button class="btn btn-outline-warning btn-sm" id="flipVerticalBtn">Flip vertical</button>
      <button class="btn btn-outline-light btn-sm" id="resetOrientationBtn">Reset orientation</button>
      <button class="btn btn-outline-success btn-sm" id="saveDefaultBtn">Save orientation</button>
    </div>
    <details class="control-section"><summary class="text-muted">Advanced local rotation</summary>
      <div class="d-grid gap-1 mt-2">
      <button class="btn btn-outline-light btn-sm" id="rotXpBtn">Rotate X +90°</button><button class="btn btn-outline-light btn-sm" id="rotXmBtn">Rotate X -90°</button>
      <button class="btn btn-outline-light btn-sm" id="rotZpBtn">Rotate Z +90°</button><button class="btn btn-outline-light btn-sm" id="rotZmBtn">Rotate Z -90°</button>
      <button class="btn btn-outline-light btn-sm" id="autoOrientBtn">Auto orientation</button>
      <button class="btn btn-outline-light btn-sm" id="imuGravityBtn">IMU gravity</button>
      <button class="btn btn-outline-light btn-sm" id="manualOrientationBtn">Manual orientation</button>
      </div>
    </details>
    </div>
    <div class="d-flex gap-2 mt-2"><button class="btn btn-outline-light btn-sm" id="setFloorBtn">Set floor here</button><button class="btn btn-outline-light btn-sm" id="raiseFloorBtn">Raise floor</button><button class="btn btn-outline-light btn-sm" id="lowerFloorBtn">Lower floor</button></div>
    <div class="d-flex gap-2 mt-2">
      <button class="btn btn-outline-warning btn-sm" id="hideOutliersBtn">Hide far outliers</button>
      <button class="btn btn-outline-light btn-sm" id="resetViewBtn">Reset view</button>
    </div>
  </div>
</div>
<div class="overlay card bg-dark text-light"><div class="card-body small"><div id="summary">Loading...</div><hr><div id="selection">Click keyframe sphere.</div></div></div>
</div>
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

const orderId=<?php echo json_encode($orderId); ?>,sessionId=<?php echo json_encode($sessionId); ?>,videoScanId=<?php echo json_encode($videoScanId); ?>,pipelineRunId=<?php echo json_encode($pipelineRunId); ?>,initialArtifact=<?php echo json_encode($artifact); ?>,debugToken=<?php echo json_encode($debugToken); ?>;
const urlParams=new URLSearchParams(window.location.search);
const autoLevelUrlOverride=urlParams.get('auto_level');
const statusEl=document.getElementById('viewerStatus');
function showError(msg){ statusEl.className='text-danger p-3'; statusEl.textContent=msg; if(!statusEl.isConnected) document.getElementById('viewer').prepend(statusEl); }
const apiUrl=pipelineRunId>0 ? `/api/sfm_3d.php?order_id=${orderId}&session_id=${sessionId}&video_scan_id=${videoScanId}&pipeline_run_id=${pipelineRunId}&artifact=${initialArtifact}${debugToken?'&debug_token='+encodeURIComponent(debugToken):''}` : `/api/sfm_3d.php?order_id=${orderId}&session_id=${sessionId}${videoScanId>0?'&video_scan_id='+videoScanId:''}${debugToken?'&debug_token='+encodeURIComponent(debugToken):''}`;
const r=await fetch(apiUrl);
const apiContentType=(r.headers.get('Content-Type')||'').toLowerCase();
const data=(r.ok && apiContentType.includes('application/json')) ? await r.json().catch(()=>({ok:false,error:'Bad API JSON response'})) : {ok:false,error:`API returned HTTP ${r.status}`};
if(!data.ok){ showError(data.error||'Artifact not found'); throw new Error(data.error||'load failed'); }
document.getElementById('sourceHeader').innerHTML = `<b>Source video:</b> ${data.source_video_filename||'unknown'} · <b>Video scan:</b> ${data.video_scan_id||videoScanId||'-'} · <b>Pipeline run:</b> ${data.pipeline_run_id||pipelineRunId||'-'} · <b>Mode:</b> ${data.pipeline_mode||'-'} · <b>Status:</b> ${data.status||'-'}`;
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
let cameraTrajectoryAvailable=!!data.summary.camera_trajectory_available;
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
let lastAutoLevelPlaneResult = null;
let hasSavedOrientation = false;
let autoLevelOnLoadApplied = false;

const formatNum=(v)=>typeof v==='number'?v.toLocaleString():v;
function updateSummary(){
  const selectedText=selected ? selected.userData.keyframe_index : 'none';
  summaryEl.innerHTML=`<b>Summary</b><br>view mode: ${currentViewMode}<br>artifact: ${artifactLabel()}<br>${artifactStatsHtml()}<br>camera_poses_count: ${formatNum(data.summary.camera_poses_count)}<br>keyframe_points_count: ${formatNum(data.summary.keyframe_points_count)}<br>point_size: ${getPointSize().toFixed(2)} px<br>orientation: custom quaternion<br><span class="text-muted">approx Euler: X=${deg(rootGroup.rotation.x)}°, Y=${deg(rootGroup.rotation.y)}°, Z=${deg(rootGroup.rotation.z)}°</span><br>selected keyframe: ${selectedText}<br><span class="text-warning">Raw cloud may include outliers.</span>`;
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
  resetCameraUp();
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

function resetCameraUp(){
  camera.up.set(0,1,0);
}

function setCameraView(directionVector, upVector = new THREE.Vector3(0,1,0), label='Camera view'){
  if(!latestCombinedBox) return;
  const center=controls.target.clone();
  const radius=Math.max(camera.position.distanceTo(center), latestRadius*2, 0.1);
  const direction=directionVector.clone().normalize();
  if(direction.lengthSq()===0) return;
  camera.up.copy(upVector).normalize();
  controls.target.copy(center);
  camera.position.copy(center).add(direction.multiplyScalar(radius));
  camera.lookAt(center);
  controls.update();
  setViewMode(label);
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
function resetView(){ resetCameraUp(); fitAll(); }

function topView(){
  setCameraView(new THREE.Vector3(0,1,0), new THREE.Vector3(0,0,-1), 'Top view');
}

function sideView(){
  setCameraView(new THREE.Vector3(1,0.25,1), new THREE.Vector3(0,1,0), 'Side view');
}

function frontView(){ setCameraView(new THREE.Vector3(0,0,1), new THREE.Vector3(0,1,0), 'Front view'); }
function backView(){ setCameraView(new THREE.Vector3(0,0,-1), new THREE.Vector3(0,1,0), 'Back view'); }
function leftView(){ setCameraView(new THREE.Vector3(-1,0,0), new THREE.Vector3(0,1,0), 'Left view'); }
function rightView(){ setCameraView(new THREE.Vector3(1,0,0), new THREE.Vector3(0,1,0), 'Right view'); }

async function fetchArtifactArrayBuffer(url, label) {
  if (!url) throw new Error(`${label} URL is missing`);
  const response = await fetch(url);
  const contentType = (response.headers.get('Content-Type') || '').toLowerCase();
  if (!response.ok) {
    throw new Error(`${label} returned HTTP ${response.status}`);
  }
  if (contentType.includes('text/html') || contentType.includes('application/json')) {
    throw new Error(`${label} returned ${contentType || 'unexpected content type'} instead of PLY`);
  }
  const buffer = await response.arrayBuffer();
  const magic = new TextDecoder('ascii').decode(buffer.slice(0, 3));
  if (magic !== 'ply') {
    throw new Error(`${label} response is not a PLY file`);
  }
  return buffer;
}

async function loadPlyGeometry(url, label) {
  const buffer = await fetchArtifactArrayBuffer(url, label);
  return new PLYLoader().parse(buffer);
}

function createObjectFromGeometry(geometry, target) {
  let object = null;

  if (target === 'dense' || target === 'sparse') {
    const hasVertexColors = geometry.hasAttribute('color');
    const material = new THREE.PointsMaterial({
      size: getPointSize(),
      vertexColors: hasVertexColors,
      color: hasVertexColors ? 0xffffff : 0x77ddff,
      sizeAttenuation: false,
      depthWrite: true,
      transparent: false
    });

    object = new THREE.Points(geometry, material);
  } else if (target === 'mesh') {
    geometry.computeVertexNormals();
    const hasVertexColors = geometry.hasAttribute('color');
    const material = new THREE.MeshStandardMaterial({
      color: hasVertexColors ? 0xffffff : 0xbfbfbf,
      vertexColors: hasVertexColors,
      metalness: 0.0,
      roughness: 0.9,
      side: THREE.DoubleSide
    });

    object = new THREE.Mesh(geometry, material);
  }

  if (!object) return null;
  object.visible = false;
  rootGroup.add(object);
  return object;
}

async function addPlyAsObject(url, target, required = false) {
  try {
    const geometry = await loadPlyGeometry(url, target);
    const object = createObjectFromGeometry(geometry, target);
    if (!object) throw new Error(`Unsupported PLY target: ${target}`);
    return object;
  } catch (error) {
    if (required) {
      console.error(`Required PLY load failed for ${target}`, error);
      throw error;
    }
    console.warn(`Optional PLY load failed for ${target}`, error);
    return null;
  }
}

function sparseArtifactAvailable() {
  return !!(
    (data.sparse && data.sparse.available) ||
    ((data.summary?.points_count || 0) > 0 && (data.sparse?.sparse_ply_url || data.artifacts?.sparse_points_ply_url))
  );
}

function sparseArtifactUrl() {
  return data.sparse?.sparse_ply_url || data.artifacts?.sparse_points_ply_url || '';
}

async function loadCameraTrajectory() {
  const url = data.artifacts?.camera_trajectory_url;
  if (!url) return {poses: []};
  try {
    const response = await fetch(url);
    const contentType = (response.headers.get('Content-Type') || '').toLowerCase();
    if (!response.ok) {
      console.warn(`Camera trajectory artifact not available: HTTP ${response.status}`);
      return {poses: []};
    }
    if (!contentType.includes('application/json') && !contentType.includes('text/json') && !contentType.includes('+json')) {
      console.warn(`Camera trajectory artifact has unexpected content type: ${contentType || 'unknown'}`);
      return {poses: []};
    }
    return await response.json();
  } catch(e) {
    console.warn('Camera trajectory artifact not available', e);
    return {poses: []};
  }
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

const selectedLoaders = {
  dense: async () => {
    if (!(data.dense && data.dense.available && data.dense.fused_ply_url)) throw new Error('Dense point cloud is not available');
    denseObject = await addPlyAsObject(data.dense.fused_ply_url, 'dense', true);
    if (!denseObject) throw new Error('Dense point cloud could not be loaded');
  },
  mesh: async () => {
    const meshUrl = data.mesh?.mesh_ply_url || data.dense?.mesh_ply_url;
    if (!((data.mesh && data.mesh.available) || meshUrl)) throw new Error('Mesh is not available');
    meshObject = await addPlyAsObject(meshUrl, 'mesh', true);
    if (!meshObject) throw new Error('Mesh could not be loaded');
  },
  sparse: async () => {
    if (!sparseArtifactAvailable()) throw new Error('Sparse point cloud is not available');
    const geometry = await loadPlyGeometry(sparseArtifactUrl(), 'sparse');
    originalPointGeometry = geometry;
    geometry.computeBoundingBox();
    pointsMesh = createObjectFromGeometry(geometry, 'sparse');
    if (pointsMesh) pointsMesh.visible = true;
  }
};

try {
  await selectedLoaders[initialArtifact]();
} catch (error) {
  showError(error.message || `Failed to load ${initialArtifact} artifact`);
  throw error;
}

if (initialArtifact !== 'dense' && data.dense && data.dense.available && data.dense.fused_ply_url) {
  denseObject = await addPlyAsObject(data.dense.fused_ply_url, 'dense');
}
if (initialArtifact !== 'mesh') {
  const meshUrl = data.mesh?.mesh_ply_url || data.dense?.mesh_ply_url;
  if ((data.mesh && data.mesh.available && data.mesh.mesh_ply_url) || meshUrl) {
    meshObject = await addPlyAsObject(meshUrl, 'mesh');
  }
}
if (initialArtifact !== 'sparse' && sparseArtifactAvailable()) {
  try {
    const geometry = await loadPlyGeometry(sparseArtifactUrl(), 'sparse');
    originalPointGeometry = geometry;
    geometry.computeBoundingBox();
    pointsMesh = createObjectFromGeometry(geometry, 'sparse');
  } catch (error) {
    console.warn('Optional sparse point cloud is not available', error);
  }
}

const traj = await loadCameraTrajectory();
const poses=(traj.poses||traj||[]).slice().sort((a,b)=>(a.timestamp_sec||0)-(b.timestamp_sec||0));
const pts=poses.map(p=>new THREE.Vector3(...(p.camera_center||[p.x||0,p.y||0,p.z||0])));
if(pts.length>1){
  trajectoryLine=new THREE.Line(new THREE.BufferGeometry().setFromPoints(pts),new THREE.LineBasicMaterial({color:0x00ff66}));
  rootGroup.add(trajectoryLine);
} else {
  selectionEl.innerHTML='<span class="text-warning">Camera trajectory artifact not available</span>';
}
poses.forEach((k,i)=>{
  const warnings=k.warnings||[];
  let color=0x00ff66;
  if(warnings.includes('POSITION_JUMP')) color=0xff0000;
  else if(warnings.includes('ROTATION_JUMP')) color=0xff9900;
  else if(warnings.includes('VISUAL_IMU_ROTATION_MISMATCH')) color=0xaa00ff;
  else if((k.pose_cluster||0)!==0) color=0xffff00;
  const s=new THREE.Mesh(new THREE.SphereGeometry(0.08,12,12),new THREE.MeshBasicMaterial({color}));
  const c=k.camera_center||[k.x||0,k.y||0,k.z||0]; s.position.set(c[0],c[1],c[2]);
  s.userData={...k,keyframe_index:i+1,keyframe_name:k.name||`pose ${i+1}`,baseColor:color};
  keyframeGroup.add(s); spheres.push(s);
});

if(pointsMesh && originalPointGeometry) applyOutlierFilter(document.getElementById('outlierMode').value);
if(initialArtifact==='dense'){
  document.getElementById('togglePoints').checked=false; document.getElementById('toggleDenseCloud').checked=true; document.getElementById('toggleMesh').checked=false; document.getElementById('togglePath').checked=false; document.getElementById('toggleKeyframes').checked=false;
  if(pointsMesh) pointsMesh.visible=false; if(denseObject) denseObject.visible=true; if(meshObject) meshObject.visible=false; if(trajectoryLine) trajectoryLine.visible=false; keyframeGroup.visible=false; applyOutlierFilter(document.getElementById('outlierMode').value); fitCloud(); updateSummary();
}
else if(initialArtifact==='mesh'){
  document.getElementById('togglePoints').checked=false; document.getElementById('toggleDenseCloud').checked=false; document.getElementById('toggleMesh').checked=true; document.getElementById('togglePath').checked=false; document.getElementById('toggleKeyframes').checked=false; if(pointsMesh) pointsMesh.visible=false; if(denseObject) denseObject.visible=false; if(meshObject) meshObject.visible=true; if(trajectoryLine) trajectoryLine.visible=false; keyframeGroup.visible=false; fitMesh(); updateSummary();
}
else { updateSceneBoundsAndCenter(); updateSummary(); }
if(data.dense && data.dense.available){ summaryEl.innerHTML += `<br><span class="text-success">Dense model ready</span>`; } else { summaryEl.innerHTML += `<br><span class="text-muted">Dense model not generated</span>`; }

const ray=new THREE.Raycaster();
const mouse=new THREE.Vector2();
renderer.domElement.addEventListener('click',(ev)=>{
  const rct=renderer.domElement.getBoundingClientRect();
  mouse.x=((ev.clientX-rct.left)/rct.width)*2-1;
  mouse.y=-((ev.clientY-rct.top)/rct.height)*2+1;
  ray.setFromCamera(mouse,camera);
  const hit=ray.intersectObjects(spheres)[0];
  if(!hit) return;
  if(selected) selected.material.color.set(selected.userData.baseColor||0xff5533);
  selected=hit.object;
  selected.material.color.set(0x00ff88);
  const k=selected.userData;
  selectionEl.innerHTML=`<b>${k.name||k.keyframe_name}</b><br>timestamp: ${k.timestamp_sec??''}<br>camera center: ${(k.camera_center||[]).map(v=>Number(v).toFixed(3)).join(', ')}<br>position step: ${Number(k.position_step||0).toFixed(3)}<br>rotation step: ${Number(k.rotation_step_deg||0).toFixed(2)}°<br>reprojection error: ${Number(k.reprojection_error||0).toFixed(2)} px<br>suspicion score: ${Number(k.suspicion_score||0).toFixed(2)}<br>warnings: ${(k.warnings||[]).join(', ')||'none'}`;
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
document.getElementById('toggleSuspicious').addEventListener('change',(e)=>{const on=e.target.checked; spheres.forEach(s=>{ if((s.userData.suspicion_score||0)>0 || (s.userData.warnings||[]).length) s.visible=on; });});
document.getElementById('toggleClusters').addEventListener('change',(e)=>{const on=e.target.checked; spheres.forEach(s=>{ if((s.userData.pose_cluster||0)!==0) s.visible=on; });});
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
function isSavedQuaternion(q){ return q && ['x','y','z','w'].every(k=>Number.isFinite(Number(q[k]))); }
function isSavedRotation(r){ return r && ['x','y','z'].some(k=>Math.abs(Number(r[k]||0))>1e-8); }
function applyDisplaySettings(sv){ if(!sv) return; if(sv.background){scene.background=new THREE.Color(sv.background); document.getElementById('backgroundColor').value=sv.background;} if(sv.exposure){renderer.toneMappingExposure=Number(sv.exposure); document.getElementById('exposure').value=String(sv.exposure); document.getElementById('exposureValue').textContent=Number(sv.exposure).toFixed(2);} if(sv.point_size){setPointSize(Number(sv.point_size));} hasSavedOrientation=false; if(isSavedQuaternion(sv.quaternion)){rootGroup.quaternion.set(Number(sv.quaternion.x),Number(sv.quaternion.y),Number(sv.quaternion.z),Number(sv.quaternion.w)).normalize(); hasSavedOrientation=true;} else if(isSavedRotation(sv.rotation)){rootGroup.rotation.set(Number(sv.rotation.x||0),Number(sv.rotation.y||0),Number(sv.rotation.z||0)); hasSavedOrientation=true;} if(typeof sv.auto_level_on_load==='boolean'){document.getElementById('autoLevelOnLoad').checked=sv.auto_level_on_load;} if(sv.preset){document.getElementById('displayPreset').value=sv.preset;} }
async function loadViewerSettings(){ try{ const rr=await fetch(`/api/sfm_viewer_settings.php?order_id=${orderId}&capture_session_id=${sessionId}&pipeline_run_id=${pipelineRunId||''}`); const js=await rr.json(); if(js.ok) applyDisplaySettings(js.settings); }catch(e){ console.warn('settings load failed',e);} }
async function saveViewerSettings(){ if(debugToken) return; const body={order_id:orderId,capture_session_id:sessionId,pipeline_run_id:pipelineRunId||null,settings:{quaternion:{x:rootGroup.quaternion.x,y:rootGroup.quaternion.y,z:rootGroup.quaternion.z,w:rootGroup.quaternion.w},rotation:{x:rootGroup.rotation.x,y:rootGroup.rotation.y,z:rootGroup.rotation.z},point_size:getPointSize(),exposure:renderer.toneMappingExposure,background:'#'+scene.background.getHexString(),use_outlier_filter:document.getElementById('outlierMode').value!=='off',outlier_mode:document.getElementById('outlierMode').value,preset:document.getElementById('displayPreset').value,auto_level_on_load:document.getElementById('autoLevelOnLoad').checked}}; await fetch('/api/sfm_viewer_settings.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)}); }
function rebuildAfterTransform(doFit=true){ const box=computeCombinedBox(initialArtifact==='sparse',false,false,initialArtifact==='dense',initialArtifact==='mesh'); if(!box) return; latestCombinedBox=box.clone(); const size=box.getSize(new THREE.Vector3()); const radius=Math.max(size.length()*0.5,0.1); recreateGrid(box, new THREE.Vector3(), radius); if(doFit) fitCloud(); controls.update(); }
function rotateRootLocal(axis, radians){ rootGroup.rotateOnAxis(axis,radians); rootGroup.quaternion.normalize(); rebuildAfterTransform(true); updateSummary(); }
function applyWorldRotation(axis, radians){ const q=new THREE.Quaternion().setFromAxisAngle(axis.clone().normalize(), radians); rootGroup.quaternion.premultiply(q).normalize(); rebuildAfterTransform(true); updateSummary(); }
function autoOrient(){ const obj=denseObject||pointsMesh||meshObject; if(!obj) return; const box=new THREE.Box3().setFromObject(obj); const size=box.getSize(new THREE.Vector3()); const minAxis=size.x<size.y&&size.x<size.z?'x':(size.y<size.z?'y':'z'); if(minAxis==='x') rotateRootLocal(new THREE.Vector3(0,0,1), Math.PI/2); else if(minAxis==='z') rotateRootLocal(new THREE.Vector3(1,0,0), Math.PI/2); selectionEl.innerHTML='Auto orient preview applied. Use “Save orientation” to save.'; }

function floorPlaneSourceObject(){
  return denseObject || meshObject || pointsMesh || null;
}

function sampleGeometryPositions(object, maxSamples=60000){
  const pos=object?.geometry?.getAttribute('position');
  if(!pos || pos.count<3) return null;
  const box=new THREE.Box3().setFromBufferAttribute(pos);
  const size=box.getSize(new THREE.Vector3());
  const diag=Math.max(size.length(), 0.001);
  const target=Math.min(maxSamples, pos.count);
  const step=Math.max(1, Math.floor(pos.count/target));
  const points=[];
  for(let i=0;i<pos.count && points.length<target;i+=step){
    const x=pos.getX(i), y=pos.getY(i), z=pos.getZ(i);
    if(Number.isFinite(x) && Number.isFinite(y) && Number.isFinite(z)) points.push(new THREE.Vector3(x,y,z));
  }
  return {points, count:points.length, total:pos.count, diag};
}

function planeFromThreePoints(a,b,c){
  const ab=b.clone().sub(a), ac=c.clone().sub(a);
  const normal=ab.cross(ac);
  const len=normal.length();
  if(len<1e-8) return null;
  normal.multiplyScalar(1/len);
  return {normal, constant:-normal.dot(a)};
}

function smallestEigenVectorSymmetric3(m){
  const a=m.map(row=>row.slice());
  const v=[[1,0,0],[0,1,0],[0,0,1]];
  for(let iter=0;iter<32;iter++){
    let p=0,q=1,max=Math.abs(a[0][1]);
    [[0,2],[1,2]].forEach(([i,j])=>{ const val=Math.abs(a[i][j]); if(val>max){max=val;p=i;q=j;} });
    if(max<1e-10) break;
    const theta=(a[q][q]-a[p][p])/(2*a[p][q]);
    const t=Math.sign(theta||1)/(Math.abs(theta)+Math.sqrt(theta*theta+1));
    const c=1/Math.sqrt(t*t+1), s=t*c;
    const app=a[p][p], aqq=a[q][q], apq=a[p][q];
    a[p][p]=c*c*app-2*s*c*apq+s*s*aqq;
    a[q][q]=s*s*app+2*s*c*apq+c*c*aqq;
    a[p][q]=0; a[q][p]=0;
    for(let k=0;k<3;k++){
      if(k===p || k===q) continue;
      const akp=a[k][p], akq=a[k][q];
      a[k][p]=c*akp-s*akq; a[p][k]=a[k][p];
      a[k][q]=s*akp+c*akq; a[q][k]=a[k][q];
    }
    for(let k=0;k<3;k++){
      const vkp=v[k][p], vkq=v[k][q];
      v[k][p]=c*vkp-s*vkq;
      v[k][q]=s*vkp+c*vkq;
    }
  }
  let idx=0;
  if(a[1][1]<a[idx][idx]) idx=1;
  if(a[2][2]<a[idx][idx]) idx=2;
  const n=new THREE.Vector3(v[0][idx],v[1][idx],v[2][idx]);
  return n.length()>1e-8 ? n.normalize() : null;
}

function refinePlaneFromInliers(points, inliers){
  const centroid=new THREE.Vector3();
  inliers.forEach(i=>centroid.add(points[i]));
  centroid.multiplyScalar(1/inliers.length);
  let xx=0,xy=0,xz=0,yy=0,yz=0,zz=0;
  inliers.forEach(i=>{
    const p=points[i]; const x=p.x-centroid.x, y=p.y-centroid.y, z=p.z-centroid.z;
    xx+=x*x; xy+=x*y; xz+=x*z; yy+=y*y; yz+=y*z; zz+=z*z;
  });
  const n=smallestEigenVectorSymmetric3([[xx,xy,xz],[xy,yy,yz],[xz,yz,zz]]);
  if(!n) return null;
  return {normal:n, constant:-n.dot(centroid)};
}

function fitDominantPlaneRansac(points, diag){
  const threshold=THREE.MathUtils.clamp(diag*0.01, 0.005, 0.15);
  const iterations=points.length>30000?700:450;
  let best=null;
  for(let iter=0;iter<iterations;iter++){
    const ia=Math.floor(Math.random()*points.length), ib=Math.floor(Math.random()*points.length), ic=Math.floor(Math.random()*points.length);
    if(ia===ib || ia===ic || ib===ic) continue;
    const plane=planeFromThreePoints(points[ia],points[ib],points[ic]);
    if(!plane) continue;
    let count=0, sumSq=0;
    for(let i=0;i<points.length;i++){
      const d=Math.abs(plane.normal.dot(points[i])+plane.constant);
      if(d<=threshold){ count++; sumSq+=d*d; }
    }
    if(!best || count>best.count || (count===best.count && sumSq<best.sumSq)) best={...plane,count,sumSq};
  }
  if(!best) return null;
  const inliers=[];
  for(let i=0;i<points.length;i++) if(Math.abs(best.normal.dot(points[i])+best.constant)<=threshold) inliers.push(i);
  const refined=inliers.length>=3 ? refinePlaneFromInliers(points,inliers) : null;
  const plane=refined || best;
  let sumSq=0;
  inliers.forEach(i=>{ const d=plane.normal.dot(points[i])+plane.constant; sumSq+=d*d; });
  return {normal:plane.normal, constant:plane.constant, inliers:inliers.length, total:points.length, ratio:inliers.length/points.length, rmse:Math.sqrt(sumSq/Math.max(inliers.length,1)), threshold};
}

function evaluateFloorSideCandidate(points, fit, candidateNormal){
  const normal=candidateNormal.clone().normalize();
  const constant=normal.dot(fit.normal) >= 0 ? fit.constant : -fit.constant;
  const currentQ=rootGroup.quaternion.clone();
  const worldNormal=normal.clone().applyQuaternion(currentQ).normalize();
  const alignQ=new THREE.Quaternion().setFromUnitVectors(worldNormal, new THREE.Vector3(0,1,0));
  const finalQ=currentQ.premultiply(alignQ);
  const planePoint=normal.clone().multiplyScalar(-constant).applyQuaternion(finalQ);
  const heights=[];
  const ys=[];
  let positive=0;
  points.forEach(p=>{
    const h=normal.dot(p)+constant;
    heights.push(h);
    if(h>=0) positive++;
    ys.push(p.clone().applyQuaternion(finalQ).y);
  });
  heights.sort((a,b)=>a-b);
  ys.sort((a,b)=>a-b);
  const minY=ys[0], p02Y=percentile(ys,0.02), medianY=percentile(ys,0.5), p98Y=percentile(ys,0.98);
  const ySpan=Math.max(p98Y-p02Y, 1e-6);
  const planeLowCloseness=Math.abs(planePoint.y-p02Y)/ySpan;
  const planeHighCloseness=Math.abs(planePoint.y-p98Y)/ySpan;
  const positiveRatio=positive/Math.max(points.length,1);
  const medianHeight=percentile(heights,0.5);
  const score=(positiveRatio*3) + (medianHeight>0 ? 2 : -2) + (planeHighCloseness-planeLowCloseness)*2;
  return {normal, constant, alignQ, finalQ, worldNormal, positiveRatio, medianHeight, minY, p02Y, medianY, p98Y, planeY:planePoint.y, planeLowCloseness, planeHighCloseness, score};
}

function chooseFloorSide(points, fit){
  const a=evaluateFloorSideCandidate(points, fit, fit.normal.clone());
  const b=evaluateFloorSideCandidate(points, fit, fit.normal.clone().negate());
  const best=a.score>=b.score ? a : b;
  const other=best===a ? b : a;
  const strong=best.positiveRatio>=0.55 && best.medianHeight>0 && best.planeLowCloseness<best.planeHighCloseness;
  const ambiguous=!strong || Math.abs(best.score-other.score)<0.35;
  return {...best, ambiguous};
}

function autoLevelByFloorPlane(options={}){
  const onLoad=!!options.onLoad;
  const obj=floorPlaneSourceObject();
  const sampled=sampleGeometryPositions(obj, 60000);
  if(!sampled || sampled.count<300){ selectionEl.innerHTML='<span class="text-warning">Auto level needs at least 300 geometry points.</span>'; return Promise.resolve(false); }
  selectionEl.innerHTML='Fitting dominant floor plane…';
  return new Promise(resolve=>setTimeout(()=>{
    const fit=fitDominantPlaneRansac(sampled.points, sampled.diag);
    const minInliers=Math.max(150, Math.floor(sampled.count*0.06));
    if(!fit || fit.inliers<minInliers || fit.ratio<0.06 || fit.rmse>fit.threshold*0.85){
      const msg=fit ? `Floor plane confidence is low: inliers ${fit.inliers} / ${fit.total}, ratio ${(fit.ratio*100).toFixed(1)}%, rmse ${fit.rmse.toFixed(3)}.` : 'No dominant floor plane found.';
      selectionEl.innerHTML=`<span class="text-warning">${msg} Auto level was not applied.</span>`;
      resolve(false);
      return;
    }
    const choice=chooseFloorSide(sampled.points, fit);
    const angle=THREE.MathUtils.radToDeg(2*Math.acos(THREE.MathUtils.clamp(Math.abs(choice.alignQ.w), -1, 1)));
    rootGroup.quaternion.premultiply(choice.alignQ).normalize();
    lastAutoLevelPlaneResult={
      normal:{x:choice.normal.x,y:choice.normal.y,z:choice.normal.z},
      inliers:fit.inliers,
      total:fit.total,
      ratio:fit.ratio,
      rmse:fit.rmse,
      rotationAngleDeg:angle,
      floorSide: choice.ambiguous ? 'ambiguous-auto' : 'auto',
      manuallyInverted:false
    };
    rebuildAfterTransform(true);
    updateSummary();
    const warning=choice.ambiguous ? '<br><span class="text-warning">Auto level applied, but floor side is ambiguous. Use Invert level if upside down.</span>' : '';
    const prefix=onLoad ? 'Auto level applied on load' : 'Auto level applied';
    selectionEl.innerHTML=`${prefix}: inliers ${fit.inliers} / ${fit.total}, ratio ${(fit.ratio*100).toFixed(1)}%, rmse ${fit.rmse.toFixed(3)}, rotation ${angle.toFixed(1)}°, floor side ${choice.ambiguous?'ambiguous auto':'auto'}${warning}<br><span class="text-muted">Use “Save orientation” to save this orientation.</span>`;
    resolve(true);
  }, 20));
}

async function maybeAutoLevelOnLoad(){
  if(autoLevelOnLoadApplied) return;
  autoLevelOnLoadApplied=true;
  if(hasSavedOrientation){ selectionEl.innerHTML='Auto level skipped: saved orientation loaded.'; return; }
  const enabledBySetting=document.getElementById('autoLevelOnLoad').checked;
  const enabled=autoLevelUrlOverride==='0' ? false : (autoLevelUrlOverride==='1' ? true : enabledBySetting);
  if(!enabled){ selectionEl.innerHTML='Auto level on load disabled.'; return; }
  await autoLevelByFloorPlane({onLoad:true});
}

function invertLevel(){
  applyWorldRotation(new THREE.Vector3(1,0,0), Math.PI);
  if(lastAutoLevelPlaneResult){
    lastAutoLevelPlaneResult={...lastAutoLevelPlaneResult, manuallyInverted:true, floorSide:'manual inverted'};
  }
  selectionEl.innerHTML='Invert level applied: floor/ceiling swapped after auto-level.<br><span class="text-muted">Use “Save orientation” to save this orientation.</span>';
}

bindClick('resetViewBtn',resetView);
bindClick('fitAllBtn',fitAll);
bindClick('fitRouteBtn',fitRoute);
bindClick('fitCloudBtn',fitCloud);
bindClick('frontViewBtn',frontView);
bindClick('backViewBtn',backView);
bindClick('leftViewBtn',leftView);
bindClick('rightViewBtn',rightView);
bindClick('topViewBtn',topView);
bindClick('sideViewBtn',sideView);
bindClick('cloudBeautyBtn',cloudBeauty);
bindClick('flipVerticalBtn',()=>applyWorldRotation(new THREE.Vector3(0,0,1),Math.PI));
bindClick('invertLevelBtn',invertLevel);
bindClick('rotXpBtn',()=>rotateRootLocal(new THREE.Vector3(1,0,0),Math.PI/2)); bindClick('rotXmBtn',()=>rotateRootLocal(new THREE.Vector3(1,0,0),-Math.PI/2));
bindClick('rotYpBtn',()=>applyWorldRotation(new THREE.Vector3(0,1,0),Math.PI/2)); bindClick('rotYmBtn',()=>applyWorldRotation(new THREE.Vector3(0,1,0),-Math.PI/2));
bindClick('rotZpBtn',()=>rotateRootLocal(new THREE.Vector3(0,0,1),Math.PI/2)); bindClick('rotZmBtn',()=>rotateRootLocal(new THREE.Vector3(0,0,1),-Math.PI/2));
bindClick('resetOrientationBtn',()=>{rootGroup.quaternion.identity(); rebuildAfterTransform(true); updateSummary();}); bindClick('saveDefaultBtn',saveViewerSettings); bindClick('autoOrientBtn',autoOrient); bindClick('floorPlaneBtn',autoLevelByFloorPlane);
bindClick('setFloorBtn',()=>{floorOffset=controls.target.y-pointPercentileY(0.02,0); rebuildAfterTransform(false);}); bindClick('raiseFloorBtn',()=>{floorOffset+=latestRadius*0.02; rebuildAfterTransform(false);}); bindClick('lowerFloorBtn',()=>{floorOffset-=latestRadius*0.02; rebuildAfterTransform(false);});
document.getElementById('displayPreset').addEventListener('change',e=>{const p=presets[e.target.value]; applyDisplaySettings({preset:e.target.value,point_size:p.pointSize,exposure:p.exposure,background:p.background}); updateSummary();});
document.getElementById('exposure').addEventListener('input',e=>{renderer.toneMappingExposure=parseFloat(e.target.value); document.getElementById('exposureValue').textContent=renderer.toneMappingExposure.toFixed(2);});
document.getElementById('backgroundColor').addEventListener('input',e=>{scene.background=new THREE.Color(e.target.value);});

await loadViewerSettings();
rebuildAfterTransform(false);
updateSummary();
await maybeAutoLevelOnLoad();
addEventListener('resize',()=>{camera.aspect=el.clientWidth/el.clientHeight;camera.updateProjectionMatrix();renderer.setSize(el.clientWidth,el.clientHeight);});
(function anim(){requestAnimationFrame(anim);controls.update();renderer.render(scene,camera);})();
</script>
</body></html>
