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

$flash = '';

$perfilMap = [
    'cidadao'       => 1,
    'admin_radci'   => 2,
    'admin_publico' => 3,
    'secretario'    => 4,
];
$perfilLabelMap = [
    1 => 'Cidadão',
    2 => 'Admin RADCI',
    3 => 'Administrador Público',
    4 => 'Secretário / Assessor',
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
}

// Busca (GET ?q=...) e listagem
$search = trim($_GET['q'] ?? '');
if ($search !== '') {
    $like = '%'.$search.'%';
    $stmt = $pdo->prepare("SELECT u.*, 0 AS answered FROM usuarios u WHERE u.nome LIKE ? OR u.email LIKE ? ORDER BY u.id DESC");
    $stmt->execute([$like, $like]);
    $users = $stmt->fetchAll();
} else {
    $users = $pdo->query("SELECT u.*, 0 AS answered FROM usuarios u ORDER BY u.id DESC")->fetchAll();
}

// Mapeia linhas do banco para o formato esperado pelo frontend
$perfilReverse = [
    1 => 'cidadao',
    2 => 'admin_radci',
    3 => 'admin_publico',
    4 => 'secretario',
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
    ];
}, $users ?? []);

// Estatísticas
$total = count($filtered);
$cidadaos = count(array_filter($filtered, fn($u) => ($u['role'] ?? '') === 'cidadao'));
$adminsPublicos = count(array_filter($filtered, fn($u) => ($u['role'] ?? '') === 'admin_publico'));
$pesquisasRecebidas = 22;     // demonstrativo
$prioridadesRecebidas = 13;   // demonstrativo
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8" />
    <title>Usuários - RADCI</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://unpkg.com/lucide@latest"></script>
    <style>
        <?php 
        $cssPath = __DIR__ . '/../assets/css/style.css';
        if (file_exists($cssPath)) {
            echo file_get_contents($cssPath);
        }
        ?>
    </style>
