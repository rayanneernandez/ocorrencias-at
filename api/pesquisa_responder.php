<?php
session_start();
require_once __DIR__ . '/../includes/db.php';
$pdo = get_pdo();

$usuarioId = intval($_SESSION['usuario_id'] ?? 0);
$perfil    = intval($_SESSION['usuario_perfil'] ?? 0);
if (!$usuarioId) { header('Location: login_cadastro.php'); exit; }

// Resolve id pela query (id direto ou via sid)
$pid = intval($_GET['id'] ?? 0);
$sid = trim($_GET['sid'] ?? '');

if (!$pid && $sid !== '') {
  try {
    $stmt = $pdo->prepare("SELECT id FROM pesquisa_meta WHERE sid = ? LIMIT 1");
    $stmt->execute([$sid]);
    $pid = intval($stmt->fetchColumn() ?: 0);
  } catch (Throwable $_) {}
}
// Fallback: buscar na tabela 'pesquisa' se não existir em 'pesquisa_meta'
if (!$pid && $sid !== '') {
  try {
    $stmt = $pdo->prepare("SELECT id FROM pesquisa WHERE sid = ? LIMIT 1");
    $stmt->execute([$sid]);
    $pid = intval($stmt->fetchColumn() ?: 0);
  } catch (Throwable $_) {}
}

if (!$pid) {
  http_response_code(404);
  echo '<!DOCTYPE html><html lang="pt-br"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Pesquisa não encontrada</title><script src="https://cdn.tailwindcss.com"></script></head><body class="bg-muted/10 min-h-screen"><main class="container mx-auto px-4 py-6 max-w-2xl"><section class="bg-white rounded-2xl shadow p-6 border border-gray-200"><h2 class="text-lg font-bold text-gray-900">Pesquisa não encontrada</h2><p class="text-sm text-gray-600 mt-2">Esta pesquisa não está mais disponível.</p><div class="mt-4"><a href="dashboard.php" class="bg-green-600 text-white px-5 py-2 rounded hover:bg-green-700 inline-block">Voltar ao Dashboard</a></div></section></main></body></html>';
  exit;
}

// Carrega metadados e perguntas
$pesquisa = null; $perguntas = [];
try {
  $stmt = $pdo->prepare("SELECT id, titulo, descricao FROM pesquisa_meta WHERE id = ?");
  $stmt->execute([$pid]);
  $pesquisa = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$pesquisa) {
    // Fallback: metadados via tabela 'pesquisa'
    $stmt = $pdo->prepare("SELECT id, titulo, descricao FROM pesquisa WHERE id = ?");
    $stmt->execute([$pid]);
    $pesquisa = $stmt->fetch(PDO::FETCH_ASSOC);
  }

  $stmt = $pdo->prepare("SELECT id, ordem, tipo, texto, opcoes_json, obrigatoria FROM pesquisa_perguntas WHERE pesquisa_id = ? ORDER BY ordem ASC");
  $stmt->execute([$pid]);
  $perguntas = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $_) {}

