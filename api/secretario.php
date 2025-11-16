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

// Dados para o painel
$usuarioId   = intval($_SESSION['usuario_id']);
$usuarioNome = trim($_SESSION['usuario_nome'] ?? 'Secretário');

// Detecta como a atribuição é armazenada
$hasSecIdCol    = $pdo->query("SHOW COLUMNS FROM ocorrencias LIKE 'secretario_id'")->rowCount() > 0;
$hasAssignedCol = !$hasSecIdCol && $pdo->query("SHOW COLUMNS FROM ocorrencias LIKE 'assigned_secretario_id'")->rowCount() > 0;
$hasMapTable    = $pdo->query("SHOW TABLES LIKE 'ocorrencias_atribuicoes'")->rowCount() > 0;

// Atualização de status (secretário só altera o que está atribuído a ele)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_status') {
    $numero = trim($_POST['numero'] ?? '');
    $status = trim($_POST['status'] ?? '');
    $allowed = ['em_analise','encaminhada','resolvida','cancelada'];

    if ($numero !== '' && in_array($status, $allowed, true)) {
        try {
            $updated = 0;

            if ($hasSecIdCol) {
                $stmt = $pdo->prepare("UPDATE ocorrencias SET status = ? WHERE numero = ? AND secretario_id = ? LIMIT 1");
                $stmt->execute([$status, $numero, $usuarioId]);
                $updated = $stmt->rowCount();
            } elseif ($hasAssignedCol) {
                $stmt = $pdo->prepare("UPDATE ocorrencias SET status = ? WHERE numero = ? AND assigned_secretario_id = ? LIMIT 1");
                $stmt->execute([$status, $numero, $usuarioId]);
                $updated = $stmt->rowCount();
            } elseif ($hasMapTable) {
                $stmt = $pdo->prepare("
                    SELECT o.id
                      FROM ocorrencias o
                      JOIN ocorrencias_atribuicoes oa ON oa.ocorrencia_id = o.id
                     WHERE o.numero = ? AND oa.secretario_id = ?
                     LIMIT 1
                ");
                $stmt->execute([$numero, $usuarioId]);
                $ocId = (int)($stmt->fetchColumn() ?? 0);
                if ($ocId > 0) {
                    $stmt = $pdo->prepare("UPDATE ocorrencias SET status = ? WHERE id = ? LIMIT 1");
                    $stmt->execute([$status, $ocId]);
                    $updated = $stmt->rowCount();
                }
            } else {
                // Fallback (sem estrutura de atribuição): não recomenda, mas atualiza pelo número
                $stmt = $pdo->prepare("UPDATE ocorrencias SET status = ? WHERE numero = ? LIMIT 1");
                $stmt->execute([$status, $numero]);
                $updated = $stmt->rowCount();
            }

            $_SESSION['flash_success'] = $updated ? "Status atualizado para '{$status}'." : "Não foi possível atualizar: ocorrência não atribuída a você.";
        } catch (Throwable $e) {
            $_SESSION['flash_error'] = 'Erro ao atualizar status.';
        }

        header('Location: secretario.php');
        exit;
    } else {
        $_SESSION['flash_error'] = 'Selecione um status válido.';
        header('Location: secretario.php');
        exit;
    }
}

// KPIs: total atribuídas ao secretário, pendentes, concluídas
$totalAtribuidas      = 0;
$pendentesAtribuidas  = 0; // em_analise ou encaminhada
$concluidasAtribuidas = 0; // resolvida

