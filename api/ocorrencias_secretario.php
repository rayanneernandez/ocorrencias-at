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
$secretarioId = intval($_SESSION['usuario_id']);

// Define filtro de atribuição (secretário vê somente o que foi direcionado a ele)
$joinSql = '';
$whereSql = '';
$params   = [$secretarioId];

try {
    $hasSecIdCol    = $pdo->query("SHOW COLUMNS FROM ocorrencias LIKE 'secretario_id'")->rowCount() > 0;
    $hasAssignedCol = !$hasSecIdCol && $pdo->query("SHOW COLUMNS FROM ocorrencias LIKE 'assigned_secretario_id'")->rowCount() > 0;
    $hasMapTable    = $pdo->query("SHOW TABLES LIKE 'ocorrencias_atribuicoes'")->rowCount() > 0;

    if ($hasSecIdCol) {
        $whereSql = "o.secretario_id = ?";
    } elseif ($hasAssignedCol) {
        $whereSql = "o.assigned_secretario_id = ?";
    } elseif ($hasMapTable) {
        $joinSql  = "JOIN ocorrencias_atribuicoes oa ON oa.ocorrencia_id = o.id";
        // Se houver colunas de responsável direto, ignorar mapeamentos quando já houver responsável definido
        $extraGuard = '';
        if ($pdo->query("SHOW COLUMNS FROM ocorrencias LIKE 'secretario_id'")->rowCount() > 0) {
            $extraGuard .= " AND (o.secretario_id IS NULL OR o.secretario_id = 0)";
        }
        if ($pdo->query("SHOW COLUMNS FROM ocorrencias LIKE 'assigned_secretario_id'")->rowCount() > 0) {
            $extraGuard .= " AND (o.assigned_secretario_id IS NULL OR o.assigned_secretario_id = 0)";
        }
        $whereSql = "oa.secretario_id = ?" . $extraGuard;
    } else {
        // Fallback: até existir atribuição no banco, mostra as registradas pelo secretário
        $whereSql = "o.usuario_id = ?";
    }
} catch (Throwable $_) {
    $whereSql = "o.usuario_id = ?";
}

// Drivers fixos (12)
$drivers = [
    'Educação','Energias Inteligentes','Infraestrutura da Cidade','Inovação','Meio Ambiente',
    'Mobilidade','Planejamento Urbano','Políticas Públicas','Riscos Urbanos','Saúde','Segurança Pública','Sustentabilidade'
];

// Agregação por tipo (apenas atribuídas)
$totalOcorrencias = 0;
$contagemPorDriver = array_fill_keys($drivers, 0);
try {
    $stmtCnt = $pdo->prepare("SELECT COUNT(*) FROM ocorrencias o {$joinSql} WHERE {$whereSql}");
    $stmtCnt->execute($params);
    $totalOcorrencias = (int)$stmtCnt->fetchColumn();

    $stmtAgg = $pdo->prepare("SELECT o.tipo, COUNT(*) AS cnt FROM ocorrencias o {$joinSql} WHERE {$whereSql} GROUP BY o.tipo");
    $stmtAgg->execute($params);
    $rows = $stmtAgg->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $tipo = trim($r['tipo'] ?? '');
        $cnt  = (int)$r['cnt'];
        foreach ($drivers as $d) {
            if (mb_strtolower($tipo) === mb_strtolower($d)) {
                $contagemPorDriver[$d] += $cnt;
                break;
            }
        }
    }
} catch (Throwable $e) {}

