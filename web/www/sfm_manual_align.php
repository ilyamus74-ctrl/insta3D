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

window.sfmManualCloudsReady = new Promise((resolve, reject) => {
  window.sfmManualCloudsResolve = resolve;
  window.sfmManualCloudsReject = reject;
});

try {
  await Promise.all([
    anchorViewer.load(apiBase + '&action=file&file=anchor'),
    sourceViewer.load(apiBase + '&action=file&file=source'),
  ]);

  const readyClouds = { anchorViewer, sourceViewer };
  window.sfmManualClouds = readyClouds;
  window.sfmManualCloudsResolve(readyClouds);
} catch (error) {
  window.sfmManualCloudsReject(error);
  throw error;
}
</script>

<script type="module">
import * as THREE from 'three';
import { TrackballControls } from 'three/addons/controls/TrackballControls.js';
import { TransformControls } from 'three/addons/controls/TransformControls.js';

let clouds = window.sfmManualClouds;
let visualStartupError = null;

try {
  clouds = clouds || await window.sfmManualCloudsReady;
} catch (error) {
  visualStartupError = error;
  console.error('Visual alignment UI startup failed', error);
}

if (!clouds?.anchorViewer?.geometry || !clouds?.sourceViewer?.geometry) {
  const errorCard = document.createElement('div');
  errorCard.id = 'visualAlignmentError';
  errorCard.className = 'alert alert-danger mt-3';
  errorCard.textContent =
    'Visual alignment не запущен: '
    + String(
      visualStartupError?.message
      || 'Anchor или Moving-source geometry не загрузилась.'
    );
  document.querySelector('.viewer-grid')
    ?.insertAdjacentElement('afterend', errorCard);
} else {
  const query = new URLSearchParams(window.location.search);
  const visualStorageKey = 'sfm-manual-visual:' + JSON.stringify({
    order_id: Number(query.get('order_id') || 0),
    anchor_kind: query.get('anchor_kind') || 'remote',
    anchor_id: Number(query.get('anchor_id') || 0),
    source_kind: query.get('source_kind') || 'remote',
    source_id: Number(query.get('source_id') || 0),
  });

  const style = document.createElement('style');
  style.textContent = `
    .combined-visual-canvas {
      height: 72vh;
      min-height: 540px;
      background: #202638;
      border-radius: .4rem;
      overflow: hidden;
      position: relative;
    }
    .visual-transform-grid input { min-width: 0; }
  `;
  document.head.appendChild(style);

  const card = document.createElement('div');
  card.id = 'visualAlignmentCard';
  card.className = 'card mt-3';
  card.innerHTML = `
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
      <div>
        <b>Visual alignment: Anchor + Moving source</b>
        <div class="small text-muted">
          Anchor фиксирован. Moving source можно двигать, вращать и равномерно масштабировать.
        </div>
      </div>
      <div class="d-flex gap-1">
        <button class="btn btn-sm btn-outline-secondary" type="button" id="visualFitBoth">Fit both</button>
        <button class="btn btn-sm btn-outline-danger" type="button" id="visualResetTransform">Reset transform</button>
      </div>
    </div>
    <div class="card-body">
      <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
        <div class="btn-group btn-group-sm">
          <button class="btn btn-outline-primary active" type="button" data-visual-mode="translate">Move (W)</button>
          <button class="btn btn-outline-primary" type="button" data-visual-mode="rotate">Rotate (E)</button>
          <button class="btn btn-outline-primary" type="button" data-visual-mode="scale">Scale (R)</button>
        </div>
        <button class="btn btn-sm btn-outline-secondary" type="button" id="visualSpaceToggle">World axes (Q)</button>
        <label class="small mb-0">Moving opacity
          <input type="range" class="form-range d-inline-block align-middle ms-1" id="visualOpacity" min="0.1" max="1" step="0.05" value="0.72" style="width:150px">
        </label>
        <label class="small mb-0">Anchor points
          <input type="range" class="form-range d-inline-block align-middle ms-1" id="visualAnchorPointSize" min="0.5" max="6" step="0.25" value="2" style="width:120px">
        </label>
        <label class="small mb-0">Moving points
          <input type="range" class="form-range d-inline-block align-middle ms-1" id="visualMovingPointSize" min="0.5" max="6" step="0.25" value="2.5" style="width:120px">
        </label>
      </div>

      <div id="combinedVisualViewer" class="combined-visual-canvas">
        <div id="combinedVisualStatus" class="viewer-status">Подготовка общего окна…</div>
        <div class="viewer-hint">
          Манипулятор изменяет Moving source. Мышь вне манипулятора управляет камерой.
        </div>
      </div>

      <div class="row g-2 visual-transform-grid mt-2">
        <div class="col-6 col-md-3 col-xl-1"><label class="form-label small">X<input class="form-control form-control-sm" id="visualTx" type="number" step="any" value="0"></label></div>
        <div class="col-6 col-md-3 col-xl-1"><label class="form-label small">Y<input class="form-control form-control-sm" id="visualTy" type="number" step="any" value="0"></label></div>
        <div class="col-6 col-md-3 col-xl-1"><label class="form-label small">Z<input class="form-control form-control-sm" id="visualTz" type="number" step="any" value="0"></label></div>
        <div class="col-6 col-md-3 col-xl-1"><label class="form-label small">Rot X°<input class="form-control form-control-sm" id="visualRx" type="number" step="0.1" value="0"></label></div>
        <div class="col-6 col-md-3 col-xl-1"><label class="form-label small">Rot Y°<input class="form-control form-control-sm" id="visualRy" type="number" step="0.1" value="0"></label></div>
        <div class="col-6 col-md-3 col-xl-1"><label class="form-label small">Rot Z°<input class="form-control form-control-sm" id="visualRz" type="number" step="0.1" value="0"></label></div>
        <div class="col-6 col-md-3 col-xl-1"><label class="form-label small">Scale<input class="form-control form-control-sm" id="visualScale" type="number" min="0.0001" max="10000" step="0.001" value="1"></label></div>
        <div class="col-12 col-xl-5 d-flex flex-wrap gap-2 align-items-end pb-3">
          <button class="btn btn-outline-secondary" type="button" id="visualCopyMatrix">Copy matrix4</button>
          <button class="btn btn-outline-primary" type="button" id="visualExportTransform">Export transform JSON</button>
        </div>
      </div>

      <div id="visualTransformStatus" class="small text-muted">
        Transform хранится локально. Server preview/finalize будет подключён следующим patch.
      </div>
    </div>
  `;
  document.querySelector('.viewer-grid').insertAdjacentElement('afterend', card);

  class VisualAlignmentViewer {
    constructor(anchorGeometry, sourceGeometry) {
      this.el = document.getElementById('combinedVisualViewer');
      this.status = document.getElementById('combinedVisualStatus');
      this.scene = new THREE.Scene();
      this.scene.background = new THREE.Color(0x202638);
      this.camera = new THREE.PerspectiveCamera(60, 1, 0.001, 100000);
      this.camera.position.set(0, 3, 8);

      this.renderer = new THREE.WebGLRenderer({ antialias: true });
      this.renderer.outputColorSpace = THREE.SRGBColorSpace;
      this.renderer.setPixelRatio(Math.min(devicePixelRatio || 1, 2));
      this.el.appendChild(this.renderer.domElement);

      this.cameraControls = new TrackballControls(this.camera, this.renderer.domElement);
      this.cameraControls.noRoll = false;
      this.cameraControls.rotateSpeed = 3.0;
      this.cameraControls.zoomSpeed = 1.2;
      this.cameraControls.panSpeed = 0.8;
      this.cameraControls.staticMoving = false;
      this.cameraControls.dynamicDampingFactor = 0.18;

      this.transformControls = new TransformControls(this.camera, this.renderer.domElement);
      this.transformControls.setMode('translate');
      this.transformControls.setSpace('world');
      this.scene.add(this.transformControls);

      this.scene.add(new THREE.AmbientLight(0xffffff, 1.25));
      this.scene.add(new THREE.AxesHelper(1.5));
      this.grid = new THREE.GridHelper(20, 40);
      this.scene.add(this.grid);

      this.anchorObject = new THREE.Points(
        anchorGeometry,
        new THREE.PointsMaterial({
          size: 2,
          sizeAttenuation: false,
          vertexColors: anchorGeometry.hasAttribute('color'),
          color: anchorGeometry.hasAttribute('color') ? 0xffffff : 0x77aaff,
        })
      );
      this.movingObject = new THREE.Points(
        sourceGeometry,
        new THREE.PointsMaterial({
          size: 2.5,
          sizeAttenuation: false,
          vertexColors: sourceGeometry.hasAttribute('color'),
          color: sourceGeometry.hasAttribute('color') ? 0x88ff99 : 0x44ff88,
          transparent: true,
          opacity: 0.72,
          depthWrite: false,
        })
      );
      this.scene.add(this.anchorObject);
      this.scene.add(this.movingObject);
      this.transformControls.attach(this.movingObject);

      this.scaleBase = 1;
      this.scaleGuard = false;
      this.transformControls.addEventListener('dragging-changed', e => {
        this.cameraControls.enabled = !e.value;
      });
      this.transformControls.addEventListener('mouseDown', () => {
        this.scaleBase = this.movingObject.scale.x;
      });
      this.transformControls.addEventListener('objectChange', () => {
        this.enforceUniformScale();
        this.syncInputs();
        this.persist();
      });

      new ResizeObserver(() => this.resize()).observe(this.el);
      this.status.textContent =
        `${anchorGeometry.getAttribute('position').count.toLocaleString()} anchor + `
        + `${sourceGeometry.getAttribute('position').count.toLocaleString()} moving points`;
      this.restore();
      this.fit();
      this.syncInputs();
      this.animate();
    }

    enforceUniformScale() {
      if (this.scaleGuard || this.transformControls.getMode() !== 'scale') return;
      const values = this.movingObject.scale.toArray();
      let selected = values[0];
      let delta = Math.abs(values[0] - this.scaleBase);
      for (const value of values.slice(1)) {
        const current = Math.abs(value - this.scaleBase);
        if (current > delta) {
          selected = value;
          delta = current;
        }
      }
      const uniform = THREE.MathUtils.clamp(Math.abs(selected), 0.0001, 10000);
      this.scaleGuard = true;
      this.movingObject.scale.setScalar(uniform);
      this.scaleGuard = false;
    }

    setMode(mode) {
      this.transformControls.setMode(mode);
      document.querySelectorAll('[data-visual-mode]').forEach(button => {
        button.classList.toggle('active', button.dataset.visualMode === mode);
      });
    }

    toggleSpace() {
      const next = this.transformControls.space === 'world' ? 'local' : 'world';
      this.transformControls.setSpace(next);
      document.getElementById('visualSpaceToggle').textContent =
        next === 'world' ? 'World axes (Q)' : 'Local axes (Q)';
    }

    matrixRows() {
      this.movingObject.updateMatrix();
      const e = this.movingObject.matrix.elements;
      return [
        [e[0], e[4], e[8], e[12]],
        [e[1], e[5], e[9], e[13]],
        [e[2], e[6], e[10], e[14]],
        [e[3], e[7], e[11], e[15]],
      ];
    }

    state() {
      return {
        schema_version: 1,
        method: 'manual_visual_transform_sim3',
        position: this.movingObject.position.toArray(),
        quaternion: this.movingObject.quaternion.toArray(),
        uniform_scale: this.movingObject.scale.x,
        matrix4: this.matrixRows(),
      };
    }

    applyState(state) {
      if (!state) return;
      if (Array.isArray(state.position) && state.position.length === 3) {
        this.movingObject.position.fromArray(state.position.map(Number));
      }
      if (Array.isArray(state.quaternion) && state.quaternion.length === 4) {
        this.movingObject.quaternion.fromArray(state.quaternion.map(Number)).normalize();
      }
      const scale = Number(state.uniform_scale ?? state.scale);
      if (Number.isFinite(scale) && scale > 0) {
        this.movingObject.scale.setScalar(THREE.MathUtils.clamp(scale, 0.0001, 10000));
      }
      this.movingObject.updateMatrix();
      this.transformControls.update();
    }

    reset() {
      this.movingObject.position.set(0, 0, 0);
      this.movingObject.quaternion.identity();
      this.movingObject.scale.setScalar(1);
      this.movingObject.updateMatrix();
      this.syncInputs();
      this.persist();
      this.fit();
    }

    fit() {
      const box = new THREE.Box3();
      for (const object of [this.anchorObject, this.movingObject]) {
        const current = new THREE.Box3().setFromObject(object);
        if (!current.isEmpty()) box.union(current);
      }
      if (box.isEmpty()) return;
      const center = box.getCenter(new THREE.Vector3());
      const radius = Math.max(box.getSize(new THREE.Vector3()).length() * 0.5, 0.01);
      this.camera.near = Math.max(radius / 10000, 0.0001);
      this.camera.far = Math.max(radius * 100, 1000);
      this.camera.updateProjectionMatrix();
      this.cameraControls.target.copy(center);
      this.camera.position.set(
        center.x + radius * 1.5,
        center.y + radius * 0.9,
        center.z + radius * 1.5
      );
      this.camera.lookAt(center);
      this.cameraControls.minDistance = radius * 0.01;
      this.cameraControls.maxDistance = radius * 100;
      this.cameraControls.update();
      this.grid.scale.setScalar(Math.max(radius / 10, 0.1));
    }

    syncInputs() {
      const r = new THREE.Euler().setFromQuaternion(this.movingObject.quaternion, 'XYZ');
      const values = {
        visualTx: this.movingObject.position.x,
        visualTy: this.movingObject.position.y,
        visualTz: this.movingObject.position.z,
        visualRx: THREE.MathUtils.radToDeg(r.x),
        visualRy: THREE.MathUtils.radToDeg(r.y),
        visualRz: THREE.MathUtils.radToDeg(r.z),
        visualScale: this.movingObject.scale.x,
      };
      for (const [id, value] of Object.entries(values)) {
        const input = document.getElementById(id);
        if (document.activeElement !== input) {
          input.value = Number(value).toFixed(id === 'visualScale' ? 6 : 4);
        }
      }
    }

    applyInputs() {
      const n = id => Number(document.getElementById(id).value);
      const position = [n('visualTx'), n('visualTy'), n('visualTz')];
      const rotation = [n('visualRx'), n('visualRy'), n('visualRz')];
      const scale = n('visualScale');
      if (!position.every(Number.isFinite) || !rotation.every(Number.isFinite) ||
          !Number.isFinite(scale) || scale <= 0) return;
      this.movingObject.position.fromArray(position);
      this.movingObject.quaternion.setFromEuler(new THREE.Euler(
        THREE.MathUtils.degToRad(rotation[0]),
        THREE.MathUtils.degToRad(rotation[1]),
        THREE.MathUtils.degToRad(rotation[2]),
        'XYZ'
      ));
      this.movingObject.scale.setScalar(THREE.MathUtils.clamp(scale, 0.0001, 10000));
      this.movingObject.updateMatrix();
      this.transformControls.update();
      this.persist();
    }

    persist() {
      localStorage.setItem(visualStorageKey, JSON.stringify(this.state()));
      const status = document.getElementById('visualTransformStatus');
      status.className = 'small text-success';
      status.textContent = 'Visual transform сохранён локально: '
        + new Date().toLocaleTimeString();
    }

    restore() {
      try {
        this.applyState(JSON.parse(localStorage.getItem(visualStorageKey) || 'null'));
      } catch (error) {
        console.warn('Cannot restore visual transform', error);
      }
    }

    resize() {
      const width = Math.max(this.el.clientWidth, 1);
      const height = Math.max(this.el.clientHeight, 1);
      this.renderer.setSize(width, height, false);
      this.camera.aspect = width / height;
      this.camera.updateProjectionMatrix();
      this.cameraControls.handleResize();
    }

    animate() {
      requestAnimationFrame(() => this.animate());
      this.cameraControls.update();
      this.renderer.render(this.scene, this.camera);
    }
  }

  const visualViewer = new VisualAlignmentViewer(
    clouds.anchorViewer.geometry,
    clouds.sourceViewer.geometry
  );
  window.sfmVisualAlignmentViewer = visualViewer;

  document.querySelectorAll('[data-visual-mode]').forEach(button => {
    button.addEventListener('click', () => visualViewer.setMode(button.dataset.visualMode));
  });
  document.getElementById('visualSpaceToggle').addEventListener('click', () => visualViewer.toggleSpace());
  document.getElementById('visualFitBoth').addEventListener('click', () => visualViewer.fit());
  document.getElementById('visualResetTransform').addEventListener('click', () => visualViewer.reset());
  document.getElementById('visualOpacity').addEventListener('input', e => {
    visualViewer.movingObject.material.opacity = Number(e.target.value);
  });
  document.getElementById('visualAnchorPointSize').addEventListener('input', e => {
    visualViewer.anchorObject.material.size = Number(e.target.value);
  });
  document.getElementById('visualMovingPointSize').addEventListener('input', e => {
    visualViewer.movingObject.material.size = Number(e.target.value);
  });

  for (const id of ['visualTx','visualTy','visualTz','visualRx','visualRy','visualRz','visualScale']) {
    document.getElementById(id).addEventListener('change', () => visualViewer.applyInputs());
  }

  document.getElementById('visualCopyMatrix').addEventListener('click', async () => {
    const text = JSON.stringify(visualViewer.matrixRows(), null, 2);
    try {
      await navigator.clipboard.writeText(text);
      document.getElementById('visualTransformStatus').textContent = 'matrix4 copied.';
    } catch (error) {
      document.getElementById('visualTransformStatus').textContent = text;
    }
  });

  document.getElementById('visualExportTransform').addEventListener('click', () => {
    const blob = new Blob([JSON.stringify(visualViewer.state(), null, 2)], {
      type: 'application/json',
    });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = `visual_transform_${query.get('order_id') || 0}_`
      + `${query.get('anchor_kind') || 'remote'}_${query.get('anchor_id') || 0}_`
      + `${query.get('source_kind') || 'remote'}_${query.get('source_id') || 0}.json`;
    link.click();
    setTimeout(() => URL.revokeObjectURL(link.href), 1000);
  });

  window.addEventListener('keydown', event => {
    if (['INPUT', 'SELECT', 'TEXTAREA'].includes(document.activeElement?.tagName)) return;
    const key = event.key.toLowerCase();
    if (key === 'w') visualViewer.setMode('translate');
    if (key === 'e') visualViewer.setMode('rotate');
    if (key === 'r') visualViewer.setMode('scale');
    if (key === 'q') visualViewer.toggleSpace();
  });
}
</script>

