<?php
session_start();

// Dataset inicial em sessão (para demonstração)
if (!isset($_SESSION['admin_users'])) {
    $_SESSION['admin_users'] = [
        [
          'id'=>1, 'name'=>'Jezreel Oliveira', 'email'=>'jezdev0@gmail.com', 'role'=>'cidadao', 'answered'=>true,
          'cep'=>'23587-618', 'uf'=>'RJ', 'municipio'=>'Rio de Janeiro', 'bairro'=>'Paciência',
          'rua'=>'Rua Pedras Preciosas', 'complemento'=>'(Monte Sinai)'
        ],
        [
          'id'=>2, 'name'=>'Admin', 'email'=>'admin@radci.com.br', 'role'=>'admin_radci', 'answered'=>false,
          'cep'=>'', 'uf'=>'', 'municipio'=>'', 'bairro'=>'', 'rua'=>'', 'complemento'=>''
        ],
        [
          'id'=>3, 'name'=>'Prefeito', 'email'=>'prefeito@gmail.com', 'role'=>'admin_publico', 'answered'=>false,
          'cep'=>'', 'uf'=>'', 'municipio'=>'', 'bairro'=>'', 'rua'=>'', 'complemento'=>''
        ],
        [
          'id'=>4, 'name'=>'Secretário 1', 'email'=>'secretario@gmail.com', 'role'=>'secretario', 'answered'=>false,
          'cep'=>'', 'uf'=>'', 'municipio'=>'', 'bairro'=>'', 'rua'=>'', 'complemento'=>''
        ],
    ];
}
$users = &$_SESSION['admin_users'];
require_once __DIR__ . '/../includes/db.php';
$pdo = get_pdo();

// Restringe acesso ao Admin (perfil 10)
if (!isset($_SESSION['usuario_id']) || intval($_SESSION['usuario_perfil'] ?? 0) !== 10) {
    header("Location: index.php");
    exit;
}

$flash = '';

$perfilMap = [
    'cidadao'       => 1,
    'admin_radci'   => 10,
    'admin_publico' => 2,  // Prefeito
    'secretario'    => 3,
];
$perfilLabelMap = [
    1  => 'Cidadão',
    2  => 'Prefeito',
    3  => 'Secretário / Assessor',
    10 => 'Admin RADCI',
];

