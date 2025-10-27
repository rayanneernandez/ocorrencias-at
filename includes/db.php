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
        $host = getenv('DB_HOST') ?: $_ENV['DB_HOST'] ?? 'localhost';
        $user = getenv('DB_USER') ?: $_ENV['DB_USER'] ?? '';
        $pass = getenv('DB_PASS') ?: $_ENV['DB_PASS'] ?? '';
        $db   = getenv('DB_NAME') ?: $_ENV['DB_NAME'] ?? '';

        error_log("DB Connection Info - Host: $host, User: $user, Database: $db");
        
        if (empty($host) || empty($user) || empty($db)) {
            error_log('Database environment variables not properly configured');
            die('Erro: Configuração do banco de dados incompleta');
        }
    }

    try {
        $dsn = "mysql:host=$host;dbname=$db;charset=utf8mb4";
        error_log("Attempting database connection: $dsn");
        
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        
        error_log("Database connection established successfully");
        return $pdo;
    } catch (PDOException $e) {
        error_log("Database connection error: " . $e->getMessage());
        die("Erro na conexão com o banco de dados: " . $e->getMessage());
    }
}
