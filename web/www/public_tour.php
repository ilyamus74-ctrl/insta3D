<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
$token=trim((string)($_GET['t']??''));
if($token===''){ http_response_code(400); exit('Bad token'); }
$smarty->assign('sessionId', 0);
$smarty->assign('orderId', 0);
$smarty->assign('orderTitle', 'Public Tour');
$smarty->assign('sessionUuid', 'public');
$smarty->assign('publicMode', 1);
$smarty->assign('publicToken', $token);
$smarty->assign('apiUrl', '/api/public_tour_session.php?token=' . rawurlencode($token));
$smarty->display('maklertour_public_tour.html');
