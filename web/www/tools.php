<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

auth_require_login();

$currentUser = auth_current_user();

$operatorChecklist = [
    'Перед съёмкой зарядить Insta360.',
    'Подключить телефон к Wi‑Fi камеры.',
    'Открыть заявку.',
    'Выбрать или создать сессию.',
    'Расставить метки, если нужна метрическая реконструкция.',
    'Снять video scan.',
    'Снять photo points.',
    'Проверить черновик.',
    'Выгрузить на сервер через Wi‑Fi.',
];

$insta360Checklist = [
    'Включить камеру.',
    'Проверить режим фото/видео через APP.',
    'Не выключать камеру во время download.',
    'Следить за зарядом и доступной памятью.',
];

$printRules = [
    'Печатать в масштабе 100%.',
    'Не использовать fit-to-page.',
    'Проверить: внешний квадрат AprilTag = 160 мм.',
    'Использовать матовую бумагу.',
    'Не использовать одинаковый ID дважды в одном объекте.',
];

$smarty->assign('current_user', $currentUser);
$smarty->assign('operatorChecklist', $operatorChecklist);
$smarty->assign('insta360Checklist', $insta360Checklist);
$smarty->assign('printRules', $printRules);

$smarty->display('maklertour_tools.html');
