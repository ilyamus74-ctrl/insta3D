<?php
/* Smarty version 5.3.1, created on 2026-05-11 07:58:27
  from 'file:maklertour_market.html' */

/* @var \Smarty\Template $_smarty_tpl */
if ($_smarty_tpl->getCompiled()->isFresh($_smarty_tpl, array (
  'version' => '5.3.1',
  'unifunc' => 'content_6a018c23ab5fc8_53345713',
  'has_nocache_code' => false,
  'file_dependency' => 
  array (
    '05a37a1629861401bf2e665b33995b98bc935718' => 
    array (
      0 => 'maklertour_market.html',
      1 => 1778483453,
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
function content_6a018c23ab5fc8_53345713 (\Smarty\Template $_smarty_tpl) {
$_smarty_current_dir = '/home/makler/web/templates';
$_smarty_tpl->renderSubTemplate("file:maklertour_header.html", $_smarty_tpl->cache_id, $_smarty_tpl->compile_id, 0, $_smarty_tpl->cache_lifetime, array(), (int) 0, $_smarty_current_dir);
$_smarty_tpl->renderSubTemplate("file:maklertour_sidebar.html", $_smarty_tpl->cache_id, $_smarty_tpl->compile_id, 0, $_smarty_tpl->cache_lifetime, array(), (int) 0, $_smarty_current_dir);
?>

<main id="main" class="main">

  <div class="pagetitle">
    <h1>Рынок заявок</h1>
    <nav>
      <ol class="breadcrumb">
        <li class="breadcrumb-item">MaklerTour</li>
        <li class="breadcrumb-item active">Рынок заявок</li>
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
        <h5 class="card-title">Доступные заявки</h5>

        <table class="table table-striped">
          <thead>
            <tr>
              <th>ID</th>
              <th>Объект</th>
              <th>Адрес</th>
              <th>Площадь</th>
              <th>Клиент</th>
              <th>Брокер</th>
              <th>Создано</th>
              <th></th>
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
"><?php echo $_smarty_tpl->getValue('o')['id'];?>
</a></td>
                <td><a href="/order.php?id=<?php echo $_smarty_tpl->getValue('o')['id'];?>
"><?php echo htmlspecialchars((string)$_smarty_tpl->getValue('o')['title'], ENT_QUOTES, 'UTF-8', true);?>
</a></td>
                <td><?php echo htmlspecialchars((string)$_smarty_tpl->getValue('o')['address'], ENT_QUOTES, 'UTF-8', true);?>
</td>
                <td><?php echo $_smarty_tpl->getValue('o')['area_m2'];?>
</td>
                <td><?php echo htmlspecialchars((string)(($tmp = $_smarty_tpl->getValue('o')['customer_name'] ?? null)===null||$tmp==='' ? '-' ?? null : $tmp), ENT_QUOTES, 'UTF-8', true);?>
</td>
                <td><?php echo htmlspecialchars((string)$_smarty_tpl->getValue('o')['broker_name'], ENT_QUOTES, 'UTF-8', true);?>
</td>
                <td><?php echo $_smarty_tpl->getValue('o')['created_at'];?>
</td>
                <td>
                  <form method="post" action="/market.php" style="display:inline">
                    <input type="hidden" name="action" value="take_order">
                    <input type="hidden" name="order_id" value="<?php echo $_smarty_tpl->getValue('o')['id'];?>
">
                    <button type="submit" class="btn btn-sm btn-primary">Взять</button>
                  </form>
                </td>
              </tr>
            <?php
}
if ($foreach0DoElse) {
?>
              <tr>
                <td colspan="8">Новых заявок пока нет</td>
              </tr>
            <?php
}
$_smarty_tpl->getSmarty()->getRuntime('Foreach')->restore($_smarty_tpl, 1);?>
          </tbody>
        </table>

      </div>
    </div>
<div class="card">
  <div class="card-body">
    <h5 class="card-title">Мои заявки в работе</h5>

    <table class="table table-striped">
      <thead>
        <tr>
          <th>ID</th>
          <th>Объект</th>
          <th>Адрес</th>
          <th>Площадь</th>
          <th>Клиент</th>
          <th>Брокер</th>
          <th>Статус</th>
          <th>Обновлено</th>
        </tr>
      </thead>
      <tbody>
        <?php
$_from = $_smarty_tpl->getSmarty()->getRuntime('Foreach')->init($_smarty_tpl, $_smarty_tpl->getValue('myOrders'), 'o');
$foreach1DoElse = true;
foreach ($_from ?? [] as $_smarty_tpl->getVariable('o')->value) {
$foreach1DoElse = false;
?>
          <tr>
            <td><a href="/order.php?id=<?php echo $_smarty_tpl->getValue('o')['id'];?>
"><?php echo $_smarty_tpl->getValue('o')['id'];?>
</a></td>
            <td><a href="/order.php?id=<?php echo $_smarty_tpl->getValue('o')['id'];?>
"><?php echo htmlspecialchars((string)$_smarty_tpl->getValue('o')['title'], ENT_QUOTES, 'UTF-8', true);?>
</a></td>
            <td><?php echo htmlspecialchars((string)$_smarty_tpl->getValue('o')['address'], ENT_QUOTES, 'UTF-8', true);?>
</td>
            <td><?php echo $_smarty_tpl->getValue('o')['area_m2'];?>
</td>
            <td><?php echo htmlspecialchars((string)(($tmp = $_smarty_tpl->getValue('o')['customer_name'] ?? null)===null||$tmp==='' ? '-' ?? null : $tmp), ENT_QUOTES, 'UTF-8', true);?>
</td>
            <td><?php echo htmlspecialchars((string)$_smarty_tpl->getValue('o')['broker_name'], ENT_QUOTES, 'UTF-8', true);?>
</td>
            <td><span class="badge bg-primary"><?php echo $_smarty_tpl->getValue('o')['status'];?>
</span></td>
            <td><?php echo $_smarty_tpl->getValue('o')['updated_at'];?>
</td>
          </tr>
        <?php
}
if ($foreach1DoElse) {
?>
          <tr>
            <td colspan="8">У вас пока нет заявок в работе</td>
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
