<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
auth_require_login();

const MARKER_KIT_ID = 'maklertour_kit_v1';
const MARKER_COUNT = 30;
const MARKER_SIZE_MM = 160;

const PRINT_CALIBRATION_MIN = 0.90;
const PRINT_CALIBRATION_MAX = 1.10;

function marker_print_calibration(): float
{
    $raw = $_GET['cal'] ?? null;
    if (!is_string($raw) || $raw === '') {
        return 1.0;
    }
    if (!preg_match('/^\d+(?:[.,]\d+)?$/', $raw)) {
        return 1.0;
    }

    $value = (float)str_replace(',', '.', $raw);
    if ($value < PRINT_CALIBRATION_MIN || $value > PRINT_CALIBRATION_MAX) {
        return 1.0;
    }

    return $value;
}

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




function marker_cropped_png_data_uri(int $id): ?string
{
    if (!function_exists('imagecreatefrompng')) {
        return null;
    }

    $sourceFile = marker_source_file($id);
    if ($sourceFile === null || !is_readable($sourceFile)) {
        return null;
    }

    $img = @imagecreatefrompng($sourceFile);
    if ($img === false) {
        return null;
    }

    $width = imagesx($img);
    $height = imagesy($img);

    $minX = $width;
    $minY = $height;
    $maxX = -1;
    $maxY = -1;

    for ($y = 0; $y < $height; $y++) {
        for ($x = 0; $x < $width; $x++) {
            $rgb = imagecolorat($img, $x, $y);
            $r = ($rgb >> 16) & 0xFF;
            $g = ($rgb >> 8) & 0xFF;
            $b = $rgb & 0xFF;

            if ($r >= 245 && $g >= 245 && $b >= 245) {
                continue;
            }

            if ($x < $minX) { $minX = $x; }
            if ($y < $minY) { $minY = $y; }
            if ($x > $maxX) { $maxX = $x; }
            if ($y > $maxY) { $maxY = $y; }
        }
    }

    if ($maxX < $minX || $maxY < $minY) {
        imagedestroy($img);
        return null;
    }

    $cropW = $maxX - $minX + 1;
    $cropH = $maxY - $minY + 1;
    $size = max($cropW, $cropH);

    $centerX = ($minX + $maxX) / 2;
    $centerY = ($minY + $maxY) / 2;

    $squareMinX = (int)floor($centerX - (($size - 1) / 2));
    $squareMinY = (int)floor($centerY - (($size - 1) / 2));
    $squareMaxX = $squareMinX + $size - 1;
    $squareMaxY = $squareMinY + $size - 1;

    if ($squareMinX < 0) {
        $squareMaxX -= $squareMinX;
        $squareMinX = 0;
    }
    if ($squareMinY < 0) {
        $squareMaxY -= $squareMinY;
        $squareMinY = 0;
    }
    if ($squareMaxX >= $width) {
        $shift = $squareMaxX - ($width - 1);
        $squareMinX -= $shift;
        $squareMaxX = $width - 1;
    }
    if ($squareMaxY >= $height) {
        $shift = $squareMaxY - ($height - 1);
        $squareMinY -= $shift;
        $squareMaxY = $height - 1;
    }

    $squareMinX = max(0, $squareMinX);
    $squareMinY = max(0, $squareMinY);
    $squareMaxX = min($width - 1, $squareMaxX);
    $squareMaxY = min($height - 1, $squareMaxY);

    $finalW = $squareMaxX - $squareMinX + 1;
    $finalH = $squareMaxY - $squareMinY + 1;
    $finalSize = max($finalW, $finalH);

    $out = imagecreatetruecolor($finalSize, $finalSize);
    if ($out === false) {
        imagedestroy($img);
        return null;
    }

    $white = imagecolorallocate($out, 255, 255, 255);
    imagefill($out, 0, 0, $white);

    $dstX = (int)floor(($finalSize - $finalW) / 2);
    $dstY = (int)floor(($finalSize - $finalH) / 2);
    imagecopy($out, $img, $dstX, $dstY, $squareMinX, $squareMinY, $finalW, $finalH);

    ob_start();
    imagepng($out);
    $pngRaw = ob_get_clean();

    imagedestroy($out);
    imagedestroy($img);

    if ($pngRaw === false || $pngRaw === '') {
        return null;
    }

    return 'data:image/png;base64,' . base64_encode($pngRaw);
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
    $printCalibration = marker_print_calibration();
    $printSizeMm = MARKER_SIZE_MM * $printCalibration;
    ?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <title>MaklerTour Marker Kit v1 — print</title>
    <style>
        @page { size: A4 portrait; margin: 10mm; }
        html, body { margin: 0; padding: 0; }
        body {
            font-family: Arial, sans-serif;
            --print-size-mm: <?= htmlspecialchars(number_format($printSizeMm, 3, '.', ''), ENT_QUOTES, 'UTF-8') ?>mm;
        }
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
            width: var(--print-size-mm);
            height: var(--print-size-mm);
            image-rendering: pixelated;
            object-fit: fill;
            border: 0;
        }
        .missing {
            width: var(--print-size-mm);
            height: var(--print-size-mm);
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
        .ruler-160mm { width: var(--print-size-mm); height: 0; border-top: 0.4mm solid #000; margin-top: 4mm; }
        .ruler-label { font-size: 4mm; text-align: center; }
        .gd-warning { width: var(--print-size-mm); color: #b00020; text-align: center; font-size: 4mm; }
    </style>
</head>
<body>
<?php foreach ($ids as $id): ?>
    <section class="marker-page">
        <?php $dataUri = marker_cropped_png_data_uri($id); ?>
        <?php if ($dataUri !== null): ?>
            <img class="marker-img" src="<?= htmlspecialchars($dataUri, ENT_QUOTES, 'UTF-8') ?>" alt="AprilTag ID <?php echo $id; ?>">
        <?php else: ?>
            <div class="missing">source image missing</div>
        <?php endif; ?>
        <div class="ruler-160mm"></div>
        <div class="ruler-label">Control line: <?= htmlspecialchars(number_format($printSizeMm, 1, '.', ''), ENT_QUOTES, 'UTF-8') ?> mm</div>
        <?php if ($dataUri === null && !function_exists('imagecreatefrompng')): ?>
            <div class="gd-warning">GD extension required for calibrated print</div>
        <?php endif; ?>
        <div class="meta">
            <div>MaklerTour Marker Kit v1</div>
            <div><?php echo marker_label($id); ?></div>
            <div>AprilTag 36h11</div>
            <div>ID: <?php echo $id; ?></div>
            <div>Tag size target: 160 mm</div>
            <?php if (abs($printCalibration - 1.0) > 0.0001): ?>
                <div>Print calibration applied: ×<?= htmlspecialchars(number_format($printCalibration, 4, '.', ''), ENT_QUOTES, 'UTF-8') ?> (print size <?= htmlspecialchars(number_format($printSizeMm, 1, '.', ''), ENT_QUOTES, 'UTF-8') ?> mm)</div>
            <?php endif; ?>
            <div>Size is the outer square of the AprilTag after white border crop.</div>
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
        .preview { width: 64px; height: 64px; object-fit: fill; image-rendering: pixelated; }
        .missing-small { color: #b00020; font-size: 12px; }
    </style>
</head>
<body>
    <h1>MaklerTour Marker Kit v1</h1>
    <p><strong>Type:</strong> AprilTag</p>
    <p><strong>Dictionary:</strong> 36h11</p>
    <p><strong>IDs:</strong> 1–30</p>
    <p><strong>Size:</strong> 160 mm (outer square of AprilTag after white-border crop)</p>
    <p class="warn">Печатать в масштабе 100%</p>
    <p class="warn">Не использовать fit-to-page</p>
    <p class="warn">Если напечатанная метка меньше 160 mm, проверьте масштаб печати и отключите fit-to-page.</p>

    <p><a href="/markers.php?print=all&cal=1.022" target="_blank">Печать всего комплекта</a></p>
    <p>Если у вас выходит 157 mm вместо 160 mm, используйте калибровку печати: <code>/markers.php?print=all&amp;cal=1.022</code>.</p>

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
                    <?php $previewDataUri = marker_cropped_png_data_uri($id); ?>
                    <?php if ($previewDataUri === null): ?>
                        <?php $previewDataUri = marker_data_uri($id); ?>
                    <?php endif; ?>
                    <?php if ($previewDataUri !== null): ?>
                        <img class="preview" src="<?= htmlspecialchars($previewDataUri, ENT_QUOTES, 'UTF-8') ?>" alt="tag <?php echo $id; ?>">
                    <?php else: ?>
                        <span class="missing-small">source image missing</span>
                    <?php endif; ?>
                </td>
                <td><a href="/markers.php?print=<?php echo $id; ?>&cal=1.022" target="_blank">Print</a></td>
                <td><a href="/markers.php?img=<?php echo $id; ?>&cal=1.022" target="_blank">Image</a></td>
            </tr>
        <?php endfor; ?>
        </tbody>
    </table>
</body>
</html>
