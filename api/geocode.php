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
    return $resp;
}

// Função para processar o endereço e retornar coordenadas
function geocode_address($address) {
    $params = [
        'format' => 'json',
        'addressdetails' => '1',
        'limit' => '1',
        'countrycodes' => 'br',
        'dedupe' => '1'
    ];

    // Se for um endereço completo, usa como query
    if (isset($address)) {
        $params['q'] = $address . ', Brasil';
    }

    $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query($params);
    $response = http_get($url);
    $data = json_decode($response, true);

    if (empty($data)) {
        http_response_code(404);
        echo json_encode(['error' => 'Endereço não encontrado']);
        exit;
    }

    // Retorna as coordenadas do primeiro resultado
    $result = $data[0];
    echo json_encode([
        floatval($result['lat']),
        floatval($result['lon'])
    ]);
}

// Processa a requisição
if (isset($_GET['address'])) {
    geocode_address($_GET['address']);
} else if (isset($_GET['q'])) {
    geocode_address($_GET['q']);
} else {
    http_response_code(400);
    echo json_encode(['error' => 'Parâmetro address ou q é obrigatório']);
}