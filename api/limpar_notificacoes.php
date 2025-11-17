<?php
session_start();
require_once __DIR__ . '/notificacoes.php';

// Garante que está autenticado e usa a mesma chave da sessão do restante do app
$usuarioId = intval($_SESSION['usuario_id'] ?? 0);
if ($usuarioId <= 0) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Usuário não autenticado']);
    exit;
}

$notificacaoManager = new NotificacaoManager();

try {
    $notificacaoManager->limparTodasNotificacoes($usuarioId);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Erro ao limpar notificações']);
}