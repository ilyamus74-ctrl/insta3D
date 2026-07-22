<?php

declare(strict_types=1);

$testRoot = sys_get_temp_dir() . '/auto_photo_sparse_ui_web_' . bin2hex(random_bytes(6));
define('AUTO_PHOTO_SPARSE_OUTPUT_ROOT', $testRoot . '/output');
define('APP_STORAGE_DIR', $testRoot . '/storage');
mkdir(APP_STORAGE_DIR . '/orders', 0775, true);

require_once __DIR__ . '/../libs/auto_photo_sparse_ui_web_lib.php';

function ui_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function ui_json(array $value): string
{
    return json_encode($value, JSON_THROW_ON_ERROR);
}

function ui_remove(string $path): void
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
            ui_remove($path . '/' . $entry);
        }
    }
    @rmdir($path);
}

function ui_default_archive(): array
{
    static $fixture = null;
    if (is_array($fixture)) {
        return $fixture;
    }

    $archive = APP_STORAGE_DIR
        . '/orders/default/sessions/default/capture_bundles/bundle.tgz';
    mkdir(dirname($archive), 0775, true);
    file_put_contents($archive, 'sentinel');
    $fixture = [
        'archive' => $archive,
        'storage_path' => 'orders/default/sessions/default/capture_bundles/bundle.tgz',
        'size_bytes' => filesize($archive),
    ];
    return $fixture;
}

function ui_bundle(int $id = 7, int $sessionId = 63): array
{
    $archive = ui_default_archive();
    return [
        'id' => $id,
        'order_id' => 30,
        'capture_session_id' => $sessionId,
        'app_bundle_uuid' => 'b' . $id,
        'capture_type' => AUTO_PHOTO_BUNDLE_CAPTURE_TYPE,
        'filename' => 'bundle.tgz',
        'storage_path' => $archive['storage_path'],
        'size_bytes' => $archive['size_bytes'],
        'status' => 'UPLOADED',
    ];
}

function ui_prepare(
    int $id = 745,
    int $remoteId = 9001,
    int $bundleId = 7,
    int $sessionId = 63,
    string $status = 'DONE',
    array|string|null $parameters = null
): array {
    $parameters ??= [
        'source_type' => 'auto_photo_bundle',
        'pipeline_mode' => 'prepare',
        'capture_bundle_id' => $bundleId,
        'app_bundle_uuid' => 'b' . $bundleId,
        'input_images' => 178,
    ];

    return [
        'id' => $id,
        'order_id' => 30,
        'capture_session_id' => $sessionId,
        'job_type' => AUTO_PHOTO_PREPARE_JOB_TYPE,
        'remote_job_id' => $remoteId,
        'status' => $status,
        'progress_percent' => 50,
        'message' => 'prepare',
        'parameters_json' => is_array($parameters)
            ? ui_json($parameters)
            : $parameters,
    ];
}

function ui_sparse(
    int $id = 746,
    int $remoteId = 434136404,
    int $parentRemoteId = 9001,
    int $bundleId = 7,
    int $sessionId = 63,
    array|string|null $parameters = null
): array {
    $parameters ??= [
        'source_type' => 'auto_photo_prepare',
        'standalone_sparse' => true,
        'prepare_job_id' => 745,
        'prepare_remote_job_id' => 9001,
        'capture_bundle_id' => $bundleId,
        'app_bundle_uuid' => 'b' . $bundleId,
        'input_images' => 178,
    ];

    return [
        'id' => $id,
        'order_id' => 30,
        'capture_session_id' => $sessionId,
        'job_type' => 'COLMAP_SPARSE',
        'remote_job_id' => $remoteId,
        'parent_remote_job_id' => $parentRemoteId,
        'status' => 'DONE',
        'progress_percent' => 100,
        'message' => 'sparse',
        'parameters_json' => is_array($parameters)
            ? ui_json($parameters)
            : $parameters,
        'pipeline_run_id' => null,
    ];
}

