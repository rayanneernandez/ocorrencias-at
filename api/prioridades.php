<?php
session_start();
require_once __DIR__ . '/../includes/db.php';
$pdo = get_pdo();

// Verifica se está em modo somente leitura
$readonly = isset($_GET['readonly']) && $_GET['readonly'] == '1';

// Trata o POST antes de qualquer saída de HTML para permitir header()
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_priorities']) && !$readonly) {
    $userId = intval($_SESSION['usuario_id'] ?? 0);
    $orderJson = $_POST['order'] ?? '[]';
    $ids = json_decode($orderJson, true);
    if (!is_array($ids)) { $ids = []; }

    // Mapeia IDs do front para colunas da sua tabela `pesquisa` (nomes reais)
    $idToColumn = [
        'saude'           => 'saude',
        'inovacao'        => 'inovacao',           
        'mobilidade'      => 'mobilidade',
        'politicas'       => 'politicasPublicas',
        'riscos'          => 'riscosUrbanos',
        'sustentabilidade'=> 'sustentabilidade',
        'planejamento'    => 'planejamentoUrbano',
        'educacao'        => 'educacao',
        'meio'            => 'meioAmbiente',
        'infraestrutura'  => 'infraestruturaCidade',
        'seguranca'       => 'segurancaPublica',
        'energias'        => 'energiasInteligentes',
    ];

    // Lista de colunas existentes na tabela (adapta ao seu banco)
    $cols = [];
    try {
        $colRows = $pdo->query("SHOW COLUMNS FROM pesquisa")->fetchAll();
        foreach ($colRows as $cr) { $cols[] = $cr['Field']; }
    } catch (Throwable $_) {}

    // Constrói o ranking 1..N e só usa colunas que realmente existem
    $valuesByColumn = [];
    foreach ($ids as $rank => $catId) {
        $col = $idToColumn[$catId] ?? null;
        if ($col && in_array($col, $cols)) {
            $valuesByColumn[$col] = $rank + 1; // ranking 1-based
        }
    }

    // Monta INSERT dinâmico em `pesquisa`
    if ($userId && !empty($valuesByColumn)) {
        $insertCols   = ['idUsuario'];
        $placeholders = ['?'];
        $vals         = [$userId];

        foreach ($valuesByColumn as $col => $val) {
            $insertCols[]   = $col;
            $placeholders[] = '?';
            $vals[]         = $val;
        }
        if (in_array('dataRegistro', $cols)) {
            $insertCols[]   = 'dataRegistro';
            $placeholders[] = 'NOW()'; // literal
        }

        $sql = "INSERT INTO pesquisa (" . implode(',', $insertCols) . ") VALUES (" . implode(',', $placeholders) . ")";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($vals);
    }

    // Marca sessão para esconder no Dashboard e redireciona com confirmação
    $_SESSION['answered_priorities'] = true;
    header('Location: dashboard.php?answered=prioridades');
    exit;
}

$primeiroNome = isset($_SESSION['usuario_nome']) ? explode(" ", trim($_SESSION['usuario_nome']))[0] : "Usuário";

