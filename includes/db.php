<?php
class FakeStatement {
    public function execute($params = []) { return true; }
    public function fetch() { return false; }
    public function fetchAll() { return []; }
    public function fetchColumn() { return 1; }
}

class FakePDO {
    public function query($sql) { return new FakeStatement(); }
    public function prepare($sql) { return new FakeStatement(); }
    public function exec($sql) { return 0; }
}

function get_pdo() {
    // Modo visual: não conecta ao banco
    $disable = getenv('DISABLE_DB') === '1' || ($_ENV['DISABLE_DB'] ?? '') === '1';
    if ($disable) {
        error_log('DB disabled: running in visual-only mode');
        return new FakePDO();
    }

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
        // Força loopback e porta padrão do MySQL (XAMPP)
        $host = '127.0.0.1';
        $port = 3306;
        $user = 'root';
        $pass = '';
        $db   = 'radci';
    } else {
        $host = getenv('DB_HOST') ?: $_ENV['DB_HOST'] ?? 'localhost';
        $port = intval(getenv('DB_PORT') ?: $_ENV['DB_PORT'] ?? 3306);
        $user = getenv('DB_USER') ?: $_ENV['DB_USER'] ?? '';
        $pass = getenv('DB_PASS') ?: $_ENV['DB_PASS'] ?? '';
        $db   = getenv('DB_NAME') ?: $_ENV['DB_NAME'] ?? '';

        error_log("DB Connection Info - Host: $host:$port, User: $user, Database: $db");

        // Se envs faltarem, entra no modo visual
        if (empty($host) || empty($user) || empty($db)) {
            error_log('Database env missing: switching to visual-only mode');
            return new FakePDO();
        }
    }

    try {
        $dsn = "mysql:host=$host;port=" . ($port ?? 3306) . ";dbname=$db;charset=utf8mb4";
        error_log("Attempting database connection: $dsn");

        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => 5,
        ]);

        error_log("Database connection established successfully");
        return $pdo;
    } catch (PDOException $e) {
        error_log("Database connection error: " . $e->getMessage() . " — switching to visual-only mode");
        // Em caso de erro, não derruba a página; volta FakePDO
        return new FakePDO();
    }
}
