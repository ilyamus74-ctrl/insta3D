<?php
declare(strict_types=1);
function check(bool $v,string $m):void { if(!$v) throw new RuntimeException($m); }
$web=(string)file_get_contents(__DIR__.'/../libs/auto_photo_sparse_web_lib.php');
$worker=(string)file_get_contents(__DIR__.'/../tools/sfm_remote_worker.php');
$api=(string)file_get_contents(__DIR__.'/../www/api/sfm_remote_job_status.php');
foreach(['standalone_auto_photo_dense','dense_only','sparse_db_job_id','sparse_job_id','sparse_remote_job_id',"sfm_pipeline_preset('preview')",'pipeline_run_id'] as $needle) check(str_contains($web,$needle),'web marker '.$needle);
check(str_contains($web, <<<'TEXT'
'max_image_size'=>$preset['max_image_size']
TEXT),'preview settings snapshot');
check(str_contains($worker, <<<'TEXT'
'settings'=>($params['settings'] ?? worker_run_parameters($db,$p))
TEXT),'normal/retry settings propagation');
check(str_contains($worker, <<<'TEXT'
($denseMarkers['standalone_auto_photo_dense'] ?? null) === true && ($denseMarkers['dense_only'] ?? null) === true
TEXT),'two-marker mesh guard');
check(str_contains($api,'element vertex ([1-9][0-9]*)'),'positive PLY vertices');
echo "OK\n";
