<?php
function get_pdo() {
    $host_name = $_SERVER['HTTP_HOST'] ?? '';
    $server_name = $_SERVER['SERVER_NAME'] ?? '';
    
    // Detecta ambiente local (XAMPP, WAMP, etc.)
    $is_local = (
        $host_name === 'localhost' || 
        $host_name === '127.0.0.1' || 
        strpos($host_name, 'localhost:') === 0 ||
        strpos($host_name, '127.0.0.1:') === 0 ||
        $server_name === 'localhost' ||
        $server_name === '127.0.0.1'
    );

    if ($is_local) {
        // Ambiente local (XAMPP/WAMP/MAMP)
        $host = 'localhost';
        $user = 'root';
        $pass = '';
        $db   = 'radci';  // ← Seu banco de testes local
    } else {
        // Ambiente de produção (hospedagem)
        $host = 'localhost';
        $user = 'u603491934_radci';
        $pass = 'CkJ|Bhma6[hM';
        $db   = 'u603491934_radci';
    }

    try {
        $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        return $pdo;
    } catch (PDOException $e) {
        die("Erro na conexão com '$db': " . $e->getMessage());
    }
}