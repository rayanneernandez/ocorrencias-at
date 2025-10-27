<?php
// api/index.php — ponto de entrada da aplicação RADCI

error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();

// Código de debug removido para evitar o erro "headers already sent"

if (isset($_SESSION['usuario_id']) && $_SESSION['usuario_id'] > 0) {
    $perfil = $_SESSION['usuario_perfil'] ?? 1;

    switch ($perfil) {
        case 2: // Admin RADCI
            header("Location: usuarios.php");
            break;
        case 3: // Admin Público
        case 4: // Secretário
            header("Location: relatorios.php");
            break;
        default: // Cidadão
            header("Location: dashboard.php");
            break;
    }
    exit();
}

// Se não está logado, vai para login
header("Location: login_cadastro.php");
exit();
