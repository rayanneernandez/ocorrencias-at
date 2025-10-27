<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

function http_get($url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_USERAGENT => 'RADCI/1.0 (localhost)',
        CURLOPT_HTTPHEADER => ['Accept-Language: pt-BR,pt;q=0.9']
    ]);
    $resp = curl_exec($ch);
    if ($resp === false) {
        http_response_code(500);
        echo json_encode(['error' => 'failed', 'detail' => curl_error($ch)]);
        curl_close($ch);
        exit;
    }
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code >= 400) {
        http_response_code($code);
        echo json_encode(['error' => 'http_'.$code]);
        exit;
    }
    echo $resp;
}

$params = [
    'format' => 'json',
    'addressdetails' => '1',
    'limit' => isset($_GET['limit']) ? $_GET['limit'] : '1',
    'countrycodes' => 'br',
    'dedupe' => '1'
];

if (isset($_GET['q']) && $_GET['q'] !== '') {
    $params['q'] = $_GET['q'];
} else {
    // campos estruturados
    if (!empty($_GET['street'])) $params['street'] = $_GET['street'];
    if (!empty($_GET['city'])) $params['city'] = $_GET['city'];
    if (!empty($_GET['state'])) $params['state'] = $_GET['state'];
    if (!empty($_GET['country'])) $params['country'] = $_GET['country']; else $params['country'] = 'Brasil';
    if (!empty($_GET['postalcode'])) $params['postalcode'] = $_GET['postalcode'];
}

// permite limitar pelo perímetro (cidade/UF) quando disponível
if (!empty($_GET['viewbox'])) $params['viewbox'] = $_GET['viewbox'];
if (!empty($_GET['bounded'])) $params['bounded'] = $_GET['bounded'];

$url = 'https://nominatim.openstreetmap.org/search?' . http_build_query($params);
http_get($url);