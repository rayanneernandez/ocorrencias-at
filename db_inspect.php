<?php
require_once __DIR__ . '/includes/db.php';

try {
    $pdo = get_pdo();

    echo "<!DOCTYPE html>
<html lang='pt-BR'>
<head>
  <meta charset='UTF-8'>
  <title>📊 Estrutura Completa do Banco RADCI</title>
  <style>
    body { font-family: Arial, sans-serif; margin: 30px; background: #fafafa; color: #333; }
    h1, h2, h3 { color: #2c5aa0; }
    table { border-collapse: collapse; width: 100%; margin: 20px 0; background: #fff; }
    th, td { border: 1px solid #ddd; padding: 8px; text-align: left; font-size: 14px; }
    th { background-color: #f2f2f2; }
    .table-name { font-weight: bold; font-size: 18px; margin-top: 40px; }
    .code-block { background: #f8f9fa; padding: 15px; border-radius: 6px; margin: 20px 0; border: 1px solid #ccc; }
    button { padding: 8px 12px; background: #2c5aa0; color: white; border: none; border-radius: 4px; cursor: pointer; }
    button:hover { background: #1e3e70; }
    hr { margin: 40px 0; border: none; border-top: 2px dashed #ccc; }
  </style>
</head>
<body>
<h1>📊 Estrutura Completa do Banco de Dados RADCI</h1>
";

    // Listar todas as tabelas
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

    if (!$tables) {
        echo "<p>Nenhuma tabela encontrada no banco de dados.</p>";
        exit;
    }

    echo "<h2>Tabelas encontradas: " . count($tables) . "</h2>";

    $mapping = []; // vai guardar toda a estrutura do banco

    foreach ($tables as $table) {
        echo "<div class='table-name'>📁 Tabela: <b>$table</b></div>";

        // Obter estrutura da tabela
        $columns = $pdo->query("DESCRIBE `$table`")->fetchAll(PDO::FETCH_ASSOC);
        $mapping[$table] = $columns;

        echo "<table>";
        echo "<tr><th>Campo</th><th>Tipo</th><th>Nulo</th><th>Chave</th><th>Padrão</th><th>Extra</th></tr>";

        foreach ($columns as $col) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($col['Field']) . "</td>";
            echo "<td>" . htmlspecialchars($col['Type']) . "</td>";
            echo "<td>" . htmlspecialchars($col['Null']) . "</td>";
            echo "<td>" . htmlspecialchars($col['Key']) . "</td>";
            echo "<td>" . htmlspecialchars($col['Default'] ?? 'NULL') . "</td>";
            echo "<td>" . htmlspecialchars($col['Extra']) . "</td>";
            echo "</tr>";
        }
        echo "</table>";

        // Mostrar exemplos
        try {
            $sample = $pdo->query("SELECT * FROM `$table` LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($sample)) {
                echo "<h4>🧩 Dados de exemplo:</h4>";
                echo "<table><tr>";
                foreach (array_keys($sample[0]) as $key) echo "<th>" . htmlspecialchars($key) . "</th>";
                echo "</tr>";
                foreach ($sample as $row) {
                    echo "<tr>";
                    foreach ($row as $value) {
                        $val = htmlspecialchars(strlen($value) > 50 ? substr($value, 0, 50) . '...' : $value);
                        echo "<td>$val</td>";
                    }
                    echo "</tr>";
                }
                echo "</table>";
            }
        } catch (Exception $e) {
            echo "<p><b>Erro ao buscar dados:</b> " . htmlspecialchars($e->getMessage()) . "</p>";
        }

        echo "<hr>";
    }

    // Gerar o código PHP de mapeamento
    $dbName = $pdo->query("SELECT DATABASE()")->fetchColumn();

    echo "<div class='code-block'>";
    echo "<h3>📜 Código PHP - Mapeamento Automático do Banco '$dbName'</h3>";
    echo "<pre id='schema-code'>";
    echo "&lt;?php\n";
    echo "// Mapeamento automático do banco '$dbName'\n";
    echo "\$db_schema = [\n";

    foreach ($mapping as $table => $cols) {
        echo "    '$table' => [\n";
        foreach ($cols as $c) {
            $nullable = $c['Null'] === 'YES' ? 'true' : 'false';
            echo "        '" . $c['Field'] . "' => ['type' => '" . $c['Type'] . "', 'nullable' => $nullable, 'key' => '" . $c['Key'] . "', 'extra' => '" . $c['Extra'] . "'],\n";
        }
        echo "    ],\n";
    }

    echo "];\n";
    echo "?&gt;";
    echo "</pre>";
    echo "<button onclick=\"copyToClipboard(document.getElementById('schema-code').innerText)\">📋 Copiar Mapeamento</button>";
    echo "</div>";

    echo "<script>
    function copyToClipboard(text) {
      navigator.clipboard.writeText(text).then(() => {
        alert('Mapeamento copiado com sucesso!');
      });
    }
    </script>";

    echo "</body></html>";

} catch (Exception $e) {
    echo "<h1>❌ Erro ao conectar com o banco de dados</h1>";
    echo "<p>Erro: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p>Verifique se o XAMPP está rodando e se as configurações do banco estão corretas.</p>";
}
?>
