<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = (string)($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $error = 'Введите логин и пароль';
    } else {
        $ok = auth_login($username, $password); // из secure.php

        if ($ok) {
            header('Location: /main');
            exit;
        } else {
            $error = 'Неверный логин или пароль';
        }
    }
}

if(isset($_SESSION['user']))   header('Location: /main');

// данные для шаблона
$header_data['domainName'] = $domainName ?? '';
$smarty->assign('header_data', $header_data);
$smarty->assign('login', 'login');
$smarty->assign('login_error', $error);

$smarty->display('cells_login_main.html');
