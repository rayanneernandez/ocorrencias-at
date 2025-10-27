<?php
function get_pdo() {
    $host_name = $_SERVER['HTTP_HOST'] ?? '';
    $server_name = $_SERVER['SERVER_NAME'] ?? '';

    $is_local = (
        $host_name === 'localhost' ||
        $host_name === '127.0.0.1' ||
        strpos($host_name, 'localhost:') === 0 ||
        strpos($host_name, '127.0.0.1:') === 0 ||
        $server_name === 'localhost' ||
        $server_name === '127.0.0.1'
    );

    if ($is_local) {
        $host = 'localhost';
        $user = 'root';
        $pass = '';
        $db   = 'radci';
    } else {
        $host = $_ENV['DB_HOST'] ?? 'localhost';
        $user = $_ENV['DB_USER'] ?? '';
        $pass = $_ENV['DB_PASS'] ?? '';
        $db   = $_ENV['DB_NAME'] ?? '';
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
