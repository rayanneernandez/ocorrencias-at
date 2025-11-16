<?php
session_start();
require_once __DIR__ . '/../includes/db.php';

if (!isset($_SESSION['usuario_id']) || intval($_SESSION['usuario_perfil'] ?? 0) !== 10) {
    header("Location: index.php");
    exit;
}

$pdo = get_pdo();

$totalUsuarios = 0;
$totalOcorrencias = 0;
$ultimosUsuarios = [];
$ultimasOcorrencias = [];

try { $totalUsuarios = (int)$pdo->query("SELECT COUNT(*) FROM usuarios")->fetchColumn(); } catch (Throwable $e) {}
try { $totalOcorrencias = (int)$pdo->query("SELECT COUNT(*) FROM ocorrencias")->fetchColumn(); } catch (Throwable $e) {}

// contabiliza pesquisas recebidas
$totalPesquisas = 0;
try {
  if ($pdo->query("SHOW TABLES LIKE 'pesquisa'")->rowCount() > 0) {
    $totalPesquisas = (int)$pdo->query("SELECT COUNT(*) FROM pesquisa")->fetchColumn();
  }
} catch (Throwable $e) { $totalPesquisas = 0; }

// NOVO: contagem de aprovações pendentes (alinhado com usuários.php)
$aprovacoesPendentes = 0;
try {
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS admin_publico_validacoes (
      id INT AUTO_INCREMENT PRIMARY KEY,
      usuario_id INT NOT NULL,
      tipo_documento VARCHAR(50) NOT NULL,
      descricao_outros VARCHAR(255) NULL,
      fonte_url VARCHAR(255) NULL,
      arquivos_json TEXT NOT NULL,
      status VARCHAR(20) NOT NULL DEFAULT 'pendente',
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      INDEX (usuario_id)
    )
  ");
  $aprovacoesPendentes = (int)$pdo->query(
    "SELECT COUNT(*) FROM admin_publico_validacoes WHERE status = 'pendente'"
  )->fetchColumn();
} catch (Throwable $e) {}

