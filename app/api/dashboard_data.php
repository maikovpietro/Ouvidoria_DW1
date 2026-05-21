<?php
/**
 * API: Dados do dashboard para atualização via AJAX
 * GET ?dash_inicio=YYYY-MM-DD&dash_fim=YYYY-MM-DD
 * Retorna todos os dados dos gráficos em JSON
 */
require_once __DIR__ . '/../../config/bootstrap.php';
exigirLoginAdm();

header('Content-Type: application/json; charset=utf-8');

$dashInicio    = trim($_GET['dash_inicio'] ?? '');
$dashFim       = trim($_GET['dash_fim']    ?? '');
$wherePeriodo  = '';
$paramsPeriodo = [];

// Valida formato YYYY-MM-DD antes de usar no SQL (evita datas malformadas)
$reData = '/^\d{4}-\d{2}-\d{2}$/';
if ($dashInicio && preg_match($reData, $dashInicio) && strtotime($dashInicio) !== false) {
    $wherePeriodo .= ' AND DATE(criado_em) >= :di';
    $paramsPeriodo[':di'] = $dashInicio;
} else {
    $dashInicio = '';
}
if ($dashFim && preg_match($reData, $dashFim) && strtotime($dashFim) !== false) {
    $wherePeriodo .= ' AND DATE(criado_em) <= :df';
    $paramsPeriodo[':df'] = $dashFim;
} else {
    $dashFim = '';
}

try {
    $pdo = conectarPDO();

    function qDash(PDO $pdo, string $sql, array $params = []): array {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    // ── Resumo geral ──────────────────────────────────────────────────────
    $resumo = qDash($pdo, "
        SELECT
            COUNT(*)                                     AS total,
            SUM(status = 'Recebida')                     AS recebidas,
            SUM(status = 'Em andamento')                 AS andamento,
            SUM(status = 'Resolvida')                    AS resolvidas,
            SUM(nome_manifestante = 'Anônimo')           AS anonimas,
            SUM(nome_manifestante <> 'Anônimo')          AS identificadas,
            SUM(DATE(criado_em) = CURDATE())             AS hoje,
            SUM(criado_em >= DATE_SUB(NOW(), INTERVAL 7 DAY)) AS semana
        FROM tbmanifest WHERE 1=1 $wherePeriodo
    ", $paramsPeriodo)[0] ?? [];

    // ── Por tipo ──────────────────────────────────────────────────────────
    $porTipo = qDash($pdo, "
        SELECT t.descricao AS tipo, COUNT(*) AS total
        FROM tbmanifest m
        INNER JOIN tipos t ON t.IDtipo = m.IDtipo
        WHERE 1=1 $wherePeriodo
        GROUP BY t.descricao ORDER BY total DESC
    ", $paramsPeriodo);

    // ── Por status ────────────────────────────────────────────────────────
    $porStatus = qDash($pdo, "
        SELECT status, COUNT(*) AS total FROM tbmanifest WHERE 1=1 $wherePeriodo GROUP BY status
    ", $paramsPeriodo);

    // ── Por curso ─────────────────────────────────────────────────────────
    $porCurso = qDash($pdo, "
        SELECT
            CASE
                WHEN turma_setor LIKE '%Informática%'  THEN 'Informática'
                WHEN turma_setor LIKE '%Saúde Bucal%'  THEN 'Saúde Bucal'
                WHEN turma_setor LIKE '%Energias%'     THEN 'Energias Renováveis'
                WHEN turma_setor LIKE '%Enfermagem%'   THEN 'Enfermagem'
                ELSE 'Não informado'
            END AS curso,
            COUNT(*) AS total
        FROM tbmanifest WHERE 1=1 $wherePeriodo
        GROUP BY curso ORDER BY total DESC
    ", $paramsPeriodo);

    // ── Evolução 30 dias ──────────────────────────────────────────────────
    $evolucao = qDash($pdo, "
        SELECT DATE(criado_em) AS dia, COUNT(*) AS total
        FROM tbmanifest
        WHERE criado_em >= DATE_SUB(CURDATE(), INTERVAL 29 DAY) $wherePeriodo
        GROUP BY dia ORDER BY dia ASC
    ", $paramsPeriodo);

    // ── Anônimo vs Identificado ───────────────────────────────────────────
    $anonimas      = (int)($resumo['anonimas']     ?? 0);
    $identificadas = (int)($resumo['identificadas'] ?? 0);
    $total         = (int)($resumo['total']         ?? 0);
    $taxaResolucao = $total > 0 ? round(($resumo['resolvidas'] / $total) * 100) : 0;

    // Preencher todos os 30 dias (sem lacunas)
    $dias = []; $vals = [];
    $evMap = array_column($evolucao, 'total', 'dia');
    for ($i = 29; $i >= 0; $i--) {
        $d      = date('Y-m-d', strtotime("-$i days"));
        $dias[] = date('d/m', strtotime($d));
        $vals[] = (int)($evMap[$d] ?? 0);
    }

    echo json_encode([
        'ok'      => true,
        'resumo'  => [
            'total'         => $total,
            'recebidas'     => (int)($resumo['recebidas']  ?? 0),
            'andamento'     => (int)($resumo['andamento']  ?? 0),
            'resolvidas'    => (int)($resumo['resolvidas'] ?? 0),
            'hoje'          => (int)($resumo['hoje']       ?? 0),
            'semana'        => (int)($resumo['semana']     ?? 0),
            'taxa'          => $taxaResolucao . '%',
        ],
        'evolucao' => [
            'labels' => $dias,
            'data'   => $vals,
        ],
        'status' => [
            'labels' => array_column($porStatus, 'status'),
            'data'   => array_map('intval', array_column($porStatus, 'total')),
        ],
        'tipos' => [
            'labels' => array_column($porTipo, 'tipo'),
            'data'   => array_map('intval', array_column($porTipo, 'total')),
        ],
        'cursos' => [
            'labels' => array_column($porCurso, 'curso'),
            'data'   => array_map('intval', array_column($porCurso, 'total')),
        ],
        'anonimo' => [
            'labels' => ['Anônimas', 'Identificadas'],
            'data'   => [$anonimas, $identificadas],
        ],
    ]);

} catch (PDOException $e) {
    echo json_encode(['ok' => false, 'erro' => 'Erro interno.']);
}
