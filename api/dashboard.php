<?php
session_start();
require_once __DIR__ . '/../includes/db.php';
$pdo = get_pdo();

// Dados do usuário
$userId       = intval($_SESSION['usuario_id'] ?? 0);
$userNome     = trim($_SESSION['usuario_nome'] ?? ($_SESSION['usuario']['nome'] ?? 'Usuário'));
$primeiroNome = explode(' ', $userNome)[0];

// Categorias (ícones simples)
$categories = [
  ['id'=>'saude','name'=>'Saúde','icon'=>'<svg xmlns="http://www.w3.org/2000/svg" class="w-7 h-7 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>'],
  ['id'=>'inovacao','name'=>'Inovação','icon'=>'<svg xmlns="http://www.w3.org/2000/svg" class="w-7 h-7 text-yellow-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M9 18h6M10 22h4M12 2a7 7 0 0 0-7 7c0 3 2 4 3 5l1 1h6l1-1c1-1 3-2 3-5a7 7 0 0 0-7-7z"/></svg>'],
  ['id'=>'mobilidade','name'=>'Mobilidade','icon'=>'<svg xmlns="http://www.w3.org/2000/svg" class="w-7 h-7 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="3" y="10" width="18" height="5" rx="2"/><circle cx="7" cy="17" r="2"/><circle cx="17" cy="17" r="2"/></svg>'],
  ['id'=>'politicas','name'=>'Políticas Públicas','icon'=>'<svg xmlns="http://www.w3.org/2000/svg" class="w-7 h-7 text-purple-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M8 13h8M8 17h8"/></svg>'],
  ['id'=>'riscos','name'=>'Riscos Urbanos','icon'=>'<svg xmlns="http://www.w3.org/2000/svg" class="w-7 h-7 text-orange-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>'],
  ['id'=>'sustentabilidade','name'=>'Sustentabilidade','icon'=>'<svg xmlns="http://www.w3.org/2000/svg" class="w-7 h-7 text-green-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M11 3c-4 9 1 14 9 10-3 5-9 7-12 4S4 9 11 3z"/></svg>'],
  ['id'=>'planejamento','name'=>'Planejamento Urbano','icon'=>'<svg xmlns="http://www.w3.org/2000/svg" class="w-7 h-7 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="18"/><rect x="14" y="8" width="7" height="13"/></svg>'],
  ['id'=>'educacao','name'=>'Educação','icon'=>'<svg xmlns="http://www.w3.org/2000/svg" class="w-7 h-7 text-teal-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M22 12l-10-5-10 5 10 5 10-5z"/><path d="M6 16v2c0 1.1.9 2 2 2h8"/></svg>'],
  ['id'=>'meio','name'=>'Meio Ambiente','icon'=>'<svg xmlns="http://www.w3.org/2000/svg" class="w-7 h-7 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M12 2c3 6 1 10-4 12 2 4 6 5 9 2s4-7-2-14c-1 0-2 0-3 0z"/></svg>'],
  ['id'=>'infraestrutura','name'=>'Infraestrutura da Cidade','icon'=>'<svg xmlns="http://www.w3.org/2000/svg" class="w-7 h-7 text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>'],
  ['id'=>'seguranca','name'=>'Segurança Pública','icon'=>'<svg xmlns="http://www.w3.org/2000/svg" class="w-7 h-7 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M12 2l8 4v6c0 5-4 9-8 10-4-1-8-5-8-10V6l8-4z"/></svg>'],
  ['id'=>'energias','name'=>'Energias Inteligentes','icon'=>'<svg xmlns="http://www.w3.org/2000/svg" class="w-7 h-7 text-yellow-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>'],
];

// Busca ocorrências do usuário no banco de dados
$ocorrencias = [];

error_log("Dashboard - Carregando ocorrências para usuário ID: $userId");

if ($userId > 0) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                id,
                numero,
                endereco as local,
                cep,
                tipo as categoria,
                descricao,
                latitude as lat,
                longitude as lng,
                arquivos,
                tem_imagens,
                status,
                DATE_FORMAT(data_criacao, '%d/%m/%Y') as data,
                data_criacao
            FROM ocorrencias 
            WHERE usuario_id = ? 
            ORDER BY data_criacao DESC 
            LIMIT 10
        ");
        $stmt->execute([$userId]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        error_log("Dashboard - Encontradas " . count($results) . " ocorrências no banco");
        
        foreach ($results as $row) {
            error_log("Dashboard - Processando ocorrência ID: " . $row['id'] . ", Tipo: " . $row['categoria']);
            
            $arquivos = json_decode($row['arquivos'] ?? '[]', true) ?: [];
            $primeiraImagem = '';
            $todasImagens = [];
            
            // Processa arquivos para extrair imagens
            foreach ($arquivos as $arquivo) {
                if (isset($arquivo['url']) && preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $arquivo['url'])) {
                    $imageUrl = '/' . $arquivo['url'];  // Ajustado para caminho absoluto
                    $todasImagens[] = $imageUrl;
                    if (empty($primeiraImagem)) {
                        $primeiraImagem = $imageUrl;
                    }
                }
            }

            // Se não há imagem, usa placeholder
            if (empty($primeiraImagem)) {
                $primeiraImagem = 'https://images.unsplash.com/photo-1509223197845-458d87318791';
            }
            
            $ocorrencia = [
                'id' => $row['id'],
                'numero' => $row['numero'] ?? 'N/A',
                'imagem' => $primeiraImagem,
                'thumb' => $primeiraImagem,
                'descricao' => $row['descricao'],
                'categoria' => $row['categoria'],
                'data' => $row['data'],
                'status' => $row['status'],
                'detalhes' => $row['descricao'],
                'imagens' => $todasImagens,
                'tem_imagens' => $row['tem_imagens'] ?? 'Não',
                'local' => $row['local'],
                'lat' => $row['lat'],
                'lng' => $row['lng']
            ];
            
            $ocorrencias[] = $ocorrencia;
            error_log("Dashboard - Ocorrência adicionada: " . json_encode($ocorrencia));
        }
        
        error_log("Dashboard - Total de ocorrências carregadas: " . count($ocorrencias));
    } catch (Exception $e) {
        error_log("Erro ao buscar ocorrências: " . $e->getMessage());
    }
} else {
    error_log("Dashboard - Usuário não logado ou ID inválido");
}

// Verifica se usuário já respondeu pesquisa de prioridades (tabela pesquisa com idUsuario)
$hasAnsweredPriorities = false;
try {
  if ($userId) {
    $stmt = $pdo->prepare("SELECT 1 FROM pesquisa WHERE idUsuario = ? LIMIT 1");
    $stmt->execute([$userId]);
    $hasAnsweredPriorities = (bool)$stmt->fetchColumn();
  }
} catch (Throwable $_) {
  $hasAnsweredPriorities = false;
}

