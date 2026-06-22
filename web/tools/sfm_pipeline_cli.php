<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){ exit("CLI only\n"); }
$connectCandidates=['/home/makler/web/configs/connectDB.php', __DIR__.'/../configs/connectDB.php'];
foreach($connectCandidates as $f){ if(is_file($f)){ require_once $f; break; } }
require_once __DIR__.'/../remote_station/sfm_pipeline.php';
function cli_sfm_job_id(mysqli $db): int { do { $id=random_int(10000,999999999); $st=$db->prepare('SELECT id FROM sfm_remote_jobs WHERE remote_job_id=? LIMIT 1'); if(!$st){ return $id; } $st->bind_param('i',$id); $st->execute(); $exists=$st->get_result()->fetch_assoc(); $st->close(); } while($exists); return $id; }
function cli_remote_output_dir(int $id): string { return '/home/makler/web/remote_station/output/job_'.$id; }
if(!isset($dbcnx) || !($dbcnx instanceof mysqli)){ fwrite(STDERR,"DB connection not found\n"); exit(1); }
ensure_sfm_pipeline_tables($dbcnx);
$opts=getopt('', ['capture-session-id:','video-scan-id::','mode:']);
$sessionId=(int)($opts['capture-session-id'] ?? 0); $videoScanId=(int)($opts['video-scan-id'] ?? 0); $mode=(string)($opts['mode'] ?? '');
if($sessionId<=0 || $mode===''){ fwrite(STDERR,"Usage: php web/tools/sfm_pipeline_cli.php --capture-session-id=54 --video-scan-id=45 --mode=preview\n"); exit(2); }
$preset=sfm_pipeline_preset($mode);
$st=$dbcnx->prepare('SELECT order_id FROM capture_sessions WHERE id=? AND deleted_at IS NULL LIMIT 1'); $st->bind_param('i',$sessionId); $st->execute(); $row=$st->get_result()->fetch_assoc(); $st->close();
if(!$row){ fwrite(STDERR,"Capture session not found\n"); exit(1); }
$orderId=(int)$row['order_id'];
$st=$dbcnx->prepare("SELECT id FROM sfm_pipeline_runs WHERE capture_session_id=? AND pipeline_mode=? AND status IN ('QUEUED','RUNNING') LIMIT 1"); $st->bind_param('is',$sessionId,$mode); $st->execute(); $active=$st->get_result()->fetch_assoc(); $st->close();
if($active){ fwrite(STDERR,$preset['label']." already active: #".$active['id']."\n"); exit(1); }
$params=json_encode($preset + ['pipeline_mode'=>$mode,'cli'=>true], JSON_UNESCAPED_SLASHES);
$st=$dbcnx->prepare("INSERT INTO sfm_pipeline_runs (order_id,capture_session_id,video_scan_id,pipeline_mode,max_image_size,status,stage,progress_percent,message,parameters_json,started_at) VALUES (?,?,?,?,?,'QUEUED','QUEUED',0,?,?,NOW(6))");
$msg=$preset['label'].' queued from CLI'; $st->bind_param('iiisiss',$orderId,$sessionId,$videoScanId,$mode,$preset['max_image_size'],$msg,$params); $st->execute(); $pipelineRunId=(int)$dbcnx->insert_id; $st->close();
$dir=sfm_pipeline_output_dir($pipelineRunId); @mkdir($dir,0775,true); @mkdir(sfm_pipeline_remote_output_dir($pipelineRunId),0775,true); $log=$dir.'/pipeline.log';
$st=$dbcnx->prepare('UPDATE sfm_pipeline_runs SET unified_log_path=? WHERE id=?'); $st->bind_param('si',$log,$pipelineRunId); $st->execute(); $st->close();
$videoPath=''; if($videoScanId>0){ $st=$dbcnx->prepare('SELECT vs.filename, cs.app_session_uuid FROM video_scans vs JOIN capture_sessions cs ON cs.id=vs.session_id WHERE vs.id=? AND vs.session_id=? AND vs.deleted_at IS NULL LIMIT 1'); $st->bind_param('ii',$videoScanId,$sessionId); $st->execute(); $v=$st->get_result()->fetch_assoc(); $st->close(); if($v){ $safe=preg_replace('/[^a-zA-Z0-9._-]+/','_', (string)$v['app_session_uuid']); $videoPath='/home/makler/web/storage/orders/'.$orderId.'/sessions/'.$safe.'/videos/'.(string)$v['filename']; } }
if($videoPath!=='' && is_file($videoPath)){ $rid=cli_sfm_job_id($dbcnx); $out=cli_remote_output_dir($rid); $result=$out.'/result.json'; $jobLog=$out.'/logs'; $jt='EXTRACT_FRAMES'; $childMsg='pipeline extract frames queued from CLI'; $childParams=json_encode(['pipeline_run_id'=>$pipelineRunId,'frame_profile'=>$preset['frame_profile']], JSON_UNESCAPED_SLASHES); $st=$dbcnx->prepare("INSERT INTO sfm_remote_jobs (order_id,capture_session_id,pipeline_run_id,job_type,remote_job_id,input_path,output_path,status,progress_percent,message,result_json_path,log_path,parameters_json) VALUES (?,?,?,?,?,?,?,'QUEUED',0,?,?,?,?)"); $st->bind_param('iiisissssss',$orderId,$sessionId,$pipelineRunId,$jt,$rid,$videoPath,$out,$childMsg,$result,$jobLog,$childParams); $st->execute(); $st->close(); $st=$dbcnx->prepare("UPDATE sfm_pipeline_runs SET root_remote_job_id=?, stage='EXTRACT_FRAMES', progress_percent=5, message='Frame extraction queued' WHERE id=?"); $st->bind_param('ii',$rid,$pipelineRunId); $st->execute(); $st->close(); pipeline_log($pipelineRunId,'INFO','EXTRACT_FRAMES','Started, remote_job_id='.$rid); } else { pipeline_log($pipelineRunId,'WARNING','PIPELINE','CLI created run without EXTRACT_FRAMES child because video_scan file was not found'); }
echo "Created sfm_pipeline_runs id={$pipelineRunId} mode={$mode} label=\"{$preset['label']}\"\n";