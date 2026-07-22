<?php
declare(strict_types=1);

$testRoot = sys_get_temp_dir() . '/maklertour_auto_photo_sparse_web_' . bin2hex(random_bytes(6));
define('AUTO_PHOTO_SPARSE_OUTPUT_ROOT', $testRoot);
require_once __DIR__ . '/../libs/auto_photo_sparse_lib.php';
require_once __DIR__ . '/../libs/auto_photo_sparse_web_lib.php';

function apsw_assert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function apsw_expect(callable $callback, string $expected): void { try { $callback(); } catch (Throwable $e) { apsw_assert($e->getMessage() === $expected, "expected {$expected}, got {$e->getMessage()}"); return; } throw new RuntimeException("missing {$expected}"); }

class ApswResult extends mysqli_result {
    private int $position = 0;
    public function __construct(private array $rows) {}
    public function fetch_assoc(): array|null|false { return $this->rows[$this->position++] ?? null; }
}
class ApswStatement extends mysqli_stmt {
    public array $bound = [];
    public string $bindTypes = '';
    public function __construct(private ApswDb $db, private string $sql) {}
    public function bind_param(string $types, mixed &...$vars): bool { $this->bindTypes = $types; $this->bound = $vars; $this->db->binds[] = ['sql'=>$this->sql, 'types'=>$types, 'bound'=>$vars]; return true; }
    public function execute(?array $params = null): bool {
        if (str_starts_with($this->sql, 'UPDATE')) {
            if ($this->db->failUpdate) return false;
            $this->db->updatedParametersJson = $this->bound[0];
        }
        if (str_starts_with($this->sql, 'INSERT')) {
            if ($this->db->failInsert) return false;
            $this->db->inserts[] = ['sql' => $this->sql, 'bound' => $this->bound];
        }
        return true;
    }
    public function get_result(): mysqli_result|false {
        if (str_contains($this->sql, "job_type='EXPORT_PLY'")) {
            if ($this->db->failDuplicateResult) return false;
            return new ApswResult($this->db->filterExportDuplicates($this->bound));
        }
        if (str_starts_with($this->sql, 'SELECT id, status, parent_remote_job_id')) return new ApswResult($this->db->relatedJobs);
        if (str_contains($this->sql, "id=? AND order_id=? AND job_type='COLMAP_SPARSE'")) return new ApswResult([$this->db->sparseJob]);
        if (str_contains($this->sql, 'FROM capture_bundles')) return new ApswResult([$this->db->bundle]);
        if (str_contains($this->sql, 'capture_session_id=? AND job_type=?')) return new ApswResult([$this->db->prepareJob]);
        if (str_contains($this->sql, 'remote_job_id=?')) return new ApswResult([]);
        return new ApswResult([]);
    }
    public function close(): true { return true; }
}
class ApswDb extends mysqli {
    public bool $committed = false;
    public bool $rolledBack = false;
    public bool $failUpdate = false;
    public bool $failInsert = false;
    public bool $failDuplicateResult = false;
    public ?string $updatedParametersJson = null;
    public array $relatedJobs = [];
    public array $inserts = [];
    public array $binds = [];
    public array $exportDuplicateRows = [];
    public function __construct(public array $sparseJob, public array $bundle, public array $prepareJob) {}
    public function begin_transaction(int $flags = 0, ?string $name = null): bool { return true; }
    public function prepare(string $query): mysqli_stmt|false { return new ApswStatement($this, $query); }
    public function commit(int $flags = 0, ?string $name = null): bool { $this->committed = true; return true; }
    public function rollback(int $flags = 0, ?string $name = null): bool { $this->rolledBack = true; return true; }
    public function filterExportDuplicates(array $bound): array {
        [$orderId, $captureSessionId, $parentRemoteJobId, $modelId, $newOutputPath, $legacyOutputPath] = $bound;
        foreach ($this->exportDuplicateRows as $row) {
            if (($row['job_type'] ?? '') !== 'EXPORT_PLY'
                || (int)($row['order_id'] ?? 0) !== (int)$orderId
                || (int)($row['capture_session_id'] ?? 0) !== (int)$captureSessionId
                || (int)($row['parent_remote_job_id'] ?? 0) !== (int)$parentRemoteJobId
                || !in_array((string)($row['status'] ?? ''), ['QUEUED','RUNNING','DONE'], true)) continue;
            $parameters = json_decode((string)($row['parameters_json'] ?? ''), true);
            $rowModel = is_array($parameters) ? ($parameters['model_id'] ?? null) : null;
            $strictModel = is_int($rowModel) ? (string)$rowModel : (is_string($rowModel) && preg_match('/^(0|[1-9][0-9]*)$/', $rowModel) ? $rowModel : null);
            if ($strictModel === (string)$modelId || (string)($row['output_path'] ?? '') === (string)$newOutputPath || (string)($row['output_path'] ?? '') === (string)$legacyOutputPath) return [$row];
        }
        return [];
    }
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
function apsw_retry_job(string $status, bool $exhaustive = true): array { return ['status' => $status, 'parameters_json' => json_encode(['retry_mode' => $exhaustive ? 'exhaustive' : 'sequential'])]; }
function apsw_export_row(array $overrides = []): array { return array_replace(['id'=>950,'remote_job_id'=>9300,'job_type'=>'EXPORT_PLY','order_id'=>30,'capture_session_id'=>63,'parent_remote_job_id'=>9002,'status'=>'DONE','parameters_json'=>json_encode(['model_id'=>0]),'output_path'=>'/other','progress_percent'=>0,'message'=>'existing'], $overrides); }
function apsw_write_files(): void {
    mkdir(auto_photo_sparse_output_path(9001) . '/frames', 0775, true);
    file_put_contents(auto_photo_sparse_output_path(9001) . '/frames/frame_000001.jpg', 'x');
    file_put_contents(auto_photo_sparse_result_path(9001), json_encode(['schema_version'=>1, 'job_type'=>AUTO_PHOTO_PREPARE_JOB_TYPE, 'status'=>'DONE', 'remote_job_id'=>9001, 'capture_bundle_id'=>7, 'app_bundle_uuid'=>'bundle-uuid', 'frames_count'=>1, 'frames_directory'=>'frames', 'warnings'=>[]]));
    mkdir(auto_photo_sparse_output_path(9002) . '/colmap', 0775, true);
    file_put_contents(auto_photo_sparse_output_path(9002) . '/colmap/sparse_components.json', json_encode(['models'=>[['model_id'=>0,'registered_images'=>1,'points3D_count'=>1], ['model_id'=>1,'registered_images'=>2,'points3D_count'=>2]]]));
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
    $videoSparse = apsw_db(); $videoSparse->sparseJob['parameters_json'] = json_encode(['prepare_job_id'=>745, 'prepare_remote_job_id'=>9001, 'capture_bundle_id'=>7, 'app_bundle_uuid'=>'bundle-uuid']);
    apsw_expect(fn() => auto_photo_sparse_validate_job_scope($videoSparse, 30, $videoSparse->sparseJob), 'standalone_photo_sparse_job_not_found');
    apsw_expect(fn() => auto_photo_sparse_web_select_model($videoSparse, 30, 746, 0), 'standalone_photo_sparse_job_not_found');
    apsw_expect(fn() => auto_photo_sparse_web_select_model(apsw_db(), 30, 746, 9), 'sparse_model_not_found');
    $corrupt = apsw_db('DONE', json_encode(['source_type'=>'auto_photo_prepare', 'standalone_sparse'=>true])); apsw_expect(fn() => auto_photo_sparse_web_select_model($corrupt, 30, 746, 0), 'sparse_job_scope_invalid'); apsw_assert($corrupt->updatedParametersJson === null, 'invalid parameters must not select model 0');
    $malformed = apsw_db('DONE', '{'); apsw_expect(fn() => auto_photo_sparse_web_select_model($malformed, 30, 746, 0), 'sparse_job_scope_invalid'); apsw_assert($malformed->updatedParametersJson === null && $malformed->rolledBack, 'malformed parameters roll back without update');
    $db = apsw_db(); $result = auto_photo_sparse_web_select_model($db, 30, '746', '1'); apsw_assert($result === ['sparse_db_job_id'=>746, 'model_id'=>1] && $db->committed, 'successful selection commits');
    $updated = json_decode((string)$db->updatedParametersJson, true); apsw_assert($updated === ['source_type'=>'auto_photo_prepare', 'standalone_sparse'=>true, 'prepare_job_id'=>745, 'prepare_remote_job_id'=>9001, 'capture_bundle_id'=>7, 'app_bundle_uuid'=>'bundle-uuid', 'keep'=>'unchanged', 'selected_model_id'=>1], 'only selected_model_id changes');
    $failed = apsw_db(); $failed->failUpdate = true; apsw_expect(fn() => auto_photo_sparse_web_select_model($failed, 30, 746, 1), 'sparse_model_update_failed'); apsw_assert($failed->rolledBack, 'update exception rolls back');

    foreach (['DONE', 'ERROR', 'FAILED'] as $status) apsw_assert(auto_photo_sparse_retry_policy(apsw_db($status)->sparseJob, [])['allowed'], "{$status} retry allowed");
    foreach (['QUEUED', 'RUNNING', 'CANCELLED'] as $status) apsw_assert(!auto_photo_sparse_retry_policy(apsw_db($status)->sparseJob, [])['allowed'], "{$status} retry denied");
    $exhaustiveSource = apsw_db(); $exhaustiveSource->sparseJob['parameters_json'] = json_encode(['retry_mode'=>'exhaustive']); apsw_assert(!auto_photo_sparse_retry_policy($exhaustiveSource->sparseJob, [])['allowed'], 'exhaustive source denied');
    foreach (['QUEUED', 'RUNNING'] as $status) { $policy = auto_photo_sparse_retry_policy(apsw_db()->sparseJob, [apsw_retry_job($status)]); apsw_assert($policy['reason'] === 'exhaustive_active' && $policy['active'], "{$status} exhaustive active"); }
    $policy = auto_photo_sparse_retry_policy(apsw_db()->sparseJob, [apsw_retry_job('DONE')]); apsw_assert($policy['reason'] === 'exhaustive_done' && $policy['done'], 'done exhaustive blocked');
    $policy = auto_photo_sparse_retry_policy(apsw_db()->sparseJob, [apsw_retry_job('DONE'), apsw_retry_job('RUNNING')]); apsw_assert($policy['reason'] === 'exhaustive_active' && $policy['active'] && $policy['done'], 'active exhaustive takes precedence over done');
    apsw_assert(auto_photo_sparse_retry_policy(apsw_db()->sparseJob, [apsw_retry_job('ERROR')])['allowed'], 'error exhaustive does not block');
    apsw_assert(auto_photo_sparse_retry_policy(apsw_db()->sparseJob, [apsw_retry_job('DONE', false)])['allowed'], 'sequential related does not block');

    $insertIdReader = static fn(mysqli $db): int => 947;
    $remoteJobIdFactory = static fn(mysqli $db): int => 9100;
    $retry = apsw_db(); $enqueued = auto_photo_sparse_web_enqueue_exhaustive($retry, 30, 746, $insertIdReader, $remoteJobIdFactory);
    apsw_assert($retry->committed && $enqueued['sparse_db_job_id'] === 947 && $enqueued['remote_job_id'] === 9100 && count($retry->inserts) === 1, 'successful exhaustive enqueue commits one insert');
    $insert = $retry->inserts[0]; $params = json_decode($insert['bound'][12], true);
    apsw_assert($insert['bound'][2] === 'COLMAP_SPARSE' && $insert['bound'][4] === 9001, 'only sparse retry has prepare parent');
    apsw_assert($params['retry_mode'] === 'exhaustive' && $params['settings']['sparse']['matcher'] === 'exhaustive', 'exhaustive parameters set');
    apsw_assert(str_contains($insert['sql'], 'pipeline_run_id)') && str_contains($insert['sql'], 'NULL)'), 'pipeline run is null');
    foreach (['EXPORT_PLY', 'COLMAP_DENSE', 'COLMAP_MESH'] as $forbidden) apsw_assert(!str_contains($insert['sql'], $forbidden), "no {$forbidden} insert");
    $insertFailure = apsw_db(); $insertFailure->failInsert = true; apsw_expect(fn() => auto_photo_sparse_web_enqueue_exhaustive($insertFailure, 30, 746, $insertIdReader, $remoteJobIdFactory), 'exhaustive_retry_insert_failed'); apsw_assert($insertFailure->rolledBack, 'insert failure rolls back');
    foreach ([[apsw_retry_job('QUEUED'), 'exhaustive_active'], [apsw_retry_job('DONE'), 'exhaustive_done']] as [$related, $reason]) { $blocked = apsw_db(); $blocked->relatedJobs = [$related]; apsw_expect(fn() => auto_photo_sparse_web_enqueue_exhaustive($blocked, 30, 746, $insertIdReader, $remoteJobIdFactory), $reason); apsw_assert(count($blocked->inserts) === 0, "{$reason} no insert"); }
    $running = apsw_db('RUNNING'); apsw_expect(fn() => auto_photo_sparse_web_enqueue_exhaustive($running, 30, 746, $insertIdReader, $remoteJobIdFactory), 'source_not_retryable'); apsw_assert(count($running->inserts) === 0, 'running source no insert');

    $sourceBytes = file_get_contents(auto_photo_sparse_output_path(9002) . '/colmap/sparse_components.json');
    $exportInsertId = static fn(mysqli $db): int => 948;
    $exportRemoteId = static fn(mysqli $db): int => 9200;
    apsw_expect(fn() => auto_photo_sparse_web_enqueue_export(apsw_db(), 30, 0, 0), 'invalid_sparse_db_job_id');
    apsw_expect(fn() => auto_photo_sparse_web_enqueue_export(apsw_db(), 30, '01', 0), 'invalid_sparse_db_job_id');
    apsw_expect(fn() => auto_photo_sparse_web_enqueue_export(apsw_db(), 30, 746, '01'), 'invalid_model_id');
    $notReady = apsw_db('RUNNING'); apsw_expect(fn() => auto_photo_sparse_web_enqueue_export($notReady, 30, 746, 0), 'sparse_job_not_ready'); apsw_assert($notReady->rolledBack, 'export not-ready rolls back');
    $wrongScope = apsw_db(); $wrongScope->sparseJob['parameters_json'] = json_encode(['source_type'=>'wrong']); apsw_expect(fn() => auto_photo_sparse_web_enqueue_export($wrongScope, 30, 746, 0), 'standalone_photo_sparse_job_not_found'); apsw_assert($wrongScope->rolledBack, 'export scope rolls back');
    $wrongChain = apsw_db(); $wrongChainParameters = json_decode($wrongChain->sparseJob['parameters_json'], true); $wrongChainParameters['prepare_remote_job_id'] = 9999; $wrongChain->sparseJob['parameters_json'] = json_encode($wrongChainParameters); apsw_expect(fn() => auto_photo_sparse_web_enqueue_export($wrongChain, 30, 746, 0), 'prepare_chain_invalid'); apsw_assert($wrongChain->rolledBack && count($wrongChain->inserts) === 0, 'export wrong prepare chain rolls back');
    $missingModel = apsw_db(); apsw_expect(fn() => auto_photo_sparse_web_enqueue_export($missingModel, 30, 746, 9), 'sparse_model_not_found'); apsw_assert($missingModel->rolledBack, 'export missing model rolls back');

    $export = apsw_db(); $exportResult = auto_photo_sparse_web_enqueue_export($export, 30, 746, 0, $exportInsertId, $exportRemoteId);
    apsw_assert($export->committed && count($export->inserts) === 1 && $exportResult === ['duplicate'=>false,'export_db_job_id'=>948,'export_remote_job_id'=>9200,'sparse_db_job_id'=>746,'sparse_remote_job_id'=>9002,'model_id'=>0], 'export model zero queued');
    $exportInsert = $export->inserts[0]; $exportParams = json_decode($exportInsert['bound'][10], true);
    apsw_assert($exportInsert['bound'][2] === 'EXPORT_PLY' && $exportInsert['bound'][4] === 9002, 'export job and parent contract');
    apsw_assert(str_starts_with($exportInsert['bound'][5], auto_photo_sparse_output_path(9200)) && str_ends_with($exportInsert['bound'][5], '/sparse_0.ply') && !str_starts_with($exportInsert['bound'][5], auto_photo_sparse_output_path(9002)), 'isolated export output contract');
    apsw_assert(str_starts_with($exportInsert['bound'][9], auto_photo_sparse_output_path(9200) . '/logs'), 'isolated export log contract');
    apsw_assert($exportParams === ['source_type'=>'auto_photo_sparse','standalone_photo_export'=>true,'sparse_job_id'=>9002,'model_id'=>0], 'export parameters contract');
    apsw_assert(str_contains($exportInsert['sql'], 'pipeline_run_id)') && str_contains($exportInsert['sql'], 'NULL)'), 'export pipeline run null');
    $insertBind = array_values(array_filter($export->binds, fn(array $bind): bool => str_starts_with($bind['sql'], 'INSERT')))[0];
    $duplicateBind = array_values(array_filter($export->binds, fn(array $bind): bool => str_contains($bind['sql'], "job_type='EXPORT_PLY'")))[0];
    apsw_assert($insertBind['types'] === 'iisiississs' && strlen($insertBind['types']) === count($insertBind['bound']), 'export insert bind signature');
    apsw_assert($duplicateBind['types'] === 'iiisss' && strlen($duplicateBind['types']) === count($duplicateBind['bound']), 'export duplicate bind signature');

    $sameRemote = apsw_db(); apsw_expect(fn() => auto_photo_sparse_web_enqueue_export($sameRemote, 30, 746, 0, $exportInsertId, static fn(mysqli $db): int => 9002), 'export_remote_job_id_invalid'); apsw_assert($sameRemote->rolledBack && count($sameRemote->inserts) === 0, 'source remote cannot be export remote');
    $selected = apsw_db(); $selectedParams = json_decode($selected->sparseJob['parameters_json'], true); $selectedParams['selected_model_id'] = 0; $selected->sparseJob['parameters_json'] = json_encode($selectedParams); apsw_assert(auto_photo_sparse_web_enqueue_export($selected, 30, 746, null, $exportInsertId, $exportRemoteId)['model_id'] === 0, 'selected model zero resolves');
    $recommended = apsw_db(); apsw_assert(auto_photo_sparse_web_enqueue_export($recommended, 30, 746, null, $exportInsertId, $exportRemoteId)['model_id'] === 1, 'recommended model resolves');

    foreach (['QUEUED', 'RUNNING', 'DONE'] as $status) { $duplicate = apsw_db(); $duplicate->exportDuplicateRows = [apsw_export_row(['status'=>$status])]; $result = auto_photo_sparse_web_enqueue_export($duplicate, 30, 746, 0, $exportInsertId, $exportRemoteId); apsw_assert($result['duplicate'] === true && $duplicate->committed && count($duplicate->inserts) === 0, "{$status} export duplicate"); }
    foreach (['ERROR', 'FAILED', 'CANCELLED'] as $status) { $nonBlocking = apsw_db(); $nonBlocking->exportDuplicateRows = [apsw_export_row(['status'=>$status])]; $result = auto_photo_sparse_web_enqueue_export($nonBlocking, 30, 746, 0, $exportInsertId, $exportRemoteId); apsw_assert($result['duplicate'] === false && count($nonBlocking->inserts) === 1, "{$status} export non-blocking"); }
    foreach (['order_id'=>31, 'capture_session_id'=>64, 'parent_remote_job_id'=>9003, 'parameters_json'=>json_encode(['model_id'=>1])] as $field=>$value) { $scoped = apsw_db(); $scoped->exportDuplicateRows = [apsw_export_row([$field=>$value])]; $result = auto_photo_sparse_web_enqueue_export($scoped, 30, 746, 0, $exportInsertId, $exportRemoteId); apsw_assert($result['duplicate'] === false && count($scoped->inserts) === 1, "{$field} does not duplicate"); }
    foreach ([json_encode(['model_id'=>'abc']), json_encode(['model_id'=>'01']), json_encode(['model_id'=>true]), json_encode([]), '{'] as $parametersJson) { $malformedMarker = apsw_db(); $malformedMarker->exportDuplicateRows = [apsw_export_row(['parameters_json'=>$parametersJson])]; $result = auto_photo_sparse_web_enqueue_export($malformedMarker, 30, 746, 0, $exportInsertId, $exportRemoteId); apsw_assert($result['duplicate'] === false && count($malformedMarker->inserts) === 1, 'malformed marker does not duplicate'); }
    foreach ([auto_photo_sparse_output_path(9002).'/sparse_0.ply', auto_photo_sparse_output_path(9200).'/sparse_0.ply'] as $pathCollision) { $collision = apsw_db(); $collision->exportDuplicateRows = [apsw_export_row(['parameters_json'=>'{','output_path'=>$pathCollision])]; $result = auto_photo_sparse_web_enqueue_export($collision, 30, 746, 0, $exportInsertId, $exportRemoteId); apsw_assert($result['duplicate'] === true && count($collision->inserts) === 0, 'path collision blocks'); }
    $duplicateFailure = apsw_db(); $duplicateFailure->failDuplicateResult = true; apsw_expect(fn() => auto_photo_sparse_web_enqueue_export($duplicateFailure, 30, 746, 0, $exportInsertId, $exportRemoteId), 'export_duplicate_query_failed'); apsw_assert($duplicateFailure->rolledBack && count($duplicateFailure->inserts) === 0, 'duplicate result failure rolls back');
    $exportFailure = apsw_db(); $exportFailure->failInsert = true; apsw_expect(fn() => auto_photo_sparse_web_enqueue_export($exportFailure, 30, 746, 0, $exportInsertId, $exportRemoteId), 'export_insert_failed'); apsw_assert($exportFailure->rolledBack, 'export insert failure rolls back');
    $invalidExportId = apsw_db(); apsw_expect(fn() => auto_photo_sparse_web_enqueue_export($invalidExportId, 30, 746, 0, static fn(mysqli $db): int => 0, $exportRemoteId), 'export_insert_id_invalid'); apsw_assert($invalidExportId->rolledBack, 'invalid export insert id rolls back');
    apsw_assert(file_get_contents(auto_photo_sparse_output_path(9002) . '/colmap/sparse_components.json') === $sourceBytes, 'source sparse bytes restored after export tests');

    $route = (string) file_get_contents(__DIR__ . '/../www/order.php'); $start = strpos($route, '$action === \'auto_photo_sparse_export_ply\''); $end = strpos($route, "if(\$action==='create_capture_bundle_dense_job'", $start); $routeFragment = substr($route, $start, $end - $start);
    foreach (['auto_photo_sparse_export_ply', 'auto_photo_sparse_web_enqueue_export', "\$_POST['sparse_db_job_id']", "\$_POST['model_id']", '$canDeleteMedia', 'photo_export_queued=1', 'photo_export_exists=1'] as $needle) apsw_assert(str_contains($routeFragment, $needle), "route {$needle}");
    foreach (['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'begin_transaction', 'commit', 'rollback', 'dense', 'mesh', 'reconstruction'] as $forbidden) apsw_assert(!str_contains($routeFragment, $forbidden), "route no {$forbidden}");
    apsw_assert(str_contains($route, 'sfm_export_ply_web') && !str_contains($routeFragment, 'sfm_export_ply_web'), 'legacy export route remains separate');
    $endpoint = (string) file_get_contents(__DIR__ . '/../www/api/sfm_remote_job_status.php');
    foreach (['source_type', 'auto_photo_sparse', 'standalone_photo_export', 'auto_photo_sparse_manifest_model_id', "\$job['remote_job_id']", '/sparse_', 'output_path', 'hash_equals', 'is_link', 'filesize', 'srj_send_ply_file'] as $needle) apsw_assert(str_contains($endpoint, $needle), "standalone endpoint {$needle}");
    $standaloneStart = strpos($endpoint, 'if ($isStandalonePhoto)'); $legacyStart = strpos($endpoint, '} else {', $standaloneStart); $standaloneFragment = substr($endpoint, $standaloneStart, $legacyStart - $standaloneStart); $legacyFragment = substr($endpoint, $legacyStart);
    apsw_assert(str_contains($standaloneFragment, "\$job['remote_job_id']") && !str_contains($standaloneFragment, "\$job['parent_remote_job_id']"), 'standalone endpoint uses own remote id');
    $modelParser = strpos($standaloneFragment, 'auto_photo_sparse_manifest_model_id'); $nullCheck = strpos($standaloneFragment, '$model === null'); $expectedPath = strpos($standaloneFragment, '$expectedPath');
    apsw_assert($modelParser !== false && $nullCheck !== false && $expectedPath !== false && $modelParser < $nullCheck && $nullCheck < $expectedPath, 'standalone endpoint rejects null model before path');
    apsw_assert(!str_contains($standaloneFragment, 'if (!$model)') && !str_contains($standaloneFragment, 'try { $model=auto_photo_sparse_manifest_model_id'), 'standalone endpoint keeps model zero and has no parser catch');
    foreach (["\$job['parent_remote_job_id']", '/colmap/sparse/', 'model.ply'] as $needle) apsw_assert(str_contains($legacyFragment, $needle), "legacy endpoint {$needle}");
    echo "OK\n";
} finally {
    $remove = static function (string $path) use (&$remove): void { if (!file_exists($path)) return; if (is_file($path)) { unlink($path); return; } foreach (scandir($path) ?: [] as $entry) if ($entry !== '.' && $entry !== '..') $remove($path.'/'.$entry); rmdir($path); };
    $remove($testRoot);
}
