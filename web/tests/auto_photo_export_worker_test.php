<?php
declare(strict_types=1);

require_once __DIR__ . '/../libs/auto_photo_export_worker_lib.php';

function apew_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function apew_expect_exception(
    callable $callback,
    string $expectedMessage
): void {
    try {
        $callback();
    } catch (Throwable $exception) {
        apew_assert(
            $exception->getMessage() === $expectedMessage,
            'expected ' . $expectedMessage . ', got ' . $exception->getMessage()
        );
        return;
    }

    throw new RuntimeException('missing exception: ' . $expectedMessage);
}

function apew_remove_tree(string $path): void
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
            apew_remove_tree($path . '/' . $entry);
        }
    }

    @rmdir($path);
}

function apew_photo_job(
    string $base,
    int|string $exportRemoteJobId = '9',
    int|string $sparseRemoteJobId = '8',
    int|string $modelId = '1'
): array {
    return [
        'remote_job_id' => $exportRemoteJobId,
        'order_id' => '1',
        'capture_session_id' => 2,
        'parent_remote_job_id' => $sparseRemoteJobId,
        'parameters_json' => json_encode([
            'source_type' => 'auto_photo_sparse',
            'standalone_photo_export' => true,
            'sparse_job_id' => $sparseRemoteJobId,
            'model_id' => $modelId,
        ]),
        'output_path' => $base . '/job_' . $exportRemoteJobId
            . '/sparse_' . $modelId . '.ply',
        'log_path' => $base . '/job_' . $exportRemoteJobId . '/logs',
    ];
}

$base = sys_get_temp_dir()
    . '/auto_photo_export_worker_'
    . bin2hex(random_bytes(6));

if (!mkdir($base, 0775, true) && !is_dir($base)) {
    throw new RuntimeException('test directory creation failed');
}

