<?php
session_start();
// Permite resetar flags de pesquisa respondida via URL: ?reset=1
if (isset($_GET['reset'])) {
  unset($_SESSION['answered_surveys']);
  unset($_SESSION['answered_priorities']);
}
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/notificacoes.php';

$pdo = get_pdo();
$notificacaoManager = new NotificacaoManager();

// Verifica se há lembretes pendentes para enviar
$notificacaoManager->enviarLembretePesquisaPendente();

// Dados do usuário
$userId       = intval($_SESSION['usuario_id'] ?? 0);
$userNome     = trim($_SESSION['usuario_nome'] ?? ($_SESSION['usuario']['nome'] ?? 'Usuário'));
$primeiroNome = explode(' ', $userNome)[0];

// Busca notificações não lidas
$notificacoesNaoLidas = $notificacaoManager->buscarNotificacoesNaoLidas($userId);

// Categorias (ícones representativos)
$categories = [
    ['id'=>'saude','name'=>'Saúde','icon'=>'<svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" viewBox="0 0 24 24" fill="#4CAF50"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-8.5 14h-1v-4h-4v-1h4V8h1v4h4v1h-4v4z"/></svg>'],
    ['id'=>'inovacao','name'=>'Inovação','icon'=>'<svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" viewBox="0 0 24 24" fill="#FFC107"><path d="M9 21c0 .55.45 1 1 1h4c.55 0 1-.45 1-1v-1H9v1zm3-19C8.14 2 5 5.14 5 9c0 2.38 1.19 4.47 3 5.74V17c0 .55.45 1 1 1h6c.55 0 1-.45 1-1v-2.26c1.81-1.27 3-3.36 3-5.74 0-3.86-3.14-7-7-7zm2.85 11.1l-.85.6V16h-4v-2.3l-.85-.6C7.8 12.16 7 10.63 7 9c0-2.76 2.24-5 5-5s5 2.24 5 5c0 1.63-.8 3.16-2.15 4.1z"/></svg>'],
    ['id'=>'mobilidade','name'=>'Mobilidade','icon'=>'<svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" viewBox="0 0 24 24" fill="#2196F3"><path d="M18.92 6.01C18.72 5.42 18.16 5 17.5 5h-11c-.66 0-1.21.42-1.42 1.01L3 12v8c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-1h12v1c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-8l-2.08-5.99zM6.85 7h10.29l1.08 3.11H5.77L6.85 7zM19 17H5v-5h14v5z"/><circle cx="7.5" cy="14.5" r="1.5"/><circle cx="16.5" cy="14.5" r="1.5"/></svg>'],
    ['id'=>'politicas','name'=>'Políticas Públicas','icon'=>'<svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" viewBox="0 0 24 24" fill="#9C27B0"><path d="M12 3c-4.97 0-9 4.03-9 9s4.03 9 9 9 9-4.03 9-9c0-.46-.04-.92-.1-1.36-.98 1.37-2.58 2.26-4.4 2.26-3.03 0-5.5-2.47-5.5-5.5 0-1.82.89-3.42 2.26-4.4-.44-.06-.9-.1-1.36-.1z"/></svg>'],
    ['id'=>'riscos','name'=>'Riscos Urbanos','icon'=>'<svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" viewBox="0 0 24 24" fill="#FF5722"><path d="M1 21h22L12 2 1 21zm12-3h-2v-2h2v2zm0-4h-2v-4h2v4z"/></svg>'],
  ['id'=>'sustentabilidade','name'=>'Sustentabilidade','icon'=>'<svg xmlns="http://www.w3.org/2000/svg" class="w-7 h-7" viewBox="0 0 24 24" fill="#8BC34A"><path d="M16 6l2.29 2.29-4.88 4.88-4-4L2 16.59 3.41 18l6-6 4 4 6.3-6.29L22 12V6z"/></svg>'],
  ['id'=>'planejamento','name'=>'Planejamento Urbano','icon'=>'<svg xmlns="http://www.w3.org/2000/svg" class="w-7 h-7" viewBox="0 0 24 24" fill="#3F51B5"><path d="M15 11V5l-3-3-3 3v2H3v14h18V11h-6zm-8 8H5v-2h2v2zm0-4H5v-2h2v2zm0-4H5V9h2v2zm6 8h-2v-2h2v2zm0-4h-2v-2h2v2zm0-4h-2V9h2v2zm0-4h-2V5h2v2zm6 12h-2v-2h2v2zm0-4h-2v-2h2v2z"/></svg>'],
  ['id'=>'educacao','name'=>'Educação','icon'=>'<svg xmlns="http://www.w3.org/2000/svg" class="w-7 h-7" viewBox="0 0 24 24" fill="#FF9800"><path d="M5 13.18v4L12 21l7-3.82v-4L12 17l-7-3.82zM12 3L1 9l11 6 9-4.91V17h2V9L12 3z"/></svg>'],
  ['id'=>'meio','name'=>'Meio Ambiente','icon'=>'<svg xmlns="http://www.w3.org/2000/svg" class="w-7 h-7" viewBox="0 0 24 24" fill="#4CAF50"><path d="M12 22c4.97 0 9-4.03 9-9-4.97 0-9 4.03-9 9zM5.6 10.25c0 1.38 1.12 2.5 2.5 2.5.53 0 1.01-.16 1.42-.44l-.02.19c0 1.38 1.12 2.5 2.5 2.5s2.5-1.12 2.5-2.5l-.02-.19c.4.28.89.44 1.42.44 1.38 0 2.5-1.12 2.5-2.5 0-1-.59-1.85-1.43-2.25.84-.4 1.43-1.25 1.43-2.25 0-1.38-1.12-2.5-2.5-2.5-.53 0-1.01.16-1.42.44l.02-.19C14.5 2.12 13.38 1 12 1S9.5 2.12 9.5 3.5l.02.19c-.4-.28-.89-.44-1.42-.44-1.38 0-2.5 1.12-2.5 2.5 0 1 .59 1.85 1.43 2.25-.84.4-1.43 1.25-1.43 2.25zM12 5.5c1.38 0 2.5 1.12 2.5 2.5s-1.12 2.5-2.5 2.5S9.5 9.38 9.5 8s1.12-2.5 2.5-2.5z"/></svg>'],
  ['id'=>'infraestrutura','name'=>'Infraestrutura da Cidade','icon'=>'<svg xmlns="http://www.w3.org/2000/svg" class="w-7 h-7" viewBox="0 0 24 24" fill="#607D8B"><path d="M15 11V5l-3-3-3 3v2H3v14h18V11h-6zm-8 8H5v-2h2v2zm0-4H5v-2h2v2zm0-4H5V9h2v2zm6 8h-2v-2h2v2zm0-4h-2v-2h2v2zm0-4h-2V9h2v2zm0-4h-2V5h2v2zm6 12h-2v-2h2v2zm0-4h-2v-2h2v2z"/></svg>'],
  ['id'=>'seguranca','name'=>'Segurança Pública','icon'=>'<svg xmlns="http://www.w3.org/2000/svg" class="w-7 h-7" viewBox="0 0 24 24" fill="#F44336"><path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm0 10.99h7c-.53 4.12-3.28 7.79-7 8.94V12H5V6.3l7-3.11v8.8z"/></svg>'],
  ['id'=>'energias','name'=>'Energias Inteligentes','icon'=>'<svg xmlns="http://www.w3.org/2000/svg" class="w-7 h-7" viewBox="0 0 24 24" fill="#FFEB3B"><path d="M7 2v11h3v9l7-12h-4l4-8z"/></svg>'],
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
        
        $BASE_PATH = '/radci/';

        foreach ($results as $row) {
            error_log("Dashboard - Processando ocorrência ID: " . $row['id'] . ", Tipo: " . $row['categoria']);
            
            $arquivos = json_decode($row['arquivos'] ?? '[]', true) ?: [];
            $primeiraImagem = '';
            $todasImagens = [];
            
            // Processa arquivos para extrair imagens
            foreach ($arquivos as $arquivo) {
                if (isset($arquivo['url']) && preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $arquivo['url'])) {
                    $imageUrl = $BASE_PATH . ltrim($arquivo['url'], '/');
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
                'tem_imagens' => ($row['tem_imagens'] ?? 0) ? 'Sim' : 'Não',
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

// Verifica se usuário já respondeu pesquisa de prioridades (tabela persistente usuarios_prioridades)
$hasAnsweredPriorities = false;
try {
  if ($userId) {
    $stmt = $pdo->prepare("SELECT 1 FROM usuarios_prioridades WHERE usuario_id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $hasAnsweredPriorities = (bool)$stmt->fetchColumn();
  }
} catch (Throwable $_) {
  $hasAnsweredPriorities = false;
}
// Fallback imediato por sessão: se acabou de responder, considera já respondido na mesma navegação
if (!$hasAnsweredPriorities && !empty($_SESSION['answered_priorities'][$userId])) {
  $hasAnsweredPriorities = true;
}

// Monta ordem das prioridades caso já tenha respondido
$prioridadesOrder = [];
if ($hasAnsweredPriorities) {
  try {
    // Mapa: coluna do banco -> id da categoria do front
    $colToCat = [
      'saude'                 => 'saude',
      'inovacao'              => 'inovacao',
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

    $sql  = "SELECT ".implode(',', $dbCols)." FROM usuarios_prioridades WHERE usuario_id = ? LIMIT 1";
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
  } catch (Throwable $_) {
    // mantém $prioridadesOrder como []
  }
}

$availableSurveys = [];
$answeredSurveys  = [];

// Resolve ID da pesquisa de prioridades (se existir no banco)
$prioridadesDbId = 0;
try {
  $stmt = $pdo->prepare("SELECT id FROM pesquisa_meta WHERE sid = ? LIMIT 1");
  $stmt->execute(['prioridades']);
  $prioridadesDbId = intval($stmt->fetchColumn() ?: 0);
} catch (Throwable $_) {}

// Se respondeu prioridades, aparece como respondida, incluindo a ordem
if ($hasAnsweredPriorities) {
  $answeredSurveys[] = [
    'sid'         => 'prioridades',
    'db_id'       => $prioridadesDbId,
    'title'       => 'Pesquisa de Prioridades',
    'description' => 'Ordene as prioridades da sua cidade',
    'order'       => $prioridadesOrder
  ];
}

// Busca pesquisas (tabela) respondidas pelo usuário
if ($userId) {
  try {
    $stmt = $pdo->prepare("\n      SELECT DISTINCT p.id AS db_id, p.titulo, p.descricao\n        FROM pesquisa_respostas r\n        JOIN pesquisa_meta p ON p.id = r.pesquisa_id\n       WHERE r.usuario_id = ?\n       ORDER BY p.id DESC\n    ");
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
      // Evita duplicar caso já esteja na lista por outro mecanismo
      $answeredSurveys[] = [
        'sid'         => 'db_' . intval($r['db_id']),
        'db_id'       => intval($r['db_id']),
        'title'       => $r['titulo'] ?? 'Pesquisa',
        'description' => $r['descricao'] ?? ''
      ];
    }
  } catch (Throwable $_) {}
}

// NOVO: montar pesquisas disponíveis a partir de 'pesquisa_meta' (preferencial) e 'pesquisa' (compatibilidade)
try {
    $usuarioMunicipio = null; $usuarioUF = null; $usuarioCriadoEm = null;
    if ($userId > 0) {
      $usrStmt = $pdo->prepare("SELECT municipio, UPPER(uf) AS uf, created_at FROM usuarios WHERE id = ?");
      $usrStmt->execute([$userId]);
      $usrRow = $usrStmt->fetch(PDO::FETCH_ASSOC) ?: [];
      $usuarioMunicipio = trim($usrRow['municipio'] ?? '');
      $usuarioUF = strtoupper(trim($usrRow['uf'] ?? ''));
      $usuarioCriadoEm = $usrRow['created_at'] ?? null;
    }

    // Listas separadas para controle fino
    $availableSurveysMeta   = [];
    $availableSurveysLegacy = [];
    $availableSurveys       = $availableSurveys ?? [];
    $availableSurveysCTA    = []; // apenas META, usado pelo card "Responder Agora"

    $seenSid = [];

  // Lista de SIDs já respondidos (DB + sessão)
  $answeredSids = [];
  try {
    $ansStmt = $pdo->prepare("\n      SELECT pm.sid\n        FROM pesquisa_respostas r\n        JOIN pesquisa_meta pm ON pm.id = r.pesquisa_id\n       WHERE r.usuario_id = ?\n    ");
    $ansStmt->execute([$userId]);
    foreach ($ansStmt->fetchAll(PDO::FETCH_COLUMN) as $sid) {
      if ($sid) { $answeredSids[strtolower(trim($sid))] = true; }
    }
  } catch (Throwable $_) {}
  if (!empty($_SESSION['answered_surveys'][$userId])) {
    foreach ($_SESSION['answered_surveys'][$userId] as $sid => $flag) {
      if ($flag && $sid) { $answeredSids[strtolower(trim($sid))] = true; }
    }
  }

  // 1) Pesquisa META (canônica) — já exclui respondidas por ID
  $metaSql = "\n    SELECT id AS db_id, titulo, descricao, tipo_destinatario, cidade, UPPER(uf) AS uf, sid, created_at\n      FROM pesquisa_meta\n     WHERE tipo_destinatario IN ('todos','cidadaos')\n       AND (\n         (cidade IS NULL OR cidade = '')\n         OR (cidade IS NOT NULL AND cidade <> '' AND (? = '' OR cidade = ?))\n       )\n       AND (\n         (uf IS NULL OR uf = '')\n         OR (uf IS NOT NULL AND uf <> '' AND (? = '' OR UPPER(uf) = ?))\n       )\n       AND id NOT IN (SELECT pesquisa_id FROM pesquisa_respostas WHERE usuario_id = ?)\n       AND (? IS NULL OR created_at >= ?)\n     ORDER BY id DESC\n     LIMIT 10\n  ";
  $params = [$usuarioMunicipio, $usuarioMunicipio, $usuarioUF, $usuarioUF, $userId, $usuarioCriadoEm, $usuarioCriadoEm];
  $mStmt = $pdo->prepare($metaSql);
  $mStmt->execute($params);
  $mRows = $mStmt->fetchAll(PDO::FETCH_ASSOC);

  foreach ($mRows as $r) {
    $sid = trim($r['sid'] ?? '');
    if ($sid !== '') {
      if (!isset($answeredSids[strtolower($sid)])) {
        $seenSid[strtolower($sid)] = true;
        $availableSurveysMeta[] = [
          'sid'         => $sid,
          'db_id'       => intval($r['db_id']),
          'title'       => $r['titulo'] ?? 'Pesquisa',
          'description' => $r['descricao'] ?? ''
        ];
      }
    } else {
      // META sem sid ainda é válido para CTA
      $availableSurveysMeta[] = [
        'sid'         => null,
        'db_id'       => intval($r['db_id']),
        'title'       => $r['titulo'] ?? 'Pesquisa',
        'description' => $r['descricao'] ?? ''
      ];
    }
  }

  // 2) Compatibilidade: tabela 'pesquisa' (legado)
  //    Para usuários novos (com created_at), não exibimos itens da tabela legado.
  if (empty($usuarioCriadoEm)) {
    $sql = "\n    SELECT id AS db_id, titulo, descricao, tipo_destinatario, cidade, UPPER(uf) AS uf, sid\n      FROM pesquisa\n     WHERE tipo_destinatario IN ('todos','cidadaos')\n       AND (\n         (cidade IS NULL OR cidade = '')\n         OR (cidade IS NOT NULL AND cidade <> '' AND (? = '' OR cidade = ?))\n       )\n       AND (\n         (uf IS NULL OR uf = '')\n         OR (uf IS NOT NULL AND uf <> '' AND (? = '' OR UPPER(uf) = ?))\n       )\n     ORDER BY id DESC\n     LIMIT 10\n  ";
    $params = [$usuarioMunicipio, $usuarioMunicipio, $usuarioUF, $usuarioUF];
    $pStmt = $pdo->prepare($sql);
    $pStmt->execute($params);
    $pRows = $pStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($pRows as $r) {
      $sid = trim($r['sid'] ?? '');
      if ($sid === '') { continue; } // ignora legado sem SID
      if (isset($answeredSids[strtolower($sid)])) { continue; } // já respondida
      if (isset($seenSid[strtolower($sid)])) { continue; } // já temos via META
      if (empty($r['titulo'])) { continue; } // ignora registros incompletos

      $availableSurveysLegacy[] = [
        'sid'         => $sid,
        'db_id'       => intval($r['db_id']),
        'title'       => $r['titulo'] ?? 'Pesquisa',
        'description' => $r['descricao'] ?? ''
      ];
    }
  }

  // Consolida listas
  $availableSurveys    = array_values(array_merge($availableSurveysMeta, $availableSurveysLegacy));
  $availableSurveysCTA = $availableSurveysMeta; // card principal usa apenas META
} catch (Throwable $_) {}

// Métricas
$totRegistradas = 0;
$totConcluidas  = 0;
$totAndamento   = 0;
foreach ($ocorrencias as $o) {
  $status = strtolower(trim($o['status'] ?? ''));
  if (in_array($status, ['resolvida', 'concluida', 'concluída'])) {
    $totConcluidas++;
  } elseif (in_array($status, ['em_analise', 'em análise', 'em analise'])) {
    $totAndamento++;
  } else {
    // Registradas inclui encaminhada, aberta, cancelada e quaisquer outros
    $totRegistradas++;
  }
}

// KPIs reais (contagem completa no banco para o usuário)
$kpiTotal         = 0;
$kpiConcluidas    = 0;
$kpiEmAnalise     = 0;
$kpiEncaminhadas  = 0;

if ($userId > 0) {
  try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM ocorrencias WHERE usuario_id = ?");
    $stmt->execute([$userId]);
    $kpiTotal = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM ocorrencias WHERE usuario_id = ? AND status IN ('resolvida','concluida','concluída')");
    $stmt->execute([$userId]);
    $kpiConcluidas = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM ocorrencias WHERE usuario_id = ? AND status IN ('em_analise','em análise','em analise')");
    $stmt->execute([$userId]);
    $kpiEmAnalise = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM ocorrencias WHERE usuario_id = ? AND status = 'encaminhada'");
    $stmt->execute([$userId]);
    $kpiEncaminhadas = (int)$stmt->fetchColumn();
  } catch (Throwable $_) {}
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
  <?php
  // Verifica se é a primeira visita do dia
  $lastVisit = $_SESSION['last_visit'] ?? '';
  $today = date('Y-m-d');
  if ($lastVisit !== $today) {
    $_SESSION['last_visit'] = $today;
    $_SESSION['show_welcome'] = true;
  }
  ?>

  /* NAV MOBILE */
.mobile-nav { background-color: #ffffff; }
.mobile-nav .nav-item {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 4px;
  color: #065f46; /* verde escuro */
  transition: all 0.2s ease;
}

.mobile-nav .nav-item svg {
  fill: currentColor;
}

.mobile-nav .nav-item.active {
  color: #065f46; /* verde escuro */
  font-weight: 600;
  transform: translateY(-2px);
}

.mobile-nav .nav-item:hover {
  color: #065f46;
  transform: translateY(-2px);
}

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
  .notification-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 16px;
    border-bottom: 1px solid #e5e7eb;
  }

  .notification-header h2 {
    font-size: 18px;
    font-weight: 600;
    color: #1f2937;
  }

  .notification-actions {
    display: flex;
    gap: 8px;
  }

  .clear-notifications {
    display: flex;
    align-items: center;
    gap: 4px;
    padding: 6px 12px;
    font-size: 12px;
    color: #6b7280;
    background-color: #f3f4f6;
    border: 1px solid #e5e7eb;
    border-radius: 4px;
    cursor: pointer;
    transition: all 0.2s;
  }

  .clear-notifications:hover {
    background-color: #e5e7eb;
    color: #4b5563;
  }

  .notification-item {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 16px;
    border-bottom: 1px solid #e5e7eb;
    transition: all 0.2s;
  }
  
  .notification-item:hover {
    background-color: #f9fafb;
  }
  
  .notification-item:last-child {
    border-bottom: none;
  }
  
  .notification-icon {
    width: 40px;
    height: 40px;
    display: flex;
    align-items: center;
    justify-content: center;
    background-color: #f3f4f6;
    border-radius: 50%;
    flex-shrink: 0;
  }

  .notification-icon svg {
    width: 20px;
    height: 20px;
  }

  .notification-icon.survey {
    background-color: #f3e8ff;
    color: #7e22ce;
  }

  .notification-icon.success {
    background-color: #dcfce7;
    color: #059669;
  }

  .notification-icon.warning {
    background-color: #fef3c7;
    color: #d97706;
  }

  .notification-icon.info {
    background-color: #e0f2fe;
    color: #0284c7;
  }

  .notification-content small {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    color: #6b7280;
    font-size: 0.875rem;
  }

  .notification-content small svg {
    width: 1rem;
    height: 1rem;
  }

  .notification-badge {
    font-size: 0.75rem;
    padding: 0.25rem 0.5rem;
    border-radius: 9999px;
    font-weight: 500;
    margin-left: 0.5rem;
  }

  .notification-badge.success {
    background-color: #dcfce7;
    color: #059669;
  }

  .notification-badge.pending {
    background-color: #f3e8ff;
    color: #7e22ce;
  }
  
  .notification-content h4 {
    margin: 0 0 4px 0;
    font-size: 14px;
    font-weight: 600;
    color: #1f2937;
    display: flex;
    align-items: center;
    gap: 6px;
  }

  .notification-badge {
    padding: 2px 6px;
    font-size: 10px;
    font-weight: 500;
    border-radius: 9999px;
  }

  .notification-badge.pending {
    background-color: #fef3c7;
    color: #d97706;
  }

  .notification-badge.success {
    background-color: #dcfce7;
    color: #059669;
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
    display: flex;
    align-items: center;
    gap: 4px;
  }

  .notification-content button {
    margin-top: 8px;
    background-color: #059669;
    color: white;
    padding: 6px 12px;
    border-radius: 4px;
    font-size: 12px;
    border: none;
    cursor: pointer;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    gap: 4px;
  }
  
  .notification-content button:hover {
    background-color: #047857;
  }

  .notification-content button.secondary {
    background-color: #f3f4f6;
    color: #6b7280;
    border: 1px solid #e5e7eb;
  }

  .notification-content button.secondary:hover {
    background-color: #e5e7eb;
    color: #4b5563;
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

  /* Ajuste para o menu móvel */
  @media (max-width: 767px) {
    .container { padding-bottom: 64px; }
  }

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
    .md\:grid-cols-4 { grid-template-columns: repeat(4, minmax(0, 1fr)); }
    .md\:px-10 { padding-left: 2.5rem; padding-right: 2.5rem; }
    .md\:hidden { display: none; }
    .md\:flex { display: flex; }
  }

  @media (min-width: 1024px) {
    .lg\:grid-cols-3 { grid-template-columns: repeat(3, minmax(0, 1fr)); }
    .lg\:grid-cols-4 { grid-template-columns: repeat(4, minmax(0, 1fr)); }
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
<body class="bg-gray-50 flex flex-col min-h-screen">

<header class="bg-green-700 sticky top-0 z-30 shadow">
  <div class="px-4 py-3 flex items-center justify-between text-white">
    <!-- Troque o src abaixo para o seu arquivo padrão -->
    <img src="/radci/assets/images/logo.png" alt="RADCI" class="h-10 md:h-12 w-auto">
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
    <script>
      // Remove o parâmetro ?answered da URL para não reaparecer no refresh
      (function() {
        const url = new URL(window.location.href);
        if (url.searchParams.has('answered')) {
          url.searchParams.delete('answered');
          window.history.replaceState({}, document.title, url.pathname + url.search);
        }
        const toast = document.getElementById('answeredToast');
        if (toast) {
          setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transform = 'translateX(-50%) translateY(-20px)';
            setTimeout(() => toast.remove(), 300);
          }, 3000);
        }
      })();
    </script>
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

<!-- Container principal termina aqui -->
</div>



  <!-- Categorias de ocorrências (desktop/horizontal) -->
  <section class="mb-4 block">
    <h3 class="text-lg font-bold mb-2">Registre Ocorrências</h3>
    <div class="relative">
      <button type="button" id="catPrev"
              class="absolute left-0 top-1/2 -translate-y-1/2 z-10 bg-white border border-gray-200 shadow rounded-full p-2 hover:bg-white"
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
              class="absolute right-0 top-1/2 -translate-y-1/2 z-10 bg-white border border-gray-200 shadow rounded-full p-2 hover:bg-white"
              aria-label="Próximo">
        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
        </svg>
      </button>
    </div>
  </section>

  <!-- Section mobile vertical removida -->

  <script>
    document.addEventListener('DOMContentLoaded', function() {
      const scroller = document.getElementById('catScroller');
      const prevBtn = document.getElementById('catPrev');
      const nextBtn = document.getElementById('catNext');
      
      function checkScrollButtons() {
        prevBtn.style.display = scroller.scrollLeft > 0 ? 'block' : 'none';
        nextBtn.style.display = (scroller.scrollLeft + scroller.clientWidth) < scroller.scrollWidth ? 'block' : 'none';
      }
      
      scroller.addEventListener('scroll', checkScrollButtons);
      window.addEventListener('resize', checkScrollButtons);
      
      prevBtn.addEventListener('click', () => {
        scroller.scrollBy({ left: -240, behavior: 'smooth' });
      });
      
      nextBtn.addEventListener('click', () => {
        scroller.scrollBy({ left: 240, behavior: 'smooth' });
      });
      
      checkScrollButtons();
    });
  </script>

  <!-- Seção de Pesquisas Disponíveis -->
  <?php if (!$hasAnsweredPriorities): ?>
    <section class="mb-6">
      <div class="bg-white rounded-2xl shadow p-6 border border-gray-200">
        <h3 class="text-lg font-bold text-gray-900 mb-1">Pesquisa de Prioridades</h3>
        <p class="text-sm text-gray-600 mb-4">Ajude-nos a entender quais são as prioridades da sua cidade. Sua opinião é muito importante!</p>
        <a href="prioridades.php" class="w-full inline-block text-center bg-green-600 text-white py-3 rounded-md hover:bg-green-700 font-semibold">Responder Pesquisa</a>
      </div>
    </section>
  <?php endif; ?>

  <?php if (!empty($availableSurveysCTA)): ?>
    <section class="mb-6">
      <?php $sv = $availableSurveysCTA[0]; ?>
      <div class="bg-white rounded-2xl shadow p-6 border border-gray-200">
        <h3 class="text-lg font-bold text-gray-900 mb-1"><?= htmlspecialchars($sv['title']) ?></h3>
        <?php if (!empty($sv['description'])): ?><p class="text-sm text-gray-600 mb-4"><?= htmlspecialchars($sv['description']) ?></p><?php endif; ?>
        <a href="<?= !empty($sv['db_id']) ? ('pesquisa_responder.php?id=' . urlencode($sv['db_id'])) : ('pesquisa_responder.php?sid=' . urlencode($sv['sid'])) ?>" class="w-full inline-block text-center bg-green-600 text-white py-3 rounded-md hover:bg-green-700 font-semibold">Responder Agora</a>
      </div>
    </section>
  <?php endif; ?>

  <section class="mb-10 relative pb-16">
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
          data-titulo="<?= htmlspecialchars($o['categoria'] ?? 'Ocorrência') ?>"
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
              <div class="text-sm font-medium text-gray-900 flex-1 mr-2"><?= htmlspecialchars($o['categoria'] ?? 'Ocorrência') ?></div>
              <?php
                $st = strtolower(trim($o['status'] ?? ''));
                $badgeClass = 'bg-gray-100 text-gray-700';
                if (in_array($st, ['resolvida', 'concluida', 'concluída'])) {
                  $badgeClass = 'bg-green-100 text-green-700';
                } elseif ($st === 'encaminhada') {
                  $badgeClass = 'bg-blue-100 text-blue-700';
                } elseif (in_array($st, ['em_analise', 'em análise', 'em analise'])) {
                  $badgeClass = 'bg-yellow-100 text-yellow-700';
                } elseif ($st === 'cancelada') {
                  $badgeClass = 'bg-red-100 text-red-700';
                }
              ?>
              <span class="text-[11px] px-2 py-1 rounded-full <?= $badgeClass ?> whitespace-nowrap">
                <?= htmlspecialchars($o['status'] ?? '') ?>
              </span>
            </div>
            <?php
              $desc = trim($o['descricao'] ?? '');
              $descShort = function_exists('mb_strimwidth')
                ? mb_strimwidth($desc, 0, 20, '...', 'UTF-8')
                : (strlen($desc) > 20 ? substr($desc, 0, 20) . '...' : $desc);
            ?>
            <div class="text-xs text-gray-500 whitespace-nowrap overflow-hidden text-ellipsis">
              <?= htmlspecialchars($descShort) ?>
            </div>
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

          <?php
            // Decide o destino do botão "Ver Detalhes"
            $detailsHref = 'javascript:void(0)';
            if (!empty($sv['db_id'])) {
              $detailsHref = 'pesquisa_detalhes.php?id=' . urlencode($sv['db_id']);
            } elseif (!empty($sv['sid']) && $sv['sid'] === 'prioridades') {
              // Abre a visualização dedicada das prioridades
              $detailsHref = 'prioridades.php?readonly=1';
            }
          ?>
          <a href="<?= $detailsHref ?>" class="w-full inline-block text-center bg-gray-100 text-gray-700 py-2 rounded-md hover:bg-gray-200 text-sm font-medium">Ver Detalhes</a>
        </article>
      <?php endforeach; ?>
    </div>
  </section>
<?php endif; ?>



  <section class="mt-6">
    <h3 class="text-lg font-bold text-gray-800 mb-3">Dashboard</h3>
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
      <div class="bg-white rounded-xl border border-gray-200 p-4 flex items-center gap-3">
        <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 text-green-700" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M3 3h18v4H3z"/><path d="M7 7v14"/><path d="M17 7v10"/></svg>
        <div><div class="text-xs text-gray-500">Registradas</div><div class="text-xl font-bold"><?= $kpiTotal ?></div></div>
      </div>
      <div class="bg-white rounded-xl border border-gray-200 p-4 flex items-center gap-3">
        <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M5 13l4 4L19 7"/></svg>
        <div><div class="text-xs text-gray-500">Concluídas</div><div class="text-xl font-bold"><?= $kpiConcluidas ?></div></div>
      </div>
      <div class="bg-white rounded-xl border border-gray-200 p-4 flex items-center gap-3">
        <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" viewBox="0 0 24 24"><path fill="#facc15" d="M12 2L1 21h22L12 2z M11 16h2v2h-2zm0-7h2v5h-2z"/></svg>
        <div><div class="text-xs text-gray-500">Em Análise</div><div class="text-xl font-bold"><?= $kpiEmAnalise ?></div></div>
      </div>
      <div class="bg-white rounded-xl border border-gray-200 p-4 flex items-center gap-3">
        <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 text-green-700" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M9 5l7 7-7 7"/><path d="M4 12h9"/></svg>
        <div><div class="text-xs text-gray-500">Encaminhadas</div><div class="text-xl font-bold"><?= $kpiEncaminhadas ?></div></div>
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

    <div class="modal-body overflow-x-hidden">
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

        <div class="md:col-span-2 bg-gray-50 rounded-lg p-4">
          <span class="text-gray-600">Descrição</span>
          <div id="evDescricao"
               class="font-medium text-gray-900 break-words whitespace-normal"
               style="overflow-wrap:anywhere; word-break: break-word;">—</div>
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

  // Viewer em modal branco (mesmo estilo do modal de detalhes)
  let currentImages = [];
  function openImageModal(startIndex) {
    const modal = document.createElement('div');
    modal.className = 'modal';
    modal.style.display = 'block';
    modal.innerHTML = `
      <div class="modal-content" style="width:95%; max-width:900px; max-height:80vh; overflow:hidden;">
        <div class="modal-header">
          <h2 class="font-bold text-gray-900 text-lg">Visualização</h2>
          <button type="button" id="ivClose" class="close" aria-label="Fechar">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
            </svg>
          </button>
        </div>
        <div class="modal-body relative flex items-center justify-center"
             style="height:calc(60vh - 72px); padding-top:12px; padding-bottom:12px;">
          <button id="ivPrev" class="absolute left-4 top-1/2 -translate-y-1/2 px-3 py-2 bg-white/70 text-gray-900 rounded shadow hover:bg-white">‹</button>
          <div id="ivStage"
               class="w-full h-full overflow-hidden flex items-center justify-center bg-white"
               style="touch-action:none; padding:24px; border-radius:12px; box-sizing:border-box;">
            <img id="ivImg" alt="Imagem ampliada"
                 style="
                   max-width: 75%;
                   max-height: 75%;
                   width: auto;
                   height: auto;
                   object-fit: contain;
                   border-radius: 8px;
                   transform: translate(0px, 0px) scale(0.85);
                   transform-origin: center center;
                   cursor: default;
                 " />
          </div>
          <button id="ivNext" class="absolute right-4 top-1/2 -translate-y-1/2 px-3 py-2 bg-white/70 text-gray-900 rounded shadow hover:bg-white">›</button>
        </div>
      </div>
    `;
    document.body.appendChild(modal);
    document.body.style.overflow = 'hidden';

    const ivImg    = modal.querySelector('#ivImg');
    const ivStage  = modal.querySelector('#ivStage');
    const btnPrev  = modal.querySelector('#ivPrev');
    const btnNext  = modal.querySelector('#ivNext');
    const btnClose = modal.querySelector('#ivClose');

    let index = typeof startIndex === 'number' ? startIndex : 0;
    const safeIndex = (i) => currentImages.length ? (i + currentImages.length) % currentImages.length : 0;

    // Estado de zoom/pan com escala base menor
    const baseScale = 0.85;
    let scale = baseScale, tx = 0, ty = 0;
    let dragging = false, lastX = 0, lastY = 0;
    let pinchActive = false, pinchStartDist = 0;

    const applyTransform = () => {
      ivImg.style.transform = `translate(${tx}px, ${ty}px) scale(${scale})`;
      ivImg.style.cursor = scale > 1 ? (dragging ? 'grabbing' : 'grab') : 'default';
    };
    const clampScale = (s) => Math.min(5, Math.max(0.5, s));

    const render = () => {
      ivImg.src = currentImages[safeIndex(index)];
      scale = baseScale; tx = 0; ty = 0; dragging = false; pinchActive = false;
      applyTransform();
    };
    render();

    // Zoom – roda do mouse
    ivStage.addEventListener('wheel', (e) => {
      e.preventDefault();
      const dir = e.deltaY < 0 ? 1 : -1;
      scale = clampScale(scale + dir * 0.25);
      applyTransform();
    }, { passive: false });

    // Duplo clique alterna entre base (0.85) e 2x
    ivStage.addEventListener('dblclick', () => {
      scale = scale <= baseScale ? 2 : baseScale;
      tx = 0; ty = 0;
      applyTransform();
    });

    // Pan com mouse quando ampliada
    ivStage.addEventListener('mousedown', (e) => {
      if (scale <= 1) return;
      dragging = true;
      lastX = e.clientX; lastY = e.clientY;
      ivImg.style.cursor = 'grabbing';
    });
    const onMouseMove = (e) => {
      if (!dragging) return;
      tx += e.clientX - lastX;
      ty += e.clientY - lastY;
      lastX = e.clientX; lastY = e.clientY;
      applyTransform();
    };
    const onMouseUp = () => {
      dragging = false;
      ivImg.style.cursor = scale > 1 ? 'grab' : 'default';
    };
    document.addEventListener('mousemove', onMouseMove);
    document.addEventListener('mouseup', onMouseUp);

    // Pinch zoom e pan no touch
    const getDist = (t) => Math.hypot(t[0].clientX - t[1].clientX, t[0].clientY - t[1].clientY);
    ivStage.addEventListener('touchstart', (e) => {
      if (e.touches.length === 2) {
        pinchActive = true;
        pinchStartDist = getDist(e.touches);
      } else if (e.touches.length === 1 && scale > 1) {
        dragging = true;
        lastX = e.touches[0].clientX; lastY = e.touches[0].clientY;
      }
    }, { passive: true });
    ivStage.addEventListener('touchmove', (e) => {
      if (pinchActive && e.touches.length === 2) {
        e.preventDefault();
        const dist = getDist(e.touches);
        const factor = dist / pinchStartDist;
        scale = clampScale(scale * factor);
        pinchStartDist = dist;
        applyTransform();
      } else if (dragging && e.touches.length === 1) {
        e.preventDefault();
        const x = e.touches[0].clientX, y = e.touches[0].clientY;
        tx += x - lastX; ty += y - lastY;
        lastX = x; lastY = y;
        applyTransform();
      }
    }, { passive: false });
    ivStage.addEventListener('touchend', () => { pinchActive = false; dragging = false; }, { passive: true });

    // Navegação e fechamento
    const close = () => {
      modal.remove();
      document.body.style.overflow = '';
      document.removeEventListener('keydown', escListener);
      document.removeEventListener('mousemove', onMouseMove);
      document.removeEventListener('mouseup', onMouseUp);
    };
    btnClose.addEventListener('click', close);
    modal.addEventListener('click', (e) => { if (e.target === modal) close(); });
    const escListener = (e) => { if (e.key === 'Escape') close(); };
    document.addEventListener('keydown', escListener);

    btnPrev.addEventListener('click', () => { index = safeIndex(index - 1); render(); });
    btnNext.addEventListener('click', () => { index = safeIndex(index + 1); render(); });
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

    currentImages = (first ? [first, ...imgs] : imgs).slice(0, 12);

    // Miniaturas que abrem o visualizador ao clicar
    currentImages.forEach((src, i) => {
      const img = document.createElement('img');
      img.src = src;
      img.alt = 'evidência';
      img.style.width = '100%';
      img.style.height = '96px';
      img.style.objectFit = 'cover';
      img.className = 'rounded cursor-pointer hover:opacity-80 transition-opacity';
      img.addEventListener('click', () => openImageModal(i));
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

    // Inicializa a flag de estado no cliente (persistida enquanto a página não recarregar)
    window.notificationsCleared = window.notificationsCleared || false;
    
    // Verifica se é a primeira visita do dia
    <?php
    $lastVisit = $_SESSION['last_visit'] ?? '';
    $today = date('Y-m-d');
    if ($lastVisit !== $today) {
      $_SESSION['last_visit'] = $today;
      $_SESSION['show_welcome'] = true;
    }
    ?>

    let notificationsHTML = '';
    
    <?php
    // Usa notificações já carregadas pelo NotificacaoManager
    $notificacoesNaoLidas = is_array($notificacoesNaoLidas ?? null) ? $notificacoesNaoLidas : [];
    
    // Verifica primeira visita do dia
    $last_visit = $_SESSION['last_visit'] ?? null;
    $today = date('Y-m-d');
    
    if (!$last_visit || $last_visit !== $today) {
      $_SESSION['last_visit'] = $today;
      $_SESSION['show_welcome'] = true;
    }
    ?>
    
    <?php if (isset($_SESSION['show_welcome'])): ?>
    notificationsHTML += `
      <div class="notification-item">
        <div class="notification-icon welcome">
          <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z"/>
          </svg>
        </div>
        <div class="notification-content">
          <h4>
            Bem-vindo(a) de volta!
            <span class="notification-badge new">Hoje</span>
          </h4>
          <p>Olá <?= htmlspecialchars($primeiroNome) ?>, que bom ter você de volta! Continue contribuindo para tornar nossa cidade mais inteligente.</p>
          <small>
            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            Agora mesmo
          </small>
        </div>
      </div>`;
    <?php
    unset($_SESSION['show_welcome']);
    endif; ?>

    // Adiciona notificações do banco somente se não estiverem limpas no cliente
    if (!window.notificationsCleared) {
    <?php foreach ($notificacoesNaoLidas as $notif): ?>
    notificationsHTML += `
      <div class="notification-item" data-id="<?= $notif['id'] ?>">
        <div class="notification-icon <?= $notif['tipo'] ?>">
          <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="<?= $notif['icone'] ?? 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z' ?>"/>
          </svg>
        </div>
        <div class="notification-content">
          <h4>
            <?= htmlspecialchars($notif['titulo']) ?>
            <span class="notification-badge <?= $notif['tipo'] ?>">Novo</span>
          </h4>
          <p><?= htmlspecialchars($notif['mensagem']) ?></p>
          <small>
            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <?= date('d/m/Y H:i', strtotime($notif['data_criacao'])) ?>
          </small>
          <?php if (!empty($notif['link'])): ?>
          <button onclick="location.href='<?= htmlspecialchars($notif['link']) ?>'" class="mt-2">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
            </svg>
            Ver Detalhes
          </button>
          <?php endif; ?>
        </div>
      </div>`;
    <?php endforeach; ?>
    }
    
    // Adicionar notificações de pesquisas não respondidas
    <?php if (!$hasAnsweredPriorities): ?>
    notificationsHTML += `
      <div class="notification-item">
        <div class="notification-icon survey">
          <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/>
          </svg>
        </div>
        <div class="notification-content">
          <h4>
            Pesquisa de Prioridades
            <span class="notification-badge pending">Pendente</span>
          </h4>
          <p>Há uma pesquisa sobre as prioridades da sua cidade aguardando sua resposta.</p>
          <small>
            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 inline-block align-text-bottom" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            Aguardando resposta
          </small>
          <a href="prioridades.php" class="mt-2 inline-block">
            Responder Agora
          </a>
        </div>
      </div>`;
    <?php endif; ?>
    
    <?php foreach($availableSurveys as $sv): ?>
    notificationsHTML += `
      <div class="notification-item">
        <div class="notification-icon survey">
          <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
          </svg>
        </div>
        <div class="notification-content">
          <h4>
            <?= htmlspecialchars($sv['title']) ?>
            <span class="notification-badge pending">Nova</span>
          </h4>
          <p><?= htmlspecialchars($sv['description'] ?? 'Nova pesquisa disponível para resposta.') ?></p>
          <small>
            <svg xmlns="http://www.w3.org/2000/svg" class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>
            </svg>
            Agora disponível
          </small>
          <button onclick="location.href='<?= !empty($sv['db_id']) ? ('pesquisa_responder.php?id=' . urlencode($sv['db_id'])) : ('pesquisa_responder.php?sid=' . urlencode($sv['sid'])) ?>'" class="w-full bg-green-600 text-white py-3 rounded-md hover:bg-green-700 font-semibold">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            Responder Agora
          </button>
        </div>
      </div>`;
    <?php endforeach; ?>
    
    // Notificações de ocorrências
    <?php foreach($ocorrencias as $index => $ocorrencia): 
      if ($index > 4) break; // Limita a 5 notificações mais recentes
    ?>
    notificationsHTML += `
      <div class="notification-item">
        <div class="notification-icon <?= $ocorrencia['status'] === 'concluída' ? 'success' : ($ocorrencia['status'] === 'em andamento' ? 'info' : 'warning') ?>">
          <?php if ($ocorrencia['status'] === 'concluída'): ?>
          <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
          </svg>
          <?php elseif ($ocorrencia['status'] === 'em andamento'): ?>
          <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M13 10V3L4 14h7v7l9-11h-7z"/>
          </svg>
          <?php else: ?>
          <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
          </svg>
          <?php endif; ?>
        </div>
        <div class="notification-content">
          <h4>
            <?= htmlspecialchars($ocorrencia['categoria']) ?>
            <span class="notification-badge <?= $ocorrencia['status'] === 'concluída' ? 'success' : 'pending' ?>">
              <?= ucfirst($ocorrencia['status']) ?>
            </span>
          </h4>
          <p><?= htmlspecialchars($ocorrencia['descricao']) ?></p>
          <small>
            <svg xmlns="http://www.w3.org/2000/svg" class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>
            </svg>
            <?= $ocorrencia['data'] ?>
          </small>
          <button onclick="location.href='minhas_ocorrencias.php#ocorrencia-<?= $ocorrencia['id'] ?>'">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
            </svg>
            Ver Detalhes
          </button>
        </div>
      </div>`;
    <?php endforeach; ?>
    
    notificationModal.innerHTML = `
      <div class="modal-content">
        <div class="modal-header">
          <h2>Notificações</h2>
          <div class="notification-actions">
            <button type="button" class="clear-notifications" onclick="clearAllNotifications()">
              <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m-6 5v6m4-6v6"/>
              </svg>
              Limpar Todas
            </button>
            <button type="button" class="close-btn" onclick="closeNotificationModal()">
              <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M18 6L6 18M6 6l12 12"/>
              </svg>
            </button>
          </div>
        </div>
        <div class="modal-body">
          ${notificationsHTML}
        </div>
      </div>
    `;
    
    document.body.appendChild(notificationModal);
    document.body.style.overflow = 'hidden';

    // Fechar modal ao clicar fora
    notificationModal.addEventListener('click', function(e) {
      if (e.target === notificationModal) {
        closeNotificationModal();
      }
    });
  });

  // Função para marcar notificação como lida
  function marcarNotificacaoComoLida(notificacaoId) {
    fetch('marcar_notificacao_lida.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json'
      },
      body: JSON.stringify({ 
        notificacao_id: notificacaoId,
        usuario_id: <?= $userId ?>
      })
    })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        // Atualiza o contador de notificações
        const badge = document.getElementById('bellBadge');
        if (badge) {
          const count = parseInt(badge.textContent) - 1;
          if (count > 0) {
            badge.textContent = count;
          } else {
            badge.classList.add('hidden');
          }
        }
      }
    })
    .catch(error => console.error('Erro ao marcar notificação como lida:', error));
  }

  // Função para fechar modal de notificações
  window.closeNotificationModal = function() {
    const modal = document.getElementById('notificationModal');
    if (modal) {
      modal.remove();
      document.body.style.overflow = '';
    }
  };

  // Função para limpar todas as notificações
  window.clearAllNotifications = function() {
    fetch('limpar_notificacoes.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ userId: <?= $userId ?> })
    })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        // Marca como limpas no cliente
        window.notificationsCleared = true;

        // Atualiza badge
        const badge = document.getElementById('bellBadge');
        if (badge) {
          badge.textContent = '0';
          badge.classList.add('hidden');
        }

        // Remove itens visíveis do modal imediatamente
        document.querySelectorAll('#notificationModal .notification-item').forEach(el => el.remove());

        // Fecha o modal
        closeNotificationModal();
      }
    })
    .catch(error => console.error('Erro ao limpar notificações:', error));
  };

  // Quando o modal de notificações é aberto
  document.addEventListener('DOMContentLoaded', function() {
    const notificationButton = document.getElementById('notificationButton');
    if (notificationButton) {
      notificationButton.addEventListener('click', function() {
        // Marca todas as notificações visíveis como lidas
        const notifications = document.querySelectorAll('.notification-item[data-id]');
        notifications.forEach(notification => {
          const notificacaoId = notification.dataset.id;
          marcarNotificacaoComoLida(notificacaoId);
        });
      });
    }
  });
})();
</script>

<?php include __DIR__ . '/../includes/mobile_nav.php'; ?>

<script>
(function() {
  // Oculta o toast de confirmação após 3s com animação
  const toast = document.getElementById('answeredToast');
  if (toast) {
    setTimeout(() => {
      toast.style.opacity = '0';
      toast.style.transform = 'translateX(-50%) translateY(-20px)';
      setTimeout(() => toast.remove(), 300);
    }, 3000);
  }
})();
</script>
</body>
</html>
