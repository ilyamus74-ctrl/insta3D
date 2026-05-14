(function () {
  'use strict';

  const app = document.getElementById('tourApp');
  if (!app) {
    return;
  }

  const sessionId = app.dataset.sessionId;
  const pointsEl = document.getElementById('tourPoints');
  const markersEl = document.getElementById('tourMarkers');
  const statusEl = document.getElementById('tourProcessingStatus');
  const detectionsCountEl = document.getElementById('tourDetectionsCount');
  const photoDetectionsEl = document.getElementById('tourPhotoDetections');
  const videoDetectionsEl = document.getElementById('tourVideoDetections');
  const currentPointEl = document.getElementById('tourCurrentPoint');
  const currentRoomEl = document.getElementById('tourCurrentRoom');

  let viewer = null;
  let photoPoints = [];

  function escapeHtml(value) {
    return String(value ?? '')
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#039;');
  }

  function metricClass(metricStatus) {
    if (metricStatus === 'METRIC_READY') {
      return 'tour-badge tour-badge-ok';
    }
    if (metricStatus === 'PARTIAL_MARKERS' || metricStatus === 'UNKNOWN' || metricStatus === 'NOT_CREATED') {
      return 'tour-badge tour-badge-warn';
    }
    return 'tour-badge tour-badge-bad';
  }

  function renderProcessing(processing, markers) {
    const metric = processing.metric_status || 'UNKNOWN';

    statusEl.className = metricClass(metric);
    statusEl.textContent = metric;

    detectionsCountEl.textContent = processing.markers_detected_count ?? 0;
    photoDetectionsEl.textContent = markers.source_counts.PHOTO_POINT ?? 0;
    videoDetectionsEl.textContent = markers.source_counts.VIDEO_FRAME ?? 0;
  }

  function renderMarkers(markers) {
    markersEl.innerHTML = '';

    if (!markers.labels || markers.labels.length === 0) {
      markersEl.innerHTML = '<span class="tour-muted">Метки не найдены</span>';
      return;
    }

    for (const label of markers.labels) {
      const span = document.createElement('span');
      span.className = 'tour-badge tour-badge-ok';
      span.textContent = label;
      markersEl.appendChild(span);
    }
  }

  function renderPoints(points) {
    photoPoints = points || [];
    pointsEl.innerHTML = '';

    if (photoPoints.length === 0) {
      pointsEl.innerHTML = '<div class="tour-muted">Фото-точек нет</div>';
      return;
    }

    photoPoints.forEach((point, index) => {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'tour-point';
      btn.dataset.index = String(index);

      const seq = point.sequence_number ?? '-';
      const room = point.room_name || '-';

      btn.innerHTML = `
        <div class="tour-point-name">${escapeHtml(point.name || ('Point #' + point.id))}</div>
        <div class="tour-point-meta">#${escapeHtml(seq)} · room: ${escapeHtml(room)}</div>
      `;

      btn.addEventListener('click', () => openPoint(index));

      pointsEl.appendChild(btn);
    });

    openPoint(0);
  }

  function markActive(index) {
    document.querySelectorAll('.tour-point').forEach((el) => {
      el.classList.remove('active');
    });

    const active = document.querySelector(`.tour-point[data-index="${index}"]`);
    if (active) {
      active.classList.add('active');
    }
  }

  function openPoint(index) {
    const point = photoPoints[index];
    if (!point) {
      return;
    }

    markActive(index);

    currentPointEl.textContent = point.name || ('Point #' + point.id);
    currentRoomEl.textContent = point.room_name ? ('room: ' + point.room_name) : '360 panorama';

    if (!point.panorama_url) {
      document.getElementById('panorama').innerHTML = '<div class="tour-viewer-placeholder">Нет original panorama для этой точки</div>';
      return;
    }

    if (viewer) {
      viewer.destroy();
      viewer = null;
    }

    viewer = pannellum.viewer('panorama', {
      type: 'equirectangular',
      panorama: point.panorama_url,
      autoLoad: true,
      showZoomCtrl: true,
      compass: false,
      hfov: 100
    });
  }

  async function loadTour() {
    const response = await fetch('/api/tour_session.php?session_id=' + encodeURIComponent(sessionId), {
      credentials: 'same-origin',
      headers: {
        'Accept': 'application/json'
      }
    });

    const data = await response.json();

    if (!response.ok || !data.ok) {
      throw new Error(data.error || ('HTTP ' + response.status));
    }

    renderProcessing(data.processing, data.markers);
    renderMarkers(data.markers);
    renderPoints(data.photo_points);
  }

  loadTour().catch((err) => {
    pointsEl.innerHTML = '<div class="tour-muted">Ошибка загрузки тура: ' + escapeHtml(err.message) + '</div>';
    document.getElementById('panorama').innerHTML = '<div class="tour-viewer-placeholder">Ошибка загрузки тура</div>';
  });
})();
