<?php
session_start();
require_once __DIR__ . '/../includes/db.php';

if (!isset($_SESSION['usuario_id']) || intval($_SESSION['usuario_perfil'] ?? 0) !== 10) {
    header("Location: index.php");
    exit;
}

$pdo = get_pdo();

// Coletas seguras com fallback
$totalUsuarios = 0;
$totalOcorrencias = 0;
$totalPesquisas = 0;
$totalRespostas = 0;
$ultimosUsuarios = [];
$ultimasOcorrencias = [];

try { $totalUsuarios = (int)$pdo->query("SELECT COUNT(*) FROM usuarios")->fetchColumn(); } catch (Throwable $e) {}
try { $totalOcorrencias = (int)$pdo->query("SELECT COUNT(*) FROM ocorrencias")->fetchColumn(); } catch (Throwable $e) {}
try { $totalPesquisas = (int)$pdo->query("SELECT COUNT(*) FROM pesquisa")->fetchColumn(); } catch (Throwable $e) {}
try { $totalRespostas = (int)$pdo->query("SELECT COUNT(*) FROM usuarios_validacoes")->fetchColumn(); } catch (Throwable $e) {}

try {
    $ultimosUsuarios = $pdo->query("
        SELECT id, COALESCE(nome, name) AS nome, email, perfil
        FROM usuarios
        ORDER BY id DESC
        LIMIT 25
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

try {
    $ultimasOcorrencias = $pdo->query("
        SELECT numero, tipo, status, DATE_FORMAT(data_criacao, '%d/%m/%Y %H:%i') AS criada_em
        FROM ocorrencias
        ORDER BY data_criacao DESC
        LIMIT 25
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

function perfilNome($p) {
    $map = [1=>'Cidadão', 2=>'Prefeito', 3=>'Secretário', 10=>'Admin'];
    return $map[intval($p)] ?? 'Desconhecido';
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8" />
  <title>Relatórios (Admin) - RADCI</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-white min-h-screen">
  <header class="bg-green-700 text-white">
    <div class="container mx-auto px-6 py-4 flex items-center justify-between">
      <h1 class="text-xl font-bold">RADCI — Admin</h1>
      <nav class="space-x-6">
        <a href="usuarios.php" class="hover:underline">Usuários</a>
        <a href="relatorios_admin.php" class="hover:underline font-semibold">Relatórios</a>
        <a href="criar_pesquisa.php" class="hover:underline">Criar Pesquisa</a>
        <a href="principal.php" class="hover:underline">Sair</a>
      </nav>
    </div>
  </header>

  <main class="container mx-auto px-6 py-8 max-w-6xl">
    <h2 class="text-2xl font-bold text-gray-900 mb-6">Resumo</h2>
    <div class="grid md:grid-cols-4 gap-6 mb-8">
      <div class="bg-white rounded-xl shadow p-6"><p class="text-gray-500">Usuários</p><p class="text-3xl font-semibold text-green-700"><?= $totalUsuarios ?></p></div>
      <div class="bg-white rounded-xl shadow p-6"><p class="text-gray-500">Ocorrências</p><p class="text-3xl font-semibold text-green-700"><?= $totalOcorrencias ?></p></div>
      <div class="bg-white rounded-xl shadow p-6"><p class="text-gray-500">Pesquisas</p><p class="text-3xl font-semibold text-green-700"><?= $totalPesquisas ?></p></div>
      <div class="bg-white rounded-xl shadow p-6"><p class="text-gray-500">Respostas</p><p class="text-3xl font-semibold text-green-700"><?= $totalRespostas ?></p></div>
    </div>

    <h3 class="text-xl font-semibold text-gray-900 mb-3">Últimos Usuários</h3>
    <div class="bg-white rounded-xl shadow overflow-hidden mb-8">
      <table class="min-w-full text-left">
        <thead class="bg-gray-50 text-gray-700">
          <tr><th class="px-4 py-2 w-20">ID</th><th class="px-4 py-2">Nome</th><th class="px-4 py-2">Email</th><th class="px-4 py-2 w-32">Perfil</th></tr>
        </thead>
        <tbody class="text-gray-800">
          <?php foreach ($ultimosUsuarios as $u): ?>
            <tr class="border-t">
              <td class="px-4 py-2"><?= htmlspecialchars($u['id'] ?? '') ?></td>
              <td class="px-4 py-2"><?= htmlspecialchars($u['nome'] ?? '') ?></td>
              <td class="px-4 py-2"><?= htmlspecialchars($u['email'] ?? '') ?></td>
              <td class="px-4 py-2"><?= perfilNome($u['perfil'] ?? 0) ?></td>
            </tr>
          <?php endforeach; if (empty($ultimosUsuarios)): ?>
            <tr><td colspan="4" class="px-4 py-3 text-gray-500">Sem dados</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <h3 class="text-xl font-semibold text-gray-900 mb-3">Últimas Ocorrências</h3>
    <div class="bg-white rounded-xl shadow overflow-hidden mb-8">
      <table class="min-w-full text-left">
        <thead class="bg-gray-50 text-gray-700">
          <tr><th class="px-4 py-2 w-32">Número</th><th class="px-4 py-2">Tipo</th><th class="px-4 py-2 w-40">Status</th><th class="px-4 py-2 w-48">Criada em</th></tr>
        </thead>
        <tbody class="text-gray-800">
          <?php foreach ($ultimasOcorrencias as $o): ?>
            <tr class="border-t">
              <td class="px-4 py-2"><?= htmlspecialchars($o['numero'] ?? '') ?></td>
              <td class="px-4 py-2"><?= htmlspecialchars($o['tipo'] ?? '') ?></td>
              <td class="px-4 py-2"><?= htmlspecialchars($o['status'] ?? '') ?></td>
              <td class="px-4 py-2"><?= htmlspecialchars($o['criada_em'] ?? '') ?></td>
            </tr>
          <?php endforeach; if (empty($ultimasOcorrencias)): ?>
            <tr><td colspan="4" class="px-4 py-3 text-gray-500">Sem dados</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="grid md:grid-cols-2 gap-6">
      <a href="relatorios_admin.php?download=pesquisas" class="inline-flex items-center gap-2 px-5 py-3 rounded-md bg-green-600 text-white hover:bg-green-700">Baixar CSV de Pesquisas</a>
      <a href="relatorios_admin.php?download=prioridades" class="inline-flex items-center gap-2 px-5 py-3 rounded-md bg-green-600 text-white hover:bg-green-700">Baixar CSV de Prioridades</a>
    </div>
  </main>
</body>
</html>