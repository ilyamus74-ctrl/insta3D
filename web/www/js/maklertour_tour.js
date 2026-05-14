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
  const viewerArea = document.querySelector('.tour-viewer-area');
  const panoramaEl = document.getElementById('panorama');
  let viewer = null, photoPoints = [], links = [], positions = {}, currentIndex = 0;
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
      return { pitch: toNum(l.pitch_deg, 0), yaw: toNum(l.yaw_deg, 0), type: 'info', text: l.label || (target?.name || 'Перейти'), clickHandlerFunc: () => openPointByPhotoPointId(l.to_photo_point_id) };
    });
  }

  function openPoint(index) {
    const point = photoPoints[index]; if (!point) return;
    if (viewer && photoPoints[currentIndex]) {
      const cur = photoPoints[currentIndex];
      cur._lastYaw = viewer.getYaw(); cur._lastPitch = viewer.getPitch(); cur._lastHfov = viewer.getHfov();
    }
    currentIndex = index; markActive(index); updateNavButtons(); renderMap();
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
      btn.innerHTML = `${thumb}<div class="tour-point-body"><div class="tour-point-name">${escapeHtml(p.name || ('Point #' + p.id))}</div><div class="tour-point-meta">#${escapeHtml(seq)} · ${escapeHtml(room)}</div></div>`;
      btn.addEventListener('click', () => openPoint(index)); pointsEl.appendChild(btn);
    });
    openPoint(0);
  }

  function renderMap() {
    if (!mapEl) return;
    const W = 280, H = 220, scale = 60;
    const temp = {};
    photoPoints.forEach((p, i) => {
      const pos = positions[String(p.id)] || { x_m: Math.cos((i / Math.max(photoPoints.length, 1)) * Math.PI * 2) * 2, y_m: Math.sin((i / Math.max(photoPoints.length, 1)) * Math.PI * 2) * 2 };
      temp[p.id] = { x: Number(pos.x_m), y: Number(pos.y_m) };
    });
    mapEl.innerHTML = `<svg class="tour-map-svg" viewBox="0 0 ${W} ${H}"></svg>`;
    const svg = mapEl.querySelector('svg');
    const toPx = (m) => (W / 2) + (m * scale); const toPy = (m) => (H / 2) - (m * scale);
    links.forEach((l) => { if (!temp[l.from_photo_point_id] || !temp[l.to_photo_point_id]) return; const a = temp[l.from_photo_point_id], b = temp[l.to_photo_point_id]; const line = document.createElementNS('http://www.w3.org/2000/svg', 'line'); line.setAttribute('x1', toPx(a.x)); line.setAttribute('y1', toPy(a.y)); line.setAttribute('x2', toPx(b.x)); line.setAttribute('y2', toPy(b.y)); line.setAttribute('class', 'tour-map-link'); svg.appendChild(line); });
    photoPoints.forEach((p) => {
      const pos = temp[p.id]; const cx = toPx(pos.x), cy = toPy(pos.y);
      const c = document.createElementNS('http://www.w3.org/2000/svg', 'circle'); c.setAttribute('cx', cx); c.setAttribute('cy', cy); c.setAttribute('r', 8); c.setAttribute('class', 'tour-map-point' + (photoPoints[currentIndex]?.id === p.id ? ' active' : '')); c.dataset.id = String(p.id); svg.appendChild(c);
      const t = document.createElementNS('http://www.w3.org/2000/svg', 'text'); t.setAttribute('x', cx + 10); t.setAttribute('y', cy + 4); t.textContent = String(p.sequence_number ?? p.id); t.setAttribute('fill', '#e5e7eb'); t.setAttribute('font-size', '11'); svg.appendChild(t);
      c.addEventListener('click', () => openPointByPhotoPointId(p.id));
      let drag = false;
      c.addEventListener('mousedown', (e) => { drag = true; e.preventDefault(); });
      window.addEventListener('mousemove', (e) => { if (!drag) return; const r = svg.getBoundingClientRect(); const nx = e.clientX - r.left; const ny = e.clientY - r.top; c.setAttribute('cx', nx); c.setAttribute('cy', ny); t.setAttribute('x', nx + 10); t.setAttribute('y', ny + 4); temp[p.id] = { x: (nx - W / 2) / scale, y: -(ny - H / 2) / scale }; });
      window.addEventListener('mouseup', () => { if (!drag) return; drag = false; positions[String(p.id)] = { photo_point_id: p.id, x_m: temp[p.id].x, y_m: temp[p.id].y, z_m: 0, yaw_deg: 0, source: 'MANUAL' }; fetch('/api/tour_point_position_save.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify({ session_id: Number(sessionId), photo_point_id: Number(p.id), x_m: temp[p.id].x, y_m: temp[p.id].y, z_m: 0, yaw_deg: 0 }) }).catch(() => {}); renderMap(); });
    });
  }

  if (prevBtn) prevBtn.addEventListener('click', () => currentIndex > 0 && openPoint(currentIndex - 1));
  if (nextBtn) nextBtn.addEventListener('click', () => currentIndex < photoPoints.length - 1 && openPoint(currentIndex + 1));
  document.addEventListener('keydown', (e) => { if (e.key === 'ArrowLeft') currentIndex > 0 && openPoint(currentIndex - 1); if (e.key === 'ArrowRight') currentIndex < photoPoints.length - 1 && openPoint(currentIndex + 1); });


  fetch('/api/tour_session.php?session_id=' + encodeURIComponent(sessionId), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
    .then(async (r) => ({ ok: r.ok, data: await r.json(), status: r.status }))
    .then(({ ok, data, status }) => { if (!ok || !data.ok) throw new Error(data.error || ('HTTP ' + status)); photoPoints = data.photo_points || []; links = data.links || []; positions = data.positions || {}; renderPoints(); })
    .catch((err) => { pointsEl.innerHTML = '<div class="tour-muted">Ошибка загрузки тура: ' + escapeHtml(err.message) + '</div>'; });
})();
