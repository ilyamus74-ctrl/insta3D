<?php
declare(strict_types=1);

$root = sys_get_temp_dir() . '/auto_photo_dense_enqueue_' . bin2hex(random_bytes(5));
define('AUTO_PHOTO_SPARSE_OUTPUT_ROOT', $root);
require_once __DIR__ . '/../libs/auto_photo_sparse_web_lib.php';

function adpe_ok(bool $value, string $message): void { if (!$value) throw new RuntimeException($message); }
function adpe_throws(callable $call, string $message): void {
    try { $call(); } catch (Throwable $e) { adpe_ok($e->getMessage() === $message, "expected {$message}, got {$e->getMessage()}"); return; }
    throw new RuntimeException("missing {$message}");
}

class AdpeResult extends mysqli_result {
    private int $position = 0;
    public function __construct(private array $rows) {}
    public function fetch_assoc(): array|null|false { return $this->rows[$this->position++] ?? null; }
}

class AdpeStatement extends mysqli_stmt {
    private array $bound = [];
    private string $types = '';
    public function __construct(private AdpeDb $db, private string $sql) {}
    public function bind_param(string $types, mixed &...$vars): bool {
        $this->types = $types; $this->bound = $vars;
        $this->db->binds[] = ['sql'=>$this->sql, 'types'=>$types, 'bound'=>$vars];
        return true;
    }
    public function execute(?array $params = null): bool {
        if (str_starts_with($this->sql, 'INSERT INTO sfm_remote_jobs')) {
            if ($this->db->failInsert) return false;
            $this->db->inserts[] = ['sql'=>$this->sql, 'types'=>$this->types, 'bound'=>$this->bound];
        }
        return true;
    }
    public function get_result(): mysqli_result|false {
        if (str_contains($this->sql, "job_type='COLMAP_RECONSTRUCTION_PREVIEW'")) return new AdpeResult($this->db->denseDuplicates);
        if (str_contains($this->sql, "id=? AND order_id=? AND job_type='COLMAP_SPARSE'")) return new AdpeResult([$this->db->sparse]);
        if (str_contains($this->sql, 'FROM capture_bundles')) return new AdpeResult([$this->db->bundle]);
        if (str_contains($this->sql, 'capture_session_id=? AND job_type=?')) return new AdpeResult([$this->db->prepare]);
        return new AdpeResult([]);
    }
    public function close(): true { return true; }
}

class AdpeDb extends mysqli {
    public bool $committed = false;
    public bool $rolledBack = false;
    public bool $failInsert = false;
    public array $denseDuplicates = [];
    public array $inserts = [];
    public array $binds = [];
    public function __construct(public array $sparse, public array $bundle, public array $prepare) {}
    public function begin_transaction(int $flags = 0, ?string $name = null): bool { return true; }
    public function prepare(string $query): mysqli_stmt|false { return new AdpeStatement($this, $query); }
    public function commit(int $flags = 0, ?string $name = null): bool { $this->committed = true; return true; }
    public function rollback(int $flags = 0, ?string $name = null): bool { $this->rolledBack = true; return true; }
}

function adpe_db(string $status = 'DONE'): AdpeDb {
    return new AdpeDb(
        [
            'id'=>746, 'order_id'=>30, 'capture_session_id'=>63, 'job_type'=>'COLMAP_SPARSE',
            'remote_job_id'=>9002, 'parent_remote_job_id'=>9001, 'status'=>$status,
            'parameters_json'=>json_encode([
                'source_type'=>'auto_photo_prepare', 'standalone_sparse'=>true,
                'prepare_job_id'=>745, 'prepare_remote_job_id'=>9001,
                'capture_bundle_id'=>7, 'app_bundle_uuid'=>'bundle-uuid',
            ]),
        ],
        ['id'=>7, 'order_id'=>30, 'capture_session_id'=>63, 'app_bundle_uuid'=>'bundle-uuid'],
        [
            'id'=>745, 'order_id'=>30, 'capture_session_id'=>63,
            'job_type'=>AUTO_PHOTO_PREPARE_JOB_TYPE, 'remote_job_id'=>9001, 'status'=>'DONE',
            'output_path'=>auto_photo_sparse_output_path(9001),
            'result_json_path'=>auto_photo_sparse_result_path(9001),
            'parameters_json'=>json_encode(['source_type'=>'auto_photo_bundle', 'pipeline_mode'=>'prepare']),
        ]
    );
}

function adpe_write_fixture(): string {
    mkdir(auto_photo_sparse_output_path(9001) . '/frames', 0775, true);
    file_put_contents(auto_photo_sparse_output_path(9001) . '/frames/frame_000001.jpg', 'x');
    file_put_contents(auto_photo_sparse_result_path(9001), json_encode([
        'schema_version'=>1, 'job_type'=>AUTO_PHOTO_PREPARE_JOB_TYPE, 'status'=>'DONE',
        'remote_job_id'=>9001, 'capture_bundle_id'=>7, 'app_bundle_uuid'=>'bundle-uuid',
        'frames_count'=>1, 'frames_directory'=>'frames', 'warnings'=>[],
    ]));
    mkdir(auto_photo_sparse_output_path(9002) . '/colmap', 0775, true);
    $path = auto_photo_sparse_output_path(9002) . '/colmap/sparse_components.json';
    file_put_contents($path, json_encode(['models'=>[
        ['model_id'=>0, 'registered_images'=>12, 'points3D_count'=>100],
        ['model_id'=>1, 'registered_images'=>9, 'points3D_count'=>90],
    ]]));
    return (string)file_get_contents($path);
}

