<?php
/* Smarty version 5.3.1, created on 2026-05-16 19:35:54
  from 'file:cells_login_main.html' */

/* @var \Smarty\Template $_smarty_tpl */
if ($_smarty_tpl->getCompiled()->isFresh($_smarty_tpl, array (
  'version' => '5.3.1',
  'unifunc' => 'content_6a08c71a1f7228_01224397',
  'has_nocache_code' => false,
  'file_dependency' => 
  array (
    'cc88aa3b48d5121f09d8e28aafa30ddb41e08199' => 
    array (
      0 => 'cells_login_main.html',
      1 => 1764768166,
      2 => 'file',
    ),
  ),
  'includes' => 
  array (
    'file:cells_header.html' => 1,
    'file:cells_login.html' => 1,
  ),
))) {
function content_6a08c71a1f7228_01224397 (\Smarty\Template $_smarty_tpl) {
$_smarty_current_dir = '/home/makler/web/templates';
?><!DOCTYPE html>
<html lang="<?php echo $_smarty_tpl->getValue('xlang');?>
">
 <?php $_smarty_tpl->renderSubTemplate('file:cells_header.html', $_smarty_tpl->cache_id, $_smarty_tpl->compile_id, 0, $_smarty_tpl->cache_lifetime, array(), (int) 0, $_smarty_current_dir);
?>
    <body id="page-top">
         <!-- Masthead-->
<!--        <header class="masthead">
            <div class="container">
                <div class="masthead-subheading"><?php echo $_smarty_tpl->getSmarty()->getModifierCallback('__')("EasyTradingMain");?>
<br></div>
                <div class="masthead-heading text-uppercase"><?php echo $_smarty_tpl->getSmarty()->getModifierCallback('__')("EasyTradingSignalsMain");?>
<br><br><br><br></div>
                <?php if (!$_smarty_tpl->getValue('predictions')) {?>
                 <a class="btn btn-primary btn-xl text-uppercase" href="predictions.html"><?php echo $_smarty_tpl->getSmarty()->getModifierCallback('__')("ToPredictsButton");?>
</a>
                <?php }?>
            </div>
        </header>
-->
<?php if ($_smarty_tpl->getValue('login')) {
$_smarty_tpl->renderSubTemplate('file:cells_login.html', $_smarty_tpl->cache_id, $_smarty_tpl->compile_id, 0, $_smarty_tpl->cache_lifetime, array(), (int) 0, $_smarty_current_dir);
}?>

    </body>
</html>
<?php }
}
