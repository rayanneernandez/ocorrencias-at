<?php
session_start();
require_once __DIR__ . '/../includes/db.php';

if (!isset($_SESSION['usuario_id']) || intval($_SESSION['usuario_perfil'] ?? 0) !== 2) {
    header("Location: index.php");
    exit;
}

$pdo = get_pdo();

// Utilitários
function csv_header_download($filename) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=' . $filename);
}
function sanitize($s) {
    $s = (string)($s ?? '');
    $s = str_replace(["\r","\n",";"], [' ',' ',' '], $s);
    return $s;
}

// Métricas básicas (sem filtro municipal por falta de coluna; ajusto depois se desejar)
$totalOcorrencias = 0;
$ultimasOcorrencias = [];
try { $totalOcorrencias = (int)$pdo->query("SELECT COUNT(*) FROM ocorrencias")->fetchColumn(); } catch (Throwable $e) {}
try {
    $ultimasOcorrencias = $pdo->query("
        SELECT numero, tipo, status, DATE_FORMAT(data_criacao, '%d/%m/%Y %H:%i') AS criada_em
        FROM ocorrencias
        ORDER BY data_criacao DESC
        LIMIT 30
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// Coletas completas (Prefeito vê tudo)
$dadosOcorrencias = [];
$dadosPrioridades = [];
$dadosPesquisas = [];

// Ocorrências (todas)
try {
    $dadosOcorrencias = $pdo->query("
        SELECT numero, tipo, status, DATE_FORMAT(data_criacao, '%d/%m/%Y %H:%i') AS criada_em
          FROM ocorrencias
         ORDER BY data_criacao DESC
         LIMIT 200
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// Prioridades (se tabela existir)
try {
    $hasPrioridades = $pdo->query("SHOW TABLES LIKE 'prioridades'")->rowCount() > 0;
    if ($hasPrioridades) {
      $dadosPrioridades = $pdo->query("
        SELECT o.numero, o.tipo AS categoria, p.nivel_prioridade, DATE_FORMAT(o.data_criacao, '%d/%m/%Y %H:%i') AS criada_em
          FROM ocorrencias o
          JOIN prioridades p ON p.id_ocorrencia = o.id
         ORDER BY o.data_criacao DESC
         LIMIT 200
      ")->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {}

// Pesquisas (se tabela existir)
try {
    $hasPesquisa = $pdo->query("SHOW TABLES LIKE 'pesquisa'")->rowCount() > 0;
    if ($hasPesquisa) {
        // tenta pegar `created_at`; se não existir, usa NOW() só para exibir algo
        $cols = $pdo->query("SHOW COLUMNS FROM pesquisa")->fetchAll(PDO::FETCH_ASSOC);
        $colNames = array_map(fn($c)=>$c['Field'], $cols);
        $createdExpr = in_array('created_at', $colNames) ? "DATE_FORMAT(created_at, '%d/%m/%Y %H:%i')" : "DATE_FORMAT(NOW(), '%d/%m/%Y %H:%i')";
        $dadosPesquisas = $pdo->query("
          SELECT titulo, descricao, {$createdExpr} AS criada_em
            FROM pesquisa
           ORDER BY id DESC
           LIMIT 200
        ")->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {}

// Downloads CSV
if (isset($_GET['download'])) {
    $type = $_GET['download'];
    if ($type === 'ocorrencias') {
        csv_header_download('ocorrencias_prefeito.csv');
        echo "Numero;Tipo;Status;CriadaEm\n";
        foreach ($dadosOcorrencias as $r) {
            echo sanitize($r['numero']).";".sanitize($r['tipo']).";".sanitize($r['status']).";".sanitize($r['criada_em'])."\n";
        }
        exit;
    }
    if ($type === 'prioridades') {
        csv_header_download('prioridades_prefeito.csv');
        echo "Numero;Categoria;NivelPrioridade;CriadaEm\n";
        if (!empty($dadosPrioridades)) {
            foreach ($dadosPrioridades as $r) {
                echo sanitize($r['numero']).";".sanitize($r['categoria']).";".sanitize($r['nivel_prioridade']).";".sanitize($r['criada_em'])."\n";
            }
        }
        exit;
    }
    if ($type === 'pesquisas') {
        csv_header_download('pesquisas_prefeito.csv');
        echo "Titulo;Descricao;CriadaEm\n";
        if (!empty($dadosPesquisas)) {
            foreach ($dadosPesquisas as $r) {
                echo sanitize($r['titulo']).";".sanitize($r['descricao']).";".sanitize($r['criada_em'])."\n";
            }
        }
        exit;
    }
}

// PDF via página de impressão
if (isset($_GET['pdf'])) {
    $type = $_GET['pdf'];
    $titulo = 'Relatório';
    $linhas = [];
    $cabecalho = [];

    if ($type === 'ocorrencias') {
        $titulo = 'Relatório de Ocorrências (Prefeito)';
        $cabecalho = ['Número','Tipo','Status','Criada em'];
        $linhas = array_map(fn($r) => [
            $r['numero'] ?? '—', $r['tipo'] ?? '—', $r['status'] ?? '—', $r['criada_em'] ?? '—'
        ], $dadosOcorrencias);
    } elseif ($type === 'prioridades') {
        $titulo = 'Relatório de Prioridades (Prefeito)';
        $cabecalho = ['Número','Categoria','Nível de Prioridade','Criada em'];
        $linhas = array_map(fn($r) => [
            $r['numero'] ?? '—', $r['categoria'] ?? '—', $r['nivel_prioridade'] ?? '—', $r['criada_em'] ?? '—'
        ], $dadosPrioridades);
    } elseif ($type === 'pesquisas') {
        $titulo = 'Relatório de Pesquisas (Prefeito)';
        $cabecalho = ['Título','Descrição','Criada em'];
        $linhas = array_map(fn($r) => [
            $r['titulo'] ?? '—', $r['descricao'] ?? '—', $r['criada_em'] ?? '—'
        ], $dadosPesquisas);
    }

    ?>
    <!DOCTYPE html>
    <html lang="pt-BR">
    <head>
      <meta charset="UTF-8" />
      <title><?= htmlspecialchars($titulo) ?></title>
      <meta name="viewport" content="width=device-width, initial-scale=1.0" />
      <style>
        body { font-family: Arial, sans-serif; margin: 24px; }
        h1 { font-size: 20px; margin-bottom: 12px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #ddd; padding: 8px; font-size: 12px; }
        th { background: #f0f0f0; text-align: left; }
        @media print { .no-print { display: none; } }
        .actions { margin: 10px 0 20px; }
        .btn { display: inline-block; padding: 8px 12px; background: #16a34a; color: #fff; text-decoration: none; border-radius: 6px; margin-right: 8px; }
      </style>
    </head>
    <body>
      <div class="actions no-print">
        <a href="#" class="btn" onclick="window.print()">Imprimir / Salvar PDF</a>
        <a class="btn" href="relatorios_prefeito.php">Voltar</a>
      </div>
      <h1><?= htmlspecialchars($titulo) ?></h1>
      <table>
        <thead><tr>
          <?php foreach ($cabecalho as $th): ?><th><?= htmlspecialchars($th) ?></th><?php endforeach; ?>
        </tr></thead>
        <tbody>
          <?php if (!empty($linhas)): foreach ($linhas as $row): ?>
            <tr>
              <?php foreach ($row as $cell): ?><td><?= htmlspecialchars($cell) ?></td><?php endforeach; ?>
            </tr>
          <?php endforeach; else: ?>
            <tr><td colspan="<?= count($cabecalho) ?>">Sem dados</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </body>
    </html>
    <?php
    exit;
}

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8" />
  <title>Relatórios (Prefeito) - RADCI</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-white min-h-screen">
  <header class="bg-green-700 text-white">
    <div class="container mx-auto px-6 py-4 flex items-center justify-between">
      <h1 class="text-xl font-bold">RADCI — Prefeito</h1>
      <nav class="space-x-6">
        <a href="prefeito_inicio.php" class="hover:underline">Início</a>
        <a href="ocorrencias.php" class="hover:underline">Ocorrências</a>
        <a href="relatorios_prefeito.php" class="hover:underline font-semibold">Relatórios</a>
        <a href="login_cadastro.php?logout=1" class="hover:underline">Sair</a>
      </nav>
    </div>
  </header>

  <main class="container mx-auto px-6 py-8 max-w-6xl">
    <h2 class="text-2xl font-bold text-gray-900 mb-6">Relatórios</h2>

    <div class="grid md:grid-cols-3 gap-6 mt-2">
      <!-- Card: Ocorrências -->
      <div class="border rounded-xl p-8 text-center hover:shadow transition">
        <div class="inline-flex items-center justify-center w-16 h-16 bg-green-50 text-green-600 rounded-xl mb-4">🗂</div>
        <h3 class="text-lg font-semibold text-gray-900 mb-2">OCORRÊNCIAS</h3>
        <p class="text-gray-600 mb-4">Exportar todas as ocorrências</p>
        <div class="flex items-center justify-center gap-3">
          <a href="?download=ocorrencias" class="inline-flex items-center gap-2 px-5 py-2 rounded-md bg-green-600 text-white hover:bg-green-700">CSV</a>
          <a href="?pdf=ocorrencias" class="inline-flex items-center gap-2 px-5 py-2 rounded-md bg-gray-800 text-white hover:bg-gray-900">PDF</a>
        </div>
      </div>

      <!-- Card: Pesquisas -->
      <div class="border rounded-xl p-8 text-center hover:shadow transition">
        <div class="inline-flex items-center justify-center w-16 h-16 bg-green-50 text-green-600 rounded-xl mb-4">📄</div>
        <h3 class="text-lg font-semibold text-gray-900 mb-2">PESQUISAS</h3>
        <p class="text-gray-600 mb-4">Exportar todas as pesquisas</p>
        <div class="flex items-center justify-center gap-3">
          <a href="?download=pesquisas" class="inline-flex items-center gap-2 px-5 py-2 rounded-md bg-green-600 text-white hover:bg-green-700">CSV</a>
          <a href="?pdf=pesquisas" class="inline-flex items-center gap-2 px-5 py-2 rounded-md bg-gray-800 text-white hover:bg-gray-900">PDF</a>
        </div>
      </div>

      <!-- Card: Prioridades -->
      <div class="border rounded-xl p-8 text-center hover:shadow transition">
        <div class="inline-flex items-center justify-center w-16 h-16 bg-green-50 text-green-600 rounded-xl mb-4">📊</div>
        <h3 class="text-lg font-semibold text-gray-900 mb-2">PRIORIDADES</h3>
        <p class="text-gray-600 mb-4">Exportar todas as prioridades</p>
        <div class="flex items-center justify-center gap-3">
          <a href="?download=prioridades" class="inline-flex items-center gap-2 px-5 py-2 rounded-md bg-green-600 text-white hover:bg-green-700">CSV</a>
          <a href="?pdf=prioridades" class="inline-flex items-center gap-2 px-5 py-2 rounded-md bg-gray-800 text-white hover:bg-gray-900">PDF</a>
        </div>
      </div>
    </div>

    <div class="mt-10 text-sm text-gray-500">
      Observação: o PDF é gerado via página de impressão. Use “Imprimir” e selecione “Salvar como PDF”.
    </div>
  </main>
</body>
</html>