<?php
/* Smarty version 5.3.1, created on 2026-05-11 07:58:28
  from 'file:maklertour_users.html' */

/* @var \Smarty\Template $_smarty_tpl */
if ($_smarty_tpl->getCompiled()->isFresh($_smarty_tpl, array (
  'version' => '5.3.1',
  'unifunc' => 'content_6a018c24a019c4_45129454',
  'has_nocache_code' => false,
  'file_dependency' => 
  array (
    '8a611fae855eb2bd7448e25366534bdbde51e6d7' => 
    array (
      0 => 'maklertour_users.html',
      1 => 1778483647,
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
function content_6a018c24a019c4_45129454 (\Smarty\Template $_smarty_tpl) {
$_smarty_current_dir = '/home/makler/web/templates';
$_smarty_tpl->renderSubTemplate("file:maklertour_header.html", $_smarty_tpl->cache_id, $_smarty_tpl->compile_id, 0, $_smarty_tpl->cache_lifetime, array(), (int) 0, $_smarty_current_dir);
$_smarty_tpl->renderSubTemplate("file:maklertour_sidebar.html", $_smarty_tpl->cache_id, $_smarty_tpl->compile_id, 0, $_smarty_tpl->cache_lifetime, array(), (int) 0, $_smarty_current_dir);
?>

<main id="main" class="main">

  <div class="pagetitle">
    <h1>Пользователи</h1>
    <nav>
      <ol class="breadcrumb">
        <li class="breadcrumb-item">MaklerTour</li>
        <li class="breadcrumb-item active">Пользователи</li>
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
        <h5 class="card-title">Список пользователей</h5>

        <table class="table table-striped">
          <thead>
            <tr>
              <th>ID</th>
              <th>Username</th>
              <th>Email</th>
              <th>ФИО</th>
              <th>Role</th>
              <th>Active</th>
              <th>Создан</th>
              <th>Последний вход</th>
              <th>Действие</th>
            </tr>
          </thead>
          <tbody>
            <?php
$_from = $_smarty_tpl->getSmarty()->getRuntime('Foreach')->init($_smarty_tpl, $_smarty_tpl->getValue('users'), 'u');
$foreach0DoElse = true;
foreach ($_from ?? [] as $_smarty_tpl->getVariable('u')->value) {
$foreach0DoElse = false;
?>
              <tr>
                <form method="post" action="/users.php">
                  <input type="hidden" name="action" value="update_user">
                  <input type="hidden" name="user_id" value="<?php echo $_smarty_tpl->getValue('u')['id'];?>
">

                  <td><?php echo $_smarty_tpl->getValue('u')['id'];?>
</td>
                  <td><?php echo htmlspecialchars((string)$_smarty_tpl->getValue('u')['username'], ENT_QUOTES, 'UTF-8', true);?>
</td>
                  <td><?php echo htmlspecialchars((string)$_smarty_tpl->getValue('u')['email'], ENT_QUOTES, 'UTF-8', true);?>
</td>
                  <td><?php echo htmlspecialchars((string)(($tmp = $_smarty_tpl->getValue('u')['full_name'] ?? null)===null||$tmp==='' ? '-' ?? null : $tmp), ENT_QUOTES, 'UTF-8', true);?>
</td>
                  <td>
                    <select class="form-select" name="role">
                      <option value="ADMIN" <?php if ($_smarty_tpl->getValue('u')['role'] === 'ADMIN') {?>selected<?php }?>>ADMIN</option>
                      <option value="BROKER" <?php if ($_smarty_tpl->getValue('u')['role'] === 'BROKER') {?>selected<?php }?>>BROKER</option>
                      <option value="OPERATOR" <?php if ($_smarty_tpl->getValue('u')['role'] === 'OPERATOR') {?>selected<?php }?>>OPERATOR</option>
                      <option value="CLIENT" <?php if ($_smarty_tpl->getValue('u')['role'] === 'CLIENT') {?>selected<?php }?>>CLIENT</option>
                    </select>
                  </td>
                  <td>
                    <select class="form-select" name="is_active">
                      <option value="1" <?php if ((int)$_smarty_tpl->getValue('u')['is_active'] === 1) {?>selected<?php }?>>1</option>
                      <option value="0" <?php if ((int)$_smarty_tpl->getValue('u')['is_active'] === 0) {?>selected<?php }?>>0</option>
                    </select>
                  </td>
                  <td><?php echo (($tmp = $_smarty_tpl->getValue('u')['created_at'] ?? null)===null||$tmp==='' ? '-' ?? null : $tmp);?>
</td>
                  <td><?php echo (($tmp = $_smarty_tpl->getValue('u')['last_login_at'] ?? null)===null||$tmp==='' ? '-' ?? null : $tmp);?>
</td>
                  <td>
                    <button type="submit" class="btn btn-sm btn-primary">Сохранить</button>
                  </td>
                </form>
              </tr>
            <?php
}
if ($foreach0DoElse) {
?>
              <tr>
                <td colspan="9">Пользователей пока нет</td>
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
