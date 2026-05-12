<?php
declare(strict_types=1);
//session_start();

// 1. Сессия + безопасность (auth_*, audit_*, БД)
require_once __DIR__ . '/../configs/secure.php';
require_once __DIR__ . '/../configs/app.php';
// 2. Чтобы не инициализировать всё два раза
if (defined('APP_BOOTSTRAP_DONE')) {
    return;
}
define('APP_BOOTSTRAP_DONE', true);

// 3. Язык (без префиксов в URL)
$supportedLocales = ['de','en','uk','ru'];
$localeMap  = [
    'de' => 'de_DE',
    'en' => 'en_US',
    'uk' => 'uk_UA',
    'ru' => 'ru_RU'
];

$sessionUser = $_SESSION['user'] ?? null;

if (!empty($sessionUser['ui_lang']) && in_array($sessionUser['ui_lang'], $supportedLocales, true)) {
    $lang = $sessionUser['ui_lang'];
} else {
    $lang = $_SESSION['lang'] ?? ($_COOKIE['lang'] ?? 'uk');
    if (!in_array($lang, $supportedLocales, true)) {
        $lang = 'uk';
    }
}

// синхронизируем обратно
$_SESSION['lang'] = $lang;
if ($sessionUser) {
    $_SESSION['user']['ui_lang'] = $lang;
}

if (!headers_sent()) {
    setcookie('lang', $lang, [
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

// 4. Gettext (локали)
$domainText = 'messages';
$localeBase = realpath(__DIR__ . '/../locale');
$localeSys  = $localeMap[$lang] ?? 'uk_UA';

putenv('LANG');
putenv('LANGUAGE');
putenv('LC_ALL');

setlocale(LC_ALL,      "{$localeSys}.UTF-8", $localeSys, "{$localeSys}.utf8");
setlocale(LC_MESSAGES, "{$localeSys}.UTF-8", $localeSys, "{$localeSys}.utf8");

bindtextdomain($domainText, $localeBase);
bind_textdomain_codeset($domainText, 'UTF-8');
textdomain($domainText);

// 5. Smarty
require_once __DIR__ . '/../libs/Smarty.class.php';
$smarty = class_exists('\\Smarty\\Smarty') ? new \Smarty\Smarty : new Smarty();
require_once __DIR__ . '/patch.php';

// плагины перевода
$smarty->registerPlugin('modifier', '__', function($msgid) use ($domainText) {
    return dgettext($domainText, (string)$msgid);
});
$smarty->registerPlugin('function', 't', function($params) use ($domainText) {
    $key = $params['key'] ?? '';
    return dgettext($domainText, (string)$key);
});

// базовые настройки Smarty
$theme = "/templates";
$smarty->debugging      = false;
$smarty->caching        = false;
$smarty->cache_lifetime = 0;

// базовые assign'ы
$smarty->assign("THEME",  $theme);
$smarty->assign("xlang",  $lang);
$smarty->assign("locale", $localeSys);

$domainName = preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
$smarty->assign("domainName", $domainName);

// secCode для форм/CSRF и прочего
if (empty($_SESSION['secCode'])) {
    $_SESSION['secCode'] = bin2hex(random_bytes(16));
}
$smarty->assign("secCode", $_SESSION['secCode']);

// после создания $smarty и после auth_login/auth_require_login
$menu = $_SESSION['menu'] ?? [];

$smarty->assign('menu', $menu);

if (function_exists('auth_is_logged_in') && auth_is_logged_in()) {
    $smarty->assign('current_user', auth_current_user());
}