// Processa ações (add/edit/remove) direto no banco
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $stmt = $pdo->prepare("
            INSERT INTO usuarios (nome, email, senha, perfil, cep, uf, municipio, bairro, rua, complemento)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            trim($_POST['name'] ?? ''),
            trim($_POST['email'] ?? ''),
            trim($_POST['senha'] ?? ''), // ajuste se houver hash
            $perfilMap[$_POST['role'] ?? 'cidadao'] ?? 1,
            trim($_POST['cep'] ?? ''),
            strtoupper(trim($_POST['uf'] ?? '')),
            trim($_POST['municipio'] ?? ''),
            trim($_POST['bairro'] ?? ''),
            trim($_POST['rua'] ?? ''),
            trim($_POST['complemento'] ?? ''),
        ]);
        // Garante created_at preenchido
        try {
            $idNovo = (int)$pdo->lastInsertId();
            if ($idNovo > 0) {
                $pdo->prepare("UPDATE usuarios SET created_at = NOW() WHERE id = ?")->execute([$idNovo]);
            }
        } catch (Throwable $_) {}
        $flash = 'Usuário adicionado.';
    } elseif ($action === 'edit') {
        $id = intval($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("
            UPDATE usuarios
               SET nome = ?,
                   email = ?,
                   senha = ?,
                   perfil = ?,
                   cep = ?,
                   uf = ?,
                   municipio = ?,
                   bairro = ?,
                   rua = ?,
                   complemento = ?
             WHERE id = ?
        ");
        $stmt->execute([
            trim($_POST['name'] ?? ''),
            trim($_POST['email'] ?? ''),
            trim($_POST['senha'] ?? ''), // ajuste se houver hash
            $perfilMap[$_POST['role'] ?? 'cidadao'] ?? 1,
            trim($_POST['cep'] ?? ''),
            strtoupper(trim($_POST['uf'] ?? '')),
            trim($_POST['municipio'] ?? ''),
            trim($_POST['bairro'] ?? ''),
            trim($_POST['rua'] ?? ''),
            trim($_POST['complemento'] ?? ''),
            $id,
        ]);
        $flash = 'Usuário atualizado.';
    } elseif ($action === 'remove') {
        $id = intval($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM usuarios WHERE id = ?")->execute([$id]);
        $flash = 'Usuário removido.';
    }

    // Aprovar Administrador Público
    if ($action === 'approve_admin_publico') {
        // Garante tabela de validações
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS admin_publico_validacoes (
              id INT AUTO_INCREMENT PRIMARY KEY,
              usuario_id INT NOT NULL,
              tipo_documento VARCHAR(50) NOT NULL,
              descricao_outros VARCHAR(255) NULL,
              fonte_url VARCHAR(255) NULL,
              arquivos_json TEXT NOT NULL,
              status VARCHAR(20) NOT NULL DEFAULT 'pendente',
              created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              INDEX (usuario_id)
            )
        ");

        $usuarioId   = intval($_POST['usuario_id'] ?? 0);
        $aprovadorId = intval($_SESSION['usuario_id'] ?? 0);
        $perfilDestino = trim($_POST['perfil_destino'] ?? 'prefeito'); // prefeito|secretario
        $mapDestino = ['prefeito' => 2, 'secretario' => 3];
        $novoPerfil = $mapDestino[$perfilDestino] ?? 2;

        // Atualiza validação e solicitação
        $pdo->prepare("
            UPDATE admin_publico_validacoes
               SET status = 'aprovado'
             WHERE usuario_id = ? AND status = 'pendente'
        ")->execute([$usuarioId]);

        // Atualiza solicitação
        $pdo->prepare("
            UPDATE admin_publico_solicitacoes
               SET status = 'aprovado', data_aprovacao = NOW(), aprovado_por = ?
             WHERE usuario_id = ? AND status = 'pendente'
        ")->execute([$aprovadorId, $usuarioId]);

        // Promove usuário conforme destino
        $pdo->prepare("UPDATE usuarios SET perfil = ? WHERE id = ?")->execute([$novoPerfil, $usuarioId]);

        // E-mail de aprovação
        $stmtU = $pdo->prepare("SELECT nome, email FROM usuarios WHERE id = ?");
        $stmtU->execute([$usuarioId]);
        $usr = $stmtU->fetch();
        if ($usr && filter_var($usr['email'], FILTER_VALIDATE_EMAIL)) {
            $loginUrl = (isset($_SERVER['HTTP_HOST']) ? 'http://' . $_SERVER['HTTP_HOST'] . '/radci/api/login_cadastro.php' : '');
            $papel    = ($novoPerfil === 3) ? 'Secretário' : 'Prefeito';
            @mail(
                $usr['email'],
                'RADCI - Acesso autorizado',
                "Olá {$usr['nome']},\n\nSua solicitação foi aprovada como {$papel}.\nAcesse: {$loginUrl}\n\nEquipe RADCI",
                "Content-Type: text/plain; charset=UTF-8"
            );
        }
        $flash = 'Solicitação aprovada. Perfil atualizado e e-mail enviado.';
    }

    if ($action === 'reject_admin_publico') {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS admin_publico_validacoes (
              id INT AUTO_INCREMENT PRIMARY KEY,
              usuario_id INT NOT NULL,
              tipo_documento VARCHAR(50) NOT NULL,
              descricao_outros VARCHAR(255) NULL,
              fonte_url VARCHAR(255) NULL,
              arquivos_json TEXT NOT NULL,
              status VARCHAR(20) NOT NULL DEFAULT 'pendente',
              created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              INDEX (usuario_id)
            )
        ");

        $usuarioId   = intval($_POST['usuario_id'] ?? 0);
        $aprovadorId = intval($_SESSION['usuario_id'] ?? 0);

        // Marca validação como rejeitada
        $pdo->prepare("
            UPDATE admin_publico_validacoes
               SET status = 'rejeitado'
             WHERE usuario_id = ? AND status = 'pendente'
        ")->execute([$usuarioId]);

        // Atualiza solicitação
        $pdo->prepare("
            UPDATE admin_publico_solicitacoes
               SET status = 'recusado', data_aprovacao = NOW(), aprovado_por = ?
             WHERE usuario_id = ? AND status = 'pendente'
        ")->execute([$aprovadorId, $usuarioId]);

        // E-mail ao solicitante
        $stmtU = $pdo->prepare("SELECT nome, email FROM usuarios WHERE id = ?");
        $stmtU->execute([$usuarioId]);
        $usr = $stmtU->fetch();
        if ($usr && filter_var($usr['email'], FILTER_VALIDATE_EMAIL)) {
            @mail(
                $usr['email'],
                'RADCI - Solicitação recusada',
                "Olá {$usr['nome']},\n\nSua solicitação de Administrador Público foi analisada e recusada.\nCaso necessário, envie nova solicitação com documentos atualizados.\n\nEquipe RADCI",
                "Content-Type: text/plain; charset=UTF-8"
            );
        }

        $flash = 'Solicitação recusada e e-mail enviado.';
    }

    // NOVO: Alterar perfil diretamente (Prefeito/Secretário)
    if ($action === 'change_role') {
        $id = intval($_POST['id'] ?? 0);
        $dest = trim($_POST['perfil_destino'] ?? '');
        $mapDestino = ['prefeito' => 2, 'secretario' => 3];
        $novoPerfil = $mapDestino[$dest] ?? 0;

        if ($id > 0 && in_array($novoPerfil, [2, 3], true)) {
            // não permitir alterar Admin RADCI por aqui
            $stmtCur = $pdo->prepare("SELECT perfil FROM usuarios WHERE id = ?");
            $stmtCur->execute([$id]);
            $cur = $stmtCur->fetch();
            if (!$cur || intval($cur['perfil']) !== 10) {
                $pdo->prepare("UPDATE usuarios SET perfil = ? WHERE id = ?")->execute([$novoPerfil, $id]);
                $flash = 'Perfil atualizado.';
            } else {
                $flash = 'Não é permitido alterar perfil de Admin RADCI.';
            }
        } else {
            $flash = 'Perfil inválido.';
        }
    }
}

// Garante coluna created_at na tabela usuarios (se ainda não existir)
try {
    $hasCreated = $pdo->query("SHOW COLUMNS FROM usuarios LIKE 'created_at'")->rowCount() > 0;
    if (!$hasCreated) {
        $pdo->exec("ALTER TABLE usuarios ADD COLUMN created_at DATETIME DEFAULT CURRENT_TIMESTAMP");
    }
} catch (Throwable $_) {}

// Busca (GET ?q=...) e listagem
$search = trim($_GET['q'] ?? '');
if ($search !== '') {
    $like = '%'.$search.'%';
    $stmt = $pdo->prepare("
        SELECT u.*,
               COALESCE(u.created_at, s.data_solicitacao) AS created_at,
               0 AS answered
          FROM usuarios u
          LEFT JOIN admin_publico_solicitacoes s
            ON s.usuario_id = u.id
         WHERE u.nome LIKE ? OR u.email LIKE ?
         ORDER BY u.id DESC
    ");
    $stmt->execute([$like, $like]);
    $users = $stmt->fetchAll();
} else {
    $users = $pdo->query("
        SELECT u.*,
               COALESCE(u.created_at, s.data_solicitacao) AS created_at,
               0 AS answered
          FROM usuarios u
          LEFT JOIN admin_publico_solicitacoes s
            ON s.usuario_id = u.id
         ORDER BY u.id DESC
    ")->fetchAll();
}

// Mapeia linhas do banco para o formato esperado pelo frontend
$perfilReverse = [
    1  => 'cidadao',
    2  => 'admin_publico',
    3  => 'secretario',
    10 => 'admin_radci',
];

$filtered = array_map(function($row) use ($perfilReverse) {
    $role = $row['role'] ?? ($perfilReverse[intval($row['perfil'] ?? 0)] ?? 'cidadao');
    return [
        'id'          => $row['id'] ?? null,
        'name'        => $row['name'] ?? ($row['nome'] ?? ''),
        'email'       => $row['email'] ?? '',
        'role'        => $role,
        'answered'    => !!intval($row['answered'] ?? 0),
        'cep'         => $row['cep'] ?? '',
        'uf'          => $row['uf'] ?? '',
        'municipio'   => $row['municipio'] ?? '',
        'bairro'      => $row['bairro'] ?? '',
        'rua'         => $row['rua'] ?? '',
        'complemento' => $row['complemento'] ?? '',
        'created_at'  => $row['created_at'] ?? null, // data de registro
    ];
}, $users ?? []);

// Estatísticas (puxadas do banco)
try { $cidadaos = (int)$pdo->query("SELECT COUNT(*) FROM usuarios WHERE perfil = 1")->fetchColumn(); } catch (Throwable $e) { $cidadaos = 0; }
try { $countPrefeitos = (int)$pdo->query("SELECT COUNT(*) FROM usuarios WHERE perfil = 2")->fetchColumn(); } catch (Throwable $e) { $countPrefeitos = 0; }
try { $secretarios = (int)$pdo->query("SELECT COUNT(*) FROM usuarios WHERE perfil = 3")->fetchColumn(); } catch (Throwable $e) { $secretarios = 0; }
$adminsPublicos = $countPrefeitos + $secretarios;

$pesquisasRecebidas = 0;
try {
    if ($pdo->query("SHOW TABLES LIKE 'pesquisa'")->rowCount() > 0) {
        $pesquisasRecebidas = (int)$pdo->query("SELECT COUNT(*) FROM pesquisa")->fetchColumn();
    }
} catch (Throwable $e) { $pesquisasRecebidas = 0; }

$prioridadesRecebidas = 0;
try {
    if ($pdo->query("SHOW TABLES LIKE 'prioridades'")->rowCount() > 0) {
        $prioridadesRecebidas = (int)$pdo->query("SELECT COUNT(*) FROM prioridades")->fetchColumn();
    }
} catch (Throwable $e) { $prioridadesRecebidas = 0; }

// Carrega validações pendentes
$pdo->exec("
    CREATE TABLE IF NOT EXISTS admin_publico_validacoes (
      id INT AUTO_INCREMENT PRIMARY KEY,
      usuario_id INT NOT NULL,
      tipo_documento VARCHAR(50) NOT NULL,
      descricao_outros VARCHAR(255) NULL,
      fonte_url VARCHAR(255) NULL,
      arquivos_json TEXT NOT NULL,
      status VARCHAR(20) NOT NULL DEFAULT 'pendente',
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      INDEX (usuario_id)
    )
");
$pendentes = $pdo->query("
    SELECT v.*, u.nome, u.email
      FROM admin_publico_validacoes v
      JOIN usuarios u ON u.id = v.usuario_id
     WHERE v.status = 'pendente'
     ORDER BY v.created_at ASC
")->fetchAll(PDO::FETCH_ASSOC);

// IDs de usuários com solicitação pendente
$pendentesIds = array_map(fn($p) => intval($p['usuario_id']), $pendentes);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8" />
  <title>Usuários (Admin) - RADCI</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-white min-h-screen">
  <header class="bg-green-700 text-white">
    <div class="container mx-auto px-6 py-4 flex items-center justify-between relative">
      <img src="/radci/assets/images/logo.png" alt="RADCI" class="h-10 w-auto" />
      <nav class="hidden md:flex items-center gap-6">
        <a href="admin_inicio.php" class="hover:underline">Início</a>
        <a href="usuarios.php" class="hover:underline font-semibold">Usuários</a>
        <a href="ocorrencias_admin.php" class="hover:underline">Ocorrências</a>
        <a href="relatorios_admin.php" class="hover:underline">Relatórios</a>
        <a href="criar_pesquisa.php" class="hover:underline">Criar Pesquisa</a>
        <a href="pesquisa_respostas_admin.php" class="hover:underline">Respostas</a>
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
        <a href="pesquisa_respostas_admin.php" class="block px-4 py-2 hover:bg-gray-100">Respostas</a>
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
    <?php if (!empty($flash)): ?>
      <div id="flashMessage" class="mb-4 rounded-lg bg-green-50 border border-green-200 text-green-800 px-4 py-3">
        <?= htmlspecialchars($flash) ?>
      </div>
      <script>
        setTimeout(function(){
          var el = document.getElementById('flashMessage');
          if (el) { el.remove(); }
        }, 3000);
      </script>
    <?php endif; ?>

    <div class="mb-6">
      <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
        <h1 class="text-3xl font-bold text-gray-900">Usuários</h1>
        <form method="get" class="flex items-center gap-2 w-full md:w-auto">
          <input
            type="text"
            name="q"
            value="<?= htmlspecialchars($search) ?>"
            class="border rounded-md px-3 py-2 w-full md:w-96"
            placeholder="Buscar por nome ou e-mail"
          />
          <button class="px-4 py-2 rounded bg-green-600 text-white hover:bg-green-700 w-full md:w-auto">
            Buscar
          </button>
        </form>
      </div>
    </div>

    <!-- Solicitações de Administrador Público -->
    <section class="mb-8">
      <h2 class="text-xl font-semibold text-gray-800 mb-3">Solicitações de Administrador Público</h2>
      <?php if (empty($pendentes)): ?>
        <div class="mb-6 p-4 border rounded bg-gray-50 text-gray-600">Não há solicitações pendentes.</div>
      <?php else: ?>
        <div class="mb-6 overflow-x-auto bg-white rounded-xl shadow">
          <table class="min-w-full">
            <thead class="bg-gray-100">
              <tr>
                <th class="px-4 py-2 text-left text-sm text-gray-700">Usuário</th>
                <th class="px-4 py-2 text-left text-sm text-gray-700">E-mail</th>
                <th class="px-4 py-2 text-left text-sm text-gray-700">Documento</th>
                <th class="px-4 py-2 text-left text-sm text-gray-700">Anexos</th>
                <th class="px-4 py-2 text-left text-sm text-gray-700">Ações</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($pendentes as $p): 
                $files = [];
                if (!empty($p['arquivos_json'])) {
                    $decoded = json_decode($p['arquivos_json'], true);
                    if (is_array($decoded)) $files = $decoded;
                }
              ?>
                <tr class="border-t">
                  <td class="px-4 py-2"><?= htmlspecialchars($p['nome'] ?? '') ?></td>
                  <td class="px-4 py-2"><?= htmlspecialchars($p['email'] ?? '') ?></td>
                  <td class="px-4 py-2">
                    <?= htmlspecialchars($p['tipo_documento'] ?? '') ?>
                    <?php if (!empty($p['descricao_outros'])): ?>
                      <div class="text-xs text-gray-500"><?= htmlspecialchars($p['descricao_outros']) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($p['fonte_url'])): ?>
                      <div class="text-xs"><a href="<?= htmlspecialchars($p['fonte_url']) ?>" target="_blank" class="text-green-600 underline">Publicação Oficial</a></div>
                    <?php endif; ?>
                  </td>
                  <td class="px-4 py-2">
                    <?php if (empty($files)): ?>
                      <span class="text-gray-500 text-sm">Sem anexos</span>
                    <?php else: ?>
                      <?php
                        // Base do aplicativo (ex.: /radci)
                        $baseRoot = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
                      ?>
                      <?php foreach ($files as $f):
                        $pPath = isset($f['path']) ? $f['path'] : '';
                        // Garante /radci/uploads/... em vez de /uploads/...
                        $href  = $baseRoot . '/' . ltrim($pPath, '/');
                      ?>
                        <div>
                          <a href="<?= htmlspecialchars($href) ?>"
                             target="_blank"
                             rel="noopener noreferrer"
                             class="text-green-600 underline">
                            <?= htmlspecialchars($f['name'] ?? basename($href)) ?>
                          </a>
                        </div>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </td>
                  <td class="px-4 py-2 flex gap-2">
                    <form method="post" class="flex items-center gap-2">
                      <input type="hidden" name="action" value="approve_admin_publico">
                      <input type="hidden" name="usuario_id" value="<?= intval($p['usuario_id']) ?>">
                      <select name="perfil_destino" class="border rounded px-2 py-1 text-sm">
                        <option value="prefeito">Prefeito</option>
                        <option value="secretario">Secretário</option>
                      </select>
                      <button class="px-3 py-1 rounded bg-green-600 text-white hover:bg-green-700">Aprovar</button>
                    </form>
                    <form method="post" onsubmit="return confirm('Tem certeza que deseja recusar?');">
                      <input type="hidden" name="action" value="reject_admin_publico">
                      <input type="hidden" name="usuario_id" value="<?= intval($p['usuario_id']) ?>">
                      <button class="px-3 py-1 rounded bg-red-600 text-white hover:bg-red-700">Recusar</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>

    <!-- Cards de resumo -->
    <section class="mb-8">
      <h2 class="text-xl font-semibold text-gray-800 mb-3">Resumo</h2>
      <div class="grid sm:grid-cols-2 lg:grid-cols-5 gap-6">
        <div class="bg-white rounded-xl shadow p-6">
          <p class="text-gray-500">Aprovações Pendentes</p>
          <p class="text-3xl font-semibold text-green-700"><?= count($pendentes ?? []) ?></p>
        </div>
        <div class="bg-white rounded-xl shadow p-6">
          <p class="text-gray-500">Cidadãos</p>
          <p class="text-3xl font-semibold text-green-700"><?= number_format($cidadaos) ?></p>
        </div>
        <div class="bg-white rounded-xl shadow p-6">
          <p class="text-gray-500">Admins Públicos</p>
          <p class="text-3xl font-semibold text-green-700"><?= number_format($adminsPublicos) ?></p>
          <p class="text-xs text-gray-500">Inclui Prefeitos e Secretários</p>
        </div>
        <div class="bg-white rounded-xl shadow p-6">
          <p class="text-gray-500">Pesquisas Recebidas</p>
          <p class="text-3xl font-semibold text-green-700"><?= number_format($pesquisasRecebidas) ?></p>
        </div>
        <div class="bg-white rounded-xl shadow p-6">
          <p class="text-gray-500">Prioridades Recebidas</p>
          <p class="text-3xl font-semibold text-green-700"><?= number_format($prioridadesRecebidas) ?></p>
        </div>
      </div>
    </section>

    <!-- Lista de usuários -->
    <section class="mb-12">
      <div class="flex items-center justify-between mb-3">
        <h2 class="text-xl font-semibold text-gray-800">Lista</h2>
      </div>
      <div class="overflow-x-auto bg-white rounded-xl shadow">
        <table class="min-w-full">
          <thead class="bg-gray-100">
            <tr>
              <th class="px-4 py-2 text-left text-sm text-gray-700">Nome</th>
              <th class="px-4 py-2 text-left text-sm text-gray-700">E-mail</th>
              <th class="px-4 py-2 text-left text-sm text-gray-700">Perfil</th>
              <th class="px-4 py-2 text-left text-sm text-gray-700">Local</th>
              <th class="px-4 py-2 text-left text-sm text-gray-700">Cadastrado</th>
              <th class="px-4 py-2 text-left text-sm text-gray-700">Ações</th>
            </tr>
          </thead>
          <tbody>
            <?php
              $roleLabel = [
                'cidadao' => 'Cidadão',
                'admin_radci' => 'Admin RADCI',
                'admin_publico' => 'Prefeito',
                'secretario' => 'Secretário / Assessor',
              ];
            ?>
            <?php foreach ($filtered as $u): ?>
              <tr class="border-t">
                <td class="px-4 py-2"><?= htmlspecialchars($u['name'] ?? '') ?></td>
                <td class="px-4 py-2"><?= htmlspecialchars($u['email'] ?? '') ?></td>
                <td class="px-4 py-2">
                  <?= htmlspecialchars($roleLabel[$u['role'] ?? 'cidadao'] ?? 'Cidadão') ?>
                  <?php if (in_array(intval($u['id'] ?? 0), $pendentesIds)): ?>
                    <span class="ml-2 inline-block px-2 py-0.5 text-xs rounded bg-yellow-100 text-yellow-800">
                      Solicitação Admin Público
                    </span>
                  <?php endif; ?>
                </td>
                <td class="px-4 py-2">
                  <span class="text-sm text-gray-700">
                    <?php
                      $localParts = [];
                      if (!empty($u['rua']))       { $localParts[] = $u['rua']; }
                      if (!empty($u['bairro']))    { $localParts[] = $u['bairro']; }
                      $cityUf = trim(($u['municipio'] ?? '') . (!empty($u['uf']) ? ' - ' . strtoupper($u['uf']) : ''));
                      if ($cityUf !== '')          { $localParts[] = $cityUf; }
                      if (!empty($u['cep']))       { $localParts[] = $u['cep']; }
                      $localStr = implode(', ', array_filter($localParts, fn($v) => trim($v) !== ''));
                      echo htmlspecialchars($localStr !== '' ? $localStr : 'Não informado');
                    ?>
                  </span>
                </td>
                <td class="px-4 py-2">
                  <span class="text-sm text-gray-700">
                    <?= !empty($u['created_at']) ? htmlspecialchars(date('d/m/Y H:i', strtotime($u['created_at']))) : '—' ?>
                  </span>
                </td>
                <td class="px-4 py-2">
                  <form method="post" class="inline-block" onsubmit="return confirm('Confirmar alteração de perfil?');">
                    <input type="hidden" name="action" value="change_role">
                    <input type="hidden" name="id" value="<?= intval($u['id'] ?? 0) ?>">
                    <?php
                      // valor atual
                      $curRole = $u['role'] ?? 'cidadao';
                      // desabilita alteração para Admin RADCI
                      $disabled = ($curRole === 'admin_radci');
                    ?>
                    <select name="perfil_destino"
                            class="border rounded px-2 py-1 text-sm <?= $disabled ? 'opacity-50 cursor-not-allowed' : '' ?>"
                            <?= $disabled ? 'disabled' : '' ?>
                            onchange="if(!this.disabled){ this.form.submit(); }">
                      <option value="">Alterar perfil…</option>
                      <option value="secretario" <?= $curRole==='secretario' ? 'selected' : '' ?>>Secretário</option>
                      <option value="prefeito"   <?= $curRole==='admin_publico' ? 'selected' : '' ?>>Prefeito</option>
                    </select>
                  </form>

                  <!-- Remover (mantido) -->
                  <form method="post" onsubmit="return confirm('Tem certeza que deseja remover este usuário?');" class="inline-block ml-2">
                    <input type="hidden" name="action" value="remove">
                    <input type="hidden" name="id" value="<?= intval($u['id'] ?? 0) ?>">
                    <button class="px-3 py-1 rounded bg-red-600 text-white hover:bg-red-700">Remover</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (empty($filtered)): ?>
              <tr><td colspan="5" class="px-4 py-6 text-center text-gray-500">Nenhum usuário encontrado.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>
  </main>
</body>
</html>


