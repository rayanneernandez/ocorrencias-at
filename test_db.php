<?php
// Arquivo de teste para verificar o banco de dados
require_once 'includes/db.php';

try {
    $pdo = get_pdo();
    echo "✅ Conexão com banco estabelecida com sucesso!<br>";
    
    // Verifica se a tabela ocorrencias existe
    $checkTable = $pdo->query("SHOW TABLES LIKE 'ocorrencias'");
    $tableExists = $checkTable->rowCount() > 0;
    
    if ($tableExists) {
        echo "✅ Tabela 'ocorrencias' existe<br>";
        
        // Mostra estrutura da tabela
        $describe = $pdo->query("DESCRIBE ocorrencias");
        $columns = $describe->fetchAll();
        
        echo "<h3>Estrutura da tabela 'ocorrencias':</h3>";
        echo "<table border='1' style='border-collapse: collapse;'>";
        echo "<tr><th>Campo</th><th>Tipo</th><th>Nulo</th><th>Chave</th><th>Padrão</th><th>Extra</th></tr>";
        foreach ($columns as $column) {
            echo "<tr>";
            echo "<td>{$column['Field']}</td>";
            echo "<td>{$column['Type']}</td>";
            echo "<td>{$column['Null']}</td>";
            echo "<td>{$column['Key']}</td>";
            echo "<td>{$column['Default']}</td>";
            echo "<td>{$column['Extra']}</td>";
            echo "</tr>";
        }
        echo "</table>";
        
        // Conta registros
        $count = $pdo->query("SELECT COUNT(*) FROM ocorrencias")->fetchColumn();
        echo "<br>📊 Total de registros na tabela: $count<br>";
        
        // Mostra últimos 5 registros se existirem
        if ($count > 0) {
            $recent = $pdo->query("SELECT * FROM ocorrencias ORDER BY data_criacao DESC LIMIT 5")->fetchAll();
            echo "<h3>Últimos 5 registros:</h3>";
            echo "<pre>" . print_r($recent, true) . "</pre>";
        }
        
    } else {
        echo "❌ Tabela 'ocorrencias' NÃO existe<br>";
        
        // Tenta criar a tabela
        echo "🔧 Tentando criar tabela...<br>";
        $createSQL = "CREATE TABLE IF NOT EXISTS ocorrencias (
            id INT AUTO_INCREMENT PRIMARY KEY,
            numero VARCHAR(20) UNIQUE NOT NULL,
            usuario_id INT NOT NULL,
            endereco VARCHAR(255),
            cep VARCHAR(10),
            tipo VARCHAR(100),
            descricao TEXT,
            latitude DECIMAL(10, 8),
            longitude DECIMAL(11, 8),
            arquivos JSON,
            tem_imagens ENUM('Sim', 'Não') DEFAULT 'Não',
            status VARCHAR(50) DEFAULT 'Em Análise',
            data_criacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_usuario (usuario_id),
            INDEX idx_data (data_criacao),
            INDEX idx_numero (numero)
        )";
        
        $result = $pdo->exec($createSQL);
        if ($result !== false) {
            echo "✅ Tabela criada com sucesso!<br>";
        } else {
            echo "❌ Erro ao criar tabela<br>";
        }
    }
    
    // Verifica outras tabelas importantes
    echo "<h3>Outras tabelas no banco:</h3>";
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $table) {
        $count = $pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
        echo "📋 $table: $count registros<br>";
    }
    
} catch (Exception $e) {
    echo "❌ Erro: " . $e->getMessage() . "<br>";
    echo "Stack trace: <pre>" . $e->getTraceAsString() . "</pre>";
}
?>