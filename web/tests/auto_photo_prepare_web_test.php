<?php

declare(strict_types=1);

$testRoot = sys_get_temp_dir() . '/auto_photo_prepare_web_' . bin2hex(random_bytes(6));
define('APP_STORAGE_DIR', $testRoot);
mkdir(APP_STORAGE_DIR . '/orders', 0775, true);
putenv('AUTO_PHOTO_BUNDLE_TEST_MODE=true');

require_once __DIR__ . '/../libs/auto_photo_prepare_web_lib.php';

function prepare_web_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function prepare_web_expect(callable $callback, string $expected): void
{
    try {
        $callback();
    } catch (Throwable $e) {
        prepare_web_assert(
            $e->getMessage() === $expected,
            "expected {$expected}, got {$e->getMessage()}"
        );
        return;
    }

    throw new RuntimeException("missing {$expected}");
}

function prepare_web_octal(int $number, int $length): string
{
    return str_pad(decoct($number), $length - 1, '0', STR_PAD_LEFT) . "\0";
}

function prepare_web_tar_header(string $name, int $size): string
{
    $header = str_pad($name, 100, "\0")
        . prepare_web_octal(0644, 8)
        . prepare_web_octal(0, 8)
        . prepare_web_octal(0, 8)
        . prepare_web_octal($size, 12)
        . prepare_web_octal(0, 12)
        . str_repeat(' ', 8)
        . '0'
        . str_repeat("\0", 100)
        . "ustar\0"
        . '00'
        . str_repeat("\0", 32 + 32 + 8 + 8 + 155 + 12);

    $checksum = array_sum(array_map('ord', str_split($header)));
    return substr_replace(
        $header,
        str_pad(decoct($checksum), 6, '0', STR_PAD_LEFT) . "\0 ",
        148,
        8
    );
}

function prepare_web_write_tgz(string $path): array
{
    $jpegOne = "\xFF\xD8\xFF\xC0\x00\x11\x08\x0C\x00\x10\x00\x03"
        . "\x01\x11\x00\x02\x11\x00\x03\x11\x00"
        . str_repeat('A', 64)
        . "\xFF\xD9";
    $jpegTwo = "\xFF\xD8\xFF\xC0\x00\x11\x08\x0C\x00\x10\x00\x03"
        . "\x01\x11\x00\x02\x11\x00\x03\x11\x00"
        . str_repeat('B', 64)
        . "\xFF\xD9";

    $photos = [
        'photos/frame_000001.jpg',
        'photos/frame_000002.jpg',
    ];
    $metadata = [];
    foreach ($photos as $index => $photo) {
        $metadata[] = json_encode([
            'sequence' => $index + 1,
            'file' => $photo,
            'photo_uuid' => 'photo-' . ($index + 1),
            'image_width' => 4096,
            'image_height' => 3072,
        ], JSON_THROW_ON_ERROR);
    }

    $members = [
        [
            'bundle_manifest.json',
            json_encode([
                'bundle_schema_version' => 1,
                'bundle_type' => 'maklertour_capture_bundle',
                'capture_type' => 'auto_photo_session',
                'app_bundle_uuid' => 'u',
                'photos_count' => 2,
            ], JSON_THROW_ON_ERROR),
        ],
        [
            'capture/manifest.json',
            json_encode([
                'schema_version' => 1,
                'capture_type' => 'auto_photo_session',
                'capture_uuid' => 'u',
                'photos_count' => 2,
                'photos' => $photos,
            ], JSON_THROW_ON_ERROR),
        ],
        ['capture/camera_info.json', '{"camera_id":"0"}'],
        ['capture/photos/frame_000001.jpg', $jpegOne],
        ['capture/photos/frame_000002.jpg', $jpegTwo],
        ['capture/photos_metadata.jsonl', implode("\n", $metadata) . "\n"],
        ['capture/imu.jsonl', implode("\n", $metadata) . "\n"],
        ['capture/quality.jsonl', "{}\n"],
        ['capture/events.jsonl', "{}\n"],
    ];

    $tar = '';
    foreach ($members as [$name, $body]) {
        $tar .= prepare_web_tar_header($name, strlen($body));
        $tar .= $body;
        $tar .= str_repeat("\0", (512 - strlen($body) % 512) % 512);
    }

    file_put_contents($path, gzencode($tar . str_repeat("\0", 1024)));

    return [
        'frame_000001.jpg' => $jpegOne,
        'frame_000002.jpg' => $jpegTwo,
    ];
}

