<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['usuario_id'])) {
  echo json_encode(['ok' => false, 'error' => 'not_logged_in']);
  exit;
}

require_once __DIR__ . '/../includes/db.php';
$pdo = get_pdo();
$usuarioId = intval($_SESSION['usuario_id']);

try {
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS usuarios_preferencias (
      usuario_id INT PRIMARY KEY,
      notif_ocorrencias TINYINT(1) NOT NULL DEFAULT 1,
      notif_novidades  TINYINT(1) NOT NULL DEFAULT 1,
      updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )
  ");
  $oc = isset($_POST['notif_ocorrencias']) ? intval($_POST['notif_ocorrencias']) : 0;
  $nv = isset($_POST['notif_novidades'])  ? intval($_POST['notif_novidades'])  : 0;

  // Upsert
  $stmt = $pdo->prepare("
    INSERT INTO usuarios_preferencias (usuario_id, notif_ocorrencias, notif_novidades)
    VALUES (?, ?, ?)
    ON DUPLICATE KEY UPDATE
      notif_ocorrencias = VALUES(notif_ocorrencias),
      notif_novidades  = VALUES(notif_novidades)
  ");
  $stmt->execute([$usuarioId, $oc, $nv]);

  echo json_encode(['ok' => true]);
} catch (Throwable $e) {
  echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}