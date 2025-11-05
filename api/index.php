<?php
// api/index.php — ponto de entrada da aplicação RADCI

error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();

if (isset($_SESSION['usuario_id']) && $_SESSION['usuario_id'] > 0) {
    $perfil = $_SESSION['usuario_perfil'] ?? 1;

    switch ($perfil) {
        case 10: // Admin
            header("Location: usuarios.php");
            break;
        case 2: // Prefeito
            header("Location: prefeito_inicio.php");
            break;
        case 3: // Secretário
            header("Location: secretario.php");
            break;
        default: // 1=Cidadão
            header("Location: dashboard.php");
            break;
    }
    exit();
}

// Se não está logado, vai para login
header("Location: login_cadastro.php");
exit();
