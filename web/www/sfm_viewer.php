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
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>SfM Viewer</title>
  <link href="/assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
  <style>
    #trajSvg { width: 100%; height: 420px; border: 1px solid #ddd; border-radius: 8px; background: #fafafa; }
    .full-track { stroke: #6c757d; fill: none; stroke-width: 1.5; opacity: 0.95; }
    .full-track-overlay { stroke: #198754; fill: none; stroke-width: 1; opacity: 0.55; }
    .keyframe-track { stroke: #0d6efd; fill: none; stroke-width: 2; opacity: 0.75; }
    .track-break { stroke: #dc3545; fill: none; stroke-width: 1.5; stroke-dasharray: 4 4; }
    .pt-kf { cursor: pointer; stroke: #fff; stroke-width: 1; }
    .pt-kf.active { stroke: #111; stroke-width: 2; }
    .pt-break { fill: #fd7e14; stroke: #842029; stroke-width: 1; }
    .hidden-elt { display: none; }
  </style>
</head>
<body class="p-3">
<div class="container">
  <h3 class="mb-3">SfM / Video reconstruction</h3>
  <div class="mb-3"><a class="btn btn-outline-secondary btn-sm" href="/order.php?id=<?php echo $orderId; ?>">← Back to order</a></div>
  <div id="statusBox" class="alert alert-secondary">Loading…</div>
  <div id="summary" class="row g-2 mb-3"></div>
  <div class="d-flex flex-wrap gap-3 mb-2">
    <div class="form-check"><input class="form-check-input" type="checkbox" id="toggleFullTrajectory" checked><label class="form-check-label" for="toggleFullTrajectory">Show full camera trajectory</label></div>
    <div class="form-check"><input class="form-check-input" type="checkbox" id="toggleKeyframes" checked><label class="form-check-label" for="toggleKeyframes">Show keyframes</label></div>
    <div class="form-check"><input class="form-check-input" type="checkbox" id="toggleBreaks" checked><label class="form-check-label" for="toggleBreaks">Show segment breaks</label></div>
    <div class="form-check"><input class="form-check-input" type="checkbox" id="toggleCrossBreaks"><label class="form-check-label" for="toggleCrossBreaks">Connect keyframe breaks</label></div>
  </div>
  <div id="warn"></div>
  <svg id="trajSvg" viewBox="0 0 1000 420" preserveAspectRatio="xMidYMid meet"></svg>
  <div id="pointInfo" class="card mt-3" style="display:none;">
    <div class="card-body">
      <div id="pointText" class="small"></div>
      <img id="pointPreview" alt="keyframe" style="max-width:220px;max-height:140px;display:none;" class="mt-2 border rounded">
    </div>
  </div>
</div>
<script>
const orderId = <?php echo json_encode($orderId); ?>;
const sessionId = <?php echo json_encode($sessionId); ?>;
const statusBox = document.getElementById('statusBox');
const summary = document.getElementById('summary');
const svg = document.getElementById('trajSvg');
const warn = document.getElementById('warn');
const pointInfo = document.getElementById('pointInfo');
const pointText = document.getElementById('pointText');
const pointPreview = document.getElementById('pointPreview');
const toggleFullTrajectory = document.getElementById('toggleFullTrajectory');
const toggleBreaks = document.getElementById('toggleBreaks');
const toggleCrossBreaks = document.getElementById('toggleCrossBreaks');
let keyframePointEls = [];
let breakEls = [];
let bridgeEls = [];
let fullTrajectoryEls = [];
let keyframeTrackEls = [];

function card(label, value){ return `<div class="col-md-3"><div class="border rounded p-2"><div class="text-muted small">${label}</div><div class="fw-semibold">${value ?? '-'}</div></div></div>`; }
function fmt(n, d=2){ return Number.isFinite(n) ? n.toFixed(d) : '-'; }
function clamp01(v){ return Math.max(0, Math.min(1, v)); }
function keyframeColor(y, yMin, yRange) {
  if (!Number.isFinite(y) || !Number.isFinite(yMin) || !Number.isFinite(yRange) || yRange <= 0) return '#0d6efd';
  const t = clamp01((y - yMin) / yRange);
  const hue = 220 - (200 * t);
  return `hsl(${hue.toFixed(1)}, 85%, 52%)`;
}
function showPoint(p, el){
  document.querySelectorAll('.pt-kf').forEach(n=>n.classList.remove('active'));
  if(el) el.classList.add('active');
  pointInfo.style.display = '';
  pointText.innerHTML = `keyframe_index=${p.keyframe_index ?? '-'}<br>nearest_frame_name=${p.nearest_frame_name ?? '-'}<br>x_scaled=${fmt(p.x_scaled,3)} · y_scaled=${fmt(p.y_scaled,3)} · z_scaled=${fmt(p.z_scaled,3)}<br>distance_from_prev_m=${fmt(p.distance_from_prev_m,3)} · frame_delta=${p.frame_delta ?? '-'}`;
  if (p.preview_url) { pointPreview.src = p.preview_url; pointPreview.style.display = ''; } else { pointPreview.style.display = 'none'; }
}

function applyToggles(){
  const showKeyframes = toggleKeyframes.checked;
  fullTrajectoryEls.forEach(el=>el.classList.toggle('hidden-elt', !toggleFullTrajectory.checked));
  keyframeTrackEls.forEach(el=>el.classList.toggle('hidden-elt', !showKeyframes));
  keyframePointEls.forEach(el=>el.classList.toggle('hidden-elt', !showKeyframes));
  breakEls.forEach(el=>el.classList.toggle('hidden-elt', !toggleBreaks.checked || !showKeyframes));
  bridgeEls.forEach(el=>el.classList.toggle('hidden-elt', !toggleCrossBreaks.checked || !showKeyframes))
}
function linePath(pts, sx, sy){
  return pts.map((p, i)=>`${i?'L':'M'}${sx(p.x_scaled).toFixed(1)} ${sy(p.z_scaled).toFixed(1)}`).join(' ');
}
function drawScene(fullPts, keyPts, yMin, yRange){
  const allPts = fullPts.concat(keyPts);
  const minX = Math.min(...allPts.map(p=>p.x_scaled)), maxX = Math.max(...allPts.map(p=>p.x_scaled));
  const minZ = Math.min(...allPts.map(p=>p.z_scaled)), maxZ = Math.max(...allPts.map(p=>p.z_scaled));
  const pad = 24, w = 1000, h = 420;
  const sx = x => pad + ((x-minX) / ((maxX-minX) || 1)) * (w - 2*pad);
  const sy = z => h - pad - ((z-minZ) / ((maxZ-minZ) || 1)) * (h - 2*pad);

  svg.innerHTML = '';
  keyframePointEls = []; breakEls = []; bridgeEls = []; fullTrajectoryEls = []; keyframeTrackEls = [];

  if (fullPts.length >= 2) {
    const p1 = document.createElementNS('http://www.w3.org/2000/svg','path');
    p1.setAttribute('class','full-track');
    p1.setAttribute('d', linePath(fullPts, sx, sy));
    svg.appendChild(p1);
    const p2 = document.createElementNS('http://www.w3.org/2000/svg','path');
    p2.setAttribute('class','full-track-overlay');
    p2.setAttribute('d', linePath(fullPts, sx, sy));
    svg.appendChild(p2);
    fullTrajectoryEls.push(p1, p2);
  }

  if (keyPts.length >= 2) {
    const p = document.createElementNS('http://www.w3.org/2000/svg','path');
    p.setAttribute('class','keyframe-track');
    p.setAttribute('d', linePath(keyPts, sx, sy));
    svg.appendChild(p);
    keyframeTrackEls.push(p);
  }

  for (let i=1;i<keyPts.length;i++) {
    if (!keyPts[i].segment_break) continue;
    const d = `M${sx(keyPts[i-1].x_scaled).toFixed(1)} ${sy(keyPts[i-1].z_scaled).toFixed(1)} L${sx(keyPts[i].x_scaled).toFixed(1)} ${sy(keyPts[i].z_scaled).toFixed(1)}`;
    const path = document.createElementNS('http://www.w3.org/2000/svg','path');
    path.setAttribute('class','track-break hidden-elt'); path.setAttribute('d',d); svg.appendChild(path); bridgeEls.push(path);
  }

  keyPts.forEach((p) => {
    const c = document.createElementNS('http://www.w3.org/2000/svg','circle');
    const isBreak = !!p.segment_break;
    c.setAttribute('class',`pt-kf${isBreak ? ' pt-break' : ''}`);
    c.setAttribute('cx',sx(p.x_scaled));
    c.setAttribute('cy',sy(p.z_scaled));
    c.setAttribute('r',isBreak ? '6' : '5');
    if (!isBreak) c.setAttribute('fill', keyframeColor(p.y_scaled, yMin, yRange));
    c.addEventListener('mouseenter', ()=>showPoint(p,c));
    c.addEventListener('click', ()=>showPoint(p,c));
    svg.appendChild(c);
    keyframePointEls.push(c);
    if (isBreak) breakEls.push(c);
  });
  applyToggles();
}
async function load(){
  if (!orderId || !sessionId) { statusBox.textContent = 'Missing order_id/session_id'; statusBox.className='alert alert-danger'; return; }
  const r = await fetch(`/api/sfm_run.php?order_id=${encodeURIComponent(orderId)}&session_id=${encodeURIComponent(sessionId)}`);
  const j = await r.json();
  if (!j.ok) { statusBox.textContent = (j.error === 'SfM run not found') ? 'No SfM reconstruction yet' : ('Error: ' + j.error); statusBox.className = 'alert alert-warning'; return; }
  statusBox.textContent = `SfM status: ${j.run.status} | Metric: ${j.run.metric_status}`; statusBox.className = 'alert alert-success';
  summary.innerHTML = [card('Status', j.run.status), card('Metric status', j.run.metric_status), card('Frames', j.summary.frames_count), card('Keyframes', j.summary.keyframes_count), card('Camera poses', j.summary.camera_trajectory_count), card('Y min', fmt(j.summary.y_min,3)), card('Y max', fmt(j.summary.y_max,3)), card('Y range, m', fmt(j.summary.y_range_m,3)), card('Registered poses', j.summary.poses_count), card('Segment breaks', j.summary.trajectory_segment_breaks), card('Path length, m', fmt(j.summary.trajectory_path_length_m)), card('Max jump, m', fmt(j.summary.trajectory_max_jump_m))].join('');
  if (j.run.metric_status !== 'METRIC_READY') warn.innerHTML = '<div class="alert alert-warning">Metric status is not METRIC_READY</div>';
  const fullPts = (j.camera_trajectory || []).filter(p => Number.isFinite(p.x_scaled) && Number.isFinite(p.z_scaled));
  const keyPts = (j.trajectory || []).filter(p => Number.isFinite(p.x_scaled) && Number.isFinite(p.z_scaled));
  if (!fullPts.length || !keyPts.length) { warn.innerHTML += '<div class="alert alert-secondary">No valid trajectory points</div>'; return; }
  drawScene(fullPts, keyPts, j.summary.y_min, j.summary.y_range_m);
}
[toggleFullTrajectory, toggleKeyframes, toggleBreaks, toggleCrossBreaks].forEach(el=>el.addEventListener('change', applyToggles));
load();
</script>
</body>
</html>
