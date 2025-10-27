<?php
session_start();

if (!isset($_SESSION['usuario_id'])) {
  header("Location: login_cadastro.php");
  exit;
}

require_once __DIR__ . '/../includes/db.php';
$pdo = get_pdo();

$usuarioId = intval($_SESSION['usuario_id']);
$stmt = $pdo->prepare("SELECT id, nome, email FROM usuarios WHERE id = ?");
$stmt->execute([$usuarioId]);
$user = $stmt->fetch();

if (!$user || empty($user['email'])) {
  header("Location: minha_conta.php?pwd_email_sent=0");
  exit;
}

// Cria tabela de reset se não existir
$pdo->exec("
  CREATE TABLE IF NOT EXISTS usuarios_reset_senha (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT NOT NULL,
    token VARCHAR(128) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (usuario_id),
    UNIQUE KEY uniq_token (token)
  )
");

// Gera token e salva
$token = bin2hex(random_bytes(32));
$expires = date('Y-m-d H:i:s', time() + 60 * 30); // 30 minutos

$pdo->prepare("
  INSERT INTO usuarios_reset_senha (usuario_id, token, expires_at)
  VALUES (?, ?, ?)
")->execute([$usuarioId, $token, $expires]);

// Monta e-mail
$assunto = "RADCI - Confirmação para Alteração de Senha";
$link = sprintf('%s/confirmar_reset_senha.php?token=%s',
  (isset($_SERVER['REQUEST_SCHEME']) ? $_SERVER['REQUEST_SCHEME'] : 'http') . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME']),
  $token
);
$mensagem = "Olá, {$user['nome']}\n\nPara confirmar a alteração de sua senha, acesse o link:\n{$link}\n\nEste link expira em 30 minutos.\n\n— RADCI";

$enviado = false;
try {
  // Tenta enviar com mail(); requer configuração no servidor
  $headers = "From: no-reply@radci.com.br\r\n";
  $enviado = @mail($user['email'], $assunto, $mensagem, $headers);
} catch (Throwable $e) {
  $enviado = false;
}

// Redireciona de volta com status
header("Location: minha_conta.php?pwd_email_sent=" . ($enviado ? '1' : '0'));
exit;