<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(1); }
$connectCandidates = ['/home/makler/web/configs/connectDB.php', __DIR__ . '/../configs/connectDB.php'];
foreach ($connectCandidates as $connectFile) {
    if (is_file($connectFile)) {
        require_once $connectFile;
        break;
    }
}
if (!isset($dbcnx) || !($dbcnx instanceof mysqli)) {
    fwrite(STDERR, "ERROR: failed to initialize mysqli via connectDB.php\n");
    exit(1);
}

const COLMAP_BIN = '/usr/local/bin/colmap';
const SFM_TOOL_BIN = '/home/makler/web/tools/sfm_cpp/build/bin/sfm_tool';
const STORAGE_ROOT = '/home/makler/web/storage/orders';

function failJob(mysqli $db, int $jobId, string $msg): void {
    $st = $db->prepare("UPDATE processing_jobs SET status='FAILED', metric_status='FAILED', error_text=?, updated_at=NOW(6) WHERE id=?");
    $st->bind_param('si', $msg, $jobId); $st->execute(); $st->close();
}
function logLine(string $f, string $m): void { file_put_contents($f, '['.date('Y-m-d H:i:s')."] {$m}\n", FILE_APPEND); }
function runStep(string $name, string $cmd, string $log): void {
    $t=microtime(true); logLine($log, "START {$name}"); logLine($log, $cmd);
    $out=[]; $rc=0; exec($cmd.' 2>&1',$out,$rc); if ($out) file_put_contents($log, implode("\n",$out)."\n", FILE_APPEND);
    if ($rc!==0){ logLine($log, "FAILED {$name} exit_code={$rc}"); throw new RuntimeException("{$name} failed"); }
    logLine($log, sprintf('DONE %s elapsed=%.3fs',$name,microtime(true)-$t));
}

function runStepSoft(string $name, string $cmd, string $log): bool {
    try {
        runStep($name, $cmd, $log);
        return true;
    } catch (Throwable $e) {
        logLine($log, "WARNING {$name} failed: " . $e->getMessage());
        return false;
    }
}
function countJpgFiles(string $dir, string $pattern = '*.jpg'): int {
    $files = glob(rtrim($dir, '/') . '/' . $pattern);
    return is_array($files) ? count($files) : 0;
}

