<?php
session_start();
require_once __DIR__ . '/../includes/db.php';
$pdo = get_pdo();

$mensagem = '';
$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $erro = 'Informe um e-mail válido.';
    } else {
        // Tenta localizar o usuário pelo e-mail
        $stmt = $pdo->prepare("SELECT id, nome, email FROM usuarios WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        // Sempre responde genericamente para não expor existência de e-mails
        $mensagem = 'Se o e-mail informado estiver cadastrado, enviaremos um link para redefinir sua senha.';

        if ($user && !empty($user['email'])) {
            // Cria tabela de reset se não existir
            $pdo->exec("
              CREATE TABLE IF NOT EXISTS usuarios_reset_senha (
                id INT AUTO_INCREMENT PRIMARY KEY,
                usuario_id INT NOT NULL,
                token VARCHAR(128) NOT NULL,
                expires_at DATETIME NOT NULL,
                used_at DATETIME NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX (usuario_id),
                UNIQUE KEY uniq_token (token)
              )
            ");

            // Gera token e salva (expira em 30 min)
            $token = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', time() + 60 * 30);

            $pdo->prepare("
              INSERT INTO usuarios_reset_senha (usuario_id, token, expires_at)
              VALUES (?, ?, ?)
            ")->execute([(int)$user['id'], $token, $expires]);

            // Monta link de confirmação
            $base = (isset($_SERVER['REQUEST_SCHEME']) ? $_SERVER['REQUEST_SCHEME'] : 'http') . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME']);
            $link = rtrim($base, '/\\') . '/confirmar_reset_senha.php?token=' . $token;

            // Envia email
            $assunto = "RADCI - Redefinição de Senha";
            $mens = "Olá, {$user['nome']}\n\nPara redefinir sua senha, acesse o link:\n{$link}\n\nEste link expira em 30 minutos.\n\n— RADCI";
            try {
                @mail($user['email'], $assunto, $mens, "From: no-reply@radci.com.br\r\n");
            } catch (Throwable $_) {
                // Silencioso: mantém resposta genérica
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="UTF-8">
  <title>RADCI - Esqueceu a Senha</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        <?php 
        $tailwindPath = __DIR__ . '/tailwind.css';
        if (file_exists($tailwindPath)) {
            echo file_get_contents($tailwindPath);
        }
        ?>
        .hide-scrollbar::-webkit-scrollbar { 
            display: none; 
        }
        .hide-scrollbar { 
            -ms-overflow-style: none; 
            scrollbar-width: none; 
        }
    </style>
</head>
<body class="min-h-screen flex flex-col items-center justify-center bg-white text-gray-900">
  <!-- Botão Voltar -->
  <div class="mb-4 self-start w-full max-w-md px-6">
    <a href="login_cadastro.php" class="inline-block px-3 py-1 border border-green-700 text-green-700 rounded-md hover:bg-green-700 hover:text-white transition">← Voltar</a>
  </div>

  <div class="w-full max-w-md">
    <!-- Logo -->
    <div class="flex items-center justify-center mb-4 space-x-3">
      <div class="bg-green-700 p-2 rounded-xl">
        <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 11c0 3.866-3.582 7-8 7h16c-4.418 0-8-3.134-8-7z"/>
        </svg>
      </div>
      <div>
        <h1 class="text-xl font-bold">RADCI</h1>
        <p class="text-gray-500 text-sm">Cidade Mais Inteligente</p>
      </div>
    </div>

    <!-- Card -->
    <div class="bg-gray-50 rounded-xl shadow-lg p-6 max-h-[75vh] overflow-y-auto hide-scrollbar relative">
      <h2 class="text-lg font-semibold mb-4">Redefinir senha</h2>
      <?php if ($erro): ?>
        <p class="text-red-600 mb-3"><?= htmlspecialchars($erro) ?></p>
      <?php endif; ?>
      <?php if ($mensagem): ?>
        <p class="text-green-600 mb-3"><?= htmlspecialchars($mensagem) ?></p>
      <?php endif; ?>

      <form method="POST" class="space-y-4">
        <div>
          <label class="text-sm mb-1 block">E-mail cadastrado</label>
          <input type="email" name="email" required class="w-full p-3 rounded-md bg-white border border-gray-300 focus:border-green-700 focus:ring-1 focus:ring-green-700" placeholder="seu.email@exemplo.com">
        </div>
        <button type="submit" class="w-full bg-green-700 text-white py-3 rounded-md font-semibold">Enviar link</button>
      </form>

      <div class="mt-4 text-sm text-gray-600">
        <a href="login_cadastro.php" class="text-green-700 hover:underline">Voltar para login</a>
      </div>
    </div>
  </div>
</body>
</html>