<?php

declare(strict_types=1);

// ВРЕМЕННАЯ ДИАГНОСТИКА - потом удалить! 
error_reporting(E_ALL);
ini_set('display_errors', '1');

try {
    require_once __DIR__ . '/bootstrap.php';
    $smarty->display('cells_NA_prepare_print.html');
} catch (Throwable $e) {
    echo "<h1>Ошибка! </h1>";
    echo "<pre>";
    echo "Message: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . "\n";
    echo "Line: " . $e->getLine() . "\n";
    echo "\nStack trace:\n" . $e->getTraceAsString();
    echo "</pre>";
    
    echo "<h2>Пути Smarty:</h2><pre>";
    print_r($smarty->getTemplateDir());
    print_r($smarty->getCompileDir());
    echo "</pre>";
}
/*


require_once __DIR__ . '/bootstrap.php';

// Определяем базовую директорию проекта
$baseDir = dirname(__DIR__);

$smarty->setTemplateDir($baseDir .  '/templates/');
$smarty->setCompileDir($baseDir . '/templates_c/');
$smarty->setConfigDir($baseDir . '/configs/');
$smarty->setCacheDir($baseDir . '/cache/');

$smarty->display('cells_NA_prepare_print.html');
*/