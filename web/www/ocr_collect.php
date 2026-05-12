<?php
// ocr_collect.php

// Часовой пояс, чтобы TIME был понятный
date_default_timezone_set('Europe/Berlin');

// Папка, куда складываем дампы
$dir = __DIR__ . 'ocr_samples';
if (!is_dir($dir)) {
    mkdir($dir, 0777, true);
}

// Имя файла: sample_YYYYMMDD_HHMMSS_xxxxxx.txt
$filename = sprintf(
    '%s/sample_%s_%s.txt',
    $dir,
    date('Ymd_His'),
    bin2hex(random_bytes(3))
);

// Время и IP
$time = date('c'); // ISO8601
$ip   = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

// Сырое тело запроса
$rawBody = file_get_contents('php://input');

// То, что распарсил PHP как POST
$postDump = print_r($_POST, true);
$getDump  = print_r($_GET, true);

// Отдельно достанем то, что шлёт твой апп
$rawTextParam = isset($_POST['raw_text']) ? (string)$_POST['raw_text'] : '';

// Пишем всё в файл
$content  = "TIME: {$time}\n";
$content .= "IP:   {$ip}\n";
$content .= "========================================\n";
$content .= "RAW BODY (php://input):\n{$rawBody}\n";
$content .= "----------------------------------------\n";
$content .= "\$_POST:\n{$postDump}\n";
$content .= "----------------------------------------\n";
$content .= "\$_GET:\n{$getDump}\n";
$content .= "----------------------------------------\n";
$content .= "raw_text param:\n{$rawTextParam}\n";

file_put_contents($filename, $content);

// Ответ клиенту
header('Content-Type: text/plain; charset=utf-8');
echo "OK\n";