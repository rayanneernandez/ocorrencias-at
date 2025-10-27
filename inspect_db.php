<?php
require_once __DIR__ . '/includes/db.php';

try {
    $pdo = get_pdo();
    
    echo "<h2>Estrutura Completa do Banco de Dados</h2>";
    
    // Listar todas as tabelas
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    
    foreach ($tables as $table) {
        echo "<h3>Tabela: $table</h3>";
        
        // Mostrar estrutura da tabela
        $columns = $pdo->query("DESCRIBE $table")->fetchAll(PDO::FETCH_ASSOC);
        
        echo "<table border='1' style='border-collapse: collapse; margin-bottom: 20px;'>";
        echo "<tr><th>Campo</th><th>Tipo</th><th>Nulo</th><th>Chave</th><th>Padrão</th><th>Extra</th></tr>";
        
        foreach ($columns as $column) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($column['Field']) . "</td>";
            echo "<td>" . htmlspecialchars($column['Type']) . "</td>";
            echo "<td>" . htmlspecialchars($column['Null']) . "</td>";
            echo "<td>" . htmlspecialchars($column['Key']) . "</td>";
            echo "<td>" . htmlspecialchars($column['Default'] ?? 'NULL') . "</td>";
            echo "<td>" . htmlspecialchars($column['Extra']) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
        
        // Mostrar alguns dados de exemplo (primeiras 3 linhas)
        try {
            $sample = $pdo->query("SELECT * FROM $table LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($sample)) {
                echo "<h4>Dados de Exemplo:</h4>";
                echo "<pre>" . print_r($sample, true) . "</pre>";
            }
        } catch (Exception $e) {
            echo "<p>Erro ao buscar dados de exemplo: " . $e->getMessage() . "</p>";
        }
        
        echo "<hr>";
    }
    
} catch (Exception $e) {
    echo "Erro: " . $e->getMessage();
}
?>