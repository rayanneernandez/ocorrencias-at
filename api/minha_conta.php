<?php
session_start();
require_once __DIR__ . '/../includes/db.php';
$pdo = get_pdo();

if (!isset($_SESSION['usuario_id'])) {
  header("Location: login_cadastro.php");
  exit;
}

$usuarioId = intval($_SESSION['usuario_id'] ?? 0);
$flash = '';

// Logout: destruir sessão e ir para tela principal
if (isset($_POST['logout'])) {
  session_unset();
  session_destroy();
  header("Location: principal.php");
  exit;
}

// Carrega dados do usuário (nome, email, endereço/cidade)
$stmt = $pdo->prepare("SELECT * FROM usuarios WHERE id = ?");
$stmt->execute([$usuarioId]);
$u = $stmt->fetch() ?: [];

$usuario = [
  "nome"        => $u['nome'] ?? ($_SESSION['usuario_nome'] ?? 'Usuário RADCI'),
  "email"       => $u['email'] ?? '',
  "cep"         => $u['cep'] ?? '',
  "uf"          => $u['uf'] ?? '',
  "municipio"   => $u['municipio'] ?? '',
  "bairro"      => $u['bairro'] ?? '',
  "rua"         => $u['rua'] ?? '',
  "complemento" => $u['complemento'] ?? '',
  "membro_desde"=> isset($u['created_at']) ? date('Y', strtotime($u['created_at'])) : date('Y'),
];

// Salvar alterações de perfil (exceto senha)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
  $nome  = trim($_POST['nome'] ?? '');
  $email = trim($_POST['email'] ?? '');
  $cep   = trim($_POST['cep'] ?? '');
  $uf    = strtoupper(trim($_POST['uf'] ?? ''));
  $cidadeInput = trim($_POST['cidade'] ?? '');
  // cidade pode vir como "Municipio - UF" ou apenas municipio
  $municipio = $cidadeInput;
  if (strpos($cidadeInput, ' - ') !== false) {
    [$municipioPart, $ufPart] = explode(' - ', $cidadeInput, 2);
    $municipio = trim($municipioPart);
    $uf = strtoupper(trim($ufPart));
  }
  $bairro      = trim($_POST['bairro'] ?? '');
  $rua         = trim($_POST['rua'] ?? '');
  $complemento = trim($_POST['complemento'] ?? '');

  $sql = "UPDATE usuarios
             SET nome = ?, email = ?, cep = ?, uf = ?, municipio = ?, bairro = ?, rua = ?, complemento = ?
           WHERE id = ?";
  $pdo->prepare($sql)->execute([
    $nome, $email, $cep, $uf, $municipio, $bairro, $rua, $complemento, $usuarioId
  ]);
  $flash = 'Dados atualizados com sucesso.';
  // Atualiza objeto para refletir mudanças
  $usuario['nome']        = $nome;
  $usuario['email']       = $email;
  $usuario['cep']         = $cep;
  $usuario['uf']          = $uf;
  $usuario['municipio']   = $municipio;
  $usuario['bairro']      = $bairro;
  $usuario['rua']         = $rua;
  $usuario['complemento'] = $complemento;
}

