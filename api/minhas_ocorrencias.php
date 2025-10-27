<?php
session_start();

require_once __DIR__ . '/../includes/db.php';

// Busca as ocorrências do usuário
$reports = [];
if (isset($_SESSION['usuario_id'])) {
    try {
        $pdo = get_pdo();
        $stmt = $pdo->prepare("
            SELECT 
                o.id,
                o.numero,
                o.tipo as category,
                o.descricao,
                o.endereco,
                o.status,
                o.tem_imagens,
                o.arquivos,
                DATE_FORMAT(o.data_criacao, '%d/%m/%Y') as data,
                p.nivel_prioridade
            FROM ocorrencias o
            LEFT JOIN prioridades p ON p.id_ocorrencia = o.id
            WHERE o.usuario_id = ?
            ORDER BY o.data_criacao DESC
        ");
        $stmt->execute([$_SESSION['usuario_id']]);
        $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Erro ao buscar ocorrências: " . $e->getMessage());
    }
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Minhas Ocorrências - RADCI</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://unpkg.com/lucide@latest"></script>
</head>
<body class="pb-28">

    <!-- Header -->
    <header class="bg-white border-b border-gray-200">
        <div class="container mx-auto px-4 py-4">
            <a href="dashboard.php" class="inline-flex items-center text-green-600 font-medium hover:text-green-800">
                <i data-lucide="arrow-left" class="w-4 h-4 mr-2"></i>
                Voltar
            </a>
        </div>
    </header>

    <!-- Conteúdo -->
    <div class="container mx-auto px-4 py-8">
        <?php if(isset($_SESSION['flash_success'])): ?>
            <div class="mb-6 bg-green-50 border border-green-200 text-green-800 rounded-lg p-4">
                <?= htmlspecialchars($_SESSION['flash_success']) ?>
            </div>
            <?php unset($_SESSION['flash_success']); endif; ?>
        <div class="mb-8">
            <h1 class="text-3xl font-bold text-gray-900 mb-2">Minhas Ocorrências</h1>
            <p class="text-gray-600">Acompanhe todas as suas manifestações registradas</p>
        </div>

        <?php if(empty($reports)): ?>
            <div class="bg-white rounded-xl shadow-lg p-12 text-center">
                <div class="bg-gray-200 rounded-full w-20 h-20 flex items-center justify-center mx-auto mb-4">
                    <i data-lucide="map-pin" class="w-10 h-10 text-gray-400"></i>
                </div>
                <h3 class="text-xl font-semibold text-gray-900 mb-2">Nenhuma ocorrência registrada</h3>
                <p class="text-gray-500 mb-6">
                    Você ainda não registrou nenhuma ocorrência. Comece agora e ajude a melhorar sua cidade!
                </p>
                <a href="registrar_ocorrencia.php" class="bg-green-500 text-white px-6 py-3 rounded-md hover:bg-green-600 transition">Registrar Primeira Ocorrência</a>
            </div>
        <?php else: ?>
            <div class="space-y-4">
                <?php foreach($reports as $report): ?>
                    <div class="bg-white rounded-xl shadow p-4 hover:shadow-lg transition">
                        <div class="flex items-start justify-between mb-3">
                            <h3 class="text-lg font-semibold text-gray-900"><?= $report['category'] ?></h3>
                            <span class="px-2 py-1 rounded text-xs <?= $report['status'] === 'pending' ? 'bg-gray-200 text-gray-800' : 'bg-green-100 text-green-800' ?>">
                                <?= $report['status'] === 'pending' ? 'Pendente' : 'Em andamento' ?>
                            </span>
                        </div>
                        <p class="text-sm text-gray-600 mb-2"><?= $report['description'] ?></p>
                        <div class="flex flex-wrap gap-4 text-xs text-gray-500">
                            <div class="flex items-center">
                                <i data-lucide="map-pin" class="w-3 h-3 mr-1"></i>
                                <?= $report['address'] ?>
                            </div>
                            <div class="flex items-center">
                                <i data-lucide="calendar" class="w-3 h-3 mr-1"></i>
                                <?= $report['date'] ?>
                            </div>
                            <?php if(!empty($report['hasMedia'])): ?>
                                <div class="flex items-center">
                                    <i data-lucide="image" class="w-3 h-3 mr-1"></i>
                                    Com mídia
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            if (window.lucide && typeof lucide.createIcons === 'function') {
                lucide.createIcons();
            }
        });
    </script>
    <?php include __DIR__ . '/../includes/mobile_nav.php'; ?>
</body>
</html>
