<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
auth_require_login();

const MARKER_KIT_ID = 'maklertour_kit_v1';
const MARKER_COUNT = 30;
const MARKER_SIZE_MM = 160;

function marker_id_from_get(string $key): ?int
{
    if (!isset($_GET[$key])) {
        return null;
    }
    $raw = (string)$_GET[$key];
    if (!preg_match('/^\d+$/', $raw)) {
        return null;
    }
    $id = (int)$raw;
    if ($id < 1 || $id > MARKER_COUNT) {
        return null;
    }
    return $id;
}

function marker_source_file(int $id): ?string
{
    $baseDir = APP_STORAGE_DIR . '/marker_kits/' . MARKER_KIT_ID . '/source/tag36h11';
    $nameA = sprintf('tag36_11_%05d.png', $id);
    $nameB = sprintf('tag36h11_%05d.png', $id);

    $candidates = [
        $baseDir . '/' . $nameA,
        $baseDir . '/' . $nameB,
    ];

    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    return null;
}


function marker_data_uri(int $id): ?string
{
    $sourceFile = marker_source_file($id);
    if ($sourceFile === null || !is_readable($sourceFile)) {
        return null;
    }

    $raw = file_get_contents($sourceFile);
    if ($raw === false || $raw === '') {
        return null;
    }

    return 'data:image/png;base64,' . base64_encode($raw);
}

function marker_label(int $id): string
{
    return 'MT-' . str_pad((string)$id, 3, '0', STR_PAD_LEFT);
}

$imgId = marker_id_from_get('img');
if (isset($_GET['img'])) {
    if ($imgId === null) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Marker image not found';
        exit;
    }

    $sourceFile = marker_source_file($imgId);
    if ($sourceFile === null) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Marker image not found';
        exit;
    }

    header('Content-Type: image/png');
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: inline; filename="' . basename($sourceFile) . '"');
    readfile($sourceFile);
    exit;
}

$printAll = isset($_GET['print']) && $_GET['print'] === 'all';
$printOneId = marker_id_from_get('print');

if (isset($_GET['print']) && !$printAll && $printOneId === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Print mode not found';
    exit;
}

if ($printAll || $printOneId !== null) {
    $ids = $printAll ? range(1, MARKER_COUNT) : [$printOneId];
    ?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <title>MaklerTour Marker Kit v1 — print</title>
    <style>
        @page { size: A4 portrait; margin: 10mm; }
        html, body { margin: 0; padding: 0; }
        body { font-family: Arial, sans-serif; }
        .marker-page {
            page-break-after: always;
            width: 190mm;
            min-height: 277mm;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 8mm;
            margin: 0 auto;
        }
        .marker-page:last-child { page-break-after: auto; }
        .marker-img {
            width: 160mm;
            height: 160mm;
            image-rendering: pixelated;
            object-fit: contain;
            border: 0;
        }
        .missing {
            width: 160mm;
            height: 160mm;
            border: 1px dashed #888;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 6mm;
            color: #b00020;
            text-align: center;
            padding: 5mm;
            box-sizing: border-box;
        }
        .meta { text-align: center; line-height: 1.5; font-size: 5mm; }
    </style>
</head>
<body>
<?php foreach ($ids as $id): ?>
    <section class="marker-page">
        <?php $dataUri = marker_data_uri($id); ?>
        <?php if ($dataUri !== null): ?>
            <img class="marker-img" src="<?= htmlspecialchars($dataUri, ENT_QUOTES, 'UTF-8') ?>" alt="AprilTag ID <?php echo $id; ?>">
        <?php else: ?>
            <div class="missing">source image missing</div>
        <?php endif; ?>
        <div class="meta">
            <div>MaklerTour Marker Kit v1</div>
            <div><?php echo marker_label($id); ?></div>
            <div>AprilTag 36h11</div>
            <div>ID: <?php echo $id; ?></div>
            <div>Tag size: 160 mm</div>
        </div>
    </section>
<?php endforeach; ?>
</body>
</html>
<?php
    exit;
}
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <title>MaklerTour Marker Kit v1</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 24px; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background: #f5f5f5; }
        .warn { color: #b00020; font-weight: bold; }
        .preview { width: 64px; height: 64px; object-fit: contain; image-rendering: pixelated; }
        .missing-small { color: #b00020; font-size: 12px; }
    </style>
</head>
<body>
    <h1>MaklerTour Marker Kit v1</h1>
    <p><strong>Type:</strong> AprilTag</p>
    <p><strong>Dictionary:</strong> 36h11</p>
    <p><strong>IDs:</strong> 1–30</p>
    <p><strong>Size:</strong> 160 mm</p>
    <p class="warn">Печатать в масштабе 100%</p>
    <p class="warn">Не использовать fit-to-page</p>

    <p><a href="/markers.php?print=all" target="_blank">Печать всего комплекта</a></p>

    <table>
        <thead>
            <tr>
                <th>Code</th>
                <th>Preview</th>
                <th>Print</th>
                <th>Image</th>
            </tr>
        </thead>
        <tbody>
        <?php for ($id = 1; $id <= MARKER_COUNT; $id++): ?>
            <tr>
                <td><?php echo marker_label($id); ?></td>
                <td>
                    <?php $dataUri = marker_data_uri($id); ?>
                    <?php if ($dataUri !== null): ?>
                        <img class="preview" src="<?= htmlspecialchars($dataUri, ENT_QUOTES, 'UTF-8') ?>" alt="tag <?php echo $id; ?>">
                    <?php else: ?>
                        <span class="missing-small">source image missing</span>
                    <?php endif; ?>
                </td>
                <td><a href="/markers.php?print=<?php echo $id; ?>" target="_blank">Print</a></td>
                <td><a href="/markers.php?img=<?php echo $id; ?>" target="_blank">Image</a></td>
            </tr>
        <?php endfor; ?>
        </tbody>
    </table>
</body>
</html>
