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
    // Ordena 'em_analise' e 'cancelada' no topo; depois mais recentes
    $stmt = $pdo->query("SELECT numero, tipo, status, data_criacao FROM ocorrencias ORDER BY (status IN ('em_analise','cancelada')) DESC, data_criacao DESC LIMIT 100");
    $ocorrencias = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $errorMsg = 'Não foi possível carregar as ocorrências.';
}

// Atribuir ocorrência a secretário (com perfil/zone)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'assign_secretario') {
    $numero       = trim($_POST['numero'] ?? '');
    $secretarioId = intval($_POST['secretario_id'] ?? 0);
    $perfilId     = intval($_POST['perfil_id'] ?? 0);

    if ($numero !== '' && $secretarioId > 0) {
        try {
            // Garante a tabela de atribuições
            $pdo->exec("
              CREATE TABLE IF NOT EXISTS ocorrencias_atribuicoes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                ocorrencia_id INT NOT NULL,
                secretario_id INT NOT NULL,
                perfil_id INT NULL,
                data_atribuicao DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_pair (ocorrencia_id, secretario_id),
                INDEX (secretario_id),
                INDEX (perfil_id)
              )
            ");

            // Resolve ID da ocorrência a partir do número
            $stmt = $pdo->prepare("SELECT id FROM ocorrencias WHERE numero = ? LIMIT 1");
            $stmt->execute([$numero]);
            $ocId = (int)$stmt->fetchColumn();

            if ($ocId > 0) {
                $stmt = $pdo->prepare("
                  INSERT INTO ocorrencias_atribuicoes (ocorrencia_id, secretario_id, perfil_id)
                  VALUES (?, ?, ?)
                  ON DUPLICATE KEY UPDATE perfil_id = VALUES(perfil_id), data_atribuicao = CURRENT_TIMESTAMP
                ");
                $stmt->execute([$ocId, $secretarioId, $perfilId ?: null]);
                $_SESSION['flash_success'] = 'Ocorrência atribuída ao secretário com sucesso.';
            } else {
                $_SESSION['flash_error'] = 'Ocorrência não encontrada.';
            }
        } catch (Throwable $e) {
            $_SESSION['flash_error'] = 'Erro ao atribuir ocorrência.';
        }
        header('Location: ocorrencias.php');
        exit;
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
        SELECT numero, tipo, status, descricao, latitude, longitude, tem_imagens, arquivos, cep, endereco
        FROM ocorrencias
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
$cepMeta = []; // mapeia CEP -> { uf, cidade, bairro }
// opção padrão
$localOptionsKeyed["RJ|Rio de Janeiro|Todos"] = "RJ - Rio de Janeiro - Todos";

// coleta CEPs distintos e resolve UF/Cidade/Bairro via ViaCEP
try {
    $ceps = $pdo->query("SELECT DISTINCT cep FROM ocorrencias WHERE cep IS NOT NULL AND cep <> '' ORDER BY cep ASC LIMIT 200")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ceps as $cep) {
        $digits = preg_replace('/\D/', '', $cep);
        if (strlen($digits) !== 8) continue;

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
                    $cepMeta[$digits] = ['uf' => $uf, 'cidade' => $cidade, 'bairro' => ($bairro ?: '')];
                }
            }
        }
    }
} catch (Throwable $e) {}
foreach ($localOptionsKeyed as $k => $label) {
    $localOptions[] = ['value' => $k, 'label' => $label];
}

