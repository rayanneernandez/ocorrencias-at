<?php
session_start();
require_once __DIR__ . '/notificacoes.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Usuário não autenticado']);
    exit;
}

$notificacaoManager = new NotificacaoManager();
$usuarioId = $_SESSION['user_id'];

try {
    $notificacaoManager->limparTodasNotificacoes($usuarioId);
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao limpar notificações']);
}