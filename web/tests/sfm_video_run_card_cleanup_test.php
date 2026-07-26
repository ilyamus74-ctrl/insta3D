<?php
declare(strict_types=1);

$template = dirname(__DIR__) . '/templates/maklertour_order_simple.html';
if (!is_file($template)) {
    throw new RuntimeException('template not found');
}

$source = (string) file_get_contents($template);

function run_card_cleanup_ok(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

foreach ([
    "document.querySelectorAll('[data-sfm-lineage]')",
    "element.textContent.trim() === 'Компоненты именно этого Run'",
    "var details = document.createElement('details');",
    "'Компоненты Run: ' + rows.length",
    "' · dense: ' + denseCount",
    "summary.textContent.trim() !== 'Модели и сборки'",
    "if (details) details.remove();",
    "{/literal}\n{literal}\n<script>",
    "</script>\n{/literal}\n</main>",
] as $required) {
    run_card_cleanup_ok(
        str_contains($source, $required),
        'missing cleanup contract: ' . $required
    );
}

echo "OK\n";
