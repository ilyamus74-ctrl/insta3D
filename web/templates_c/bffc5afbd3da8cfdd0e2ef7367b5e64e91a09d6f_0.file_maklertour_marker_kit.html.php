<?php
/* Smarty version 5.3.1, created on 2026-05-15 08:29:09
  from 'file:maklertour_marker_kit.html' */

/* @var \Smarty\Template $_smarty_tpl */
if ($_smarty_tpl->getCompiled()->isFresh($_smarty_tpl, array (
  'version' => '5.3.1',
  'unifunc' => 'content_6a06d95551cb45_60909676',
  'has_nocache_code' => false,
  'file_dependency' => 
  array (
    'bffc5afbd3da8cfdd0e2ef7367b5e64e91a09d6f' => 
    array (
      0 => 'maklertour_marker_kit.html',
      1 => 1778784443,
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
function content_6a06d95551cb45_60909676 (\Smarty\Template $_smarty_tpl) {
$_smarty_current_dir = '/home/makler/web/templates';
$_smarty_tpl->renderSubTemplate("file:maklertour_header.html", $_smarty_tpl->cache_id, $_smarty_tpl->compile_id, 0, $_smarty_tpl->cache_lifetime, array(), (int) 0, $_smarty_current_dir);
$_smarty_tpl->renderSubTemplate("file:maklertour_sidebar.html", $_smarty_tpl->cache_id, $_smarty_tpl->compile_id, 0, $_smarty_tpl->cache_lifetime, array(), (int) 0, $_smarty_current_dir);
?>
<main id="main" class="main">
  <div class="pagetitle"><h1>Marker Kit Layout</h1></div>
  <p>Здесь задаются реальные размеры, координаты и ориентация меток. Эти данные нужны для будущей метрической карты и расчёта расстояний.</p>
  <?php if ($_smarty_tpl->getValue('error')) {?><div class="alert alert-danger"><?php echo htmlspecialchars((string)$_smarty_tpl->getValue('error'), ENT_QUOTES, 'UTF-8', true);?>
</div><?php }?>
  <?php if ($_smarty_tpl->getValue('success')) {?><div class="alert alert-success"><?php echo htmlspecialchars((string)$_smarty_tpl->getValue('success'), ENT_QUOTES, 'UTF-8', true);?>
</div><?php }?>

  <div class="mb-3 d-flex gap-2">
    <a class="btn btn-sm btn-primary" href="/tools/seed_marker_kit_v1.php" target="_blank">Seed MT-001..MT-030</a>
    <a class="btn btn-sm btn-outline-secondary" href="/api/marker_kit_layout.php" target="_blank">Export JSON</a>
  </div>

  <div class="table-responsive">
    <table class="table table-striped table-sm" id="markerKitTable">
      <thead><tr><th>MT label</th><th>size_m</th><th>center_x_m</th><th>center_y_m</th><th>center_z_m</th><th>yaw_deg</th><th>pitch_deg</th><th>roll_deg</th><th>surface_type</th><th>note</th><th>actions</th></tr></thead>
      <tbody>
      <?php
$_from = $_smarty_tpl->getSmarty()->getRuntime('Foreach')->init($_smarty_tpl, $_smarty_tpl->getValue('markers'), 'm');
$foreach0DoElse = true;
foreach ($_from ?? [] as $_smarty_tpl->getVariable('m')->value) {
$foreach0DoElse = false;
?>
        <tr data-marker-id="<?php echo htmlspecialchars((string)$_smarty_tpl->getValue('m')['marker_id'], ENT_QUOTES, 'UTF-8', true);?>
">
          <td>MT-<?php echo sprintf("%03d",$_smarty_tpl->getValue('m')['marker_id']);?>
</td>
          <td><input class="form-control form-control-sm" type="number" step="0.0001" name="marker_size_m" value="<?php echo htmlspecialchars((string)(($tmp = $_smarty_tpl->getValue('m')['marker_size_m'] ?? null)===null||$tmp==='' ? '0.1600' ?? null : $tmp), ENT_QUOTES, 'UTF-8', true);?>
"></td>
          <td><input class="form-control form-control-sm" type="number" step="0.0001" name="center_x_m" value="<?php echo htmlspecialchars((string)(($tmp = $_smarty_tpl->getValue('m')['center_x_m'] ?? null)===null||$tmp==='' ? '0' ?? null : $tmp), ENT_QUOTES, 'UTF-8', true);?>
"></td>
          <td><input class="form-control form-control-sm" type="number" step="0.0001" name="center_y_m" value="<?php echo htmlspecialchars((string)(($tmp = $_smarty_tpl->getValue('m')['center_y_m'] ?? null)===null||$tmp==='' ? '0' ?? null : $tmp), ENT_QUOTES, 'UTF-8', true);?>
"></td>
          <td><input class="form-control form-control-sm" type="number" step="0.0001" name="center_z_m" value="<?php echo htmlspecialchars((string)(($tmp = $_smarty_tpl->getValue('m')['center_z_m'] ?? null)===null||$tmp==='' ? '1.2' ?? null : $tmp), ENT_QUOTES, 'UTF-8', true);?>
"></td>
          <td><input class="form-control form-control-sm" type="number" step="0.001" name="yaw_deg" value="<?php echo htmlspecialchars((string)(($tmp = $_smarty_tpl->getValue('m')['yaw_deg'] ?? null)===null||$tmp==='' ? '0' ?? null : $tmp), ENT_QUOTES, 'UTF-8', true);?>
"></td>
          <td><input class="form-control form-control-sm" type="number" step="0.001" name="pitch_deg" value="<?php echo htmlspecialchars((string)(($tmp = $_smarty_tpl->getValue('m')['pitch_deg'] ?? null)===null||$tmp==='' ? '0' ?? null : $tmp), ENT_QUOTES, 'UTF-8', true);?>
"></td>
          <td><input class="form-control form-control-sm" type="number" step="0.001" name="roll_deg" value="<?php echo htmlspecialchars((string)(($tmp = $_smarty_tpl->getValue('m')['roll_deg'] ?? null)===null||$tmp==='' ? '0' ?? null : $tmp), ENT_QUOTES, 'UTF-8', true);?>
"></td>
          <td><select class="form-select form-select-sm" name="surface_type"><option value="" <?php if (!$_smarty_tpl->getValue('m')['surface_type']) {?>selected<?php }?>></option><option value="WALL" <?php if ($_smarty_tpl->getValue('m')['surface_type'] == 'WALL') {?>selected<?php }?>>WALL</option><option value="FLOOR" <?php if ($_smarty_tpl->getValue('m')['surface_type'] == 'FLOOR') {?>selected<?php }?>>FLOOR</option><option value="CEILING" <?php if ($_smarty_tpl->getValue('m')['surface_type'] == 'CEILING') {?>selected<?php }?>>CEILING</option><option value="OBJECT" <?php if ($_smarty_tpl->getValue('m')['surface_type'] == 'OBJECT') {?>selected<?php }?>>OBJECT</option><option value="CALIBRATION_RIG" <?php if ($_smarty_tpl->getValue('m')['surface_type'] == 'CALIBRATION_RIG') {?>selected<?php }?>>CALIBRATION_RIG</option></select></td>
          <td><input class="form-control form-control-sm" type="text" maxlength="255" name="note" value="<?php echo htmlspecialchars((string)(($tmp = $_smarty_tpl->getValue('m')['note'] ?? null)===null||$tmp==='' ? '' ?? null : $tmp), ENT_QUOTES, 'UTF-8', true);?>
"></td>
          <td><button type="button" class="btn btn-sm btn-success js-save-row">Save row</button><div class="js-save-status small mt-1"></div></td>
        </tr>
      <?php
}
$_smarty_tpl->getSmarty()->getRuntime('Foreach')->restore($_smarty_tpl, 1);?>
      </tbody>
    </table>
  </div>
</main>
<?php echo '<script'; ?>
 src="/js/marker_kit_layout.js"><?php echo '</script'; ?>
>
<?php $_smarty_tpl->renderSubTemplate("file:maklertour_footer.html", $_smarty_tpl->cache_id, $_smarty_tpl->compile_id, 0, $_smarty_tpl->cache_lifetime, array(), (int) 0, $_smarty_current_dir);
}
}