// Carrega preferências de notificações (cria tabela se não existir)
$pdo->exec("
  CREATE TABLE IF NOT EXISTS usuarios_preferencias (
    usuario_id INT PRIMARY KEY,
    notif_ocorrencias TINYINT(1) NOT NULL DEFAULT 1,
    notif_novidades  TINYINT(1) NOT NULL DEFAULT 1,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
  )
");
$prefStmt = $pdo->prepare("SELECT * FROM usuarios_preferencias WHERE usuario_id = ?");
$prefStmt->execute([$usuarioId]);
$prefs = $prefStmt->fetch() ?: ['notif_ocorrencias' => 1, 'notif_novidades' => 1];
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Minha Conta | RADCI</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script defer src="https://unpkg.com/lucide@latest"></script>
  <style>
    :root {
      --background: 0 0% 100%;
      --foreground: 222.2 47.4% 11.2%;
      --muted: 210 40% 96%;
      --muted-foreground: 215 20.2% 65.1%;
      --card: 0 0% 100%;
      --border: 214.3 31.8% 91.4%;
      --input: 214.3 31.8% 91.4%;
      --primary: 142 71% 45%;
      --primary-light: 142 76% 55%;
      --primary-foreground: 0 0% 100%;
      --destructive: 0 84.2% 60.2%;
      --destructive-foreground: 0 0% 100%;
    }
  </style>
</head>
<body class="min-h-screen bg-white pb-28 md:pb-8 text-[hsl(var(--foreground))]">

  <!-- =========================
       Cabeçalho Principal
  ========================== -->
  <header class="bg-[hsl(var(--card))] border-b border-[hsl(var(--border))] sticky top-0 z-10 shadow-sm">
    <div class="container mx-auto px-4 py-4 flex items-center justify-between">
      <div class="flex items-center space-x-3">
        <div class="bg-[hsl(var(--primary))] p-2 rounded-lg">
          <i data-lucide="map-pin" class="w-6 h-6 text-[hsl(var(--primary-foreground))]"></i>
        </div>
        <div>
          <h1 class="text-xl font-bold text-[hsl(var(--foreground))]">RADCI</h1>
          <p class="text-xs text-[hsl(var(--muted-foreground))]">Minha Conta</p>
        </div>
      </div>
      <!-- Botão Voltar (Desktop) -->
      <a href="dashboard.php" class="hidden md:inline-flex items-center px-3 py-2 rounded text-sm text-[hsl(var(--foreground))] hover:bg-[hsl(var(--muted))] transition">
        <i data-lucide="arrow-left" class="w-4 h-4 mr-2"></i>
        Voltar
      </a>
      <!-- Botão Sair (Desktop) -->
      <form method="POST" class="hidden md:flex">
        <button type="submit" name="logout" class="flex items-center px-3 py-2 rounded text-sm text-[hsl(var(--foreground))] hover:bg-[hsl(var(--muted))] transition">
          <i data-lucide="log-out" class="w-4 h-4 mr-2"></i>
          Sair
        </button>
      </form>
    </div>
  </header>

  <!-- =========================
       Conteúdo Principal
  ========================== -->
  <main class="container mx-auto px-4 py-8 max-w-2xl">
    <?php if (!empty($flash)): ?>
      <div class="mb-4 bg-green-50 border border-green-200 text-green-800 rounded-lg p-3">
        <?= htmlspecialchars($flash) ?>
      </div>
    <?php endif; ?>

    <!-- Voltar ao Dashboard (Mobile) -->
    <div class="md:hidden mb-4">
      <button onclick="location.href='dashboard.php'" class="w-full bg-green-600 text-white py-2 rounded-md flex items-center justify-center">
        <i data-lucide="arrow-left" class="w-4 h-4 mr-2"></i>
        Voltar ao Dashboard
      </button>
    </div>

    <!-- Cabeçalho do Perfil -->
    <div class="flex items-center space-x-4 mb-8">
      <div class="w-20 h-20 rounded-full bg-[hsl(var(--primary))] flex items-center justify-center text-[hsl(var(--primary-foreground))]">
        <i data-lucide="user" class="w-10 h-10"></i>
      </div>
      <div>
        <h2 class="text-2xl font-bold text-[hsl(var(--foreground))]">Olá, <?= htmlspecialchars($usuario["nome"]); ?></h2>
        <p class="text-[hsl(var(--muted-foreground))]">Membro desde <?= htmlspecialchars($usuario["membro_desde"]); ?></p>
      </div>
    </div>

    <!-- Informações da Conta -->
    <section class="bg-[hsl(var(--card))] rounded-2xl shadow-md mb-6 p-6 space-y-4 border border-[hsl(var(--border))]">
      <h3 class="text-lg font-semibold">Informações da Conta</h3>
      <p class="text-sm text-[hsl(var(--muted-foreground))] mb-4">Gerencie seus dados pessoais</p>

      <form method="POST" action="">
        <input type="hidden" name="update_profile" value="1" />
        <div class="space-y-2">
          <label for="nome" class="block text-sm font-medium">Nome Completo</label>
          <input type="text" id="nome" name="nome" value="<?= htmlspecialchars($usuario["nome"]); ?>" class="w-full border border-[hsl(var(--input))] rounded p-2 bg-[hsl(var(--background))]">
        </div>

        <div class="space-y-2">
          <label for="email" class="block text-sm font-medium">E-mail</label>
          <div class="flex items-center gap-2">
            <i data-lucide="mail" class="w-4 h-4 text-[hsl(var(--muted-foreground))]"></i>
            <input type="email" id="email" name="email" value="<?= htmlspecialchars($usuario["email"]); ?>" class="w-full border border-[hsl(var(--input))] rounded p-2 bg-[hsl(var(--background))]">
          </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div class="space-y-2">
            <label for="cep" class="block text-sm font-medium">CEP</label>
            <input type="text" id="cep" name="cep" value="<?= htmlspecialchars($usuario["cep"]); ?>" class="w-full border border-[hsl(var(--input))] rounded p-2 bg-[hsl(var(--background))]">
          </div>
          <div class="space-y-2">
            <label for="cidade" class="block text-sm font-medium">Cidade</label>
            <input type="text" id="cidade" name="cidade" value="<?= htmlspecialchars(($usuario["municipio"] ?: '') . ($usuario["uf"] ? ' - ' . $usuario["uf"] : '')); ?>" class="w-full border border-[hsl(var(--input))] rounded p-2 bg-[hsl(var(--background))]">
          </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div class="space-y-2">
            <label for="bairro" class="block text-sm font-medium">Bairro</label>
            <input type="text" id="bairro" name="bairro" value="<?= htmlspecialchars($usuario["bairro"]); ?>" class="w-full border border-[hsl(var(--input))] rounded p-2 bg-[hsl(var(--background))]">
          </div>
          <div class="space-y-2">
            <label for="rua" class="block text-sm font-medium">Rua</label>
            <input type="text" id="rua" name="rua" value="<?= htmlspecialchars($usuario["rua"]); ?>" class="w-full border border-[hsl(var(--input))] rounded p-2 bg-[hsl(var(--background))]">
          </div>
        </div>

        <div class="space-y-2">
          <label for="complemento" class="block text-sm font-medium">Complemento</label>
          <input type="text" id="complemento" name="complemento" value="<?= htmlspecialchars($usuario["complemento"]); ?>" class="w-full border border-[hsl(var(--input))] rounded p-2 bg-[hsl(var(--background))]">
        </div>

        <button type="submit" class="w-full mt-4 bg-[hsl(var(--primary))] text-[hsl(var(--primary-foreground))] py-2 rounded-lg hover:bg-[hsl(var(--primary-light))] transition">
          Salvar Alterações
        </button>
      </form>
    </section>

    <!-- Notificações -->
    <section class="bg-[hsl(var(--card))] rounded-2xl shadow-md mb-6 p-6 border border-[hsl(var(--border))] space-y-4">
      <h3 class="text-lg font-semibold">Notificações</h3>
      <p class="text-sm text-[hsl(var(--muted-foreground))] mb-4">Configure suas preferências de notificação</p>

      <div class="flex items-center justify-between">
        <div class="flex items-center gap-2">
          <i data-lucide="bell" class="w-4 h-4 text-[hsl(var(--muted-foreground))]"></i>
          <span class="text-sm">Atualizações de ocorrências</span>
        </div>
        <input type="checkbox" id="notif_ocorrencias" <?= intval($prefs['notif_ocorrencias']) ? 'checked' : '' ?> class="w-4 h-4 accent-[hsl(var(--primary))]">
      </div>

      <div class="flex items-center justify-between">
        <div class="flex items-center gap-2">
          <i data-lucide="bell" class="w-4 h-4 text-[hsl(var(--muted-foreground))]"></i>
          <span class="text-sm">Novidades da plataforma</span>
        </div>
        <input type="checkbox" id="notif_novidades" <?= intval($prefs['notif_novidades']) ? 'checked' : '' ?> class="w-4 h-4 accent-[hsl(var(--primary))]">
      </div>

      <div id="notif_status" class="text-xs text-[hsl(var(--muted-foreground))]"></div>
    </section>

    <!-- Alterar Senha (via confirmação por e-mail) -->
    <section class="bg-[hsl(var(--card))] rounded-2xl shadow-md mb-6 p-6 border border-[hsl(var(--border))] space-y-4">
      <h3 class="text-lg font-semibold">Segurança</h3>
      <p class="text-sm text-[hsl(var(--muted-foreground))]">Para alterar sua senha, enviaremos um link de confirmação por e-mail.</p>
      <form method="POST" action="solicitar_reset_senha.php" class="flex flex-col sm:flex-row gap-3">
        <input type="hidden" name="usuario_id" value="<?= $usuarioId ?>">
        <button type="submit" class="inline-flex items-center px-4 py-2 rounded-lg bg-green-600 text-white hover:bg-green-700">
          <i data-lucide="shield-check" class="w-4 h-4 mr-2"></i>
          Enviar confirmação por e-mail
        </button>
      </form>
    </section>

    <!-- Botão Sair (Mobile) -->
    <form method="POST" class="md:hidden">
      <button type="submit" name="logout" class="w-full bg-[hsl(var(--destructive))] text-[hsl(var(--destructive-foreground))] py-2 rounded-lg hover:opacity-90 transition flex items-center justify-center">
        <i data-lucide="log-out" class="w-4 h-4 mr-2"></i>
        Sair da Conta
      </button>
    </form>
  </main>

  <!-- Navegação Mobile -->
  <footer class="fixed bottom-0 left-0 w-full bg-[hsl(var(--card))] border-t border-[hsl(var(--border))] md:hidden">
    <nav class="flex justify-around py-2">
      <?php include __DIR__ . '/../includes/mobile_nav.php'; ?>
    </nav>
  </footer>
  <script>
    document.addEventListener('DOMContentLoaded', function() {
      if (window.lucide && typeof lucide.createIcons === 'function') {
        lucide.createIcons();
      }
      // Salva preferências de notificações via AJAX
      const notifOc = document.getElementById('notif_ocorrencias');
      const notifNv = document.getElementById('notif_novidades');
      const statusEl = document.getElementById('notif_status');

      function savePrefs() {
        const formData = new FormData();
        formData.append('notif_ocorrencias', notifOc.checked ? '1' : '0');
        formData.append('notif_novidades',  notifNv.checked ? '1' : '0');

        fetch('salvar_preferencias.php', { method: 'POST', body: formData })
          .then(r => r.json())
          .then(j => {
            statusEl.textContent = j.ok ? 'Preferências salvas.' : ('Falha ao salvar: ' + (j.error || ''));
          })
          .catch(err => { statusEl.textContent = 'Erro de rede.'; });
      }
      notifOc && notifOc.addEventListener('change', savePrefs);
      notifNv && notifNv.addEventListener('change', savePrefs);
    });
  </script>
</body>
</html>
