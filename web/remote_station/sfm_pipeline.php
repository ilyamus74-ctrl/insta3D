<?php
declare(strict_types=1);

function sfm_pipeline_preset(string $mode): array
{
    return match ($mode) {
        'preview' => ['label'=>'Preview 640','max_image_size'=>640,'frame_profile'=>'preview','target_images_per_chunk'=>50,'max_images_per_chunk'=>70,'overlap_images'=>15,'patchmatch_cache_size'=>2,'fusion_cache_size'=>2,'num_src_images'=>6,'mesh_depth'=>7,'target_faces'=>100000],
        'standard' => ['label'=>'Standard 1600','max_image_size'=>1600,'frame_profile'=>'standard','target_images_per_chunk'=>35,'max_images_per_chunk'=>50,'overlap_images'=>12,'patchmatch_cache_size'=>4,'fusion_cache_size'=>4,'num_src_images'=>8,'mesh_depth'=>8,'target_faces'=>300000],
        'fullhd' => ['label'=>'Full HD 1920','max_image_size'=>1920,'frame_profile'=>'fullhd','target_images_per_chunk'=>25,'max_images_per_chunk'=>35,'overlap_images'=>10,'patchmatch_cache_size'=>4,'fusion_cache_size'=>4,'num_src_images'=>8,'mesh_depth'=>9,'target_faces'=>500000],
        default => throw new InvalidArgumentException('Unsupported pipeline mode'),
    };
}

function sfm_pipeline_modes(): array { return ['preview','standard','fullhd']; }
function sfm_pipeline_output_dir(int $pipelineRunId): string { return '/home/makler/web/remote_station/output/pipeline_' . $pipelineRunId; }
function sfm_pipeline_remote_output_dir(int $pipelineRunId): string { return '/home/makler_storage/output/pipeline_' . $pipelineRunId; }

function ensure_sfm_pipeline_tables(mysqli $db): void
{
    $sql = "CREATE TABLE IF NOT EXISTS sfm_pipeline_runs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        order_id BIGINT UNSIGNED NOT NULL,
        capture_session_id BIGINT UNSIGNED NOT NULL,
        video_scan_id BIGINT UNSIGNED NULL,
        pipeline_mode ENUM('preview','standard','fullhd') NOT NULL,
        max_image_size INT NOT NULL,
        status ENUM('QUEUED','RUNNING','DONE','ERROR','CANCELLED') NOT NULL DEFAULT 'QUEUED',
        stage ENUM('QUEUED','EXTRACT_FRAMES','SPARSE','DENSE_PLAN','DENSE','MERGE','MESH','FETCH_RESULT','DONE','ERROR') NOT NULL DEFAULT 'QUEUED',
        progress_percent INT NOT NULL DEFAULT 0,
        message TEXT NULL,
        sparse_model_id INT NULL,
        registered_images INT NULL,
        sparse_points INT NULL,
        dense_points INT NULL,
        mesh_vertices INT NULL,
        mesh_faces INT NULL,
        root_remote_job_id BIGINT NULL,
        output_point_cloud_path VARCHAR(500) NULL,
        output_mesh_path VARCHAR(500) NULL,
        output_result_json_path VARCHAR(500) NULL,
        unified_log_path VARCHAR(500) NULL,
        parameters_json LONGTEXT NULL,
        started_by_user_id BIGINT UNSIGNED NULL,
        extracted_frames INT NULL,
        registration_ratio DECIMAL(6,2) NULL,
        sparse_models_count INT NULL,
        selected_model_id INT NULL,
        selected_model_points INT NULL,
        sparse_diagnostics_json LONGTEXT NULL,
        sparse_reprojection_p95 DECIMAL(8,3) NULL,
        sparse_position_jumps INT NULL,
        sparse_pose_clusters INT NULL,
        error_json LONGTEXT NULL,
        started_at DATETIME(6) NULL,
        finished_at DATETIME(6) NULL,
        created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
        updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
        INDEX idx_pipeline_session (capture_session_id),
        INDEX idx_pipeline_status (status),
        INDEX idx_pipeline_mode (capture_session_id, pipeline_mode)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if (!$db->query($sql)) {
    error_log(
        'failed to ensure sfm_pipeline_runs: ' .
        $db->error
    );
    return;
}

    $res = $db->query("SHOW COLUMNS FROM sfm_remote_jobs LIKE 'pipeline_run_id'");
    $exists = $res && $res->num_rows > 0; if ($res) { $res->close(); }
    if (!$exists) { @$db->query("ALTER TABLE sfm_remote_jobs ADD COLUMN pipeline_run_id BIGINT UNSIGNED NULL AFTER capture_session_id, ADD INDEX idx_sfm_pipeline_run_id (pipeline_run_id)"); }
    foreach(['parameters_json'=>'LONGTEXT NULL','started_by_user_id'=>'BIGINT UNSIGNED NULL','extracted_frames'=>'INT NULL','registration_ratio'=>'DECIMAL(6,2) NULL','sparse_models_count'=>'INT NULL','selected_model_id'=>'INT NULL','selected_model_points'=>'INT NULL','sparse_diagnostics_json'=>'LONGTEXT NULL','sparse_reprojection_p95'=>'DECIMAL(8,3) NULL','sparse_position_jumps'=>'INT NULL','sparse_pose_clusters'=>'INT NULL'] as $c=>$def){ $r=$db->query("SHOW COLUMNS FROM sfm_pipeline_runs LIKE '".$db->real_escape_string($c)."'"); $ok=$r&&$r->num_rows>0; if($r){$r->close();} if(!$ok){ @$db->query('ALTER TABLE sfm_pipeline_runs ADD COLUMN '.$c.' '.$def); } }
    @$db->query("ALTER TABLE sfm_pipeline_runs MODIFY status ENUM('QUEUED','RUNNING','DONE','ERROR','CANCELLED','CANCELLING','RESTARTING') NOT NULL DEFAULT 'QUEUED'");
    @$db->query("ALTER TABLE sfm_pipeline_runs MODIFY stage ENUM('QUEUED','EXTRACT_FRAMES','SPARSE','DENSE_PLAN','DENSE','MERGE','MESH','FETCH_RESULT','DONE','ERROR','CANCELLED','CANCELLING') NOT NULL DEFAULT 'QUEUED'");
}