function ui_export(
    int $id = 900,
    int $remoteId = 9100,
    int $parentRemoteId = 434136404,
    string $status = 'DONE',
    array|string|null $parameters = null,
    ?string $output = null
): array {
    $parameters ??= [
        'source_type' => 'auto_photo_sparse',
        'standalone_photo_export' => true,
        'sparse_job_id' => 434136404,
        'model_id' => 0,
    ];

    return [
        'id' => $id,
        'order_id' => 30,
        'capture_session_id' => 63,
        'job_type' => 'EXPORT_PLY',
        'remote_job_id' => $remoteId,
        'parent_remote_job_id' => $parentRemoteId,
        'output_path' => $output
            ?? auto_photo_sparse_output_path($remoteId) . '/sparse_0.ply',
        'status' => $status,
        'progress_percent' => 0,
        'message' => 'export',
        'parameters_json' => is_array($parameters)
            ? ui_json($parameters)
            : $parameters,
    ];
}

function ui_create_bundle_fixture(
    string $label,
    int $id = 8,
    int $orderId = 30,
    int $sessionId = 63,
    string $uuid = 'b8'
): array {
    static $counter = 0;
    $counter++;
    $safeLabel = preg_replace('/[^a-z0-9_-]+/i', '_', $label) ?: 'fixture';
    $relative = 'orders/' . $safeLabel . '_' . $counter
        . '/sessions/session/capture_bundles/bundle.tgz';
    $archive = APP_STORAGE_DIR . '/' . $relative;
    mkdir(dirname($archive), 0775, true);
    file_put_contents($archive, 'sentinel-' . $counter);

    $row = [
        'id' => $id,
        'order_id' => $orderId,
        'capture_session_id' => $sessionId,
        'app_bundle_uuid' => $uuid,
        'capture_type' => AUTO_PHOTO_BUNDLE_CAPTURE_TYPE,
        'filename' => 'bundle.tgz',
        'storage_path' => $relative,
        'size_bytes' => filesize($archive),
        'status' => 'UPLOADED',
    ];
    $indexPath = auto_photo_bundle_index_cache_path($row, $archive);

    return [
        'row' => $row,
        'archive' => $archive,
        'index_path' => $indexPath,
        'bundle_dir' => dirname($indexPath),
        'bundles_dir' => dirname(dirname($indexPath)),
    ];
}

function ui_valid_cached_index(array $row, int $photosCount = 87): array
{
    return [
        'schema_version' => AUTO_PHOTO_BUNDLE_INDEX_SCHEMA_VERSION,
        'capture_bundle_id' => (int) $row['id'],
        'order_id' => (int) $row['order_id'],
        'capture_session_id' => (int) $row['capture_session_id'],
        'app_bundle_uuid' => (string) $row['app_bundle_uuid'],
        'capture_type' => AUTO_PHOTO_BUNDLE_CAPTURE_TYPE,
        'validation_status' => 'VALID',
        'blocking_errors' => [],
        'photos_count_actual' => $photosCount,
        'photos' => array_fill(0, $photosCount, []),
    ];
}

function ui_write_cached_index(array $fixture, array|string $index): void
{
    mkdir($fixture['bundle_dir'], 0775, true);
    $contents = is_array($index) ? ui_json($index) : $index;
    file_put_contents($fixture['index_path'], $contents);
}

function ui_reject_index_mutation(
    string $label,
    callable $mutator
): void {
    $fixture = ui_create_bundle_fixture($label);
    $index = ui_valid_cached_index($fixture['row']);
    $mutated = $mutator($index, $fixture['row']);
    ui_write_cached_index($fixture, $mutated);
    ui_assert(
        auto_photo_sparse_ui_web_bundle_index_summary($fixture['row']) === null,
        'cached index rejected: ' . $label
    );
}

