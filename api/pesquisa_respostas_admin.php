<?php
session_start();
require_once __DIR__ . '/../includes/db.php';
$pdo = get_pdo();

// Gate: apenas Admin RADCI (perfil 10)
$perfilAtual = intval($_SESSION['usuario_perfil'] ?? 0);
if ($perfilAtual !== 10) {
  header("Location: index.php");
  exit;
}

// Carrega lista de pesquisas a partir de 'pesquisa_meta' se existir; fallback para 'pesquisa'
$temMeta = false;
try { $temMeta = $pdo->query("SHOW TABLES LIKE 'pesquisa_meta'")->rowCount() > 0; } catch (Throwable $_) {}
$temLegacy = false;
try { $temLegacy = $pdo->query("SHOW TABLES LIKE 'pesquisa'")->rowCount() > 0; } catch (Throwable $_) {}

$pesquisas = [];
if ($temMeta) {
  try {
    $pesquisas = $pdo->query("SELECT id, titulo, created_at FROM pesquisa_meta ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
  } catch (Throwable $_) {}
} elseif ($temLegacy) {
  try {
    $pesquisas = $pdo->query("SELECT id, titulo FROM pesquisa ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
  } catch (Throwable $_) {}
}

$pid = intval($_GET['pid'] ?? 0);
$respostas = [];
// Inclui "Pesquisa de Prioridades" como opção virtual no dropdown, se a tabela existir
try {
  $temPrioridades = $pdo->query("SHOW TABLES LIKE 'usuarios_prioridades'")->rowCount() > 0;
  if ($temPrioridades) {
    array_unshift($pesquisas, ['id' => -1, 'titulo' => 'Pesquisa de Prioridades', 'created_at' => null]);
  }
} catch (Throwable $_) {}

// Fluxo: respostas de pesquisas normais
if ($pid > 0) {
  try {
    $stmt = $pdo->prepare("
      SELECT r.id, r.pesquisa_id, r.pergunta_id, r.usuario_id, r.perfil_usuario, r.cidade, r.uf, r.resposta_json, r.created_at,
             pp.texto AS pergunta_texto,
             u.nome AS usuario_nome, u.email AS usuario_email
        FROM pesquisa_respostas r
        LEFT JOIN pesquisa_perguntas pp ON pp.id = r.pergunta_id
        LEFT JOIN usuarios u ON u.id = r.usuario_id
       WHERE r.pesquisa_id = ?
       ORDER BY r.usuario_id, r.pergunta_id
    ");
    $stmt->execute([$pid]);
    $respostas = $stmt->fetchAll(PDO::FETCH_ASSOC);
  } catch (Throwable $_) {}
}

// Fluxo: respostas de prioridades (pid = -1)
if ($pid === -1) {
  try {
    $stmt = $pdo->query("
      SELECT
        u.id AS usuario_id,
        u.nome AS usuario_nome,
        u.email AS usuario_email,
        u.perfil AS perfil_usuario,
        u.municipio AS cidade,
        UPPER(u.uf) AS uf,
        up.saude, up.inovacao, up.mobilidade, up.politicasPublicas, up.riscosUrbanos, up.sustentabilidade,
        up.planejamentoUrbano, up.educacao, up.meioAmbiente, up.infraestruturaCidade, up.segurancaPublica, up.energiasInteligentes,
        DATE_FORMAT(up.data_registro, '%Y-%m-%d %H:%i:%s') AS created_at
      FROM usuarios_prioridades up
      LEFT JOIN usuarios u ON u.id = up.usuario_id
      ORDER BY up.data_registro DESC
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
      $labels = [
        'saude' => 'Saúde',
        'inovacao' => 'Inovação',
        'mobilidade' => 'Mobilidade',
        'politicasPublicas' => 'Políticas Públicas',
        'riscosUrbanos' => 'Riscos Urbanos',
        'sustentabilidade' => 'Sustentabilidade',
        'planejamentoUrbano' => 'Planejamento Urbano',
        'educacao' => 'Educação',
        'meioAmbiente' => 'Meio Ambiente',
        'infraestruturaCidade' => 'Infraestrutura da Cidade',
        'segurancaPublica' => 'Segurança Pública',
        'energiasInteligentes' => 'Energias Inteligentes',
      ];
      $ranking = [];
      foreach ($labels as $col => $label) {
        $val = isset($row[$col]) ? intval($row[$col]) : null;
        if (!is_null($val) && $val > 0) {
          $ranking[$label] = $val;
        }
      }
      asort($ranking); // menor número = maior prioridade
      $parts = [];
      foreach ($ranking as $label => $pos) {
        $parts[] = $pos . ') ' . $label;
      }
      $ordem = implode(', ', $parts);

      $respostas[] = [
        'usuario_id' => $row['usuario_id'],
        'usuario_nome' => $row['usuario_nome'],
        'usuario_email' => $row['usuario_email'],
        'perfil_usuario' => $row['perfil_usuario'],
        'cidade' => $row['cidade'],
        'uf' => $row['uf'],
        'pergunta_texto' => 'Ordenação das prioridades da cidade',
        'resposta_json' => $ordem,
        'created_at' => $row['created_at'],
      ];
    }
  } catch (Throwable $_) {}
}

// CSV
if (($pid > 0 || $pid === -1) && isset($_GET['download']) && $_GET['download'] === 'csv') {
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename='.($pid === -1 ? 'prioridades_respostas.csv' : ('pesquisa_'.$pid.'_respostas.csv')));
  $out = fopen('php://output', 'w');
  fputcsv($out, ['Respondente', 'Email', 'Perfil', 'Cidade', 'UF', 'Pergunta', 'Resposta', 'RespondidaEm'], ';');
  foreach ($respostas as $r) {
    $val = $r['resposta_json'];
    $parsed = null;
    try { $parsed = json_decode($val, true); } catch (Throwable $_) { $parsed = null; }
    if (is_array($parsed)) $val = implode(', ', array_map('strval', $parsed));
    elseif (is_scalar($parsed)) $val = strval($parsed);
    fputcsv($out, [
      $r['usuario_nome'] ?: ('Usuário #'.$r['usuario_id']),
      $r['usuario_email'] ?: '',
      intval($r['perfil_usuario']),
      $r['cidade'] ?: '',
      strtoupper($r['uf'] ?: ''),
      $r['pergunta_texto'] ?: ('Pergunta #'.($r['pergunta_id'] ?? '')),
      $val,
      $r['created_at'] ?: ''
    ], ';');
  }
  fclose($out);
  exit;
}

// Mapa de perfis
function perfilNome($p) {
  $map = [1=>'Cidadão', 2=>'Prefeito', 3=>'Secretário', 10=>'Admin'];
  return $map[intval($p)] ?? 'Desconhecido';
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8" />
  <title>Respostas de Pesquisas (Admin) - RADCI</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-white min-h-screen">
  <header class="bg-green-700 text-white">
    <div class="container mx-auto px-6 py-4 flex items-center justify-between relative">
      <img src="/radci/assets/images/logo.png" alt="RADCI" class="h-8 w-auto" />
      <nav class="hidden md:flex items-center gap-6">
        <a href="admin_inicio.php" class="hover:underline">Início</a>
        <a href="usuarios.php" class="hover:underline">Usuários</a>
        <a href="ocorrencias_admin.php" class="hover:underline">Ocorrências</a>
        <a href="relatorios_admin.php" class="hover:underline">Relatórios</a>
        <a href="criar_pesquisa.php" class="hover:underline">Criar Pesquisa</a>
        <a href="pesquisa_respostas_admin.php" class="hover:underline font-semibold">Respostas</a>
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
        <a href="pesquisa_respostas_admin.php" class="block px-4 py-2 hover:bg-gray-100 font-semibold">Respostas</a>
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

  <main class="container mx-auto px-6 py-8 max-w-[1400px]">
    <h1 class="text-2xl font-bold text-gray-900 mb-4">Respostas de Pesquisas</h1>

    <form method="get" class="flex flex-col md:flex-row md:items-center gap-3 mb-6">
      <label class="text-sm text-gray-600">Pesquisa</label>
      <select name="pid" class="border rounded px-3 py-2 w-full md:w-[420px]">
        <option value="0">Selecione uma pesquisa</option>
        <?php foreach ($pesquisas as $p): ?>
          <option value="<?= (int)$p['id'] ?>" <?= ($pid === (int)$p['id'] ? 'selected' : '') ?>>
            <?= htmlspecialchars(($p['titulo'] ?? 'Pesquisa').' '.(isset($p['created_at']) && $p['created_at'] ? '('.$p['created_at'].')' : '')) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <button class="px-4 py-2 rounded bg-green-600 text-white hover:bg-green-700 w-full md:w-auto">Filtrar</button>
      <?php if ($pid > 0 || $pid === -1): ?>
        <a class="px-4 py-2 rounded bg-gray-100 text-gray-800 hover:bg-gray-200 w-full md:w-auto text-center" href="?pid=<?= (int)$pid ?>&download=csv">Baixar CSV</a>
      <?php endif; ?>
    </form>

    <?php if ($pid === 0): ?>
      <p class="text-gray-600">Selecione uma pesquisa para ver as respostas.</p>
    <?php elseif (empty($respostas)): ?>
      <p class="text-gray-600">Nenhuma resposta encontrada para esta pesquisa.</p>
    <?php else: ?>
      <div class="overflow-x-auto bg-white rounded-xl shadow">
        <table class="min-w-full">
          <thead class="bg-gray-100">
            <tr>
              <th class="px-4 py-2 text-left text-sm text-gray-700">Respondente</th>
              <th class="px-4 py-2 text-left text-sm text-gray-700">Email</th>
              <th class="px-4 py-2 text-left text-sm text-gray-700">Perfil</th>
              <th class="px-4 py-2 text-left text-sm text-gray-700">Cidade/UF</th>
              <th class="px-4 py-2 text-left text-sm text-gray-700">Pergunta</th>
              <th class="px-4 py-2 text-left text-sm text-gray-700">Resposta</th>
              <th class="px-4 py-2 text-left text-sm text-gray-700">Respondida em</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($respostas as $r): ?>
              <?php
                $val = $r['resposta_json'];
                $parsed = null;
                try { $parsed = json_decode($val, true); } catch (Throwable $_) { $parsed = null; }
                if (is_array($parsed)) $val = implode(', ', array_map('strval', $parsed));
                elseif (is_scalar($parsed)) $val = strval($parsed);
              ?>
              <tr class="border-b">
                <td class="px-4 py-2"><?= htmlspecialchars($r['usuario_nome'] ?: ('Usuário #'.$r['usuario_id'])) ?></td>
                <td class="px-4 py-2"><?= htmlspecialchars($r['usuario_email'] ?: '') ?></td>
                <td class="px-4 py-2"><?= htmlspecialchars(perfilNome($r['perfil_usuario'])) ?></td>
                <td class="px-4 py-2"><?= htmlspecialchars(trim(($r['cidade'] ?: '').' / '.strtoupper($r['uf'] ?: ''))) ?></td>
                <td class="px-4 py-2"><?= htmlspecialchars($r['pergunta_texto'] ?: ('Pergunta #'.$r['pergunta_id'])) ?></td>
                <td class="px-4 py-2"><?= htmlspecialchars($val) ?></td>
                <td class="px-4 py-2"><?= htmlspecialchars($r['created_at'] ?: '') ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </main>
</body>
</html>