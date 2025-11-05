<?php
session_start();

require_once __DIR__ . '/../includes/db.php';
$pdo = get_pdo();

$erroLogin = "";
$erroCadastro = "";
$sucessoCadastro = "";

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
            // 10 = Admin → usuarios.php
            if ((int)$user['perfil'] === 10) { $redirect = 'usuarios.php'; }
            // 2 = Prefeito → prefeito_inicio.php
            elseif ((int)$user['perfil'] === 2) { $redirect = 'prefeito_inicio.php'; }
            // 3 = Secretário → secretario.php
            elseif ((int)$user['perfil'] === 3) { $redirect = 'secretario.php'; }
            // 1 = Cidadão → dashboard.php (default)

            header("Location: $redirect");
            exit();
        }
    } elseif ($_POST['acao'] === 'cadastro') {
        $perfilStr       = trim($_POST['perfil'] ?? '');
        $nome            = trim($_POST['nome_completo'] ?? '');
        $email           = trim($_POST['email_cadastro'] ?? '');
        $senha           = trim($_POST['senha_cadastro'] ?? '');
        $confirmarSenha  = trim($_POST['confirmar_senha'] ?? '');
        $termos          = isset($_POST['termos']);
        $privacidade     = isset($_POST['privacidade']);

        // Corrige mapeamento de perfis para alinhamento com o sistema:
        $perfilMap = ['cidadao'=>1, 'prefeito'=>2, 'secretario'=>3, 'admin_radci'=>10];
        $perfil    = $perfilMap[$perfilStr] ?? 1;

        if ($senha !== $confirmarSenha) {
            $erroCadastro = "As senhas não coincidem.";
        } elseif (!$termos || !$privacidade) {
            $erroCadastro = "Você deve aceitar os termos de uso e privacidade.";
        } elseif (strlen($senha) < 6 || !preg_match('/[A-Za-z]/', $senha) || !preg_match('/[0-9]/', $senha) || !preg_match('/[^A-Za-z0-9]/', $senha)) {
            $erroCadastro = "A senha deve conter no mínimo 6 caracteres, incluindo letras, números e caracteres especiais.";
        } else {
            $exists = false;
            try {
                $stmt = $pdo->prepare("SELECT 1 FROM usuarios WHERE email = ? LIMIT 1");
                $stmt->execute([$email]);
                $exists = (bool)$stmt->fetch();
            } catch (Throwable $_) {}

            if ($exists) {
                $erroCadastro = "E-mail já cadastrado.";
            } else {
                // Validação específica quando perfil = Prefeito
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
                            trim($_POST['municipio'] ?? ''),
                            trim($_POST['bairro'] ?? ''),
                            trim($_POST['rua'] ?? ''),
                            trim($_POST['complemento'] ?? ''),
                        ]);
                        $newUserId = (int)$pdo->lastInsertId();

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
                        echo "<script>window.addEventListener('DOMContentLoaded',()=>{switchTab('login');});</script>";
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
<html lang="pt-br">
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
<svg xmlns="http://www.w3.org/2000/svg" class="w-8 h-8 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
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

<!-- Container mobile -->
<div class="md:hidden">
<div class="flex items-center justify-center mb-6 space-x-3">
<div class="bg-green-500 p-3 rounded-xl">
<svg xmlns="http://www.w3.org/2000/svg" class="w-8 h-8 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 11c0 3.866-3.582 7-8 7h16c-4.418 0-8-3.134-8-7z"/>
</svg>
</div>
<div>
<h1 class="text-2xl font-bold">RADCI</h1>
<p class="text-gray-500">Cidade Mais Inteligente</p>
</div>
</div>
</div>

<div class="bg-gray-50 rounded-2xl shadow-lg p-6 sm:p-8 relative">
<div class="flex mb-8 border-b border-gray-300 sticky top-0 bg-gray-50 z-10">
<button id="tabBtnLogin" onclick="switchTab('login')" class="flex-1 py-3 font-semibold text-gray-500 border-b-2 border-transparent">Entrar</button>
<button id="tabBtnCadastro" onclick="switchTab('cadastro')" class="flex-1 py-3 font-semibold border-b-2 border-green-500 text-green-600">Cadastrar</button>
</div>

<!-- LOGIN -->
<div id="tabLogin" style="display:none;">
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
<div id="tabCadastro" style="display:none;">
<?php if($erroCadastro) echo "<p class='text-red-500 mb-2'>$erroCadastro</p>"; ?>
<?php if($sucessoCadastro) echo "<p class='text-green-500 mb-2'>$sucessoCadastro</p>"; ?>
<form method="POST" class="space-y-6" id="formCadastro" enctype="multipart/form-data">
<input type="hidden" name="acao" value="cadastro">

<div>
<label class="text-sm font-medium mb-2 block text-gray-700">Perfil *</label>
<select name="perfil" id="perfilSelect" required class="w-full p-4 rounded-lg bg-white border border-gray-300 focus:border-green-500 focus:ring-2 focus:ring-green-200 text-base transition-colors appearance-none">
  <option value="">Selecione seu perfil</option>
  <option value="cidadao">Cidadão</option>
  <option value="prefeito">Prefeito</option>
  <option value="secretario">Secretário</option>
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

</body>
</html>

<script>


function switchTab(tab) {
    const login = document.getElementById('tabLogin');
    const cadastro = document.getElementById('tabCadastro');
    const btnLogin = document.getElementById('tabBtnLogin');
    const btnCadastro = document.getElementById('tabBtnCadastro');

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

    const url = new URL(window.location);
    url.searchParams.set('tab', tab);
    history.replaceState(null, '', url);
}

document.addEventListener("DOMContentLoaded", function(){
    const params = new URLSearchParams(window.location.search);
    const tab = params.get("tab");
    if (tab === 'login') { 
        switchTab('login'); 
    } else { 
        switchTab('cadastro'); 
    }

    const manualAddress = document.getElementById('manualAddress');
    const enderecos = document.getElementById('enderecos');
    manualAddress && manualAddress.addEventListener('change', function(){
        enderecos && enderecos.classList.toggle('hidden', !this.checked);
    });

    if (window.lucide && lucide.createIcons) { lucide.createIcons(); }
});

// Exibe/oculta seção de documentos do Prefeito sem alterar o layout
const perfilSel = document.getElementById('perfilSelect');
const docsWrap  = document.getElementById('prefeitoDocs');
const tipoSel   = document.getElementById('docTipo');
const outrosWrap= document.getElementById('docOutrosWrap');
const urlWrap   = document.getElementById('docFonteUrlWrap');
const filesInp  = document.getElementById('docArquivos');

function toggleDocs() {
  const isPrefeito = (perfilSel.value === 'prefeito');
  docsWrap.classList.toggle('hidden', !isPrefeito);
  if (!isPrefeito) { outrosWrap.classList.add('hidden'); urlWrap.classList.add('hidden'); }
}
perfilSel.addEventListener('change', toggleDocs);
window.addEventListener('DOMContentLoaded', toggleDocs);

tipoSel?.addEventListener('change', () => {
  const v = tipoSel.value;
  outrosWrap.classList.toggle('hidden', v !== 'outros');
  urlWrap.classList.toggle('hidden', v !== 'publicacao_oficial');
});

filesInp?.addEventListener('change', (e) => {
  const files = e.target.files || [];
  if (files.length > 3) {
    alert('Você pode anexar no máximo 3 arquivos.');
    e.target.value = ''; // limpa seleção
  }
});
</script>
