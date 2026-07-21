<?php
declare(strict_types=1);

$testRoot = sys_get_temp_dir()
    . '/maklertour_auto_photo_sparse_'
    . bin2hex(random_bytes(6));
define('AUTO_PHOTO_SPARSE_OUTPUT_ROOT', $testRoot);

require_once __DIR__ . '/../libs/auto_photo_sparse_lib.php';

function aps_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function aps_expect(callable $callback, string $expected): void
{
    try {
        $callback();
    } catch (Throwable $e) {
        aps_assert(
            $e->getMessage() === $expected,
            "expected {$expected}, got {$e->getMessage()}"
        );
        return;
    }

    throw new RuntimeException("missing {$expected}");
}

function aps_remove_tree(string $path): void
{
    if (!file_exists($path) && !is_link($path)) {
        return;
    }
    if (is_file($path) || is_link($path)) {
        unlink($path);
        return;
    }

    foreach (new DirectoryIterator($path) as $entry) {
        if ($entry->isDot()) {
            continue;
        }
        aps_remove_tree($entry->getPathname());
    }
    rmdir($path);
}

try {
    $remoteJobId = 991234567;
    $outputPath = auto_photo_sparse_output_path($remoteJobId);
    $framesPath = $outputPath . '/frames';
    mkdir($framesPath, 0775, true);
    file_put_contents($framesPath . '/frame_000001.jpg', "\xFF\xD8\xFF\xD9");
    file_put_contents($framesPath . '/frame_000002.jpg', "\xFF\xD8\xFF\xD9");

    $result = [
        'schema_version' => 1,
        'job_type' => AUTO_PHOTO_PREPARE_JOB_TYPE,
        'remote_job_id' => $remoteJobId,
        'capture_bundle_id' => 7,
        'app_bundle_uuid' => 'bundle-uuid',
        'status' => 'DONE',
        'frames_count' => 2,
        'frames_directory' => 'frames',
        'warnings' => [],
    ];
    file_put_contents(
        auto_photo_sparse_result_path($remoteJobId),
        json_encode($result)
    );

    $parent = [
        'id' => 745,
        'order_id' => 30,
        'capture_session_id' => 63,
        'job_type' => AUTO_PHOTO_PREPARE_JOB_TYPE,
        'status' => 'DONE',
        'remote_job_id' => $remoteJobId,
        'output_path' => $outputPath,
        'result_json_path' => auto_photo_sparse_result_path($remoteJobId),
    ];

    $plan = auto_photo_sparse_plan($parent);
    aps_assert($plan['input_images'] === 2, 'valid parent count');
    aps_assert(
        $plan['input_path']
            === '/home/makler_storage/output/job_991234567/frames',
        'remote input path'
    );
    aps_assert(
        auto_photo_sparse_result_path(123)
            === $testRoot . '/job_123/result.json',
        'sparse result path contract'
    );

    $parameters = auto_photo_sparse_parameters($plan);
    aps_assert($parameters['standalone_sparse'] === true, 'standalone marker');
    aps_assert(isset($parameters['settings']['sparse']), 'settings reuse');

    $standaloneJob = [
        'job_type' => 'COLMAP_SPARSE',
        'parameters_json' => json_encode($parameters),
    ];
    aps_assert(
        auto_photo_sparse_is_standalone_job($standaloneJob),
        'standalone chain guard decision'
    );
    aps_assert(
        !auto_photo_sparse_is_standalone_job([
            'job_type' => 'COLMAP_SPARSE',
            'parameters_json' => json_encode([
                'source_type' => 'video_pipeline',
                'standalone_sparse' => false,
            ]),
        ]),
        'ordinary sparse chain retained'
    );

    $worker = (string) file_get_contents(
        __DIR__ . '/../tools/sfm_remote_worker.php'
    );
    aps_assert(
        str_contains($worker, 'auto_photo_sparse_is_standalone_job($job)'),
        'worker standalone guard integration'
    );

    $bad = $parent;
    $bad['status'] = 'RUNNING';
    aps_expect(fn() => auto_photo_sparse_plan($bad), 'prepare_not_done');

    $bad = $parent;
    $bad['job_type'] = 'EXTRACT_FRAMES';
    aps_expect(
        fn() => auto_photo_sparse_plan($bad),
        'invalid_prepare_job_type'
    );

    $badResult = $result;
    $badResult['remote_job_id'] = 1;
    file_put_contents(
        auto_photo_sparse_result_path($remoteJobId),
        json_encode($badResult)
    );
    aps_expect(
        fn() => auto_photo_sparse_plan($parent),
        'prepare_result_contract_invalid'
    );
    file_put_contents(
        auto_photo_sparse_result_path($remoteJobId),
        json_encode($result)
    );

    unlink($framesPath . '/frame_000002.jpg');
    aps_expect(
        fn() => auto_photo_sparse_plan($parent),
        'frames_count_mismatch'
    );
    file_put_contents($framesPath . '/frame_000002.jpg', "\xFF\xD8\xFF\xD9");

    file_put_contents($framesPath . '/extra.txt', 'x');
    aps_expect(
        fn() => auto_photo_sparse_plan($parent),
        'invalid_frames_entry'
    );
    unlink($framesPath . '/extra.txt');

    symlink(
        $framesPath . '/frame_000001.jpg',
        $framesPath . '/frame_000003.jpg'
    );
    aps_expect(
        fn() => auto_photo_sparse_plan($parent),
        'invalid_frames_entry'
    );
    unlink($framesPath . '/frame_000003.jpg');

    aps_assert(
        auto_photo_sparse_parse_cli([
            'tool',
            '--prepare-job-id=745',
            '--dry-run',
        ])['mode'] === 'dry-run',
        'dry-run parser'
    );
    aps_assert(
        auto_photo_sparse_parse_cli(['tool', '--status=800'])['mode']
            === 'status',
        'status parser'
    );
    aps_expect(
        fn() => auto_photo_sparse_parse_cli([
            'tool',
            '--status=800',
            '--enqueue',
        ]),
        'invalid_cli_mode'
    );
    aps_expect(
        fn() => auto_photo_sparse_parse_cli(['tool', '--unknown']),
        'unknown_cli_argument'
    );

    echo "AUTO-B03 tests: PASS\n";
} finally {
    aps_remove_tree($testRoot);
}
