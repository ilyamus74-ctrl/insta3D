#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="/home/makler/web"
STAMP="$(date +%Y%m%d_%H%M%S)"

PAGE="$ROOT/www/sfm_manual_align.php"
API="$ROOT/www/api/sfm_manual_alignment.php"
PY="$ROOT/remote_station/scripts/manual_pointcloud_correspondence_merge.py"

for file in "$PAGE" "$API" "$PY"; do
  if [[ -e "$file" ]]; then
    cp -a "$file" "${file}.before_manual_alignment_${STAMP}"
  fi
done

cat > "$PAGE" <<'PHP'
<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
auth_require_login();

$orderId = max(0, (int)($_GET['order_id'] ?? 0));
$anchorKind = (string)($_GET['anchor_kind'] ?? 'remote');
$anchorId = max(0, (int)($_GET['anchor_id'] ?? 0));
$sourceKind = (string)($_GET['source_kind'] ?? 'remote');
$sourceId = max(0, (int)($_GET['source_id'] ?? 0));

if (!in_array($anchorKind, ['remote', 'merge'], true)) {
    $anchorKind = 'remote';
}
if (!in_array($sourceKind, ['remote', 'merge'], true)) {
    $sourceKind = 'remote';
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
    <a class="btn btn-outline-secondary btn-sm" href="/order_simple.php?id=<?= $orderId ?>#simple-generated">← К заявке</a>
    <span class="badge text-bg-dark">Order <?= $orderId ?></span>
    <span class="badge text-bg-primary">Anchor <?= h($anchorKind) ?>:<?= $anchorId ?></span>
    <span class="badge text-bg-success">Source <?= h($sourceKind) ?>:<?= $sourceId ?></span>
    <span class="text-muted small">Черновой ручной Sim(3), без записи в БД</span>
  </div>

  <div class="alert alert-warning py-2 small">
    Выбирай одну и ту же физическую точку: сначала слева, потом справа. Нужно минимум 4 пары, лучше 6–12.
    Не выбирай все точки на одной линии или на одной маленькой области.
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
import { PLYLoader } from 'three/addons/loaders/PLYLoader.js';

const params = <?= json_encode([
    'order_id' => $orderId,
    'anchor_kind' => $anchorKind,
    'anchor_id' => $anchorId,
    'source_kind' => $sourceKind,
    'source_id' => $sourceId,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;

const apiBase = '/api/sfm_manual_alignment.php?' + new URLSearchParams(params).toString();
const storageKey = 'sfm-manual-align:' + JSON.stringify(params);

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
  constructor({ elementId, statusId, side, onPick }) {
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
    this.controls = new OrbitControls(this.camera, this.renderer.domElement);
    this.controls.enableDamping = true;
    this.controls.screenSpacePanning = true;
    this.scene.add(new THREE.AmbientLight(0xffffff, 1.2));
    this.scene.add(new THREE.AxesHelper(1.5));
    this.cloud = null;
    this.geometry = null;
    this.markers = new THREE.Group();
    this.scene.add(this.markers);
    this.radius = 1;
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
    this.camera.position.set(center.x + radius * 1.25, center.y + radius * 0.8, center.z + radius * 1.25);
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
  onPick: point => {
    pendingSource = point;
    updatePendingUi();
    completePairIfReady();
  },
});

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
      headers: { 'Content-Type': 'application/json' },
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
    `;
  } catch (error) {
    resultBox.className = 'small result-box text-danger';
    resultBox.textContent = String(error.message || error);
  } finally {
    button.disabled = pairs.length < 4;
  }
});

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
PHP

cat > "$API" <<'PHP'
<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
auth_require_login();
set_time_limit(0);

header('X-Content-Type-Options: nosniff');

$orderId = max(0, (int)($_GET['order_id'] ?? 0));
$anchorKind = (string)($_GET['anchor_kind'] ?? 'remote');
$anchorId = max(0, (int)($_GET['anchor_id'] ?? 0));
$sourceKind = (string)($_GET['source_kind'] ?? 'remote');
$sourceId = max(0, (int)($_GET['source_id'] ?? 0));
$action = (string)($_GET['action'] ?? 'meta');

if ($orderId <= 0 || $anchorId <= 0 || $sourceId <= 0) {
    json_response(['ok' => false, 'error' => 'Missing alignment identifiers'], 400);
}

if (!in_array($anchorKind, ['remote', 'merge'], true) || !in_array($sourceKind, ['remote', 'merge'], true)) {
    json_response(['ok' => false, 'error' => 'Invalid model kind'], 400);
}

function json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function can_view_order(array $order, int $userId, string $role): bool
{
    return $role === 'ADMIN'
        || (int)($order['broker_id'] ?? 0) === $userId
        || ($role === 'OPERATOR' && (
            (int)($order['operator_id'] ?? 0) === $userId
            || (
                (int)($order['is_published'] ?? 0) === 1
                && (string)($order['status'] ?? '') === 'NEW'
                && ($order['operator_id'] ?? null) === null
            )
        ));
}

function ensure_order_access(mysqli $dbcnx, int $orderId): array
{
    $user = auth_current_user();
    $userId = (int)($user['id'] ?? 0);
    $role = (string)($user['role'] ?? 'BROKER');

    $stmt = $dbcnx->prepare(
        'SELECT id, broker_id, operator_id, is_published, status
         FROM tour_orders
         WHERE id=?
         LIMIT 1'
    );
    if (!$stmt) {
        json_response(['ok' => false, 'error' => 'DB prepare error'], 500);
    }
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$order) {
        json_response(['ok' => false, 'error' => 'Order not found'], 404);
    }
    if (!can_view_order($order, $userId, $role)) {
        json_response(['ok' => false, 'error' => 'Forbidden'], 403);
    }
    return $order;
}

function safe_existing_ply(array $candidates): string
{
    $base = realpath(dirname(__DIR__, 2) . '/remote_station/output');
    if ($base === false) {
        json_response(['ok' => false, 'error' => 'SfM output root not found'], 500);
    }

    foreach ($candidates as $candidate) {
        if (!is_string($candidate) || $candidate === '') {
            continue;
        }
        $real = realpath($candidate);
        if ($real === false || !is_file($real) || strtolower(pathinfo($real, PATHINFO_EXTENSION)) !== 'ply') {
            continue;
        }
        if ($real !== $base && !str_starts_with($real, $base . DIRECTORY_SEPARATOR)) {
            continue;
        }
        return $real;
    }

    json_response(['ok' => false, 'error' => 'PLY file not found in allowed SfM output tree'], 404);
}

function resolve_model(mysqli $dbcnx, int $orderId, string $kind, int $id): array
{
    $root = dirname(__DIR__, 2);

    if ($kind === 'merge') {
        $stmt = $dbcnx->prepare(
            'SELECT id, order_id, output_path, total_points, merge_type, status
             FROM sfm_generated_model_merges
             WHERE id=? AND order_id=?
             LIMIT 1'
        );
        if (!$stmt) {
            json_response(['ok' => false, 'error' => 'DB prepare error'], 500);
        }
        $stmt->bind_param('ii', $id, $orderId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            json_response(['ok' => false, 'error' => "Merge model $id not found"], 404);
        }

        $ply = safe_existing_ply([
            (string)($row['output_path'] ?? ''),
        ]);

        return [
            'kind' => 'merge',
            'id' => $id,
            'label' => 'merge #' . $id,
            'ply' => $ply,
            'db' => $row,
        ];
    }

    $stmt = $dbcnx->prepare(
        'SELECT id, order_id, remote_job_id, output_path, status, job_type, parameters_json
         FROM sfm_remote_jobs
         WHERE remote_job_id=? AND order_id=?
         LIMIT 1'
    );
    if (!$stmt) {
        json_response(['ok' => false, 'error' => 'DB prepare error'], 500);
    }
    $stmt->bind_param('ii', $id, $orderId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        json_response(['ok' => false, 'error' => "Remote model $id not found"], 404);
    }

    $outputPath = rtrim((string)($row['output_path'] ?? ''), '/');
    $localMirror = $root . '/remote_station/output/job_' . $id;

    $ply = safe_existing_ply([
        $localMirror . '/merged/merged_fused.ply',
        $outputPath . '/merged/merged_fused.ply',
        $localMirror . '/dense/fused.ply',
        $outputPath . '/dense/fused.ply',
    ]);

    return [
        'kind' => 'remote',
        'id' => $id,
        'label' => 'remote job ' . $id,
        'ply' => $ply,
        'db' => $row,
    ];
}

function draft_dir(
    int $orderId,
    string $anchorKind,
    int $anchorId,
    string $sourceKind,
    int $sourceId
): string {
    $root = dirname(__DIR__, 2) . '/remote_station/output';
    $name = sprintf(
        'manual_alignment_order_%d_anchor_%s_%d_source_%s_%d',
        $orderId,
        preg_replace('/[^a-z0-9_]+/i', '_', $anchorKind),
        $anchorId,
        preg_replace('/[^a-z0-9_]+/i', '_', $sourceKind),
        $sourceId
    );
    return $root . '/' . $name;
}

function stream_file(string $path, string $contentType, string $downloadName): never
{
    if (!is_file($path)) {
        json_response(['ok' => false, 'error' => 'File not found'], 404);
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: ' . $contentType);
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: inline; filename="' . addcslashes($downloadName, "\"\\") . '"');
    header('Cache-Control: private, max-age=0, must-revalidate');
    $fh = fopen($path, 'rb');
    if ($fh === false) {
        json_response(['ok' => false, 'error' => 'Cannot open file'], 500);
    }
    fpassthru($fh);
    fclose($fh);
    exit;
}

ensure_order_access($dbcnx, $orderId);
$anchor = resolve_model($dbcnx, $orderId, $anchorKind, $anchorId);
$source = resolve_model($dbcnx, $orderId, $sourceKind, $sourceId);
$draftDir = draft_dir($orderId, $anchorKind, $anchorId, $sourceKind, $sourceId);

if ($action === 'file') {
    $file = (string)($_GET['file'] ?? '');
    if ($file === 'anchor') {
        stream_file($anchor['ply'], 'application/octet-stream', 'anchor.ply');
    }
    if ($file === 'source') {
        stream_file($source['ply'], 'application/octet-stream', 'source.ply');
    }
    if ($file === 'aligned') {
        stream_file($draftDir . '/source_aligned_to_anchor.ply', 'application/octet-stream', 'source_aligned_to_anchor.ply');
    }
    if ($file === 'merged') {
        stream_file($draftDir . '/manual_merged_dense_cloud.ply', 'application/octet-stream', 'manual_merged_dense_cloud.ply');
    }
    if ($file === 'result') {
        stream_file($draftDir . '/merge_result.json', 'application/json; charset=utf-8', 'merge_result.json');
    }
    json_response(['ok' => false, 'error' => 'Unsupported file selector'], 400);
}

if ($action === 'meta') {
    json_response([
        'ok' => true,
        'order_id' => $orderId,
        'anchor' => [
            'kind' => $anchor['kind'],
            'id' => $anchor['id'],
            'label' => $anchor['label'],
            'size_bytes' => filesize($anchor['ply']),
        ],
        'source' => [
            'kind' => $source['kind'],
            'id' => $source['id'],
            'label' => $source['label'],
            'size_bytes' => filesize($source['ply']),
        ],
        'draft_dir' => $draftDir,
    ]);
}

if ($action !== 'compute' || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Unsupported action'], 400);
}

$raw = file_get_contents('php://input');
$input = json_decode((string)$raw, true);
$pairs = is_array($input) && isset($input['pairs']) && is_array($input['pairs']) ? $input['pairs'] : [];

if (count($pairs) < 4 || count($pairs) > 100) {
    json_response(['ok' => false, 'error' => 'Expected 4–100 correspondence pairs'], 422);
}

$normalized = [];
foreach ($pairs as $index => $pair) {
    if (!is_array($pair)) {
        json_response(['ok' => false, 'error' => "Pair $index is invalid"], 422);
    }
    $entry = [];
    foreach (['anchor', 'source'] as $side) {
        $coords = $pair[$side] ?? null;
        if (!is_array($coords) || count($coords) !== 3) {
            json_response(['ok' => false, 'error' => "Pair $index $side must contain XYZ"], 422);
        }
        $values = array_map('floatval', array_values($coords));
        foreach ($values as $value) {
            if (!is_finite($value)) {
                json_response(['ok' => false, 'error' => "Pair $index $side contains non-finite value"], 422);
            }
        }
        $entry[$side] = $values;
    }
    $normalized[] = $entry;
}

if (!is_dir($draftDir) && !mkdir($draftDir, 0775, true) && !is_dir($draftDir)) {
    json_response(['ok' => false, 'error' => 'Cannot create draft directory'], 500);
}

$correspondencePath = $draftDir . '/correspondence_pairs.json';
$payload = [
    'schema_version' => 1,
    'created_at' => date(DATE_ATOM),
    'order_id' => $orderId,
    'anchor' => ['kind' => $anchorKind, 'id' => $anchorId, 'ply' => $anchor['ply']],
    'source' => ['kind' => $sourceKind, 'id' => $sourceId, 'ply' => $source['ply']],
    'pairs' => $normalized,
];

$tmp = $correspondencePath . '.tmp';
if (file_put_contents(
    $tmp,
    json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
) === false || !rename($tmp, $correspondencePath)) {
    @unlink($tmp);
    json_response(['ok' => false, 'error' => 'Cannot save correspondence file'], 500);
}

$script = dirname(__DIR__, 2) . '/remote_station/scripts/manual_pointcloud_correspondence_merge.py';
if (!is_file($script)) {
    json_response(['ok' => false, 'error' => 'Alignment script not found'], 500);
}

$command = implode(' ', array_map('escapeshellarg', [
    '/usr/bin/python3',
    $script,
    '--anchor',
    $anchor['ply'],
    '--source',
    $source['ply'],
    '--correspondences',
    $correspondencePath,
    '--output-dir',
    $draftDir,
]));

$output = [];
$code = 0;
exec($command . ' 2>&1', $output, $code);

if ($code !== 0) {
    json_response([
        'ok' => false,
        'error' => 'Manual alignment failed',
        'exit_code' => $code,
        'log' => implode("\n", array_slice($output, -100)),
    ], 500);
}

$resultPath = $draftDir . '/merge_result.json';
$result = is_file($resultPath)
    ? json_decode((string)file_get_contents($resultPath), true)
    : null;

if (!is_array($result)) {
    json_response(['ok' => false, 'error' => 'Result JSON was not produced'], 500);
}

$query = http_build_query([
    'order_id' => $orderId,
    'anchor_kind' => $anchorKind,
    'anchor_id' => $anchorId,
    'source_kind' => $sourceKind,
    'source_id' => $sourceId,
    'action' => 'file',
]);

$result['ok'] = true;
$result['aligned_url'] = '/api/sfm_manual_alignment.php?' . $query . '&file=aligned';
$result['merged_url'] = '/api/sfm_manual_alignment.php?' . $query . '&file=merged';
$result['result_url'] = '/api/sfm_manual_alignment.php?' . $query . '&file=result';
$result['draft_dir'] = $draftDir;
json_response($result);
PHP

cat > "$PY" <<'PY'
#!/usr/bin/env python3
from __future__ import annotations

import argparse
import hashlib
import json
import math
import os
import struct
from dataclasses import dataclass
from pathlib import Path
from typing import Any

import numpy as np


PLY_TYPES: dict[str, tuple[str, str]] = {
    "char": ("i1", "b"),
    "int8": ("i1", "b"),
    "uchar": ("u1", "B"),
    "uint8": ("u1", "B"),
    "short": ("<i2", "h"),
    "int16": ("<i2", "h"),
    "ushort": ("<u2", "H"),
    "uint16": ("<u2", "H"),
    "int": ("<i4", "i"),
    "int32": ("<i4", "i"),
    "uint": ("<u4", "I"),
    "uint32": ("<u4", "I"),
    "float": ("<f4", "f"),
    "float32": ("<f4", "f"),
    "double": ("<f8", "d"),
    "float64": ("<f8", "d"),
}


@dataclass
class PlyCloud:
    path: Path
    format_name: str
    properties: list[tuple[str, str]]
    vertices: np.ndarray

    @property
    def count(self) -> int:
        return int(len(self.vertices))


def read_ply(path: Path) -> PlyCloud:
    with path.open("rb") as fh:
        first = fh.readline()
        if first.strip() != b"ply":
            raise ValueError(f"{path}: not a PLY file")

        format_name = ""
        vertex_count: int | None = None
        properties: list[tuple[str, str]] = []
        current_element: str | None = None
        saw_non_vertex_element_with_data = False

        while True:
            raw = fh.readline()
            if not raw:
                raise ValueError(f"{path}: incomplete PLY header")
            line = raw.decode("ascii", errors="strict").strip()
            if line == "end_header":
                break
            if not line or line.startswith("comment") or line.startswith("obj_info"):
                continue

            parts = line.split()
            if parts[0] == "format":
                format_name = parts[1]
            elif parts[0] == "element":
                current_element = parts[1]
                count = int(parts[2])
                if current_element == "vertex":
                    vertex_count = count
                elif count > 0:
                    saw_non_vertex_element_with_data = True
            elif parts[0] == "property" and current_element == "vertex":
                if parts[1] == "list":
                    raise ValueError(f"{path}: list property in vertex element is unsupported")
                type_name, name = parts[1], parts[2]
                if type_name not in PLY_TYPES:
                    raise ValueError(f"{path}: unsupported PLY property type {type_name}")
                properties.append((name, type_name))

        if vertex_count is None:
            raise ValueError(f"{path}: vertex element not found")
        if not {"x", "y", "z"}.issubset({name for name, _ in properties}):
            raise ValueError(f"{path}: x/y/z properties are required")
        if saw_non_vertex_element_with_data:
            raise ValueError(f"{path}: this manual point-cloud tool expects a vertex-only PLY")

        dtype = np.dtype([(name, PLY_TYPES[type_name][0]) for name, type_name in properties])

        if format_name == "binary_little_endian":
            vertices = np.fromfile(fh, dtype=dtype, count=vertex_count)
            if len(vertices) != vertex_count:
                raise ValueError(f"{path}: expected {vertex_count} vertices, got {len(vertices)}")
        elif format_name == "ascii":
            rows: list[tuple[Any, ...]] = []
            for _ in range(vertex_count):
                line = fh.readline().decode("ascii", errors="strict").strip()
                if not line:
                    raise ValueError(f"{path}: unexpected EOF in ASCII vertices")
                values = line.split()
                if len(values) != len(properties):
                    raise ValueError(f"{path}: vertex column count mismatch")
                converted: list[Any] = []
                for value, (_, type_name) in zip(values, properties):
                    np_type = np.dtype(PLY_TYPES[type_name][0])
                    converted.append(float(value) if np.issubdtype(np_type, np.floating) else int(value))
                rows.append(tuple(converted))
            vertices = np.array(rows, dtype=dtype)
        else:
            raise ValueError(f"{path}: unsupported PLY format {format_name!r}")

    return PlyCloud(path=path, format_name=format_name, properties=properties, vertices=vertices)


def schema_equal(a: PlyCloud, b: PlyCloud) -> bool:
    return a.properties == b.properties and a.vertices.dtype == b.vertices.dtype


def standardize_cloud(cloud: PlyCloud, include_color: bool) -> np.ndarray:
    fields: list[tuple[str, str]] = [("x", "<f4"), ("y", "<f4"), ("z", "<f4")]
    if include_color:
        fields += [("red", "u1"), ("green", "u1"), ("blue", "u1")]
    out = np.empty(cloud.count, dtype=np.dtype(fields))
    for axis in ("x", "y", "z"):
        out[axis] = cloud.vertices[axis].astype(np.float32)
    if include_color:
        for color in ("red", "green", "blue"):
            out[color] = cloud.vertices[color].astype(np.uint8)
    return out


def ply_type_for_dtype(dtype: np.dtype) -> str:
    dtype = np.dtype(dtype)
    mapping = {
        ("i", 1): "char",
        ("u", 1): "uchar",
        ("i", 2): "short",
        ("u", 2): "ushort",
        ("i", 4): "int",
        ("u", 4): "uint",
        ("f", 4): "float",
        ("f", 8): "double",
    }
    key = (dtype.kind, dtype.itemsize)
    if key not in mapping:
        raise ValueError(f"Cannot serialize dtype {dtype}")
    return mapping[key]


def write_binary_ply(path: Path, vertices: np.ndarray) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    header = ["ply", "format binary_little_endian 1.0", "comment manual Sim3 alignment draft"]
    header.append(f"element vertex {len(vertices)}")
    for name in vertices.dtype.names or ():
        header.append(f"property {ply_type_for_dtype(vertices.dtype[name])} {name}")
    header.append("end_header")
    header_bytes = ("\n".join(header) + "\n").encode("ascii")

    tmp = path.with_suffix(path.suffix + ".tmp")
    with tmp.open("wb") as fh:
        fh.write(header_bytes)
        little = vertices.astype(vertices.dtype.newbyteorder("<"), copy=False)
        little.tofile(fh)
        fh.flush()
        os.fsync(fh.fileno())
    tmp.replace(path)


def md5_file(path: Path) -> str:
    digest = hashlib.md5()
    with path.open("rb") as fh:
        for chunk in iter(lambda: fh.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def load_correspondences(path: Path) -> tuple[np.ndarray, np.ndarray, dict[str, Any]]:
    payload = json.loads(path.read_text(encoding="utf-8"))
    pairs = payload.get("pairs")
    if not isinstance(pairs, list) or len(pairs) < 4:
        raise ValueError("At least 4 correspondence pairs are required")

    target = np.asarray([pair["anchor"] for pair in pairs], dtype=np.float64)
    source = np.asarray([pair["source"] for pair in pairs], dtype=np.float64)

    if target.shape != source.shape or target.ndim != 2 or target.shape[1] != 3:
        raise ValueError("Correspondence arrays must have shape Nx3")
    if not np.isfinite(target).all() or not np.isfinite(source).all():
        raise ValueError("Correspondences contain non-finite values")
    return source, target, payload


def umeyama_similarity(source: np.ndarray, target: np.ndarray) -> tuple[float, np.ndarray, np.ndarray]:
    n = source.shape[0]
    source_mean = source.mean(axis=0)
    target_mean = target.mean(axis=0)
    source_centered = source - source_mean
    target_centered = target - target_mean

    source_rank = int(np.linalg.matrix_rank(source_centered))
    target_rank = int(np.linalg.matrix_rank(target_centered))
    if source_rank < 2 or target_rank < 2:
        raise ValueError(
            f"Degenerate correspondences: source_rank={source_rank}, target_rank={target_rank}; "
            "choose points spread over the object"
        )

    covariance = (target_centered.T @ source_centered) / float(n)
    u, singular_values, vt = np.linalg.svd(covariance)

    correction = np.eye(3)
    if np.linalg.det(u) * np.linalg.det(vt) < 0:
        correction[-1, -1] = -1.0

    rotation = u @ correction @ vt
    source_variance = float(np.sum(source_centered * source_centered) / n)
    if source_variance <= np.finfo(np.float64).eps:
        raise ValueError("Source correspondence variance is zero")

    scale = float(np.sum(singular_values * np.diag(correction)) / source_variance)
    translation = target_mean - scale * (rotation @ source_mean)

    if not math.isfinite(scale) or scale <= 0:
        raise ValueError(f"Invalid scale: {scale}")
    if not np.isfinite(rotation).all() or not np.isfinite(translation).all():
        raise ValueError("Transform contains non-finite values")
    if abs(float(np.linalg.det(rotation)) - 1.0) > 1e-5:
        raise ValueError(f"Rotation determinant is invalid: {np.linalg.det(rotation)}")

    return scale, rotation, translation


def apply_transform(points: np.ndarray, scale: float, rotation: np.ndarray, translation: np.ndarray) -> np.ndarray:
    return (scale * (rotation @ points.T)).T + translation


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--anchor", required=True)
    parser.add_argument("--source", required=True)
    parser.add_argument("--correspondences", required=True)
    parser.add_argument("--output-dir", required=True)
    args = parser.parse_args()

    anchor_path = Path(args.anchor).resolve()
    source_path = Path(args.source).resolve()
    correspondence_path = Path(args.correspondences).resolve()
    output_dir = Path(args.output_dir).resolve()
    output_dir.mkdir(parents=True, exist_ok=True)

    source_pairs, target_pairs, correspondence_payload = load_correspondences(correspondence_path)
    scale, rotation, translation = umeyama_similarity(source_pairs, target_pairs)

    predicted = apply_transform(source_pairs, scale, rotation, translation)
    residuals = np.linalg.norm(predicted - target_pairs, axis=1)

    anchor = read_ply(anchor_path)
    source = read_ply(source_path)

    transformed_source = source.vertices.copy()
    source_xyz = np.column_stack([
        source.vertices["x"].astype(np.float64),
        source.vertices["y"].astype(np.float64),
        source.vertices["z"].astype(np.float64),
    ])
    transformed_xyz = apply_transform(source_xyz, scale, rotation, translation)
    transformed_source["x"] = transformed_xyz[:, 0]
    transformed_source["y"] = transformed_xyz[:, 1]
    transformed_source["z"] = transformed_xyz[:, 2]

    aligned_path = output_dir / "source_aligned_to_anchor.ply"
    merged_path = output_dir / "manual_merged_dense_cloud.ply"

    write_binary_ply(aligned_path, transformed_source)

    color_fields = {"red", "green", "blue"}
    both_have_color = color_fields.issubset(anchor.vertices.dtype.names or ()) and color_fields.issubset(source.vertices.dtype.names or ())

    if schema_equal(anchor, source):
        merged = np.concatenate([anchor.vertices, transformed_source])
    else:
        anchor_standard = standardize_cloud(anchor, both_have_color)
        source_standard = standardize_cloud(
            PlyCloud(source.path, source.format_name, source.properties, transformed_source),
            both_have_color,
        )
        merged = np.concatenate([anchor_standard, source_standard])

    write_binary_ply(merged_path, merged)

    matrix4 = np.eye(4, dtype=np.float64)
    matrix4[:3, :3] = scale * rotation
    matrix4[:3, 3] = translation

    warnings: list[str] = []
    if scale < 0.1 or scale > 10.0:
        warnings.append(f"scale {scale:.6g} is outside broad plausibility range 0.1..10")
    if float(np.max(residuals)) > max(float(np.median(residuals)) * 5.0, 1e-9):
        warnings.append("one or more correspondence residuals are much larger than the median")
    if len(source_pairs) < 6:
        warnings.append("only 4–5 pairs were used; 6–12 distributed pairs are preferred")
    if not schema_equal(anchor, source):
        warnings.append("input PLY schemas differed; merged output was standardized")

    result = {
        "schema_version": 1,
        "status": "DRAFT",
        "method": "manual_correspondences_umeyama_sim3",
        "icp_applied": False,
        "pairs_count": int(len(source_pairs)),
        "scale": scale,
        "rotation": rotation.tolist(),
        "translation": translation.tolist(),
        "matrix4": matrix4.tolist(),
        "rotation_determinant": float(np.linalg.det(rotation)),
        "residuals": residuals.tolist(),
        "rms": float(np.sqrt(np.mean(residuals ** 2))),
        "median": float(np.median(residuals)),
        "max": float(np.max(residuals)),
        "anchor_points": anchor.count,
        "source_points": source.count,
        "merged_points": int(len(merged)),
        "anchor_md5": md5_file(anchor_path),
        "source_md5": md5_file(source_path),
        "aligned_source_md5": md5_file(aligned_path),
        "merged_md5": md5_file(merged_path),
        "aligned_source_path": str(aligned_path),
        "merged_path": str(merged_path),
        "correspondence_path": str(correspondence_path),
        "warnings": warnings,
        "correspondences": correspondence_payload.get("pairs", []),
    }

    result_tmp = output_dir / "merge_result.json.tmp"
    result_path = output_dir / "merge_result.json"
    result_tmp.write_text(
        json.dumps(result, ensure_ascii=False, indent=2),
        encoding="utf-8",
    )
    result_tmp.replace(result_path)

    print(json.dumps({
        "ok": True,
        "scale": scale,
        "rms": result["rms"],
        "merged_points": result["merged_points"],
        "result_path": str(result_path),
    }))


if __name__ == "__main__":
    main()
PY

chmod +x "$PY"

php -l "$PAGE"
php -l "$API"
python3 -m py_compile "$PY"

echo
echo "Installed manual alignment MVP."
echo "Open:"
echo "https://makler.cargocells.com/sfm_manual_align.php?order_id=30&anchor_kind=remote&anchor_id=860990938&source_kind=remote&source_id=917339860"
echo
echo "No DB rows are created. Output draft:"
echo "$ROOT/remote_station/output/manual_alignment_order_30_anchor_remote_860990938_source_remote_917339860/"
