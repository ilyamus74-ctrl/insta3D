<?php
/* Smarty version 5.3.1, created on 2026-05-15 07:48:44
  from 'file:maklertour_register.html' */

/* @var \Smarty\Template $_smarty_tpl */
if ($_smarty_tpl->getCompiled()->isFresh($_smarty_tpl, array (
  'version' => '5.3.1',
  'unifunc' => 'content_6a06cfdcaf7013_72258331',
  'has_nocache_code' => false,
  'file_dependency' => 
  array (
    'ba711e32b276c6706446e347ced0680014c28124' => 
    array (
      0 => 'maklertour_register.html',
      1 => 1778831292,
      2 => 'file',
    ),
  ),
  'includes' => 
  array (
  ),
))) {
function content_6a06cfdcaf7013_72258331 (\Smarty\Template $_smarty_tpl) {
$_smarty_current_dir = '/home/makler/web/templates';
?><!DOCTYPE html>
<html lang="ru">

<head>
  <meta charset="utf-8">
  <meta content="width=device-width, initial-scale=1.0" name="viewport">

  <title>MaklerTour — регистрация</title>

  <link href="/img/favicon.png" rel="icon">
  <link href="/css/style.css" rel="stylesheet">
  <link href="/css/styles.css" rel="stylesheet">
</head>

<body>

<main>
  <div class="container">

   <section class="section register min-vh-100 d-flex flex-column align-items-center justify-content-center py-4">
      <div class="container">
        <div class="row justify-content-center">

          <div class="col-lg-4 col-md-6 d-flex flex-column align-items-center justify-content-center">
<div class="register-logo-wrap">
  <a href="/" class="d-flex justify-content-center">
    <img
      src="/img/logo/01E_home_aperture.png"
      alt="MaklerTour"
      class="register-logo"
    >
  </a>
</div>
            <div class="card mb-3">
              <div class="card-body">

                <div class="pt-4 pb-2">
                  <h5 class="card-title text-center pb-0 fs-4">Регистрация</h5>
                  <p class="text-center small">Создайте аккаунт маклера</p>
                </div>

                <?php if ($_smarty_tpl->getValue('register_error')) {?>
                  <div class="alert alert-danger"><?php echo $_smarty_tpl->getValue('register_error');?>
</div>
                <?php }?>

                <?php if ($_smarty_tpl->getValue('register_success')) {?>
                  <div class="alert alert-success"><?php echo $_smarty_tpl->getValue('register_success');?>
</div>
                  <div class="text-center">
                    <a href="/login.php" class="btn btn-primary">Войти</a>
                  </div>
                <?php } else { ?>

                <form class="row g-3" method="post">

                  <div class="col-12">
                    <label class="form-label">Логин</label>
                    <input type="text" name="username" class="form-control" required>
                  </div>

                  <div class="col-12">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control" required>
                  </div>

                  <div class="col-12">
                    <label class="form-label">Имя / компания</label>
                    <input type="text" name="full_name" class="form-control">
                  </div>

                  <div class="col-12">
                    <label class="form-label">Пароль</label>
                    <input type="password" name="password" class="form-control" required>
                  </div>

                  <div class="col-12">
                    <label class="form-label">Повтор пароля</label>
                    <input type="password" name="password2" class="form-control" required>
                  </div>

                  <div class="col-12">
                    <button class="btn btn-primary w-100" type="submit">Зарегистрироваться</button>
                  </div>

                  <div class="col-12 text-center">
                    <p class="small mb-0">
                      Уже есть аккаунт?
                      <a href="/login.php">Войти</a>
                    </p>
                  </div>

                </form>

                <?php }?>

              </div>
            </div>

          </div>

        </div>
      </div>
    </section>

  </div>
</main>

</body>
</html><?php }
}
