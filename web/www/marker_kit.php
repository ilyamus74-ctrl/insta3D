<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
auth_require_login();

$user = auth_current_user();
if (($user['role'] ?? '') !== 'ADMIN') {
    http_response_code(403);
    exit('Forbidden');
}

$markerKitId = 'maklertour_kit_v1';
$markerDictionary = 'APRILTAG_36H11';
$markers = [];
$error = null;
$success = isset($_GET['success']) ? 'Done' : null;

$stmt = $dbcnx->prepare('SELECT * FROM marker_kit_layout WHERE marker_kit_id = ? AND marker_dictionary = ? ORDER BY marker_id ASC');
if ($stmt) {
    $stmt->bind_param('ss', $markerKitId, $markerDictionary);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($row = $rs->fetch_assoc()) {
        $markers[] = $row;
    }
    $stmt->close();
} else {
    $error = 'DB error';
}

$smarty->assign('markerKitId', $markerKitId);
$smarty->assign('markerDictionary', $markerDictionary);
$smarty->assign('markers', $markers);
$smarty->assign('error', $error);
$smarty->assign('success', $success);
$smarty->display('maklertour_marker_kit.html');
