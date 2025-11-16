<?php
session_start();
require_once __DIR__ . '/../includes/db.php';

if (!isset($_SESSION['usuario_id']) || intval($_SESSION['usuario_perfil'] ?? 0) !== 3) {
    header("Location: index.php");
    exit;
}

$pdo = get_pdo();
$secretarioId = intval($_SESSION['usuario_id']);

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

// Dados filtrados (atribuídos ao Secretário)
$dadosOcorrencias = [];
$dadosPrioridades = [];
$dadosPesquisas = [];

// Ocorrências atribuídas
try {
    // Detecta forma de atribuição no banco
    $hasSecIdCol = false;
    $hasAssignedCol = false;
    $hasMapTable = false;
    try {
        $hasSecIdCol    = $pdo->query("SHOW COLUMNS FROM ocorrencias LIKE 'secretario_id'")->rowCount() > 0;
        $hasAssignedCol = !$hasSecIdCol && $pdo->query("SHOW COLUMNS FROM ocorrencias LIKE 'assigned_secretario_id'")->rowCount() > 0;
        $hasMapTable    = $pdo->query("SHOW TABLES LIKE 'ocorrencias_atribuicoes'")->rowCount() > 0;
    } catch (Throwable $_) {}

    $joinSql   = '';
    $whereSql  = '';
    $paramVals = [$secretarioId];

    if ($hasSecIdCol) {
        $whereSql = "o.secretario_id = ?";
    } elseif ($hasAssignedCol) {
        $whereSql = "o.assigned_secretario_id = ?";
    } elseif ($hasMapTable) {
        $joinSql  = "JOIN ocorrencias_atribuicoes oa ON oa.ocorrencia_id = o.id";
        $whereSql = "oa.secretario_id = ?";
    } else {
        // Fallback: ocorrências do próprio usuário (até existir atribuição no banco)
        $whereSql = "o.usuario_id = ?";
    }

    // Consulta principal de Ocorrências
    $sql = "
        SELECT o.numero, o.tipo, o.status, DATE_FORMAT(o.data_criacao, '%d/%m/%Y %H:%i') AS criada_em
          FROM ocorrencias o
          {$joinSql}
         WHERE {$whereSql}
         ORDER BY o.data_criacao DESC
         LIMIT 100
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($paramVals);
    $dadosOcorrencias = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// Prioridades vinculadas às ocorrências do Secretário (se tabela existir)
try {
    $hasPrioridades = $pdo->query("SHOW TABLES LIKE 'prioridades'")->rowCount() > 0;
    if ($hasPrioridades) {
      // Reutiliza o mesmo filtro de atribuição
      $joinSql   = '';
      $whereSql  = '';
      $paramVals = [$secretarioId];

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
          $whereSql = "o.usuario_id = ?";
      }

      $sql = "
        SELECT o.numero, o.tipo AS categoria, p.nivel_prioridade, DATE_FORMAT(o.data_criacao, '%d/%m/%Y %H:%i') AS criada_em
          FROM ocorrencias o
          {$joinSql}
          JOIN prioridades p ON p.id_ocorrencia = o.id
         WHERE {$whereSql}
         ORDER BY o.data_criacao DESC
         LIMIT 100
      ";
      $stmt = $pdo->prepare($sql);
      $stmt->execute($paramVals);
      $dadosPrioridades = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {}

// Pesquisas respondidas pelo Secretário (se tabelas existirem)
try {
    $hasUV = $pdo->query("SHOW TABLES LIKE 'usuarios_validacoes'")->rowCount() > 0;
    $hasPesquisa = $pdo->query("SHOW TABLES LIKE 'pesquisa'")->rowCount() > 0;
    if ($hasUV && $hasPesquisa) {
        $stmt = $pdo->prepare("
          SELECT p.titulo, p.descricao, DATE_FORMAT(p.created_at, '%d/%m/%Y %H:%i') AS criada_em
            FROM usuarios_validacoes uv
            JOIN pesquisa p ON p.id = uv.idPesquisa
           WHERE uv.idUsuario = ?
           ORDER BY uv.id DESC
           LIMIT 100
        ");
        $stmt->execute([$secretarioId]);
        $dadosPesquisas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {}

// Downloads CSV
if (isset($_GET['download'])) {
    $type = $_GET['download'];
    if ($type === 'ocorrencias') {
        csv_header_download('ocorrencias_secretario.csv');
        echo "Numero;Tipo;Status;CriadaEm\n";
        foreach ($dadosOcorrencias as $r) {
            echo sanitize($r['numero']).";".sanitize($r['tipo']).";".sanitize($r['status']).";".sanitize($r['criada_em'])."\n";
        }
        exit;
    }
    if ($type === 'prioridades') {
        csv_header_download('prioridades_secretario.csv');
        echo "Numero;Categoria;NivelPrioridade;CriadaEm\n";
        if (!empty($dadosPrioridades)) {
            foreach ($dadosPrioridades as $r) {
                echo sanitize($r['numero']).";".sanitize($r['categoria']).";".sanitize($r['nivel_prioridade']).";".sanitize($r['criada_em'])."\n";
            }
        } else {
            echo "—;—;—;—\n";
        }
        exit;
    }
    if ($type === 'pesquisas') {
        csv_header_download('pesquisas_secretario.csv');
        echo "Titulo;Descricao;CriadaEm\n";
        if (!empty($dadosPesquisas)) {
            foreach ($dadosPesquisas as $r) {
                echo sanitize($r['titulo']).";".sanitize($r['descricao']).";".sanitize($r['criada_em'])."\n";
            }
        } else {
            echo "—;—;—\n";
        }
        exit;
    }
}

// “PDF” via página de impressão (o navegador permite salvar como PDF)
if (isset($_GET['pdf'])) {
    $type = $_GET['pdf'];
    $titulo = 'Relatório';
    $linhas = [];
    $cabecalho = [];

    if ($type === 'ocorrencias') {
        $titulo = 'Relatório de Ocorrências (Secretário)';
        $cabecalho = ['Número','Tipo','Status','Criada em'];
        $linhas = array_map(fn($r) => [
            $r['numero'] ?? '—', $r['tipo'] ?? '—', $r['status'] ?? '—', $r['criada_em'] ?? '—'
        ], $dadosOcorrencias);
    } elseif ($type === 'prioridades') {
        $titulo = 'Relatório de Prioridades (Secretário)';
        $cabecalho = ['Número','Categoria','Nível de Prioridade','Criada em'];
        $linhas = array_map(fn($r) => [
            $r['numero'] ?? '—', $r['categoria'] ?? '—', $r['nivel_prioridade'] ?? '—', $r['criada_em'] ?? '—'
        ], $dadosPrioridades);
    } elseif ($type === 'pesquisas') {
        $titulo = 'Relatório de Pesquisas (Secretário)';
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
        <a class="btn" href="relatorios_secretario.php">Voltar</a>
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
    <title>Relatórios (Secretário) - RADCI</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-white min-h-screen">
    <header class="bg-green-700 text-white">
        <div class="container mx-auto px-6 py-5 flex items-center justify-between relative">
            <div class="flex items-center gap-3">
                <div class="bg-white/20 p-2 rounded-lg">
                    <span class="font-bold">RADCI</span>
                </div>
                <div>
                    <div class="text-sm">Relatórios atribuídos ao Secretário</div>
                </div>
            </div>
            <nav class="hidden md:flex items-center gap-8 text-white/90">
                <a href="secretario.php" class="hover:text-white">Início</a>
                <a href="prioridades.php" class="hover:text-white">Prioridades</a>
                <a href="relatorios_secretario.php" class="text-white">Relatórios</a>
                <a href="login_cadastro.php?logout=1" class="hover:text-white">Sair</a>
            </nav>
            <button type="button" id="mobileMenuBtn" class="md:hidden inline-flex items-center gap-2 px-3 py-2 rounded-md bg-green-600 hover:bg-green-700">
              <span class="sr-only">Abrir menu</span>
              <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h14M3 10h14M3 14h14"/></svg>
            </button>
            <div id="mobileMenu" class="absolute right-6 top-14 md:hidden hidden bg-white text-gray-800 rounded-lg shadow-lg border w-56">
              <a href="secretario.php" class="block px-4 py-2 hover:bg-gray-100">Início</a>
              <a href="prioridades.php" class="block px-4 py-2 hover:bg-gray-100">Prioridades</a>
              <a href="relatorios_secretario.php" class="block px-4 py-2 hover:bg-gray-100">Relatórios</a>
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

    <main class="container mx-auto px-6 py-8 max-w-6xl">
        <a href="secretario.php" class="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-gray-100 text-gray-700 hover:bg-gray-200 mb-6">
            Voltar
        </a>

        <h1 class="text-3xl font-bold text-gray-900 mb-2">Relatórios</h1>

        <div class="grid md:grid-cols-3 gap-6 mt-6">
            <!-- Card: Ocorrências -->
            <div class="border rounded-xl p-8 text-center hover:shadow transition">
                <div class="inline-flex items-center justify-center w-16 h-16 bg-green-50 text-green-600 rounded-xl mb-4">🗂</div>
                <h3 class="text-lg font-semibold text-gray-900 mb-2">OCORRÊNCIAS</h3>
                <p class="text-gray-600 mb-4">Exportar as ocorrências atribuídas</p>
                <div class="flex items-center justify-center gap-3">
                  <a href="?download=ocorrencias" class="inline-flex items-center gap-2 px-5 py-2 rounded-md bg-green-600 text-white hover:bg-green-700">CSV</a>
                  <a href="?pdf=ocorrencias" class="inline-flex items-center gap-2 px-5 py-2 rounded-md bg-gray-800 text-white hover:bg-gray-900">PDF</a>
                </div>
            </div>

            <!-- Card: Pesquisas -->
            <div class="border rounded-xl p-8 text-center hover:shadow transition">
                <div class="inline-flex items-center justify-center w-16 h-16 bg-green-50 text-green-600 rounded-xl mb-4">📄</div>
                <h3 class="text-lg font-semibold text-gray-900 mb-2">PESQUISAS</h3>
                <p class="text-gray-600 mb-4">Exportar pesquisas relacionadas</p>
                <div class="flex items-center justify-center gap-3">
                  <a href="?download=pesquisas" class="inline-flex items-center gap-2 px-5 py-2 rounded-md bg-green-600 text-white hover:bg-green-700">CSV</a>
                  <a href="?pdf=pesquisas" class="inline-flex items-center gap-2 px-5 py-2 rounded-md bg-gray-800 text-white hover:bg-gray-900">PDF</a>
                </div>
            </div>

            <!-- Card: Prioridades -->
            <div class="border rounded-xl p-8 text-center hover:shadow transition">
                <div class="inline-flex items-center justify-center w-16 h-16 bg-green-50 text-green-600 rounded-xl mb-4">📊</div>
                <h3 class="text-lg font-semibold text-gray-900 mb-2">PRIORIDADES</h3>
                <p class="text-gray-600 mb-4">Exportar prioridades das suas ocorrências</p>
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