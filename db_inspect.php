<?php
require_once __DIR__ . '/includes/db.php';

try {
    $pdo = get_pdo();
    
    echo "<h1>Estrutura Completa do Banco de Dados RADCI</h1>\n";
    echo "<style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        table { border-collapse: collapse; width: 100%; margin: 20px 0; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
        .table-name { color: #2c5aa0; font-weight: bold; font-size: 18px; margin-top: 30px; }
        .code-block { background: #f8f9fa; padding: 15px; border-radius: 5px; margin: 10px 0; }
    </style>\n";
    
    // Listar todas as tabelas
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    
    echo "<h2>Tabelas encontradas: " . count($tables) . "</h2>\n";
    
    foreach ($tables as $table) {
        echo "<div class='table-name'>Tabela: $table</div>\n";
        
        // Obter estrutura da tabela
        $columns = $pdo->query("DESCRIBE $table")->fetchAll(PDO::FETCH_ASSOC);
        
        echo "<table>\n";
        echo "<tr><th>Campo</th><th>Tipo</th><th>Nulo</th><th>Chave</th><th>Padrão</th><th>Extra</th></tr>\n";
        
        foreach ($columns as $column) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($column['Field']) . "</td>";
            echo "<td>" . htmlspecialchars($column['Type']) . "</td>";
            echo "<td>" . htmlspecialchars($column['Null']) . "</td>";
            echo "<td>" . htmlspecialchars($column['Key']) . "</td>";
            echo "<td>" . htmlspecialchars($column['Default'] ?? 'NULL') . "</td>";
            echo "<td>" . htmlspecialchars($column['Extra']) . "</td>";
            echo "</tr>\n";
        }
        
        echo "</table>\n";
        
        // Mostrar alguns dados de exemplo (primeiros 3 registros)
        try {
            $sample = $pdo->query("SELECT * FROM $table LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($sample)) {
                echo "<h4>Dados de exemplo:</h4>\n";
                echo "<table>\n";
                
                // Cabeçalho
                echo "<tr>";
                foreach (array_keys($sample[0]) as $key) {
                    echo "<th>" . htmlspecialchars($key) . "</th>";
                }
                echo "</tr>\n";
                
                // Dados
                foreach ($sample as $row) {
                    echo "<tr>";
                    foreach ($row as $value) {
                        $displayValue = $value;
                        if (strlen($displayValue) > 50) {
                            $displayValue = substr($displayValue, 0, 50) . '...';
                        }
                        echo "<td>" . htmlspecialchars($displayValue ?? 'NULL') . "</td>";
                    }
                    echo "</tr>\n";
                }
                
                echo "</table>\n";
            }
        } catch (Exception $e) {
            echo "<p>Erro ao buscar dados de exemplo: " . htmlspecialchars($e->getMessage()) . "</p>\n";
        }
        
        echo "<hr>\n";
    }
    
    // Código PHP para copiar
    echo "<div class='code-block'>";
    echo "<h3>Código PHP - Estrutura das Tabelas:</h3>";
    echo "<pre>";
    echo "<?php\n";
    echo "// Estrutura das tabelas do banco RADCI\n";
    echo "\$database_structure = [\n";
    
    foreach ($tables as $table) {
        $columns = $pdo->query("DESCRIBE $table")->fetchAll(PDO::FETCH_ASSOC);
        echo "    '$table' => [\n";
        foreach ($columns as $column) {
            echo "        '" . $column['Field'] . "' => '" . $column['Type'] . "',\n";
        }
        echo "    ],\n";
    }
    
    echo "];\n";
    echo "?>";
    echo "</pre>";
    echo "</div>";
    
} catch (Exception $e) {
    echo "<h1>Erro ao conectar com o banco de dados</h1>";
    echo "<p>Erro: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p>Verifique se o XAMPP está rodando e se as configurações do banco estão corretas.</p>";
}
?>
echo "// Mapeamento automático do banco '$dbName'\n";
echo "\$db_schema = [\n";
foreach ($mapping as $table => $columns) {
    echo "    '$table' => [\n";
    foreach ($columns as $col) {
        $name = $col['COLUMN_NAME'];
        $type = $col['COLUMN_TYPE'];
        $nullable = $col['IS_NULLABLE'] === 'YES' ? 'true' : 'false';
        $key = $col['COLUMN_KEY'];
        $extra = $col['EXTRA'];
        echo "        '$name' => ['type' => '$type', 'nullable' => $nullable, 'key' => '$key', 'extra' => '$extra'],\n";
    }
    echo "    ],\n";
}
echo "];\n";
echo "?>";
    ?></pre>
    <button class="copy-btn" onclick="copyToClipboard(this.previousElementSibling.textContent)">Copiar Mapeamento</button>
  </div>

  <!-- Visualização das tabelas -->
  <?php if (!$tables): ?>
    <p class="empty">Nenhuma tabela encontrada.</p>
  <?php else: ?>
    <?php foreach ($tables as $t): ?>
      <div class="table">
        <div class="name">📋 Tabela: <?= htmlspecialchars($t) ?> (<?= count($mapping[$t]) ?> colunas)</div>
        <table>
          <thead>
            <tr>
              <th>Coluna</th><th>Tipo</th><th>Nulo</th><th>Default</th><th>Chave</th><th>Extra</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($mapping[$t] as $c): ?>
              <tr>
                <td><strong><?= htmlspecialchars($c['COLUMN_NAME']) ?></strong></td>
                <td><?= htmlspecialchars($c['COLUMN_TYPE']) ?></td>
                <td><?= htmlspecialchars($c['IS_NULLABLE']) ?></td>
                <td><?= htmlspecialchars($c['COLUMN_DEFAULT'] ?? 'NULL') ?></td>
                <td><?= htmlspecialchars($c['COLUMN_KEY']) ?></td>
                <td><?= htmlspecialchars($c['EXTRA']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>

  <script>
    function copyToClipboard(text) {
      navigator.clipboard.writeText(text).then(() => {
        alert('Mapeamento copiado! Cole no chat para o assistente.');
      });
    }
  </script>
</body>
</html>