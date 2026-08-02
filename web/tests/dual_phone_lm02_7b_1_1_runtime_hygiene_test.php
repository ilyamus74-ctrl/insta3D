<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$host = $root . '/web/remote_station/dual_phone_host';

$checks = [
    'host-local gitignore exists' => is_file($host . '/.gitignore'),
    'build directory ignored' => str_contains((string) file_get_contents($host . '/.gitignore'), '/build/'),
    'sessions directory ignored' => str_contains((string) file_get_contents($host . '/.gitignore'), '/sessions/'),
    'default archive disabled' => str_contains((string) file_get_contents($host . '/scripts/run.sh'), 'MAKLER_ARCHIVE_EVERY:-0'),
    'default output outside repository' => str_contains((string) file_get_contents($host . '/scripts/run.sh'), 'XDG_STATE_HOME'),
    'diagnostic packer exists' => is_file($host . '/scripts/pack_session.sh'),
    'packer defaults to json-only' => str_contains((string) file_get_contents($host . '/scripts/pack_session.sh'), 'SAMPLE_EVERY=0'),
    'untrack helper exists' => is_file($host . '/scripts/untrack_runtime_artifacts.sh'),
];

$failed = false;
foreach ($checks as $label => $ok) {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    $failed = $failed || !$ok;
}

echo 'Result: ' . ($failed ? 'FAIL' : 'PASS') . PHP_EOL;
exit($failed ? 1 : 0);
