<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$lat = isset($_GET['lat']) ? $_GET['lat'] : null;
$lon = isset($_GET['lon']) ? $_GET['lon'] : null;
if (!$lat || !$lon) {
    http_response_code(400);
    echo json_encode(['error' => 'missing lat/lon']);
    exit;
}

$url = 'https://nominatim.openstreetmap.org/reverse?format=jsonv2&addressdetails=1&zoom=20&lat=' . urlencode($lat) . '&lon=' . urlencode($lon);

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