<script type="module">
import * as THREE from 'three';

function visualScaleRadius(geometry) {
  geometry.computeBoundingBox();
  const size = geometry.boundingBox.getSize(new THREE.Vector3());
  return Math.max(size.length() * 0.5, 0.01);
}

function fitManualViewerWithSharedRadius(viewer, sharedRadius) {
  const center = viewer.geometry.boundingBox.getCenter(
    new THREE.Vector3()
  );
  const radius = Math.max(Number(sharedRadius) || 0, 0.01);

  viewer.camera.near = Math.max(radius / 10000, 0.0001);
  viewer.camera.far = Math.max(radius * 100, 1000);
  viewer.camera.updateProjectionMatrix();
  viewer.controls.target.copy(center);

  if (viewer.navigationMode === 'horizon') {
    viewer.camera.up.set(0, 1, 0);
  }

  viewer.camera.position.set(
    center.x + radius * 1.25,
    center.y + radius * 0.8,
    center.z + radius * 1.25
  );
  viewer.camera.lookAt(center);
  viewer.controls.minDistance = radius * 0.01;
  viewer.controls.maxDistance = radius * 100;
  viewer.controls.update();
}

async function waitForVisualAlignmentViewer() {
  for (let attempt = 0; attempt < 100; attempt += 1) {
    if (window.sfmVisualAlignmentViewer) {
      return window.sfmVisualAlignmentViewer;
    }
    await new Promise(resolve => setTimeout(resolve, 50));
  }
  throw new Error('Visual alignment viewer was not initialized');
}

