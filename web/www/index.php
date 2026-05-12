<?php
declare(strict_types=1);


// Общая инициализация приложения (сессия, secure.php, язык, gettext, Smarty и т.п.)
require_once __DIR__ . '/bootstrap.php';

// Анти-кеш
header('Vary: Cookie, Accept-Language, Accept-Encoding');
header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// Определяем путь
$uri  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$uri  = preg_replace('#//+#', '/', $uri);
$path = trim($uri, '/');   // "" | "login.html" | "main"

// Мини-роутер
$ROUTES = [
    ''           => 'main.php',
    'main'       => 'main.php',
    'login.html' => 'login.php',
    'login.php'  => 'login.php',
    'login'      => 'login.php',
    'logout.html'=> 'logout.php',
    'logout.php' => 'logout.php',
    'logout'     => 'logout.php',

    // сюда потом добавишь остальные страницы
];

// скрипты, доступные без авторизации
$PUBLIC_SCRIPTS = [
    'login.php',
    '404.php',
];

if (isset($ROUTES[$path])) {
    $script = $ROUTES[$path];

    if (!in_array($script, $PUBLIC_SCRIPTS, true)) {
        auth_require_login(); // теперь без $lang
    }

    include_once __DIR__ . '/' . $script;
    exit;
}

// 404
http_response_code(404);
include_once __DIR__ . '/404.php';
