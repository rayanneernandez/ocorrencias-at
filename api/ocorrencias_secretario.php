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
        $whereSql = "oa.secretario_id = ?";
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
        SELECT o.numero, o.tipo, o.status, o.descricao, o.latitude, o.longitude, o.tem_imagens, o.arquivos, o.data_criacao
          FROM ocorrencias o
          {$joinSql}
         WHERE {$whereSql}
         ORDER BY o.data_criacao DESC
         LIMIT 100
    ");
    $stmtList->execute($params);
    $ocorrencias = $stmtList->fetchAll(PDO::FETCH_ASSOC);

    $stmtMarkers = $pdo->prepare("
        SELECT o.numero, o.tipo, o.status, o.descricao, o.latitude, o.longitude, o.tem_imagens, o.arquivos
          FROM ocorrencias o
          {$joinSql}
         WHERE {$whereSql} AND o.latitude IS NOT NULL AND o.longitude IS NOT NULL
         ORDER BY o.data_criacao DESC
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
    <div class="container mx-auto px-6 py-4 flex items-center justify-between">
      <h1 class="text-xl font-bold">RADCI</h1>
      <nav class="space-x-6">
        <a href="secretario.php" class="hover:underline">Início</a>
        <a href="ocorrencias_secretario.php" class="hover:underline font-semibold">Ocorrências</a>
        <a href="relatorios.php" class="hover:underline">Relatórios</a>
        <a href="login_cadastro.php?logout=1" class="hover:underline">Sair</a>
      </nav>
    </div>
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
          const html = `
            <div style="min-width: 220px">
              <div><strong>Nº:</strong> ${m.numero}</div>
              <div><strong>Tipo:</strong> ${m.tipo}</div>
              <div><strong>Status:</strong> ${m.status}</div>
              <form method="POST" action="ocorrencias_secretario.php" style="margin-top:8px">
                <input type="hidden" name="action" value="update_status" />
                <input type="hidden" name="numero" value="${m.numero}" />
                <div class="flex gap-6">
                  <button name="status" value="Resolvido" class="px-3 py-1 rounded-md bg-green-600 text-white">Resolvido</button>
                  <button name="status" value="Não Resolvido" class="px-3 py-1 rounded-md bg-red-600 text-white">Não Resolvido</button>
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
  </main>
</body>
</html>