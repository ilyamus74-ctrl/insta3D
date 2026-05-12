<?php
declare(strict_types=1);

function system_tasks_ensure_tables(mysqli $dbcnx): void
{
    $dbcnx->query("CREATE TABLE IF NOT EXISTS system_tasks (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(64) NOT NULL UNIQUE,
        name VARCHAR(191) NOT NULL,
        description TEXT NULL,
        endpoint_action VARCHAR(128) NOT NULL,
        payload_json LONGTEXT NULL,
        interval_minutes INT UNSIGNED NOT NULL DEFAULT 60,
        is_enabled TINYINT(1) NOT NULL DEFAULT 1,
        last_run_at DATETIME NULL,
        next_run_at DATETIME NULL,
        last_status VARCHAR(16) NULL,
        last_message TEXT NULL,
        is_running TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $dbcnx->query("CREATE TABLE IF NOT EXISTS system_task_runs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        task_id INT UNSIGNED NOT NULL,
        started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        finished_at DATETIME NULL,
        status VARCHAR(16) NOT NULL,
        message TEXT NULL,
        context_json LONGTEXT NULL,
        KEY idx_task_started (task_id, started_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $dbcnx->query("CREATE TABLE IF NOT EXISTS warehouse_sync_batch_jobs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        status VARCHAR(16) NOT NULL DEFAULT 'queued',
        forwarder VARCHAR(64) NULL,
        requested_by INT UNSIGNED NULL,
        total_items INT UNSIGNED NOT NULL DEFAULT 0,
        processed_items INT UNSIGNED NOT NULL DEFAULT 0,
        ok_items INT UNSIGNED NOT NULL DEFAULT 0,
        fail_items INT UNSIGNED NOT NULL DEFAULT 0,
        message TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        started_at DATETIME NULL,
        finished_at DATETIME NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_status_created (status, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $dbcnx->query("CREATE TABLE IF NOT EXISTS warehouse_sync_batch_job_items (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        job_id BIGINT UNSIGNED NOT NULL,
        item_id INT UNSIGNED NOT NULL,
        connector_id INT UNSIGNED NULL,
        status VARCHAR(16) NOT NULL DEFAULT 'pending',
        message TEXT NULL,
        attempts INT UNSIGNED NOT NULL DEFAULT 0,
        started_at DATETIME NULL,
        finished_at DATETIME NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_job_item (job_id, item_id),
        KEY idx_job_status (job_id, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}


function system_tasks_endpoint_registry(): array
{
    return [
        'operation_1' => [
            'group' => 'core',
            'name' => 'Операция #1 (шаблон)',
            'description' => 'Плейсхолдер системной задачи.',
        ],
        'warehouse_sync_out_backfill' => [
            'group' => 'warehouse',
            'name' => 'Backfill warehouse_item_out',
            'description' => 'Заполняет/обновляет warehouse_item_out из warehouse_item_stock.',
        ],
        'warehouse_stock_to_out_sync' => [
            'group' => 'warehouse',
            'name' => 'Stock -> Out sync',
            'description' => 'Отдельный endpoint для переноса/обновления warehouse_item_out из warehouse_item_stock.',
        ],
        'connectors_report_operation_1' => [
            'group' => 'connectors',
            'name' => 'Коннекторы: операция #1 (репорты)',
            'description' => 'Скачивает и импортирует репорты активных коннекторов.',
        ],
        'warehouse_sync_batch_worker' => [
            'group' => 'warehouse',
            'name' => 'Обработчик batch sync',
            'description' => 'Берет queued/running batch jobs и обрабатывает их в фоне.',
        ],
        'warehouse_sync_reconcile' => [
            'group' => 'warehouse',
            'name' => 'Reconcile half_sync/error',
            'description' => 'Перепроверяет статусы half_sync/error в warehouse_item_out.',
        ],
    ];
}

function system_tasks_is_known_endpoint_action(string $action): bool
{
    if ($action === '') {
        return false;
    }

    $registry = system_tasks_endpoint_registry();
    return isset($registry[$action]);
}

function system_tasks_seed_defaults(mysqli $dbcnx): void
{

    $hasRows = false;
    $countRes = $dbcnx->query("SELECT COUNT(*) AS cnt FROM system_tasks");
    if ($countRes) {
        $countRow = $countRes->fetch_assoc();
        $hasRows = ((int)($countRow['cnt'] ?? 0)) > 0;
        $countRes->free();
    }
    if ($hasRows) {
        return;
    }



    $defaults = [
        [
            'code' => 'operation_1_hourly',
            'name' => 'Операция #1 (раз в час)',
            'description' => 'Шаблон системной задачи. Можно заменить endpoint_action на реальный.',
            'endpoint_action' => 'operation_1',
            'interval_minutes' => 60,
        ],
        [
            'code' => 'warehouse_sync_out_backfill_hourly',
            'name' => 'Backfill warehouse_item_out (раз в час)',
            'description' => 'Заполняет/обновляет warehouse_item_out из warehouse_item_stock.',
            'endpoint_action' => 'warehouse_stock_to_out_sync',
            'interval_minutes' => 60,
        ],
        [
            'code' => 'connectors_report_operation_1_hourly',
            'name' => 'Коннекторы: report/entrypoint (раз в час)',
            'description' => 'Для активных коннекторов запускает report (или entrypoint), скачивает и импортирует репорты. Через payload_json можно указать operation_id и connector_id(s).',
            'endpoint_action' => 'connectors_report_operation_1',
            'interval_minutes' => 60,
        ],
        [
            'code' => 'warehouse_sync_batch_worker',
            'name' => 'Обработчик пакетной синхронизации посылок',
            'description' => 'Берет queued/running batch jobs и обрабатывает их в фоне.',
            'endpoint_action' => 'warehouse_sync_batch_worker',
            'interval_minutes' => 1,
        ],
        [
            'code' => 'warehouse_sync_reconcile_half_sync_30m',
            'name' => 'Reconcile half_sync/error (раз в 30 минут)',
            'description' => 'Перепроверяет статусы half_sync/error в warehouse_item_out и обновляет до confirmed_sync/error.',
            'endpoint_action' => 'warehouse_sync_reconcile',
            'interval_minutes' => 30,
        ],

    ];
/*
    $sql = "INSERT INTO system_tasks (code, name, description, endpoint_action, interval_minutes, is_enabled, next_run_at)
            VALUES (?, ?, ?, ?, ?, 1, NOW())
            ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                description = VALUES(description),
                endpoint_action = VALUES(endpoint_action),
                interval_minutes = VALUES(interval_minutes)";
*/
    $sql = "INSERT IGNORE INTO system_tasks (code, name, description, endpoint_action, interval_minutes, is_enabled, next_run_at)
            VALUES (?, ?, ?, ?, ?, 1, NOW())";
    $stmt = $dbcnx->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('system_tasks seed prepare failed');
    }

    foreach ($defaults as $task) {
        $stmt->bind_param(
            'ssssi',
            $task['code'],
            $task['name'],
            $task['description'],
            $task['endpoint_action'],
            $task['interval_minutes']
        );
        $stmt->execute();
    }
    $stmt->close();
}

function system_tasks_run_due(mysqli $dbcnx, int $systemUserId = 0): array
{
    system_tasks_ensure_tables($dbcnx);

    $ran = 0;
    $errors = 0;

    $res = $dbcnx->query("SELECT * FROM system_tasks
                         WHERE is_enabled = 1
                           AND is_running = 0
                           AND (next_run_at IS NULL OR next_run_at <= NOW())
                         ORDER BY next_run_at IS NULL DESC, next_run_at ASC, id ASC
                         LIMIT 20");

    if (!$res) {
        throw new RuntimeException('Failed to fetch due tasks: ' . $dbcnx->error);
    }

    while ($task = $res->fetch_assoc()) {
        $taskId = (int)$task['id'];
        $lockStmt = $dbcnx->prepare("UPDATE system_tasks SET is_running = 1 WHERE id = ? AND is_running = 0");
        if (!$lockStmt) {
            continue;
        }
        $lockStmt->bind_param('i', $taskId);
        $lockStmt->execute();
        $locked = $lockStmt->affected_rows > 0;
        $lockStmt->close();
        if (!$locked) {
            continue;
        }

        $ran += 1;
        $runStatus = 'ok';
        $runMessage = 'done';
        $context = [];

        try {
            $result = system_tasks_execute($dbcnx, $task, $systemUserId);
            $runStatus = ($result['status'] ?? 'ok') === 'ok' ? 'ok' : 'error';
            $runMessage = (string)($result['message'] ?? 'done');
            $context = is_array($result['context'] ?? null) ? $result['context'] : [];
        } catch (Throwable $e) {
            $runStatus = 'error';
            $runMessage = $e->getMessage();
            $errors += 1;
        }

        $contextJson = json_encode($context, JSON_UNESCAPED_UNICODE);

        $runStmt = $dbcnx->prepare("INSERT INTO system_task_runs (task_id, status, message, context_json, finished_at)
                                    VALUES (?, ?, ?, ?, NOW())");
        if ($runStmt) {
            $runStmt->bind_param('isss', $taskId, $runStatus, $runMessage, $contextJson);
            $runStmt->execute();
            $runStmt->close();
        }

        $interval = max(1, (int)($task['interval_minutes'] ?? 60));
        $upd = $dbcnx->prepare("UPDATE system_tasks
                               SET is_running = 0,
                                   last_run_at = NOW(),
                                   next_run_at = DATE_ADD(NOW(), INTERVAL ? MINUTE),
                                   last_status = ?,
                                   last_message = ?
                               WHERE id = ?");
        if ($upd) {
            $upd->bind_param('issi', $interval, $runStatus, $runMessage, $taskId);
            $upd->execute();
            $upd->close();
        }
    }

    $res->free();

    return ['ran' => $ran, 'errors' => $errors];
}

function system_tasks_execute(mysqli $dbcnx, array $task, int $systemUserId = 0): array
{
    $action = trim((string)($task['endpoint_action'] ?? ''));
    if ($action === '') {
        return ['status' => 'error', 'message' => 'Empty endpoint_action'];
    }

    if (!system_tasks_is_known_endpoint_action($action)) {
        return [
            'status' => 'error',
            'message' => 'Unknown endpoint_action: ' . $action,
        ];
    }

    if ($action === 'operation_1') {
        return [
            'status' => 'ok',
            'message' => 'operation_1 placeholder executed',
            'context' => ['task' => 'operation_1'],
        ];
    }

    if ($action === 'warehouse_sync_batch_worker') {
        return system_tasks_run_warehouse_sync_batch_worker($dbcnx, $task, $systemUserId);
    }

    if ($action === 'warehouse_sync_out_backfill' || $action === 'warehouse_stock_to_out_sync') {
        return system_tasks_run_warehouse_sync_out_backfill($dbcnx, $task);
    }

    if ($action === 'connectors_report_operation_1') {
        return system_tasks_run_connectors_report_operation_1($dbcnx, $task);
    }

    if ($action === 'warehouse_sync_reconcile') {
        return system_tasks_run_warehouse_sync_reconcile($dbcnx, $task, $systemUserId);
    }

    return [
        'status' => 'error',

        'message' => 'Unhandled endpoint_action: ' . $action,
    ];
}


function system_tasks_run_warehouse_sync_reconcile(mysqli $dbcnx, array $task, int $systemUserId = 0): array
{
    $payloadRaw = (string)($task['payload_json'] ?? '');
    $payload = $payloadRaw !== '' ? json_decode($payloadRaw, true) : [];
    if (!is_array($payload)) {
        $payload = [];
    }

    $limit = max(1, min(5000, (int)($payload['limit'] ?? 200)));

    if (!function_exists('warehouse_sync_reconcile_half_sync')) {
        $action = '__system_task_bootstrap__';
        $user = ['id' => $systemUserId > 0 ? $systemUserId : 1, 'role' => 'ADMIN'];
        $response = ['status' => 'ok'];
        require_once __DIR__ . '/../warehouse/warehouse_sync_actions.php';
    }

    if (!function_exists('warehouse_sync_reconcile_half_sync')) {
        return ['status' => 'error', 'message' => 'warehouse_sync_reconcile_half_sync not available'];
    }

    $stats = warehouse_sync_reconcile_half_sync($dbcnx, $limit, $systemUserId > 0 ? $systemUserId : 1);

    return [
        'status' => 'ok',
        'message' => 'warehouse_sync_reconcile done',
        'context' => [
            'limit' => $limit,
            'checked' => (int)($stats['checked'] ?? 0),
            'confirmed_sync' => (int)($stats['confirmed_sync'] ?? 0),
            'error' => (int)($stats['error'] ?? 0),
            'unchanged' => (int)($stats['unchanged'] ?? 0),
        ],
    ];
}

function system_tasks_run_warehouse_sync_out_backfill(mysqli $dbcnx, array $task): array
{
    $payloadRaw = (string)($task['payload_json'] ?? '');
    $payload = $payloadRaw !== '' ? json_decode($payloadRaw, true) : [];
    if (!is_array($payload)) {
        $payload = [];
    }

    $limit = max(1, min(10000, (int)($payload['limit'] ?? 500)));

    if (!function_exists('warehouse_sync_out_backfill_from_stock')) {
        $action = '__system_task_bootstrap__';
        $user = ['id' => 1, 'role' => 'ADMIN'];
        $response = ['status' => 'ok'];
        require_once __DIR__ . '/../warehouse/warehouse_sync_actions.php';
    }

    if (!function_exists('warehouse_sync_out_backfill_from_stock')) {
        return ['status' => 'error', 'message' => 'warehouse_sync_out_backfill_from_stock not available'];
    }

    $result = warehouse_sync_out_backfill_from_stock($dbcnx, $limit);

    return [
        'status' => 'ok',
        'message' => 'warehouse_sync_out_backfill done',
        'context' => [
            'limit' => $limit,
            'processed' => (int)($result['processed'] ?? 0),
            'inserted' => (int)($result['inserted'] ?? 0),
            'updated' => (int)($result['updated'] ?? 0),
        ],
    ];
}

function system_tasks_run_connectors_report_operation_1(mysqli $dbcnx, array $task): array
{
    if (!function_exists('connectors_decode_operations')) {
        $action = '__system_task_bootstrap__';
        $user = ['id' => 1, 'role' => 'ADMIN'];
        $response = ['status' => 'ok'];
        require_once __DIR__ . '/../connectors/connector_actions.php';
    }

    $requiredFns = [
        'connectors_decode_operations_payload',
        'connectors_decode_operations_for_runtime',
        'connectors_v3_payload_to_runtime_operations',
        'connectors_resolve_report_operation_id',
        'connectors_normalize_report_table_name',
        'connectors_ensure_report_table',
        'connectors_download_report_file',
        'connectors_import_csv_into_report_table',
        'connectors_import_xlsx_into_report_table',
        'connectors_execute_operation_by_kind_for_manual_test',
        'connectors_generate_run_id',
        'connectors_append_trace_event',
        'connectors_append_operation_executed_event',
        'connectors_build_chain_status_map',
        'connectors_persist_run_trace',
        'connectors_is_dependency_graph_enabled',
        'connectors_build_execution_plan',
        'connectors_build_legacy_execution_plan',
        'connectors_build_graph_error',
        'connectors_resolve_graph_error_code',
    ];
    foreach ($requiredFns as $fn) {
        if (!function_exists($fn)) {
            return ['status' => 'error', 'message' => "{$fn} not available"];
        }
    }

    $payloadRaw = trim((string)($task['payload_json'] ?? ''));
    $payload = $payloadRaw !== '' ? json_decode($payloadRaw, true) : [];
    if (!is_array($payload)) {
        $payload = [];
    }

    $forcedOperationId = trim((string)($payload['operation_id'] ?? ''));
    $forcedConnectorIds = [];
    if (isset($payload['connector_id'])) {
        $forcedConnectorId = (int)$payload['connector_id'];
        if ($forcedConnectorId > 0) {
            $forcedConnectorIds[$forcedConnectorId] = true;
        }
    }
    if (isset($payload['connector_ids']) && is_array($payload['connector_ids'])) {
        foreach ($payload['connector_ids'] as $rawConnectorId) {
            $forcedConnectorId = (int)$rawConnectorId;
            if ($forcedConnectorId > 0) {
                $forcedConnectorIds[$forcedConnectorId] = true;
            }
        }
    }

    $processed = 0;
    $ok = 0;
    $fail = 0;
    $details = [];

    $res = $dbcnx->query("SELECT * FROM connectors WHERE is_active = 1 ORDER BY id ASC");
    if (!$res) {
        return ['status' => 'error', 'message' => 'Failed to fetch connectors'];
    }

    while ($connector = $res->fetch_assoc()) {
        $connectorId = (int)($connector['id'] ?? 0);
        if ($connectorId <= 0) {
            continue;
        }
        if ($forcedConnectorIds !== [] && !isset($forcedConnectorIds[$connectorId])) {
            continue;
        }

        $operations = connectors_decode_operations_for_runtime($connector);
        $reportOperationId = $forcedOperationId !== '' ? $forcedOperationId : connectors_resolve_report_operation_id($operations);
        if ($reportOperationId === null || !isset($operations[$reportOperationId]) || !is_array($operations[$reportOperationId])) {
            continue;
        }

        $reportCfg = (array)$operations[$reportOperationId];
        $reportConfig = isset($reportCfg['config']) && is_array($reportCfg['config']) ? $reportCfg['config'] : [];
        foreach ($reportConfig as $configKey => $configValue) {
            if (!array_key_exists($configKey, $reportCfg)) {
                $reportCfg[$configKey] = $configValue;
            }
        }
        if (!isset($reportCfg['steps']) && !empty($reportCfg['steps_json'])) {
            $decodedSteps = json_decode((string)$reportCfg['steps_json'], true);
            if (is_array($decodedSteps)) {
                $reportCfg['steps'] = $decodedSteps;
            }
        }
        if (!isset($reportCfg['curl_config']) && !empty($reportCfg['curl_config_json'])) {
            $decodedCurlConfig = json_decode((string)$reportCfg['curl_config_json'], true);
            if (is_array($decodedCurlConfig)) {
                $reportCfg['curl_config'] = $decodedCurlConfig;
            }
        }
        if (!isset($reportCfg['field_mapping']) && !empty($reportCfg['field_mapping_json'])) {
            $decodedFieldMapping = json_decode((string)$reportCfg['field_mapping_json'], true);
            if (is_array($decodedFieldMapping)) {
                $reportCfg['field_mapping'] = $decodedFieldMapping;
            }
        }
        if (empty($reportCfg['enabled'])) {
            continue;
        }

        $processed += 1;

        $runStartedAtTs = microtime(true);
        $runStartedAt = date('Y-m-d H:i:s');
        $runId = function_exists('connectors_generate_run_id')
            ? connectors_generate_run_id($connectorId)
            : ('run-' . date('YmdHis') . '-c' . $connectorId . '-' . bin2hex(random_bytes(4)));
        $reportOperationId = trim((string)($reportCfg['operation_id'] ?? $reportOperationId));
        if ($reportOperationId === '') {
            $reportOperationId = 'report';
        }
        $executionPlan = [
            'before' => [],
            'main' => $reportOperationId,
            'during' => [],
            'finally' => [],
        ];
        $graphErrors = [];
        $traceLog = [];
        if (function_exists('connectors_append_trace_event')) {
            connectors_append_trace_event($traceLog, $runId, $reportOperationId, 'start', 'start', 'Запуск cron операции коннектора');
        }
        try {

            if (connectors_is_dependency_graph_enabled($connector)) {
                try {
                    $executionPlan = connectors_build_execution_plan($operations, $reportOperationId);
                } catch (InvalidArgumentException $graphException) {
                    $graphErrors[] = connectors_build_graph_error(
                        $runId,
                        $connectorId,
                        $reportOperationId,
                        connectors_resolve_graph_error_code($graphException->getMessage()),
                        [
                            'message' => $graphException->getMessage(),
                            'source' => 'cron_report',
                        ]
                    );
                    throw $graphException;
                }
            } else {
                $executionPlan = connectors_build_legacy_execution_plan($reportOperationId);
            }

            $operationResult = connectors_execute_operation_by_kind_for_manual_test(
                $connector,
                $reportCfg,
                $connectorId,
                null,
                null
            );
            $targetTable = connectors_normalize_report_table_name((string)($operationResult['target_table'] ?? ($reportCfg['target_table'] ?? '')));
            $importedRows = (int)($operationResult['imported_rows'] ?? 0);
            $downloadInfo = isset($operationResult['download']) && is_array($operationResult['download']) ? $operationResult['download'] : [];
            $fileExt = strtolower(trim((string)($downloadInfo['file_extension'] ?? '')));

            $traceMeta = isset($operationResult['trace_meta']) && is_array($operationResult['trace_meta']) ? $operationResult['trace_meta'] : [];
            $ok += 1;

            if (function_exists('connectors_append_trace_event')) {
                connectors_append_operation_executed_event($traceLog, $runId, $reportOperationId, 'main', 'success', 'Cron операция #1 выполнена успешно', 0, null, null, [
                    'target_table' => $targetTable,
                    'file_extension' => $fileExt,
                    'imported_rows' => $importedRows,
                    'trace_meta' => $traceMeta,
                ]);
            }

            if (function_exists('connectors_persist_run_trace')) {
                connectors_persist_run_trace($dbcnx, [
                    'connector_id' => $connectorId,
                    'run_id' => $runId,
                    'test_operation' => 'cron_report',
                    'status' => 'ok',
                    'message' => 'Cron операция #1 выполнена успешно',
                    'target_table' => $targetTable,
                    'created_by' => 0,
                    'started_at' => $runStartedAt,
                    'finished_at' => date('Y-m-d H:i:s'),
                    'duration_ms' => max(0, (int)round((microtime(true) - $runStartedAtTs) * 1000)),
                    'trace_log' => $traceLog,
                    'step_log' => isset($downloadInfo['step_log']) && is_array($downloadInfo['step_log']) ? $downloadInfo['step_log'] : [],
                    'execution_plan' => $executionPlan,
                    'chain_status' => function_exists('connectors_build_chain_status_map')
                        ? connectors_build_chain_status_map($executionPlan, $reportOperationId, true, $traceLog)
                        : [['operation_id' => $reportOperationId, 'status' => 'success']],
                    'artifacts_dir' => (string)($downloadInfo['artifacts_dir'] ?? ''),
                    'graph_errors' => $graphErrors,
                ]);
            }
            $details[] = [
                'connector_id' => $connectorId,
                'status' => 'ok',
                'run_id' => $runId,
                'target_table' => $targetTable,
                'file_extension' => $fileExt,
                'imported_rows' => $importedRows,
            ];
        } catch (Throwable $e) {
            $fail += 1;

            if (empty($graphErrors) && connectors_is_dependency_graph_enabled($connector) && $e instanceof InvalidArgumentException) {
                $graphErrors[] = connectors_build_graph_error(
                    $runId,
                    $connectorId,
                    $reportOperationId,
                    connectors_resolve_graph_error_code($e->getMessage()),
                    [
                        'message' => $e->getMessage(),
                        'source' => 'cron_report',
                    ]
                );
            }

            if (function_exists('connectors_append_trace_event')) {
                connectors_append_operation_executed_event($traceLog, $runId, $reportOperationId, 'main', 'failed', 'Cron операция коннектора завершилась ошибкой', 0, null, null, [
                    'error' => $e->getMessage(),
                    'graph_errors' => $graphErrors,
                ]);
            }

            if (function_exists('connectors_persist_run_trace')) {
            $stepLog = method_exists($e, 'getStepLog') ? (array)$e->getStepLog() : [];
            $artifactsDir = method_exists($e, 'getArtifactsDir') ? (string)$e->getArtifactsDir() : '';
                connectors_persist_run_trace($dbcnx, [
                    'connector_id' => $connectorId,
                    'run_id' => $runId,
                    'test_operation' => 'cron_report',
                    'status' => 'error',
                    'message' => $e->getMessage(),
                    'target_table' => '',
                    'created_by' => 0,
                    'started_at' => $runStartedAt,
                    'finished_at' => date('Y-m-d H:i:s'),
                    'duration_ms' => max(0, (int)round((microtime(true) - $runStartedAtTs) * 1000)),
                    'trace_log' => $traceLog,
                    'step_log' => [],
                    'execution_plan' => $executionPlan,
                    'chain_status' => function_exists('connectors_build_chain_status_map')
                        ? connectors_build_chain_status_map($executionPlan, $reportOperationId, false, $traceLog)
                        : [['operation_id' => $reportOperationId, 'status' => 'failed']],
                    'artifacts_dir' => '',
                    'graph_errors' => $graphErrors,
                ]);
            }

            $details[] = [
                'connector_id' => $connectorId,
                'status' => 'error',
                'run_id' => $runId,
                'message' => $e->getMessage(),
                'graph_errors' => $graphErrors,
            ];
        }
    }
    $res->free();

    return [
        'status' => $fail > 0 ? 'error' : 'ok',
        'message' => "connectors_report_operation_1 done: processed={$processed}, ok={$ok}, fail={$fail}",
        'context' => [
            'processed' => $processed,
            'ok' => $ok,
            'fail' => $fail,
            'forced_operation_id' => $forcedOperationId,
            'forced_connector_ids' => array_keys($forcedConnectorIds),
            'details' => $details,
        ],
    ];
}


function system_tasks_auto_enqueue_warehouse_sync_batch(mysqli $dbcnx, int $limit = 100, int $requestedBy = 0): array
{

    $limit = max(1, min(500, $limit));

    if (!function_exists('warehouse_sync_fetch_connector') || !function_exists('warehouse_sync_resolve_permitted_connector')) {
        $action = '__system_task_bootstrap__';
        $user = ['id' => $requestedBy > 0 ? $requestedBy : 1, 'role' => 'ADMIN'];
        $response = ['status' => 'ok'];
        require_once __DIR__ . '/../warehouse/warehouse_sync_actions.php';
    }

    $sql = "SELECT wi.id, wi.receiver_company, wi.receiver_country_code
            FROM warehouse_item_stock wi
            LEFT JOIN warehouse_item_out wo ON wo.stock_item_id = wi.id
            WHERE wi.receiver_company IS NOT NULL
              AND TRIM(wi.receiver_company) <> ''
              AND wi.receiver_country_code IS NOT NULL
              AND TRIM(wi.receiver_country_code) <> ''
              AND (wo.stock_item_id IS NULL OR wo.status IN ('for_sync', 'error'))
            ORDER BY wi.created_at ASC
            LIMIT ?";

    $stmt = $dbcnx->prepare($sql);
    if (!$stmt) {
        return ['queued' => 0, 'job_id' => 0, 'message' => 'auto-enqueue prepare failed'];
    }

    $stmt->bind_param('i', $limit);
    $stmt->execute();
    $res = $stmt->get_result();

    $connectorActiveStmt = $dbcnx->prepare("SELECT is_active FROM connectors WHERE id = ? LIMIT 1");

    $candidates = [];
    while ($res && ($row = $res->fetch_assoc())) {
        $itemId = (int)($row['id'] ?? 0);
        if ($itemId <= 0) {
            continue;
        }

        $forwarder = strtoupper(trim((string)($row['receiver_company'] ?? '')));
        $country = strtoupper(trim((string)($row['receiver_country_code'] ?? '')));
        if ($forwarder === '' || $country === '') {
            continue;
        }

        $connector = null;
        if (function_exists('warehouse_sync_resolve_permitted_connector')) {
            try {
                $connector = warehouse_sync_resolve_permitted_connector($dbcnx, [
                    'receiver_company' => $forwarder,
                    'receiver_country_code' => $country,
                ], 0);
            } catch (Throwable $e) {
                $connector = null;
            }
        } elseif (function_exists('warehouse_sync_fetch_connector')) {
            $connector = warehouse_sync_fetch_connector($dbcnx, $forwarder, $country);
        }
        if (!$connector) {
            continue;
        }

        $connectorId = (int)($connector['id'] ?? 0);
        if ($connectorId <= 0) {
            continue;
        }

        if ($connectorActiveStmt) {
            $connectorActiveStmt->bind_param('i', $connectorId);
            $connectorActiveStmt->execute();
            $connectorRes = $connectorActiveStmt->get_result();
            $connectorRow = $connectorRes ? $connectorRes->fetch_assoc() : null;
            if ($connectorRes) {
                $connectorRes->free();
            }
            if ((int)($connectorRow['is_active'] ?? 0) !== 1) {
                continue;
            }
        }

        $candidates[] = [
            'item_id' => $itemId,
            'connector_id' => $connectorId,
        ];
    }
    if ($connectorActiveStmt) {
        $connectorActiveStmt->close();
    }
    $stmt->close();

    if (!$candidates) {
        return ['queued' => 0, 'job_id' => 0, 'message' => 'no eligible for_sync/error items'];
    }

    $dbcnx->begin_transaction();
    try {
        $total = count($candidates);
        $forwarder = 'AUTO';
        $jobStmt = $dbcnx->prepare("INSERT INTO warehouse_sync_batch_jobs (status, forwarder, requested_by, total_items, message)
                                    VALUES ('queued', ?, ?, ?, 'queued automatically by worker')");
        if (!$jobStmt) {
            throw new RuntimeException('auto-enqueue job prepare failed');
        }
        $jobStmt->bind_param('sii', $forwarder, $requestedBy, $total);
        $jobStmt->execute();
        $jobId = (int)$dbcnx->insert_id;
        $jobStmt->close();

        $itemStmt = $dbcnx->prepare("INSERT IGNORE INTO warehouse_sync_batch_job_items (job_id, item_id, connector_id, status)
                                     VALUES (?, ?, ?, 'pending')");
        if (!$itemStmt) {
            throw new RuntimeException('auto-enqueue item prepare failed');
        }

        $queued = 0;
        foreach ($candidates as $candidate) {
            $itemId = (int)$candidate['item_id'];
            $connectorId = (int)$candidate['connector_id'];
            $itemStmt->bind_param('iii', $jobId, $itemId, $connectorId);
            $itemStmt->execute();
            if ($itemStmt->affected_rows > 0) {
                $queued += 1;
            }
        }
        $itemStmt->close();

        if ($queued === 0) {
            $cleanup = $dbcnx->prepare("DELETE FROM warehouse_sync_batch_jobs WHERE id = ?");
            if ($cleanup) {
                $cleanup->bind_param('i', $jobId);
                $cleanup->execute();
                $cleanup->close();
            }
            $dbcnx->commit();
            return ['queued' => 0, 'job_id' => 0, 'message' => 'auto-enqueue skipped (all duplicates)'];
        }

        $upd = $dbcnx->prepare("UPDATE warehouse_sync_batch_jobs SET total_items = ? WHERE id = ?");
        if ($upd) {
            $upd->bind_param('ii', $queued, $jobId);
            $upd->execute();
            $upd->close();
        }

        $dbcnx->commit();
        return ['queued' => $queued, 'job_id' => $jobId, 'message' => 'auto-enqueue created'];
    } catch (Throwable $e) {
        $dbcnx->rollback();
        return ['queued' => 0, 'job_id' => 0, 'message' => 'auto-enqueue failed: ' . $e->getMessage()];
    }
}

function system_tasks_run_warehouse_sync_batch_worker(mysqli $dbcnx, array $task, int $systemUserId = 0): array
{
    $payloadRaw = (string)($task['payload_json'] ?? '');
    $payload = $payloadRaw !== '' ? json_decode($payloadRaw, true) : [];
    if (!is_array($payload)) {
        $payload = [];
    }

    $autoEnqueue = !array_key_exists('auto_enqueue', $payload)
        || filter_var($payload['auto_enqueue'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== false;
    $autoEnqueueLimit = max(1, min(500, (int)($payload['auto_enqueue_limit'] ?? 100)));

    $jobRes = $dbcnx->query("SELECT * FROM warehouse_sync_batch_jobs
                            WHERE status IN ('queued','running')
                            ORDER BY FIELD(status, 'running', 'queued'), created_at ASC
                            LIMIT 1");
    if (!$jobRes) {
        return ['status' => 'error', 'message' => 'Failed to fetch batch jobs'];
    }

    $job = $jobRes->fetch_assoc();
    $jobRes->free();

    if (!$job && $autoEnqueue) {
        $enqueueInfo = system_tasks_auto_enqueue_warehouse_sync_batch($dbcnx, $autoEnqueueLimit, $systemUserId > 0 ? $systemUserId : 1);
        if ((int)($enqueueInfo['queued'] ?? 0) > 0) {
            $jobRes = $dbcnx->query("SELECT * FROM warehouse_sync_batch_jobs
                                    WHERE id = " . (int)$enqueueInfo['job_id'] . "
                                    LIMIT 1");
            if ($jobRes) {
                $job = $jobRes->fetch_assoc();
                $jobRes->free();
            }
        }
    }

    if (!$job) {
        return [
            'status' => 'ok',
            'message' => 'No queued batch jobs',
            'context' => [
                'processed' => 0,
                'auto_enqueue' => $autoEnqueue ? 'enabled' : 'disabled',
            ],
        ];
    }

    $jobId = (int)$job['id'];
    if ((string)$job['status'] === 'queued') {
        $stmt = $dbcnx->prepare("UPDATE warehouse_sync_batch_jobs SET status = 'running', started_at = NOW() WHERE id = ? AND status = 'queued'");
        if ($stmt) {
            $stmt->bind_param('i', $jobId);
            $stmt->execute();
            $stmt->close();
        }
    }

    $itemsRes = $dbcnx->query("SELECT * FROM warehouse_sync_batch_job_items
                              WHERE job_id = {$jobId} AND status = 'pending'
                              ORDER BY id ASC
                              LIMIT 20");
    if (!$itemsRes) {
        return ['status' => 'error', 'message' => 'Failed to fetch batch items'];
    }

    $processed = 0;
    $ok = 0;
    $fail = 0;

    $workerConnectorActiveStmt = $dbcnx->prepare("SELECT is_active FROM connectors WHERE id = ? LIMIT 1");

    while ($item = $itemsRes->fetch_assoc()) {
        $processed += 1;
        $itemId = (int)$item['item_id'];
        $connectorId = (int)($item['connector_id'] ?? 0);
        $jobItemId = (int)$item['id'];

        $mark = $dbcnx->prepare("UPDATE warehouse_sync_batch_job_items
                                 SET status = 'running', attempts = attempts + 1, started_at = NOW()
                                 WHERE id = ? AND status = 'pending'");
        if ($mark) {
            $mark->bind_param('i', $jobItemId);
            $mark->execute();
            $changed = $mark->affected_rows > 0;
            $mark->close();
            if (!$changed) {
                continue;
            }
        }

        if ($connectorId > 0 && $workerConnectorActiveStmt) {
            $workerConnectorActiveStmt->bind_param('i', $connectorId);
            $workerConnectorActiveStmt->execute();
            $workerConnectorRes = $workerConnectorActiveStmt->get_result();
            $workerConnectorRow = $workerConnectorRes ? $workerConnectorRes->fetch_assoc() : null;
            if ($workerConnectorRes) {
                $workerConnectorRes->free();
            }

            if ((int)($workerConnectorRow['is_active'] ?? 0) !== 1) {
                $fail += 1;
                $inactiveMsg = 'Коннектор не активен, синхронизация пропущена';
                $updInactive = $dbcnx->prepare("UPDATE warehouse_sync_batch_job_items SET status = 'error', message = ?, finished_at = NOW() WHERE id = ?");
                if ($updInactive) {
                    $updInactive->bind_param('si', $inactiveMsg, $jobItemId);
                    $updInactive->execute();
                    $updInactive->close();
                }
                continue;
            }
        }

        $_SESSION['user'] = ['id' => $systemUserId > 0 ? $systemUserId : 1, 'role' => 'ADMIN'];
        $_POST = [
            'action' => 'warehouse_sync_item',
            'item_id' => (string)$itemId,
            'connector_id' => (string)$connectorId,
        ];
        $action = 'warehouse_sync_item';
        $user = $_SESSION['user'];
        $response = ['status' => 'error', 'message' => 'Unknown'];

        require __DIR__ . '/../warehouse/warehouse_sync_actions.php';

        $isOk = (string)($response['status'] ?? '') === 'ok';
        $msg = (string)($response['message'] ?? '');

        if ($isOk) {
            $ok += 1;
            $upd = $dbcnx->prepare("UPDATE warehouse_sync_batch_job_items SET status = 'ok', message = ?, finished_at = NOW() WHERE id = ?");
        } else {
            $fail += 1;
            $upd = $dbcnx->prepare("UPDATE warehouse_sync_batch_job_items SET status = 'error', message = ?, finished_at = NOW() WHERE id = ?");
        }
        if ($upd) {
            $upd->bind_param('si', $msg, $jobItemId);
            $upd->execute();
            $upd->close();
        }
    }
    if ($workerConnectorActiveStmt) {
        $workerConnectorActiveStmt->close();
    }
    $itemsRes->free();

    $dbcnx->query("UPDATE warehouse_sync_batch_jobs j
                  JOIN (
                    SELECT job_id,
                           SUM(status IN ('ok','error')) AS processed,
                           SUM(status = 'ok') AS ok_count,
                           SUM(status = 'error') AS fail_count,
                           SUM(status = 'pending') AS pending_count
                    FROM warehouse_sync_batch_job_items
                    WHERE job_id = {$jobId}
                    GROUP BY job_id
                  ) s ON s.job_id = j.id
                  SET j.processed_items = s.processed,
                      j.ok_items = s.ok_count,
                      j.fail_items = s.fail_count,
                      j.status = IF(s.pending_count = 0, 'done', 'running'),
                      j.finished_at = IF(s.pending_count = 0, NOW(), j.finished_at),
                      j.message = CONCAT('processed=', s.processed, ', ok=', s.ok_count, ', fail=', s.fail_count)
                  WHERE j.id = {$jobId}");

    return [
        'status' => 'ok',
        'message' => 'Batch worker executed',
        'context' => ['job_id' => $jobId, 'processed' => $processed, 'ok' => $ok, 'fail' => $fail],
    ];
}
