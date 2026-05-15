(function () {
  'use strict';

  const app = document.getElementById('tourApp'); if (!app) return;
  const sessionId = app.dataset.sessionId;
  const isPublicMode = app.dataset.publicMode === '1';
  const apiUrl = app.dataset.apiUrl || ('/api/tour_session.php?session_id=' + encodeURIComponent(sessionId));
  const pointsEl = document.getElementById('tourPoints');
  const currentPointEl = document.getElementById('tourCurrentPoint');
  const currentRoomEl = document.getElementById('tourCurrentRoom');
  const prevBtn = document.getElementById('tourPrevPoint');
  const nextBtn = document.getElementById('tourNextPoint');
  const mapEl = document.getElementById('tourMap');
  const map2dBtn = document.getElementById('tourMap2dBtn');
  const map3dBtn = document.getElementById('tourMap3dBtn');
  const map2dPanel = document.getElementById('tourMap2dPanel');
  const map3dPanel = document.getElementById('tourMap3dPanel');
  const map3dCanvas = document.getElementById('tour3dMapCanvas');
  const mapMetaEl = document.getElementById('tourMapMeta');
  const mapResetBtn = document.getElementById('tourMapReset');
  const mapFitBtn = document.getElementById('tourMapFitBtn');
  const mapZoomOutBtn = document.getElementById('tourMapZoomOutBtn');
  const mapZoomInBtn = document.getElementById('tourMapZoomInBtn');
  const mapExpandBtn = document.getElementById('tourMapExpandBtn');
  const autoMapBtn = document.getElementById('tourAutoMapBtn');
  const autoMapOverwriteManualBtn = document.getElementById('tourAutoMapOverwriteManualBtn');
  const viewerArea = document.querySelector('.tour-viewer-area');
  const panoramaEl = document.getElementById('panorama');
  const markerLayoutEl = document.getElementById('tourMarkerLayoutSummary');
  const processingStatusEl = document.getElementById('tourProcessingStatus');
  const detectionsCountEl = document.getElementById('tourDetectionsCount');
  const photoDetectionsEl = document.getElementById('tourPhotoDetections');
  const videoDetectionsEl = document.getElementById('tourVideoDetections');
  const markersEl = document.getElementById('tourMarkers');
  const hotspotTargetEl = document.getElementById('tourHotspotTargetPoint');
  const addHotspotBtn = document.getElementById('tourAddHotspotBtn');
  const currentLinksEl = document.getElementById('tourCurrentLinks');
  const detectedMarkersListEl = document.getElementById('tourDetectedMarkersList');
  const selectedMarkerInfoEl = document.getElementById('tourSelectedMarkerInfo');
  const showMarkerHotspotsEl = document.getElementById('tourShowMarkerHotspots');
  const qualityLightBtn = document.getElementById('tourQualityLightBtn');
  const qualityHdBtn = document.getElementById('tourQualityHdBtn');
  let viewer = null, photoPoints = [], links = [], positions = {}, currentIndex = 0, autoEdges = [];
  let panoramaQuality = localStorage.getItem('maklertour_panorama_quality') || 'light';
  let mapMode = localStorage.getItem('maklertour_map_mode') || '2d';
  let threeMap = {
    scene: null,
    camera: null,
    renderer: null,
    raycaster: null,
    mouse: null,
    pointMeshes: new Map(),
    hitMeshes: [],
    labelSprites: [],
    linesGroup: null,
    pointsGroup: null,
    initialized: false,
    view: { centerX: 0, centerZ: 0, zoom: 1, baseSpan: 10 },
    minZoom: 0.3,
    maxZoom: 8,
    isPanning: false,
    panLast: null,
    dragMoved: false
  };
  let mapZoom = 1.0;
  const preloadCache = new Set();

  const escapeHtml = (v) => String(v ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;').replaceAll("'", '&#039;');
  const toNum = (v, d) => Number.isFinite(Number(v)) ? Number(v) : d;
  const getById = (id) => photoPoints.find((p) => Number(p.id) === Number(id));

  function preloadPanorama(url) { if (!url || preloadCache.has(url)) return; preloadCache.add(url); const i = new Image(); i.src = url; }
  function markActive(index) { document.querySelectorAll('.tour-point').forEach((el) => el.classList.remove('active')); const active = document.querySelector(`.tour-point[data-index="${index}"]`); if (active) active.classList.add('active'); }
  function updateNavButtons() { if (prevBtn) prevBtn.disabled = currentIndex <= 0; if (nextBtn) nextBtn.disabled = currentIndex >= photoPoints.length - 1; }
  function openPointByPhotoPointId(photoPointId, viewOptions = null) { const idx = photoPoints.findIndex((p) => Number(p.id) === Number(photoPointId)); if (idx >= 0) openPoint(idx, viewOptions); }
  function selectDetectedMarker(marker) {
    if (!selectedMarkerInfoEl || !marker) return;
    selectedMarkerInfoEl.classList.remove('tour-muted');
    selectedMarkerInfoEl.innerHTML = `${escapeHtml(marker.marker_label || '-')}: yaw ${Number(marker.bearing_yaw_deg ?? 0).toFixed(2)}, pitch ${Number(marker.bearing_pitch_deg ?? 0).toFixed(2)}, conf ${Number(marker.confidence ?? 0).toFixed(2)}`;
  }

  function renderDetectedMarkers(point) {
    if (!detectedMarkersListEl) return;
    const detectedMarkers = point?.detected_markers || [];
    detectedMarkersListEl.innerHTML = '';
    if (!detectedMarkers.length) {
      detectedMarkersListEl.innerHTML = '<span class="tour-muted">Для этой точки метки не найдены</span>';
      if (selectedMarkerInfoEl) selectedMarkerInfoEl.innerHTML = 'Кликните по MT-метке в панораме.';
      return;
    }
    detectedMarkers.forEach((marker) => {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'tour-detected-marker-btn';
      btn.textContent = `${marker.marker_label} (${Number(marker.confidence || 0).toFixed(1)})`;
      btn.addEventListener('click', () => {
        if (viewer && marker.bearing_yaw_deg !== null && marker.bearing_pitch_deg !== null) viewer.lookAt(Number(marker.bearing_pitch_deg), Number(marker.bearing_yaw_deg));
        selectDetectedMarker(marker);
      });
      detectedMarkersListEl.appendChild(btn);
    });
  }

  function getPanoramaUrl(point) {
    if (panoramaQuality === 'hd' && point.panorama_hd_url) return point.panorama_hd_url;
    if (point.panorama_light_url) return point.panorama_light_url;
    return point.panorama_url;
  }

  function buildHotspots(point) {
    const hotSpots = links.filter((l) => Number(l.from_photo_point_id) === Number(point.id)).map((l) => {
      const target = getById(l.to_photo_point_id);
      const markerLabel = (l.shared_markers && l.shared_markers.length) ? ('MT-' + String(l.shared_markers[0]).padStart(3, '0')) : '';
      const confText = Number.isFinite(Number(l.confidence)) ? (' · conf ' + Number(l.confidence).toFixed(1)) : '';
      const autoSuffix = l.source === 'AUTO_MARKER_BEARING' ? (' AUTO · via ' + markerLabel + confText) : '';
      return { pitch: toNum(l.pitch_deg, 0), yaw: toNum(l.yaw_deg, 0), type: 'info', text: (l.label || (target?.name || 'Перейти')) + autoSuffix, cssClass: l.source === 'AUTO_MARKER_BEARING' ? 'tour-hotspot tour-hotspot-auto' : 'tour-hotspot tour-hotspot-manual', clickHandlerFunc: () => openPointByPhotoPointId(l.to_photo_point_id, { yaw: Number(l.target_yaw_deg), pitch: Number(l.target_pitch_deg), hfov: Number(l.target_hfov || 100) }) };
    });
    const showMarkers = !showMarkerHotspotsEl || !!showMarkerHotspotsEl.checked;
    if (showMarkers) {
      (point.detected_markers || []).forEach((marker) => {
        if (marker.bearing_yaw_deg === null || marker.bearing_pitch_deg === null) return;
        hotSpots.push({ pitch: Number(marker.bearing_pitch_deg), yaw: Number(marker.bearing_yaw_deg), type: 'info', text: (marker.marker_label || 'MT') + ' · conf ' + marker.confidence, cssClass: 'tour-marker-hotspot', clickHandlerFunc: () => selectDetectedMarker(marker) });
      });
    }
    return hotSpots;
  }

  function openPoint(index, viewOptions = null) {
    const point = photoPoints[index]; if (!point) return;
    if (viewer && photoPoints[currentIndex]) {
      const cur = photoPoints[currentIndex];
      cur._lastYaw = viewer.getYaw(); cur._lastPitch = viewer.getPitch(); cur._lastHfov = viewer.getHfov();
    }
    currentIndex = index; markActive(index); updateNavButtons(); renderMap(); updateThreeMapActivePoint(); renderHotspotTargetSelect(); renderCurrentLinks(); renderDetectedMarkers(point);
    if (currentPointEl) currentPointEl.textContent = point.name || ('Point #' + point.id);
    if (currentRoomEl) currentRoomEl.textContent = point.room_name ? ('room: ' + point.room_name) : '360 panorama';

    const panoramaUrl = getPanoramaUrl(point);
    if (!panoramaUrl) { if (panoramaEl) panoramaEl.innerHTML = '<div class="tour-viewer-placeholder">Нет panorama для этой точки</div>'; return; }
    if (viewerArea) viewerArea.classList.add('is-loading');
    const preImg = new Image();
    preImg.onload = function () {
      if (viewer) { viewer.destroy(); viewer = null; }
      if (panoramaEl) panoramaEl.innerHTML = '';
      viewer = pannellum.viewer('panorama', { type: 'equirectangular', panorama: panoramaUrl, autoLoad: true, showZoomCtrl: true, compass: false, yaw: (viewOptions && Number.isFinite(Number(viewOptions.yaw))) ? Number(viewOptions.yaw) : toNum(point._lastYaw, toNum(point.initial_yaw_deg, 0)), pitch: (viewOptions && Number.isFinite(Number(viewOptions.pitch))) ? Number(viewOptions.pitch) : toNum(point._lastPitch, toNum(point.initial_pitch_deg, 0)), hfov: (viewOptions && Number.isFinite(Number(viewOptions.hfov))) ? Number(viewOptions.hfov) : toNum(point._lastHfov, toNum(point.initial_hfov, 100)), hotSpots: buildHotspots(point) });
      if (viewerArea) viewerArea.classList.remove('is-loading');
      [index - 1, index + 1].forEach((i) => { if (photoPoints[i]) preloadPanorama(getPanoramaUrl(photoPoints[i])); });
      links.filter((l) => Number(l.from_photo_point_id) === Number(point.id)).forEach((l) => { const target = getById(l.to_photo_point_id); if (target) preloadPanorama(getPanoramaUrl(target)); });
    };
    preImg.onerror = function () {
      if (viewerArea) viewerArea.classList.remove('is-loading');
      if (panoramaEl) panoramaEl.innerHTML = `<div class="tour-viewer-placeholder">Ошибка загрузки panorama.<br><a target="_blank" rel="noopener" href="${escapeHtml(panoramaUrl)}">Открыть JPG напрямую</a></div>`;
    };
    preImg.src = panoramaUrl;
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
      const via = (l.shared_markers && l.shared_markers.length) ? (' · via MT-' + String(l.shared_markers[0]).padStart(3, '0')) : '';
      const conf = Number.isFinite(Number(l.confidence)) ? (' · conf ' + Number(l.confidence).toFixed(1)) : '';
      item.innerHTML = `<div>→ ${escapeHtml(l.label || target?.name || ('Point #' + l.to_photo_point_id))}</div><div class="tour-muted">${escapeHtml(l.source || 'MANUAL')}${escapeHtml(via)}${escapeHtml(conf)}</div><div class="tour-muted">yaw: ${Number(l.yaw_deg || 0).toFixed(1)} / pitch: ${Number(l.pitch_deg || 0).toFixed(1)}</div>`;
      if (!isPublicMode) {
      const del = document.createElement('button');
      del.type = 'button'; del.className = 'btn btn-sm btn-outline-danger mt-2'; del.textContent = 'Удалить';
      del.addEventListener('click', async () => {
        const r = await fetch('/api/tour_point_link_delete.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify({ session_id: Number(sessionId), link_id: Number(l.id) }) });
        const d = await r.json(); if (!r.ok || !d.ok) return alert('Ошибка удаления перехода');
        links = links.filter((x) => Number(x.id) !== Number(l.id));
        renderCurrentLinks(); openPoint(currentIndex);
      });
      item.appendChild(del); }
      currentLinksEl.appendChild(item);
    });
  }


  function renderProcessingSummary(data) {
    const processing = data.processing || {};
    const markers = data.markers || {};
    const sourceCounts = markers.source_counts || {};

    if (processingStatusEl) {
      const status = processing.status || '-';
      const metric = processing.metric_status || '-';
      processingStatusEl.textContent = status + ' / ' + metric;
      processingStatusEl.classList.remove('tour-badge-muted');
    }

    if (detectionsCountEl) {
      detectionsCountEl.textContent = String(processing.markers_detected_count ?? 0);
    }

    if (photoDetectionsEl) {
      photoDetectionsEl.textContent = String(sourceCounts.PHOTO_POINT ?? 0);
    }

    if (videoDetectionsEl) {
      videoDetectionsEl.textContent = String(sourceCounts.VIDEO_FRAME ?? 0);
    }
  }

  function renderMarkersSummary(data) {
    if (!markersEl) return;

    const markers = data.markers || {};
    const labels = markers.labels || [];

    markersEl.innerHTML = '';

    if (!labels.length) {
      markersEl.innerHTML = '<span class="tour-muted">Метки не найдены</span>';
      return;
    }

    labels.forEach((label) => {
      const span = document.createElement('span');
      span.className = 'tour-marker-pill';
      span.textContent = label;
      markersEl.appendChild(span);
    });
  }

  function createTextSprite(text) {
    const canvas = document.createElement('canvas');
    canvas.width = 128; canvas.height = 64;
    const ctx = canvas.getContext('2d');
    ctx.fillStyle = 'rgba(2,6,23,0.7)'; ctx.fillRect(0, 12, 128, 40);
    ctx.fillStyle = '#e2e8f0'; ctx.font = 'bold 28px sans-serif'; ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
    ctx.fillText(String(text || ''), 64, 33);
    const texture = new THREE.CanvasTexture(canvas);
    const sprite = new THREE.Sprite(new THREE.SpriteMaterial({ map: texture, transparent: true }));
    sprite.scale.set(0.9, 0.45, 1);
    return sprite;
  }

  function initThreeMapIfNeeded() {
    if (threeMap.initialized || !map3dCanvas) return;
    if (!window.THREE) { map3dCanvas.innerHTML = '<div class="tour-muted" style="padding:10px;">Three.js не загружен: проверьте /vendor/three/three.min.js</div>'; return; }
    threeMap.scene = new THREE.Scene();
    threeMap.scene.background = new THREE.Color(0x020617);
    threeMap.camera = new THREE.OrthographicCamera(-1, 1, 1, -1, 0.1, 200);
    threeMap.renderer = new THREE.WebGLRenderer({ antialias: true });
    threeMap.renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
    map3dCanvas.innerHTML = ''; map3dCanvas.appendChild(threeMap.renderer.domElement);
    threeMap.raycaster = new THREE.Raycaster(); threeMap.mouse = new THREE.Vector2();
    threeMap.initialized = true;
    const el = threeMap.renderer.domElement;
    el.addEventListener('click', onThreeMapClick);
    el.addEventListener('wheel', (e) => { e.preventDefault(); zoomThreeMap(e.deltaY > 0 ? -1 : 1); }, { passive: false });
    el.addEventListener('mousedown', (e) => {
      if (e.button !== 0) return;
      threeMap.isPanning = true;
      threeMap.dragMoved = false;
      threeMap.panLast = { x: e.clientX, y: e.clientY };
    });
    window.addEventListener('mousemove', (e) => {
      if (!threeMap.isPanning || !threeMap.panLast) return;
      const dx = e.clientX - threeMap.panLast.x;
      const dy = e.clientY - threeMap.panLast.y;
      if (Math.abs(dx) > 2 || Math.abs(dy) > 2) {
        threeMap.dragMoved = true;
      }
      threeMap.panLast = { x: e.clientX, y: e.clientY };
      panThreeMap(dx, dy);
    });
    window.addEventListener('mouseup', () => { threeMap.isPanning = false; threeMap.panLast = null; });
    resizeThreeMap();
  }

  function onThreeMapClick(e) {
    if (threeMap.dragMoved) {
      threeMap.dragMoved = false;
      return;
    }
    if (!threeMap.renderer || !threeMap.camera || !threeMap.raycaster) return;
    const rect = threeMap.renderer.domElement.getBoundingClientRect();
    threeMap.mouse.x = ((e.clientX - rect.left) / rect.width) * 2 - 1;
    threeMap.mouse.y = -((e.clientY - rect.top) / rect.height) * 2 + 1;
    threeMap.raycaster.setFromCamera(threeMap.mouse, threeMap.camera);
    const hit = threeMap.raycaster.intersectObjects(threeMap.hitMeshes, false);
    if (hit[0]?.object?.userData?.photoPointId) openPointByPhotoPointId(hit[0].object.userData.photoPointId);
  }

  function fitThreeMapToPoints() {
    const vals = photoPoints.map((p, i) => { const pos = positions[String(p.id)] || { x_m: Math.cos((i / Math.max(photoPoints.length, 1)) * Math.PI * 2) * 2, y_m: Math.sin((i / Math.max(photoPoints.length, 1)) * Math.PI * 2) * 2 }; return { x: Number(pos.x_m || 0), z: Number(pos.y_m || 0) }; });
    const xs = vals.map(v=>v.x), zs = vals.map(v=>v.z);
    const minX=Math.min(...xs, -1), maxX=Math.max(...xs,1), minZ=Math.min(...zs,-1), maxZ=Math.max(...zs,1);
    threeMap.view.centerX = (minX+maxX)/2; threeMap.view.centerZ=(minZ+maxZ)/2;
    threeMap.view.baseSpan = Math.max(maxX-minX, maxZ-minZ, 2) * 1.4;
    setThreeMapZoom(1);
  }
  function setThreeMapZoom(zoom) { threeMap.view.zoom = Math.max(threeMap.minZoom, Math.min(threeMap.maxZoom, zoom)); renderThreeMap(); }
  function zoomThreeMap(delta) { setThreeMapZoom(threeMap.view.zoom * (delta > 0 ? 1.2 : 1/1.2)); }
  function panThreeMap(dx, dy) { const aspect = Math.max((map3dCanvas.clientWidth||320) / Math.max(map3dCanvas.clientHeight||260,1), 0.1); const span = threeMap.view.baseSpan / threeMap.view.zoom; threeMap.view.centerX -= (dx / Math.max(map3dCanvas.clientWidth||1,1)) * span * aspect; threeMap.view.centerZ += (dy / Math.max(map3dCanvas.clientHeight||1,1)) * span; renderThreeMap(); }

  function resizeThreeMap() {
    if (!threeMap.initialized || !threeMap.renderer || !threeMap.camera) return;
    const w = Math.max(map3dCanvas.clientWidth || 320, 320), h = Math.max(map3dCanvas.clientHeight || 260, 260);
    threeMap.renderer.setSize(w, h);
    renderThreeMap();
  }

  function renderThreeMap() {
    if (!threeMap.initialized || !threeMap.scene || !threeMap.camera || !threeMap.renderer || !window.THREE) return;
    while (threeMap.scene.children.length > 0) threeMap.scene.remove(threeMap.scene.children[0]);
    threeMap.pointMeshes.clear(); threeMap.hitMeshes = []; threeMap.labelSprites = [];
    const amb = new THREE.AmbientLight(0xffffff, 0.9); threeMap.scene.add(amb);
    const dir = new THREE.DirectionalLight(0xffffff, 0.6); dir.position.set(3, 5, 2); threeMap.scene.add(dir);
    const pMap = {};
    photoPoints.forEach((p, i) => {
      const pos = positions[String(p.id)] || { x_m: Math.cos((i / Math.max(photoPoints.length, 1)) * Math.PI * 2) * 2, y_m: Math.sin((i / Math.max(photoPoints.length, 1)) * Math.PI * 2) * 2, z_m: 0 };
      const x = Number(pos.x_m || 0), y = Number(pos.z_m || 0), z = Number(pos.y_m || 0); pMap[p.id] = { x, y, z };
      const active = Number(photoPoints[currentIndex]?.id) === Number(p.id);
      const mesh = new THREE.Mesh(new THREE.SphereGeometry(0.12, 16, 16), new THREE.MeshStandardMaterial({ color: active ? 0x22d3ee : 0x93c5fd }));
      mesh.position.set(x, y, z); mesh.scale.setScalar(active ? 1.5 : 1); threeMap.scene.add(mesh); threeMap.pointMeshes.set(Number(p.id), mesh);
      const hit = new THREE.Mesh(new THREE.SphereGeometry(0.4, 10, 10), new THREE.MeshBasicMaterial({ transparent: true, opacity: 0 }));
      hit.position.copy(mesh.position); hit.userData.photoPointId = Number(p.id); threeMap.scene.add(hit); threeMap.hitMeshes.push(hit);
      const label = createTextSprite(String(p.sequence_number ?? p.id)); label.position.set(x, y + 0.45, z); threeMap.scene.add(label); threeMap.labelSprites.push(label);
    });
    const seq = [...photoPoints].sort((a,b)=>Number(a.sequence_number||0)-Number(b.sequence_number||0));
    let drawLinks = links; if (!drawLinks.length && seq.length > 1) drawLinks = seq.slice(1).map((p, i) => ({ from_photo_point_id: seq[i].id, to_photo_point_id: p.id }));
    drawLinks.forEach((l) => { const a = pMap[l.from_photo_point_id], b = pMap[l.to_photo_point_id]; if (!a || !b) return; const g = new THREE.BufferGeometry().setFromPoints([new THREE.Vector3(a.x,a.y,a.z), new THREE.Vector3(b.x,b.y,b.z)]); threeMap.scene.add(new THREE.Line(g, new THREE.LineBasicMaterial({ color: 0x64748b }))); });
    const aspect = Math.max((map3dCanvas.clientWidth || 320) / Math.max(map3dCanvas.clientHeight || 260, 1), 0.1);
    const span = threeMap.view.baseSpan / threeMap.view.zoom;
    threeMap.camera.left = -span * aspect / 2; threeMap.camera.right = span * aspect / 2; threeMap.camera.top = span / 2; threeMap.camera.bottom = -span / 2;
    threeMap.camera.position.set(threeMap.view.centerX, 8, threeMap.view.centerZ + 8); threeMap.camera.lookAt(threeMap.view.centerX, 0, threeMap.view.centerZ); threeMap.camera.updateProjectionMatrix();
    threeMap.renderer.render(threeMap.scene, threeMap.camera);
  }

  function updateThreeMapActivePoint() { if (mapMode === '3d') renderThreeMap(); }

  function applyMapMode() {
    const is3d = mapMode === '3d';
    if (map2dPanel) map2dPanel.style.display = is3d ? 'none' : '';
    if (map3dPanel) map3dPanel.style.display = is3d ? '' : 'none';
    if (map2dBtn) map2dBtn.classList.toggle('tour-map-tab-active', !is3d);
    if (map3dBtn) map3dBtn.classList.toggle('tour-map-tab-active', is3d);
    if (is3d) { initThreeMapIfNeeded(); if (threeMap.view.baseSpan <= 0 || !Number.isFinite(threeMap.view.baseSpan)) fitThreeMapToPoints(); resizeThreeMap(); }
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

  if (map2dBtn) map2dBtn.addEventListener('click', () => { mapMode = '2d'; localStorage.setItem('maklertour_map_mode', mapMode); applyMapMode(); });
  if (map3dBtn) map3dBtn.addEventListener('click', () => { mapMode = '3d'; localStorage.setItem('maklertour_map_mode', mapMode); applyMapMode(); });
  if (mapFitBtn) mapFitBtn.addEventListener('click', () => { if (mapMode === '3d') { fitThreeMapToPoints(); resizeThreeMap(); } else { mapZoom = 1.0; renderMap(); } });
  if (mapZoomInBtn) mapZoomInBtn.addEventListener('click', () => { if (mapMode === '3d') zoomThreeMap(1); else { mapZoom = Math.min(mapZoom * 1.2, 5); renderMap(); } });
  if (mapZoomOutBtn) mapZoomOutBtn.addEventListener('click', () => { if (mapMode === '3d') zoomThreeMap(-1); else { mapZoom = Math.max(mapZoom / 1.2, 0.3); renderMap(); } });
  if (mapExpandBtn) mapExpandBtn.addEventListener('click', () => {
    const card = mapExpandBtn.closest('.tour-card');
    const expanded = card?.classList.toggle('tour-map-expanded');
    mapExpandBtn.textContent = expanded ? 'Свернуть карту' : 'Развернуть карту';
    setTimeout(resizeThreeMap, 50);
  });
  window.addEventListener('resize', () => { if (mapMode === '3d') resizeThreeMap(); });

  async function loadTour() {
    const r = await fetch(apiUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
    const data = await r.json();
    if (!r.ok || !data.ok) throw new Error(data.error || ('HTTP ' + r.status));
    photoPoints = data.photo_points || [];
    links = data.links || [];
    positions = data.positions || {};
    autoEdges = data.auto_map_edges || [];
    renderProcessingSummary(data);
    renderMarkersSummary(data);
    if (markerLayoutEl) {
      const ms = data.marker_layout_summary || {};
      const missing = ms.missing_layout_marker_ids || [];
      const missLabels = missing.map((id) => 'MT-' + String(id).padStart(3, '0'));
      markerLayoutEl.innerHTML = `Marker layout: ${Number(ms.defined_markers_count || 0)} defined<br>Detected markers covered: ${Number(ms.detected_markers_with_layout_count || 0)}/${(data.markers?.unique_ids || []).length}` +
        (missing.length ? `<br>Нет layout для ${missLabels.join(', ')}<br>Marker layout неполный. Для метрической карты нужно задать размеры, координаты и ориентацию всех найденных меток.` : '<br>Автострелки строятся по общим видимым MT-меткам между соседними точками. Это навигационные переходы, не метрическое направление камеры.')
    }
    renderPoints();
    renderHotspotTargetSelect();
    renderCurrentLinks();
    if (window.THREE) fitThreeMapToPoints();
    applyMapMode();
  }

  async function runAutoMap(overwriteManual) {
      autoMapBtn.disabled = true; if (autoMapOverwriteManualBtn) autoMapOverwriteManualBtn.disabled = true;
      try {
        const r = await fetch('/api/tour_auto_map.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify({ session_id: Number(sessionId), overwrite: true, overwrite_manual: !!overwriteManual, generate_links: true }) });
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
  if (showMarkerHotspotsEl) {
    const saved = localStorage.getItem('maklertour_show_marker_hotspots');
    showMarkerHotspotsEl.checked = isPublicMode ? false : (saved === null ? true : saved === '1');
    showMarkerHotspotsEl.addEventListener('change', () => {
      localStorage.setItem('maklertour_show_marker_hotspots', showMarkerHotspotsEl.checked ? '1' : '0');
      openPoint(currentIndex, viewer ? { yaw: viewer.getYaw(), pitch: viewer.getPitch(), hfov: viewer.getHfov() } : null);
    });
  }
  if (qualityLightBtn) qualityLightBtn.addEventListener('click', () => {
    panoramaQuality = 'light';
    localStorage.setItem('maklertour_panorama_quality', panoramaQuality);
    applyQualityUi();
    openPoint(currentIndex, viewer ? { yaw: viewer.getYaw(), pitch: viewer.getPitch(), hfov: viewer.getHfov() } : null);
  });
  if (qualityHdBtn) qualityHdBtn.addEventListener('click', () => {
    panoramaQuality = 'hd';
    localStorage.setItem('maklertour_panorama_quality', panoramaQuality);
    applyQualityUi();
    openPoint(currentIndex, viewer ? { yaw: viewer.getYaw(), pitch: viewer.getPitch(), hfov: viewer.getHfov() } : null);
  });
  applyQualityUi();
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
  function applyQualityUi() {
    if (qualityLightBtn) qualityLightBtn.classList.toggle('active', panoramaQuality !== 'hd');
    if (qualityHdBtn) qualityHdBtn.classList.toggle('active', panoramaQuality === 'hd');
  }
  loadTour().catch((err) => { pointsEl.innerHTML = '<div class="tour-muted">Ошибка загрузки тура: ' + escapeHtml(err.message) + '</div>'; });
})();
