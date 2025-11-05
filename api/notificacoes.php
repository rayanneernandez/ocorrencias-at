<?php
require_once __DIR__ . '/../includes/db.php';


class NotificacaoManager {
    private $pdo;
    
    public function __construct() {
        $this->pdo = get_pdo();
        $this->criarTabelaSeNaoExistir();
    }
    
    private function criarTabelaSeNaoExistir() {
        $sql = "CREATE TABLE IF NOT EXISTS notificacoes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            usuario_id INT NOT NULL,
            tipo VARCHAR(50) NOT NULL,
            titulo VARCHAR(255) NOT NULL,
            mensagem TEXT NOT NULL,
            referencia_id VARCHAR(50),
            status VARCHAR(20) DEFAULT 'nao_lida',
            data_criacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            data_leitura TIMESTAMP NULL,
            link VARCHAR(255),
            icone TEXT,
            INDEX (usuario_id),
            INDEX (status),
            INDEX (data_criacao)
        )";
        $this->pdo->exec($sql);
    }
    
    private function verificarPreferenciasNotificacao($usuarioId, $tipo) {
        $stmt = $this->pdo->prepare("
            SELECT notif_ocorrencias, notif_novidades 
            FROM usuarios_preferencias 
            WHERE usuario_id = ?
        ");
        $stmt->execute([$usuarioId]);
        $prefs = $stmt->fetch(PDO::FETCH_ASSOC);

        // Se não tem preferências definidas, assume que aceita todas as notificações
        if (!$prefs) {
            return true;
        }

        // Verifica o tipo de notificação
        if ($tipo === 'status_ocorrencia') {
            return (bool)$prefs['notif_ocorrencias'];
        } elseif ($tipo === 'nova_pesquisa' || $tipo === 'lembrete_pesquisa') {
            return (bool)$prefs['notif_novidades'];
        }

        return true;
    }

    public function criarNotificacao($usuarioId, $tipo, $titulo, $mensagem, $referenciaId = null, $link = null, $icone = null) {
        // Verifica as preferências do usuário antes de criar a notificação
        if (!$this->verificarPreferenciasNotificacao($usuarioId, $tipo)) {
            return false;
        }

        $sql = "INSERT INTO notificacoes (usuario_id, tipo, titulo, mensagem, referencia_id, link, icone) 
                VALUES (?, ?, ?, ?, ?, ?, ?)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$usuarioId, $tipo, $titulo, $mensagem, $referenciaId, $link, $icone]);
        
        $_SESSION['has_unread_notifications'] = true;
        
        return $this->pdo->lastInsertId();
    }
    
    public function notificarMudancaStatus($ocorrenciaId, $novoStatus) {
        $sql = "SELECT usuario_id, tipo as categoria FROM ocorrencias WHERE id = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$ocorrenciaId]);
        $ocorrencia = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($ocorrencia) {
            $titulo = "Atualização de Status";
            $mensagem = "Sua ocorrência na categoria {$ocorrencia['categoria']} teve seu status atualizado para: " . ucfirst($novoStatus);
            
            $icone = match($novoStatus) {
                'concluída' => 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z',
                'em andamento' => 'M13 10V3L4 14h7v7l9-11h-7z',
                default => 'M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z'
            };
            
            $this->criarNotificacao(
                $ocorrencia['usuario_id'],
                'status_ocorrencia',
                $titulo,
                $mensagem,
                $ocorrenciaId,
                "minhas_ocorrencias.php#ocorrencia-{$ocorrenciaId}",
                $icone
            );
        }
    }
    
    public function notificarNovaPesquisa($pesquisaId, $titulo, $descricao = '') {
        $sql = "SELECT id FROM usuarios WHERE ativo = 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        
        $icone = 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2';
        
        while ($usuario = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $this->criarNotificacao(
                $usuario['id'],
                'nova_pesquisa',
                'Nova Pesquisa Disponível',
                $descricao ?: "Uma nova pesquisa está disponível: {$titulo}",
                $pesquisaId,
                "prioridades.php?survey_id={$pesquisaId}",
                $icone
            );
        }
    }
    
    public function enviarLembretePesquisaPendente() {
        $sql = "SELECT u.id, u.nome 
                FROM usuarios u 
                LEFT JOIN pesquisa p ON p.idUsuario = u.id 
                WHERE p.id IS NULL AND u.ativo = 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        
        $icone = 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z';
        
        while ($usuario = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $checkSql = "SELECT 1 FROM notificacoes 
                        WHERE usuario_id = ? 
                        AND tipo = 'lembrete_pesquisa' 
                        AND DATE(data_criacao) = CURDATE()";
            $checkStmt = $this->pdo->prepare($checkSql);
            $checkStmt->execute([$usuario['id']]);
            
            if (!$checkStmt->fetch()) {
                $this->criarNotificacao(
                    $usuario['id'],
                    'lembrete_pesquisa',
                    'Pesquisa Pendente',
                    "Olá {$usuario['nome']}, não esqueça de responder a pesquisa de prioridades da sua cidade!",
                    'prioridades',
                    'prioridades.php',
                    $icone
                );
            }
        }
    }
    
    public function marcarComoLida($notificacaoId, $usuarioId) {
        $sql = "UPDATE notificacoes 
                SET status = 'lida', data_leitura = CURRENT_TIMESTAMP 
                WHERE id = ? AND usuario_id = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$notificacaoId, $usuarioId]);
        
        $sql = "SELECT 1 FROM notificacoes 
                WHERE usuario_id = ? AND status = 'nao_lida' LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$usuarioId]);
        
        if (!$stmt->fetch()) {
            unset($_SESSION['has_unread_notifications']);
        }
    }
    
    public function buscarNotificacoesNaoLidas($usuarioId) {
        $sql = "SELECT * FROM notificacoes 
                WHERE usuario_id = ? AND status = 'nao_lida' 
                ORDER BY data_criacao DESC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$usuarioId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function limparTodasNotificacoes($usuarioId) {
        $sql = "UPDATE notificacoes 
                SET status = 'lida', data_leitura = CURRENT_TIMESTAMP 
                WHERE usuario_id = ? AND status = 'nao_lida'";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$usuarioId]);
        
        unset($_SESSION['has_unread_notifications']);
        return true;
    }
}