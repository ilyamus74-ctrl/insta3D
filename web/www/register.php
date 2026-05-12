<?php
declare(strict_types=1);
//ini_set('display_errors', '1');
//ini_set('display_startup_errors', '1');
//error_reporting(E_ALL);
require_once __DIR__ . '/bootstrap.php';

$error = null;
$success = null;

if (auth_is_logged_in()) {
    header('Location: /main');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $fullName = trim($_POST['full_name'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    $password2 = (string)($_POST['password2'] ?? '');

    if ($username === '' || $email === '' || $password === '') {
        $error = 'Заполните логин, email и пароль';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Некорректный email';
    } elseif (strlen($password) < 8) {
        $error = 'Пароль должен быть не короче 8 символов';
    } elseif ($password !== $password2) {
        $error = 'Пароли не совпадают';
    } else {
        $stmt = $dbcnx->prepare("
            SELECT id
            FROM users
            WHERE username = ? OR email = ?
            LIMIT 1
        ");

        if (!$stmt) {
            $error = 'DB prepare error: ' . $dbcnx->error;
        } else {
            $stmt->bind_param("ss", $username, $email);
            $stmt->execute();
            $res = $stmt->get_result();
            $exists = $res->fetch_assoc();
            $stmt->close();

            if ($exists) {
                $error = 'Пользователь с таким логином или email уже существует';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $role = 'BROKER';
                $lang = 'ru';

                $stmt = $dbcnx->prepare("
                    INSERT INTO users
                    (username, email, password_hash, full_name, role, is_active, ui_lang)
                    VALUES (?, ?, ?, ?, ?, 1, ?)
                ");

                if (!$stmt) {
                    $error = 'DB insert prepare error: ' . $dbcnx->error;
                } else {
                    $stmt->bind_param(
                        "ssssss",
                        $username,
                        $email,
                        $hash,
                        $fullName,
                        $role,
                        $lang
                    );

               if ($stmt->execute()) {
                  $newUserId = (int)$stmt->insert_id;

                  audit_log(
                           $newUserId,
                           'REGISTER',
                           'USER',
                           $newUserId,
                           'Пользователь зарегистрировался',
                  [
                   'username' => $username,
                   'email' => $email,
                   'role' => $role,
                   'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                   'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                  ]
                  );

                     $success = 'Регистрация успешна. Теперь можно войти.';
                   } else {
                        $error = 'DB insert execute error: ' . $stmt->error;
                    }

                    $stmt->close();
                }
            }
        }
    }
}

$smarty->assign('register_error', $error);
$smarty->assign('register_success', $success);
$smarty->display('maklertour_register.html');
