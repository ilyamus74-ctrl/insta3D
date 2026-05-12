<?php
/* Smarty version 5.3.1, created on 2026-05-11 07:58:11
  from 'file:maklertour_sidebar.html' */

/* @var \Smarty\Template $_smarty_tpl */
if ($_smarty_tpl->getCompiled()->isFresh($_smarty_tpl, array (
  'version' => '5.3.1',
  'unifunc' => 'content_6a018c13c6a551_16295405',
  'has_nocache_code' => false,
  'file_dependency' => 
  array (
    '9d44941e68c6f4ae602f8adf0a31b925a1e84ec6' => 
    array (
      0 => 'maklertour_sidebar.html',
      1 => 1778483323,
      2 => 'file',
    ),
  ),
  'includes' => 
  array (
  ),
))) {
function content_6a018c13c6a551_16295405 (\Smarty\Template $_smarty_tpl) {
$_smarty_current_dir = '/home/makler/web/templates';
?><aside id="sidebar" class="sidebar">
  <ul class="sidebar-nav" id="sidebar-nav">
    <li class="nav-item"><a class="nav-link" href="/main.php"><i class="bi bi-grid"></i><span>Dashboard</span></a></li>
    <li class="nav-item"><a class="nav-link" href="/orders.php"><i class="bi bi-card-list"></i><span>Заявки</span></a></li>
    <li class="nav-item"><a class="nav-link" href="/market.php"><i class="bi bi-shop"></i><span>Рынок заявок</span></a></li>
    <?php if ($_smarty_tpl->getValue('current_user')['role'] == 'ADMIN') {?>
      <li class="nav-item"><a class="nav-link" href="/users.php"><i class="bi bi-people"></i><span>Пользователи</span></a></li>
      <li class="nav-item"><a class="nav-link" href="/audit.php"><i class="bi bi-journal-text"></i><span>Аудит</span></a></li>
    <?php }?>
  </ul>
</aside>
<?php }
}
