<?php
session_start();
require_once __DIR__ . '/notificacoes.php';

$usuarioId = intval($_SESSION['usuario_id'] ?? 0);
header('Content-Type: application/json; charset=utf-8');

if ($usuarioId <= 0) {
    http_response_code(401);
    echo json_encode(['error' => 'Usuário não autenticado']);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true) ?: [];
$notifId = intval($data['notificacao_id'] ?? 0);

if ($notifId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Dados inválidos']);
    exit;
}

try {
    $manager = new NotificacaoManager();
    $manager->marcarComoLida($notifId, $usuarioId);
    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Falha ao marcar como lida']);
}