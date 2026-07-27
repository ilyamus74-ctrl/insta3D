<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$main = $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/MainActivity.kt';
if (!is_file($main)) {
    throw new RuntimeException('MainActivity.kt missing');
}
$text = (string) file_get_contents($main);
foreach ([
    'WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON',
    'window.addFlags',
] as $token) {
    if (!str_contains($text, $token)) {
        throw new RuntimeException('Foreground keep-awake contract missing: ' . $token);
    }
}
echo "OK\n";