</head>
<body class="bg-white min-h-screen">
    <!-- Header Admin -->
    <header class="bg-green-700 text-white">
        <div class="container mx-auto px-6 py-5 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div class="bg-white/20 p-2 rounded-lg">
                    <i data-lucide="map-pin" class="w-6 h-6 text-white"></i>
                </div>
                <div>
                    <div class="text-2xl font-bold tracking-wide">RADCI</div>
                    <div class="text-sm">Radar de Avaliações dos Drivers de uma Cidade mais Inteligente</div>
                </div>
            </div>
            <nav class="hidden md:flex items-center gap-8 text-white/90">
                <a href="usuarios.php" class="text-white">Usuários</a>
                <a href="relatorios.php" class="hover:text-white">Relatórios</a>
                <a href="criar_pesquisa.php" class="hover:text-white">Criar Pesquisa</a>
                <a href="principal.php" class="hover:text-white">Sair</a>
            </nav>
        </div>
    </header>

    <main class="container mx-auto px-6 py-8 max-w-6xl">
        <a href="principal.php" class="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-gray-100 text-gray-700 hover:bg-gray-200 mb-6">
            <i data-lucide="arrow-left" class="w-4 h-4"></i>
            Voltar
        </a>

        <h1 class="text-3xl font-bold text-gray-900 mb-2">Usuários</h1>

        <?php if ($flash): ?>
            <div class="mb-6 bg-green-50 border border-green-200 text-green-800 rounded-lg p-4">
                <?= htmlspecialchars($flash) ?>
            </div>
        <?php endif; ?>

        <!-- Cards de estatísticas -->
        <div class="grid lg:grid-cols-3 gap-4 mb-6">
            <div class="border rounded-xl p-4">
                <div class="flex items-center gap-3 mb-2">
                    <i data-lucide="users" class="w-6 h-6 text-green-700"></i>
                    <div class="font-semibold text-gray-900">Total de Usuários</div>
                </div>
                <div class="text-2xl font-bold text-gray-900 mb-3"><?= $total ?></div>
                <div class="flex gap-2 flex-wrap">
                    <span class="px-3 py-1 rounded-full text-sm bg-green-50 border border-green-200 text-green-700"><?= $cidadaos ?> Cidadãos</span>
                    <span class="px-3 py-1 rounded-full text-sm bg-green-50 border border-green-200 text-green-700"><?= $adminsPublicos ?> Admin. Públicos</span>
                </div>
            </div>

            <div class="border rounded-xl p-4">
                <div class="flex items-center gap-3 mb-2">
                    <i data-lucide="search" class="w-6 h-6 text-green-700"></i>
                    <div class="font-semibold text-gray-900">Pesquisas recebidas</div>
                </div>
                <div class="text-2xl font-bold text-gray-900"><?= $pesquisasRecebidas ?></div>
            </div>

            <div class="border rounded-xl p-4">
                <div class="flex items-center gap-3 mb-2">
                    <i data-lucide="alert-octagon" class="w-6 h-6 text-green-700"></i>
                    <div class="font-semibold text-gray-900">Prioridades recebidas</div>
                </div>
                <div class="text-2xl font-bold text-gray-900"><?= $prioridadesRecebidas ?></div>
            </div>
        </div>

        <!-- Ações de topo -->
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-4">
            <form method="GET" class="flex items-center gap-2">
                <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Buscar por nome ou e-mail"
                       class="w-64 rounded-md border-gray-300" />
                <button class="px-4 py-2 rounded-md bg-green-600 text-white hover:bg-green-700">Buscar</button>
                <?php if ($search): ?>
                    <a href="usuarios.php" class="px-4 py-2 rounded-md bg-gray-100 text-gray-700 hover:bg-gray-200">Limpar</a>
                <?php endif; ?>
            </form>

            <button id="btnAddUser" class="px-4 py-2 rounded-md bg-green-600 text-white hover:bg-green-700 inline-flex items-center gap-2">
                <i data-lucide="plus" class="w-4 h-4"></i>
                Adicionar Usuário
            </button>
        </div>

        <!-- Tabela -->
        <div class="overflow-x-auto border rounded-xl">
            <table class="min-w-full text-left">
                <thead class="bg-gray-50 text-gray-700">
                    <tr>
                        <th class="px-4 py-3 w-12">#</th>
                        <th class="px-4 py-3">Usuário</th>
                        <th class="px-4 py-3">E-mail</th>
                        <th class="px-4 py-3">Perfil</th>
                        <th class="px-4 py-3">Respondeu Pesquisa</th>
                        <th class="px-4 py-3 w-24 text-right">Ações</th>
                    </tr>
                </thead>
                <tbody class="text-gray-800">
                    <?php foreach($filtered as $idx => $u): ?>
                        <tr class="<?= $idx % 2 === 0 ? 'bg-white' : 'bg-gray-50' ?>">
                            <td class="px-4 py-3"><?= $u['id'] ?></td>
                            <td class="px-4 py-3 font-medium"><?= htmlspecialchars($u['name']) ?></td>
                            <td class="px-4 py-3"><?= htmlspecialchars($u['email']) ?></td>
                            <td class="px-4 py-3">
                                <?php
                                  $roleLabel = [
                                    'cidadao' => 'Cidadão',
                                    'admin_radci' => 'Administrador RADCI',
                                    'admin_publico' => 'Administrador Público',
                                    'secretario' => 'Secretários, Assessores e outros'
                                  ][$u['role']] ?? $u['role'];
                                  echo htmlspecialchars($roleLabel);
                                ?>
                            </td>
                            <td class="px-4 py-3"><?= $u['answered'] ? 'Sim' : 'Perfil não elegível' ?></td>
                            <td class="px-4 py-3 text-right">
                                <button
                                  class="rowMenuBtn px-3 py-2 rounded-full bg-gray-100 hover:bg-gray-200"
                                  data-id="<?= $u['id'] ?>"
                                  data-user='<?= htmlspecialchars(json_encode($u), ENT_QUOTES) ?>'>
                                  <i data-lucide="chevron-down" class="w-4 h-4"></i>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($filtered)): ?>
                        <tr><td colspan="6" class="px-4 py-6 text-center text-gray-500">Nenhum usuário encontrado.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Dropdown de ações (renderizado dinamicamente) -->
        <div id="rowMenu" class="hidden absolute bg-white border border-gray-200 rounded-md shadow-lg z-30 min-w-[160px]">
            <button data-action="edit" class="block w-full text-left px-4 py-2 hover:bg-gray-50">Editar</button>
            <form data-action="remove" method="POST" class="border-t border-gray-100">
                <input type="hidden" name="action" value="remove">
                <input type="hidden" name="id" value="">
                <button class="block w-full text-left px-4 py-2 text-red-700 hover:bg-red-50">Remover</button>
            </form>
        </div>

        <!-- Modal (Adicionar/Editar) -->
        <div id="userModal" class="fixed inset-0 bg-black/40 hidden z-40 items-center justify-center p-4">
          <div class="bg-white w-full max-w-4xl rounded-2xl shadow-xl overflow-hidden">
            <div class="p-6 border-b flex items-center justify-between">
              <h3 id="modalTitle" class="text-xl font-semibold">Editar usuário</h3>
              <button id="modalClose" class="text-gray-500 hover:text-gray-700 text-2xl leading-none">&times;</button>
            </div>
        
            <form id="userForm" method="POST" class="p-6 space-y-6">
              <input type="hidden" name="action" value="edit">
              <input type="hidden" name="id" value="">
        
              <!-- Perfil -->
              <div>
                <label class="block text-sm text-gray-700 mb-1">Perfil</label>
                <select name="role" class="w-full rounded-md border border-gray-300 bg-gray-50 shadow-sm focus:border-green-500 focus:ring-1 focus:ring-green-500">
                  <option value="cidadao">Cidadão</option>
                  <option value="admin_radci">Administrador RADCI</option>
                  <option value="admin_publico">Administrador Público</option>
                  <option value="secretario">Secretários, Assessores e outros</option>
                </select>
              </div>

              <!-- Grid principal -->
              <div class="grid md:grid-cols-2 gap-4">
                <div>
                  <label class="block text-sm text-gray-700 mb-1">Nome Completo</label>
                  <input name="name" type="text" class="w-full rounded-md border border-gray-300 bg-gray-50 shadow-sm focus:border-green-500 focus:ring-1 focus:ring-green-500" />
                </div>
                <div>
                  <label class="block text-sm text-gray-700 mb-1">E-mail institucional</label>
                  <input name="email" type="email" class="w-full rounded-md border border-gray-300 bg-gray-50 shadow-sm focus:border-green-500 focus:ring-1 focus:ring-green-500" />
                </div>

                <div>
                  <label class="block text-sm text-gray-700 mb-1">CEP</label>
                  <input name="cep" type="text" class="w-full rounded-md border border-gray-300 bg-gray-50 shadow-sm focus:border-green-500 focus:ring-1 focus:ring-green-500" placeholder="00000-000" />
                </div>
                <div>
                  <label class="block text-sm text-gray-700 mb-1">UF</label>
                  <input name="uf" type="text" class="w-full rounded-md border border-gray-300 bg-gray-50 shadow-sm focus:border-green-500 focus:ring-1 focus:ring-green-500" placeholder="RJ" />
                </div>

                <div>
                  <label class="block text-sm text-gray-700 mb-1">Município</label>
                  <input name="municipio" type="text" class="w-full rounded-md border border-gray-300 bg-gray-50 shadow-sm focus:border-green-500 focus:ring-1 focus:ring-green-500" />
                </div>
                <div>
                  <label class="block text-sm text-gray-700 mb-1">Bairro</label>
                  <input name="bairro" type="text" class="w-full rounded-md border border-gray-300 bg-gray-50 shadow-sm focus:border-green-500 focus:ring-1 focus:ring-green-500" />
                </div>

                <!-- Rua (2 colunas) + Complemento (1 coluna) -->
                <div class="md:col-span-2 grid md:grid-cols-3 gap-4">
                  <div class="md:col-span-2">
                    <label class="block text-sm text-gray-700 mb-1">Rua</label>
                    <input name="rua" type="text" class="w-full rounded-md border border-gray-300 bg-gray-50 shadow-sm focus:border-green-500 focus:ring-1 focus:ring-green-500" />
                  </div>
                  <div class="md:col-span-1">
                    <label class="block text-sm text-gray-700 mb-1">Complemento</label>
                    <input name="complemento" type="text" class="w-full rounded-md border border-gray-300 bg-gray-50 shadow-sm focus:border-green-500 focus:ring-1 focus:ring-green-500" />
                  </div>
                </div>
              </div>
        
              <!-- Respondeu pesquisa -->
              <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                <input type="checkbox" name="answered" class="accent-green-600">
                Respondeu Pesquisa
              </label>
        
              <div class="flex items-center justify-end gap-3 pt-2">
                <button type="button" id="modalCancel" class="px-6 py-2 rounded-full bg-gray-100 text-gray-700 hover:bg-gray-200">Cancelar</button>
                <button class="px-6 py-2 rounded-full bg-green-600 text-white hover:bg-green-700">Salvar</button>
              </div>
            </form>
          </div>
        </div>
    </main>

    <script>
    document.addEventListener('DOMContentLoaded', () => {
        if (window.lucide && typeof lucide.createIcons === 'function') {
            lucide.createIcons();
        }

        const rowMenu = document.getElementById('rowMenu');
        const removeForm = rowMenu.querySelector('form[data-action="remove"]');

        const userModal   = document.getElementById('userModal');
        const modalTitle  = document.getElementById('modalTitle');
        const modalClose  = document.getElementById('modalClose');
        const modalCancel = document.getElementById('modalCancel');
        const userForm    = document.getElementById('userForm');
        const btnAddUser  = document.getElementById('btnAddUser');

        function openModal(mode, payload = null) {
            userModal.classList.remove('hidden');
            userModal.classList.add('flex');

            const fAction = userForm.querySelector('input[name="action"]');
            const fId     = userForm.querySelector('input[name="id"]');

            if (mode === 'add') {
                modalTitle.textContent = 'Adicionar usuário';
                fAction.value = 'add';
                fId.value = '';
                userForm.name.value = '';
                userForm.email.value = '';
                userForm.role.value = 'cidadao';
                userForm.answered.checked = false;
                userForm.cep.value = '';
                userForm.uf.value = '';
                userForm.municipio.value = '';
                userForm.bairro.value = '';
                userForm.rua.value = '';
                userForm.complemento.value = '';
            } else {
                modalTitle.textContent = 'Editar usuário';
                fAction.value = 'edit';
                fId.value = payload.id ?? '';
                userForm.name.value = payload.name ?? '';
                userForm.email.value = payload.email ?? '';
                userForm.role.value = payload.role ?? 'cidadao';
                userForm.answered.checked = !!payload.answered;
                userForm.cep.value = payload.cep ?? '';
                userForm.uf.value = payload.uf ?? '';
                userForm.municipio.value = payload.municipio ?? '';
                userForm.bairro.value = payload.bairro ?? '';
                userForm.rua.value = payload.rua ?? '';
                userForm.complemento.value = payload.complemento ?? '';
            }
        }

        function closeModal() {
            userModal.classList.add('hidden');
            userModal.classList.remove('flex');
        }

        btnAddUser?.addEventListener('click', () => openModal('add'));
        modalClose?.addEventListener('click', closeModal);
        modalCancel?.addEventListener('click', closeModal);

        // Dropdown de ações por linha (abrir/editar/remover)
        document.querySelectorAll('.rowMenuBtn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const id = btn.getAttribute('data-id');
                const data = btn.getAttribute('data-user');
                const payload = data ? JSON.parse(data) : { id };

                const rect = btn.getBoundingClientRect();
                rowMenu.style.top = (window.scrollY + rect.bottom + 4) + 'px';
                rowMenu.style.left = (window.scrollX + rect.right - 160) + 'px';
                removeForm.querySelector('input[name="id"]').value = id;
                rowMenu.classList.remove('hidden');

                rowMenu.querySelector('[data-action="edit"]').onclick = () => {
                    rowMenu.classList.add('hidden');
                    openModal('edit', payload);
                };
            });
        });

            // Auto-preenche endereço a partir do CEP (ViaCEP via API local)
    const cepInput = userForm.querySelector('input[name="cep"]');

    function formatCepDigits(raw) {
        const d = String(raw).replace(/\D/g,'').slice(0, 8);
        return d.length <= 5 ? d : d.slice(0,5) + '-' + d.slice(5);
    }

    async function fillFromCep(cepDigits) {
        try {
            const res = await fetch(`../api/viacep.php?cep=${encodeURIComponent(cepDigits)}`);
            if (!res.ok) return;
            const data = await res.json();
            if (data?.erro) return;
            userForm.uf.value          = data.uf || '';
            userForm.municipio.value   = data.localidade || '';
            userForm.rua.value         = data.logradouro || '';
            userForm.bairro.value      = data.bairro || '';
            userForm.complemento.value = data.complemento || '';
        } catch (_) {
            // Silencioso: não quebra o fluxo se a consulta falhar
        }
    }

    // Máscara do CEP enquanto digita
    cepInput.addEventListener('input', (e) => {
        e.target.value = formatCepDigits(e.target.value);
    });

    // Busca e preenche ao sair do campo
    cepInput.addEventListener('blur', (e) => {
        const digits = (e.target.value || '').replace(/\D/g,'');
        if (digits.length === 8) fillFromCep(digits);
    });

        // Fechar dropdown ao clicar fora
        document.addEventListener('click', (e) => {
            if (!rowMenu.contains(e.target)) rowMenu.classList.add('hidden');
        });
    });
    </script>
</body>
</html>