function prepare_web_remove(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            prepare_web_remove($path . '/' . $entry);
        }
    }
    @rmdir($path);
}

class PrepareWebFakeResult extends mysqli_result
{
    private int $offset = 0;

    public function __construct(private array $rows)
    {
    }

    public function fetch_assoc(): array|null|false
    {
        return $this->rows[$this->offset++] ?? null;
    }
}

class PrepareWebFakeStatement extends mysqli_stmt
{
    public string $types = '';
    public array $bound = [];

    public function __construct(
        private PrepareWebFakeDb $db,
        public string $sql,
        public string $operation
    ) {
    }

    public function bind_param(string $types, mixed &...$vars): bool
    {
        if ($this->db->failBind[$this->operation] ?? false) {
            return false;
        }

        $this->types = $types;
        $this->bound = $vars;
        $this->db->binds[] = [
            'operation' => $this->operation,
            'sql' => $this->sql,
            'types' => $types,
            'bound' => $vars,
        ];
        return true;
    }

    public function execute(?array $params = null): bool
    {
        if ($this->db->failExecute[$this->operation] ?? false) {
            return false;
        }

        if ($this->operation === 'insert') {
            $this->db->insertedJobs[] = [
                'sql' => $this->sql,
                'types' => $this->types,
                'bound' => $this->bound,
            ];
        }
        return true;
    }

    public function get_result(): mysqli_result|false
    {
        if ($this->db->failResult[$this->operation] ?? false) {
            return false;
        }

        return new PrepareWebFakeResult(
            $this->db->resultRows($this->operation, $this->bound)
        );
    }

    public function close(): true
    {
        return true;
    }
}

class PrepareWebFakeDb extends mysqli
{
    public int|string $insert_id = 99;
    public array $bundleRow;
    public array $lockedBundleRow;
    public array $duplicateCandidates = [];
    public array $remoteIdRows = [];
    public array $preparedSql = [];
    public array $binds = [];
    public array $insertedJobs = [];
    public array $failPrepare = [];
    public array $failBind = [];
    public array $failExecute = [];
    public array $failResult = [];
    public bool $beginOk = true;
    public bool $commitOk = true;
    public int $beginCalls = 0;
    public int $commitCalls = 0;
    public int $rollbackCalls = 0;

    public function __construct(array $bundleRow)
    {
        $this->bundleRow = $bundleRow;
        $this->lockedBundleRow = $bundleRow;
    }

    public function begin_transaction(int $flags = 0, ?string $name = null): bool
    {
        $this->beginCalls++;
        return $this->beginOk;
    }

    public function commit(int $flags = 0, ?string $name = null): bool
    {
        $this->commitCalls++;
        return $this->commitOk;
    }

    public function rollback(int $flags = 0, ?string $name = null): bool
    {
        $this->rollbackCalls++;
        return true;
    }

    public function prepare(string $query): mysqli_stmt|false
    {
        $operation = $this->operationForSql($query);
        $this->preparedSql[] = [
            'operation' => $operation,
            'sql' => $query,
        ];

        if ($this->failPrepare[$operation] ?? false) {
            return false;
        }
        return new PrepareWebFakeStatement($this, $query, $operation);
    }

    public function operationForSql(string $sql): string
    {
        if (str_starts_with(trim($sql), 'INSERT INTO sfm_remote_jobs')) {
            return 'insert';
        }
        if (str_contains($sql, 'FROM capture_bundles')) {
            return str_contains($sql, 'FOR UPDATE')
                ? 'locked_bundle'
                : 'initial_bundle';
        }
        if (str_contains($sql, 'JSON_VALID(parameters_json)')) {
            return 'duplicate';
        }
        if (str_contains($sql, 'WHERE remote_job_id=?')) {
            return 'remote';
        }

        throw new RuntimeException('unexpected_sql');
    }

    public function resultRows(string $operation, array $bound): array
    {
        return match ($operation) {
            'initial_bundle' => $this->bundleRow === [] ? [] : [$this->bundleRow],
            'locked_bundle' => $this->lockedBundleRow === [] ? [] : [$this->lockedBundleRow],
            'duplicate' => $this->matchingDuplicateCandidates($bound),
            'remote' => $this->matchingRemoteRows($bound),
            default => [],
        };
    }

