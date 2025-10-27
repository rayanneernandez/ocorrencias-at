<?php
session_start();
require_once 'includes/db.php';

echo "<h2>🔍 Debug - Cadastro de Ocorrência</h2>";

// Verifica se o usuário está logado
if (!isset($_SESSION['usuario_id'])) {
    echo "❌ Usuário não está logado<br>";
    echo "Session data: <pre>" . print_r($_SESSION, true) . "</pre>";
    exit;
}

echo "✅ Usuário logado - ID: " . $_SESSION['usuario_id'] . "<br>";

// Verifica dados da sessão
if (isset($_SESSION['report'])) {
    echo "<h3>📋 Dados da Ocorrência na Sessão:</h3>";
    echo "<pre>" . print_r($_SESSION['report'], true) . "</pre>";
} else {
    echo "❌ Nenhum dado de ocorrência na sessão<br>";
}

// Testa conexão com banco
try {
    $pdo = get_pdo();
    echo "✅ Conexão com banco OK<br>";
    
    // Verifica tabela ocorrencias
    $checkTable = $pdo->query("SHOW TABLES LIKE 'ocorrencias'");
    if ($checkTable->rowCount() > 0) {
        echo "✅ Tabela 'ocorrencias' existe<br>";
        
        // Mostra estrutura
        $describe = $pdo->query("DESCRIBE ocorrencias")->fetchAll();
        echo "<h3>📊 Estrutura da Tabela:</h3>";
        foreach ($describe as $col) {
            echo "- {$col['Field']} ({$col['Type']})<br>";
        }
        
        // Conta registros
        $count = $pdo->query("SELECT COUNT(*) FROM ocorrencias")->fetchColumn();
        echo "<br>📈 Total de registros: $count<br>";
        
    } else {
        echo "❌ Tabela 'ocorrencias' não existe<br>";
    }
    
} catch (Exception $e) {
    echo "❌ Erro no banco: " . $e->getMessage() . "<br>";
}

// Simula inserção de teste
if (isset($_GET['test']) && $_GET['test'] == '1') {
    echo "<h3>🧪 Teste de Inserção:</h3>";
    
    try {
        $pdo = get_pdo();
        
        // Dados de teste
        $numeroOcorrencia = 'OC-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
        $userId = $_SESSION['usuario_id'];
        $endereco = 'Teste Endereço';
        $cep = '12345-678';
        $tipo = 'Teste';
        $descricao = 'Teste de inserção';
        $latitude = -22.9068;
        $longitude = -43.1729;
        $arquivos = '[]';
        $temImagens = 'Não';
        
        echo "Tentando inserir:<br>";
        echo "- Número: $numeroOcorrencia<br>";
        echo "- User ID: $userId<br>";
        echo "- Endereço: $endereco<br>";
        echo "- Coordenadas: $latitude, $longitude<br>";
        
        $stmt = $pdo->prepare("
            INSERT INTO ocorrencias (numero, usuario_id, endereco, cep, tipo, descricao, latitude, longitude, arquivos, tem_imagens, status, data_criacao) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Em Análise', NOW())
        ");
        
        $result = $stmt->execute([
            $numeroOcorrencia, $userId, $endereco, $cep, $tipo, $descricao,
            $latitude, $longitude, $arquivos, $temImagens
        ]);
        
        if ($result) {
            $id = $pdo->lastInsertId();
            echo "✅ Inserção bem-sucedida! ID: $id<br>";
            
            // Verifica se foi realmente inserido
            $verify = $pdo->prepare("SELECT * FROM ocorrencias WHERE id = ?");
            $verify->execute([$id]);
            $inserted = $verify->fetch();
            
            if ($inserted) {
                echo "✅ Verificação OK - Dados inseridos:<br>";
                echo "<pre>" . print_r($inserted, true) . "</pre>";
            } else {
                echo "❌ Erro: Registro não encontrado após inserção<br>";
            }
        } else {
            $errorInfo = $stmt->errorInfo();
            echo "❌ Falha na inserção: " . print_r($errorInfo, true) . "<br>";
        }
        
    } catch (Exception $e) {
        echo "❌ Erro no teste: " . $e->getMessage() . "<br>";
        echo "Stack trace: <pre>" . $e->getTraceAsString() . "</pre>";
    }
}

echo "<br><a href='?test=1'>🧪 Executar Teste de Inserção</a><br>";
echo "<a href='pages/registrar_ocorrencia.php'>🔙 Voltar ao Cadastro</a><br>";
echo "<a href='test_db.php'>🔍 Ver Test DB</a>";
?>