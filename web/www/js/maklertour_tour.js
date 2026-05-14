(function () {
  'use strict';

  const app = document.getElementById('tourApp');
  if (!app) return;

  const sessionId = app.dataset.sessionId;
  const pointsEl = document.getElementById('tourPoints');
  const markersEl = document.getElementById('tourMarkers');
  const statusEl = document.getElementById('tourProcessingStatus');
  const detectionsCountEl = document.getElementById('tourDetectionsCount');
  const photoDetectionsEl = document.getElementById('tourPhotoDetections');
  const videoDetectionsEl = document.getElementById('tourVideoDetections');
  const currentPointEl = document.getElementById('tourCurrentPoint');
  const currentRoomEl = document.getElementById('tourCurrentRoom');
  const prevBtn = document.getElementById('tourPrevPoint');
  const nextBtn = document.getElementById('tourNextPoint');

  let viewer = null;
  let photoPoints = [];
  let currentIndex = 0;

  const escapeHtml = (v) => String(v ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;').replaceAll("'", '&#039;');
  const toNum = (v, d) => Number.isFinite(Number(v)) ? Number(v) : d;
  function metricClass(m) { return m === 'METRIC_READY' ? 'tour-badge tour-badge-ok' : ((m === 'PARTIAL_MARKERS' || m === 'UNKNOWN' || m === 'NOT_CREATED') ? 'tour-badge tour-badge-warn' : 'tour-badge tour-badge-bad'); }
  function renderProcessing(p, m) { statusEl.className = metricClass(p.metric_status || 'UNKNOWN'); statusEl.textContent = p.metric_status || 'UNKNOWN'; detectionsCountEl.textContent = p.markers_detected_count ?? 0; photoDetectionsEl.textContent = m.source_counts.PHOTO_POINT ?? 0; videoDetectionsEl.textContent = m.source_counts.VIDEO_FRAME ?? 0; }


  function renderMarkers(markers) {
    markersEl.innerHTML = '';

    if (!markers.labels || !markers.labels.length) return void (markersEl.innerHTML = '<span class="tour-muted">Метки не найдены</span>');
    markers.labels.forEach((label) => { const span = document.createElement('span'); span.className = 'tour-badge tour-badge-ok'; span.textContent = label; markersEl.appendChild(span); });

  }

  function updateNavButtons() {
    if (!prevBtn || !nextBtn) return;
    prevBtn.disabled = currentIndex <= 0;
    nextBtn.disabled = currentIndex >= photoPoints.length - 1;
  }

  function markActive(index) { document.querySelectorAll('.tour-point').forEach((el) => el.classList.remove('active')); const active = document.querySelector(`.tour-point[data-index="${index}"]`); if (active) active.classList.add('active'); }

  function openPoint(index) {
    const point = photoPoints[index];
    if (!point) return;
    currentIndex = index;
    updateNavButtons();
    markActive(index);
    currentPointEl.textContent = point.name || ('Point #' + point.id);
    currentRoomEl.textContent = point.room_name ? ('room: ' + point.room_name) : '360 panorama';

    if (!point.panorama_url) return void (document.getElementById('panorama').innerHTML = '<div class="tour-viewer-placeholder">Нет panorama для этой точки</div>');
    if (viewer) { viewer.destroy(); viewer = null; }
    viewer = pannellum.viewer('panorama', {
      type: 'equirectangular', panorama: point.panorama_url, autoLoad: true, showZoomCtrl: true, compass: false,
      yaw: toNum(point.initial_yaw_deg, 0), pitch: toNum(point.initial_pitch_deg, 0), hfov: toNum(point.initial_hfov, 100)
    });
  }

  function openPrevPoint() { if (currentIndex > 0) openPoint(currentIndex - 1); }
  function openNextPoint() { if (currentIndex < photoPoints.length - 1) openPoint(currentIndex + 1); }

  function renderPoints(points) {
    photoPoints = points || []; pointsEl.innerHTML = '';
    if (!photoPoints.length) return void (pointsEl.innerHTML = '<div class="tour-muted">Фото-точек нет</div>');
    photoPoints.forEach((point, index) => {
      const btn = document.createElement('button'); btn.type = 'button'; btn.className = 'tour-point'; btn.dataset.index = String(index);
      const seq = point.sequence_number ?? '-'; const room = point.room_name || '-';
      const thumb = point.preview_url ? `<img class="tour-point-thumb" src="${escapeHtml(point.preview_url)}" alt="${escapeHtml(point.name || ('Point #' + point.id))}">` : '<div class="tour-point-thumb"></div>';
      btn.innerHTML = `${thumb}<div class="tour-point-body"><div class="tour-point-name">${escapeHtml(point.name || ('Point #' + point.id))}</div><div class="tour-point-meta">#${escapeHtml(seq)} · room: ${escapeHtml(room)}</div></div>`;
      btn.addEventListener('click', () => openPoint(index)); pointsEl.appendChild(btn);
    });
    openPoint(0);
  }

  if (prevBtn) prevBtn.addEventListener('click', openPrevPoint);
  if (nextBtn) nextBtn.addEventListener('click', openNextPoint);
  document.addEventListener('keydown', (e) => { if (e.key === 'ArrowLeft') openPrevPoint(); if (e.key === 'ArrowRight') openNextPoint(); });

  fetch('/api/tour_session.php?session_id=' + encodeURIComponent(sessionId), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
    .then(async (r) => ({ ok: r.ok, data: await r.json(), status: r.status }))
    .then(({ ok, data, status }) => { if (!ok || !data.ok) throw new Error(data.error || ('HTTP ' + status)); renderProcessing(data.processing, data.markers); renderMarkers(data.markers); renderPoints(data.photo_points); updateNavButtons(); })
    .catch((err) => { pointsEl.innerHTML = '<div class="tour-muted">Ошибка загрузки тура: ' + escapeHtml(err.message) + '</div>'; document.getElementById('panorama').innerHTML = '<div class="tour-viewer-placeholder">Ошибка загрузки тура</div>'; });
})();
