<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$selectorPath = $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/ui/session/DualPhoneDepthProfileModeSelector.kt';
$contractPath = $root . '/app/MaklerTour/docs/APP_DUAL_PHONE_LM02_7A_2_1_PROFILE_MENU_CONTRACT.md';

foreach ([$selectorPath, $contractPath] as $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "[FAIL] missing {$path}\n");
        exit(1);
    }
}

$selector = file_get_contents($selectorPath);
$contract = file_get_contents($contractPath);

$checks = [
    'collapsed control keeps selected and active profiles visible' =>
        str_contains($selector, 'DEPTH ${selected.shortLabel} → $activeProfile'),
    'three-line affordance opens an overflow menu' =>
        str_contains($selector, 'text = "☰"') &&
        str_contains($selector, 'DropdownMenu(') &&
        str_contains($selector, 'expanded = expanded'),
    'all profile modes remain selectable' =>
        str_contains($selector, 'DualPhoneDepthProfileMode.values().forEach') &&
        str_contains($selector, 'DualPhoneDepthProfileSelection.select(mode)'),
    'old horizontal profile strip is removed' =>
        !str_contains($selector, 'horizontalScroll(') &&
        !str_contains($selector, 'rememberScrollState('),
    'runtime override remains visible' =>
        str_contains($selector, 'thermal/runtime override'),
    'contract excludes depth and clock changes' =>
        str_contains($contract, 'no clock, transport, depth or thermal policy changes'),
];

$failed = false;
foreach ($checks as $label => $ok) {
    if ($ok) {
        fwrite(STDOUT, "[OK] {$label}\n");
    } else {
        fwrite(STDERR, "[FAIL] {$label}\n");
        $failed = true;
    }
}

exit($failed ? 1 : 0);
