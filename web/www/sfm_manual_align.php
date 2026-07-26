<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
auth_require_login();

$orderId = max(0, (int)($_GET['order_id'] ?? 0));
$anchorKind = (string)($_GET['anchor_kind'] ?? 'remote');
$anchorId = max(0, (int)($_GET['anchor_id'] ?? 0));
$sourceKind = (string)($_GET['source_kind'] ?? 'remote');
$sourceId = max(0, (int)($_GET['source_id'] ?? 0));

if (!in_array($anchorKind, ['remote','merge'], true) || $sourceKind !== 'remote') {
    http_response_code(400);
    exit('Manual alignment supports anchor_kind=remote|merge and source_kind=remote');
}

if ($orderId <= 0 || $anchorId <= 0 || $sourceId <= 0) {
    http_response_code(400);
    exit('Required: order_id, anchor_kind, anchor_id, source_kind, source_id');
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$queryBase = http_build_query([
    'order_id' => $orderId,
    'anchor_kind' => $anchorKind,
    'anchor_id' => $anchorId,
    'source_kind' => $sourceKind,
    'source_id' => $sourceId,
]);
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Manual SfM alignment</title>
<link href="/assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
<style>
html, body { min-height: 100%; }
body { background: #f5f6f8; }
.viewer-grid { display: grid; grid-template-columns: 1fr 1fr; gap: .75rem; }
.viewer-card { min-width: 0; }
.viewer-canvas { height: 58vh; min-height: 430px; background: #252b3f; border-radius: .4rem; overflow: hidden; position: relative; }
.viewer-status { position: absolute; inset: .75rem auto auto .75rem; z-index: 3; color: white; background: rgba(0,0,0,.55); padding: .35rem .55rem; border-radius: .25rem; }
.viewer-hint { position: absolute; inset: auto .75rem .75rem .75rem; z-index: 3; color: #fff; background: rgba(0,0,0,.62); padding: .4rem .55rem; border-radius: .25rem; pointer-events: none; }
.pairs-scroll { max-height: 310px; overflow: auto; }
.coord { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: .76rem; }
.result-box { white-space: pre-wrap; overflow-wrap: anywhere; }
@media (max-width: 950px) {
  .viewer-grid { grid-template-columns: 1fr; }
  .viewer-canvas { height: 55vh; }
}
</style>
</head>
<body>
<div class="container-fluid py-3">
  <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
    <a class="btn btn-outline-secondary btn-sm" href="/order_simple.php?id=<?= $orderId ?>#simple-video-sfm">← К заявке</a>
    <span class="badge text-bg-dark">Order <?= $orderId ?></span>
    <span class="badge text-bg-primary">Anchor <?= h($anchorKind) ?>:<?= $anchorId ?></span>
    <span class="badge text-bg-success">Source <?= h($sourceKind) ?>:<?= $sourceId ?></span>
    <span class="text-muted small">Ручной Sim(3): результат сохраняется в БД только после подтверждения</span>
  </div>

  <div class="alert alert-warning py-2 small">
    Выбирай одну и ту же физическую точку: сначала слева, потом справа. Нужно минимум 4 пары, лучше 6–12.
    Не выбирай все точки на одной линии или на одной маленькой области.
  </div>

  <div class="card mb-3">
    <div class="card-body py-2">
      <div class="d-flex flex-wrap gap-3 align-items-end">
        <label class="form-label small mb-0">
          Anchor camera
          <select class="form-select form-select-sm mt-1" id="anchorNavigationMode">
            <option value="horizon">Horizon locked</option>
            <option value="free">Free orbit 360°</option>
          </select>
        </label>
        <label class="form-label small mb-0">
          Source camera
          <select class="form-select form-select-sm mt-1" id="sourceNavigationMode">
            <option value="horizon">Horizon locked</option>
            <option value="free">Free orbit 360°</option>
          </select>
        </label>
        <div class="form-check mb-1">
          <input class="form-check-input" type="checkbox" id="syncNavigationModes" checked>
          <label class="form-check-label small" for="syncNavigationModes">Apply mode to both viewers</label>
        </div>
        <div id="manualNavigationHint" class="small text-muted mb-1">
          Camera navigation does not modify either cloud.
        </div>
      </div>
    </div>
  </div>

  <div class="viewer-grid">
    <div class="card viewer-card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <b>Anchor</b>
        <div class="d-flex gap-1">
          <button class="btn btn-sm btn-outline-secondary" id="fitAnchor">Fit</button>
          <button class="btn btn-sm btn-outline-danger" id="clearAnchorPending">Сбросить текущую</button>
        </div>
      </div>
      <div class="card-body p-2">
        <div id="anchorViewer" class="viewer-canvas">
          <div id="anchorStatus" class="viewer-status">Загрузка…</div>
          <div class="viewer-hint">Клик по точке создаёт Anchor для следующей пары.</div>
        </div>
        <div class="small mt-2">Текущая: <span id="anchorPending" class="coord text-muted">не выбрана</span></div>
      </div>
    </div>

    <div class="card viewer-card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <b>Source</b>
        <div class="d-flex gap-1">
          <button class="btn btn-sm btn-outline-secondary" id="fitSource">Fit</button>
          <button class="btn btn-sm btn-outline-danger" id="clearSourcePending">Сбросить текущую</button>
        </div>
      </div>
      <div class="card-body p-2">
        <div id="sourceViewer" class="viewer-canvas">
          <div id="sourceStatus" class="viewer-status">Загрузка…</div>
          <div class="viewer-hint">Клик по соответствующей точке создаёт Source и завершает пару.</div>
        </div>
        <div class="small mt-2">Текущая: <span id="sourcePending" class="coord text-muted">не выбрана</span></div>
      </div>
    </div>
  </div>

  <div class="row g-3 mt-1">
    <div class="col-xl-7">
      <div class="card">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
          <b>Correspondence pairs: <span id="pairsCount">0</span></b>
          <div class="d-flex flex-wrap gap-1">
            <button class="btn btn-sm btn-outline-warning" id="undoPair">Удалить последнюю</button>
            <button class="btn btn-sm btn-outline-danger" id="clearPairs">Очистить</button>
            <button class="btn btn-sm btn-outline-secondary" id="exportPairs">Скачать JSON</button>
            <button class="btn btn-sm btn-primary" id="computeBtn" disabled>Рассчитать Sim(3)</button>
          </div>
        </div>
        <div class="card-body pairs-scroll p-2">
          <table class="table table-sm table-striped align-middle mb-0">
            <thead><tr><th>#</th><th>Anchor XYZ</th><th>Source XYZ</th><th></th></tr></thead>
            <tbody id="pairsBody"><tr><td colspan="4" class="text-muted">Точки ещё не выбраны.</td></tr></tbody>
          </table>
        </div>
      </div>
    </div>

    <div class="col-xl-5">
      <div class="card h-100">
        <div class="card-header d-flex justify-content-between align-items-center">
          <b>Результат</b>
          <button class="btn btn-sm btn-outline-success" id="toggleCombined" disabled>Показать/скрыть overlay</button>
        </div>
        <div class="card-body">
          <div id="resultBox" class="small result-box text-muted">После 4+ пар будет рассчитан source → anchor transform.</div>
          <div id="saveStatus" class="small mt-2"></div>
          <div id="resultLinks" class="d-flex flex-wrap gap-2 mt-2"></div>
        </div>
      </div>
    </div>
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
import { OrbitControls } from 'three/addons/controls/OrbitControls.js';
import { TrackballControls } from 'three/addons/controls/TrackballControls.js';
import { PLYLoader } from 'three/addons/loaders/PLYLoader.js';

const csrfToken = <?= json_encode((string)($_SESSION['secCode'] ?? '')) ?>;
const params = <?= json_encode([
    'order_id' => $orderId,
    'anchor_kind' => $anchorKind,
    'anchor_id' => $anchorId,
    'source_kind' => $sourceKind,
    'source_id' => $sourceId,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;

const apiBase = '/api/sfm_manual_alignment.php?' + new URLSearchParams(params).toString();
const orderUrl = '/order_simple.php?id=' + encodeURIComponent(params.order_id) + '#simple-video-sfm';
const storageKey = 'sfm-manual-align:' + JSON.stringify(params);
const navigationStorageKey = 'sfm-manual-align:navigation-mode:v1';

function normalizeNavigationMode(mode) {
  return mode === 'free' ? 'free' : 'horizon';
}

function loadNavigationState() {
  try {
    const saved = JSON.parse(
      localStorage.getItem(navigationStorageKey) || '{}'
    );
    const anchor = normalizeNavigationMode(saved.anchor);
    const source = normalizeNavigationMode(saved.source);
    const sync = saved.sync !== false;
    return {
      anchor,
      source: sync ? anchor : source,
      sync,
    };
  } catch (error) {
    console.warn('Could not restore navigation state', error);
    return { anchor: 'horizon', source: 'horizon', sync: true };
  }
}

const navigationState = loadNavigationState();

const pairs = [];
let pendingAnchor = null;
let pendingSource = null;
let combinedOverlay = null;
let lastResult = null;

function fmtPoint(point) {
  return point ? point.map(v => Number(v).toFixed(5)).join(', ') : 'не выбрана';
}

function updatePendingUi() {
  const a = document.getElementById('anchorPending');
  const s = document.getElementById('sourcePending');
  a.textContent = fmtPoint(pendingAnchor);
  s.textContent = fmtPoint(pendingSource);
  a.className = 'coord ' + (pendingAnchor ? 'text-primary' : 'text-muted');
  s.className = 'coord ' + (pendingSource ? 'text-success' : 'text-muted');
}

function persistLocal() {
  localStorage.setItem(storageKey, JSON.stringify({ pairs }));
}

function restoreLocal() {
  try {
    const saved = JSON.parse(localStorage.getItem(storageKey) || '{}');
    if (Array.isArray(saved.pairs)) {
      for (const pair of saved.pairs) {
        if (
          Array.isArray(pair.anchor) && pair.anchor.length === 3 &&
          Array.isArray(pair.source) && pair.source.length === 3
        ) {
          pairs.push({
            anchor: pair.anchor.map(Number),
            source: pair.source.map(Number),
          });
        }
      }
    }
  } catch (error) {
    console.warn('Could not restore local draft', error);
  }
}

function renderPairs() {
  document.getElementById('pairsCount').textContent = String(pairs.length);
  document.getElementById('computeBtn').disabled = pairs.length < 4;
  const body = document.getElementById('pairsBody');
  if (!pairs.length) {
    body.innerHTML = '<tr><td colspan="4" class="text-muted">Точки ещё не выбраны.</td></tr>';
  } else {
    body.innerHTML = pairs.map((pair, index) => `
      <tr>
        <td>${index + 1}</td>
        <td class="coord">${fmtPoint(pair.anchor)}</td>
        <td class="coord">${fmtPoint(pair.source)}</td>
        <td><button class="btn btn-sm btn-outline-danger" data-delete-pair="${index}">×</button></td>
      </tr>
    `).join('');
    body.querySelectorAll('[data-delete-pair]').forEach(button => {
      button.addEventListener('click', () => {
        pairs.splice(Number(button.dataset.deletePair), 1);
        persistLocal();
        renderPairs();
        rebuildMarkers();
      });
    });
  }
}

function completePairIfReady() {
  if (!pendingAnchor || !pendingSource) return;
  pairs.push({ anchor: pendingAnchor, source: pendingSource });
  pendingAnchor = null;
  pendingSource = null;
  persistLocal();
  updatePendingUi();
  renderPairs();
  rebuildMarkers();
}

class CloudViewer {
  constructor({ elementId, statusId, side, onPick, navigationMode }) {
    this.el = document.getElementById(elementId);
    this.status = document.getElementById(statusId);
    this.side = side;
    this.onPick = onPick;
    this.scene = new THREE.Scene();
    this.scene.background = new THREE.Color(0x252b3f);
    this.camera = new THREE.PerspectiveCamera(60, 1, 0.001, 100000);
    this.camera.position.set(0, 3, 8);
    this.renderer = new THREE.WebGLRenderer({ antialias: true });
    this.renderer.outputColorSpace = THREE.SRGBColorSpace;
    this.renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
    this.el.appendChild(this.renderer.domElement);
    this.controls = null;
    this.navigationMode = 'horizon';
    this.scene.add(new THREE.AmbientLight(0xffffff, 1.2));
    this.scene.add(new THREE.AxesHelper(1.5));
    this.cloud = null;
    this.geometry = null;
    this.markers = new THREE.Group();
    this.scene.add(this.markers);
    this.radius = 1;
    this.setNavigationMode(navigationMode);
    this.raycaster = new THREE.Raycaster();
    this.raycaster.params.Points.threshold = 0.03;
    this.pointer = new THREE.Vector2();
    this.pointerDown = null;
    this.resizeObserver = new ResizeObserver(() => this.resize());
    this.resizeObserver.observe(this.el);
    this.renderer.domElement.addEventListener('pointerdown', event => {
      this.pointerDown = { x: event.clientX, y: event.clientY };
    });
    this.renderer.domElement.addEventListener('pointerup', event => {
      if (!this.pointerDown) return;
      const movement = Math.hypot(event.clientX - this.pointerDown.x, event.clientY - this.pointerDown.y);
      this.pointerDown = null;
      if (movement > 5) return;
      this.pick(event);
    });
    this.animate();
  }

  setNavigationMode(mode) {
    const nextMode = normalizeNavigationMode(mode);
    const target = this.controls?.target?.clone()
      || new THREE.Vector3();
    const minDistance = Number.isFinite(this.controls?.minDistance)
      ? this.controls.minDistance
      : 0;
    const maxDistance = Number.isFinite(this.controls?.maxDistance)
      ? this.controls.maxDistance
      : Infinity;

    if (this.controls) this.controls.dispose();
    this.navigationMode = nextMode;

    if (this.navigationMode === 'horizon') {
      this.camera.up.set(0, 1, 0);
      this.camera.lookAt(target);
    }

    if (this.navigationMode === 'free') {
      this.controls = new TrackballControls(
        this.camera,
        this.renderer.domElement
      );
      this.controls.noRoll = false;
      this.controls.rotateSpeed = 3.2;
      this.controls.zoomSpeed = 1.2;
      this.controls.panSpeed = 0.8;
      this.controls.staticMoving = false;
      this.controls.dynamicDampingFactor = 0.18;
    } else {
      this.controls = new OrbitControls(
        this.camera,
        this.renderer.domElement
      );
      this.controls.enableDamping = true;
      this.controls.screenSpacePanning = true;
    }

    this.controls.target.copy(target);
    this.controls.minDistance = minDistance;
    this.controls.maxDistance = maxDistance;
    if (typeof this.controls.handleResize === 'function') {
      this.controls.handleResize();
    }
    this.controls.update();
  }

  async load(url) {
    this.status.textContent = 'Загрузка PLY…';
    const loader = new PLYLoader();
    this.geometry = await loader.loadAsync(url);
    this.geometry.computeBoundingBox();
    this.geometry.computeBoundingSphere();
    const hasColor = this.geometry.hasAttribute('color');
    const material = new THREE.PointsMaterial({
      size: 2.0,
      sizeAttenuation: false,
      vertexColors: hasColor,
      color: hasColor ? 0xffffff : (this.side === 'anchor' ? 0x66aaff : 0x55dd88),
    });
    this.cloud = new THREE.Points(this.geometry, material);
    this.scene.add(this.cloud);
    this.radius = Math.max(this.geometry.boundingSphere?.radius || 1, 0.001);
    this.raycaster.params.Points.threshold = this.radius * 0.004;
    this.status.textContent = `${this.geometry.getAttribute('position').count.toLocaleString()} points`;
    this.fit();
  }

  resize() {
    const width = Math.max(this.el.clientWidth, 1);
    const height = Math.max(this.el.clientHeight, 1);
    this.renderer.setSize(width, height, false);
    this.camera.aspect = width / height;
    this.camera.updateProjectionMatrix();
    if (typeof this.controls?.handleResize === 'function') {
      this.controls.handleResize();
    }
  }

  fit(extraObject = null) {
    const box = new THREE.Box3();
    let has = false;
    for (const object of [this.cloud, extraObject]) {
      if (!object || object.visible === false) continue;
      const objectBox = new THREE.Box3().setFromObject(object);
      if (!objectBox.isEmpty()) {
        box.union(objectBox);
        has = true;
      }
    }
    if (!has) return;
    const center = box.getCenter(new THREE.Vector3());
    const size = box.getSize(new THREE.Vector3());
    const radius = Math.max(size.length() * 0.5, 0.01);
    this.camera.near = Math.max(radius / 10000, 0.0001);
    this.camera.far = Math.max(radius * 100, 1000);
    this.camera.updateProjectionMatrix();
    this.controls.target.copy(center);
    if (this.navigationMode === 'horizon') {
      this.camera.up.set(0, 1, 0);
    }
    this.camera.position.set(
      center.x + radius * 1.25,
      center.y + radius * 0.8,
      center.z + radius * 1.25
    );
    this.camera.lookAt(center);
    this.controls.minDistance = radius * 0.01;
    this.controls.maxDistance = radius * 100;
    this.controls.update();
  }

  pick(event) {
    if (!this.cloud) return;
    const rect = this.renderer.domElement.getBoundingClientRect();
    this.pointer.x = ((event.clientX - rect.left) / rect.width) * 2 - 1;
    this.pointer.y = -((event.clientY - rect.top) / rect.height) * 2 + 1;
    this.raycaster.setFromCamera(this.pointer, this.camera);
    const hit = this.raycaster.intersectObject(this.cloud, false)[0];
    if (!hit || !Number.isInteger(hit.index)) {
      this.status.textContent = 'Точка не найдена — приблизь облако и кликни по плотной области';
      return;
    }
    const local = new THREE.Vector3().fromBufferAttribute(this.geometry.getAttribute('position'), hit.index);
    const world = this.cloud.localToWorld(local.clone());
    this.onPick([world.x, world.y, world.z]);
  }

  setMarkers(points, color) {
    this.markers.clear();
    const markerRadius = Math.max(this.radius * 0.006, 0.005);
    points.forEach(point => {
      const marker = new THREE.Mesh(
        new THREE.SphereGeometry(markerRadius, 12, 12),
        new THREE.MeshBasicMaterial({ color, depthTest: false })
      );
      marker.position.fromArray(point);
      marker.renderOrder = 10;
      this.markers.add(marker);
    });
  }

  animate() {
    requestAnimationFrame(() => this.animate());
    this.controls.update();
    this.renderer.render(this.scene, this.camera);
  }
}

const anchorViewer = new CloudViewer({
  elementId: 'anchorViewer',
  statusId: 'anchorStatus',
  side: 'anchor',
  navigationMode: navigationState.anchor,
  onPick: point => {
    pendingAnchor = point;
    updatePendingUi();
    completePairIfReady();
  },
});

const sourceViewer = new CloudViewer({
  elementId: 'sourceViewer',
  statusId: 'sourceStatus',
  side: 'source',
  navigationMode: navigationState.source,
  onPick: point => {
    pendingSource = point;
    updatePendingUi();
    completePairIfReady();
  },
});

const anchorNavigationSelect = document.getElementById(
  'anchorNavigationMode'
);
const sourceNavigationSelect = document.getElementById(
  'sourceNavigationMode'
);
const syncNavigationModes = document.getElementById(
  'syncNavigationModes'
);
const manualNavigationHint = document.getElementById(
  'manualNavigationHint'
);

anchorNavigationSelect.value = navigationState.anchor;
sourceNavigationSelect.value = navigationState.source;
syncNavigationModes.checked = navigationState.sync;

function persistNavigationState() {
  try {
    localStorage.setItem(
      navigationStorageKey,
      JSON.stringify({
        anchor: anchorViewer.navigationMode,
        source: sourceViewer.navigationMode,
        sync: syncNavigationModes.checked,
      })
    );
  } catch (error) {
    console.warn('Navigation mode persistence failed', error);
  }
}

function updateNavigationHint() {
  const same = anchorViewer.navigationMode
    === sourceViewer.navigationMode;
  manualNavigationHint.textContent = same
    ? (
        anchorViewer.navigationMode === 'free'
          ? 'Both viewers use free camera roll. Cloud transforms are unchanged.'
          : 'Both viewers keep world up locked.'
      )
    : 'Anchor and Source use independent camera-navigation modes.';
}

function applyNavigationMode(side, mode) {
  const nextMode = normalizeNavigationMode(mode);
  const sync = syncNavigationModes.checked;

  if (side === 'anchor' || sync) {
    anchorViewer.setNavigationMode(nextMode);
    anchorNavigationSelect.value = nextMode;
  }
  if (side === 'source' || sync) {
    sourceViewer.setNavigationMode(nextMode);
    sourceNavigationSelect.value = nextMode;
  }

  persistNavigationState();
  updateNavigationHint();
}

anchorNavigationSelect.addEventListener(
  'change',
  event => applyNavigationMode('anchor', event.target.value)
);
sourceNavigationSelect.addEventListener(
  'change',
  event => applyNavigationMode('source', event.target.value)
);
syncNavigationModes.addEventListener('change', () => {
  if (syncNavigationModes.checked) {
    sourceViewer.setNavigationMode(anchorViewer.navigationMode);
    sourceNavigationSelect.value = anchorViewer.navigationMode;
  }
  persistNavigationState();
  updateNavigationHint();
});
updateNavigationHint();

function rebuildMarkers() {
  const anchorPoints = pairs.map(pair => pair.anchor);
  const sourcePoints = pairs.map(pair => pair.source);
  if (pendingAnchor) anchorPoints.push(pendingAnchor);
  if (pendingSource) sourcePoints.push(pendingSource);
  anchorViewer.setMarkers(anchorPoints, 0xffcc00);
  sourceViewer.setMarkers(sourcePoints, 0x00ff88);
}

function setPending(side, value) {
  if (side === 'anchor') pendingAnchor = value;
  else pendingSource = value;
  updatePendingUi();
  rebuildMarkers();
}

document.getElementById('fitAnchor').addEventListener('click', () => anchorViewer.fit(combinedOverlay));
document.getElementById('fitSource').addEventListener('click', () => sourceViewer.fit());
document.getElementById('clearAnchorPending').addEventListener('click', () => setPending('anchor', null));
document.getElementById('clearSourcePending').addEventListener('click', () => setPending('source', null));

document.getElementById('undoPair').addEventListener('click', () => {
  pairs.pop();
  persistLocal();
  renderPairs();
  rebuildMarkers();
});

document.getElementById('clearPairs').addEventListener('click', () => {
  if (!confirm('Удалить все correspondence pairs?')) return;
  pairs.length = 0;
  pendingAnchor = null;
  pendingSource = null;
  persistLocal();
  updatePendingUi();
  renderPairs();
  rebuildMarkers();
});

document.getElementById('exportPairs').addEventListener('click', () => {
  const blob = new Blob([JSON.stringify({ ...params, pairs }, null, 2)], { type: 'application/json' });
  const link = document.createElement('a');
  link.href = URL.createObjectURL(blob);
  link.download = `manual_correspondences_${params.order_id}_${params.anchor_id}_${params.source_id}.json`;
  link.click();
  setTimeout(() => URL.revokeObjectURL(link.href), 1000);
});

function matrixFromRows(rows) {
  const m = new THREE.Matrix4();
  m.set(
    rows[0][0], rows[0][1], rows[0][2], rows[0][3],
    rows[1][0], rows[1][1], rows[1][2], rows[1][3],
    rows[2][0], rows[2][1], rows[2][2], rows[2][3],
    rows[3][0], rows[3][1], rows[3][2], rows[3][3],
  );
  return m;
}

function showCombinedPreview(result) {
  if (combinedOverlay) {
    anchorViewer.scene.remove(combinedOverlay);
    combinedOverlay.material?.dispose();
    combinedOverlay = null;
  }
  const material = new THREE.PointsMaterial({
    size: 2.25,
    sizeAttenuation: false,
    vertexColors: sourceViewer.geometry.hasAttribute('color'),
    color: sourceViewer.geometry.hasAttribute('color') ? 0xffffff : 0x00ff88,
    transparent: true,
    opacity: 0.72,
    depthWrite: false,
  });
  combinedOverlay = new THREE.Points(sourceViewer.geometry, material);
  combinedOverlay.matrixAutoUpdate = false;
  combinedOverlay.matrix.copy(matrixFromRows(result.matrix4));
  combinedOverlay.visible = true;
  anchorViewer.scene.add(combinedOverlay);
  anchorViewer.fit(combinedOverlay);
  document.getElementById('toggleCombined').disabled = false;
}



function renderSavedMerge(saved) {
  if (!saved || !saved.id) return;
  const saveStatus = document.getElementById('saveStatus');
  const links = document.getElementById('resultLinks');
  const mergeId = Number(saved.id);
  saveStatus.className = 'small text-success mt-2';
  saveStatus.innerHTML = `<span class="badge bg-success">Сохранено</span> merge_id: <strong>${mergeId}</strong> · принятая ручная сборка`;
  links.innerHTML = `
    <a class="btn btn-sm btn-success" href="/sfm_3d_viewer.php?order_id=${encodeURIComponent(params.order_id)}&merge_id=${mergeId}&artifact=dense" target="_blank">Открыть в обычном 3D viewer</a>
    <a class="btn btn-sm btn-outline-secondary" href="${orderUrl}">Вернуться к заявке</a>
    <a class="btn btn-sm btn-outline-secondary" href="/api/sfm_generated_merge_file.php?merge_id=${mergeId}&file=result" target="_blank">Result JSON</a>
  `;
  const resultBox = document.getElementById('resultBox');
  resultBox.className = 'small result-box text-success';
  resultBox.textContent = 'Этот результат уже сохранён как принятая ручная сборка. Повторный finalize не требуется.';
}

async function loadMetaState() {
  try {
    const response = await fetch(apiBase + '&action=meta');
    const meta = await response.json();
    if (meta && meta.saved_merge) renderSavedMerge(meta.saved_merge);
  } catch (error) {
    console.warn('Cannot load manual alignment meta', error);
  }
}

async function finalizeManualAlignment() {
  if (!confirm('Сохранить текущий результат как принятую ручную сборку? Он появится в списке моделей заявки.')) return;
  const saveStatus = document.getElementById('saveStatus');
  const finalizeButton = document.getElementById('finalizeBtn');
  if (finalizeButton) finalizeButton.disabled = true;
  saveStatus.className = 'small text-primary mt-2';
  saveStatus.textContent = 'Сохранение ручной сборки…';
  try {
    const response = await fetch(apiBase + '&action=finalize', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken }, body: '{}'  });
    const result = await response.json();
    if (!response.ok || !result.ok) throw new Error(result.error || `HTTP ${response.status}`);
    saveStatus.className = 'small text-success mt-2';
    saveStatus.innerHTML = `<span class="badge bg-success">Сохранено</span> merge_id: <strong>${result.merge_id}</strong>${result.already_saved ? ' · уже было сохранено' : ''}`;
    if (finalizeButton) finalizeButton.classList.add('disabled');
    const links = document.getElementById('resultLinks');
    links.insertAdjacentHTML('beforeend', `
      <a class="btn btn-sm btn-success" href="${result.viewer_url}" target="_blank">Открыть в обычном 3D viewer</a>
      <a class="btn btn-sm btn-outline-secondary" href="${result.order_url}">Вернуться к заявке</a>
    `);
  } catch (error) {
    saveStatus.className = 'small text-danger mt-2';
    saveStatus.textContent = String(error.message || error);
    if (finalizeButton) finalizeButton.disabled = false;
  }
}

document.getElementById('toggleCombined').addEventListener('click', () => {
  if (!combinedOverlay) return;
  combinedOverlay.visible = !combinedOverlay.visible;
  anchorViewer.fit(combinedOverlay.visible ? combinedOverlay : null);
});

document.getElementById('computeBtn').addEventListener('click', async () => {
  const button = document.getElementById('computeBtn');
  const resultBox = document.getElementById('resultBox');
  const links = document.getElementById('resultLinks');
  button.disabled = true;
  resultBox.className = 'small result-box text-primary';
  resultBox.textContent = 'Расчёт Sim(3) и создание PLY…';
  links.innerHTML = '';

  try {
    const response = await fetch(apiBase + '&action=compute', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
      body: JSON.stringify({ pairs }),
    });
    const result = await response.json();
    if (!response.ok || !result.ok) {
      throw new Error(result.error || `HTTP ${response.status}`);
    }
    lastResult = result;
    showCombinedPreview(result);
    resultBox.className = 'small result-box text-success';
    resultBox.textContent =
      `scale: ${Number(result.scale).toFixed(9)}\n` +
      `RMS: ${Number(result.rms).toFixed(8)}\n` +
      `median: ${Number(result.median).toFixed(8)}\n` +
      `max: ${Number(result.max).toFixed(8)}\n` +
      `pairs: ${result.pairs_count}\n` +
      `anchor points: ${Number(result.anchor_points).toLocaleString()}\n` +
      `source points: ${Number(result.source_points).toLocaleString()}\n` +
      `merged points: ${Number(result.merged_points).toLocaleString()}\n` +
      `warnings: ${(result.warnings || []).join('; ') || 'none'}`;
    links.innerHTML = `
      <a class="btn btn-sm btn-outline-primary" href="${result.aligned_url}">Aligned source PLY</a>
      <a class="btn btn-sm btn-outline-success" href="${result.merged_url}">Merged draft PLY</a>
      <a class="btn btn-sm btn-outline-secondary" href="${result.result_url}">Result JSON</a>
      <button class="btn btn-sm btn-success" id="finalizeBtn">Сохранить как ручную сборку</button>
      <a class="btn btn-sm btn-outline-secondary" href="${orderUrl}">Вернуться к заявке</a>
    `;
    document.getElementById('finalizeBtn').addEventListener('click', finalizeManualAlignment);
  } catch (error) {
    resultBox.className = 'small result-box text-danger';
    resultBox.textContent = String(error.message || error);
  } finally {
    button.disabled = pairs.length < 4;
  }
});

loadMetaState();
restoreLocal();
renderPairs();
updatePendingUi();
rebuildMarkers();

await Promise.all([
  anchorViewer.load(apiBase + '&action=file&file=anchor'),
  sourceViewer.load(apiBase + '&action=file&file=source'),
]);
</script>
</body>
</html>