try {
    if ($hasSecIdCol) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM ocorrencias WHERE secretario_id = ?");
        $stmt->execute([$usuarioId]);
        $totalAtribuidas = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM ocorrencias WHERE secretario_id = ? AND status IN ('em_analise','encaminhada')");
        $stmt->execute([$usuarioId]);
        $pendentesAtribuidas = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM ocorrencias WHERE secretario_id = ? AND status = 'resolvida'");
        $stmt->execute([$usuarioId]);
        $concluidasAtribuidas = (int)$stmt->fetchColumn();
    } elseif ($hasAssignedCol) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM ocorrencias WHERE assigned_secretario_id = ?");
        $stmt->execute([$usuarioId]);
        $totalAtribuidas = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM ocorrencias WHERE assigned_secretario_id = ? AND status IN ('em_analise','encaminhada')");
        $stmt->execute([$usuarioId]);
        $pendentesAtribuidas = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM ocorrencias WHERE assigned_secretario_id = ? AND status = 'resolvida'");
        $stmt->execute([$usuarioId]);
        $concluidasAtribuidas = (int)$stmt->fetchColumn();
    } elseif ($hasMapTable) {
        // Quando usamos mapeamento, ignorar ocorrências que já têm responsável direto nas colunas de ocorrencias
        $extraGuard = '';
        if ($hasSecIdCol) {
            $extraGuard .= " AND (o.secretario_id IS NULL OR o.secretario_id = 0)";
        }
        if ($hasAssignedCol) {
            $extraGuard .= " AND (o.assigned_secretario_id IS NULL OR o.assigned_secretario_id = 0)";
        }

        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT o.id)
              FROM ocorrencias o
              JOIN ocorrencias_atribuicoes oa ON oa.ocorrencia_id = o.id
             WHERE oa.secretario_id = ?{$extraGuard}
        ");
        $stmt->execute([$usuarioId]);
        $totalAtribuidas = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT COUNT(*)
              FROM ocorrencias o
              JOIN ocorrencias_atribuicoes oa ON oa.ocorrencia_id = o.id
             WHERE oa.secretario_id = ?{$extraGuard}
               AND o.status IN ('em_analise','encaminhada')
        ");
        $stmt->execute([$usuarioId]);
        $pendentesAtribuidas = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT COUNT(*)
              FROM ocorrencias o
              JOIN ocorrencias_atribuicoes oa ON oa.ocorrencia_id = o.id
             WHERE oa.secretario_id = ?{$extraGuard}
               AND o.status = 'resolvida'
        ");
        $stmt->execute([$usuarioId]);
        $concluidasAtribuidas = (int)$stmt->fetchColumn();
    }
} catch (Throwable $_) {}

