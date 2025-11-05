<?php
// Configuração do banco antigo
$old_db = [
    'host' => 'localhost',
    'user' => 'root',
    'pass' => '',
    'name' => 'radci' // nome do banco antigo
];

// Configuração do banco novo
$new_db = [
    'host' => 'localhost',
    'user' => 'root',
    'pass' => '',
    'name' => 'radci_ocorrencias'
];

try {
    // Conecta aos bancos
    $old_pdo = new PDO(
        "mysql:host={$old_db['host']};dbname={$old_db['name']};charset=utf8mb4",
        $old_db['user'],
        $old_db['pass']
    );
    $old_pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $new_pdo = new PDO(
        "mysql:host={$new_db['host']};dbname={$new_db['name']};charset=utf8mb4",
        $new_db['user'],
        $new_db['pass']
    );
    $new_pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 1. Migra usuários
    echo "Migrando usuários...<br>";
    $users = $old_pdo->query("SELECT * FROM usuarios")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($users as $user) {
        $sql = "INSERT INTO usuarios (id, nome, email, senha, tipo, cidade, uf, data_criacao) 
                VALUES (:id, :nome, :email, :senha, :tipo, :cidade, :uf, :data_criacao)";
        $stmt = $new_pdo->prepare($sql);
        $stmt->execute($user);
    }
    echo "✅ " . count($users) . " usuários migrados<br>";

    // 2. Migra pesquisas
    echo "Migrando pesquisas...<br>";
    $surveys = $old_pdo->query("SELECT * FROM pesquisa")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($surveys as $survey) {
        $cols = implode(',', array_keys($survey));
        $vals = ':' . implode(',:', array_keys($survey));
        $sql = "INSERT INTO pesquisa ($cols) VALUES ($vals)";
        $stmt = $new_pdo->prepare($sql);
        $stmt->execute($survey);
    }
    echo "✅ " . count($surveys) . " pesquisas migradas<br>";

    // 3. Migra ocorrências
    echo "Migrando ocorrências...<br>";
    $occurrences = $old_pdo->query("SELECT * FROM ocorrencias")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($occurrences as $occurrence) {
        $cols = implode(',', array_keys($occurrence));
        $vals = ':' . implode(',:', array_keys($occurrence));
        $sql = "INSERT INTO ocorrencias ($cols) VALUES ($vals)";
        $stmt = $new_pdo->prepare($sql);
        $stmt->execute($occurrence);
    }
    echo "✅ " . count($occurrences) . " ocorrências migradas<br>";

    echo "<br>✅ Migração concluída com sucesso!";

} catch (Exception $e) {
    echo "❌ Erro: " . $e->getMessage() . "<br>";
    echo "Stack trace: <pre>" . $e->getTraceAsString() . "</pre>";
}