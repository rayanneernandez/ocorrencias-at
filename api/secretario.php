<?php
session_start();
require_once __DIR__ . '/../includes/db.php';

// Controle de acesso: apenas Secretário (perfil 3)
$perfil = intval($_SESSION['usuario_perfil'] ?? 0);
if (!isset($_SESSION['usuario_id']) || $perfil !== 3) {
    $_SESSION['flash_error'] = 'Acesso restrito: apenas perfis de Secretário.';
    header('Location: dashboard.php');
    exit;
}

$pdo = get_pdo();

// Dados para o painel
$usuarioId   = intval($_SESSION['usuario_id']);
$usuarioNome = trim($_SESSION['usuario_nome'] ?? 'Secretário');

// Últimas ocorrências (resumo)
$ocorrencias = [];
try {
    $stmt = $pdo->query("SELECT numero, tipo, status, data_criacao FROM ocorrencias ORDER BY data_criacao DESC LIMIT 10");
    $ocorrencias = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $ocorrencias = [];
}

// KPIs simples
$totalOcorrencias = 0;
try {
    $totalOcorrencias = (int)$pdo->query("SELECT COUNT(*) FROM ocorrencias")->fetchColumn();
} catch (Throwable $_) {}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Área do Secretário - RADCI</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 min-h-screen">
  <header class="bg-green-700 text-white">
    <div class="container mx-auto px-6 py-4 flex items-center justify-between">
      <h1 class="text-xl font-bold">RADCI</h1>
      <nav class="space-x-6">
        <a href="dashboard.php" class="hover:underline">Início</a>
        <a href="ocorrencias_secretario.php" class="hover:underline">Ocorrências</a>
        <a href="relatorios.php" class="hover:underline">Relatórios</a>
        <a href="login_cadastro.php?logout=1" class="hover:underline">Sair</a>
      </nav>
    </div>
  </header>

  <main class="container mx-auto px-6 py-8 max-w-6xl">
    <div class="mb-6">
      <h2 class="text-2xl font-bold text-gray-900">Área do Secretário</h2>
      <p class="text-gray-600">Bem-vindo, <?= htmlspecialchars($usuarioNome) ?>. Aqui você encontra um resumo operacional.</p>
    </div>

    <!-- KPIs -->
    <div class="grid md:grid-cols-3 gap-6 mb-8">
      <div class="bg-white rounded-xl shadow p-6">
        <p class="text-sm text-gray-500">Total de Ocorrências</p>
        <p class="text-3xl font-semibold text-green-700"><?= number_format($totalOcorrencias) ?></p>
      </div>
      <div class="bg-white rounded-xl shadow p-6">
        <p class="text-sm text-gray-500">Acesso Rápido</p>
        <div class="mt-3 space-x-3">
          <a href="ocorrencias_secretario.php" class="inline-block bg-green-700 text-white px-4 py-2 rounded-md">Ver Ocorrências</a>
          <a href="relatorios.php" class="inline-block bg-gray-800 text-white px-4 py-2 rounded-md">Ver Relatórios</a>
        </div>
      </div>
      <div class="bg-white rounded-xl shadow p-6">
        <p class="text-sm text-gray-500">Perfil</p>
        <p class="text-lg font-medium">Secretário</p>
      </div>
    </div>

    <!-- Últimas ocorrências -->
    <div class="bg-white rounded-xl shadow overflow-hidden">
      <div class="px-6 py-4 border-b">
        <h3 class="text-lg font-semibold text-gray-900">Últimas Ocorrências</h3>
      </div>
      <div class="p-6">
        <?php if (empty($ocorrencias)): ?>
          <p class="text-gray-600">Nenhuma ocorrência encontrada.</p>
        <?php else: ?>
          <div class="overflow-x-auto">
            <table class="min-w-full text-left">
              <thead class="bg-gray-50">
                <tr>
                  <th class="px-4 py-2 text-gray-700">Número</th>
                  <th class="px-4 py-2 text-gray-700">Tipo</th>
                  <th class="px-4 py-2 text-gray-700">Status</th>
                  <th class="px-4 py-2 text-gray-700">Criada em</th>
                </tr>
              </thead>
              <tbody class="text-gray-800">
                <?php foreach ($ocorrencias as $row): ?>
                  <tr class="border-t">
                    <td class="px-4 py-2"><?= htmlspecialchars($row['numero']) ?></td>
                    <td class="px-4 py-2"><?= htmlspecialchars($row['tipo']) ?></td>
                    <td class="px-4 py-2"><?= htmlspecialchars($row['status']) ?></td>
                    <td class="px-4 py-2"><?= htmlspecialchars($row['data_criacao']) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </main>
</body>
</html>