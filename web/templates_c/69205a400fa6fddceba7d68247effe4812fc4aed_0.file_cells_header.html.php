<?php
/* Smarty version 5.3.1, created on 2026-05-16 19:35:54
  from 'file:cells_header.html' */

/* @var \Smarty\Template $_smarty_tpl */
if ($_smarty_tpl->getCompiled()->isFresh($_smarty_tpl, array (
  'version' => '5.3.1',
  'unifunc' => 'content_6a08c71a20b1e6_10074387',
  'has_nocache_code' => false,
  'file_dependency' => 
  array (
    '69205a400fa6fddceba7d68247effe4812fc4aed' => 
    array (
      0 => 'cells_header.html',
      1 => 1764768173,
      2 => 'file',
    ),
  ),
  'includes' => 
  array (
  ),
))) {
function content_6a08c71a20b1e6_10074387 (\Smarty\Template $_smarty_tpl) {
$_smarty_current_dir = '/home/makler/web/templates';
?>    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no" />
        <meta name="description" content="<?php echo $_smarty_tpl->getSmarty()->getModifierCallback('__')($_smarty_tpl->getValue('header_data')['description']);?>
" />
        <title><?php echo $_smarty_tpl->getSmarty()->getModifierCallback('__')($_smarty_tpl->getValue('header_data')['title']);?>
</title>
        <meta name="keywords" content="<?php echo $_smarty_tpl->getSmarty()->getModifierCallback('__')($_smarty_tpl->getValue('header_data')['keywords']);?>
">
        <meta name="author" content="" />
        <!-- 🕸️ Canonical URL -->
        <link rel="canonical" href="<?php echo htmlspecialchars((string)$_smarty_tpl->getValue('canonical'), ENT_QUOTES, 'UTF-8', true);?>
">
        <?php
$_from = $_smarty_tpl->getSmarty()->getRuntime('Foreach')->init($_smarty_tpl, $_smarty_tpl->getValue('alternates'), 'url', false, 'hre');
$foreach0DoElse = true;
foreach ($_from ?? [] as $_smarty_tpl->getVariable('hre')->value => $_smarty_tpl->getVariable('url')->value) {
$foreach0DoElse = false;
?>
        <link rel="alternate" hreflang="<?php echo $_smarty_tpl->getValue('hre');?>
" href="<?php echo htmlspecialchars((string)$_smarty_tpl->getValue('url'), ENT_QUOTES, 'UTF-8', true);?>
">
        <?php
}
$_smarty_tpl->getSmarty()->getRuntime('Foreach')->restore($_smarty_tpl, 1);?>
        <link rel="alternate" hreflang="x-default" href="https://<?php echo $_smarty_tpl->getValue('domainName');?>
/">
        <meta property="og:locale" content="<?php echo htmlspecialchars((string)$_smarty_tpl->getValue('og_locale'), ENT_QUOTES, 'UTF-8', true);?>
">


        <!-- Favicon-->
        <link rel="icon" type="image/x-icon" href="https://<?php echo $_smarty_tpl->getValue('domainName');?>
/assets/favicon.ico" />
        <!-- Font Awesome icons (free version)-->
        <?php echo '<script'; ?>
 src="https://use.fontawesome.com/releases/v6.3.0/js/all.js" crossorigin="anonymous"><?php echo '</script'; ?>
>
        <!-- Google fonts-->
        <link href="https://fonts.googleapis.com/css?family=Montserrat:400,700" rel="stylesheet" type="text/css" />
        <link href="https://fonts.googleapis.com/css?family=Roboto+Slab:400,100,300,700" rel="stylesheet" type="text/css" />
        <!-- Core theme CSS (includes Bootstrap)-->
        <link href="https://<?php echo $_smarty_tpl->getValue('domainName');?>
/css/styles.css" rel="stylesheet" />

    </head>
<?php }
}
