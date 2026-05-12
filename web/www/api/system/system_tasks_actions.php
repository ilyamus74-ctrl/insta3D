<?php
declare(strict_types=1);

require_once __DIR__ . '/system_tasks_lib.php';

if (!function_exists('system_tasks_parse_checkbox_flag')) {
    function system_tasks_parse_checkbox_flag($value): int
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        if (is_int($value)) {
            return $value === 1 ? 1 : 0;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if (in_array($normalized, ['1', 'true', 'on', 'yes'], true)) {
                return 1;
            }
        }

        return 0;
    }
}

auth_require_role('ADMIN');
system_tasks_ensure_tables($dbcnx);
system_tasks_seed_defaults($dbcnx);

$response = ['status' => 'error', 'message' => 'Unknown system task action'];

if ($action === 'system_tasks') {
    $tasks = [];
    $res = $dbcnx->query("SELECT * FROM system_tasks ORDER BY id ASC");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $tasks[] = $row;
        }
        $res->free();
    }

    $recentRuns = [];
    $runsRes = $dbcnx->query("SELECT r.id, r.task_id, t.code AS task_code, r.started_at, r.finished_at, r.status, r.message
                              FROM system_task_runs r
                              LEFT JOIN system_tasks t ON t.id = r.task_id
                              ORDER BY r.id DESC
                              LIMIT 50");
    if ($runsRes) {
        while ($row = $runsRes->fetch_assoc()) {
            $recentRuns[] = $row;
        }
        $runsRes->free();
    }

    $smarty->assign('tasks', $tasks);
    $smarty->assign('task_runs', $recentRuns);
    $smarty->assign('current_user', $user);
    $smarty->assign('endpoint_actions', system_tasks_endpoint_registry());

    ob_start();
    $smarty->display('cells_NA_API_system_tasks.html');
    $html = ob_get_clean();

    $response = ['status' => 'ok', 'html' => $html];
}

if ($action === 'save_system_task') {
    $taskId = (int)($_POST['task_id'] ?? 0);
    $code = trim((string)($_POST['code'] ?? ''));
    $name = trim((string)($_POST['name'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $endpointAction = trim((string)($_POST['endpoint_action'] ?? ''));
    $intervalMinutes = max(1, (int)($_POST['interval_minutes'] ?? 60));
    $isEnabled = system_tasks_parse_checkbox_flag($_POST['is_enabled'] ?? 0);

    if ($code === '' || $name === '' || $endpointAction === '') {
        $response = ['status' => 'error', 'message' => 'code, name и endpoint_action обязательны'];
        return;
    }

    if (!system_tasks_is_known_endpoint_action($endpointAction)) {
        $allowed = array_keys(system_tasks_endpoint_registry());
        $response = [
            'status' => 'error',
            'message' => 'Неизвестный endpoint_action. Доступные: ' . implode(', ', $allowed),
        ];
        return;
    }

    if ($taskId > 0) {
        $stmt = $dbcnx->prepare("UPDATE system_tasks
                                 SET code = ?,
                                     name = ?,
                                     description = ?,
                                     endpoint_action = ?,
                                     interval_minutes = ?,
                                     is_enabled = ?,
                                     next_run_at = CASE
                                         WHEN ? = 1 THEN NOW()
                                         ELSE next_run_at
                                     END
                                 WHERE id = ?");
        if (!$stmt) {
            throw new RuntimeException('save_system_task update prepare failed');
        }
        $stmt->bind_param('ssssiiii', $code, $name, $description, $endpointAction, $intervalMinutes, $isEnabled, $isEnabled, $taskId);
        $ok = $stmt->execute();
        $stmt->close();

        $response = $ok
            ? ['status' => 'ok', 'message' => 'Задание обновлено. Следующий запуск пересчитан от текущего времени.']
            : ['status' => 'error', 'message' => 'Не удалось обновить задание'];
        return;
    }

    $stmt = $dbcnx->prepare("INSERT INTO system_tasks (code, name, description, endpoint_action, interval_minutes, is_enabled, next_run_at)
                             VALUES (?, ?, ?, ?, ?, ?, NOW())");
    if (!$stmt) {
        throw new RuntimeException('save_system_task insert prepare failed');
    }
    $stmt->bind_param('ssssii', $code, $name, $description, $endpointAction, $intervalMinutes, $isEnabled);
    $ok = $stmt->execute();
    $stmt->close();

    $response = $ok
        ? ['status' => 'ok', 'message' => 'Задание добавлено']
        : ['status' => 'error', 'message' => 'Не удалось добавить задание'];
}

if ($action === 'delete_system_task') {
    $taskId = (int)($_POST['task_id'] ?? 0);
    if ($taskId <= 0) {
        $response = ['status' => 'error', 'message' => 'task_id required'];
        return;
    }

    $stmt = $dbcnx->prepare("DELETE FROM system_tasks WHERE id = ?");
    if (!$stmt) {
        throw new RuntimeException('delete_system_task prepare failed');
    }
    $stmt->bind_param('i', $taskId);
    $stmt->execute();
    $stmt->close();

    $response = ['status' => 'ok', 'message' => 'Задание удалено'];
}

if ($action === 'run_system_tasks_now') {
    $stats = system_tasks_run_due($dbcnx, (int)($user['id'] ?? 0));
    $response = [
        'status' => 'ok',
        'message' => 'Выполнено задач: ' . (int)$stats['ran'] . ', ошибок: ' . (int)$stats['errors'],
        'stats' => $stats,
    ];
}
