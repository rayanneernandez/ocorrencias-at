<?php
session_start();
require_once __DIR__ . '/../includes/db.php';
$pdo = get_pdo();

$usuarioId = intval($_SESSION['usuario_id'] ?? 0);
if (!$usuarioId) { header('Location: login_cadastro.php'); exit; }

$sid = trim($_GET['sid'] ?? '');
$pid = intval($_GET['id'] ?? 0);

if (!$pid && !$sid) { header('Location: dashboard.php'); exit; }

$pesquisa = null; $perguntas = []; $respostas = [];
$prioridadesOrder = [];

// Bloco especial: detalhes da pesquisa de ordenação (prioridades)
if ($sid === 'prioridades') {
  // Mapa de rótulos (id do front -> nome)
  $catLabels = [
    'saude' => 'Saúde',
    'inovacao' => 'Inovação',
    'mobilidade' => 'Mobilidade',
    'politicas' => 'Políticas Públicas',
    'riscos' => 'Riscos Urbanos',
    'sustentabilidade' => 'Sustentabilidade',
    'planejamento' => 'Planejamento Urbano',
    'educacao' => 'Educação',
    'meio' => 'Meio Ambiente',
    'infraestrutura' => 'Infraestrutura da Cidade',
    'seguranca' => 'Segurança Pública',
    'energias' => 'Energias Inteligentes',
  ];
  // Mapa: coluna do banco -> id do front
  $colToCat = [
    'saude' => 'saude',
    'inovacao' => 'inovacao',
    'mobilidade' => 'mobilidade',
    'politicasPublicas' => 'politicas',
    'riscosUrbanos' => 'riscos',
    'sustentabilidade' => 'sustentabilidade',
    'planejamentoUrbano' => 'planejamento',
    'educacao' => 'educacao',
    'meioAmbiente' => 'meio',
    'infraestruturaCidade' => 'infraestrutura',
    'segurancaPublica' => 'seguranca',
    'energiasInteligentes' => 'energias',
  ];

  try {
    $stmt = $pdo->prepare("SELECT ".implode(',', array_keys($colToCat))." FROM usuarios_prioridades WHERE usuario_id = ? LIMIT 1");
    $stmt->execute([$usuarioId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    // pares: idCategoriaDoFront => ranking (1..N)
    $pairs = [];
    foreach ($colToCat as $col => $catId) {
      if (isset($row[$col])) {
        $r = intval($row[$col]);
        if ($r > 0) { $pairs[$catId] = $r; }
      }
    }

    if ($pairs) {
      asort($pairs, SORT_NUMERIC); // menor número = maior prioridade
      foreach (array_keys($pairs) as $cid) {
        $prioridadesOrder[] = $catLabels[$cid] ?? ucfirst($cid);
      }
    }
    // Define metadados básicos para o cabeçalho
    $pesquisa = ['titulo' => 'Pesquisa de Prioridades', 'descricao' => 'Sua ordenação de prioridades'];
  } catch (Throwable $_) {}
}

// Somente carrega perguntas/respostas do banco quando não é o caso de prioridades
if ($sid !== 'prioridades' && $pid) {
  try {
    $stmt = $pdo->prepare("SELECT id, titulo, descricao FROM pesquisa_meta WHERE id = ?");
    $stmt->execute([$pid]);
    $pesquisa = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT id, ordem, tipo, texto, opcoes_json, obrigatoria FROM pesquisa_perguntas WHERE pesquisa_id = ? ORDER BY ordem ASC");
    $stmt->execute([$pid]);
    $perguntas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT pergunta_id, resposta_json FROM pesquisa_respostas WHERE pesquisa_id = ? AND usuario_id = ?");
    $stmt->execute([$pid, $usuarioId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
      $respostas[intval($r['pergunta_id'])] = json_decode($r['resposta_json'], true);
    }
  } catch (Throwable $_) {}
}

?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>RADCI - Detalhes da Pesquisa</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-muted/10 min-h-screen">
  <header class="bg-white shadow sticky top-0 z-10">
    <div class="container mx-auto px-4 py-4 flex items-center gap-2">
      <button onclick="window.location.href='dashboard.php'" class="flex items-center text-sm font-medium text-gray-700 hover:text-green-600">
        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
        Voltar
      </button>
      <h1 class="text-xl font-bold text-gray-800 ml-4"><?= htmlspecialchars($pesquisa['titulo'] ?? 'Pesquisa') ?></h1>
    </div>
  </header>

  <main class="container mx-auto px-4 py-8 max-w-3xl">
    <?php if (!empty($pesquisa['descricao'])): ?>
      <p class="text-gray-600 mb-6"><?= htmlspecialchars($pesquisa['descricao']) ?></p>
    <?php endif; ?>

    <?php if ($sid === 'prioridades'): ?>
      <?php if (!empty($prioridadesOrder)): ?>
        <article class="rounded-lg border border-gray-200 p-4">
          <div class="text-sm font-semibold text-gray-900 mb-2">Ordenação definida</div>
          <ol class="list-decimal ml-5 text-sm text-gray-700">
            <?php foreach ($prioridadesOrder as $i => $label): ?>
              <li><?= htmlspecialchars($label) ?></li>
            <?php endforeach; ?>
          </ol>
          <div class="text-xs text-gray-500 mt-3">Visualização somente leitura</div>
          <div class="mt-4">
            <a href="prioridades.php?readonly=1" class="inline-block bg-gray-100 text-gray-700 px-3 py-2 rounded-md hover:bg-gray-200 text-sm font-medium">Abrir visualização</a>
          </div>
        </article>
      <?php else: ?>
        <div class="rounded-lg border border-gray-200 p-4 text-gray-600">Sem resposta encontrada para prioridades.</div>
      <?php endif; ?>
    <?php else: ?>
      <?php if (empty($perguntas)): ?>
        <div class="rounded-lg border border-gray-200 p-4 text-gray-600">Nenhuma pergunta encontrada para esta pesquisa.</div>
      <?php else: ?>
        <div class="space-y-5">
          <?php foreach ($perguntas as $q): 
            $pid   = intval($q['id']);
            $tipo  = strtolower(trim($q['tipo']));
            $texto = $q['texto'];
            $opcoes = json_decode($q['opcoes_json'] ?? '[]', true) ?: [];
            $resp   = $respostas[$pid] ?? null;
          ?>
            <article class="rounded-lg border border-gray-200 p-4">
              <div class="text-sm font-semibold text-gray-900 mb-2"><?= htmlspecialchars($texto) ?></div>
              <div class="text-sm text-gray-700">
                <?php if ($tipo === 'multipla' || $tipo === 'multiple' || $tipo === 'múltipla escolha'): ?>
                  <?php if (is_array($resp)): ?>
                    <ul class="list-disc ml-5">
                      <?php foreach ($resp as $opt): ?>
                        <li><?= htmlspecialchars($opt) ?></li>
                      <?php endforeach; ?>
                    </ul>
                  <?php else: ?>
                    <span class="text-gray-400">Sem resposta</span>
                  <?php endif; ?>
                <?php elseif ($tipo === 'nota' || $tipo === 'nota 1-5' || $tipo === 'rating'): ?>
                  <?php if ($resp !== null): ?>
                    <span class="font-medium">Nota:</span> <?= htmlspecialchars($resp) ?>
                  <?php else: ?>
                    <span class="text-gray-400">Sem resposta</span>
                  <?php endif; ?>
                <?php elseif ($tipo === 'prioridades' || $tipo === 'ordenar prioridades'): ?>
                  <?php if (is_array($resp)): ?>
                    <ol class="list-decimal ml-5">
                      <?php foreach ($resp as $opt): ?>
                        <li><?= htmlspecialchars($opt) ?></li>
                      <?php endforeach; ?>
                    </ol>
                  <?php else: ?>
                    <span class="text-gray-400">Sem resposta</span>
                  <?php endif; ?>
                <?php else: ?>
                  <?php if ($resp !== null): ?>
                    <span><?= is_scalar($resp) ? htmlspecialchars($resp) : htmlspecialchars(json_encode($resp, JSON_UNESCAPED_UNICODE)) ?></span>
                  <?php else: ?>
                    <span class="text-gray-400">Sem resposta</span>
                  <?php endif; ?>
                <?php endif; ?>
              </div>
              <div class="text-xs text-gray-500 mt-3">Edição bloqueada</div>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </main>

  <?php include __DIR__ . '/../includes/mobile_nav.php'; ?>
</body>
</html>