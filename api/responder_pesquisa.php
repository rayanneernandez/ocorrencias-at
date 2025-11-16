<?php
session_start();
require_once __DIR__ . '/../includes/db.php';
$pdo = get_pdo();

$usuarioId = intval($_SESSION['usuario_id'] ?? 0);
$perfil    = intval($_SESSION['usuario_perfil'] ?? 0);
if (!$usuarioId) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: application/json');

    $pid = intval($_GET['id'] ?? 0);
    if (!$pid) { echo json_encode(['error' => 'missing id']); exit; }

    $pesquisa = null; $perguntas = [];
    try {
        $pesquisa = $pdo->prepare("SELECT id, titulo, descricao FROM pesquisa_meta WHERE id = ?");
        $pesquisa->execute([$pid]);
        $pesquisa = $pesquisa->fetch(PDO::FETCH_ASSOC);

        $perg = $pdo->prepare("SELECT id, ordem, tipo, texto, opcoes_json, obrigatoria FROM pesquisa_perguntas WHERE pesquisa_id = ? ORDER BY ordem ASC");
        $perg->execute([$pid]);
        $perguntas = $perg->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $_) {}

    echo json_encode(['pesquisa' => $pesquisa, 'perguntas' => $perguntas]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    $pid = intval($_POST['pesquisa_id'] ?? 0);
    $answersJson = $_POST['answers_json'] ?? '';
    $answers = json_decode($answersJson, true);
    if (!$pid || !is_array($answers)) { echo json_encode(['error' => 'invalid']); exit; }

    $cidade = null; $uf = null;
    try {
        $stmt = $pdo->prepare("SELECT municipio, uf FROM usuarios WHERE id = ?");
        $stmt->execute([$usuarioId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $cidade = trim($row['municipio'] ?? '') ?: null;
        $uf = strtoupper(trim($row['uf'] ?? '')) ?: null;
    } catch (Throwable $_) {}

    try {
        $ins = $pdo->prepare("
            INSERT INTO pesquisa_respostas (pesquisa_id, pergunta_id, usuario_id, perfil_usuario, cidade, uf, resposta_json)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        foreach ($answers as $ans) {
            $perguntaId = intval($ans['pergunta_id'] ?? 0);
            $valor = $ans['valor'] ?? null;
            if ($perguntaId && $valor !== null) {
                $ins->execute([$pid, $perguntaId, $usuarioId, $perfil, $cidade, $uf, json_encode($valor, JSON_UNESCAPED_UNICODE)]);
            }
        }

        // Marca sessão como respondida usando SID da pesquisa
        $sid = '';
        try {
            $s = $pdo->prepare("SELECT sid FROM pesquisa_meta WHERE id = ? LIMIT 1");
            $s->execute([$pid]);
            $sid = trim($s->fetchColumn() ?: '');
        } catch (Throwable $_) {}
        $sidKey = $sid !== '' ? $sid : ('db_'.$pid);

        // Garante estrutura de sessão por usuário antes de indexar
        if (!isset($_SESSION['answered_surveys']) || !is_array($_SESSION['answered_surveys'])) {
            $_SESSION['answered_surveys'] = [];
        }
        if (!isset($_SESSION['answered_surveys'][$usuarioId]) || !is_array($_SESSION['answered_surveys'][$usuarioId])) {
            $_SESSION['answered_surveys'][$usuarioId] = [];
        }
        $_SESSION['answered_surveys'][$usuarioId][$sidKey] = true;

        echo json_encode(['ok' => true]);
    } catch (Throwable $e) {
        echo json_encode(['error' => 'db_error']);
    }
    exit;
}

http_response_code(405);
echo 'method not allowed';