<?php
// Arquivo de entrada principal da aplicação RADCI
// Redireciona para a página de login/cadastro

session_start();

// Se o usuário já está logado, redireciona para o dashboard
if (isset($_SESSION['usuario_id']) && $_SESSION['usuario_id'] > 0) {
    $perfil = $_SESSION['usuario_perfil'] ?? 1;
    
    // Redireciona baseado no perfil do usuário
    switch ($perfil) {
        case 2: // Admin RADCI
            header("Location: pages/usuarios.php");
            break;
        case 3: // Admin Público
        case 4: // Secretário
            header("Location: pages/relatorios.php");
            break;
        default: // Cidadão
            header("Location: pages/dashboard.php");
            break;
    }
    exit();
}

//# Se não está logado, redireciona para login
header("Location: pages/login_cadastro.php");
exit();
?>