// Últimas ocorrências atribuídas ao secretário
$ocorrencias = [];
try {
    if ($hasSecIdCol) {
        $stmt = $pdo->prepare("
            SELECT numero, tipo, status, endereco, DATE_FORMAT(data_criacao, '%d/%m/%Y %H:%i') AS criada_em
              FROM ocorrencias
             WHERE secretario_id = ?
             ORDER BY (status IN ('em_analise','encaminhada')) DESC, data_criacao DESC
             LIMIT 10
        ");
        $stmt->execute([$usuarioId]);
    } elseif ($hasAssignedCol) {
        $stmt = $pdo->prepare("
            SELECT numero, tipo, status, endereco, DATE_FORMAT(data_criacao, '%d/%m/%Y %H:%i') AS criada_em
              FROM ocorrencias
             WHERE assigned_secretario_id = ?
             ORDER BY (status IN ('em_analise','encaminhada')) DESC, data_criacao DESC
             LIMIT 10
        ");
        $stmt->execute([$usuarioId]);
    } elseif ($hasMapTable) {
        $extraGuard = '';
        if ($hasSecIdCol) {
            $extraGuard .= " AND (o.secretario_id IS NULL OR o.secretario_id = 0)";
        }
        if ($hasAssignedCol) {
            $extraGuard .= " AND (o.assigned_secretario_id IS NULL OR o.assigned_secretario_id = 0)";
        }

        $stmt = $pdo->prepare("
            SELECT o.numero, o.tipo, o.status, o.endereco, DATE_FORMAT(o.data_criacao, '%d/%m/%Y %H:%i') AS criada_em
              FROM ocorrencias o
              JOIN ocorrencias_atribuicoes oa ON oa.ocorrencia_id = o.id
             WHERE oa.secretario_id = ?{$extraGuard}
             ORDER BY (o.status IN ('em_analise','encaminhada')) DESC, o.data_criacao DESC
             LIMIT 10
        ");
        $stmt->execute([$usuarioId]);
    } else {
        // fallback: sem estrutura de atribuição, mostra últimas gerais
        $stmt = $pdo->query("
            SELECT numero, tipo, status, endereco, DATE_FORMAT(data_criacao, '%d/%m/%Y %H:%i') AS criada_em
              FROM ocorrencias
             ORDER BY data_criacao DESC
             LIMIT 10
        ");
    }
    $ocorrencias = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $ocorrencias = [];
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Área do Secretário - RADCI</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 min-h-screen">
  <header class="bg-green-700 text-white">
    <div class="container mx-auto px-6 py-4 flex items-center justify-between relative">
      <h1 class="text-xl font-bold">RADCI</h1>
      <nav class="hidden md:flex items-center gap-6">
        <a href="secretario.php" class="hover:underline">Início</a>
        <a href="ocorrencias_secretario.php" class="hover:underline">Ocorrências</a>
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

  <main class="container mx-auto px-6 py-8 max-w-6xl">
    <div class="mb-6">
      <h2 class="text-2xl font-bold text-gray-900">Área do Secretário</h2>
      <p class="text-gray-600">Bem-vindo, <?= htmlspecialchars($usuarioNome) ?>. Aqui você encontra um resumo operacional.</p>
    </div>

    <!-- KPIs -->
    <div class="grid md:grid-cols-3 gap-6 mb-8">
      <div class="bg-white rounded-xl shadow p-6">
        <p class="text-sm text-gray-500">Total de ocorrências encaminhadas para você</p>
        <p class="text-3xl font-semibold text-green-700"><?= number_format($totalAtribuidas) ?></p>
      </div>
      <div class="bg-white rounded-xl shadow p-6">
        <p class="text-sm text-gray-500">Pendentes (não resolvidas)</p>
        <p class="text-3xl font-semibold text-yellow-600"><?= number_format($pendentesAtribuidas) ?></p>
      </div>
      <div class="bg-white rounded-xl shadow p-6">
        <p class="text-sm text-gray-500">Concluídas (resolvidas)</p>
        <p class="text-3xl font-semibold text-blue-700"><?= number_format($concluidasAtribuidas) ?></p>
      </div>
    </div>

    <!-- Últimas ocorrências atribuídas -->
    <div class="bg-white rounded-xl shadow overflow-hidden">
      <div class="px-6 py-4 border-b">
        <h2 class="text-2xl font-semibold text-gray-900">Últimas Ocorrências para você</h2>
        
        <?php if (!empty($_SESSION['flash_success'])): ?>
        <div id="flash-success" class="mt-3 mb-4 rounded-md border border-green-300 bg-green-100 text-green-900 px-4 py-3 shadow-sm transition-all duration-500 ease-out" role="alert" aria-live="polite">
            <div class="flex items-start">
                <svg class="h-5 w-5 text-green-600" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M16.704 5.29a1 1 0 010 1.414l-7.5 7.5a1 1 0 01-1.414 0l-3-3A1 1 0 016.204 10.79l2.293 2.293 6.793-6.793a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                <div class="ml-2">
                    <span class="font-medium">Sucesso:</span>
                    <span><?= htmlspecialchars($_SESSION['flash_success']); ?></span>
                </div>
            </div>
        </div>
        <?php unset($_SESSION['flash_success']); endif; ?>
        
        <?php if (!empty($_SESSION['flash_error'])): ?>
        <div id="flash-error" class="mt-3 mb-4 rounded-md border border-red-300 bg-red-100 text-red-900 px-4 py-3 shadow-sm transition-all duration-500 ease-out" role="alert" aria-live="assertive">
            <div class="flex items-start">
                <svg class="h-5 w-5 text-red-600" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.721-1.36 3.486 0l6.518 11.6c.724 1.288-.198 2.901-1.743 2.901H3.482c-1.545 0-2.467-1.613-1.743-2.9l6.518-11.6zM11 14a1 1 0 10-2 0 1 1 0 002 0zm-1-2a1 1 0 01-1-1V8a1 1 0 112 0v3a1 1 0 01-1 1z" clip-rule="evenodd"/></svg>
                <div class="ml-2">
                    <span class="font-medium">Erro:</span>
                    <span><?= htmlspecialchars($_SESSION['flash_error']); ?></span>
                </div>
            </div>
        </div>
        <?php unset($_SESSION['flash_error']); endif; ?>
        
        <script>
        // Auto-ocultar alertas após 3s com fade + slide-up
        (function() {
            function autoDismiss(id) {
                var el = document.getElementById(id);
                if (!el) return;
                setTimeout(function() {
                    el.style.transition = 'opacity 400ms ease, transform 400ms ease, max-height 400ms ease, margin 400ms ease, padding 400ms ease';
                    el.style.opacity = '0';
                    el.style.transform = 'translateY(-6px)';
                    el.style.maxHeight = '0';
                    el.style.margin = '0';
                    el.style.paddingTop = '0';
                    el.style.paddingBottom = '0';
                    setTimeout(function(){ el.remove(); }, 450);
                }, 3000);
            }
            autoDismiss('flash-success');
            autoDismiss('flash-error');
        })();
        </script>
      </div>
      <div class="p-6">
        <?php if (empty($ocorrencias)): ?>
          <p class="text-gray-600">Nenhuma ocorrência encontrada.</p>
        <?php else: ?>
          <div class="overflow-x-auto">
            <table class="min-w-full text-left">
              <thead class="bg-gray-50">
                <tr>
                  <th class="px-4 py-2 text-gray-700">Número</th>
                  <th class="px-4 py-2 text-gray-700">Tipo</th>
                  <th class="px-4 py-2 text-gray-700">Endereço</th>
                  <th class="px-4 py-2 text-gray-700">Status</th>
                  <th class="px-4 py-2 text-gray-700">Criada em</th>
                </tr>
              </thead>
              <tbody class="text-gray-800">
                <?php foreach ($ocorrencias as $row): ?>
                  <tr class="border-t">
                    <td class="px-4 py-2"><?= htmlspecialchars($row['numero']) ?></td>
                    <td class="px-4 py-2"><?= htmlspecialchars($row['tipo']) ?></td>
                    <td class="px-4 py-2"><?= htmlspecialchars($row['endereco'] ?? '') ?></td>
                    <td class="px-4 py-2">
                      <form method="post" class="inline-block">
                        <input type="hidden" name="action" value="update_status">
                        <input type="hidden" name="numero" value="<?= htmlspecialchars($row['numero']) ?>">
                        <select name="status" class="border rounded px-2 py-1 text-sm" onchange="this.form.submit()">
                          <option value="em_analise"   <?= ($row['status'] ?? '') === 'em_analise'   ? 'selected' : '' ?>>Em análise</option>
                          <option value="encaminhada"  <?= ($row['status'] ?? '') === 'encaminhada'  ? 'selected' : '' ?>>Encaminhada</option>
                          <option value="resolvida"    <?= ($row['status'] ?? '') === 'resolvida'    ? 'selected' : '' ?>>Resolvida</option>
                          <option value="cancelada"    <?= ($row['status'] ?? '') === 'cancelada'    ? 'selected' : '' ?>>Cancelada</option>
                        </select>
                      </form>
                    </td>
                    <td class="px-4 py-2"><?= htmlspecialchars($row['criada_em'] ?? $row['data_criacao'] ?? '') ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </main>
</body>
</html>