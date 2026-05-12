<?php

declare(strict_types=1);

// Доступны: $action, $user, $dbcnx, $smarty
//$response = ['status' => 'error', 'message' => 'Unknown connector action'];


$routeActionRaw = isset($action) ? (string)$action : '';
$postActionRaw = isset($_POST['action']) ? (string)$_POST['action'] : '';
$getActionRaw = isset($_GET['action']) ? (string)$_GET['action'] : '';

// Важно: handler должен в первую очередь доверять action, уже отмаршрутизированному в core_api.php.
// Это исключает расхождение между роутером и switch при «грязном» POST/GET.
$normalizedRouteAction = connectors_resolve_action_alias(connectors_normalize_action($routeActionRaw));
$normalizedPostAction = connectors_resolve_action_alias(connectors_normalize_action($postActionRaw));
$normalizedGetAction = connectors_resolve_action_alias(connectors_normalize_action($getActionRaw));

// Для switch используем максимально «сырой», но уже отмаршрутизированный action (route-first),
// чтобы не ломать совпадение case из-за агрессивной нормализации.
$dispatchAction = $routeActionRaw !== '' ? trim($routeActionRaw) : trim(($postActionRaw !== '' ? $postActionRaw : $getActionRaw));
$dispatchAction = connectors_resolve_action_alias($dispatchAction);

$normalizedAction = $normalizedRouteAction !== '' ? $normalizedRouteAction : ($normalizedPostAction !== '' ? $normalizedPostAction : $normalizedGetAction);
$incomingAction = $routeActionRaw !== '' ? $routeActionRaw : ($postActionRaw !== '' ? $postActionRaw : $getActionRaw);

if ($normalizedRouteAction !== '' && $normalizedPostAction !== '' && $normalizedRouteAction !== $normalizedPostAction) {
    error_log('connector_actions action mismatch route_vs_post: route=' . $normalizedRouteAction . '; post=' . $normalizedPostAction . '; post_hex=' . bin2hex((string)$postActionRaw));
}

$response = ['status' => 'error', 'message' => 'Unknown connector action: ' . $normalizedAction];

require_once __DIR__ . '/connector_engine.php';
require_once __DIR__ . '/subrunners/connector_modules.php';

final class ConnectorStepLogException extends RuntimeException
{
    /** @var array<int,array<string,mixed>> */
    private array $stepLog;
    private string $artifactsDir;
    /** @var array<string,mixed> */
    private array $context;

    public function __construct(string $message, array $stepLog = [], int $code = 0, ?Throwable $previous = null, string $artifactsDir = '', array $context = [])
    {
        parent::__construct($message, $code, $previous);
        $this->stepLog = $stepLog;
        $this->artifactsDir = trim($artifactsDir);
        $this->context = $context;
    }

    /** @return array<int,array<string,mixed>> */
    public function getStepLog(): array
    {
        return $this->stepLog;
    }

    public function getArtifactsDir(): string
    {
        return $this->artifactsDir;
    }
    /** @return array<string,mixed> */
    public function getContext(): array
    {
        return $this->context;
    }
}

function connectors_normalize_action($action): string
{
    $normalized = trim((string)$action);

    // byte-level cleanup: remove ASCII control chars even if input has broken UTF-8
    $normalized = preg_replace('/[\x00-\x1F\x7F]/', '', $normalized) ?? $normalized;
    // remove common UTF-8 invisible chars by raw byte sequences
    $normalized = str_replace(["\xC2\xA0", "\xE2\x80\x8B", "\xE2\x80\x8C", "\xE2\x80\x8D", "\xE2\x81\xA0", "\xEF\xBB\xBF"], '', $normalized);
    $normalized = preg_replace('/\s+/u', '', $normalized) ?? preg_replace('/\s+/', '', $normalized) ?? $normalized;
    $normalized = strtolower($normalized);

    return preg_replace('/[^a-z0-9_.-]/', '', $normalized) ?? $normalized;
}

function connectors_resolve_action_alias(string $action): string
{
    if (preg_match('/^test[_.-]*connector[_.-]*operations$/i', $action)) {
        return 'test_connector_operations';
    }

    static $aliases = [
        'testconnectoroperations' => 'test_connector_operations',
    ];

    return $aliases[$action] ?? $action;
}

