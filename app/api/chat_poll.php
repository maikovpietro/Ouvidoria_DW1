<?php
/**
 * API: Polling de mensagens do chat
 * GET ?protocolo=GRE-...&desde_id=N
 * Retorna mensagens novas + status atual da manifestação
 */
require_once __DIR__ . '/../../config/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$protocolo = trim($_GET['protocolo'] ?? '');
$desdeId   = (int)($_GET['desde_id'] ?? 0);

if (!preg_match('/^GRE-\d{8}-[A-Z0-9]{6}$/', $protocolo)) {
    echo json_encode(['ok' => false, 'erro' => 'Protocolo inválido.']);
    exit;
}

try {
    $pdo = conectarPDO();

    // Rate limiting: máximo 60 requisições por IP a cada 60 segundos
    $chaveRL = 'chat_poll:' . ($_SERVER['REMOTE_ADDR'] ?? '');
    if (verificarRateLimit($pdo, $chaveRL, 60, 60)) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'erro' => 'Muitas requisições. Aguarde um momento.']);
        exit;
    }
    registrarTentativaFalhada($pdo, $chaveRL);

    // Buscar manifestação
    $stmt = $pdo->prepare('SELECT IDmanifest, IDusu, status FROM tbmanifest WHERE protocolo = :p LIMIT 1');
    $stmt->execute([':p' => $protocolo]);
    $manifest = $stmt->fetch();

    if (!$manifest) {
        echo json_encode(['ok' => false, 'erro' => 'Protocolo não encontrado.']);
        exit;
    }

    // Verifica ownership: se o usuário estiver logado, a manifestação deve pertencer a ele.
    // Manifestações anônimas (IDusu = NULL) são acessíveis apenas por quem tem o protocolo.
    // Manifestações vinculadas a uma conta só podem ser lidas pelo dono da conta.
    if (!empty($manifest['IDusu']) && usuarioLogado()) {
        if ((int)$manifest['IDusu'] !== (int)$_SESSION['usuario']['id']) {
            echo json_encode(['ok' => false, 'erro' => 'Acesso negado.']);
            exit;
        }
    } elseif (!empty($manifest['IDusu']) && !usuarioLogado()) {
        // Manifestação de conta, mas sem sessão — não expõe o histórico
        echo json_encode(['ok' => false, 'erro' => 'Faça login para acompanhar esta manifestação.']);
        exit;
    }

    $idManifest = (int)$manifest['IDmanifest'];
    $statusAtual = $manifest['status'];

    // Mensagens novas desde o último ID visto
    $stmtMsg = $pdo->prepare('
        SELECT IDresposta, autor_nome, autor_tipo, mensagem,
               DATE_FORMAT(criado_em, "%d/%m/%Y %H:%i") AS data_fmt
        FROM respostas_manifest
        WHERE IDmanifest = :id AND IDresposta > :desde
        ORDER BY criado_em ASC
    ');
    $stmtMsg->execute([':id' => $idManifest, ':desde' => $desdeId]);
    $mensagens = $stmtMsg->fetchAll();

    // Marcar mensagens do admin como lidas (se usuário estiver olhando)
    if (!empty($mensagens)) {
        $pdo->prepare("
            UPDATE respostas_manifest
            SET lida_pelo_usuario = 1
            WHERE IDmanifest = :id AND autor_tipo = 'adm' AND IDresposta > :desde
        ")->execute([':id' => $idManifest, ':desde' => $desdeId]);
    }

    echo json_encode([
        'ok'          => true,
        'status'      => $statusAtual,
        'mensagens'   => $mensagens,
        'ultimo_id'   => !empty($mensagens) ? (int)end($mensagens)['IDresposta'] : $desdeId,
    ]);

} catch (PDOException $e) {
    echo json_encode(['ok' => false, 'erro' => 'Erro interno.']);
}
