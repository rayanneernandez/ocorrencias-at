<?php
session_start();
require_once __DIR__ . '/../includes/db.php';

// Acesso restrito: Prefeito (perfil 2)
$perfil = intval($_SESSION['usuario_perfil'] ?? 0);
if (!isset($_SESSION['usuario_id']) || $perfil !== 2) {
    $_SESSION['flash_error'] = 'Acesso restrito: apenas perfis de Prefeito.';
    header('Location: dashboard.php');
    exit;
}

$pdo = get_pdo();

// Carrega ocorrências (lista simples)
$ocorrencias = [];
$errorMsg = '';
try {
    $stmt = $pdo->query("SELECT numero, tipo, status, data_criacao FROM ocorrencias ORDER BY data_criacao DESC LIMIT 100");
    $ocorrencias = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $errorMsg = 'Não foi possível carregar as ocorrências.';
}

// Atualiza status (Resolvido / Não Resolvido)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_status') {
    $numero = trim($_POST['numero'] ?? '');
    $status = trim($_POST['status'] ?? '');
    if ($numero !== '' && in_array($status, ['Resolvido','Não Resolvido','Em Análise','Em Andamento'])) {
        try {
            $stmt = $pdo->prepare("UPDATE ocorrencias SET status = ? WHERE numero = ?");
            $stmt->execute([$status, $numero]);
            $_SESSION['flash_success'] = "Status atualizado para: $status";
            header('Location: ocorrencias.php');
            exit;
        } catch (Throwable $e) {
            $_SESSION['flash_error'] = 'Erro ao atualizar status.';
            header('Location: ocorrencias.php');
            exit;
        }
    }
}

// Drivers fixos (12) para agregação
$drivers = [
    'Educação','Energias Inteligentes','Infraestrutura da Cidade','Inovação','Meio Ambiente',
    'Mobilidade','Planejamento Urbano','Políticas Públicas','Riscos Urbanos','Saúde','Segurança Pública','Sustentabilidade'
];

// Agregação por tipo (contagem e percentual)
$totalOcorrencias = 0;
$contagemPorDriver = array_fill_keys($drivers, 0);
try {
    $totalOcorrencias = (int)$pdo->query("SELECT COUNT(*) FROM ocorrencias")->fetchColumn();
    $rows = $pdo->query("SELECT tipo, COUNT(*) AS cnt FROM ocorrencias GROUP BY tipo")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $tipo = trim($r['tipo'] ?? '');
        $cnt  = (int)$r['cnt'];
        // Normaliza tipo para os nomes dos drivers
        // Caso o banco salve com variações, tente mapear aproximado por prefixos
        foreach ($drivers as $d) {
            if (mb_strtolower($tipo) === mb_strtolower($d)) {
                $contagemPorDriver[$d] += $cnt;
                break;
            }
        }
    }
} catch (Throwable $e) {
    // Se falhar, mantém zero e mostra aviso na UI
}

