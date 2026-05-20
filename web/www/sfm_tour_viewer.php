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
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/pannellum@2.5.6/build/pannellum.css">
  <style>
    #planSvg { width: 100%; height: 420px; border: 1px solid #ddd; border-radius: 8px; background: #fafafa; }
    .viewer-shell { background: #111; border-radius: 10px; border: 1px solid #222; min-height: 320px; }
    #panoViewer { width: 100%; height: 65vh; min-height: 320px; display: none; border-radius: 10px; overflow: hidden; }
    #mainPreview { width: 100%; max-height: 65vh; object-fit: contain; display: none; }
    #previewError { display: none; min-height: 220px; }
    .traj-line { fill: none; stroke: #6c757d; stroke-width: 1.2; opacity: .85; }
    .key-dot { fill: #0d6efd; stroke: #fff; stroke-width: 1; cursor: pointer; }
    .key-dot.break { fill: #fd7e14; stroke: #842029; }
    .key-dot.active { fill: #dc3545; stroke: #111; stroke-width: 2; }
    .thumb-btn.active { background: #0d6efd; color: #fff; border-color: #0d6efd; }
    #thumbStrip { white-space: nowrap; }
    .sfm-hotspot-dot { width: 22px; height: 22px; border-radius: 50%; background: rgba(13, 110, 253, 0.95); border: 2px solid #fff; box-shadow: 0 0 8px rgba(0,0,0,.45); cursor: pointer; }
    .sfm-hotspot-label { position: absolute; left: 26px; top: -4px; white-space: nowrap; background: rgba(0,0,0,.7); color: #fff; padding: 2px 6px; border-radius: 4px; font-size: 12px; }
  </style>
</head>
<body class="p-3">
<div class="container-fluid">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div class="d-flex gap-2">
      <a class="btn btn-outline-secondary btn-sm" href="/order.php?id=<?php echo $orderId; ?>">← Back to order</a>
      <a class="btn btn-outline-success btn-sm" href="/sfm_viewer.php?order_id=<?php echo $orderId; ?>&session_id=<?php echo $sessionId; ?>">Open diagnostics</a>
      <a class="btn btn-outline-primary btn-sm" href="/sfm_3d_viewer.php?order_id=<?php echo $orderId; ?>&session_id=<?php echo $sessionId; ?>">Open 3D model</a>
    </div>
    <div id="statusBox" class="alert alert-secondary mb-0 py-2 px-3">Loading…</div>
  </div>

  <div class="row g-3">
    <div class="col-12 col-lg-8">
      <div class="viewer-shell d-flex flex-column justify-content-center align-items-center p-2">
        <div id="panoViewer"></div>
        <img id="mainPreview" alt="Selected keyframe" src="">
        <div id="previewError" class="text-danger fw-semibold text-center py-4">Keyframe image failed to load</div>
      </div>
      <div class="d-flex justify-content-between align-items-center mt-2 small">
        <div id="keyframeCounter" class="fw-semibold">Keyframe - / -</div>
        <div class="d-flex align-items-center gap-2">
          <span id="previewTypeBadge" class="badge text-bg-secondary">-</span>
          <div id="keyframeName" class="text-muted"></div>
        </div>
      </div>
      <div class="card mt-2">
        <div class="card-body small" id="pointMeta">No point selected.</div>
      </div>
    </div>
    <div class="col-12 col-lg-4">
      <svg id="planSvg" viewBox="0 0 1000 420" preserveAspectRatio="xMidYMid meet"></svg>
      <div class="small mt-2">
        <span class="badge text-bg-secondary me-1">gray</span> camera trajectory
        <span class="badge text-bg-primary ms-2 me-1">blue</span> keyframe
        <span class="badge text-bg-warning ms-2 me-1">orange</span> segment break
        <span class="badge text-bg-danger ms-2 me-1">red</span> selected
      </div>
      <div id="segmentWarn" class="alert alert-warning small mt-2 d-none">Segment break / possible jump</div>
    </div>
  </div>

  <div class="d-flex flex-wrap justify-content-between align-items-center mt-3 gap-2">
    <div>
      <button id="prevBtn" class="btn btn-primary btn-sm">Prev</button>
      <button id="nextBtn" class="btn btn-primary btn-sm">Next</button>
    </div>

  </div>
    <div class="small text-muted">Use ArrowLeft / ArrowRight / Home / End for navigation.</div>
  </div>
  <div class="card mt-2">
    <div class="card-body py-2">
      <div id="thumbStrip" class="d-flex gap-2 overflow-auto"></div>
    </div></div>

<script src="https://cdn.jsdelivr.net/npm/pannellum@2.5.6/build/pannellum.js"></script>
<script>
const orderId = <?php echo json_encode($orderId); ?>;
const sessionId = <?php echo json_encode($sessionId); ?>;
const statusBox = document.getElementById('statusBox');
const planSvg = document.getElementById('planSvg');
const panoViewer = document.getElementById('panoViewer');
const mainPreview = document.getElementById('mainPreview');
const pointMeta = document.getElementById('pointMeta');
const segmentWarn = document.getElementById('segmentWarn');
const keyframeCounter = document.getElementById('keyframeCounter');
const keyframeName = document.getElementById('keyframeName');
const previewTypeBadge = document.getElementById('previewTypeBadge');
const previewError = document.getElementById('previewError');
const thumbStrip = document.getElementById('thumbStrip');
const prevBtn = document.getElementById('prevBtn');
const nextBtn = document.getElementById('nextBtn');

let keyPoints = [];
let cameraTrajectory = [];
let selectedIdx = 0;
let pointEls = [];
let thumbEls = [];
let panoInstance = null;

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

function preloadNeighbors(){
  const nextUrl = keyPoints[selectedIdx + 1]?.preview_url;
  const prevUrl = keyPoints[selectedIdx - 1]?.preview_url;
  if (nextUrl) { const im = new Image(); im.src = nextUrl; }
  if (prevUrl) { const im = new Image(); im.src = prevUrl; }
}

function buildThumbStrip(){
  thumbStrip.innerHTML = '';
  thumbEls = [];
  keyPoints.forEach((pt, idx) => {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'btn btn-outline-secondary btn-sm thumb-btn';
    btn.textContent = String(pt.keyframe_index ?? (idx + 1));
    btn.addEventListener('click', () => selectPoint(idx));
    thumbStrip.appendChild(btn);
    thumbEls.push(btn);
  });
}

function destroyPano() {
  if (panoInstance && typeof panoInstance.destroy === 'function') {
    panoInstance.destroy();
  }
  panoInstance = null;
  panoViewer.innerHTML = '';
}

function showImageFallback(url) {
  destroyPano();
  panoViewer.style.display = 'none';
  mainPreview.style.display = 'block';
  previewError.style.display = 'none';
  mainPreview.src = url || '';
}


function hotspotTooltip(hotSpotDiv, args) {
  hotSpotDiv.classList.add('sfm-hotspot-dot');
  const span = document.createElement('span');
  span.textContent = args.label || '';
  span.className = 'sfm-hotspot-label';
  hotSpotDiv.appendChild(span);
}

function yawToPoint(cur, target) {
  if (!cur || !target) return 0;
  const dx = target.x_scaled - cur.x_scaled;
  const dz = target.z_scaled - cur.z_scaled;
  return Math.atan2(dx, dz) * 180 / Math.PI;
}

function buildHotspots(pointIdx) {
  const spots = [];
  const cur = keyPoints[pointIdx];
  if (!cur) return spots;

  if (pointIdx > 0) {
    const prev = keyPoints[pointIdx - 1];
    spots.push({
      pitch: 0,
      yaw: yawToPoint(cur, prev),
      type: 'custom',
      text: '← Previous',
      cssClass: 'sfm-hotspot sfm-hotspot-prev',
      createTooltipFunc: hotspotTooltip,
      createTooltipArgs: { label: '← Previous' },
      clickHandlerFunc: () => selectPoint(pointIdx - 1)
    });
  }

  if (pointIdx < keyPoints.length - 1) {
    const next = keyPoints[pointIdx + 1];
    spots.push({
      pitch: 0,
      yaw: yawToPoint(cur, next),
      type: 'custom',
      text: 'Next →',
      cssClass: 'sfm-hotspot sfm-hotspot-next',
      createTooltipFunc: hotspotTooltip,
      createTooltipArgs: { label: 'Next →' },
      clickHandlerFunc: () => selectPoint(pointIdx + 1)
    });
  }

  return spots;
}

function showPano(url, rawFallbackUrl, pointIdx) {
  mainPreview.style.display = 'none';
  previewError.style.display = 'none';
  panoViewer.style.display = 'block';
  destroyPano();

  try {
    const hotSpots = buildHotspots(pointIdx);
    panoInstance = pannellum.viewer('panoViewer', {
      type: 'equirectangular',
      panorama: url,
      autoLoad: true,
      showControls: true,
      compass: false,
      hfov: 110,
      pitch: 0,
      yaw: 0,
      hotSpots
    });
  } catch (e) {
    showImageFallback(rawFallbackUrl || url);
  }
}

function selectPoint(idx){
  if (!keyPoints.length) return;
  selectedIdx = Math.max(0, Math.min(keyPoints.length - 1, idx));
  const p = keyPoints[selectedIdx];
  if (!(p.preview_url || p.raw_preview_url)) {
    destroyPano();
    panoViewer.style.display = 'none';
    mainPreview.style.display = 'none';
    previewError.style.display = 'block';
  } else if (p.preview_type === 'equirectangular' && p.preview_url) {
    showPano(p.preview_url, p.raw_preview_url, selectedIdx);
  } else {
    showImageFallback(p.preview_url || p.raw_preview_url || '');
  }
  pointMeta.innerHTML = `keyframe_index=${p.keyframe_index ?? '-'}<br>keyframe_name=${p.keyframe_name ?? '-'}<br>nearest_frame_name=${p.nearest_frame_name ?? '-'}<br>x_scaled=${fmt(p.x_scaled)} · y_scaled=${fmt(p.y_scaled)} · z_scaled=${fmt(p.z_scaled)}<br>segment_break=${p.segment_break ? 'true' : 'false'}`;
  keyframeCounter.textContent = `Keyframe ${selectedIdx + 1} / ${keyPoints.length}`;
  keyframeName.textContent = p.keyframe_name ?? '';
  if (p.preview_type === 'equirectangular') {
    previewTypeBadge.className = 'badge text-bg-success';
    previewTypeBadge.textContent = '360 stitched keyframe';
  } else if (p.preview_type === 'perspective') {
    previewTypeBadge.className = 'badge text-bg-primary';
    previewTypeBadge.textContent = 'phone video keyframe';
  } else {
    previewTypeBadge.className = 'badge text-bg-warning';
    previewTypeBadge.textContent = 'raw fisheye fallback';
  }
  thumbEls.forEach((el, i) => el.classList.toggle('active', i === selectedIdx));
  segmentWarn.classList.toggle('d-none', !p.segment_break);
  preloadNeighbors();
  drawPlan();
}

function goPrev(){ selectPoint(selectedIdx - 1); }
function goNext(){ selectPoint(selectedIdx + 1); }

prevBtn.addEventListener('click', goPrev);
nextBtn.addEventListener('click', goNext);
document.addEventListener('keydown', (e) => {
  if (e.key === 'ArrowLeft') { e.preventDefault(); goPrev(); }
  if (e.key === 'ArrowRight') { e.preventDefault(); goNext(); }

  if (e.key === 'Home') { e.preventDefault(); selectPoint(0); }
  if (e.key === 'End') { e.preventDefault(); selectPoint(keyPoints.length - 1); }
});

mainPreview.addEventListener('load', () => {
  previewError.style.display = 'none';
  mainPreview.style.display = 'block';
});
mainPreview.addEventListener('error', () => {
  mainPreview.style.display = 'none';
  previewError.style.display = 'block';
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
  buildThumbStrip();
  selectPoint(0);
}

load();
</script>
</body>
</html>