// Processa envio das respostas
$erroEnvio = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $cidade = null; $uf = null;
  try {
    $stmt = $pdo->prepare("SELECT municipio, uf FROM usuarios WHERE id = ?");
    $stmt->execute([$usuarioId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $cidade = trim($row['municipio'] ?? '') ?: null;
    $uf = strtoupper(trim($row['uf'] ?? '')) ?: null;
  } catch (Throwable $_) {}

  try {
    $ins = $pdo->prepare("
      INSERT INTO pesquisa_respostas (pesquisa_id, pergunta_id, usuario_id, perfil_usuario, cidade, uf, resposta_json)
      VALUES (?, ?, ?, ?, ?, ?, ?)
    ");

    foreach ($perguntas as $q) {
      $qid  = intval($q['id']);
      $tipo = $q['tipo'];
      $val  = null;

      if ($tipo === 'multiple') {
        if (isset($_POST["q_$qid"])) {
          $v = $_POST["q_$qid"];
          $val = is_array($v) ? array_values($v) : $v;
        }
      } elseif ($tipo === 'nota') {
        $v = trim($_POST["q_$qid"] ?? '');
        if ($v !== '') { $val = intval($v); }
      } else {
        // Resposta em texto ou qualquer outro tipo padrão
        $v = isset($_POST["q_$qid"]) ? trim($_POST["q_$qid"]) : '';
        // Se obrigatória mas veio vazia, registra string vazia para não quebrar o envio
        if ($v !== '' || intval($q['obrigatoria'] ?? 0) === 1) { $val = $v; }
      }

      if ($val !== null) {
        $ins->execute([$pid, $qid, $usuarioId, $perfil, $cidade, $uf, json_encode($val, JSON_UNESCAPED_UNICODE)]);
      }
    }

    // Resolve o SID da pesquisa para marcar como respondida na sessão
    if ($sid === '') {
      try {
        $sidStmt = $pdo->prepare("SELECT sid FROM pesquisa_meta WHERE id = ? LIMIT 1");
        $sidStmt->execute([$pid]);
        $sidDb = trim($sidStmt->fetchColumn() ?: '');
        if ($sidDb === '') {
          // Fallback: SID na tabela 'pesquisa'
          $sidStmt = $pdo->prepare("SELECT sid FROM pesquisa WHERE id = ? LIMIT 1");
          $sidStmt->execute([$pid]);
          $sidDb = trim($sidStmt->fetchColumn() ?: '');
        }
        if ($sidDb !== '') { $sid = $sidDb; }
      } catch (Throwable $_) {}
    }
    $sidKey = $sid !== '' ? $sid : ('db_'.$pid);

    // Garante estrutura de sessão por usuário antes de indexar
    if (!isset($_SESSION['answered_surveys']) || !is_array($_SESSION['answered_surveys'])) {
      $_SESSION['answered_surveys'] = [];
    }
    if (!isset($_SESSION['answered_surveys'][$usuarioId]) || !is_array($_SESSION['answered_surveys'][$usuarioId])) {
      $_SESSION['answered_surveys'][$usuarioId] = [];
    }
    $_SESSION['answered_surveys'][$usuarioId][$sidKey] = true;

    header('Location: dashboard.php?answered=pesquisa');
    exit;
  } catch (Throwable $e) {
    $erroEnvio = 'Falha ao salvar respostas. Tente novamente.';
  }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Responder Pesquisa - RADCI</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-muted/10 min-h-screen">
  <header class="bg-white shadow sticky top-0 z-10">
    <div class="container mx-auto px-4 py-4 flex items-center gap-2">
      <a href="dashboard.php" class="text-sm text-gray-700 hover:text-green-600">&larr; Voltar</a>
      <h1 class="text-xl font-bold text-gray-800 ml-4">Responder Pesquisa</h1>
    </div>
  </header>

  <main class="container mx-auto px-4 py-6 max-w-4xl">
    <?php if ($erroEnvio): ?>
      <div class="bg-red-100 text-red-700 p-3 rounded mb-4"><?= htmlspecialchars($erroEnvio) ?></div>
    <?php endif; ?>

    <section class="bg-white rounded-2xl shadow p-6 border border-gray-200">
      <h2 class="text-lg font-bold text-gray-900"><?= htmlspecialchars($pesquisa['titulo'] ?? 'Pesquisa') ?></h2>
      <?php if (!empty($pesquisa['descricao'])): ?>
        <p class="text-sm text-gray-600 mb-4"><?= htmlspecialchars($pesquisa['descricao']) ?></p>
      <?php endif; ?>

      <form method="post" class="space-y-6">
        <?php foreach ($perguntas as $q): ?>
          <?php
            $qid = intval($q['id']);
            $tipo = $q['tipo'];
            $texto = $q['texto'];
            $opcoes = json_decode($q['opcoes_json'] ?? 'null', true);
          ?>
          <div class="border rounded-lg p-4">
            <p class="font-medium mb-3"><?= htmlspecialchars($texto) ?></p>

            <?php if ($tipo === 'multiple'): ?>
              <?php $opts = (array)($opcoes['options'] ?? []); ?>
              <?php foreach ($opts as $opt): ?>
                <label class="flex items-center gap-2 mb-2">
                  <input type="checkbox" name="q_<?= $qid ?>[]" value="<?= htmlspecialchars($opt) ?>" class="accent-green-600" />
                  <span><?= htmlspecialchars($opt) ?></span>
                </label>
              <?php endforeach; ?>
            <?php elseif ($tipo === 'nota'): ?>
              <input type="number" name="q_<?= $qid ?>" min="1" max="5" class="border rounded px-3 py-2 w-24" />
            <?php elseif ($tipo === 'prioridades'): ?>
              <div class="text-sm text-gray-600">Arraste para ordenar (1 = maior prioridade)</div>
              <ul class="space-y-2" id="prio_<?= $qid ?>">
                <?php foreach ((array)($opcoes['items'] ?? []) as $idx => $label): ?>
                  <li class="border rounded px-3 py-2 bg-gray-50" draggable="true" data-label="<?= htmlspecialchars($label) ?>"><?= htmlspecialchars($label) ?></li>
                <?php endforeach; ?>
              </ul>
              <input type="hidden" name="q_<?= $qid ?>" id="prio_input_<?= $qid ?>" />
              <script>
                (function(){
                  const ul = document.getElementById('prio_<?= $qid ?>');
                  const input = document.getElementById('prio_input_<?= $qid ?>');
                  let dragEl = null;
                  ul.querySelectorAll('li').forEach(li => {
                    li.addEventListener('dragstart', () => { dragEl = li; });
                    li.addEventListener('dragover', e => { e.preventDefault(); });
                    li.addEventListener('drop', () => {
                      if (dragEl && dragEl !== li) {
                        ul.insertBefore(dragEl, li);
                        input.value = JSON.stringify(Array.from(ul.querySelectorAll('li')).map(x => x.dataset.label));
                      }
                    });
                  });
                  input.value = JSON.stringify(Array.from(ul.querySelectorAll('li')).map(x => x.dataset.label));
                })();
              </script>
            <?php else: ?>
              <textarea name="q_<?= $qid ?>" rows="3" class="border rounded px-3 py-2 w-full"></textarea>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>

        <div class="pt-2">
          <button type="submit" class="bg-green-600 text-white px-5 py-2 rounded hover:bg-green-700">Enviar Respostas</button>
        </div>
      </form>
    </section>
  </main>
</body>
</html>