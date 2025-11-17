<?php
session_start();
require_once __DIR__ . '/../includes/db.php';

$pdo = get_pdo();

// Controle de acesso: apenas Prefeito (perfil 2)
$perfil = intval($_SESSION['usuario_perfil'] ?? 0);
if (!isset($_SESSION['usuario_id']) || $perfil !== 2) {
    $_SESSION['flash_error'] = 'Acesso restrito: apenas perfis de Prefeito.';
    header('Location: dashboard.php');
    exit;
}

// Cria tabelas necessárias se não existirem
try {
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS gestor_perfis (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nome VARCHAR(100) NOT NULL,
        config TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_nome (nome)
      )
    ");
    // Migrações tolerantes: garantir colunas em bancos legados
    try { $pdo->exec("ALTER TABLE gestor_perfis ADD COLUMN nome VARCHAR(100) NULL"); } catch (Throwable $_) {}
    try { $pdo->exec("ALTER TABLE gestor_perfis ADD COLUMN config TEXT NULL"); } catch (Throwable $_) {}

    $pdo->exec("
      CREATE TABLE IF NOT EXISTS secretarios_perfis (
        id INT AUTO_INCREMENT PRIMARY KEY,
        secretario_id INT NOT NULL,
        perfil_id INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_secretario (secretario_id),
        INDEX (perfil_id)
      )
    ");
    try { $pdo->exec("ALTER TABLE secretarios_perfis ADD COLUMN secretario_id INT NULL"); } catch (Throwable $_) {}
    try { $pdo->exec("ALTER TABLE secretarios_perfis ADD COLUMN perfil_id INT NULL"); } catch (Throwable $_) {}
    try { $pdo->exec("ALTER TABLE secretarios_perfis ADD COLUMN config TEXT NULL"); } catch (Throwable $_) {}

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
} catch (Throwable $e) {
    $_SESSION['flash_error'] = 'Erro ao preparar tabelas de gestão de secretários: ' . $e->getMessage();
}

// KPIs
$totalSecretarios = (int)$pdo->query("SELECT COUNT(*) FROM usuarios WHERE perfil = 3")->fetchColumn();
$pesquisasRecebidas = 0;
$prioridadesRecebidas = 0;
try { $prioridadesRecebidas = (int)$pdo->query("SELECT COUNT(*) FROM prioridades")->fetchColumn(); } catch (Throwable $_) {}

// contabiliza pesquisas recebidas
try {
    if ($pdo->query("SHOW TABLES LIKE 'pesquisa'")->rowCount() > 0) {
        $pesquisasRecebidas = (int)$pdo->query("SELECT COUNT(*) FROM pesquisa")->fetchColumn();
    }
} catch (Throwable $_) { $pesquisasRecebidas = 0; }

// Listagem principal (dinâmica conforme estrutura de secretarios_perfis e gestor_perfis)
$hasSecretarioId = false;
$hasPerfilId     = false;
$hasGpNome       = false;
$hasGpConfig     = false;
try { $hasSecretarioId = $pdo->query("SHOW COLUMNS FROM secretarios_perfis LIKE 'secretario_id'")->rowCount() > 0; } catch (Throwable $_) {}
try { $hasPerfilId     = $pdo->query("SHOW COLUMNS FROM secretarios_perfis LIKE 'perfil_id'")->rowCount() > 0; } catch (Throwable $_) {}
try { $hasGpNome       = $pdo->query("SHOW COLUMNS FROM gestor_perfis LIKE 'nome'")->rowCount() > 0; } catch (Throwable $_) {}
try { $hasGpConfig     = $pdo->query("SHOW COLUMNS FROM gestor_perfis LIKE 'config'")->rowCount() > 0; } catch (Throwable $_) {}
if (!$hasSecretarioId) { $hasPerfilId = false; } // não referenciar sp.* sem chave

$joinSp           = $hasSecretarioId ? 'LEFT JOIN secretarios_perfis sp ON sp.secretario_id = u.id' : '';
$perfilExpr       = $hasPerfilId ? 'sp.perfil_id' : 'NULL';
$joinPerfil       = ($hasPerfilId && $hasGpNome) ? 'LEFT JOIN gestor_perfis gp ON gp.id = sp.perfil_id' : '';
$selectPerfilNome = ($hasPerfilId && $hasGpNome) ? "COALESCE(gp.nome, '') AS perfil_nome" : "'' AS perfil_nome";

$sql = "
  SELECT u.id, u.nome, u.email,
         {$perfilExpr} AS perfil_id,
         {$selectPerfilNome}
    FROM usuarios u
    {$joinSp}
    {$joinPerfil}
   WHERE u.perfil = 3
   ORDER BY u.id DESC
";
$secretarios = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

// Perfis disponíveis (tolerante: se não houver 'nome'/'config', devolve vazio)
$perfisSql = "SELECT id"
           . ($hasGpNome   ? ", nome"                          : ", '' AS nome")
           . ($hasGpConfig ? ", COALESCE(config, '') AS config" : ", '' AS config")
           . " FROM gestor_perfis"
           . " ORDER BY " . ($hasGpNome ? "nome" : "id") . " ASC";
$perfis = $pdo->query($perfisSql)->fetchAll(PDO::FETCH_ASSOC);

// Estados de UI
$openModal = $openModal ?? '';
$flash = $flash ?? '';
$searchResult = $searchResult ?? null;

// POST: salvar/renomear/remover perfis
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_perfil') {
        $id   = (int)($_POST['id'] ?? 0);
        $nome = trim($_POST['nome'] ?? '');
        $cfg  = $_POST['config'] ?? '{}';

        if ($nome === '') {
            $flash = 'Informe o nome do perfil.';
        } else {
            try {
                // valida JSON
                $test = json_decode($cfg, true);
                if (!is_array($test)) $cfg = '{}';

                if ($id > 0) {
                    $stmt = $pdo->prepare("UPDATE gestor_perfis SET nome = ?, config = ? WHERE id = ?");
                    $stmt->execute([$nome, $cfg, $id]);
                    $flash = 'Perfil atualizado com sucesso.';
                } else {
                    $stmt = $pdo->prepare("INSERT INTO gestor_perfis (nome, config) VALUES (?, ?)");
                    $stmt->execute([$nome, $cfg]);
                    $flash = 'Perfil criado com sucesso.';
                }
            } catch (Throwable $e) {
                $flash = 'Erro ao salvar perfil: ' . $e->getMessage();
            }
        }
        $openModal = 'perfis';
        // recarrega perfis após salvar
        $perfis = $pdo->query("SELECT id, nome, COALESCE(config, '') AS config FROM gestor_perfis ORDER BY nome ASC")->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($action === 'remove_perfil') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            try {
                $pdo->beginTransaction();
                $pdo->prepare("DELETE FROM secretarios_perfis WHERE perfil_id = ?")->execute([$id]);
                $pdo->prepare("DELETE FROM gestor_perfis WHERE id = ?")->execute([$id]);
                $pdo->commit();
                $flash = 'Perfil removido.';
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $flash = 'Erro ao remover perfil: ' . $e->getMessage();
            }
        } else {
            $flash = 'ID de perfil inválido.';
        }
        $openModal = 'perfis';
    } elseif ($action === 'create_secretario') {
        $nome  = trim($_POST['nome'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $senha = $_POST['senha'] ?? '';
        $conf  = $_POST['confirmar'] ?? '';
        $assignPerfilId = (int)($_POST['assign_perfil_id'] ?? 0);

        if ($nome === '' || $email === '' || $senha === '' || $conf === '') {
            $flash = 'Preencha todos os campos do secretário.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $flash = 'E-mail inválido.';
        } elseif ($senha !== $conf) {
            $flash = 'As senhas não coincidem.';
        } else {
            try {
                $exists = (int)$pdo->query("SELECT COUNT(*) FROM usuarios WHERE email = " . $pdo->quote($email))->fetchColumn() > 0;
                if ($exists) {
                    $flash = 'E-mail já cadastrado.';
                } else {
                    $hash = password_hash($senha, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("INSERT INTO usuarios (nome, email, senha, perfil) VALUES (?, ?, ?, 3)");
                    $stmt->execute([$nome, $email, $hash]);
                    $secId = (int)$pdo->lastInsertId();

                    // Se perfil selecionado, atribui
                    if ($assignPerfilId > 0) {
                        try {
                            $pdo->prepare("
                                INSERT INTO secretarios_perfis (secretario_id, perfil_id)
                                VALUES (?, ?)
                                ON DUPLICATE KEY UPDATE perfil_id = VALUES(perfil_id)
                            ")->execute([$secId, $assignPerfilId]);
                        } catch (Throwable $_) {}
                    }

                    $flash = 'Secretário criado com sucesso.';
                }
            } catch (Throwable $e) {
                $flash = 'Erro ao criar secretário: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'update_secretario') {
        $id    = (int)($_POST['id'] ?? 0);
        $nome  = trim($_POST['nome'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $senha = $_POST['senha'] ?? '';
        $conf  = $_POST['confirmar'] ?? '';

        if ($id <= 0) {
            $flash = 'ID de secretário inválido.';
        } elseif ($nome === '' || $email === '') {
            $flash = 'Informe nome e e-mail.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $flash = 'E-mail inválido.';
        } else {
            try {
                $exists = (int)$pdo->query("SELECT COUNT(*) FROM usuarios WHERE email = " . $pdo->quote($email) . " AND id <> {$id}")->fetchColumn() > 0;
                if ($exists) {
                    $flash = 'E-mail já cadastrado para outro usuário.';
                } else {
                    $hasSec = (int)$pdo->query("SELECT COUNT(*) FROM usuarios WHERE id = {$id} AND perfil = 3")->fetchColumn() > 0;
                    if (!$hasSec) {
                        $flash = 'Secretário não encontrado.';
                    } else {
                        if ($senha !== '') {
                            if ($senha !== $conf) {
                                $flash = 'As senhas não coincidem.';
                            } else {
                                $hash = password_hash($senha, PASSWORD_DEFAULT);
                                $stmt = $pdo->prepare("UPDATE usuarios SET nome = ?, email = ?, senha = ? WHERE id = ?");
                                $stmt->execute([$nome, $email, $hash, $id]);
                                $flash = 'Secretário atualizado com sucesso.';
                            }
                        } else {
                            $stmt = $pdo->prepare("UPDATE usuarios SET nome = ?, email = ? WHERE id = ?");
                            $stmt->execute([$nome, $email, $id]);
                            $flash = 'Secretário atualizado com sucesso.';
                        }
                    }
                }
            } catch (Throwable $e) {
                $flash = 'Erro ao atualizar secretário: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'delete_secretario') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            $flash = 'ID de secretário inválido.';
        } else {
            try {
                $pdo->beginTransaction();
                // Remove vínculos do secretário
                $pdo->prepare("DELETE FROM secretarios_perfis WHERE secretario_id = ?")->execute([$id]);
                // Remove usuário (somente secretários)
                $pdo->prepare("DELETE FROM usuarios WHERE id = ? AND perfil = 3")->execute([$id]);
                $pdo->commit();
                $flash = 'Secretário excluído com sucesso.';
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $flash = 'Erro ao excluir secretário: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'assign_secretario') {
        $perfilId     = (int)($_POST['perfil_id'] ?? 0);
        $secretarioId = (int)($_POST['secretario_id'] ?? 0);

        if ($perfilId <= 0 || $secretarioId <= 0) {
            $flash = 'Selecione um secretário e um perfil válidos.';
        } else {
            try {
                // Confere existência rápida
                $hasSec = (int)$pdo->query("SELECT COUNT(*) FROM usuarios WHERE id = {$secretarioId} AND perfil = 3")->fetchColumn() > 0;
                $hasPerf = (int)$pdo->query("SELECT COUNT(*) FROM gestor_perfis WHERE id = {$perfilId}")->fetchColumn() > 0;

                if (!$hasSec || !$hasPerf) {
                    $flash = 'Perfil ou Secretário inexistente.';
                } else {
                    $pdo->prepare("
                        INSERT INTO secretarios_perfis (secretario_id, perfil_id)
                        VALUES (?, ?)
                        ON DUPLICATE KEY UPDATE perfil_id = VALUES(perfil_id)
                    ")->execute([$secretarioId, $perfilId]);

                    $flash = 'Secretário atribuído ao perfil com sucesso.';
                }
            } catch (Throwable $e) {
                $flash = 'Erro ao atribuir secretário: ' . $e->getMessage();
            }
        }
    }
}
// Recarrega a listagem após alterações, para refletir perfil_nome
$secretarios = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="UTF-8" />
  <title>Meus Secretários - RADCI</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-white min-h-screen">
  <header class="bg-green-700 text-white">
    <div class="container mx-auto px-6 py-4 flex items-center justify-between relative">
      <img src="/radci/assets/images/logo.png" alt="RADCI" class="h-10 w-auto" />
      <nav class="hidden md:flex items-center gap-6">
        <a href="prefeito_inicio.php" class="hover:underline">Início</a>
        <a href="gestor_secretarios.php" class="hover:underline font-semibold">Meus Secretários</a>
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

  <main class="container mx-auto px-6 py-8 max-w-6xl">


    <!-- KPIs -->
    <div class="grid md:grid-cols-3 gap-6 mb-8">
      <div class="bg-gray-50 rounded-xl border p-6">
        <p class="text-sm text-gray-600">Total de Secretários</p>
        <p class="text-3xl font-semibold text-gray-900"><?= number_format($totalSecretarios) ?></p>
      </div>
      <div class="bg-gray-50 rounded-xl border p-6">
        <p class="text-sm text-gray-600">Pesquisas recebidas</p>
        <p class="text-3xl font-semibold text-gray-900"><?= number_format($pesquisasRecebidas) ?></p>
      </div>
      <div class="bg-gray-50 rounded-xl border p-6">
        <p class="text-sm text-gray-600">Ocorências recebidas</p>
        <p class="text-3xl font-semibold text-gray-900"><?= number_format($prioridadesRecebidas) ?></p>
      </div>
    </div>

    <div class="flex gap-3 mb-6">
      <button class="px-4 py-2 rounded-md bg-green-600 text-white hover:bg-green-700" onclick="openCadastrarSecretario()">Cadastrar Secretário</button>
      <button class="px-4 py-2 rounded-md bg-blue-600 text-white hover:bg-blue-700" onclick="openModal('modalPerfis')">Gerenciar Perfis</button>
    </div>

    <div class="bg-white rounded-xl shadow border">
      <table class="min-w-full text-left">
        <thead class="bg-gray-50 text-gray-700">
          <tr>
            <th class="px-4 py-3 w-12">I</th>
            <th class="px-4 py-3">Usuário</th>
            <th class="px-4 py-3">E-mail</th>
            <th class="px-4 py-3">Perfil</th>
            <th class="px-4 py-3 w-24 text-right">Ações</th>
          </tr>
        </thead>
        <tbody class="text-gray-800">
<?php foreach ($secretarios as $s): ?>
<tr class="border-t">
    <td class="px-4 py-2"><?= (int)$s['id'] ?></td>
    <td class="px-4 py-2"><?= htmlspecialchars($s['nome'] ?? '') ?></td>
    <td class="px-4 py-2"><?= htmlspecialchars($s['email'] ?? '') ?></td>
    <td class="px-4 py-2"><?= htmlspecialchars($s['perfil_nome'] ?? '-') ?></td>

    <!-- Ações por usuário -->
    <td class="px-4 py-2 text-right relative">
        <button type="button" class="px-2 py-1 rounded-md border hover:bg-gray-50" onclick="toggleMenu(this)">▼</button>
        <div class="hidden absolute right-0 mt-2 w-56 bg-white border rounded-md shadow-lg z-10">
            <button type="button" class="block w-full text-left px-4 py-2 hover:bg-gray-100"
                    onclick="openAtribuirPerfil(<?= (int)$s['id'] ?>)">Atribuir a Perfil…</button>

            <button type="button" class="block w-full text-left px-4 py-2 hover:bg-gray-100"
                    onclick="openEditarSecretario(<?= (int)$s['id'] ?>,'<?= htmlspecialchars($s['nome'] ?? '', ENT_QUOTES) ?>','<?= htmlspecialchars($s['email'] ?? '', ENT_QUOTES) ?>')">Editar Usuário…</button>

            <form method="POST" class="block">
                <input type="hidden" name="action" value="delete_secretario" />
                <input type="hidden" name="id" value="<?= (int)$s['id'] ?>" />
                <button class="block w-full text-left px-4 py-2 hover:bg-gray-100"
                        onclick="return confirm('Excluir este secretário?')">Excluir Usuário</button>
            </form>
        </div>
    </td>
</tr>
<?php endforeach; ?>
          <?php if (empty($secretarios)): ?>
            <tr><td colspan="5" class="px-4 py-6 text-center text-gray-500">Nenhum secretário encontrado.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <!-- Modal: Gerenciar Secretários (deve existir com id="modalSecretarios") -->
    <div id="modalSecretarios" class="fixed inset-0 bg-black/50 hidden">
      <div class="bg-white rounded-xl max-w-3xl mx-auto mt-24 overflow-hidden">
        <div class="flex items-center justify-between px-6 py-4 border-b">
          <h3 class="text-lg font-semibold">Gerenciar Secretários</h3>
          <button class="text-gray-500" onclick="closeModal('modalSecretarios')">✕</button>
        </div>

        <div class="p-6 space-y-6">
          <!-- Busca por e-mail/nome -->
          <form id="searchSection" method="POST" class="flex items-center gap-3">
            <input type="hidden" name="action" value="search_secretario" />
            <input type="text" id="searchInput" name="q" class="flex-1 rounded-md border bg-white p-3" placeholder="Nome ou E-mail">
            <button type="submit" class="px-4 py-2 rounded-md bg-blue-600 text-white">BUSCAR</button>
          </form>

          <!-- Aviso igual ao print -->
          <div id="assignNotice" class="hidden bg-yellow-100 border border-yellow-300 text-yellow-800 px-4 py-3 rounded">
            Ao modificar o Perfil do Gestor listado, você estará adicionando-o como Secretário. Caso o perfil fique como "Nenhum Perfil", ele não terá acesso de secretário habilitado.
          </div>

          <!-- Inline assign: exatamente como no print -->
          <div id="assignInline" class="hidden">
            <form id="assignForm" method="POST">
              <input type="hidden" name="action" value="assign_secretario" />
              <input type="hidden" id="assignInlineSecId" name="secretario_id" value="" />

              <div class="border rounded-lg overflow-hidden">
                <table class="min-w-full text-left">
                  <thead class="bg-gray-50">
                    <tr>
                      <th class="px-4 py-2">Nome</th>
                      <th class="px-4 py-2">E-mail</th>
                      <th class="px-4 py-2">Perfil</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr>
                      <td id="assignSecName" class="px-4 py-2"></td>
                      <td id="assignSecEmail" class="px-4 py-2"></td>
                      <td class="px-4 py-2">
                        <select id="assignPerfilSelect" name="perfil_id" class="rounded-md border bg-gray-50 p-2 w-full">
                          <?php foreach ($perfis as $p): ?>
                            <option value="<?= (int)$p['id'] ?>"><?= htmlspecialchars($p['nome']) ?></option>
                          <?php endforeach; ?>
                        </select>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>

              <div class="flex justify-end gap-3 mt-4">
                <button type="button" class="px-4 py-2 rounded-md bg-gray-200 text-gray-800" onclick="closeAssignInline()">CANCELAR</button>
                <button type="button" class="px-4 py-2 rounded-md bg-green-700 text-white" onclick="document.getElementById('assignForm').submit()">SALVAR</button>
              </div>
            </form>
          </div>

          <?php if ($searchResult): ?>
            <div class="border rounded-lg overflow-hidden">
              <table class="min-w-full text-left">
                <thead class="bg-gray-50">
                  <tr><th class="px-4 py-2">Nome</th><th class="px-4 py-2">E-mail</th><th class="px-4 py-2">Perfil</th></tr>
                </thead>
                <tbody>
                  <tr>
                    <td class="px-4 py-2"><?= htmlspecialchars($searchResult['nome']) ?></td>
                    <td class="px-4 py-2"><?= htmlspecialchars($searchResult['email']) ?></td>
                    <td class="px-4 py-2">
                      <form method="POST" class="flex items-center gap-2">
                        <input type="hidden" name="action" value="assign_secretario" />
                        <input type="hidden" name="secretario_id" value="<?= (int)$searchResult['id'] ?>" />
                        <select name="perfil_id" class="rounded-md border bg-gray-50 p-2">
                          <?php foreach ($perfis as $p): ?>
                            <option value="<?= (int)$p['id'] ?>"><?= htmlspecialchars($p['nome']) ?></option>
                          <?php endforeach; ?>
                        </select>
                        <button type="submit" class="px-4 py-2 rounded-md bg-green-700 text-white">Salvar</button>
                      </form>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </main>

  <script>
    
      // Controle de modais com bloqueio de scroll do fundo
      function openModal(id) {
        const el = document.getElementById(id);
        if (!el) return;
        el.classList.remove('hidden');
        document.body.classList.add('overflow-hidden');
      }
      function closeModal(id) {
        const el = document.getElementById(id);
        if (!el) return;
        el.classList.add('hidden');
        document.body.classList.remove('overflow-hidden');
      }
    
    // Abrir “Gerenciar Perfis”
    document.getElementById('btnPerfis')?.addEventListener('click', () => openModal('modalPerfis'));

    // Abrir “Cadastrar Secretário”
    function openCadastrarSecretario() {
      openModal('modalCadastrarSecretario');
    }

    // Abrir “Editar Secretário” preenchendo o formulário
    function openEditarSecretario(id, nome, email) {
      const idEl   = document.getElementById('editSecId');
      const nomeEl = document.getElementById('editSecNome');
      const mailEl = document.getElementById('editSecEmail');
      if (idEl)   idEl.value   = String(id || 0);
      if (nomeEl) nomeEl.value = String(nome || '');
      if (mailEl) mailEl.value = String(email || '');
      openModal('modalEditarSecretario');
    }

    // Abrir “Atribuir a Perfil” preenchendo o secretário alvo
    function openAtribuirPerfil(secretarioId) {
      const idEl = document.getElementById('assignSecId');
      if (idEl) idEl.value = String(secretarioId || 0);
      openModal('modalAtribuirPerfil');
    }

    // Wrapper para referências antigas
    function openAtribuirSecretario(secretarioId) {
      openAtribuirPerfil(secretarioId);
    }
    
    // Drop-down dos perfis
    function togglePerfilMenu(id) {
      const el = document.getElementById(`p-menu-${id}`);
      if (!el) return; el.classList.toggle('hidden');
    }
    document.addEventListener('click', (e) => {
      if (!e.target.closest('[data-perfis="1"]')) {
        document.querySelectorAll('[id^="p-menu-"]').forEach(el => el.classList.add('hidden'));
      }
    });
    
    // Dados dos perfis para preencher o editor
    const perfisData = <?php echo json_encode($perfis, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    
    // Lista de categorias (Drivers) para multi-seleção
    const driversCategorias = [
      'Energia Inteligente','Desenvolvimento','Mobilidade Urbana','Educação','Saúde','Segurança',
      'Saneamento','Meio Ambiente','Tecnologia','Transparência','Economia','Cultura'
    ];

    // Categorias (mesmos IDs das prioridades)
    const prioridadeCategorias = [
      { id:'saude',           name:'Saúde' },
      { id:'inovacao',        name:'Inovação' },
      { id:'mobilidade',      name:'Mobilidade' },
      { id:'politicas',       name:'Políticas Públicas' },
      { id:'riscos',          name:'Riscos Urbanos' },
      { id:'sustentabilidade',name:'Sustentabilidade' },
      { id:'planejamento',    name:'Planejamento Urbano' },
      { id:'educacao',        name:'Educação' },
      { id:'meio',            name:'Meio Ambiente' },
      { id:'infraestrutura',  name:'Infraestrutura da Cidade' },
      { id:'seguranca',       name:'Segurança Pública' },
      { id:'energias',        name:'Energias Inteligentes' },
    ];

    // Componente simples de multi-select com dropdown
    function initMultiSelect(msId, options, placeholder='Selecione categorias') {
      const root = document.getElementById(msId);
      if (!root) return;

      // Evita duplicar
      if (root.dataset.msReady === '1') return;

      const btn = root.querySelector('[data-ms-btn]');
      const list = root.querySelector('[data-ms-list]');
      const search = root.querySelector('[data-ms-search]');
      const hidden = root.querySelector('[data-ms-hidden]');

      // Render opções
      list.innerHTML = '';
      options.forEach(opt => {
        const li = document.createElement('label');
        li.className = 'flex items-center gap-2 px-3 py-2 hover:bg-gray-50 cursor-pointer';
        li.innerHTML = `
          <input type="checkbox" class="ms-check" value="${opt.id}">
          <span>${opt.name}</span>
        `;
        list.appendChild(li);
      });

      // Toggle dropdown
      btn.addEventListener('click', () => list.classList.toggle('hidden'));
      document.addEventListener('click', (e) => {
        if (!root.contains(e.target)) list.classList.add('hidden');
      });

      // Filtro simples
      if (search) {
        search.addEventListener('input', () => {
          const q = search.value.toLowerCase();
          list.querySelectorAll('label').forEach(l => {
            const txt = l.textContent.toLowerCase();
            l.classList.toggle('hidden', !txt.includes(q));
          });
        });
      }

      // Atualiza label do botão e hidden com valores
      function update() {
        const values = Array.from(list.querySelectorAll('.ms-check:checked')).map(c => c.value);
        hidden.value = JSON.stringify(values);
        const selectedNames = options.filter(o => values.includes(o.id)).map(o => o.name);
        btn.textContent = selectedNames.length ? selectedNames.join(', ') : placeholder;
      }

      list.addEventListener('change', update);
      // Inicial
      btn.textContent = placeholder;
      hidden.value = '[]';
      root.dataset.msReady = '1';
    }
    function setMSValues(msId, arrIds) {
      const root = document.getElementById(msId);
      if (!root) return;
      const list = root.querySelector('[data-ms-list]');
      const hidden = root.querySelector('[data-ms-hidden]');
      const btn = root.querySelector('[data-ms-btn]');
      const options = prioridadeCategorias;

      const ids = Array.isArray(arrIds) ? arrIds : [];
      list.querySelectorAll('.ms-check').forEach(c => {
        c.checked = ids.includes(c.value);
      });

      hidden.value = JSON.stringify(ids);
      const selectedNames = options.filter(o => ids.includes(o.id)).map(o => o.name);
      btn.textContent = selectedNames.length ? selectedNames.join(', ') : btn.dataset.placeholder || 'Selecione categorias';
    }
    function getMSValues(msId) {
      const root = document.getElementById(msId);
      if (!root) return [];
      try {
        const v = JSON.parse(root.querySelector('[data-ms-hidden]').value || '[]');
        return Array.isArray(v) ? v : [];
      } catch(_) { return []; }
    }

    // Estados e Municípios (RJ por padrão)
    const estadosBR = [{ sigla: 'RJ', nome: 'Rio de Janeiro' }];
    const municipiosRJ = [
      'Angra dos Reis','Aperibé','Araruama','Areal','Armação dos Búzios','Barra do Piraí','Barra Mansa','Belford Roxo',
      'Bom Jardim','Bom Jesus do Itabapoana','Cabo Frio','Cachoeiras de Macacu','Cambuci','Campos dos Goytacazes','Cantagalo',
      'Carapebus','Cardoso Moreira','Carmo','Casimiro de Abreu','Comendador Levy Gasparian','Conceição de Macabu',
      'Cordeiro','Duas Barras','Duque de Caxias','Engenheiro Paulo de Frontin','Guapimirim','Iguaba Grande',
      'Itaboraí','Itaguaí','Italva','Itaocara','Itaperuna','Itatiaia','Japeri','Laje do Muriaé','Macaé','Macuco',
      'Magé','Mangaratiba','Maricá','Mendes','Mesquita','Miguel Pereira','Miracema','Natividade','Nilópolis',
      'Niterói','Nova Friburgo','Nova Iguaçu','Paracambi','Paraíba do Sul','Paraty','Paty do Alferes','Petrópolis',
      'Pinheiral','Piraí','Porciúncula','Porto Real','Quatis','Queimados','Quissamã','Resende','Rio Bonito',
      'Rio Claro','Rio das Flores','Rio das Ostras','Rio de Janeiro','Santa Maria Madalena','Santo Antônio de Pádua',
      'São Fidélis','São Francisco de Itabapoana','São Gonçalo','São João da Barra','São João de Meriti',
      'São José de Ubá','São José do Vale do Rio Preto','São Pedro da Aldeia','São Sebastião do Alto','Sapucaia',
      'Saquarema','Seropédica','Silva Jardim','Sumidouro','Tanguá','Teresópolis','Trajano de Moraes','Três Rios',
      'Valença','Varre-Sai','Vassouras','Volta Redonda'
    ];

    // Utilitário: popula <select> com opções simples
    function fillSelectOptions(selectId, options, { placeholder='' } = {}) {
      const sel = document.getElementById(selectId);
      if (!sel) return;
      sel.innerHTML = '';
      if (placeholder) {
        const opt = document.createElement('option');
        opt.value = '';
        opt.textContent = placeholder;
        sel.appendChild(opt);
      }
      options.forEach(v => {
        const opt = document.createElement('option');
        if (typeof v === 'string') {
          opt.value = v; opt.textContent = v;
        } else {
          opt.value = v.sigla ?? v.value ?? '';
          opt.textContent = v.nome ?? v.label ?? opt.value;
        }
        sel.appendChild(opt);
      });
    }

    // Configura opções e componentes do editor
    function setupPerfilEditorOptions() {
      // Inicializa os 4 multi-selects de categorias
      initMultiSelect('ms-pesquisas',  prioridadeCategorias, 'Selecione categorias');
      initMultiSelect('ms-sugestoes',  prioridadeCategorias, 'Selecione categorias');
      initMultiSelect('ms-elogios',    prioridadeCategorias, 'Selecione categorias');
      initMultiSelect('ms-reclamacao', prioridadeCategorias, 'Selecione categorias');
    
      // Estado RJ padrão
      fillSelectOptions('acessosEstado', estadosBR, { placeholder: 'Selecione o Estado' });
      const estadoSel = document.getElementById('acessosEstado');
      if (estadoSel) estadoSel.value = 'RJ';
    
      // Municípios: todos do RJ
      fillSelectOptions('acessosMunicipio', municipiosRJ, { placeholder: 'Município' });
    }

    // NOVO: limpar todo o editor de perfil
    function clearPerfilEditor(preserveId = false) {
      const idInput   = document.getElementById('perfilEditId');
      const nomeInput = document.getElementById('perfilEditNome');
    
      if (!preserveId && idInput) idInput.value = '';
      if (nomeInput) nomeInput.value = '';
    
      // Zera multi-selects
      setMSValues('ms-pesquisas',  []);
      setMSValues('ms-sugestoes',  []);
      setMSValues('ms-elogios',    []);
      setMSValues('ms-reclamacao', []);
    
      // Reseta selects e texto
      const estado = document.getElementById('acessosEstado');
      const municipio = document.getElementById('acessosMunicipio');
      const bairros = document.getElementById('acessosBairros');
      if (estado) estado.value = 'RJ';
      if (municipio) municipio.value = '';
      if (bairros) bairros.value = '';
    
      // Zera config
      const cfgHidden = document.getElementById('perfilEditConfig');
      if (cfgHidden) cfgHidden.value = '{}';
    }

    // Editor de Perfil: abrir (novo/alterar) e preencher
    function openPerfilEditor(mode, id) {
      openModal('modalPerfilEditor');
      setupPerfilEditorOptions();
    
      // Limpa antes de popular
      clearPerfilEditor(true);
    
      if (mode === 'edit' && id) {
        const perfil = perfisData.find(p => String(p.id) === String(id));
        if (perfil) {
          document.getElementById('perfilEditId').value   = String(perfil.id);
          document.getElementById('perfilEditNome').value = perfil.nome || '';
    
          // Corrigido: usar JSON.parse e normalizar arrays
          let cfg = {};
          try { cfg = perfil.config ? JSON.parse(perfil.config) : {}; } catch (_) { cfg = {}; }
          const asArr = v => Array.isArray(v) ? v : [];
    
          setMSValues('ms-pesquisas',  asArr(cfg.pesquisas));
          setMSValues('ms-sugestoes',  asArr(cfg.sugestoes));
          setMSValues('ms-elogios',    asArr(cfg.elogios));
          setMSValues('ms-reclamacao', asArr(cfg.reclamacao));
    
          document.getElementById('acessosEstado').value    = (cfg.estado    || 'RJ');
          document.getElementById('acessosMunicipio').value = (cfg.municipio || '');
          document.getElementById('acessosBairros').value   = (cfg.bairros   || '');
    
          document.getElementById('perfilEditConfig').value = JSON.stringify(cfg);
        }
      }
    }

    function savePerfilEditor() {
      const cfg = {
        pesquisas:   getMSValues('ms-pesquisas'),
        sugestoes:   getMSValues('ms-sugestoes'),
        elogios:     getMSValues('ms-elogios'),
        reclamacao:  getMSValues('ms-reclamacao'),
        estado:      document.getElementById('acessosEstado').value || 'RJ',
        municipio:   document.getElementById('acessosMunicipio').value || '',
        bairros:     document.getElementById('acessosBairros').value || ''
      };
      document.getElementById('perfilEditConfig').value = JSON.stringify(cfg);
      document.getElementById('perfilEditForm').submit();
    }

    // ações de bairros (placeholder visual)
    function addBairro() {
      const el = document.getElementById('acessosBairros');
      el.value = el.value;
    }
    function limparBairros() {
      // AGORA: limpa tudo. Se estiver editando (id presente), preserva id.
      const preserveId = !!document.getElementById('perfilEditId')?.value;
      clearPerfilEditor(preserveId);
    }
    
    // Abre modal automaticamente após POST (sem warnings)
    <?php if ($openModal === 'perfis'): ?>
      openModal('modalPerfis');
    <?php endif; ?>
    <?php if ($openModal === 'secretarios'): ?>
      openModal('modalSecretarios');
    <?php endif; ?>
  </script>
</body>
</html>

    <!-- Modal: Gerenciar Perfis (lista + ações) -->
    <div id="modalPerfis" class="fixed inset-0 bg-black/50 hidden z-40 flex items-center justify-center p-4">
      <div class="bg-white rounded-2xl w-full max-w-3xl shadow-2xl overflow-hidden max-h-[85vh] overflow-y-auto">
        <div class="flex items-center justify-between px-6 py-4 border-b">
          <h3 class="text-lg font-semibold">Gerenciar Perfis</h3>
          <button class="text-gray-500 hover:text-gray-700" onclick="closeModal('modalPerfis')">✕</button>
        </div>

        <div class="p-6 space-y-4">
          <div>
            <button type="button" class="px-4 py-2 rounded-md bg-green-700 text-white hover:bg-green-800"
                    onclick="openPerfilEditor('new')">+ NOVO PERFIL</button>
          </div>

          <div class="border rounded-lg overflow-hidden">
            <table class="min-w-full text-left">
              <thead class="bg-gray-50">
                <tr>
                  <th class="px-4 py-2">Perfil</th>
                  <th class="px-4 py-2 w-24 text-right">Ação</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($perfis as $p): ?>
                  <tr>
                    <td class="px-4 py-2"><?= htmlspecialchars($p['nome']) ?></td>
                    <td class="px-4 py-2 text-right relative" data-perfis="1">
                      <button class="px-3 py-2 rounded-md bg-gray-100 hover:bg-gray-200" onclick="togglePerfilMenu(<?= (int)$p['id'] ?>)">▼</button>
                      <div id="p-menu-<?= (int)$p['id'] ?>" class="absolute right-0 mt-2 w-36 bg-white border rounded-md shadow-lg hidden z-30">
                        <button class="block w-full text-left px-4 py-2 hover:bg-gray-100"
                                onclick="openPerfilEditor('edit', <?= (int)$p['id'] ?>); togglePerfilMenu(<?= (int)$p['id'] ?>);">Alterar</button>
                        <form method="POST" class="border-t">
                          <input type="hidden" name="action" value="remove_perfil" />
                          <input type="hidden" name="id" value="<?= (int)$p['id'] ?>" />
                          <button class="block w-full text-left px-4 py-2 hover:bg-gray-100">Remover</button>
                        </form>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
                <?php if (empty($perfis)): ?>
                  <tr><td colspan="2" class="px-4 py-6 text-center text-gray-500">Nenhum perfil cadastrado.</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <!-- Modal: Editor de Perfil (cabeçalho com título) -->
    <div id="modalPerfilEditor" class="fixed inset-0 bg-black/50 hidden z-50 flex items-center justify-center p-4">
      <div class="bg-white rounded-2xl w-full max-w-4xl shadow-2xl overflow-hidden max-h-[90vh] overflow-y-auto">
        <!-- Cabeçalho fixo com título -->
        <div class="flex items-center justify-between px-6 py-4 border-b sticky top-0 bg-white z-10">
          <div class="flex items-center gap-3">
            <button class="px-3 py-1 rounded-md bg-gray-100 hover:bg-gray-200 text-gray-700"
                    onclick="closeModal('modalPerfilEditor')">‹ VOLTAR</button>
            <h3 class="text-lg font-semibold text-gray-900">Editor de Perfil</h3>
          </div>
          <button class="text-gray-500 hover:text-gray-700" onclick="closeModal('modalPerfilEditor')">✕</button>
        </div>
    
        <!-- Conteúdo com rolagem interna -->
        <form id="perfilEditForm" method="POST" class="px-6 py-4 space-y-6 max-h-[80vh] overflow-y-auto">
          <input type="hidden" name="action" value="save_perfil" />
          <input type="hidden" id="perfilEditId" name="id" value="">
          <input type="hidden" id="perfilEditConfig" name="config" value="{}">
    
          <div>
            <label class="block text-sm text-gray-700 mb-1">Nome do Perfil</label>
            <input type="text" id="perfilEditNome" name="nome"
                   class="w-full rounded-lg border border-gray-300 bg-white p-3 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-green-600" />
          </div>
    
          <div>
            <p class="font-semibold text-gray-900 mb-2">Visibilidade de Temas x Drivers</p>
    
            <label class="block text-sm text-gray-700 mb-1">Pesquisas</label>
            <div id="ms-pesquisas" class="relative" data-ms>
              <button type="button" data-ms-btn data-placeholder="Selecione categorias"
                      class="w-full text-left rounded-lg border border-gray-300 bg-white p-3 focus:outline-none focus:ring-2 focus:ring-green-600">
                Selecione categorias
              </button>
              <div data-ms-list class="absolute mt-2 w-full bg-white border rounded-md shadow-lg hidden z-20 max-h-60 overflow-y-auto">
                <div class="p-2">
                  <input type="text" data-ms-search placeholder="Buscar..." class="w-full border rounded-md p-3" />
                </div>
              </div>
              <input type="hidden" data-ms-hidden id="driversPesquisas" value="[]">
            </div>
    
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
              <div>
                <label class="block text-sm text-gray-700 mb-1">Sugestão de Melhorias</label>
                <div id="ms-sugestoes" class="relative" data-ms>
                  <button type="button" data-ms-btn data-placeholder="Selecione categorias"
                          class="w-full text-left rounded-lg border border-gray-300 bg-white p-3 focus:outline-none focus:ring-2 focus:ring-green-600">
                    Selecione categorias
                  </button>
                  <div data-ms-list class="absolute mt-2 w-full bg-white border rounded-md shadow-lg hidden z-20 max-h-60 overflow-y-auto">
                    <div class="p-2">
                      <input type="text" data-ms-search placeholder="Buscar..." class="w-full border rounded-md p-3" />
                    </div>
                  </div>
                  <input type="hidden" data-ms-hidden id="driversSugestoes" value="[]">
                </div>
              </div>
              <div>
                <label class="block text-sm text-gray-700 mb-1">Elogios</label>
                <div id="ms-elogios" class="relative" data-ms>
                  <button type="button" data-ms-btn data-placeholder="Selecione categorias"
                          class="w-full text-left rounded-lg border border-gray-300 bg-white p-3 focus:outline-none focus:ring-2 focus:ring-green-600">
                    Selecione categorias
                  </button>
                  <div data-ms-list class="absolute mt-2 w-full bg-white border rounded-md shadow-lg hidden z-20 max-h-60 overflow-y-auto">
                    <div class="p-2">
                      <input type="text" data-ms-search placeholder="Buscar..." class="w-full border rounded-md p-3" />
                    </div>
                  </div>
                  <input type="hidden" data-ms-hidden id="driversElogios" value="[]">
                </div>
              </div>
            </div>
    
            <!-- Reclamação em bloco único -->
            <div class="mt-4">
              <label class="block text-sm text-gray-700 mb-1">Reclamação</label>
              <div id="ms-reclamacao" class="relative" data-ms>
                <button type="button" data-ms-btn data-placeholder="Selecione categorias"
                        class="w-full text-left rounded-lg border border-gray-300 bg-white p-3 focus:outline-none focus:ring-2 focus:ring-green-600">
                  Selecione categorias
                </button>
                <div data-ms-list class="absolute mt-2 w-full bg-white border rounded-md shadow-lg hidden z-20 max-h-60 overflow-y-auto">
                  <div class="p-2">
                    <input type="text" data-ms-search placeholder="Buscar..." class="w-full border rounded-md p-3" />
                  </div>
                </div>
                <input type="hidden" data-ms-hidden id="driversReclamacao" value="[]">
              </div>
            </div>
          </div>
    
          <div>
            <p class="font-semibold text-gray-900 mb-2">Acessos</p>
    
            <label class="block text-sm text-gray-700 mb-1">Estado</label>
            <select id="acessosEstado"
                    class="w-full rounded-lg border border-gray-300 bg-white p-3 focus:outline-none focus:ring-2 focus:ring-green-600">
              <option value="">Selecione o Estado</option>
            </select>
    
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
              <div>
                <label class="block text-sm text-gray-700 mb-1">Município</label>
                <select id="acessosMunicipio"
                        class="w-full rounded-lg border border-gray-300 bg-white p-3 focus:outline-none focus:ring-2 focus:ring-green-600">
                  <option value="">Município</option>
                </select>
              </div>
              <div>
                <label class="block text-sm text-gray-700 mb-1">Buscar bairros</label>
                <input type="text" id="acessosBairros"
                       class="w-full rounded-lg border border-gray-300 bg-white p-3 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-green-600"
                       placeholder="Buscar bairros">
              </div>
            </div>
    
            <div class="flex gap-3 mt-4">
              <button type="button" class="px-4 py-2 rounded-md bg-blue-600 text-white hover:bg-blue-700" onclick="addBairro()">+ ADICIONAR</button>
              <button type="button" class="px-4 py-2 rounded-md bg-yellow-400 text-white hover:bg-yellow-500" onclick="limparBairros()">LIMPAR</button>
            </div>
    
            <div class="flex justify-end gap-3 mt-6">
              <button type="button" class="px-4 py-2 rounded-md bg-gray-200 text-gray-800 hover:bg-gray-300"
                      onclick="closeModal('modalPerfilEditor')">CANCELAR</button>
              <button type="button" class="px-4 py-2 rounded-md bg-green-700 text-white hover:bg-green-800"
                      onclick="savePerfilEditor()">SALVAR</button>
            </div>
          </div>
        </form>
      </div>
    </div>

<script>
function toggleMenu(btn) {
  const menu = btn.nextElementSibling;
  if (!menu) return;
  menu.classList.toggle('hidden');
}

// Wrapper para compatibilidade com referências antigas
function openAtribuirSecretario(secretarioId) {
  openAtribuirPerfil(secretarioId);
}
</script>
<!-- Modal: Cadastrar Secretário -->
<div id="modalCadastrarSecretario" class="fixed inset-0 bg-black/50 hidden z-50 flex items-center justify-center p-4">
  <div class="bg-white rounded-2xl w-full max-w-lg shadow-2xl overflow-hidden">
    <div class="flex items-center justify-between px-6 py-4 border-b bg-white">
      <h3 class="text-lg font-semibold text-gray-900">Cadastrar Secretário</h3>
      <button class="px-3 py-1 rounded-md bg-gray-100 hover:bg-gray-200" onclick="closeModal('modalCadastrarSecretario')">×</button>
    </div>
    <form method="POST" class="p-6 space-y-3">
      <input type="hidden" name="action" value="create_secretario" />
      <div>
        <label class="text-sm mb-1 block">Nome</label>
        <input type="text" name="nome" class="w-full p-3 rounded-md border" required />
      </div>
      <div>
        <label class="text-sm mb-1 block">E-mail</label>
        <input type="email" name="email" class="w-full p-3 rounded-md border" required />
      </div>
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="text-sm mb-1 block">Senha</label>
          <input type="password" name="senha" class="w-full p-3 rounded-md border" required />
        </div>
        <div>
          <label class="text-sm mb-1 block">Confirmar</label>
          <input type="password" name="confirmar" class="w-full p-3 rounded-md border" required />
        </div>
      </div>
      <div>
        <label class="text-sm mb-1 block">Atribuir ao Perfil (opcional)</label>
        <select name="assign_perfil_id" class="w-full p-3 rounded-md border">
          <option value="">Selecione</option>
          <?php foreach ($perfis as $p): ?>
            <option value="<?= (int)$p['id'] ?>"><?= htmlspecialchars($p['nome'] ?? '') ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="flex justify-end gap-3">
        <button type="button" class="px-3 py-2 rounded-md bg-gray-100 hover:bg-gray-200" onclick="closeModal('modalCadastrarSecretario')">Cancelar</button>
        <button type="submit" class="px-4 py-2 rounded-md bg-green-600 text-white hover:bg-green-700">Criar</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: Editar Secretário -->
<div id="modalEditarSecretario" class="fixed inset-0 bg-black/50 hidden z-50 flex items-center justify-center p-4">
  <div class="bg-white rounded-2xl w-full max-w-lg shadow-2xl overflow-hidden">
    <div class="flex items-center justify-between px-6 py-4 border-b bg-white">
      <h3 class="text-lg font-semibold text-gray-900">Editar Secretário</h3>
      <button class="px-3 py-1 rounded-md bg-gray-100 hover:bg-gray-200" onclick="closeModal('modalEditarSecretario')">×</button>
    </div>
    <form method="POST" class="p-6 space-y-3">
      <input type="hidden" name="action" value="update_secretario" />
      <input type="hidden" name="id" id="editSecId" value="0" />
      <div>
        <label class="text-sm mb-1 block">Nome</label>
        <input type="text" name="nome" id="editSecNome" class="w-full p-3 rounded-md border" required />
      </div>
      <div>
        <label class="text-sm mb-1 block">E-mail</label>
        <input type="email" name="email" id="editSecEmail" class="w-full p-3 rounded-md border" required />
      </div>
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="text-sm mb-1 block">Nova Senha (opcional)</label>
          <input type="password" name="senha" class="w-full p-3 rounded-md border" />
        </div>
        <div>
          <label class="text-sm mb-1 block">Confirmar</label>
          <input type="password" name="confirmar" class="w-full p-3 rounded-md border" />
        </div>
      </div>
      <div class="flex justify-end gap-3">
        <button type="button" class="px-3 py-2 rounded-md bg-gray-100 hover:bg-gray-200" onclick="closeModal('modalEditarSecretario')">Cancelar</button>
        <button type="submit" class="px-4 py-2 rounded-md bg-green-600 text-white hover:bg-green-700">Salvar</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: Atribuir a Perfil -->
<div id="modalAtribuirPerfil" class="fixed inset-0 bg-black/50 hidden z-50 flex items-center justify-center p-4">
  <div class="bg-white rounded-2xl w-full max-w-lg shadow-2xl overflow-hidden">
    <div class="flex items-center justify-between px-6 py-4 border-b bg-white">
      <h3 class="text-lg font-semibold text-gray-900">Atribuir a Perfil</h3>
      <button class="px-3 py-1 rounded-md bg-gray-100 hover:bg-gray-200" onclick="closeModal('modalAtribuirPerfil')">×</button>
    </div>
    <form method="POST" class="p-6 space-y-3">
      <input type="hidden" name="action" value="assign_secretario" />
      <input type="hidden" name="secretario_id" id="assignSecId" value="0" />
      <div>
        <label class="text-sm mb-1 block">Perfil</label>
        <select name="perfil_id" class="w-full p-3 rounded-md border" required>
          <option value="">Selecione</option>
          <?php foreach ($perfis as $p): ?>
            <option value="<?= (int)$p['id'] ?>"><?= htmlspecialchars($p['nome'] ?? '') ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="flex justify-end gap-3">
        <button type="button" class="px-3 py-2 rounded-md bg-gray-100 hover:bg-gray-200" onclick="closeModal('modalAtribuirPerfil')">Cancelar</button>
        <button type="submit" class="px-4 py-2 rounded-md bg-green-600 text-white hover:bg-green-700">Atribuir</button>
      </div>
    </form>
  </div>
</div>