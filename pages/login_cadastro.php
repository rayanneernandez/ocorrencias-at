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
            // Compatibilidade: aceita hash (password_verify) e texto puro
            if (password_verify($senha, $user['senha'])) {
                $ok = true;
            } elseif ($user['senha'] === $senha) {
                $ok = true;
            }
        }

        if (!$ok) {
            $erroLogin = "E-mail ou senha inválidos.";
        } else {
            $_SESSION['usuario_id']     = (int)$user['id'];
            $_SESSION['usuario_nome']   = $user['nome'];
            $_SESSION['usuario_perfil'] = (int)$user['perfil'];

            // 1=cidadão → dashboard; 2=admin RADCI → usuarios; 3=admin público → relatorios; 4=secretário → relatorios
            $redirect = 'dashboard.php';
            if ((int)$user['perfil'] === 2) { $redirect = 'usuarios.php'; }
            elseif ((int)$user['perfil'] === 3) { $redirect = 'relatorios.php'; }
            elseif ((int)$user['perfil'] === 4) { $redirect = 'relatorios.php'; }

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

        // Map de perfis para TINYINT
        $perfilMap = ['cidadao'=>1, 'admin_radci'=>2, 'admin_publico'=>3, 'secretario'=>4];
        $perfil    = $perfilMap[$perfilStr] ?? 1;

        if ($senha !== $confirmarSenha) {
            $erroCadastro = "As senhas não coincidem.";
        } elseif (!$termos || !$privacidade) {
            $erroCadastro = "Você deve aceitar os termos de uso e privacidade.";
        } else {
            // Checa duplicidade de email
            $exists = false;
            try {
                $stmt = $pdo->prepare("SELECT 1 FROM usuarios WHERE email = ? LIMIT 1");
                $stmt->execute([$email]);
                $exists = (bool)$stmt->fetch();
            } catch (Throwable $_) {}

            if ($exists) {
                $erroCadastro = "E-mail já cadastrado.";
            } else {
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
                    $sucessoCadastro = "Cadastro realizado com sucesso! Agora faça login.";
                    // Opcional: já posiciona a aba Login
                    echo "<script>window.addEventListener('DOMContentLoaded',()=>{switchTab('login');});</script>";
                } catch (Throwable $e) {
                    $erroCadastro = "Falha ao cadastrar. Verifique os dados.";
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
    <style>
        <?php 
        $cssPath = __DIR__ . '/../assets/css/style.css';
        if (file_exists($cssPath)) {
            echo file_get_contents($cssPath);
        }
        ?>
        .hide-scrollbar::-webkit-scrollbar { display: none; }
        .hide-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
    </style>
</head>
<body class="min-h-screen flex flex-col items-center justify-center bg-white text-gray-900">

<!-- Botão Voltar -->
<div class="mb-4 self-start w-full max-w-md px-6">
    <a href="principal.php" class="inline-block px-3 py-1 border border-green-500 text-green-500 rounded-md hover:bg-green-500 hover:text-white transition">← Voltar</a>
</div>

<div class="w-full max-w-md">
    <!-- Logo -->
    <div class="flex items-center justify-center mb-4 space-x-3">
        <div class="bg-green-500 p-2 rounded-xl">
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
        <!-- Tabs -->
        <div class="flex mb-6 border-b border-gray-300">
            <button id="tabBtnLogin" onclick="switchTab('login')" class="flex-1 py-2 font-semibold text-gray-500 border-b-2 border-transparent">Entrar</button>
            <button id="tabBtnCadastro" onclick="switchTab('cadastro')" class="flex-1 py-2 font-semibold border-b-2 border-green-500 text-green-600">Cadastrar</button>
        </div>

        <!-- Login -->
        <div id="tabLogin" style="display:none;">
            <?php if($erroLogin) echo "<p class='text-red-500 mb-2'>$erroLogin</p>"; ?>
            <form method="POST" class="space-y-4">
                <input type="hidden" name="acao" value="login">
                <div>
                    <label class="text-sm mb-1 block">E-mail</label>
                    <input type="email" name="login_email" required class="w-full p-3 rounded-md bg-white border border-gray-300 focus:border-green-500 focus:ring-1 focus:ring-green-500">
                </div>
                <div>
                    <label class="text-sm mb-1 block">Senha</label>
                    <div class="relative">
                        <input type="password" id="loginSenha" name="login_senha" required class="w-full p-3 rounded-md bg-white border border-gray-300 focus:border-green-500 focus:ring-1 focus:ring-green-500 pr-10">
                        <button type="button" onclick="togglePassword('loginSenha')" class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-700">👁</button>
                    </div>
                </div>
                <div class="flex justify-end text-sm">
                    <a href="esqueceu_senha.php" class="text-green-500 hover:underline">Esqueceu sua senha?</a>
                </div>
                <button type="submit" class="w-full bg-green-500 text-white py-3 rounded-md font-semibold">Entrar</button>
            </form>
        </div>

        <!-- Cadastro -->
        <div id="tabCadastro" style="display:none;">
            <?php if($erroCadastro) echo "<p class='text-red-500 mb-2'>$erroCadastro</p>"; ?>
            <?php if($sucessoCadastro) echo "<p class='text-green-500 mb-2'>$sucessoCadastro</p>"; ?>
            <form method="POST" class="space-y-4" id="formCadastro">
                <input type="hidden" name="acao" value="cadastro">

                <div>
                    <label class="text-sm mb-1 block">Perfil *</label>
                    <select name="perfil" required class="w-full p-3 rounded-md bg-white border border-gray-300 focus:border-green-500 focus:ring-1 focus:ring-green-500">
                        <option value="">Selecione seu perfil</option>
                        <option value="cidadao">Cidadão</option>
                        <option value="admin_publico">Administrador Público</option>
                    </select>
                </div>

                <div>
                    <label class="text-sm mb-1 block">Nome Completo *</label>
                    <input type="text" name="nome_completo" required class="w-full p-3 rounded-md bg-white border border-gray-300 focus:border-green-500 focus:ring-1 focus:ring-green-500">
                </div>

                <div>
                    <label class="text-sm mb-1 block">E-mail Institucional *</label>
                    <input type="email" name="email_cadastro" required class="w-full p-3 rounded-md bg-white border border-gray-300 focus:border-green-500 focus:ring-1 focus:ring-green-500">
                </div>

                <div>
                    <label class="text-sm mb-1 block">Senha *</label>
                    <div class="relative">
                        <input type="password" id="senhaCadastro" name="senha_cadastro" required class="w-full p-3 rounded-md bg-white border border-gray-300 focus:border-green-500 focus:ring-1 focus:ring-green-500 pr-10">
                        <button type="button" onclick="togglePassword('senhaCadastro')" class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-700">👁</button>
                    </div>
                </div>

                <div>
                    <label class="text-sm mb-1 block">Confirmar Senha *</label>
                    <div class="relative">
                        <input type="password" id="confirmSenhaCadastro" name="confirmar_senha" required class="w-full p-3 rounded-md bg-white border border-gray-300 focus:border-green-500 focus:ring-1 focus:ring-green-500 pr-10">
                        <button type="button" onclick="togglePassword('confirmSenhaCadastro')" class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-700">👁</button>
                    </div>
                </div>

                <div>
                    <label class="text-sm mb-1 block">CEP</label>
                    <input type="text" id="cep" name="cep" maxlength="8" placeholder="00000000" class="w-full p-3 rounded-md bg-white border border-gray-300 focus:border-green-500 focus:ring-1 focus:ring-green-500">
                </div>

                <div class="flex items-center space-x-2 mt-2">
                    <input type="checkbox" id="manualAddress" class="peer h-5 w-5 text-green-500 rounded-full border-gray-300 focus:ring-green-500">
                    <label for="manualAddress" class="text-sm cursor-pointer peer-checked:text-green-500">Preencher endereço manualmente</label>
                </div>

                <div id="enderecos" class="space-y-2 mt-2 hidden">
                    <input type="text" id="uf" name="uf" placeholder="UF" class="w-full p-3 rounded-md bg-white border border-gray-300 focus:border-green-500 focus:ring-1 focus:ring-green-500">
                    <input type="text" id="municipio" name="municipio" placeholder="Município" class="w-full p-3 rounded-md bg-white border border-gray-300 focus:border-green-500 focus:ring-1 focus:ring-green-500">
                    <input type="text" id="rua" name="rua" placeholder="Rua" class="w-full p-3 rounded-md bg-white border border-gray-300 focus:border-green-500 focus:ring-1 focus:ring-green-500">
                    <input type="text" id="bairro" name="bairro" placeholder="Bairro" class="w-full p-3 rounded-md bg-white border border-gray-300 focus:border-green-500 focus:ring-1 focus:ring-green-500">
                    <input type="text" id="complemento" name="complemento" placeholder="Complemento" class="w-full p-3 rounded-md bg-white border border-gray-300 focus:border-green-500 focus:ring-1 focus:ring-green-500">
                </div>

                <div class="flex items-center space-x-2 mt-2">
                    <input type="checkbox" name="termos" required class="peer h-5 w-5 text-green-500 rounded-full border-gray-300 focus:ring-green-500">
                    <label class="text-sm cursor-pointer peer-checked:text-green-500">Eu li e concordo com os termos de uso *</label>
                </div>
                <div class="flex items-center space-x-2">
                    <input type="checkbox" name="privacidade" required class="peer h-5 w-5 text-green-500 rounded-full border-gray-300 focus:ring-green-500">
                    <label class="text-sm cursor-pointer peer-checked:text-green-500">Eu li e concordo com os termos de privacidade *</label>
                </div>

                <button type="submit" class="w-full bg-green-500 text-white py-3 rounded-md font-semibold mt-2">Criar conta</button>
            </form>
        </div>
    </div>
</div>

<script>
function switchTab(tab){
    if(tab==='login'){
        document.getElementById('tabLogin').style.display='block';
        document.getElementById('tabCadastro').style.display='none';
        document.getElementById('tabBtnLogin').classList.add('border-green-500','text-green-600');
        document.getElementById('tabBtnCadastro').classList.remove('border-green-500','text-green-600');
    } else {
        document.getElementById('tabLogin').style.display='none';
        document.getElementById('tabCadastro').style.display='block';
        document.getElementById('tabBtnLogin').classList.remove('border-green-500','text-green-600');
        document.getElementById('tabBtnCadastro').classList.add('border-green-500','text-green-600');
    }
}

function togglePassword(id){
    const input=document.getElementById(id);
    input.type = input.type === 'password' ? 'text' : 'password';
}

document.getElementById('manualAddress').addEventListener('change', function(){
    const end = document.getElementById('enderecos');
    end.classList.toggle('hidden');
});

document.getElementById('cep').addEventListener('blur', function(){
    const cep = this.value.replace(/\D/g,'');
    if(cep.length===8){
        fetch(`https://viacep.com.br/ws/${cep}/json/`)
        .then(res=>res.json())
        .then(data=>{
            if(!data.erro){
                document.getElementById('uf').value = data.uf;
                document.getElementById('municipio').value = data.localidade;
                document.getElementById('rua').value = data.logradouro;
                document.getElementById('bairro').value = data.bairro;
                document.getElementById('enderecos').classList.remove('hidden');
            }
        });
    }
});

        // Abre aba correta via URL
        document.addEventListener("DOMContentLoaded", function() {
            const params = new URLSearchParams(window.location.search);
            const tab = params.get("tab");
            if (tab === "login") {
                switchTab("login");
            } else if (tab === "cadastro") {
                switchTab("cadastro");
            } else {
                switchTab("login"); // padrão
            }
        });
</script>





</body>
</html>
