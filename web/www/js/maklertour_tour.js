(function () {
  'use strict';

  const app = document.getElementById('tourApp'); if (!app) return;
  const sessionId = app.dataset.sessionId;
  const pointsEl = document.getElementById('tourPoints');
  const currentPointEl = document.getElementById('tourCurrentPoint');
  const currentRoomEl = document.getElementById('tourCurrentRoom');
  const prevBtn = document.getElementById('tourPrevPoint');
  const nextBtn = document.getElementById('tourNextPoint');

  const mapEl = document.getElementById('tourMap');
  const mapMetaEl = document.getElementById('tourMapMeta');
  const mapResetBtn = document.getElementById('tourMapReset');
  const mapFitBtn = document.getElementById('tourMapFitBtn');
  const mapZoomOutBtn = document.getElementById('tourMapZoomOutBtn');
  const mapZoomInBtn = document.getElementById('tourMapZoomInBtn');
  const autoMapBtn = document.getElementById('tourAutoMapBtn');
  const autoMapOverwriteManualBtn = document.getElementById('tourAutoMapOverwriteManualBtn');
  const viewerArea = document.querySelector('.tour-viewer-area');
  const panoramaEl = document.getElementById('panorama');
  const markerLayoutEl = document.getElementById('tourMarkerLayoutSummary');
  const hotspotTargetEl = document.getElementById('tourHotspotTargetPoint');
  const addHotspotBtn = document.getElementById('tourAddHotspotBtn');
  const currentLinksEl = document.getElementById('tourCurrentLinks');
  let viewer = null, photoPoints = [], links = [], positions = {}, currentIndex = 0, autoEdges = [];
  let mapZoom = 1.0;
  const preloadCache = new Set();

  const escapeHtml = (v) => String(v ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;').replaceAll("'", '&#039;');
  const toNum = (v, d) => Number.isFinite(Number(v)) ? Number(v) : d;
  const getById = (id) => photoPoints.find((p) => Number(p.id) === Number(id));

  function preloadPanorama(url) { if (!url || preloadCache.has(url)) return; preloadCache.add(url); const i = new Image(); i.src = url; }
  function markActive(index) { document.querySelectorAll('.tour-point').forEach((el) => el.classList.remove('active')); const active = document.querySelector(`.tour-point[data-index="${index}"]`); if (active) active.classList.add('active'); }
  function updateNavButtons() { if (prevBtn) prevBtn.disabled = currentIndex <= 0; if (nextBtn) nextBtn.disabled = currentIndex >= photoPoints.length - 1; }
  function openPointByPhotoPointId(photoPointId) { const idx = photoPoints.findIndex((p) => Number(p.id) === Number(photoPointId)); if (idx >= 0) openPoint(idx); }
  function buildHotspots(point) {
    return links.filter((l) => Number(l.from_photo_point_id) === Number(point.id)).map((l) => {
      const target = getById(l.to_photo_point_id);
      return { pitch: toNum(l.pitch_deg, 0), yaw: toNum(l.yaw_deg, 0), type: 'info', text: l.label || (target?.name || 'Перейти'), cssClass: 'tour-hotspot', clickHandlerFunc: () => openPointByPhotoPointId(l.to_photo_point_id) };
    });
  }

  function openPoint(index) {
    const point = photoPoints[index]; if (!point) return;
    if (viewer && photoPoints[currentIndex]) {
      const cur = photoPoints[currentIndex];
      cur._lastYaw = viewer.getYaw(); cur._lastPitch = viewer.getPitch(); cur._lastHfov = viewer.getHfov();
    }
    currentIndex = index; markActive(index); updateNavButtons(); renderMap(); renderHotspotTargetSelect(); renderCurrentLinks();
    currentPointEl.textContent = point.name || ('Point #' + point.id);
    currentRoomEl.textContent = point.room_name ? ('room: ' + point.room_name) : '360 panorama';

    if (!point.panorama_url) { panoramaEl.innerHTML = '<div class="tour-viewer-placeholder">Нет panorama для этой точки</div>'; return; }
    viewerArea.classList.add('is-loading');
    const preImg = new Image();
    preImg.onload = function () {
      if (viewer) { viewer.destroy(); viewer = null; }
      panoramaEl.innerHTML = '';
      viewer = pannellum.viewer('panorama', { type: 'equirectangular', panorama: point.panorama_url, autoLoad: true, showZoomCtrl: true, compass: false, yaw: toNum(point._lastYaw, toNum(point.initial_yaw_deg, 0)), pitch: toNum(point._lastPitch, toNum(point.initial_pitch_deg, 0)), hfov: toNum(point._lastHfov, toNum(point.initial_hfov, 100)), hotSpots: buildHotspots(point) });
      viewerArea.classList.remove('is-loading');
      [index - 1, index + 1].forEach((i) => { if (photoPoints[i]?.panorama_url) preloadPanorama(photoPoints[i].panorama_url); });
      links.filter((l) => Number(l.from_photo_point_id) === Number(point.id)).forEach((l) => { const target = getById(l.to_photo_point_id); if (target?.panorama_url) preloadPanorama(target.panorama_url); });
    };
    preImg.onerror = function () {
      viewerArea.classList.remove('is-loading');
      panoramaEl.innerHTML = `<div class="tour-viewer-placeholder">Ошибка загрузки panorama.<br><a target="_blank" rel="noopener" href="${escapeHtml(point.panorama_url)}">Открыть JPG напрямую</a></div>`;
    };
    preImg.src = point.panorama_url;
  }

  function renderPoints() {
    pointsEl.innerHTML = ''; if (!photoPoints.length) return;
    photoPoints.forEach((p, index) => {

      const btn = document.createElement('button'); btn.type = 'button'; btn.className = 'tour-point'; btn.dataset.index = String(index);
      const seq = p.sequence_number ?? '-'; const room = p.room_name || '-';
      const thumb = p.preview_url ? `<img class="tour-point-thumb" src="${escapeHtml(p.preview_url)}" alt="">` : '<div class="tour-point-thumb"></div>';
      const markerLine = `${escapeHtml((p.marker_labels || []).join(', ') || 'без меток')} · conf ${escapeHtml((Number(p.avg_marker_confidence || 0)).toFixed(2))}`;
      btn.innerHTML = `${thumb}<div class="tour-point-body"><div class="tour-point-name">${escapeHtml(p.name || ('Point #' + p.id))}</div><div class="tour-point-meta">#${escapeHtml(seq)} · ${escapeHtml(room)}</div><div class="tour-point-markers">${markerLine}</div></div>`;
      btn.addEventListener('click', () => openPoint(index)); pointsEl.appendChild(btn);
    });
    openPoint(0);
  }

  function renderHotspotTargetSelect() {
    if (!hotspotTargetEl || !photoPoints.length) return;
    const current = photoPoints[currentIndex];
    hotspotTargetEl.innerHTML = '';
    photoPoints.filter((p) => Number(p.id) !== Number(current?.id)).forEach((p) => {
      const opt = document.createElement('option');
      opt.value = String(p.id);
      opt.textContent = p.name || ('Point #' + p.id);
      hotspotTargetEl.appendChild(opt);
    });
  }

  function renderCurrentLinks() {
    if (!currentLinksEl) return;
    const current = photoPoints[currentIndex];
    if (!current) { currentLinksEl.innerHTML = ''; return; }
    const rows = links.filter((l) => Number(l.from_photo_point_id) === Number(current.id));
    if (!rows.length) { currentLinksEl.innerHTML = '<div class="tour-muted">Нет переходов для текущей точки.</div>'; return; }
    currentLinksEl.innerHTML = '';
    rows.forEach((l) => {
      const target = getById(l.to_photo_point_id);
      const item = document.createElement('div');
      item.className = 'tour-link-item';
      item.innerHTML = `<div>→ ${escapeHtml(l.label || target?.name || ('Point #' + l.to_photo_point_id))}</div><div class="tour-muted">yaw: ${Number(l.yaw_deg || 0).toFixed(1)} / pitch: ${Number(l.pitch_deg || 0).toFixed(1)}</div>`;
      const del = document.createElement('button');
      del.type = 'button'; del.className = 'btn btn-sm btn-outline-danger mt-2'; del.textContent = 'Удалить';
      del.addEventListener('click', async () => {
        const r = await fetch('/api/tour_point_link_delete.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify({ session_id: Number(sessionId), link_id: Number(l.id) }) });
        const d = await r.json(); if (!r.ok || !d.ok) return alert('Ошибка удаления перехода');
        links = links.filter((x) => Number(x.id) !== Number(l.id));
        renderCurrentLinks(); openPoint(currentIndex);
      });
      item.appendChild(del); currentLinksEl.appendChild(item);
    });
  }
  function renderMap() {
    if (!mapEl) return;
    const padding = 24;
    const W = Math.max(280, Math.round(mapEl.clientWidth || 280));
    const H = 220;
    const temp = {};
    photoPoints.forEach((p, i) => {
      const pos = positions[String(p.id)] || { x_m: Math.cos((i / Math.max(photoPoints.length, 1)) * Math.PI * 2) * 2, y_m: Math.sin((i / Math.max(photoPoints.length, 1)) * Math.PI * 2) * 2 };
      temp[p.id] = { x: Number(pos.x_m), y: Number(pos.y_m) };
    });
    function computeMapTransform(points) {
      if (!points.length) return { minX: -1, minY: -1, maxX: 1, maxY: 1, scale: 1, padding, width: W, height: H };
      let minX = Math.min(...points.map((p) => p.x));
      let maxX = Math.max(...points.map((p) => p.x));
      let minY = Math.min(...points.map((p) => p.y));
      let maxY = Math.max(...points.map((p) => p.y));
      if (minX === maxX) { minX -= 1; maxX += 1; }
      if (minY === maxY) { minY -= 1; maxY += 1; }
      const scaleX = (W - padding * 2) / (maxX - minX);
      const scaleY = (H - padding * 2) / (maxY - minY);
      const scale = Math.max(0.0001, Math.min(scaleX, scaleY)) * mapZoom;
      return { minX, minY, maxX, maxY, scale, padding, width: W, height: H };
    }
    function worldToScreen(x, y, transform) {
      return { sx: transform.padding + ((x - transform.minX) * transform.scale), sy: transform.padding + ((y - transform.minY) * transform.scale) };
    }
    function screenToWorld(sx, sy, transform) {
      return { x: transform.minX + ((sx - transform.padding) / transform.scale), y: transform.minY + ((sy - transform.padding) / transform.scale) };
    }
    const transform = computeMapTransform(Object.values(temp));
    mapEl.innerHTML = `<svg class="tour-map-svg" viewBox="0 0 ${W} ${H}"></svg>`;
    const svg = mapEl.querySelector('svg');
    autoEdges.forEach((l) => { if (!temp[l.from_photo_point_id] || !temp[l.to_photo_point_id]) return; const a = temp[l.from_photo_point_id], b = temp[l.to_photo_point_id]; const aa = worldToScreen(a.x, a.y, transform); const bb = worldToScreen(b.x, b.y, transform); const line = document.createElementNS('http://www.w3.org/2000/svg', 'line'); line.setAttribute('x1', aa.sx); line.setAttribute('y1', aa.sy); line.setAttribute('x2', bb.sx); line.setAttribute('y2', bb.sy); line.setAttribute('class', 'tour-map-edge'); svg.appendChild(line); });
    links.forEach((l) => { if (!temp[l.from_photo_point_id] || !temp[l.to_photo_point_id]) return; const a = temp[l.from_photo_point_id], b = temp[l.to_photo_point_id]; const aa = worldToScreen(a.x, a.y, transform); const bb = worldToScreen(b.x, b.y, transform); const line = document.createElementNS('http://www.w3.org/2000/svg', 'line'); line.setAttribute('x1', aa.sx); line.setAttribute('y1', aa.sy); line.setAttribute('x2', bb.sx); line.setAttribute('y2', bb.sy); line.setAttribute('class', 'tour-map-link'); svg.appendChild(line); });
    photoPoints.forEach((p) => {
      const pos = temp[p.id]; const screen = worldToScreen(pos.x, pos.y, transform); const cx = screen.sx, cy = screen.sy;
      const src = (positions[String(p.id)]?.source || 'MANUAL');
      const c = document.createElementNS('http://www.w3.org/2000/svg', 'circle'); c.setAttribute('cx', cx); c.setAttribute('cy', cy); c.setAttribute('r', 8); c.setAttribute('class', 'tour-map-point source-' + src + (photoPoints[currentIndex]?.id === p.id ? ' active' : '')); c.dataset.id = String(p.id); svg.appendChild(c);
      const t = document.createElementNS('http://www.w3.org/2000/svg', 'text'); t.setAttribute('x', cx + 10); t.setAttribute('y', cy + 4); t.textContent = String(p.sequence_number ?? p.id); t.setAttribute('fill', '#e5e7eb'); t.setAttribute('font-size', '11'); svg.appendChild(t);
      c.addEventListener('click', () => openPointByPhotoPointId(p.id));
      c.setAttribute('title', `${p.name || ('Point #' + p.id)} | markers: ${(p.marker_labels || []).join(', ') || 'No markers'} | detections: ${p.marker_detections_count || 0} | avg confidence: ${Number(p.avg_marker_confidence || 0).toFixed(2)} | source: ${src}`);
      let drag = false;
      c.addEventListener('mousedown', (e) => { drag = true; e.preventDefault(); });

      window.addEventListener('mousemove', (e) => {
        if (!drag) return;
        const r = svg.getBoundingClientRect();
        const nxRaw = e.clientX - r.left;
        const nyRaw = e.clientY - r.top;
        const nx = Math.max(8, Math.min(W - 8, nxRaw));
        const ny = Math.max(8, Math.min(H - 8, nyRaw));
        c.setAttribute('cx', nx); c.setAttribute('cy', ny); t.setAttribute('x', nx + 10); t.setAttribute('y', ny + 4);
        const world = screenToWorld(nx, ny, transform);
        temp[p.id] = { x: world.x, y: world.y };
      });

      window.addEventListener('mouseup', () => { if (!drag) return; drag = false; positions[String(p.id)] = { photo_point_id: p.id, x_m: temp[p.id].x, y_m: temp[p.id].y, z_m: 0, yaw_deg: 0, source: 'MANUAL' }; fetch('/api/tour_point_position_save.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify({ session_id: Number(sessionId), photo_point_id: Number(p.id), x_m: temp[p.id].x, y_m: temp[p.id].y, z_m: 0, yaw_deg: 0 }) }).catch(() => {}); renderMap(); });
    });
    if (mapMetaEl) mapMetaEl.textContent = `Fit: ON · Zoom: ${Math.round(mapZoom * 100)}% · Points: ${photoPoints.length}`;
  }

  if (prevBtn) prevBtn.addEventListener('click', () => currentIndex > 0 && openPoint(currentIndex - 1));
  if (nextBtn) nextBtn.addEventListener('click', () => currentIndex < photoPoints.length - 1 && openPoint(currentIndex + 1));
  document.addEventListener('keydown', (e) => { if (e.key === 'ArrowLeft') currentIndex > 0 && openPoint(currentIndex - 1); if (e.key === 'ArrowRight') currentIndex < photoPoints.length - 1 && openPoint(currentIndex + 1); });

  if (mapResetBtn) {
    mapResetBtn.addEventListener('click', async () => {
      const count = Math.max(photoPoints.length, 1);
      const requests = photoPoints.map((p, i) => {
        const x = Math.cos((i / count) * Math.PI * 2) * 2;
        const y = Math.sin((i / count) * Math.PI * 2) * 2;
        positions[String(p.id)] = { photo_point_id: p.id, x_m: x, y_m: y, z_m: 0, yaw_deg: 0, source: 'MANUAL' };
        return fetch('/api/tour_point_position_save.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify({ session_id: Number(sessionId), photo_point_id: Number(p.id), x_m: x, y_m: y, z_m: 0, yaw_deg: 0 }) }).catch(() => {});
      });
      await Promise.all(requests);
      mapZoom = 1.0;
      renderMap();
    });
  }
  if (mapFitBtn) mapFitBtn.addEventListener('click', () => { mapZoom = 1.0; renderMap(); });
  if (mapZoomInBtn) mapZoomInBtn.addEventListener('click', () => { mapZoom = Math.min(mapZoom * 1.2, 5); renderMap(); });
  if (mapZoomOutBtn) mapZoomOutBtn.addEventListener('click', () => { mapZoom = Math.max(mapZoom / 1.2, 0.3); renderMap(); });

  async function loadTour() {
    const r = await fetch('/api/tour_session.php?session_id=' + encodeURIComponent(sessionId), { credentials: 'same-origin', headers: { Accept: 'application/json' } });
    const data = await r.json();
    if (!r.ok || !data.ok) throw new Error(data.error || ('HTTP ' + r.status));
    photoPoints = data.photo_points || [];
    links = data.links || [];
    positions = data.positions || {};
    autoEdges = data.auto_map_edges || [];
    if (markerLayoutEl) {
      const ms = data.marker_layout_summary || {};
      const missing = ms.missing_layout_marker_ids || [];
      const missLabels = missing.map((id) => 'MT-' + String(id).padStart(3, '0'));
      markerLayoutEl.innerHTML = `Marker layout: ${Number(ms.defined_markers_count || 0)} defined<br>Detected markers covered: ${Number(ms.detected_markers_with_layout_count || 0)}/${(data.markers?.unique_ids || []).length}` +
        (missing.length ? `<br>Нет layout для ${missLabels.join(', ')}<br>Marker layout неполный. Для метрической карты нужно задать размеры, координаты и ориентацию всех найденных меток.` : '<br>Marker layout задан. Следующий этап — pose/metric map.');
    }
    renderPoints();
    renderHotspotTargetSelect();
    renderCurrentLinks();
  }

  async function runAutoMap(overwriteManual) {
      autoMapBtn.disabled = true; if (autoMapOverwriteManualBtn) autoMapOverwriteManualBtn.disabled = true;
      try {
        const r = await fetch('/api/tour_auto_map.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify({ session_id: Number(sessionId), overwrite: true, overwrite_manual: !!overwriteManual }) });
        const data = await r.json();
        if (!r.ok || !data.ok) throw new Error(data.error || ('HTTP ' + r.status));
        alert('Авторасстановка выполнена. Позиции обновлены.');
        mapZoom = 1.0;
        await loadTour();
      } catch (err) {
        alert('Ошибка авторасстановки: ' + (err.message || 'unknown_error'));
      } finally {
        autoMapBtn.disabled = false; if (autoMapOverwriteManualBtn) autoMapOverwriteManualBtn.disabled = false;
      }
  }
  if (autoMapBtn) autoMapBtn.addEventListener('click', async () => runAutoMap(false));
  if (autoMapOverwriteManualBtn) autoMapOverwriteManualBtn.addEventListener('click', async () => runAutoMap(true));

  if (addHotspotBtn) {
    addHotspotBtn.addEventListener('click', async () => {
      if (!viewer) return alert('Viewer не готов');
      const current = photoPoints[currentIndex];
      const toId = Number(hotspotTargetEl?.value || 0);
      const target = getById(toId);
      if (!current || !toId || !target) return;
      const payload = { session_id: Number(sessionId), from_photo_point_id: Number(current.id), to_photo_point_id: toId, yaw_deg: Number(viewer.getYaw()), pitch_deg: Number(viewer.getPitch()), label: target.name || ('Point #' + target.id) };
      const r = await fetch('/api/tour_point_link_save.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify(payload) });
      const d = await r.json();
      if (!r.ok || !d.ok) return alert('Ошибка сохранения перехода');
      const idx = links.findIndex((x) => Number(x.from_photo_point_id) === Number(d.link.from_photo_point_id) && Number(x.to_photo_point_id) === Number(d.link.to_photo_point_id));
      if (idx >= 0) links[idx] = d.link; else links.push(d.link);
      renderCurrentLinks(); openPoint(currentIndex);
      if (currentLinksEl) currentLinksEl.insertAdjacentHTML('afterbegin', '<div class="tour-muted">Переход сохранён</div>');
    });
  }
  loadTour().catch((err) => { pointsEl.innerHTML = '<div class="tour-muted">Ошибка загрузки тура: ' + escapeHtml(err.message) + '</div>'; });
})();
