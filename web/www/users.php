<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

auth_require_login();

$user = auth_current_user();
$userId = (int)$user['id'];
$role = $user['role'] ?? 'BROKER';

if ($role !== 'ADMIN') {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$error = null;
$success = isset($_GET['updated']) ? 'Пользователь обновлён' : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_user') {
        $targetUserId = (int)($_POST['user_id'] ?? 0);
        $newRole = trim((string)($_POST['role'] ?? ''));
        $newIsActive = (int)($_POST['is_active'] ?? 0);

        $allowedRoles = ['ADMIN', 'BROKER', 'OPERATOR', 'CLIENT'];

        if ($targetUserId <= 0) {
            $error = 'Некорректный user_id';
        } elseif (!in_array($newRole, $allowedRoles, true)) {
            $error = 'Некорректная роль';
        } elseif (!in_array($newIsActive, [0, 1], true)) {
            $error = 'Некорректное значение is_active';
        } else {
            $stmt = $dbcnx->prepare("\n                UPDATE users\n                SET role = ?, is_active = ?\n                WHERE id = ?\n            ");

            if (!$stmt) {
                $error = 'DB prepare error: ' . $dbcnx->error;
            } else {
                $stmt->bind_param('sii', $newRole, $newIsActive, $targetUserId);

                if ($stmt->execute()) {
                    audit_log(
                        $userId,
                        'USER_UPDATED',
                        'USER',
                        $targetUserId,
                        'Обновлены роль/активность пользователя',
                        [
                            'role' => $newRole,
                            'is_active' => $newIsActive,
                        ]
                    );
                    $stmt->close();
                    header('Location: /users.php?updated=1');
                    exit;
                } else {
                    $error = 'DB execute error: ' . $stmt->error;
                }
            }
        }
    }
}

$users = [];
$stmt = $dbcnx->prepare("\n    SELECT id, username, email, full_name, role, is_active, created_at, last_login_at\n    FROM users\n    ORDER BY id DESC\n");

if ($stmt) {
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $users[] = $row;
    }
    $stmt->close();
} else {
    $error = 'DB prepare error: ' . $dbcnx->error;
}

$smarty->assign('users', $users);
$smarty->assign('error', $error);
$smarty->assign('success', $success);
$smarty->display('maklertour_users.html');
