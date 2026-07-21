<?php
declare(strict_types=1);

$testRoot = sys_get_temp_dir() . '/maklertour_auto_photo_sparse_web_' . bin2hex(random_bytes(6));
define('AUTO_PHOTO_SPARSE_OUTPUT_ROOT', $testRoot);
require_once __DIR__ . '/../libs/auto_photo_sparse_lib.php';
require_once __DIR__ . '/../libs/auto_photo_sparse_web_lib.php';

function apsw_assert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function apsw_expect(callable $callback, string $expected): void { try { $callback(); } catch (Throwable $e) { apsw_assert($e->getMessage() === $expected, "expected {$expected}, got {$e->getMessage()}"); return; } throw new RuntimeException("missing {$expected}"); }

class ApswResult extends mysqli_result {
    public function __construct(private ?array $row) {}
    public function fetch_assoc(): array|null|false { return $this->row; }
}
class ApswStatement extends mysqli_stmt {
    public array $bound = [];
    public function __construct(private ApswDb $db, private string $sql) {}
    public function bind_param(string $types, mixed &...$vars): bool { $this->bound = $vars; return true; }
    public function execute(?array $params = null): bool {
        if (str_starts_with($this->sql, 'UPDATE')) {
            if ($this->db->failUpdate) return false;
            $this->db->updatedParametersJson = $this->bound[0];
        }
        return true;
    }
    public function get_result(): mysqli_result|false {
        if (str_contains($this->sql, "job_type='COLMAP_SPARSE'")) return new ApswResult($this->db->sparseJob);
        if (str_contains($this->sql, 'FROM capture_bundles')) return new ApswResult($this->db->bundle);
        if (str_contains($this->sql, 'capture_session_id=? AND job_type=?')) return new ApswResult($this->db->prepareJob);
        return new ApswResult(null);
    }
    public function close(): true { return true; }
}
class ApswDb extends mysqli {
    public bool $committed = false;
    public bool $rolledBack = false;
    public bool $failUpdate = false;
    public ?string $updatedParametersJson = null;
    public function __construct(public array $sparseJob, public array $bundle, public array $prepareJob) {}
    public function begin_transaction(int $flags = 0, ?string $name = null): bool { return true; }
    public function prepare(string $query): mysqli_stmt|false { return new ApswStatement($this, $query); }
    public function commit(int $flags = 0, ?string $name = null): bool { $this->committed = true; return true; }
    public function rollback(int $flags = 0, ?string $name = null): bool { $this->rolledBack = true; return true; }
}

function apsw_db(string $status = 'DONE', string $parametersJson = ''): ApswDb {
    $parameters = ['source_type' => 'auto_photo_prepare', 'standalone_sparse' => true, 'prepare_job_id' => 745, 'prepare_remote_job_id' => 9001, 'capture_bundle_id' => 7, 'app_bundle_uuid' => 'bundle-uuid', 'keep' => 'unchanged'];
    $parametersJson = $parametersJson ?: json_encode($parameters);
    return new ApswDb(
        ['id' => 746, 'order_id' => 30, 'capture_session_id' => 63, 'job_type' => 'COLMAP_SPARSE', 'remote_job_id' => 9002, 'parent_remote_job_id' => 9001, 'status' => $status, 'parameters_json' => $parametersJson],
        ['id' => 7, 'order_id' => 30, 'capture_session_id' => 63, 'app_bundle_uuid' => 'bundle-uuid'],
        ['id' => 745, 'order_id' => 30, 'capture_session_id' => 63, 'job_type' => AUTO_PHOTO_PREPARE_JOB_TYPE, 'remote_job_id' => 9001, 'status' => 'DONE', 'output_path' => auto_photo_sparse_output_path(9001), 'result_json_path' => auto_photo_sparse_result_path(9001), 'parameters_json' => json_encode(['source_type' => 'auto_photo_bundle', 'pipeline_mode' => 'prepare'])]
    );
}

function apsw_write_files(): void {
    mkdir(auto_photo_sparse_output_path(9001) . '/frames', 0775, true);
    file_put_contents(auto_photo_sparse_output_path(9001) . '/frames/frame_000001.jpg', 'x');
    file_put_contents(auto_photo_sparse_result_path(9001), json_encode(['schema_version'=>1, 'job_type'=>AUTO_PHOTO_PREPARE_JOB_TYPE, 'status'=>'DONE', 'remote_job_id'=>9001, 'capture_bundle_id'=>7, 'app_bundle_uuid'=>'bundle-uuid', 'frames_count'=>1, 'frames_directory'=>'frames', 'warnings'=>[]]));
    mkdir(auto_photo_sparse_output_path(9002) . '/colmap', 0775, true);
    file_put_contents(auto_photo_sparse_output_path(9002) . '/colmap/sparse_components.json', json_encode(['models'=>[['model_id'=>0], ['model_id'=>1]] ]));
}

