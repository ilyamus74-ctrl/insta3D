<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$test = $root . '/tests/stereo_global_fusion_test.py';
if (!is_file($test)) {
    throw new RuntimeException('Python test not found: ' . $test);
}

$python = getenv('PYTHON') ?: 'python3';
$command = escapeshellarg($python) . ' ' . escapeshellarg($test) . ' 2>&1';
$output = [];
$code = 0;
exec($command, $output, $code);

if ($code !== 0) {
    throw new RuntimeException(
        "stereo global fusion test failed\n" . implode("\n", $output)
    );
}

if (trim(implode("\n", $output)) !== 'OK') {
    throw new RuntimeException(
        "unexpected stereo global fusion test output\n"
        . implode("\n", $output)
    );
}

echo "OK\n";
