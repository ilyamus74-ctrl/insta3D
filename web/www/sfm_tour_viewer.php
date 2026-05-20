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
  <title>SfM Tour Viewer</title>
  <link href="/assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
  <style>
    #planSvg { width: 100%; height: 420px; border: 1px solid #ddd; border-radius: 8px; background: #fafafa; }
    #mainPreview { width: 100%; max-height: 62vh; object-fit: contain; background: #111; border-radius: 8px; }
    .traj-line { fill: none; stroke: #6c757d; stroke-width: 1.2; opacity: .85; }
    .key-dot { fill: #0d6efd; stroke: #fff; stroke-width: 1; cursor: pointer; }
    .key-dot.break { fill: #fd7e14; stroke: #842029; }
    .key-dot.active { fill: #dc3545; stroke: #111; stroke-width: 2; }
  </style>
</head>
<body class="p-3">
<div class="container-fluid">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div class="d-flex gap-2">
      <a class="btn btn-outline-secondary btn-sm" href="/order.php?id=<?php echo $orderId; ?>">← Back to order</a>
      <a class="btn btn-outline-success btn-sm" href="/sfm_viewer.php?order_id=<?php echo $orderId; ?>&session_id=<?php echo $sessionId; ?>">Open diagnostics</a>
    </div>
    <div id="statusBox" class="alert alert-secondary mb-0 py-2 px-3">Loading…</div>
  </div>

  <div class="row g-3">
    <div class="col-12 col-lg-8">
      <img id="mainPreview" alt="Selected keyframe" src="">
      <div class="card mt-2">
        <div class="card-body small" id="pointMeta">No point selected.</div>
      </div>
    </div>
    <div class="col-12 col-lg-4">
      <svg id="planSvg" viewBox="0 0 1000 420" preserveAspectRatio="xMidYMid meet"></svg>
      <div id="segmentWarn" class="alert alert-warning small mt-2 d-none">Segment break / possible jump</div>
    </div>
  </div>

  <div class="d-flex flex-wrap justify-content-between align-items-center mt-3 gap-2">
    <div>
      <button id="prevBtn" class="btn btn-primary btn-sm">Prev</button>
      <button id="nextBtn" class="btn btn-primary btn-sm">Next</button>
    </div>
    <div class="small text-muted">Use ArrowLeft / ArrowRight for navigation.</div>
  </div>
</div>

<script>
const orderId = <?php echo json_encode($orderId); ?>;
const sessionId = <?php echo json_encode($sessionId); ?>;
const statusBox = document.getElementById('statusBox');
const planSvg = document.getElementById('planSvg');
const mainPreview = document.getElementById('mainPreview');
const pointMeta = document.getElementById('pointMeta');
const segmentWarn = document.getElementById('segmentWarn');
const prevBtn = document.getElementById('prevBtn');
const nextBtn = document.getElementById('nextBtn');

let keyPoints = [];
let cameraTrajectory = [];
let selectedIdx = 0;
let pointEls = [];

function fmt(n, d=3){ return Number.isFinite(n) ? Number(n).toFixed(d) : '-'; }
function linePath(pts, sx, sy){ return pts.map((p, i)=>`${i?'L':'M'}${sx(p.x_scaled).toFixed(1)} ${sy(p.z_scaled).toFixed(1)}`).join(' '); }

function drawPlan(){
  const all = cameraTrajectory.concat(keyPoints).filter(p => Number.isFinite(p.x_scaled) && Number.isFinite(p.z_scaled));
  if (!all.length) { planSvg.innerHTML=''; return; }
  const minX = Math.min(...all.map(p=>p.x_scaled));
  const maxX = Math.max(...all.map(p=>p.x_scaled));
  const minZ = Math.min(...all.map(p=>p.z_scaled));
  const maxZ = Math.max(...all.map(p=>p.z_scaled));
  const pad = 20, w = 1000, h = 420;
  const sx = x => pad + ((x-minX)/((maxX-minX)||1))*(w-2*pad);
  const sy = z => h-pad - ((z-minZ)/((maxZ-minZ)||1))*(h-2*pad);

  planSvg.innerHTML = '';
  pointEls = [];

  if (cameraTrajectory.length >= 2) {
    const p = document.createElementNS('http://www.w3.org/2000/svg','path');
    p.setAttribute('class','traj-line');
    p.setAttribute('d', linePath(cameraTrajectory, sx, sy));
    planSvg.appendChild(p);
  }

  keyPoints.forEach((pt, idx) => {
    const c = document.createElementNS('http://www.w3.org/2000/svg','circle');
    c.setAttribute('cx', sx(pt.x_scaled));
    c.setAttribute('cy', sy(pt.z_scaled));
    c.setAttribute('r', idx === selectedIdx ? '7' : '5');
    c.setAttribute('class', `key-dot${pt.segment_break ? ' break' : ''}${idx === selectedIdx ? ' active' : ''}`);
    c.addEventListener('click', () => selectPoint(idx));
    planSvg.appendChild(c);
    pointEls.push(c);
  });
}

function selectPoint(idx){
  if (!keyPoints.length) return;
  selectedIdx = Math.max(0, Math.min(keyPoints.length - 1, idx));
  const p = keyPoints[selectedIdx];
  mainPreview.src = p.preview_url || '';
  pointMeta.innerHTML = `keyframe_index=${p.keyframe_index ?? '-'}<br>nearest_frame_name=${p.nearest_frame_name ?? '-'}<br>x_scaled=${fmt(p.x_scaled)} · y_scaled=${fmt(p.y_scaled)} · z_scaled=${fmt(p.z_scaled)}<br>segment_break=${p.segment_break ? 'true' : 'false'}`;
  segmentWarn.classList.toggle('d-none', !p.segment_break);
  drawPlan();
}

function goPrev(){ selectPoint(selectedIdx - 1); }
function goNext(){ selectPoint(selectedIdx + 1); }

prevBtn.addEventListener('click', goPrev);
nextBtn.addEventListener('click', goNext);
document.addEventListener('keydown', (e) => {
  if (e.key === 'ArrowLeft') { e.preventDefault(); goPrev(); }
  if (e.key === 'ArrowRight') { e.preventDefault(); goNext(); }
});

async function load(){
  if (!orderId || !sessionId) {
    statusBox.className = 'alert alert-danger mb-0 py-2 px-3';
    statusBox.textContent = 'Missing order_id/session_id';
    return;
  }
  const r = await fetch(`/api/sfm_run.php?order_id=${encodeURIComponent(orderId)}&session_id=${encodeURIComponent(sessionId)}`);
  const j = await r.json();
  if (!j.ok) {
    statusBox.className = 'alert alert-warning mb-0 py-2 px-3';
    statusBox.textContent = 'Error: ' + (j.error || 'unknown');
    return;
  }
  statusBox.className = 'alert alert-success mb-0 py-2 px-3';
  statusBox.textContent = `SfM status: ${j.run.status} | Metric: ${j.run.metric_status}`;

  keyPoints = (j.keyframe_points || []).filter(p => Number.isFinite(p.x_scaled) && Number.isFinite(p.z_scaled));
  cameraTrajectory = (j.camera_trajectory || []).filter(p => Number.isFinite(p.x_scaled) && Number.isFinite(p.z_scaled));

  if (!keyPoints.length) {
    pointMeta.textContent = 'No materialized keyframe points available.';
    drawPlan();
    return;
  }
  selectPoint(0);
}

load();
</script>
</body>
</html>
