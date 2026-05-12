<?php
// ingest_ohlc.php — приём OHLC и UPSERT в HistDataEURUSD
// Требует: include("/home/ilyamus/GPTFOREX/config/connectDB.php");
// Безопасность: передавайте токен в заголовке Authorization: Bearer XXXXXX

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require "/home/easyt/web/configs/connectDB.php"; // mysqli $dbcnx

// ================== КОНФИГ ==================
const TABLE_NAME   = 'HistDataEURUSD';
const REQUIRE_TOKEN = true;
const TOKEN_ENV     = 'INGEST_TOKEN';       // положи секрет в env, например через systemd Environment
//$serverToken = getenv(TOKEN_ENV) ?: '';     // или зашить константой, если нужно
$serverToken ='SupraTokAmpera888';     // или зашить константой, если нужно
// ============================================

// Авторизация
if (REQUIRE_TOKEN) {
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('~^Bearer\s+(.+)$~i', $auth, $m) || !$serverToken || !hash_equals($serverToken, $m[1])) {
        http_response_code(403);
        echo json_encode(['ok'=>false,'error'=>'forbidden']);
        exit;
    }
}

// Читаем JSON
$raw = file_get_contents('php://input');
$raw = rtrim($raw, "\0");  // <-- убрать завершающий NUL, если прилетел
$in  = json_decode($raw, true, 512, JSON_INVALID_UTF8_IGNORE);
if (!is_array($in)) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'bad json','len'=>strlen($raw)]);
  exit;
}
if ($raw === false || $raw === '') {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'empty body']);
    exit;
}

$in = json_decode($raw, true);
if (!is_array($in)) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'bad json']);
    exit;
}

// Схема ожидаемого payload’а:
// {
//   "symbol": "EURUSD",
//   "period": "M1",
//   "tz_offset_sec": 0,                // опционально; если MT шлёт серверное время, укажи смещение
//   "rows": [                          // массив баров
//     {"ts": 1730124480, "o":1.07320, "h":1.07355, "l":1.07310, "c":1.07340},
//     ...
//   ]
// }
$rows = $in['rows'] ?? null;
if (!is_array($rows) || !$rows) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'rows empty']);
    exit;
}
$tzOffset = isset($in['tz_offset_sec']) ? (int)$in['tz_offset_sec'] : 0;

// Проверим наличие уникального индекса (один раз на запуск)
static $checkedIdx = false;
if (!$checkedIdx) {
    $q = "SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='".TABLE_NAME."'
            AND COLUMN_NAME='timestamp_utc' AND NON_UNIQUE=0 LIMIT 1";
    if (($res = $dbcnx->query($q)) && !$res->fetch_row()) {
        // не критично, но предупредим
        error_log("[ingest_ohlc] WARNING: no UNIQUE on timestamp_utc; upsert may duplicate");
    }
    if ($res) $res->close();
    $checkedIdx = true;
}

// Приводим данные и собираем батчи
$BATCH = 800;
$total = 0; $bad = 0; $insertBatches = 0;
$buf = [];

function f10($v) { // десятичное с точкой, 10 знаков
    return number_format((float)$v, 10, '.', '');
}
function esc(mysqli $db, string $s) { return "'".$db->real_escape_string($s)."'"; }

foreach ($rows as $r) {
    if (!is_array($r)) { $bad++; continue; }
    if (!isset($r['ts'],$r['o'],$r['h'],$r['l'],$r['c'])) { $bad++; continue; }

    // ts может прийти числом (Unix sec) или ISO-строкой
    $ts = $r['ts'];
    if (is_numeric($ts)) {
        $sec = (int)$ts - $tzOffset;                 // приводим к UTC
        if ($sec < 0) { $bad++; continue; }
        $dt = gmdate('Y-m-d H:i:s', $sec);
    } else {
        // ISO -> UTC
        try {
            $dti = new DateTimeImmutable((string)$ts);                 // сам поймёт Z/offset
            $dt  = $dti->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (Throwable $e) { $bad++; continue; }
    }

    $date = substr($dt, 0, 10);
    $time = substr($dt, 11, 8);

    $o = f10($r['o']); $h = f10($r['h']); $l = f10($r['l']); $c = f10($r['c']);

    $buf[] = '('.esc($dbcnx,$dt).','.esc($dbcnx,$date).','.esc($dbcnx,$time).','.$o.','.$h.','.$l.','.$c.')';
    $total++;

    if (count($buf) >= $BATCH) {
        $sql = "INSERT INTO ".TABLE_NAME." (`timestamp_utc`,`date`,`time`,`open`,`high`,`low`,`close`) VALUES "
             . implode(',', $buf)
             . " ON DUPLICATE KEY UPDATE
                 `open`=VALUES(`open`),
                 `high`=VALUES(`high`),
                 `low` =VALUES(`low`),
                 `close`=VALUES(`close`),
                 `date`=VALUES(`date`),
                 `time`=VALUES(`time`)";
        if (!$dbcnx->query($sql)) {
            error_log("[ingest_ohlc] batch error: ".$dbcnx->error);
        } else {
            $insertBatches++;
        }
        $buf = [];
    }
}
if ($buf) {
    $sql = "INSERT INTO ".TABLE_NAME." (`timestamp_utc`,`date`,`time`,`open`,`high`,`low`,`close`) VALUES "
         . implode(',', $buf)
         . " ON DUPLICATE KEY UPDATE
             `open`=VALUES(`open`),
             `high`=VALUES(`high`),
             `low` =VALUES(`low`),
             `close`=VALUES(`close`),
             `date`=VALUES(`date`),
             `time`=VALUES(`time`)";
    if (!$dbcnx->query($sql)) {
        error_log("[ingest_ohlc] tail error: ".$dbcnx->error);
    } else {
        $insertBatches++;
    }
}

echo json_encode([
  'ok' => true,
  'accepted' => $total,
  'skipped'  => $bad,
  'batches'  => $insertBatches
], JSON_UNESCAPED_SLASHES);