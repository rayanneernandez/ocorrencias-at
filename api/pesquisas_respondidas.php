<?php
session_start();
if (!isset($_SESSION['usuario_id'])) {
  header("Location: login_cadastro.php");
  exit;
}
require_once __DIR__ . '/../includes/db.php';
$pdo = get_pdo();

$userId = intval($_SESSION['usuario_id']);
$valCols = $pdo->query("SHOW COLUMNS FROM usuarios_validacoes")->fetchAll(PDO::FETCH_ASSOC);
$valCols = array_map(fn($r)=>$r['Field'], $valCols);

$rows = [];
if (in_array('idUsuario',$valCols) && in_array('idPesquisa',$valCols)) {
  $stmt = $pdo->prepare("
    SELECT p.sid, p.titulo, p.descricao, p.id
      FROM usuarios_validacoes uv
      JOIN pesquisa p ON p.id = uv.idPesquisa
     WHERE uv.idUsuario = ?
     ORDER BY uv.id DESC
  ");
  $stmt->execute([$userId]);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8" />
  <title>Pesquisas Respondidas - RADCI</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-white min-h-screen">
  <header class="bg-green-700 text-white">
    <div class="container mx-auto px-6 py-4 flex items-center justify-between">
      <h1 class="text-xl font-bold">RADCI</h1>
      <a href="dashboard.php" class="underline">Voltar Tela Inicial</a>
    </div>
  </header>

  <main class="container mx-auto px-6 py-8 max-w-5xl">
    <h2 class="text-2xl font-bold text-gray-900 mb-4">Pesquisas Respondidas</h2>
    <?php if (empty($rows)): ?>
      <div class="rounded-lg border border-gray-200 p-4 text-gray-600">Você ainda não respondeu nenhuma pesquisa.</div>
    <?php else: ?>
      <div class="grid md:grid-cols-2 gap-4">
        <?php foreach ($rows as $r): ?>
          <article class="rounded-lg border border-gray-200 p-4">
            <div class="font-semibold text-gray-900"><?= htmlspecialchars($r['titulo'] ?? 'Pesquisa') ?></div>
            <?php if (!empty($r['descricao'])): ?>
              <div class="text-gray-600 text-sm mt-1"><?= htmlspecialchars($r['descricao']) ?></div>
            <?php endif; ?>
            <div class="text-gray-500 text-xs mt-2">Resposta registrada — edição bloqueada</div>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </main>
  <?php include __DIR__ . '/../includes/mobile_nav.php'; ?>
</body>
</html>