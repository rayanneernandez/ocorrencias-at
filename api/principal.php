<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: principal.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>RADCI - Radar de Avaliações</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script defer src="https://unpkg.com/lucide@latest"></script>

  <style>
    :root {
      --verde-principal: #065f46;
      --verde-claro: #34d399;
      --verde-hover: #047857;
    }

    body {
      margin: 0;
      padding: 0;
      min-height: 100vh;
      font-family: system-ui, -apple-system, sans-serif;
      background-color: #fff;
      color: #111;
    }

    /* Hero verde sólido */
    .gradient-hero {
      background-color: var(--verde-principal);
      color: #fff;
    }

    .gradient-card {
      background: linear-gradient(180deg, #ffffff 0%, #f3f4f6 100%);
    }

    .text-primary-light {
      color: var(--verde-claro) !important;
    }

    .text-primary, .bg-primary {
      color: var(--verde-principal) !important;
      background-color: var(--verde-principal) !important;
    }

    .animate-fade-in {
      animation: fadeIn 0.8s ease-in-out;
    }

    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(10px); }
      to { opacity: 1; transform: translateY(0); }
    }

    @keyframes spin {
      0% { transform: rotate(0deg); }
      100% { transform: rotate(360deg); }
    }

    #loader div:first-child {
      border: 3px solid #f3f3f3;
      border-top: 3px solid var(--verde-principal);
      border-radius: 50%;
      animation: spin 1s linear infinite;
    }

    /* Sobrescrever todos os verdes do Tailwind */
    .text-green-500, .text-green-600, .text-green-700 {
      color: var(--verde-principal) !important;
    }

    .bg-green-500, .bg-green-600, .bg-green-700 {
      background-color: var(--verde-principal) !important;
    }

    .border-green-500, .border-green-600, .border-green-700 {
      border-color: var(--verde-principal) !important;
    }

    .hover\:bg-green-500:hover, .hover\:bg-green-600:hover, .hover\:bg-green-700:hover {
      background-color: var(--verde-hover) !important;
    }
  </style>
</head>

