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
    .pt { fill: #0d6efd; cursor: pointer; }
    .pt.active { fill: #dc3545; }
    .pt-break { fill: #fd7e14; stroke: #842029; stroke-width: 1; }
    .track { stroke: #198754; fill: none; stroke-width: 2; }
    .track-break { stroke: #6c757d; fill: none; stroke-width: 1.5; stroke-dasharray: 4 4; opacity: 0.7; }
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
    <div class="form-check"><input class="form-check-input" type="checkbox" id="togglePoints" checked><label class="form-check-label" for="togglePoints">Show points</label></div>
    <div class="form-check"><input class="form-check-input" type="checkbox" id="toggleBreaks" checked><label class="form-check-label" for="toggleBreaks">Show segment breaks</label></div>
    <div class="form-check"><input class="form-check-input" type="checkbox" id="toggleCrossBreaks"><label class="form-check-label" for="toggleCrossBreaks">Connect across breaks</label></div>
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
const togglePoints = document.getElementById('togglePoints');
const toggleBreaks = document.getElementById('toggleBreaks');
const toggleCrossBreaks = document.getElementById('toggleCrossBreaks');
let pointEls = [];
let breakEls = [];
let bridgeEls = [];
function card(label, value){ return `<div class="col-md-3"><div class="border rounded p-2"><div class="text-muted small">${label}</div><div class="fw-semibold">${value ?? '-'}</div></div></div>`; }
function fmt(n, d=2){ return Number.isFinite(n) ? n.toFixed(d) : '-'; }
function showPoint(p, el){
  document.querySelectorAll('.pt').forEach(n=>n.classList.remove('active'));
  if(el) el.classList.add('active');
  pointInfo.style.display = '';
  pointText.innerHTML = `keyframe_index=${p.keyframe_index ?? '-'}<br>target_frame_index=${p.target_frame_index ?? '-'} · nearest_frame_index=${p.nearest_frame_index ?? '-'}<br>frame_delta=${p.frame_delta ?? '-'} · distance_from_prev_m=${fmt(p.distance_from_prev_m,3)}<br>x_scaled=${fmt(p.x_scaled,3)} · z_scaled=${fmt(p.z_scaled,3)}<br>${p.nearest_frame_name ?? '-'}`;
  if (p.preview_url) { pointPreview.src = p.preview_url; pointPreview.style.display = ''; } else { pointPreview.style.display = 'none'; }
}

function applyToggles(){
  pointEls.forEach(el=>el.classList.toggle('hidden-elt', !togglePoints.checked));
  breakEls.forEach(el=>el.classList.toggle('hidden-elt', !toggleBreaks.checked));
  bridgeEls.forEach(el=>el.classList.toggle('hidden-elt', !toggleCrossBreaks.checked));
}
function drawTrajectory(pts){
  const minX = Math.min(...pts.map(p=>p.x_scaled)), maxX = Math.max(...pts.map(p=>p.x_scaled));
  const minY = Math.min(...pts.map(p=>p.z_scaled)), maxY = Math.max(...pts.map(p=>p.z_scaled));
  const pad = 24, w = 1000, h = 420;
  const sx = x => pad + ((x-minX) / ((maxX-minX) || 1)) * (w - 2*pad);
  const sy = y => h - pad - ((y-minY) / ((maxY-minY) || 1)) * (h - 2*pad);

  svg.innerHTML = '';
  pointEls = []; breakEls = []; bridgeEls = [];

  const segs = []; let cur = [];
  pts.forEach((p, i)=>{
    if (i === 0) { cur.push(p); return; }
    if (p.segment_break) { if (cur.length) segs.push(cur); cur = [p]; }
    else cur.push(p);
  });
  if (cur.length) segs.push(cur);

  segs.forEach(seg=>{
    if (seg.length < 2) return;
    const d = seg.map((p,i)=>`${i?'L':'M'}${sx(p.x_scaled).toFixed(1)} ${sy(p.z_scaled).toFixed(1)}`).join(' ');
    const path = document.createElementNS('http://www.w3.org/2000/svg','path');
    path.setAttribute('class','track'); path.setAttribute('d',d); svg.appendChild(path);
  });

  for (let i=1;i<pts.length;i++) {
    if (!pts[i].segment_break) continue;
    const d = `M${sx(pts[i-1].x_scaled).toFixed(1)} ${sy(pts[i-1].z_scaled).toFixed(1)} L${sx(pts[i].x_scaled).toFixed(1)} ${sy(pts[i].z_scaled).toFixed(1)}`;
    const path = document.createElementNS('http://www.w3.org/2000/svg','path');
    path.setAttribute('class','track-break hidden-elt'); path.setAttribute('d',d); svg.appendChild(path); bridgeEls.push(path);
  }

  pts.forEach((p) => {
    const c = document.createElementNS('http://www.w3.org/2000/svg','circle');
    c.setAttribute('class',`pt${p.segment_break ? ' pt-break' : ''}`); c.setAttribute('cx',sx(p.x_scaled)); c.setAttribute('cy',sy(p.z_scaled)); c.setAttribute('r',p.segment_break ? '5' : '4');
    c.addEventListener('mouseenter', ()=>showPoint(p,c)); c.addEventListener('click', ()=>showPoint(p,c)); svg.appendChild(c); pointEls.push(c);
    if (p.segment_break) breakEls.push(c);
  });
  applyToggles();
}
async function load(){
  if (!orderId || !sessionId) { statusBox.textContent = 'Missing order_id/session_id'; statusBox.className='alert alert-danger'; return; }
  const r = await fetch(`/api/sfm_run.php?order_id=${encodeURIComponent(orderId)}&session_id=${encodeURIComponent(sessionId)}`);
  const j = await r.json();
  if (!j.ok) { statusBox.textContent = (j.error === 'SfM run not found') ? 'No SfM reconstruction yet' : ('Error: ' + j.error); statusBox.className = 'alert alert-warning'; return; }
  statusBox.textContent = `SfM status: ${j.run.status} | Metric: ${j.run.metric_status}`; statusBox.className = 'alert alert-success';
  summary.innerHTML = [card('Status', j.run.status), card('Metric status', j.run.metric_status), card('Frames', j.summary.frames_count), card('Keyframes', j.summary.keyframes_count), card('Markers', j.summary.marker_count), card('Registered poses', j.summary.poses_count), card('Path length, m', fmt(j.summary.trajectory_path_length_m)), card('Max jump, m', fmt(j.summary.trajectory_max_jump_m)), card('Segment breaks', j.summary.trajectory_segment_breaks), card('Scale factor', j.summary.scale_factor), card('Scale samples', j.summary.scale_samples)].join('');
  if (j.run.metric_status !== 'METRIC_READY') warn.innerHTML = '<div class="alert alert-warning">Metric status is not METRIC_READY</div>';
  if (!j.trajectory || !j.trajectory.length) { warn.innerHTML += '<div class="alert alert-secondary">No trajectory points</div>'; return; }
  const pts = j.trajectory.filter(p => Number.isFinite(p.x_scaled) && Number.isFinite(p.z_scaled));
  if (!pts.length) { warn.innerHTML += '<div class="alert alert-secondary">No valid trajectory points</div>'; return; }
  drawTrajectory(pts);
}
[togglePoints, toggleBreaks, toggleCrossBreaks].forEach(el=>el.addEventListener('change', applyToggles));
load();
</script>
</body>
</html>
