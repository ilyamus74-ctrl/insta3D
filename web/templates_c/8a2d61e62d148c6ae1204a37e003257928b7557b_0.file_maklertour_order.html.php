<?php
/* Smarty version 5.3.1, created on 2026-05-16 19:36:01
  from 'file:maklertour_order.html' */

/* @var \Smarty\Template $_smarty_tpl */
if ($_smarty_tpl->getCompiled()->isFresh($_smarty_tpl, array (
  'version' => '5.3.1',
  'unifunc' => 'content_6a08c7210a6cd4_80073942',
  'has_nocache_code' => false,
  'file_dependency' => 
  array (
    '8a2d61e62d148c6ae1204a37e003257928b7557b' => 
    array (
      0 => 'maklertour_order.html',
      1 => 1778960025,
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
function content_6a08c7210a6cd4_80073942 (\Smarty\Template $_smarty_tpl) {
$_smarty_current_dir = '/home/makler/web/templates';
$_smarty_tpl->renderSubTemplate("file:maklertour_header.html", $_smarty_tpl->cache_id, $_smarty_tpl->compile_id, 0, $_smarty_tpl->cache_lifetime, array(), (int) 0, $_smarty_current_dir);
$_smarty_tpl->renderSubTemplate("file:maklertour_sidebar.html", $_smarty_tpl->cache_id, $_smarty_tpl->compile_id, 0, $_smarty_tpl->cache_lifetime, array(), (int) 0, $_smarty_current_dir);
?>

<main id="main" class="main">

  <div class="pagetitle">
    <h1>Заявка #<?php echo $_smarty_tpl->getValue('order')['id'];?>
</h1>
    <nav>
      <ol class="breadcrumb">
        <li class="breadcrumb-item">MaklerTour</li>
        <li class="breadcrumb-item"><a href="/orders.php">Заявки</a></li>
        <li class="breadcrumb-item active">#<?php echo $_smarty_tpl->getValue('order')['id'];?>
</li>
      </ol>
    </nav>
  </div>

  <?php if ($_smarty_tpl->getValue('error')) {?>
    <div class="alert alert-danger"><?php echo $_smarty_tpl->getValue('error');?>
</div>
  <?php }?>

  <?php if ($_smarty_tpl->getValue('success')) {?>
    <div class="alert alert-success"><?php echo $_smarty_tpl->getValue('success');?>
</div>
  <?php }?>

  <section class="section">

    <div class="card">
      <div class="card-body">
        <h5 class="card-title">Объект</h5>
        <div class="mb-3 d-flex gap-2">
          <a href="javascript:history.back()" class="btn btn-outline-secondary">← Назад</a>
          <?php if ($_smarty_tpl->getValue('canEdit')) {?><a href="#order-edit" class="btn btn-primary">Редактировать</a><?php }?>
        </div>

        <table class="table">
          <tr>
            <th style="width:220px;">Название</th>
            <td><?php echo htmlspecialchars((string)$_smarty_tpl->getValue('order')['title'], ENT_QUOTES, 'UTF-8', true);?>
</td>
          </tr>
          <tr>
            <th>Адрес</th>
            <td><?php echo htmlspecialchars((string)$_smarty_tpl->getValue('order')['address'], ENT_QUOTES, 'UTF-8', true);?>
</td>
          </tr>
          <tr>
            <th>Площадь</th>
            <td><?php echo $_smarty_tpl->getValue('order')['area_m2'];?>
 м²</td>
          </tr>
          <tr>
            <th>Клиент</th>
            <td><?php echo htmlspecialchars((string)(($tmp = $_smarty_tpl->getValue('order')['customer_name'] ?? null)===null||$tmp==='' ? '-' ?? null : $tmp), ENT_QUOTES, 'UTF-8', true);?>
</td>
          </tr>
          <tr>
            <th>Телефон</th>
            <td><?php echo htmlspecialchars((string)(($tmp = $_smarty_tpl->getValue('order')['customer_phone'] ?? null)===null||$tmp==='' ? '-' ?? null : $tmp), ENT_QUOTES, 'UTF-8', true);?>
</td>
          </tr>
          <tr>
            <th>Email</th>
            <td><?php echo htmlspecialchars((string)(($tmp = $_smarty_tpl->getValue('order')['customer_email'] ?? null)===null||$tmp==='' ? '-' ?? null : $tmp), ENT_QUOTES, 'UTF-8', true);?>
</td>
          </tr>
          <tr>
            <th>Брокер</th>
            <td><?php echo htmlspecialchars((string)(($tmp = $_smarty_tpl->getValue('order')['broker_name'] ?? null)===null||$tmp==='' ? '-' ?? null : $tmp), ENT_QUOTES, 'UTF-8', true);?>
 / <?php echo htmlspecialchars((string)(($tmp = $_smarty_tpl->getValue('order')['broker_email'] ?? null)===null||$tmp==='' ? '-' ?? null : $tmp), ENT_QUOTES, 'UTF-8', true);?>
</td>
          </tr>
          <tr>
            <th>Оператор</th>
            <td><?php echo htmlspecialchars((string)(($tmp = $_smarty_tpl->getValue('order')['operator_name'] ?? null)===null||$tmp==='' ? '-' ?? null : $tmp), ENT_QUOTES, 'UTF-8', true);?>
 / <?php echo htmlspecialchars((string)(($tmp = $_smarty_tpl->getValue('order')['operator_email'] ?? null)===null||$tmp==='' ? '-' ?? null : $tmp), ENT_QUOTES, 'UTF-8', true);?>
</td>
          </tr>
          <tr>
            <th>Статус</th>
            <td><span class="badge <?php echo $_smarty_tpl->getValue('order')['status_meta']['class'];?>
"><i class="bi <?php echo $_smarty_tpl->getValue('order')['status_meta']['icon'];?>
"></i> <?php echo $_smarty_tpl->getValue('order')['status_meta']['label'];?>
</span></td>
          </tr>
            <tr><th>Публикация</th><td><?php if ($_smarty_tpl->getValue('order')['is_published'] == 1) {?><span class="badge bg-success">Опубликована</span><?php } else { ?><span class="badge bg-light text-dark">Черновик</span><?php }?></td></tr>
          <tr>
            <th>Публичный токен</th>
            <td><code><?php echo $_smarty_tpl->getValue('order')['public_token'];?>
</code></td>
          </tr>
        </table>

        <?php if ($_smarty_tpl->getValue('canEdit')) {?>
        <form id="order-edit" method="post" action="/order.php?id=<?php echo $_smarty_tpl->getValue('order')['id'];?>
" class="row g-2 mt-3">
          <input type="hidden" name="action" value="update_order">
          <div class="col-md-6"><input type="text" class="form-control" name="title" value="<?php echo htmlspecialchars((string)$_smarty_tpl->getValue('order')['title'], ENT_QUOTES, 'UTF-8', true);?>
" placeholder="Название"></div>
          <div class="col-md-6"><input type="text" class="form-control" name="address" value="<?php echo htmlspecialchars((string)$_smarty_tpl->getValue('order')['address'], ENT_QUOTES, 'UTF-8', true);?>
" placeholder="Адрес"></div>
          <div class="col-md-3"><input type="number" step="0.01" class="form-control" name="area_m2" value="<?php echo $_smarty_tpl->getValue('order')['area_m2'];?>
" placeholder="Площадь"></div>
          <div class="col-md-3"><input type="text" class="form-control" name="customer_name" value="<?php echo htmlspecialchars((string)$_smarty_tpl->getValue('order')['customer_name'], ENT_QUOTES, 'UTF-8', true);?>
" placeholder="Клиент"></div>
          <div class="col-md-3"><input type="text" class="form-control" name="customer_phone" value="<?php echo htmlspecialchars((string)$_smarty_tpl->getValue('order')['customer_phone'], ENT_QUOTES, 'UTF-8', true);?>
" placeholder="Телефон"></div>
          <div class="col-md-3"><input type="email" class="form-control" name="customer_email" value="<?php echo htmlspecialchars((string)$_smarty_tpl->getValue('order')['customer_email'], ENT_QUOTES, 'UTF-8', true);?>
" placeholder="Email"></div>
          <div class="col-12 form-check form-switch ms-1"><input class="form-check-input" type="checkbox" name="is_published" value="1" <?php if ($_smarty_tpl->getValue('order')['is_published'] == 1) {?>checked<?php }?>><label class="form-check-label">Опубликовать</label></div>
          <div class="col-12 d-flex gap-2"><button class="btn btn-primary" type="submit">Сохранить</button></div>
        </form>
        <form method="post" action="/order.php?id=<?php echo $_smarty_tpl->getValue('order')['id'];?>
" class="d-inline"><input type="hidden" name="action" value="close_order"><button class="btn btn-dark mt-2" type="submit">Закрыть</button></form>
        <form method="post" action="/order.php?id=<?php echo $_smarty_tpl->getValue('order')['id'];?>
" class="d-inline"><input type="hidden" name="action" value="reopen_order"><button class="btn btn-outline-secondary mt-2" type="submit">Переоткрыть</button></form>
        <?php }?>

        <div id="order-map-placeholder" class="mt-3">Карта будет здесь</div>

        <form method="post" action="/order.php?id=<?php echo $_smarty_tpl->getValue('order')['id'];?>
" class="row g-2" style="display:none">
          <input type="hidden" name="action" value="set_status">

          <div class="col-md-3">
            <select name="status" class="form-select">
              <option value="NEW" <?php if ($_smarty_tpl->getValue('order')['status'] == 'NEW') {?>selected<?php }?>>NEW</option>
              <option value="ASSIGNED" <?php if ($_smarty_tpl->getValue('order')['status'] == 'ASSIGNED') {?>selected<?php }?>>ASSIGNED</option>
              <option value="IN_PROGRESS" <?php if ($_smarty_tpl->getValue('order')['status'] == 'IN_PROGRESS') {?>selected<?php }?>>IN_PROGRESS</option>
              <option value="CAPTURED" <?php if ($_smarty_tpl->getValue('order')['status'] == 'CAPTURED') {?>selected<?php }?>>CAPTURED</option>
              <option value="UPLOADING" <?php if ($_smarty_tpl->getValue('order')['status'] == 'UPLOADING') {?>selected<?php }?>>UPLOADING</option>
              <option value="UPLOADED" <?php if ($_smarty_tpl->getValue('order')['status'] == 'UPLOADED') {?>selected<?php }?>>UPLOADED</option>
              <option value="PROCESSING" <?php if ($_smarty_tpl->getValue('order')['status'] == 'PROCESSING') {?>selected<?php }?>>PROCESSING</option>
              <option value="READY" <?php if ($_smarty_tpl->getValue('order')['status'] == 'READY') {?>selected<?php }?>>READY</option>
              <option value="CANCELLED" <?php if ($_smarty_tpl->getValue('order')['status'] == 'CANCELLED') {?>selected<?php }?>>CANCELLED</option>
            </select>
          </div>

          <div class="col-md-3">
            <button type="submit" class="btn btn-primary">Обновить статус</button>
          </div>
        </form>

      </div>
    </div>

    <div class="row g-3 mb-3">
      <div class="col-md-4">
        <div class="card"><div class="card-body"><h5 class="card-title">Sessions</h5><div class="display-6"><?php echo (($tmp = $_smarty_tpl->getValue('mediaTotals')['sessions'] ?? null)===null||$tmp==='' ? 0 ?? null : $tmp);?>
</div></div></div>

      </div>
      <div class="col-md-4">
        <div class="card"><div class="card-body"><h5 class="card-title">Photos</h5><div class="display-6"><?php echo (($tmp = $_smarty_tpl->getValue('mediaTotals')['photos'] ?? null)===null||$tmp==='' ? 0 ?? null : $tmp);?>
</div></div></div>
      </div>
      <div class="col-md-4">
        <div class="card"><div class="card-body"><h5 class="card-title">Videos</h5><div class="display-6"><?php echo (($tmp = $_smarty_tpl->getValue('mediaTotals')['videos'] ?? null)===null||$tmp==='' ? 0 ?? null : $tmp);?>
</div></div></div>

      </div>
    </div>

    <?php
$_from = $_smarty_tpl->getSmarty()->getRuntime('Foreach')->init($_smarty_tpl, $_smarty_tpl->getValue('captureSessions'), 's');
$foreach0DoElse = true;
foreach ($_from ?? [] as $_smarty_tpl->getVariable('s')->value) {
$foreach0DoElse = false;
?>
      <div class="card">
        <div class="card-body">
          <h5 class="card-title">Capture session #<?php echo $_smarty_tpl->getValue('s')['id'];?>
</h5>

<?php if ((($tmp = $_smarty_tpl->getValue('s')['photo_count'] ?? null)===null||$tmp==='' ? 0 ?? null : $tmp) > 0) {?>
<a class="btn btn-sm btn-outline-primary mb-2" href="/tour.php?session_id=<?php echo $_smarty_tpl->getValue('s')['id'];?>
">
  Открыть 3D тур
</a>
<?php }?>
          <div class="mb-2" id="publicLinkState<?php echo $_smarty_tpl->getValue('s')['id'];?>
">
            <?php if ($_smarty_tpl->getValue('s')['public_link'] && $_smarty_tpl->getValue('s')['public_link']['is_active'] == 1) {?>
              <span class="badge bg-success">Публичная ссылка активна</span>
              <div class="input-group input-group-sm mt-2" style="max-width:720px;">
                <input id="pubLink<?php echo $_smarty_tpl->getValue('s')['id'];?>
" class="form-control" readonly value="/public_tour.php?t=<?php echo htmlspecialchars((string)$_smarty_tpl->getValue('s')['public_link']['token'], ENT_QUOTES, 'UTF-8', true);?>
">
                <button type="button" class="btn btn-outline-primary" onclick="window.open(document.getElementById('pubLink<?php echo $_smarty_tpl->getValue('s')['id'];?>
').value,'_blank')">Открыть</button>
                <?php if ($_smarty_tpl->getValue('canCreatePublicLink')) {?><button type="button" class="btn btn-outline-secondary" onclick="copyPublicLink(<?php echo $_smarty_tpl->getValue('s')['id'];?>
)">Скопировать</button><button type="button" class="btn btn-outline-danger" onclick="disablePublicLink(<?php echo $_smarty_tpl->getValue('s')['id'];?>
)">Отключить</button><?php }?>
              </div>
            <?php } else { ?>
              <span class="badge bg-secondary">Публичная ссылка не создана</span>
              <input type="hidden" id="pubLink<?php echo $_smarty_tpl->getValue('s')['id'];?>
" value="">
              <?php if ($_smarty_tpl->getValue('canCreatePublicLink')) {?><button type="button" class="btn btn-sm btn-outline-primary ms-2" onclick="createPublicLink(<?php echo $_smarty_tpl->getValue('s')['id'];?>
)">Создать публичную ссылку</button><?php }?>
            <?php }?>
          </div>
          <div class="row g-3 mb-3">
            <div class="col-md-6"><strong>UUID:</strong> <code><?php echo htmlspecialchars((string)(($tmp = $_smarty_tpl->getValue('s')['app_session_uuid'] ?? null)===null||$tmp==='' ? '-' ?? null : $tmp), ENT_QUOTES, 'UTF-8', true);?>
</code></div>
            <div class="col-md-3"><strong>Camera:</strong> <?php echo htmlspecialchars((string)(($tmp = $_smarty_tpl->getValue('s')['camera_model'] ?? null)===null||$tmp==='' ? '-' ?? null : $tmp), ENT_QUOTES, 'UTF-8', true);?>
</div>
            <div class="col-md-3"><strong>Status:</strong> <span class="badge bg-secondary"><?php echo htmlspecialchars((string)(($tmp = $_smarty_tpl->getValue('s')['status'] ?? null)===null||$tmp==='' ? '-' ?? null : $tmp), ENT_QUOTES, 'UTF-8', true);?>
</span></div>
            <div class="col-md-3"><strong>Started:</strong> <?php echo (($tmp = $_smarty_tpl->getValue('s')['started_at'] ?? null)===null||$tmp==='' ? '-' ?? null : $tmp);?>
</div>
            <div class="col-md-3"><strong>Completed:</strong> <?php echo (($tmp = $_smarty_tpl->getValue('s')['completed_at'] ?? null)===null||$tmp==='' ? '-' ?? null : $tmp);?>
</div>
            <div class="col-md-3"><strong>Photos:</strong> <?php echo (($tmp = $_smarty_tpl->getValue('s')['photo_count'] ?? null)===null||$tmp==='' ? 0 ?? null : $tmp);?>
</div>
            <div class="col-md-3"><strong>Videos:</strong> <?php echo (($tmp = $_smarty_tpl->getValue('s')['video_count'] ?? null)===null||$tmp==='' ? 0 ?? null : $tmp);?>
</div>
          </div>

          <h6 class="mb-3">Photos</h6>
          <?php if ($_smarty_tpl->getSmarty()->getModifierCallback('count')($_smarty_tpl->getValue('s')['photos']) > 0) {?>
            <div class="row g-3">
              <?php
$_from = $_smarty_tpl->getSmarty()->getRuntime('Foreach')->init($_smarty_tpl, $_smarty_tpl->getValue('s')['photos'], 'p');
$foreach1DoElse = true;
foreach ($_from ?? [] as $_smarty_tpl->getVariable('p')->value) {
$foreach1DoElse = false;
?>
                <div class="col-md-4 col-lg-3">
                  <div class="card h-100">
                    <div class="card-body">
                      <?php if ($_smarty_tpl->getValue('p')['preview_url']) {?>
                        <img src="<?php echo htmlspecialchars((string)$_smarty_tpl->getValue('p')['preview_url'], ENT_QUOTES, 'UTF-8', true);?>
" class="img-fluid rounded mb-2" alt="<?php echo htmlspecialchars((string)$_smarty_tpl->getValue('p')['display_name'], ENT_QUOTES, 'UTF-8', true);?>
">
                      <?php } else { ?>
                        <div class="border rounded p-3 text-muted text-center mb-2">No preview</div>
                      <?php }?>
                      <div><strong><?php echo htmlspecialchars((string)$_smarty_tpl->getValue('p')['display_name'], ENT_QUOTES, 'UTF-8', true);?>
</strong></div>
                      <div class="small text-muted">#<?php echo (($tmp = $_smarty_tpl->getValue('p')['sequence_number'] ?? null)===null||$tmp==='' ? '-' ?? null : $tmp);?>
, room: <?php echo htmlspecialchars((string)(($tmp = $_smarty_tpl->getValue('p')['room_name'] ?? null)===null||$tmp==='' ? '-' ?? null : $tmp), ENT_QUOTES, 'UTF-8', true);?>
</div>
                      <div class="mt-1"><span class="badge bg-secondary"><?php echo htmlspecialchars((string)(($tmp = $_smarty_tpl->getValue('p')['upload_state'] ?? null)===null||$tmp==='' ? '-' ?? null : $tmp), ENT_QUOTES, 'UTF-8', true);?>
</span></div>
                      <div class="small mt-2"><a href="<?php echo htmlspecialchars((string)$_smarty_tpl->getValue('p')['preview_url'], ENT_QUOTES, 'UTF-8', true);?>
" target="_blank">Preview</a> (uploaded app preview) · <?php echo $_smarty_tpl->getValue('p')['preview_size_human'];?>
</div>
                      <div class="small"><a href="<?php echo htmlspecialchars((string)$_smarty_tpl->getValue('p')['original_url'], ENT_QUOTES, 'UTF-8', true);?>
" target="_blank">Original</a> (uploaded original) · <?php echo $_smarty_tpl->getValue('p')['original_size_human'];?>
</div>
                      <div class="small mt-2"><code><?php echo htmlspecialchars((string)(($tmp = $_smarty_tpl->getValue('p')['preview_effective_path'] ?? null)===null||$tmp==='' ? '-' ?? null : $tmp), ENT_QUOTES, 'UTF-8', true);?>
</code></div>
                      <div class="small"><code><?php echo htmlspecialchars((string)(($tmp = $_smarty_tpl->getValue('p')['original_effective_path'] ?? null)===null||$tmp==='' ? '-' ?? null : $tmp), ENT_QUOTES, 'UTF-8', true);?>
</code></div>
                    </div>
                  </div>
                </div>
              <?php
}
$_smarty_tpl->getSmarty()->getRuntime('Foreach')->restore($_smarty_tpl, 1);?>
            </div>
          <?php } else { ?>
            <div class="text-muted">Фото в этой сессии пока нет.</div>
          <?php }?>

          <h6 class="mt-4 mb-3">Videos</h6>
          <?php if ($_smarty_tpl->getSmarty()->getModifierCallback('count')($_smarty_tpl->getValue('s')['videos']) > 0) {?>
            <div class="row g-3">
              <?php
$_from = $_smarty_tpl->getSmarty()->getRuntime('Foreach')->init($_smarty_tpl, $_smarty_tpl->getValue('s')['videos'], 'v');
$foreach2DoElse = true;
foreach ($_from ?? [] as $_smarty_tpl->getVariable('v')->value) {
$foreach2DoElse = false;
?>
                <div class="col-md-6">
                  <div class="card h-100">
                    <div class="card-body">
                      <div><strong><?php echo htmlspecialchars((string)(($tmp = $_smarty_tpl->getValue('v')['filename'] ?? null)===null||$tmp==='' ? '-' ?? null : $tmp), ENT_QUOTES, 'UTF-8', true);?>
</strong></div>
                      <div class="small">Size: <?php echo (($tmp = $_smarty_tpl->getValue('v')['size_human'] ?? null)===null||$tmp==='' ? '-' ?? null : $tmp);?>
, duration: <?php echo (($tmp = $_smarty_tpl->getValue('v')['duration_sec'] ?? null)===null||$tmp==='' ? '-' ?? null : $tmp);?>
</div>
                      <div class="small">Upload: <span class="badge bg-secondary"><?php echo htmlspecialchars((string)(($tmp = $_smarty_tpl->getValue('v')['upload_state'] ?? null)===null||$tmp==='' ? '-' ?? null : $tmp), ENT_QUOTES, 'UTF-8', true);?>
</span> Processing: <span class="badge bg-secondary"><?php echo htmlspecialchars((string)(($tmp = $_smarty_tpl->getValue('v')['processing_state'] ?? null)===null||$tmp==='' ? '-' ?? null : $tmp), ENT_QUOTES, 'UTF-8', true);?>
</span></div>
                      <div class="small mt-1"><code><?php echo htmlspecialchars((string)(($tmp = $_smarty_tpl->getValue('v')['storage_path'] ?? null)===null||$tmp==='' ? '-' ?? null : $tmp), ENT_QUOTES, 'UTF-8', true);?>
</code></div>
                      <?php if ($_smarty_tpl->getValue('v')['media_url']) {?>
                      <video controls preload="metadata" class="w-100 mt-2" style="max-height:280px;">
                        <source src="<?php echo htmlspecialchars((string)$_smarty_tpl->getValue('v')['media_url'], ENT_QUOTES, 'UTF-8', true);?>
" type="video/mp4">
                      </video>
                      <div class="mt-2"><a href="<?php echo htmlspecialchars((string)$_smarty_tpl->getValue('v')['media_url'], ENT_QUOTES, 'UTF-8', true);?>
" target="_blank">Открыть видео в новой вкладке</a></div>
                      <?php }?>
                    </div>
                  </div>
                </div>
              <?php
}
$_smarty_tpl->getSmarty()->getRuntime('Foreach')->restore($_smarty_tpl, 1);?>
            </div>
          <?php } else { ?>
            <div class="text-muted">Видео в этой сессии пока нет.</div>
          <?php }?>

          <h6 class="mt-4 mb-3">Обработка / Метки</h6>
          <?php $_smarty_tpl->assign('job', $_smarty_tpl->getValue('s')['processing_job'], false, NULL);?>
          <?php if ($_smarty_tpl->getValue('job')) {?>
            <div class="card border-light bg-light-subtle">
              <div class="card-body">
                <div><strong>marker kit:</strong> MaklerTour Kit v1</div>
                <div><strong>expected IDs:</strong> 1–30</div>
                <div><strong>marker size:</strong> 160 mm</div>
                <div><strong>processing status:</strong> <span class="badge bg-secondary"><?php echo htmlspecialchars((string)(($tmp = $_smarty_tpl->getValue('job')['status'] ?? null)===null||$tmp==='' ? '-' ?? null : $tmp), ENT_QUOTES, 'UTF-8', true);?>
</span></div>
                <div><strong>metric status:</strong> <span class="badge bg-secondary"><?php echo htmlspecialchars((string)(($tmp = $_smarty_tpl->getValue('job')['metric_status'] ?? null)===null||$tmp==='' ? '-' ?? null : $tmp), ENT_QUOTES, 'UTF-8', true);?>
</span></div>
                <div><strong>markers detected count:</strong> <?php echo (($tmp = $_smarty_tpl->getValue('job')['markers_detected_count'] ?? null)===null||$tmp==='' ? 0 ?? null : $tmp);?>
</div>
                <div class="mt-2"><strong>unique marker IDs found:</strong>
                  <?php if ($_smarty_tpl->getSmarty()->getModifierCallback('count')($_smarty_tpl->getValue('s')['marker_unique_ids']) > 0) {?>
                    <?php
$_from = $_smarty_tpl->getSmarty()->getRuntime('Foreach')->init($_smarty_tpl, $_smarty_tpl->getValue('s')['marker_unique_ids'], 'mid', false, NULL, 'ml', array (
  'last' => true,
  'iteration' => true,
  'total' => true,
));
$foreach3DoElse = true;
foreach ($_from ?? [] as $_smarty_tpl->getVariable('mid')->value) {
$foreach3DoElse = false;
$_smarty_tpl->tpl_vars['__smarty_foreach_ml']->value['iteration']++;
$_smarty_tpl->tpl_vars['__smarty_foreach_ml']->value['last'] = $_smarty_tpl->tpl_vars['__smarty_foreach_ml']->value['iteration'] === $_smarty_tpl->tpl_vars['__smarty_foreach_ml']->value['total'];
?>
                      MT-<?php echo sprintf("%03d",$_smarty_tpl->getValue('mid'));
if (!($_smarty_tpl->getValue('__smarty_foreach_ml')['last'] ?? null)) {?>, <?php }?>
                    <?php
}
$_smarty_tpl->getSmarty()->getRuntime('Foreach')->restore($_smarty_tpl, 1);?>
                  <?php } else { ?>
                    -
                  <?php }?>
                </div>
                <div><strong>total detections:</strong> <?php echo (($tmp = $_smarty_tpl->getValue('s')['marker_detections_count'] ?? null)===null||$tmp==='' ? 0 ?? null : $tmp);?>
</div>
                <div><strong>PHOTO_POINT count:</strong> <?php echo (($tmp = $_smarty_tpl->getValue('s')['marker_source_counts']['PHOTO_POINT'] ?? null)===null||$tmp==='' ? 0 ?? null : $tmp);?>
</div>
                <div><strong>VIDEO_FRAME count:</strong> <?php echo (($tmp = $_smarty_tpl->getValue('s')['marker_source_counts']['VIDEO_FRAME'] ?? null)===null||$tmp==='' ? 0 ?? null : $tmp);?>
</div>
                <div><strong>warning text:</strong> <?php echo htmlspecialchars((string)(($tmp = $_smarty_tpl->getValue('job')['warning_text'] ?? null)===null||$tmp==='' ? '-' ?? null : $tmp), ENT_QUOTES, 'UTF-8', true);?>
</div>
                <div><strong>error text:</strong> <?php echo htmlspecialchars((string)(($tmp = $_smarty_tpl->getValue('job')['error_text'] ?? null)===null||$tmp==='' ? '-' ?? null : $tmp), ENT_QUOTES, 'UTF-8', true);?>
</div>
                <?php if ($_smarty_tpl->getValue('job')['status'] == 'PROCESSED' && $_smarty_tpl->getValue('job')['metric_status'] == 'NO_MARKERS') {?>
                  <div class="alert alert-warning mt-3 mb-0">Метки не обнаружены или detector ещё не выполнил распознавание. Точная геометрия и размеры не гарантируются.</div>
                <?php } elseif ($_smarty_tpl->getValue('job')['metric_status'] == 'METRIC_READY') {?>
                  <div class="alert alert-success mt-3 mb-0">Метки обнаружены. Данные подходят для метрической реконструкции.</div>
                <?php }?>
              </div>
            </div>
            <?php if ($_smarty_tpl->getSmarty()->getModifierCallback('count')($_smarty_tpl->getValue('s')['marker_detections']) > 0) {?>
              <div class="table-responsive mt-3">
                <table class="table table-sm table-bordered align-middle">
                  <thead>
                    <tr>
                      <th>marker ID</th><th>source type</th><th>source id</th><th>frame</th><th>confidence</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php
$_from = $_smarty_tpl->getSmarty()->getRuntime('Foreach')->init($_smarty_tpl, $_smarty_tpl->getValue('s')['marker_detections'], 'det', false, NULL, 'dets', array (
  'iteration' => true,
));
$foreach4DoElse = true;
foreach ($_from ?? [] as $_smarty_tpl->getVariable('det')->value) {
$foreach4DoElse = false;
$_smarty_tpl->tpl_vars['__smarty_foreach_dets']->value['iteration']++;
?>
                      <?php if (($_smarty_tpl->getValue('__smarty_foreach_dets')['iteration'] ?? null) > 30) {
break 1;
}?>
                      <tr>
                        <td>MT-<?php echo sprintf("%03d",$_smarty_tpl->getValue('det')['marker_id']);?>
</td>
                        <td><?php echo htmlspecialchars((string)$_smarty_tpl->getValue('det')['source_type'], ENT_QUOTES, 'UTF-8', true);?>
</td>
                        <td><?php echo htmlspecialchars((string)$_smarty_tpl->getValue('det')['source_id'], ENT_QUOTES, 'UTF-8', true);?>
</td>
                        <td><?php echo htmlspecialchars((string)(($tmp = $_smarty_tpl->getValue('det')['frame_index'] ?? null)===null||$tmp==='' ? '-' ?? null : $tmp), ENT_QUOTES, 'UTF-8', true);?>
</td>
                        <td><?php echo htmlspecialchars((string)(($tmp = $_smarty_tpl->getValue('det')['confidence'] ?? null)===null||$tmp==='' ? '-' ?? null : $tmp), ENT_QUOTES, 'UTF-8', true);?>
</td>
                      </tr>
                    <?php
}
$_smarty_tpl->getSmarty()->getRuntime('Foreach')->restore($_smarty_tpl, 1);?>
                  </tbody>
                </table>
              </div>
            <?php }?>
          <?php } else { ?>
            <div class="text-muted">Обработка ещё не запускалась.</div>
          <?php }?>
          <form method="post" action="/order.php?id=<?php echo $_smarty_tpl->getValue('order')['id'];?>
" class="mt-3">
            <input type="hidden" name="action" value="create_processing_job_web">
            <input type="hidden" name="capture_session_id" value="<?php echo $_smarty_tpl->getValue('s')['id'];?>
">
            <button type="submit" class="btn btn-outline-primary btn-sm">Запустить обработку меток</button>
          </form>
        </div>

      </div>
    <?php
}
if ($foreach0DoElse) {
?>
      <div class="card"><div class="card-body"><div class="text-muted">По этой заявке пока нет capture sessions.</div></div></div>
    <?php
}
$_smarty_tpl->getSmarty()->getRuntime('Foreach')->restore($_smarty_tpl, 1);?>
  </section>

</main>

<?php echo '<script'; ?>
>
function publicLinkBlockHtml(sessionId, relativeUrl, isActive, canManage){
  if(isActive){
    return '<span class="badge bg-success">Публичная ссылка активна</span>'+
      '<div class="input-group input-group-sm mt-2" style="max-width:720px;">'+
      '<input id="pubLink'+sessionId+'" class="form-control form-control-sm" readonly value="'+relativeUrl+'">'+
      '<button type="button" class="btn btn-sm btn-outline-primary" onclick="window.open(document.getElementById(\'pubLink'+sessionId+'\').value,\'_blank\')">Открыть</button>'+
      (canManage?'<button type="button" class="btn btn-sm btn-outline-secondary" onclick="copyPublicLink('+sessionId+')">Скопировать</button><button type="button" class="btn btn-sm btn-outline-danger" onclick="disablePublicLink('+sessionId+')">Отключить</button>':'')+
      '</div>';
  }
  return '<span class="badge bg-secondary">Публичная ссылка не создана</span>'+
    '<input type="hidden" id="pubLink'+sessionId+'" value="">'+
    (canManage?'<button type="button" class="btn btn-sm btn-outline-primary ms-2" onclick="createPublicLink('+sessionId+')">Создать публичную ссылку</button>':'');
}
function renderPublicLinkState(sessionId, relativeUrl, isActive){
  var box=document.getElementById('publicLinkState'+sessionId); if(!box) return;
  box.innerHTML=publicLinkBlockHtml(sessionId, relativeUrl||'', !!isActive, true);
}
async function createPublicLink(sessionId){const r=await fetch('/api/public_tour_link_create.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({session_id:Number(sessionId)})});const d=await r.json();if(!r.ok||!d.ok)return alert(d.error||'Ошибка');renderPublicLinkState(sessionId,d.url||'',true);}
function copyPublicLink(sessionId){const el=document.getElementById('pubLink'+sessionId);if(!el||!el.value)return;const abs=location.origin+el.value;(navigator.clipboard&&navigator.clipboard.writeText)?navigator.clipboard.writeText(abs).then(()=>{}):window.prompt('Скопируйте ссылку',abs);}
async function disablePublicLink(sessionId){if(!confirm('Отключить публичную ссылку?'))return;const r=await fetch('/api/public_tour_link_disable.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({session_id:Number(sessionId)})});const d=await r.json();if(!r.ok||!d.ok)return alert(d.error||'Ошибка');renderPublicLinkState(sessionId,'',false);}
<?php echo '</script'; ?>
>

<?php $_smarty_tpl->renderSubTemplate("file:maklertour_footer.html", $_smarty_tpl->cache_id, $_smarty_tpl->compile_id, 0, $_smarty_tpl->cache_lifetime, array(), (int) 0, $_smarty_current_dir);
}
}
