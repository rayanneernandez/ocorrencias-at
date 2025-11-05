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
    // tenta adicionar a coluna config caso a tabela já exista sem ela
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
} catch (Throwable $e) {
    $_SESSION['flash_error'] = 'Erro ao preparar tabelas de gestão de secretários: ' . $e->getMessage();
}

// KPIs
$totalSecretarios = (int)$pdo->query("SELECT COUNT(*) FROM usuarios WHERE perfil = 3")->fetchColumn();
$pesquisasRecebidas = 0;
$prioridadesRecebidas = 0;
try { $prioridadesRecebidas = (int)$pdo->query("SELECT COUNT(*) FROM prioridades")->fetchColumn(); } catch (Throwable $_) {}

// Listagem principal
$secretarios = $pdo->query("
  SELECT u.id, u.nome, u.email,
         sp.perfil_id AS perfil_id,
         COALESCE(gp.nome, '') AS perfil_nome
    FROM usuarios u
    LEFT JOIN secretarios_perfis sp ON sp.secretario_id = u.id
    LEFT JOIN gestor_perfis gp ON gp.id = sp.perfil_id
   WHERE u.perfil = 3
   ORDER BY u.id DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Perfis disponíveis
$perfis = $pdo->query("SELECT id, nome, COALESCE(config, '') AS config FROM gestor_perfis ORDER BY nome ASC")->fetchAll(PDO::FETCH_ASSOC);

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
    }
}
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
    <div class="container mx-auto px-6 py-4 flex items-center justify-between">
      <h1 class="text-xl font-bold">RADCI</h1>
      <nav class="space-x-6">
        <a href="prefeito_inicio.php" class="hover:underline">Início</a>
        <a href="gestor_secretarios.php" class="hover:underline font-semibold">Meus Secretários</a>
        <a href="ocorrencias.php" class="hover:underline">Ocorrências</a>
        <a href="relatorios.php" class="hover:underline">Relatório</a>
        <a href="login_cadastro.php?logout=1" class="hover:underline">Sair</a>
      </nav>
    </div>
  </header>

  <main class="container mx-auto px-6 py-8 max-w-6xl">
    <?php if (!empty($flash)): ?>
    <div class="mb-4 bg-green-100 border border-green-300 text-green-800 px-4 py-3 rounded">
      <?= htmlspecialchars($flash) ?>
    </div>
    <?php endif; ?>

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
        <p class="text-sm text-gray-600">Prioridades recebidas</p>
        <p class="text-3xl font-semibold text-gray-900"><?= number_format($prioridadesRecebidas) ?></p>
      </div>
    </div>

    <div class="flex gap-3 mb-6">
      <button id="btnSecretarios" class="px-4 py-2 rounded-md bg-green-700 text-white">Gerenciar Secretários</button>
      <button id="btnPerfis" class="px-4 py-2 rounded-md bg-blue-600 text-white">Gerenciar Perfis</button>
    </div>

    <div class="bg-white rounded-xl shadow border">
      <table class="min-w-full text-left">
        <thead class="bg-gray-50 text-gray-700">
          <tr>
            <th class="px-4 py-3 w-12">#</th>
            <th class="px-4 py-3">Usuário</th>
            <th class="px-4 py-3">E-mail</th>
            <th class="px-4 py-3">Perfil</th>
            <th class="px-4 py-3 w-24 text-right">Ações</th>
          </tr>
        </thead>
        <tbody class="text-gray-800">
          <?php foreach ($secretarios as $idx => $s): ?>
            <tr class="<?= $idx % 2 === 0 ? 'bg-white' : 'bg-gray-50' ?>">
              <td class="px-4 py-3"><?= (int)$s['id'] ?></td>
              <td class="px-4 py-3 font-medium"><?= htmlspecialchars($s['nome']) ?></td>
              <td class="px-4 py-3"><?= htmlspecialchars($s['email']) ?></td>
              <td class="px-4 py-3"><?= htmlspecialchars($s['perfil_nome'] ?: '-') ?></td>
              <td class="px-4 py-3 text-right relative" data-actions="1">
                <button
                  class="px-3 py-2 rounded-full bg-gray-100 hover:bg-gray-200"
                  onclick="toggleMenu(<?= (int)$s['id'] ?>)">
                  ▼
                </button>
                <div id="menu-<?= (int)$s['id'] ?>" class="absolute right-0 mt-2 w-36 bg-white border rounded-md shadow-lg hidden z-20">
                  <button
                    class="block w-full text-left px-4 py-2 hover:bg-gray-100"
                    onclick="openAssign(<?= (int)$s['id'] ?>, '<?= htmlspecialchars($s['nome'], ENT_QUOTES) ?>', <?= (int)($s['perfil_id'] ?? 0) ?>); toggleMenu(<?= (int)$s['id'] ?>);">
                    Alterar Perfil
                  </button>
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
              <input type="hidden" id="assignSecId" name="secretario_id" value="" />

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

    // Editor de Perfil: abrir (novo/alterar) e preencher
    function openPerfilEditor(mode, id) {
      openModal('modalPerfilEditor');
      setupPerfilEditorOptions();
    
      const idInput   = document.getElementById('perfilEditId');
      const nomeInput = document.getElementById('perfilEditNome');
    
      // limpa
      idInput.value = '';
      nomeInput.value = '';
      setMSValues('ms-pesquisas',  []);
      setMSValues('ms-sugestoes',  []);
      setMSValues('ms-elogios',    []);
      setMSValues('ms-reclamacao', []);
      document.getElementById('acessosEstado').value = 'RJ';
      document.getElementById('acessosMunicipio').value = '';
      document.getElementById('acessosBairros').value = '';
      document.getElementById('perfilEditConfig').value = '{}';
    
      if (mode === 'edit' && id) {
        const perfil = perfisData.find(p => String(p.id) === String(id));
        if (perfil) {
          idInput.value   = String(perfil.id);
          nomeInput.value = perfil.nome || '';
          let cfg = {};
          try { cfg = perfil.config ? JSON.parse(perfil.config) : {}; } catch(_) { cfg = {}; }
    
          setMSValues('ms-pesquisas',  cfg.pesquisas);
          setMSValues('ms-sugestoes',  cfg.sugestoes);
          setMSValues('ms-elogios',    cfg.elogios);
          setMSValues('ms-reclamacao', cfg.reclamacao);
          document.getElementById('acessosEstado').value    = (cfg.estado || 'RJ');
          document.getElementById('acessosMunicipio').value = (cfg.municipio || '');
          document.getElementById('acessosBairros').value   = (cfg.bairros || '');
    
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
      el.value = el.value; // mantenha a digitação, pode evoluir para chips depois
    }
    function limparBairros() {
      const el = document.getElementById('acessosBairros');
      el.value = '';
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
      <div class="bg-white rounded-2xl w-full max-w-3xl shadow-2xl overflow-hidden">
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
                      <button class="px-3 py-2 rounded-full bg-gray-100 hover:bg-gray-200" onclick="togglePerfilMenu(<?= (int)$p['id'] ?>)">▼</button>
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
      <div class="bg-white rounded-2xl w-full max-w-4xl shadow-2xl overflow-hidden">
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
        <form id="perfilEditForm" method="POST" class="px-6 py-4 space-y-6 max-h-[70vh] overflow-y-auto">
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
                  <input type="text" data-ms-search placeholder="Buscar..." class="w-full border rounded-md p-2" />
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
                      <input type="text" data-ms-search placeholder="Buscar..." class="w-full border rounded-md p-2" />
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
                      <input type="text" data-ms-search placeholder="Buscar..." class="w-full border rounded-md p-2" />
                    </div>
                  </div>
                  <input type="hidden" data-ms-hidden id="driversElogios" value="[]">
                </div>
              </div>
            </div>
    
            <!-- Removido: campo "Outros" -->
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
                    <input type="text" data-ms-search placeholder="Buscar..." class="w-full border rounded-md p-2" />
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