try {
  $ultimosUsuarios = $pdo->query("
    SELECT id, nome, email, perfil, DATE_FORMAT(created_at, '%d/%m/%Y %H:%i') AS criado_em
    FROM usuarios
    ORDER BY id DESC
    LIMIT 10
  ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

try {
  $ultimasOcorrencias = $pdo->query("
    SELECT numero, tipo, status, DATE_FORMAT(data_criacao, '%d/%m/%Y %H:%i') AS criada_em
    FROM ocorrencias
    ORDER BY data_criacao DESC
    LIMIT 10
  ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8" />
  <title>Admin - Início | RADCI</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-white min-h-screen">
  <header class="bg-green-700 text-white">
    <div class="container mx-auto px-6 py-4 flex items-center justify-between relative">
      <img src="/radci/assets/images/logo.png" alt="RADCI" class="h-8 w-auto" />
      <nav class="hidden md:flex items-center gap-6">
        <a href="admin_inicio.php" class="hover:underline font-semibold">Início</a>
        <a href="usuarios.php" class="hover:underline">Usuários</a>
        <a href="ocorrencias_admin.php" class="hover:underline">Ocorrências</a>
        <a href="relatorios_admin.php" class="hover:underline">Relatórios</a>
        <a href="criar_pesquisa.php" class="hover:underline">Criar Pesquisa</a>
        <a href="pesquisa_respostas_admin.php" class="hover:underline">Respostas</a>
        <a href="login_cadastro.php?logout=1" class="hover:underline">Sair</a>
      </nav>
      <button type="button" id="mobileMenuBtn" class="md:hidden inline-flex items-center gap-2 px-3 py-2 rounded-md bg-green-600 hover:bg-green-700">
        <span class="sr-only">Abrir menu</span>
        <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h14M3 10h14M3 14h14"/></svg>
      </button>
      <div id="mobileMenu" class="absolute right-6 top-14 md:hidden hidden bg-white text-gray-800 rounded-lg shadow-lg border w-56">
        <a href="admin_inicio.php" class="block px-4 py-2 hover:bg-gray-100">Início</a>
        <a href="usuarios.php" class="block px-4 py-2 hover:bg-gray-100">Usuários</a>
        <a href="ocorrencias_admin.php" class="block px-4 py-2 hover:bg-gray-100">Ocorrências</a>
        <a href="relatorios_admin.php" class="block px-4 py-2 hover:bg-gray-100">Relatórios</a>
        <a href="criar_pesquisa.php" class="block px-4 py-2 hover:bg-gray-100">Criar Pesquisa</a>
        <a href="pesquisa_respostas_admin.php" class="block px-4 py-2 hover:bg-gray-100">Respostas</a>
        <a href="login_cadastro.php?logout=1" class="block px-4 py-2 hover:bg-gray-100">Sair</a>
      </div>
    </div>
  </header>
  <script>
    document.addEventListener('DOMContentLoaded', () => {
      const btn = document.getElementById('mobileMenuBtn');
      const menu = document.getElementById('mobileMenu');
      if (btn && menu) {
        btn.addEventListener('click', () => menu.classList.toggle('hidden'));
        document.addEventListener('click', (e) => {
          if (!menu.contains(e.target) && !btn.contains(e.target)) menu.classList.add('hidden');
        });
      }
    });
  </script>

  <main class="mx-auto w-full max-w-[1400px] px-6 py-6">
    <div class="grid md:grid-cols-4 gap-6 mb-8">
      <div class="bg-white rounded-xl shadow p-6">
        <p class="text-gray-500">Usuários</p>
        <p class="text-3xl font-semibold text-green-700"><?= number_format($totalUsuarios) ?></p>
      </div>
      <div class="bg-white rounded-xl shadow p-6">
        <p class="text-gray-500">Ocorrências</p>
        <p class="text-3xl font-semibold text-green-700"><?= number_format($totalOcorrencias) ?></p>
      </div>
      <div class="bg-white rounded-xl shadow p-6">
        <p class="text-gray-500">Pesquisas</p>
        <p class="text-3xl font-semibold text-green-700"><?= number_format($totalPesquisas) ?></p>
      </div>
      <div class="bg-white rounded-xl shadow p-6">
        <p class="text-gray-500">Aprovações Pendentes</p>
        <p class="text-3xl font-semibold text-green-700"><?= number_format($aprovacoesPendentes) ?></p>
      </div>
    </div>

    <div class="grid md:grid-cols-2 gap-6">
      <div class="bg-white rounded-xl shadow p-6">
        <h2 class="text-xl font-bold text-gray-900 mb-4">Últimos Usuários</h2>
        <div class="space-y-2">
          <?php foreach ($ultimosUsuarios as $u): ?>
            <div class="flex items-center justify-between border-b py-2">
              <div>
                <div class="font-medium text-gray-800"><?= htmlspecialchars($u['nome'] ?? '') ?></div>
                <div class="text-sm text-gray-500"><?= htmlspecialchars($u['email'] ?? '') ?></div>
              </div>
              <div class="text-sm text-gray-600"><?= htmlspecialchars($u['criado_em'] ?? '') ?></div>
            </div>
          <?php endforeach; ?>
          <?php if (empty($ultimosUsuarios)): ?>
            <div class="text-gray-500">Sem registros.</div>
          <?php endif; ?>
        </div>
      </div>

      <div class="bg-white rounded-xl shadow p-6">
        <h2 class="text-xl font-bold text-gray-900 mb-4">Últimas Ocorrências</h2>
        <div class="space-y-2">
          <?php foreach ($ultimasOcorrencias as $o): ?>
            <div class="flex items-center justify-between border-b py-2">
              <div>
                <div class="font-medium text-gray-800">#<?= htmlspecialchars($o['numero'] ?? '') ?> — <?= htmlspecialchars($o['tipo'] ?? '') ?></div>
                <div class="text-sm text-gray-500">Status: <?= htmlspecialchars($o['status'] ?? '') ?></div>
              </div>
              <div class="text-sm text-gray-600"><?= htmlspecialchars($o['criada_em'] ?? '') ?></div>
            </div>
          <?php endforeach; ?>
          <?php if (empty($ultimasOcorrencias)): ?>
            <div class="text-gray-500">Sem registros.</div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </main>
</body>
</html>