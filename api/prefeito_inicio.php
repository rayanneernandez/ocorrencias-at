<?php
session_start();
require_once __DIR__ . '/../includes/db.php';

$pdo = get_pdo();
$prefeitoId = (int)($_SESSION['usuario_id'] ?? 0);

// KPIs
$totalSecretarios = 0;
$totalOcorrencias = 0;
try { $totalSecretarios = (int)$pdo->query("SELECT COUNT(*) FROM usuarios WHERE perfil = 3")->fetchColumn(); } catch (Throwable $_) {}
try { $totalOcorrencias = (int)$pdo->query("SELECT COUNT(*) FROM ocorrencias")->fetchColumn(); } catch (Throwable $_) {}

// Ações (autorizar secretário e atualizar status)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'assign_secretario') {
        $numero       = trim($_POST['numero'] ?? '');
        $secretarioId = intval($_POST['secretario_id'] ?? 0);

        if ($numero !== '' && $secretarioId > 0) {
            try {
                // Descobre a ocorrência por número
                $stmt = $pdo->prepare("SELECT id FROM ocorrencias WHERE numero = ? LIMIT 1");
                $stmt->execute([$numero]);
                $ocId = (int)$stmt->fetchColumn();

                if ($ocId > 0) {
                    // Descobre estrutura disponível para salvar o responsável
                    $hasSecIdCol    = $pdo->query("SHOW COLUMNS FROM ocorrencias LIKE 'secretario_id'")->rowCount() > 0;
                    $hasAssignedCol = !$hasSecIdCol && $pdo->query("SHOW COLUMNS FROM ocorrencias LIKE 'assigned_secretario_id'")->rowCount() > 0;

                    if ($hasSecIdCol) {
                        // Atualiza responsável direto na ocorrência
                        $pdo->prepare("UPDATE ocorrencias SET secretario_id = ? WHERE id = ? LIMIT 1")->execute([$secretarioId, $ocId]);
                    } elseif ($hasAssignedCol) {
                        // Usa coluna alternativa, se existir
                        $pdo->prepare("UPDATE ocorrencias SET assigned_secretario_id = ? WHERE id = ? LIMIT 1")->execute([$secretarioId, $ocId]);
                    } else {
                        // Fallback: usa tabela de atribuições
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
                        $stmtIns = $pdo->prepare("
                            INSERT INTO ocorrencias_atribuicoes (ocorrencia_id, secretario_id, perfil_id)
                            VALUES (?, ?, ?)
                            ON DUPLICATE KEY UPDATE data_atribuicao = CURRENT_TIMESTAMP, perfil_id = VALUES(perfil_id)
                        ");
                        $stmtIns->execute([$ocId, $secretarioId, $prefeitoId]);
                    }

                    // Atualiza status para 'encaminhada'
                    $pdo->prepare("UPDATE ocorrencias SET status = 'encaminhada' WHERE id = ? LIMIT 1")->execute([$ocId]);

                    // Nome do secretário para feedback
                    $secNome = '';
                    try {
                        $stmtSec = $pdo->prepare("SELECT nome FROM usuarios WHERE id = ? LIMIT 1");
                        $stmtSec->execute([$secretarioId]);
                        $secNome = (string)($stmtSec->fetchColumn() ?: '');
                    } catch (Throwable $_) {}

                    $_SESSION['flash_success'] = "Ocorrência encaminhada para " . ($secNome ?: 'o secretário selecionado') . " e o status foi definido como ‘encaminhada’.";
                } else {
                    $_SESSION['flash_error'] = 'Ocorrência não encontrada para autorizar.';
                }
            } catch (Throwable $e) {
                $_SESSION['flash_error'] = 'Erro ao autorizar ao secretário.';
            }
        }
        header('Location: prefeito_inicio.php');
        exit;
    } elseif ($action === 'update_status') {
        $numero = trim($_POST['numero'] ?? '');
        $status = trim($_POST['status'] ?? '');

        // Usa exatamente os valores do ENUM do banco
        $allowed = ['aberta','em_analise','encaminhada','resolvida','cancelada'];

        if ($numero !== '' && in_array($status, $allowed, true)) {
            try {
                $stmt = $pdo->prepare("UPDATE ocorrencias SET status = ? WHERE numero = ? LIMIT 1");
                $stmt->execute([$status, $numero]);
                $_SESSION['flash_success'] = "Status atualizado para '{$status}'.";
            } catch (Throwable $e) {
                $_SESSION['flash_error'] = 'Erro ao atualizar status.';
            }
        } else {
            $_SESSION['flash_error'] = 'Selecione um status válido antes de atualizar.';
        }

        header('Location: prefeito_inicio.php');
        exit;
    }
}