// Monta ordem das prioridades caso já tenha respondido
$prioridadesOrder = [];
if ($hasAnsweredPriorities) {
  try {
    // Mapa: coluna do banco -> id da categoria do front
    $colToCat = [
      'saude'                 => 'saude',
      'inovacao'              => 'inovacao',             // se não existir/for nula, será ignorado
      'mobilidade'            => 'mobilidade',
      'politicasPublicas'     => 'politicas',
      'riscosUrbanos'         => 'riscos',
      'sustentabilidade'      => 'sustentabilidade',
      'planejamentoUrbano'    => 'planejamento',
      'educacao'              => 'educacao',
      'meioAmbiente'          => 'meio',
      'infraestruturaCidade'  => 'infraestrutura',
      'segurancaPublica'      => 'seguranca',
      'energiasInteligentes'  => 'energias',
    ];
    $dbCols = array_keys($colToCat);

    $sql  = "SELECT ".implode(',', $dbCols)." FROM pesquisa WHERE idUsuario = ? ORDER BY id DESC LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId]);
    $row  = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    // pares: idCategoriaDoFront => ranking
    $pairs = [];
    foreach ($dbCols as $dbCol) {
      if (isset($row[$dbCol])) {
        $r = intval($row[$dbCol]);
        if ($r > 0) {
          $frontId = $colToCat[$dbCol];
          $pairs[$frontId] = $r;
        }
      }
    }

    if ($pairs) {
      asort($pairs, SORT_NUMERIC); // menor número = maior prioridade
      $catMap = array_column($categories, 'name', 'id'); // id => nome
      foreach (array_keys($pairs) as $cid) {
        $prioridadesOrder[] = $catMap[$cid] ?? ucfirst($cid);
      }
    }
  } catch (Throwable $_) {}
}

$availableSurveys = [];
$answeredSurveys  = [];

// Se respondeu prioridades, aparece como respondida, incluindo a ordem
if ($hasAnsweredPriorities) {
  $answeredSurveys[] = [
    'sid'         => 'prioridades',
    'title'       => 'Pesquisa de Prioridades',
    'description' => 'Ordene as prioridades da sua cidade',
    'order'       => $prioridadesOrder
  ];
}

$surveyDir = __DIR__ . '/../uploads/surveys';
if (is_dir($surveyDir)) {
  foreach (glob($surveyDir . '/*.json') as $file) {
    $meta = [];
    try { $meta = json_decode(file_get_contents($file), true) ?: []; } catch (Throwable $_) {}
    $sid   = $meta['sid'] ?? basename($file, '.json');
    $title = $meta['title'] ?? ($meta['titulo'] ?? 'Pesquisa');
    $desc  = $meta['description'] ?? ($meta['descricao'] ?? '');

    if (!empty($_SESSION['answered_surveys'][$sid])) {
      $answeredSurveys[] = ['sid'=>$sid,'title'=>$title,'description'=>$desc];
    } else {
      $availableSurveys[] = ['sid'=>$sid,'title'=>$title,'description'=>$desc];
    }
  }
}