// Lista e mapa (apenas atribuídas, com lat/lon)
$ocorrencias = [];
$markers     = [];
try {
    $stmtList = $pdo->prepare("
        SELECT o.numero, o.tipo, o.status, o.descricao, o.endereco, o.cep, o.latitude, o.longitude, o.tem_imagens, o.arquivos, o.data_criacao
          FROM ocorrencias o
          {$joinSql}
         WHERE {$whereSql}
         ORDER BY (o.status = 'em_analise') DESC, o.data_criacao DESC
         LIMIT 100
    ");
    $stmtList->execute($params);
    $ocorrencias = $stmtList->fetchAll(PDO::FETCH_ASSOC);

    $stmtMarkers = $pdo->prepare("
        SELECT o.numero, o.tipo, o.status, o.descricao, o.latitude, o.longitude, o.tem_imagens, o.arquivos
          FROM ocorrencias o
          {$joinSql}
         WHERE {$whereSql} AND o.latitude IS NOT NULL AND o.longitude IS NOT NULL
         ORDER BY (o.status = 'em_analise') DESC, o.data_criacao DESC
         LIMIT 500
    ");
    $stmtMarkers->execute($params);
    $markers = $stmtMarkers->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// Opções de local (UF - Município - Bairro) com base nas atribuídas
$localOptionsKeyed = ["RJ|Rio de Janeiro|Todos" => "RJ - Rio de Janeiro - Todos"];
try {
    $stmtCeps = $pdo->prepare("
        SELECT DISTINCT o.cep
          FROM ocorrencias o
          {$joinSql}
         WHERE {$whereSql} AND o.cep IS NOT NULL AND o.cep <> ''
         ORDER BY o.cep ASC
         LIMIT 200
    ");
    $stmtCeps->execute($params);
    $ceps = $stmtCeps->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ceps as $cep) {
        $digits = preg_replace('/\D/', '', $cep);
        if (strlen($digits) !== 8) continue;
        $url = "https://viacep.com.br/ws/{$digits}/json/";
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_USERAGENT => 'RADCI/1.0 (ocorrencias_secretario.php)'
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);
        if ($resp) {
            $data = json_decode($resp, true);
            if (is_array($data) && empty($data['erro'])) {
                $uf   = trim($data['uf'] ?? '');
                $cid  = trim($data['localidade'] ?? '');
                $bai  = trim($data['bairro'] ?? '');
                if ($uf && $cid) {
                    $key = "{$uf}|{$cid}|" . ($bai ?: 'Todos');
                    $localOptionsKeyed[$key] = "{$uf} - {$cid} - " . ($bai ?: 'Todos');
                }
            }
        }
    }
} catch (Throwable $_) {}

?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="UTF-8" />
  <title>Ocorrências (Secretário) - RADCI</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="tailwind.css" />
  <style>
    .chart-fixed { width: 600px; height: 320px; }
  </style>
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="bg-white min-h-screen">
  <header class="bg-green-700 text-white">
    <div class="container mx-auto px-6 py-4 flex items-center justify-between relative">
      <img src="/radci/assets/images/logo.png" alt="RADCI" class="h-8 w-auto" />
      <nav class="hidden md:flex items-center gap-6">
        <a href="secretario.php" class="hover:underline">Início</a>
        <a href="ocorrencias_secretario.php" class="hover:underline font-semibold">Ocorrências</a>
        <a href="relatorios.php" class="hover:underline">Relatórios</a>
        <a href="login_cadastro.php?logout=1" class="hover:underline">Sair</a>
      </nav>
      <button type="button" id="mobileMenuBtn" class="md:hidden inline-flex items-center gap-2 px-3 py-2 rounded-md bg-green-600 hover:bg-green-700">
        <span class="sr-only">Abrir menu</span>
        <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h14M3 10h14M3 14h14"/></svg>
      </button>
      <div id="mobileMenu" class="absolute right-6 top-14 md:hidden hidden bg-white text-gray-800 rounded-lg shadow-lg border w-56">
        <a href="secretario.php" class="block px-4 py-2 hover:bg-gray-100">Início</a>
        <a href="ocorrencias_secretario.php" class="block px-4 py-2 hover:bg-gray-100">Ocorrências</a>
        <a href="relatorios.php" class="block px-4 py-2 hover:bg-gray-100">Relatórios</a>
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
    <h1 class="text-xl font-bold">RADCI</h1>
    <nav class="space-x-6">
      <a href="secretario.php" class="hover:underline">Início</a>
      <a href="ocorrencias_secretario.php" class="hover:underline font-semibold">Ocorrências</a>
      <a href="relatorios.php" class="hover:underline">Relatórios</a>
      <a href="login_cadastro.php?logout=1" class="hover:underline">Sair</a>
    </nav>
  </header>

  <main class="container mx-auto px-6 py-8 max-w-6xl">
    <?php if (!empty($_SESSION['flash_success'])): ?>
      <div class="mb-4 bg-green-100 border border-green-300 text-green-800 px-4 py-3 rounded"><?= htmlspecialchars($_SESSION['flash_success']) ?></div>
      <?php unset($_SESSION['flash_success']); endif; ?>
    <?php if (!empty($_SESSION['flash_error'])): ?>
      <div class="mb-4 bg-red-100 border border-red-300 text-red-800 px-4 py-3 rounded"><?= htmlspecialchars($_SESSION['flash_error']) ?></div>
      <?php unset($_SESSION['flash_error']); endif; ?>

    <!-- 1) Lista de drivers com percentuais -->
    <section class="mb-8">
      <div class="flex items-center justify-between mb-4">
        <h2 class="text-2xl font-bold text-gray-900">Ocorrências atribuídas</h2>
        <select id="localSelect" class="border rounded-md px-3 py-2">
          <?php foreach ($localOptionsKeyed as $k => $label): ?>
            <option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="grid md:grid-cols-3 gap-4">
        <?php foreach ($drivers as $d): 
            $cnt = $contagemPorDriver[$d] ?? 0;
            $pct = $totalOcorrencias > 0 ? round(($cnt / $totalOcorrencias) * 100, 1) : 0;
        ?>
          <div class="border rounded-xl p-4">
            <div class="flex items-center justify-between">
              <span class="font-semibold text-gray-800"><?= htmlspecialchars($d) ?></span>
              <span class="text-gray-600"><?= number_format($pct, 1) ?>%</span>
            </div>
            <div class="mt-2 text-sm text-gray-600">Total: <?= number_format($cnt) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    </section>

    <!-- 2) Mapa e status -->
    <section class="mb-10">
      <div id="map" style="height: 420px;" class="rounded-xl border"></div>
      <script>
        const map = L.map('map').setView([-22.9068, -43.1729], 11);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
          maxZoom: 19,
          attribution: '&copy; OpenStreetMap'
        }).addTo(map);

        const markers = <?= json_encode($markers) ?>;
        markers.forEach(m => {
          if (!m.latitude || !m.longitude) return;
          const marker = L.marker([parseFloat(m.latitude), parseFloat(m.longitude)]).addTo(map);

          const statusRaw = (m.status || '').toLowerCase();
          const isResolved = ['resolvida','concluida','concluída','cancelada'].includes(statusRaw);
          const buttonsHtml = isResolved
            ? `
                <button name="status" value="resolvida" class="px-3 py-1 rounded-md bg-green-600 text-white">Resolvida</button>
              `
            : `
                <button name="status" value="resolvida" class="px-3 py-1 rounded-md bg-green-600 text-white">Resolvida</button>
                <button name="status" value="em_analise" class="px-3 py-1 rounded-md bg-red-600 text-white">Em análise</button>
              `;

          const html = `
            <div style="min-width: 220px">
              <div><strong>Nº:</strong> ${m.numero}</div>
              <div><strong>Tipo:</strong> ${m.tipo}</div>
              <div><strong>Status:</strong> ${m.status}</div>
              <form method="POST" action="ocorrencias_secretario.php" style="margin-top:8px">
                <input type="hidden" name="action" value="update_status" />
                <input type="hidden" name="numero" value="${m.numero}" />
                <div class="flex gap-6">
                  ${buttonsHtml}
                </div>
              </form>
            </div>`;
          marker.bindPopup(html);
        });
      </script>
    </section>

    <!-- 3) Gráficos -->
    <section class="mb-10 grid md:grid-cols-2 gap-6">
      <div class="border rounded-xl p-4">
        <h3 class="font-semibold mb-2 text-gray-800">Ranking</h3>
        <canvas id="chartRanking" class="chart-fixed"></canvas>
      </div>
      <div class="border rounded-xl p-4">
        <h3 class="font-semibold mb-2 text-gray-800">Enviadas x Benchmark</h3>
        <canvas id="chartBenchmark" class="chart-fixed"></canvas>
      </div>
      <script>
        const labels = <?= json_encode($drivers) ?>;
        const counts = <?= json_encode(array_values($contagemPorDriver)) ?>;

        new Chart(document.getElementById('chartRanking').getContext('2d'), {
          type: 'bar',
          data: { labels, datasets: [{ label: 'Ocorrências', data: counts, backgroundColor: '#16a34a' }] },
          options: { responsive: false, maintainAspectRatio: false, animation: false, scales: { y: { beginAtZero: true } } }
        });

        new Chart(document.getElementById('chartBenchmark').getContext('2d'), {
          type: 'bar',
          data: { labels, datasets: [
            { label: 'Enviadas', data: counts, backgroundColor: '#2563eb' },
            { label: 'Benchmark', data: counts.map(v => Math.round(v * 1.2)), backgroundColor: '#64748b' }
          ]},
          options: { responsive: false, maintainAspectRatio: false, animation: false, scales: { y: { beginAtZero: true } } }
        });
      </script>
    </section>

    <!-- 4) Lista detalhada das ocorrências direcionadas -->
    <section class="mb-12">
      <h3 class="text-xl font-semibold text-gray-900 mb-4">Ocorrências direcionadas para você</h3>
      <?php if (empty($ocorrencias)): ?>
        <p class="text-gray-600">Nenhuma ocorrência atribuída.</p>
      <?php else: ?>
      <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-6">
        <?php foreach ($ocorrencias as $row): ?>
          <?php
            // Inicializa variáveis a partir da linha atual
            $numero        = htmlspecialchars($row['numero'] ?? '');
            $tipo          = htmlspecialchars($row['tipo'] ?? '');
            $endereco      = htmlspecialchars($row['endereco'] ?? '');
            $dataFmt       = !empty($row['data_criacao']) ? date('d/m/Y', strtotime($row['data_criacao'])) : '';
            $temImg        = (intval($row['tem_imagens'] ?? 0) ? 'Sim' : 'Não');

            // Usa status “cru” para lógica e uma versão sanitizada para exibir
            $statusRaw     = strtolower(trim($row['status'] ?? ''));
            $statusDisplay = htmlspecialchars($row['status'] ?? '');

            // Define classe do badge conforme status
            $badgeClass = 'bg-gray-100 text-gray-800';
            if ($statusRaw === 'resolvida' || $statusRaw === 'concluida' || $statusRaw === 'concluída') {
              $badgeClass = 'bg-green-100 text-green-800';
            } elseif ($statusRaw === 'encaminhada') {
              $badgeClass = 'bg-blue-100 text-blue-800';
            } elseif (in_array($statusRaw, ['em_analise', 'em analise', 'em analise'], true)) {
              $badgeClass = 'bg-yellow-100 text-yellow-800';
            } elseif ($statusRaw === 'cancelada') {
              $badgeClass = 'bg-red-100 text-red-800';
            }
          ?>
          <article class="border rounded-xl p-4 flex flex-col gap-3">
            <div class="flex items-center justify-between">
              <span class="text-sm text-gray-600"><?= $numero ?></span>
              <span class="text-xs px-2 py-1 rounded-full <?= $badgeClass ?>">
                <?= $statusDisplay ?>
              </span>
            </div>
            <div class="text-lg font-semibold text-gray-900"><?= $tipo ?></div>
            <div class="text-sm text-gray-700"><?= $endereco ?></div>
            <div class="text-sm text-gray-500"><?= $dataFmt ?> · <?= $temImg === 'Sim' ? 'Com imagens' : 'Sem imagens' ?></div>
            <button type="button"
                    class="btn-view-details bg-green-600 text-white px-4 py-2 rounded-md hover:bg-green-700 mt-auto"
                    data-numero="<?= htmlspecialchars($row['numero'] ?? '', ENT_QUOTES) ?>"
                    data-tipo="<?= htmlspecialchars($row['tipo'] ?? '', ENT_QUOTES) ?>"
                    data-status="<?= htmlspecialchars($row['status'] ?? '', ENT_QUOTES) ?>"
                    data-local="<?= htmlspecialchars($row['endereco'] ?? '', ENT_QUOTES) ?>"
                    data-data="<?= htmlspecialchars(($row['data_criacao'] ?? '') ? date('d/m/Y', strtotime($row['data_criacao'])) : '', ENT_QUOTES) ?>"
                    data-tem-imagens="<?= (intval($row['tem_imagens'] ?? 0) ? 'Sim' : 'Não') ?>"
                    data-descricao="<?= htmlspecialchars($row['descricao'] ?? '', ENT_QUOTES) ?>"
                    data-arquivos="<?= htmlspecialchars(is_string($row['arquivos'] ?? '') ? ($row['arquivos'] ?? '[]') : json_encode($row['arquivos'] ?? []), ENT_QUOTES) ?>">
                  Ver detalhes
                </button>
          </article>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </section>

    <!-- Modal de detalhes (fora do foreach) -->
    <!-- Modal de detalhes -->
    <div id="detailsModal" class="fixed inset-0 bg-black/60 hidden z-50 items-center justify-center p-4">
        <div class="bg-white rounded-2xl w-full max-w-4xl shadow-2xl overflow-y-auto overflow-x-hidden">
            <div class="px-6 pt-4 flex items-center justify-between border-b">
                <div>
                    <h2 id="detTitulo" class="text-lg font-semibold text-gray-900">Ocorrência</h2>
                    <div class="flex items-center gap-3 mt-1">
                        <span id="detNumero" class="text-sm text-gray-500">—</span>
                        <span id="detStatus" class="text-xs px-2 py-1 rounded-full bg-gray-100 text-gray-800">—</span>
                    </div>
                </div>
                <button id="detClose" class="px-3 py-1 rounded-md bg-gray-100 hover:bg-gray-200">✕</button>
            </div>
        
            <!-- GRID 2 colunas: esquerda (dados + descrição), direita (evidências) -->
            <div class="px-6 py-4 grid md:grid-cols-2 gap-6">
                <div class="space-y-3">
                    <div><span class="text-gray-600">Categoria</span><div class="font-medium text-gray-900" id="detCategoria">—</div></div>
                    <div><span class="text-gray-600">Local</span><div class="font-medium text-gray-900" id="detLocal">—</div></div>
                    <div><span class="text-gray-600">Data</span><div class="font-medium text-gray-900" id="detData">—</div></div>
                    <div><span class="text-gray-600">Tem Imagens</span><div class="font-medium text-gray-900" id="detTemImagens">—</div></div>
            
                    <!-- Descrição ocupa a coluna esquerda inteira -->
                    <div class="mt-4">
                        <span class="text-gray-600">Descrição</span>
                        <div id="detDescricao"
                             class="mt-1 text-gray-900 break-words whitespace-pre-wrap"
                             style="overflow-wrap:anywhere; word-break: break-word;">—</div>
                    </div>
                </div>
        
                <!-- Evidências na coluna direita -->
                <div>
                    <span class="text-gray-600">Evidências</span>
                    <div class="mt-2">
                        <!-- Imagem principal limitada, clicável -->
                        <img id="detMainImg" class="w-full max-h-[50vh] object-contain rounded-lg cursor-pointer hidden" alt="Evidência principal" />
                        <!-- Miniaturas com rolagem quando necessário -->
                        <div id="detThumbs" class="mt-2 grid grid-cols-3 gap-2 max-h-[50vh] overflow-y-auto"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Visualizador de evidências (overlay) -->
    <div id="evidenceViewer" class="fixed inset-0 bg-black/80 hidden items-center justify-center p-4" style="z-index: 9999;">
        <button id="evPrev" class="absolute left-6 top-1/2 -translate-y-1/2 text-white text-3xl">‹</button>
        <img id="evImg" class="max-w-[70vw] max-h-[65vh] object-contain rounded-lg shadow-lg" alt="Evidência" />
        <button id="evNext" class="absolute right-6 top-1/2 -translate-y-1/2 text-white text-3xl">›</button>
        <button id="evClose" class="absolute top-4 right-4 text-white text-2xl">✕</button>
    </div>

    <script>
        // Helpers de status
        function getBadgeClass(statusRaw) {
            const st = (statusRaw || '').toLowerCase();
            if (st === 'resolvida' || st === 'concluida' || st === 'concluída') return 'bg-green-100 text-green-800';
            if (st === 'encaminhada') return 'bg-blue-100 text-blue-800';
            if (['em_analise','em análise','em analise'].includes(st)) return 'bg-yellow-100 text-yellow-800';
            if (st === 'cancelada') return 'bg-red-100 text-red-800';
            return 'bg-gray-100 text-gray-800';
        }
    
        // Modal de detalhes
        const detailsModal = document.getElementById('detailsModal');
        const detClose     = document.getElementById('detClose');
        const detNumero    = document.getElementById('detNumero');
        const detStatus    = document.getElementById('detStatus');
        const detCategoria = document.getElementById('detCategoria');
        const detLocal     = document.getElementById('detLocal');
        const detData      = document.getElementById('detData');
        const detTemImagens= document.getElementById('detTemImagens');
        const detDescricao = document.getElementById('detDescricao');
        const detThumbs    = document.getElementById('detThumbs');
        const detMainImg   = document.getElementById('detMainImg');
    
        // Decodifica entidades HTML (ex.: &quot;, &#039;)
        function decodeEntities(str) {
            const t = document.createElement('textarea');
            t.innerHTML = String(str ?? '');
            return t.value;
        }
        
        // Helper robusto para ler atributos data-*
        function readData(el, name, fallback = '—') {
            // nome: 'data-numero', 'data-tipo', etc.
            const raw = el.getAttribute(name);
            if (raw == null || raw === '') return fallback;
            const val = decodeEntities(raw).trim();
            return val === '' ? fallback : val;
        }
        
        function openDetails(el) {
            // Lê dados do botão
            const numero     = readData(el, 'data-numero', '—');
            const tipo       = readData(el, 'data-tipo', '—');
            const status     = readData(el, 'data-status', '—');
            const local      = readData(el, 'data-local', '—');
            const data       = readData(el, 'data-data', '—');
            const temImagens = readData(el, 'data-tem-imagens', 'Não');
            const descricao  = readData(el, 'data-descricao', '—');
        
            // Preenche o modal imediatamente
            detNumero.textContent     = numero;
            detStatus.textContent     = status;
            detStatus.className       = 'text-xs px-2 py-1 rounded-full ' + getBadgeClass(status);
            detCategoria.textContent  = tipo;
            detLocal.textContent      = local;
            detData.textContent       = data;
            detTemImagens.textContent = temImagens;
            detDescricao.textContent  = descricao;
        
            // Processa arquivos/imagens (sem travar o modal se houver erro)
            try {
                const arquivosStr = readData(el, 'data-arquivos', '[]');
                let files = [];
                try {
                    files = JSON.parse(arquivosStr);
                } catch (_) {
                    try {
                        files = JSON.parse(
                            arquivosStr.replace(/&quot;/g, '"').replace(/&#039;/g, "'")
                        );
                    } catch (_) {
                        files = arquivosStr.split(/[|,;]\s*/).filter(Boolean);
                    }
                }
        
                const isImg = (p) => (/\.(png|jpe?g|gif|webp|bmp)$/i).test(p || '');
                const baseFromApi = window.location.pathname.includes('/api/');
                const makeUrl = (p) => {
                    if (!p) return '';
                    if (p.startsWith('http')) return p;
                    if (p.startsWith('/radci/uploads/') || p.startsWith('/uploads/')) return p;
                    if (p.startsWith('uploads/')) return (baseFromApi ? '../' : '') + p;
                    if (p.startsWith('../uploads/')) return p;
                    const base = baseFromApi ? '../uploads/' : 'uploads/';
                    return base + p.replace(/^\.?\/?uploads\/?/i, '');
                };
        
                const imgs = (Array.isArray(files) ? files : [])
                    .map(f => typeof f === 'string' ? f : (f?.path || f?.url || ''))
                    .filter(Boolean)
                    .filter(isImg);
        
                detThumbs.innerHTML = '';
                if (imgs.length === 0) {
                    detThumbs.innerHTML = '<div class="text-gray-500">Sem imagens</div>';
                } else {
                    const urls = imgs.map(makeUrl);
                    // Principal (se existir área de imagem principal)
                    if (typeof detMainImg !== 'undefined' && detMainImg) {
                        detMainImg.src = urls[0];
                        detMainImg.classList.remove('hidden');
                        detMainImg.onclick = () => openViewer(urls, 0);
                    }
                    // Thumbs
                    urls.forEach((url, idx) => {
                        const a = document.createElement('a');
                        a.href = 'javascript:void(0)';
                        a.innerHTML = '<img src="' + url + '" class="w-full h-32 object-cover rounded-md hover:opacity-90" alt="Evidência ' + (idx + 1) + '" />';
                        a.addEventListener('click', () => openViewer(urls, idx));
                        detThumbs.appendChild(a);
                    });
                }
            } catch (_) {
                // Ignora erros de imagens; modal já foi preenchido
            }
        
            // Abre modal
            detailsModal.classList.remove('hidden');
            detailsModal.classList.add('flex');
            document.body.style.overflow = 'hidden';
        }
        
        // Registro robusto dos eventos de clique
        window.addEventListener('DOMContentLoaded', () => {
            const bindButtons = () => {
                document.querySelectorAll('.btn-view-details').forEach((btn) => {
                    if (btn.dataset.bound === '1') return;
                    btn.dataset.bound = '1';
                    btn.addEventListener('click', (e) => {
                        e.preventDefault();
                        e.stopPropagation();
                        openDetails(btn);
                    });
                });
            };
            bindButtons();
        });
        
        // Delegação de eventos (fallback para elementos dinâmicos)
        document.addEventListener('click', (e) => {
            const btn = e.target.closest('.btn-view-details');
            if (btn) {
                e.preventDefault();
                openDetails(btn);
            }
        });
    
        function closeDetails() {
            detailsModal.classList.add('hidden');
            detailsModal.classList.remove('flex');
            document.body.style.overflow = '';
        }
    
        detClose.addEventListener('click', closeDetails);
        detailsModal.addEventListener('click', (e) => {
            // Fecha ao clicar fora do conteúdo
            if (e.target === detailsModal) closeDetails();
        });
    
        // Liga os botões "Ver detalhes" quando o DOM estiver carregado
        window.addEventListener('DOMContentLoaded', () => {
            document.querySelectorAll('.btn-view-details').forEach((btn) => {
                btn.addEventListener('click', () => openDetails(btn));
            });
        });
    
        // Delegação de eventos (fallback caso os botões sejam renderizados dinamicamente)
        document.addEventListener('click', (e) => {
            const btn = e.target.closest('.btn-view-details');
            if (btn) {
                e.preventDefault();
                openDetails(btn);
            }
        });
    
        // Visualizador de evidências
        const evidenceViewer = document.getElementById('evidenceViewer');
        const evImg   = document.getElementById('evImg');
        const evPrev  = document.getElementById('evPrev');
        const evNext  = document.getElementById('evNext');
        const evClose = document.getElementById('evClose');
        let evList = [];
        let evIndex = 0;
    
        function showEv() {
            evImg.src = evList[evIndex];
        }
    
        function openViewer(list, startIdx = 0) {
            evList = Array.isArray(list) ? list : [];
            evIndex = Math.min(Math.max(startIdx, 0), evList.length - 1);
            if (evList.length === 0) return;
            showEv();
            evidenceViewer.style.zIndex = '9999';
            evidenceViewer.classList.remove('hidden');
            evidenceViewer.classList.add('flex');
            document.body.style.overflow = 'hidden';
        }
    
        function closeViewer() {
            evidenceViewer.classList.add('hidden');
            evidenceViewer.classList.remove('flex');
            document.body.style.overflow = '';
        }
    
        evPrev.addEventListener('click', () => {
            evIndex = (evIndex - 1 + evList.length) % evList.length;
            showEv();
        });
        evNext.addEventListener('click', () => {
            evIndex = (evIndex + 1) % evList.length;
            showEv();
        });
        evClose.addEventListener('click', closeViewer);
        evidenceViewer.addEventListener('click', (e) => {
            if (e.target === evidenceViewer) closeViewer();
        });
    </script>