// Dataset plano para recontar “Prioridades” no cliente (inclui endereço)
$occList = [];
try {
    $occList = $pdo->query("
        SELECT tipo, cep, endereco
          FROM ocorrencias
         LIMIT 2000
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $_) {}

// Cards: todas as ocorrências com paginação (10 por página)
$cards = [];
$cardsPerPage  = 10;
$cardsPage     = max(1, intval($_GET['page'] ?? 1));
$cardsOffset   = ($cardsPage - 1) * $cardsPerPage;
$cardsTotal    = 0;
$cardsPages    = 1;

try {
    $cardsTotal = (int)$pdo->query("SELECT COUNT(*) FROM ocorrencias")->fetchColumn();
    $cardsPages = max(1, (int)ceil($cardsTotal / $cardsPerPage));
    $stmtCards = $pdo->prepare("
        SELECT numero, tipo, status, endereco, descricao, tem_imagens, arquivos, data_criacao
          FROM ocorrencias
      ORDER BY (status IN ('em_analise','cancelada')) DESC, data_criacao DESC
         LIMIT :limit OFFSET :offset
    ");
    $stmtCards->bindValue(':limit',  $cardsPerPage, PDO::PARAM_INT);
    $stmtCards->bindValue(':offset', $cardsOffset,  PDO::PARAM_INT);
    $stmtCards->execute();
    $cards = $stmtCards->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $_) {}
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
    /* Gráficos fluídos: ocupam toda a largura do container */
    .chart-fluid { width: 100%; height: 320px; }
  </style>
</head>
<body class="bg-white min-h-screen">
  <header class="bg-green-700 text-white">
    <div class="container mx-auto px-6 py-4 flex items-center justify-between relative">
      <img src="/radci/assets/images/logo.png" alt="RADCI" class="h-8 w-auto" />
      <nav class="hidden md:flex items-center gap-6">
        <a href="prefeito_inicio.php" class="hover:underline">Início</a>
        <a href="gestor_secretarios.php" class="hover:underline">Meus Secretários</a>
        <a href="ocorrencias.php" class="hover:underline font-semibold">Ocorrências</a>
        <a href="relatorios_prefeito.php" class="hover:underline">Relatórios</a>
        <a href="criar_pesquisa.php" class="hover:underline">Criar Pesquisa</a>
        <a href="pesquisa_respostas_prefeito.php" class="hover:underline">Respostas</a>
        <a href="login_cadastro.php?logout=1" class="hover:underline">Sair</a>
      </nav>
      <button type="button" id="mobileMenuBtn" class="md:hidden inline-flex items-center gap-2 px-3 py-2 rounded-md bg-green-600 hover:bg-green-700">
        <span class="sr-only">Abrir menu</span>
        <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h14M3 10h14M3 14h14"/></svg>
      </button>
      <div id="mobileMenu" class="absolute right-6 top-14 md:hidden hidden bg-white text-gray-800 rounded-lg shadow-lg border w-56">
        <a href="prefeito_inicio.php" class="block px-4 py-2 hover:bg-gray-100">Início</a>
        <a href="gestor_secretarios.php" class="block px-4 py-2 hover:bg-gray-100">Meus Secretários</a>
        <a href="ocorrencias.php" class="block px-4 py-2 hover:bg-gray-100">Ocorrências</a>
        <a href="relatorios_prefeito.php" class="block px-4 py-2 hover:bg-gray-100">Relatórios</a>
        <a href="criar_pesquisa.php" class="block px-4 py-2 hover:bg-gray-100">Criar Pesquisa</a>
        <a href="pesquisa_respostas_prefeito.php" class="block px-4 py-2 hover:bg-gray-100">Respostas</a>
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
    <?php if (!empty($_SESSION['flash_success'])): ?>
      <div class="mb-4 rounded-lg bg-green-50 border border-green-200 text-green-800 px-4 py-3">
        <?= htmlspecialchars($_SESSION['flash_success']) ?>
      </div>
      <?php $_SESSION['flash_success'] = null; ?>
    <?php endif; ?>
    <?php if (!empty($_SESSION['flash_error'])): ?>
      <div class="mb-4 rounded-lg bg-red-50 border border-red-200 text-red-800 px-4 py-3">
        <?= htmlspecialchars($_SESSION['flash_error']) ?>
      </div>
      <?php $_SESSION['flash_error'] = null; ?>
    <?php endif; ?>

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
            <table id="prioridades-table" class="min-w-full text-left">
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

    <!-- 4) Todas as Ocorrências (cards com paginação) -->
    <section class="mt-10">
      <h2 class="text-2xl font-bold text-gray-900 mb-4">Todas as Ocorrências</h2>

      <?php if (empty($cards)): ?>
        <p class="text-gray-600">Nenhuma ocorrência encontrada.</p>
      <?php else: ?>
        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-6">
          <?php foreach ($cards as $row): 
            $numero     = htmlspecialchars($row['numero'] ?? '');
            $tipo       = htmlspecialchars($row['tipo'] ?? '');
            $statusRaw  = strtolower(trim($row['status'] ?? ''));
            $statusDisp = htmlspecialchars($row['status'] ?? '');
            $endereco   = htmlspecialchars($row['endereco'] ?? '');
            $dataFmt    = !empty($row['data_criacao']) ? date('d/m/Y', strtotime($row['data_criacao'])) : '—';
            $temImg     = (intval($row['tem_imagens'] ?? 0) ? 'Sim' : 'Não');
            $badgeClass = 'bg-gray-100 text-gray-800';
            if (in_array($statusRaw, ['resolvida','concluida','concluída'], true)) $badgeClass = 'bg-green-100 text-green-800';
            elseif ($statusRaw === 'encaminhada') $badgeClass = 'bg-blue-100 text-blue-800';
            elseif (in_array($statusRaw, ['em_analise','em analise','em analise'], true)) $badgeClass = 'bg-yellow-100 text-yellow-800';
            elseif ($statusRaw === 'cancelada') $badgeClass = 'bg-red-100 text-red-800';

            $descricao  = htmlspecialchars($row['descricao'] ?? '', ENT_QUOTES);
            $arquivos   = htmlspecialchars(is_string($row['arquivos'] ?? '') ? ($row['arquivos'] ?? '[]') : json_encode($row['arquivos'] ?? []), ENT_QUOTES);
          ?>
          <article class="rounded-xl border p-4 flex flex-col gap-2 shadow-sm">
            <div class="flex items-center justify-between">
              <span class="text-sm text-gray-500"><?= $numero ?></span>
              <span class="text-xs px-2 py-1 rounded-full <?= $badgeClass ?>"><?= $statusDisp ?></span>
            </div>
            <div class="text-lg font-semibold text-gray-900"><?= $tipo ?></div>
            <div class="text-sm text-gray-700"><?= $endereco ?></div>
            <div class="text-sm text-gray-500"><?= $dataFmt ?> · <?= $temImg === 'Sim' ? 'Com imagens' : 'Sem imagens' ?></div>
            <button type="button"
                    class="btn-view-details bg-green-600 text-white px-4 py-2 rounded-md hover:bg-green-700 mt-auto"
                    data-numero="<?= $numero ?>"
                    data-tipo="<?= $tipo ?>"
                    data-status="<?= $statusDisp ?>"
                    data-local="<?= $endereco ?>"
                    data-data="<?= htmlspecialchars($dataFmt, ENT_QUOTES) ?>"
                    data-tem-imagens="<?= $temImg ?>"
                    data-descricao="<?= $descricao ?>"
                    data-arquivos="<?= $arquivos ?>">
              Ver detalhes
            </button>
          </article>
          <?php endforeach; ?>
        </div>

        <?php
          // Paginação (preserva query atual)
          $qs = $_GET ?? [];
          $makeUrl = function($p) use ($qs) {
            $qs['page'] = max(1, (int)$p);
            return 'ocorrencias.php?' . htmlspecialchars(http_build_query($qs));
          };
        ?>
        <?php if ($cardsPages > 1): ?>
          <div class="mt-6 flex items-center justify-between">
            <div class="text-sm text-gray-600">Página <?= (int)$cardsPage ?> de <?= (int)$cardsPages ?></div>
            <nav class="flex gap-2">
              <a href="<?= $makeUrl(max(1, $cardsPage-1)) ?>"
                 class="px-3 py-1 rounded border <?= ($cardsPage<=1?'opacity-50 pointer-events-none':'') ?>">Anterior</a>
              <?php for ($p=1; $p <= $cardsPages; $p++): ?>
                <a href="<?= $makeUrl($p) ?>"
                   class="px-3 py-1 rounded border <?= ($p===$cardsPage ? 'bg-green-600 text-white border-green-600' : 'bg-white text-gray-700') ?>">
                  <?= (int)$p ?>
                </a>
              <?php endfor; ?>
              <a href="<?= $makeUrl(min($cardsPages, $cardsPage+1)) ?>"
                 class="px-3 py-1 rounded border <?= ($cardsPage>=$cardsPages?'opacity-50 pointer-events-none':'') ?>">Próxima</a>
            </nav>
          </div>
        <?php endif; ?>
      <?php endif; ?>
    </section>

    <!-- Modal de detalhes (mesmo padrão do Secretário) -->
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

        <div class="px-6 py-4 grid md:grid-cols-2 gap-6">
          <div class="space-y-3">
            <div><span class="text-gray-600">Categoria</span><div class="font-medium text-gray-900" id="detCategoria">—</div></div>
            <div><span class="text-gray-600">Local</span><div class="font-medium text-gray-900" id="detLocal">—</div></div>
            <div><span class="text-gray-600">Data</span><div class="font-medium text-gray-900" id="detData">—</div></div>
            <div><span class="text-gray-600">Tem Imagens</span><div class="font-medium text-gray-900" id="detTemImagens">—</div></div>

            <div class="mt-4">
              <span class="text-gray-600">Descrição</span>
              <div id="detDescricao" class="mt-1 text-gray-900 break-words whitespace-normal" style="overflow-wrap:anywhere; word-break: break-word; white-space: pre-wrap;">—</div>
            </div>
          </div>

          <div>
            <span class="text-gray-600">Evidências</span>
            <div class="mt-2">
              <img id="detMainImg" class="w-full max-h-[50vh] object-contain rounded-lg cursor-pointer hidden" alt="Evidência principal" />
              <div id="detThumbs" class="mt-2 grid grid-cols-3 gap-2 max-h-[50vh] overflow-y-auto"></div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Visualizador de evidências -->
    <div id="evidenceViewer" class="fixed inset-0 bg-black/80 hidden items-center justify-center p-4" style="z-index: 9999;">
      <button id="evPrev" class="absolute left-6 top-1/2 -translate-y-1/2 text-white text-3xl">‹</button>
      <img id="evImg" class="max-w-[70vw] max-h-[65vh] object-contain rounded-lg shadow-lg" alt="Evidência" />
      <button id="evNext" class="absolute right-6 top-1/2 -translate-y-1/2 text-white text-3xl">›</button>
      <button id="evClose" class="absolute top-4 right-4 text-white text-2xl">✕</button>
    </div>
  </main>

  <script>
    // Dados vindos do PHP
    const markers = <?php echo json_encode($markers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    const CEP_META = <?php echo json_encode($cepMeta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    const OCC_LIST = <?php echo json_encode($occList, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

    document.addEventListener('DOMContentLoaded', () => {
      const DEFAULT = [-22.9068, -43.1729];
      const map = L.map('map').setView(DEFAULT, 11);
      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 18,
        attribution: '&copy; OpenStreetMap'
      }).addTo(map);

      const layerGroup = L.layerGroup().addTo(map);

      // Fallback: tenta geocodificar pelo endereço quando não há lat/lon
      async function ensureLatLon(item) {
        const lat = parseFloat(item.latitude);
        const lon = parseFloat(item.longitude);
        if (!isNaN(lat) && !isNaN(lon)) return { lat, lon };

        const q = (item.endereco || '').toString().trim();
        if (!q) return null;
        try {
          const url = `/radci/api/geocode.php?q=${encodeURIComponent(q)}&countrycodes=br&limit=1`;
          const r = await fetch(url);
          const j = await r.json();
          const cand = Array.isArray(j) && j[0];
          const plat = cand ? parseFloat(cand.lat) : NaN;
          const plon = cand ? parseFloat(cand.lon) : NaN;
          if (!isNaN(plat) && !isNaN(plon)) return { lat: plat, lon: plon };
        } catch (_) {}
        return null;
      }

      async function addMarkersAsync(list) {
        layerGroup.clearLayers();
        // limita a 100 para evitar excesso de requisições
        const limited = list.slice(0, 100);
        for (const m of limited) {
          const coords = await ensureLatLon(m);
          if (!coords) continue;
          const marker = L.marker([coords.lat, coords.lon]);
          const desc = (m.descricao || '').toString().slice(0, 200);
          const numero = m.numero;
          const tipo = m.tipo || '';
          const status = m.status || 'Em Análise';
          const statusRaw = (status || '').toLowerCase();
          const isResolved = ['resolvida','concluida','concluída','cancelada'].includes(statusRaw);
          const buttonsHtml = isResolved
            // resolvida: oculta “Em análise”
            ? `
                <form method="POST" action="ocorrencias.php" class="inline">
                  <input type="hidden" name="action" value="update_status"/>
                  <input type="hidden" name="numero" value="${numero}"/>
                  <input type="hidden" name="status" value="resolvida"/>
                  <button type="submit" class="px-3 py-2 rounded-md bg-green-600 text-white hover:bg-green-700 text-sm">✔ RESOLVIDA</button>
                </form>
              `
            // demais: mostra ambos
            : `
                <form method="POST" action="ocorrencias.php" class="inline">
                  <input type="hidden" name="action" value="update_status"/>
                  <input type="hidden" name="numero" value="${numero}"/>
                  <input type="hidden" name="status" value="resolvida"/>
                  <button type="submit" class="px-3 py-2 rounded-md bg-green-600 text-white hover:bg-green-700 text-sm">✔ RESOLVIDA</button>
                </form>
                <form method="POST" action="ocorrencias.php" class="inline">
                  <input type="hidden" name="action" value="update_status"/>
                  <input type="hidden" name="numero" value="${numero}"/>
                  <input type="hidden" name="status" value="em_analise"/>
                  <button type="submit" class="px-3 py-2 rounded-md bg-red-600 text-white hover:bg-red-700 text-sm">✖ EM ANÁLISE</button>
                </form>
              `;
          const popupHtml = `
            <div class="min-w-[260px]">
              <div class="flex items-center justify-between mb-2">
                <div class="font-semibold text-gray-800">${tipo}</div>
                <button type="button" class="text-gray-500 hover:text-gray-700" onclick="this.closest('.leaflet-popup').querySelector('.leaflet-popup-close-button')?.click()">✕</button>
              </div>
              <div class="text-sm text-gray-700 mb-2" style="overflow-wrap:anywhere; word-break: break-word; white-space: pre-wrap;">${desc || 'Sem descrição'}</div>
              <div class="text-xs text-gray-500 mb-3">Status atual: ${status}</div>
              <div class="flex gap-2">
                ${buttonsHtml}
              </div>
            </div>
          `;
          marker.bindPopup(popupHtml).addTo(layerGroup);
        }
      }

      // cria marcadores iniciais (com fallback)
      addMarkersAsync(markers);

      // Gráficos
      const labels = <?php echo json_encode(array_values($drivers), JSON_UNESCAPED_UNICODE); ?>;
      const initialValues = <?php echo json_encode(array_map(function($d) use ($contagemPorDriver,$totalOcorrencias){ $c=$contagemPorDriver[$d]??0; return $totalOcorrencias>0?round(($c/$totalOcorrencias)*100,1):0; }, $drivers), JSON_UNESCAPED_UNICODE); ?>;

      const ctx1 = document.getElementById('chartRanking');
      const chartRanking = new Chart(ctx1, { type: 'bar', data: { labels, datasets: [{ label: 'Percentual', data: initialValues, backgroundColor: '#ef4444', borderRadius: 6 }] }, options: { responsive: false, maintainAspectRatio: false, animation: false, scales: { y: { beginAtZero: true, ticks: { callback: v => v + '%' } } } } });
      const ctx2 = document.getElementById('chartBenchmark');
      const chartBenchmark = new Chart(ctx2, { type: 'bar', data: { labels, datasets: [{ label: 'Enviadas', data: initialValues, backgroundColor: 'rgba(239,68,68,0.8)', borderRadius: 6 }, { label: 'Benchmark', data: initialValues.map(v => Math.max(0, Math.min(100, v * 0.8 + 10))), backgroundColor: 'rgba(239,68,68,0.3)', borderRadius: 6 }] }, options: { responsive: false, maintainAspectRatio: false, animation: false, scales: { y: { beginAtZero: true, ticks: { callback: v => v + '%' } } } } });

      // Utilidades de filtro
      const digits = s => (s || '').toString().replace(/\D/g, '');
      function parseLocalFromEndereco(endereco) {
        const raw = (endereco || '').toString();
        // tenta: "Rua X, Bairro Y, Cidade Z, UF"
        const parts = raw.split(',').map(s => s.trim()).filter(Boolean);
        if (parts.length >= 3) {
          const bairro = parts[1] || '';
          const cidade = parts[2] || '';
          const uf = (parts[3] || '').replace(/[^A-Z]/g,'') || 'RJ';
          return { uf, cidade, bairro };
        }
        // fallback: "..., Bairro Y - Cidade Z, UF"
        const m = raw.match(/,\s*(.*?)\s*-\s*(.*?),(?:\s*([A-Z]{2}))?/u);
        if (m) return { bairro: m[1] || '', cidade: m[2] || '', uf: m[3] || 'RJ' };
        return null;
      }
      function matchesLocalForItem(item, sel) {
        const [ufSel, cidadeSel, bairroSel] = sel.split('|');
        const cd = digits(item.cep);
        const meta = (cd && CEP_META[cd]) || parseLocalFromEndereco(item.endereco);
        if (!meta) return false;
        if (meta.uf !== ufSel || meta.cidade !== cidadeSel) return false;
        if (bairroSel === 'Todos') return true;
        return (meta.bairro || '') === bairroSel;
      }

      function recalcValues(sel) {
        const counts = Object.fromEntries(labels.map(l => [l, 0]));
        let total = 0;
        OCC_LIST.forEach(o => {
          if (matchesLocalForItem(o, sel)) {
            const tipo = (o.tipo || '').toString();
            const match = labels.find(l => l.toLowerCase() === tipo.toLowerCase());
            if (match) { counts[match] += 1; total += 1; }
          }
        });
        const vals = labels.map(l => total > 0 ? Math.round((counts[l] / total) * 1000) / 10 : 0);
        return { values: vals, total };
      }

      function updatePrioridadesTable(vals) {
        const rows = Array.from(document.querySelectorAll('#prioridades-table tbody tr'));
        rows.forEach((tr, i) => {
          const perc = vals[i] || 0;
          const bar = tr.querySelector('.bg-green-700.h-3.rounded-full');
          const valCell = tr.querySelector('td:last-child');
          if (bar) bar.style.width = perc + '%';
          if (valCell) valCell.textContent = perc.toFixed(1).replace('.', ',') + '%';
        });
      }

      const localSelect = document.getElementById('localSelect');
      async function applyFilter() {
        const sel = localSelect.value;
        // 1) Marcadores filtrados (com fallback de geocode)
        const filtered = markers.filter(m => matchesLocalForItem(m, sel));
        await addMarkersAsync(filtered);
        // 2) Gráficos e tabela
        const { values } = recalcValues(sel);
        chartRanking.data.datasets[0].data = values;
        chartRanking.update();
        chartBenchmark.data.datasets[0].data = values;
        chartBenchmark.data.datasets[1].data = values.map(v => Math.max(0, Math.min(100, v * 0.8 + 10)));
        chartBenchmark.update();
        updatePrioridadesTable(values);
      }

      applyFilter();
      localSelect.addEventListener('change', applyFilter);
    });
  </script>

  <script>
    function getBadgeClass(statusRaw) {
      const st = (statusRaw || '').toLowerCase();
      if (st === 'resolvida' || st === 'concluida' || st === 'concluída') return 'bg-green-100 text-green-800';
      if (st === 'encaminhada') return 'bg-blue-100 text-blue-800';
      if (['em_analise','em análise','em analise'].includes(st)) return 'bg-yellow-100 text-yellow-800';
      if (st === 'cancelada') return 'bg-red-100 text-red-800';
      return 'bg-gray-100 text-gray-800';
    }

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

    function decodeEntities(str) {
      const t = document.createElement('textarea');
      t.innerHTML = String(str ?? '');
      return t.value;
    }
    function readData(el, name, fallback = '—') {
      const raw = el.getAttribute(name);
      if (raw == null || raw === '') return fallback;
      const val = decodeEntities(raw).trim();
      return val === '' ? fallback : val;
    }

    function openDetails(el) {
      const numero     = readData(el, 'data-numero', '—');
      const tipo       = readData(el, 'data-tipo', '—');
      const status     = readData(el, 'data-status', '—');
      const local      = readData(el, 'data-local', '—');
      const data       = readData(el, 'data-data', '—');
      const temImagens = readData(el, 'data-tem-imagens', 'Não');
      const descricao  = readData(el, 'data-descricao', '—');

      detNumero.textContent     = numero;
      detStatus.textContent     = status;
      detStatus.className       = 'text-xs px-2 py-1 rounded-full ' + getBadgeClass(status);
      detCategoria.textContent  = tipo;
      detLocal.textContent      = local;
      detData.textContent       = data;
      detTemImagens.textContent = temImagens;
      detDescricao.textContent  = descricao;

      try {
        const arquivosStr = readData(el, 'data-arquivos', '[]');
        let files = [];
        try {
          files = JSON.parse(arquivosStr);
        } catch (_) {
          try {
            files = JSON.parse(arquivosStr.replace(/&quot;/g, '\"').replace(/&#039;/g, "'"));
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
          detMainImg.classList.add('hidden');
        } else {
          const urls = imgs.map(makeUrl);
          detMainImg.src = urls[0];
          detMainImg.classList.remove('hidden');
          detMainImg.onclick = () => openViewer(urls, 0);
          urls.forEach((url, idx) => {
            const a = document.createElement('a');
            a.href = 'javascript:void(0)';
            a.innerHTML = '<img src="' + url + '" class="w-full h-32 object-cover rounded-md hover:opacity-90" alt="Evidência ' + (idx + 1) + '" />';
            a.addEventListener('click', () => openViewer(urls, idx));
            detThumbs.appendChild(a);
          });
        }
      } catch (_) {}

      detailsModal.classList.remove('hidden');
      detailsModal.classList.add('flex');
      document.body.style.overflow = 'hidden';
    }

    window.addEventListener('DOMContentLoaded', () => {
      document.querySelectorAll('.btn-view-details').forEach((btn) => {
        if (btn.dataset.bound === '1') return;
        btn.dataset.bound = '1';
        btn.addEventListener('click', (e) => {
          e.preventDefault();
          e.stopPropagation();
          openDetails(btn);
        });
      });
    });

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
      if (e.target === detailsModal) closeDetails();
    });

    const evidenceViewer = document.getElementById('evidenceViewer');
    const evImg   = document.getElementById('evImg');
    const evPrev  = document.getElementById('evPrev');
    const evNext  = document.getElementById('evNext');
    const evClose = document.getElementById('evClose');
    let evList = [];
    let evIndex = 0;

    function showEv() { evImg.src = evList[evIndex]; }
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
    evPrev.addEventListener('click', () => { evIndex = (evIndex - 1 + evList.length) % evList.length; showEv(); });
    evNext.addEventListener('click', () => { evIndex = (evIndex + 1) % evList.length; showEv(); });
    evClose.addEventListener('click', closeViewer);
    evidenceViewer.addEventListener('click', (e) => { if (e.target === evidenceViewer) closeViewer(); });
  </script>
</body>
</html>
