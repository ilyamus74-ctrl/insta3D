<?php

$carriers = [];
        $sql = "
            SELECT carrier_code, carrier_name, template_json
            FROM ocr_carrier_templates
            WHERE active = 1
            ORDER BY priority ASC
        ";
        $stmt = $dbcnx->prepare($sql);
        $stmt->execute();
//print_r($stmt);

       $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {

    $code = $row['carrier_code'];
    $tmpl = json_decode($row['template_json'], true);

    if (!is_array($tmpl)) {
        $tmpl = [];
    }

    if (empty($tmpl['display_name'])) {
        $tmpl['display_name'] = $row['carrier_name'];
    }

    // гарантируем, что rules хотя бы объект
    if (!isset($tmpl['rules']) || !is_array($tmpl['rules'])) {
        $tmpl['rules'] = [];
    }

    if (!isset($carriers[$code])) {
        $carriers[$code] = [];
    }

    $carriers[$code][] = [
        'display_name' => $tmpl['display_name'],
        'rules'        => $tmpl['rules'],
    ];
/*        $items[] = $row;
    $tmpl = json_decode($row['template_json'], true);

    if (!is_array($tmpl)) { $tmpl = []; }
    // гарантируем display_name
    if (empty($tmpl['display_name'])) {  $tmpl['display_name'] = $row['carrier_name'];  }
    $carriers[$row['carrier_code']] = $tmpl;
*/
    }
    
    
$ocrTemplates = [
    'version'  => 1,
    'carriers' => $carriers,
];

$jsonOcrTemplates = json_encode($ocrTemplates, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$smarty->assign('jsonOcrTemplates', $jsonOcrTemplates);


    $result = [];

    $sql = "SELECT id, code_iso2, code_iso3, name_en, name_local, aliases, forwarders_json
              FROM dest_countries
             WHERE is_active = 1";
    if ($res = $dbcnx->query($sql)) {
        while ($row = $res->fetch_assoc()) {
            $aliases = [];

            if (!empty($row['aliases'])) {
                // делим по строкам
                $lines = preg_split('/\r\n|\r|\n/', $row['aliases']);
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line !== '') {
                        $aliases[] = $line;
                    }
                }
            }

    $forwarders = [];
    if (!empty($row['forwarders_json'])) {
        $tmp = json_decode($row['forwarders_json'], true);
        if (is_array($tmp)) {
            $forwarders = $tmp;
        }
    }
            $result[] = [
                'id'         => (int)$row['id'],
                'code_iso2'  => $row['code_iso2'],
                'code_iso3'  => $row['code_iso3'],
                'name_en'    => $row['name_en'],
                'name_local' => $row['name_local'],
                'aliases'    => $aliases,
                'forwarders' => $forwarders,   // НОВОЕ ПОЛЕ
            ];
        }
        $res->free();
    }
$jsonDestCountry = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$smarty->assign('jsonDestCountry', $jsonDestCountry);
