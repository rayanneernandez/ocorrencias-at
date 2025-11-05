<?php
// api/index.php — ponto de entrada da aplicação RADCI

// Controle de erros e buffer (evita 'headers already sent')
$host = $_SERVER['HTTP_HOST'] ?? '';
$is_local = (
    $host === 'localhost' ||
    $host === '127.0.0.1' ||
    strpos($host, 'localhost:') === 0 ||
    strpos($host, '127.0.0.1:') === 0
);

error_reporting(E_ALL);
ini_set('display_errors', $is_local ? '1' : '0');

// Inicia buffer para impedir envio acidental de saída antes dos headers
if (!headers_sent()) {
    ob_start();
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Helper para redirecionar com limpeza do buffer
function redirect($target) {
    if (ob_get_length()) { ob_clean(); }
    header("Location: $target");
    exit();
}

if (isset($_SESSION['usuario_id']) && $_SESSION['usuario_id'] > 0) {
    $perfil = $_SESSION['usuario_perfil'] ?? 1;

    switch ($perfil) {
        case 10: // Admin
            redirect("usuarios.php");
            break;
        case 2: // Prefeito
            redirect("prefeito_inicio.php");
            break;
        case 3: // Secretário
            redirect("secretario.php");
            break;
        default: // 1=Cidadão
            redirect("dashboard.php");
            break;
    }
}

// Se não está logado, vai para login
redirect("login_cadastro.php");
exit();
