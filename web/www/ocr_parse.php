<?php
declare(strict_types=1);
mb_internal_encoding('UTF-8');
header('Content-Type: application/json; charset=utf-8');

function json_error(string $msg): void {
    echo json_encode([
        'status'  => 'error',
        'message' => $msg,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$rawText = $_POST['raw_text'] ?? '';
$rawText = trim($rawText);
if ($rawText === '') {
    json_error('raw_text is empty');
}

// Нормализация текста
function normalize_text(string $s): string {
    $s = str_replace(["\r\n", "\r"], "\n", $s);
    $lines = array_map('trim', explode("\n", $s));
    $lines = array_values(array_filter($lines, fn($l) => $l !== ''));
    return implode("\n", $lines);
}

$text = normalize_text($rawText);

// --------------------------------------------------
// 1) Конфиг стран / направлений и форвардеров
//    (по твоему JSON из ocr-templates-destcountry / описанию)
// --------------------------------------------------

$DEST_CONFIG = [
    // TLS / Германия
/*    [
        'destCode'   => 'DE',
        'code_iso2'  => 'DE',
        'name_en'    => 'Germany',
        'name_local' => 'Deutschland',
        'aliases'    => [
            'TLS Cargo Gmbh',
            'TLS Cargo GmbH',
            'Starkenburgstr.10',
            'Starkenburgstr 10',
            'Starkenburgstr10',
        ],
        'forwarders' => [
            [
                'code'    => 'TLS',
                'name'    => 'TLS Cargo GmbH',
                'aliases' => [
                    'TLS Cargo Gmbh',
                    'TLS Cargo GmbH',
                    'TLS Cargo',
                    'Starkenburgstr.10',
                    'Starkenburgstr 10',
                    'Starkenburgstr10',
                ],
            ],
        ],
    ],
*/
    // AZB / Азербайджан (Baku)
    [
        'destCode'   => 'AZB',
        'code_iso2'  => 'AZ',
        'name_en'    => 'Azerbaijan',
        'name_local' => 'Азербайджан',
        'aliases'    => [
            'AZB',
            'AZB - Colibri Express',
            'AZB - Postlink',
            'AZB - Camex',
            'AZB - Kolli MMC',
            'AZB - AserCargo',
            'AZB - KargoFlex',
            'Starkenburgstr.10C',
            'Starkenburgstr 10C',
            'Starkenburgstr10C',
            'Starkenburgstr.10H',
            'Starkenburgstr 10H',
            'Starkenburgstr10H',
            'Starkenburgstr.10A',
            'Starkenburgstr 10A',
            'Starkenburgstr10A',
            'Starkenburgstr.10K',
            'Starkenburgstr 10K',
            'Starkenburgstr10K',
            'Starkenburgstr.10G',
            'Starkenburgstr 10G',
            'Starkenburgstr10G',
            'Starkenburgstr.10T',
            'Starkenburgstr 10T',
            'Starkenburgstr10T',
        ],
        'forwarders' => [
            [
                'code'    => 'COLIBRI',
                'name'    => 'Colibri Express',
                'aliases' => [
                    'AZB - Colibri Express',
                    'Colibri Express',
                    'COLIBRI EXPRESS',
                    'colibri',
                    'Starkenburgstr.10C',
                    'Starkenburgstr 10C',
                    'Starkenburgstr10C',
                ],
            ],
            [
                'code'    => 'POSTLINK',
                'name'    => 'Postlink',
                'aliases' => [
                    'AZB - Postlink',
                    'Postlink',
                    'POSTLINK',
                    'Starkenburgstr.10H',
                    'Starkenburgstr 10H',
                    'Starkenburgstr10H',
                ],
            ],
            [
                'code'    => 'CAMEX',
                'name'    => 'Camex',
                'aliases' => [
                    'AZB - Camex',
                    'Camex',
                    'CAMEX',
                    'CAMEX Express',
                    'Camex Express',
                    'Camex LLC',
                    'Starkenburgstr.10A',
                    'Starkenburgstr 10A',
                    'Starkenburgstr10A',
                ],
            ],
            [
                'code'    => 'KOLLI',
                'name'    => 'Kolli MMC',
                'aliases' => [
                    'AZB - Kolli MMC',
                    'Kolli MMC',
                    'KOLLI MMC',
                    'Starkenburgstr.10K',
                    'Starkenburgstr 10K',
                    'Starkenburgstr10K',
                ],
            ],
            [
                'code'    => 'ASER',
                'name'    => 'ASER Express',
                'aliases' => [
                    'AZB - AserCargo',
                    'AserCargo',
                    'ASER Express',
                    'ASER EXPRESS',
                    'ASER Express -',
                    'Starkenburgstr.10G',
                    'Starkenburgstr 10G',
                    'Starkenburgstr10G',
                ],
            ],
            [
                'code'    => 'KARGOFLEX',
                'name'    => 'KargoFlex',
                'aliases' => [
                    'AZB - KargoFlex',
                    'KargoFlex',
                    'KARGOFLEX',
                    'Starkenburgstr.10T',
                    'Starkenburgstr 10T',
                    'Starkenburgstr10T',
                ],
            ],
        ],
    ],

    // TBS / Грузия
[
    'destCode'   => 'TBS',
    'code_iso2'  => 'GE',
    'name_en'    => 'Georgia',
    'name_local' => 'Грузія',
    'aliases'    => [
        'TBS',
        'TBS - Camaratc LCC',
        'KG - Camaratc LCC TBS',
        'Starkenburgstr.10B',
        'Starkenburgstr 10B',
        'Starkenburgstr10B',
    ],
    'forwarders' => [
        [
            'code'    => 'CAMARATC',
            'name'    => 'Camaratc LLC',
            'aliases' => [
                'Camaratc LLC',
                'Camaratc LCC',
                'Starkenburgstr.10B',
                'Starkenburgstr 10B',
                'Starkenburgstr10B',
            ],
        ],
    ],
],

    // KG / Киргизстан
[
    'destCode'   => 'KG',
    'code_iso2'  => 'KG',
    'name_en'    => 'Kyrgyzstan',
    'name_local' => 'Киргизстан',
    'aliases'    => [
        'KG',
        'KG - Camaratc',
        'Starkenburgstr.10E',
        'Starkenburgstr 10E',
        'Starkenburgstr10E',
    ],
    'forwarders' => [
        [
            'code'    => 'CAMARATC',
            'name'    => 'Camaratc',
            'aliases' => [
                'Camaratc',
                'KG - Camaratc',
                'Starkenburgstr.10E',
                'Starkenburgstr 10E',
                'Starkenburgstr10E',
            ],
        ],
    ],
],
];

// Если надо — можно скопировать forwarders из AZB в TBS/KG
foreach ($DEST_CONFIG as &$c) {
    if (in_array($c['destCode'], ['TBS', 'KG'], true) && empty($c['forwarders'])) {
        // берем из AZB
        foreach ($DEST_CONFIG as $src) {
            if ($src['destCode'] === 'AZB') {
                $c['forwarders'] = $src['forwarders'];
                break;
            }
        }
    }
}
unset($c);

// --------------------------------------------------
// 2) Детект страны / форвардера
// --------------------------------------------------

function detect_dest_country_and_forwarder(string $text, array $countries): array {
    $textLower = mb_strtolower($text, 'UTF-8');

    $bestCountry = null;
    $bestCountryScore = 0;

    foreach ($countries as $country) {
        $score = 0;
        foreach ($country['aliases'] as $alias) {
            $a = mb_strtolower($alias, 'UTF-8');
            if ($a === '') {
                continue;
            }
            if (mb_strpos($textLower, $a) !== false) {
                $score++;
            }
        }
        if ($score > $bestCountryScore) {
            $bestCountryScore = $score;
            $bestCountry = $country;
        }
    }

    $bestForwarder = null;
    $bestFwScore   = 0;

    if ($bestCountry !== null && !empty($bestCountry['forwarders'])) {
        foreach ($bestCountry['forwarders'] as $fw) {
            $score = 0;
            foreach ($fw['aliases'] as $alias) {
                $a = mb_strtolower($alias, 'UTF-8');
                if ($a === '') {
                    continue;
                }
                if (mb_strpos($textLower, $a) !== false) {
                    $score++;
                }
            }
            if ($score > $bestFwScore) {
                $bestFwScore   = $score;
                $bestForwarder = $fw;
            }
        }
    }

    return [
        'destCode'      => $bestCountry['destCode']   ?? null,
        'countryName'   => $bestCountry['name_en']    ?? null,
        'forwarderCode' => $bestForwarder['code']     ?? null,
        'forwarderName' => $bestForwarder['name']     ?? null,
    ];
}

// --------------------------------------------------
// 3) Код ячейки: A66050 / AS228905 / C163361 / C41 …
///  буквы + цифры (ZIP не цепляем, там только цифры)
// --------------------------------------------------

function detect_cell_code(string $text): ?string {
    // Ищем все кандидаты вида БУКВЫ+ЦИФРЫ (OCR может перепутать 0 и O)
    if (!preg_match_all('/\b([A-Z]{1,3}[0-9O]{2,8})\b/iu', $text, $m)) {
        return null;
    }

    // Коды, которые НИКОГДА не считаем ячейкой
    $bannedPrefixes = ['PZ', 'URC'];

//    $bestKnown = null;

    $textLower = mb_strtolower($text, 'UTF-8');

    // Простые хинты по упоминанию форвардеров в тексте, чтобы выбрать
    // наиболее релевантный код ячейки, если распознали несколько вариантов.
    $forwarderHints = [
        'COLIBRI'   => ['colibri'],
        'KOLLI'     => ['koli', 'kolli'],
        'ASER'      => ['aser'],
        'CAMEX'     => ['camex'],
        'KARGOFLEX' => ['kargoflex', 'kargo'],
        'CAMARATC'  => ['camaratc'],
        'POSTLINK'  => ['postlink'],
    ];

    $candidates = [];
    $idx = 0;

    foreach ($m[1] as $rawCode) {
        $code = strtoupper($rawCode);

        if (!preg_match('/^([A-Z]+)([0-9O]{2,8})$/', $code, $p)) {
            continue;
        }
        $prefix = $p[1];
        if (in_array($prefix, $bannedPrefixes, true)) {
            // пропускаем PZ63, URC84 и тому подобное
            continue;
        }
        $digits = strtr($p[2], ['O' => '0']);
        $normalizedCode = $prefix . $digits;

        $variants = [$normalizedCode];

        // OCR иногда путает буквы в префиксе:
        //  - KI → KL в кодах Колли
        if (preg_match('/^KI(\d+)$/', $normalizedCode, $kiParts)) {
            $variants[] = 'KL' . $kiParts[1];
        }

        // OCR иногда вставляет лишнюю букву "O" после префикса вместо нуля, например PLO0152
        // вместо PL00152. Пытаемся восстановить такую ошибку.
        if (preg_match('/O$/', $prefix)) {
            $variants[] = substr($prefix, 0, -1) . '0' . $digits;
        }
        foreach ($variants as $variant) {
            $forwarder = detect_forwarder_by_cell_code($variant);
            if ($forwarder === null) {
                continue;
            }

            $score = 1;
            if (isset($forwarderHints[$forwarder])) {
                foreach ($forwarderHints[$forwarder] as $alias) {
                    if ($alias !== '' && mb_strpos($textLower, $alias) !== false) {
                        $score++;
                    }
                }
            }

            $candidates[] = [
                'code'  => $variant,
                'score' => $score,
                'idx'   => $idx++,
            ];

            // не добавляем дубль одной и той же разновидности второй раз
            break;

        }

    }
    if ($candidates === []) {
        return null;
    }

    usort($candidates, function ($a, $b) {
        $byScore = $b['score'] <=> $a['score'];
        if ($byScore !== 0) {
            return $byScore;
        }

        // При равном счёте предпочитаем более длинные коды (чаще истинные ячейки),
        // а уже потом — те, что встретились раньше в тексте.
        $byLength = strlen($b['code']) <=> strlen($a['code']);
        if ($byLength !== 0) {
            return $byLength;
        }

        return $a['idx'] <=> $b['idx'];
    });

    return $candidates[0]['code'];
}

// --------------------------------------------------
// 4) ФИО клиента
// --------------------------------------------------
function load_ocr_name_dict(): array {
    static $dict = null;
    if ($dict !== null) {
        return $dict;
    }

    $dict = [
        'exact_bad'  => [],
        'substr_bad' => [],
    ];

    $path = __DIR__ . '/ocr_name_dict.json';
    if (is_file($path)) {
        $content = file_get_contents($path);
        if ($content !== false) {
            $data = json_decode($content, true);
            if (is_array($data)) {
                foreach (['exact_bad', 'substr_bad'] as $key) {
                    if (isset($data[$key]) && is_array($data[$key])) {
                        $dict[$key] = array_values(array_map(
                            fn($v) => mb_strtolower((string)$v, 'UTF-8'),
                            $data[$key]
                        ));
                    }
                }
            }
        }
    }

    return $dict;
}

function looks_like_person_name(string $line): bool {
    $line = trim($line);
    if ($line === '') return false;

    // слишком длинные строки - почти никогда не только имя
    if (mb_strlen($line, 'UTF-8') > 45) {
        return false;
    }

    $low = mb_strtolower($line, 'UTF-8');
    $dict = load_ocr_name_dict();
    // чисто служебные/мусорные значения 1:1
    $exactBad = $dict['exact_bad'] ?? [];
    if (in_array($low, $exactBad, true)) {
        return false;
    }

    // явные служебные префиксы целиком строк
    if (preg_match(
        '/^(zal\s+deu|dhl\s+paket|we\s+do!?|ve\s+do!?|von:|from:|shipment\s+no:|sendungs\-id|sendungs\-d|deutsche\s+post|dhl\s+parcel|dhl\s+warenpost|epg\s+one|ups\s+standard)/iu',
        $line
    )) {
        return false;
    }

    // TLS вообще не имя
    if (preg_match('/TLS\s+CARGO/iu', $line)) {
        return false;
    }

    // мусор / организации по подстрокам
    $badSubstrings = $dict['substr_bad'] ?? [];
    foreach ($badSubstrings as $b) {
        if ($b !== '' && mb_strpos($low, $b) !== false) {
            return false;
        }
    }

    // должен быть хотя бы один буквенный символ
    if (!preg_match('/[A-Za-zÄÖÜäöüß]/u', $line)) {
        return false;
    }

    // не хотим строки с цифрами - почти всегда индексы/вес/коды
    if (preg_match('/\d/u', $line)) {
        return false;
    }

    // минимум 2 слова (режем "Hermes", "Weight", "Poing", "XUN" и т.п.)
    $words = preg_split('/\s+/u', $line);
    $words = array_values(array_filter($words, fn($w) => $w !== ''));
    if (count($words) < 2) {
        return false;
    }

    // хотя бы одно слово с заглавной буквы
    $capitalWords = 0;
    foreach ($words as $w) {
        $first = mb_substr($w, 0, 1);
        if ($first !== '' && preg_match('/\p{Lu}/u', $first)) {
            $capitalWords++;
        }
    }
    if ($capitalWords === 0) {
        return false;
    }

    return true;
}



function clean_name_line(string $line, ?string $forwarderCode, ?string $cellCode): string {
    $line = trim($line);
    if ($line === '') {
        return '';
    }

    // убираем служебные префиксы в начале
    $line = preg_replace(
        '/^(to|an|von|from|empf[aäå]nger(?:in)?|addressee|receiver|contact|billing\s+no)\s*:?\s*/iu',
        '',
        $line
    );

    $patterns = [];

    // если знаем код форвардера – специфичные варианты
    if ($forwarderCode) {
        $patterns[] = '/\b' . preg_quote($forwarderCode, '/') . '\b[:\-]*/iu';
        switch ($forwarderCode) {
            case 'COLIBRI':
                $patterns[] = '/\bCOLIBRI\s+EXPRESS\b[:\-]*/iu';
                $patterns[] = '/\bCOLIBRIEXPRESS\b[:\-]*/iu';
                $patterns[] = '/\bCOLIBRI\s+EXP\b[:\-]*/iu';
                break;
            case 'KOLLI':
                $patterns[] = '/\bKOLI\s*EXPRESS\b[:\-]*/iu';
                $patterns[] = '/\bKOLIEXPRESS\b[:\-]*/iu';
                $patterns[] = '/\bKOLIEXP\b[:\-]*/iu';
                break;
            case 'ASER':
                $patterns[] = '/\bASER\s+EXPRESS\b[:\-]*/iu';
                break;
            case 'CAMEX':
                $patterns[] = '/\bCAMEX\s+EXPRESS\b[:\-]*/iu';
                $patterns[] = '/\bCAMEX\s+LLC\b[:\-]*/iu';
                break;
            case 'KARGOFLEX':
                $patterns[] = '/\bKARGOFLEX\b[:\-]*/iu';
                break;
            case 'CAMARATC':
                $patterns[] = '/\bCAMARATC\b[:\-]*/iu';
                break;
        }
    }

    // общие брендовые/служебные слова – режем всегда
    $patterns = array_merge($patterns, [
        '/\bTLS\s+CARGO\b[:\-]*/iu',
        '/\bASER\p{L}*\b[:\-]*/iu',
        '/\bCOLIBR\p{L}*\s+EXP\p{L}*\b[:\-]*/iu',
        '/\bCOLIBR\p{L}*\b[:\-]*/iu',
        '/\bCOLIBRIEXPRESS\b[:\-]*/iu',
        '/\bKOLI\p{L}*\s*EXP\p{L}*\b[:\-]*/iu',
        '/\bKOLIEXPRESS\b[:\-]*/iu',
        '/\bKOLI\p{L}*\b[:\-]*/iu',
        '/\bCAMEX\p{L}*\b[:\-]*/iu',
        '/\bKARGO?FLEX\p{L}*\b[:\-]*/iu',
        '/\bCAMARATC\p{L}*\b[:\-]*/iu',
        '/\bE\p{L}{0,2}PRESS\p{L}*\b[:\-]*/iu',
        '/\bEXP\b[:\-]*/iu',
        '/\bCARGO\b[:\-]*/iu',
        '/\bSHIP\b[:\-]*/iu',
    ]);

   // специальные мусорные префиксы
   $line = preg_replace('/^T\s+Jo:\s*/iu', '', $line);                // "T Jo: Khatira ..."
   $line = preg_replace('/^[A-ZÄÖÜ]{2,4}\s*-\s+/u', ' ', $line);      // "BRI - SACLI AZIZOVA" → "SACLI AZIZOVA"

    foreach ($patterns as $p) {
        $line = preg_replace($p, ' ', $line);
    }

    // убираем код ячейки, если есть
    if ($cellCode) {
        $line = preg_replace('/\b' . preg_quote($cellCode, '/') . '\b/iu', ' ', $line);
    }

    // выкидываем ведущие "коды" вида AZCAMEXA200841 / A76227 и т.п.
    $line = preg_replace('/^\s*\S*\d+\S*\s+/u', ' ', $line);

    // хвосты-организации
    $line = preg_replace(
        '/\b(exp|cargo|llc|gmbh|gimbh|shop|online|spa|hub)\b\.?$/iu',
        '',
        $line
    );

    // финальная чистка
    $line = preg_replace('/\s*[-–—]+\s*/u', ' ', $line); // убираем одиночные дефисы между словами
    $line = trim($line, " \t-,:.;/");
    $line = preg_replace('/\s{2,}/u', ' ', $line);

    // если строка повторяет одно и то же имя через разделители – оставляем единственный вариант
    $splitParts = array_filter(
        array_map('trim', preg_split('/\s*[-–—,:;\/]+\s*/u', $line)),
        'strlen'
    );
    if (count($splitParts) > 1) {
        $lowerUnique = array_unique(array_map(fn($p) => mb_strtolower($p, 'UTF-8'), $splitParts));
        if (count($lowerUnique) === 1) {
            $line = $splitParts[0];
        }
    }

    return $line;
}


function detect_client_name(string $text, ?string $forwarderCode, ?string $cellCode): ?string {
    $lines = preg_split("/\n/u", $text);
    $lines = array_map('trim', $lines);

    $forwarderAliases = [
        'COLIBRI'   => ['colibri express', 'colibriexpress', 'colibri exp', 'colibri'],
        'KOLLI'     => ['koliexpress', 'koli express', 'koli-express', 'koliexp', 'koli'],
        'ASER'      => ['aser express', 'aser exp', 'aser'],
        'CAMEX'     => ['camex', 'camex llc', 'camex express'],
        'KARGOFLEX' => ['kargoflex'],
        'CAMARATC'  => ['camaratc'],
        'POSTLINK'  => ['postlink'],
    ];

    // 1) сначала пытаемся около строки с форвардером
    if ($forwarderCode && isset($forwarderAliases[$forwarderCode])) {
        foreach ($lines as $i => $line) {
            if ($line === '') continue;
            $low = mb_strtolower($line, 'UTF-8');

            foreach ($forwarderAliases[$forwarderCode] as $alias) {
                if (mb_strpos($low, $alias) !== false) {

                    // сначала пробуем текущую строку
                    $name = clean_name_line($line, $forwarderCode, $cellCode);
                    if (looks_like_person_name($name)) {
                        return $name;
                    }

                    // затем следующую строку (часто там только ФИО)
                    if (isset($lines[$i + 1])) {
                        $candidate = clean_name_line($lines[$i + 1], $forwarderCode, $cellCode);
                        if (looks_like_person_name($candidate)) {
                            return $candidate;
                        }
                    }
                }
            }
        }
    }

    // 2) fallback: любая строка, похожая на имя
    foreach ($lines as $line) {
        if ($line === '') continue;
        $candidate = clean_name_line($line, $forwarderCode, $cellCode);
        if (looks_like_person_name($candidate)) {
            return $candidate;
        }
    }

    return null;
}


// --------------------------------------------------
// 5) Трек (НЕ обязателен)
//    Просто пробуем вытащить что-то длиной >= 12 символов (цифры/буквы)
// --------------------------------------------------

function detect_tracking_no(string $text): ?string {
    $lines = preg_split("/\n/u", $text);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }

        // требуем хотя бы одну цифру внутри кандидата
        if (preg_match('/\b(?=[0-9A-Z]*\d)[0-9A-Z]{12,}\b/u', $line, $m)) {
            return $m[0];
        }
    }
    return null;
}

function detect_forwarder_by_cell_code(string $cell): ?string {
    $cell = strtoupper(trim($cell));

    // Колли (Kolli / KoliExpress)
    if (preg_match('/^(KL|KI)\d+$/', $cell)) {
        return 'KOLLI';
    }

    // Colibri: Cxxxxx, иногда Sxx / OPxx / Bxxxx – можешь добавить по мере надобности
    if (preg_match('/^C\d+$/', $cell) || preg_match('/^S\d+$/', $cell) || preg_match('/^OP\d+$/', $cell)) {
        return 'COLIBRI';
    }

    // ASER: AS123456
    if (preg_match('/^AS\d+$/', $cell)) {
        return 'ASER';
    }

    // Postlink: PL123456
    if (preg_match('/^PL\d+$/', $cell)) {
        return 'POSTLINK';
    }

    // CAMEX: A123456
    if (preg_match('/^A\d+$/', $cell)) {
        return 'CAMEX';
    }

    // KargoFlex: FX123456
    if (preg_match('/^FX\d+$/', $cell)) {
        return 'KARGOFLEX';
    }

    // Camaratc (KG/TBS): Bxxxxx, Kxxxxx и т.п. – подстроишь под свои реальные коды
    if (preg_match('/^B\d+$/', $cell) || preg_match('/^K\d+$/', $cell)) {
        return 'CAMARATC';
    }

    return null;
}



function detect_local_carrier_name(string $text): ?string {
    $t = mb_strtolower($text, 'UTF-8');

//    if (preg_match('/\bhermes\b/u', $t)) return 'HERMES';
//    if (preg_match('/\bgls\b/u', $t)) return 'GLS';
//    if (preg_match('/\bups\b/u', $t) || preg_match('/\b1z[0-9a-z]{16}\b/iu', $text)) return 'UPS';
//    if (preg_match('/\bazamazon\b/u', $t) || preg_match('/\btba\d{10,}\b/iu', $text)) return 'AMAZON';
//    if (preg_match('/\bdhl\b/u', $t) || mb_strpos($t, 'deutsche post') !== false) return 'DHL';
    if (preg_match('/\bh[\W_]*e[\W_]*r[\W_]*m[\W_]*e[\W_]*s\b/u', $t)) return 'HERMES';
    if (preg_match('/\bg[\W_]*l[\W_]*s\b/u', $t)) return 'GLS';
    if (preg_match('/\bu[\W_]*p[\W_]*s\b/u', $t) || preg_match('/\b1z[0-9a-z]{16}\b/iu', $text)) return 'UPS';
    if (preg_match('/\ba[\W_]*m[\W_]*a[\W_]*z[\W_]*o[\W_]*n\b/u', $t) || preg_match('/\btba\d{10,}\b/iu', $text)) return 'AMAZON';
    if (preg_match('/\bd[\W_]*h[\W_]*l\b/u', $t) || mb_strpos($t, 'deutsche post') !== false) return 'DHL';

    return null;
}

function detect_local_tracking_no(string $text, ?string $carrier): ?string {
    $up = strtoupper(str_replace(["\r","\n"], " ", $text));

    // UPS
    if (preg_match('/\b1Z[0-9A-Z]{16}\b/', $up, $m)) return $m[0];

    // Amazon
    if (preg_match('/\bTBA\d{10,}\b/', $up, $m)) return $m[0];

    // digits-only кандидаты
    if (!preg_match_all('/\b\d{11,20}\b/', $up, $mm)) return null;
    $cands = $mm[0];

    $best = null;
    $bestScore = -1;

    foreach ($cands as $v) {
        $score = strlen($v);

        if ($carrier === 'GLS' && strlen($v) === 11) $score += 50;
        if ($carrier === 'HERMES' && (strlen($v) === 14 || strlen($v) === 15 || strlen($v) === 16)) $score += 50;
        if ($carrier === 'DHL' && strlen($v) >= 12) $score += 20;

        if ($score > $bestScore) {
            $bestScore = $score;
            $best = $v;
        }
    }

    return $best;
}
// --------------------------------------------------
// 6) Запуск детекторов
// --------------------------------------------------

$dest     = detect_dest_country_and_forwarder($text, $DEST_CONFIG);
$cellCode = detect_cell_code($text);
$tracking = detect_tracking_no($text);
$localCarrier  = detect_local_carrier_name($text);
$localTracking = detect_local_tracking_no($text, $localCarrier);

// --- KOLLI всегда AZB ---
// форвардеры, которые считаем "только AZB"
$forceAzbForwarders = ['KOLLI', 'CAMEX', 'COLIBRI', 'POSTLINK', 'ASER', 'KARGOFLEX'];

if (in_array($dest['forwarderCode'] ?? null, $forceAzbForwarders, true)) {
    $dest['destCode']    = 'AZB';
    $dest['countryName'] = 'Azerbaijan';
}

// базовые значения из конфигурации
$forwarderCode = $dest['forwarderCode'] ?? null;
$forwarderName = $dest['forwarderName'] ?? null;
$countryCode   = $dest['destCode']      ?? null;
$countryName   = $dest['countryName']   ?? null;

// если форвардер не найден по алиасам — пробуем вычислить по коду ячейки
if (!$forwarderCode && $cellCode !== null) {
    $codeByCell = detect_forwarder_by_cell_code($cellCode);
    if ($codeByCell !== null) {
        $forwarderCode = $codeByCell;

        // маппинг: код форвардера → название компании
        $forwarderCompanies = [
            'COLIBRI'   => 'Colibri Express',
            'KOLLI'     => 'KoliExpress',
            'ASER'      => 'ASER Express',
            'CAMEX'     => 'Camex',
            'KARGOFLEX' => 'KargoFlex',
            'CAMARATC'  => 'Camaratc',
            'POSTLINK'  => 'Postlink',
        ];
        if (isset($forwarderCompanies[$forwarderCode])) {
            $forwarderName = $forwarderCompanies[$forwarderCode];
        }

        // если страна ещё не определена — берём дефолт по форвардеру
        if (!$countryCode) {
            $forwarderCountries = [
                'COLIBRI'   => 'AZB',
                'KOLLI'     => 'AZB',
                'ASER'      => 'AZB',
                'CAMEX'     => 'AZB',
                'KARGOFLEX' => 'AZB',
                'POSTLINK'  => 'AZB',
                'CAMARATC'  => 'KG',
            ];
            $countryNames = [
                'AZB' => 'Azerbaijan',
                'KG'  => 'Kyrgyzstan',
            ];

            if (isset($forwarderCountries[$forwarderCode])) {
                $countryCode = $forwarderCountries[$forwarderCode];
                if (isset($countryNames[$countryCode])) {
                    $countryName = $countryNames[$countryCode];
                }
            }
        }
    }
}



// --- Жесткие подсказки по адресу Starkenburgstr.* ---
$textLower = mb_strtolower($text, 'UTF-8');

$addressHints = [
    [
        'patterns'       => ['starkenburgstr.10c', 'starkenburgstr 10c', 'starkenburgstr10c'],
        'forwarderCode'  => 'COLIBRI',
        'forwarderName'  => 'Colibri Express',
        'countryCode'    => 'AZB',
    ],
    [
        'patterns'       => ['starkenburgstr.10h', 'starkenburgstr 10h', 'starkenburgstr10h'],
        'forwarderCode'  => 'POSTLINK',
        'forwarderName'  => 'Postlink',
        'countryCode'    => 'AZB',
    ],
    [
        'patterns'       => ['starkenburgstr.10a', 'starkenburgstr 10a', 'starkenburgstr10a'],
        'forwarderCode'  => 'CAMEX',
        'forwarderName'  => 'Camex',
        'countryCode'    => 'AZB',
    ],
    [
        'patterns'       => ['starkenburgstr.10k', 'starkenburgstr 10k', 'starkenburgstr10k'],
        'forwarderCode'  => 'KOLLI',
        'forwarderName'  => 'KoliExpress',
        'countryCode'    => 'AZB',
    ],
    [
        'patterns'       => ['starkenburgstr.10g', 'starkenburgstr 10g', 'starkenburgstr10g'],
        'forwarderCode'  => 'ASER',
        'forwarderName'  => 'ASER Express',
        'countryCode'    => 'AZB',
    ],
    [
        'patterns'       => ['starkenburgstr.10t', 'starkenburgstr 10t', 'starkenburgstr10t'],
        'forwarderCode'  => 'KARGOFLEX',
        'forwarderName'  => 'KargoFlex',
        'countryCode'    => 'AZB',
    ],
    [
        'patterns'       => ['starkenburgstr.10b', 'starkenburgstr 10b', 'starkenburgstr10b'],
        'forwarderCode'  => 'CAMARATC',
        'forwarderName'  => 'Camaratc LLC',
        'countryCode'    => 'TBS',
    ],
    [
        'patterns'       => ['starkenburgstr.10e', 'starkenburgstr 10e', 'starkenburgstr10e'],
        'forwarderCode'  => 'CAMARATC',
        'forwarderName'  => 'Camaratc',
        'countryCode'    => 'KG',
    ],
];

$countryNames = [
    'AZB' => 'Azerbaijan',
    'KG'  => 'Kyrgyzstan',
    'TBS' => 'Georgia',
];

foreach ($addressHints as $hint) {
    foreach ($hint['patterns'] as $pattern) {
        if (mb_strpos($textLower, $pattern) !== false) {
            $forwarderCode = $hint['forwarderCode'];
            $forwarderName = $hint['forwarderName'];
            $countryCode   = $hint['countryCode'];
            $countryName   = $countryNames[$countryCode] ?? $countryName;
            break 2;
        }
    }
}

// если форвардер входит в строгий AZB-список — фиксируем страну
if (in_array($forwarderCode, $forceAzbForwarders, true)) {
    $countryCode = 'AZB';
    $countryName = 'Azerbaijan';
}

// --- Camaratc: KG vs TBS по адресу Starkenburgstr.10B/10E ---
$textLower = mb_strtolower($text, 'UTF-8');

if ($forwarderCode === 'CAMARATC' || mb_strpos($textLower, 'camaratc') !== false) {
    $hasB = (
        mb_strpos($textLower, 'starkenburgstr.10b') !== false ||
        mb_strpos($textLower, 'starkenburgstr 10b') !== false ||
        mb_strpos($textLower, 'starkenburgstr10b') !== false
    );

    $hasE = (
        mb_strpos($textLower, 'starkenburgstr.10e') !== false ||
        mb_strpos($textLower, 'starkenburgstr 10e') !== false ||
        mb_strpos($textLower, 'starkenburgstr10e') !== false
    );

    if ($hasB && !$hasE) {
        // TBS / Georgia
        $countryCode = 'TBS';
        $countryName = 'Georgia';
    } elseif ($hasE && !$hasB) {
        // KG / Kyrgyzstan
        $countryCode = 'KG';
        $countryName = 'Kyrgyzstan';
    }
    // если оба или ни одного – оставляем то, что уже выбрал общий детектор
}

// имя получателя – с учётом форвардера и кода ячейки
$client = detect_client_name($text, $forwarderCode, $cellCode);

$data = [
    'tracking_no'             => $tracking,
    'receiver_country_code'   => $countryCode,
    'receiver_country_name'   => $countryName,
    'receiver_forwarder_code' => $forwarderCode,
    'receiver_company'        => $forwarderName,
    'receiver_cell_code'      => $cellCode,
    'receiver_name'           => $client,
    'local_carrier_name'      => $localCarrier,
    'local_tracking_no'       => $localTracking,
];

echo json_encode(
    [
        'status' => 'ok',
        'data'   => $data,
    ],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);
