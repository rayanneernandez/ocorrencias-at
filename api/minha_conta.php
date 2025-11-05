<?php
// minha_conta.php
session_start();
require_once __DIR__ . '/../includes/db.php'; // get_pdo()
$pdo = get_pdo();

// ---------- Função de envio de e-mail ----------
function enviar_email_simples($destinatario, $assunto, $mensagemHtml) {
    $from = 'no-reply@seu-dominio.com';
    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-type: text/html; charset=UTF-8\r\n";
    $headers .= "From: RADCI <{$from}>\r\n";
    return mail($destinatario, $assunto, $mensagemHtml, $headers);
}

// ---------- Cria tabela reset_senhas se não existir ----------
$pdo->exec("
CREATE TABLE IF NOT EXISTS reset_senhas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT NOT NULL,
    token VARCHAR(128) NOT NULL,
    expira_em DATETIME NOT NULL,
    usado TINYINT(1) DEFAULT 0,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (token),
    INDEX (usuario_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

$tokenGet = $_GET['token'] ?? '';
$mensagem_flash = '';
$erro_redefinir = '';

if (!empty($tokenGet)) {
    $stmt = $pdo->prepare("SELECT * FROM reset_senhas WHERE token = ? LIMIT 1");
    $stmt->execute([$tokenGet]);
    $registro = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$registro) {
        $erro_redefinir = "Token inválido.";
    } elseif ($registro['usado']) {
        $erro_redefinir = "Este link já foi utilizado.";
    } elseif (strtotime($registro['expira_em']) < time()) {
        $erro_redefinir = "Este link expirou.";
    } else {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['nova_senha'], $_POST['confirmar_senha'])) {
            $nova = trim($_POST['nova_senha']);
            $conf = trim($_POST['confirmar_senha']);
            if (strlen($nova) < 6) {
                $erro_redefinir = "A senha deve ter ao menos 6 caracteres.";
            } elseif ($nova !== $conf) {
                $erro_redefinir = "As senhas não conferem.";
            } else {
                $hash = password_hash($nova, PASSWORD_DEFAULT);
                $pdo->prepare("UPDATE usuarios SET senha = ? WHERE id = ?")
                    ->execute([$hash, $registro['usuario_id']]);
                $pdo->prepare("UPDATE reset_senhas SET usado = 1 WHERE id = ?")
                    ->execute([$registro['id']]);
                $mensagem_flash = "Senha redefinida com sucesso. Você já pode fazer login.";
            }
        }

        $stmtU = $pdo->prepare("SELECT id, nome, email FROM usuarios WHERE id = ? LIMIT 1");
        $stmtU->execute([$registro['usuario_id']]);
        $usuarioParaReset = $stmtU->fetch(PDO::FETCH_ASSOC);
    }
} else {
    if (!isset($_SESSION['usuario_id'])) {
        header("Location: login_cadastro.php");
        exit;
    }

    $usuarioId = intval($_SESSION['usuario_id']);
    $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE id = ? LIMIT 1");
    $stmt->execute([$usuarioId]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $usuario = [
        "id" => $u['id'] ?? $usuarioId,
        "nome" => $u['nome'] ?? ($_SESSION['usuario_nome'] ?? 'Usuário RADCI'),
        "email" => $u['email'] ?? '',
        "cep" => $u['cep'] ?? '',
        "uf" => $u['uf'] ?? '',
        "municipio" => $u['municipio'] ?? '',
        "bairro" => $u['bairro'] ?? '',
        "rua" => $u['rua'] ?? '',
        "complemento" => $u['complemento'] ?? '',
        "created_at" => $u['created_at'] ?? date('Y-m-d'),
    ];

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
        $nome = trim($_POST['nome'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $cep = trim($_POST['cep'] ?? '');
        $cidadeInput = trim($_POST['cidade'] ?? '');
        $uf = strtoupper(trim($_POST['uf'] ?? ''));
        $municipio = $cidadeInput;
        if (strpos($cidadeInput, ' - ') !== false) {
            [$municipioPart, $ufPart] = explode(' - ', $cidadeInput, 2);
            $municipio = trim($municipioPart);
            $uf = strtoupper(trim($ufPart));
        }
        $bairro = trim($_POST['bairro'] ?? '');
        $rua = trim($_POST['rua'] ?? '');
        $complemento = trim($_POST['complemento'] ?? '');

        $pdo->prepare("UPDATE usuarios SET nome=?, email=?, cep=?, uf=?, municipio=?, bairro=?, rua=?, complemento=? WHERE id=?")
            ->execute([$nome, $email, $cep, $uf, $municipio, $bairro, $rua, $complemento, $usuario['id']]);

        $mensagem_flash = 'Dados atualizados com sucesso.';
        $usuario['nome'] = $nome;
        $usuario['email'] = $email;
        $usuario['cep'] = $cep;
        $usuario['uf'] = $uf;
        $usuario['municipio'] = $municipio;
        $usuario['bairro'] = $bairro;
        $usuario['rua'] = $rua;
        $usuario['complemento'] = $complemento;
    }

    $pdo->exec("
    CREATE TABLE IF NOT EXISTS usuarios_preferencias (
      usuario_id INT PRIMARY KEY,
      notif_ocorrencias TINYINT(1) NOT NULL DEFAULT 1,
      notif_novidades TINYINT(1) NOT NULL DEFAULT 1,
      updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $prefStmt = $pdo->prepare("SELECT * FROM usuarios_preferencias WHERE usuario_id = ? LIMIT 1");
    $prefStmt->execute([$usuario['id']]);
    $prefs = $prefStmt->fetch(PDO::FETCH_ASSOC) ?: ['notif_ocorrencias'=>1,'notif_novidades'=>1];

    if (isset($_POST['logout'])) {
        session_unset();
        session_destroy();
        header("Location: principal.php");
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['enviar_link'])) {
        $emailUser = $usuario['email'] ?? '';
        if (!empty($emailUser)) {
            $pdo->prepare("DELETE FROM reset_senhas WHERE usuario_id=?")->execute([$usuario['id']]);
            $token = bin2hex(random_bytes(32));
            $expira_em = date('Y-m-d H:i:s', strtotime('+1 hour'));
            $pdo->prepare("INSERT INTO reset_senhas (usuario_id, token, expira_em) VALUES (?, ?, ?)")
                ->execute([$usuario['id'], $token, $expira_em]);

            $base = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS']==='on' ? "https" : "http") . "://{$_SERVER['HTTP_HOST']}" . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
            $link = $base . '/' . basename(__FILE__) . '?token=' . urlencode($token);

            $assunto = "Redefinição de Senha - RADCI";
            $mensagemHtml = "
                <p>Olá, <strong>".htmlspecialchars($usuario['nome'])."</strong>,</p>
                <p>Clique no link abaixo para redefinir sua senha (válido por 1 hora):</p>
                <p><a href='{$link}' style='color:#065f46'>Redefinir minha senha</a></p>
            ";

            $mensagem_flash = enviar_email_simples($emailUser,$assunto,$mensagemHtml) ?
                "E-mail enviado com sucesso. Verifique sua caixa de entrada." :
                "Falha ao enviar o e-mail. Verifique o servidor.";
        } else {
            $mensagem_flash = "E-mail não encontrado para este usuário.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>Minha Conta | RADCI</title>
<script src="https://cdn.tailwindcss.com"></script>
<script defer src="https://unpkg.com/lucide@latest"></script>
<style>:root{--primary:#065f46}</style>
</head>
<body class="min-h-screen bg-white text-gray-800">

<header class="bg-white border-b py-4 shadow-sm">
  <div class="container mx-auto px-4 flex items-center justify-between">
    <div class="flex items-center gap-3">
      <div class="bg-[var(--primary)] text-white w-10 h-10 rounded flex items-center justify-center font-bold">R</div>
      <div>
        <h1 class="text-lg font-semibold text-[var(--primary)]">RADCI</h1>
        <p class="text-xs text-gray-500"><?= empty($tokenGet) ? 'Minha Conta' : 'Redefinição de senha' ?></p>
      </div>
    </div>
    <?php if (empty($tokenGet)): ?>
    <form method="POST" class="hidden md:block">
      <button type="submit" name="logout" class="text-sm text-gray-600 hover:underline">Sair</button>
    </form>
    <?php endif; ?>
  </div>
</header>

<main class="container mx-auto px-4 py-8 max-w-2xl">
<?php if(!empty($mensagem_flash)): ?>
<div class="mb-4 p-3 rounded bg-green-50 border border-green-200 text-green-800">
<?= htmlspecialchars($mensagem_flash) ?>
</div>
<?php endif; ?>

<?php if(!empty($tokenGet)): ?>
<div class="bg-white p-6 rounded-lg shadow">
<h2 class="text-xl font-bold text-[var(--primary)] mb-2">Redefinir Senha</h2>
<?php if(!empty($erro_redefinir)): ?>
<p class="text-red-600 mb-4"><?= htmlspecialchars($erro_redefinir) ?></p>
<?php else: ?>
<form method="POST" class="space-y-3">
<div><label class="block text-sm font-medium">Nova senha</label><input type="password" name="nova_senha" required class="w-full border p-2 rounded"></div>
<div><label class="block text-sm font-medium">Confirmar nova senha</label><input type="password" name="confirmar_senha" required class="w-full border p-2 rounded"></div>
<button class="bg-[var(--primary)] text-white px-4 py-2 rounded">Salvar nova senha</button>
</form>
<?php endif; ?>
</div>
<?php else: ?>
<!-- Página Minha Conta -->
<div class="flex items-center gap-4 mb-6">
<div class="w-14 h-14 rounded-full bg-[var(--primary)] flex items-center justify-center text-white text-lg font-bold">
<?= strtoupper(substr($usuario['nome'] ?? 'U',0,1)) ?>
</div>
<div>
<h2 class="text-lg font-bold text-[var(--primary)]">Olá, <?= htmlspecialchars($usuario['nome'] ?? 'Usuário') ?></h2>
<p class="text-xs text-gray-500">Membro desde <?= date('Y', strtotime($usuario['created_at'])) ?></p>
</div>
</div>

<section class="bg-white rounded p-6 shadow mb-6">
<h3 class="font-semibold mb-3">Informações da Conta</h3>
<form method="POST" class="space-y-3">
<input type="hidden" name="update_profile" value="1"/>
<label class="block text-sm">Nome completo</label>
<input type="text" name="nome" value="<?= htmlspecialchars($usuario['nome'] ?? '') ?>" class="w-full border p-2 rounded"/>
<label class="block text-sm">E-mail</label>
<input type="email" name="email" value="<?= htmlspecialchars($usuario['email'] ?? '') ?>" class="w-full border p-2 rounded"/>
<div class="grid grid-cols-1 md:grid-cols-2 gap-3">
<div><label class="block text-sm">CEP</label><input type="text" name="cep" value="<?= htmlspecialchars($usuario['cep'] ?? '') ?>" class="w-full border p-2 rounded"/></div>
<div><label class="block text-sm">Cidade (ex.: São Paulo - SP)</label><input type="text" name="cidade" value="<?= htmlspecialchars((($usuario['municipio'] ?? '') . ($usuario['uf'] ? ' - '.$usuario['uf'] : ''))) ?>" class="w-full border p-2 rounded"/></div>
</div>
<div class="grid grid-cols-1 md:grid-cols-2 gap-3">
<div><label class="block text-sm">Bairro</label><input type="text" name="bairro" value="<?= htmlspecialchars($usuario['bairro'] ?? '') ?>" class="w-full border p-2 rounded"/></div>
<div><label class="block text-sm">Rua</label><input type="text" name="rua" value="<?= htmlspecialchars($usuario['rua'] ?? '') ?>" class="w-full border p-2 rounded"/></div>
</div>
<label class="block text-sm">Complemento</label>
<input type="text" name="complemento" value="<?= htmlspecialchars($usuario['complemento'] ?? '') ?>" class="w-full border p-2 rounded"/>
<div class="flex gap-3 mt-3">
<button type="submit" class="bg-[var(--primary)] text-white px-4 py-2 rounded">Salvar Alterações</button>
<a href="dashboard.php" class="px-4 py-2 border rounded text-sm">Voltar</a>
</div>
</form>
</section>

<section class="bg-white rounded p-6 shadow mb-6">
<h3 class="font-semibold mb-3">Notificações</h3>
<div class="flex items-center justify-between mb-3"><div>Atualizações de ocorrências</div><input type="checkbox" <?= intval($prefs['notif_ocorrencias']) ? 'checked' : '' ?> disabled/></div>
<div class="flex items-center justify-between"><div>Novidades da plataforma</div><input type="checkbox" <?= intval($prefs['notif_novidades']) ? 'checked' : '' ?> disabled/></div>
</section>

<section class="bg-white rounded p-6 shadow">
<h3 class="font-semibold mb-3">Segurança</h3>
<p class="text-sm text-gray-600 mb-4">Para alterar sua senha, enviaremos um link de redefinição por e-mail.</p>
<form method="POST" class="flex gap-3">
<input type="hidden" name="enviar_link" value="1"/>
<button type="submit" class="bg-[var(--primary)] text-white px-4 py-2 rounded inline-flex items-center">
<svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 11c0 3.866-3.582 7-8 7h16c-4.418 0-8-3.134-8-7z"/></svg>
Enviar confirmação por e-mail
</button>
</form>
</section>

<?php endif; ?>
</main>

<script>
document.addEventListener('DOMContentLoaded', function() {
  if(window.lucide && typeof lucide.createIcons === 'function') lucide.createIcons();
});
</script>
</body>
</html>
