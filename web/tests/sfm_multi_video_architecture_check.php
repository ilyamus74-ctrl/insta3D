<?php
declare(strict_types=1);
$root = dirname(__DIR__, 2);
$checks = [
  'template no first video hidden id' => ["web/templates/maklertour_order.html", 'videos.0.id', false],
  'template no first disk video filename' => ["web/templates/maklertour_order.html", 'sfm_disk_videos.0.filename', false],
  'template per-video cards' => ["web/templates/maklertour_order.html", 'foreach from=$dv.sfm_pipeline_cards', true],
  'template explicit video hidden id' => ["web/templates/maklertour_order.html", 'name="video_scan_id" value="{$dv.video_scan_id', true],
  'legacy runs section' => ["web/templates/maklertour_order.html", 'Legacy runs — source video unknown', true],
  'active run scoped by video' => ["web/www/order.php", 'capture_session_id=? AND video_scan_id=? AND pipeline_mode=?', true],
  'source video snapshot' => ["web/www/order.php", "'source_video'=>sfm_source_video_snapshot", true],
  'restart validates source video' => ["web/www/order.php", 'sfm_load_source_video($dbcnx,$orderId,$captureSessionId,(int)($run[\'video_scan_id\'] ?? 0))', true],
  'rerender cancellation uses enum stage' => ["web/www/order.php", "status='CANCELLED', stage='CANCELLED', message='Superseded by rerender'", true],
  'rerender does not use superseded stage' => ["web/www/order.php", "stage='SUPERSEDED'", false],
  'cleanup includes web job output' => ["web/remote_station/sfm_cleanup.php", "SFM_CLEANUP_WEB_OUTPUT_BASE.'/job_'.\$rid", true],
  'cleanup includes station input output and log' => ["web/remote_station/sfm_cleanup.php", "SFM_CLEANUP_STATION_BASE_DEFAULT.'/input/job_'.\$rid,SFM_CLEANUP_STATION_BASE_DEFAULT.'/output/job_'.\$rid,SFM_CLEANUP_STATION_BASE_DEFAULT.'/logs/job_'.\$rid.'.log'", true],
  'cleanup rejects order source media paths' => ["web/remote_station/sfm_cleanup.php", "str_starts_with(\$p,'/home/storage/orders/')", true],
  'viewer no silent multi fallback' => ["web/www/api/sfm_3d.php", 'Multiple source videos found. Select a video.', true],
  'artifact validates requested video' => ["web/www/api/sfm_pipeline_artifact.php", 'Pipeline run does not belong to requested video', true],
  'index exists' => ["web/remote_station/sfm_pipeline.php", 'idx_pipeline_video_mode', true],
];
$failed = 0;
foreach ($checks as $name => [$file, $needle, $shouldContain]) {
    $text = file_get_contents($root . '/' . $file);
    $ok = (strpos($text, $needle) !== false) === $shouldContain;
    echo ($ok ? 'PASS' : 'FAIL') . " {$name}\n";
    if (!$ok) $failed++;
}
exit($failed === 0 ? 0 : 1);