// Lista de secretários para autorização
$secretariosList = [];
try {
    // Carrega todos os secretários (perfil 3) para garantir opções no dropdown
    $secretariosList = $pdo->query("
        SELECT id, nome
          FROM usuarios
         WHERE perfil = 3
         ORDER BY nome ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $_) {}

// Verifica existência das tabelas/colunas de vínculo
$hasSpTable   = $pdo->query("SHOW TABLES LIKE 'secretarios_perfis'")->rowCount() > 0;
$hasSpSecCol  = $hasSpTable && $pdo->query("SHOW COLUMNS FROM secretarios_perfis LIKE 'secretario_id'")->rowCount() > 0;
$hasSpPerfCol = $hasSpTable && $pdo->query("SHOW COLUMNS FROM secretarios_perfis LIKE 'perfil_id'")->rowCount() > 0;

if ($hasSpSecCol && $hasSpPerfCol && $prefeitoId > 0) {
    // Secretários vinculados ao prefeito logado
    $stmt = $pdo->prepare("
        SELECT u.id, u.nome
          FROM usuarios u
          INNER JOIN secretarios_perfis sp ON sp.secretario_id = u.id
         WHERE u.perfil = 3
           AND sp.perfil_id = ?
         ORDER BY u.nome ASC
    ");
    $stmt->execute([$prefeitoId]);
    $secretariosList = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Fallback: quando não houver vínculo ou lista vazia
if (empty($secretariosList)) {
    try {
        $secretariosList = $pdo->query("
            SELECT id, nome
              FROM usuarios
             WHERE perfil = 3
             ORDER BY nome ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $_) {}
}

// Contagem de ocorrências não autorizadas (somente canceladas)
$naoAutorizadas = 0;
try {
    $naoAutorizadas = (int)$pdo->query("
        SELECT COUNT(*)
          FROM ocorrencias
         WHERE TRIM(LOWER(status)) = 'cancelada'
    ")->fetchColumn();
} catch (Throwable $_) {}
try {
    $hasSecIdCol    = $pdo->query("SHOW COLUMNS FROM ocorrencias LIKE 'secretario_id'")->rowCount() > 0;
    $hasAssignedCol = !$hasSecIdCol && $pdo->query("SHOW COLUMNS FROM ocorrencias LIKE 'assigned_secretario_id'")->rowCount() > 0;
    $hasMapTable    = $pdo->query("SHOW TABLES LIKE 'ocorrencias_atribuicoes'")->rowCount() > 0;

    // 1) Canceladas sempre contam
    $qtCanceladas = (int)$pdo->query("SELECT COUNT(*) FROM ocorrencias WHERE status = 'cancelada'")->fetchColumn();

    // 2) Em análise sem atribuição
    $qtEmAnaliseSemAtrib = 0;
    if ($hasSecIdCol) {
        $qtEmAnaliseSemAtrib = (int)$pdo->query("SELECT COUNT(*) FROM ocorrencias WHERE status = 'em_analise' AND (secretario_id IS NULL OR secretario_id = 0)")->fetchColumn();
    } elseif ($hasAssignedCol) {
        $qtEmAnaliseSemAtrib = (int)$pdo->query("SELECT COUNT(*) FROM ocorrencias WHERE status = 'em_analise' AND (assigned_secretario_id IS NULL OR assigned_secretario_id = 0)")->fetchColumn();
    } elseif ($hasMapTable) {
        $qtEmAnaliseSemAtrib = (int)$pdo->query("
            SELECT COUNT(*)
              FROM ocorrencias o
             WHERE o.status = 'em_analise'
               AND NOT EXISTS (SELECT 1 FROM ocorrencias_atribuicoes oa WHERE oa.ocorrencia_id = o.id)
        ")->fetchColumn();
    } else {
        // Sem estrutura de atribuição, considera todas em análise
        $qtEmAnaliseSemAtrib = (int)$pdo->query("SELECT COUNT(*) FROM ocorrencias WHERE status = 'em_analise'")->fetchColumn();
    }

    $naoAutorizadas = $qtCanceladas + $qtEmAnaliseSemAtrib;
} catch (Throwable $_) {}

// Últimas ocorrências (com endereço e CEP)
$ocorrencias = [];
try {
    // Filtros (GET)
    $fStatus = trim($_GET['f_status'] ?? '');
    $fSecId  = intval($_GET['f_secretario'] ?? 0);
    
    // Últimas ocorrências + secretário vinculado
    $ocorrencias = [];
    try {
        // Detecta estrutura de atribuição para JOIN
        $hasSecIdCol    = $pdo->query("SHOW COLUMNS FROM ocorrencias LIKE 'secretario_id'")->rowCount() > 0;
        $hasAssignedCol = !$hasSecIdCol && $pdo->query("SHOW COLUMNS FROM ocorrencias LIKE 'assigned_secretario_id'")->rowCount() > 0;
        $hasMapTable    = $pdo->query("SHOW TABLES LIKE 'ocorrencias_atribuicoes'")->rowCount() > 0;
    
        $joinSql   = '';
        $selectSec = 'NULL AS secretario_nome';
        $groupSql  = ''; // garante variável definida para interpolação
    
        if ($hasSecIdCol) {
            $joinSql   = 'LEFT JOIN usuarios u ON u.id = o.secretario_id';
            $selectSec = 'u.nome AS secretario_nome';
        } elseif ($hasAssignedCol) {
            $joinSql   = 'LEFT JOIN usuarios u ON u.id = o.assigned_secretario_id';
            $selectSec = 'u.nome AS secretario_nome';
        } elseif ($hasMapTable) {
            // Usa agregação para pegar algum secretário vinculado
            $joinSql   = 'LEFT JOIN ocorrencias_atribuicoes oa ON oa.ocorrencia_id = o.id LEFT JOIN usuarios u ON u.id = oa.secretario_id';
            $selectSec = 'MAX(u.nome) AS secretario_nome';
            $groupSql  = 'GROUP BY o.id';
        }
    
        $where  = [];
        $params = [];
    
        if ($fStatus !== '') {
            $where[]   = 'o.status = ?';
            $params[]  = $fStatus;
        }
        if ($fSecId > 0) {
            if ($hasSecIdCol) {
                $where[] = 'o.secretario_id = ?';
            } elseif ($hasAssignedCol) {
                $where[] = 'o.assigned_secretario_id = ?';
            } else {
                $where[] = 'oa.secretario_id = ?';
            }
            $params[] = $fSecId;
        }
        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
    
        $sql = "
          SELECT o.numero, o.tipo, o.status,
                 DATE_FORMAT(o.data_criacao, '%d/%m/%Y %H:%i') AS criada_em,
                 o.endereco, o.cep, {$selectSec}
            FROM ocorrencias o
            {$joinSql}
            {$whereSql}
            {$groupSql}
        ORDER BY (o.status IN ('em_analise','cancelada')) DESC, o.data_criacao DESC
           LIMIT 10
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $ocorrencias = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
        // Resolve bairro por CEP/endereço
        $bairroCache = [];
        foreach ($ocorrencias as &$row) {
            $cepDigits = preg_replace('/\D/', '', $row['cep'] ?? '');
            $bairro = '';
            if ($cepDigits && !isset($bairroCache[$cepDigits])) {
                $url = "https://viacep.com.br/ws/{$cepDigits}/json/";
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 4,
                    CURLOPT_CONNECTTIMEOUT => 3,
                    CURLOPT_USERAGENT => 'RADCI/1.0 (prefeito_inicio.php)'
                ]);
                $resp = curl_exec($ch);
                curl_close($ch);
                if ($resp) {
                    $data = json_decode($resp, true);
                    if (is_array($data) && empty($data['erro'])) {
                        $bairroCache[$cepDigits] = trim($data['bairro'] ?? '');
                    }
                }
            }
            $bairro = $bairroCache[$cepDigits] ?? '';
            if ($bairro === '') {
                // Tenta extrair do endereço: "Rua X, Bairro Y - Cidade, UF"
                $end = trim($row['endereco'] ?? '');
                if ($end && preg_match('/,\\s*(.*?)\\s*-\\s*/u', $end, $m)) {
                    $bairro = trim($m[1] ?? '');
                }
            }
            $row['bairro_resolved'] = $bairro;
        }
        unset($row);
    } catch (Throwable $_) {}
} catch (Throwable $_) {}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="UTF-8" />
  <title>Início (Prefeito) - RADCI</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-white min-h-screen">
  <header class="bg-green-700 text-white">
    <div class="container mx-auto px-6 py-4 flex items-center justify-between relative">
      <img src="/radci/assets/images/logo.png" alt="RADCI" class="h-10 w-auto" />
      <nav class="hidden md:flex items-center gap-6">
        <a href="prefeito_inicio.php" class="hover:underline font-semibold">Início</a>
        <a href="gestor_secretarios.php" class="hover:underline">Meus Secretários</a>
        <a href="ocorrencias.php" class="hover:underline">Ocorrências</a>
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

  <!-- Aumenta largura útil e reduz margens -->
  <main class="mx-auto w-full max-w-[1400px] px-6 py-6">
    <?php if (!empty($_SESSION['flash_success'])): ?>
      <div id="flash-success" class="mb-4 rounded-lg bg-green-50 border border-green-200 text-green-800 px-4 py-3">
        <?= htmlspecialchars($_SESSION['flash_success']) ?>
      </div>
      <?php $_SESSION['flash_success'] = null; ?>
    <?php endif; ?>
    <?php if (!empty($_SESSION['flash_error'])): ?>
      <div id="flash-error" class="mb-4 rounded-lg bg-red-50 border border-red-200 text-red-800 px-4 py-3">
        <?= htmlspecialchars($_SESSION['flash_error']) ?>
      </div>
      <?php $_SESSION['flash_error'] = null; ?>
    <?php endif; ?>
  
    <script>
      setTimeout(() => {
        document.getElementById('flash-success')?.remove();
        document.getElementById('flash-error')?.remove();
      }, 3000);
    </script>

    <!-- KPIs (restaurados) -->
    <section class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
      <div class="rounded-xl border bg-white p-6">
        <div class="text-sm text-gray-600">Total de Secretários</div>
        <div class="mt-1 text-3xl font-semibold text-gray-900"><?= (int)$totalSecretarios ?></div>
      </div>
      <div class="rounded-xl border bg-white p-6">
        <div class="text-sm text-gray-600">Total de Ocorrências</div>
        <div class="mt-1 text-3xl font-semibold text-gray-900"><?= (int)$totalOcorrencias ?></div>
      </div>
      <div class="rounded-xl border bg-white p-6">
        <div class="text-sm text-gray-600">Ocorrências não encaminhadas</div>
        <div class="mt-1 text-3xl font-semibold text-gray-900"><?= (int)$naoAutorizadas ?></div>
      </div>
    </section>
  
    <!-- Filtros -->
    <div class="bg-white rounded-xl shadow overflow-hidden">
      <div class="px-6 py-4 border-b flex items-center justify-between">
        <h3 class="text-lg font-semibold text-gray-900">Últimas Ocorrências</h3>
        <form method="GET" id="filters-form" class="flex gap-2">
          <select name="f_status" class="border rounded px-3 py-2 text-sm" onchange="this.form.submit()">
            <option value="">Status: todos</option>
            <option value="aberta" <?= ($fStatus==='aberta'?'selected':'') ?>>Aberta</option>
            <option value="em_analise" <?= ($fStatus==='em_analise'?'selected':'') ?>>Em análise</option>
            <option value="encaminhada" <?= ($fStatus==='encaminhada'?'selected':'') ?>>Encaminhada</option>
            <option value="resolvida" <?= ($fStatus==='resolvida'?'selected':'') ?>>Resolvida</option>
            <option value="cancelada" <?= ($fStatus==='cancelada'?'selected':'') ?>>Cancelada</option>
          </select>
          <select name="f_secretario" class="border rounded px-3 py-2 text-sm" onchange="this.form.submit()">
            <option value="0">Secretário: todos</option>
            <?php foreach ($secretariosList as $s): ?>
              <option value="<?= (int)$s['id'] ?>" <?= ($fSecId===(int)$s['id']?'selected':'') ?>><?= htmlspecialchars($s['nome']) ?></option>
            <?php endforeach; ?>
          </select>
        </form>
      </div>
      <div class="p-6">
        <?php if (empty($ocorrencias)): ?>
          <p class="text-gray-600">Nenhuma ocorrência encontrada.</p>
        <?php else: ?>
          <div class="overflow-x-auto">
            <table class="min-w-full text-left">
              <thead class="bg-gray-50 text-gray-700">
                <tr>
                  <th class="px-4 py-2 w-32">Id</th>
                  <th class="px-4 py-2 w-40">Tipo</th>
                  <th class="px-4 py-2 w-[520px]">Endereço</th>
                  <th class="px-4 py-2 w-40">Status</th>
                  <th class="px-4 py-2 w-56">Responsável</th>
                  <th class="px-4 py-2 w-48">Criada em</th>
                  <th class="px-4 py-2 w-[380px]">Ações</th>
                </tr>
              </thead>
              <tbody class="text-gray-800">
                <?php foreach ($ocorrencias as $row): ?>
                  <tr class="border-t align-top">
                    <td class="px-4 py-2"><?= htmlspecialchars($row['numero']) ?></td>
                    <td class="px-4 py-2">
                      <div class="font-medium"><?= htmlspecialchars($row['tipo']) ?></div>
                    </td>
                    <td class="px-4 py-2 break-words">
                      <div class="text-sm font-medium text-gray-800"><?= htmlspecialchars($row['endereco'] ?? '') ?></div>
                      <?php if (!empty($row['bairro_resolved'])): ?>
                        <div class="text-xs text-gray-600">Bairro: <?= htmlspecialchars($row['bairro_resolved']) ?></div>
                      <?php endif; ?>
                    </td>
                    <td class="px-4 py-2"><?= htmlspecialchars($row['status']) ?></td>
                    <td class="px-4 py-2">
                      <?= !empty($row['secretario_nome'])
                           ? htmlspecialchars($row['secretario_nome'])
                           : 'Não direcionada' ?>
                    </td>
                    <td class="px-4 py-2"><?= htmlspecialchars($row['criada_em'] ?? $row['data_criacao'] ?? '') ?></td>
                    <td class="px-4 py-3">
                        <?php
                        // Sugestão de secretário conforme perfis/permissões
                        $recommendedSecId = 0;
                        try {
                            $stmtMap = $pdo->query("
                                SELECT sp.secretario_id, COALESCE(gp.config, '') AS cfg
                                  FROM secretarios_perfis sp
                                  LEFT JOIN gestor_perfis gp ON gp.id = sp.perfil_id
                            ");
                            $mapRows = $stmtMap->fetchAll(PDO::FETCH_ASSOC);
                            foreach ($mapRows as $mr) {
                                $cfg = [];
                                try { $cfg = $mr['cfg'] ? json_decode($mr['cfg'], true) : []; } catch (Throwable $_) { $cfg = []; }
                                if (!is_array($cfg)) $cfg = [];
                                $cats = [];
                                foreach (['reclamacao','pesquisas','sugestoes','elogios'] as $k) {
                                    if (isset($cfg[$k]) && is_array($cfg[$k])) {
                                        $cats = array_merge($cats, $cfg[$k]);
                                    }
                                }
                                $cats = array_map(fn($v) => strtolower(trim((string)$v)), $cats);
                                if (in_array(strtolower(trim($row['tipo'] ?? '')), $cats, true)) {
                                    $recommendedSecId = (int)$mr['secretario_id'];
                                    break;
                                }
                            }
                        } catch (Throwable $_) {}
                        ?>
                        <!-- Encaminhar para secretário: envia automaticamente ao trocar -->
                        <form method="POST" action="prefeito_inicio.php" class="mb-2">
                            <input type="hidden" name="action" value="assign_secretario">
                            <input type="hidden" name="numero" value="<?= htmlspecialchars($row['numero']) ?>">
                            <select name="secretario_id" class="w-full border rounded-md px-3 py-2 text-sm"
                                    onchange="this.form.submit()">
                                <option value=""><?= htmlspecialchars('Selecione o secretário') ?></option>
                                <?php foreach ($secretariosList as $sec): ?>
                                    <option value="<?= (int)$sec['id'] ?>" <?= ((int)$sec['id'] === $recommendedSecId ? 'selected' : '') ?>>
                                        <?= htmlspecialchars($sec['nome']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                        <!-- Atualizar status: envia automaticamente ao trocar -->
                        <form method="POST" action="prefeito_inicio.php">
                            <input type="hidden" name="action" value="update_status">
                            <input type="hidden" name="numero" value="<?= htmlspecialchars($row['numero']) ?>">
                            <select name="status" class="w-full border rounded-md px-3 py-2 text-sm"
                                    onchange="this.form.submit()">
                                <option value=""><?= htmlspecialchars('Selecione o status') ?></option>
                                <option value="em_analise"   <?= ($row['status'] ?? '') === 'em_analise'   ? 'selected' : '' ?>>Em análise</option>
                                <option value="encaminhada"  <?= ($row['status'] ?? '') === 'encaminhada'  ? 'selected' : '' ?>>Encaminhada</option>
                                <option value="resolvida"    <?= ($row['status'] ?? '') === 'resolvida'    ? 'selected' : '' ?>>Resolvida</option>
                                <option value="cancelada"    <?= ($row['status'] ?? '') === 'cancelada'    ? 'selected' : '' ?>>Cancelada</option>
                            </select>
                        </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <script>
      // Reforço JS: em alguns navegadores o onchange inline pode ser bloqueado
      document.querySelectorAll('#filters-form select').forEach((el) => {
        el.addEventListener('change', () => el.form.submit());
      });
    </script>
  </main>
</body>
</html>
