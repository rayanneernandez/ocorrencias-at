<?php
session_start();

// Downloads CSV simples (demo)
if (isset($_GET['download'])) {
    $type = $_GET['download'];
    if ($type === 'pesquisas') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=pesquisas.csv');
        echo "Titulo;Descricao;CriadoEm\n";
        echo "Pesquisa de Satisfação do Transporte;Avaliação dos serviços;".date('Y-m-d H:i')."\n";
        echo "Opinião sobre Iluminação Pública;Levantamento por bairro;".date('Y-m-d H:i')."\n";
        exit;
    }
    if ($type === 'prioridades') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=prioridades.csv');
        echo "Categoria;Prioridade;CriadoEm\n";
        echo "Mobilidade;Alta;".date('Y-m-d H:i')."\n";
        echo "Segurança Pública;Média;".date('Y-m-d H:i')."\n";
        exit;
    }
}

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8" />
    <title>Relatórios - RADCI</title>
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
                <a href="usuarios.php" class="hover:text-white">Usuários</a>
                <a href="relatorios.php" class="text-white">Relatórios</a>
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

        <h1 class="text-3xl font-bold text-gray-900 mb-2">Relatórios</h1>

        <div class="grid md:grid-cols-2 gap-6 mt-6">
            <!-- Card: Pesquisas -->
            <div class="border rounded-xl p-8 text-center hover:shadow transition">
                <div class="inline-flex items-center justify-center w-16 h-16 bg-green-50 text-green-600 rounded-xl mb-4">
                    <i data-lucide="file-text" class="w-8 h-8"></i>
                </div>
                <h3 class="text-lg font-semibold text-gray-900 mb-2">PESQUISAS</h3>
                <p class="text-gray-600 mb-4">Baixe o CSV das pesquisas criadas</p>
                <a href="?download=pesquisas" class="inline-flex items-center gap-2 px-5 py-2 rounded-md bg-green-600 text-white hover:bg-green-700">
                    <i data-lucide="download" class="w-4 h-4"></i>
                    CSV
                </a>
            </div>

            <!-- Card: Prioridades -->
            <div class="border rounded-xl p-8 text-center hover:shadow transition">
                <div class="inline-flex items-center justify-center w-16 h-16 bg-green-50 text-green-600 rounded-xl mb-4">
                    <i data-lucide="bar-chart-3" class="w-8 h-8"></i>
                </div>
                <h3 class="text-lg font-semibold text-gray-900 mb-2">PRIORIDADES</h3>
                <p class="text-gray-600 mb-4">Baixe o CSV das prioridades recebidas</p>
                <a href="?download=prioridades" class="inline-flex items-center gap-2 px-5 py-2 rounded-md bg-green-600 text-white hover:bg-green-700">
                    <i data-lucide="download" class="w-4 h-4"></i>
                    CSV
                </a>
            </div>
        </div>
    </main>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        if (window.lucide && typeof lucide.createIcons === 'function') {
            lucide.createIcons();
        }
    });
    </script>
</body>
</html>