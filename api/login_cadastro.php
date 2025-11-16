<?php
session_start();

require_once __DIR__ . '/../includes/db.php';
$pdo = get_pdo();

$erroLogin = "";
$erroCadastro = "";
$sucessoCadastro = "";

// Flash de sucesso para exibir toast de 3s no front
$flashSuccess = $_SESSION['flash_success'] ?? '';
unset($_SESSION['flash_success']);

if (isset($_POST['acao'])) {
    if ($_POST['acao'] === 'login') {
        $email = trim($_POST['login_email'] ?? '');
        $senha = trim($_POST['login_senha'] ?? '');

        try {
            $stmt = $pdo->prepare("SELECT id, nome, email, senha, perfil FROM usuarios WHERE email = ? LIMIT 1");
            $stmt->execute([$email]);
            $user = $stmt->fetch();
        } catch (Throwable $e) {
            $user = null;
        }

        // Se existe solicitação pendente, bloqueia login e mostra mensagem específica
        $hasPending = false;
        if ($user) {
            try {
                $stmtP = $pdo->prepare("SELECT 1 FROM admin_publico_solicitacoes WHERE usuario_id = ? AND status = 'pendente' LIMIT 1");
                $stmtP->execute([(int)$user['id']]);
                $hasPending = (bool)$stmtP->fetchColumn();
            } catch (Throwable $_) {}
        }
        if ($user && (int)$user['perfil'] === 1 && $hasPending) {
            $erroLogin = "Sua solicitação de Administrador Público está pendente. Aguarde aprovação por e-mail.";
        } else {
            $ok = false;
            if ($user) {
                if (password_verify($senha, $user['senha']) || $user['senha'] === $senha) {
                    $ok = true;
                }
            }
            if (!$ok) {
                $erroLogin = "E-mail ou senha inválidos.";
            } else {
                $_SESSION['usuario_id']     = (int)$user['id'];
                $_SESSION['usuario_nome']   = $user['nome'];
                $_SESSION['usuario_perfil'] = (int)$user['perfil'];

                $redirect = 'dashboard.php';
                if ((int)$user['perfil'] === 10) { $redirect = 'admin_inicio.php'; }
                elseif ((int)$user['perfil'] === 2) { $redirect = 'prefeito_inicio.php'; }
                elseif ((int)$user['perfil'] === 3) { $redirect = 'secretario.php'; }

                header("Location: $redirect");
                exit();
            }
        }
    } elseif ($_POST['acao'] === 'cadastro') {
        $nome           = trim($_POST['nome_completo'] ?? '');
        $email          = trim($_POST['email_cadastro'] ?? '');
        $senha          = (string)($_POST['senha_cadastro'] ?? '');
        $confirmarSenha = (string)($_POST['confirmar_senha'] ?? '');
        $termos         = isset($_POST['termos']) ? 1 : 0;
        $privacidade    = isset($_POST['privacidade']) ? 1 : 0;

        $perfilStr       = trim($_POST['perfil'] ?? '');
        $solicitadoAdminPublico = ($perfilStr === 'admin_publico');
        
        $perfilMap = ['cidadao'=>1, 'admin_publico'=>1, 'admin_radci'=>10];
        $perfil    = $perfilMap[$perfilStr] ?? 1;

        // Validação dos campos base
        if ($senha !== $confirmarSenha) {
            $erroCadastro = "As senhas não coincidem.";
        } elseif (!$termos || !$privacidade) {
            $erroCadastro = "Você deve aceitar os termos de uso e privacidade.";
        } elseif (strlen($senha) < 6 || !preg_match('/[A-Za-z]/', $senha) || !preg_match('/[0-9]/', $senha) || !preg_match('/[^A-Za-z0-9]/', $senha)) {
            $erroCadastro = "A senha deve conter no mínimo 6 caracteres, incluindo letras, números e caracteres especiais.";
        } else {
            // Validação de documentos quando é Administrador Público
            if ($solicitadoAdminPublico) {
                $docTipo      = trim($_POST['doc_tipo'] ?? '');
                $docOutros    = trim($_POST['doc_outros'] ?? '');
                $docFonteUrl  = trim($_POST['doc_fonte_url'] ?? '');
                $allowedTipos = ['diploma_prefeito','termo_posse','publicacao_oficial','oficio_timbre','outros'];
                if (!in_array($docTipo, $allowedTipos, true)) {
                    $erroCadastro = "Selecione um tipo de documento válido.";
                } elseif ($docTipo === 'outros' && $docOutros === '') {
                    $erroCadastro = "Descreva o documento quando selecionar 'Outros'.";
                }

                $fileErr = '';
                $storedTemp = [];
                if (!isset($_FILES['doc_arquivos']) || empty($_FILES['doc_arquivos']['name'])) {
                    $fileErr = "Anexe ao menos um documento (PDF ou imagem).";
                } else {
                    $names = $_FILES['doc_arquivos']['name'];
                    $tmps  = $_FILES['doc_arquivos']['tmp_name'];
                    $errs  = $_FILES['doc_arquivos']['error'];
                    $cnt   = is_array($names) ? count($names) : 1;
                    if ($cnt > 3) {
                        $fileErr = "Você pode anexar no máximo 3 arquivos.";
                    } else {
                        $finfo = new finfo(FILEINFO_MIME_TYPE);
                        for ($i = 0; $i < $cnt; $i++) {
                            if (($errs[$i] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) { $fileErr = "Falha no upload de um dos arquivos."; break; }
                            $mime = $finfo->file($tmps[$i]);
                            $okMime = in_array($mime, ['application/pdf','image/jpeg','image/png'], true);
                            if (!$okMime) { $fileErr = "Apenas PDF, JPG ou PNG são permitidos."; break; }
                            $ext = strtolower(pathinfo($names[$i], PATHINFO_EXTENSION));
                            $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', pathinfo($names[$i], PATHINFO_FILENAME));
                            $dest = __DIR__ . '/../uploads/temp/' . (uniqid('ap_', true)) . '_' . $safe . '.' . $ext;
                            if (!@move_uploaded_file($tmps[$i], $dest)) { $fileErr = "Não foi possível salvar um dos arquivos."; break; }
                            $storedTemp[] = ['path' => $dest, 'mime' => $mime, 'name' => $safe . '.' . $ext];
                        }
                    }
                }
                if ($fileErr) { $erroCadastro = $fileErr; }

                if (!$erroCadastro && $docTipo === 'publicacao_oficial') {
                    if (!filter_var($docFonteUrl, FILTER_VALIDATE_URL)) {
                        $erroCadastro = "Informe a URL da publicação oficial (Diário Oficial ou site da Prefeitura/Estado).";
                    }
                }

                // Se houve erro, limpa temp
                if ($erroCadastro ?? '') {
                    foreach ($storedTemp as $f) { @unlink($f['path']); }
                }
            }

            // Inicializa variáveis para evitar avisos e validar corretamente
            $exists = false;
            try {
                $stmt = $pdo->prepare("SELECT 1 FROM usuarios WHERE email = ? LIMIT 1");
                $stmt->execute([$email]);
                $exists = (bool)$stmt->fetch();
            } catch (Throwable $_) {}

            if ($exists) {
                $erroCadastro = "E-mail já cadastrado.";
            } else {
                // Dentro do bloco de cadastro, após verificar se o e-mail não existe:
                if ($perfil === 2) {
                    $docTipo      = trim($_POST['doc_tipo'] ?? '');
                    $docOutros    = trim($_POST['doc_outros'] ?? '');
                    $docFonteUrl  = trim($_POST['doc_fonte_url'] ?? '');
                    $allowedTipos = ['diploma_prefeito','termo_posse','publicacao_oficial','oficio_timbre','outros'];
                    if (!in_array($docTipo, $allowedTipos, true)) {
                        $erroCadastro = "Selecione um tipo de documento válido.";
                    } elseif ($docTipo === 'outros' && $docOutros === '') {
                        $erroCadastro = "Descreva o documento quando selecionar 'Outros'.";
                    }

                    // Validação de anexos: até 3, tipos permitidos
                    $fileErr = '';
                    $storedTemp = [];
                    if (!isset($_FILES['doc_arquivos']) || empty($_FILES['doc_arquivos']['name'])) {
                        $fileErr = "Anexe ao menos um documento (PDF ou imagem).";
                    } else {
                        $names = $_FILES['doc_arquivos']['name'];
                        $tmps  = $_FILES['doc_arquivos']['tmp_name'];
                        $errs  = $_FILES['doc_arquivos']['error'];
                        $cnt   = is_array($names) ? count($names) : 1;
                        if ($cnt > 3) {
                            $fileErr = "Você pode anexar no máximo 3 arquivos.";
                        } else {
                            $finfo = new finfo(FILEINFO_MIME_TYPE);
                            for ($i = 0; $i < $cnt; $i++) {
                                if (($errs[$i] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) { $fileErr = "Falha no upload de um dos arquivos."; break; }
                                $mime = $finfo->file($tmps[$i]);
                                $okMime = in_array($mime, ['application/pdf','image/jpeg','image/png'], true);
                                if (!$okMime) { $fileErr = "Apenas PDF, JPG ou PNG são permitidos."; break; }
                                // move para uploads/temp com nome único
                                $ext = strtolower(pathinfo($names[$i], PATHINFO_EXTENSION));
                                $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', pathinfo($names[$i], PATHINFO_FILENAME));
                                $dest = __DIR__ . '/../uploads/temp/' . (uniqid('pref_', true)) . '_' . $safe . '.' . $ext;
                                if (!@move_uploaded_file($tmps[$i], $dest)) { $fileErr = "Não foi possível salvar um dos arquivos."; break; }
                                $storedTemp[] = ['path' => $dest, 'mime' => $mime, 'name' => $safe . '.' . $ext];
                            }
                        }
                    }
                    if ($fileErr) { $erroCadastro = $fileErr; }

                    // Validação adicional para Publicação Oficial: URL obrigatória e acessível
                    if (!$erroCadastro && $docTipo === 'publicacao_oficial') {
                        if (!filter_var($docFonteUrl, FILTER_VALIDATE_URL)) {
                            $erroCadastro = "Informe a URL da publicação oficial (Diário Oficial ou site da Prefeitura/Estado).";
                        } else {
                            // Checa se a URL é acessível e parece oficial (.gov.br ou contém 'diario'/'tre')
                            $host = parse_url($docFonteUrl, PHP_URL_HOST) ?? '';
                            $isOficialHost = (str_ends_with($host, '.gov.br') || stripos($host, 'diario') !== false || stripos($host, 'tre') !== false);
                            $ch = curl_init($docFonteUrl);
                            curl_setopt_array($ch, [
                                CURLOPT_NOBODY => true,
                                CURLOPT_RETURNTRANSFER => true,
                                CURLOPT_TIMEOUT => 8,
                                CURLOPT_FOLLOWLOCATION => true,
                                CURLOPT_SSL_VERIFYPEER => false,
                            ]);
                            $ok = curl_exec($ch) !== false;
                            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                            curl_close($ch);
                            if (!$ok || $code < 200 || $code >= 400) {
                                $erroCadastro = "Não foi possível validar a URL da publicação oficial.";
                            } elseif (!$isOficialHost) {
                                $erroCadastro = "A URL deve ser um domínio oficial (.gov.br, Diário Oficial ou TRE).";
                            }
                        }
                    }

                    // Se houver erro nas validações do Prefeito, limpa quaisquer arquivos temporários
                    if ($erroCadastro) {
                        foreach ($storedTemp as $f) { @unlink($f['path']); }
                    }
                }

                if (!$erroCadastro) {
                    $hash = password_hash($senha, PASSWORD_DEFAULT);

                    try {
                        $stmt = $pdo->prepare("
                            INSERT INTO usuarios (nome, email, senha, perfil, cep, uf, municipio, bairro, rua, complemento)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([
                            $nome,
                            $email,
                            $hash,
                            $perfil,
                            trim($_POST['cep'] ?? ''),
                            strtoupper(trim($_POST['uf'] ?? '')),
                            trim($_POST['cidade'] ?? ''),
                            trim($_POST['bairro'] ?? ''),
                            trim($_POST['logradouro'] ?? ''),
                            trim($_POST['complemento'] ?? ''),
                        ]);
                        $newUserId = (int)$pdo->lastInsertId();

                        // Garante a data de cadastro no banco
                        try {
                            if ($newUserId > 0) {
                                $pdo->prepare("UPDATE usuarios SET created_at = NOW() WHERE id = ?")->execute([$newUserId]);
                            }
                        } catch (Throwable $_) {}
                        if ($solicitadoAdminPublico && $newUserId > 0) {
                            // Cria tabelas e grava solicitação pendente
                            $pdo->exec("
                                CREATE TABLE IF NOT EXISTS admin_publico_solicitacoes (
                                    id INT AUTO_INCREMENT PRIMARY KEY,
                                    usuario_id INT NOT NULL,
                                    status ENUM('pendente','aprovado','recusado') NOT NULL DEFAULT 'pendente',
                                    data_solicitacao DATETIME NOT NULL,
                                    data_aprovacao DATETIME NULL,
                                    aprovado_por INT NULL,
                                    INDEX (usuario_id)
                                )
                            ");

                            // Registra solicitação pendente
                            $stmtSol = $pdo->prepare("
                                INSERT INTO admin_publico_solicitacoes (usuario_id, status, data_solicitacao)
                                VALUES (?, 'pendente', NOW())
                            ");
                            $stmtSol->execute([$newUserId]);

                            // NOVO: criar tabela de validações e gravar anexos para aparecer como pendente
                            $pdo->exec("
                                CREATE TABLE IF NOT EXISTS admin_publico_validacoes (
                                  id INT AUTO_INCREMENT PRIMARY KEY,
                                  usuario_id INT NOT NULL,
                                  tipo_documento VARCHAR(50) NOT NULL,
                                  descricao_outros VARCHAR(255) NULL,
                                  fonte_url VARCHAR(255) NULL,
                                  arquivos_json TEXT NOT NULL,
                                  status VARCHAR(20) NOT NULL DEFAULT 'pendente',
                                  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                                  INDEX (usuario_id)
                                )
                            ");

                            // Move arquivos da temp para uploads/admin_publico/{usuario_id}/ e registra JSON
                            $finalDir = __DIR__ . '/../uploads/admin_publico/' . $newUserId . '/';
                            if (!is_dir($finalDir)) { @mkdir($finalDir, 0777, true); }
                            $finalFiles = [];
                            foreach ($storedTemp ?? [] as $f) {
                                $basename = basename($f['path']);
                                $finalPath = $finalDir . $basename;
                                if (@rename($f['path'], $finalPath)) {
                                    $finalFiles[] = [
                                        'path' => 'uploads/admin_publico/' . $newUserId . '/' . $basename,
                                        'mime' => $f['mime'],
                                        'name' => $f['name']
                                    ];
                                } else {
                                    @copy($f['path'], $finalPath);
                                    @unlink($f['path']);
                                    $finalFiles[] = [
                                        'path' => 'uploads/admin_publico/' . $newUserId . '/' . $basename,
                                        'mime' => $f['mime'],
                                        'name' => $f['name']
                                    ];
                                }
                            }

                            // Insere a validação pendente com os metadados (tipo de documento, etc.)
                            $stmtVal = $pdo->prepare("
                                INSERT INTO admin_publico_validacoes (usuario_id, tipo_documento, descricao_outros, fonte_url, arquivos_json, status)
                                VALUES (?, ?, ?, ?, ?, 'pendente')
                            ");
                            $stmtVal->execute([
                                $newUserId,
                                $docTipo,
                                $docOutros,
                                $docFonteUrl,
                                json_encode($finalFiles, JSON_UNESCAPED_SLASHES)
                            ]);

                            // Mensagem visível no card de cadastro
                            $sucessoCadastro = 'Solicitação enviada para autorização. Você receberá um e-mail após a aprovação.';

                            // Em vez da mensagem genérica, usa flash + redirect para exibir toast de 3s
                            $_SESSION['flash_success'] = 'Solicitação enviada para autorização. Você receberá um e-mail após a aprovação.';
                            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                                @mail(
                                    $email,
                                    'RADCI - Solicitação de Administrador Público recebida',
                                    "Olá {$nome},\n\nRecebemos sua solicitação para acesso como Administrador Público.\nAssim que for autorizada, você receberá um e-mail de confirmação.\n\nEquipe RADCI",
                                    "Content-Type: text/plain; charset=UTF-8"
                                );
                            }
                            header('Location: login_cadastro.php?tab=cadastro');
                            exit();
                        } else {
                            // Cadastro comum (Cidadão/Admin RADCI)
                            $sucessoCadastro = "Cadastro realizado com sucesso! Agora faça login.";
                        }
                        // Se Prefeito: persiste os documentos e metadados
                        if ($perfil === 2) {
                            // Cria tabela de validação se não existir
                            $pdo->exec("
                                CREATE TABLE IF NOT EXISTS prefeitos_validacoes (
                                  id INT AUTO_INCREMENT PRIMARY KEY,
                                  usuario_id INT NOT NULL,
                                  tipo_documento VARCHAR(50) NOT NULL,
                                  descricao_outros VARCHAR(255) NULL,
                                  fonte_url VARCHAR(255) NULL,
                                  arquivos_json TEXT NOT NULL,
                                  status VARCHAR(20) NOT NULL DEFAULT 'pendente',
                                  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                                )
                            ");

                            // Move arquivos de temp para pasta final do usuário
                            $finalDir = __DIR__ . '/../uploads/prefeitos/' . $newUserId . '/';
                            if (!is_dir($finalDir)) { @mkdir($finalDir, 0777, true); }
                            $finalFiles = [];
                            foreach ($storedTemp ?? [] as $f) {
                                $basename = basename($f['path']);
                                $finalPath = $finalDir . $basename;
                                if (@rename($f['path'], $finalPath)) {
                                    $finalFiles[] = ['path' => 'uploads/prefeitos/' . $newUserId . '/' . $basename, 'mime' => $f['mime'], 'name' => $f['name']];
                                } else {
                                    // fallback: copia e apaga
                                    @copy($f['path'], $finalPath);
                                    @unlink($f['path']);
                                    $finalFiles[] = ['path' => 'uploads/prefeitos/' . $newUserId . '/' . $basename, 'mime' => $f['mime'], 'name' => $f['name']];
                                }
                            }

                            // Insere registro de validação
                            $stmtVal = $pdo->prepare("
                                INSERT INTO prefeitos_validacoes (usuario_id, tipo_documento, descricao_outros, fonte_url, arquivos_json, status)
                                VALUES (?, ?, ?, ?, ?, ?)
                            ");
                            $stmtVal->execute([
                                $newUserId,
                                trim($_POST['doc_tipo'] ?? ''),
                                trim($_POST['doc_outros'] ?? ''),
                                trim($_POST['doc_fonte_url'] ?? ''),
                                json_encode($finalFiles, JSON_UNESCAPED_SLASHES),
                                'pendente'
                            ]);
                        }

                        $sucessoCadastro = "Cadastro realizado com sucesso! Agora faça login.";
                        
                    } catch (Throwable $e) {
                        $erroCadastro = "Erro ao cadastrar usuário.";
                    }
                }
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="pt-br"
<head>
<meta charset="UTF-8">
<title>RADCI - Login e Cadastro</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://unpkg.com/lucide@latest"></script>
<style>
:root{--verde-principal:#065f46;--verde-hover:#047857;}
<?php $cssPath = __DIR__ . '/../assets/css/style.css'; if(file_exists($cssPath)) echo file_get_contents($cssPath); ?>
.hide-scrollbar::-webkit-scrollbar{display:none;}.hide-scrollbar{-ms-overflow-style:none;scrollbar-width:none;}
@media(max-width:640px){body{background-color:#f9fafb;}.card-container{padding:0;}.login-card{border-radius:2rem 2rem 0 0;margin-top:auto;box-shadow:0 -4px 6px -1px rgba(0,0,0,.1);}input,select{font-size:16px!important}.btn-submit{position:sticky;bottom:0;margin-top:2rem;}}
.fade-in{animation:fadeIn .3s ease-in-out}@keyframes fadeIn{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
input::placeholder,select::placeholder{color:#9ca3af}input:focus,select:focus{outline:none;box-shadow:0 0 0 3px rgba(6,95,70,.1);}
.text-verde-principal{color:#047857!important;}
.text-green-500,.text-green-600,.text-green-700{color:var(--verde-principal)!important;}
.bg-green-500,.bg-green-600,.bg-green-700{background-color:var(--verde-principal)!important;}
.border-green-500,.border-green-600,.border-green-700{border-color:var(--verde-principal)!important;}
.hover\:bg-green-500:hover,.hover\:bg-green-600:hover,.hover\:bg-green-700:hover{background-color:var(--verde-hover)!important;}
.password-toggle{position:absolute;right:.75rem;top:50%;transform:translateY(-50%);color:#6b7280;cursor:pointer;padding:.25rem;border-radius:.375rem;transition:all .2s}
.password-toggle:hover{color:var(--verde-principal);background-color:#f3f4f6}
</style>
</head>
<body class="min-h-screen flex flex-col bg-white text-gray-900">

<?php if (!empty($flashSuccess)): ?>
  <div id="toastSuccess" class="fixed top-4 left-1/2 -translate-x-1/2 bg-green-600 text-white px-4 py-2 rounded shadow z-50">
    <?= htmlspecialchars($flashSuccess) ?>
  </div>
  <script>
    setTimeout(() => { document.getElementById('toastSuccess')?.classList.add('hidden'); }, 3000);
  </script>
<?php endif; ?>


<div class="flex-1 flex flex-col items-center justify-center px-4 pt-8 pb-8">
<div class="w-full max-w-md relative">
<!-- Botão voltar mobile -->
<div class="md:hidden mb-6">
<a href="principal.php" class="inline-block px-3 py-1 border border-green-500 text-green-500 rounded-md hover:bg-green-500 hover:text-white transition">← Voltar</a>
</div>

<!-- Container flex para desktop -->
<div class="hidden md:flex items-start gap-4">
<!-- Botão voltar desktop -->
<a href="principal.php" class="inline-block px-3 py-1 border border-green-500 text-green-500 rounded-md hover:bg-green-500 hover:text-white transition self-start sticky top-8">← Voltar</a>

<!-- Container principal -->
<div class="flex-1">
<div class="flex items-center justify-center mb-6 space-x-3">
<div class="bg-green-500 p-3 rounded-xl">
<img src="/radci/assets/images/logo.png" alt="RADCI" class="w-8 h-8">
<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 11c0 3.866-3.582 7-8 7h16c-4.418 0-8-3.134-8-7z"/>
</svg>
</div>
<div>
<h1 class="text-2xl font-bold">RADCI</h1>
<p class="text-gray-500">Cidade Mais Inteligente</p>
</div>
</div>
</div>
</div>

<div class="md:hidden">
<div class="flex items-center justify-center mb-6 space-x-3">
<div class="bg-green-500 rounded-xl w-16 h-16 flex items-center justify-center">
<img src="/radci/assets/images/logo.png" alt="RADCI" class="w-10 h-10">
</div>
<div>
<h1 class="text-2xl font-bold">RADCI</h1>
<p class="text-gray-500">Cidade Mais Inteligente</p>
</div>
</div>
</div>

<div class="bg-gray-50 rounded-2xl shadow-lg p-6 sm:p-8 relative">
<!-- Header das abas -->
<div class="flex space-x-4 mb-6">
<button id="tabBtnLogin" type="button" onclick="switchTab('login')" class="flex-1 py-3 font-semibold text-gray-500 border-b-2 border-transparent">Entrar</button>
<button id="tabBtnCadastro" type="button" onclick="switchTab('cadastro')" class="flex-1 py-3 font-semibold border-b-2 border-green-500 text-green-600">Cadastrar</button>
</div>

<!-- LOGIN -->
<div id="tabLogin" style="<?php echo ($initialTab === 'login' ? 'display:block' : 'display:none'); ?>">
<?php if($erroLogin) echo "<p class='text-red-500 mb-2'>$erroLogin</p>"; ?>
<form method="POST" class="space-y-6">
<input type="hidden" name="acao" value="login">
<div><label class="text-sm font-medium mb-2 block text-gray-700">E-mail</label>
<input type="email" name="login_email" required class="w-full p-4 rounded-lg bg-white border border-gray-300 focus:border-green-500 focus:ring-2 focus:ring-green-200 text-base transition-colors" placeholder="Digite seu e-mail"></div>

<div><label class="text-sm font-medium mb-2 block text-gray-700">Senha</label>
<div class="relative">
<input type="password" id="loginSenha" name="login_senha" required class="w-full p-4 rounded-lg bg-white border border-gray-300 focus:border-green-500 focus:ring-2 focus:ring-green-200 text-base transition-colors" placeholder="Digite sua senha">
<button type="button" onclick="togglePassword('loginSenha')" class="password-toggle"><i data-lucide="eye" class="w-5 h-5"></i></button>
</div></div>

<div class="flex justify-end text-sm"><a href="esqueceu_senha.php" class="text-green-600 hover:text-green-700 font-medium hover:underline transition-colors">Esqueceu sua senha?</a></div>

<button type="submit" class="w-full bg-green-500 text-white py-4 rounded-lg font-semibold text-lg hover:bg-green-600 transition-colors shadow-sm hover:shadow">Entrar</button>
</form>
</div>

<!-- CADASTRO -->
<div id="tabCadastro" style="<?php echo ($initialTab === 'cadastro' ? 'display:block' : 'display:none'); ?>">
<?php if($erroCadastro) echo "<p class='text-red-500 mb-2'>$erroCadastro</p>"; ?>
<?php if($sucessoCadastro) echo "<p class='text-green-500 mb-2'>$sucessoCadastro</p>"; ?>
<form method="POST" class="space-y-6" id="formCadastro" enctype="multipart/form-data">
<input type="hidden" name="acao" value="cadastro">

<div>
<label class="text-sm font-medium mb-2 block text-gray-700">Perfil *</label>
<select name="perfil" id="perfilSelect" required class="w-full p-4 rounded-lg bg-white border border-gray-300 focus:border-green-500 focus:ring-2 focus:ring-green-500 text-base transition-colors appearance-none">
    <option value="">Selecione seu perfil</option>
    <option value="cidadao">Cidadão</option>
    <option value="admin_publico">Administrador Público</option>
</select>
</div>

<div><label class="text-sm font-medium mb-2 block text-gray-700">Nome Completo *</label>
<input type="text" name="nome_completo" required placeholder="Digite seu nome completo" class="w-full p-4 rounded-lg bg-white border border-gray-300 focus:border-green-500 focus:ring-2 focus:ring-green-200 text-base transition-colors">
</div>

<div><label class="text-sm mb-1 block">E-mail Institucional *</label>
<input type="email" name="email_cadastro" required class="w-full p-3 rounded-md bg-white border border-gray-300 focus:border-green-500 focus:ring-1 focus:ring-green-500"></div>

<div><label class="text-sm mb-1 block">Senha *</label>
<div class="relative">
<input type="password" id="senhaCadastro" name="senha_cadastro" required 
                               class="w-full p-3 rounded-md bg-white border border-gray-300 focus:border-green-500 focus:ring-1 focus:ring-green-500 pr-10" 
                               oninput="validatePassword(this.value)" 
                               onchange="validatePassword(this.value)"
                               onkeyup="validatePassword(this.value)"
                               onpaste="setTimeout(() => validatePassword(this.value), 100)"
                               onfocus="validatePassword(this.value)"
                               onblur="validatePassword(this.value)">
<button type="button" onclick="togglePassword('senhaCadastro')" class="password-toggle"><i data-lucide="eye" class="w-5 h-5"></i></button>
</div>
<div class="mt-2 text-xs space-y-1">
<div id="minLength" class="flex items-center gap-1 text-red-500"><i data-lucide="x-circle" class="w-4 h-4"></i><span>Mínimo 6 caracteres</span></div>
<div id="hasLetter" class="flex items-center gap-1 text-red-500"><i data-lucide="x-circle" class="w-4 h-4"></i><span>Pelo menos uma letra</span></div>
<div id="hasNumber" class="flex items-center gap-1 text-red-500"><i data-lucide="x-circle" class="w-4 h-4"></i><span>Pelo menos um número</span></div>
<div id="hasSpecial" class="flex items-center gap-1 text-red-500"><i data-lucide="x-circle" class="w-4 h-4"></i><span>Pelo menos um caractere especial</span></div>
</div></div>

<div><label class="text-sm mb-1 block">Confirmar Senha *</label>
<div class="relative">
<input type="password" id="confirmSenhaCadastro" name="confirmar_senha" required class="w-full p-3 rounded-md bg-white border border-gray-300 focus:border-green-500 focus:ring-1 focus:ring-green-500 pr-10" oninput="validatePasswordMatch()">
<button type="button" onclick="togglePassword('confirmSenhaCadastro')" class="password-toggle"><i data-lucide="eye" class="w-5 h-5"></i></button>
</div>
<div id="passwordMatch" class="mt-2 text-xs flex items-center gap-1 text-red-500 hidden"><i data-lucide="x-circle" class="w-4 h-4"></i><span>As senhas não coincidem</span></div>
</div>

<div><label class="text-sm mb-1 block">CEP</label>
<input type="text" id="cep" name="cep" maxlength="8" placeholder="00000000" class="w-full p-3 rounded-md bg-white border border-gray-300 focus:border-green-500 focus:ring-1 focus:ring-green-500"></div>

<!-- Documentos do Administrador Público (aparece somente quando perfil = Administrador Público) -->
<div id="adminPublicoDocs" class="hidden mt-4 p-4 border border-gray-200 rounded-md bg-gray-50">
  <h3 class="text-base font-semibold text-gray-800 mb-3">Documentos do Administrador Público</h3>

  <div class="mb-3">
    <label class="text-sm mb-1 block">Tipo de Documento *</label>
    <select id="docTipo" name="doc_tipo" class="w-full p-3 rounded-md bg-white border border-gray-300 focus:border-green-500 focus:ring-1 focus:ring-green-500">
      <option value="">Selecione</option>
      <option value="diploma_prefeito">Diploma de Prefeito</option>
      <option value="termo_posse">Termo de Posse</option>
      <option value="publicacao_oficial">Publicação Oficial</option>
      <option value="oficio_timbre">Ofício com timbre da Prefeitura</option>
      <option value="outros">Outros</option>
    </select>
    <small class="text-gray-500">Escolha o documento que comprova sua nomeação/posse.</small>
  </div>

  <div id="docOutrosWrap" class="hidden mb-3">
    <label class="text-sm mb-1 block">Descreva o documento (quando selecionar “Outros”) *</label>
    <input type="text" id="docOutros" name="doc_outros" class="w-full p-3 rounded-md bg-white border border-gray-300 focus:border-green-500 focus:ring-1 focus:ring-green-500" placeholder="Ex.: Portaria, decreto, certidão, etc.">
  </div>

  <div id="docFonteUrlWrap" class="hidden mb-3">
    <label class="text-sm mb-1 block">URL da Publicação Oficial</label>
    <input type="url" id="docFonteUrl" name="doc_fonte_url" class="w-full p-3 rounded-md bg-white border border-gray-300 focus:border-green-500 focus:ring-1 focus:ring-green-500" placeholder="https://diariooficial.exemplo.gov.br/...">
    <small class="text-gray-500">Será validado se é um domínio oficial (gov.br, Diário Oficial ou TRE).</small>
  </div>

  <div class="mb-1">
    <label class="text-sm mb-1 block">Anexos (até 3 arquivos - PDF ou imagem) *</label>
    <input type="file" id="docArquivos" name="doc_arquivos[]" multiple accept="application/pdf,image/*" class="w-full p-3 rounded-md bg-white border border-gray-300 focus:border-green-500 focus:ring-1 focus:ring-green-500">
    <small class="text-gray-500">Tipos permitidos: PDF, PNG, JPG, JPEG, WEBP.</small>
  </div>
</div>

<?php
function base_project_root() {
    $script = $_SERVER['SCRIPT_NAME'] ?? '/';
    // Sobe um nível a partir de /api/ (ex.: /radci/api -> /radci; /api -> /)
    $root = rtrim(dirname(dirname($script)), '/\\');
    if ($root === '/' || $root === '\\' || $root === '') {
        return '';
    }
    return $root;
}
$PROJECT_ROOT = base_project_root();
?>

<!-- Botão para edição manual do endereço (acima dos termos) -->
<div class="mt-4">
    <button type="button" class="toggleDetailsBtn w-full bg-gray-200 text-gray-700 py-2 rounded-md font-medium hover:bg-gray-300 transition">
        Editar manualmente endereço
    </button>
</div>

<!-- Campos de endereço (inicialmente ocultos) -->
<div id="addressDetails" class="hidden mt-3 grid grid-cols-1 sm:grid-cols-2 gap-3">
    <div>
        <label class="text-sm mb-1 block">Logradouro</label>
        <input type="text" id="logradouro" name="logradouro" class="w-full p-3 rounded-md bg-white border border-gray-300 focus:border-green-500 focus:ring-1 focus:ring-green-500" placeholder="Rua/Avenida">
    </div>
    <div>
        <label class="text-sm mb-1 block">Número</label>
        <input type="text" id="numero" name="numero" class="w-full p-3 rounded-md bg-white border border-gray-300 focus:border-green-500 focus:ring-1 focus:ring-green-500" placeholder="Número">
    </div>
    <div>
        <label class="text-sm mb-1 block">Bairro</label>
        <input type="text" id="bairro" name="bairro" class="w-full p-3 rounded-md bg-white border border-gray-300 focus:border-green-500 focus:ring-1 focus:ring-green-500" placeholder="Bairro">
    </div>
    <div>
        <label class="text-sm mb-1 block">Complemento</label>
        <input type="text" id="complemento" name="complemento" class="w-full p-3 rounded-md bg-white border border-gray-300 focus:border-green-500 focus:ring-1 focus:ring-green-500" placeholder="Apartamento, bloco, etc.">
    </div>
    <div>
        <label class="text-sm mb-1 block">Cidade</label>
        <input type="text" id="cidade" name="cidade" class="w-full p-3 rounded-md bg-white border border-gray-300 focus:border-green-500 focus:ring-1 focus:ring-green-500" placeholder="Cidade">
    </div>
    <div>
        <label class="text-sm mb-1 block">UF</label>
        <input type="text" id="uf" name="uf" maxlength="2" class="w-full p-3 rounded-md bg-white border border-gray-300 focus:border-green-500 focus:ring-1 focus:ring-green-500" placeholder="UF">
    </div>
</div>

<div class="flex items-center space-x-2 mt-2">
    <input type="checkbox" id="termos" name="termos" required class="peer h-5 w-5 text-green-500 rounded-full border-gray-300 focus:ring-green-500">
    <label for="termos" class="text-sm cursor-pointer peer-checked:text-green-500">
        Eu li e concordo com os
        <a href="<?php echo $PROJECT_ROOT; ?>/assets/images/termos-de-uso.pdf" target="_blank" rel="noopener noreferrer" class="text-green-600 hover:text-green-700 underline">termos de uso</a> *
    </label>
</div>
<div class="flex items-center space-x-2">
    <input type="checkbox" id="privacidade" name="privacidade" required class="peer h-5 w-5 text-green-500 rounded-full border-gray-300 focus:ring-green-500">
    <label for="privacidade" class="text-sm cursor-pointer peer-checked:text-green-500">
        Eu li e concordo com os
        <a href="<?php echo $PROJECT_ROOT; ?>/assets/images/termos-de-consentimento-livre-e-esclarecido.pdf" target="_blank" rel="noopener noreferrer" class="text-green-600 hover:text-green-700 underline">termos de privacidade</a> *
    </label>
</div>

<button type="submit" class="w-full bg-green-500 text-white py-3 rounded-md font-semibold mt-2">Criar conta</button>
</form>
</div>

</div>
</div>

<script>
// Define switchTab no escopo global
window.switchTab = function(tab) {
  var login = document.getElementById('tabLogin');
  var cadastro = document.getElementById('tabCadastro');
  var btnLogin = document.getElementById('tabBtnLogin');
  var btnCadastro = document.getElementById('tabBtnCadastro');

  if (!login || !cadastro || !btnLogin || !btnCadastro) return;

  if (tab === 'login') {
    login.style.display = 'block';
    cadastro.style.display = 'none';

    btnLogin.classList.remove('text-gray-500','border-transparent');
    btnLogin.classList.add('text-green-600','border-green-500');

    btnCadastro.classList.remove('text-green-600','border-green-500');
    btnCadastro.classList.add('text-gray-500','border-transparent');
  } else {
    login.style.display = 'none';
    cadastro.style.display = 'block';

    btnCadastro.classList.remove('text-gray-500','border-transparent');
    btnCadastro.classList.add('text-green-600','border-green-500');

    btnLogin.classList.remove('text-green-600','border-green-500');
    btnLogin.classList.add('text-gray-500','border-transparent');
  }

  // Mantém o parâmetro na URL
  var url = new URL(window.location);
  url.searchParams.set('tab', tab);
  history.replaceState(null, '', url);

  // Recria ícones
  if (window.lucide && typeof lucide.createIcons === 'function') { lucide.createIcons(); }
};

// Inicializa a aba ao carregar (sem PHP dentro do script)
document.addEventListener('DOMContentLoaded', function () {
  var params = new URLSearchParams(window.location.search);
  var initial = params.get('tab') === 'login' ? 'login' : 'cadastro';
  window.switchTab(initial);
  if (window.lucide && typeof lucide.createIcons === 'function') { lucide.createIcons(); }
});
</script>
</script>

<script>
(function() {
  function get(id) { return document.getElementById(id); }

  // Exibir/ocultar documentos do Administrador Público
  function toggleDocs() {
    const perfilSel  = get('perfilSelect');
    const docsWrap   = get('adminPublicoDocs');
    const outrosWrap = get('docOutrosWrap');
    const urlWrap    = get('docFonteUrlWrap');
    if (!perfilSel || !docsWrap) return;

    const isAdminPublico = perfilSel.value === 'admin_publico';
    docsWrap.classList.toggle('hidden', !isAdminPublico);
    docsWrap.style.display = isAdminPublico ? 'block' : 'none';

    if (!isAdminPublico) {
      if (outrosWrap) { outrosWrap.classList.add('hidden'); outrosWrap.style.display = 'none'; }
      if (urlWrap)    { urlWrap.classList.add('hidden');    urlWrap.style.display    = 'none'; }
    }
  }

  // Mostrar campos complementares conforme tipo selecionado
  function handleDocTipoChange() {
    const tipoSel    = get('docTipo');
    const outrosWrap = get('docOutrosWrap');
    const urlWrap    = get('docFonteUrlWrap');
    if (!tipoSel) return;

    const v = tipoSel.value;
    const showOutros = v === 'outros';
    const showUrl    = v === 'publicacao_oficial';

    if (outrosWrap) { outrosWrap.classList.toggle('hidden', !showOutros); outrosWrap.style.display = showOutros ? 'block' : 'none'; }
    if (urlWrap)    { urlWrap.classList.toggle('hidden', !showUrl);       urlWrap.style.display    = showUrl ? 'block' : 'none'; }
  }

  function init() {
    // Estado inicial dos documentos
    toggleDocs();
    handleDocTipoChange();

    // Listeners
    const perfilSel = get('perfilSelect');
    const tipoSel   = get('docTipo');
    const filesInp  = get('docArquivos');

    perfilSel && perfilSel.addEventListener('change', toggleDocs);
    tipoSel   && tipoSel.addEventListener('change', handleDocTipoChange);
    filesInp && filesInp.addEventListener('change', (e) => {
      const files = e.target.files || [];
      if (files.length > 3) {
        alert('Você pode anexar no máximo 3 arquivos.');
        e.target.value = '';
      }
    });

    // Auto-maiuscular UF
    const uf = get('uf');
    uf && uf.addEventListener('input', () => { uf.value = uf.value.toUpperCase().slice(0,2); });

    // Recria ícones
    if (window.lucide && typeof lucide.createIcons === 'function') { lucide.createIcons(); }

    // Validação visual da senha, se os campos existirem
    const senhaInput = get('senhaCadastro');
    if (senhaInput && typeof validatePassword === 'function') {
      validatePassword(senhaInput.value || '');
    }
    if (typeof validatePasswordMatch === 'function') {
      validatePasswordMatch();
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
</script>

<!-- NOVO: Funções globais para olho da senha e validação dinâmica -->
<script>
  // Mostrar/ocultar senha com atualização dos ícones Lucide
  function togglePassword(inputId) {
    const input = document.getElementById(inputId);
    if (!input) return;
    const button = input.nextElementSibling;
    const icon = button?.querySelector('i');

    if (input.type === 'password') {
      input.type = 'text';
      if (icon) icon.setAttribute('data-lucide', 'eye-off');
    } else {
      input.type = 'password';
      if (icon) icon.setAttribute('data-lucide', 'eye');
    }
    if (window.lucide && lucide.createIcons) { lucide.createIcons(); }
  }

  // Utilitário para marcar item da checklist
  function setIndicator(id, ok) {
    const el = document.getElementById(id);
    if (!el) return;
    el.classList.toggle('text-green-600', ok);
    el.classList.toggle('text-red-500', !ok);
    const icon = el.querySelector('i');
    if (icon) icon.setAttribute('data-lucide', ok ? 'check-circle' : 'x-circle');
  }

  // Validação da força da senha (mínimo 6, letra, número, especial)
  function validatePassword(pwd) {
    const hasMin     = typeof pwd === 'string' && pwd.length >= 6;
    const hasLetter  = /[A-Za-z]/.test(pwd || '');
    const hasNumber  = /[0-9]/.test(pwd || '');
    const hasSpecial = /[^A-Za-z0-9]/.test(pwd || '');

    setIndicator('minLength', hasMin);
    setIndicator('hasLetter', hasLetter);
    setIndicator('hasNumber', hasNumber);
    setIndicator('hasSpecial', hasSpecial);

    if (window.lucide && lucide.createIcons) { lucide.createIcons(); }
    // Atualiza também o match entre senha e confirmar
    validatePasswordMatch();
  }

  // Validação de confirmação de senha
  function validatePasswordMatch() {
    const a = document.getElementById('senhaCadastro')?.value || '';
    const b = document.getElementById('confirmSenhaCadastro')?.value || '';
    const el = document.getElementById('passwordMatch');
    if (!el) return;

    const ok = a !== '' && b !== '' && a === b;
    el.classList.toggle('hidden', ok);
    el.classList.toggle('text-red-500', !ok);

    const icon = el.querySelector('i');
    if (icon) icon.setAttribute('data-lucide', ok ? 'check-circle' : 'x-circle');

    if (window.lucide && lucide.createIcons) { lucide.createIcons(); }
  }

  // ===== Cadastro: CEP auto-preenche + toggle edição manual =====
(function() {
  const cepEl = document.getElementById('cep');
  const logradouroEl = document.getElementById('logradouro');
  const numeroEl = document.getElementById('numero');
  const bairroEl = document.getElementById('bairro');
  const complementoEl = document.getElementById('complemento');
  const cidadeEl = document.getElementById('cidade');
  const ufEl = document.getElementById('uf');
  const detailsDiv = document.getElementById('addressDetails');

  // Toggle mostrar/ocultar campos de endereço
  const toggleBtns = document.querySelectorAll('.toggleDetailsBtn');
  if (toggleBtns && detailsDiv) {
    toggleBtns.forEach(btn => {
      btn.addEventListener('click', () => {
        detailsDiv.classList.toggle('hidden');
      });
    });
  }

  // Debounce util
  function debounce(fn, wait) {
    let t = null;
    return (...args) => {
      clearTimeout(t);
      t = setTimeout(() => fn(...args), wait);
    };
  }

  async function fromCepCadastro(rawCep) {
    const cep = String(rawCep || '').replace(/\D/g, '');
    if (cep.length !== 8) return;

    try {
      const resp = await fetch(`https://viacep.com.br/ws/${cep}/json/`);
      const data = await resp.json();
      if (data?.erro) return;

      // Preenche os campos a partir do CEP (pode editar manualmente depois)
      if (logradouroEl) logradouroEl.value = data.logradouro ?? '';
      if (bairroEl) bairroEl.value = data.bairro ?? '';
      if (complementoEl) complementoEl.value = data.complemento ?? '';
      if (cidadeEl) cidadeEl.value = data.localidade ?? '';
      if (ufEl) ufEl.value = data.uf ?? '';
    } catch (e) {
      console.warn('Falha ao consultar ViaCEP', e);
    }
  }

  if (cepEl) {
    const onCepInput = debounce(() => fromCepCadastro(cepEl.value), 300);
    cepEl.addEventListener('input', onCepInput);
    cepEl.addEventListener('blur', () => fromCepCadastro(cepEl.value));
    // Enter também dispara a consulta
    cepEl.addEventListener('keydown', (ev) => {
      if (ev.key === 'Enter') {
        ev.preventDefault();
        fromCepCadastro(cepEl.value);
      }
    });
  }
})();
</script>
</body>
</html>


