<?php
declare(strict_types=1);

function mt_create_form_token(mysqli $dbcnx, int $userId, string $formName): string {
    $token = bin2hex(random_bytes(32));

    $stmt = $dbcnx->prepare("
        INSERT INTO form_tokens
        (user_id, token, form_name, expires_at)
        VALUES (?, ?, ?, DATE_ADD(NOW(6), INTERVAL 30 MINUTE))
    ");

    if (!$stmt) {
        throw new RuntimeException('form token prepare error: ' . $dbcnx->error);
    }

    $stmt->bind_param("iss", $userId, $token, $formName);

    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('form token execute error');
    }

    $stmt->close();

    return $token;
}

function mt_consume_form_token(mysqli $dbcnx, int $userId, string $formName, string $token): bool {
    $token = trim($token);

    if ($token === '') {
        return false;
    }

    $stmt = $dbcnx->prepare("
        UPDATE form_tokens
        SET is_used = 1,
            used_at = NOW(6)
        WHERE token = ?
          AND user_id = ?
          AND form_name = ?
          AND is_used = 0
          AND expires_at > NOW(6)
        LIMIT 1
    ");

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param("sis", $token, $userId, $formName);
    $stmt->execute();
    $ok = ($stmt->affected_rows === 1);
    $stmt->close();

    return $ok;
}
