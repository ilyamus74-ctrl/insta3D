<?php
declare(strict_types=1);

$worker = dirname(__DIR__) . '/tools/sfm_remote_worker.php';
$resume = dirname(__DIR__) . '/tools/sfm_resume_dense_parent.php';
foreach ([$worker, $resume] as $path) {
    if (!is_file($path)) {
        throw new RuntimeException('missing file: ' . $path);
    }
}

$text = (string)file_get_contents($worker);
foreach ([
    "retryCount<=0",
    "Initial dense chunk failure recorded; pipeline remains active",
    "elseif(\$failed && \$active>0)",
    "Retry chunk is active",
] as $token) {
    if (!str_contains($text, $token)) {
        throw new RuntimeException('dense retry guard missing: ' . $token);
    }
}

$resumeText = (string)file_get_contents($resume);
foreach ([
    'Recovered parent after automatic dense retry',
    "finished_at=NULL",
    "error_json=NULL",
] as $token) {
    if (!str_contains($resumeText, $token)) {
        throw new RuntimeException('resume contract missing: ' . $token);
    }
}

echo "OK\n";
