<?php
/* Smarty version 5.3.1, created on 2026-05-15 12:02:28
  from 'file:maklertour_tour.html' */

/* @var \Smarty\Template $_smarty_tpl */
if ($_smarty_tpl->getCompiled()->isFresh($_smarty_tpl, array (
  'version' => '5.3.1',
  'unifunc' => 'content_6a070b54d9e625_30391324',
  'has_nocache_code' => false,
  'file_dependency' => 
  array (
    '0e5eded05bc37eff5c2407f99950b26d18b2f3c3' => 
    array (
      0 => 'maklertour_tour.html',
      1 => 1778846266,
      2 => 'file',
    ),
  ),
  'includes' => 
  array (
    'file:maklertour_header.html' => 1,
    'file:maklertour_sidebar.html' => 1,
    'file:maklertour_footer.html' => 1,
  ),
))) {
function content_6a070b54d9e625_30391324 (\Smarty\Template $_smarty_tpl) {
$_smarty_current_dir = '/home/makler/web/templates';
$_smarty_tpl->renderSubTemplate("file:maklertour_header.html", $_smarty_tpl->cache_id, $_smarty_tpl->compile_id, 0, $_smarty_tpl->cache_lifetime, array(), (int) 0, $_smarty_current_dir);
$_smarty_tpl->renderSubTemplate("file:maklertour_sidebar.html", $_smarty_tpl->cache_id, $_smarty_tpl->compile_id, 0, $_smarty_tpl->cache_lifetime, array(), (int) 0, $_smarty_current_dir);
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/pannellum/build/pannellum.css">
<link rel="stylesheet" href="/css/maklertour_tour.css">

<main id="main" class="main maklertour-tour-page">
  <div class="pagetitle">
    <h1>3D тур</h1>
    <nav>
      <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="/main.php">MaklerTour</a></li>
        <li class="breadcrumb-item"><a href="/order.php?id=<?php echo $_smarty_tpl->getValue('orderId');?>
">Заявка #<?php echo $_smarty_tpl->getValue('orderId');?>
</a></li>
        <li class="breadcrumb-item active">3D тур</li>
      </ol>
    </nav>
  </div>

  <section
    class="tour-shell"
    id="tourApp"
    data-session-id="<?php echo htmlspecialchars((string)$_smarty_tpl->getValue('sessionId'), ENT_QUOTES, 'UTF-8', true);?>
"
  >
    <aside class="tour-sidebar">
      <div class="tour-card">
        <div class="tour-title">Сессия #<?php echo $_smarty_tpl->getValue('sessionId');?>
</div>
        <div class="tour-muted">UUID: <?php echo htmlspecialchars((string)(($tmp = $_smarty_tpl->getValue('sessionUuid') ?? null)===null||$tmp==='' ? '-' ?? null : $tmp), ENT_QUOTES, 'UTF-8', true);?>
</div>
        <div class="tour-muted">Заявка: <?php echo htmlspecialchars((string)(($tmp = $_smarty_tpl->getValue('orderTitle') ?? null)===null||$tmp==='' ? '-' ?? null : $tmp), ENT_QUOTES, 'UTF-8', true);?>
</div>
      </div>

      <div class="tour-card">
        <div class="tour-section-title">Обработка</div>
        <div id="tourProcessingStatus" class="tour-badge tour-badge-muted">Загрузка...</div>

        <div class="tour-stats">
          <div>Detections: <span id="tourDetectionsCount">-</span></div>
          <div>PHOTO_POINT: <span id="tourPhotoDetections">-</span></div>
          <div>VIDEO_FRAME: <span id="tourVideoDetections">-</span></div>
        </div>
      </div>

      <div class="tour-card">
        <div class="tour-section-title">Метки</div>
        <div id="tourMarkers" class="tour-marker-list">
          <span class="tour-muted">Загрузка...</span>
        </div>
      </div>

      <div class="tour-card">
        <div class="tour-section-title">Карта точек</div>
        <div class="tour-map-controls">
          <button type="button" id="tourMapReset" class="btn btn-sm btn-outline-light">Сбросить расположение</button>
          <button type="button" id="tourAutoMapBtn" class="btn btn-sm btn-outline-info">Авторасставить по меткам</button>
          <button type="button" id="tourAutoMapOverwriteManualBtn" class="btn btn-sm btn-outline-warning">Перезаписать ручные позиции</button>
          <button type="button" id="tourMapFitBtn" class="btn btn-sm btn-outline-light">Вписать</button>
          <button type="button" id="tourMapZoomOutBtn" class="btn btn-sm btn-outline-light">−</button>
          <button type="button" id="tourMapZoomInBtn" class="btn btn-sm btn-outline-light">+</button>
        </div>
        <div class="tour-map-tabs">
          <button id="tourMap2dBtn" type="button" class="btn btn-sm btn-outline-light">2D карта</button>
          <button id="tourMap3dBtn" type="button" class="btn btn-sm btn-outline-light">3D карта</button>
        </div>
        <div id="tourMap2dPanel">
          <div id="tourMap" class="tour-map"></div>
        </div>
        <div id="tourMap3dPanel" style="display:none;">
          <div id="tour3dMapCanvas" class="tour-3d-map"></div>
          <div class="tour-muted">3D Map v0: схема точек съёмки и переходов, не метрическая модель помещения.</div>
        </div>
        <div id="tourMapMeta" class="tour-map-meta"></div>
        <div class="tour-map-legend">
          <span><i class="dot manual"></i> Manual</span>
          <span><i class="dot cov"></i> Marker co-visibility</span>
          <span><i class="dot nomarker"></i> No markers</span>
        </div>
        <div class="tour-muted">Перетащите точки для ручного размещения.<br>
          Авторасстановка v1: порядок точек + видимость меток. Без метрической точности.</div>
      </div>

      <div class="tour-card">
        <div class="tour-section-title">Metric readiness</div>
        <div id="tourMarkerLayoutSummary" class="tour-muted">Загрузка...</div>
      </div>
      <div class="tour-card">
        <div class="tour-section-title">Переходы</div>
        <select id="tourHotspotTargetPoint" class="form-select form-select-sm mb-2"></select>
        <button type="button" id="tourAddHotspotBtn" class="btn btn-sm btn-outline-info">Добавить переход здесь</button>
        <div class="tour-muted mt-2">Поверните панораму в сторону перехода, выберите целевую точку и нажмите добавить. Будут сохранены текущие yaw/pitch.</div>
        <div id="tourCurrentLinks" class="tour-link-list mt-2"></div>
      </div>
      <div class="tour-card">
        <div class="tour-section-title">Найденные метки в панораме</div>
        <div class="tour-settings-row"><label class="tour-muted"><input type="checkbox" id="tourShowMarkerHotspots" checked> Показывать MT-метки</label></div>
        <div class="tour-muted mt-1">Физические AprilTag на фото будут удаляться отдельной clean-обработкой позже.</div>
        <div id="tourDetectedMarkersList" class="tour-detected-markers-list">
          <span class="tour-muted">Выберите точку</span>
        </div>
        <div id="tourSelectedMarkerInfo" class="tour-selected-marker-info tour-muted">
          Кликните по MT-метке в панораме.
        </div>
      </div>

      <div class="tour-card">
        <div class="tour-section-title">Точки съемки</div>
        <div id="tourPoints" class="tour-points">
          <div class="tour-muted">Загрузка...</div>
        </div>
      </div>
    </aside>

    <section class="tour-viewer-area">
      <div class="tour-topbar">
        <div>
          <div id="tourCurrentPoint" class="tour-current-title">-</div>
          <div id="tourCurrentRoom" class="tour-muted">360 panorama</div>
        </div>

        <div>
          <div class="tour-nav-buttons">
            <div class="tour-quality-toggle">
              <span class="tour-muted">Качество:</span>
              <button type="button" id="tourQualityLightBtn" class="btn btn-sm btn-outline-light tour-quality-btn">Light</button>
              <button type="button" id="tourQualityHdBtn" class="btn btn-sm btn-outline-light tour-quality-btn">HD</button>
            </div>
            <button type="button" id="tourPrevPoint" class="btn btn-sm btn-outline-light">← Предыдущая</button>
            <button type="button" id="tourNextPoint" class="btn btn-sm btn-outline-light">Следующая →</button>
            <a class="btn btn-sm btn-outline-light" href="/order.php?id=<?php echo $_smarty_tpl->getValue('orderId');?>
">← Назад к заявке</a>
          </div>
        </div>
      </div>

      <div id="panorama" class="tour-panorama">
        <div class="tour-viewer-placeholder">Загрузка тура...</div>
      </div>
    </section>
  </section>
</main>

<?php echo '<script'; ?>
 src="https://cdn.jsdelivr.net/npm/pannellum/build/pannellum.js"><?php echo '</script'; ?>
>
<?php echo '<script'; ?>
 src="/vendor/three/three.min.js"><?php echo '</script'; ?>
>
<?php echo '<script'; ?>
 src="/js/maklertour_tour.js"><?php echo '</script'; ?>
>

<?php $_smarty_tpl->renderSubTemplate("file:maklertour_footer.html", $_smarty_tpl->cache_id, $_smarty_tpl->compile_id, 0, $_smarty_tpl->cache_lifetime, array(), (int) 0, $_smarty_current_dir);
}
}
