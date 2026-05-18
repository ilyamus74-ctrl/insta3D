<?php
/* Smarty version 5.3.1, created on 2026-05-16 19:35:59
  from 'file:maklertour_orders.html' */

/* @var \Smarty\Template $_smarty_tpl */
if ($_smarty_tpl->getCompiled()->isFresh($_smarty_tpl, array (
  'version' => '5.3.1',
  'unifunc' => 'content_6a08c71f409771_05484478',
  'has_nocache_code' => false,
  'file_dependency' => 
  array (
    '2102b08e2bd10e7a10d9db769396dd3be0956406' => 
    array (
      0 => 'maklertour_orders.html',
      1 => 1778493003,
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
function content_6a08c71f409771_05484478 (\Smarty\Template $_smarty_tpl) {
$_smarty_current_dir = '/home/makler/web/templates';
$_smarty_tpl->renderSubTemplate("file:maklertour_header.html", $_smarty_tpl->cache_id, $_smarty_tpl->compile_id, 0, $_smarty_tpl->cache_lifetime, array(), (int) 0, $_smarty_current_dir);
$_smarty_tpl->renderSubTemplate("file:maklertour_sidebar.html", $_smarty_tpl->cache_id, $_smarty_tpl->compile_id, 0, $_smarty_tpl->cache_lifetime, array(), (int) 0, $_smarty_current_dir);
?>

<main id="main" class="main">

  <div class="pagetitle">
    <h1>Заявки</h1>
    <nav>
      <ol class="breadcrumb">
        <li class="breadcrumb-item">MaklerTour</li>
        <li class="breadcrumb-item active">Заявки</li>
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
        <h5 class="card-title">Создать заявку</h5>

        <form method="post" action="/orders.php">
          <input type="hidden" name="action" value="create_order">
          <input type="hidden" name="form_token" value="<?php echo $_smarty_tpl->getValue('createOrderToken');?>
">

          <div class="row mb-3">
            <div class="col-md-6">
              <label class="form-label">Название объекта</label>
              <input type="text" name="title" class="form-control" placeholder="Квартира на Hauptstraße">
            </div>

            <div class="col-md-3">
              <label class="form-label">Площадь, м²</label>
              <input type="number" step="0.01" name="area_m2" class="form-control" placeholder="250">
            </div>
          </div>

          <div class="row mb-3">
            <div class="col-md-9">
              <label class="form-label">Адрес</label>
              <input type="text" name="address" class="form-control" placeholder="Город, улица, дом">
            </div>
          </div>

          <div class="row mb-3">
            <div class="col-md-3">
              <label class="form-label">Клиент</label>
              <input type="text" name="customer_name" class="form-control">
            </div>

            <div class="col-md-3">
              <label class="form-label">Телефон клиента</label>
              <input type="text" name="customer_phone" class="form-control">
            </div>

            <div class="col-md-3">
              <label class="form-label">Email клиента</label>
              <input type="email" name="customer_email" class="form-control">
            </div>
          </div>
           <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" name="is_published" id="is_published" value="1">
            <label class="form-check-label" for="is_published">Опубликовать</label>
          </div>
          <button type="submit" class="btn btn-primary" >
            Создать заявку
           </button>
        </form>

      </div>
    </div>

    <div class="card">
      <div class="card-body">
        <h5 class="card-title">Список заявок</h5>

        <table class="table table-striped">
          <thead>
            <tr>
              <th>ID</th>
              <th>Объект</th>
              <th>Адрес</th>
              <th>Площадь</th>
              <th>Статус</th>
              <th>Публикация</th>
              <th>Брокер</th>
              <th>Оператор</th>
              <th>Создано</th>
            </tr>
          </thead>
          <tbody>
            <?php
$_from = $_smarty_tpl->getSmarty()->getRuntime('Foreach')->init($_smarty_tpl, $_smarty_tpl->getValue('orders'), 'o');
$foreach0DoElse = true;
foreach ($_from ?? [] as $_smarty_tpl->getVariable('o')->value) {
$foreach0DoElse = false;
?>
              <tr>
                <td><a href="/order.php?id=<?php echo $_smarty_tpl->getValue('o')['id'];?>
"><?php echo htmlspecialchars((string)$_smarty_tpl->getValue('o')['title'], ENT_QUOTES, 'UTF-8', true);?>
</a></td>
                <td><?php echo htmlspecialchars((string)$_smarty_tpl->getValue('o')['title'], ENT_QUOTES, 'UTF-8', true);?>
</td>
                <td><?php echo htmlspecialchars((string)$_smarty_tpl->getValue('o')['address'], ENT_QUOTES, 'UTF-8', true);?>
</td>
                <td><?php echo $_smarty_tpl->getValue('o')['area_m2'];?>
</td>
                <td><span class="badge <?php echo $_smarty_tpl->getValue('o')['status_meta']['class'];?>
"><i class="bi <?php echo $_smarty_tpl->getValue('o')['status_meta']['icon'];?>
"></i> <?php echo $_smarty_tpl->getValue('o')['status_meta']['label'];?>
</span></td>
                <td><?php if ($_smarty_tpl->getValue('o')['is_published'] == 1) {?><span class="badge bg-success">Опубликована</span><?php } else { ?><span class="badge bg-light text-dark">Черновик</span><?php }?></td>
                <td><?php echo htmlspecialchars((string)$_smarty_tpl->getValue('o')['broker_name'], ENT_QUOTES, 'UTF-8', true);?>
</td>
                <td><?php echo htmlspecialchars((string)(($tmp = $_smarty_tpl->getValue('o')['operator_name'] ?? null)===null||$tmp==='' ? '-' ?? null : $tmp), ENT_QUOTES, 'UTF-8', true);?>
</td>
                <td><?php echo $_smarty_tpl->getValue('o')['created_at'];?>
</td>
              </tr>
            <?php
}
if ($foreach0DoElse) {
?>
              <tr>
                <td colspan="9">Заявок пока нет</td>
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
