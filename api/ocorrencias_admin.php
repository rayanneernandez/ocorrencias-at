<?php
session_start();
require_once __DIR__ . '/../includes/db.php';

// Acesso restrito: Admin (perfil 10)
$perfil = intval($_SESSION['usuario_perfil'] ?? 0);
if (!isset($_SESSION['usuario_id']) || $perfil !== 10) {
    $_SESSION['flash_error'] = 'Acesso restrito: apenas perfis de Admin.';
    header('Location: dashboard.php');
    exit;
}

$pdo = get_pdo();

// Paginação: 10 cards por página
$cardsPerPage = 10;
$page = max(1, intval($_GET['page'] ?? 1));
$offset = ($page - 1) * $cardsPerPage;

// Coleta dados
$totalCount = 0;
$cards = [];
try {
  $totalCount = (int)$pdo->query("SELECT COUNT(*) FROM ocorrencias")->fetchColumn();
  $stmt = $pdo->query("
    SELECT id, numero, tipo, status, endereco, descricao, tem_imagens, arquivos,
           DATE_FORMAT(data_criacao, '%d/%m/%Y %H:%i') AS criada_em
      FROM ocorrencias
     ORDER BY (status IN ('em_analise','cancelada')) DESC, data_criacao DESC
     LIMIT {$cardsPerPage} OFFSET {$offset}
  ");
  $cards = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$totalPages = max(1, ceil($totalCount / $cardsPerPage));
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="UTF-8" />
  <title>Ocorrências (Admin) - RADCI</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    .chart-fluid { width: 100%; height: 320px; }
    .desc-wrap { overflow-wrap: anywhere; word-break: break-word; white-space: pre-wrap; }
  </style>
</head>
<body class="bg-white min-h-screen">
  <header class="bg-green-700 text-white">
    <div class="container mx-auto px-6 py-4 flex items-center justify-between relative">
      <img src="/radci/assets/images/logo.png" alt="RADCI" class="h-8 w-auto" />
      <nav class="hidden md:flex items-center gap-6">
        <a href="admin_inicio.php" class="hover:underline">Início</a>
        <a href="usuarios.php" class="hover:underline">Usuários</a>
        <a href="ocorrencias_admin.php" class="hover:underline font-semibold">Ocorrências</a>
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
    <!-- Grid de Cards -->
    <section class="mb-8">
      <h2 class="text-2xl font-bold text-gray-900 mb-4">Ocorrências</h2>
      <div class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-6">
        <?php foreach ($cards as $c): 
          $hasImages = !!intval($c['tem_imagens'] ?? 0);
          $arquivos = [];
          if (!empty($c['arquivos'])) {
            $decoded = json_decode($c['arquivos'], true);
            if (is_array($decoded)) { $arquivos = $decoded; }
          }
        ?>
          <div class="bg-white rounded-xl border p-4 shadow-sm">
            <div class="flex items-center justify-between">
              <div class="text-sm text-gray-600">#<?= htmlspecialchars($c['numero'] ?? '') ?></div>
              <span class="px-2 py-1 rounded text-xs 
                <?= ($c['status'] ?? '') === 'cancelada' ? 'bg-red-100 text-red-700' : (($c['status'] ?? '') === 'em_analise' ? 'bg-yellow-100 text-yellow-700' : 'bg-green-100 text-green-700') ?>">
                <?= htmlspecialchars($c['status'] ?? '') ?>
              </span>
            </div>
            <div class="mt-2 font-semibold text-gray-800"><?= htmlspecialchars($c['tipo'] ?? '') ?></div>
            <div class="mt-1 text-sm text-gray-600"><?= htmlspecialchars($c['endereco'] ?? '') ?></div>
            <div class="mt-1 text-xs text-gray-500"><?= htmlspecialchars($c['criada_em'] ?? '') ?></div>
            <div class="mt-2 text-sm <?= $hasImages ? 'text-green-700' : 'text-gray-500' ?>">
              <?= $hasImages ? 'Com imagens' : 'Sem imagens' ?>
            </div>
            <p class="mt-2 text-sm text-gray-700 desc-wrap"><?= htmlspecialchars($c['descricao'] ?? '') ?></p>
            <button
              class="mt-4 w-full px-3 py-2 rounded bg-green-600 text-white hover:bg-green-700"
              onclick='openDetailsModal(<?= json_encode([
                "numero" => $c["numero"] ?? "",
                "status" => $c["status"] ?? "",
                "tipo" => $c["tipo"] ?? "",
                "endereco" => $c["endereco"] ?? "",
                "descricao" => $c["descricao"] ?? "",
                "arquivos" => $arquivos,
                "tem_imagens" => $hasImages,
                "criada_em" => $c["criada_em"] ?? "",
              ]) ?>)'>
              Ver detalhes
            </button>
          </div>
        <?php endforeach; ?>
      </div>

      <!-- Paginação -->
      <div class="mt-6 flex items-center justify-center gap-2">
        <?php if ($page > 1): ?>
          <a href="?page=<?= $page - 1 ?>" class="px-3 py-2 rounded border text-gray-700 hover:bg-gray-50">Anterior</a>
        <?php endif; ?>
        <span class="px-3 py-2 rounded bg-gray-100 text-gray-700">Página <?= $page ?> de <?= $totalPages ?></span>
        <?php if ($page < $totalPages): ?>
          <a href="?page=<?= $page + 1 ?>" class="px-3 py-2 rounded border text-gray-700 hover:bg-gray-50">Próxima</a>
        <?php endif; ?>
      </div>
    </section>
  </main>

  <!-- Modal de Detalhes (idêntico ao Secretário) -->
  <div id="detailsModal" class="fixed inset-0 bg-black/50 hidden items-center justify-center z-50">
    <div class="bg-white rounded-xl shadow-xl w-full max-w-3xl mx-4">
      <div class="px-6 py-4 border-b flex items-center justify-between">
        <h3 class="text-lg font-semibold">Detalhes da Ocorrência</h3>
        <button class="text-gray-600 hover:text-gray-800" onclick="closeDetailsModal()">Fechar</button>
      </div>
      <div class="p-6 space-y-3">
        <div class="flex items-center justify-between">
          <div class="text-sm text-gray-600" id="detNum"></div>
          <span id="detStatusBadge" class="px-2 py-1 rounded text-xs"></span>
        </div>
        <div class="font-semibold text-gray-800" id="detTipo"></div>
        <div class="text-sm text-gray-600" id="detEndereco"></div>
        <div class="text-xs text-gray-500" id="detCriadaEm"></div>
        <div class="text-sm text-gray-700" id="detHasImgs"></div>
        <div class="text-sm text-gray-700 desc-wrap" id="detDesc"></div>

        <div>
          <div class="font-semibold text-gray-800 mb-2">Evidências</div>
          <div id="detThumbs" class="grid grid-cols-3 gap-2"></div>
          <div id="detViewer" class="mt-4 hidden">
            <div class="relative bg-black rounded-lg overflow-hidden">
              <img id="detImage" class="w-full h-[360px] object-contain bg-black" src="" alt="" />
              <div class="absolute inset-x-0 top-0 flex justify-between p-2">
                <button class="px-3 py-2 bg-white/20 text-white rounded hover:bg-white/30" onclick="prevImage()">Anterior</button>
                <button class="px-3 py-2 bg-white/20 text-white rounded hover:bg-white/30" onclick="nextImage()">Próximo</button>
              </div>
            </div>
            <div id="detCounter" class="text-sm text-gray-600 mt-2 text-right"></div>
          </div>
        </div>
      </div>
      <div class="px-6 py-4 border-t text-right">
        <button class="px-4 py-2 rounded bg-gray-100 hover:bg-gray-200" onclick="closeDetailsModal()">Fechar</button>
      </div>
    </div>
  </div>

  <script>
    let viewerFiles = [];
    let viewerIndex = 0;

    function openDetailsModal(data) {
      document.getElementById('detailsModal').classList.remove('hidden');

      document.getElementById('detNum').textContent = '#' + (data.numero || '');
      const st = (data.status || '').toLowerCase();
      const badge = document.getElementById('detStatusBadge');
      badge.textContent = data.status || '';
      badge.className = 'px-2 py-1 rounded text-xs ' + (
        st === 'cancelada' ? 'bg-red-100 text-red-700' :
        st === 'em_analise' ? 'bg-yellow-100 text-yellow-700' :
        'bg-green-100 text-green-700'
      );

      document.getElementById('detTipo').textContent = data.tipo || '';
      document.getElementById('detEndereco').textContent = data.endereco || '';
      document.getElementById('detCriadaEm').textContent = data.criada_em || '';
      document.getElementById('detHasImgs').textContent = (data.tem_imagens ? 'Com imagens' : 'Sem imagens');
      document.getElementById('detDesc').textContent = data.descricao || '';

      viewerFiles = Array.isArray(data.arquivos) ? data.arquivos.filter(f => !!(f && f.path)) : [];
      viewerIndex = 0;
      const thumbs = document.getElementById('detThumbs');
      thumbs.innerHTML = '';
      const viewer = document.getElementById('detViewer');
      const imgEl = document.getElementById('detImage');
      const counter = document.getElementById('detCounter');

      if (viewerFiles.length > 0) {
        viewer.classList.remove('hidden');
        viewerFiles.forEach((f, i) => {
          const src = '/' + String(f.path || '').replace(/^\//, '');
          const el = document.createElement('img');
          el.src = src;
          el.alt = f.name || ('evidencia_' + (i + 1));
          el.className = 'w-full h-24 object-cover rounded cursor-pointer';
          el.onclick = () => { viewerIndex = i; renderViewer(); };
          thumbs.appendChild(el);
        });
        renderViewer();
      } else {
        viewer.classList.add('hidden');
      }

      function renderViewer() {
        const f = viewerFiles[viewerIndex];
        const src = '/' + String(f.path || '').replace(/^\//, '');
        imgEl.src = src;
        counter.textContent = (viewerIndex + 1) + ' / ' + viewerFiles.length;
      }
      window.prevImage = function() {
        if (viewerFiles.length === 0) return;
        viewerIndex = (viewerIndex - 1 + viewerFiles.length) % viewerFiles.length;
        const ev = new Event('renderViewer');
        renderViewer();
      };
      window.nextImage = function() {
        if (viewerFiles.length === 0) return;
        viewerIndex = (viewerIndex + 1) % viewerFiles.length;
        renderViewer();
      };
    }
    function closeDetailsModal() {
      document.getElementById('detailsModal').classList.add('hidden');
    }
  </script>
</body>
</html>