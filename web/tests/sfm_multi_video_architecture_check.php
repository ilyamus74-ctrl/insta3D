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
  'cleanup includes station input output work and exact status' => ["web/remote_station/sfm_cleanup.php", "SFM_CLEANUP_STATION_BASE_DEFAULT.'/input/job_'.\$rid,SFM_CLEANUP_STATION_BASE_DEFAULT.'/output/job_'.\$rid,SFM_CLEANUP_STATION_BASE_DEFAULT.'/work/job_'.\$rid,SFM_CLEANUP_STATION_BASE_DEFAULT.'/status/job_'.\$rid.'.json'", true],
  'cleanup no status prefix wildcard in shell' => ["web/remote_station/cleanup_station_artifacts.sh", 'glob.glob(f"{base}/status/job_{jid}*")', false],
  'cleanup shell exact status json' => ["web/remote_station/cleanup_station_artifacts.sh", 'f"{base}/status/job_{jid}.json"', true],
  'cleanup standalone remote jobs scheduled' => ["web/tools/sfm_remote_worker.php", 'pipeline_run_id IS NULL AND status IN', true],
  'cleanup standalone schema remote job unique' => ["web/migrations/20260714_sfm_remote_cleanup_runs.sql", 'UNIQUE KEY uq_sfm_remote_cleanup_remote_job (remote_job_id)', true],
  'cleanup v2 migration exists' => ["web/migrations/20260714_sfm_remote_cleanup_runs_v2.sql", 'ADD UNIQUE KEY uq_sfm_remote_cleanup_remote_job (remote_job_id)', true],
  'cleanup terminal status oom' => ["web/remote_station/sfm_cleanup.php", 'ERROR_OOM', true],
  'cleanup terminal excludes cancel error' => ["web/remote_station/sfm_cleanup.php", "'CANCEL_ERROR'", false],
  'cleanup locks by remote id' => ["web/remote_station/sfm_cleanup.php", 'sfm_remote_job_cleanup:', true],
  'cleanup pipeline chain standalone requires explicit scope' => ["web/remote_station/sfm_cleanup.php", "cleanup_scope", true],
  'cleanup scheduler filters standalone scope in SQL' => ["web/tools/sfm_remote_worker.php", "JSON_UNQUOTE(JSON_EXTRACT(parameters_json,'$.cleanup_scope'))='standalone'", true],
  'cleanup docs mention v2 migration' => ["web/remote_station/WEB_WORKER_SETUP.md", "20260714_sfm_remote_cleanup_runs_v2.sql", true],
  'cleanup cli done returns saved result' => ["web/remote_station/cleanup_sfm_artifacts.php", "remote_cleanup_status'])==='DONE'", true],
  'cleanup sparse result json under colmap' => ["web/remote_station/sfm_cleanup.php", "\$base.'/colmap/result.json'", true],
  'cleanup dense chunk strict fused and result' => ["web/remote_station/sfm_cleanup.php", "missing dense chunk required artifacts", true],
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