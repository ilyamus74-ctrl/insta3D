<?php
/* Smarty version 5.3.1, created on 2026-05-16 19:22:49
  from 'file:maklertour_public_tour.html' */

/* @var \Smarty\Template $_smarty_tpl */
if ($_smarty_tpl->getCompiled()->isFresh($_smarty_tpl, array (
  'version' => '5.3.1',
  'unifunc' => 'content_6a08c40994bfc9_24685527',
  'has_nocache_code' => false,
  'file_dependency' => 
  array (
    '3359f6c297399af65997274aae38f144266141cb' => 
    array (
      0 => 'maklertour_public_tour.html',
      1 => 1778959353,
      2 => 'file',
    ),
  ),
  'includes' => 
  array (
  ),
))) {
function content_6a08c40994bfc9_24685527 (\Smarty\Template $_smarty_tpl) {
$_smarty_current_dir = '/home/makler/web/templates';
?><!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Публичный 3D тур</title>
  <link rel="stylesheet" href="/assets/vendor/bootstrap/css/bootstrap.min.css">
  <link rel="stylesheet" href="/assets/vendor/bootstrap-icons/bootstrap-icons.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/pannellum/build/pannellum.css">
  <link rel="stylesheet" href="/css/maklertour_tour.css">
  <style>
    body.public-tour-page { margin: 0; background: #0f1116; color: #e6e8ef; }
    .public-tour-page .public-tour-shell { max-width: 100%; min-height: 100vh; border-radius: 0; }
    .public-tour-page .public-tour-page-wrap { padding: 12px; }
  </style>
</head>
<body class="public-tour-page">
  <div class="public-tour-page-wrap">
    <section
      class="tour-shell public-tour-shell"
      id="tourApp"
      data-session-id="0"
      data-public-mode="1"
      data-public-token="<?php echo htmlspecialchars((string)$_smarty_tpl->getValue('publicToken'), ENT_QUOTES, 'UTF-8', true);?>
"
      data-api-url="<?php echo htmlspecialchars((string)$_smarty_tpl->getValue('apiUrl'), ENT_QUOTES, 'UTF-8', true);?>
"
    >
      <aside class="tour-sidebar">
        <div class="tour-card">
          <div class="tour-title">Публичный 3D тур</div>
          <div class="tour-muted"><?php echo htmlspecialchars((string)(($tmp = $_smarty_tpl->getValue('orderTitle') ?? null)===null||$tmp==='' ? 'Объект' ?? null : $tmp), ENT_QUOTES, 'UTF-8', true);?>
</div>
        </div>

        <div id="tourProcessingStatus" style="display:none"></div>
        <div id="tourDetectionsCount" style="display:none"></div>
        <div id="tourPhotoDetections" style="display:none"></div>
        <div id="tourVideoDetections" style="display:none"></div>
        <div id="tourMarkers" style="display:none"></div>
        <div id="tourMarkerLayoutSummary" style="display:none"></div>
        <select id="tourHotspotTargetPoint" style="display:none"></select>
        <button type="button" id="tourAddHotspotBtn" style="display:none"></button>
        <div id="tourCurrentLinks" style="display:none"></div>
        <input id="tourShowMarkerHotspots" type="checkbox" style="display:none" checked>
        <div id="tourDetectedMarkersList" style="display:none"></div>
        <div id="tourSelectedMarkerInfo" style="display:none"></div>
        <button type="button" id="tourAutoMapBtn" style="display:none"></button>
        <button type="button" id="tourAutoMapOverwriteManualBtn" style="display:none"></button>
        <button type="button" id="tourMapReset" style="display:none"></button>

        <div class="tour-card">
          <div class="tour-section-title">Карта точек</div>
          <div class="tour-map-controls">
            <button type="button" id="tourMapFitBtn" class="btn btn-sm btn-outline-light">Вписать</button>
            <button type="button" id="tourMapZoomOutBtn" class="btn btn-sm btn-outline-light">−</button>
            <button type="button" id="tourMapZoomInBtn" class="btn btn-sm btn-outline-light">+</button>
          </div>
          <div class="tour-map-tabs">
            <button id="tourMap2dBtn" type="button" class="btn btn-sm btn-outline-light">2D карта</button>
            <button id="tourMap3dBtn" type="button" class="btn btn-sm btn-outline-light">3D карта</button>
            <button id="tourMapExpandBtn" type="button" class="btn btn-sm btn-outline-light">Развернуть карту</button>
          </div>
          <div id="tourMap2dPanel"><div id="tourMap" class="tour-map"></div></div>
          <div id="tourMap3dPanel" style="display:none;"><div id="tour3dMapCanvas" class="tour-3d-map"></div></div>
          <div id="tourMapMeta" class="tour-map-meta"></div>
        </div>

        <div class="tour-card">
          <div class="tour-section-title">Точки съёмки</div>
          <div id="tourPoints" class="tour-point-list"><span class="tour-muted">Загрузка...</span></div>
        </div>
      </aside>

      <div class="tour-main">
        <div class="tour-main-header">
          <div>
            <h2 id="tourCurrentPoint">Загрузка...</h2>
            <div id="tourCurrentRoom" class="tour-muted"></div>
          </div>
          <div class="tour-quality-switch">
            <button id="tourQualityLightBtn" type="button" class="btn btn-sm btn-outline-light">Light</button>
            <button id="tourQualityHdBtn" type="button" class="btn btn-sm btn-outline-light">HD</button>
          </div>
        </div>

        <div class="tour-viewer-area"><div id="panorama" class="tour-panorama"></div></div>

        <div class="tour-nav-row">
          <button id="tourPrevPoint" type="button" class="btn btn-outline-light">← Previous</button>
          <button id="tourNextPoint" type="button" class="btn btn-outline-light">Next →</button>
        </div>
      </div>
    </section>
  </div>

  <?php echo '<script'; ?>
 src="https://cdn.jsdelivr.net/npm/pannellum/build/pannellum.js"><?php echo '</script'; ?>
>
  <?php echo '<script'; ?>
 src="/vendor/three/three.min.js"><?php echo '</script'; ?>
>
  <?php echo '<script'; ?>
 src="/js/maklertour_tour.js"><?php echo '</script'; ?>
>
</body>
</html><?php }
}