$opts=getopt('', ['limit::','job-id::']); $limit=max(1,(int)($opts['limit']??1)); $jobIdFilter=isset($opts['job-id'])?(int)$opts['job-id']:0;
$processed=0;
while($processed<$limit){
    $sql = $jobIdFilter>0
        ? "SELECT * FROM processing_jobs WHERE id={$jobIdFilter} AND job_type='SFM_VIDEO_PIPELINE' LIMIT 1"
        : "SELECT * FROM processing_jobs WHERE job_type='SFM_VIDEO_PIPELINE' AND status IN ('NOT_STARTED','QUEUED','PENDING') ORDER BY id ASC LIMIT 1";
    $job=$dbcnx->query($sql)->fetch_assoc(); if(!$job) break;
    $jobId=(int)$job['id'];
    $u=$dbcnx->prepare("UPDATE processing_jobs SET status='RUNNING',updated_at=NOW(6) WHERE id=? AND status IN ('NOT_STARTED','QUEUED','PENDING')");
    $u->bind_param('i',$jobId); $u->execute(); $locked=$u->affected_rows>0; $u->close(); if(!$locked){ if($jobIdFilter>0) break; continue; }

    try {
        if (!is_file(COLMAP_BIN) || !is_file(SFM_TOOL_BIN)) throw new RuntimeException('required binaries missing');
        $orderId=(int)$job['order_id']; $sessionId=(int)$job['session_id']; if($orderId<=0||$sessionId<=0) throw new RuntimeException('invalid order/session');
        $payload=json_decode((string)($job['warning_text']??''), true); if(!is_array($payload)) $payload=[];
        $videoPath=(string)($payload['video_path']??''); if($videoPath===''||!is_file($videoPath)) throw new RuntimeException('video_path missing or not found');
        $cameraType = strtoupper((string)($payload['camera_type'] ?? 'INSTA360_DUAL_VIDEO'));

        if (!in_array($cameraType, ['INSTA360_DUAL_VIDEO', 'PHONE_VIDEO'], true)) throw new RuntimeException('unsupported camera_type');
        $sfmFps = isset($payload['sfm_fps']) ? (float)$payload['sfm_fps'] : 3.0;
        $keyframeFps = isset($payload['keyframe_fps']) ? (float)$payload['keyframe_fps'] : 0.33;
        $frameWidth = isset($payload['frame_width']) ? (int)$payload['frame_width'] : 1920;
        $markerSize = isset($payload['marker_size_m']) ? (float)$payload['marker_size_m'] : 0.16;
        $markerFamily = isset($payload['marker_family']) ? (string)$payload['marker_family'] : 'tag36h11';

        $sessionDir = '';
        $st=$dbcnx->prepare('SELECT session_dir FROM video_sfm_runs WHERE order_id=? AND session_id=? ORDER BY id DESC LIMIT 1');
        $st->bind_param('ii',$orderId,$sessionId);
        $st->execute();
        $runRow=$st->get_result()->fetch_assoc();
        $st->close();

        if ($runRow && isset($runRow['session_dir'])) {
            $candidate = trim((string)$runRow['session_dir']);
            if ($candidate !== '' && preg_match('/^[a-zA-Z0-9_-]+$/', $candidate)) {
                $sessionDir = $candidate;
            }
        }

        if ($sessionDir === '') {
            $st=$dbcnx->prepare('SELECT app_session_uuid FROM capture_sessions WHERE id=? AND order_id=? LIMIT 1');
            $st->bind_param('ii',$sessionId,$orderId);
            $st->execute();
            $sess=$st->get_result()->fetch_assoc();
            $st->close();
            if(!$sess) throw new RuntimeException('capture_session not found');

            $uuid=preg_replace('/[^a-zA-Z0-9_-]/','_',trim((string)$sess['app_session_uuid']));
            if (preg_match('/_' . preg_quote((string)$orderId, '/') . '$/', $uuid)) {
                $sessionDir = $uuid;
            } else {
                $sessionDir = $uuid . '_' . $orderId;
            }
        }

        $sessionBase=STORAGE_ROOT."/{$orderId}/sessions/{$sessionDir}"; $realRoot=realpath(STORAGE_ROOT); @mkdir($sessionBase,0775,true);
        $realSession=realpath($sessionBase); if($realRoot===false||$realSession===false||strpos($realSession,$realRoot)!==0) throw new RuntimeException('session path outside storage');
        $sfmBase=$sessionBase.'/sfm'; foreach(['','/frames','/keyframes','/markers','/colmap/sparse','/trajectory','/logs'] as $d){ @mkdir($sfmBase.$d,0775,true); }
        $log=$sfmBase.'/logs/sfm_pipeline_job_'.$jobId.'_'.date('Ymd_His').'.log';

        runStep('prepare_video', 'test -f '.escapeshellarg($videoPath), $log);
        $fpsFilterSfm = sprintf('fps=%s,scale=%d:-1', rtrim(rtrim(sprintf('%.6F', $sfmFps), '0'), '.'), $frameWidth);
        $fpsFilterKf = sprintf('fps=%s,scale=%d:-1', rtrim(rtrim(sprintf('%.6F', $keyframeFps), '0'), '.'), $frameWidth);
        $mapArg = $cameraType === 'PHONE_VIDEO' ? ' -map 0:v:0' : '';
        runStep('extract_sfm_frames', 'rm -rf '.escapeshellarg($sfmBase.'/frames').' && mkdir -p '.escapeshellarg($sfmBase.'/frames').' && ffmpeg -y -i '.escapeshellarg($videoPath).$mapArg.' -vf '.escapeshellarg($fpsFilterSfm).' -q:v 2 '.escapeshellarg($sfmBase.'/frames/frame_%06d.jpg'), $log);
        runStep('extract_project_keyframes', 'rm -rf '.escapeshellarg($sfmBase.'/keyframes').' && mkdir -p '.escapeshellarg($sfmBase.'/keyframes').' && ffmpeg -y -i '.escapeshellarg($videoPath).$mapArg.' -vf '.escapeshellarg($fpsFilterKf).' -q:v 2 '.escapeshellarg($sfmBase.'/keyframes/keyframe_%06d.jpg'), $log);

        if ($cameraType === 'INSTA360_DUAL_VIDEO') {
            runStepSoft(
                'build_viewer_keyframes',
                'php ' . escapeshellarg(__DIR__ . '/sfm_build_viewer_keyframes.php')
                . ' --order-id=' . $orderId
                . ' --session-id=' . $sessionId
                . ' --video-path=' . escapeshellarg($videoPath)
                . ' --keyframe-fps=' . escapeshellarg((string)$keyframeFps) . ' --frame-size=' . $frameWidth . ' --output-width=4096 --output-height=2048',
                $log
            );
            file_put_contents($sfmBase.'/camera_profile.json', json_encode(['name'=>'insta360_video_test_1920','image_width'=>1920,'image_height'=>1920,'camera_model'=>'OPENCV','fx'=>960.0,'fy'=>960.0,'cx'=>960.0,'cy'=>960.0,'dist'=>[0,0,0,0,0]], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
        } else {
            runStep('build_viewer_keyframes_phone', 'rm -rf ' . escapeshellarg($sfmBase.'/viewer_keyframes') . ' && mkdir -p ' . escapeshellarg($sfmBase.'/viewer_keyframes') . ' && bash -lc ' . escapeshellarg('shopt -s nullglob; cp ' . escapeshellarg($sfmBase.'/keyframes') . '/keyframe_*.jpg ' . escapeshellarg($sfmBase.'/viewer_keyframes/') ), $log);
            $viewerSummary = [
                'ok' => true,
                'viewer_keyframes_count' => countJpgFiles($sfmBase . '/viewer_keyframes', 'keyframe_*.jpg'),
                'source' => 'phone_video',
                'preview_type' => 'perspective',
            ];
            file_put_contents($sfmBase.'/viewer_keyframes_summary.json', json_encode($viewerSummary, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
            $firstFrame = $sfmBase . '/frames/frame_000001.jpg';
            $size = is_file($firstFrame) ? @getimagesize($firstFrame) : false;
            $w = (is_array($size) && isset($size[0]) && (int)$size[0] > 0) ? (int)$size[0] : 1920;
            $h = (is_array($size) && isset($size[1]) && (int)$size[1] > 0) ? (int)$size[1] : 1080;
            $f = max($w, $h) * 0.8;
            file_put_contents($sfmBase.'/camera_profile.json', json_encode(['name'=>'phone_video_'.$w,'image_width'=>$w,'image_height'=>$h,'camera_model'=>'OPENCV','fx'=>$f,'fy'=>$f,'cx'=>$w/2.0,'cy'=>$h/2.0,'dist'=>[0,0,0,0,0]], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
        }
        runStep('detect_apriltags', escapeshellarg(SFM_TOOL_BIN).' detect-apriltag-frames --frames '.escapeshellarg($sfmBase.'/frames').' --camera-profile '.escapeshellarg($sfmBase.'/camera_profile.json').' --marker-size-m '.escapeshellarg((string)$markerSize).' --family '.escapeshellarg($markerFamily).' --out '.escapeshellarg($sfmBase.'/markers/marker_observations.json'), $log);
        runStep('colmap_feature_extractor', escapeshellarg(COLMAP_BIN).' feature_extractor --database_path '.escapeshellarg($sfmBase.'/colmap/database.db').' --image_path '.escapeshellarg($sfmBase.'/frames').' --ImageReader.single_camera 1', $log);
        runStep('colmap_sequential_matcher', escapeshellarg(COLMAP_BIN).' sequential_matcher --database_path '.escapeshellarg($sfmBase.'/colmap/database.db').' --SequentialMatching.overlap 20', $log);
        runStep('colmap_mapper', 'rm -rf '.escapeshellarg($sfmBase.'/colmap/sparse').' && mkdir -p '.escapeshellarg($sfmBase.'/colmap/sparse').' && '.escapeshellarg(COLMAP_BIN).' mapper --database_path '.escapeshellarg($sfmBase.'/colmap/database.db').' --image_path '.escapeshellarg($sfmBase.'/frames').' --output_path '.escapeshellarg($sfmBase.'/colmap/sparse'), $log);
        runStep('colmap_model_converter', 'mkdir -p '.escapeshellarg($sfmBase.'/colmap/sparse/0_txt').' && '.escapeshellarg(COLMAP_BIN).' model_converter --input_path '.escapeshellarg($sfmBase.'/colmap/sparse/0').' --output_path '.escapeshellarg($sfmBase.'/colmap/sparse/0_txt').' --output_type TXT', $log);
        runStep('parse_colmap_images', escapeshellarg(SFM_TOOL_BIN).' parse-colmap-images --images '.escapeshellarg($sfmBase.'/colmap/sparse/0_txt/images.txt').' --out '.escapeshellarg($sfmBase.'/trajectory/camera_poses.json'), $log);
        runStep('rough_scale', escapeshellarg(SFM_TOOL_BIN).' rough-scale --poses '.escapeshellarg($sfmBase.'/trajectory/camera_poses.json').' --markers '.escapeshellarg($sfmBase.'/markers/marker_observations.json').' --out '.escapeshellarg($sfmBase.'/trajectory/trajectory_scaled.json'), $log);
        runStep('sfm_finalize_run.php', 'php '.escapeshellarg(__DIR__.'/sfm_finalize_run.php').' --order-id='.$orderId.' --session-dir='.escapeshellarg($sessionDir).' --video-path='.escapeshellarg($videoPath).' --sfm-fps='.escapeshellarg((string)$sfmFps).' --keyframe-fps='.escapeshellarg((string)$keyframeFps), $log);
        runStep('sfm_materialize_keyframes.php', 'php '.escapeshellarg(__DIR__.'/sfm_materialize_keyframes.php').' --order-id='.$orderId.' --session-id='.$sessionId, $log);

        $summary=json_decode((string)@file_get_contents($sfmBase.'/sfm_result_summary.json'),true)?:[];
        $markerData=json_decode((string)@file_get_contents($sfmBase.'/markers/marker_observations.json'),true)?:[];
        $markerCount = isset($markerData['observations'])&&is_array($markerData['observations'])?count($markerData['observations']):(is_array($markerData)?count($markerData):0);
        $metric=((string)($summary['metric_status']??''))==='METRIC_READY'?'METRIC_READY':'NOT_READY';
        $warn=json_encode(['session_dir'=>$sessionDir,'video_path'=>$videoPath,'sfm_base'=>$sfmBase,'log_path'=>$log,'frames_count'=>(int)($summary['frames_count']??0),'keyframes_count'=>(int)($summary['keyframes_count']??0),'marker_count'=>$markerCount,'poses_count'=>(int)($summary['poses_count']??0)], JSON_UNESCAPED_SLASHES);
        $up=$dbcnx->prepare("UPDATE processing_jobs SET status='PROCESSED', metric_status=?, markers_detected_count=?, warning_text=?, error_text=NULL, updated_at=NOW(6) WHERE id=?");
        $up->bind_param('sisi',$metric,$markerCount,$warn,$jobId); $up->execute(); $up->close();
        echo "OK\nprocessed_jobs=1\njob_id={$jobId}\nstatus=PROCESSED\nmetric_status={$metric}\n";
        $processed++;
    } catch(Throwable $e){ failJob($dbcnx,$jobId,$e->getMessage()); echo "ERROR job_id={$jobId} {$e->getMessage()}\n"; $processed++; }
    if($jobIdFilter>0) break;
}
if($processed===0){ echo "OK\nprocessed_jobs=0\n"; }
