<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$cep = isset($_GET['cep']) ? preg_replace('/\D+/', '', $_GET['cep']) : null;
$uf = isset($_GET['uf']) ? trim($_GET['uf']) : null;
$cidade = isset($_GET['cidade']) ? trim($_GET['cidade']) : null;
$logradouro = isset($_GET['logradouro']) ? trim($_GET['logradouro']) : null;

if ($cep) {
    $url = "https://viacep.com.br/ws/{$cep}/json/";
} elseif ($uf && $cidade && $logradouro) {
    $url = "https://viacep.com.br/ws/" . rawurlencode($uf) . "/" . rawurlencode($cidade) . "/" . rawurlencode($logradouro) . "/json/";
} else {
    http_response_code(400);
    echo json_encode(['error' => 'Parâmetros inválidos. Use cep OU uf+cidade+logradouro']);
    exit;
}

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_USERAGENT => 'RADCI/1.0 (+localhost)',
]);
$res = curl_exec($ch);
$err = curl_error($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($err || $code >= 400 || !$res) {
    http_response_code(502);
    echo json_encode(['error' => 'Falha ao consultar ViaCEP', 'status' => $code, 'detail' => $err]);
    exit;
}

echo $res;