try {
    apew_assert(auto_photo_export_worker_parse_positive_id(1) === 1, 'positive int');
    apew_assert(auto_photo_export_worker_parse_positive_id('1') === 1, 'positive string');

    foreach ([0, '0', '01', 'abc', true, false, -1, 1.5, str_repeat('9', 40)] as $value) {
        apew_assert(
            auto_photo_export_worker_parse_positive_id($value) === null,
            'invalid positive ID accepted'
        );
    }

    apew_assert(auto_photo_export_worker_parse_model_id(0) === 0, 'model zero int');
    apew_assert(auto_photo_export_worker_parse_model_id('0') === 0, 'model zero string');
    apew_assert(auto_photo_export_worker_parse_model_id('12') === 12, 'model string');

    foreach ([-1, '01', 'abc', true, false, 1.5, str_repeat('9', 40)] as $value) {
        apew_assert(
            auto_photo_export_worker_parse_model_id($value) === null,
            'invalid model ID accepted'
        );
    }

    apew_assert(
        !auto_photo_export_worker_is_photo_job(['parameters_json' => '{']),
        'malformed JSON must be legacy'
    );

    $job = apew_photo_job($base);
    apew_assert(auto_photo_export_worker_is_photo_job($job), 'trusted marker');

    $plan = auto_photo_export_worker_plan(
        $job,
        $base,
        '/worker/export_sparse_ply.sh',
        '/worker/stations.conf'
    );

    apew_assert(
        $plan['args'] === [
            '/worker/export_sparse_ply.sh',
            '/worker/stations.conf',
            '8',
            '1',
            $base,
            $job['output_path'],
            '9',
        ],
        'exact args'
    );

    apew_expect_exception(
        fn() => auto_photo_export_worker_plan(
            array_replace($job, ['parent_remote_job_id' => 7]),
            $base,
            'script',
            'config'
        ),
        'photo_export_parent_mismatch'
    );

    $missingModel = $job;
    $parameters = json_decode((string) $missingModel['parameters_json'], true);
    unset($parameters['model_id']);
    $missingModel['parameters_json'] = json_encode($parameters);

    apew_expect_exception(
        fn() => auto_photo_export_worker_plan(
            $missingModel,
            $base,
            'script',
            'config'
        ),
        'photo_export_parameters_invalid'
    );

    apew_expect_exception(
        fn() => auto_photo_export_worker_plan(
            array_replace($job, [
                'output_path' => $base . '/job_8/sparse_1.ply',
            ]),
            $base,
            'script',
            'config'
        ),
        'photo_export_path_invalid'
    );

    apew_expect_exception(
        fn() => auto_photo_export_worker_plan(
            array_replace($job, [
                'log_path' => $base . '/job_9/other_logs',
            ]),
            $base,
            'script',
            'config'
        ),
        'photo_export_path_invalid'
    );

    auto_photo_export_worker_prepare_paths($plan, $base);
    apew_assert(is_dir($plan['export_root']), 'export root');
    apew_assert(is_dir($plan['log_path']), 'log path');

    $symlinkRoot = $base . '/job_10';
    apew_assert(symlink($base, $symlinkRoot), 'root symlink fixture');
    $symlinkRootPlan = auto_photo_export_worker_plan(
        apew_photo_job($base, 10, 8, 1),
        $base,
        'script',
        'config'
    );
    apew_expect_exception(
        fn() => auto_photo_export_worker_prepare_paths($symlinkRootPlan, $base),
        'photo_export_path_invalid'
    );

    $symlinkLogsRoot = $base . '/job_11';
    apew_assert(mkdir($symlinkLogsRoot, 0775), 'logs root fixture');
    apew_assert(symlink($base, $symlinkLogsRoot . '/logs'), 'logs symlink fixture');
    $symlinkLogsPlan = auto_photo_export_worker_plan(
        apew_photo_job($base, 11, 8, 1),
        $base,
        'script',
        'config'
    );
    apew_expect_exception(
        fn() => auto_photo_export_worker_prepare_paths($symlinkLogsPlan, $base),
        'photo_export_path_invalid'
    );

    file_put_contents($plan['output_path'], 'ply');
    $done = auto_photo_export_worker_completion($plan, 0);
    apew_assert($done['status'] === 'DONE', 'photo done');
    apew_assert(
        $done['message'] === 'PLY exported: job_9/sparse_1.ply',
        'photo done message'
    );

    unlink($plan['output_path']);
    $missing = auto_photo_export_worker_completion($plan, 0);
    apew_assert(
        $missing['status'] === 'ERROR'
            && $missing['message'] === 'photo_export_output_missing',
        'missing artifact'
    );

    file_put_contents($plan['output_path'], '');
    $empty = auto_photo_export_worker_completion($plan, 0);
    apew_assert(
        $empty['status'] === 'ERROR'
            && $empty['message'] === 'photo_export_output_missing',
        'empty artifact'
    );

    apew_assert(
        auto_photo_export_worker_completion($plan, 7) === [
            'status' => 'ERROR',
            'progress' => 0,
            'message' => 'command_failed',
        ],
        'photo command failure'
    );

    $legacyPlan = auto_photo_export_worker_plan(
        ['parameters_json' => '{'],
        $base,
        'script',
        'config'
    );
    apew_assert(
        $legacyPlan === ['is_photo_export' => false],
        'legacy plan'
    );

    apew_assert(
        auto_photo_export_worker_completion($legacyPlan, 0, 8, 1) === [
            'status' => 'DONE',
            'progress' => 100,
            'message' => 'PLY exported: job_8/colmap/sparse/1/model.ply',
        ],
        'legacy success'
    );

    apew_assert(
        auto_photo_export_worker_completion($legacyPlan, 1, 8, 1) === [
            'status' => 'ERROR',
            'progress' => 0,
            'message' => 'command_failed',
        ],
        'legacy failure'
    );

    $workerSource = file_get_contents(
        __DIR__ . '/../tools/sfm_remote_worker.php'
    );
    apew_assert($workerSource !== false, 'worker source readable');
    apew_assert(
        str_contains($workerSource, 'auto_photo_export_worker_lib.php'),
        'worker requires export helper'
    );
    apew_assert(
        str_contains($workerSource, 'auto_photo_export_worker_plan'),
        'worker plans photo export'
    );
    apew_assert(
        str_contains($workerSource, 'auto_photo_export_worker_prepare_paths'),
        'worker prepares photo export paths'
    );
    apew_assert(
        str_contains($workerSource, '$args = $photoExportPlan[\'args\'];'),
        'worker uses photo export plan arguments'
    );
    apew_assert(
        !str_contains($workerSource, 'photo_export_shell_not_ready'),
        'worker removes temporary photo shell guard'
    );
    apew_assert(
        !preg_match('/function\\s+photo_export_integer\\s*\\(/', $workerSource),
        'worker must not implement an inline photo ID parser'
    );

    $exportBranchStart = strpos($workerSource, "} elseif (\$type === 'EXPORT_PLY') {");
    $denseBranchStart = strpos(
        $workerSource,
        "} elseif (\$type === 'COLMAP_DENSE_CHUNK') {",
        $exportBranchStart
    );
    $runCommandStart = strpos(
        $workerSource,
        'run_command($args)',
        $denseBranchStart
    );
    apew_assert(
        $exportBranchStart !== false
            && $denseBranchStart !== false
            && $runCommandStart !== false,
        'worker export branch boundaries'
    );
    $exportBranch = substr(
        $workerSource,
        $exportBranchStart,
        $denseBranchStart - $exportBranchStart
    );
    $photoBranchStart = strpos($exportBranch, "if (\$photoExportPlan['is_photo_export'] === true)");
    apew_assert(
        $photoBranchStart !== false && $exportBranchStart + $photoBranchStart < $runCommandStart,
        'photo branch precedes run_command'
    );
    $legacyBranchStart = strpos(
        $exportBranch,
        '} else {',
        $photoBranchStart
    );
    apew_assert($legacyBranchStart !== false, 'legacy export branch present');
    $photoBranch = substr(
        $exportBranch,
        $photoBranchStart,
        $legacyBranchStart - $photoBranchStart
    );
    apew_assert(
        str_contains($photoBranch, 'auto_photo_export_worker_prepare_paths'),
        'photo branch prepares paths through helper'
    );
    apew_assert(
        str_contains($photoBranch, '$args = $photoExportPlan[\'args\'];'),
        'photo branch takes arguments from helper plan'
    );
    apew_assert(
        !str_contains($photoBranch, 'model_id_from_job'),
        'photo branch must not use legacy model parsing'
    );
    apew_assert(
        !str_contains($photoBranch, 'remote_output_dir')
            && !str_contains($photoBranch, 'output_path')
            && !str_contains($photoBranch, 'log_path'),
        'photo branch must not calculate output paths'
    );

    $normalizedExportBranch = preg_replace('/\\s+/', '', $exportBranch);
    apew_assert(
        $normalizedExportBranch !== null
            && str_contains(
                $normalizedExportBranch,
                "[SFM_REMOTE_BASE.'/export_sparse_ply.sh',SFM_REMOTE_CONF,(string)\$parent,(string)\$modelId,SFM_REMOTE_OUTPUT]"
        ),
        'legacy export arguments remain compatible'
    );

    $completionStart = strpos(
        $workerSource,
        'if (' . "\n"
            . "        \$type === 'EXPORT_PLY'" . "\n"
            . "        && is_array(\$photoExportPlan)",
        $runCommandStart
    );
    $commandFailureStart = strpos($workerSource, 'if ($code !== 0)', $runCommandStart);
    $photoLogStart = strpos(
        $workerSource,
        'write_job_text_log(',
        $completionStart
    );
    $helperCompletionStart = strpos(
        $workerSource,
        'auto_photo_export_worker_completion(',
        $completionStart
    );
    $completionSetJobStart = strpos(
        $workerSource,
        'set_job(',
        $helperCompletionStart
    );
    $completionReturnStart = strpos(
        $workerSource,
        'return;',
        $completionSetJobStart
    );
    apew_assert(
        $completionStart !== false
            && $completionStart > $runCommandStart
            && $completionStart < $commandFailureStart,
        'photo completion is between command execution and generic failure'
    );
    apew_assert(
        $photoLogStart !== false
            && $photoLogStart < $helperCompletionStart
            && $helperCompletionStart < $completionSetJobStart,
        'photo output log precedes helper completion and status update'
    );
    apew_assert(
        $completionReturnStart !== false
            && $completionReturnStart < $commandFailureStart,
        'photo completion returns before legacy completion'
    );

    $legacyBranch = substr($exportBranch, $legacyBranchStart);
    apew_assert(
        str_contains($legacyBranch, 'model_id_from_job($job)'),
        'legacy branch retains model resolver'
    );
    $legacyCompletionStart = strpos(
        $workerSource,
        "if (\$type === 'EXPORT_PLY') {\n        write_job_text_log",
        $commandFailureStart
    );
    $legacyCompletion = substr($workerSource, $legacyCompletionStart);
    apew_assert(
        $legacyCompletionStart !== false
            && str_contains($legacyCompletion, 'model_id_from_job($job)')
            && str_contains(
                $legacyCompletion,
                "'PLY exported: job_' . \$parent . '/colmap/sparse/' . \$modelId . '/model.ply'"
            ),
        'legacy completion remains unchanged'
    );

    echo "OK\n";
} finally {
    apew_remove_tree($base);
}
