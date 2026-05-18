<?php
/* Smarty version 5.3.1, created on 2026-05-16 19:35:57
  from 'file:maklertour_dashboard.html' */

/* @var \Smarty\Template $_smarty_tpl */
if ($_smarty_tpl->getCompiled()->isFresh($_smarty_tpl, array (
  'version' => '5.3.1',
  'unifunc' => 'content_6a08c71d5a7b24_24441870',
  'has_nocache_code' => false,
  'file_dependency' => 
  array (
    '05188b916bee56da30a3302b019c0362e69611e6' => 
    array (
      0 => 'maklertour_dashboard.html',
      1 => 1778483397,
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
function content_6a08c71d5a7b24_24441870 (\Smarty\Template $_smarty_tpl) {
$_smarty_current_dir = '/home/makler/web/templates';
$_smarty_tpl->renderSubTemplate("file:maklertour_header.html", $_smarty_tpl->cache_id, $_smarty_tpl->compile_id, 0, $_smarty_tpl->cache_lifetime, array(), (int) 0, $_smarty_current_dir);
$_smarty_tpl->renderSubTemplate("file:maklertour_sidebar.html", $_smarty_tpl->cache_id, $_smarty_tpl->compile_id, 0, $_smarty_tpl->cache_lifetime, array(), (int) 0, $_smarty_current_dir);
?>

<main id="main" class="main">

  <div class="pagetitle">
    <h1>MaklerTour</h1>
    <nav>
      <ol class="breadcrumb">
        <li class="breadcrumb-item">Home</li>
        <li class="breadcrumb-item active">Dashboard</li>
      </ol>
    </nav>
  </div>

  <section class="section dashboard">
    <div class="row">

      <div class="col-xxl-3 col-md-6">
        <div class="card info-card sales-card">
          <div class="card-body">
            <h5 class="card-title">Всего заявок</h5>
            <div class="d-flex align-items-center">
              <div class="ps-3">
                <h6><?php echo $_smarty_tpl->getValue('dashboard')['totalOrders'];?>
</h6>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="col-xxl-3 col-md-6">
        <div class="card info-card revenue-card">
          <div class="card-body">
            <h5 class="card-title">Новые</h5>
            <div class="d-flex align-items-center">
              <div class="ps-3">
                <h6><?php echo $_smarty_tpl->getValue('dashboard')['newOrders'];?>
</h6>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="col-xxl-3 col-md-6">
        <div class="card info-card customers-card">
          <div class="card-body">
            <h5 class="card-title">В работе</h5>
            <div class="d-flex align-items-center">
              <div class="ps-3">
                <h6><?php echo $_smarty_tpl->getValue('dashboard')['inProgressOrders'];?>
</h6>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="col-xxl-3 col-md-6">
        <div class="card info-card customers-card">
          <div class="card-body">
            <h5 class="card-title">Готово</h5>
            <div class="d-flex align-items-center">
              <div class="ps-3">
                <h6><?php echo $_smarty_tpl->getValue('dashboard')['readyOrders'];?>
</h6>
              </div>
            </div>
          </div>
        </div>
      </div>

    </div>

    <div class="card">
      <div class="card-body">
        <h5 class="card-title">Статус</h5>
        <p>Вход работает. База MaklerTour подключена. Следующий этап — заявки и рынок операторов.</p>
        <p>Активных пользователей: <b><?php echo $_smarty_tpl->getValue('dashboard')['totalUsers'];?>
</b></p>
      </div>
    </div>

  </section>

</main>

<?php $_smarty_tpl->renderSubTemplate("file:maklertour_footer.html", $_smarty_tpl->cache_id, $_smarty_tpl->compile_id, 0, $_smarty_tpl->cache_lifetime, array(), (int) 0, $_smarty_current_dir);
}
}