try {
  const clouds = window.sfmManualClouds
    || await window.sfmManualCloudsReady;
  const visualViewer = await waitForVisualAlignmentViewer();

  const scaleToolbar = document.createElement('div');
  scaleToolbar.className =
    'd-flex flex-wrap gap-2 align-items-center mb-1';
  scaleToolbar.innerHTML = `
    <button class="btn btn-sm btn-outline-primary"
      type="button" id="syncViewerScaleBtn">
      Одинаковый масштаб двух окон
    </button>
    <span class="small text-muted"
      id="sharedViewerScaleStatus"></span>
  `;
  document.getElementById('manualNavigationHint')
    ?.insertAdjacentElement('afterend', scaleToolbar);

  const matchButton = document.createElement('button');
  matchButton.type = 'button';
  matchButton.id = 'visualMatchMovingScale';
  matchButton.className = 'btn btn-sm btn-outline-primary';
  matchButton.textContent = 'Match Moving scale to Anchor';
  document.getElementById('visualFitBoth')
    ?.insertAdjacentElement('afterend', matchButton);

  function syncTopViewerCameraScale() {
    const anchorRadius = visualScaleRadius(
      clouds.anchorViewer.geometry
    );
    const sourceRadius = visualScaleRadius(
      clouds.sourceViewer.geometry
    );
    const sharedRadius = Math.max(anchorRadius, sourceRadius);

    fitManualViewerWithSharedRadius(
      clouds.anchorViewer,
      sharedRadius
    );
    fitManualViewerWithSharedRadius(
      clouds.sourceViewer,
      sharedRadius
    );

    const status = document.getElementById(
      'sharedViewerScaleStatus'
    );
    if (status) {
      status.textContent =
        'Одинаковый camera scale: '
        + `Anchor ${anchorRadius.toFixed(4)}, `
        + `Source ${sourceRadius.toFixed(4)}, `
        + `shared ${sharedRadius.toFixed(4)}.`;
    }
  }

  function matchMovingScaleToAnchor() {
    const anchorRadius = visualScaleRadius(
      visualViewer.anchorObject.geometry
    );
    const movingRadius = visualScaleRadius(
      visualViewer.movingObject.geometry
    );
    if (!(anchorRadius > 0) || !(movingRadius > 0)) {
      return;
    }

    const matchedScale = THREE.MathUtils.clamp(
      anchorRadius / movingRadius,
      0.0001,
      10000
    );

    visualViewer.movingObject.scale.setScalar(matchedScale);
    visualViewer.movingObject.updateMatrix();
    visualViewer.transformControls.update();
    visualViewer.syncInputs();
    visualViewer.persist();
    visualViewer.fit();

    const status = document.getElementById(
      'visualTransformStatus'
    );
    status.className = 'small text-primary';
    status.textContent =
      'Moving uniform scale подобран по bounding box: '
      + matchedScale.toFixed(8)
      + '. Проверь и уточни вручную.';
  }

  document.getElementById('syncViewerScaleBtn')
    .addEventListener('click', syncTopViewerCameraScale);
  document.getElementById('visualMatchMovingScale')
    .addEventListener('click', matchMovingScaleToAnchor);

  for (const fitButtonId of ['fitAnchor', 'fitSource']) {
    document.getElementById(fitButtonId)
      ?.addEventListener(
        'click',
        () => setTimeout(syncTopViewerCameraScale, 0)
      );
  }

  syncTopViewerCameraScale();
} catch (error) {
  console.error('Visual scale controls failed', error);
}
</script>
</body>
</html>
