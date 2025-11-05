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
    ['id'=>'saude','name'=>'Saúde','icon'=>'<svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" viewBox="0 0 24 24" fill="#4CAF50"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-8.5 14h-1v-4h-4v-1h4V8h1v4h4v1h-4v4z"/></svg>'],
    ['id'=>'inovacao','name'=>'Inovação','icon'=>'<svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" viewBox="0 0 24 24" fill="#FFC107"><path d="M9 21c0 .55.45 1 1 1h4c.55 0 1-.45 1-1v-1H9v1zm3-19C8.14 2 5 5.14 5 9c0 2.38 1.19 4.47 3 5.74V17c0 .55.45 1 1 1h6c.55 0 1-.45 1-1v-2.26c1.81-1.27 3-3.36 3-5.74 0-3.86-3.14-7-7-7zm2.85 11.1l-.85.6V16h-4v-2.3l-.85-.6C7.8 12.16 7 10.63 7 9c0-2.76 2.24-5 5-5s5 2.24 5 5c0 1.63-.8 3.16-2.15 4.1z"/></svg>'],
    ['id'=>'mobilidade','name'=>'Mobilidade','icon'=>'<svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" viewBox="0 0 24 24" fill="#2196F3"><path d="M18.92 6.01C18.72 5.42 18.16 5 17.5 5h-11c-.66 0-1.21.42-1.42 1.01L3 12v8c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-1h12v1c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-8l-2.08-5.99zM6.85 7h10.29l1.08 3.11H5.77L6.85 7zM19 17H5v-5h14v5z"/><circle cx="7.5" cy="14.5" r="1.5"/><circle cx="16.5" cy="14.5" r="1.5"/></svg>'],
    ['id'=>'politicas','name'=>'Políticas Públicas','icon'=>'<svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" viewBox="0 0 24 24" fill="#9C27B0"><path d="M12 3c-4.97 0-9 4.03-9 9s4.03 9 9 9 9-4.03 9-9c0-.46-.04-.92-.1-1.36-.98 1.37-2.58 2.26-4.4 2.26-3.03 0-5.5-2.47-5.5-5.5 0-1.82.89-3.42 2.26-4.4-.44-.06-.9-.1-1.36-.1z"/></svg>'],
    ['id'=>'riscos','name'=>'Riscos Urbanos','icon'=>'<svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" viewBox="0 0 24 24" fill="#FF5722"><path d="M1 21h22L12 2 1 21zm12-3h-2v-2h2v2zm0-4h-2v-4h2v4z"/></svg>'],
    ['id'=>'sustentabilidade','name'=>'Sustentabilidade','icon'=>'<svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" viewBox="0 0 24 24" fill="#8BC34A"><path d="M16 6l2.29 2.29-4.88 4.88-4-4L2 16.59 3.41 18l6-6 4 4 6.3-6.29L22 12V6z"/></svg>'],
    ['id'=>'planejamento','name'=>'Planejamento Urbano','icon'=>'<svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" viewBox="0 0 24 24" fill="#3F51B5"><path d="M15 11V5l-3-3-3 3v2H3v14h18V11h-6zm-8 8H5v-2h2v2zm0-4H5v-2h2v2zm0-4H5V9h2v2zm6 8h-2v-2h2v2zm0-4h-2v-2h2v2zm0-4h-2V9h2v2zm0-4h-2V5h2v2zm6 12h-2v-2h2v2zm0-4h-2v-2h2v2z"/></svg>'],
    ['id'=>'educacao','name'=>'Educação','icon'=>'<svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" viewBox="0 0 24 24" fill="#FF9800"><path d="M5 13.18v4L12 21l7-3.82v-4L12 17l-7-3.82zM12 3L1 9l11 6 9-4.91V17h2V9L12 3z"/></svg>'],
    ['id'=>'meio','name'=>'Meio Ambiente','icon'=>'<svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" viewBox="0 0 24 24" fill="#4CAF50"><path d="M12 22c4.97 0 9-4.03 9-9-4.97 0-9 4.03-9 9zM5.6 10.25c0 1.38 1.12 2.5 2.5 2.5.53 0 1.01-.16 1.42-.44l-.02.19c0 1.38 1.12 2.5 2.5 2.5s2.5-1.12 2.5-2.5l-.02-.19c.4.28.89.44 1.42.44 1.38 0 2.5-1.12 2.5-2.5 0-1-.59-1.85-1.43-2.25.84-.4 1.43-1.25 1.43-2.25 0-1.38-1.12-2.5-2.5-2.5-.53 0-1.01.16-1.42.44l.02-.19C14.5 2.12 13.38 1 12 1S9.5 2.12 9.5 3.5l.02.19c-.4-.28-.89-.44-1.42-.44-1.38 0-2.5 1.12-2.5 2.5 0 1 .59 1.85 1.43 2.25-.84.4-1.43 1.25-1.43 2.25zM12 5.5c1.38 0 2.5 1.12 2.5 2.5s-1.12 2.5-2.5 2.5S9.5 9.38 9.5 8s1.12-2.5 2.5-2.5z"/></svg>'],
    ['id'=>'infraestrutura','name'=>'Infraestrutura da Cidade','icon'=>'<svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" viewBox="0 0 24 24" fill="#607D8B"><path d="M15 11V5l-3-3-3 3v2H3v14h18V11h-6zm-8 8H5v-2h2v2zm0-4H5v-2h2v2zm0-4H5V9h2v2zm6 8h-2v-2h2v2zm0-4h-2v-2h2v2zm0-4h-2V9h2v2zm0-4h-2V5h2v2zm6 12h-2v-2h2v2zm0-4h-2v-2h2v2z"/></svg>'],
    ['id'=>'seguranca','name'=>'Segurança Pública','icon'=>'<svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" viewBox="0 0 24 24" fill="#F44336"><path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm0 10.99h7c-.53 4.12-3.28 7.79-7 8.94V12H5V6.3l7-3.11v8.8z"/></svg>'],
    ['id'=>'energias','name'=>'Energias Inteligentes','icon'=>'<svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" viewBox="0 0 24 24" fill="#FFEB3B"><path d="M7 2v11h3v9l7-12h-4l4-8z"/></svg>'],
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

<div class="container mx-auto px-4 py-8 pb-24 max-w-full">
  <?php if ($readonly): ?>
    <p class="text-gray-600 mb-6">Visualizando suas prioridades definidas anteriormente</p>
  <?php else: ?>
    <p class="text-gray-600 mb-6">Arraste as categorias para ordenar por ordem de importância</p>
  <?php endif; ?>

  <div id="categoryList" class="flex flex-col md:flex-row flex-wrap gap-4 w-full justify-center">
    <?php foreach($categories as $index => $cat): ?>
    <div class="category-card flex items-center gap-4 p-4 <?= $readonly ? 'cursor-default' : 'cursor-move' ?> flex-1 md:basis-[calc(25%-0.75rem)]"
         <?= $readonly ? '' : 'draggable="true"' ?> data-index="<?= $index ?>" data-catid="<?= htmlspecialchars($cat['id']) ?>">
      <div class="w-9 h-9 flex items-center justify-center rounded-full font-bold position-num bg-[#047857] text-white"><?= $index+1 ?></div>
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
    <button type="submit" class="flex-1 bg-[#065f46] text-white py-3 rounded-md hover:bg-[#047857] font-medium shadow">Salvar Prioridades</button>
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
