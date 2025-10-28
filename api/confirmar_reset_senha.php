<?php
session_start();
require_once __DIR__ . '/../includes/db.php';
$pdo = get_pdo();

$token = trim($_GET['token'] ?? '');
$erro = '';
$sucesso = '';
$valid = null;
$userId = null;

if ($token) {
    $stmt = $pdo->prepare("SELECT id, usuario_id, token, expires_at, used_at FROM usuarios_reset_senha WHERE token = ? LIMIT 1");
    $stmt->execute([$token]);
    $valid = $stmt->fetch();

    if (!$valid) {
        $erro = 'Token inválido.';
    } else {
        if (!empty($valid['used_at'])) {
            $erro = 'Este link já foi utilizado.';
        } elseif (strtotime($valid['expires_at']) < time()) {
            $erro = 'Este link expirou. Solicite novamente.';
        } else {
            $userId = (int)$valid['usuario_id'];
        }
    }
} else {
    $erro = 'Token não informado.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $userId && !$erro) {
    $senha = trim($_POST['senha'] ?? '');
    $confirm = trim($_POST['confirm'] ?? '');

    if (strlen($senha) < 6) {
        $erro = 'A senha deve ter pelo menos 6 caracteres.';
    } elseif ($senha !== $confirm) {
        $erro = 'As senhas não coincidem.';
    } else {
        $hash = password_hash($senha, PASSWORD_DEFAULT);

        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE usuarios SET senha = ? WHERE id = ?")->execute([$hash, $userId]);
            $pdo->prepare("UPDATE usuarios_reset_senha SET used_at = NOW() WHERE id = ?")->execute([(int)$valid['id']]);
            $pdo->commit();
            $sucesso = 'Senha redefinida com sucesso! Você já pode entrar.';
        } catch (Throwable $e) {
            $pdo->rollBack();
            $erro = 'Falha ao redefinir a senha. Tente novamente.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="UTF-8">
  <title>RADCI - Confirmar Reset de Senha</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://unpkg.com/lucide@latest"></script>
  <style>
    <?php 
    $cssPath = __DIR__ . '/../assets/css/style.css';
    if (file_exists($cssPath)) {
        echo file_get_contents($cssPath);
    }
    ?>
    .hide-scrollbar::-webkit-scrollbar { display: none; }
    .hide-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }

    .password-toggle {
      position: absolute;
      right: 1rem;
      top: 50%;
      transform: translateY(-50%);
      color: #9CA3AF;
      padding: 0.5rem;
      border-radius: 0.375rem;
      transition: color 0.2s;
    }

    .password-toggle:hover {
      color: #4B5563;
    }
  </style>
</head>
<body class="min-h-screen flex flex-col items-center justify-center bg-white text-gray-900">
  <div class="w-full max-w-md">
    <!-- Logo -->
    <div class="flex items-center justify-center mb-4 space-x-3">
      <div class="bg-green-600 p-2 rounded-xl">
        <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 11c0 3.866-3.582 7-8 7h16c-4.418 0-8-3.134-8-7z"/>
        </svg>
      </div>
      <div>
        <h1 class="text-xl font-bold">RADCI</h1>
        <p class="text-gray-500 text-sm">Cidade Mais Inteligente</p>
      </div>
    </div>

    <div class="bg-gray-50 rounded-xl shadow-lg p-6 max-h-[75vh] overflow-y-auto hide-scrollbar relative">
      <h2 class="text-lg font-semibold mb-4">Definir nova senha</h2>

      <?php if ($erro): ?>
        <p class="text-red-600 mb-3"><?= htmlspecialchars($erro) ?></p>
      <?php endif; ?>

      <?php if ($sucesso): ?>
        <p class="text-green-600 mb-3"><?= htmlspecialchars($sucesso) ?></p>
        <div class="mt-4 text-sm text-gray-600">
          <a href="login_cadastro.php" class="text-green-600 hover:underline">Ir para login</a>
        </div>
      <?php endif; ?>

      <?php if (!$sucesso && !$erro && $userId): ?>
        <form method="POST" class="space-y-4">
          <div>
            <label class="text-sm mb-1 block">Nova senha</label>
            <div class="relative">
              <input type="password" id="pwd1" name="senha" required class="w-full p-3 rounded-md bg-white border border-gray-300 focus:border-green-600 focus:ring-1 focus:ring-green-600 pr-12">
              <button type="button" onclick="togglePassword('pwd1')" class="password-toggle">
                <i data-lucide="eye" class="w-5 h-5"></i>
              </button>
            </div>
          </div>
          <div>
            <label class="text-sm mb-1 block">Confirmar senha</label>
            <div class="relative">
              <input type="password" id="pwd2" name="confirm" required class="w-full p-3 rounded-md bg-white border border-gray-300 focus:border-green-600 focus:ring-1 focus:ring-green-600 pr-12">
              <button type="button" onclick="togglePassword('pwd2')" class="password-toggle">
                <i data-lucide="eye" class="w-5 h-5"></i>
              </button>
            </div>
          </div>
          <button type="submit" class="w-full bg-green-600 text-white py-3 rounded-md font-semibold">Redefinir senha</button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <script>
    lucide.createIcons();

    function togglePassword(inputId) {
      const input = document.getElementById(inputId);
      const button = input.nextElementSibling;
      const icon = button.querySelector('i');
      
      if (input.type === 'password') {
        input.type = 'text';
        icon.setAttribute('data-lucide', 'eye-off');
      } else {
        input.type = 'password';
        icon.setAttribute('data-lucide', 'eye');
      }
      lucide.createIcons();
    }
  </script>
</body>
</html>