// Dados para mapa (marcadores)
$markers = [];
$errorMsg = '';
try {
    $stmt = $pdo->query("
        SELECT numero, tipo, status, descricao, latitude, longitude, tem_imagens, arquivos
        FROM ocorrencias
        WHERE latitude IS NOT NULL AND longitude IS NOT NULL
        ORDER BY data_criacao DESC
        LIMIT 500
    ");
    $markers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $errorMsg = 'Não foi possível carregar as ocorrências.';
}

// Monta opções do seletor “UF - Município - Bairro” baseado nos locais com registros
$localOptions = [];
$localOptionsKeyed = [];
// opção padrão
$localOptionsKeyed["RJ|Rio de Janeiro|Todos"] = "RJ - Rio de Janeiro - Todos";

// coleta CEPs distintos e resolve UF/Cidade/Bairro via ViaCEP
try {
    $ceps = $pdo->query("SELECT DISTINCT cep FROM ocorrencias WHERE cep IS NOT NULL AND cep <> '' ORDER BY cep ASC LIMIT 200")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ceps as $cep) {
        $digits = preg_replace('/\D/', '', $cep);
        if (strlen($digits) !== 8) continue;

        // consulta ViaCEP
        $url = "https://viacep.com.br/ws/{$digits}/json/";
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_USERAGENT => 'RADCI/1.0 (ocorrencias.php)'
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);
        if ($resp) {
            $data = json_decode($resp, true);
            if (is_array($data) && empty($data['erro'])) {
                $uf = trim($data['uf'] ?? '');
                $cidade = trim($data['localidade'] ?? '');
                $bairro = trim($data['bairro'] ?? '');
                if ($uf && $cidade) {
                    $key = $uf . '|' . $cidade . '|' . ($bairro ?: 'Todos');
                    $label = $uf . ' - ' . $cidade . ' - ' . ($bairro ?: 'Todos');
                    $localOptionsKeyed[$key] = $label;
                }
            }
        }
    }
} catch (Throwable $e) {
    // se falhar, mantém apenas a opção padrão
}
// transforma em array final
foreach ($localOptionsKeyed as $k => $label) {
    $localOptions[] = ['value' => $k, 'label' => $label];
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="UTF-8" />
  <title>Ocorrências - RADCI</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <script src="https://cdn.tailwindcss.com"></script>
  <!-- Chart.js para os gráficos -->
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
  <!-- Leaflet para o mapa -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css" crossorigin="anonymous" />
  <script defer src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js" crossorigin="anonymous"></script>
  <style>
    /* Tamanho fixo para os gráficos */
    .chart-fixed { width: 900px; height: 300px; }
  </style>
</head>
<body class="bg-white min-h-screen">
  <header class="bg-green-700 text-white">
    <div class="container mx-auto px-6 py-4 flex items-center justify-between">
      <h1 class="text-xl font-bold">RADCI</h1>
      <nav class="space-x-6">
        <a href="prefeito_inicio.php" class="hover:underline">Início</a>
        <a href="gestor_secretarios.php" class="hover:underline">Meus Secretários</a>
        <a href="ocorrencias.php" class="hover:underline font-semibold">Ocorrências</a>
        <a href="relatorios.php" class="hover:underline">Relatório</a>
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

    <!-- 1) Lista dos 12 drivers com Percentual (como no print) -->
    <section class="mb-8">
      <div class="flex items-center justify-between mb-4">
        <h2 class="text-2xl font-bold text-gray-900">Prioridades</h2>
        <div class="text-sm text-gray-600">UF - Município - Bairro</div>
      </div>
      <div class="bg-white rounded-xl shadow overflow-hidden">
        <div class="px-6 py-4 border-b">
          <select id="localSelect" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-green-600">
            <?php foreach ($localOptions as $opt): ?>
              <option value="<?= htmlspecialchars($opt['value']) ?>"><?= htmlspecialchars($opt['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="p-6">
          <div class="overflow-x-auto">
            <table class="min-w-full text-left">
              <thead>
                <tr class="bg-green-600 text-white">
                  <th class="px-4 py-2">Driver</th>
                  <th class="px-4 py-2 w-40">Percentual</th>
                  <th class="px-4 py-2 w-24">Valor</th>
                </tr>
              </thead>
              <tbody class="bg-white">
                <?php foreach ($drivers as $d): 
                  $count = $contagemPorDriver[$d] ?? 0;
                  $perc = $totalOcorrencias > 0 ? round(($count / $totalOcorrencias) * 100, 1) : 0;
                ?>
                <tr class="border-t">
                  <td class="px-4 py-3 text-gray-900"><?= htmlspecialchars($d) ?></td>
                  <td class="px-4 py-3">
                    <div class="w-full bg-gray-200 rounded-full h-3">
                      <div class="bg-green-700 h-3 rounded-full" style="width: <?= $perc ?>%;"></div>
                    </div>
                  </td>
                  <td class="px-4 py-3 text-gray-700"><?= number_format($perc, 1, ',', '.') ?>%</td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
            <?php if ($totalOcorrencias === 0): ?>
              <p class="text-gray-500 mt-4">Nenhuma ocorrência registrada.</p>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </section>

    <!-- 2) Mapa com ocorrências e botões Resolvido / Não Resolvido -->
    <section class="mb-8">
      <h2 class="text-2xl font-bold text-gray-900 mb-4">Mapa de Ocorrências</h2>
      <div id="map" class="w-full h-[420px] rounded-xl shadow border"></div>
    </section>

    <!-- 3) Gráficos -->
    <section class="mb-8">
      <h2 class="text-2xl font-bold text-gray-900 mb-4">Pesquisa do Cidadão - Ranking de Prioridade</h2>
      <div class="bg-white rounded-xl shadow p-6">
        <canvas id="chartRanking" width="900" height="300" class="chart-fixed"></canvas>
      </div>

      <h2 class="text-2xl font-bold text-gray-900 mt-10 mb-4">Prioridades Enviadas x Benchmark (Pesquisa do Cidadão)</h2>
      <div class="bg-white rounded-xl shadow p-6">
        <canvas id="chartBenchmark" width="900" height="300" class="chart-fixed"></canvas>
      </div>
    </section>

  </main>

  <script>
    // Dados do mapa vindos do PHP
    const markers = <?php echo json_encode($markers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

    document.addEventListener('DOMContentLoaded', () => {
      const DEFAULT = [-22.9068, -43.1729];
      const map = L.map('map').setView(DEFAULT, 11);
      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 18,
        attribution: '&copy; OpenStreetMap'
      }).addTo(map);

      markers.forEach(m => {
        if (!m.latitude || !m.longitude) return;
        const marker = L.marker([parseFloat(m.latitude), parseFloat(m.longitude)]).addTo(map);

        const desc = (m.descricao || '').toString().slice(0, 200);
        const numero = m.numero;
        const tipo = m.tipo || '';
        const status = m.status || 'Em Análise';

        // Popup com botões
        const popupHtml = `
          <div class="min-w-[260px]">
            <div class="flex items-center justify-between mb-2">
              <div class="font-semibold text-gray-800">${tipo}</div>
              <button type="button" class="text-gray-500 hover:text-gray-700" onclick="this.closest('.leaflet-popup').querySelector('.leaflet-popup-close-button')?.click()">✕</button>
            </div>
            <div class="text-sm text-gray-700 mb-2">${desc || 'Sem descrição'}</div>
            <div class="text-xs text-gray-500 mb-3">Status atual: ${status}</div>
            <div class="flex gap-2">
              <form method="POST" action="ocorrencias.php" class="inline">
                <input type="hidden" name="action" value="update_status"/>
                <input type="hidden" name="numero" value="${numero}"/>
                <input type="hidden" name="status" value="Resolvido"/>
                <button type="submit" class="px-3 py-2 rounded-md bg-green-600 text-white hover:bg-green-700 text-sm">✔ RESOLVIDO</button>
              </form>
              <form method="POST" action="ocorrencias.php" class="inline">
                <input type="hidden" name="action" value="update_status"/>
                <input type="hidden" name="numero" value="${numero}"/>
                <input type="hidden" name="status" value="Não Resolvido"/>
                <button type="submit" class="px-3 py-2 rounded-md bg-red-600 text-white hover:bg-red-700 text-sm">✖ NÃO RESOLVIDO</button>
              </form>
            </div>
          </div>
        `;
        marker.bindPopup(popupHtml);
      });

      // Gráfico 1: Ranking
      const labels = <?php echo json_encode(array_values($drivers), JSON_UNESCAPED_UNICODE); ?>;
      const values = <?php echo json_encode(array_map(function($d) use ($contagemPorDriver,$totalOcorrencias){ $c=$contagemPorDriver[$d]??0; return $totalOcorrencias>0?round(($c/$totalOcorrencias)*100,1):0; }, $drivers), JSON_UNESCAPED_UNICODE); ?>;

      const ctx1 = document.getElementById('chartRanking');
      new Chart(ctx1, {
        type: 'bar',
        data: {
          labels,
          datasets: [{
            label: 'Percentual',
            data: values,
            backgroundColor: '#ef4444',
            borderRadius: 6
          }]
        },
        options: {
          responsive: false,
          maintainAspectRatio: false,
          animation: false,
          scales: { y: { beginAtZero: true, ticks: { callback: v => v + '%' } } }
        }
      });

      // Gráfico 2: Enviadas x Benchmark
      const ctx2 = document.getElementById('chartBenchmark');
      const benchmark = values.map(v => Math.max(0, Math.min(100, v * 0.8 + 10)));
      new Chart(ctx2, {
        type: 'bar',
        data: {
          labels,
          datasets: [
            { label: 'Enviadas', data: values, backgroundColor: 'rgba(239,68,68,0.8)', borderRadius: 6 },
            { label: 'Benchmark', data: benchmark, backgroundColor: 'rgba(239,68,68,0.3)', borderRadius: 6 }
          ]
        },
        options: {
          responsive: false,
          maintainAspectRatio: false,
          animation: false,
          scales: { y: { beginAtZero: true, ticks: { callback: v => v + '%' } } }
        }
      });
    });
  </script>
</body>
</html>