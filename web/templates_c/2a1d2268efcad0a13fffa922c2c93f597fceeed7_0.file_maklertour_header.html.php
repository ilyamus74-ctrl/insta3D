<?php
/* Smarty version 5.3.1, created on 2026-05-16 19:35:57
  from 'file:maklertour_header.html' */

/* @var \Smarty\Template $_smarty_tpl */
if ($_smarty_tpl->getCompiled()->isFresh($_smarty_tpl, array (
  'version' => '5.3.1',
  'unifunc' => 'content_6a08c71d5ba4c9_94127925',
  'has_nocache_code' => false,
  'file_dependency' => 
  array (
    '2a1d2268efcad0a13fffa922c2c93f597fceeed7' => 
    array (
      0 => 'maklertour_header.html',
      1 => 1778483306,
      2 => 'file',
    ),
  ),
  'includes' => 
  array (
  ),
))) {
function content_6a08c71d5ba4c9_94127925 (\Smarty\Template $_smarty_tpl) {
$_smarty_current_dir = '/home/makler/web/templates';
?><!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta content="width=device-width, initial-scale=1.0" name="viewport">
  <title>MaklerTour</title>
  <meta content="MaklerTour" name="description">

  <link href="/assets/img/favicon.png" rel="icon">
  <link href="https://fonts.gstatic.com" rel="preconnect">
  <link href="https://fonts.googleapis.com/css?family=Open+Sans:300,300i,400,400i,600,600i,700,700i|Nunito:300,300i,400,400i,600,600i,700,700i|Poppins:300,300i,400,400i,500,500i,600,600i,700,700i" rel="stylesheet">

  <link href="/assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
  <link href="/assets/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
  <link href="/assets/vendor/boxicons/css/boxicons.min.css" rel="stylesheet">
  <link href="/assets/vendor/quill/quill.snow.css" rel="stylesheet">
  <link href="/assets/vendor/quill/quill.bubble.css" rel="stylesheet">
  <link href="/assets/vendor/remixicon/remixicon.css" rel="stylesheet">
  <link href="/assets/vendor/simple-datatables/style.css" rel="stylesheet">
  <link href="/assets/css/style.css" rel="stylesheet">
</head>
<body>
<header id="header" class="header fixed-top d-flex align-items-center">
  <div class="d-flex align-items-center justify-content-between">
    <a href="/main.php" class="logo d-flex align-items-center">
      <span class="d-none d-lg-block">MaklerTour</span>
    </a>
    <i class="bi bi-list toggle-sidebar-btn"></i>
  </div>

  <nav class="header-nav ms-auto">
    <ul class="d-flex align-items-center">
      <li class="nav-item dropdown pe-3">
        <a class="nav-link nav-profile d-flex align-items-center pe-0" href="#" data-bs-toggle="dropdown">
          <span class="d-none d-md-block dropdown-toggle ps-2"><?php echo htmlspecialchars((string)(($tmp = $_smarty_tpl->getValue('current_user')['full_name'] ?? null)===null||$tmp==='' ? $_smarty_tpl->getValue('current_user')['username'] ?? null : $tmp), ENT_QUOTES, 'UTF-8', true);?>
</span>
        </a>
        <ul class="dropdown-menu dropdown-menu-end dropdown-menu-arrow profile">
          <li class="dropdown-header">
            <h6><?php echo htmlspecialchars((string)(($tmp = $_smarty_tpl->getValue('current_user')['full_name'] ?? null)===null||$tmp==='' ? $_smarty_tpl->getValue('current_user')['username'] ?? null : $tmp), ENT_QUOTES, 'UTF-8', true);?>
</h6>
            <span><?php echo htmlspecialchars((string)(($tmp = $_smarty_tpl->getValue('current_user')['role'] ?? null)===null||$tmp==='' ? 'USER' ?? null : $tmp), ENT_QUOTES, 'UTF-8', true);?>
</span>
          </li>
          <li><hr class="dropdown-divider"></li>
          <li>
            <a class="dropdown-item d-flex align-items-center" href="/logout.php">
              <i class="bi bi-box-arrow-right"></i>
              <span>Выйти</span>
            </a>
          </li>
        </ul>
      </li>
    </ul>
  </nav>
</header>
<?php }
}