try {
    $validFixture = ui_create_bundle_fixture('valid_cached');
    ui_write_cached_index(
        $validFixture,
        ui_valid_cached_index($validFixture['row'])
    );
    $summary = auto_photo_sparse_ui_web_bundle_index_summary($validFixture['row']);
    ui_assert($summary === ['photos_count' => 87], 'cached index 87');
    $cachedDto = auto_photo_sparse_ui_web_bundle($validFixture['row'], 30);
    ui_assert(
        $cachedDto['photos_count'] === 87
        && $cachedDto['photos_count_known'] === true,
        'cached count known'
    );

    $missingFixture = ui_create_bundle_fixture('missing_cached');
    $missingDto = auto_photo_sparse_ui_web_bundle($missingFixture['row'], 30);
    ui_assert(
        $missingDto['photos_count'] === 0
        && $missingDto['photos_count_known'] === false,
        'missing cached index unknown'
    );

    $emptyFixture = ui_create_bundle_fixture('empty_cached');
    ui_write_cached_index($emptyFixture, '');
    ui_assert(
        auto_photo_sparse_ui_web_bundle_index_summary($emptyFixture['row']) === null,
        'empty cached index rejected'
    );

    $malformedFixture = ui_create_bundle_fixture('malformed_cached');
    ui_write_cached_index($malformedFixture, '{');
    ui_assert(
        auto_photo_sparse_ui_web_bundle_index_summary($malformedFixture['row']) === null,
        'malformed cached index rejected'
    );

    $oversizedFixture = ui_create_bundle_fixture('oversized_cached');
    ui_write_cached_index($oversizedFixture, str_repeat('x', 4 * 1024 * 1024 + 1));
    ui_assert(
        auto_photo_sparse_ui_web_bundle_index_summary($oversizedFixture['row']) === null,
        'oversized cached index rejected'
    );

    $indexSymlinkFixture = ui_create_bundle_fixture('index_symlink');
    mkdir($indexSymlinkFixture['bundle_dir'], 0775, true);
    $indexSymlinkTarget = $testRoot . '/index_symlink_target.json';
    file_put_contents(
        $indexSymlinkTarget,
        ui_json(ui_valid_cached_index($indexSymlinkFixture['row']))
    );
    symlink($indexSymlinkTarget, $indexSymlinkFixture['index_path']);
    ui_assert(
        auto_photo_sparse_ui_web_bundle_index_summary($indexSymlinkFixture['row']) === null,
        'index symlink rejected'
    );

    $bundlesSymlinkFixture = ui_create_bundle_fixture('bundles_symlink');
    $bundlesSymlinkTarget = $testRoot . '/bundles_symlink_target';
    mkdir($bundlesSymlinkTarget, 0775, true);
    symlink($bundlesSymlinkTarget, $bundlesSymlinkFixture['bundles_dir']);
    ui_assert(
        auto_photo_sparse_ui_web_bundle_index_summary($bundlesSymlinkFixture['row']) === null,
        'auto_photo_bundles symlink rejected'
    );

    $bundleDirSymlinkFixture = ui_create_bundle_fixture('bundle_dir_symlink');
    mkdir($bundleDirSymlinkFixture['bundles_dir'], 0775, true);
    $bundleDirSymlinkTarget = $testRoot . '/bundle_dir_symlink_target';
    mkdir($bundleDirSymlinkTarget, 0775, true);
    symlink($bundleDirSymlinkTarget, $bundleDirSymlinkFixture['bundle_dir']);
    ui_assert(
        auto_photo_sparse_ui_web_bundle_index_summary($bundleDirSymlinkFixture['row']) === null,
        'bundle directory symlink rejected'
    );

    $nonRegularFixture = ui_create_bundle_fixture('non_regular_index');
    mkdir($nonRegularFixture['index_path'], 0775, true);
    ui_assert(
        auto_photo_sparse_ui_web_bundle_index_summary($nonRegularFixture['row']) === null,
        'non-regular cached index rejected'
    );

    ui_reject_index_mutation('wrong schema', static function (array $index): array {
        $index['schema_version'] = 2;
        return $index;
    });
    ui_reject_index_mutation('wrong bundle ID', static function (array $index): array {
        $index['capture_bundle_id'] = 9;
        return $index;
    });
    ui_reject_index_mutation('wrong order ID', static function (array $index): array {
        $index['order_id'] = 31;
        return $index;
    });
    ui_reject_index_mutation('wrong session ID', static function (array $index): array {
        $index['capture_session_id'] = 64;
        return $index;
    });
    ui_reject_index_mutation('wrong UUID', static function (array $index): array {
        $index['app_bundle_uuid'] = 'wrong';
        return $index;
    });
    ui_reject_index_mutation('wrong capture type', static function (array $index): array {
        $index['capture_type'] = 'video';
        return $index;
    });
    foreach (['WARNING', 'INVALID'] as $validationStatus) {
        ui_reject_index_mutation(
            strtolower($validationStatus),
            static function (array $index) use ($validationStatus): array {
                $index['validation_status'] = $validationStatus;
                return $index;
            }
        );
    }
    ui_reject_index_mutation('blocking errors', static function (array $index): array {
        $index['blocking_errors'] = ['broken'];
        return $index;
    });
    ui_reject_index_mutation('count string', static function (array $index): array {
        $index['photos_count_actual'] = '87';
        return $index;
    });
    ui_reject_index_mutation('negative count', static function (array $index): array {
        $index['photos_count_actual'] = -1;
        return $index;
    });
    ui_reject_index_mutation('photos count mismatch', static function (array $index): array {
        $index['photos'] = array_fill(0, 86, []);
        return $index;
    });

    $priorityFixture = ui_create_bundle_fixture('count_priority');
    ui_write_cached_index(
        $priorityFixture,
        ui_valid_cached_index($priorityFixture['row'], 87)
    );
    $priorityBundle = $priorityFixture['row'];

    $noJobs = auto_photo_sparse_ui_web_build_from_rows(
        30,
        true,
        [$priorityBundle],
        [],
        [],
        [],
        null
    );
    ui_assert(
        $noJobs['bundle']['photos_count'] === 87
        && $noJobs['bundle']['photos_count_known'] === true,
        'cached count priority without jobs'
    );

    $prepareNinety = ui_prepare(
        bundleId: 8,
        sessionId: 63,
        parameters: [
            'source_type' => 'auto_photo_bundle',
            'pipeline_mode' => 'prepare',
            'capture_bundle_id' => 8,
            'app_bundle_uuid' => 'b8',
            'input_images' => 90,
        ]
    );
    $prepareCountDto = auto_photo_sparse_ui_web_build_from_rows(
        30,
        true,
        [$priorityBundle],
        [],
        [$prepareNinety],
        [],
        null
    );
    ui_assert(
        $prepareCountDto['bundle']['photos_count'] === 90
        && $prepareCountDto['bundle']['photos_count_known'] === true,
        'prepare count overrides cache'
    );

    $prepareZero = ui_prepare(
        bundleId: 8,
        sessionId: 63,
        parameters: [
            'source_type' => 'auto_photo_bundle',
            'pipeline_mode' => 'prepare',
            'capture_bundle_id' => 8,
            'app_bundle_uuid' => 'b8',
            'input_images' => 0,
        ]
    );
    $sparseNinetyOne = ui_sparse(
        bundleId: 8,
        sessionId: 63,
        parameters: [
            'source_type' => 'auto_photo_prepare',
            'standalone_sparse' => true,
            'prepare_job_id' => 745,
            'prepare_remote_job_id' => 9001,
            'capture_bundle_id' => 8,
            'app_bundle_uuid' => 'b8',
            'input_images' => 91,
        ]
    );
    $sparseCountDto = auto_photo_sparse_ui_web_build_from_rows(
        30,
        true,
        [$priorityBundle],
        [$sparseNinetyOne],
        [$prepareZero],
        [],
        static fn(int $unused): array => ['models' => []]
    );
    ui_assert(
        $sparseCountDto['bundle']['photos_count'] === 91
        && $sparseCountDto['bundle']['photos_count_known'] === true,
        'sparse count overrides cache when prepare count absent'
    );

    $noCacheFixture = ui_create_bundle_fixture('count_unknown');
    $unknownCountDto = auto_photo_sparse_ui_web_build_from_rows(
        30,
        true,
        [$noCacheFixture['row']],
        [],
        [],
        [],
        null
    );
    ui_assert(
        $unknownCountDto['bundle']['photos_count'] === 0
        && $unknownCountDto['bundle']['photos_count_known'] === false,
        'missing cache remains unknown'
    );

    $canPrepareNoJob = auto_photo_sparse_ui_web_build_from_rows(
        30,
        true,
        [$priorityBundle],
        [],
        [],
        [],
        null
    );
    ui_assert($canPrepareNoJob['bundle']['can_prepare'] === true, 'no prepare can start');

    foreach (['QUEUED', 'RUNNING', 'DONE'] as $status) {
        $dto = auto_photo_sparse_ui_web_build_from_rows(
            30,
            true,
            [$priorityBundle],
            [],
            [ui_prepare(bundleId: 8, sessionId: 63, status: $status)],
            [],
            null
        );
        ui_assert($dto['bundle']['can_prepare'] === false, $status . ' blocks prepare');
    }

    foreach (['ERROR', 'FAILED', 'CANCELLED'] as $status) {
        $dto = auto_photo_sparse_ui_web_build_from_rows(
            30,
            true,
            [$priorityBundle],
            [],
            [ui_prepare(bundleId: 8, sessionId: 63, status: $status)],
            [],
            null
        );
        ui_assert($dto['bundle']['can_prepare'] === true, $status . ' allows prepare retry');
    }

    $readOnlyDto = auto_photo_sparse_ui_web_build_from_rows(
        30,
        false,
        [$priorityBundle],
        [],
        [],
        [],
        null
    );
    ui_assert($readOnlyDto['bundle']['can_prepare'] === false, 'permission blocks prepare');

    $wrongMarkerPrepare = ui_prepare(
        bundleId: 8,
        sessionId: 63,
        status: 'QUEUED',
        parameters: [
            'source_type' => 'wrong',
            'pipeline_mode' => 'prepare',
            'capture_bundle_id' => 8,
            'app_bundle_uuid' => 'b8',
        ]
    );
    $wrongMarkerDto = auto_photo_sparse_ui_web_build_from_rows(
        30,
        true,
        [$priorityBundle],
        [],
        [$wrongMarkerPrepare],
        [],
        null
    );
    ui_assert($wrongMarkerDto['bundle']['can_prepare'] === true, 'wrong marker prepare does not block');

    $malformedPrepare = ui_prepare(
        bundleId: 8,
        sessionId: 63,
        status: 'QUEUED',
        parameters: '{'
    );
    $malformedPrepareDto = auto_photo_sparse_ui_web_build_from_rows(
        30,
        true,
        [$priorityBundle],
        [],
        [$malformedPrepare],
        [],
        null
    );
    ui_assert($malformedPrepareDto['bundle']['can_prepare'] === true, 'malformed prepare does not block');

    $legacyJob = array_merge(
        ui_prepare(bundleId: 8, sessionId: 63, status: 'QUEUED'),
        ['job_type' => 'EXTRACT_FRAMES']
    );
    $legacyDto = auto_photo_sparse_ui_web_build_from_rows(
        30,
        true,
        [$priorityBundle],
        [],
        [$legacyJob],
        [],
        null
    );
    ui_assert($legacyDto['bundle']['can_prepare'] === true, 'legacy video job does not block');

    $unrelatedSparse = array_merge(
        ui_sparse(bundleId: 8, sessionId: 63),
        ['pipeline_run_id' => 123]
    );
    $unrelatedSparseDto = auto_photo_sparse_ui_web_build_from_rows(
        30,
        true,
        [$priorityBundle],
        [$unrelatedSparse],
        [],
        [],
        null
    );
    ui_assert($unrelatedSparseDto['bundle']['can_prepare'] === true, 'unrelated sparse does not block');

    $models = [
        'models' => [[
            'model_id' => 0,
            'registered_images' => 118,
            'points3D_count' => 23230,
        ]],
    ];

    $only = auto_photo_sparse_ui_web_build_from_rows(
        30,
        false,
        [ui_bundle()],
        [],
        [],
        [],
        null
    );
    ui_assert(
        $only['visible']
        && $only['prepare'] === null
        && $only['runs'] === []
        && $only['bundle']['id'] === 7,
        'bundle only'
    );

    $running = auto_photo_sparse_ui_web_build_from_rows(
        30,
        true,
        [ui_bundle()],
        [],
        [ui_prepare(status: 'RUNNING')],
        [],
        null
    );
    ui_assert(
        $running['visible']
        && $running['prepare']['status'] === 'RUNNING'
        && $running['active_jobs']
        && $running['runs'] === []
        && $running['bundle']['photos_count'] === 178,
        'prepare running'
    );

    $error = auto_photo_sparse_ui_web_build_from_rows(
        30,
        true,
        [ui_bundle()],
        [],
        [ui_prepare(status: 'ERROR')],
        [],
        null
    );
    ui_assert(
        $error['visible']
        && $error['prepare']['status'] === 'ERROR'
        && !$error['active_jobs'],
        'prepare error'
    );

    $bundles = [ui_bundle(), ui_bundle(8, 64)];
    $badSparse = ui_sparse(
        id: 800,
        remoteId: 434136405,
        parentRemoteId: 9002,
        bundleId: 8,
        sessionId: 64,
        parameters: [
            'source_type' => 'auto_photo_prepare',
            'standalone_sparse' => true,
            'prepare_job_id' => 999,
            'prepare_remote_job_id' => 9002,
            'capture_bundle_id' => 8,
            'app_bundle_uuid' => 'b8',
            'input_images' => 3,
        ]
    );
    $goodSparse = ui_sparse();
    $prepares = [ui_prepare(), ui_prepare(750, 9002, 8, 64)];
    foreach ([
        [$bundles, [$badSparse, $goodSparse]],
        [array_reverse($bundles), array_reverse([$badSparse, $goodSparse])],
    ] as [$bundleRows, $sparseRows]) {
        ui_assert(
            auto_photo_sparse_ui_web_select_bundle(
                $bundleRows,
                $sparseRows,
                $prepares,
                30
            )['id'] === 7,
            'sparse chain fallback'
        );
    }

    $prepares = [ui_prepare(745, 9001, 7), ui_prepare(750, 9002, 8, 64)];
    foreach ([$bundles, array_reverse($bundles)] as $bundleRows) {
        ui_assert(
            auto_photo_sparse_ui_web_select_bundle($bundleRows, [], $prepares, 30)['id'] === 8,
            'prepare fallback'
        );
        ui_assert(
            auto_photo_sparse_ui_web_select_bundle($bundleRows, [], [], 30)['id'] === 8,
            'bundle ID fallback'
        );
    }

    $badPrepares = [
        ui_prepare(parameters: '{'),
        ui_prepare(parameters: [
            'source_type' => 'wrong',
            'pipeline_mode' => 'prepare',
            'capture_bundle_id' => 7,
            'app_bundle_uuid' => 'b7',
        ]),
        ui_prepare(parameters: [
            'source_type' => 'auto_photo_bundle',
            'pipeline_mode' => 'wrong',
            'capture_bundle_id' => 7,
            'app_bundle_uuid' => 'b7',
        ]),
        ui_prepare(parameters: [
            'source_type' => 'auto_photo_bundle',
            'pipeline_mode' => 'prepare',
            'capture_bundle_id' => 8,
            'app_bundle_uuid' => 'b7',
        ]),
        ui_prepare(parameters: [
            'source_type' => 'auto_photo_bundle',
            'pipeline_mode' => 'prepare',
            'capture_bundle_id' => 7,
            'app_bundle_uuid' => 'wrong',
        ]),
        array_merge(ui_prepare(), ['order_id' => 31]),
        array_merge(ui_prepare(), ['capture_session_id' => 99]),
        array_merge(ui_prepare(), ['id' => 0]),
        array_merge(ui_prepare(), ['remote_job_id' => 0]),
    ];
    foreach ($badPrepares as $badPrepare) {
        ui_assert(
            auto_photo_sparse_ui_web_prepare_for_bundle(
                $badPrepare,
                30,
                ui_bundle()
            ) === null,
            'prepare markers'
        );
    }

    $prepareNoImages = ui_prepare(parameters: [
        'source_type' => 'auto_photo_bundle',
        'pipeline_mode' => 'prepare',
        'capture_bundle_id' => 7,
        'app_bundle_uuid' => 'b7',
    ]);
    $prepareZeroImages = ui_prepare(parameters: [
        'source_type' => 'auto_photo_bundle',
        'pipeline_mode' => 'prepare',
        'capture_bundle_id' => 7,
        'app_bundle_uuid' => 'b7',
        'input_images' => 0,
    ]);
    $prepareTwoHundred = ui_prepare(parameters: [
        'source_type' => 'auto_photo_bundle',
        'pipeline_mode' => 'prepare',
        'capture_bundle_id' => 7,
        'app_bundle_uuid' => 'b7',
        'input_images' => 200,
    ]);
    foreach ([
        [$prepareNoImages, 178],
        [$prepareZeroImages, 178],
        [$prepareTwoHundred, 200],
    ] as [$prepareRow, $expectedCount]) {
        $dto = auto_photo_sparse_ui_web_build_from_rows(
            30,
            true,
            [ui_bundle()],
            [ui_sparse()],
            [$prepareRow],
            [],
            static fn(int $unused): array => $models
        );
        ui_assert($dto['bundle']['photos_count'] === $expectedCount, 'photos fallback');
    }

    $exportPath = auto_photo_sparse_output_path(9100) . '/sparse_0.ply';
    mkdir(dirname($exportPath), 0775, true);
    file_put_contents($exportPath, 'ply');
    $dto = auto_photo_sparse_ui_web_build_from_rows(
        30,
        false,
        [ui_bundle()],
        [ui_sparse()],
        [ui_prepare()],
        [434136404 => [ui_export()]],
        static fn(int $unused): array => $models
    );
    ui_assert(
        $dto['visible']
        && $dto['prepare']['db_job_id'] === 745
        && $dto['runs'][0]['sparse_remote_job_id'] === 434136404
        && $dto['runs'][0]['models'][0]['model_id'] === 0
        && $dto['runs'][0]['models'][0]['export']['db_job_id'] === 900
        && $dto['runs'][0]['models'][0]['export']['download_url'] !== ''
        && !$dto['runs'][0]['models'][0]['can_export'],
        '746/model 0/ready export/canManage'
    );

    foreach ([
        ui_sparse(parameters: '{'),
        ui_sparse(parameters: [
            'source_type' => 'auto_photo_prepare',
            'standalone_sparse' => false,
            'prepare_job_id' => 745,
            'prepare_remote_job_id' => 9001,
            'capture_bundle_id' => 7,
            'app_bundle_uuid' => 'b7',
        ]),
        array_merge(ui_sparse(), ['pipeline_run_id' => 1]),
        array_merge(ui_sparse(), ['capture_session_id' => 99]),
    ] as $badSparseRow) {
        ui_assert(
            auto_photo_sparse_ui_web_sparse($badSparseRow, 30, ui_bundle()) === null,
            'sparse filters'
        );
    }

    $chainParameters = [
        'source_type' => 'auto_photo_prepare',
        'standalone_sparse' => true,
        'prepare_job_id' => 745,
        'prepare_remote_job_id' => 9001,
        'capture_bundle_id' => 7,
        'app_bundle_uuid' => 'b7',
        'input_images' => 178,
    ];
    foreach (['prepare_job_id', 'prepare_remote_job_id'] as $key) {
        $parameters = $chainParameters;
        unset($parameters[$key]);
        ui_assert(
            !auto_photo_sparse_ui_web_sparse_prepare(
                ui_sparse(parameters: $parameters),
                ui_prepare()
            ),
            'missing prepare ID'
        );
    }
    foreach ([
        ['prepare_job_id' => 0],
        ['prepare_remote_job_id' => 0],
        ['prepare_job_id' => '01'],
        ['prepare_remote_job_id' => '01'],
        ['prepare_job_id' => 744],
        ['prepare_remote_job_id' => 9002],
        ['prepare_job_id' => 744, 'prepare_remote_job_id' => 9002],
    ] as $change) {
        $parameters = array_replace($chainParameters, $change);
        ui_assert(
            !auto_photo_sparse_ui_web_sparse_prepare(
                ui_sparse(parameters: $parameters),
                ui_prepare()
            ),
            'invalid prepare chain parameters'
        );
    }
    ui_assert(
        !auto_photo_sparse_ui_web_sparse_prepare(
            ui_sparse(parentRemoteId: 9002, parameters: $chainParameters),
            ui_prepare()
        ),
        'wrong sparse parent'
    );

    ui_assert(
        auto_photo_sparse_ui_web_export(
            ui_export(parameters: ['source_type' => 'video_sparse']),
            30,
            ui_sparse()
        ) === null
        && auto_photo_sparse_ui_web_export(ui_export(parentRemoteId: 12), 30, ui_sparse()) === null
        && auto_photo_sparse_ui_web_export(array_merge(ui_export(), ['id' => 0]), 30, ui_sparse()) === null
        && auto_photo_sparse_ui_web_export(array_merge(ui_export(), ['remote_job_id' => 0]), 30, ui_sparse()) === null
        && auto_photo_sparse_ui_web_export(ui_export(remoteId: 434136404), 30, ui_sparse()) === null
        && auto_photo_sparse_ui_web_export(ui_export(output: '/wrong/path'), 30, ui_sparse()) === null,
        'export filters, separate ID and output path'
    );

    $missingExport = ui_export(remoteId: 9101);
    ui_assert(
        auto_photo_sparse_ui_web_export($missingExport, 30, ui_sparse()) === null,
        'missing DONE output'
    );

    $emptyExport = ui_export(remoteId: 9102);
    mkdir(dirname($emptyExport['output_path']), 0775, true);
    file_put_contents($emptyExport['output_path'], '');
    ui_assert(
        auto_photo_sparse_ui_web_export($emptyExport, 30, ui_sparse()) === null,
        'empty DONE output'
    );

    $linkedExport = ui_export(remoteId: 9103);
    mkdir(dirname($linkedExport['output_path']), 0775, true);
    symlink($exportPath, $linkedExport['output_path']);
    ui_assert(
        auto_photo_sparse_ui_web_export($linkedExport, 30, ui_sparse()) === null,
        'symlink DONE output'
    );

    $activeExport = ui_export(
        status: 'RUNNING',
        parameters: [
            'source_type' => 'auto_photo_sparse',
            'standalone_photo_export' => true,
            'sparse_job_id' => 434136404,
            'model_id' => '01',
        ]
    );
    $activeDto = auto_photo_sparse_ui_web_build_from_rows(
        30,
        true,
        [ui_bundle()],
        [ui_sparse()],
        [ui_prepare()],
        [434136404 => [$activeExport]],
        static fn(int $unused): array => $models
    );
    ui_assert(
        $activeDto['active_jobs']
        && $activeDto['runs'][0]['models'][0]['export'] === null,
        'active malformed export'
    );

    $jobDir = auto_photo_sparse_output_path(434136404);
    mkdir($jobDir . '/colmap', 0775, true);
    $manifestFile = $jobDir . '/colmap/sparse_components.json';
    file_put_contents($manifestFile, ui_json($models));
    ui_assert(
        auto_photo_sparse_ui_web_components(434136404)['models'][0]['model_id'] === 0,
        'valid manifest'
    );
    ui_assert(
        auto_photo_sparse_ui_web_components(1) === ['models' => []],
        'missing manifest'
    );
    file_put_contents($manifestFile, '{');
    ui_assert(
        auto_photo_sparse_ui_web_components(434136404) === ['models' => []],
        'invalid manifest'
    );
    file_put_contents(
        $manifestFile,
        str_repeat('x', AUTO_PHOTO_SPARSE_UI_WEB_MANIFEST_MAX_BYTES + 1)
    );
    ui_assert(
        auto_photo_sparse_ui_web_components(434136404) === ['models' => []],
        'oversized manifest'
    );
    @unlink($manifestFile);
    symlink('/etc/passwd', $manifestFile);
    ui_assert(
        auto_photo_sparse_ui_web_components(434136404) === ['models' => []],
        'manifest symlink'
    );
    @unlink($manifestFile);
    @rmdir($jobDir . '/colmap');
    @rmdir($jobDir);

    $outside = $testRoot . '/output_outside';
    mkdir($outside, 0775, true);
    symlink($outside, $jobDir);
    ui_assert(
        auto_photo_sparse_ui_web_components(434136404) === ['models' => []],
        'job symlink'
    );
    @unlink($jobDir);
    mkdir($jobDir, 0775, true);
    symlink($outside, $jobDir . '/colmap');
    ui_assert(
        auto_photo_sparse_ui_web_components(434136404) === ['models' => []],
        'colmap symlink'
    );

    $source = (string) file_get_contents(
        __DIR__ . '/../libs/auto_photo_sparse_ui_web_lib.php'
    );
    ui_assert(
        preg_match(
            '/\b(INSERT|UPDATE|DELETE|REPLACE|FOR[[:space:]]+UPDATE|LOCK[[:space:]]+TABLES)\b/i',
            $source
        ) !== 1,
        'no modifying SQL'
    );
    ui_assert(
        array_keys($dto) === [
            'visible',
            'bundle',
            'prepare',
            'runs',
            'recommended_sparse_db_job_id',
            'active_jobs',
        ],
        'DTO shape'
    );

    echo "OK\n";
} finally {
    ui_remove($testRoot);
}