$categories = [
    ['id'=>'saude','name'=>'Saúde','icon'=>'<svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>'],
    ['id'=>'inovacao','name'=>'Inovação','icon'=>'<svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M11 3h2v5h-2V3zM4 12h5M15 12h5M6.343 17.657l1.414-1.414M17.657 6.343l-1.414 1.414"/></svg>'],
    ['id'=>'mobilidade','name'=>'Mobilidade','icon'=>'<svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 17v-6a4 4 0 014-4h4v10h-4a4 4 0 01-4-4z"/></svg>'],
    ['id'=>'politicas','name'=>'Políticas Públicas','icon'=>'<svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/></svg>'],
    ['id'=>'riscos','name'=>'Riscos Urbanos','icon'=>'<svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01"/></svg>'],
    ['id'=>'sustentabilidade','name'=>'Sustentabilidade','icon'=>'<svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 2a10 10 0 00-7.5 17.2L12 22l7.5-2.8A10 10 0 0012 2z"/></svg>'],
    ['id'=>'planejamento','name'=>'Planejamento Urbano','icon'=>'<svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M3 7h18M3 12h18M3 17h18"/></svg>'],
    ['id'=>'educacao','name'=>'Educação','icon'=>'<svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 14l9-5-9-5-9 5 9 5zM12 14v7"/></svg>'],
    ['id'=>'meio','name'=>'Meio Ambiente','icon'=>'<svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 2l4 10H8l4-10z"/></svg>'],
    ['id'=>'infraestrutura','name'=>'Infraestrutura da Cidade','icon'=>'<svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M3 12h18M3 6h18M3 18h18"/></svg>'],
    ['id'=>'seguranca','name'=>'Segurança Pública','icon'=>'<svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 2l7 7-7 7-7-7 7-7z"/></svg>'],
    ['id'=>'energias','name'=>'Energias Inteligentes','icon'=>'<svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>'],
];
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>RADCI - Prioridades</title>
<script src="https://cdn.tailwindcss.com"></script>
<style>
  .hide-scrollbar::-webkit-scrollbar { display: none; }
  .hide-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
  .category-card { transition: all 0.2s ease; border: 1px solid #e5e7eb; border-radius: 12px; background: #fff; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }
  .category-card:hover { box-shadow: 0 6px 16px rgba(16,185,129,0.15); transform: translateY(-1px); }
  .category-card.dragging { opacity: 0.7; transform: scale(1.02); box-shadow: 0 8px 20px rgba(0,0,0,0.15); }
  .position-num { background: linear-gradient(180deg, #22c55e 0%, #16a34a 100%); color: #fff; }
</style>
</head>
<body class="bg-muted/10 min-h-screen pb-28">

<header class="bg-white shadow sticky top-0 z-10">
  <div class="container mx-auto px-4 py-4 flex items-center gap-2">
    <button onclick="window.location.href='dashboard.php'" class="flex items-center text-sm font-medium text-gray-700 hover:text-green-600">
      <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
      Voltar
    </button>
    <h1 class="text-xl font-bold text-gray-800 ml-4">Definir Prioridades</h1>
  </div>
</header>

<div class="container mx-auto px-4 py-8 max-w-full">
  <?php if ($readonly): ?>
    <p class="text-gray-600 mb-6">Visualizando suas prioridades definidas anteriormente</p>
  <?php else: ?>
    <p class="text-gray-600 mb-6">Arraste as categorias para ordenar por ordem de importância</p>
  <?php endif; ?>

  <div id="categoryList" class="flex flex-col md:flex-row flex-wrap gap-4 w-full justify-center">
    <?php foreach($categories as $index => $cat): ?>
    <div class="category-card flex items-center gap-4 p-4 <?= $readonly ? 'cursor-default' : 'cursor-move' ?> flex-1 md:basis-[calc(25%-0.75rem)]"
         <?= $readonly ? '' : 'draggable="true"' ?> data-index="<?= $index ?>" data-catid="<?= htmlspecialchars($cat['id']) ?>">
      <div class="w-9 h-9 flex items-center justify-center rounded-full font-bold position-num"><?= $index+1 ?></div>
      <div class="bg-green-50 rounded-full p-2"><?= $cat['icon'] ?></div>
      <span class="flex-1 font-medium text-gray-800"><?= htmlspecialchars($cat['name']) ?></span>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Formulário POST para salvar -->
  <?php if (!$readonly): ?>
  <form id="prioridadesForm" method="POST" action="prioridades.php" class="flex gap-3 mt-8">
    <button type="button" onclick="window.location.href='dashboard.php'" class="flex-1 border border-gray-300 rounded-md py-3 hover:bg-gray-100 font-medium">Cancelar</button>
    <input type="hidden" name="set_priorities" value="1" />
    <input type="hidden" name="order" id="orderInput" value="[]" />
    <button type="submit" class="flex-1 bg-green-600 text-white py-3 rounded-md hover:bg-green-700 font-medium shadow">Salvar Prioridades</button>
  </form>
  <?php else: ?>
  <div class="flex justify-center mt-8">
    <button type="button" onclick="window.location.href='dashboard.php'" class="px-8 py-3 border border-gray-300 rounded-md hover:bg-gray-100 font-medium">Voltar Tela Inicial</button>
  </div>
  <?php endif; ?>
</div>

<script>
<?php if (!$readonly): ?>
const categoryList = document.getElementById('categoryList');
let draggedItem = null;

function updateNumbers() {
  document.querySelectorAll('.category-card').forEach((card, idx) => {
    card.querySelector('.position-num').textContent = idx + 1;
  });
}

categoryList.addEventListener('dragstart', e => {
  const card = e.target.closest('.category-card');
  if (!card) return;
  draggedItem = card;
  draggedItem.classList.add('dragging');
  // Necessário para habilitar o DnD em alguns navegadores
  if (e.dataTransfer) {
    e.dataTransfer.setData('text/plain', '');
    e.dataTransfer.effectAllowed = 'move';
  }
});

categoryList.addEventListener('dragend', () => {
  if (!draggedItem) return;
  draggedItem.classList.remove('dragging');
  draggedItem = null;
  updateNumbers();
});

// Reordenação mais robusta: usa centro do elemento alvo
categoryList.addEventListener('dragover', e => {
  e.preventDefault();
  const target = e.target.closest('.category-card');
  if (!target || !draggedItem || target === draggedItem) return;

  const rect = target.getBoundingClientRect();
  const isRow = window.matchMedia('(min-width: 768px)').matches;
  const shouldPlaceAfter = isRow
    ? e.clientX > rect.left + rect.width / 2
    : e.clientY > rect.top + rect.height / 2;

  categoryList.insertBefore(draggedItem, shouldPlaceAfter ? target.nextSibling : target);
  updateNumbers();
});

updateNumbers();

// Captura a ordem no submit
document.getElementById('prioridadesForm')?.addEventListener('submit', function() {
  const ids = Array.from(document.querySelectorAll('.category-card')).map(c => c.dataset.catid);
  document.getElementById('orderInput').value = JSON.stringify(ids);
});
<?php endif; ?>
</script>
<?php include __DIR__ . '/../includes/mobile_nav.php'; ?>
</body>
</html>
