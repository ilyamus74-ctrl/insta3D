<?php
/* Smarty version 5.3.1, created on 2026-05-11 20:26:56
  from 'file:maklertour_order.html' */

/* @var \Smarty\Template $_smarty_tpl */
if ($_smarty_tpl->getCompiled()->isFresh($_smarty_tpl, array (
  'version' => '5.3.1',
  'unifunc' => 'content_6a023b90c57450_41695569',
  'has_nocache_code' => false,
  'file_dependency' => 
  array (
    '8a2d61e62d148c6ae1204a37e003257928b7557b' => 
    array (
      0 => 'maklertour_order.html',
      1 => 1778531114,
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
function content_6a023b90c57450_41695569 (\Smarty\Template $_smarty_tpl) {
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
" target="_blank">Preview</a> · <?php echo $_smarty_tpl->getValue('p')['preview_size_human'];?>
</div>
                      <div class="small"><a href="<?php echo htmlspecialchars((string)$_smarty_tpl->getValue('p')['original_url'], ENT_QUOTES, 'UTF-8', true);?>
" target="_blank">Original</a> · <?php echo $_smarty_tpl->getValue('p')['original_size_human'];?>
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

<?php $_smarty_tpl->renderSubTemplate("file:maklertour_footer.html", $_smarty_tpl->cache_id, $_smarty_tpl->compile_id, 0, $_smarty_tpl->cache_lifetime, array(), (int) 0, $_smarty_current_dir);
}
}
