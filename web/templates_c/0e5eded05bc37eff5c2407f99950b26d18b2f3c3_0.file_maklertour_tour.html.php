<?php
/* Smarty version 5.3.1, created on 2026-05-14 16:12:28
  from 'file:maklertour_tour.html' */

/* @var \Smarty\Template $_smarty_tpl */
if ($_smarty_tpl->getCompiled()->isFresh($_smarty_tpl, array (
  'version' => '5.3.1',
  'unifunc' => 'content_6a05f46c692433_69947293',
  'has_nocache_code' => false,
  'file_dependency' => 
  array (
    '0e5eded05bc37eff5c2407f99950b26d18b2f3c3' => 
    array (
      0 => 'maklertour_tour.html',
      1 => 1778774528,
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
function content_6a05f46c692433_69947293 (\Smarty\Template $_smarty_tpl) {
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
        <div id="tourMap" class="tour-map"></div>
        <div class="tour-muted">Перетащите точки для ручного размещения.</div>
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
 src="/js/maklertour_tour.js"><?php echo '</script'; ?>
>

<?php $_smarty_tpl->renderSubTemplate("file:maklertour_footer.html", $_smarty_tpl->cache_id, $_smarty_tpl->compile_id, 0, $_smarty_tpl->cache_lifetime, array(), (int) 0, $_smarty_current_dir);
}
}