    public function matchingRemoteRows(array $bound): array
    {
        $remoteJobId = (int) ($bound[0] ?? 0);
        foreach ($this->remoteIdRows as $row) {
            if ((int) ($row['remote_job_id'] ?? 0) === $remoteJobId) {
                return [['id' => (int) ($row['id'] ?? 0)]];
            }
        }
        return [];
    }

    public function matchingDuplicateCandidates(array $bound): array
    {
        [$orderId, $sessionId, $jobType, $bundleIdText, $uuid] = $bound;

        foreach ($this->duplicateCandidates as $row) {
            if ((int) ($row['order_id'] ?? 0) !== (int) $orderId
                || (int) ($row['capture_session_id'] ?? 0) !== (int) $sessionId
                || (string) ($row['job_type'] ?? '') !== (string) $jobType
                || !in_array((string) ($row['status'] ?? ''), ['QUEUED', 'RUNNING', 'DONE'], true)) {
                continue;
            }

            $parameters = json_decode((string) ($row['parameters_json'] ?? ''), true);
            if (!is_array($parameters)
                || ($parameters['source_type'] ?? null) !== 'auto_photo_bundle'
                || ($parameters['pipeline_mode'] ?? null) !== 'prepare') {
                continue;
            }

            if (prepare_web_decimal($parameters['capture_bundle_id'] ?? null) !== (string) $bundleIdText
                || (string) ($parameters['app_bundle_uuid'] ?? '') !== (string) $uuid) {
                continue;
            }

            return [[
                'id' => $row['id'] ?? null,
                'remote_job_id' => $row['remote_job_id'] ?? null,
            ]];
        }

        return [];
    }
}

function prepare_web_decimal(mixed $value): ?string
{
    if (is_int($value) && $value >= 0) {
        return (string) $value;
    }
    if (is_string($value) && preg_match('/^(?:0|[1-9][0-9]*)$/', $value) === 1) {
        return $value;
    }
    return null;
}

function prepare_web_fixture(): array
{
    $archive = APP_STORAGE_DIR
        . '/orders/o/sessions/s/capture_bundles/b.tgz';
    mkdir(dirname($archive), 0775, true);
    $jpegBytes = prepare_web_write_tgz($archive);

    $row = [
        'id' => 8,
        'order_id' => 31,
        'capture_session_id' => 65,
        'app_bundle_uuid' => 'u',
        'capture_type' => AUTO_PHOTO_BUNDLE_CAPTURE_TYPE,
        'filename' => 'b.tgz',
        'storage_path' => 'orders/o/sessions/s/capture_bundles/b.tgz',
        'size_bytes' => filesize($archive),
        'status' => 'UPLOADED',
        'created_at' => null,
        'updated_at' => null,
    ];

    return [
        'archive' => $archive,
        'row' => $row,
        'jpeg_bytes' => $jpegBytes,
        'base_dir' => dirname(auto_photo_bundle_index_cache_path($row, $archive)),
    ];
}

function prepare_web_duplicate_candidate(
    string $status = 'QUEUED',
    array $replace = []
): array {
    return array_replace([
        'id' => 100,
        'remote_job_id' => 200,
        'order_id' => 31,
        'capture_session_id' => 65,
        'job_type' => AUTO_PHOTO_PREPARE_JOB_TYPE,
        'status' => $status,
        'parameters_json' => json_encode([
            'source_type' => 'auto_photo_bundle',
            'pipeline_mode' => 'prepare',
            'capture_bundle_id' => 8,
            'app_bundle_uuid' => 'u',
        ], JSON_THROW_ON_ERROR),
    ], $replace);
}

function prepare_web_run(
    PrepareWebFakeDb $db,
    int $remoteJobId,
    int $insertedId = 99
): array {
    return auto_photo_prepare_web_start_bundle(
        $db,
        31,
        '8',
        static fn(mysqli $unused): int => $insertedId,
        static fn(mysqli $unused): int => $remoteJobId
    );
}

function prepare_web_find_sql(PrepareWebFakeDb $db, string $operation): string
{
    foreach ($db->preparedSql as $entry) {
        if ($entry['operation'] === $operation) {
            return (string) $entry['sql'];
        }
    }
    return '';
}

function prepare_web_unused_remote_id(): int
{
    for ($attempt = 0; $attempt < 100; $attempt++) {
        $remoteJobId = random_int(700000000, 799999999);
        $path = '/home/makler/web/remote_station/output/job_' . $remoteJobId;
        if (!file_exists($path)) {
            return $remoteJobId;
        }
    }
    throw new RuntimeException('unable_to_allocate_test_remote_id');
}

