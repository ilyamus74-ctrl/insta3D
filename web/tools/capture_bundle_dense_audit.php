<?php
$checks = [
  'upload_capture_bundle exists' => ['web/www/api/mobile.php', 'upload_capture_bundle'],
  'capture_bundles table exists' => ['web/www/api/mobile.php', 'CREATE TABLE IF NOT EXISTS capture_bundles'],
  'order page queries capture_bundles' => ['web/www/order.php', 'SELECT * FROM capture_bundles WHERE order_id=?'],
  'worker job type' => ['web/tools/sfm_remote_worker.php', 'MAKLERTOUR_SYNCED_DENSE'],
  'runner exists' => ['web/remote_station/run_maklertour_synced_dense_job.sh', 'process_maklertour_synced_dense.sh'],
  'process script exists' => ['web/remote_station/scripts/process_maklertour_synced_dense.sh', 'dense_depth_from_synced_capture.py'],
  'python deployed copy' => ['web/remote_station/scripts/dense_depth_from_synced_capture.py', 'max-pairs'],
  'UI button' => ['web/templates/maklertour_order.html', 'Run synced dense'],
];
$ok = true;
foreach ($checks as $name => [$file, $needle]) {
    $text = is_file($file) ? file_get_contents($file) : '';
    $pass = $text !== false && str_contains($text, $needle);
    echo ($pass ? 'OK' : 'FAIL') . " $name\n";
    $ok = $ok && $pass;
}
exit($ok ? 0 : 1);