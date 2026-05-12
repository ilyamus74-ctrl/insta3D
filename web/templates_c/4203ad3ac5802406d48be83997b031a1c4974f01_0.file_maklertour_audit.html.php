<?php
/* Smarty version 5.3.1, created on 2026-05-11 07:58:29
  from 'file:maklertour_audit.html' */

/* @var \Smarty\Template $_smarty_tpl */
if ($_smarty_tpl->getCompiled()->isFresh($_smarty_tpl, array (
  'version' => '5.3.1',
  'unifunc' => 'content_6a018c255f9498_00753983',
  'has_nocache_code' => false,
  'file_dependency' => 
  array (
    '4203ad3ac5802406d48be83997b031a1c4974f01' => 
    array (
      0 => 'maklertour_audit.html',
      1 => 1778483361,
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
function content_6a018c255f9498_00753983 (\Smarty\Template $_smarty_tpl) {
$_smarty_current_dir = '/home/makler/web/templates';
$_smarty_tpl->renderSubTemplate("file:maklertour_header.html", $_smarty_tpl->cache_id, $_smarty_tpl->compile_id, 0, $_smarty_tpl->cache_lifetime, array(), (int) 0, $_smarty_current_dir);
$_smarty_tpl->renderSubTemplate("file:maklertour_sidebar.html", $_smarty_tpl->cache_id, $_smarty_tpl->compile_id, 0, $_smarty_tpl->cache_lifetime, array(), (int) 0, $_smarty_current_dir);
?>

<main id="main" class="main">

  <div class="pagetitle">
    <h1>Аудит</h1>
    <nav>
      <ol class="breadcrumb">
        <li class="breadcrumb-item">MaklerTour</li>
        <li class="breadcrumb-item active">Аудит</li>
      </ol>
    </nav>
  </div>

  <section class="section">
    <div class="card">
      <div class="card-body">
        <h5 class="card-title">Последние события</h5>

        <table class="table table-striped">
          <thead>
            <tr>
              <th>Время</th>
              <th>Пользователь</th>
              <th>Событие</th>
              <th>Сущность</th>
              <th>ID</th>
              <th>IP</th>
              <th>Описание</th>
              <th>Extra</th>
            </tr>
          </thead>
          <tbody>
            <?php
$_from = $_smarty_tpl->getSmarty()->getRuntime('Foreach')->init($_smarty_tpl, $_smarty_tpl->getValue('logs'), 'l');
$foreach0DoElse = true;
foreach ($_from ?? [] as $_smarty_tpl->getVariable('l')->value) {
$foreach0DoElse = false;
?>
              <tr>
                <td><?php echo $_smarty_tpl->getValue('l')['event_time'];?>
</td>
                <td>
                  <?php echo htmlspecialchars((string)(($tmp = $_smarty_tpl->getValue('l')['username'] ?? null)===null||$tmp==='' ? '-' ?? null : $tmp), ENT_QUOTES, 'UTF-8', true);?>
<br>
                  <small><?php echo htmlspecialchars((string)(($tmp = $_smarty_tpl->getValue('l')['email'] ?? null)===null||$tmp==='' ? '-' ?? null : $tmp), ENT_QUOTES, 'UTF-8', true);?>
</small>
                </td>
                <td><span class="badge bg-secondary"><?php echo $_smarty_tpl->getValue('l')['event_type'];?>
</span></td>
                <td><?php echo (($tmp = $_smarty_tpl->getValue('l')['entity_type'] ?? null)===null||$tmp==='' ? '-' ?? null : $tmp);?>
</td>
                <td><?php echo (($tmp = $_smarty_tpl->getValue('l')['entity_id'] ?? null)===null||$tmp==='' ? '-' ?? null : $tmp);?>
</td>
                <td><?php echo (($tmp = $_smarty_tpl->getValue('l')['ip_address'] ?? null)===null||$tmp==='' ? '-' ?? null : $tmp);?>
</td>
                <td><?php echo htmlspecialchars((string)(($tmp = $_smarty_tpl->getValue('l')['description'] ?? null)===null||$tmp==='' ? '-' ?? null : $tmp), ENT_QUOTES, 'UTF-8', true);?>
</td>
                <td><code><?php echo htmlspecialchars((string)(($tmp = $_smarty_tpl->getValue('l')['extra_data'] ?? null)===null||$tmp==='' ? '' ?? null : $tmp), ENT_QUOTES, 'UTF-8', true);?>
</code></td>
              </tr>
            <?php
}
if ($foreach0DoElse) {
?>
              <tr>
                <td colspan="8">Событий пока нет</td>
              </tr>
            <?php
}
$_smarty_tpl->getSmarty()->getRuntime('Foreach')->restore($_smarty_tpl, 1);?>
          </tbody>
        </table>

      </div>
    </div>
  </section>

</main>

<?php $_smarty_tpl->renderSubTemplate("file:maklertour_footer.html", $_smarty_tpl->cache_id, $_smarty_tpl->compile_id, 0, $_smarty_tpl->cache_lifetime, array(), (int) 0, $_smarty_current_dir);
}
}