try {
    apsw_write_files();
    apsw_assert(auto_photo_sparse_web_parse_sparse_db_id(746) === 746, 'integer sparse id');
    apsw_assert(auto_photo_sparse_web_parse_sparse_db_id('746') === 746, 'string sparse id');
    apsw_expect(fn() => auto_photo_sparse_web_parse_sparse_db_id(0), 'invalid_sparse_db_job_id');
    apsw_expect(fn() => auto_photo_sparse_web_parse_sparse_db_id('01'), 'invalid_sparse_db_job_id');
    apsw_assert(auto_photo_sparse_parse_model_id('0') === 0, 'valid model id');
    apsw_expect(fn() => auto_photo_sparse_parse_model_id('01'), 'invalid_model_id');
    apsw_assert(auto_photo_sparse_parse_model_id(null, true) === null, 'only null is an allowed missing model id');
    apsw_expect(fn() => auto_photo_sparse_parse_model_id('', true), 'invalid_model_id');
    apsw_assert(auto_photo_sparse_validate_model_id(['models'=>[['model_id'=>1]]], 1)['model_id'] === 1, 'integer model id is accepted');
    apsw_assert(auto_photo_sparse_validate_model_id(['models'=>[['model_id'=>'1']]], 1)['model_id'] === '1', 'numeric-string model id is accepted');
    apsw_expect(fn() => auto_photo_sparse_validate_model_id(['models'=>[['model_id'=>'abc']]], 0), 'sparse_model_not_found');
    apsw_expect(fn() => auto_photo_sparse_web_select_model(apsw_db('RUNNING'), 30, 746, 0), 'sparse_job_not_ready');
    $videoSparse = apsw_db();
    $videoSparse->sparseJob['parameters_json'] = json_encode(['prepare_job_id'=>745, 'prepare_remote_job_id'=>9001, 'capture_bundle_id'=>7, 'app_bundle_uuid'=>'bundle-uuid']);
    apsw_expect(fn() => auto_photo_sparse_validate_job_scope($videoSparse, 30, $videoSparse->sparseJob), 'standalone_photo_sparse_job_not_found');
    apsw_expect(fn() => auto_photo_sparse_web_select_model($videoSparse, 30, 746, 0), 'standalone_photo_sparse_job_not_found');
    apsw_expect(fn() => auto_photo_sparse_web_select_model(apsw_db(), 30, 746, 9), 'sparse_model_not_found');
    $corrupt = apsw_db('DONE', json_encode(['source_type'=>'auto_photo_prepare', 'standalone_sparse'=>true]));
    apsw_expect(fn() => auto_photo_sparse_web_select_model($corrupt, 30, 746, 0), 'sparse_job_scope_invalid');
    apsw_assert($corrupt->updatedParametersJson === null, 'invalid parameters must not select model 0');
    $malformed = apsw_db('DONE', '{');
    apsw_expect(fn() => auto_photo_sparse_web_select_model($malformed, 30, 746, 0), 'sparse_job_scope_invalid');
    apsw_assert($malformed->updatedParametersJson === null && $malformed->rolledBack, 'malformed parameters roll back without update');
    $db = apsw_db();
    $result = auto_photo_sparse_web_select_model($db, 30, '746', '1');
    apsw_assert($result === ['sparse_db_job_id'=>746, 'model_id'=>1] && $db->committed, 'successful selection commits');
    $updated = json_decode((string)$db->updatedParametersJson, true);
    apsw_assert($updated === ['source_type'=>'auto_photo_prepare', 'standalone_sparse'=>true, 'prepare_job_id'=>745, 'prepare_remote_job_id'=>9001, 'capture_bundle_id'=>7, 'app_bundle_uuid'=>'bundle-uuid', 'keep'=>'unchanged', 'selected_model_id'=>1], 'only selected_model_id changes');
    $failed = apsw_db(); $failed->failUpdate = true;
    apsw_expect(fn() => auto_photo_sparse_web_select_model($failed, 30, 746, 1), 'sparse_model_update_failed');
    apsw_assert($failed->rolledBack, 'update exception rolls back');
    echo "OK\n";
} finally {
    $remove = static function (string $path) use (&$remove): void { if (!file_exists($path)) return; if (is_file($path)) { unlink($path); return; } foreach (scandir($path) ?: [] as $entry) if ($entry !== '.' && $entry !== '..') $remove($path.'/'.$entry); rmdir($path); };
    $remove($testRoot);
}