function connectors_ensure_schema(mysqli $dbcnx): void
{
    $sql = "
        CREATE TABLE IF NOT EXISTS connectors (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(128) NOT NULL,
            countries VARCHAR(255) NOT NULL DEFAULT '',
            base_url VARCHAR(255) NOT NULL DEFAULT '',
            auth_type VARCHAR(32) NOT NULL DEFAULT 'login',
            auth_username VARCHAR(128) NOT NULL DEFAULT '',
            auth_password VARCHAR(255) NOT NULL DEFAULT '',
            api_token VARCHAR(255) NOT NULL DEFAULT '',
            auth_token TEXT NULL,
            auth_token_expires_at DATETIME NULL,
            auth_cookies TEXT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            last_sync_at DATETIME NULL,
            last_success_at DATETIME NULL,
            last_error TEXT NULL,
            ssl_ignore TINYINT(1) NOT NULL DEFAULT 0,
            scenario_json TEXT NULL,
            last_manual_confirm_at DATETIME NULL,
            last_manual_confirm_by INT NULL,
            notes TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ";

    if (!$dbcnx->query($sql)) {
        error_log('connectors schema create error: ' . $dbcnx->error);
    }

    $columnsToEnsure = [
        'scenario_json' => "ALTER TABLE connectors ADD COLUMN scenario_json TEXT NULL AFTER last_error",
        'auth_token' => "ALTER TABLE connectors ADD COLUMN auth_token TEXT NULL AFTER api_token",
        'auth_token_expires_at' => "ALTER TABLE connectors ADD COLUMN auth_token_expires_at DATETIME NULL AFTER auth_token",
        'auth_cookies' => "ALTER TABLE connectors ADD COLUMN auth_cookies TEXT NULL AFTER auth_token_expires_at",
        'last_manual_confirm_at' => "ALTER TABLE connectors ADD COLUMN last_manual_confirm_at DATETIME NULL AFTER scenario_json",
        'last_manual_confirm_by' => "ALTER TABLE connectors ADD COLUMN last_manual_confirm_by INT NULL AFTER last_manual_confirm_at",
        'operations_json' => "ALTER TABLE connectors ADD COLUMN operations_json LONGTEXT NULL AFTER scenario_json",
        'is_test_connector' => "ALTER TABLE connectors ADD COLUMN is_test_connector TINYINT(1) NOT NULL DEFAULT 0 AFTER notes",
    ];

    foreach ($columnsToEnsure as $column => $alterSql) {
        $columnCheck = $dbcnx->query("SHOW COLUMNS FROM connectors LIKE '{$column}'");
        if ($columnCheck instanceof mysqli_result) {
            if ($columnCheck->num_rows === 0) {
                if (!$dbcnx->query($alterSql)) {
                    error_log('connectors schema alter error: ' . $dbcnx->error);
                }
            }
            $columnCheck->free();
        } elseif ($columnCheck === false) {
            error_log('connectors schema check error: ' . $dbcnx->error);
        }
    }



    $addonsSql = "
        CREATE TABLE IF NOT EXISTS connectors_addons (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            connector_id INT UNSIGNED NOT NULL,
            connector_name VARCHAR(128) NOT NULL DEFAULT '',
            addons_json LONGTEXT NULL,
            node_mapping_json LONGTEXT NULL,
            status_targets_json LONGTEXT NULL,
            report_out_statuses_json LONGTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_connector_id (connector_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ";

    if (!$dbcnx->query($addonsSql)) {
        error_log('connectors_addons schema create error: ' . $dbcnx->error);
    }

    $addonsColumnsToEnsure = [
        'status_targets_json' => "ALTER TABLE connectors_addons ADD COLUMN status_targets_json LONGTEXT NULL AFTER node_mapping_json",
        'report_out_statuses_json' => "ALTER TABLE connectors_addons ADD COLUMN report_out_statuses_json LONGTEXT NULL AFTER status_targets_json",
    ];

    foreach ($addonsColumnsToEnsure as $column => $alterSql) {
        $columnCheck = $dbcnx->query("SHOW COLUMNS FROM connectors_addons LIKE '{$column}'");
        if ($columnCheck instanceof mysqli_result) {
            if ($columnCheck->num_rows === 0) {
                if (!$dbcnx->query($alterSql)) {
                    error_log('connectors_addons schema alter error: ' . $dbcnx->error);
                }
            }
            $columnCheck->free();
        } elseif ($columnCheck === false) {
            error_log('connectors_addons schema check error: ' . $dbcnx->error);
        }
    }
}

function connectors_default_addons(): array
{
    return [
        'tariff_type' => '1',
        'category' => '',
        'extra_json' => '',
        'node_mapping_json' => '',
        'status_targets_json' => '',
        'report_out_statuses_json' => '',
    ];
}

function connectors_decode_addons(array $row): array
{
    $addons = connectors_default_addons();

    $rawAddons = trim((string)($row['addons_json'] ?? ''));
    if ($rawAddons !== '') {
        $decoded = json_decode($rawAddons, true);
        if (is_array($decoded)) {
            $tariffType = trim((string)($decoded['tariff_type'] ?? '1'));
            $addons['tariff_type'] = in_array($tariffType, ['1', '2', '3'], true) ? $tariffType : '1';
            $addons['category'] = trim((string)($decoded['category'] ?? ''));

            if (isset($decoded['extra']) && is_array($decoded['extra']) && !empty($decoded['extra'])) {
                $addons['extra_json'] = json_encode($decoded['extra'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '';
            }
        }
    }

    $rawMapping = trim((string)($row['node_mapping_json'] ?? ''));
    if ($rawMapping !== '') {
        $decodedMapping = json_decode($rawMapping, true);
        if (is_array($decodedMapping) && !empty($decodedMapping)) {
            $addons['node_mapping_json'] = json_encode($decodedMapping, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '';
        }
    }


    $rawStatusTargets = trim((string)($row['status_targets_json'] ?? ''));
    if ($rawStatusTargets !== '') {
        $decodedStatusTargets = json_decode($rawStatusTargets, true);
        if (is_array($decodedStatusTargets) && !empty($decodedStatusTargets)) {
            $addons['status_targets_json'] = json_encode($decodedStatusTargets, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '';
        }
    }

    $rawReportOutStatuses = trim((string)($row['report_out_statuses_json'] ?? ''));
    if ($rawReportOutStatuses !== '') {
        $decodedReportOutStatuses = json_decode($rawReportOutStatuses, true);
        if (is_array($decodedReportOutStatuses) && !empty($decodedReportOutStatuses)) {
            $addons['report_out_statuses_json'] = json_encode($decodedReportOutStatuses, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '';
        }
    }

    return $addons;
}

function connectors_fetch_addons(mysqli $dbcnx, int $connectorId): array
{
    $addons = connectors_default_addons();
    $stmt = $dbcnx->prepare('SELECT addons_json, node_mapping_json, status_targets_json, report_out_statuses_json FROM connectors_addons WHERE connector_id = ? LIMIT 1');
    if (!$stmt) {
        error_log('connectors addons fetch prepare error: ' . $dbcnx->error);
        return $addons;
    }

    $stmt->bind_param('i', $connectorId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if (!$row) {
        return $addons;
    }

    return connectors_decode_addons($row);
}

function connectors_build_addons_payload_from_post(): array
{
    $tariffType = trim((string)($_POST['addon_tariff_type'] ?? '1'));
    if (!in_array($tariffType, ['1', '2', '3'], true)) {
        $tariffType = '1';
    }

    $category = trim((string)($_POST['addon_category'] ?? ''));
    $extraJsonRaw = trim((string)($_POST['addon_extra_json'] ?? ''));
    $nodeMappingJsonRaw = trim((string)($_POST['addon_node_mapping_json'] ?? ''));
    $statusTargetsJsonRaw = trim((string)($_POST['addon_status_targets_json'] ?? ''));
    $reportOutStatusesJsonRaw = trim((string)($_POST['addon_report_out_statuses_json'] ?? ''));

    $extra = [];
    if ($extraJsonRaw !== '') {
        $decodedExtra = json_decode($extraJsonRaw, true);
        if (!is_array($decodedExtra)) {
            throw new InvalidArgumentException('Некорректный JSON в "Дополнения (extra)"');
        }
        $extra = $decodedExtra;
    }

    $nodeMapping = [];
    if ($nodeMappingJsonRaw !== '') {
        $decodedMapping = json_decode($nodeMappingJsonRaw, true);
        if (!is_array($decodedMapping)) {
            throw new InvalidArgumentException('Некорректный JSON в "Node mapping"');
        }
        $nodeMapping = $decodedMapping;
    }

    $statusTargets = [];
    if ($statusTargetsJsonRaw !== '') {
        $decodedStatusTargets = json_decode($statusTargetsJsonRaw, true);
        if (!is_array($decodedStatusTargets)) {
            throw new InvalidArgumentException('Некорректный JSON в "Карта статусов -> таблица"');
        }
        foreach ($decodedStatusTargets as $status => $targetTable) {
            $statusKey = trim((string)$status);
            $target = trim((string)$targetTable);
            if ($statusKey === '' || $target === '') {
                continue;
            }
            $statusTargets[$statusKey] = $target;
        }
    }

    $reportOutStatuses = [];
    if ($reportOutStatusesJsonRaw !== '') {
        $decodedReportOutStatuses = json_decode($reportOutStatusesJsonRaw, true);
        if (!is_array($decodedReportOutStatuses)) {
            throw new InvalidArgumentException('Некорректный JSON в "Карта статусов репорта -> warehouse_item_out.status"');
        }

        $allowedOutStatuses = ['error', 'for_sync', 'half_sync', 'confirmed_sync', 'to_send', 'sended', 'success'];
        foreach ($decodedReportOutStatuses as $reportStatus => $outStatus) {
            $reportStatusKey = trim((string)$reportStatus);
            $normalizedOutStatus = strtolower(trim((string)$outStatus));
            if ($reportStatusKey === '' || $normalizedOutStatus === '') {
                continue;
            }
            if (!in_array($normalizedOutStatus, $allowedOutStatuses, true)) {
                throw new InvalidArgumentException('Недопустимый warehouse_item_out.status в карте статусов репорта: ' . $normalizedOutStatus);
            }
            $reportOutStatuses[$reportStatusKey] = $normalizedOutStatus;
        }
    }

    return [
        'addons' => [
            'tariff_type' => $tariffType,
            'category' => $category,
            'extra' => $extra,
        ],
        'node_mapping' => $nodeMapping,
        'status_targets' => $statusTargets,
        'report_out_statuses' => $reportOutStatuses,
    ];
}
function connectors_build_status(array $connector): array
{
    $isActive = (int)($connector['is_active'] ?? 0) === 1;
    $lastError = trim((string)($connector['last_error'] ?? ''));

    if (!$isActive) {
        $connector['status_label'] = 'Отключен';
        $connector['status_class'] = 'secondary';
        return $connector;
    }

    if ($lastError !== '') {
        $connector['status_label'] = 'Ошибка';
        $connector['status_class'] = 'danger';
        return $connector;
    }

    if (!empty($connector['last_success_at'])) {
        $connector['status_label'] = 'Работает';
        $connector['status_class'] = 'success';
        return $connector;
    }

    $connector['status_label'] = 'Ожидание';
    $connector['status_class'] = 'warning';
    return $connector;
}

function connectors_fetch_one(mysqli $dbcnx, int $connectorId): ?array
{
    $sql = "SELECT
                id,
                name,
                countries,
                base_url,
                auth_type,
                auth_username,
                auth_password,
                api_token,
                auth_token,
                auth_token_expires_at,
                auth_cookies,
                is_active,
                last_sync_at,
                last_success_at,
                last_error,
                ssl_ignore,
                scenario_json,
                operations_json,
                last_manual_confirm_at,
                last_manual_confirm_by,
                notes,
                is_test_connector,
                created_at,
                updated_at
            FROM connectors
            WHERE id = ?
            LIMIT 1";
    $stmt = $dbcnx->prepare($sql);
    if (!$stmt) {
        error_log('connectors fetch prepare error: ' . $dbcnx->error);
        return null;
    }
    $stmt->bind_param('i', $connectorId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if (!is_array($row)) {
        return null;
    }
    return connectors_try_migrate_operations_json($row);
}




function connectors_default_operations(array $connector): array
{
    $defaultTarget = '';
    $name = strtolower(trim((string)($connector['name'] ?? '')));
    $countries = strtolower(trim((string)($connector['countries'] ?? '')));

    if ($name !== '' && $countries !== '') {
        $country = trim(explode(',', $countries)[0]);
        $country = preg_replace('/[^a-z0-9_]+/', '_', $country ?? '');
        $name = preg_replace('/[^a-z0-9_]+/', '_', $name);
        $defaultTarget = trim($name . '_' . $country, '_');
    }

    return [
        'report' => [
            'schema_version' => 2,
            'operation_id' => 'report',
            'display_name' => 'Операция #1 (report)',
            'module' => 'connectors',
            'action' => 'sync_connector_report',
            'kind' => 'browser_steps',
            'run_after' => [],
            'run_with' => [],
            'run_finally' => [],
            'entrypoint' => 1,
            'on_dependency_fail' => 'stop',
            'enabled' => 0,
            'page_url' => '',
            'file_extension' => 'xlsx',
            'download_mode' => 'browser',
            'log_steps' => 0,
            'steps_json' => '',
            'curl_config_json' => '',
            'target_table' => $defaultTarget,
            'field_mapping_json' => '',
        ],
        'submission' => [
            'schema_version' => 2,
            'operation_id' => 'submission',
            'display_name' => 'Операция #2 (submission)',
            'module' => 'connectors',
            'action' => 'submit_connector_shipment',
            'kind' => 'browser_steps',
            'run_after' => [],
            'run_with' => [],
            'run_finally' => [],
            'entrypoint' => 0,
            'on_dependency_fail' => 'stop',
            'enabled' => 0,
            'page_url' => '',
            'log_steps' => 0,
            'steps_json' => '',
            'request_config_json' => '',
            'success_selector' => '',
            'success_text' => '',
            'error_selector' => '',
        ],
        'track_and_label_info' => [
            'schema_version' => 2,
            'operation_id' => 'track_and_label_info',
            'display_name' => 'Операция #3 (track_and_label_info)',
            'module' => 'connectors',
            'action' => 'track_and_label_info',
            'kind' => 'noop',
            'run_after' => [],
            'run_with' => [],
            'run_finally' => [],
            'entrypoint' => 0,
            'on_dependency_fail' => 'stop',
            'enabled' => 0,
        ],
    ];
}


function connectors_normalize_dependency_links($value): array
{
    if (!is_array($value)) {
        return [];
    }

    $result = [];
    foreach ($value as $item) {
        $id = trim((string)$item);
        if ($id === '') {
            continue;
        }
        if (!preg_match('/^[a-zA-Z0-9_\-\.]+$/', $id)) {
            continue;
        }
        $result[$id] = true;
    }

    return array_keys($result);
}

function connectors_normalize_dependency_policy($value): string
{
    $value = strtolower(trim((string)$value));
    if (!in_array($value, ['stop', 'skip', 'continue'], true)) {
        return 'stop';
    }

    return $value;
}


function connectors_operation_module_registry(): array
{
    return ['warehouse', 'connectors', 'devices', 'tools', 'users', 'system', 'generic'];
}

function connectors_operation_kind_registry(): array
{
    return ['api_call', 'browser_steps', 'script', 'noop', 'subrunner', 'php_report'];
}

function connectors_operation_config_templates(): array
{
    return [
        'php_report' => [
            'description' => 'Download + import через PHP runtime (без браузера).',
            'operation' => [
                'operation_id' => 'report_php',
                'display_name' => 'Report (PHP)',
                'module' => 'connectors',
                'kind' => 'php_report',
                'enabled' => 1,
                'entrypoint' => 1,
                'on_dependency_fail' => 'stop',
                'run_after' => [],
                'run_with' => [],
                'run_finally' => [],
                'config' => [
                    'from' => '2026-03-01',
                    'to' => '2026-03-25',
                    'target_table' => 'connector_report_table',
                    'download' => [
                        'url' => 'https://example.com/report',
                        'method' => 'GET',
                        'timeout_sec' => 120,
                    ],
                    'import' => [
                        'enabled' => 1,
                        'file_extension' => 'xlsx',
                        'field_mapping' => [],
                    ],
                ],
            ],
        ],
        'script_php' => [
            'description' => 'Внешний PHP-скрипт для custom-flow (логин/cURL/парсинг/импорт).',
            'operation' => [
                'operation_id' => 'report_script_php',
                'display_name' => 'Report (script+php)',
                'module' => 'connectors',
                'kind' => 'script',
                'enabled' => 1,
                'entrypoint' => 1,
                'on_dependency_fail' => 'stop',
                'run_after' => [],
                'run_with' => [],
                'run_finally' => [],
                'config' => [
                    'interpreter' => 'php',
                    'script_path' => 'www/scripts/mvp/app/Forwarder/run_report.php',
                    'timeout_sec' => 180,
                    'args' => [
                        '--from={{from}}',
                        '--to={{to}}',
                        '--target_table={{target_table}}',
                    ],
                ],
            ],
        ],
        'flight_list_php' => [
            'description' => 'Flight list через PHP runtime + import/update (upsert) в target_table.',
            'operation' => [
                'operation_id' => 'flight_list_php',
                'display_name' => 'Flight list (PHP)',
                'module' => 'connectors',
                'kind' => 'script',
                'enabled' => 1,
                'entrypoint' => 0,
                'on_dependency_fail' => 'stop',
                'run_after' => [],
                'run_with' => [],
                'run_finally' => [],
                'config' => [
                    'interpreter' => 'php',
                    'script_path' => 'www/scripts/mvp/app/Forwarder/run_flight_list.php',
                    'target_table' => 'connector_dev_colibri_operation_flight_list',
                    'timeout_sec' => 180,
                    'args' => [
                        '--base-url={{base_url}}',
                        '--login={{auth_username}}',
                        '--password={{auth_password}}',
                        '--page-path=/collector/flights',
                        '--connector-id={{connector_id}}',
                        '--target-table={{target_table}}',
                        '--write-mode=upsert',
                    ],
                ],
            ],
        ],
        'browser_steps' => [
            'description' => 'Node fallback для браузерных flow (DOM/клики/JS-рендер).',
            'operation' => [
                'operation_id' => 'report_browser',
                'display_name' => 'Report (browser)',
                'module' => 'connectors',
                'kind' => 'browser_steps',
                'enabled' => 1,
                'entrypoint' => 1,
                'on_dependency_fail' => 'stop',
                'run_after' => [],
                'run_with' => [],
                'run_finally' => [],
                'config' => [
                    'page_url' => 'https://example.com/login',
                    'expect_download' => 1,
                    'target_table' => 'connector_report_table',
                    'steps' => [
                        ['action' => 'goto', 'url' => 'https://example.com/login'],
                        ['action' => 'waitForSelector', 'selector' => '#username'],
                        ['action' => 'type', 'selector' => '#username', 'text' => '{{auth_username}}'],
                        ['action' => 'type', 'selector' => '#password', 'text' => '{{auth_password}}'],
                        ['action' => 'click', 'selector' => 'button[type=submit]'],
                    ],
                ],
            ],
        ],
    ];
}
function connectors_extract_module_from_handler_path(string $handlerPath): string
{
    if (preg_match('#^api/([^/]+)/#', trim($handlerPath), $matches)) {
        return strtolower(trim((string)$matches[1]));
    }

    return 'generic';
}

function connectors_core_api_action_module_registry(): array
{
    static $registry = null;
    if (is_array($registry)) {
        return $registry;
    }

    $registry = [];
    $coreApiPath = __DIR__ . '/../../core_api.php';
    if (!is_file($coreApiPath)) {
        return $registry;
    }

    $contents = (string)file_get_contents($coreApiPath);
    if ($contents === '') {
        return $registry;
    }

    if (preg_match_all("/['\"]([a-zA-Z0-9_.-]+)['\"]\s*=>\s*['\"](api\/[a-zA-Z0-9_\/-]+\.php)['\"]/", $contents, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $action = trim((string)($match[1] ?? ''));
            $handlerPath = trim((string)($match[2] ?? ''));
            if ($action === '' || $handlerPath === '') {
                continue;
            }

            $registry[$action] = connectors_extract_module_from_handler_path($handlerPath);
        }
    }

    return $registry;
}

function connectors_validate_operation_action_module(string $operationId, string $module, string $kind, string $action): void
{
    if ($module === 'generic' || $kind !== 'api_call' || $action === '') {
        return;
    }

    $registry = connectors_core_api_action_module_registry();
    if (!isset($registry[$action])) {
        throw new InvalidArgumentException('Операция "' . $operationId . '": action "' . $action . '" не найден в core_api router');
    }

    $routerModule = (string)$registry[$action];
    if ($routerModule !== $module) {
        throw new InvalidArgumentException('Операция "' . $operationId . '": module="' . $module . '" не совпадает с модулем action "' . $action . '" из router ("' . $routerModule . '")');
    }
}

function connectors_is_valid_operation_module(string $module): bool
{
    return in_array($module, connectors_operation_module_registry(), true);
}

function connectors_is_valid_operation_kind(string $kind): bool
{
    return in_array($kind, connectors_operation_kind_registry(), true);
}

function connectors_normalize_operation_module($value): string
{
    $module = strtolower(trim((string)$value));
    if ($module === '') {
        return 'generic';
    }

    return $module;
}

function connectors_normalize_operation_kind($value, string $module = 'generic'): string
{
    $kind = strtolower(trim((string)$value));
    if ($kind === '' && $module === 'generic') {
        return 'browser_steps';
    }

    return $kind;
}

function connectors_is_v3_operations_payload(array $payload): bool
{
    return (int)($payload['schema_version'] ?? 0) === 3
        && isset($payload['operations'])
        && is_array($payload['operations']);
}

function connectors_v3_payload_to_runtime_operations(array $payload): array
{
    $result = [];
    $operations = $payload['operations'] ?? [];
    if (!is_array($operations)) {
        return $result;
    }

    foreach ($operations as $operation) {
        if (!is_array($operation)) {
            continue;
        }

        $operationId = trim((string)($operation['operation_id'] ?? ''));
        if ($operationId === '') {
            continue;
        }

        $module = connectors_normalize_operation_module($operation['module'] ?? 'generic');
        $kind = connectors_normalize_operation_kind($operation['kind'] ?? '', $module);
        $config = isset($operation['config']) && is_array($operation['config']) ? $operation['config'] : [];

        $result[$operationId] = [
            'schema_version' => 3,
            'operation_id' => $operationId,
            'display_name' => trim((string)($operation['display_name'] ?? $operationId)),
            'module' => $module,
            'action' => trim((string)($operation['action'] ?? '')),
            'kind' => $kind,
            'run_after' => connectors_normalize_dependency_links($operation['run_after'] ?? []),
            'run_with' => connectors_normalize_dependency_links($operation['run_with'] ?? []),
            'run_finally' => connectors_normalize_dependency_links($operation['run_finally'] ?? []),
            'entrypoint' => !empty($operation['entrypoint']) ? 1 : 0,
            'on_dependency_fail' => connectors_normalize_dependency_policy($operation['on_dependency_fail'] ?? 'stop'),
            'enabled' => !empty($operation['enabled']) ? 1 : 0,
            'config' => $config,
        ];
    }

    return $result;
}


function connectors_build_v3_operation_payload(array $operation): array
{
    $operationId = trim((string)($operation['operation_id'] ?? ''));
    $module = connectors_normalize_operation_module($operation['module'] ?? 'generic');
    $kind = connectors_normalize_operation_kind($operation['kind'] ?? '', $module);
    $displayName = trim((string)($operation['display_name'] ?? $operationId));

    if ($displayName === '') {
        $displayName = $operationId;
    }

    $config = isset($operation['config']) && is_array($operation['config']) ? $operation['config'] : [];

    return [
        'operation_id' => $operationId,
        'display_name' => $displayName,
        'module' => $module,
        'action' => trim((string)($operation['action'] ?? '')),
        'kind' => $kind,
        'enabled' => !empty($operation['enabled']) ? 1 : 0,
        'entrypoint' => !empty($operation['entrypoint']) ? 1 : 0,
        'on_dependency_fail' => connectors_normalize_dependency_policy($operation['on_dependency_fail'] ?? 'stop'),
        'run_after' => connectors_normalize_dependency_links($operation['run_after'] ?? []),
        'run_with' => connectors_normalize_dependency_links($operation['run_with'] ?? []),
        'run_finally' => connectors_normalize_dependency_links($operation['run_finally'] ?? []),
        'config' => $config,
    ];
}

function connectors_legacy_operations_to_v3_payload(array $payload): array
{
    $operations = [];

    foreach ($payload as $operationKey => $operation) {
        if (!is_array($operation)) {
            continue;
        }

        $operationId = trim((string)($operation['operation_id'] ?? $operationKey));
        if ($operationId === '') {
            continue;
        }

        $module = connectors_normalize_operation_module($operation['module'] ?? 'connectors');
        $kind = connectors_normalize_operation_kind($operation['kind'] ?? '', $module);
        $displayName = trim((string)($operation['display_name'] ?? ''));
        if ($displayName === '') {
            $displayName = 'Операция ' . $operationId;
        }

        $config = [];
        foreach ([
            'page_url', 'file_extension', 'download_mode', 'log_steps', 'steps', 'curl_config',
            'target_table', 'field_mapping', 'request_config', 'success_selector', 'success_text', 'error_selector',
        ] as $configKey) {
            if (array_key_exists($configKey, $operation)) {
                $config[$configKey] = $operation[$configKey];
            }
        }

        $operations[] = connectors_build_v3_operation_payload([
            'operation_id' => $operationId,
            'display_name' => $displayName,
            'module' => $module,
            'action' => trim((string)($operation['action'] ?? '')),
            'kind' => $kind,
            'enabled' => !empty($operation['enabled']) ? 1 : 0,
            'entrypoint' => !empty($operation['entrypoint']) ? 1 : 0,
            'on_dependency_fail' => $operation['on_dependency_fail'] ?? 'stop',
            'run_after' => $operation['run_after'] ?? [],
            'run_with' => $operation['run_with'] ?? [],
            'run_finally' => $operation['run_finally'] ?? [],
            'config' => $config,
        ]);
    }

    return [
        'schema_version' => 3,
        'operations' => $operations,
    ];
}

function connectors_validate_v3_operations_payload(array $operationsPayload): void
{
    if ((int)($operationsPayload['schema_version'] ?? 0) !== 3) {
        throw new InvalidArgumentException('Для нового формата ожидается schema_version = 3');
    }

    if (!isset($operationsPayload['operations']) || !is_array($operationsPayload['operations']) || $operationsPayload['operations'] === []) {
        throw new InvalidArgumentException('Операции v3 должны содержать непустой массив operations');
    }

    $runtimeOperations = connectors_v3_payload_to_runtime_operations($operationsPayload);
    if ($runtimeOperations === []) {
        throw new InvalidArgumentException('Операции v3 не содержат валидных operation_id');
    }


    $seenOperationIds = [];
    foreach ($operationsPayload['operations'] as $index => $operation) {
        if (!is_array($operation)) {
            continue;
        }
        $operationId = trim((string)($operation['operation_id'] ?? ''));
        if ($operationId === '') {
            continue;
        }
        if (isset($seenOperationIds[$operationId])) {
            throw new InvalidArgumentException('Дублирующийся operation_id в operations_v3_json: "' . $operationId . '" (операции #' . $seenOperationIds[$operationId] . ' и #' . ($index + 1) . ')');
        }
        $seenOperationIds[$operationId] = $index + 1;
    }

    foreach ($operationsPayload['operations'] as $index => $operation) {
        if (!is_array($operation)) {
            throw new InvalidArgumentException('Операция #' . ($index + 1) . ': ожидается JSON-объект');
        }

        $operationId = trim((string)($operation['operation_id'] ?? ''));
        if ($operationId === '') {
            throw new InvalidArgumentException('Операция #' . ($index + 1) . ': operation_id обязателен');
        }


        $displayName = trim((string)($operation['display_name'] ?? ''));
        if ($displayName === '') {
            throw new InvalidArgumentException('Операция "' . $operationId . '": display_name обязателен');
        }

        foreach (['run_after', 'run_with', 'run_finally'] as $dependencyField) {
            if (!array_key_exists($dependencyField, $operation) || !is_array($operation[$dependencyField])) {
                throw new InvalidArgumentException('Операция "' . $operationId . '": поле ' . $dependencyField . ' должно быть JSON-массивом');
            }
        }

        if (!array_key_exists('config', $operation) || !is_array($operation['config'])) {
            throw new InvalidArgumentException('Операция "' . $operationId . '": config должен быть JSON-объектом');
        }

        $module = connectors_normalize_operation_module($operation['module'] ?? 'generic');
        $kind = connectors_normalize_operation_kind($operation['kind'] ?? '', $module);
        $action = trim((string)($operation['action'] ?? ''));


        if (!connectors_is_valid_operation_module($module)) {
            throw new InvalidArgumentException('Операция "' . $operationId . '": module должен входить в справочник [' . implode(', ', connectors_operation_module_registry()) . ']');
        }

        if (!connectors_is_valid_operation_kind($kind)) {
            throw new InvalidArgumentException('Операция "' . $operationId . '": kind должен входить в справочник [' . implode(', ', connectors_operation_kind_registry()) . ']');
        }

        if ($module === 'generic' && $action === '') {
            // ok for generic/browser steps
        } elseif ($kind === 'api_call' && $action === '') {
            throw new InvalidArgumentException('Операция "' . $operationId . '": для kind=api_call поле action обязательно');
        }
        connectors_validate_operation_action_module($operationId, $module, $kind, $action);
    }


    connectors_validate_operations_runtime($runtimeOperations);

    foreach ($runtimeOperations as $operation) {
        if (!empty($operation['entrypoint']) && !empty($operation['enabled'])) {
            connectors_build_execution_plan($runtimeOperations, (string)($operation['operation_id'] ?? ''));
        }
    }
}

function connectors_dependency_graph_rollout_mode(): string
{
    $mode = strtolower(trim((string)(getenv('CONNECTORS_DEPENDENCY_GRAPH_ROLLOUT') ?: 'test_only')));
    if (!in_array($mode, ['off', 'test_only', 'all'], true)) {
        return 'test_only';
    }

    return $mode;
}

function connectors_is_test_connector(array $connector): bool
{
    return (int)($connector['is_test_connector'] ?? 0) === 1;
}


function connectors_build_graph_error(string $runId, int $connectorId, string $entrypoint, string $errorCode, array $details = []): array
{
    return [
        'run_id' => $runId,
        'connector_id' => $connectorId,
        'entrypoint' => $entrypoint,
        'error_code' => $errorCode,
        'details' => $details,
    ];
}

function connectors_resolve_graph_error_code(string $message): string
{
    if (mb_stripos($message, 'Дублирующийся operation_id') !== false) {
        return 'duplicate_operation_id';
    }
    if (mb_stripos($message, 'не найдена') !== false) {
        return 'missing_dependency';
    }
    if (mb_stripos($message, 'ссылка на саму себя') !== false) {
        return 'self_dependency';
    }
    if (mb_stripos($message, 'неактивна') !== false) {
        return 'inactive_dependency';
    }
    if (mb_stripos($message, 'циклическая зависимость') !== false || mb_stripos($message, 'цикл в run_with/run_after') !== false) {
        return 'dependency_cycle';
    }
    if (mb_stripos($message, 'Entrypoint операция не найдена') !== false || mb_stripos($message, 'Не найден entrypoint') !== false) {
        return 'entrypoint_not_found';
    }

    return 'invalid_graph';
}

function connectors_is_dependency_graph_enabled(array $connector): bool
{
    $rolloutMode = connectors_dependency_graph_rollout_mode();
    if ($rolloutMode === 'off') {
        return false;
    }

    if ($rolloutMode === 'all') {
        return true;
    }

    return connectors_is_test_connector($connector);
}

function connectors_decode_dependency_links_json(string $raw, string $fieldLabel): array
{
    $raw = trim($raw);
    if ($raw === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new InvalidArgumentException('Некорректный JSON в "' . $fieldLabel . '"');
    }

    return connectors_normalize_dependency_links($decoded);
}


function connectors_validate_single_operation_schema(string $operationKey, array $operation): void
{
    $requiredFields = [
        'schema_version',
        'operation_id',
        'run_after',
        'run_with',
        'run_finally',
        'entrypoint',
        'on_dependency_fail',
        'enabled',
    ];

    foreach ($requiredFields as $field) {
        if (!array_key_exists($field, $operation)) {
            throw new InvalidArgumentException('Операция "' . $operationKey . '": отсутствует обязательное поле "' . $field . '"');
        }
    }

    $schemaVersion = (int)$operation['schema_version'];
    if (!in_array($schemaVersion, [2, 3], true)) {
        throw new InvalidArgumentException('Операция "' . $operationKey . '": поддерживается schema_version = 2|3');
    }

    $operationId = trim((string)$operation['operation_id']);
    if ($operationId === '' || !preg_match('/^[a-zA-Z0-9_\-\.]+$/', $operationId)) {
        throw new InvalidArgumentException('Операция "' . $operationKey . '": некорректный operation_id');
    }

    foreach (['run_after', 'run_with', 'run_finally'] as $dependencyField) {
        if (!is_array($operation[$dependencyField])) {
            throw new InvalidArgumentException('Операция "' . $operationKey . '": поле "' . $dependencyField . '" должно быть массивом');
        }
        foreach ($operation[$dependencyField] as $link) {
            if (!is_string($link) || trim($link) === '' || !preg_match('/^[a-zA-Z0-9_\-\.]+$/', $link)) {
                throw new InvalidArgumentException('Операция "' . $operationKey . '": поле "' . $dependencyField . '" содержит некорректную ссылку');
            }
        }
    }

    $onDependencyFail = strtolower(trim((string)$operation['on_dependency_fail']));
    if (!in_array($onDependencyFail, ['stop', 'skip', 'continue'], true)) {
        throw new InvalidArgumentException('Операция "' . $operationKey . '": поле "on_dependency_fail" должно быть stop|skip|continue');
    }

    if ($schemaVersion === 3) {
        $displayName = trim((string)($operation['display_name'] ?? ''));
        if ($displayName === '') {
            throw new InvalidArgumentException('Операция "' . $operationKey . '": для schema_version=3 поле "display_name" обязательно');
        }

        $module = connectors_normalize_operation_module($operation['module'] ?? '');
        if (!connectors_is_valid_operation_module($module)) {
            throw new InvalidArgumentException('Операция "' . $operationKey . '": module должен входить в справочник [' . implode(', ', connectors_operation_module_registry()) . ']');
        }

        $kind = connectors_normalize_operation_kind($operation['kind'] ?? '', $module);
        if (!connectors_is_valid_operation_kind($kind)) {
            throw new InvalidArgumentException('Операция "' . $operationKey . '": kind должен входить в справочник [' . implode(', ', connectors_operation_kind_registry()) . ']');
        }

        if (!array_key_exists('config', $operation) || !is_array($operation['config'])) {
            throw new InvalidArgumentException('Операция "' . $operationKey . '": для schema_version=3 поле "config" должно быть JSON-объектом');
        }

        $action = trim((string)($operation['action'] ?? ''));
        if ($module !== 'generic' && $kind === 'api_call' && $action === '') {
            throw new InvalidArgumentException('Операция "' . $operationKey . '": для module != generic и kind=api_call поле "action" обязательно');
        }
        connectors_validate_operation_action_module($operationKey, $module, $kind, $action);
    }
}

function connectors_validate_operations_runtime(array $operations): void
{
    $operationIndex = [];
    $dependencies = [];

    foreach ($operations as $operationKey => $operation) {
        if (!is_array($operation)) {
            if (in_array((string)$operationKey, ['schema_version'], true)) {
                continue;
            }
            throw new InvalidArgumentException('Операция "' . $operationKey . '": неверный формат');
        }

        connectors_validate_single_operation_schema((string)$operationKey, $operation);

        $operationId = trim((string)$operation['operation_id']);
        if (isset($operationIndex[$operationId])) {
            throw new InvalidArgumentException('Дублирующийся operation_id: "' . $operationId . '"');
        }

        $operationIndex[$operationId] = [
            'key' => (string)$operationKey,
            'enabled' => !empty($operation['enabled']),
        ];

        $dependencies[$operationId] = [];
        foreach (['run_after', 'run_with', 'run_finally'] as $field) {
            foreach ($operation[$field] as $linkedOperationId) {
                $dependencies[$operationId][] = trim((string)$linkedOperationId);
            }
        }
    }

    foreach ($operations as $operationKey => $operation) {
        if (!is_array($operation)) {
            continue;
        }
        $operationId = trim((string)$operation['operation_id']);
        foreach (['run_after', 'run_with', 'run_finally'] as $field) {
            foreach ($operation[$field] as $linkedOperationIdRaw) {
                $linkedOperationId = trim((string)$linkedOperationIdRaw);

                if (!isset($operationIndex[$linkedOperationId])) {
                    throw new InvalidArgumentException('Операция "' . $operationId . '": ссылка "' . $linkedOperationId . '" в "' . $field . '" не найдена');
                }

                if ($linkedOperationId === $operationId) {
                    throw new InvalidArgumentException('Операция "' . $operationId . '": ссылка на саму себя в "' . $field . '" запрещена');
                }

                if (!$operationIndex[$linkedOperationId]['enabled']) {
                    throw new InvalidArgumentException('Операция "' . $operationId . '": зависимость "' . $linkedOperationId . '" неактивна');
                }
            }
        }
    }

    $adjacency = [];
    foreach (array_keys($dependencies) as $operationId) {
        $adjacency[$operationId] = [];
    }
    foreach ($dependencies as $operationId => $linkedOperationIds) {
        foreach ($linkedOperationIds as $linkedOperationId) {
            if ($linkedOperationId === '' || !isset($adjacency[$linkedOperationId])) {
                continue;
            }
            $adjacency[$linkedOperationId][] = $operationId;
        }
    }

    $stableOrder = [];
    $index = 0;
    foreach ($operations as $operation) {
        if (!is_array($operation)) {
            continue;
        }
        $operationId = trim((string)($operation['operation_id'] ?? ''));
        if ($operationId === '') {
            continue;
        }
        $stableOrder[$operationId] = $index++;
    }
    try {
        connectors_topological_sort_operations(array_keys($dependencies), $adjacency, $stableOrder);
    } catch (InvalidArgumentException $e) {
        throw new InvalidArgumentException('Обнаружен цикл зависимостей между операциями (run_after/run_with/run_finally): ' . $e->getMessage());
    }
}

function connectors_topological_sort_operations(array $nodes, array $adjacency, array $stableOrder): array
{
    $nodeSet = [];
    foreach ($nodes as $nodeId) {
        $nodeSet[(string)$nodeId] = true;
    }

    $indegree = [];
    foreach (array_keys($nodeSet) as $nodeId) {
        $indegree[$nodeId] = 0;
    }

    foreach ($adjacency as $from => $toList) {
        $from = (string)$from;
        if (!isset($nodeSet[$from]) || !is_array($toList)) {
            continue;
        }

        foreach ($toList as $to) {
            $to = (string)$to;
            if (!isset($nodeSet[$to])) {
                continue;
            }
            $indegree[$to] = (int)($indegree[$to] ?? 0) + 1;
        }
    }

    $queue = [];
    foreach ($indegree as $nodeId => $degree) {
        if ((int)$degree === 0) {
            $queue[] = $nodeId;
        }
    }

    usort($queue, static function (string $a, string $b) use ($stableOrder): int {
        $aPos = (int)($stableOrder[$a] ?? PHP_INT_MAX);
        $bPos = (int)($stableOrder[$b] ?? PHP_INT_MAX);
        if ($aPos === $bPos) {
            return strcmp($a, $b);
        }
        return $aPos <=> $bPos;
    });

    $result = [];
    while (!empty($queue)) {
        $current = array_shift($queue);
        $result[] = $current;

        $targets = isset($adjacency[$current]) && is_array($adjacency[$current]) ? $adjacency[$current] : [];
        foreach ($targets as $target) {
            $target = (string)$target;
            if (!isset($nodeSet[$target])) {
                continue;
            }

            $indegree[$target]--;
            if ($indegree[$target] === 0) {
                $queue[] = $target;
            }
        }

        usort($queue, static function (string $a, string $b) use ($stableOrder): int {
            $aPos = (int)($stableOrder[$a] ?? PHP_INT_MAX);
            $bPos = (int)($stableOrder[$b] ?? PHP_INT_MAX);
            if ($aPos === $bPos) {
                return strcmp($a, $b);
            }
            return $aPos <=> $bPos;
        });
    }

    if (count($result) !== count($nodeSet)) {
        throw new InvalidArgumentException('Не удалось построить топологический порядок (обнаружен цикл в подграфе)');
    }

    return $result;
}

function connectors_build_execution_plan(array $operations, ?string $entrypointOperationId = null): array
{
    connectors_validate_operations_runtime($operations);

    $operationsById = [];
    $stableOrder = [];
    $index = 0;

    foreach ($operations as $operationKey => $operation) {
        if (!is_array($operation)) {
            continue;
        }
        $operationId = trim((string)($operation['operation_id'] ?? ''));
        if ($operationId === '' || empty($operation['enabled'])) {
            continue;
        }
        $operationsById[$operationId] = [
            'key' => (string)$operationKey,
            'config' => $operation,
        ];
        $stableOrder[$operationId] = $index++;
    }

    if (empty($operationsById)) {
        throw new InvalidArgumentException('Нет активных операций для построения плана');
    }

    if ($entrypointOperationId !== null) {
        $entrypointOperationId = trim($entrypointOperationId);
    }

    if ($entrypointOperationId === null || $entrypointOperationId === '') {
        foreach ($operationsById as $operationId => $entry) {
            if (!empty($entry['config']['entrypoint'])) {
                $entrypointOperationId = $operationId;
                break;
            }
        }
    }

    if ($entrypointOperationId === null || $entrypointOperationId === '') {
        throw new InvalidArgumentException('Не найден entrypoint среди активных операций');
    }

    if (!isset($operationsById[$entrypointOperationId])) {
        throw new InvalidArgumentException('Entrypoint операция не найдена среди активных: "' . $entrypointOperationId . '"');
    }

    $main = $operationsById[$entrypointOperationId]['config'];
    $mainOperationId = trim((string)$main['operation_id']);

    $duringSet = [];
    $duringQueue = [];
    foreach ((array)($main['run_with'] ?? []) as $duringId) {
        $duringId = trim((string)$duringId);
        if ($duringId !== '') {
            $duringQueue[] = $duringId;
        }
    }

    while (!empty($duringQueue)) {
        $current = array_shift($duringQueue);
        if ($current === $mainOperationId || isset($duringSet[$current])) {
            continue;
        }
        $duringSet[$current] = true;

        $cfg = $operationsById[$current]['config'] ?? null;
        if (!is_array($cfg)) {
            throw new InvalidArgumentException('During-операция не найдена: "' . $current . '"');
        }

        foreach ((array)($cfg['run_with'] ?? []) as $nestedDuringId) {
            $nestedDuringId = trim((string)$nestedDuringId);
            if ($nestedDuringId !== '' && !isset($duringSet[$nestedDuringId])) {
                $duringQueue[] = $nestedDuringId;
            }
        }
    }

    $beforeSet = [];
    $beforeQueue = [$mainOperationId, ...array_keys($duringSet)];
    while (!empty($beforeQueue)) {
        $current = array_shift($beforeQueue);
        $cfg = $operationsById[$current]['config'] ?? null;
        if (!is_array($cfg)) {
            continue;
        }
        foreach ((array)($cfg['run_after'] ?? []) as $dependencyId) {
            $dependencyId = trim((string)$dependencyId);
            if ($dependencyId === '' || $dependencyId === $mainOperationId || isset($duringSet[$dependencyId])) {
                continue;
            }
            if (!isset($beforeSet[$dependencyId])) {
                $beforeSet[$dependencyId] = true;
                $beforeQueue[] = $dependencyId;
            }
        }
    }

    $finallySet = [];
    $finallyQueue = (array)($main['run_finally'] ?? []);
    while (!empty($finallyQueue)) {
        $current = trim((string)array_shift($finallyQueue));
        if ($current === '' || $current === $mainOperationId || isset($duringSet[$current]) || isset($beforeSet[$current])) {
            continue;
        }
        if (isset($finallySet[$current])) {
            continue;
        }

        $finallySet[$current] = true;
        $cfg = $operationsById[$current]['config'] ?? null;
        if (is_array($cfg)) {
            foreach ((array)($cfg['run_finally'] ?? []) as $nestedFinallyId) {
                $nestedFinallyId = trim((string)$nestedFinallyId);
                if ($nestedFinallyId !== '' && !isset($finallySet[$nestedFinallyId])) {
                    $finallyQueue[] = $nestedFinallyId;
                }
            }
        }
    }

    $adjacency = [];
    foreach ($operationsById as $operationId => $entry) {
        $adjacency[$operationId] = [];
    }
    foreach ($operationsById as $operationId => $entry) {
        $cfg = $entry['config'];
        foreach ((array)($cfg['run_after'] ?? []) as $dependencyId) {
            $dependencyId = trim((string)$dependencyId);
            if ($dependencyId !== '' && isset($adjacency[$dependencyId])) {
                $adjacency[$dependencyId][] = $operationId;
            }
        }
    }

    $beforeOrder = connectors_topological_sort_operations(array_keys($beforeSet), $adjacency, $stableOrder);
    $finallyOrder = connectors_topological_sort_operations(array_keys($finallySet), $adjacency, $stableOrder);

    $duringNodes = array_keys($duringSet);
    $duringAdjacency = [];
    foreach ($duringNodes as $nodeId) {
        $duringAdjacency[$nodeId] = [];
    }
    foreach ($duringNodes as $nodeId) {
        $cfg = $operationsById[$nodeId]['config'] ?? [];
        foreach ((array)($cfg['run_after'] ?? []) as $dependencyId) {
            $dependencyId = trim((string)$dependencyId);
            if (isset($duringSet[$dependencyId])) {
                $duringAdjacency[$dependencyId][] = $nodeId;
            }
        }
    }

    $duringGroups = [];
    if (!empty($duringNodes)) {
        $indegree = [];
        foreach ($duringNodes as $nodeId) {
            $indegree[$nodeId] = 0;
        }
        foreach ($duringAdjacency as $from => $toList) {
            foreach ($toList as $toId) {
                $indegree[$toId]++;
            }
        }

        $processed = 0;
        while ($processed < count($duringNodes)) {
            $group = [];
            foreach ($indegree as $nodeId => $degree) {
                if ($degree === 0) {
                    $group[] = $nodeId;
                }
            }

            if (empty($group)) {
                throw new InvalidArgumentException('Не удалось запланировать during-группы: цикл в run_with/run_after');
            }

            usort($group, static function (string $a, string $b) use ($stableOrder): int {
                $aPos = (int)($stableOrder[$a] ?? PHP_INT_MAX);
                $bPos = (int)($stableOrder[$b] ?? PHP_INT_MAX);
                if ($aPos === $bPos) {
                    return strcmp($a, $b);
                }
                return $aPos <=> $bPos;
            });

            $duringGroups[] = $group;
            foreach ($group as $nodeId) {
                $processed++;
                $indegree[$nodeId] = -1;
                foreach ((array)($duringAdjacency[$nodeId] ?? []) as $toId) {
                    $indegree[$toId]--;
                }
            }
        }
    }

    return [
        'entrypoint_operation_id' => $entrypointOperationId,
        'before' => $beforeOrder,
        'main' => $mainOperationId,
        'during' => $duringGroups,
        'during_groups' => $duringGroups,
        'finally' => $finallyOrder,
        'after' => $finallyOrder,
    ];
}

function connectors_build_legacy_execution_plan(string $entrypointOperationId): array
{
    return [
        'entrypoint_operation_id' => $entrypointOperationId,
        'before' => [],
        'main' => $entrypointOperationId,
        'during' => [],
        'finally' => [],
        'legacy_mode' => true,
        'reason' => 'dependency_graph_rollout_disabled',
    ];
}

function connectors_validate_operations_payload(array $operationsPayload): void
{
    if (connectors_is_v3_operations_payload($operationsPayload)) {
        connectors_validate_v3_operations_payload($operationsPayload);
        return;
    }

    if (!isset($operationsPayload['report']) || !isset($operationsPayload['submission'])) {
        throw new InvalidArgumentException('Операции должны содержать как минимум report и submission');
    }

    connectors_validate_operations_runtime($operationsPayload);

    foreach ($operationsPayload as $operation) {
        if (!is_array($operation) || empty($operation['entrypoint']) || empty($operation['enabled'])) {
            continue;
        }
        connectors_build_execution_plan($operationsPayload, (string)($operation['operation_id'] ?? ''));
    }
}

function connectors_decode_operations(array $connector): array
{
    $operations = connectors_default_operations($connector);
    $raw = (string)($connector['operations_json'] ?? '');
    if ($raw === '') {
        return $operations;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return $operations;
    }



    if (connectors_is_v3_operations_payload($decoded)) {
        return connectors_build_legacy_compat_operations_view($decoded, $connector);
    }

    if (isset($decoded['report']) && is_array($decoded['report'])) {
        $report = $decoded['report'];
        $operations['report']['schema_version'] = (int)($report['schema_version'] ?? 2) > 0 ? (int)$report['schema_version'] : 2;
        $operations['report']['operation_id'] = trim((string)($report['operation_id'] ?? 'report')) ?: 'report';
        $operations['report']['run_after'] = connectors_normalize_dependency_links($report['run_after'] ?? []);
        $operations['report']['run_with'] = connectors_normalize_dependency_links($report['run_with'] ?? []);
        $operations['report']['run_finally'] = connectors_normalize_dependency_links($report['run_finally'] ?? []);
        $operations['report']['entrypoint'] = !empty($report['entrypoint']) ? 1 : 0;
        $operations['report']['on_dependency_fail'] = connectors_normalize_dependency_policy($report['on_dependency_fail'] ?? 'stop');
        $operations['report']['enabled'] = !empty($report['enabled']) ? 1 : 0;
        $operations['report']['display_name'] = trim((string)($report['display_name'] ?? $operations['report']['display_name']));
        $operations['report']['module'] = connectors_normalize_operation_module($report['module'] ?? $operations['report']['module']);
        $operations['report']['action'] = trim((string)($report['action'] ?? $operations['report']['action']));
        $operations['report']['kind'] = connectors_normalize_operation_kind($report['kind'] ?? $operations['report']['kind'], (string)$operations['report']['module']);
        $operations['report']['page_url'] = trim((string)($report['page_url'] ?? ''));
        $operations['report']['file_extension'] = trim((string)($report['file_extension'] ?? 'xlsx')) ?: 'xlsx';
        $operations['report']['download_mode'] = in_array(($report['download_mode'] ?? 'browser'), ['browser', 'curl'], true)
            ? (string)$report['download_mode']
            : 'browser';
        $operations['report']['log_steps'] = !empty($report['log_steps']) ? 1 : 0;

        if (isset($report['steps'])) {
            $operations['report']['steps_json'] = json_encode($report['steps'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '';
        }
        if (isset($report['curl_config'])) {
            $operations['report']['curl_config_json'] = json_encode($report['curl_config'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '';
        }

        $operations['report']['target_table'] = trim((string)($report['target_table'] ?? $operations['report']['target_table']));

        if (isset($report['field_mapping'])) {
            $operations['report']['field_mapping_json'] = json_encode($report['field_mapping'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '';
        }
    }

    if (isset($decoded['submission']) && is_array($decoded['submission'])) {
        $submission = $decoded['submission'];
        $operations['submission']['schema_version'] = (int)($submission['schema_version'] ?? 2) > 0 ? (int)$submission['schema_version'] : 2;
        $operations['submission']['operation_id'] = trim((string)($submission['operation_id'] ?? 'submission')) ?: 'submission';
        $operations['submission']['run_after'] = connectors_normalize_dependency_links($submission['run_after'] ?? []);
        $operations['submission']['run_with'] = connectors_normalize_dependency_links($submission['run_with'] ?? []);
        $operations['submission']['run_finally'] = connectors_normalize_dependency_links($submission['run_finally'] ?? []);
        $operations['submission']['entrypoint'] = !empty($submission['entrypoint']) ? 1 : 0;
        $operations['submission']['on_dependency_fail'] = connectors_normalize_dependency_policy($submission['on_dependency_fail'] ?? 'stop');
        $operations['submission']['enabled'] = !empty($submission['enabled']) ? 1 : 0;
        $operations['submission']['display_name'] = trim((string)($submission['display_name'] ?? $operations['submission']['display_name']));
        $operations['submission']['module'] = connectors_normalize_operation_module($submission['module'] ?? $operations['submission']['module']);
        $operations['submission']['action'] = trim((string)($submission['action'] ?? $operations['submission']['action']));
        $operations['submission']['kind'] = connectors_normalize_operation_kind($submission['kind'] ?? $operations['submission']['kind'], (string)$operations['submission']['module']);
        $operations['submission']['page_url'] = trim((string)($submission['page_url'] ?? ''));
        $operations['submission']['log_steps'] = !empty($submission['log_steps']) ? 1 : 0;
        $operations['submission']['success_selector'] = trim((string)($submission['success_selector'] ?? ''));
        $operations['submission']['success_text'] = trim((string)($submission['success_text'] ?? ''));
        $operations['submission']['error_selector'] = trim((string)($submission['error_selector'] ?? ''));

        if (isset($submission['steps'])) {
            $operations['submission']['steps_json'] = json_encode($submission['steps'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '';
        }

        if (isset($submission['request_config'])) {
            $operations['submission']['request_config_json'] = json_encode($submission['request_config'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '';
        }
    }

    if (isset($decoded['track_and_label_info']) && is_array($decoded['track_and_label_info'])) {
        $trackAndLabel = $decoded['track_and_label_info'];
        $operations['track_and_label_info']['schema_version'] = (int)($trackAndLabel['schema_version'] ?? 2) > 0 ? (int)$trackAndLabel['schema_version'] : 2;
        $operations['track_and_label_info']['operation_id'] = trim((string)($trackAndLabel['operation_id'] ?? 'track_and_label_info')) ?: 'track_and_label_info';
        $operations['track_and_label_info']['run_after'] = connectors_normalize_dependency_links($trackAndLabel['run_after'] ?? []);
        $operations['track_and_label_info']['run_with'] = connectors_normalize_dependency_links($trackAndLabel['run_with'] ?? []);
        $operations['track_and_label_info']['run_finally'] = connectors_normalize_dependency_links($trackAndLabel['run_finally'] ?? []);
        $operations['track_and_label_info']['entrypoint'] = !empty($trackAndLabel['entrypoint']) ? 1 : 0;
        $operations['track_and_label_info']['on_dependency_fail'] = connectors_normalize_dependency_policy($trackAndLabel['on_dependency_fail'] ?? 'stop');
        $operations['track_and_label_info']['enabled'] = !empty($trackAndLabel['enabled']) ? 1 : 0;
        $operations['track_and_label_info']['display_name'] = trim((string)($trackAndLabel['display_name'] ?? $operations['track_and_label_info']['display_name']));
        $operations['track_and_label_info']['module'] = connectors_normalize_operation_module($trackAndLabel['module'] ?? $operations['track_and_label_info']['module']);
        $operations['track_and_label_info']['action'] = trim((string)($trackAndLabel['action'] ?? $operations['track_and_label_info']['action']));
        $operations['track_and_label_info']['kind'] = connectors_normalize_operation_kind($trackAndLabel['kind'] ?? $operations['track_and_label_info']['kind'], (string)$operations['track_and_label_info']['module']);
    }
    return $operations;
}


function connectors_decode_runtime_json_fields(array $operation): array
{
    $jsonToArrayMap = [
        'steps_json' => 'steps',
        'curl_config_json' => 'curl_config',
        'field_mapping_json' => 'field_mapping',
        'request_config_json' => 'request_config',
    ];

    foreach ($jsonToArrayMap as $jsonKey => $arrayKey) {
        if (isset($operation[$arrayKey])) {
            continue;
        }

        $rawJson = trim((string)($operation[$jsonKey] ?? ''));
        if ($rawJson === '') {
            continue;
        }

        $decoded = json_decode($rawJson, true);
        if (is_array($decoded)) {
            $operation[$arrayKey] = $decoded;
        }
    }

    return $operation;
}

function connectors_decode_operations_payload(array $connector): array
{
    $raw = trim((string)($connector['operations_json'] ?? ''));
    if ($raw === '') {
        return [
            'schema_version' => 3,
            'operations' => [],
        ];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [
            'schema_version' => 3,
            'operations' => [],
        ];
    }

    return connectors_migrate_operations_payload($decoded);
}

function connectors_decode_operations_for_runtime(array $connector): array
{
    $operationsPayload = connectors_decode_operations_payload($connector);

    if (connectors_is_v3_operations_payload($operationsPayload)) {
        return connectors_v3_payload_to_runtime_operations($operationsPayload);
    }

    return connectors_decode_operations($connector);
}

function connectors_fetch_last_run_status_by_operation(mysqli $dbcnx, int $connectorId, array $operationsPayload): array
{
    if ($connectorId <= 0) {
        return [];
    }

    $runtimeOperations = connectors_is_v3_operations_payload($operationsPayload)
        ? connectors_v3_payload_to_runtime_operations($operationsPayload)
        : $operationsPayload;

    $allowedOperationIds = [];
    foreach ($runtimeOperations as $operationId => $operation) {
        if (!is_array($operation)) {
            continue;
        }
        $resolvedId = trim((string)($operation['operation_id'] ?? $operationId));
        if ($resolvedId === '') {
            continue;
        }
        $allowedOperationIds[$resolvedId] = true;
    }

    if (empty($allowedOperationIds)) {
        return [];
    }

    $stmt = $dbcnx->prepare('SELECT test_operation, status, message, finished_at, run_id FROM connector_operation_runs WHERE connector_id = ? ORDER BY finished_at DESC, id DESC LIMIT 300');
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('i', $connectorId);
    if (!$stmt->execute()) {
        $stmt->close();
        return [];
    }

    $res = $stmt->get_result();
    if (!$res) {
        $stmt->close();
        return [];
    }

    $byOperation = [];
    while ($row = $res->fetch_assoc()) {
        $operationId = trim((string)($row['test_operation'] ?? ''));
        if ($operationId === '' || !isset($allowedOperationIds[$operationId])) {
            continue;
        }
        if (isset($byOperation[$operationId])) {
            continue;
        }

        $byOperation[$operationId] = [
            'status' => trim((string)($row['status'] ?? '')),
            'message' => trim((string)($row['message'] ?? '')),
            'finished_at' => trim((string)($row['finished_at'] ?? '')),
            'run_id' => trim((string)($row['run_id'] ?? '')),
        ];
    }

    $res->free();
    $stmt->close();

    return $byOperation;
}

function connectors_resolve_report_operation_id(array $runtimeOperations): ?string
{
    if (isset($runtimeOperations['report']) && is_array($runtimeOperations['report'])) {
        return 'report';
    }

    foreach ($runtimeOperations as $operationId => $operation) {
        if (!is_array($operation) || empty($operation['enabled']) || empty($operation['entrypoint'])) {
            continue;
        }
        return trim((string)$operationId) ?: null;
    }

    foreach ($runtimeOperations as $operationId => $operation) {
        if (!is_array($operation) || empty($operation['enabled'])) {
            continue;
        }
        return trim((string)$operationId) ?: null;
    }

    return null;
}

function connectors_resolve_script_interpreter(array $config): string
{
    $interpreter = strtolower(trim((string)($config['interpreter'] ?? '')));
    if ($interpreter !== '') {
        return $interpreter;
    }

    $scriptPath = trim((string)($config['script_path'] ?? ''));
    $ext = strtolower(pathinfo($scriptPath, PATHINFO_EXTENSION));
    if ($ext === 'php') {
        return 'php';
    }
    if ($ext === 'js') {
        return 'node';
    }

    return 'bash';
}

function connectors_unwrap_embedded_operation_config(array $config, string $operationId = ''): array
{
    if (!isset($config['operations']) || !is_array($config['operations'])) {
        return $config;
    }

    $candidates = $config['operations'];
    if ($operationId !== '') {
        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }
            $candidateId = trim((string)($candidate['operation_id'] ?? ''));
            if ($candidateId !== '' && strcasecmp($candidateId, $operationId) === 0) {
                $nestedConfig = isset($candidate['config']) && is_array($candidate['config']) ? $candidate['config'] : null;
                if (is_array($nestedConfig)) {
                    return $nestedConfig;
                }
            }
        }
    }


    if ($operationId === '') {
        // Если operation_id неизвестен, не надо «угадывать» и брать первый попавшийся nested config:
        // это может подменить config текущей операции (в т.ч. потерять target_table).
        return $config;
    }

    foreach ($candidates as $candidate) {
        if (!is_array($candidate)) {
            continue;
        }
        $nestedConfig = isset($candidate['config']) && is_array($candidate['config']) ? $candidate['config'] : null;
        if (is_array($nestedConfig)) {
            return $nestedConfig;
        }
    }

    return $config;
}

function connectors_operation_supports_php_entrypoint(array $operation): bool
{
    $kind = strtolower(trim((string)($operation['kind'] ?? '')));
    if ($kind === 'php_report') {
        return true;
    }
    if ($kind !== 'script') {
        return false;
    }
    $operationId = trim((string)($operation['operation_id'] ?? ''));
    $config = isset($operation['config']) && is_array($operation['config']) ? $operation['config'] : [];
    $config = connectors_unwrap_embedded_operation_config($config, $operationId);

    return connectors_resolve_script_interpreter($config) === 'php';
}

function connectors_apply_php_entrypoint_mode(string $testOperation, array $runtimeOperations, string $entrypointMode = ''): string
{
    $testOperation = trim($testOperation);
    if ($testOperation === '') {
        return '';
    }

    $entrypointMode = strtolower(trim($entrypointMode));
    if (!in_array($entrypointMode, ['php', 'entrypoint_php'], true)) {
        return $testOperation;
    }

    if (preg_match('/_php$/i', $testOperation)) {
        return $testOperation;
    }

    $candidate = $testOperation . '_php';
    if (isset($runtimeOperations[$candidate]) && is_array($runtimeOperations[$candidate])) {
        $candidateOperation = $runtimeOperations[$candidate];
        if (connectors_operation_supports_php_entrypoint($candidateOperation)) {
            return $candidate;
        }
        return $candidate;
    }
}

function connectors_evaluate_operation_runnable(array $operation, array $connector = []): array{
    $kind = strtolower(trim((string)($operation['kind'] ?? 'browser_steps')));
    $config = isset($operation['config']) && is_array($operation['config']) ? $operation['config'] : [];
    $operationId = trim((string)($operation['operation_id'] ?? ''));
    $config = connectors_unwrap_embedded_operation_config($config, $operationId);

    if ($kind === 'script') {
        $scriptPath = trim((string)($config['script_path'] ?? ''));
        if ($scriptPath === '') {
            return ['is_runnable' => false, 'reason' => 'missing_script_path'];
        }
        return ['is_runnable' => true, 'reason' => ''];
    }

    if ($kind === 'php_report') {
        $downloadMode = strtolower(trim((string)($config['download_mode'] ?? '')));
        if ($downloadMode === '') {
            return ['is_runnable' => false, 'reason' => 'missing_download_mode'];
        }
        if ($downloadMode === 'curl') {
            $curlConfig = isset($config['curl_config']) && is_array($config['curl_config']) ? $config['curl_config'] : [];
            $url = trim((string)($curlConfig['url'] ?? ''));
            if ($url === '') {
                return ['is_runnable' => false, 'reason' => 'missing_curl_config_url'];
            }
        }

        return ['is_runnable' => true, 'reason' => ''];
    }

    if ($kind === 'api_call') {
        $action = trim((string)($operation['action'] ?? ''));
        if ($action === '') {
            return ['is_runnable' => false, 'reason' => 'missing_action'];
        }
        return ['is_runnable' => true, 'reason' => ''];
    }

    if ($kind === 'browser_steps') {
        $steps = isset($config['steps']) && is_array($config['steps']) ? $config['steps'] : [];
        if (!empty($steps)) {
            return ['is_runnable' => true, 'reason' => ''];
        }
        $prependLoginSteps = !array_key_exists('prepend_login_steps', $config) || !empty($config['prepend_login_steps']);
        $loginSteps = $prependLoginSteps ? connectors_extract_browser_login_steps($connector) : [];
        if (!empty($loginSteps)) {
            return ['is_runnable' => true, 'reason' => ''];
        }
        return ['is_runnable' => false, 'reason' => 'missing_steps'];
    }

    return ['is_runnable' => true, 'reason' => ''];
}

function connectors_resolve_test_entrypoint_with_diagnostics(array $operationsPayload, string $testOperation, string $entrypointMode = '', array $connector = []): array
{
    $runtimeOperations = connectors_is_v3_operations_payload($operationsPayload)
        ? connectors_v3_payload_to_runtime_operations($operationsPayload)
        : $operationsPayload;
    $testOperation = trim($testOperation);
    $testOperationLower = strtolower($testOperation);
    $resolvedRequestedOperation = $testOperation !== '' ? $testOperation : ($testOperationLower === 'submission' ? 'submission' : 'report');
    if ($resolvedRequestedOperation === '') {
        $resolvedRequestedOperation = 'report';
    }

    $entrypointModeNormalized = strtolower(trim($entrypointMode));
    $phpModeRequested = in_array($entrypointModeNormalized, ['php', 'entrypoint_php'], true);
    $candidatePhpOperationId = 'report_php';
    if ($resolvedRequestedOperation !== '' && $resolvedRequestedOperation !== 'submission') {
        $candidatePhpOperationId = preg_match('/_php$/i', $resolvedRequestedOperation) ? $resolvedRequestedOperation : ($resolvedRequestedOperation . '_php');
    }

    $candidateExists = isset($runtimeOperations[$candidatePhpOperationId]) && is_array($runtimeOperations[$candidatePhpOperationId]);
    $candidateOperation = $candidateExists ? $runtimeOperations[$candidatePhpOperationId] : [];
    $candidateKind = $candidateExists
        ? strtolower(trim((string)($candidateOperation['kind'] ?? '')))
        : '';
    $candidateRunnable = $candidateExists ? connectors_evaluate_operation_runnable($candidateOperation, $connector) : ['is_runnable' => false, 'reason' => 'missing_php_operation'];
    $candidateIsRunnable = !empty($candidateRunnable['is_runnable']);
    $candidateNotRunnableReason = $candidateIsRunnable ? '' : (string)($candidateRunnable['reason'] ?? 'not_runnable');

    $resolvedEntrypointOperation = $resolvedRequestedOperation;
    $fallback = [
        'used' => false,
        'from' => '',
        'to' => '',
        'reason' => '',
    ];

    if ($phpModeRequested && $resolvedRequestedOperation !== 'submission') {
        $requestedExists = isset($runtimeOperations[$resolvedRequestedOperation]) && is_array($runtimeOperations[$resolvedRequestedOperation]);
        $requestedOperation = $requestedExists ? $runtimeOperations[$resolvedRequestedOperation] : [];
        $requestedRunnable = $requestedExists ? connectors_evaluate_operation_runnable($requestedOperation, $connector) : ['is_runnable' => false, 'reason' => 'missing_operation'];
        $requestedIsRunnable = !empty($requestedRunnable['is_runnable']);
        $requestedSupportsPhpEntrypoint = connectors_operation_supports_php_entrypoint($requestedOperation);

        if ($requestedSupportsPhpEntrypoint && $requestedIsRunnable) {
            $resolvedEntrypointOperation = $resolvedRequestedOperation;
        } elseif (!$candidateExists) {
            $fallback = [
                'used' => true,
                'from' => $candidatePhpOperationId,
                'to' => $resolvedRequestedOperation,
                'reason' => 'missing_php_operation',
            ];
        } elseif (!connectors_operation_supports_php_entrypoint($candidateOperation)) {
            $fallback = [
                'used' => true,
                'from' => $candidatePhpOperationId,
                'to' => $resolvedRequestedOperation,
                'reason' => 'php_entrypoint_kind_not_supported',
            ];
        } elseif (!$candidateIsRunnable) {
            $fallback = [
                'used' => true,
                'from' => $candidatePhpOperationId,
                'to' => $resolvedRequestedOperation,
                'reason' => $candidateNotRunnableReason !== '' ? $candidateNotRunnableReason : 'php_entrypoint_not_runnable',
            ];
        } else {
            $resolvedEntrypointOperation = $candidatePhpOperationId;
        }
    }
    if (!isset($runtimeOperations[$resolvedEntrypointOperation])) {
        throw new InvalidArgumentException('Операция для теста не найдена: ' . $resolvedEntrypointOperation);
    }

    $resolvedEntrypointKind = strtolower(trim((string)($runtimeOperations[$resolvedEntrypointOperation]['kind'] ?? '')));

    return [
        'entrypoint' => (string)($runtimeOperations[$resolvedEntrypointOperation]['operation_id'] ?? $resolvedEntrypointOperation),
        'runtime_operations' => $runtimeOperations,
        'diagnostics' => [
            'requested' => [
                'test_operation' => $testOperation !== '' ? $testOperation : 'report',
                'entrypoint_mode' => $entrypointMode,
            ],
            'resolved' => [
                'entrypoint_operation' => (string)($runtimeOperations[$resolvedEntrypointOperation]['operation_id'] ?? $resolvedEntrypointOperation),
                'entrypoint_kind' => $resolvedEntrypointKind,
            ],
            'source' => [
                'payload_schema_version' => (int)($operationsPayload['schema_version'] ?? 0),
            ],
            'candidate_php' => [
                'operation_id' => $candidatePhpOperationId,
                'exists' => $candidateExists,
                'kind' => $candidateKind,
                'is_runnable' => $candidateIsRunnable,
                'not_runnable_reason' => $candidateNotRunnableReason,
            ],
            'fallback' => $fallback,
        ],
    ];
}

function connectors_resolve_legacy_test_entrypoint(array $operationsPayload, string $testOperation, string $entrypointMode = ''): string
{
    $resolved = connectors_resolve_test_entrypoint_with_diagnostics($operationsPayload, $testOperation, $entrypointMode);
    return (string)($resolved['entrypoint'] ?? '');
}



function connectors_force_enable_test_entrypoint(array $runtimeOperations, string $entrypointOperationId, bool &$wasForced = false): array
{
    $wasForced = false;
    $entrypointOperationId = trim($entrypointOperationId);
    if ($entrypointOperationId === '' || !isset($runtimeOperations[$entrypointOperationId]) || !is_array($runtimeOperations[$entrypointOperationId])) {
        return $runtimeOperations;
    }

    if (!empty($runtimeOperations[$entrypointOperationId]['enabled'])) {
        return $runtimeOperations;
    }

    $runtimeOperations[$entrypointOperationId]['enabled'] = 1;
    $wasForced = true;
    return $runtimeOperations;
}

function connectors_migrate_operations_payload(array $payload): array
{

    if (connectors_is_v3_operations_payload($payload)) {
        $normalized = [];
        foreach ((array)($payload['operations'] ?? []) as $operation) {
            if (!is_array($operation)) {
                continue;
            }
            $normalized[] = connectors_build_v3_operation_payload($operation);
        }

        return [
            'schema_version' => 3,
            'operations' => $normalized,
        ];
    }

    $operationDefaults = [
        'report' => [
            'operation_id' => 'report',
            'entrypoint' => 1,
            'schema_version' => 2,
        ],
        'submission' => [
            'operation_id' => 'submission',
            'entrypoint' => 0,
            'schema_version' => 2,
        ],
        'track_and_label_info' => [
            'operation_id' => 'track_and_label_info',
            'entrypoint' => 0,
            'schema_version' => 2,
        ],
    ];

    $mainOperationKey = 'report';
    foreach (['report', 'submission', 'track_and_label_info'] as $operationKey) {
        if (!empty($payload[$operationKey]['enabled'])) {
            $mainOperationKey = $operationKey;
            break;
        }
    }

    $hasExplicitEntrypoint = false;
    foreach ($operationDefaults as $operationKey => $defaults) {
        if (!isset($payload[$operationKey]) || !is_array($payload[$operationKey])) {
            continue;
        }
        if (array_key_exists('entrypoint', $payload[$operationKey])) {
            $hasExplicitEntrypoint = $hasExplicitEntrypoint || !empty($payload[$operationKey]['entrypoint']);
        }
    }

    foreach ($operationDefaults as $operationKey => $defaults) {
        if (!isset($payload[$operationKey]) || !is_array($payload[$operationKey])) {
            continue;
        }

        $operationId = trim((string)($payload[$operationKey]['operation_id'] ?? ''));
        if ($operationId === '') {
            $payload[$operationKey]['operation_id'] = $defaults['operation_id'];
        }

        if (!isset($payload[$operationKey]['run_after']) || !is_array($payload[$operationKey]['run_after'])) {
            $payload[$operationKey]['run_after'] = [];
        }

        $currentSchemaVersion = (int)($payload[$operationKey]['schema_version'] ?? 0);
        if ($currentSchemaVersion !== (int)$defaults['schema_version']) {
            $payload[$operationKey]['schema_version'] = (int)$defaults['schema_version'];
        }

        if (!array_key_exists('entrypoint', $payload[$operationKey])) {
            $payload[$operationKey]['entrypoint'] = (!$hasExplicitEntrypoint && $operationKey === $mainOperationKey)
                ? 1
                : $defaults['entrypoint'];
        }
    }

    return connectors_legacy_operations_to_v3_payload($payload);
}


function connectors_build_legacy_compat_operations_view(array $operationsPayload, array $connector = []): array
{
    if (!connectors_is_v3_operations_payload($operationsPayload)) {
        return $operationsPayload;
    }

    $operations = connectors_default_operations($connector);
    $runtimeOperations = connectors_v3_payload_to_runtime_operations($operationsPayload);

    foreach (['report', 'submission', 'track_and_label_info'] as $operationKey) {
        if (!isset($runtimeOperations[$operationKey])) {
            continue;
        }

        $op = $runtimeOperations[$operationKey];
        $cfg = isset($op['config']) && is_array($op['config']) ? $op['config'] : [];
        $operations[$operationKey]['schema_version'] = 3;
        $operations[$operationKey]['operation_id'] = $op['operation_id'];
        $operations[$operationKey]['display_name'] = trim((string)($op['display_name'] ?? $operations[$operationKey]['display_name'] ?? $operationKey));
        $operations[$operationKey]['module'] = connectors_normalize_operation_module($op['module'] ?? 'generic');
        $operations[$operationKey]['action'] = trim((string)($op['action'] ?? ''));
        $operations[$operationKey]['kind'] = connectors_normalize_operation_kind($op['kind'] ?? '', (string)($operations[$operationKey]['module'] ?? 'generic'));
        $operations[$operationKey]['run_after'] = connectors_normalize_dependency_links($op['run_after'] ?? []);
        $operations[$operationKey]['run_with'] = connectors_normalize_dependency_links($op['run_with'] ?? []);
        $operations[$operationKey]['run_finally'] = connectors_normalize_dependency_links($op['run_finally'] ?? []);
        $operations[$operationKey]['entrypoint'] = !empty($op['entrypoint']) ? 1 : 0;
        $operations[$operationKey]['on_dependency_fail'] = connectors_normalize_dependency_policy($op['on_dependency_fail'] ?? 'stop');
        $operations[$operationKey]['enabled'] = !empty($op['enabled']) ? 1 : 0;

        if ($operationKey === 'report') {
            $operations[$operationKey]['page_url'] = trim((string)($cfg['page_url'] ?? ''));
            $operations[$operationKey]['file_extension'] = trim((string)($cfg['file_extension'] ?? 'xlsx')) ?: 'xlsx';
            $operations[$operationKey]['download_mode'] = in_array(($cfg['download_mode'] ?? 'browser'), ['browser', 'curl'], true)
                ? (string)$cfg['download_mode']
                : 'browser';
            $operations[$operationKey]['log_steps'] = !empty($cfg['log_steps']) ? 1 : 0;
            if (isset($cfg['steps']) && is_array($cfg['steps'])) {
                $operations[$operationKey]['steps_json'] = json_encode($cfg['steps'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '';
            }
            if (isset($cfg['curl_config']) && is_array($cfg['curl_config'])) {
                $operations[$operationKey]['curl_config_json'] = json_encode($cfg['curl_config'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '';
            }
            $operations[$operationKey]['target_table'] = trim((string)($cfg['target_table'] ?? $operations[$operationKey]['target_table']));
            if (isset($cfg['field_mapping']) && is_array($cfg['field_mapping'])) {
                $operations[$operationKey]['field_mapping_json'] = json_encode($cfg['field_mapping'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '';
            }
        }

        if ($operationKey === 'submission') {
            $operations[$operationKey]['page_url'] = trim((string)($cfg['page_url'] ?? ''));
            $operations[$operationKey]['log_steps'] = !empty($cfg['log_steps']) ? 1 : 0;
            if (isset($cfg['steps']) && is_array($cfg['steps'])) {
                $operations[$operationKey]['steps_json'] = json_encode($cfg['steps'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '';
            }
            if (isset($cfg['request_config']) && is_array($cfg['request_config'])) {
                $operations[$operationKey]['request_config_json'] = json_encode($cfg['request_config'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '';
            }
            $operations[$operationKey]['success_selector'] = trim((string)($cfg['success_selector'] ?? ''));
            $operations[$operationKey]['success_text'] = trim((string)($cfg['success_text'] ?? ''));
            $operations[$operationKey]['error_selector'] = trim((string)($cfg['error_selector'] ?? ''));
        }
    }

    return $operations;
}

function connectors_try_migrate_operations_json(array $connector): array
{
    $raw = trim((string)($connector['operations_json'] ?? ''));
    if ($raw === '') {
        return $connector;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return $connector;
    }

    $migrated = connectors_migrate_operations_payload($decoded);
    if ($migrated === $decoded) {
        return $connector;
    }

    $encoded = json_encode($migrated, JSON_UNESCAPED_UNICODE);
    if ($encoded === false) {
        return $connector;
    }

    $connector['operations_json'] = $encoded;
    return $connector;
}



function connectors_validate_iso_date(?string $value): ?string
{
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }

    $dt = DateTime::createFromFormat('Y-m-d', $value);
    if (!$dt || $dt->format('Y-m-d') !== $value) {
        throw new InvalidArgumentException('Дата должна быть в формате YYYY-MM-DD');
    }

    return $value;
}

function connectors_normalize_report_table_name(string $table): string
{
    $table = strtolower(trim($table));
    $table = preg_replace('/[^a-z0-9_]+/', '_', $table) ?? '';
    $table = trim($table, '_');

    if ($table === '') {
        throw new InvalidArgumentException('Не указана целевая таблица отчета');
    }

    if (strpos($table, 'connector_report_') !== 0) {
        $table = 'connector_report_' . $table;
    }

    return $table;
}

function connectors_ensure_report_table(mysqli $dbcnx, string $tableName): void
{
    $safeTable = '`' . str_replace('`', '``', $tableName) . '`';
    $sql = "CREATE TABLE IF NOT EXISTS {$safeTable} (
"
        . " id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
"
        . " connector_id INT UNSIGNED NOT NULL,
"
        . " period_from DATE NULL,
"
        . " period_to DATE NULL,
"
        . " payload_json LONGTEXT NULL,
"
        . " source_file VARCHAR(255) NOT NULL DEFAULT '',
"
        . " created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
"
        . " PRIMARY KEY (id),
"
        . " KEY idx_connector_period (connector_id, period_from, period_to)
"
        . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

    if (!$dbcnx->query($sql)) {
        throw new RuntimeException('DB error (create report table): ' . $dbcnx->error);
    }
}


function connectors_apply_vars($value, array $vars)
{
    if (is_array($value)) {
        $result = [];
        foreach ($value as $k => $v) {
            $result[$k] = connectors_apply_vars($v, $vars);
        }
        return $result;
    }

    if (!is_string($value)) {
        return $value;
    }

    $value = preg_replace_callback('/\$\{([a-zA-Z0-9_]+)\}/', static function ($m) use ($vars) {
        $key = $m[1] ?? '';
        return array_key_exists($key, $vars) ? (string)$vars[$key] : '';
    }, $value) ?? $value;

    return preg_replace_callback('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', static function ($m) use ($vars) {
        $key = $m[1] ?? '';
        return array_key_exists($key, $vars) ? (string)$vars[$key] : '';
    }, $value) ?? $value;
}


function connectors_extract_browser_login_steps(array $connector): array
{
    $scenarioRaw = trim((string)($connector['scenario_json'] ?? ''));
    if ($scenarioRaw === '') {
        return [];
    }

    $scenario = json_decode($scenarioRaw, true);
    if (!is_array($scenario)) {
        return [];
    }

    if (isset($scenario['browser_login_steps']) && is_array($scenario['browser_login_steps'])) {
        return $scenario['browser_login_steps'];
    }

    if (isset($scenario['login']['browser_steps']) && is_array($scenario['login']['browser_steps'])) {
        return $scenario['login']['browser_steps'];
    }

    return [];
}


function connectors_parse_set_cookie_header(array $headers): string
{
    $cookies = [];
    foreach ($headers as $headerLine) {
        if (stripos((string)$headerLine, 'Set-Cookie:') !== 0) {
            continue;
        }
        $raw = trim(substr((string)$headerLine, strlen('Set-Cookie:')));
        if ($raw === '') {
            continue;
        }
        $firstPart = trim(explode(';', $raw, 2)[0] ?? '');
        if ($firstPart === '' || strpos($firstPart, '=') === false) {
            continue;
        }
        [$k, $v] = array_map('trim', explode('=', $firstPart, 2));
        if ($k === '') {
            continue;
        }
        $cookies[$k] = $v;
    }

    $pairs = [];
    foreach ($cookies as $k => $v) {
        $pairs[] = $k . '=' . $v;
    }
    return implode('; ', $pairs);
}

function connectors_parse_location_headers(array $headers): array
{
    $locations = [];
    foreach ($headers as $headerLine) {
        if (stripos((string)$headerLine, 'Location:') !== 0) {
            continue;
        }

        $location = trim(substr((string)$headerLine, strlen('Location:')));
        if ($location !== '') {
            $locations[] = $location;
        }
    }

    return $locations;
}

function connectors_parse_content_type_header(array $headers): string
{
    $contentType = '';
    foreach ($headers as $headerLine) {
        if (stripos((string)$headerLine, 'Content-Type:') !== 0) {
            continue;
        }

        $rawValue = trim(substr((string)$headerLine, strlen('Content-Type:')));
        if ($rawValue === '') {
            continue;
        }
        $contentType = strtolower(trim((string)explode(';', $rawValue, 2)[0]));
    }

    return $contentType;
}

function connectors_build_curl_network_error_hint(int $curlErrNo, string $effectiveUrl, string $requestUrl = ''): string
{
    $host = '';
    $candidateUrl = trim($effectiveUrl) !== '' ? $effectiveUrl : $requestUrl;
    if ($candidateUrl !== '') {
        $parsedHost = parse_url($candidateUrl, PHP_URL_HOST);
        if (is_string($parsedHost)) {
            $host = trim($parsedHost);
        }
    }

    if ($curlErrNo === 6) {
        return $host !== ''
            ? ('DNS lookup failed for host "' . $host . '". Проверьте DNS/firewall и доступность домена из этого окружения.')
            : 'DNS lookup failed. Проверьте DNS/firewall и доступность домена из этого окружения.';
    }
    if ($curlErrNo === 7) {
        return $host !== ''
            ? ('Connection to "' . $host . '" failed. Проверьте порт, firewall и маршрутизацию до endpoint.')
            : 'Connection failed. Проверьте порт, firewall и маршрутизацию до endpoint.';
    }
    if ($curlErrNo === 28) {
        return 'Request timed out. Проверьте latency/доступность endpoint или увеличьте timeout.';
    }
    if ($curlErrNo === 35 || $curlErrNo === 60) {
        return 'TLS/SSL handshake failed. Проверьте сертификат endpoint и ssl_ignore в настройках connector.';
    }

    return '';
}

function connectors_resolve_curl_timeout_seconds(array $cfg, int $defaultTimeoutSeconds = 120): int
{
    $timeoutCandidates = [
        $cfg['curl_timeout_seconds'] ?? null,
        $cfg['timeout_seconds'] ?? null,
        $cfg['timeout'] ?? null,
    ];

    foreach ($timeoutCandidates as $candidate) {
        if ($candidate === null || $candidate === '') {
            continue;
        }
        $timeoutSeconds = (int)$candidate;
        if ($timeoutSeconds > 0) {
            return max(3, min($timeoutSeconds, 600));
        }
    }

    return max(3, min($defaultTimeoutSeconds, 600));
}

function connectors_default_content_types_for_extension(string $extension): array
{
    $ext = strtolower(trim($extension));
    if ($ext === 'csv') {
        return ['text/csv', 'application/csv', 'application/vnd.ms-excel', 'application/octet-stream'];
    }
    if ($ext === 'xlsx') {
        return ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/octet-stream'];
    }
    if ($ext === 'xls') {
        return ['application/vnd.ms-excel', 'application/octet-stream'];
    }

    return [];
}

function connectors_validate_downloaded_report_file(string $filePath, string $extension): array
{
    $ext = strtolower(trim($extension));
    if ($filePath === '' || !is_file($filePath)) {
        return ['valid' => false, 'reason' => 'file_not_found'];
    }

    $fh = @fopen($filePath, 'rb');
    if ($fh === false) {
        return ['valid' => false, 'reason' => 'file_open_failed'];
    }
    $head = (string)fread($fh, 8);
    fclose($fh);

    if ($ext === 'xlsx') {
        // XLSX is a ZIP container (PK\x03\x04)
        if (strncmp($head, "PK\x03\x04", 4) !== 0) {
            return ['valid' => false, 'reason' => 'invalid_xlsx_signature'];
        }
        return ['valid' => true, 'reason' => 'ok'];
    }

    if ($ext === 'xls') {
        // Legacy XLS (OLE CF): D0 CF 11 E0 A1 B1 1A E1
        $expected = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";
        if ($head !== $expected) {
            return ['valid' => false, 'reason' => 'invalid_xls_signature'];
        }
        return ['valid' => true, 'reason' => 'ok'];
    }

    if ($ext === 'csv') {
        $sample = @file_get_contents($filePath, false, null, 0, 2048);
        if (!is_string($sample) || $sample === '') {
            return ['valid' => false, 'reason' => 'csv_empty_sample'];
        }
        if (strpos($sample, "\x00") !== false) {
            return ['valid' => false, 'reason' => 'csv_contains_binary_null'];
        }
        return ['valid' => true, 'reason' => 'ok'];
    }

    return ['valid' => true, 'reason' => 'skipped_for_extension'];
}

function connectors_merge_cookie_parts(array $cookieParts): string
{
    $cookies = [];
    foreach ($cookieParts as $part) {
        $line = trim((string)$part);
        if ($line === '') {
            continue;
        }

        $segments = explode(';', $line);
        foreach ($segments as $segment) {
            $pair = trim((string)$segment);
            if ($pair === '' || strpos($pair, '=') === false) {
                continue;
            }
            [$name, $value] = array_map('trim', explode('=', $pair, 2));
            if ($name === '') {
                continue;
            }
            $cookies[$name] = $value;
        }
    }

    $pairs = [];
    foreach ($cookies as $name => $value) {
        $pairs[] = $name . '=' . $value;
    }

    return implode('; ', $pairs);
}


function connectors_parse_csrf_token_from_html(string $html): string
{
    if ($html === '') {
        return '';
    }

    if (preg_match('/<input\\b[^>]*\\bname\\s*=\\s*["\\\']_token["\\\'][^>]*\\bvalue\\s*=\\s*["\\\']([^"\\\']+)["\\\'][^>]*>/iu', $html, $m)) {
        return html_entity_decode((string)($m[1] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    if (preg_match('/<input\\b[^>]*\\bvalue\\s*=\\s*["\\\']([^"\\\']+)["\\\'][^>]*\\bname\\s*=\\s*["\\\']_token["\\\'][^>]*>/iu', $html, $m)) {
        return html_entity_decode((string)($m[1] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    if (preg_match('/<meta\\b[^>]*\\bname\\s*=\\s*["\\\']csrf-token["\\\'][^>]*\\bcontent\\s*=\\s*["\\\']([^"\\\']+)["\\\'][^>]*>/iu', $html, $m)) {
        return html_entity_decode((string)($m[1] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    if (preg_match('/<meta\\b[^>]*\\bcontent\\s*=\\s*["\\\']([^"\\\']+)["\\\'][^>]*\\bname\\s*=\\s*["\\\']csrf-token["\\\'][^>]*>/iu', $html, $m)) {
        return html_entity_decode((string)($m[1] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    return '';
}

/**
 * @return array{form_action:string,input_names:array<int,string>,candidate_login_fields:array<int,string>}
 */
function connectors_detect_login_fields(string $html): array
{
    $action = '';
    $inputNames = [];
    $candidateLoginFields = [];

    if (preg_match('/<form\\b[^>]*\\baction\\s*=\\s*["\']([^"\']+)["\'][^>]*>/iu', $html, $formMatch)) {
        $action = trim((string)($formMatch[1] ?? ''));
    }

    if (preg_match_all('/<input\\b[^>]*>/iu', $html, $inputMatches)) {
        foreach ((array)($inputMatches[0] ?? []) as $tag) {
            $name = '';
            $type = 'text';

            if (preg_match('/\\bname\\s*=\\s*["\']([^"\']+)["\']/iu', (string)$tag, $mName)) {
                $name = trim((string)($mName[1] ?? ''));
            }
            if (preg_match('/\\btype\\s*=\\s*["\']([^"\']+)["\']/iu', (string)$tag, $mType)) {
                $type = strtolower(trim((string)($mType[1] ?? 'text')));
            }

            if ($name === '') {
                continue;
            }
            $inputNames[] = $name;
            if (in_array($name, ['_token', 'password', 'remember'], true)) {
                continue;
            }
            if (in_array($type, ['text', 'email'], true)) {
                $candidateLoginFields[] = $name;
            }
        }
    }

    return [
        'form_action' => $action,
        'input_names' => array_values(array_unique($inputNames)),
        'candidate_login_fields' => array_values(array_unique($candidateLoginFields)),
    ];
}

function connectors_parse_xsrf_token_from_cookie_string(string $cookieHeader): string
{
    if ($cookieHeader === '') {
        return '';
    }

    if (!preg_match('/(?:^|;\\s*)XSRF-TOKEN=([^;]+)/i', $cookieHeader, $m)) {
        return '';
    }

    return urldecode((string)($m[1] ?? ''));
}

function connectors_cfg_has_cookie_header(array $cfg): bool
{
    if (!isset($cfg['headers']) || !is_array($cfg['headers'])) {
        return false;
    }

    foreach ($cfg['headers'] as $k => $v) {
        if (strcasecmp(trim((string)$k), 'Cookie') === 0) {
            return true;
        }
    }

    return false;
}



function connectors_cfg_has_header(array $cfg, string $headerName): bool
{
    if (!isset($cfg['headers']) || !is_array($cfg['headers'])) {
        return false;
    }

    foreach ($cfg['headers'] as $k => $v) {
        if (strcasecmp(trim((string)$k), $headerName) === 0) {
            return true;
        }
    }

    return false;
}

function connectors_url_origin(string $url): string
{
    $parts = parse_url($url);
    if (!is_array($parts)) {
        return '';
    }

    $scheme = strtolower(trim((string)($parts['scheme'] ?? '')));
    $host = trim((string)($parts['host'] ?? ''));
    if ($scheme === '' || $host === '') {
        return '';
    }

    $origin = $scheme . '://' . $host;
    $port = isset($parts['port']) ? (int)$parts['port'] : 0;
    if ($port > 0) {
        $isDefaultPort = ($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443);
        if (!$isDefaultPort) {
            $origin .= ':' . $port;
        }
    }

    return $origin;
}

function connectors_is_login_redirect_target(string $location, string $loginUrl): bool
{
    $location = trim($location);
    if ($location === '') {
        return false;
    }

    $locationPath = parse_url($location, PHP_URL_PATH);
    if (!is_string($locationPath)) {
        $locationPath = $location;
    }
    $locationPath = '/' . ltrim(trim((string)$locationPath), '/');

    if ($locationPath === '/login') {
        return true;
    }

    $loginPath = parse_url($loginUrl, PHP_URL_PATH);
    if (!is_string($loginPath) || trim($loginPath) === '') {
        $loginPath = '/login';
    }
    $loginPath = '/' . ltrim(trim((string)$loginPath), '/');

    return rtrim($locationPath, '/') === rtrim($loginPath, '/');
}

function connectors_cfg_requires_csrf_preflight(array $cfg): bool
{
    $needles = ['{{csrf_token}}', '{{_token}}', '${csrf_token}', '${_token}'];
    $targets = [];

    if (isset($cfg['body']) && is_array($cfg['body'])) {
        $targets[] = $cfg['body'];
    }
    if (isset($cfg['fields']) && is_array($cfg['fields'])) {
        $targets[] = $cfg['fields'];
    }
    if (isset($cfg['headers']) && is_array($cfg['headers'])) {
        $targets[] = $cfg['headers'];
    }

    foreach ($targets as $target) {
        foreach ($target as $value) {
            $stringValue = is_scalar($value) ? (string)$value : json_encode($value, JSON_UNESCAPED_UNICODE);
            if (!is_string($stringValue) || $stringValue === '') {
                continue;
            }
            foreach ($needles as $needle) {
                if (strpos($stringValue, $needle) !== false) {
                    return true;
                }
            }
        }
    }

    return false;
}

function connectors_curl_request(array $cfg, array $vars, bool $sslIgnore): array
{
    $url = trim((string)connectors_apply_vars((string)($cfg['url'] ?? ''), $vars));
    if ($url === '') {
        throw new InvalidArgumentException('В cURL-запросе отсутствует url');
    }

    $method = strtoupper(trim((string)($cfg['method'] ?? 'GET')));
    if ($method === '') {
        $method = 'GET';
    }
    $followRedirects = array_key_exists('follow_redirects', $cfg)
        ? !empty($cfg['follow_redirects'])
        : false;
    $headers = [];
    if (isset($cfg['headers']) && is_array($cfg['headers'])) {
        foreach ($cfg['headers'] as $k => $v) {
            $headers[] = $k . ': ' . (string)connectors_apply_vars((string)$v, $vars);
        }
    }

    $bodyData = [];
    $rawBody = [];
    if (isset($cfg['body']) && is_array($cfg['body'])) {
        $rawBody = $cfg['body'];
    } elseif (isset($cfg['fields']) && is_array($cfg['fields'])) {
        $rawBody = $cfg['fields'];
    }
    foreach ($rawBody as $k => $v) {
        $bodyData[$k] = connectors_apply_vars($v, $vars);
    }

    $responseHeaders = [];
    $timeoutSeconds = connectors_resolve_curl_timeout_seconds($cfg, 120);
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, $followRedirects);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSeconds);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, static function ($ch, $line) use (&$responseHeaders) {
        $responseHeaders[] = trim((string)$line);
        return strlen($line);
    });
    if ($sslIgnore) {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    }
    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }
    if ($method !== 'GET') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        if (!empty($bodyData)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($bodyData));
        }
    }

    $body = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $effectiveUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $redirectCount = (int)curl_getinfo($ch, CURLINFO_REDIRECT_COUNT);
    $curlErr = curl_error($ch);
    $curlErrNo = (int)curl_errno($ch);
    curl_close($ch);

    if ($body === false) {
        if ($curlErrNo === 28) {
            throw new RuntimeException('Ошибка cURL: timeout after ' . $timeoutSeconds . 's');
        }
        $errorDetails = $curlErrNo > 0
            ? ('#' . $curlErrNo . ' ' . $curlErr)
            : $curlErr;
        throw new RuntimeException('Ошибка cURL: ' . trim($errorDetails));
    }

    return [
        'http_code' => $httpCode,
        'effective_url' => $effectiveUrl,
        'redirect_count' => $redirectCount,
        'body' => (string)$body,
        'headers' => $responseHeaders,
        'cookies' => connectors_parse_set_cookie_header($responseHeaders),
    ];
}



function connectors_clear_directory(string $directory, ?int $olderThanSeconds = null): void
{
    if (!is_dir($directory)) {
        return;
    }

    $items = scandir($directory);
    if (!is_array($items)) {
        return;
    }
    $minMtime = $olderThanSeconds !== null ? (time() - max(0, $olderThanSeconds)) : null;

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $path = $directory . DIRECTORY_SEPARATOR . $item;

        if ($minMtime !== null) {
            $pathMtime = @filemtime($path);
            if ($pathMtime !== false && $pathMtime >= $minMtime) {
                continue;
            }
        }

        if (is_dir($path) && !is_link($path)) {
            connectors_clear_directory($path);
            @rmdir($path);
            continue;
        }

        @unlink($path);
    }
}

function connectors_is_node_runtime_available(): bool
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    if (!function_exists('exec') || !is_callable('exec')) {
        $cached = false;
        return $cached;
    }

    $disabledFunctions = (string)ini_get('disable_functions');
    if ($disabledFunctions !== '') {
        $disabledList = array_map('trim', explode(',', $disabledFunctions));
        if (in_array('exec', $disabledList, true)) {
            $cached = false;
            return $cached;
        }
    }

    $output = [];
    $exitCode = 1;
    @exec('node --version 2>/dev/null', $output, $exitCode);
    $cached = ($exitCode === 0);
    return $cached;
}

function connectors_generate_run_id(int $connectorId): string
{
    $random = bin2hex(random_bytes(4));
    return 'run-' . date('YmdHis') . '-c' . max(0, $connectorId) . '-' . $random;
}


function connectors_ensure_run_trace_tables(mysqli $dbcnx): void
{
    $runsSql = "
        CREATE TABLE IF NOT EXISTS connector_operation_runs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            connector_id INT UNSIGNED NOT NULL,
            run_id VARCHAR(96) NOT NULL,
            test_operation VARCHAR(64) NOT NULL DEFAULT 'report',
            status VARCHAR(32) NOT NULL DEFAULT 'error',
            message TEXT NULL,
            target_table VARCHAR(128) NOT NULL DEFAULT '',
            started_at DATETIME NOT NULL,
            finished_at DATETIME NOT NULL,
            duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
            created_by INT NOT NULL DEFAULT 0,
            trace_log_json LONGTEXT NULL,
            step_log_json LONGTEXT NULL,
            execution_plan_json LONGTEXT NULL,
            chain_status_json LONGTEXT NULL,
            artifacts_dir TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_run_id (run_id),
            KEY idx_connector_created (connector_id, created_at),
            KEY idx_connector_status_created (connector_id, status, created_at),
            KEY idx_test_operation_created (test_operation, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ";

    if (!$dbcnx->query($runsSql)) {
        error_log('connector_operation_runs schema create error: ' . $dbcnx->error);
    }

    $eventsSql = "
        CREATE TABLE IF NOT EXISTS connector_operation_run_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            run_id VARCHAR(96) NOT NULL,
            connector_id INT UNSIGNED NOT NULL,
            event_index INT UNSIGNED NOT NULL DEFAULT 0,
            event_source VARCHAR(16) NOT NULL DEFAULT 'trace',
            operation_id VARCHAR(96) NOT NULL DEFAULT '',
            stage VARCHAR(96) NOT NULL DEFAULT '',
            step_name VARCHAR(255) NOT NULL DEFAULT '',
            status VARCHAR(32) NOT NULL DEFAULT '',
            duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
            message TEXT NULL,
            meta_json LONGTEXT NULL,
            event_time DATETIME NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_run_event_index (run_id, event_index),
            KEY idx_connector_event_time (connector_id, event_time),
            KEY idx_operation_event_time (operation_id, event_time),
            KEY idx_status_event_time (status, event_time)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ";

    if (!$dbcnx->query($eventsSql)) {
        error_log('connector_operation_run_events schema create error: ' . $dbcnx->error);
    }
}

function connectors_persist_run_trace(mysqli $dbcnx, array $payload): bool
{
    connectors_ensure_run_trace_tables($dbcnx);

    $connectorId = (int)($payload['connector_id'] ?? 0);
    $runId = trim((string)($payload['run_id'] ?? ''));
    if ($connectorId <= 0 || $runId === '') {
        return false;
    }

    $testOperation = trim((string)($payload['test_operation'] ?? 'report')) ?: 'report';
    $status = trim((string)($payload['status'] ?? 'error')) ?: 'error';
    $message = trim((string)($payload['message'] ?? ''));
    $targetTable = trim((string)($payload['target_table'] ?? ''));
    $createdBy = (int)($payload['created_by'] ?? 0);
    $startedAt = trim((string)($payload['started_at'] ?? date('Y-m-d H:i:s')));
    $finishedAt = trim((string)($payload['finished_at'] ?? date('Y-m-d H:i:s')));
    $durationMs = max(0, (int)($payload['duration_ms'] ?? 0));
    $artifactsDir = trim((string)($payload['artifacts_dir'] ?? ''));

    $traceLog = isset($payload['trace_log']) && is_array($payload['trace_log']) ? $payload['trace_log'] : [];
    $stepLog = isset($payload['step_log']) && is_array($payload['step_log']) ? $payload['step_log'] : [];
    $executionPlan = isset($payload['execution_plan']) && is_array($payload['execution_plan']) ? $payload['execution_plan'] : [];
    $chainStatus = isset($payload['chain_status']) && is_array($payload['chain_status']) ? $payload['chain_status'] : [];

    $traceLogJson = json_encode($traceLog, JSON_UNESCAPED_UNICODE);
    $stepLogJson = json_encode($stepLog, JSON_UNESCAPED_UNICODE);
    $executionPlanJson = json_encode($executionPlan, JSON_UNESCAPED_UNICODE);
    $chainStatusJson = json_encode($chainStatus, JSON_UNESCAPED_UNICODE);

    if ($traceLogJson === false || $stepLogJson === false || $executionPlanJson === false || $chainStatusJson === false) {
        return false;
    }

    $dbcnx->begin_transaction();
    try {
        $runSql = 'INSERT INTO connector_operation_runs (connector_id, run_id, test_operation, status, message, target_table, started_at, finished_at, duration_ms, created_by, trace_log_json, step_log_json, execution_plan_json, chain_status_json, artifacts_dir) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE connector_id = VALUES(connector_id), test_operation = VALUES(test_operation), status = VALUES(status), message = VALUES(message), target_table = VALUES(target_table), started_at = VALUES(started_at), finished_at = VALUES(finished_at), duration_ms = VALUES(duration_ms), created_by = VALUES(created_by), trace_log_json = VALUES(trace_log_json), step_log_json = VALUES(step_log_json), execution_plan_json = VALUES(execution_plan_json), chain_status_json = VALUES(chain_status_json), artifacts_dir = VALUES(artifacts_dir)';
        $runStmt = $dbcnx->prepare($runSql);
        if (!$runStmt) {
            throw new RuntimeException('prepare connector_operation_runs failed');
        }

        $runStmt->bind_param(
            'isssssssissssss',
            $connectorId,
            $runId,
            $testOperation,
            $status,
            $message,
            $targetTable,
            $startedAt,
            $finishedAt,
            $durationMs,
            $createdBy,
            $traceLogJson,
            $stepLogJson,
            $executionPlanJson,
            $chainStatusJson,
            $artifactsDir
        );
        if (!$runStmt->execute()) {
            $error = $runStmt->error;
            $runStmt->close();
            throw new RuntimeException('execute connector_operation_runs failed: ' . $error);
        }
        $runStmt->close();

        $deleteStmt = $dbcnx->prepare('DELETE FROM connector_operation_run_events WHERE run_id = ?');
        if (!$deleteStmt) {
            throw new RuntimeException('prepare connector_operation_run_events delete failed');
        }
        $deleteStmt->bind_param('s', $runId);
        $deleteStmt->execute();
        $deleteStmt->close();

        $eventSql = 'INSERT INTO connector_operation_run_events (run_id, connector_id, event_index, event_source, operation_id, stage, step_name, status, duration_ms, message, meta_json, event_time) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
        $eventStmt = $dbcnx->prepare($eventSql);
        if (!$eventStmt) {
            throw new RuntimeException('prepare connector_operation_run_events insert failed');
        }

        $eventIndex = 0;
        $appendEvent = static function (array $event, string $source) use (&$eventIndex, $eventStmt, $runId, $connectorId): void {
            $eventTime = trim((string)($event['time'] ?? ''));
            if ($eventTime === '') {
                $eventTime = date('Y-m-d H:i:s');
            } else {
                $timestamp = strtotime($eventTime);
                $eventTime = $timestamp !== false ? date('Y-m-d H:i:s', $timestamp) : date('Y-m-d H:i:s');
            }

            $operationId = trim((string)($event['operation_id'] ?? ''));
            $stage = trim((string)($event['stage'] ?? ($event['step'] ?? '')));
            $stepName = trim((string)($event['step'] ?? ''));
            $status = trim((string)($event['status'] ?? ''));
            $durationMs = max(0, (int)($event['duration_ms'] ?? (($event['meta']['duration_ms'] ?? 0))));
            $message = trim((string)($event['message'] ?? ''));
            $meta = isset($event['meta']) && is_array($event['meta']) ? $event['meta'] : [];
            $metaJson = json_encode($meta, JSON_UNESCAPED_UNICODE);
            if ($metaJson === false) {
                $metaJson = '{}';
            }

            $index = $eventIndex;
            $eventStmt->bind_param('siisssssisss', $runId, $connectorId, $index, $source, $operationId, $stage, $stepName, $status, $durationMs, $message, $metaJson, $eventTime);
            if (!$eventStmt->execute()) {
                throw new RuntimeException('execute connector_operation_run_events failed: ' . $eventStmt->error);
            }
            $eventIndex++;
        };

        foreach ($traceLog as $traceEvent) {
            if (is_array($traceEvent)) {
                $appendEvent($traceEvent, 'trace');
            }
        }

        foreach ($stepLog as $stepEvent) {
            if (is_array($stepEvent)) {
                $appendEvent($stepEvent, 'step');
            }
        }

        $eventStmt->close();
        $dbcnx->commit();
        return true;
    } catch (Throwable $e) {
        $dbcnx->rollback();
        error_log('connectors_persist_run_trace error: ' . $e->getMessage());
        return false;
    }
}


function connectors_append_trace_event(array &$traceLog, string $runId, string $operationId, string $stage, string $status, string $message, array $meta = []): void
{
    $traceLog[] = [
        'time' => date('c'),
        'run_id' => $runId,
        'event_type' => 'trace',
        'operation_id' => $operationId,
        'stage' => $stage,
        'status' => $status,
        'message' => $message,
        'meta' => $meta,
    ];
}


function connectors_append_operation_executed_event(
    array &$traceLog,
    string $runId,
    string $operationId,
    string $stage,
    string $status,
    string $message,
    int $durationMs = 0,
    ?string $startedAt = null,
    ?string $finishedAt = null,
    array $meta = []
): void {
    $resolvedStartedAt = $startedAt ?: date('c');
    $resolvedFinishedAt = $finishedAt ?: date('c');
    $traceLog[] = [
        'time' => $resolvedFinishedAt,
        'run_id' => $runId,
        'event_type' => 'operation_executed',
        'operation_id' => $operationId,
        'stage' => $stage,
        'status' => $status,
        'started_at' => $resolvedStartedAt,
        'finished_at' => $resolvedFinishedAt,
        'duration_ms' => max(0, $durationMs),
        'message' => $message,
        'meta' => $meta,
    ];
}

function connectors_build_chain_status_map(array $executionPlan, string $currentOperationId, bool $isSuccess, array $traceLog = []): array
{
    $stageByOperation = [];
    $operationIds = [];

    foreach ((array)($executionPlan['before'] ?? []) as $operationId) {
        $normalized = trim((string)$operationId);
        if ($normalized === '') {
            continue;
        }
        $operationIds[] = $normalized;
        $stageByOperation[$normalized] = 'before';
    }


    $mainOperationId = trim((string)($executionPlan['main'] ?? ''));
    if ($mainOperationId !== '') {
        $operationIds[] = $mainOperationId;
        $stageByOperation[$mainOperationId] = 'main';
    }

    foreach ((array)($executionPlan['during'] ?? []) as $group) {
        if (!is_array($group)) {
            continue;
        }
        foreach ($group as $operationId) {

            $normalized = trim((string)$operationId);
            if ($normalized === '') {
                continue;
            }
            $operationIds[] = $normalized;
            $stageByOperation[$normalized] = 'during';
        }
    }

    foreach ((array)($executionPlan['finally'] ?? []) as $operationId) {
        $normalized = trim((string)$operationId);
        if ($normalized === '') {
            continue;
        }
        $operationIds[] = $normalized;
        $stageByOperation[$normalized] = 'finally';
    }

    $operationIds = array_values(array_filter(array_unique($operationIds), static fn(string $v): bool => $v !== ''));
    if (empty($operationIds)) {
        return [];
    }

    $statusByOperation = [];
    foreach ($operationIds as $operationId) {
        $statusByOperation[$operationId] = 'pending';
    }

    $timeline = [];
    $currentEvent = null;
    foreach ($traceLog as $event) {
        if (!is_array($event)) {
            continue;
        }

        $eventType = trim((string)($event['event_type'] ?? ''));
        if ($eventType !== 'operation_executed') {
            continue;
        }

        $eventOperationId = trim((string)($event['operation_id'] ?? ''));
        if ($eventOperationId === '' || !isset($statusByOperation[$eventOperationId])) {
            continue;
        }

        $eventStatus = strtolower(trim((string)($event['status'] ?? 'pending')));
        if (!in_array($eventStatus, ['success', 'failed', 'pending'], true)) {
            $eventStatus = 'pending';
        }
        $statusByOperation[$eventOperationId] = $eventStatus;

        $timelineEvent = [
            'event_type' => 'operation_executed',
            'operation_id' => $eventOperationId,
            'stage' => trim((string)($event['stage'] ?? ($stageByOperation[$eventOperationId] ?? 'main'))),
            'status' => $eventStatus,
            'started_at' => (string)($event['started_at'] ?? ''),
            'finished_at' => (string)($event['finished_at'] ?? ($event['time'] ?? '')),
            'duration_ms' => max(0, (int)($event['duration_ms'] ?? 0)),
            'message' => (string)($event['message'] ?? ''),
        ];

        $timeline[] = $timelineEvent;
        $currentEvent = $timelineEvent;
    }

    $statusMap = [];
    foreach ($operationIds as $operationId) {
        $state = $statusByOperation[$operationId] ?? 'pending';

        $statusMap[] = [
            'operation_id' => $operationId,
            'stage' => $stageByOperation[$operationId] ?? 'main',
            'status' => $state,
        ];
    }

    $stages = [
        'before' => ['executed' => 0, 'success' => 0, 'failed' => 0],
        'during' => ['executed' => 0, 'success' => 0, 'failed' => 0],
        'main' => ['executed' => 0, 'success' => 0, 'failed' => 0],
        'finally' => ['executed' => 0, 'success' => 0, 'failed' => 0],
    ];

    foreach ($timeline as $event) {
        $stage = trim((string)($event['stage'] ?? ''));
        if (!isset($stages[$stage])) {
            continue;
        }
        $stages[$stage]['executed']++;
        if ($event['status'] === 'success') {
            $stages[$stage]['success']++;
        }
        if ($event['status'] === 'failed') {
            $stages[$stage]['failed']++;
        }
    }

    return [
        'operations' => $statusMap,
        'stages' => $stages,
        'current_event' => $currentEvent,
        'timeline' => $timeline,
    ];
}


function connectors_download_report_file(array $connector, array $reportCfg, ?string $periodFrom, ?string $periodTo): array
{
    $ext = strtolower(trim((string)($reportCfg['file_extension'] ?? 'xlsx')));
    if ($ext === '') {
        $ext = 'xlsx';
    }

    $today = date('Y-m-d');
    $defaultDateFrom = date('Y-m-d', strtotime('-2 years', strtotime($today)));
    $resolvedDateFrom = $periodFrom ?? $defaultDateFrom;
    $resolvedDateTo = $periodTo ?? $today;

    $vars = [
        'date_from' => $resolvedDateFrom,
        'date_to' => $resolvedDateTo,
        'test_period_from' => $resolvedDateFrom,
        'test_period_to' => $resolvedDateTo,
        'today' => $today,
        'today_minus_2y' => $defaultDateFrom,
        'base_url' => trim((string)($connector['base_url'] ?? '')),
        'login' => trim((string)($connector['auth_username'] ?? '')),
        'password' => trim((string)($connector['auth_password'] ?? '')),
    ];


    $logStepsEnabled = !empty($reportCfg['log_steps']);
    $stepLog = [];
    $appendStepLog = static function (string $step, string $message, array $meta = []) use (&$stepLog, $logStepsEnabled): void {
        if (!$logStepsEnabled) {
            return;
        }

        $stepLog[] = [
            'time' => date('c'),
            'step' => $step,
            'message' => $message,
            'meta' => $meta,
        ];
    };

    if (($reportCfg['download_mode'] ?? 'browser') === 'browser') {
        $reportSteps = isset($reportCfg['steps']) && is_array($reportCfg['steps']) ? $reportCfg['steps'] : [];
        $loginSteps = connectors_extract_browser_login_steps($connector);
        $steps = array_merge($loginSteps, $reportSteps);
        if (empty($steps)) {
            throw new InvalidArgumentException('Для режима browser заполните report_steps_json или browser_login_steps в scenario_json');
        }

        $scriptPath = realpath(__DIR__ . '/../../scripts/test_connector_operations_browser.js');
        if (!$scriptPath) {
            throw new RuntimeException('Не найден browser script для теста скачивания');
        }


        $tempDir = __DIR__ . '/../../scripts/_tmp';
        if (!is_dir($tempDir)) {
            @mkdir($tempDir, 0775, true);
        }
        connectors_clear_directory($tempDir, 21600);

        $payload = [
            'steps' => $steps,
            'vars' => $vars,
            'file_extension' => $ext,
            'ssl_ignore' => !empty($connector['ssl_ignore']),
            'cookies' => (string)($connector['auth_cookies'] ?? ''),
            'auth_token' => (string)($connector['auth_token'] ?? ''),
            'temp_dir' => realpath($tempDir) ?: $tempDir,
        ];

        $cmd = 'node ' . escapeshellarg($scriptPath) . ' ' . escapeshellarg((string)json_encode($payload, JSON_UNESCAPED_UNICODE));
        $output = connectors_run_shell_command_with_timeout($cmd . ' 2>&1', 90);
        $decoded = json_decode(trim((string)$output), true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Не удалось выполнить browser-тест скачивания: ' . trim((string)$output));
        }
        if (empty($decoded['ok'])) {
           $browserStepLog = isset($decoded['step_log']) && is_array($decoded['step_log']) ? $decoded['step_log'] : [];
           $browserArtifactsDir = trim((string)($decoded['artifacts_dir'] ?? ''));
           if (!empty($browserStepLog)) {
              throw new ConnectorStepLogException((string)($decoded['message'] ?? 'Browser test failed'), $browserStepLog, 0, null, $browserArtifactsDir);
              }
            throw new RuntimeException((string)($decoded['message'] ?? 'Browser test failed'));
        }

        $filePath = (string)($decoded['file_path'] ?? '');
        if ($filePath === '' || !is_file($filePath)) {
            throw new RuntimeException('Browser-тест не вернул путь к скачанному файлу');
        }

        return [
            'file_path' => $filePath,
            'file_size' => (int)filesize($filePath),
            'file_extension' => $ext,
            'download_mode' => 'browser',
            'step_log' => isset($decoded['step_log']) && is_array($decoded['step_log']) ? $decoded['step_log'] : [],
            'artifacts_dir' => trim((string)($decoded['artifacts_dir'] ?? '')),
        ];
    }

    $curlCfg = isset($reportCfg['curl_config']) && is_array($reportCfg['curl_config']) ? $reportCfg['curl_config'] : [];

    $urlTemplate = trim((string)($curlCfg['url'] ?? ''));
    if ($urlTemplate === '') {
        throw new InvalidArgumentException('Для режима cURL нужен JSON-объект в report_curl_config_json с полем url (например {"url":"https://.../export","method":"POST"}).');
    }

    $method = strtoupper(trim((string)($curlCfg['method'] ?? 'GET')));
    if ($method === '') {
        $method = 'GET';
    }

    $cookieParts = [];
    if (!empty($connector['auth_cookies'])) {
        $cookieParts[] = trim((string)$connector['auth_cookies']);
    }

    if (isset($curlCfg['login']) && is_array($curlCfg['login'])) {
       $loginCfg = $curlCfg['login'];
        $loginMethod = strtoupper(trim((string)($loginCfg['method'] ?? 'GET')));
        if ($loginMethod === '') {
            $loginMethod = 'GET';
        }
        $loginUrlRaw = trim((string)($loginCfg['url'] ?? ''));
        $loginOrigin = connectors_url_origin((string)connectors_apply_vars($loginUrlRaw, $vars));
        if (!isset($loginCfg['headers']) || !is_array($loginCfg['headers'])) {
            $loginCfg['headers'] = [];
        }
        if ($loginOrigin !== '' && !connectors_cfg_has_header($loginCfg, 'Origin')) {
            $loginCfg['headers']['Origin'] = $loginOrigin;
        }
        if ($loginUrlRaw !== '' && !connectors_cfg_has_header($loginCfg, 'Referer')) {
            $loginCfg['headers']['Referer'] = $loginUrlRaw;
        }
        if ($loginMethod !== 'GET' && !connectors_cfg_has_header($loginCfg, 'Content-Type')) {
            $loginCfg['headers']['Content-Type'] = 'application/x-www-form-urlencoded';
        }
        $csrfNeeded = connectors_cfg_requires_csrf_preflight($loginCfg);
        if (!$csrfNeeded && array_key_exists('csrf_preflight', $loginCfg)) {
            $csrfNeeded = !empty($loginCfg['csrf_preflight']);
        }
        if (!$csrfNeeded && $loginMethod !== 'GET') {
            $csrfNeeded = true;
        }
        if ($csrfNeeded) {
            $csrfCfg = [
                'url' => (string)($loginCfg['csrf_url'] ?? $loginCfg['url'] ?? ''),
                'method' => (string)($loginCfg['csrf_method'] ?? 'GET'),
            ];
            if (isset($loginCfg['csrf_headers']) && is_array($loginCfg['csrf_headers'])) {
                $csrfCfg['headers'] = $loginCfg['csrf_headers'];
            }
            if (isset($loginCfg['csrf_body']) && is_array($loginCfg['csrf_body'])) {
                $csrfCfg['body'] = $loginCfg['csrf_body'];
            }

            if (!connectors_cfg_has_cookie_header($csrfCfg) && !empty($cookieParts)) {
                $csrfHeaders = isset($csrfCfg['headers']) && is_array($csrfCfg['headers']) ? $csrfCfg['headers'] : [];
                $csrfHeaders['Cookie'] = connectors_merge_cookie_parts($cookieParts);
                $csrfCfg['headers'] = $csrfHeaders;
            }

            $appendStepLog('login_preflight', 'Выполняем preflight для получения CSRF/cookies', [
                'url' => (string)($csrfCfg['url'] ?? ''),
                'method' => strtoupper((string)($csrfCfg['method'] ?? 'GET')),
            ]);

            try {
                $csrfResponse = connectors_curl_request($csrfCfg, $vars, !empty($connector['ssl_ignore']));
            } catch (Throwable $e) {
                $appendStepLog('login_preflight', 'Preflight завершился network/cURL ошибкой', [
                    'error' => $e->getMessage(),
                ]);
                throw new ConnectorStepLogException('Network ошибка preflight перед login через cURL: ' . $e->getMessage(), $stepLog, 0, $e);
            }
            $csrfHttp = (int)($csrfResponse['http_code'] ?? 0);
            $appendStepLog('login_preflight', 'Preflight выполнен', ['http_code' => $csrfHttp]);
            if ($csrfHttp >= 400) {
                throw new ConnectorStepLogException('Ошибка preflight перед login через cURL: HTTP ' . $csrfHttp, $stepLog);
            }

            $csrfCookies = trim((string)($csrfResponse['cookies'] ?? ''));
            if ($csrfCookies !== '') {
                $cookieParts[] = $csrfCookies;
            }

            $csrfToken = connectors_parse_csrf_token_from_html((string)($csrfResponse['body'] ?? ''));
            if ($csrfToken !== '') {
                $vars['csrf_token'] = $csrfToken;
                $vars['_token'] = $csrfToken;
            }

            $loginBodyRaw = (string)($csrfResponse['body'] ?? '');
            $loginFieldsMeta = connectors_detect_login_fields($loginBodyRaw);
            $candidateLoginField = (string)($loginFieldsMeta['candidate_login_fields'][0] ?? '');
            if (
                $candidateLoginField !== ''
                && isset($loginCfg['body'])
                && is_array($loginCfg['body'])
                && array_key_exists('email', $loginCfg['body'])
                && !array_key_exists($candidateLoginField, $loginCfg['body'])
            ) {
                $loginCfg['body'][$candidateLoginField] = $loginCfg['body']['email'];
                unset($loginCfg['body']['email']);
                $appendStepLog('login_preflight', 'Автоопределено поле логина из формы', [
                    'selected_field' => $candidateLoginField,
                ]);
            }

            $preflightCookies = connectors_merge_cookie_parts($cookieParts);
            $xsrfToken = connectors_parse_xsrf_token_from_cookie_string($preflightCookies);
            if ($xsrfToken !== '') {
                $vars['xsrf_token'] = $xsrfToken;
                if (empty($vars['csrf_token'])) {
                    $vars['csrf_token'] = $xsrfToken;
                    $vars['_token'] = $xsrfToken;
                }
            }
        }

        if (!connectors_cfg_has_cookie_header($loginCfg) && !empty($cookieParts)) {
            $loginHeaders = isset($loginCfg['headers']) && is_array($loginCfg['headers']) ? $loginCfg['headers'] : [];
            $loginHeaders['Cookie'] = connectors_merge_cookie_parts($cookieParts);
            $loginCfg['headers'] = $loginHeaders;
        }

        $appendStepLog('login', 'Выполняем login-запрос через cURL', [
            'url' => (string)($loginCfg['url'] ?? ''),
            'method' => $loginMethod,
        ]);
        try {
            $loginResponse = connectors_curl_request($loginCfg, $vars, !empty($connector['ssl_ignore']));
        } catch (Throwable $e) {
            $appendStepLog('login', 'Login-запрос завершился network/cURL ошибкой', [
                'error' => $e->getMessage(),
            ]);
            throw new ConnectorStepLogException('Network ошибка логина через cURL: ' . $e->getMessage(), $stepLog, 0, $e);
        }
        $loginHttp = (int)($loginResponse['http_code'] ?? 0);
        $appendStepLog('login', 'Login-запрос выполнен', ['http_code' => $loginHttp]);
        if ($loginHttp >= 400) {
            $loginBodyRaw = (string)($loginResponse['body'] ?? '');
            $loginBodyPreview = trim(preg_replace('/\s+/u', ' ', mb_substr($loginBodyRaw, 0, 600, 'UTF-8')) ?? '');
            $appendStepLog('login', 'Login вернул HTTP >= 400', [
                'http_code' => $loginHttp,
                'cookies_present' => !empty($cookieParts),
                'csrf_token_present' => !empty($vars['csrf_token']),
                'xsrf_token_present' => !empty($vars['xsrf_token']),
                'response_body_preview' => $loginBodyPreview,
            ]);
            throw new ConnectorStepLogException('Ошибка логина через cURL: HTTP ' . $loginHttp, $stepLog);
        }

        $successCfg = isset($loginCfg['success']) && is_array($loginCfg['success'])
            ? $loginCfg['success']
            : [];
        $successSelector = trim((string)($successCfg['selector'] ?? ''));
        if ($successSelector !== '') {
            $loginBody = (string)($loginResponse['body'] ?? '');
            $parts = array_values(array_filter(array_map('trim', explode('*', $successSelector)), static fn($part) => $part !== ''));
            $found = true;
            foreach ($parts as $part) {
                if (stripos($loginBody, $part) === false) {
                    $found = false;
                    break;
                }
            }
            $locationHeaders = connectors_parse_location_headers(isset($loginResponse['headers']) && is_array($loginResponse['headers']) ? $loginResponse['headers'] : []);
            $redirectDetected = $loginHttp >= 300 && $loginHttp < 400 && !empty($locationHeaders);
            $redirectToLogin = false;
            if ($redirectDetected) {
                foreach ($locationHeaders as $locationHeader) {
                    if (connectors_is_login_redirect_target((string)$locationHeader, (string)($loginCfg['url'] ?? ''))) {
                        $redirectToLogin = true;
                        break;
                    }
                }
            }
            $matchedByRedirect = !$found && $redirectDetected && !$redirectToLogin;
            if ($matchedByRedirect) {
                $found = true;
            }
            $appendStepLog('login', 'Проверка login.success.selector', [
                'selector' => $successSelector,
                'matched' => $found,
                'matched_by_redirect' => $matchedByRedirect,
                'redirect_to_login' => $redirectToLogin,
                'http_code' => $loginHttp,
                'location_headers' => $locationHeaders,
            ]);

            if (!$found) {
                $loginBodyPreview = trim(preg_replace('/\s+/u', ' ', mb_substr($loginBody, 0, 500, 'UTF-8')) ?? '');
                $invalidCredentialsHint = false;
                $loginErrorPatterns = [
                    'invalid password',
                    'invalid credentials',
                    'incorrect password',
                    'wrong password',
                    'неверный пароль',
                    'неверный логин',
                    'ошибка авторизации',
                    'authentication failed',
                    'login failed',
                ];
                $loginBodyLower = function_exists('mb_strtolower')
                    ? mb_strtolower($loginBody, 'UTF-8')
                    : strtolower($loginBody);
                foreach ($loginErrorPatterns as $pattern) {
                    if ($pattern !== '' && mb_strpos($loginBodyLower, $pattern, 0, 'UTF-8') !== false) {
                        $invalidCredentialsHint = true;
                        break;
                    }
                }
                $appendStepLog('login', 'login.success.selector не найден', [
                    'response_body_preview' => $loginBodyPreview,
                    'possible_invalid_credentials' => $invalidCredentialsHint,
                ]);
                $errorMessage = $invalidCredentialsHint
                    ? 'Логин через cURL не прошёл проверку success.selector (похоже на неверный логин/пароль)'
                    : 'Логин через cURL не прошёл проверку success.selector';
                throw new ConnectorStepLogException($errorMessage, $stepLog);
            }
        } else {
            $locationHeaders = connectors_parse_location_headers(isset($loginResponse['headers']) && is_array($loginResponse['headers']) ? $loginResponse['headers'] : []);
            foreach ($locationHeaders as $locationHeader) {
                if (connectors_is_login_redirect_target((string)$locationHeader, (string)($loginCfg['url'] ?? ''))) {
                    throw new ConnectorStepLogException('Логин через cURL вернул редирект обратно на страницу login', $stepLog);
                }
            }
        }
        $loginCookies = trim((string)($loginResponse['cookies'] ?? ''));
        if ($loginCookies !== '') {
            $cookieParts[] = $loginCookies;
        }

        $postLoginCookies = connectors_merge_cookie_parts($cookieParts);
        $xsrfToken = connectors_parse_xsrf_token_from_cookie_string($postLoginCookies);
        if ($xsrfToken !== '') {
            $vars['xsrf_token'] = $xsrfToken;
            if (empty($vars['csrf_token'])) {
                $vars['csrf_token'] = $xsrfToken;
                $vars['_token'] = $xsrfToken;
            }
        }

        $loginBody = (string)($loginResponse['body'] ?? '');
        $csrfToken = connectors_parse_csrf_token_from_html($loginBody);
        if ($csrfToken !== '') {
            $vars['csrf_token'] = $csrfToken;
            $vars['_token'] = $csrfToken;
        }
    }


    $requestCsrfNeeded = connectors_cfg_requires_csrf_preflight($curlCfg);
    if ($requestCsrfNeeded || !empty($curlCfg['csrf_url']) || $method !== 'GET') {
        $requestCsrfCfg = [
            'url' => (string)($curlCfg['csrf_url'] ?? $urlTemplate),
            'method' => (string)($curlCfg['csrf_method'] ?? 'GET'),
        ];
        if (isset($curlCfg['csrf_headers']) && is_array($curlCfg['csrf_headers'])) {
            $requestCsrfCfg['headers'] = $curlCfg['csrf_headers'];
        }
        if (isset($curlCfg['csrf_body']) && is_array($curlCfg['csrf_body'])) {
            $requestCsrfCfg['body'] = $curlCfg['csrf_body'];
        }

        if (!connectors_cfg_has_cookie_header($requestCsrfCfg) && !empty($cookieParts)) {
            $requestCsrfHeaders = isset($requestCsrfCfg['headers']) && is_array($requestCsrfCfg['headers'])
                ? $requestCsrfCfg['headers']
                : [];
            $requestCsrfHeaders['Cookie'] = connectors_merge_cookie_parts($cookieParts);
            $requestCsrfCfg['headers'] = $requestCsrfHeaders;
        }

        $appendStepLog('request_preflight', 'Выполняем preflight перед скачиванием отчёта через cURL', [
            'url' => (string)($requestCsrfCfg['url'] ?? ''),
            'method' => strtoupper((string)($requestCsrfCfg['method'] ?? 'GET')),
        ]);

        try {
            $requestCsrfResponse = connectors_curl_request($requestCsrfCfg, $vars, !empty($connector['ssl_ignore']));
        } catch (Throwable $e) {
            $appendStepLog('request_preflight', 'Preflight перед скачиванием завершился network/cURL ошибкой', [
                'error' => $e->getMessage(),
            ]);
            throw new ConnectorStepLogException('Network ошибка preflight перед скачиванием через cURL: ' . $e->getMessage(), $stepLog, 0, $e);
        }
        $requestCsrfHttp = (int)($requestCsrfResponse['http_code'] ?? 0);
        $appendStepLog('request_preflight', 'Preflight перед скачиванием выполнен', ['http_code' => $requestCsrfHttp]);
        if ($requestCsrfHttp >= 400) {
            throw new ConnectorStepLogException('Ошибка preflight перед скачиванием через cURL: HTTP ' . $requestCsrfHttp, $stepLog);
        }

        $requestCsrfCookies = trim((string)($requestCsrfResponse['cookies'] ?? ''));
        if ($requestCsrfCookies !== '') {
            $cookieParts[] = $requestCsrfCookies;
        }

        $requestCsrfToken = connectors_parse_csrf_token_from_html((string)($requestCsrfResponse['body'] ?? ''));
        if ($requestCsrfToken !== '') {
            $vars['csrf_token'] = $requestCsrfToken;
            $vars['_token'] = $requestCsrfToken;
        }
    }

    $combinedCookies = connectors_merge_cookie_parts($cookieParts);
    if ($combinedCookies !== '') {
        $xsrfToken = connectors_parse_xsrf_token_from_cookie_string($combinedCookies);
        if ($xsrfToken !== '') {
            $vars['xsrf_token'] = $xsrfToken;
            if (empty($vars['csrf_token'])) {
                $vars['csrf_token'] = $xsrfToken;
                $vars['_token'] = $xsrfToken;
            }
        }
    }

    $url = (string)connectors_apply_vars($urlTemplate, $vars);
    $followRedirects = array_key_exists('follow_redirects', $curlCfg)
        ? !empty($curlCfg['follow_redirects'])
        : false;
    $headers = [];
    $hasAuthorizationHeader = false;
    $hasCookieHeader = false;
    $hasXsrfTokenHeader = false;
    $hasCsrfTokenHeader = false;
    if (isset($curlCfg['headers']) && is_array($curlCfg['headers'])) {
        foreach ($curlCfg['headers'] as $k => $v) {
            $headerName = trim((string)$k);
            $headerValue = (string)connectors_apply_vars((string)$v, $vars);
            if (strcasecmp($headerName, 'Authorization') === 0) {
                $hasAuthorizationHeader = true;
            }
            if (strcasecmp($headerName, 'Cookie') === 0) {
                $hasCookieHeader = true;

                $headerValue = connectors_merge_cookie_parts([
                    $headerValue,
                    connectors_merge_cookie_parts($cookieParts),
                ]);
            }
            if (strcasecmp($headerName, 'X-XSRF-TOKEN') === 0) {
                $hasXsrfTokenHeader = true;
            }
            if (strcasecmp($headerName, 'X-CSRF-TOKEN') === 0) {
                $hasCsrfTokenHeader = true;
            }
            $headers[] = $headerName . ': ' . $headerValue;
        }
    }

    $bodyData = [];
    if (isset($curlCfg['body']) && is_array($curlCfg['body'])) {
        foreach ($curlCfg['body'] as $k => $v) {
            $bodyData[$k] = connectors_apply_vars($v, $vars);
        }
    }
    if ($method !== 'GET' && !array_key_exists('_token', $bodyData) && !empty($vars['_token'])) {
        $bodyData['_token'] = (string)$vars['_token'];
    }
    if (!$hasAuthorizationHeader && !empty($connector['auth_token'])) {
        $headers[] = 'Authorization: Bearer ' . trim((string)$connector['auth_token']);
    }

    if (!$hasCookieHeader && !empty($cookieParts)) {
        $headers[] = 'Cookie: ' . connectors_merge_cookie_parts($cookieParts);
    }
    if ($method !== 'GET' && !$hasXsrfTokenHeader && !empty($vars['xsrf_token'])) {
        $headers[] = 'X-XSRF-TOKEN: ' . (string)$vars['xsrf_token'];
    }
    if ($method !== 'GET' && !$hasCsrfTokenHeader && !empty($vars['csrf_token'])) {
        $headers[] = 'X-CSRF-TOKEN: ' . (string)$vars['csrf_token'];
    }

    $appendStepLog('download_prepare', 'Подготовлен запрос на скачивание файла', [
        'url' => $url,
        'method' => $method,
        'follow_redirects' => $followRedirects,
        'timeout_seconds' => connectors_resolve_curl_timeout_seconds($curlCfg, 120),
        'has_cookie_header' => $hasCookieHeader || !empty($cookieParts),
        'cookies_merged_with_config_header' => $hasCookieHeader && !empty($cookieParts),
    ]);

    $tmpFile = tempnam(sys_get_temp_dir(), 'connector-report-');
    if ($tmpFile === false) {
        throw new RuntimeException('Не удалось создать временный файл');
    }
    $filePath = $tmpFile . '.' . $ext;
    @rename($tmpFile, $filePath);

    $fh = fopen($filePath, 'wb');
    if ($fh === false) {
        throw new RuntimeException('Не удалось открыть временный файл для записи');
    }

    $timeoutSeconds = connectors_resolve_curl_timeout_seconds($curlCfg, 120);
    $ch = curl_init();
    $responseHeaders = [];
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, $followRedirects);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSeconds);
    curl_setopt($ch, CURLOPT_FILE, $fh);
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, static function ($ch, $line) use (&$responseHeaders) {
        $responseHeaders[] = trim((string)$line);
        return strlen($line);
    });
    if (!empty($connector['ssl_ignore'])) {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    }
    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }
    if ($method !== 'GET') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        if (!empty($bodyData)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($bodyData));
        }
    }

    $ok = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $effectiveUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $redirectCount = (int)curl_getinfo($ch, CURLINFO_REDIRECT_COUNT);
    $curlErr = curl_error($ch);
    $curlErrNo = (int)curl_errno($ch);
    curl_close($ch);
    fclose($fh);


    $locationHeaders = connectors_parse_location_headers($responseHeaders);
    $responseContentType = connectors_parse_content_type_header($responseHeaders);
    $isRedirectHttp = $httpCode >= 300 && $httpCode < 400;
    if (!$ok || $httpCode >= 400 || $isRedirectHttp) {
        @unlink($filePath);
        $appendStepLog('download', 'Скачивание завершилось ошибкой', [
            'http_code' => $httpCode,
            'effective_url' => $effectiveUrl,
            'request_url' => $url,
            'redirect_count' => $redirectCount,
            'location_headers' => $locationHeaders,
            'curl_error' => $curlErr,
            'curl_errno' => $curlErrNo,
        ]);
        $redirectNote = $isRedirectHttp && !empty($locationHeaders)
            ? (' redirect_to=' . (string)$locationHeaders[0])
            : '';
        $curlErrorNote = trim($curlErr) !== ''
            ? (' cURL(' . $curlErrNo . '): ' . $curlErr)
            : '';
        if ($httpCode === 0 && $curlErrNo > 0) {
            $networkHint = connectors_build_curl_network_error_hint($curlErrNo, $effectiveUrl, $url);
            $networkHintNote = $networkHint !== '' ? (' Hint: ' . $networkHint) : '';
            throw new ConnectorStepLogException(
                'Network ошибка при скачивании через cURL:' . $curlErrorNote . $redirectNote . $networkHintNote,
                $stepLog
            );
        }
        throw new ConnectorStepLogException(
            'Ошибка скачивания через cURL: HTTP ' . $httpCode . $curlErrorNote . $redirectNote,
            $stepLog
        );
    }

    $expectedContentTypesRaw = isset($reportCfg['expected_content_types']) && is_array($reportCfg['expected_content_types'])
        ? $reportCfg['expected_content_types']
        : connectors_default_content_types_for_extension($ext);
    $expectedContentTypes = [];
    foreach ($expectedContentTypesRaw as $expectedType) {
        $expectedType = strtolower(trim((string)$expectedType));
        if ($expectedType !== '') {
            $expectedContentTypes[] = $expectedType;
        }
    }
    $expectedContentTypes = array_values(array_unique($expectedContentTypes));

    $contentTypeMismatch = false;
    if (!empty($expectedContentTypes) && $responseContentType !== '') {
        $isExpectedContentType = in_array($responseContentType, $expectedContentTypes, true);
        if (!$isExpectedContentType) {
            $appendStepLog('download', 'Скачивание вернуло неожиданный content-type', [
                'http_code' => $httpCode,
                'response_content_type' => $responseContentType,
                'expected_content_types' => $expectedContentTypes,
            ]);
            $contentTypeMismatch = true;
        }
    }

    $size = (int)filesize($filePath);
    if ($size <= 0) {
        @unlink($filePath);
        $appendStepLog('download', 'Скачивание завершилось пустым файлом', ['http_code' => $httpCode]);
        throw new ConnectorStepLogException('Скачанный файл пустой', $stepLog);
    }

    $fileValidation = connectors_validate_downloaded_report_file($filePath, $ext);
    $fileLooksValid = !empty($fileValidation['valid']);
    if (!$fileLooksValid) {
        @unlink($filePath);
        $appendStepLog('download', 'Скачивание вернуло файл с невалидной сигнатурой', [
            'file_extension' => $ext,
            'validation_reason' => (string)($fileValidation['reason'] ?? 'unknown'),
            'response_content_type' => $responseContentType,
        ]);
        throw new ConnectorStepLogException(
            'Скачанный файл не похож на ' . strtoupper($ext) . ' (' . (string)($fileValidation['reason'] ?? 'unknown') . ')',
            $stepLog
        );
    }

    if ($contentTypeMismatch) {
        $appendStepLog('download', 'Content-Type не совпал, но файл прошёл проверку сигнатуры и будет импортирован', [
            'response_content_type' => $responseContentType,
            'file_extension' => $ext,
        ]);
    }

    $appendStepLog('download', 'Файл успешно скачан', [
        'http_code' => $httpCode,
        'file_size' => $size,
        'file_path' => $filePath,
        'response_content_type' => $responseContentType,
    ]);

    return [
        'file_path' => $filePath,
        'file_size' => $size,
        'file_extension' => $ext,
        'download_mode' => 'curl',
        'step_log' => $logStepsEnabled ? $stepLog : [],
    ];
}

function connectors_import_csv_into_report_table(mysqli $dbcnx, string $tableName, string $filePath, int $connectorId, ?string $periodFrom, ?string $periodTo, array $fieldMapping): int
{
    $fh = fopen($filePath, 'rb');
    if ($fh === false) {
        throw new RuntimeException('Не удалось открыть CSV для импорта');
    }

    $header = fgetcsv($fh);
    if (!is_array($header) || empty($header)) {
        fclose($fh);
        throw new RuntimeException('CSV не содержит заголовок');
    }

    $headerMap = [];
    foreach ($header as $idx => $name) {
        $headerMap[trim((string)$name)] = (int)$idx;
    }

    if (empty($fieldMapping)) {
        fclose($fh);
        throw new InvalidArgumentException('Для CSV-импорта заполните "Маппинг полей"');
    }

    $safeTable = '`' . str_replace('`', '``', $tableName) . '`';
    $sql = 'INSERT INTO ' . $safeTable . ' (connector_id, period_from, period_to, payload_json, source_file) VALUES (?, ?, ?, ?, ?)';
    $stmt = $dbcnx->prepare($sql);
    if (!$stmt) {
        fclose($fh);
        throw new RuntimeException('DB error (prepare import): ' . $dbcnx->error);
    }

    $count = 0;
    while (($row = fgetcsv($fh)) !== false) {
        $payload = [];
        foreach ($fieldMapping as $targetField => $csvColumnName) {
            $csvColumnName = trim((string)$csvColumnName);
            if ($csvColumnName === '' || !array_key_exists($csvColumnName, $headerMap)) {
                $payload[$targetField] = null;
                continue;
            }
            $payload[$targetField] = $row[$headerMap[$csvColumnName]] ?? null;
        }

        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($payloadJson === false) {
            continue;
        }

        $sourceFile = basename($filePath);
        $stmt->bind_param('issss', $connectorId, $periodFrom, $periodTo, $payloadJson, $sourceFile);
        if ($stmt->execute()) {
            $count++;
        }
    }

    $stmt->close();
    fclose($fh);
    return $count;
}


function connectors_read_xlsx_rows(string $filePath): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('Расширение ZipArchive недоступно для XLSX-импорта');
    }

    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) {
        throw new RuntimeException('Не удалось открыть XLSX для импорта');
    }

    try {
        $sharedStrings = [];
        $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
        if (is_string($sharedXml) && $sharedXml !== '') {
            $sx = @simplexml_load_string($sharedXml);
            if ($sx !== false && isset($sx->si)) {
                foreach ($sx->si as $si) {
                    $text = '';
                    if (isset($si->t)) {
                        $text = (string)$si->t;
                    } elseif (isset($si->r)) {
                        foreach ($si->r as $run) {
                            $text .= (string)$run->t;
                        }
                    }
                    $sharedStrings[] = $text;
                }
            }
        }

        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        if (!is_string($sheetXml) || $sheetXml === '') {
            throw new RuntimeException('XLSX не содержит sheet1.xml');
        }

        $sheet = @simplexml_load_string($sheetXml);
        if ($sheet === false || !isset($sheet->sheetData->row)) {
            throw new RuntimeException('XLSX не содержит данных в первом листе');
        }

        $rows = [];
        foreach ($sheet->sheetData->row as $row) {
            $rowData = [];
            $nextCol = 0;

            foreach ($row->c as $cell) {
                $ref = (string)($cell['r'] ?? '');
                if ($ref !== '' && preg_match('/^([A-Z]+)\d+$/i', $ref, $m)) {
                    $letters = strtoupper($m[1]);
                    $idx = 0;
                    for ($i = 0; $i < strlen($letters); $i++) {
                        $idx = $idx * 26 + (ord($letters[$i]) - 64);
                    }
                    $cellCol = max(0, $idx - 1);
                    while ($nextCol < $cellCol) {
                        $rowData[] = '';
                        $nextCol++;
                    }
                }

                $type = (string)($cell['t'] ?? '');
                $value = '';
                if ($type === 'inlineStr') {
                    $value = isset($cell->is->t) ? (string)$cell->is->t : '';
                } elseif ($type === 's') {
                    $sharedIndex = (int)($cell->v ?? -1);
                    $value = $sharedStrings[$sharedIndex] ?? '';
                } else {
                    $value = isset($cell->v) ? (string)$cell->v : '';
                }

                $rowData[] = $value;
                $nextCol++;
            }

            $rows[] = $rowData;
        }

        if (empty($rows)) {
            throw new RuntimeException('XLSX не содержит строк');
        }

        return $rows;
    } finally {
        $zip->close();
    }
}

function connectors_import_xlsx_into_report_table(mysqli $dbcnx, string $tableName, string $filePath, int $connectorId, ?string $periodFrom, ?string $periodTo, array $fieldMapping): int
{
    $rows = connectors_read_xlsx_rows($filePath);
    $header = array_shift($rows);
    if (!is_array($header) || empty($header)) {
        throw new RuntimeException('XLSX не содержит заголовок');
    }

    $headerMap = [];
    foreach ($header as $idx => $name) {
        $headerMap[trim((string)$name)] = (int)$idx;
    }

    if (empty($fieldMapping)) {
        throw new InvalidArgumentException('Для XLSX-импорта заполните "Маппинг полей"');
    }

    $safeTable = '`' . str_replace('`', '``', $tableName) . '`';
    $sql = 'INSERT INTO ' . $safeTable . ' (connector_id, period_from, period_to, payload_json, source_file) VALUES (?, ?, ?, ?, ?)';
    $stmt = $dbcnx->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('DB error (prepare import): ' . $dbcnx->error);
    }

    $count = 0;
    foreach ($rows as $row) {
        $payload = [];
        foreach ($fieldMapping as $targetField => $xlsxColumnName) {
            $xlsxColumnName = trim((string)$xlsxColumnName);
            if ($xlsxColumnName === '' || !array_key_exists($xlsxColumnName, $headerMap)) {
                $payload[$targetField] = null;
                continue;
            }
            $payload[$targetField] = $row[$headerMap[$xlsxColumnName]] ?? null;
        }

        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($payloadJson === false) {
            continue;
        }

        $sourceFile = basename($filePath);
        $stmt->bind_param('issss', $connectorId, $periodFrom, $periodTo, $payloadJson, $sourceFile);
        if ($stmt->execute()) {
            $count++;
        }
    }

    $stmt->close();
    return $count;
}






function connectors_build_submission_test_vars(array $connector): array
{
    $vars = [
        'base_url' => trim((string)($connector['base_url'] ?? '')),
        'login' => trim((string)($connector['auth_username'] ?? '')),
        'password' => trim((string)($connector['auth_password'] ?? '')),
    ];

    $scenarioRaw = trim((string)($connector['scenario_json'] ?? ''));
    if ($scenarioRaw !== '') {
        $decoded = json_decode($scenarioRaw, true);
        if (is_array($decoded)) {
            foreach ($decoded as $k => $v) {
                if (!is_string($k) || $k === '') {
                    continue;
                }
                if (is_scalar($v) || $v === null) {
                    $vars[$k] = (string)($v ?? '');
                }
            }
        }
    }


    $runtimeVarsRaw = trim((string)($_POST['runtime_vars_json'] ?? ''));
    if ($runtimeVarsRaw !== '') {
        $runtimeVars = json_decode($runtimeVarsRaw, true);
        if (is_array($runtimeVars)) {
            foreach ($runtimeVars as $key => $value) {
                if (!is_string($key) || trim($key) === '') {
                    continue;
                }
                if (is_scalar($value) || $value === null) {
                    $vars[$key] = (string)($value ?? '');
                }
            }
        }
    }

    return $vars;
}

function connectors_decode_runtime_vars_from_post(): array
{
    $runtimeVarsRaw = trim((string)($_POST['runtime_vars_json'] ?? ''));
    if ($runtimeVarsRaw === '') {
        return [];
    }

    $runtimeVars = json_decode($runtimeVarsRaw, true);
    return is_array($runtimeVars) ? $runtimeVars : [];
}

function connectors_extract_flight_list_runtime_operations(array $connector): array
{
    $raw = trim((string)($connector['operations_json'] ?? ''));
    if ($raw === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [];
    }

    if (isset($decoded['operations']) && is_array($decoded['operations'])) {
        $operations = [];
        foreach ($decoded['operations'] as $operation) {
            if (!is_array($operation)) {
                continue;
            }

            $operationId = trim((string)($operation['operation_id'] ?? ''));
            if ($operationId === '') {
                continue;
            }

            $operations[$operationId] = [
                'operation_id' => $operationId,
                'config' => isset($operation['config']) && is_array($operation['config']) ? $operation['config'] : [],
            ];
        }

        return $operations;
    }

    $operations = [];
    foreach ($decoded as $operationKey => $operation) {
        if (!is_array($operation)) {
            continue;
        }

        $operationId = trim((string)($operation['operation_id'] ?? $operationKey));
        if ($operationId === '') {
            continue;
        }

        $config = [];
        foreach (['subrunner'] as $configKey) {
            if (array_key_exists($configKey, $operation)) {
                $config[$configKey] = $operation[$configKey];
            }
        }

        $operations[$operationId] = [
            'operation_id' => $operationId,
            'config' => $config,
        ];
    }

    return $operations;
}

function connectors_resolve_flight_list_table_names(array $connector): array
{
    $tableNames = [];
    $runtimeOperations = connectors_extract_flight_list_runtime_operations($connector);

    foreach ($runtimeOperations as $operation) {
        if (!is_array($operation)) {
            continue;
        }

        $config = isset($operation['config']) && is_array($operation['config']) ? $operation['config'] : [];
        $subrunner = isset($config['subrunner']) && is_array($config['subrunner']) ? $config['subrunner'] : [];
        $subrunnerName = trim((string)($subrunner['name'] ?? ''));
        if ($subrunnerName === '' || stripos($subrunnerName, 'flight_list') !== 0) {
            continue;
        }

        $options = isset($subrunner['options']) && is_array($subrunner['options']) ? $subrunner['options'] : [];

        try {
            $tableNames[] = connectors_subrunner_resolve_flight_table_name($connector, $options);
        } catch (Throwable $e) {
            error_log('connectors resolve flight table error: ' . $e->getMessage());
        }
    }

    if ($tableNames === []) {
        try {
            $tableNames[] = connectors_subrunner_resolve_flight_table_name($connector, []);
        } catch (Throwable $e) {
            error_log('connectors resolve default flight table error: ' . $e->getMessage());
        }
    }

    return array_values(array_unique(array_filter(array_map('strval', $tableNames))));
}

function connectors_table_exists(mysqli $dbcnx, string $tableName): bool
{
    $normalizedTable = connectors_subrunner_sanitize_table_name($tableName);
    $escapedTable = $dbcnx->real_escape_string($normalizedTable);
    $res = $dbcnx->query("SHOW TABLES LIKE '{$escapedTable}'");
    if ($res instanceof mysqli_result) {
        $exists = $res->num_rows > 0;
        $res->free();
        return $exists;
    }

    return false;
}

function connectors_cleanup_deleted_departure_flight(mysqli $dbcnx, array $connector, array $runtimeVars): array
{
    $connectorId = (int)($connector['id'] ?? 0);
    if ($connectorId <= 0) {
        return [
            'flight_rows_deleted' => 0,
            'container_rows_deleted' => 0,
            'tables_checked' => [],
        ];
    }

    $flightRecordId = (int)($runtimeVars['flight_record_id'] ?? 0);
    $externalIdCandidates = array_values(array_unique(array_filter(array_map(
        static fn($value): string => trim((string)$value),
        [
            $runtimeVars['flight_id'] ?? '',
            $runtimeVars['external_id'] ?? '',
            $runtimeVars['selected_flight_id'] ?? '',
            $runtimeVars['selected_flight_external_id'] ?? '',
            $runtimeVars['target_flight_id'] ?? '',
            $runtimeVars['target_flight_external_id'] ?? '',
        ]
    ))));
    $flightNoCandidates = array_values(array_unique(array_filter(array_map(
        static fn($value): string => trim((string)$value),
        [
            $runtimeVars['flight'] ?? '',
            $runtimeVars['flight_no'] ?? '',
            $runtimeVars['flight_name'] ?? '',
            $runtimeVars['selected_flight'] ?? '',
            $runtimeVars['selected_flight_name'] ?? '',
            $runtimeVars['target_flight_name'] ?? '',
        ]
    ))));

    $flightRowsDeleted = 0;
    $containerRowsDeleted = 0;
    $tablesChecked = [];

    foreach (connectors_resolve_flight_list_table_names($connector) as $tableName) {
        if (!connectors_table_exists($dbcnx, $tableName)) {
            continue;
        }

        $tablesChecked[] = $tableName;
        $safeTable = '`' . str_replace('`', '``', $tableName) . '`';
        $containerTableName = connectors_subrunner_resolve_flight_containers_table_name($tableName, []);
        $safeContainerTable = '`' . str_replace('`', '``', $containerTableName) . '`';
        $containerTableExists = connectors_table_exists($dbcnx, $containerTableName);

        $matchedRowIds = [];
        $matchedExternalIds = [];
        $matchedFlightNos = [];

        if ($flightRecordId > 0) {
            $stmt = $dbcnx->prepare("SELECT id, external_id, flight_no FROM {$safeTable} WHERE connector_id = ? AND id = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('ii', $connectorId, $flightRecordId);
                if ($stmt->execute()) {
                    $result = $stmt->get_result();
                    $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
                    if ($result instanceof mysqli_result) {
                        $result->free();
                    }
                    if (is_array($row)) {
                        $matchedRowIds[] = (int)($row['id'] ?? 0);
                        $matchedExternalIds[] = trim((string)($row['external_id'] ?? ''));
                        $matchedFlightNos[] = trim((string)($row['flight_no'] ?? ''));
                    }
                }
                $stmt->close();
            }
        }

        foreach ($externalIdCandidates as $externalId) {
            $stmt = $dbcnx->prepare("SELECT id, external_id, flight_no FROM {$safeTable} WHERE connector_id = ? AND external_id = ?");
            if (!$stmt) {
                continue;
            }
            $stmt->bind_param('is', $connectorId, $externalId);
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                if ($result instanceof mysqli_result) {
                    while ($row = $result->fetch_assoc()) {
                        $matchedRowIds[] = (int)($row['id'] ?? 0);
                        $matchedExternalIds[] = trim((string)($row['external_id'] ?? ''));
                        $matchedFlightNos[] = trim((string)($row['flight_no'] ?? ''));
                    }
                    $result->free();
                }
            }
            $stmt->close();
        }

        if ($matchedRowIds === [] && $flightNoCandidates !== []) {
            foreach ($flightNoCandidates as $flightNo) {
                $stmt = $dbcnx->prepare("SELECT id, external_id, flight_no FROM {$safeTable} WHERE connector_id = ? AND flight_no = ?");
                if (!$stmt) {
                    continue;
                }
                $stmt->bind_param('is', $connectorId, $flightNo);
                if ($stmt->execute()) {
                    $result = $stmt->get_result();
                    if ($result instanceof mysqli_result) {
                        while ($row = $result->fetch_assoc()) {
                            $matchedRowIds[] = (int)($row['id'] ?? 0);
                            $matchedExternalIds[] = trim((string)($row['external_id'] ?? ''));
                            $matchedFlightNos[] = trim((string)($row['flight_no'] ?? ''));
                        }
                        $result->free();
                    }
                }
                $stmt->close();
            }
        }

        $matchedRowIds = array_values(array_unique(array_filter(array_map('intval', $matchedRowIds))));
        $matchedExternalIds = array_values(array_unique(array_filter(array_map('strval', $matchedExternalIds))));
        $matchedFlightNos = array_values(array_unique(array_filter(array_map('strval', $matchedFlightNos))));

        if ($matchedRowIds === [] && $matchedExternalIds === [] && $matchedFlightNos === []) {
            continue;
        }

        if ($matchedRowIds !== []) {
            $placeholders = implode(',', array_fill(0, count($matchedRowIds), '?'));
            $types = 'i' . str_repeat('i', count($matchedRowIds));
            $params = array_merge([$connectorId], $matchedRowIds);
            $stmt = $dbcnx->prepare("DELETE FROM {$safeTable} WHERE connector_id = ? AND id IN ({$placeholders})");
            if ($stmt) {
                $stmt->bind_param($types, ...$params);
                if ($stmt->execute()) {
                    $flightRowsDeleted += (int)$stmt->affected_rows;
                }
                $stmt->close();
            }
        } elseif ($matchedExternalIds !== []) {
            $placeholders = implode(',', array_fill(0, count($matchedExternalIds), '?'));
            $types = 'i' . str_repeat('s', count($matchedExternalIds));
            $params = array_merge([$connectorId], $matchedExternalIds);
            $stmt = $dbcnx->prepare("DELETE FROM {$safeTable} WHERE connector_id = ? AND external_id IN ({$placeholders})");
            if ($stmt) {
                $stmt->bind_param($types, ...$params);
                if ($stmt->execute()) {
                    $flightRowsDeleted += (int)$stmt->affected_rows;
                }
                $stmt->close();
            }
        }

        if (!$containerTableExists) {
            continue;
        }

        if ($matchedRowIds !== []) {
            $placeholders = implode(',', array_fill(0, count($matchedRowIds), '?'));
            $types = 'i' . str_repeat('i', count($matchedRowIds));
            $params = array_merge([$connectorId], $matchedRowIds);
            $stmt = $dbcnx->prepare("DELETE FROM {$safeContainerTable} WHERE connector_id = ? AND flight_record_id IN ({$placeholders})");
            if ($stmt) {
                $stmt->bind_param($types, ...$params);
                if ($stmt->execute()) {
                    $containerRowsDeleted += (int)$stmt->affected_rows;
                }
                $stmt->close();
            }
        }

        if ($matchedExternalIds !== []) {
            $placeholders = implode(',', array_fill(0, count($matchedExternalIds), '?'));
            $types = 'i' . str_repeat('s', count($matchedExternalIds));
            $params = array_merge([$connectorId], $matchedExternalIds);
            $stmt = $dbcnx->prepare("DELETE FROM {$safeContainerTable} WHERE connector_id = ? AND flight_external_id IN ({$placeholders})");
            if ($stmt) {
                $stmt->bind_param($types, ...$params);
                if ($stmt->execute()) {
                    $containerRowsDeleted += (int)$stmt->affected_rows;
                }
                $stmt->close();
            }
        } elseif ($matchedFlightNos !== []) {
            $placeholders = implode(',', array_fill(0, count($matchedFlightNos), '?'));
            $types = 'i' . str_repeat('s', count($matchedFlightNos));
            $params = array_merge([$connectorId], $matchedFlightNos);
            $stmt = $dbcnx->prepare("DELETE FROM {$safeContainerTable} WHERE connector_id = ? AND flight_no IN ({$placeholders})");
            if ($stmt) {
                $stmt->bind_param($types, ...$params);
                if ($stmt->execute()) {
                    $containerRowsDeleted += (int)$stmt->affected_rows;
                }
                $stmt->close();
            }
        }
    }

    return [
        'flight_rows_deleted' => $flightRowsDeleted,
        'container_rows_deleted' => $containerRowsDeleted,
        'tables_checked' => $tablesChecked,
    ];
}

function connectors_run_shell_command_with_timeout(string $cmd, int $timeoutSeconds = 50): string
{
    $timeoutSeconds = max(1, $timeoutSeconds);
    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = @proc_open($cmd, $descriptorSpec, $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Не удалось запустить внешний процесс');
    }

    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $stdout = '';
    $stderr = '';
    $start = microtime(true);

    while (true) {
        $status = proc_get_status($process);
        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);

        if (!$status['running']) {
            break;
        }

        if ((microtime(true) - $start) >= $timeoutSeconds) {
            proc_terminate($process, 15);
            usleep(300000);
            $status = proc_get_status($process);
            if (!empty($status['running'])) {
                proc_terminate($process, 9);
            }

            $stdout .= stream_get_contents($pipes[1]);
            $stderr .= stream_get_contents($pipes[2]);
            foreach ([1, 2] as $pipeIndex) {
                if (isset($pipes[$pipeIndex]) && is_resource($pipes[$pipeIndex])) {
                    fclose($pipes[$pipeIndex]);
                }
            }
            proc_close($process);

            $combinedOutput = trim($stdout . ($stderr !== '' ? "\n" . $stderr : ''));
            throw new RuntimeException('Внешний процесс превысил timeout ' . $timeoutSeconds . 's' . ($combinedOutput !== '' ? ': ' . $combinedOutput : ''));
        }

        usleep(100000);
    }

    foreach ([1, 2] as $pipeIndex) {
        if (isset($pipes[$pipeIndex]) && is_resource($pipes[$pipeIndex])) {
            fclose($pipes[$pipeIndex]);
        }
    }
    proc_close($process);

    return $stdout . ($stderr !== '' ? "\n" . $stderr : '');
}


function connectors_run_submission_test(array $connector, array $submissionCfg): array
{
    $steps = isset($submissionCfg['steps']) && is_array($submissionCfg['steps']) ? $submissionCfg['steps'] : [];
    if (empty($steps)) {
        throw new InvalidArgumentException('Для теста операции #2 заполните "Шаги формы операции #2".');
    }

    $scriptPath = realpath(__DIR__ . '/../../scripts/test_connector_operations_browser.js');
    if (!$scriptPath) {
        throw new RuntimeException('Не найден browser script для теста операции #2');
    }

    $tempDir = __DIR__ . '/../../scripts/_tmp';
    if (!is_dir($tempDir)) {
        @mkdir($tempDir, 0775, true);
    }
    connectors_clear_directory($tempDir, 21600);

    $vars = connectors_build_submission_test_vars($connector);
    $payload = [
        'steps' => $steps,
        'vars' => $vars,
        'ssl_ignore' => !empty($connector['ssl_ignore']),
        'cookies' => (string)($connector['auth_cookies'] ?? ''),
        'auth_token' => (string)($connector['auth_token'] ?? ''),
        'temp_dir' => realpath($tempDir) ?: $tempDir,
        'expect_download' => false,
        'error_selector' => trim((string)($submissionCfg['error_selector'] ?? '')),
        'error_wait_ms' => 1800,
    ];

    $cmd = 'node ' . escapeshellarg($scriptPath) . ' ' . escapeshellarg((string)json_encode($payload, JSON_UNESCAPED_UNICODE));
    $output = connectors_run_shell_command_with_timeout($cmd . ' 2>&1', 90);
    $decoded = json_decode(trim((string)$output), true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Не удалось выполнить browser-тест операции #2: ' . trim((string)$output));
    }
    if (empty($decoded['ok'])) {
        $browserStepLog = isset($decoded['step_log']) && is_array($decoded['step_log']) ? $decoded['step_log'] : [];
        $browserArtifactsDir = trim((string)($decoded['artifacts_dir'] ?? ''));
        if (!empty($browserStepLog)) {
            throw new ConnectorStepLogException((string)($decoded['message'] ?? 'Browser test failed'), $browserStepLog, 0, null, $browserArtifactsDir);
        }
        throw new RuntimeException((string)($decoded['message'] ?? 'Browser test failed'));
    }

    $resolvedSuccessSelector = trim((string)connectors_apply_vars((string)($submissionCfg['success_selector'] ?? ''), $vars));
    $resolvedSuccessText = trim((string)connectors_apply_vars((string)($submissionCfg['success_text'] ?? ''), $vars));
    $resolvedErrorSelector = trim((string)connectors_apply_vars((string)($submissionCfg['error_selector'] ?? ''), $vars));
    $tracking = trim((string)($vars['tracking_number'] ?? ''));

    return [
        'step_log' => isset($decoded['step_log']) && is_array($decoded['step_log']) ? $decoded['step_log'] : [],
        'artifacts_dir' => trim((string)($decoded['artifacts_dir'] ?? '')),
        'resolved_success_selector' => $resolvedSuccessSelector,
        'resolved_success_text' => $resolvedSuccessText,
        'resolved_error_selector' => $resolvedErrorSelector,
        'captured_error_text' => trim((string)($decoded['captured_error_text'] ?? '')),
        'tracking_number' => $tracking,
        'message' => trim((string)($decoded['message'] ?? '')),
        'node_payload' => $payload,
    ];
}



function connectors_core_api_action_handler_registry(): array
{
    static $registry = null;
    if (is_array($registry)) {
        return $registry;
    }

    $registry = [];
    $coreApiPath = __DIR__ . '/../../core_api.php';
    if (!is_file($coreApiPath)) {
        return $registry;
    }

    $contents = (string)file_get_contents($coreApiPath);
    if ($contents === '') {
        return $registry;
    }

    if (preg_match_all('/[\'"]([a-zA-Z0-9_.-]+)[\'"]\s*=>\s*[\'"]([^\'"]+)[\'"]/', $contents, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $action = trim((string)($match[1] ?? ''));
            $handler = trim((string)($match[2] ?? ''));
            if ($action === '' || $handler === '' || strpos($handler, 'api/') !== 0) {
                continue;
            }
            $registry[$action] = $handler;
        }
    }

    return $registry;
}

function connectors_execute_api_call_operation(array $operation, int $connectorId): array
{
    $action = trim((string)($operation['action'] ?? ''));
    if ($action === '') {
        throw new InvalidArgumentException('Для kind=api_call поле action обязательно');
    }

    $routes = connectors_core_api_action_handler_registry();
    $handlerPath = trim((string)($routes[$action] ?? ''));
    if ($handlerPath === '') {
        throw new InvalidArgumentException('Не найден handler для action "' . $action . '"');
    }

    $fullHandlerPath = __DIR__ . '/../../' . ltrim($handlerPath, '/');
    if (!is_file($fullHandlerPath)) {
        throw new RuntimeException('Файл handler не найден: ' . $handlerPath);
    }

    if (realpath($fullHandlerPath) === realpath(__FILE__)) {
        throw new RuntimeException('Рекурсивный вызов action "' . $action . '" запрещен в manual test dispatcher');
    }

    $postBackup = $_POST;
    $getBackup = $_GET;
    $responseBackupSet = array_key_exists('response', $GLOBALS);
    $responseBackup = $responseBackupSet ? $GLOBALS['response'] : null;

    $config = isset($operation['config']) && is_array($operation['config']) ? $operation['config'] : [];
    $_POST = [
        'action' => $action,
        'connector_id' => $connectorId,
        'operation_id' => (string)($operation['operation_id'] ?? ''),
        'operation_config_json' => json_encode($config, JSON_UNESCAPED_UNICODE),
    ];
    $_GET = [];
    $response = null;

    try {
        require $fullHandlerPath;
    } finally {
        $_POST = $postBackup;
        $_GET = $getBackup;
    }

    if (!is_array($response)) {
        if ($responseBackupSet) {
            $GLOBALS['response'] = $responseBackup;
        } else {
            unset($GLOBALS['response']);
        }
        throw new RuntimeException('API handler не вернул корректный response для action "' . $action . '"');
    }

    if ($responseBackupSet) {
        $GLOBALS['response'] = $responseBackup;
    } else {
        unset($GLOBALS['response']);
    }

    if (($response['status'] ?? '') !== 'ok') {
        throw new RuntimeException((string)($response['message'] ?? ('api_call failed: ' . $action)));
    }

    return [
        'message' => trim((string)($response['message'] ?? ('api_call выполнен: ' . $action))),
        'payload' => $response,
    ];
}



function connectors_execute_browser_steps_operation(array $connector, array $operation, ?string $periodFrom, ?string $periodTo): array
{
    $config = isset($operation['config']) && is_array($operation['config']) ? $operation['config'] : [];
    $steps = isset($config['steps']) && is_array($config['steps']) ? $config['steps'] : [];
    $prependLoginSteps = !array_key_exists('prepend_login_steps', $config) || !empty($config['prepend_login_steps']);
    if ($prependLoginSteps) {
        $steps = array_merge(connectors_extract_browser_login_steps($connector), $steps);
    }

    if (empty($steps)) {
        throw new InvalidArgumentException('Для kind=browser_steps нужно заполнить operation.config.steps (или browser_login_steps в scenario_json).');
    }

    $scriptPath = realpath(__DIR__ . '/../../scripts/test_connector_operations_browser.js');
    if (!$scriptPath) {
        throw new RuntimeException('Не найден browser script для kind=browser_steps');
    }

    $tempDir = __DIR__ . '/../../scripts/_tmp';
    if (!is_dir($tempDir)) {
        @mkdir($tempDir, 0775, true);
    }
    connectors_clear_directory($tempDir, 21600);

    $today = date('Y-m-d');
    $defaultDateFrom = date('Y-m-d', strtotime('-2 years', strtotime($today)));
    $resolvedDateFrom = $periodFrom ?? $defaultDateFrom;
    $resolvedDateTo = $periodTo ?? $today;
    $vars = connectors_build_submission_test_vars($connector);
    $vars['date_from'] = $resolvedDateFrom;
    $vars['date_to'] = $resolvedDateTo;
    $vars['test_period_from'] = $resolvedDateFrom;
    $vars['test_period_to'] = $resolvedDateTo;
    $vars['today'] = $today;
    $vars['today_minus_2y'] = $defaultDateFrom;

    $expectDownload = !empty($config['expect_download']);
    $payload = [
        'steps' => $steps,
        'vars' => $vars,
        'file_extension' => strtolower(trim((string)($config['file_extension'] ?? 'xlsx'))) ?: 'xlsx',
        'ssl_ignore' => !empty($connector['ssl_ignore']),
        'cookies' => (string)($connector['auth_cookies'] ?? ''),
        'auth_token' => (string)($connector['auth_token'] ?? ''),
        'temp_dir' => realpath($tempDir) ?: $tempDir,
        'expect_download' => $expectDownload,
        'error_selector' => trim((string)($config['error_selector'] ?? '')),
        'error_wait_ms' => max(0, (int)($config['error_wait_ms'] ?? 1800)),
    ];

    $cmd = 'node ' . escapeshellarg($scriptPath) . ' ' . escapeshellarg((string)json_encode($payload, JSON_UNESCAPED_UNICODE));
    $output = connectors_run_shell_command_with_timeout($cmd . ' 2>&1', 90);
    $decoded = json_decode(trim((string)$output), true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Не удалось выполнить kind=browser_steps: ' . trim((string)$output));
    }
    if (empty($decoded['ok'])) {
        $browserStepLog = isset($decoded['step_log']) && is_array($decoded['step_log']) ? $decoded['step_log'] : [];
        $browserArtifactsDir = trim((string)($decoded['artifacts_dir'] ?? ''));
        if (!empty($browserStepLog)) {
            throw new ConnectorStepLogException((string)($decoded['message'] ?? 'Browser steps failed'), $browserStepLog, 0, null, $browserArtifactsDir);
        }
        throw new RuntimeException((string)($decoded['message'] ?? 'Browser steps failed'));
    }

    $result = [
        'message' => trim((string)($decoded['message'] ?? 'Операция browser_steps выполнена')),
        'step_log' => isset($decoded['step_log']) && is_array($decoded['step_log']) ? $decoded['step_log'] : [],
        'artifacts_dir' => trim((string)($decoded['artifacts_dir'] ?? '')),
        'final_html_path' => trim((string)($decoded['final_html_path'] ?? '')),
        'cookies' => trim((string)($decoded['cookies'] ?? '')),
    ];

    if ($expectDownload) {
        $filePath = (string)($decoded['file_path'] ?? '');
        if ($filePath === '' || !is_file($filePath)) {
            throw new RuntimeException('kind=browser_steps (expect_download=1) не вернул путь к скачанному файлу');
        }
        $result['download'] = [
            'file_path' => $filePath,
            'file_size' => (int)filesize($filePath),
            'file_extension' => strtolower(trim((string)($payload['file_extension'] ?? 'xlsx'))) ?: 'xlsx',
            'download_mode' => 'browser',
            'step_log' => $result['step_log'],
            'artifacts_dir' => $result['artifacts_dir'],
        ];
    }

    return $result;
}


function connectors_execute_subrunner(array $connector, array $operation, int $connectorId, ?string $periodFrom, ?string $periodTo): array
{
    $config = isset($operation['config']) && is_array($operation['config']) ? $operation['config'] : [];
    $subrunner = isset($config['subrunner']) && is_array($config['subrunner']) ? $config['subrunner'] : [];
    $subrunnerName = trim((string)($subrunner['name'] ?? ''));

    if ($subrunnerName === '') {
        throw new InvalidArgumentException('Для subrunner нужно заполнить operation.config.subrunner.name');
    }

    $options = isset($subrunner['options']) && is_array($subrunner['options']) ? $subrunner['options'] : [];

    $browserResult = connectors_execute_browser_steps_operation($connector, $operation, $periodFrom, $periodTo);

    $ctx = [
        'connector' => $connector,
        'connector_id' => $connectorId,
        'operation' => $operation,
        'operation_id' => (string)($operation['operation_id'] ?? ''),
        'period_from' => $periodFrom,
        'period_to' => $periodTo,
        'browser' => $browserResult,
    ];

    $subrunnerResult = connectors_subrunners_run($subrunnerName, $ctx, $options);
    $status = trim((string)($subrunnerResult['status'] ?? ''));
    if ($status !== 'ok') {

        $targetTable = trim((string)($subrunnerResult['meta']['table_name'] ?? ''));
        $errors = isset($subrunnerResult['errors']) && is_array($subrunnerResult['errors']) ? $subrunnerResult['errors'] : [];
        $errorMessages = [];
        foreach (array_slice($errors, 0, 3) as $error) {
            if (!is_array($error)) {
                continue;
            }
            $rowLabel = isset($error['row']) ? ('row ' . (string)$error['row'] . ': ') : '';
            $errorMessage = trim((string)($error['message'] ?? ''));
            if ($errorMessage !== '') {
                $errorMessages[] = $rowLabel . $errorMessage;
            }
        }

        $message = trim((string)($subrunnerResult['message'] ?? ('Subrunner завершился с ошибкой: ' . $subrunnerName)));
        if ($errorMessages !== []) {
            $message .= ' Details: ' . implode('; ', $errorMessages);
        }

        throw new ConnectorStepLogException(
            $message,
            isset($browserResult['step_log']) && is_array($browserResult['step_log']) ? $browserResult['step_log'] : [],
            0,
            null,
            (string)($browserResult['artifacts_dir'] ?? ''),
            [
                'target_table' => $targetTable,
                'subrunner_errors' => $errors,
                'subrunner_name' => $subrunnerName,
            ]
        );
    }

    return [
        'message' => trim((string)($subrunnerResult['message'] ?? ('Операция subrunner выполнена: ' . $subrunnerName))),
        'step_log' => isset($browserResult['step_log']) && is_array($browserResult['step_log']) ? $browserResult['step_log'] : [],
        'artifacts_dir' => (string)($browserResult['artifacts_dir'] ?? ''),
        'subrunner' => $subrunnerResult,
    ];
}


function connectors_expand_script_arg_placeholders(string $value, array $context): string
{
    if ($value === '') {
        return $value;
    }

    return (string)strtr($value, [
        '{{from}}' => (string)($context['from'] ?? ''),
        '{{to}}' => (string)($context['to'] ?? ''),
        '{{period_from}}' => (string)($context['period_from'] ?? ''),
        '{{period_to}}' => (string)($context['period_to'] ?? ''),
        '{{connector_id}}' => (string)($context['connector_id'] ?? ''),
        '{{base_url}}' => (string)($context['base_url'] ?? ''),
        '{{auth_username}}' => (string)($context['auth_username'] ?? ''),
        '{{auth_password}}' => (string)($context['auth_password'] ?? ''),
        '{{auth_token}}' => (string)($context['auth_token'] ?? ''),
        '{{api_token}}' => (string)($context['api_token'] ?? ''),
        '{{target_table}}' => (string)($context['target_table'] ?? ''),
    ]);
}

function connectors_mask_script_arg(string $arg): string
{
    $trimmed = trim($arg);
    if ($trimmed === '') {
        return $arg;
    }

    if (preg_match('/^(--(?:password|auth_password|token|api-token|auth-token))=/i', $trimmed, $m)) {
        return $m[1] . '=***';
    }

    return $arg;
}

function connectors_try_decode_script_json_output(string $output): ?array
{
    $output = trim($output);
    if ($output === '') {
        return null;
    }

    $decoded = json_decode($output, true);
    if (is_array($decoded)) {
        return $decoded;
    }

    $lines = preg_split('/\R/', $output) ?: [];
    for ($i = count($lines) - 1; $i >= 0; $i--) {
        $line = trim((string)$lines[$i]);
        if ($line === '') {
            continue;
        }
        $decodedLine = json_decode($line, true);
        if (is_array($decodedLine)) {
            return $decodedLine;
        }
    }

    $firstBrace = strpos($output, '{');
    $lastBrace = strrpos($output, '}');
    if ($firstBrace !== false && $lastBrace !== false && $lastBrace > $firstBrace) {
        $slice = substr($output, $firstBrace, $lastBrace - $firstBrace + 1);
        $decodedSlice = json_decode((string)$slice, true);
        if (is_array($decodedSlice)) {
            return $decodedSlice;
        }
    }

    return null;
}

function connectors_execute_script_operation(array $operation, array $connector = [], ?string $periodFrom = null, ?string $periodTo = null): array
{
    $rawConfig = isset($operation['config']) && is_array($operation['config']) ? $operation['config'] : [];
    $operationId = trim((string)($operation['operation_id'] ?? ''));
    $config = connectors_unwrap_embedded_operation_config($rawConfig, $operationId);
    $resolvedTargetTable = trim((string)($config['target_table'] ?? ''));
    $targetTableTrace = [
        'from_unwrapped_config' => $resolvedTargetTable,
        'from_raw_config' => '',
        'from_raw_config_operations' => '',
        'from_operation' => trim((string)($operation['target_table'] ?? '')),
    ];
    if ($resolvedTargetTable === '') {
        $resolvedTargetTable = trim((string)($rawConfig['target_table'] ?? ''));
        $targetTableTrace['from_raw_config'] = $resolvedTargetTable;
    }
    if ($resolvedTargetTable === '' && isset($rawConfig['operations']) && is_array($rawConfig['operations'])) {
        foreach ($rawConfig['operations'] as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }
            $candidateId = trim((string)($candidate['operation_id'] ?? ''));
            if ($operationId !== '' && $candidateId !== '' && strcasecmp($candidateId, $operationId) !== 0) {
                continue;
            }
            $resolvedTargetTable = trim((string)($candidate['target_table'] ?? ''));
            if ($resolvedTargetTable === '') {
                $candidateCfg = isset($candidate['config']) && is_array($candidate['config']) ? $candidate['config'] : [];
                $resolvedTargetTable = trim((string)($candidateCfg['target_table'] ?? ''));
            }
            if ($resolvedTargetTable !== '') {
                $targetTableTrace['from_raw_config_operations'] = $resolvedTargetTable;
                break;
            }
        }
    }
    if ($resolvedTargetTable === '') {
        $resolvedTargetTable = trim((string)($operation['target_table'] ?? ''));
    }
    $scriptPathRaw = trim((string)($config['script_path'] ?? ''));
    if ($scriptPathRaw === '') {
        throw new InvalidArgumentException('Для kind=script укажите operation.config.script_path');
    }

    $rootPath = realpath(__DIR__ . '/../../');

    $repoRootPath = realpath(__DIR__ . '/../../../');
    $scriptPath = false;

    $scriptCandidates = [
        $scriptPathRaw,
    ];

    if ($rootPath !== false) {
        $scriptCandidates[] = $rootPath . '/' . ltrim($scriptPathRaw, '/');
    }
    if ($repoRootPath !== false) {
        $scriptCandidates[] = $repoRootPath . '/' . ltrim($scriptPathRaw, '/');
        if (strpos($scriptPathRaw, 'www/') === 0) {
            $scriptCandidates[] = $repoRootPath . '/' . ltrim(substr($scriptPathRaw, 4), '/');
        }
    }

    foreach ($scriptCandidates as $candidatePath) {
        $resolvedPath = realpath($candidatePath);
        if ($resolvedPath !== false && is_file($resolvedPath)) {
            $scriptPath = $resolvedPath;
            break;
        }
    }
    if ($scriptPath === false || !is_file($scriptPath)) {
        throw new RuntimeException('kind=script: файл не найден: ' . $scriptPathRaw);
    }

    if ($rootPath !== false && strpos($scriptPath, $rootPath) !== 0) {
        throw new RuntimeException('kind=script: путь должен быть внутри репозитория');
    }

    $interpreter = strtolower(trim((string)($config['interpreter'] ?? '')));
    if ($interpreter === '') {
        $ext = strtolower(pathinfo($scriptPath, PATHINFO_EXTENSION));
        $interpreter = $ext === 'js' ? 'node' : ($ext === 'php' ? 'php' : 'bash');
    }
    if (!in_array($interpreter, ['bash', 'sh', 'node', 'php', 'python3'], true)) {
        throw new InvalidArgumentException('kind=script: недопустимый interpreter (bash|sh|node|php|python3)');
    }


    $argsContext = [
        'from' => (string)($periodFrom ?? ($config['from'] ?? '')),
        'to' => (string)($periodTo ?? ($config['to'] ?? '')),
        'period_from' => (string)($periodFrom ?? ''),
        'period_to' => (string)($periodTo ?? ''),
        'connector_id' => (string)($connector['id'] ?? ''),
        'base_url' => (string)($connector['base_url'] ?? ''),
        'auth_username' => (string)($connector['auth_username'] ?? ''),
        'auth_password' => (string)($connector['auth_password'] ?? ''),
        'auth_token' => (string)($connector['auth_token'] ?? ''),
        'api_token' => (string)($connector['api_token'] ?? ''),
        'target_table' => $resolvedTargetTable,
    ];

    $args = [];
    $argsMasked = [];
    $hasTargetTableArg = false;
    if (isset($config['args']) && is_array($config['args'])) {
        foreach ($config['args'] as $arg) {
            $expandedArg = connectors_expand_script_arg_placeholders((string)$arg, $argsContext);
            $args[] = $expandedArg;
            $argsMasked[] = connectors_mask_script_arg($expandedArg);
            if (preg_match('/^--target(?:-|_)table=/i', trim($expandedArg))) {
                $hasTargetTableArg = true;
            }
        }
    }

    if (!$hasTargetTableArg && $resolvedTargetTable !== '') {
        $forcedTargetArg = '--target-table=' . $resolvedTargetTable;
        $args[] = $forcedTargetArg;
        $argsMasked[] = connectors_mask_script_arg($forcedTargetArg);
    }
    $timeoutSec = max(1, (int)($config['timeout_sec'] ?? 60));
    $parts = [];
    if ($resolvedTargetTable !== '') {
        $parts[] = 'FORWARDER_TARGET_TABLE=' . escapeshellarg($resolvedTargetTable);
    }
    $parts = array_merge($parts, ['timeout', (string)$timeoutSec, $interpreter, escapeshellarg($scriptPath)]);
    foreach ($args as $arg) {
        $parts[] = escapeshellarg($arg);
    }
    $cmd = implode(' ', $parts) . ' 2>&1';

    $lines = [];
    $exitCode = 0;
    @exec($cmd, $lines, $exitCode);
    $output = trim((string)implode("\n", $lines));
    if ($exitCode !== 0) {
        throw new RuntimeException('kind=script завершился с ошибкой (exit_code=' . $exitCode . '): ' . $output);
    }


    $parsedJson = connectors_try_decode_script_json_output($output);

    if (is_array($parsedJson)) {
        $importStatus = strtolower(trim((string)($parsedJson['import_status'] ?? '')));
        if (in_array($importStatus, ['error', 'failed'], true)) {
            $importMessage = trim((string)($parsedJson['import_message'] ?? 'script import returned error'));
            $importErrors = $parsedJson['import_errors'] ?? [];
            $details = '';
            if (is_array($importErrors) && $importErrors !== []) {
                $details = '; errors=' . json_encode($importErrors, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            throw new RuntimeException('kind=script import error: ' . $importMessage . $details);
        }
    }
    return [
        'message' => 'Операция script выполнена',
        'script' => [
            'script_path' => $scriptPath,
            'interpreter' => $interpreter,
            'output' => $output,
            'exit_code' => $exitCode,
            'args_expanded_masked' => $argsMasked,
            'parsed_json' => $parsedJson,
            'debug' => [
                'target_table_resolved' => $resolvedTargetTable,
                'target_table_resolution' => $targetTableTrace,
            ],
        ],
        'target_table' => is_array($parsedJson) ? (string)($parsedJson['target_table'] ?? '') : '',
        'imported_rows' => is_array($parsedJson) ? (int)($parsedJson['imported_rows'] ?? 0) : 0,
        'rows_detected' => is_array($parsedJson) ? (int)($parsedJson['rows_detected'] ?? 0) : 0,
        'import_status' => is_array($parsedJson) ? (string)($parsedJson['import_status'] ?? '') : '',
    ];
}


function connectors_execute_php_report_operation(array $connector, array $operation, ?string $periodFrom, ?string $periodTo): array
{
    $config = isset($operation['config']) && is_array($operation['config']) ? $operation['config'] : [];
    $reportCfg = $config;
    if (!isset($reportCfg['download_mode']) || trim((string)$reportCfg['download_mode']) === '') {
        $reportCfg['download_mode'] = 'curl';
    }
    if (!isset($reportCfg['file_extension']) || trim((string)$reportCfg['file_extension']) === '') {
        $reportCfg['file_extension'] = 'xlsx';
    }
    if (!isset($reportCfg['target_table']) || trim((string)$reportCfg['target_table']) === '') {
        $reportCfg['target_table'] = 'connector_report_temp';
    }

    $downloadInfo = connectors_download_report_file($connector, $reportCfg, $periodFrom, $periodTo);
    $targetTable = connectors_normalize_report_table_name((string)($reportCfg['target_table'] ?? 'connector_report_temp'));

    return [
        'message' => 'Файл успешно скачан через PHP report',
        'download' => $downloadInfo,
        'target_table' => $targetTable,
    ];
}

function connectors_resolve_manual_test_target_table(array $operation, array $result = []): string
{
    $subrunnerTable = trim((string)($result['subrunner']['meta']['table_name'] ?? ''));
    if ($subrunnerTable !== '') {
        return $subrunnerTable;
    }

    $config = isset($operation['config']) && is_array($operation['config']) ? $operation['config'] : [];
    $configTargetTable = trim((string)($config['target_table'] ?? ''));
    if ($configTargetTable !== '') {
        return connectors_normalize_report_table_name($configTargetTable);
    }

    return '';
}

function connectors_execute_operation_by_kind_for_manual_test(array $connector, array $operation, int $connectorId, ?string $periodFrom, ?string $periodTo): array
{
    $kind = trim((string)($operation['kind'] ?? ''));
    $kind = $kind !== '' ? $kind : 'browser_steps';
    $config = isset($operation['config']) && is_array($operation['config']) ? $operation['config'] : [];
    $hasSubrunnerConfig = isset($config['subrunner']) && is_array($config['subrunner'])
        && trim((string)($config['subrunner']['name'] ?? '')) !== '';

    if ($kind === 'noop') {
        return [
            'message' => 'Операция noop пропущена (технический узел графа).',
            'trace_meta' => ['kind' => 'noop'],
        ];
    }

    if ($kind === 'script') {
        $scriptResult = connectors_execute_script_operation($operation, $connector, $periodFrom, $periodTo);
        $scriptMeta = isset($scriptResult['script']) && is_array($scriptResult['script']) ? $scriptResult['script'] : [];
        $scriptParsed = isset($scriptMeta['parsed_json']) && is_array($scriptMeta['parsed_json']) ? $scriptMeta['parsed_json'] : [];
        $message = (string)($scriptResult['message'] ?? 'Операция script выполнена');
        if ($scriptParsed !== []) {
            $importStatus = trim((string)($scriptParsed['import_status'] ?? ''));
            $importedRows = (int)($scriptParsed['imported_rows'] ?? 0);
            $rowsDetected = (int)($scriptParsed['rows_detected'] ?? 0);
            if ($importStatus !== '') {
                $message .= ' import_status=' . $importStatus;
            }
            $message .= ' imported_rows=' . $importedRows . ' rows_detected=' . $rowsDetected;
        }
        return [
            'message' => $message,
            'script' => $scriptMeta !== [] ? $scriptMeta : null,
            'target_table' => trim((string)($scriptResult['target_table'] ?? ($scriptParsed['target_table'] ?? ''))),
            'imported_rows' => (int)($scriptResult['imported_rows'] ?? ($scriptParsed['imported_rows'] ?? 0)),
            'rows_detected' => (int)($scriptResult['rows_detected'] ?? ($scriptParsed['rows_detected'] ?? 0)),
            'import_status' => trim((string)($scriptResult['import_status'] ?? ($scriptParsed['import_status'] ?? ''))),
            'trace_meta' => [
                'kind' => 'script',
                'target_table' => trim((string)($scriptResult['target_table'] ?? ($scriptParsed['target_table'] ?? ''))),
                'imported_rows' => (int)($scriptResult['imported_rows'] ?? ($scriptParsed['imported_rows'] ?? 0)),
                'rows_detected' => (int)($scriptResult['rows_detected'] ?? ($scriptParsed['rows_detected'] ?? 0)),
                'import_status' => trim((string)($scriptResult['import_status'] ?? ($scriptParsed['import_status'] ?? ''))),
            ],
        ];
    }

    if ($kind === 'php_report') {
        $phpReportResult = connectors_execute_php_report_operation($connector, $operation, $periodFrom, $periodTo);
        $importedRows = 0;
        $importNote = '';
        $targetTable = trim((string)($phpReportResult['target_table'] ?? ''));
        $downloadInfo = isset($phpReportResult['download']) && is_array($phpReportResult['download']) ? $phpReportResult['download'] : [];
        $downloadPath = trim((string)($downloadInfo['file_path'] ?? ''));
        $fileExt = strtolower(trim((string)($downloadInfo['file_extension'] ?? ($config['file_extension'] ?? 'xlsx'))));
        $fieldMapping = isset($config['field_mapping']) && is_array($config['field_mapping']) ? $config['field_mapping'] : [];

        global $dbcnx;
        if (
            $targetTable !== ''
            && $downloadPath !== ''
            && is_file($downloadPath)
            && $fieldMapping !== []
            && ($dbcnx instanceof mysqli)
        ) {
            connectors_ensure_report_table($dbcnx, $targetTable);
            if ($fileExt === 'csv') {
                $importedRows = connectors_import_csv_into_report_table($dbcnx, $targetTable, $downloadPath, $connectorId, $periodFrom, $periodTo, $fieldMapping);
            } elseif ($fileExt === 'xlsx') {
                $importedRows = connectors_import_xlsx_into_report_table($dbcnx, $targetTable, $downloadPath, $connectorId, $periodFrom, $periodTo, $fieldMapping);
            } else {
                $importNote = 'auto-import поддерживается только для CSV/XLSX';
            }
        } elseif ($fieldMapping === []) {
            $importNote = 'field_mapping не заполнен, импорт в БД пропущен';
        } else {
            $importNote = 'недостаточно данных для импорта в БД';
        }

        $message = (string)($phpReportResult['message'] ?? 'Операция php_report выполнена');
        if ($importedRows > 0) {
            $message .= ' Импортировано строк: ' . $importedRows . '.';
        } elseif ($importNote !== '') {
            $message .= ' (' . $importNote . ')';
        }
        return [
            'message' => $message,
            'download' => isset($phpReportResult['download']) && is_array($phpReportResult['download']) ? $phpReportResult['download'] : null,
            'target_table' => trim((string)($phpReportResult['target_table'] ?? '')),
            'imported_rows' => $importedRows,
            'trace_meta' => ['kind' => 'php_report'],
        ];
    }

    if ($kind === 'api_call') {
        $apiCallResult = connectors_execute_api_call_operation($operation, $connectorId);
        return [
            'message' => $apiCallResult['message'],
            'api_response' => $apiCallResult['payload'],
            'trace_meta' => ['kind' => 'api_call', 'action' => (string)($operation['action'] ?? '')],
        ];
    }


    if ($kind === 'subrunner' || ($kind === 'browser_steps' && (((string)($operation['action'] ?? '') === 'connectors_run_subrunner') || $hasSubrunnerConfig))) {
        $subrunnerResult = connectors_execute_subrunner($connector, $operation, $connectorId, $periodFrom, $periodTo);
        return [
            'message' => (string)($subrunnerResult['message'] ?? 'Операция subrunner выполнена'),
            'subrunner' => isset($subrunnerResult['subrunner']) && is_array($subrunnerResult['subrunner']) ? $subrunnerResult['subrunner'] : null,
            'step_log' => isset($subrunnerResult['step_log']) && is_array($subrunnerResult['step_log']) ? $subrunnerResult['step_log'] : [],
            'artifacts_dir' => (string)($subrunnerResult['artifacts_dir'] ?? ''),
            'target_table' => connectors_resolve_manual_test_target_table($operation, $subrunnerResult),
            'trace_meta' => ['kind' => 'subrunner', 'subrunner_name' => (string)($config['subrunner']['name'] ?? '')],
        ];
    }


    $browserResult = connectors_execute_browser_steps_operation($connector, $operation, $periodFrom, $periodTo);
    return [
        'message' => (string)($browserResult['message'] ?? 'Операция browser_steps выполнена'),
        'download' => isset($browserResult['download']) && is_array($browserResult['download']) ? $browserResult['download'] : null,
        'step_log' => isset($browserResult['step_log']) && is_array($browserResult['step_log']) ? $browserResult['step_log'] : [],
        'artifacts_dir' => (string)($browserResult['artifacts_dir'] ?? ''),
        'target_table' => connectors_resolve_manual_test_target_table($operation, $browserResult),
        'trace_meta' => ['kind' => 'browser_steps'],
    ];
}


function connectors_execute_manual_test_execution_plan(
    array $connector,
    int $connectorId,
    array $executionPlan,
    array $runtimeOperations,
    ?string $periodFrom,
    ?string $periodTo,
    string $runId,
    array &$traceLog
): array {
    $executed = [];
    $stageCollections = [
        'before' => (array)($executionPlan['before'] ?? []),
        'main' => [trim((string)($executionPlan['main'] ?? ''))],
    ];

    foreach ($stageCollections as $stage => $operationIds) {
        foreach ($operationIds as $operationIdRaw) {
            $operationId = trim((string)$operationIdRaw);
            if ($operationId === '' || isset($executed[$operationId])) {
                continue;
            }
            if (!isset($runtimeOperations[$operationId]) || !is_array($runtimeOperations[$operationId])) {
                throw new InvalidArgumentException('Операция из execution plan не найдена: ' . $operationId);
            }

            $startedAtTs = microtime(true);
            $startedAt = date('c');
            $result = connectors_execute_operation_by_kind_for_manual_test($connector, $runtimeOperations[$operationId], $connectorId, $periodFrom, $periodTo);
            $finishedAtTs = microtime(true);
            $finishedAt = date('c');

            connectors_append_operation_executed_event(
                $traceLog,
                $runId,
                $operationId,
                $stage,
                'success',
                (string)($result['message'] ?? 'Операция выполнена'),
                (int)round(($finishedAtTs - $startedAtTs) * 1000),
                $startedAt,
                $finishedAt,
                isset($result['trace_meta']) && is_array($result['trace_meta']) ? $result['trace_meta'] : []
            );

            $executed[$operationId] = [
                'operation_id' => $operationId,
                'stage' => $stage,
                'result' => $result,
            ];
        }
    }

    $duringGroups = (array)($executionPlan['during_groups'] ?? ($executionPlan['during'] ?? []));
    foreach ($duringGroups as $groupIndex => $group) {
        if (!is_array($group)) {
            continue;
        }
        foreach ($group as $operationIdRaw) {
            $operationId = trim((string)$operationIdRaw);
            if ($operationId === '' || isset($executed[$operationId])) {
                continue;
            }
            if (!isset($runtimeOperations[$operationId]) || !is_array($runtimeOperations[$operationId])) {
                throw new InvalidArgumentException('During-операция из execution plan не найдена: ' . $operationId);
            }

            $startedAtTs = microtime(true);
            $startedAt = date('c');
            $result = connectors_execute_operation_by_kind_for_manual_test($connector, $runtimeOperations[$operationId], $connectorId, $periodFrom, $periodTo);
            $finishedAtTs = microtime(true);
            $finishedAt = date('c');

            $stage = 'during';
            connectors_append_operation_executed_event(
                $traceLog,
                $runId,
                $operationId,
                $stage,
                'success',
                (string)($result['message'] ?? ('Операция выполнена (during группа #' . ($groupIndex + 1) . ')')),
                (int)round(($finishedAtTs - $startedAtTs) * 1000),
                $startedAt,
                $finishedAt,
                isset($result['trace_meta']) && is_array($result['trace_meta']) ? $result['trace_meta'] : []
            );

            $executed[$operationId] = [
                'operation_id' => $operationId,
                'stage' => $stage,
                'result' => $result,
                'during_group' => $groupIndex + 1,
            ];
        }
    }

    foreach ((array)($executionPlan['after'] ?? ($executionPlan['finally'] ?? [])) as $operationIdRaw) {
        $operationId = trim((string)$operationIdRaw);
        if ($operationId === '' || isset($executed[$operationId])) {
            continue;
        }
        if (!isset($runtimeOperations[$operationId]) || !is_array($runtimeOperations[$operationId])) {
            throw new InvalidArgumentException('After-операция из execution plan не найдена: ' . $operationId);
        }

        $startedAtTs = microtime(true);
        $startedAt = date('c');
        $result = connectors_execute_operation_by_kind_for_manual_test($connector, $runtimeOperations[$operationId], $connectorId, $periodFrom, $periodTo);
        $finishedAtTs = microtime(true);
        $finishedAt = date('c');

        connectors_append_operation_executed_event(
            $traceLog,
            $runId,
            $operationId,
            'after',
            'success',
            (string)($result['message'] ?? 'Операция выполнена'),
            (int)round(($finishedAtTs - $startedAtTs) * 1000),
            $startedAt,
            $finishedAt,
            isset($result['trace_meta']) && is_array($result['trace_meta']) ? $result['trace_meta'] : []
        );

        $executed[$operationId] = [
            'operation_id' => $operationId,
            'stage' => 'after',
            'result' => $result,
        ];
    }

    return array_values($executed);
}


function connectors_has_operations_payload_in_post(): bool
{
    $fields = ['operations_v3_json'];

    foreach (['report', 'submission', 'track_and_label_info'] as $operationKey) {
        foreach (['enabled', 'operation_id', 'steps_json', 'run_after_json', 'run_with_json', 'run_finally_json'] as $suffix) {
            $fields[] = $operationKey . '_' . $suffix;
        }
    }
    foreach ($fields as $field) {
        if (array_key_exists($field, $_POST)) {
            return true;
        }
    }

    return false;
}


function connectors_build_operations_payload_from_post(): array
{

    $operationsV3Json = trim((string)($_POST['operations_v3_json'] ?? ''));
    if ($operationsV3Json !== '') {
        $decodedV3 = json_decode($operationsV3Json, true);
        if (!is_array($decodedV3)) {
            throw new InvalidArgumentException('Некорректный JSON в "operations_v3_json"');
        }

        if (!connectors_is_v3_operations_payload($decodedV3)) {
            throw new InvalidArgumentException('Поле "operations_v3_json" должно содержать payload формата v3: {"schema_version":3,"operations":[...]}');
        }

        return $decodedV3;
    }


    $hasLegacyOperationFields = false;
    foreach (['report', 'submission', 'track_and_label_info'] as $operationKey) {
        foreach (['enabled', 'operation_id', 'steps_json', 'run_after_json', 'run_with_json', 'run_finally_json'] as $suffix) {
            if (array_key_exists($operationKey . '_' . $suffix, $_POST)) {
                $hasLegacyOperationFields = true;
                break 2;
            }
        }
    }

    if (!$hasLegacyOperationFields) {
        throw new InvalidArgumentException('Не переданы данные операций (operations_v3_json отсутствует)');
    }

    $legacyOperationDefs = [
        'report' => [
            'default_operation_id' => 'report',
            'label' => 'Report',
            'config_builder' => static function (): array {
                $targetTable = strtolower(trim((string)($_POST['report_target_table'] ?? '')));
                $targetTable = preg_replace('/[^a-z0-9_]+/', '_', $targetTable ?? '');
                if ($targetTable !== '') {
                    $targetTable = trim($targetTable, '_');
                }

                $steps = [];
                $stepsJson = trim((string)($_POST['report_steps_json'] ?? ''));
                if ($stepsJson !== '') {
                    $decoded = json_decode($stepsJson, true);
                    if (!is_array($decoded)) {
                        throw new InvalidArgumentException('Некорректный JSON в "Шаги формы/кнопок"');
                    }
                    $steps = $decoded;
                }

                $curlConfig = [];
                $curlConfigJson = trim((string)($_POST['report_curl_config_json'] ?? ''));
                if ($curlConfigJson !== '') {
                    $decoded = json_decode($curlConfigJson, true);
                    if (!is_array($decoded)) {
                        throw new InvalidArgumentException('Некорректный JSON в "PHP cURL конфиг"');
                    }
                    $isList = array_keys($decoded) === range(0, count($decoded) - 1);
                    if ($isList) {
                        throw new InvalidArgumentException('"PHP cURL конфиг" должен быть JSON-объектом вида {"url":"...","method":"GET|POST",...}, а не массивом browser-шагов. Шаги нужно указывать в "Шаги формы/кнопок".');
                    }
                    $curlConfig = $decoded;
                }

                $fieldMapping = [];
                $fieldMappingJson = trim((string)($_POST['report_field_mapping_json'] ?? ''));
                if ($fieldMappingJson !== '') {
                    $decoded = json_decode($fieldMappingJson, true);
                    if (!is_array($decoded)) {
                        throw new InvalidArgumentException('Некорректный JSON в "Маппинг полей"');
                    }
                    $fieldMapping = $decoded;
                }
                $downloadMode = trim((string)($_POST['report_download_mode'] ?? 'browser'));
                if (!in_array($downloadMode, ['browser', 'curl'], true)) {
                    $downloadMode = 'browser';
                }

                $fileExtension = strtolower(trim((string)($_POST['report_file_extension'] ?? 'xlsx')));
                if ($fileExtension === '') {
                    $fileExtension = 'xlsx';
                }

                return [
                    'page_url' => trim((string)($_POST['report_page_url'] ?? '')),
                    'file_extension' => $fileExtension,
                    'download_mode' => $downloadMode,
                    'log_steps' => !empty($_POST['report_log_steps']) ? 1 : 0,
                    'steps' => $steps,
                    'curl_config' => $curlConfig,
                    'target_table' => $targetTable,
                    'field_mapping' => $fieldMapping,
                ];
            },
        ],
        'submission' => [

            'default_operation_id' => 'submission',
            'label' => 'Submission',
            'config_builder' => static function (): array {
                $steps = [];
                $stepsJson = trim((string)($_POST['submission_steps_json'] ?? ''));
                if ($stepsJson !== '') {
                    $decoded = json_decode($stepsJson, true);
                    if (!is_array($decoded)) {
                        throw new InvalidArgumentException('Некорректный JSON в "Шаги формы операции #2"');
                    }
                    $steps = $decoded;
                }

                $requestConfig = [];
                $requestConfigJson = trim((string)($_POST['submission_request_config_json'] ?? ''));
                if ($requestConfigJson !== '') {
                    $decoded = json_decode($requestConfigJson, true);
                    if (!is_array($decoded)) {
                        throw new InvalidArgumentException('Некорректный JSON в "AJAX / Request конфиг"');
                    }
                    $isList = array_keys($decoded) === range(0, count($decoded) - 1);
                    if ($isList) {
                        throw new InvalidArgumentException('"AJAX / Request конфиг" должен быть JSON-объектом, а не массивом.');
                    }
                    $requestConfig = $decoded;
                }

                return [
                    'page_url' => trim((string)($_POST['submission_page_url'] ?? '')),
                    'log_steps' => !empty($_POST['submission_log_steps']) ? 1 : 0,
                    'steps' => $steps,
                    'request_config' => $requestConfig,
                    'success_selector' => trim((string)($_POST['submission_success_selector'] ?? '')),
                    'success_text' => trim((string)($_POST['submission_success_text'] ?? '')),
                    'error_selector' => trim((string)($_POST['submission_error_selector'] ?? '')),
                ];
            },
        ],

        'track_and_label_info' => [
            'default_operation_id' => 'track_and_label_info',
            'label' => 'TrackAndLabelInfo',
            'config_builder' => static function (): array {
                return [];
            },
        ],
    ];

    $operations = [];
    foreach ($legacyOperationDefs as $operationKey => $def) {
        $operationId = trim((string)($_POST[$operationKey . '_operation_id'] ?? $def['default_operation_id']));
        if ($operationId === '') {
            $operationId = $def['default_operation_id'];
        }

        $operations[$operationKey] = array_merge([
            'schema_version' => 2,
            'operation_id' => $operationId,
            'run_after' => connectors_decode_dependency_links_json((string)($_POST[$operationKey . '_run_after_json'] ?? ''), $def['label'] . ' run_after'),
            'run_with' => connectors_decode_dependency_links_json((string)($_POST[$operationKey . '_run_with_json'] ?? ''), $def['label'] . ' run_with'),
            'run_finally' => connectors_decode_dependency_links_json((string)($_POST[$operationKey . '_run_finally_json'] ?? ''), $def['label'] . ' run_finally'),
            'entrypoint' => !empty($_POST[$operationKey . '_entrypoint']) ? 1 : 0,
            'on_dependency_fail' => connectors_normalize_dependency_policy($_POST[$operationKey . '_on_dependency_fail'] ?? 'stop'),
            'enabled' => !empty($_POST[$operationKey . '_enabled']) ? 1 : 0,
        ], $def['config_builder']());
    }

    return $operations;
}


connectors_ensure_schema($dbcnx);

switch ($dispatchAction) {
    case 'view_connectors':
        $connectors = [];
        $sql = "SELECT
                    id,
                    name,
                    countries,
                    base_url,
                    auth_type,
                    auth_username,
                    auth_token,
                    is_active,
                    last_sync_at,
                    last_success_at,
                    last_error,
                    ssl_ignore,
                    scenario_json,
                    last_manual_confirm_at,
                    created_at,
                    updated_at
                FROM connectors
                ORDER BY id DESC";
        if ($res = $dbcnx->query($sql)) {
            while ($row = $res->fetch_assoc()) {
                $connectors[] = connectors_build_status($row);
            }
            $res->free();
        }

        $smarty->assign('connectors', $connectors);
        $smarty->assign('current_user', $user);

        ob_start();
        $smarty->display('cells_NA_API_connectors.html');
        $html = ob_get_clean();

        $response = [
            'status' => 'ok',
            'html'   => $html,
        ];
        break;


    case 'form_new_connector':
        $connector = [
            'id' => 0,
            'name' => '',
            'countries' => '',
            'base_url' => '',
            'auth_type' => 'login',
            'auth_username' => '',
            'auth_password' => '',
            'api_token' => '',
            'auth_token' => '',
            'auth_token_expires_at' => null,
            'auth_cookies' => '',
            'is_active' => 1,
            'last_sync_at' => null,
            'last_success_at' => null,
            'last_error' => '',
            'ssl_ignore' => 0,
            'scenario_json' => '',
            'operations_json' => '',
            'last_manual_confirm_at' => null,
            'last_manual_confirm_by' => null,
            'manual_instruction' => '',
            'notes' => '',
            'created_at' => null,
            'updated_at' => null,
        ];

        $smarty->assign('connector', $connector);

        ob_start();
        $smarty->display('cells_NA_API_connector_modal.html');
        $html = ob_get_clean();

        $response = [
            'status' => 'ok',
            'html'   => $html,
        ];
        break;

    case 'form_edit_connector':
        $connectorId = (int)($_POST['connector_id'] ?? 0);
        if ($connectorId <= 0) {
            $response = [
                'status' => 'error',
                'message' => 'connector_id required',
            ];
            break;
        }

        $connector = connectors_fetch_one($dbcnx, $connectorId);
        if (!$connector) {
            $response = [
                'status' => 'error',
                'message' => 'Коннектор не найден',
            ];
            break;
        }

        $connector['manual_instruction'] = '';
        if (!empty($connector['scenario_json'])) {
            $scenario = json_decode((string)$connector['scenario_json'], true);
            if (is_array($scenario) && isset($scenario['manual_confirm']['instruction'])) {
                $connector['manual_instruction'] = (string)$scenario['manual_confirm']['instruction'];
            }
        }

        $smarty->assign('connector', $connector);

        ob_start();
        $smarty->display('cells_NA_API_connector_modal.html');
        $html = ob_get_clean();

        $response = [
            'status' => 'ok',
            'html'   => $html,
        ];
        break;


    case 'test_connector':
        $connectorId = (int)($_POST['connector_id'] ?? 0);
        if ($connectorId <= 0) {
            $response = [
                'status' => 'error',
                'message' => 'connector_id required',
            ];
            break;
        }

        $userId = (int)($user['id'] ?? 0);
        $result = connector_engine_run_by_id($dbcnx, $connectorId, $userId);
        audit_log(
            $userId,
            $result['ok'] ? 'CONNECTOR_TEST_OK' : 'CONNECTOR_TEST_FAIL',
            'connector',
            $connectorId,
            $result['message'] ?? 'Проверка коннектора'
        );
        $response = [
            'status' => 'ok',
            'ok' => $result['ok'],
            'message' => $result['message'],
            'connector_id' => $connectorId,
        ];
        break;

    case 'manual_confirm_connector':
        $connectorId = (int)($_POST['connector_id'] ?? 0);
        if ($connectorId <= 0) {
            $response = [
                'status' => 'error',
                'message' => 'connector_id required',
            ];
            break;
        }

        $authToken = trim($_POST['auth_token'] ?? '');
        $authCookies = trim($_POST['auth_cookies'] ?? '');
        $authTokenExpiresAt = trim($_POST['auth_token_expires_at'] ?? '');
        $authTokenExpiresAt = $authTokenExpiresAt !== '' ? $authTokenExpiresAt : null;
        $expiresAt = $authTokenExpiresAt !== '' ? $authTokenExpiresAt : null;

        $sql = "UPDATE connectors
                   SET auth_token = ?,
                       auth_cookies = ?,
                       auth_token_expires_at = ?,
                       last_manual_confirm_at = NOW(),
                       last_manual_confirm_by = ?,
                       last_error = ''
                 WHERE id = ?";
        $stmt = $dbcnx->prepare($sql);
        if (!$stmt) {
            $response = [
                'status' => 'error',
                'message' => 'DB error (manual confirm)',
            ];
            break;
        }
        $userId = (int)($user['id'] ?? 0);
        $stmt->bind_param('sssii', $authToken, $authCookies, $expiresAt, $userId, $connectorId);
        $stmt->execute();
        $stmt->close();

        audit_log($userId, 'CONNECTOR_MANUAL_CONFIRM', 'connector', $connectorId, 'Ручное подтверждение токена');
        $response = [
            'status' => 'ok',
            'message' => 'Токен/куки обновлены',
            'connector_id' => $connectorId,
        ];
        break;


    case 'manual_confirm_puppeteer':
        $connectorId = (int)($_POST['connector_id'] ?? 0);
        if ($connectorId <= 0) {
            $response = [
                'status' => 'error',
                'message' => 'connector_id required',
            ];
            break;
        }

        $connector = connectors_fetch_one($dbcnx, $connectorId);
        if (!$connector) {
            $response = [
                'status' => 'error',
                'message' => 'Коннектор не найден',
            ];
            break;
        }

        $baseUrl = trim((string)($connector['base_url'] ?? ''));
        $login = trim((string)($connector['auth_username'] ?? ''));
        $password = trim((string)($connector['auth_password'] ?? ''));
        $scenarioJson = (string)($connector['scenario_json'] ?? '');
        $scenario = json_decode($scenarioJson, true);

        $loginUrl = '';
        if (is_array($scenario) && isset($scenario['login']['url'])) {
            $loginUrl = trim((string)$scenario['login']['url']);
        }
        if ($loginUrl === '' && $baseUrl !== '') {
            $loginUrl = $baseUrl;
        }

        if ($loginUrl === '') {
            $response = [
                'status' => 'error',
                'message' => 'Укажите base_url или login.url в сценарии',
            ];
            break;
        }

        $scriptDir = realpath(__DIR__ . '/../../scripts');
        $scriptPath = $scriptDir ? $scriptDir . '/manual_confirm_puppeteer.js' : '';
        if (!$scriptPath || !is_file($scriptPath)) {
            $response = [
                'status' => 'error',
                'message' => 'Скрипт Puppeteer не найден',
            ];
            break;
        }

        $command = sprintf(
            'node %s %s %s %s',
            escapeshellarg($scriptPath),
            escapeshellarg($loginUrl),
            escapeshellarg($login),
            escapeshellarg($password)
        );

        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);
        $rawOutput = trim(implode("\n", $output));
        $payload = $rawOutput !== '' ? json_decode($rawOutput, true) : null;

        if ($exitCode !== 0 || !is_array($payload)) {
            $response = [
                'status' => 'error',
                'message' => 'Не удалось получить токен/куки через Puppeteer',
                'details' => $rawOutput,
            ];
            break;
        }

        $authToken = trim((string)($payload['auth_token'] ?? ''));
        $authCookies = trim((string)($payload['auth_cookies'] ?? ''));
        $authTokenExpiresAt = trim((string)($payload['auth_token_expires_at'] ?? ''));
        $expiresAt = $authTokenExpiresAt !== '' ? $authTokenExpiresAt : null;

        $sql = "UPDATE connectors
                   SET auth_token = ?,
                       auth_cookies = ?,
                       auth_token_expires_at = ?,
                       last_manual_confirm_at = NOW(),
                       last_manual_confirm_by = ?,
                       last_error = ''
                 WHERE id = ?";
        $stmt = $dbcnx->prepare($sql);
        if (!$stmt) {
            $response = [
                'status' => 'error',
                'message' => 'DB error (manual confirm)',
            ];
            break;
        }
        $userId = (int)($user['id'] ?? 0);
        $stmt->bind_param('sssii', $authToken, $authCookies, $expiresAt, $userId, $connectorId);
        $stmt->execute();
        $stmt->close();

        audit_log($userId, 'CONNECTOR_MANUAL_CONFIRM', 'connector', $connectorId, 'Ручное подтверждение (Puppeteer)');
        $response = [
            'status' => 'ok',
            'message' => 'Токен/куки обновлены через браузер',
            'connector_id' => $connectorId,
        ];
        break;

    case 'manual_confirm_extension':
        $connectorId = (int)($_POST['connector_id'] ?? 0);
        if ($connectorId <= 0) {
            $response = [
                'status' => 'error',
                'message' => 'connector_id required',
            ];
            break;
        }

        $connector = connectors_fetch_one($dbcnx, $connectorId);
        if (!$connector) {
            $response = [
                'status' => 'error',
                'message' => 'Коннектор не найден',
            ];
            break;
        }

        $authToken = trim($_POST['auth_token'] ?? '');
        $authCookies = trim($_POST['auth_cookies'] ?? '');
        $authTokenExpiresAt = trim($_POST['auth_token_expires_at'] ?? '');
        $authTokenExpiresAt = $authTokenExpiresAt !== '' ? $authTokenExpiresAt : null;
        $expiresAt = $authTokenExpiresAt !== '' ? $authTokenExpiresAt : null;

        if ($authToken === '' && $authCookies === '') {
            $response = [
                'status' => 'error',
                'message' => 'Нужен токен или cookies',
            ];
            break;
        }

        $sql = "UPDATE connectors
                   SET auth_token = ?,
                       auth_cookies = ?,
                       auth_token_expires_at = ?,
                       last_manual_confirm_at = NOW(),
                       last_manual_confirm_by = ?,
                       last_error = ''
                 WHERE id = ?";
        $stmt = $dbcnx->prepare($sql);
        if (!$stmt) {
            $response = [
                'status' => 'error',
                'message' => 'DB error (manual confirm)',
            ];
            break;
        }
        $userId = (int)($user['id'] ?? 0);
        $stmt->bind_param('sssii', $authToken, $authCookies, $expiresAt, $userId, $connectorId);
        $stmt->execute();
        $stmt->close();

        audit_log($userId, 'CONNECTOR_MANUAL_CONFIRM', 'connector', $connectorId, 'Ручное подтверждение (Extension)');
        $response = [
            'status' => 'ok',
            'message' => 'Токен/куки обновлены через расширение',
            'connector_id' => $connectorId,
        ];
        break;


    case 'form_connector_operations':
        $connectorId = (int)($_POST['connector_id'] ?? 0);
        if ($connectorId <= 0) {
            $response = [
                'status' => 'error',
                'message' => 'connector_id required',
            ];
            break;
        }

        $connector = connectors_fetch_one($dbcnx, $connectorId);
        if (!$connector) {
            $response = [
                'status' => 'error',
                'message' => 'Коннектор не найден',
            ];
            break;
        }

        $operations = connectors_decode_operations($connector);
        $operationsV3Payload = connectors_decode_operations_payload($connector);
        $lastRunStatusByOperation = connectors_fetch_last_run_status_by_operation($dbcnx, $connectorId, $operationsV3Payload);
        $nodeRuntimeAvailable = connectors_is_node_runtime_available();
        if (!$nodeRuntimeAvailable && (($operations['report']['download_mode'] ?? 'browser') === 'curl')) {
            $operations['report']['download_mode'] = 'browser';
        }
        $smarty->assign('connector', $connector);
        $addons = connectors_fetch_addons($dbcnx, $connectorId);

        $smarty->assign('operations', $operations);
        $smarty->assign('operations_v3_json', json_encode($operationsV3Payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $smarty->assign('operations_last_status_json', json_encode($lastRunStatusByOperation, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $smarty->assign('operation_templates_json', json_encode(connectors_operation_config_templates(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $smarty->assign('addons', $addons);
        $openTab = trim((string)($_POST['open_tab'] ?? ''));
        $smarty->assign('open_tab', $openTab);
        $smarty->assign('node_runtime_available', $nodeRuntimeAvailable);

        if (method_exists($smarty, 'clearCompiledTemplate')) {
            $smarty->clearCompiledTemplate('cells_NA_API_connector_operations_modal.html');
        }

        ob_start();
        $smarty->display('cells_NA_API_connector_operations_modal.html');
        $html = ob_get_clean();

        $response = [
            'status' => 'ok',
            'html' => $html,
            'operation_templates' => connectors_operation_config_templates(),
        ];
        break;

    case 'save_connector_operations':
        $connectorId = (int)($_POST['connector_id'] ?? 0);
        if ($connectorId <= 0) {
            $response = [
                'status' => 'error',
                'message' => 'connector_id required',
            ];
            break;
        }

        $connector = connectors_fetch_one($dbcnx, $connectorId);
        if (!$connector) {
            $response = [
                'status' => 'error',
                'message' => 'Коннектор не найден',
            ];
            break;
        }

        try {
            $operationsPayload = connectors_build_operations_payload_from_post();
            connectors_validate_operations_payload($operationsPayload);
        } catch (InvalidArgumentException $e) {
            $response = [
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
            break;
        }

        $operationsJson = json_encode($operationsPayload, JSON_UNESCAPED_UNICODE);
        if ($operationsJson === false) {
            $response = [
                'status' => 'error',
                'message' => 'Не удалось сериализовать операции',
            ];
            break;
        }

        $stmt = $dbcnx->prepare('UPDATE connectors SET operations_json = ? WHERE id = ?');
        if (!$stmt) {
            $response = [
                'status' => 'error',
                'message' => 'DB error (save operations)',
            ];
            break;
        }
        $stmt->bind_param('si', $operationsJson, $connectorId);
        if (!$stmt->execute()) {
            $response = [
                'status' => 'error',
                'message' => 'DB error (save operations): ' . $stmt->error,
            ];
            $stmt->close();
            break;
        }
        $stmt->close();

        $userId = (int)($user['id'] ?? 0);
        audit_log($userId, 'CONNECTOR_OPERATIONS_UPDATE', 'connector', $connectorId, 'Операции коннектора обновлены');

        $response = [
            'status' => 'ok',
            'message' => 'Операции сохранены',
            'connector_id' => $connectorId,
            'open_tab' => trim((string)($_POST['open_tab'] ?? '')),
        ];
        break;



    case 'save_connector_addons':
        $connectorId = (int)($_POST['connector_id'] ?? 0);
        if ($connectorId <= 0) {
            $response = [
                'status' => 'error',
                'message' => 'connector_id required',
            ];
            break;
        }

        $connector = connectors_fetch_one($dbcnx, $connectorId);
        if (!$connector) {
            $response = [
                'status' => 'error',
                'message' => 'Коннектор не найден',
            ];
            break;
        }

        try {
            $addonsPayload = connectors_build_addons_payload_from_post();
        } catch (InvalidArgumentException $e) {
            $response = [
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
            break;
        }

        $addonsJson = json_encode($addonsPayload['addons'], JSON_UNESCAPED_UNICODE);
        if ($addonsJson === false) {
            $response = [
                'status' => 'error',
                'message' => 'Не удалось сериализовать ДопИнфо',
            ];
            break;
        }

        $nodeMappingJson = json_encode($addonsPayload['node_mapping'], JSON_UNESCAPED_UNICODE);
        if ($nodeMappingJson === false) {
            $response = [
                'status' => 'error',
                'message' => 'Не удалось сериализовать Node mapping',
            ];
            break;
        }

        $statusTargetsJson = json_encode($addonsPayload['status_targets'], JSON_UNESCAPED_UNICODE);
        if ($statusTargetsJson === false) {
            $response = [
                'status' => 'error',
                'message' => 'Не удалось сериализовать карту статусов',
            ];
            break;
        }

        $reportOutStatusesJson = json_encode($addonsPayload['report_out_statuses'], JSON_UNESCAPED_UNICODE);
        if ($reportOutStatusesJson === false) {
            $response = [
                'status' => 'error',
                'message' => 'Не удалось сериализовать карту статусов репорта -> warehouse_item_out.status',
            ];
            break;
        }

        $stmt = $dbcnx->prepare('INSERT INTO connectors_addons (connector_id, connector_name, addons_json, node_mapping_json, status_targets_json, report_out_statuses_json) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE connector_name = VALUES(connector_name), addons_json = VALUES(addons_json), node_mapping_json = VALUES(node_mapping_json), status_targets_json = VALUES(status_targets_json), report_out_statuses_json = VALUES(report_out_statuses_json)');
        if (!$stmt) {
            $response = [
                'status' => 'error',
                'message' => 'DB error (save addons)',
            ];
            break;
        }

        $connectorName = trim((string)($connector['name'] ?? ''));
        $stmt->bind_param('isssss', $connectorId, $connectorName, $addonsJson, $nodeMappingJson, $statusTargetsJson, $reportOutStatusesJson);
        if (!$stmt->execute()) {
            $response = [
                'status' => 'error',
                'message' => 'DB error (save addons): ' . $stmt->error,
            ];
            $stmt->close();
            break;
        }
        $stmt->close();

        $userId = (int)($user['id'] ?? 0);
        audit_log($userId, 'CONNECTOR_ADDONS_UPDATE', 'connector', $connectorId, 'ДопИнфо коннектора обновлено');

        $response = [
            'status' => 'ok',
            'message' => 'ДопИнфо сохранено',
            'connector_id' => $connectorId,
        ];
        break;



    case 'test_connector_operations':
        $connectorId = (int)($_POST['connector_id'] ?? 0);
        $runId = connectors_generate_run_id($connectorId);
        $traceLog = [];
        $executionPlan = [];
        $graphErrors = [];
        $response = [];
        $targetTable = '';
        $periodFrom = null;
        $periodTo = null;
        $reportCfg = [];
        $submissionCfg = [];
        $manualKindFlowHandled = false;
        $runStartedAtTs = microtime(true);
        $runStartedAt = date('Y-m-d H:i:s');
        $testOperation = trim((string)($_POST['test_operation'] ?? 'report'));
        $entrypointMode = trim((string)($_POST['entrypoint_mode'] ?? ''));
        $entrypointDiagnostics = [
            'requested' => [
                'test_operation' => $testOperation ?: 'report',
                'entrypoint_mode' => $entrypointMode,
            ],
            'resolved' => [
                'entrypoint_operation' => '',
                'entrypoint_kind' => '',
            ],
            'source' => [
                'operations_payload' => '',
                'payload_schema_version' => 0,
            ],
            'candidate_php' => [
                'operation_id' => 'report_php',
                'exists' => false,
                'kind' => '',
                'is_runnable' => false,
                'not_runnable_reason' => '',
            ],
            'fallback' => [
                'used' => false,
                'from' => '',
                'to' => '',
                'reason' => '',
            ],
            'factors' => [
                'operations_payload_source' => '',
                'payload_schema_version' => 0,
                'report_php_exists' => false,
                'report_php_kind' => '',
                'report_php_config_valid' => false,
                'dependency_graph_enabled' => false,
                'entrypoint_force_enabled' => false,
                'payload_migration_applied' => false,
            ],
        ];
        if ($connectorId <= 0) {
            $response = [
                'status' => 'error',
                'message' => 'connector_id required',
                'run_id' => $runId,
                'entrypoint_diagnostics' => $entrypointDiagnostics,
            ];
            break;
        }

        $connector = connectors_fetch_one($dbcnx, $connectorId);
        if (!$connector) {
            $response = [
                'status' => 'error',
                'message' => 'Коннектор не найден',
                'run_id' => $runId,
                'entrypoint_diagnostics' => $entrypointDiagnostics,
            ];
            break;
        }

        connectors_append_trace_event($traceLog, $runId, $testOperation ?: 'report', 'start', 'start', 'Запуск теста операции');

        try {
            $hasPostPayload = connectors_has_operations_payload_in_post();
            $entrypointDiagnostics['source']['operations_payload'] = $hasPostPayload ? 'post' : 'db';
            if ($hasPostPayload) {
                $operationsPayloadRaw = connectors_build_operations_payload_from_post();
            } else {
                $operationsPayloadRaw = connectors_decode_operations_payload($connector);
            }

            $operationsPayload = connectors_migrate_operations_payload($operationsPayloadRaw);
            $migrationApplied = json_encode($operationsPayloadRaw, JSON_UNESCAPED_UNICODE) !== json_encode($operationsPayload, JSON_UNESCAPED_UNICODE);
            $entrypointDiagnostics['factors']['payload_migration_applied'] = $migrationApplied;
            connectors_validate_operations_payload($operationsPayload);

            $entrypointResolution = connectors_resolve_test_entrypoint_with_diagnostics($operationsPayload, $testOperation, $entrypointMode, $connector);
            $entrypoint = (string)($entrypointResolution['entrypoint'] ?? '');
            $runtimeOperations = isset($entrypointResolution['runtime_operations']) && is_array($entrypointResolution['runtime_operations'])
                ? $entrypointResolution['runtime_operations']
                : [];
            if (isset($entrypointResolution['diagnostics']) && is_array($entrypointResolution['diagnostics'])) {
                $entrypointDiagnostics = array_replace_recursive($entrypointDiagnostics, $entrypointResolution['diagnostics']);
            }
            $entrypointDiagnostics['source']['operations_payload'] = $hasPostPayload ? 'post' : 'db';
            $entrypointDiagnostics['source']['payload_schema_version'] = (int)($operationsPayload['schema_version'] ?? 0);
            $entrypointDiagnostics['factors']['operations_payload_source'] = $entrypointDiagnostics['source']['operations_payload'];
            $entrypointDiagnostics['factors']['payload_schema_version'] = $entrypointDiagnostics['source']['payload_schema_version'];
            $entrypointDiagnostics['factors']['report_php_exists'] = !empty($entrypointDiagnostics['candidate_php']['exists']);
            $entrypointDiagnostics['factors']['report_php_kind'] = (string)($entrypointDiagnostics['candidate_php']['kind'] ?? '');
            $entrypointDiagnostics['factors']['report_php_config_valid'] = !empty($entrypointDiagnostics['candidate_php']['is_runnable']);
            $entrypointDiagnostics['factors']['dependency_graph_enabled'] = connectors_is_dependency_graph_enabled($connector);

            $entrypointModeNormalized = strtolower(trim($entrypointMode));
            $phpModeRequested = in_array($entrypointModeNormalized, ['php', 'entrypoint_php'], true);
            if (
                $phpModeRequested
                && !empty($entrypointDiagnostics['fallback']['used'])
                && !empty($entrypointDiagnostics['fallback']['reason'])
            ) {
                $fallbackReason = trim((string)($entrypointDiagnostics['fallback']['reason'] ?? ''));
                $candidatePhpOperationId = trim((string)($entrypointDiagnostics['candidate_php']['operation_id'] ?? 'report_php')) ?: 'report_php';
                $fallbackHumanMap = [
                    'missing_php_operation' => 'не найдена операция "' . $candidatePhpOperationId . '"',
                    'php_entrypoint_kind_not_supported' => 'операция "' . $candidatePhpOperationId . '" не поддерживает PHP entrypoint (ожидается kind=php_report или kind=script с interpreter=php/файлом .php)',
                    'missing_script_path' => 'для операции "' . $candidatePhpOperationId . '" не указан config.script_path',
                    'missing_download_mode' => 'для операции "' . $candidatePhpOperationId . '" не указан config.download_mode',
                    'missing_curl_config_url' => 'для операции "' . $candidatePhpOperationId . '" не указан config.curl_config.url',
                ];
                $fallbackHumanReason = $fallbackHumanMap[$fallbackReason] ?? ('fallback reason: ' . $fallbackReason);
                throw new InvalidArgumentException(
                    'Запрошен PHP entrypoint (entrypoint_mode=' . $entrypointMode . '), но ' . $fallbackHumanReason . '. Добавьте/исправьте php-операцию, иначе тест продолжится через Node.'
                );
            }

            if (!is_array($runtimeOperations) || $runtimeOperations === []) {
                $runtimeOperations = connectors_decode_operations_for_runtime($connector);
            }
            if (!is_array($runtimeOperations) || $runtimeOperations === []) {
                throw new InvalidArgumentException('Не удалось подготовить runtime-операции для тестового запуска');
            }


            $entrypointForceEnabled = false;
            $runtimeOperations = connectors_force_enable_test_entrypoint($runtimeOperations, $entrypoint, $entrypointForceEnabled);
            $entrypointDiagnostics['factors']['entrypoint_force_enabled'] = $entrypointForceEnabled;
            if (!empty($entrypointDiagnostics['fallback']['used'])) {
                connectors_append_trace_event(
                    $traceLog,
                    $runId,
                    $testOperation ?: 'report',
                    'entrypoint',
                    'fallback',
                    'Entrypoint fallback применён',
                    [
                        'from' => (string)($entrypointDiagnostics['fallback']['from'] ?? ''),
                        'to' => (string)($entrypointDiagnostics['fallback']['to'] ?? ''),
                        'reason' => (string)($entrypointDiagnostics['fallback']['reason'] ?? ''),
                    ]
                );
            }
            if (connectors_is_dependency_graph_enabled($connector)) {
                try {
                    $executionPlan = connectors_build_execution_plan($runtimeOperations, $entrypoint);
                } catch (InvalidArgumentException $graphException) {
                    $graphErrors[] = connectors_build_graph_error(
                        $runId,
                        $connectorId,
                        $entrypoint,
                        connectors_resolve_graph_error_code($graphException->getMessage()),
                        [
                            'message' => $graphException->getMessage(),
                            'source' => 'manual_test',
                        ]
                    );
                    throw $graphException;
                }
            } else {
                $executionPlan = connectors_build_legacy_execution_plan($entrypoint);
            }

            if (isset($runtimeOperations[$entrypoint]) && is_array($runtimeOperations[$entrypoint])) {
                $periodFrom = connectors_validate_iso_date($_POST['test_period_from'] ?? null);
                $periodTo = connectors_validate_iso_date($_POST['test_period_to'] ?? null);
                if ($periodFrom !== null && $periodTo !== null && $periodFrom > $periodTo) {
                    throw new InvalidArgumentException('Дата начала периода больше даты окончания');
                }



                $executedOperations = connectors_execute_manual_test_execution_plan(
                    $connector,
                    $connectorId,
                    $executionPlan,
                    $runtimeOperations,
                    $periodFrom,
                    $periodTo,
                    $runId,
                    $traceLog
                );
                $manualKindFlowHandled = true;


                $mainOperationId = trim((string)($executionPlan['main'] ?? $entrypoint));
                if ($mainOperationId === '') {
                    $mainOperationId = $entrypoint;
                }

                $mainResult = null;
                $allStepLog = [];
                $lastArtifactsDir = '';
                $lastDownload = null;
                $lastApiResponse = null;
                $lastScriptResult = null;

                foreach ($executedOperations as $executedOperation) {
                    if (!is_array($executedOperation)) {
                        continue;
                    }
                    $result = isset($executedOperation['result']) && is_array($executedOperation['result']) ? $executedOperation['result'] : [];
                    $opId = trim((string)($executedOperation['operation_id'] ?? ''));
                    if ($opId === $mainOperationId) {
                        $mainResult = $result;
                    }
                    if (isset($result['step_log']) && is_array($result['step_log'])) {
                        $allStepLog = array_merge($allStepLog, $result['step_log']);
                    }
                    $artifactDir = trim((string)($result['artifacts_dir'] ?? ''));
                    if ($artifactDir !== '') {
                        $lastArtifactsDir = $artifactDir;
                    }
                    if (isset($result['download']) && is_array($result['download'])) {
                        $lastDownload = $result['download'];
                    }
                    if (isset($result['api_response']) && is_array($result['api_response'])) {
                        $lastApiResponse = $result['api_response'];
                    }
                    if (isset($result['script']) && is_array($result['script'])) {
                        $lastScriptResult = $result['script'];
                    }
                }

                if (!is_array($mainResult)) {
                    $mainResult = isset($executedOperations[0]['result']) && is_array($executedOperations[0]['result']) ? $executedOperations[0]['result'] : [];
                }
                $targetTable = trim((string)($mainResult['target_table'] ?? ''));
                if ($targetTable === '') {
                    foreach ($executedOperations as $executedOperation) {
                        if (!is_array($executedOperation)) {
                            continue;
                        }
                        $operationResult = isset($executedOperation['result']) && is_array($executedOperation['result']) ? $executedOperation['result'] : [];
                        $candidateTargetTable = trim((string)($operationResult['target_table'] ?? ''));
                        if ($candidateTargetTable !== '') {
                            $targetTable = $candidateTargetTable;
                            break;
                        }
                    }
                }
                $response = [
                    'status' => 'ok',
                    'test_operation' => $testOperation,
                    'message' => (string)($mainResult['message'] ?? 'Операции выполнены'),
                    'connector_id' => $connectorId,
                    'run_id' => $runId,
                    'entrypoint_mode' => $entrypointMode,
                    'resolved_entrypoint_operation' => $entrypoint,
                    'imported_rows' => (int)($mainResult['imported_rows'] ?? 0),
                    'rows_detected' => (int)($mainResult['rows_detected'] ?? 0),
                    'import_status' => trim((string)($mainResult['import_status'] ?? '')),
                    'target_table' => $targetTable,
                    'download' => $lastDownload,
                    'api_response' => $lastApiResponse,
                    'script' => $lastScriptResult,
                    'submission_tracking' => (string)($mainResult['submission_tracking'] ?? ''),
                    'step_log' => $allStepLog,
                    'trace_log' => $traceLog,
                    'execution_plan' => $executionPlan,
                    'executed_operations' => $executedOperations,
                    'chain_status' => connectors_build_chain_status_map($executionPlan, $mainOperationId, true, $traceLog),
                    'artifacts_dir' => $lastArtifactsDir,
                    'graph_errors' => $graphErrors,
                    'entrypoint_diagnostics' => $entrypointDiagnostics,
                ];
            }

            if (!$manualKindFlowHandled) {
                $legacyCompatPayload = connectors_build_legacy_compat_operations_view($operationsPayload, $connector);
                $reportCfg = (array)($legacyCompatPayload['report'] ?? []);
                $submissionCfg = (array)($legacyCompatPayload['submission'] ?? []);
                if ($testOperation === 'submission') {
                $submissionResult = connectors_run_submission_test($connector, $submissionCfg);
                $tracking = (string)($submissionResult['tracking_number'] ?? '');
                $suffix = $tracking !== '' ? (' Трек: ' . $tracking . '.') : '';

                $operationId = trim((string)($submissionCfg['operation_id'] ?? 'submission'));
                connectors_append_operation_executed_event($traceLog, $runId, $operationId, 'main', 'success', 'Операция #2 выполнена успешно', 0, null, null, [
                    'tracking_number' => $tracking,
                ]);
                $response = [
                    'status' => 'ok',
                    'test_operation' => 'submission',
                    'message' => 'Тест операции #2 пройден. Проверка Last changes выполнена.' . $suffix,
                    'connector_id' => $connectorId,
                    'run_id' => $runId,
                    'entrypoint_mode' => $entrypointMode,
                    'resolved_entrypoint_operation' => $entrypoint,
                    'open_tab' => 'op2-pane',
                    'submission_tracking' => $tracking,
                    'resolved_success_selector' => (string)($submissionResult['resolved_success_selector'] ?? ''),
                    'resolved_success_text' => (string)($submissionResult['resolved_success_text'] ?? ''),
                    'resolved_error_selector' => (string)($submissionResult['resolved_error_selector'] ?? ''),
                    'captured_error_text' => (string)($submissionResult['captured_error_text'] ?? ''),
                    'step_log' => isset($submissionResult['step_log']) && is_array($submissionResult['step_log']) ? $submissionResult['step_log'] : [],
                    'trace_log' => $traceLog,
                    'execution_plan' => $executionPlan,
                    'chain_status' => connectors_build_chain_status_map($executionPlan, $operationId, true, $traceLog),
                    'artifacts_dir' => (string)($submissionResult['artifacts_dir'] ?? ''),
                    'node_payload' => isset($submissionResult['node_payload']) && is_array($submissionResult['node_payload']) ? $submissionResult['node_payload'] : null,
                    'graph_errors' => $graphErrors,
                    'entrypoint_diagnostics' => $entrypointDiagnostics,
                ];
                }

                if ($testOperation !== 'submission') {
                    $periodFrom = connectors_validate_iso_date($_POST['test_period_from'] ?? null);
                $periodTo = connectors_validate_iso_date($_POST['test_period_to'] ?? null);
                if ($periodFrom !== null && $periodTo !== null && $periodFrom > $periodTo) {
                    throw new InvalidArgumentException('Дата начала периода больше даты окончания');
                }

                $targetTable = connectors_normalize_report_table_name((string)($reportCfg['target_table'] ?? ''));
                connectors_ensure_report_table($dbcnx, $targetTable);
                }
            }
        } catch (InvalidArgumentException $e) {

            if (empty($graphErrors) && connectors_is_dependency_graph_enabled($connector)) {
                $graphErrors[] = connectors_build_graph_error(
                    $runId,
                    $connectorId,
                    isset($entrypoint) ? (string)$entrypoint : '',
                    connectors_resolve_graph_error_code($e->getMessage()),
                    [
                        'message' => $e->getMessage(),
                        'source' => 'manual_test',
                    ]
                );
            }
            $response = [
                'status' => 'error',
                'message' => $e->getMessage(),
                'connector_id' => $connectorId,
                'run_id' => $runId,
                'trace_log' => $traceLog,
                'graph_errors' => $graphErrors,
                'entrypoint_diagnostics' => $entrypointDiagnostics,
            ];
        } catch (ConnectorStepLogException $e) {
            connectors_append_trace_event($traceLog, $runId, $testOperation ?: 'report', 'validate', 'failed', 'Ошибка выполнения шага', [
                'error' => $e->getMessage(),
            ]);
            $exceptionContext = $e->getContext();
            $response = [
                'status' => 'error',
                'message' => $e->getMessage(),
                'connector_id' => $connectorId,
                'test_operation' => $testOperation,
                'run_id' => $runId,
                'target_table' => trim((string)($exceptionContext['target_table'] ?? '')),
                'step_log' => $e->getStepLog(),
                'trace_log' => $traceLog,
                'artifacts_dir' => $e->getArtifactsDir(),
                'subrunner_errors' => isset($exceptionContext['subrunner_errors']) && is_array($exceptionContext['subrunner_errors']) ? $exceptionContext['subrunner_errors'] : [],
                'entrypoint_diagnostics' => $entrypointDiagnostics,
            ];

        } catch (RuntimeException $e) {

            connectors_append_trace_event($traceLog, $runId, $testOperation ?: 'report', 'validate', 'failed', 'Ошибка подготовки операции', [
                'error' => $e->getMessage(),
            ]);
            $response = [
                'status' => 'error',
                'message' => $e->getMessage(),
                'connector_id' => $connectorId,
                'test_operation' => $testOperation,
                'run_id' => $runId,
                'trace_log' => $traceLog,
                'entrypoint_diagnostics' => $entrypointDiagnostics,
            ];
        } catch (Throwable $e) {
            connectors_append_trace_event($traceLog, $runId, $testOperation ?: 'report', 'validate', 'failed', 'Фатальная ошибка подготовки операции', [
                'error' => $e->getMessage(),
            ]);
            error_log('test_connector_operations fatal (prepare): ' . $e->getMessage());
            $response = [
                'status' => 'error',
                'message' => 'Фатальная ошибка подготовки операции: ' . $e->getMessage(),
                'connector_id' => $connectorId,
                'test_operation' => $testOperation,
                'run_id' => $runId,
                'trace_log' => $traceLog,
                'graph_errors' => $graphErrors,
                'entrypoint_diagnostics' => $entrypointDiagnostics,
            ];
        }

        if (($response['status'] ?? '') === 'error' || $manualKindFlowHandled) {
            // Ошибка подготовки: запуск скачивания/импорта пропускаем.
        } else {
            $periodMessage = '';
            if ($periodFrom !== null || $periodTo !== null) {
                $periodMessage = ' Период: ' . ($periodFrom ?? '...') . ' — ' . ($periodTo ?? '...') . '.';
            }
            try {
                $downloadInfo = connectors_download_report_file($connector, $reportCfg, $periodFrom, $periodTo);
                $reportOperationId = trim((string)($reportCfg['operation_id'] ?? 'report'));
                connectors_append_operation_executed_event($traceLog, $runId, $reportOperationId, 'main', 'success', 'Файл успешно скачан', 0, null, null, [
                    'file_size' => (int)($downloadInfo['file_size'] ?? 0),
                ]);
                $importedRows = 0;
                $importMessage = ' Парсинг не выполнен: поддержан авто-импорт CSV/XLSX.';
                $fieldMapping = isset($reportCfg['field_mapping']) && is_array($reportCfg['field_mapping']) ? $reportCfg['field_mapping'] : [];
                $fileExt = strtolower(trim((string)($downloadInfo['file_extension'] ?? '')));
                if ($fileExt === 'csv') {
                    $importedRows = connectors_import_csv_into_report_table(
                        $dbcnx,
                        $targetTable,
                        (string)$downloadInfo['file_path'],
                        $connectorId,
                        $periodFrom,
                        $periodTo,
                        $fieldMapping
                    );
                    $importMessage = ' Импортировано строк: ' . $importedRows . '.';
                } elseif ($fileExt === 'xlsx') {
                    $importedRows = connectors_import_xlsx_into_report_table(
                        $dbcnx,
                        $targetTable,
                        (string)$downloadInfo['file_path'],
                        $connectorId,
                        $periodFrom,
                        $periodTo,
                        $fieldMapping
                    );
                    $importMessage = ' Импортировано строк: ' . $importedRows . '.';
                }

                $response = [
                    'status' => 'ok',
                    'message' => 'Тест операции пройден. Файл скачан (' . (int)($downloadInfo['file_size'] ?? 0) . ' байт). Таблица `' . $targetTable . '` готова.' . $periodMessage . $importMessage,
                    'connector_id' => $connectorId,
                    'run_id' => $runId,
                    'entrypoint_mode' => $entrypointMode,
                    'resolved_entrypoint_operation' => $entrypoint,
                    'target_table' => $targetTable,
                    'period_from' => $periodFrom,
                    'period_to' => $periodTo,
                    'download' => $downloadInfo,
                    'step_log' => isset($downloadInfo['step_log']) && is_array($downloadInfo['step_log']) ? $downloadInfo['step_log'] : [],
                    'trace_log' => $traceLog,
                    'execution_plan' => $executionPlan,
                    'chain_status' => connectors_build_chain_status_map($executionPlan, $reportOperationId, true, $traceLog),
                    'imported_rows' => $importedRows,
                    'entrypoint_diagnostics' => $entrypointDiagnostics,
                ];
            } catch (InvalidArgumentException $e) {
                $reportOperationId = trim((string)($reportCfg['operation_id'] ?? 'report'));
                connectors_append_operation_executed_event($traceLog, $runId, $reportOperationId, 'main', 'failed', 'Ошибка валидации операции #1', 0, null, null, [
                    'error' => $e->getMessage(),
                ]);
                $response = [
                    'status' => 'error',
                    'message' => $e->getMessage(),
                    'connector_id' => $connectorId,
                    'run_id' => $runId,
                    'target_table' => $targetTable,
                    'trace_log' => $traceLog,
                    'execution_plan' => $executionPlan,
                    'chain_status' => connectors_build_chain_status_map($executionPlan, $reportOperationId, false, $traceLog),
                    'entrypoint_diagnostics' => $entrypointDiagnostics,
                ];
            } catch (ConnectorStepLogException $e) {
                $reportOperationId = trim((string)($reportCfg['operation_id'] ?? 'report'));
                connectors_append_operation_executed_event($traceLog, $runId, $reportOperationId, 'main', 'failed', 'Ошибка шага операции #1', 0, null, null, [
                    'error' => $e->getMessage(),
                ]);
                $exceptionContext = $e->getContext();
                $response = [
                    'status' => 'error',
                    'message' => $e->getMessage(),
                    'connector_id' => $connectorId,
                    'run_id' => $runId,
                    'target_table' => trim((string)($exceptionContext['target_table'] ?? $targetTable)),
                    'step_log' => $e->getStepLog(),
                    'trace_log' => $traceLog,
                    'execution_plan' => $executionPlan,
                    'chain_status' => connectors_build_chain_status_map($executionPlan, $reportOperationId, false, $traceLog),
                    'artifacts_dir' => $e->getArtifactsDir(),
                    'subrunner_errors' => isset($exceptionContext['subrunner_errors']) && is_array($exceptionContext['subrunner_errors']) ? $exceptionContext['subrunner_errors'] : [],
                    'entrypoint_diagnostics' => $entrypointDiagnostics,
                ];
            } catch (RuntimeException $e) {
                $reportOperationId = trim((string)($reportCfg['operation_id'] ?? 'report'));
                connectors_append_operation_executed_event($traceLog, $runId, $reportOperationId, 'main', 'failed', 'Ошибка выполнения операции #1', 0, null, null, [
                    'error' => $e->getMessage(),
                ]);
                $response = [
                    'status' => 'error',
                    'message' => $e->getMessage(),
                    'connector_id' => $connectorId,
                    'run_id' => $runId,
                    'target_table' => $targetTable,
                    'trace_log' => $traceLog,
                    'execution_plan' => $executionPlan,
                    'chain_status' => connectors_build_chain_status_map($executionPlan, $reportOperationId, false, $traceLog),
                    'entrypoint_diagnostics' => $entrypointDiagnostics,
                ];
            } catch (Throwable $e) {
                $reportOperationId = trim((string)($reportCfg['operation_id'] ?? 'report'));
                connectors_append_operation_executed_event($traceLog, $runId, $reportOperationId, 'main', 'failed', 'Фатальная ошибка операции #1', 0, null, null, [
                    'error' => $e->getMessage(),
                ]);
                error_log('test_connector_operations fatal: ' . $e->getMessage());
                $response = [
                    'status' => 'error',
                    'message' => 'Ошибка во время теста операции: ' . $e->getMessage(),
                    'connector_id' => $connectorId,
                    'run_id' => $runId,
                    'target_table' => $targetTable,
                    'trace_log' => $traceLog,
                    'execution_plan' => $executionPlan,
                    'chain_status' => connectors_build_chain_status_map($executionPlan, $reportOperationId, false, $traceLog),
                    'entrypoint_diagnostics' => $entrypointDiagnostics,
                ];
            }
            }

        $runFinishedAt = date('Y-m-d H:i:s');
        $runDurationMs = max(0, (int)round((microtime(true) - $runStartedAtTs) * 1000));
        $response['finished_at'] = $runFinishedAt;
        connectors_persist_run_trace($dbcnx, [
            'connector_id' => $connectorId,
            'run_id' => $runId,
            'test_operation' => (string)($response['test_operation'] ?? $testOperation),
            'status' => (string)($response['status'] ?? 'error'),
            'message' => (string)($response['message'] ?? ''),
            'target_table' => (string)($response['target_table'] ?? $targetTable),
            'created_by' => (int)($user['id'] ?? 0),
            'started_at' => $runStartedAt,
            'finished_at' => $runFinishedAt,
            'duration_ms' => $runDurationMs,
            'trace_log' => isset($response['trace_log']) && is_array($response['trace_log']) ? $response['trace_log'] : $traceLog,
            'step_log' => isset($response['step_log']) && is_array($response['step_log']) ? $response['step_log'] : [],
            'execution_plan' => isset($response['execution_plan']) && is_array($response['execution_plan']) ? $response['execution_plan'] : $executionPlan,
            'chain_status' => isset($response['chain_status']) && is_array($response['chain_status']) ? $response['chain_status'] : [],
            'artifacts_dir' => (string)($response['artifacts_dir'] ?? ''),
            'graph_errors' => isset($response['graph_errors']) && is_array($response['graph_errors']) ? $response['graph_errors'] : $graphErrors,
        ]);
        if (!isset($response['graph_errors']) || !is_array($response['graph_errors'])) {
            $response['graph_errors'] = $graphErrors;
        }
        if (!isset($response['entrypoint_diagnostics']) || !is_array($response['entrypoint_diagnostics'])) {
            $response['entrypoint_diagnostics'] = $entrypointDiagnostics;
        }
        break;


    case 'save_connector':
        $connectorId = (int)($_POST['connector_id'] ?? 0);
        $deleteFlag = !empty($_POST['delete']);

        if ($deleteFlag && $connectorId > 0) {
            $stmt = $dbcnx->prepare("DELETE FROM connectors WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param('i', $connectorId);
                $stmt->execute();
                $stmt->close();
            }

            $userId = (int)($user['id'] ?? 0);
            audit_log($userId, 'CONNECTOR_DELETE', 'connector', $connectorId, 'Коннектор удалён');

            $response = [
                'status' => 'ok',
                'message' => 'Коннектор удалён',
                'deleted' => true,
            ];
            break;
        }

        $name = trim($_POST['name'] ?? '');
        if ($name === '') {
            $response = [
                'status' => 'error',
                'message' => 'Название обязательно',
            ];
            break;
        }

        $countries = trim($_POST['countries'] ?? '');
        $baseUrl = trim($_POST['base_url'] ?? '');
        $authType = trim($_POST['auth_type'] ?? 'login');
        $authUsername = trim($_POST['auth_username'] ?? '');
        $authPassword = trim($_POST['auth_password'] ?? '');
        $apiToken = trim($_POST['api_token'] ?? '');
        $authToken = trim($_POST['auth_token'] ?? '');
        $authCookies = trim($_POST['auth_cookies'] ?? '');
        $authTokenExpiresAt = trim($_POST['auth_token_expires_at'] ?? '');
        $authTokenExpiresAt = $authTokenExpiresAt !== '' ? $authTokenExpiresAt : null;
        $scenarioJson = trim($_POST['scenario_json'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        $isActive = !empty($_POST['is_active']) ? 1 : 0;
        $isTestConnector = !empty($_POST['is_test_connector']) ? 1 : 0;
        $sslIgnore = !empty($_POST['ssl_ignore']) ? 1 : 0;

        $isNew = $connectorId <= 0;
        if ($connectorId > 0) {
            $existing = connectors_fetch_one($dbcnx, $connectorId);
            if (!$existing) {
                $response = [
                    'status' => 'error',
                    'message' => 'Коннектор не найден',
                ];
                break;
            }

            if ($authPassword === '') {
                $authPassword = $existing['auth_password'];
            }
            if ($apiToken === '') {
                $apiToken = $existing['api_token'];
            }
            $operationsJson = (string)($existing['operations_json'] ?? '');
            $sql = "UPDATE connectors
                       SET name = ?,
                           countries = ?,
                           base_url = ?,
                           auth_type = ?,
                           auth_username = ?,
                           auth_password = ?,
                           api_token = ?,
                           auth_token = ?,
                           auth_cookies = ?,
                           auth_token_expires_at = ?,
                           is_active = ?,
                           is_test_connector = ?,
                           ssl_ignore = ?,
                           scenario_json = ?,
                           operations_json = ?,
                           notes = ?
                     WHERE id = ?";
            $stmt = $dbcnx->prepare($sql);
            if (!$stmt) {
                $response = [
                    'status' => 'error',
                    'message' => 'DB error (update connector)',
                ];
                break;
            }
            $stmt->bind_param(
                'ssssssssssiiisssi',
                $name,
                $countries,
                $baseUrl,
                $authType,
                $authUsername,
                $authPassword,
                $apiToken,
                $authToken,
                $authCookies,
                $authTokenExpiresAt,
                $isActive,
                $isTestConnector,
                $sslIgnore,
                $scenarioJson,
                $operationsJson,
                $notes,
                $connectorId
            );
            if (!$stmt->execute()) {
                $response = [
                    'status' => 'error',
                    'message' => 'DB error (update connector): ' . $stmt->error,
                ];
                $stmt->close();
                break;
            }
            $stmt->close();
        } else {
            $sql = "INSERT INTO connectors
                        (name, countries, base_url, auth_type, auth_username, auth_password, api_token, auth_token, auth_cookies, auth_token_expires_at, is_active, is_test_connector, ssl_ignore, scenario_json, operations_json, notes)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $dbcnx->prepare($sql);
            if (!$stmt) {
                $response = [
                    'status' => 'error',
                    'message' => 'DB error (insert connector)',
                ];
                break;
            }
            $stmt->bind_param(
                'ssssssssssiiisss',
                $name,
                $countries,
                $baseUrl,
                $authType,
                $authUsername,
                $authPassword,
                $apiToken,
                $authToken,
                $authCookies,
                $authTokenExpiresAt,
                $isActive,
                $isTestConnector,
                $sslIgnore,
                $scenarioJson,
                '',
                $notes
            );
            if (!$stmt->execute()) {
                $response = [
                    'status' => 'error',
                    'message' => 'DB error (insert connector): ' . $stmt->error,
                ];
                $stmt->close();
                break;
            }
            $connectorId = (int)$stmt->insert_id;
            $stmt->close();
        }


        $userId = (int)($user['id'] ?? 0);
        if ($isNew) {
            audit_log($userId, 'CONNECTOR_CREATE', 'connector', $connectorId, 'Коннектор создан');
        } else {
            audit_log($userId, 'CONNECTOR_UPDATE', 'connector', $connectorId, 'Коннектор обновлён');
        }

        $response = [
            'status' => 'ok',
            'message' => 'Коннектор сохранён',
            'connector_id' => $connectorId,
        ];
        break;

    default:        error_log('connector_actions unknown action: normalized=' . $normalizedAction
            . '; route=' . $normalizedRouteAction
            . '; post=' . $normalizedPostAction
            . '; get=' . $normalizedGetAction
            . '; raw=' . json_encode($incomingAction, JSON_UNESCAPED_UNICODE)
            . '; raw_hex=' . bin2hex((string)$incomingAction));
        $response = [
            'status' => 'error',
            'message' => 'Unknown connector action: ' . $normalizedAction,
        ];
        break;
}
