<?php

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


$jsonOcrDicts = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$smarty->assign('jsonOcrDicts', $jsonOcrDicts);