function adpe_rm(string $path): void {
    if (!file_exists($path) && !is_link($path)) return;
    if (is_file($path) || is_link($path)) { unlink($path); return; }
    foreach (scandir($path) ?: [] as $entry) if ($entry !== '.' && $entry !== '..') adpe_rm($path . '/' . $entry);
    rmdir($path);
}

try {
    $manifest = adpe_write_fixture();
    $insertId = static fn(mysqli $db): int => 981;
    $remoteId = static fn(mysqli $db): int => 9200;

    $db = adpe_db();
    $result = auto_photo_sparse_web_enqueue_dense_preview($db, 30, 746, '0', $insertId, $remoteId);
    adpe_ok($result === [
        'duplicate'=>false, 'dense_db_job_id'=>981, 'dense_remote_job_id'=>9200,
        'sparse_db_job_id'=>746, 'sparse_remote_job_id'=>9002, 'model_id'=>0,
    ], 'model 0 result contract');
    adpe_ok($db->committed && count($db->inserts) === 1, 'model 0 queues one job');

    $insert = $db->inserts[0];
    adpe_ok($insert['types'] === 'iisiississsss', 'progress_percent bind type is integer');
    adpe_ok(strlen($insert['types']) === count($insert['bound']), 'bind count matches');
    adpe_ok(str_contains($insert['sql'], 'VALUES (?,?,NULL,'), 'pipeline_run_id is NULL');
    adpe_ok($insert['bound'][2] === 'COLMAP_RECONSTRUCTION_PREVIEW', 'job type');
    adpe_ok($insert['bound'][3] === 9200 && $insert['bound'][4] === 9002, 'independent remote and sparse parent');
    adpe_ok($insert['bound'][5] === auto_photo_sparse_output_path(9200) . '/merged/merged_fused.ply', 'exact output path');
    adpe_ok($insert['bound'][7] === 0 && is_int($insert['bound'][7]), 'progress value is integer');
    adpe_ok($insert['bound'][11] === 'preview', 'preview reconstruction mode');

    $params = json_decode((string)$insert['bound'][12], true);
    foreach ([
        'source_type'=>'auto_photo_sparse', 'standalone_auto_photo_dense'=>true,
        'dense_only'=>true, 'sparse_db_job_id'=>746, 'sparse_job_id'=>9002,
        'sparse_remote_job_id'=>9002, 'model_id'=>0, 'max_image_size'=>640,
        'target_images_per_chunk'=>50, 'max_images_per_chunk'=>70,
        'overlap_images'=>15, 'num_src_images'=>6,
    ] as $key=>$expected) adpe_ok(($params[$key] ?? null) === $expected, "parameter {$key}");
    foreach ([
        'max_image_size'=>640, 'num_src_images'=>6, 'target_images_per_chunk'=>50,
        'max_images_per_chunk'=>70, 'chunk_overlap'=>15,
    ] as $key=>$expected) adpe_ok(($params['settings']['dense'][$key] ?? null) === $expected, "dense setting {$key}");

    $unknown = adpe_db();
    adpe_throws(fn() => auto_photo_sparse_web_enqueue_dense_preview($unknown, 30, 746, 9, $insertId, $remoteId), 'sparse_model_not_found');
    adpe_ok($unknown->rolledBack && $unknown->inserts === [], 'unknown model rolls back');

    $small = adpe_db();
    adpe_throws(fn() => auto_photo_sparse_web_enqueue_dense_preview($small, 30, 746, 1, $insertId, $remoteId), 'sparse_model_insufficient_registered_images');
    adpe_ok($small->rolledBack && $small->inserts === [], 'small model rolls back');

    $running = adpe_db('RUNNING');
    adpe_throws(fn() => auto_photo_sparse_web_enqueue_dense_preview($running, 30, 746, 0, $insertId, $remoteId), 'sparse_job_not_ready');
    adpe_ok($running->rolledBack && $running->inserts === [], 'running sparse rolls back');

    foreach (['QUEUED','RUNNING','PLANNING','RUNNING_CHUNKS','MERGING','DONE'] as $status) {
        $duplicate = adpe_db();
        $duplicate->denseDuplicates = [['id'=>982, 'remote_job_id'=>9300]];
        $duplicateResult = auto_photo_sparse_web_enqueue_dense_preview($duplicate, 30, 746, 0, $insertId, $remoteId);
        adpe_ok(($duplicateResult['duplicate'] ?? false) === true, "{$status} duplicate blocks");
        adpe_ok($duplicate->committed && $duplicate->inserts === [], "{$status} no insert");
    }
    $duplicateSql = implode("\n", array_column($duplicate->binds, 'sql'));
    foreach (['QUEUED','RUNNING','PLANNING','RUNNING_CHUNKS','MERGING','DONE'] as $status) adpe_ok(str_contains($duplicateSql, "'{$status}'"), "duplicate SQL includes {$status}");

    $sameRemote = adpe_db();
    adpe_throws(
        fn() => auto_photo_sparse_web_enqueue_dense_preview($sameRemote, 30, 746, 0, $insertId, static fn(mysqli $db): int => 9002),
        'dense_preview_remote_job_id_invalid'
    );
    adpe_ok($sameRemote->rolledBack && $sameRemote->inserts === [], 'source remote cannot be reused');

    adpe_ok(file_get_contents(auto_photo_sparse_output_path(9002) . '/colmap/sparse_components.json') === $manifest, 'source manifest unchanged');
    echo "OK\n";
} finally {
    adpe_rm($root);
}