try {
    $fixture = prepare_web_fixture();
    $archiveHash = hash_file('sha256', $fixture['archive']);
    $remoteJobId = prepare_web_unused_remote_id();
    $remoteOutputPath = '/home/makler/web/remote_station/output/job_' . $remoteJobId;

    foreach ([1, '8', PHP_INT_MAX] as $validId) {
        prepare_web_assert(
            auto_photo_prepare_web_bundle_id($validId) > 0,
            'valid capture bundle ID'
        );
    }
    foreach ([0, '0', '01', -1, 1.2, [], null, ' 8', '8 '] as $invalidId) {
        prepare_web_expect(
            static fn() => auto_photo_prepare_web_bundle_id($invalidId),
            'capture_bundle_id_invalid'
        );
    }

    $db = new PrepareWebFakeDb($fixture['row']);
    $result = prepare_web_run($db, $remoteJobId);

    prepare_web_assert($result === [
        'duplicate' => false,
        'capture_bundle_id' => 8,
        'prepare_db_job_id' => 99,
        'prepare_remote_job_id' => $remoteJobId,
        'input_images' => 2,
    ], 'valid enqueue result');
    prepare_web_assert(
        $db->beginCalls === 1
        && $db->commitCalls === 1
        && $db->rollbackCalls === 0
        && count($db->insertedJobs) === 1,
        'valid transaction'
    );

    $lockedSql = prepare_web_find_sql($db, 'locked_bundle');
    $duplicateSql = prepare_web_find_sql($db, 'duplicate');
    prepare_web_assert(str_contains($lockedSql, 'FOR UPDATE'), 'locked bundle query');
    prepare_web_assert(str_contains($duplicateSql, 'FOR UPDATE'), 'duplicate query lock');

    $index = json_decode(
        (string) file_get_contents($fixture['base_dir'] . '/index.json'),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    $materialization = json_decode(
        (string) file_get_contents($fixture['base_dir'] . '/materialization.json'),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    prepare_web_assert(
        ($index['validation_status'] ?? null) === 'VALID'
        && (int) ($index['photos_count_actual'] ?? 0) === 2,
        'valid cached index'
    );
    prepare_web_assert(
        ($materialization['status'] ?? null) === 'READY'
        && (int) ($materialization['photos_count'] ?? 0) === 2,
        'ready materialization'
    );
    prepare_web_assert(
        hash_file('sha256', $fixture['archive']) === $archiveHash,
        'source TGZ immutable'
    );
    foreach ($fixture['jpeg_bytes'] as $filename => $bytes) {
        prepare_web_assert(
            hash_file('sha256', $fixture['base_dir'] . '/photos/' . $filename)
                === hash('sha256', $bytes),
            'materialized JPEG immutable ' . $filename
        );
    }
    prepare_web_assert(!file_exists($remoteOutputPath), 'service creates no remote output');

    $insert = $db->insertedJobs[0];
    prepare_web_assert(
        str_contains($insert['sql'], 'order_id,capture_session_id,job_type,remote_job_id,output_path,status,progress_percent,message,result_json_path,parameters_json')
        && str_contains($insert['sql'], "'QUEUED'")
        && str_contains($insert['sql'], ',0,'),
        'exact INSERT literals'
    );
    prepare_web_assert($insert['types'] === 'iisissss', 'exact INSERT bind signature');
    prepare_web_assert($insert['bound'][0] === 31, 'insert order');
    prepare_web_assert((int) $insert['bound'][1] === 65, 'insert session');
    prepare_web_assert(
        $insert['bound'][2] === AUTO_PHOTO_PREPARE_JOB_TYPE,
        'insert job type'
    );
    prepare_web_assert($insert['bound'][3] === $remoteJobId, 'insert remote ID');
    prepare_web_assert(
        $insert['bound'][4] === $remoteOutputPath,
        'insert output path'
    );
    prepare_web_assert(
        $insert['bound'][5] === 'Auto photo prepare queued',
        'insert message'
    );
    prepare_web_assert(
        $insert['bound'][6] === $remoteOutputPath . '/result.json',
        'insert result path'
    );
    $parameters = json_decode((string) $insert['bound'][7], true, 512, JSON_THROW_ON_ERROR);
    prepare_web_assert($parameters === [
        'source_type' => 'auto_photo_bundle',
        'capture_bundle_id' => 8,
        'capture_type' => 'auto_photo_session',
        'app_bundle_uuid' => 'u',
        'input_images' => 2,
        'already_selected_frames' => true,
        'pipeline_mode' => 'prepare',
    ], 'canonical prepare parameters');

    foreach (['QUEUED', 'RUNNING', 'DONE'] as $status) {
        $db = new PrepareWebFakeDb($fixture['row']);
        $db->duplicateCandidates = [prepare_web_duplicate_candidate($status)];
        $duplicateResult = prepare_web_run($db, $remoteJobId);
        prepare_web_assert(
            $duplicateResult['duplicate'] === true
            && $duplicateResult['prepare_db_job_id'] === 100
            && $duplicateResult['prepare_remote_job_id'] === 200
            && count($db->insertedJobs) === 0
            && $db->commitCalls === 1
            && $db->rollbackCalls === 0,
            $status . ' duplicate'
        );
    }

    foreach (['ERROR', 'FAILED', 'CANCELLED'] as $status) {
        $db = new PrepareWebFakeDb($fixture['row']);
        $db->duplicateCandidates = [prepare_web_duplicate_candidate($status)];
        $retryResult = prepare_web_run($db, $remoteJobId);
        prepare_web_assert(
            $retryResult['duplicate'] === false
            && count($db->insertedJobs) === 1
            && $db->commitCalls === 1,
            $status . ' allows retry'
        );
    }

    $scopeMismatches = [
        ['order_id' => 32],
        ['capture_session_id' => 66],
        ['job_type' => 'COLMAP_SPARSE'],
    ];
    foreach ($scopeMismatches as $replace) {
        $db = new PrepareWebFakeDb($fixture['row']);
        $db->duplicateCandidates = [prepare_web_duplicate_candidate('QUEUED', $replace)];
        prepare_web_assert(
            prepare_web_run($db, $remoteJobId)['duplicate'] === false
            && count($db->insertedJobs) === 1,
            'duplicate scope mismatch ' . array_key_first($replace)
        );
    }

    foreach ([
        ['source_type', 'wrong'],
        ['pipeline_mode', 'wrong'],
        ['capture_bundle_id', 9],
        ['app_bundle_uuid', 'wrong'],
    ] as [$key, $value]) {
        $candidate = prepare_web_duplicate_candidate();
        $candidateParameters = json_decode(
            (string) $candidate['parameters_json'],
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $candidateParameters[$key] = $value;
        $candidate['parameters_json'] = json_encode($candidateParameters, JSON_THROW_ON_ERROR);

        $db = new PrepareWebFakeDb($fixture['row']);
        $db->duplicateCandidates = [$candidate];
        prepare_web_assert(
            prepare_web_run($db, $remoteJobId)['duplicate'] === false
            && count($db->insertedJobs) === 1,
            'duplicate marker mismatch ' . $key
        );
    }

    $db = new PrepareWebFakeDb($fixture['row']);
    $db->duplicateCandidates = [
        prepare_web_duplicate_candidate('QUEUED', ['parameters_json' => '{']),
    ];
    prepare_web_assert(
        prepare_web_run($db, $remoteJobId)['duplicate'] === false
        && count($db->insertedJobs) === 1,
        'malformed duplicate parameters do not block'
    );

    foreach ([['id', 0], ['remote_job_id', 0]] as [$key, $value]) {
        $db = new PrepareWebFakeDb($fixture['row']);
        $db->duplicateCandidates = [prepare_web_duplicate_candidate('DONE', [$key => $value])];
        prepare_web_expect(
            static fn() => prepare_web_run($db, $remoteJobId),
            'prepare_duplicate_result_invalid'
        );
        prepare_web_assert(
            count($db->insertedJobs) === 0 && $db->rollbackCalls === 1,
            'invalid duplicate rollback ' . $key
        );
    }

    $db = new PrepareWebFakeDb($fixture['row']);
    $db->beginOk = false;
    prepare_web_expect(
        static fn() => prepare_web_run($db, $remoteJobId),
        'prepare_transaction_failed'
    );
    prepare_web_assert($db->rollbackCalls === 0, 'begin failure has no rollback');

    foreach (['failPrepare', 'failBind', 'failExecute', 'failResult'] as $failure) {
        $db = new PrepareWebFakeDb($fixture['row']);
        $db->{$failure}['locked_bundle'] = true;
        prepare_web_expect(
            static fn() => prepare_web_run($db, $remoteJobId),
            'capture_bundle_lock_failed'
        );
        prepare_web_assert($db->rollbackCalls === 1, $failure . ' locked rollback');
    }

    $db = new PrepareWebFakeDb($fixture['row']);
    $db->lockedBundleRow = [];
    prepare_web_expect(
        static fn() => prepare_web_run($db, $remoteJobId),
        'capture_bundle_not_found'
    );
    prepare_web_assert($db->rollbackCalls === 1, 'missing locked row rollback');

    foreach ([
        ['storage_path', 'orders/changed/archive.tgz'],
        ['size_bytes', (int) $fixture['row']['size_bytes'] + 1],
        ['app_bundle_uuid', 'changed-u'],
        ['capture_session_id', 66],
    ] as [$key, $value]) {
        $db = new PrepareWebFakeDb($fixture['row']);
        $db->lockedBundleRow[$key] = $value;
        prepare_web_expect(
            static fn() => prepare_web_run($db, $remoteJobId),
            'capture_bundle_changed'
        );
        prepare_web_assert($db->rollbackCalls === 1, 'changed bundle rollback ' . $key);
    }

    foreach (['failPrepare', 'failBind', 'failExecute', 'failResult'] as $failure) {
        $db = new PrepareWebFakeDb($fixture['row']);
        $db->{$failure}['duplicate'] = true;
        prepare_web_expect(
            static fn() => prepare_web_run($db, $remoteJobId),
            'prepare_duplicate_query_failed'
        );
        prepare_web_assert($db->rollbackCalls === 1, $failure . ' duplicate rollback');
    }

    foreach (['failPrepare', 'failBind', 'failExecute', 'failResult'] as $failure) {
        $db = new PrepareWebFakeDb($fixture['row']);
        $db->{$failure}['remote'] = true;
        prepare_web_expect(
            static fn() => prepare_web_run($db, $remoteJobId),
            'prepare_remote_job_query_failed'
        );
        prepare_web_assert($db->rollbackCalls === 1, $failure . ' remote rollback');
    }

    $db = new PrepareWebFakeDb($fixture['row']);
    prepare_web_expect(
        static fn() => prepare_web_run($db, 0),
        'prepare_remote_job_id_invalid'
    );
    prepare_web_assert($db->rollbackCalls === 1, 'invalid remote ID rollback');

    $db = new PrepareWebFakeDb($fixture['row']);
    $db->remoteIdRows = [['id' => 1, 'remote_job_id' => $remoteJobId]];
    prepare_web_expect(
        static fn() => prepare_web_run($db, $remoteJobId),
        'prepare_remote_job_id_invalid'
    );
    prepare_web_assert($db->rollbackCalls === 1, 'existing remote ID rollback');

    foreach (['failPrepare', 'failBind', 'failExecute'] as $failure) {
        $db = new PrepareWebFakeDb($fixture['row']);
        $db->{$failure}['insert'] = true;
        prepare_web_expect(
            static fn() => prepare_web_run($db, $remoteJobId),
            'prepare_insert_failed'
        );
        prepare_web_assert($db->rollbackCalls === 1, $failure . ' insert rollback');
    }

    $db = new PrepareWebFakeDb($fixture['row']);
    prepare_web_expect(
        static fn() => prepare_web_run($db, $remoteJobId, 0),
        'prepare_insert_id_invalid'
    );
    prepare_web_assert($db->rollbackCalls === 1, 'invalid insert ID rollback');

    $db = new PrepareWebFakeDb($fixture['row']);
    $db->commitOk = false;
    prepare_web_expect(
        static fn() => prepare_web_run($db, $remoteJobId),
        'prepare_commit_failed'
    );
    prepare_web_assert($db->rollbackCalls === 1, 'commit failure rollback');

    foreach ($fixture['jpeg_bytes'] as $filename => $bytes) {
        prepare_web_assert(
            hash_file('sha256', $fixture['base_dir'] . '/photos/' . $filename)
                === hash('sha256', $bytes),
            'materialized JPEG remains immutable ' . $filename
        );
    }
    prepare_web_assert(
        hash_file('sha256', $fixture['archive']) === $archiveHash,
        'source TGZ remains immutable'
    );

    echo "OK\n";
} finally {
    putenv('AUTO_PHOTO_BUNDLE_TEST_MODE');
    prepare_web_remove($testRoot);
}
