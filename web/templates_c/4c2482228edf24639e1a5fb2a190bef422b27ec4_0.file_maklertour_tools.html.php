<?php
/* Smarty version 5.3.1, created on 2026-05-13 19:22:28
  from 'file:maklertour_tools.html' */

/* @var \Smarty\Template $_smarty_tpl */
if ($_smarty_tpl->getCompiled()->isFresh($_smarty_tpl, array (
  'version' => '5.3.1',
  'unifunc' => 'content_6a04cf744a3b82_04614764',
  'has_nocache_code' => false,
  'file_dependency' => 
  array (
    '4c2482228edf24639e1a5fb2a190bef422b27ec4' => 
    array (
      0 => 'maklertour_tools.html',
      1 => 1778700037,
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
function content_6a04cf744a3b82_04614764 (\Smarty\Template $_smarty_tpl) {
$_smarty_current_dir = '/home/makler/web/templates';
$_smarty_tpl->renderSubTemplate("file:maklertour_header.html", $_smarty_tpl->cache_id, $_smarty_tpl->compile_id, 0, $_smarty_tpl->cache_lifetime, array(), (int) 0, $_smarty_current_dir);
$_smarty_tpl->renderSubTemplate("file:maklertour_sidebar.html", $_smarty_tpl->cache_id, $_smarty_tpl->compile_id, 0, $_smarty_tpl->cache_lifetime, array(), (int) 0, $_smarty_current_dir);
?>

<main id="main" class="main">

  <div class="pagetitle">
    <h1>Инструменты</h1>
    <nav>
      <ol class="breadcrumb">
        <li class="breadcrumb-item">MaklerTour</li>
        <li class="breadcrumb-item active">Инструменты</li>
      </ol>
    </nav>
  </div>

  <section class="section">
    <div class="row g-3">
      <div class="col-12 col-xl-6">
        <div class="card h-100">
          <div class="card-body">
            <h5 class="card-title">MaklerTour Marker Kit v1</h5>
            <p class="text-muted mb-3">Печатные AprilTag-метки для восстановления масштаба и геометрии.</p>
            <div class="d-flex gap-2 flex-wrap">
              <a class="btn btn-primary" href="/markers.php">Открыть набор</a>
              <a class="btn btn-outline-primary" href="/markers.php?print=all" target="_blank">Печать всего комплекта</a>
            </div>
          </div>
        </div>
      </div>

      <div class="col-12 col-xl-6">
        <div class="card h-100">
          <div class="card-body">
            <h5 class="card-title">Инструкция оператору</h5>
            <ul class="mb-0">
              <?php
$_from = $_smarty_tpl->getSmarty()->getRuntime('Foreach')->init($_smarty_tpl, $_smarty_tpl->getValue('operatorChecklist'), 'step');
$foreach0DoElse = true;
foreach ($_from ?? [] as $_smarty_tpl->getVariable('step')->value) {
$foreach0DoElse = false;
?>
                <li><?php echo htmlspecialchars((string)$_smarty_tpl->getValue('step'), ENT_QUOTES, 'UTF-8', true);?>
</li>
              <?php
}
$_smarty_tpl->getSmarty()->getRuntime('Foreach')->restore($_smarty_tpl, 1);?>
            </ul>
          </div>
        </div>
      </div>

      <div class="col-12 col-xl-6">
        <div class="card h-100">
          <div class="card-body">
            <h5 class="card-title">Insta360 X4</h5>
            <ul class="mb-0">
              <?php
$_from = $_smarty_tpl->getSmarty()->getRuntime('Foreach')->init($_smarty_tpl, $_smarty_tpl->getValue('insta360Checklist'), 'step');
$foreach1DoElse = true;
foreach ($_from ?? [] as $_smarty_tpl->getVariable('step')->value) {
$foreach1DoElse = false;
?>
                <li><?php echo htmlspecialchars((string)$_smarty_tpl->getValue('step'), ENT_QUOTES, 'UTF-8', true);?>
</li>
              <?php
}
$_smarty_tpl->getSmarty()->getRuntime('Foreach')->restore($_smarty_tpl, 1);?>
            </ul>
          </div>
        </div>
      </div>

      <div class="col-12 col-xl-6">
        <div class="card h-100">
          <div class="card-body">
            <h5 class="card-title">Правила печати меток</h5>
            <ul class="mb-0">
              <?php
$_from = $_smarty_tpl->getSmarty()->getRuntime('Foreach')->init($_smarty_tpl, $_smarty_tpl->getValue('printRules'), 'rule');
$foreach2DoElse = true;
foreach ($_from ?? [] as $_smarty_tpl->getVariable('rule')->value) {
$foreach2DoElse = false;
?>
                <li><?php echo htmlspecialchars((string)$_smarty_tpl->getValue('rule'), ENT_QUOTES, 'UTF-8', true);?>
</li>
              <?php
}
$_smarty_tpl->getSmarty()->getRuntime('Foreach')->restore($_smarty_tpl, 1);?>
            </ul>
          </div>
        </div>
      </div>
    </div>
  </section>

</main>

<?php $_smarty_tpl->renderSubTemplate("file:maklertour_footer.html", $_smarty_tpl->cache_id, $_smarty_tpl->compile_id, 0, $_smarty_tpl->cache_lifetime, array(), (int) 0, $_smarty_current_dir);
}
}