<body class="min-h-screen flex flex-col">

  <!-- Loader -->
  <div id="loader" style="position: fixed; inset: 0; background: rgba(255,255,255,0.9); display: flex; justify-content: center; align-items: center; z-index: 9999;">
    <div style="text-align: center;">
      <div style="width: 50px; height: 50px;"></div>
      <p style="margin-top: 1rem; color: var(--verde-principal);">Carregando...</p>
    </div>
  </div>

  <script>
    window.addEventListener('load', () => {
      document.getElementById('loader').style.display = 'none';
    });
  </script>

  <!-- Hero -->
  <section class="relative gradient-hero overflow-hidden py-20 lg:py-32 text-white">
    <div class="container relative mx-auto px-4">
      <div class="grid lg:grid-cols-2 gap-12 items-center">
        
        <!-- Left -->
        <div class="space-y-8 animate-fade-in">
          <div class="flex items-center space-x-3 mb-6">
            <div class="bg-white/10 p-3 rounded-xl border border-white/30">
              <i data-lucide="map-pin" class="w-8 h-8 text-white"></i>
            </div>
            <div>
              <h1 class="text-4xl lg:text-5xl font-bold">RADCI</h1>
              <p class="text-white/90 text-sm lg:text-base font-medium mt-1">
                Radar de Avaliações dos Drivers de uma Cidade Mais Inteligente
              </p>
            </div>
          </div>

          <h2 class="text-3xl lg:text-5xl font-bold leading-tight">
            Transforme sua cidade com a força da 
            <span class="text-primary-light">comunidade</span>
          </h2>

          <p class="text-xl text-white/90 leading-relaxed">
            Relate problemas urbanos, sugira melhorias e acompanhe as mudanças em tempo real. 
            Juntos, construímos uma cidade mais inteligente e acessível para todos.
          </p>

          <div class="flex flex-col sm:flex-row gap-4">
            <a href="login_cadastro.php?tab=cadastro"
               class="text-lg inline-flex items-center justify-center bg-white text-[var(--verde-principal)] font-medium px-6 py-3 rounded-lg hover:bg-gray-100 transition">
              Registre-se
              <i data-lucide="arrow-right" class="ml-2 w-5 h-5"></i>
            </a>
            <a href="login_cadastro.php?tab=login"
               class="text-lg inline-flex items-center justify-center bg-transparent border border-white text-white font-medium px-6 py-3 rounded-lg hover:bg-white/10 transition">
              Fazer Login
            </a>
          </div>
        </div>

        <!-- Right -->
        <div class="hidden lg:block">
          <div class="relative">
            <div class="absolute inset-0 rounded-3xl opacity-20" style="background-color: var(--verde-principal);"></div>
            <div class="relative bg-white/10 rounded-3xl p-8 border border-white/20">
              <div class="grid grid-cols-2 gap-4">
                <!-- Card de Ocorrência -->
                <div class="bg-white/20 rounded-xl p-4">
                  <div class="flex items-center gap-2 mb-2">
                    <i data-lucide="alert-triangle" class="w-5 h-5 text-yellow-400"></i>
                    <span class="text-sm text-white">Nova Ocorrência</span>
                  </div>
                  <div class="h-16 bg-white/30 rounded-lg mb-2 flex items-center justify-center">
                    <i data-lucide="camera" class="w-8 h-8 text-white/60"></i>
                  </div>
                  <div class="flex items-center gap-2">
                    <i data-lucide="map-pin" class="w-4 h-4 text-white/60"></i>
                    <div class="h-3 bg-white/40 rounded w-3/4"></div>
                  </div>
                </div>

                <!-- Card de Status -->
                <div class="bg-white/20 rounded-xl p-4">
                  <div class="flex items-center gap-2 mb-2">
                    <i data-lucide="check-circle" class="w-5 h-5 text-green-400"></i>
                    <span class="text-sm text-white">Em Andamento</span>
                  </div>
                  <div class="h-16 bg-white/30 rounded-lg mb-2 flex items-center justify-center">
                    <i data-lucide="trending-up" class="w-8 h-8 text-white/60"></i>
                  </div>
                  <div class="flex items-center gap-2">
                    <i data-lucide="clock" class="w-4 h-4 text-white/60"></i>
                    <div class="h-3 bg-white/40 rounded w-3/4"></div>
                  </div>
                </div>

                <!-- Card de Comunidade -->
                <div class="bg-white/20 rounded-xl p-4">
                  <div class="flex items-center gap-2 mb-2">
                    <i data-lucide="users" class="w-5 h-5 text-blue-400"></i>
                    <span class="text-sm text-white">Comunidade</span>
                  </div>
                  <div class="h-16 bg-white/30 rounded-lg mb-2 flex items-center justify-center">
                    <i data-lucide="message-circle" class="w-8 h-8 text-white/60"></i>
                  </div>
                  <div class="flex items-center gap-2">
                    <i data-lucide="heart" class="w-4 h-4 text-white/60"></i>
                    <div class="h-3 bg-white/40 rounded w-3/4"></div>
                  </div>
                </div>

                <!-- Card de Feedback -->
                <div class="bg-white/20 rounded-xl p-4">
                  <div class="flex items-center gap-2 mb-2">
                    <i data-lucide="star" class="w-5 h-5 text-yellow-400"></i>
                    <span class="text-sm text-white">Feedback</span>
                  </div>
                  <div class="h-16 bg-white/30 rounded-lg mb-2 flex items-center justify-center">
                    <i data-lucide="thumbs-up" class="w-8 h-8 text-white/60"></i>
                  </div>
                  <div class="flex items-center gap-2">
                    <i data-lucide="bar-chart" class="w-4 h-4 text-white/60"></i>
                    <div class="h-3 bg-white/40 rounded w-3/4"></div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

      </div>
    </div>
  </section>

  <!-- Features -->
  <section class="py-20 bg-gray-50">
    <div class="container mx-auto px-4">
      <div class="text-center mb-16 animate-fade-in">
        <h2 class="text-3xl lg:text-4xl font-bold text-gray-900 mb-4">
          Como o RADCI funciona
        </h2>
        <p class="text-xl text-gray-600 max-w-3xl mx-auto">
          Uma plataforma completa para conectar cidadãos e gestores públicos, 
          facilitando a comunicação e acelerando soluções urbanas.
        </p>
      </div>

      <div class="grid md:grid-cols-2 lg:grid-cols-4 gap-8">
        <?php
          $features = [
            ["icon" => "camera", "title" => "Relato Visual", "desc" => "Adicione fotos e vídeos para documentar problemas urbanos de forma clara e objetiva"],
            ["icon" => "map-pin", "title" => "Localização Precisa", "desc" => "Sistema de geolocalização para identificar exatamente onde está o problema"],
            ["icon" => "trending-up", "title" => "Acompanhamento", "desc" => "Monitore o progresso das suas ocorrências em tempo real"],
            ["icon" => "users", "title" => "Comunidade Ativa", "desc" => "Junte-se a milhares de cidadãos engajados em melhorar a cidade"]
          ];
          foreach ($features as $feature):
        ?>
          <div class="gradient-card rounded-2xl p-6 shadow-lg hover:shadow-xl transition-all duration-300 hover:-translate-y-1 border border-gray-200 animate-fade-in">
            <div class="w-12 h-12 rounded-xl flex items-center justify-center mb-4" style="background-color: #e6f4ef;">
              <i data-lucide="<?= htmlspecialchars($feature['icon']) ?>" class="w-6 h-6" style="color: var(--verde-principal);"></i>
            </div>
            <h3 class="text-xl font-semibold text-gray-900 mb-3">
              <?= htmlspecialchars($feature['title']) ?>
            </h3>
            <p class="text-gray-600 leading-relaxed">
              <?= htmlspecialchars($feature['desc']) ?>
            </p>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <!-- CTA -->
  <section class="py-20 gradient-hero text-center text-white">
    <div class="container mx-auto px-4">
      <div class="max-w-3xl mx-auto space-y-8 animate-fade-in">
        <h2 class="text-3xl lg:text-4xl font-bold">
          Pronto para transformar sua cidade?
        </h2>
        <p class="text-xl text-white/90">
          Junte-se a milhares de cidadãos que já estão fazendo a diferença. 
          Cadastre-se gratuitamente e comece hoje mesmo!
        </p>
        <a href="login_cadastro.php?tab=cadastro"
           class="text-lg inline-flex items-center justify-center bg-white text-[var(--verde-principal)] font-medium px-6 py-3 rounded-lg hover:bg-gray-100 transition">
          Registre-se
          <i data-lucide="arrow-right" class="ml-2 w-5 h-5"></i>
        </a>
      </div>
    </div>
  </section>

  <!-- Footer -->
  <footer class="bg-white border-t border-gray-200 py-12">
    <div class="container mx-auto px-4">
      <div class="flex flex-col items-center space-y-6">
        <div class="flex items-center space-x-3">
          <div class="p-2 rounded-lg" style="background-color: var(--verde-principal);">
            <i data-lucide="map-pin" class="w-6 h-6 text-white"></i>
          </div>
          <div>
            <div class="text-xl font-bold text-gray-900">RADCI</div>
            <div class="text-sm text-gray-500">
              Construindo cidades mais inteligentes
            </div>
          </div>
        </div>
        <div class="text-gray-500 text-sm">
          © 2025 RADCI. Todos os direitos reservados.
        </div>
      </div>
    </div>
  </footer>

  <script>
    document.addEventListener('DOMContentLoaded', () => {
      if (window.lucide && typeof lucide.createIcons === 'function') {
        lucide.createIcons();
      }
    });
  </script>
</body>
</html>