function pipeline_log(int $pipelineRunId, string $level, string $stage, string $message): void
{
    $line = date('c') . ' | ' . strtoupper($level) . ' | ' . strtoupper($stage) . ' | ' . str_replace(["\r","\n"], ' ', $message) . "\n";
    foreach ([sfm_pipeline_output_dir($pipelineRunId), sfm_pipeline_remote_output_dir($pipelineRunId)] as $dir) {
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
        @file_put_contents($dir . '/pipeline.log', $line, FILE_APPEND | LOCK_EX);
    }
}

function sfm_pipeline_progress(string $stage, int $doneChunks=0, int $totalChunks=0): int
{
    return match ($stage) {
        'QUEUED' => 0, 'EXTRACT_FRAMES' => 5, 'SPARSE' => 15, 'DENSE_PLAN' => 35,
        'DENSE' => $totalChunks > 0 ? max(40, min(80, 40 + (int)round($doneChunks / $totalChunks * 40))) : 40,
        'MERGE' => 80, 'MESH' => 88, 'FETCH_RESULT' => 97, 'DONE' => 100, 'ERROR' => 100,
        default => 0,
    };
}

function sfm_pipeline_update(mysqli $db, int $id, string $status, string $stage, int $progress, string $message='', array $extra=[]): void
{
    $sets = ['status=?','stage=?','progress_percent=GREATEST(progress_percent, ?)','message=?','updated_at=NOW(6)'];
    $types = 'ssis'; $params = [$status,$stage,$progress,$message];
    foreach ($extra as $col=>$val) { $sets[] = "`$col`=?"; $types .= is_int($val) ? 'i' : 's'; $params[] = $val; }
    if (in_array($status, ['DONE','ERROR','CANCELLED'], true)) { $sets[]='finished_at=COALESCE(finished_at,NOW(6))'; }
    $params[] = $id; $types .= 'i';
    $st=$db->prepare('UPDATE sfm_pipeline_runs SET '.implode(',',$sets).' WHERE id=?');
    if ($st) { $st->bind_param($types, ...$params); $st->execute(); $st->close(); }
}

function sfm_pipeline_last_log(string $path, int $lines=300): string
{
    if ($path === '' || !is_file($path) || !is_readable($path)) { return ''; }
    $data = @file($path, FILE_IGNORE_NEW_LINES); if (!$data) { return ''; }
    return implode("\n", array_slice($data, -$lines));
}

function sfm_sparse_geometry_confidence(?array $diagnostics): string
{
    if (!$diagnostics || ($diagnostics['status'] ?? '') !== 'DONE') { return 'Unknown'; }
    $ratio = (float)($diagnostics['registration_ratio'] ?? 0.0);
    $reproj = (float)($diagnostics['reprojection']['median_px'] ?? 0.0);
    $jumps = (int)($diagnostics['trajectory']['position_jumps'] ?? 0) + (int)($diagnostics['trajectory']['rotation_jumps'] ?? 0);
    $clusters = (int)($diagnostics['trajectory']['pose_clusters'] ?? 0);
    $imu = (int)($diagnostics['imu']['rotation_mismatches'] ?? 0);
    if ($clusters > 1 || $jumps >= 3 || $imu > 0 || $reproj >= 3.0) { return 'High risk'; }
    if ($ratio > 0.70 && $jumps <= 1 && $clusters <= 1 && $reproj < 1.5) { return 'Good'; }
    return 'Warning';
}

function sfm_sparse_diagnostics_summary(?array $diagnostics): array
{
    if (!$diagnostics || ($diagnostics['status'] ?? '') !== 'DONE') { return []; }
    $registered = (int)($diagnostics['registered_images'] ?? 0);
    $selected = (int)($diagnostics['selected_frames'] ?? 0);
    $ratio = $selected > 0 ? ($registered / $selected * 100.0) : ((float)($diagnostics['registration_ratio'] ?? 0) * 100.0);
    return [
        'registration' => sprintf('%d / %d — %.1f%%', $registered, $selected, $ratio),
        'median_reprojection_error' => sprintf('%.2f px', (float)($diagnostics['reprojection']['median_px'] ?? 0)),
        'p95_reprojection_error' => sprintf('%.2f px', (float)($diagnostics['reprojection']['p95_px'] ?? 0)),
        'position_jumps' => (int)($diagnostics['trajectory']['position_jumps'] ?? 0),
        'rotation_jumps' => (int)($diagnostics['trajectory']['rotation_jumps'] ?? 0),
        'pose_clusters' => (int)($diagnostics['trajectory']['pose_clusters'] ?? 0),
        'largest_pose_cluster' => (int)($diagnostics['trajectory']['largest_cluster_images'] ?? 0),
        'suspicious_images' => count($diagnostics['suspicious_images'] ?? []),
        'imu_comparison' => !empty($diagnostics['imu']['available']) ? 'available' : 'unavailable',
        'overall_geometry_confidence' => sfm_sparse_geometry_confidence($diagnostics),
    ];
}