// Métricas
$totRegistradas = 0;
$totConcluidas  = 0;
$totAndamento   = 0;
foreach ($ocorrencias as $o) {
  $status = strtolower($o['status'] ?? 'registrada');
  if (in_array($status, ['concluída', 'concluida'])) $totConcluidas++;
  elseif (in_array($status, ['andamento', 'em andamento', 'em análise', 'em analise'])) $totAndamento++;
  else $totRegistradas++;
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>RADCI - Painel Cidadão</title>

<!-- Leaflet -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<style>
  /* Reset básico */
  * {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
  }

  body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    background-color: #f9fafb;
    color: #111827;
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    padding-bottom: 5rem;
  }

  /* Layout básico */
  .flex { display: flex; }
  .flex-col { flex-direction: column; }
  .items-center { align-items: center; }
  .items-start { align-items: flex-start; }
  .justify-center { justify-content: center; }
  .justify-between { justify-content: space-between; }
  .min-h-screen { min-height: 100vh; }
  .w-full { width: 100%; }
  .h-6 { height: 1.5rem; }
  .w-6 { width: 1.5rem; }
  .h-5 { height: 1.25rem; }
  .w-5 { width: 1.25rem; }
  .w-4 { width: 1rem; }
  .h-4 { height: 1rem; }
  .w-7 { width: 1.75rem; }
  .h-7 { height: 1.75rem; }
  .w-10 { width: 2.5rem; }
  .h-10 { height: 2.5rem; }
  .w-14 { width: 3.5rem; }
  .h-14 { height: 3.5rem; }
  .w-16 { width: 4rem; }
  .h-16 { height: 4rem; }
  .h-9 { height: 2.25rem; }
  .h-32 { height: 8rem; }

  /* Grid */
  .grid { display: grid; }
  .grid-cols-1 { grid-template-columns: repeat(1, minmax(0, 1fr)); }
  .gap-3 { gap: 0.75rem; }
  .gap-4 { gap: 1rem; }
  .gap-6 { gap: 1.5rem; }

  /* Modal de notificações */
  .notification-item {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 16px;
    border-bottom: 1px solid #e5e7eb;
    transition: background-color 0.2s;
  }
  
  .notification-item:hover {
    background-color: #f9fafb;
  }
  
  .notification-item:last-child {
    border-bottom: none;
  }
  
  .notification-icon {
    font-size: 20px;
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    background-color: #f3f4f6;
    border-radius: 50%;
    flex-shrink: 0;
  }
  
  .notification-content h4 {
    margin: 0 0 4px 0;
    font-size: 14px;
    font-weight: 600;
    color: #1f2937;
  }
  
  .notification-content p {
    margin: 0 0 4px 0;
    font-size: 13px;
    color: #6b7280;
    line-height: 1.4;
  }
  
  .notification-content small {
    font-size: 11px;
    color: #9ca3af;
  }

  .notification-content button {
    margin-top: 8px;
    background-color: #059669;
    color: white;
    padding: 4px 12px;
    border-radius: 4px;
    font-size: 12px;
    border: none;
    cursor: pointer;
    transition: background-color 0.2s;
  }
  
  .notification-content button:hover {
    background-color: #047857;
  }

  /* Backgrounds */
  .bg-white { background-color: white; }
  .bg-gray-50 { background-color: #f9fafb; }
  .bg-gray-100 { background-color: #f3f4f6; }
  .bg-green-50 { background-color: #f0fdf4; }
  .bg-green-500 { background-color: #10b981; }
  .bg-green-600 { background-color: #059669; }
  .bg-green-700 { background-color: #047857; }
  .bg-yellow-100 { background-color: #fef3c7; }
  .bg-red-500 { background-color: #ef4444; }

  /* Texto */
  .text-gray-400 { color: #9ca3af; }
  .text-gray-500 { color: #6b7280; }
  .text-gray-600 { color: #4b5563; }
  .text-gray-700 { color: #374151; }
  .text-gray-800 { color: #1f2937; }
  .text-gray-900 { color: #111827; }
  .text-green-600 { color: #059669; }
  .text-green-700 { color: #047857; }
  .text-green-800 { color: #065f46; }
  .text-white { color: white; }
  .text-red-500 { color: #ef4444; }
  .text-yellow-500 { color: #eab308; }
  .text-yellow-700 { color: #a16207; }
  .text-blue-500 { color: #3b82f6; }
  .text-blue-600 { color: #2563eb; }
  .text-purple-500 { color: #8b5cf6; }
  .text-orange-500 { color: #f97316; }
  .text-teal-500 { color: #14b8a6; }
  .text-indigo-500 { color: #6366f1; }
  .text-center { text-align: center; }

  .text-xs { font-size: 0.75rem; line-height: 1rem; }
  .text-sm { font-size: 0.875rem; line-height: 1.25rem; }
  .text-base { font-size: 1rem; line-height: 1.5rem; }
  .text-lg { font-size: 1.125rem; line-height: 1.75rem; }
  .text-xl { font-size: 1.25rem; line-height: 1.75rem; }
  .text-2xl { font-size: 1.5rem; line-height: 2rem; }

  .font-medium { font-weight: 500; }
  .font-semibold { font-weight: 600; }
  .font-bold { font-weight: 700; }

  /* Padding e margin */
  .p-1 { padding: 0.25rem; }
  .p-2 { padding: 0.5rem; }
  .p-3 { padding: 0.75rem; }
  .p-4 { padding: 1rem; }
  .p-6 { padding: 1.5rem; }
  .px-1 { padding-left: 0.25rem; padding-right: 0.25rem; }
  .px-2 { padding-left: 0.5rem; padding-right: 0.5rem; }
  .px-3 { padding-left: 0.75rem; padding-right: 0.75rem; }
  .px-4 { padding-left: 1rem; padding-right: 1rem; }
  .px-6 { padding-left: 1.5rem; padding-right: 1.5rem; }
  .px-8 { padding-left: 2rem; padding-right: 2rem; }
  .px-10 { padding-left: 2.5rem; padding-right: 2.5rem; }
  .py-1 { padding-top: 0.25rem; padding-bottom: 0.25rem; }
  .py-2 { padding-top: 0.5rem; padding-bottom: 0.5rem; }
  .py-3 { padding-top: 0.75rem; padding-bottom: 0.75rem; }
  .py-6 { padding-top: 1.5rem; padding-bottom: 1.5rem; }

  .pl-3 { padding-left: 0.75rem; }
  .pl-5 { padding-left: 1.25rem; }
  .pl-8 { padding-left: 2rem; }
  .pb-3 { padding-bottom: 0.75rem; }
  .pb-20 { padding-bottom: 5rem; }

  .m-2 { margin: 0.5rem; }
  .mb-1 { margin-bottom: 0.25rem; }
  .mb-2 { margin-bottom: 0.5rem; }
  .mb-3 { margin-bottom: 0.75rem; }
  .mb-4 { margin-bottom: 1rem; }
  .mb-6 { margin-bottom: 1.5rem; }
  .mb-8 { margin-bottom: 2rem; }
  .mb-10 { margin-bottom: 2.5rem; }
  .mt-1 { margin-top: 0.25rem; }
  .mt-2 { margin-top: 0.5rem; }
  .mt-3 { margin-top: 0.75rem; }
  .mt-4 { margin-top: 1rem; }
  .mt-6 { margin-top: 1.5rem; }
  .ml-5 { margin-left: 1.25rem; }

  /* Bordas */
  .border { border: 1px solid #d1d5db; }
  .border-b { border-bottom: 1px solid #d1d5db; }
  .border-b-2 { border-bottom: 2px solid #d1d5db; }
  .border-gray-100 { border-color: #f3f4f6; }
  .border-gray-200 { border-color: #e5e7eb; }
  .border-gray-300 { border-color: #d1d5db; }
  .border-green-100 { border-color: #dcfce7; }
  .border-green-200 { border-color: #bbf7d0; }
  .border-green-500 { border-color: #10b981; }
  .border-green-600 { border-color: #059669; }
  .border-transparent { border-color: transparent; }

  .rounded { border-radius: 0.25rem; }
  .rounded-md { border-radius: 0.375rem; }
  .rounded-lg { border-radius: 0.5rem; }
  .rounded-xl { border-radius: 0.75rem; }
  .rounded-2xl { border-radius: 1rem; }
  .rounded-full { border-radius: 9999px; }

  /* Sombras */
  .shadow { box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06); }
  .shadow-lg { box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05); }

  /* Overflow */
  .overflow-x-auto { overflow-x: auto; }
  .overflow-y-auto { overflow-y: auto; }
  .object-cover { object-fit: cover; }

  /* Posicionamento */
  .relative { position: relative; }
  .absolute { position: absolute; }
  .fixed { position: fixed; }
  .sticky { position: sticky; }
  .top-0 { top: 0; }
  .top-1\/2 { top: 50%; }
  .top-20 { top: 5rem; }
  .left-0 { left: 0; }
  .left-1\/2 { left: 50%; }
  .left-3 { left: 0.75rem; }
  .right-0 { right: 0; }
  .right-6 { right: 1.5rem; }
  .-top-1 { top: -0.25rem; }
  .-right-1 { right: -0.25rem; }
  .-translate-x-1\/2 { transform: translateX(-50%); }
  .-translate-y-1\/2 { transform: translateY(-50%); }
  .z-10 { z-index: 10; }
  .z-30 { z-index: 30; }
  .z-40 { z-index: 40; }

  /* Flex utilities */
  .flex-1 { flex: 1 1 0%; }
  .flex-shrink-0 { flex-shrink: 0; }

  /* Display */
  .block { display: block; }
  .inline-block { display: inline-block; }
  .inline-flex { display: inline-flex; }
  .hidden { display: none; }

  /* Cursor */
  .cursor-pointer { cursor: pointer; }

  /* Transições */
  .transition { transition: all 0.15s ease-in-out; }
  .hover\:bg-white\/20:hover { background-color: rgba(255, 255, 255, 0.2); }
  .hover\:bg-white:hover { background-color: white; }
  .hover\:bg-green-700:hover { background-color: #047857; }
  .hover\:text-green-800:hover { color: #065f46; }
  .hover\:underline:hover { text-decoration: underline; }
  .hover\:shadow-lg:hover { box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05); }
  .hover\:scale-105:hover { transform: scale(1.05); }

  /* Modal */
  .modal {
    display: none;
    position: fixed;
    z-index: 9999;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0, 0, 0, 0.6);
    backdrop-filter: blur(2px);
  }

  .modal-content {
    background-color: white;
    border-radius: 12px;
    width: 90%;
    max-width: 600px;
    margin: 5vh auto;
    padding: 0;
    box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
    max-height: 90vh;
    overflow-y: auto;
    position: relative;
  }

  /* Responsivo para mobile */
  @media (max-width: 768px) {
    .modal-content {
      width: 95%;
      margin: 2vh auto;
      max-height: 96vh;
    }
  }

  .modal-header {
    padding: 20px 24px 16px;
    border-bottom: 1px solid #e5e7eb;
    display: flex;
    justify-content: space-between;
    align-items: center;
  }

  .modal-body {
    padding: 24px;
  }

  .close {
    background: none;
    border: none;
    font-size: 24px;
    cursor: pointer;
    color: #6b7280;
    padding: 4px;
    border-radius: 4px;
    display: flex;
    align-items: center;
    justify-content: center;
  }

  .close:hover {
    background-color: #f3f4f6;
    color: #374151;
  }

  /* Larguras específicas */
  .min-w-\[100px\] { min-width: 100px; }
  .leading-none { line-height: 1; }
  .leading-tight { line-height: 1.25; }

  /* Scroll */
  .snap-x { scroll-snap-type: x mandatory; }
  .snap-mandatory { scroll-snap-type: mandatory; }
  .snap-start { scroll-snap-align: start; }
  .scroll-smooth { scroll-behavior: smooth; }

  /* Scrollbar hiding */
  .hide-scrollbar::-webkit-scrollbar { display: none; }
  .hide-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }

  /* Placeholder */
  .placeholder\:text-white\/80::placeholder { color: rgba(255, 255, 255, 0.8); }

  /* Listas */
  .list-decimal { list-style-type: decimal; }

  /* Cores específicas para status */
  .text-yellow-600 { color: #d97706; }
  .text-indigo-600 { color: #4f46e5; }

  /* Backgrounds específicos */
  .bg-white\/80 { background-color: rgba(255, 255, 255, 0.8); }
  .bg-green-600\/20 { background-color: rgba(5, 150, 105, 0.2); }
  .bg-white\/70 { background-color: rgba(255, 255, 255, 0.7); }
  .bg-black\/40 { background-color: rgba(0, 0, 0, 0.4); }

  /* Posicionamento específico */
  .inset-0 { top: 0; right: 0; bottom: 0; left: 0; }
  .w-11\/12 { width: 91.666667%; }
  .max-w-4xl { max-width: 56rem; }
  .max-h-\[90vh\] { max-height: 90vh; }
  .h-24 { height: 6rem; }
  .h-56 { height: 14rem; }
  .my-4 { margin-top: 1rem; margin-bottom: 1rem; }
  .my-10 { margin-top: 2.5rem; margin-bottom: 2.5rem; }

  /* Sombras específicas */
  .shadow-2xl { box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25); }

  /* Overflow específico */
  .overflow-hidden { overflow: hidden; }

  /* Texto específico */
  .text-\[11px\] { font-size: 11px; }

  /* Responsivo */
  @media (min-width: 640px) {
    .sm\:my-10 { margin-top: 2.5rem; margin-bottom: 2.5rem; }
  }

  @media (min-width: 768px) {
    .md\:grid-cols-2 { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .md\:grid-cols-3 { grid-template-columns: repeat(3, minmax(0, 1fr)); }
    .md\:px-10 { padding-left: 2.5rem; padding-right: 2.5rem; }
    .md\:hidden { display: none; }
    .md\:flex { display: flex; }
  }

  @media (min-width: 1024px) {
    .lg\:grid-cols-3 { grid-template-columns: repeat(3, minmax(0, 1fr)); }
  }

  /* Responsivo específico adicional */
  @media (min-width: 640px) {
    .sm\:my-10 { margin-top: 2.5rem; margin-bottom: 2.5rem; }
  }

  /* Remover bordas de foco padrão e outline */
  *:focus {
    outline: none !important;
    box-shadow: none !important;
  }

  button:focus,
  input:focus,
  select:focus,
  textarea:focus,
  a:focus {
    outline: none !important;
    box-shadow: none !important;
  }

  /* Remover bordas azuis do Chrome */
  input, button, select, textarea {
    -webkit-appearance: none;
    -moz-appearance: none;
    appearance: none;
  }

  button {
    border: none;
    background: none;
  }

  /* Cards de ocorrências */
  .occurrence-card {
    background: white;
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    transition: all 0.2s;
    border: 1px solid #e5e7eb;
  }

  .occurrence-card:hover {
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.12);
    transform: translateY(-2px);
  }

  /* Botões das categorias */
  .category-btn {
    background: white;
    border: 2px solid #e5e7eb;
    border-radius: 12px;
    padding: 16px;
    cursor: pointer;
    transition: all 0.2s;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
    text-decoration: none;
    color: #374151;
  }

  .category-btn:hover {
    border-color: #10b981;
    background-color: #f0fdf4;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.15);
  }

  .category-btn:focus {
    outline: none;
    border-color: #10b981;
    box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.2);
  }

  /* Botões Ver detalhes - estilos específicos */
  .btn-details {
    background-color: #10b981 !important;
    color: white !important;
    border: none !important;
    padding: 8px 16px !important;
    border-radius: 6px !important;
    font-size: 14px !important;
    font-weight: 500 !important;
    cursor: pointer !important;
    transition: all 0.2s !important;
    display: inline-flex !important;
    align-items: center !important;
    gap: 6px !important;
    outline: none !important;
    box-shadow: none !important;
  }

  .btn-details:hover {
    background-color: #059669 !important;
    transform: translateY(-1px) !important;
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3) !important;
    outline: none !important;
  }

  .btn-details:focus {
    outline: none !important;
    box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.2) !important;
  }

  .btn-details:active {
    transform: translateY(0) !important;
    outline: none !important;
  }

  /* Modal */
  .modal {
    display: none;
    position: fixed;
    z-index: 9999;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0, 0, 0, 0.6);
    backdrop-filter: blur(2px);
    overflow-y: auto;
  }

  .modal-content {
    background-color: white;
    margin: 20px auto;
    padding: 0;
    border-radius: 12px;
    width: 95%;
    max-width: 700px;
    position: relative;
    box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
    max-height: calc(100vh - 40px);
    overflow-y: auto;
    top: 50%;
    transform: translateY(-50%);
  }

  .modal-header {
    padding: 20px 24px 16px;
    border-bottom: 1px solid #e5e7eb;
    display: flex;
    align-items: center;
    justify-content: space-between;
  }

  .modal-body {
    padding: 20px 24px 24px;
  }

  .close {
    background: none;
    border: none;
    font-size: 24px;
    cursor: pointer;
    color: #6b7280;
    padding: 4px;
    border-radius: 4px;
    display: flex;
    align-items: center;
    justify-content: center;
  }

  .close:hover {
    background-color: #f3f4f6;
    color: #374151;
  }
</style>
</head>
<body class="bg-gray-50 flex flex-col min-h-screen pb-20">

<header class="bg-green-700 sticky top-0 z-30 shadow">
  <div class="px-4 py-3 flex items-center justify-between text-white">
    <h1 class="text-lg font-bold">RADCI</h1>
    <div class="flex items-center gap-3">
      <button id="bellBtn" class="p-2 rounded hover:bg-white/20 relative" aria-label="Notificações">
        <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
          <path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6 6 0 10-12 0v3.159c0 .538-.214 1.055-.595 1.437L4 17h11z"/>
        </svg>
        <span id="bellBadge" class="absolute -top-1 -right-1 bg-red-500 text-white text-[10px] leading-none rounded-full px-1 hidden">0</span>
      </button>
      <a href="minha_conta.php" class="p-2 rounded hover:bg-white/20 flex items-center gap-1" aria-label="Minha Conta">
        <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
          <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
        </svg>
        <span class="text-white text-sm hidden md:inline">Minha Conta</span>
      </a>
      <a href="principal.php" class="p-2 rounded hover:bg-white/20 flex items-center gap-1" aria-label="Sair">
        <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
          <path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
        </svg>
        <span class="text-white text-sm hidden md:inline">Sair</span>
      </a>
    </div>
  </div>
  <div class="px-4 pb-3">
    <div class="relative mt-2">
      <svg xmlns="http://www.w3.org/2000/svg" class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-200" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
      <input id="globalSearch" type="text" placeholder="Busque em todo o RADCI" class="pl-8 w-full h-9 rounded-md border border-green-600/30 text-white placeholder:text-white/80 bg-green-600/20" />
    </div>
  </div>
</header>

<main class="px-4 md:px-10 py-4 md:py-6 flex-1">

  <!-- Toast de sucesso para ocorrência cadastrada -->
  <?php if (isset($_GET['success']) && $_GET['success'] === 'ocorrencia'): ?>
  <div id="successToastOcorrencia" class="fixed top-4 left-1/2 transform -translate-x-1/2 bg-green-500 text-white px-6 py-3 rounded-lg shadow-lg z-50 flex items-center gap-2">
    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
    </svg>
    <span>Ocorrência cadastrada com sucesso!</span>
  </div>
  <script>
    // Remove o parâmetro da URL imediatamente
    const url = new URL(window.location);
    url.searchParams.delete('success');
    window.history.replaceState({}, document.title, url.pathname + url.search);
    
    // Remove o toast após 3 segundos
    setTimeout(() => {
      const toast = document.getElementById('successToastOcorrencia');
      if (toast) {
        toast.style.opacity = '0';
        toast.style.transform = 'translateX(-50%) translateY(-100%)';
        setTimeout(() => toast.remove(), 300);
      }
    }, 3000);
  </script>
  <?php endif; ?>

  <!-- Toast de sucesso para pesquisa respondida -->
  <?php if (isset($_GET['answered'])): ?>
    <div id="answeredToast" class="fixed top-20 left-1/2 -translate-x-1/2 z-40 bg-green-600 text-white px-4 py-2 rounded shadow flex items-center gap-2">
      <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
      </svg>
      <span>Pesquisa respondida com sucesso!</span>
    </div>
  <?php endif; ?>

  <section class="mb-4 md:mb-6">
    <div class="rounded-2xl border border-green-200 bg-green-50 p-4 md:p-6">
      <div class="flex items-start gap-3">
        <div class="text-green-600 flex-shrink-0">
          <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 md:w-6 md:h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path d="M9 12l2 2 4-4M12 22C6.477 22 2 17.523 2 12S6.477 2 12 2s10 4.477 10 10-4.477 10-10 10z"/>
          </svg>
        </div>
        <div class="flex-1">
          <div class="font-semibold text-green-800">Olá, <?= htmlspecialchars($primeiroNome) ?>! 👋</div>
          <p class="text-sm text-green-800 mt-1">
            Bem-vindo ao RADCI! Aqui você pode relatar problemas urbanos, sugerir melhorias e ajudar a construir uma cidade mais inteligente e acessível para todos.
          </p>
          <div class="mt-3 bg-white/70 border border-green-100 rounded-lg p-3">
            <div class="text-sm font-medium text-green-800">Como funciona:</div>
            <ol class="list-decimal ml-5 text-sm text-green-800 mt-1">
              <li>Selecione uma categoria que melhor se encaixe no seu problema</li>
              <li>Adicione a localização da ocorrência</li>
              <li>Descreva o problema de forma detalhada e anexe as imagens ou videos de registro</li>
              <li>Confirme os dados e envie a ocorrência</li>
            </ol>
          </div>
        </div>
      </div>
    </div>
  </section>



  <section class="mb-4 md:mb-6">
    <h3 class="text-lg font-bold mb-2">Registre Ocorrências</h3>
  
    <div class="relative">
      <button type="button" id="catPrev"
              class="absolute left-0 top-1/2 -translate-y-1/2 z-10 bg-white/80 border border-gray-200 shadow rounded-full p-2 hover:bg-white"
              aria-label="Anterior">
        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/>
        </svg>
      </button>
  
      <div id="catScroller"
           class="flex gap-4 overflow-x-auto hide-scrollbar snap-x snap-mandatory scroll-smooth pb-3 px-8">
        <?php foreach($categories as $cat): ?>
        <form method="GET" action="registrar_ocorrencia.php"
              class="flex-shrink-0 w-[120px]">
          <input type="hidden" name="categoryId" value="<?= htmlspecialchars($cat['id']) ?>">
          <button type="submit" class="flex flex-col items-center group cursor-pointer hover:bg-gray-50 rounded-lg p-2 transition-all duration-200 w-full">
            <div class="w-16 h-16 rounded-full bg-gray-100 flex items-center justify-center mb-2 group-hover:shadow-lg group-hover:scale-105 transition-all duration-200">
              <?= $cat['icon'] ?>
            </div>
            <span class="text-xs text-center text-gray-700 leading-tight group-hover:text-gray-900 font-medium">
              <?= htmlspecialchars($cat['name']) ?>
            </span>
          </button>
        </form>
        <?php endforeach; ?>
      </div>
  
      <button type="button" id="catNext"
              class="absolute right-0 top-1/2 -translate-y-1/2 z-10 bg-white/80 border border-gray-200 shadow rounded-full p-2 hover:bg-white"
              aria-label="Próximo">
        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
        </svg>
      </button>
    </div>
    
    <script>
    document.addEventListener('DOMContentLoaded', function() {
      const scroller = document.getElementById('catScroller');
      const prevBtn = document.getElementById('catPrev');
      const nextBtn = document.getElementById('catNext');
      
      // Função para verificar se precisa mostrar os botões
      function checkScrollButtons() {
        prevBtn.style.display = scroller.scrollLeft > 0 ? 'block' : 'none';
        nextBtn.style.display = (scroller.scrollLeft + scroller.clientWidth) < scroller.scrollWidth ? 'block' : 'none';
      }
      
      // Evento de scroll
      scroller.addEventListener('scroll', checkScrollButtons);
      
      // Evento de resize
      window.addEventListener('resize', checkScrollButtons);
      
      // Botões de navegação
      prevBtn.addEventListener('click', () => {
        scroller.scrollBy({ left: -240, behavior: 'smooth' });
      });
      
      nextBtn.addEventListener('click', () => {
        scroller.scrollBy({ left: 240, behavior: 'smooth' });
      });
      
      // Verificação inicial
      checkScrollButtons();
    });
    </script>
  </section>


 

  </section>

  <!-- Seção de Pesquisas Disponíveis -->
  <?php if (!$hasAnsweredPriorities): ?>
    <section class="mb-6">
      <div class="bg-white rounded-2xl shadow p-6 border border-gray-200">
        <h3 class="text-lg font-bold text-gray-900 mb-1">Pesquisa de Prioridades</h3>
        <p class="text-sm text-gray-600 mb-4">Ajude-nos a entender quais são as prioridades da sua cidade. Sua opinião é muito importante!</p>
        <button onclick="location.href='prioridades.php'" class="w-full bg-green-600 text-white py-3 rounded-md hover:bg-green-700 font-semibold">Responder Pesquisa</button>
      </div>
    </section>
  <?php elseif (!empty($availableSurveys)): ?>
    <section class="mb-6">
      <?php $sv = $availableSurveys[0]; ?>
      <div class="bg-white rounded-2xl shadow p-6 border border-gray-200">
        <h3 class="text-lg font-bold text-gray-900 mb-1"><?= htmlspecialchars($sv['title']) ?></h3>
        <?php if (!empty($sv['description'])): ?><p class="text-sm text-gray-600 mb-4"><?= htmlspecialchars($sv['description']) ?></p><?php endif; ?>
        <button onclick="location.href='prioridades.php?survey_id=<?= urlencode($sv['sid']) ?>'" class="w-full bg-green-600 text-white py-3 rounded-md hover:bg-green-700 font-semibold">Responder Pesquisa</button>
      </div>
    </section>
  <?php endif; ?>

  <section class="mb-10 relative">
    <div class="flex items-center justify-between mb-4">
      <h3 class="text-lg font-bold text-gray-800">Últimas Ocorrências</h3>
      <!-- Botão + (mobile) -->
      <a
        href="registrar_ocorrencia.php"
        class="md:hidden inline-flex items-center justify-center w-10 h-10 rounded-full bg-green-600 text-white shadow hover:bg-green-700"
        aria-label="Registrar ocorrência"
        title="Registrar ocorrência">
        <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14M5 12h14"/>
        </svg>
      </a>
    </div>
  
    <div id="cardsWrap" class="flex overflow-x-auto gap-6 pb-4" style="scroll-snap-type: x mandatory;">
      <?php foreach($ocorrencias as $i => $o): ?>
        <article
          class="bg-white rounded-xl shadow border border-gray-200 p-4 cursor-pointer flex flex-col min-w-[280px] max-w-[280px] flex-shrink-0"
          style="scroll-snap-align: start;"
          data-titulo="<?= htmlspecialchars($o['descricao'] ?? 'Ocorrência') ?>"
          data-numero="<?= htmlspecialchars($o['numero'] ?? 'N/A') ?>"
          data-categoria="<?= htmlspecialchars($o['categoria'] ?? '') ?>"
          data-local="<?= htmlspecialchars($o['local'] ?? 'Local não informado') ?>"
          data-data="<?= htmlspecialchars($o['data'] ?? '') ?>"
          data-status="<?= htmlspecialchars($o['status'] ?? 'Em Análise') ?>"
          data-descricao="<?= htmlspecialchars($o['detalhes'] ?? ($o['descricao'] ?? '')) ?>"
          data-imagem="<?= htmlspecialchars($o['imagem'] ?? '') ?>"
          data-fotos='<?= json_encode($o['imagens'] ?? []) ?>'
          data-tem-imagens="<?= htmlspecialchars($o['tem_imagens'] ?? 'Não') ?>"
          data-lat="<?= htmlspecialchars($o['lat'] ?? '') ?>"
          data-lng="<?= htmlspecialchars($o['lng'] ?? '') ?>"
        >
          <img src="<?= htmlspecialchars($o['thumb']) ?>" class="w-full h-32 object-cover rounded-md" alt="thumb">
          <div class="flex flex-col h-full">
            <div class="mt-2 mb-1">
              <div class="text-xs font-mono text-gray-500 bg-gray-100 px-2 py-1 rounded inline-block">
                <?= htmlspecialchars($o['numero'] ?? 'N/A') ?>
              </div>
            </div>
            <div class="flex items-center justify-between">
              <div class="text-sm font-medium text-gray-900 flex-1 mr-2"><?= htmlspecialchars($o['descricao']) ?></div>
              <span class="text-[11px] px-2 py-1 rounded-full bg-yellow-100 text-yellow-700 whitespace-nowrap"><?= htmlspecialchars($o['status']) ?></span>
            </div>
            <div class="text-xs text-gray-500"><?= htmlspecialchars($o['categoria']) ?></div>
            <div class="flex items-center justify-between text-xs text-gray-400">
              <span><?= htmlspecialchars($o['data']) ?></span>
              <span class="flex items-center gap-1">
                <?php if (($o['tem_imagens'] ?? 'Não') === 'Sim'): ?>
                  <svg xmlns="http://www.w3.org/2000/svg" class="w-3 h-3 text-green-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                  </svg>
                  <span class="text-green-600">Com imagens</span>
                <?php else: ?>
                  <span class="text-gray-400">Sem imagens</span>
                <?php endif; ?>
              </span>
            </div>
            <div class="mt-auto pt-3">
              <button type="button" class="btn-details btn-ver-detalhes w-full">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                  <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                </svg>
                Ver detalhes
              </button>
            </div>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  
    <!-- Botão + fixo na lateral (desktop), menor -->
    <a
      href="registrar_ocorrencia.php"
      class="hidden md:flex items-center justify-center w-14 h-14 rounded-full bg-green-600 text-white shadow hover:bg-green-700 absolute right-6 top-1/2 -translate-y-1/2"
      aria-label="Registrar ocorrência"
      title="Registrar ocorrência">
      <svg xmlns="http://www.w3.org/2000/svg" class="w-7 h-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14M5 12h14"/>
      </svg>
    </a>
  </div>
</section>

<!-- Seção de Pesquisas Registradas -->
<?php if (!empty($answeredSurveys)): ?>
  <section class="mb-8">
    <h3 class="text-lg font-bold text-gray-800 mb-4">Pesquisas Registradas</h3>
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
      <?php foreach ($answeredSurveys as $sv): ?>
        <article class="bg-white rounded-xl shadow border border-gray-200 p-4">
          <div class="flex items-start justify-between mb-3">
            <h4 class="text-sm font-semibold text-gray-900"><?= htmlspecialchars($sv['title']) ?></h4>
            <span class="text-[11px] px-2 py-1 rounded-full bg-green-100 text-green-700">Respondida</span>
          </div>
          <?php if (!empty($sv['description'])): ?>
            <p class="text-xs text-gray-600 mb-3"><?= htmlspecialchars($sv['description']) ?></p>
          <?php endif; ?>
          
          <?php if (!empty($sv['order'])): ?>
            <div class="mb-3">
              <p class="text-xs font-medium text-gray-700 mb-2">Suas prioridades:</p>
              <ol class="text-xs text-gray-600 space-y-1">
                <?php foreach (array_slice($sv['order'], 0, 3) as $i => $priority): ?>
                  <li><?= ($i + 1) ?>. <?= htmlspecialchars($priority) ?></li>
                <?php endforeach; ?>
                <?php if (count($sv['order']) > 3): ?>
                  <li class="text-gray-400">... e mais <?= count($sv['order']) - 3 ?></li>
                <?php endif; ?>
              </ol>
            </div>
          <?php endif; ?>
          
          <button onclick="location.href='prioridades.php?survey_id=<?= urlencode($sv['sid']) ?>&readonly=1'" 
                  class="w-full bg-gray-100 text-gray-700 py-2 rounded-md hover:bg-gray-200 text-sm font-medium">
            Ver Detalhes
          </button>
        </article>
      <?php endforeach; ?>
    </div>
  </section>
<?php endif; ?>



  <section class="mt-6">
    <h3 class="text-lg font-bold text-gray-800 mb-3">Dashboard</h3>
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
      <div class="bg-white rounded-xl border border-gray-200 p-4 flex items-center gap-3">
        <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 text-green-700" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M3 3h18v4H3z"/><path d="M7 7v14"/><path d="M17 7v10"/></svg>
        <div><div class="text-xs text-gray-500">Registradas</div><div class="text-xl font-bold"><?= $totRegistradas ?></div></div>
      </div>
      <div class="bg-white rounded-xl border border-gray-200 p-4 flex items-center gap-3">
        <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M5 13l4 4L19 7"/></svg>
        <div><div class="text-xs text-gray-500">Concluídas</div><div class="text-xl font-bold"><?= $totConcluidas ?></div></div>
      </div>
      <div class="bg-white rounded-xl border border-gray-200 p-4 flex items-center gap-3">
        <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 text-yellow-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M12 8v4m0 4h.01"/></svg>
        <div><div class="text-xs text-gray-500">Em Análise</div><div class="text-xl font-bold"><?= $totAndamento ?></div></div>
      </div>
    </div>
  </section>
</main>

<div id="evidenceModal" class="modal">
  <div class="modal-content">
    <div class="modal-header">
      <div class="flex items-center gap-3">
        <h2 class="font-bold text-gray-900 text-lg" id="evTitle">Detalhes da Ocorrência</h2>
        <span class="text-xs px-3 py-1 rounded-full bg-blue-100 text-blue-700 font-mono" id="evNumero">N/A</span>
        <span class="text-xs px-3 py-1 rounded-full bg-gray-100 text-gray-700 font-medium" id="evStatus">Status</span>
      </div>
      <button type="button" id="evClose" class="close" aria-label="Fechar">
        <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
        </svg>
      </button>
    </div>

    <div class="modal-body">
      <div class="grid md:grid-cols-2 gap-6">
        <div class="space-y-4">
          <div class="bg-gray-50 rounded-lg p-4">
            <div class="text-xs font-medium text-gray-500 uppercase tracking-wide mb-1">Categoria</div>
            <div class="font-semibold text-gray-900" id="evCategoria">—</div>
          </div>
          <div class="bg-gray-50 rounded-lg p-4">
            <div class="text-xs font-medium text-gray-500 uppercase tracking-wide mb-1">Local</div>
            <div class="font-semibold text-gray-900" id="evLocal">—</div>
          </div>
          <div class="bg-gray-50 rounded-lg p-4">
            <div class="text-xs font-medium text-gray-500 uppercase tracking-wide mb-1">Data</div>
            <div class="font-semibold text-gray-900" id="evData">—</div>
          </div>
          <div class="bg-gray-50 rounded-lg p-4">
            <div class="text-xs font-medium text-gray-500 uppercase tracking-wide mb-1">Tem Imagens</div>
            <div class="font-semibold text-gray-900" id="evTemImagens">—</div>
          </div>
          <div class="bg-gray-50 rounded-lg p-4">
            <div class="text-xs font-medium text-gray-500 uppercase tracking-wide mb-1">Descrição</div>
            <div class="text-gray-800 leading-relaxed" id="evDescricao">—</div>
          </div>
        </div>
        <div class="space-y-4">
          <div id="evMapWrap" class="rounded-lg overflow-hidden border border-gray-200 h-64">
            <div id="evMap" class="w-full h-full bg-gray-100"></div>
          </div>
          <div>
            <div class="text-xs font-medium text-gray-500 uppercase tracking-wide mb-3">Evidências</div>
            <div id="evImages" class="grid grid-cols-2 gap-3"></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
(function() {
  const modal = document.getElementById('evidenceModal');
  const evTitle = document.getElementById('evTitle');
  const evNumero = document.getElementById('evNumero');
  const evStatus = document.getElementById('evStatus');
  const evCategoria = document.getElementById('evCategoria');
  const evLocal = document.getElementById('evLocal');
  const evData = document.getElementById('evData');
  const evTemImagens = document.getElementById('evTemImagens');
  const evDescricao = document.getElementById('evDescricao');
  const evImages = document.getElementById('evImages');
  const evMapWrap = document.getElementById('evMapWrap');

  let map, marker;

  function openModal() {
    modal.style.display = 'block';
    document.body.style.overflow = 'hidden';
  }
  function closeModal() {
    modal.style.display = 'none';
    document.body.style.overflow = '';
  }

  // Modal de visualização de imagem
  function openImageModal(imageSrc) {
    const imageModal = document.createElement('div');
    imageModal.className = 'fixed inset-0 bg-black/80 flex items-center justify-center z-50 p-4';
    imageModal.innerHTML = `
      <div class="relative max-w-4xl max-h-full">
        <button class="absolute top-4 right-4 text-white text-2xl hover:text-gray-300 z-10" onclick="this.parentElement.parentElement.remove()">×</button>
        <img src="${imageSrc}" class="max-w-full max-h-full object-contain rounded-lg" alt="Imagem ampliada">
      </div>
    `;
    imageModal.addEventListener('click', (e) => {
      if (e.target === imageModal) {
        imageModal.remove();
      }
    });
    document.body.appendChild(imageModal);
  }

  function populateFromArticle(article) {
    evTitle.textContent = article.dataset.titulo || 'Ocorrência';
    evNumero.textContent = article.dataset.numero || 'N/A';
    evStatus.textContent = article.dataset.status || 'Em Análise';
    evCategoria.textContent = article.dataset.categoria || '—';
    evLocal.textContent = article.dataset.local || '—';
    evData.textContent = article.dataset.data || '—';
    evTemImagens.textContent = article.dataset.temImagens || '—';
    evDescricao.textContent = article.dataset.descricao || '—';

    evImages.innerHTML = '';
    const imgsData = article.dataset.fotos || '[]';
    let imgs = [];
    try { imgs = JSON.parse(imgsData); } catch(_) {}
    const first = article.dataset.imagem || '';
    const all = first ? [first, ...imgs] : imgs;
    all.slice(0, 6).forEach(src => {
      const img = document.createElement('img');
      img.src = src;
      img.alt = 'evidência';
      img.className = 'w-full h-24 object-cover rounded cursor-pointer hover:opacity-80 transition-opacity';
      img.addEventListener('click', () => openImageModal(src));
      evImages.appendChild(img);
    });

    const lat = parseFloat(article.dataset.lat || '');
    const lng = parseFloat(article.dataset.lng || '');
    if (!isNaN(lat) && !isNaN(lng)) {
      evMapWrap.classList.remove('hidden');
      setTimeout(() => {
        if (!map) {
          map = L.map('evMap').setView([lat, lng], 15);
          L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '&copy; OpenStreetMap' }).addTo(map);
          marker = L.marker([lat, lng]).addTo(map);
        } else {
          map.setView([lat, lng], 15);
          if (marker) marker.setLatLng([lat, lng]); else marker = L.marker([lat, lng]).addTo(map);
        }
      }, 10);
    } else {
      evMapWrap.classList.add('hidden');
    }
  }

  const cardsWrap = document.getElementById('cardsWrap');
  if (cardsWrap) {
    cardsWrap.addEventListener('click', (e) => {
      const article = e.target.closest('article[data-titulo]');
      if (!article) return;
      if (e.target.closest('a')) return;
      populateFromArticle(article);
      openModal();
    });
  }

  // Fecha ao clicar fora (overlay)
  modal?.addEventListener('click', (e) => {
    // se o alvo do clique for o próprio overlay, fecha
    if (e.target === modal) {
      closeModal();
    }
  });

  // Fecha ao pressionar ESC
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && !modal.classList.contains('hidden')) {
      closeModal();
    }
  });

  document.getElementById('evClose')?.addEventListener('click', closeModal);
  document.getElementById('evCloseFooter')?.addEventListener('click', closeModal);

  // Funcionalidade do botão de notificação
  document.getElementById('bellBtn')?.addEventListener('click', function() {
    // Criar modal de notificações
    const notificationModal = document.createElement('div');
    notificationModal.id = 'notificationModal';
    notificationModal.className = 'modal';
    notificationModal.style.display = 'block';
    
    let notificationsHTML = '';
    
    // Adicionar notificações de pesquisas não respondidas
    <?php if (!$hasAnsweredPriorities): ?>
    notificationsHTML += `
      <div class="notification-item">
        <div class="notification-icon">📋</div>
        <div class="notification-content">
          <h4>Pesquisa de Prioridades Disponível</h4>
          <p>Há uma pesquisa sobre as prioridades da sua cidade aguardando sua resposta.</p>
          <small>Pendente</small>
          <button onclick="location.href='prioridades.php'" class="mt-2 bg-green-600 text-white px-3 py-1 rounded text-xs hover:bg-green-700">Responder Agora</button>
        </div>
      </div>`;
    <?php endif; ?>
    
    <?php foreach($availableSurveys as $sv): ?>
    notificationsHTML += `
      <div class="notification-item">
        <div class="notification-icon">📋</div>
        <div class="notification-content">
          <h4><?= htmlspecialchars($sv['title']) ?></h4>
          <p><?= htmlspecialchars($sv['description'] ?? 'Nova pesquisa disponível para resposta.') ?></p>
          <small>Pendente</small>
          <button onclick="location.href='prioridades.php?survey_id=<?= urlencode($sv['sid']) ?>'" class="mt-2 bg-green-600 text-white px-3 py-1 rounded text-xs hover:bg-green-700">Responder Agora</button>
        </div>
      </div>`;
    <?php endforeach; ?>
    
    // Notificações padrão
    notificationsHTML += `
      <div class="notification-item">
        <div class="notification-icon">🔔</div>
        <div class="notification-content">
          <h4>Nova ocorrência registrada</h4>
          <p>Sua ocorrência foi registrada com sucesso e está sendo analisada.</p>
          <small>Há 2 horas</small>
        </div>
      </div>
      <div class="notification-item">
        <div class="notification-icon">✅</div>
        <div class="notification-content">
          <h4>Ocorrência atualizada</h4>
          <p>O status da sua ocorrência foi atualizado para "Em andamento".</p>
          <small>Há 1 dia</small>
        </div>
      </div>`;
    
    notificationModal.innerHTML = `
      <div class="modal-content">
        <div class="modal-header">
          <h2>Notificações</h2>
          <button type="button" class="close-btn" onclick="closeNotificationModal()">&times;</button>
        </div>
        <div class="modal-body">
          ${notificationsHTML}
        </div>
      </div>
    `;
    
    document.body.appendChild(notificationModal);
    document.body.style.overflow = 'hidden';
  });

  // Função para fechar modal de notificações
  window.closeNotificationModal = function() {
    const modal = document.getElementById('notificationModal');
    if (modal) {
      modal.remove();
      document.body.style.overflow = '';
    }
  };
})();
</script>

<?php include __DIR__ . '/../includes/mobile_nav.php'; ?>

<script>
(function() {
  // Oculta o toast de confirmação após 5s
  const toast = document.getElementById('answeredToast');
  if (toast) {
    setTimeout(() => toast.remove(), 5000);
  }
})();
</script>

</body>
</html>
