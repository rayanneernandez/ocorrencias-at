<?php
session_start();

require_once __DIR__ . '/../includes/db.php';
$pdo = get_pdo();

$flash_success = '';
$flash_error   = '';

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
                $outDir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'surveys';
                if (!is_dir($outDir)) { @mkdir($outDir, 0777, true); }
                $fname = 'survey_' . date('Ymd_His') . '_' . substr(sha1(uniqid('', true)), 0, 8) . '.json';
                $path  = $outDir . DIRECTORY_SEPARATOR . $fname;

                @file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                if (file_exists($path)) {
                    // Dados base
                    $sid = basename($fname, '.json');
                    $recipientType = $data['recipientType'] ?? 'todos';
                    $targetCity    = trim($data['targetCity'] ?? '');
                    $targetUF      = strtoupper(trim($data['targetUF'] ?? ''));

                    // Inserção resiliente na tabela "pesquisa"
                    try {
                        $colsStmt = $pdo->query("SHOW COLUMNS FROM pesquisa");
                        $cols = array_map(fn($r) => $r['Field'], $colsStmt->fetchAll());

                        // Mapeia possíveis colunas do seu modelo
                        $candidate = [
                            'sid'               => $sid,
                            'titulo'            => $data['title'] ?? 'Pesquisa',
                            'descricao'         => $data['description'] ?? '',
                            'tipo_destinatario' => $recipientType,
                            'cidade'            => $targetCity ?: null,
                            'uf'                => $targetUF ?: null,
                            'json'              => $json,
                        ];
                        $insertData = array_filter($candidate, fn($k) => in_array($k, $cols), ARRAY_FILTER_USE_KEY);

                        if (!empty($insertData)) {
                            $names = array_keys($insertData);
                            $place = implode(',', array_fill(0, count($names), '?'));
                            $sql   = "INSERT INTO pesquisa (" . implode(',', $names) . ") VALUES ($place)";
                            $stmt  = $pdo->prepare($sql);
                            $stmt->execute(array_values($insertData));
                        }
                    } catch (Throwable $e) {
                        // Não interrompe o fluxo de sucesso do arquivo; log opcional
                        // error_log('Falha ao inserir em pesquisa: ' . $e->getMessage());
                    }

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
                <a href="usuarios.php" class="hover:text-white">Usuários</a>
                <a href="relatorios.php" class="hover:text-white">Relatórios</a>
                <a href="criar_pesquisa.php" class="text-white">Criar Pesquisa</a>
                <a href="principal.php" class="hover:text-white">Sair</a>
            </nav>
        </div>
    </header>

    <main class="container mx-auto px-6 py-8 max-w-5xl">
        <a href="usuarios.php" class="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-gray-100 text-gray-700 hover:bg-gray-200 mb-6">
            <i data-lucide="arrow-left" class="w-4 h-4"></i>
            Voltar
        </a>

        <h1 class="text-3xl font-bold text-gray-900 mb-2">Criar Nova Pesquisa</h1>
        <p class="text-gray-600 mb-6">Crie pesquisas personalizadas e envie diretamente para o perfil dos clientes.</p>

        <?php if ($flash_success): ?>
            <div class="mb-6 bg-green-50 border border-green-200 text-green-800 rounded-lg p-4">
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

            <!-- Destinatários -->
            <section class="bg-gray-50 rounded-xl shadow p-6">
                <h2 class="text-xl font-semibold text-gray-900 mb-4">Destinatários</h2>
                <p class="text-gray-600 mb-2">Selecione o tipo de destinatário</p>
                <select id="recipientType" class="w-full rounded-md border-gray-300">
                    <option value="cidadaos">Cidadãos</option>
                    <option value="admin_publicos">Administradores Públicos</option>
                    <option value="secretarios">Secretários / Assessores</option>
                    <option value="todos" selected>Todos os perfis</option>
                </select>
            
                <!-- Alvo (opcional) por Cidade/UF -->
            
                <div class="grid sm:grid-cols-3 gap-3 mt-3">
                  <div class="sm:col-span-2">
                    <label class="block text-sm text-gray-700">Cidade (opcional)</label>
                    <input id="targetCity" type="text" class="mt-1 w-full rounded-md border-gray-300" placeholder="Ex.: Niterói" />
                  </div>
                  <div>
                    <label class="block text-sm text-gray-700">UF (opcional)</label>
                    <input id="targetUF" type="text" class="mt-1 w-full rounded-md border-gray-300 text-center uppercase" placeholder="RJ" maxlength="2" />
                  </div>
                </div>
            
                <p class="text-xs text-gray-500 mt-2">Dica: você pode integrar com seu banco de usuários.</p>
            </section>

            <div class="flex gap-4">
                <button type="submit" class="flex-1 bg-green-600 text-white px-6 py-3 rounded-md hover:bg-green-700 inline-flex items-center justify-center gap-2">
                    <i data-lucide="send" class="w-4 h-4"></i>
                    Criar e Enviar Pesquisa
                </button>
                <a href="usuarios.php" class="flex-1 text-center bg-gray-100 text-gray-700 px-6 py-3 rounded-md hover:bg-gray-200 inline-flex items-center justify-center gap-2">
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
                    <input type="text" class="option-input flex-1 rounded-md border-gray-300" placeholder="Opção">
                    <button type="button" class="px-2 py-1 rounded bg-red-100 text-red-700 hover:bg-red-200">Remover</button>
                `;
                optList.appendChild(row);
                row.querySelector('button').addEventListener('click', () => row.remove());
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
            if (typeSel.value === 'multiple') {
                optWrap.classList.remove('hidden');
            } else {
                optWrap.classList.add('hidden');
            }
        }

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