<?php
session_start();

require_once __DIR__ . '/../includes/db.php';
$pdo = get_pdo();

$flash_success = '';
$flash_error   = '';

// Gate de acesso: Admin (10) e Prefeito (2)
$perfilAtual = intval($_SESSION['usuario_perfil'] ?? 0);
if (!$perfilAtual || !in_array($perfilAtual, [10, 2], true)) {
    header("Location: index.php");
    exit;
}

// Carrega opções distintas de Cidade (municipio) e UF dos usuários
$municipiosOptions = [];
$ufsOptions = [];
try {
    $municipiosOptions = $pdo->query("
        SELECT DISTINCT municipio
        FROM usuarios
        WHERE municipio IS NOT NULL AND TRIM(municipio) <> ''
        ORDER BY municipio
    ")->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $_) {}
try {
    $ufsOptions = $pdo->query("
        SELECT DISTINCT UPPER(uf)
        FROM usuarios
        WHERE uf IS NOT NULL AND TRIM(uf) <> ''
        ORDER BY UPPER(uf)
    ")->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $_) {}

// Mapeia tipo de destinatário -> perfis
function mapPerfis($tipo) {
    $tipo = strtolower(trim($tipo));
    if ($tipo === 'cidadaos') return [1];
    if ($tipo === 'prefeitos' || $tipo === 'admin_publicos') return [2];
    if ($tipo === 'secretarios') return [3];
    if ($tipo === 'admins') return [10];
    return [1, 2, 3, 10]; // todos os perfis
}

// Endpoint para calcular alcance
if (isset($_GET['calc']) && $_GET['calc'] === '1') {
    header('Content-Type: application/json');
    $tipo   = $_GET['recipientType'] ?? 'todos';
    $cidade = trim($_GET['cidade'] ?? '');
    $uf     = strtoupper(trim($_GET['uf'] ?? ''));

    $perfis = mapPerfis($tipo);
    $sql = "SELECT COUNT(*) FROM usuarios WHERE perfil IN (" . implode(',', array_map('intval', $perfis)) . ")";
    $params = [];
    if ($cidade !== '') { $sql .= " AND municipio = ?"; $params[] = $cidade; }
    if ($uf !== '')     { $sql .= " AND UPPER(uf) = ?"; $params[] = $uf; }

    $count = 0;
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $count = (int)$stmt->fetchColumn();
    } catch (Throwable $_) {}

    echo json_encode(['count' => $count]);
    exit;
}

// Back URL: volta à tela anterior; fallback conforme perfil
$defaultBack = ($perfilAtual === 10) ? 'admin_inicio.php' : 'prefeito_inicio.php';
$backUrl = $_GET['back'] ?? ($_SESSION['criar_pesquisa_back'] ?? '');
if (!$backUrl) {
    $ref = $_SERVER['HTTP_REFERER'] ?? '';
    if ($ref && stripos($ref, 'criar_pesquisa.php') === false) {
        $backUrl = $ref;
    }
}
$_SESSION['criar_pesquisa_back'] = $backUrl ?: $defaultBack;

// criação das tabelas auxiliares (se não existirem)
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS pesquisa_perguntas (
            id INT AUTO_INCREMENT PRIMARY KEY,
            pesquisa_id INT NOT NULL,
            ordem INT NOT NULL,
            tipo VARCHAR(30) NOT NULL,
            texto VARCHAR(255) NOT NULL,
            opcoes_json TEXT NULL,
            obrigatoria TINYINT(1) NOT NULL DEFAULT 0,
            INDEX (pesquisa_id)
        )
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS pesquisa_respostas (
            id INT AUTO_INCREMENT PRIMARY KEY,
            pesquisa_id INT NOT NULL,
            pergunta_id INT NOT NULL,
            usuario_id INT NOT NULL,
            perfil_usuario INT NOT NULL,
            cidade VARCHAR(100) NULL,
            uf VARCHAR(2) NULL,
            resposta_json TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX (pesquisa_id),
            INDEX (pergunta_id),
            INDEX (usuario_id)
        )
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS pesquisa_meta (
            id INT AUTO_INCREMENT PRIMARY KEY,
            sid VARCHAR(100) UNIQUE,
            titulo VARCHAR(255) NOT NULL,
            descricao TEXT NULL,
            tipo_destinatario VARCHAR(50) NULL,
            cidade VARCHAR(100) NULL,
            uf VARCHAR(2) NULL,
            json LONGTEXT NULL,
            criador_usuario_id INT NULL,
            criador_perfil INT NULL,
            criado_por VARCHAR(20) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS pesquisa (
            id INT AUTO_INCREMENT PRIMARY KEY,
            sid VARCHAR(100) UNIQUE,
            titulo VARCHAR(255) NOT NULL,
            descricao TEXT NULL,
            tipo_destinatario VARCHAR(50) NULL,
            cidade VARCHAR(100) NULL,
            uf VARCHAR(2) NULL,
            json LONGTEXT NULL,
            criador_usuario_id INT NULL,
            criador_perfil INT NULL,
            criado_por VARCHAR(20) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_pesquisa_destino (tipo_destinatario, cidade, uf)
        )
    ");
} catch (Throwable $_) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $json = $_POST['survey_json'] ?? '';
    if (!$json) {
        $flash_error = 'Dados da pesquisa não enviados.';
    } else {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            $flash_error = 'Formato inválido dos dados.';
        } else {
            $title = trim($data['title'] ?? '');
            $questions = $data['questions'] ?? [];
            if ($title === '') {
                $flash_error = 'O título da pesquisa é obrigatório.';
            } elseif (!is_array($questions) || count($questions) === 0) {
                $flash_error = 'Adicione ao menos uma pergunta.';
            } else {
                $outDir = __DIR__ . '/../uploads/surveys';
                if (!is_dir($outDir)) { @mkdir($outDir, 0777, true); }
                $fname = 'survey_' . date('Ymd_His') . '_' . substr(sha1(uniqid('', true)), 0, 8) . '.json';
                $path  = $outDir . DIRECTORY_SEPARATOR . $fname;

                @file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                if (file_exists($path)) {
                    $sid = basename($fname, '.json');
                    $recipientType = $data['recipientType'] ?? 'todos';
                    $targetCity    = trim($data['targetCity'] ?? '');
                    $targetUF      = strtoupper(trim($data['targetUF'] ?? ''));

                    // 1) Insere na tabela canônica 'pesquisa_meta'
                    $pesquisaIdMeta = 0;
                    try {
                        $insMeta = $pdo->prepare("
                            INSERT INTO pesquisa_meta (sid, titulo, descricao, tipo_destinatario, cidade, uf, json, criador_usuario_id, criador_perfil, criado_por)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        $insMeta->execute([
                            $sid,
                            $data['title'] ?? 'Pesquisa',
                            $data['description'] ?? '',
                            $recipientType,
                            $targetCity ?: null,
                            $targetUF ?: null,
                            $json,
                            intval($_SESSION['usuario_id'] ?? 0) ?: null,
                            $perfilAtual ?: null,
                            ($perfilAtual === 10 ? 'admin' : ($perfilAtual === 2 ? 'prefeito' : null)),
                        ]);
                        $pesquisaIdMeta = (int)$pdo->lastInsertId();
                    } catch (Throwable $_) {}

                    // 2) Compatibilidade: grava também em 'pesquisa' (se existir)
                    try {
                        $colsStmt = $pdo->query("SHOW COLUMNS FROM pesquisa");
                        $cols = array_map(fn($r) => $r['Field'], $colsStmt->fetchAll());
                        $candidate = [
                            'sid'               => $sid,
                            'titulo'            => $data['title'] ?? 'Pesquisa',
                            'descricao'         => $data['description'] ?? '',
                            'tipo_destinatario' => $recipientType,
                            'cidade'            => $targetCity ?: null,
                            'uf'                => $targetUF ?: null,
                            'json'              => $json,
                            'criador_usuario_id'=> intval($_SESSION['usuario_id'] ?? 0) ?: null,
                            'criador_perfil'    => $perfilAtual ?: null,
                            'criado_por'        => ($perfilAtual === 10 ? 'admin' : ($perfilAtual === 2 ? 'prefeito' : null)),
                        ];
                        $insertData = array_filter($candidate, fn($k) => in_array($k, $cols), ARRAY_FILTER_USE_KEY);
                        if (!empty($insertData)) {
                            $names = array_keys($insertData);
                            $place = implode(',', array_fill(0, count($names), '?'));
                            $sql   = "INSERT INTO pesquisa (" . implode(',', $names) . ") VALUES ($place)";
                            $stmt  = $pdo->prepare($sql);
                            $stmt->execute(array_values($insertData));
                        }
                    } catch (Throwable $_) {}

                    // 3) Salva perguntas usando o id de 'pesquisa_meta'
                    try {
                        $pesquisaId = $pesquisaIdMeta;
                        if ($pesquisaId > 0 && is_array($questions)) {
                            $ins = $pdo->prepare("
                                INSERT INTO pesquisa_perguntas (pesquisa_id, ordem, tipo, texto, opcoes_json, obrigatoria)
                                VALUES (?, ?, ?, ?, ?, ?)
                            ");
                            foreach ($questions as $idx => $q) {
                                $tipo = trim($q['type'] ?? 'texto');
                                $texto = trim($q['text'] ?? '');
                                $req = !empty($q['required']) ? 1 : 0;

                                $opcoes = null;
                                if ($tipo === 'multiple') {
                                    $opcoes = json_encode(['options' => (array)($q['options'] ?? [])], JSON_UNESCAPED_UNICODE);
                                } elseif ($tipo === 'prioridades') {
                                    $opcoes = json_encode(['items' => (array)($q['options'] ?? [])], JSON_UNESCAPED_UNICODE);
                                } elseif ($tipo === 'nota') {
                                    $opcoes = json_encode(['min' => 1, 'max' => 5], JSON_UNESCAPED_UNICODE);
                                } else {
                                    $tipo = 'texto';
                                }

                                if ($texto !== '') {
                                    $ins->execute([
                                        $pesquisaId, $idx + 1, $tipo, $texto, $opcoes, $req
                                    ]);
                                }
                            }
                        }
                    } catch (Throwable $_) {}

                    $flash_success = 'Pesquisa criada e enviada com sucesso!';
                } else {
                    $flash_error = 'Falha ao salvar a pesquisa.';
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8" />
    <title>Criar Nova Pesquisa - RADCI</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://unpkg.com/lucide@latest"></script>
</head>
<body class="bg-white min-h-screen">
    <!-- Header conforme perfil (menus iguais aos das telas principais) -->
    <header class="bg-green-700 text-white">
        <div class="container mx-auto px-6 py-4 flex items-center justify-between relative">
            <img src="/radci/assets/images/logo.png" alt="RADCI" class="h-8 w-auto" />
            <?php if ($perfilAtual === 10): ?>
                <nav class="hidden md:flex items-center gap-6">
                    <a href="admin_inicio.php" class="hover:underline">Início</a>
                    <a href="usuarios.php" class="hover:underline">Usuários</a>
                    <a href="ocorrencias_admin.php" class="hover:underline">Ocorrências</a>
                    <a href="relatorios_admin.php" class="hover:underline">Relatórios</a>
                    <a href="criar_pesquisa.php" class="hover:underline font-semibold">Criar Pesquisa</a>
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
                    <a href="criar_pesquisa.php" class="block px-4 py-2 hover:bg-gray-100 font-semibold">Criar Pesquisa</a>
                    <a href="pesquisa_respostas_admin.php" class="block px-4 py-2 hover:bg-gray-100">Respostas</a>
                    <a href="login_cadastro.php?logout=1" class="block px-4 py-2 hover:bg-gray-100">Sair</a>
                </div>
            <?php elseif ($perfilAtual === 2): ?>
                <nav class="hidden md:flex items-center gap-6">
                    <a href="prefeito_inicio.php" class="hover:underline">Início</a>
                    <a href="gestor_secretarios.php" class="hover:underline">Meus Secretários</a>
                    <a href="ocorrencias.php" class="hover:underline">Ocorrências</a>
                    <a href="relatorios_prefeito.php" class="hover:underline">Relatórios</a>
                    <a href="criar_pesquisa.php" class="hover:underline font-semibold">Criar Pesquisa</a>
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
                    <a href="criar_pesquisa.php" class="block px-4 py-2 hover:bg-gray-100 font-semibold">Criar Pesquisa</a>
                    <a href="pesquisa_respostas_prefeito.php" class="block px-4 py-2 hover:bg-gray-100">Respostas</a>
                    <a href="login_cadastro.php?logout=1" class="block px-4 py-2 hover:bg-gray-100">Sair</a>
                </div>
            <?php endif; ?>
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

        // Auto-esconde o alerta de sucesso após 3s
        const flashSuccess = document.getElementById('flash-success');
        if (flashSuccess) {
          setTimeout(() => {
            flashSuccess.remove();
          }, 3000);
        }
      });
    </script>

    <main class="container mx-auto px-6 py-8 max-w-5xl">
        <a href="<?= htmlspecialchars($_SESSION['criar_pesquisa_back'] ?? $defaultBack) ?>" class="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-gray-100 text-gray-700 hover:bg-gray-200 mb-6">
            <i data-lucide="arrow-left" class="w-4 h-4"></i>
            Voltar
        </a>

        <h1 class="text-3xl font-bold text-gray-900 mb-2">Criar Nova Pesquisa</h1>
        <p class="text-gray-600 mb-6">Crie pesquisas personalizadas e envie diretamente para o perfil dos clientes.</p>

        <?php if ($flash_success): ?>
            <div id="flash-success" class="mb-6 bg-green-50 border border-green-200 text-green-800 rounded-lg p-4">
                <?= htmlspecialchars($flash_success) ?>
            </div>
        <?php endif; ?>
        <?php if ($flash_error): ?>
            <div class="mb-6 bg-red-50 border border-red-200 text-red-800 rounded-lg p-4">
                <?= htmlspecialchars($flash_error) ?>
            </div>
        <?php endif; ?>

        <form id="surveyForm" method="POST" class="space-y-6">
            <input type="hidden" id="surveyJson" name="survey_json" />

            <!-- Informações Básicas -->
            <section class="bg-gray-50 rounded-xl shadow p-6">
                <h2 class="text-xl font-semibold text-gray-900 mb-4">Informações Básicas</h2>
                <div class="grid gap-4">
                    <div>
                        <label for="surveyTitle" class="block text-sm font-medium text-gray-700">Título da Pesquisa *</label>
                        <input id="surveyTitle" type="text" class="mt-1 w-full rounded-md border-gray-300" placeholder="Ex: Pesquisa de Satisfação do Transporte Público" required />
                    </div>
                    <div>
                        <label for="surveyDesc" class="block text-sm font-medium text-gray-700">Descrição *</label>
                        <textarea id="surveyDesc" rows="3" class="mt-1 w-full rounded-md border-gray-300" placeholder="Descreva o objetivo desta pesquisa..." required></textarea>
                    </div>
                </div>
            </section>

            <!-- Perguntas -->
            <section class="bg-gray-50 rounded-xl shadow p-6">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-xl font-semibold text-gray-900">Perguntas</h2>
                    <button type="button" id="btnAddQuestion" class="inline-flex items-center gap-2 bg-green-600 text-white px-4 py-2 rounded-md hover:bg-green-700">
                        <i data-lucide="plus" class="w-4 h-4"></i>
                        Adicionar Pergunta
                    </button>
                </div>

                <div id="questionList" class="space-y-4">
                    <!-- cards de perguntas gerados pelo JS -->
                </div>
            </section>

            <!-- Destinatários (visual refinado) -->
            <section class="bg-gray-50 rounded-xl shadow p-6">
              <div class="flex items-center justify-between mb-4">
                <h2 class="text-xl font-semibold text-gray-900">Destinatários</h2>
                <span class="text-xs text-gray-500">Defina o público e a localização</span>
              </div>
            
              <div class="grid md:grid-cols-3 gap-4">
                <div class="md:col-span-3">
                  <label class="block text-sm text-gray-700 mb-1">Selecione o tipo de destinatário</label>
                  <select id="recipientType" class="w-full rounded-md border-gray-300">
                    <option value="cidadaos">Cidadãos</option>
                    <option value="prefeitos">Prefeitos</option>
                    <option value="secretarios">Secretários / Assessores</option>
                    <option value="admins">Admins</option>
                    <option value="todos" selected>Todos os perfis</option>
                  </select>
                </div>
            
                <div class="md:col-span-2">
                  <label class="block text-sm text-gray-700 mb-1">Cidade (opcional)</label>
                  <select id="targetCity" class="mt-1 w-full rounded-md border-gray-300">
                    <option value="">Todas as cidades</option>
                    <?php foreach ($municipiosOptions as $m): ?>
                      <option value="<?= htmlspecialchars($m) ?>"><?= htmlspecialchars($m) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
            
                <div>
                  <label class="block text-sm text-gray-700 mb-1">UF (opcional)</label>
                  <select id="targetUF" class="mt-1 w-full rounded-md border-gray-300 text-center uppercase">
                    <option value="">TODAS AS UFS</option>
                    <?php foreach ($ufsOptions as $u): ?>
                      <option value="<?= htmlspecialchars(strtoupper($u)) ?>"><?= htmlspecialchars(strtoupper($u)) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>
            
              <div class="mt-4 flex items-center gap-2 text-sm">
                <span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-green-100 text-green-700">👥</span>
                <span>Esta pesquisa alcançará: <strong id="alcanceCount">0</strong> usuários</span>
                <span id="alcanceLoading" class="ml-2 text-gray-500 hidden">calculando…</span>
              </div>
            
              <p class="text-xs text-gray-500 mt-2">Dica: os valores de Cidade/UF vêm dos endereços dos usuários cadastrados.</p>
            </section>

                <div class="flex gap-4">
                    <button type="submit" class="flex-1 bg-green-600 text-white px-6 py-3 rounded-md hover:bg-green-700 inline-flex items-center justify-center gap-2">
                        <i data-lucide="send" class="w-4 h-4"></i>
                        Criar e Enviar Pesquisa
                    </button>
                    <a href="<?= htmlspecialchars($_SESSION['criar_pesquisa_back'] ?? $defaultBack) ?>" class="flex-1 text-center bg-gray-100 text-gray-700 px-6 py-3 rounded-md hover:bg-gray-200 inline-flex items-center justify-center gap-2">
                        <i data-lucide="x" class="w-4 h-4"></i>
                        Cancelar
                    </a>
                </div>
            </form>
        </main>

        <script>
        document.addEventListener('DOMContentLoaded', () => {
            if (window.lucide && typeof lucide.createIcons === 'function') {
                lucide.createIcons();
            }

            const questionList = document.getElementById('questionList');
            const btnAddQuestion = document.getElementById('btnAddQuestion');

            btnAddQuestion.addEventListener('click', () => addQuestionCard('texto'));

            function addQuestionCard(defaultType = 'texto') {
                const card = document.createElement('div');
                card.className = 'question-card bg-white rounded-lg border border-gray-200 p-4';
                card.innerHTML = `
                    <div class="flex items-start gap-3">
                        <div class="flex-1 space-y-3">
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Pergunta *</label>
                                <input type="text" data-role="text" class="mt-1 w-full rounded-md border-gray-300" placeholder="Digite a pergunta..." required />
                            </div>
                            <div class="grid sm:grid-cols-3 gap-3">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700">Tipo</label>
                                    <!-- Dentro do card de Perguntas, adiciona o novo tipo no select -->
                                    <select data-role="type" class="mt-1 w-full rounded-md border-gray-300">
                                        <option value="texto">Resposta em texto</option>
                                        <option value="multiple">Múltipla escolha</option>
                                        <option value="nota">Nota 1–5</option>
                                    </select>
                                </div>
                                <div class="sm:col-span-2 flex items-center gap-3">
                                    <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                                        <input type="checkbox" data-role="required" class="accent-green-600">
                                        Obrigatória
                                    </label>
                                </div>
                            </div>
                            <div data-role="options" class="hidden">
                                <label class="block text-sm font-medium text-gray-700">Opções</label>
                                <div class="space-y-2" data-role="optList"></div>
                                <button type="button" data-role="addOption" class="mt-2 inline-flex items-center gap-2 bg-gray-100 text-gray-700 px-3 py-1 rounded hover:bg-gray-200">
                                    <i data-lucide="plus" class="w-4 h-4"></i>
                                    Adicionar opção
                                </button>
                            </div>
                        </div>
                        <div class="flex flex-col gap-2">
                            <button type="button" data-role="up" class="px-2 py-1 rounded bg-gray-100 hover:bg-gray-200" title="Mover para cima">▲</button>
                            <button type="button" data-role="down" class="px-2 py-1 rounded bg-gray-100 hover:bg-gray-200" title="Mover para baixo">▼</button>
                            <button type="button" data-role="remove" class="px-2 py-1 rounded bg-red-100 text-red-700 hover:bg-red-200" title="Remover">Remover</button>
                        </div>
                    </div>
                `;
                questionList.appendChild(card);
                bindCard(card);
                card.querySelector('[data-role="type"]').value = defaultType;
                updateOptionsVisibility(card);
                if (window.lucide && typeof lucide.createIcons === 'function') {
                    lucide.createIcons();
                }
            }

            function bindCard(card) {
                const typeSel   = card.querySelector('[data-role="type"]');
                const optWrap   = card.querySelector('[data-role="options"]');
                const optList   = card.querySelector('[data-role="optList"]');
                const addOptBtn = card.querySelector('[data-role="addOption"]');
                const removeBtn = card.querySelector('[data-role="remove"]');
                const upBtn     = card.querySelector('[data-role="up"]');
                const downBtn   = card.querySelector('[data-role="down"]');

                typeSel.addEventListener('change', () => updateOptionsVisibility(card));
                addOptBtn.addEventListener('click', () => {
                    const row = document.createElement('div');
                    row.className = 'flex items-center gap-2';
                    row.innerHTML = `
                        <input type="text" class="option-input flex-1 rounded-md border-gray-300" placeholder="Item/Opção">
                        <div class="flex items-center gap-1">
                          <button type="button" data-role="opt-up" class="px-2 py-1 rounded bg-gray-100 hover:bg-gray-200" title="Mover para cima">▲</button>
                          <button type="button" data-role="opt-down" class="px-2 py-1 rounded bg-gray-100 hover:bg-gray-200" title="Mover para baixo">▼</button>
                          <button type="button" class="px-2 py-1 rounded bg-red-100 text-red-700 hover:bg-red-200">Remover</button>
                        </div>
                    `;
                    optList.appendChild(row);
                    const [btnUp, btnDown, btnRem] = row.querySelectorAll('button');
                    btnRem.addEventListener('click', () => row.remove());
                    btnUp.addEventListener('click', () => { const prev = row.previousElementSibling; if (prev) optList.insertBefore(row, prev); });
                    btnDown.addEventListener('click', () => { const next = row.nextElementSibling; if (next) optList.insertBefore(next.nextElementSibling, row); });
                });

                removeBtn.addEventListener('click', () => card.remove());
                upBtn.addEventListener('click', () => {
                    const prev = card.previousElementSibling;
                    if (prev) questionList.insertBefore(card, prev);
                });
                downBtn.addEventListener('click', () => {
                    const next = card.nextElementSibling;
                    if (next) questionList.insertBefore(next, card.nextElementSibling.nextElementSibling);
                });
            }

            function updateOptionsVisibility(card) {
                const typeSel = card.querySelector('[data-role="type"]');
                const optWrap = card.querySelector('[data-role="options"]');
                if (typeSel.value === 'multiple' || typeSel.value === 'prioridades') {
                    optWrap.classList.remove('hidden');
                } else {
                    optWrap.classList.add('hidden');
                }
            }

            const recipientTypeSel = document.getElementById('recipientType');
            const citySel = document.getElementById('targetCity');
            const ufSel   = document.getElementById('targetUF');
            const alcanceCountEl = document.getElementById('alcanceCount');
            const alcanceLoadingEl = document.getElementById('alcanceLoading');
        
            async function updateAlcance() {
              const tipo = recipientTypeSel.value;
              const cidade = citySel.value;
              const uf = (ufSel.value || '').toUpperCase();
        
              try {
                alcanceLoadingEl.classList.remove('hidden');
                const url = new URL(window.location.href);
                url.searchParams.set('calc', '1');
                url.searchParams.set('recipientType', tipo);
                url.searchParams.set('cidade', cidade);
                url.searchParams.set('uf', uf);
                const res = await fetch(url.toString(), { headers: { 'X-Requested-With': 'fetch' } });
                const data = await res.json();
                alcanceCountEl.textContent = (data && typeof data.count === 'number') ? data.count : '0';
              } catch (e) {
                alcanceCountEl.textContent = '0';
              } finally {
                alcanceLoadingEl.classList.add('hidden');
              }
            }
        
            recipientTypeSel.addEventListener('change', updateAlcance);
            citySel.addEventListener('change', updateAlcance);
            ufSel.addEventListener('change', updateAlcance);
            updateAlcance();
        
            const form = document.getElementById('surveyForm');
            form.addEventListener('submit', (e) => {
                const title = document.getElementById('surveyTitle').value.trim();
                const desc  = document.getElementById('surveyDesc').value.trim();

                if (!title || !desc) {
                    alert('Informe título e descrição.');
                    e.preventDefault();
                    return;
                }

                const questions = [];
                document.querySelectorAll('.question-card').forEach(card => {
                    const text = card.querySelector('[data-role="text"]').value.trim();
                    const type = card.querySelector('[data-role="type"]').value;
                    const required = card.querySelector('[data-role="required"]').checked;

                    if (!text) return;

                    const q = { text, type, required };
                    if (type === 'multiple') {
                        q.options = Array.from(card.querySelectorAll('.option-input'))
                            .map(i => i.value.trim())
                            .filter(Boolean);
                    }
                    questions.push(q);
                });

                if (questions.length === 0) {
                    alert('Adicione ao menos uma pergunta.');
                    e.preventDefault();
                    return;
                }

                // Substitui o multiselect por um select único de tipo
                const recipientType = document.getElementById('recipientType').value;
                const targetCity = document.getElementById('targetCity').value.trim();
                const targetUF   = document.getElementById('targetUF').value.trim().toUpperCase();

                const payload = { title, description: desc, questions, recipientType };
                if (targetCity) payload.targetCity = targetCity;
                if (targetUF)   payload.targetUF   = targetUF;

                document.getElementById('surveyJson').value = JSON.stringify(payload);
            });
        });
        </script>
    </body>
    </html>
