<?php
require_once __DIR__ . '/../../config/bootstrap.php';
exigirLoginAdm();

$pdo   = conectarPDO();
$idAdm = (int)$_SESSION['admin']['id'];

// ── Filtro de período ─────────────────────────────────────────────────────
$dashInicio    = trim($_GET['dash_inicio'] ?? '');
$dashFim       = trim($_GET['dash_fim']    ?? '');
$wherePeriodo  = '';
$paramsPeriodo = [];
if ($dashInicio) { $wherePeriodo .= " AND DATE(criado_em) >= :di"; $paramsPeriodo[':di'] = $dashInicio; }
if ($dashFim)    { $wherePeriodo .= " AND DATE(criado_em) <= :df"; $paramsPeriodo[':df'] = $dashFim; }

/**
 * Executa query preparada e retorna o statement.
 */
function queryDash(PDO $pdo, string $sql, array $params = []): PDOStatement {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

// ── Estatísticas gerais ──────────────────────────────────────────────────
$resumo = queryDash($pdo, "
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
", $paramsPeriodo)->fetch() ?: [];

// ── Por tipo ─────────────────────────────────────────────────────────────
$porTipo = queryDash($pdo, "
    SELECT t.descricao AS tipo, COUNT(*) AS total
    FROM tbmanifest m
    INNER JOIN tipos t ON t.IDtipo = m.IDtipo
    WHERE 1=1 $wherePeriodo
    GROUP BY t.descricao ORDER BY total DESC
", $paramsPeriodo)->fetchAll();

// ── Por status ────────────────────────────────────────────────────────────
$porStatus = queryDash($pdo, "
    SELECT status, COUNT(*) AS total FROM tbmanifest WHERE 1=1 $wherePeriodo GROUP BY status
", $paramsPeriodo)->fetchAll();

// ── Por curso/turma ───────────────────────────────────────────────────────
$porCurso = queryDash($pdo, "
    SELECT
        CASE
            WHEN turma_setor LIKE '%Informática%'  THEN 'Informática'
            WHEN turma_setor LIKE '%Saúde Bucal%'  THEN 'Saúde Bucal'
            WHEN turma_setor LIKE '%Energias%'      THEN 'Energias Renováveis'
            WHEN turma_setor LIKE '%Enfermagem%'    THEN 'Enfermagem'
            ELSE 'Não informado'
        END AS curso,
        COUNT(*) AS total
    FROM tbmanifest WHERE 1=1 $wherePeriodo
    GROUP BY curso ORDER BY total DESC
", $paramsPeriodo)->fetchAll();

// ── Evolução últimos 30 dias ──────────────────────────────────────────────
$evolucao = queryDash($pdo, "
    SELECT DATE(criado_em) AS dia, COUNT(*) AS total
    FROM tbmanifest
    WHERE criado_em >= DATE_SUB(CURDATE(), INTERVAL 29 DAY) $wherePeriodo
    GROUP BY dia ORDER BY dia ASC
", $paramsPeriodo)->fetchAll();

// ── Últimas 5 manifestações ───────────────────────────────────────────────
$ultimas = $pdo->query("
    SELECT m.IDmanifest, m.protocolo, m.assunto, m.status,
           m.nome_manifestante, m.criado_em, t.descricao AS tipo
    FROM tbmanifest m
    INNER JOIN tipos t ON t.IDtipo = m.IDtipo
    ORDER BY m.criado_em DESC
    LIMIT 5
")->fetchAll();

// ── Respostas pendentes ───────────────────────────────────────────────────
$pendentes = 0;
try {
    $pendentes = (int)$pdo->query("
        SELECT COUNT(DISTINCT IDmanifest) FROM respostas_manifest
        WHERE autor_tipo='usuario' AND lida_pelo_adm=0
    ")->fetchColumn();
} catch (Exception $e) {}

// ── Notificações não lidas ────────────────────────────────────────────────
$notifsAdm = contarNotificacoesNaoLidas($pdo, 'IDadm', $idAdm);

// ── Preparar dados para gráficos ──────────────────────────────────────────
$jsonTipos     = json_encode(array_column($porTipo,   'tipo'));
$jsonTiposVal  = json_encode(array_map('intval', array_column($porTipo,   'total')));
$jsonStatus    = json_encode(array_column($porStatus, 'status'));
$jsonStatusVal = json_encode(array_map('intval', array_column($porStatus, 'total')));
$jsonCursos    = json_encode(array_column($porCurso,  'curso'));
$jsonCursosVal = json_encode(array_map('intval', array_column($porCurso,  'total')));

// Preencher todos os 30 dias (sem lacunas)
$dias = []; $vals = [];
$evMap = array_column($evolucao, 'total', 'dia');
for ($i = 29; $i >= 0; $i--) {
    $d      = date('Y-m-d', strtotime("-$i days"));
    $dias[] = date('d/m', strtotime($d));
    $vals[] = (int)($evMap[$d] ?? 0);
}
$jsonDias = json_encode($dias);
$jsonVals = json_encode($vals);

// Taxa de resolução
$taxaResolucao = ($resumo['total'] ?? 0) > 0
    ? round(($resumo['resolvidas'] / $resumo['total']) * 100)
    : 0;

$tituloPagina = 'Dashboard — Ouvidoria do Grêmio Escolar';
require_once __DIR__ . '/../../includes/header.php';
?>

<!-- ── Page header ─────────────────────────────────────────────────────── -->
<div class="page-header">
  <div class="page-header-inner">
    <span class="section-label">PAINEL ADMINISTRATIVO</span>
    <h1>Dashboard</h1>
    <p>Visão geral das manifestações da Ouvidoria do Grêmio Escolar</p>
  </div>
</div>

<section style="background:var(--bg);padding:clamp(20px,4vw,48px) clamp(14px,4vw,40px);">
<div style="max-width:1300px;margin:0 auto;">

  <!-- Filtro de período -->
  <div class="card border-0 shadow-sm mb-4" style="border-radius:var(--r-lg) !important;">
    <div class="card-body py-3 px-4">
      <form method="get" class="d-flex flex-wrap gap-3 align-items-end" id="formDashFiltro">
        <div>
          <label class="form-label" style="font-size:.75rem;font-weight:700;letter-spacing:.5px;text-transform:uppercase;color:var(--text-2);">Início</label>
          <input type="date" name="dash_inicio" class="form-control form-control-sm" style="border-radius:8px;" value="<?= e($dashInicio) ?>">
        </div>
        <div>
          <label class="form-label" style="font-size:.75rem;font-weight:700;letter-spacing:.5px;text-transform:uppercase;color:var(--text-2);">Fim</label>
          <input type="date" name="dash_fim" class="form-control form-control-sm" style="border-radius:8px;" value="<?= e($dashFim) ?>">
        </div>
        <div class="d-flex gap-2">
          <button type="submit" class="btn btn-sm" style="background:var(--verde);color:#fff;border-radius:8px;font-weight:600;">
            <i class="fa-solid fa-chart-line me-1"></i>Aplicar
          </button>
          <a href="dashboard.php" class="btn btn-sm btn-outline-secondary btn-limpar-dash" style="border-radius:8px;">Limpar</a>
        </div>
        <?php if ($dashInicio || $dashFim): ?>
          <span style="background:var(--verde-xs);color:var(--verde-d);font-size:.78rem;font-weight:700;padding:6px 14px;border-radius:999px;border:1px solid rgba(26,107,64,0.2);">
            <i class="fa-solid fa-calendar-check me-1"></i>
            <?= $dashInicio ? date('d/m/Y', strtotime($dashInicio)) : '…' ?> → <?= $dashFim ? date('d/m/Y', strtotime($dashFim)) : '…' ?>
          </span>
        <?php endif; ?>
      </form>
    </div>
  </div>

  <!-- ── Linha 1: cards de métricas (estilo Tesla) ─────────────────────── -->
  <div class="row g-3 mb-4">
    <?php
    $metrics = [
      ['label' => 'Total',            'val' => $resumo['total']     ?? 0, 'key' => 'total',      'icon' => 'fa-inbox',           'color' => 'var(--verde-d)',  'bg' => 'var(--verde-xs)',   'border' => 'rgba(26,107,64,0.2)'],
      ['label' => 'Recebidas',        'val' => $resumo['recebidas'] ?? 0, 'key' => 'recebidas',  'icon' => 'fa-envelope-open',   'color' => '#1d4ed8',         'bg' => '#eff6ff',           'border' => '#bfdbfe'],
      ['label' => 'Em andamento',     'val' => $resumo['andamento'] ?? 0, 'key' => 'andamento',  'icon' => 'fa-spinner',         'color' => '#c2410c',         'bg' => '#fff7ed',           'border' => '#fed7aa'],
      ['label' => 'Resolvidas',       'val' => $resumo['resolvidas']?? 0, 'key' => 'resolvidas', 'icon' => 'fa-circle-check',    'color' => 'var(--verde)',     'bg' => 'var(--verde-xs)',   'border' => 'rgba(26,107,64,0.2)'],
      ['label' => 'Hoje',             'val' => $resumo['hoje']      ?? 0, 'key' => 'hoje',       'icon' => 'fa-calendar-day',    'color' => '#7e22ce',         'bg' => '#f5f3ff',           'border' => '#ddd6fe'],
      ['label' => 'Esta semana',      'val' => $resumo['semana']    ?? 0, 'key' => 'semana',     'icon' => 'fa-calendar-week',   'color' => '#be123c',         'bg' => '#fff1f2',           'border' => '#fecdd3'],
      ['label' => 'Pendentes',        'val' => $pendentes,                'key' => '',           'icon' => 'fa-comment-dots',    'color' => '#b45309',         'bg' => '#fffbeb',           'border' => '#fde68a'],
      ['label' => 'Taxa de resolução','val' => $taxaResolucao . '%',      'key' => 'taxa',       'icon' => 'fa-chart-pie',       'color' => 'var(--verde)',     'bg' => 'var(--verde-xs)',   'border' => 'rgba(26,107,64,0.2)'],
    ];
    foreach ($metrics as $m): ?>
    <div class="col-6 col-md-3">
      <div class="card border-0 h-100 dash-card" style="background:<?= $m['bg'] ?>;border:1px solid <?= $m['border'] ?> !important;border-radius:var(--r-lg) !important;">
        <div class="card-body p-3 d-flex align-items-center gap-3">
          <div class="dash-card-icon" style="background:<?= $m['border'] ?>;color:<?= $m['color'] ?>;">
            <i class="fa-solid <?= $m['icon'] ?>"></i>
          </div>
          <div>
            <div class="dash-card-label"><?= $m['label'] ?></div>
            <div class="dash-card-val" style="color:<?= $m['color'] ?>;" <?= $m['key'] ? 'data-resumo="'.$m['key'].'"' : '' ?>><?= $m['val'] ?></div>
          </div>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- ── Linha 2: gráfico de linha + donut status ──────────────────────── -->
  <div class="row g-3 mb-4">
    <div class="col-12 col-lg-8">
      <div class="card border-0 h-100" style="border:1px solid var(--border) !important;border-radius:var(--r-lg) !important;">
        <div class="card-body p-4">
          <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
              <div class="dash-chart-title mb-0" style="font-family:'Sora',sans-serif;font-size:.95rem;color:var(--verde-d);">
                Manifestações — últimos 30 dias
              </div>
              <div style="font-size:.75rem;color:var(--text-3);margin-top:2px;">Evolução temporal das entradas</div>
            </div>
            <span style="background:var(--verde-xs);color:var(--verde);font-size:.7rem;font-weight:700;padding:4px 12px;border-radius:999px;border:1px solid rgba(26,107,64,0.15);">
              30 dias
            </span>
          </div>
          <div class="dash-chart-wrap">
            <canvas id="chartEvolucao"></canvas>
          </div>
        </div>
      </div>
    </div>
    <div class="col-12 col-lg-4">
      <div class="card border-0 h-100" style="border:1px solid var(--border) !important;border-radius:var(--r-lg) !important;">
        <div class="card-body p-4">
          <div class="dash-chart-title" style="font-family:'Sora',sans-serif;font-size:.95rem;color:var(--verde-d);">Por status</div>
          <div class="dash-chart-wrap dash-chart-wrap--sm">
            <canvas id="chartStatus"></canvas>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- ── Linha 3: barras por tipo + barras horizontais por curso ──────────── -->
  <div class="row g-3 mb-4">
    <div class="col-12 col-md-6">
      <div class="card border-0 h-100" style="border:1px solid var(--border) !important;border-radius:var(--r-lg) !important;">
        <div class="card-body p-4">
          <div class="dash-chart-title" style="font-family:'Sora',sans-serif;font-size:.95rem;color:var(--verde-d);">Por tipo de manifestação</div>
          <div class="dash-chart-wrap">
            <canvas id="chartTipos"></canvas>
          </div>
        </div>
      </div>
    </div>
    <div class="col-12 col-md-6">
      <div class="card border-0 h-100" style="border:1px solid var(--border) !important;border-radius:var(--r-lg) !important;">
        <div class="card-body p-4">
          <div class="dash-chart-title" style="font-family:'Sora',sans-serif;font-size:.95rem;color:var(--verde-d);">Por curso / turma</div>
          <div class="dash-chart-wrap">
            <canvas id="chartCursos"></canvas>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- ── Linha 4: anônimo vs identificado + últimas manifestações ──────── -->
  <div class="row g-3">
    <div class="col-12 col-md-4">
      <div class="card border-0 h-100" style="border:1px solid var(--border) !important;border-radius:var(--r-lg) !important;">
        <div class="card-body p-4">
          <div class="dash-chart-title" style="font-family:'Sora',sans-serif;font-size:.95rem;color:var(--verde-d);">Anônimo vs Identificado</div>
          <div class="dash-chart-wrap dash-chart-wrap--sm">
            <canvas id="chartAnonimo"></canvas>
          </div>
          <div class="d-flex justify-content-center gap-4 mt-3">
            <div class="text-center">
              <div style="font-size:1.4rem;font-weight:700;color:#1d4ed8;font-family:'Sora',sans-serif;"><?= (int)($resumo['anonimas'] ?? 0) ?></div>
              <div style="font-size:.72rem;color:var(--text-2);">Anônimas</div>
            </div>
            <div class="text-center">
              <div style="font-size:1.4rem;font-weight:700;color:var(--verde);font-family:'Sora',sans-serif;"><?= (int)($resumo['identificadas'] ?? 0) ?></div>
              <div style="font-size:.72rem;color:var(--text-2);">Identificadas</div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="col-12 col-md-8">
      <div class="card border-0 h-100" style="border:1px solid var(--border) !important;border-radius:var(--r-lg) !important;overflow:hidden;">
        <div class="card-header bg-white d-flex justify-content-between align-items-center py-3 px-4" style="border-bottom:1px solid var(--border);">
          <div style="font-family:'Sora',sans-serif;font-size:.95rem;font-weight:700;color:var(--verde-d);">
            <i class="fa-solid fa-clock-rotate-left me-2" style="color:var(--laranja);"></i>Últimas manifestações
          </div>
          <a href="<?= $_base ?>app/painel/adm.php" class="btn btn-sm" style="background:var(--verde);color:#fff;border-radius:8px;font-size:.75rem;font-weight:600;">
            Ver todas
          </a>
        </div>
        <div class="card-body p-0">
          <?php if (empty($ultimas)): ?>
            <div class="text-center py-4" style="color:var(--text-3);font-size:.85rem;">
              <i class="fa-solid fa-inbox d-block mb-2" style="font-size:1.5rem;opacity:.3;"></i>
              Nenhuma manifestação ainda.
            </div>
          <?php else: ?>
            <div class="list-group list-group-flush">
              <?php foreach ($ultimas as $u): ?>
                <a href="<?= $_base ?>app/painel/adm.php#manifest-<?= (int)$u['IDmanifest'] ?>"
                   class="list-group-item list-group-item-action px-4 py-3 border-0 border-bottom"
                   style="border-color:var(--border) !important;">
                  <div class="d-flex justify-content-between align-items-start gap-2">
                    <div class="flex-grow-1 overflow-hidden">
                      <div class="fw-semibold text-truncate" style="font-size:.87rem;color:var(--verde-d);"><?= e($u['assunto']) ?></div>
                      <div style="font-size:.75rem;color:var(--text-2);margin-top:2px;">
                        <span class="me-2"><?= e($u['tipo']) ?></span>·
                        <span class="ms-2"><?= e($u['nome_manifestante']) ?></span>·
                        <span class="ms-2"><?= date('d/m/Y H:i', strtotime($u['criado_em'])) ?></span>
                      </div>
                    </div>
                    <span class="<?= classeStatus($u['status']) ?> flex-shrink-0">
                      <?= e($u['status']) ?>
                    </span>
                  </div>
                </a>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

</div>
</section>

<!-- Chart.js -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script>
// ── Configuração global ──────────────────────────────────────────────────
Chart.defaults.font.family = "'Inter', system-ui, sans-serif";
Chart.defaults.font.size   = 12;
Chart.defaults.color       = '#4a5248';

const C = {
  verde:   '#1a6b40',
  verdeL:  '#2e9e5b',
  laranja: '#e8820a',
  azul:    '#1d4ed8',
  roxo:    '#7e22ce',
  rosa:    '#be123c',
  cinza:   '#9ca3af',
  bg:      'rgba(26,107,64,0.06)',
};

// Opções compartilhadas para todos os gráficos
const baseOptions = {
  responsive: true,
  maintainAspectRatio: false,
  plugins: {
    legend: { display: false },
    tooltip: {
      backgroundColor: '#0f4228',
      titleColor: '#fff',
      bodyColor: 'rgba(255,255,255,0.8)',
      padding: 10,
      cornerRadius: 8,
      displayColors: false,
    }
  }
};

// ── Evolução 30 dias ──────────────────────────────────────────────────────
const _chartEvolucao = new Chart(document.getElementById('chartEvolucao'), {
  type: 'line',
  data: {
    labels: <?= $jsonDias ?>,
    datasets: [{
      label: 'Manifestações',
      data: <?= $jsonVals ?>,
      borderColor: C.verde,
      backgroundColor: C.bg,
      borderWidth: 2,
      pointBackgroundColor: C.verde,
      pointRadius: 2.5,
      pointHoverRadius: 5,
      fill: true,
      tension: 0.4,
    }]
  },
  options: {
    ...baseOptions,
    scales: {
      y: { beginAtZero: true, ticks: { stepSize: 1, precision: 0 }, grid: { color: 'rgba(0,0,0,0.04)' }, border: { display: false } },
      x: { grid: { display: false }, ticks: { maxTicksLimit: 10 } }
    }
  }
});

// ── Donut status ──────────────────────────────────────────────────────────
const _chartStatus = new Chart(document.getElementById('chartStatus'), {
  type: 'doughnut',
  data: {
    labels: <?= $jsonStatus ?>,
    datasets: [{
      data: <?= $jsonStatusVal ?>,
      backgroundColor: [C.rosa, C.azul, C.verde, C.cinza],
      borderWidth: 3,
      borderColor: '#fff',
      hoverOffset: 6,
    }]
  },
  options: {
    ...baseOptions,
    cutout: '68%',
    plugins: {
      ...baseOptions.plugins,
      legend: { position: 'bottom', labels: { boxWidth: 10, padding: 14, font: { size: 11 } } }
    }
  }
});

// ── Barras por tipo ───────────────────────────────────────────────────────
const _chartTipos = new Chart(document.getElementById('chartTipos'), {
  type: 'bar',
  data: {
    labels: <?= $jsonTipos ?>,
    datasets: [{
      label: 'Manifestações',
      data: <?= $jsonTiposVal ?>,
      backgroundColor: [C.verde, C.laranja, C.azul, C.roxo],
      borderRadius: 6,
      borderSkipped: false,
    }]
  },
  options: {
    ...baseOptions,
    scales: {
      y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: 'rgba(0,0,0,0.04)' }, border: { display: false } },
      x: { grid: { display: false } }
    }
  }
});

// ── Barras horizontais por curso ─────────────────────────────────────────
const _chartCursos = new Chart(document.getElementById('chartCursos'), {
  type: 'bar',
  data: {
    labels: <?= $jsonCursos ?>,
    datasets: [{
      label: 'Manifestações',
      data: <?= $jsonCursosVal ?>,
      backgroundColor: [C.azul, C.verde, C.laranja, C.rosa, C.cinza],
      borderRadius: 6,
      borderSkipped: false,
    }]
  },
  options: {
    ...baseOptions,
    indexAxis: 'y',
    scales: {
      x: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: 'rgba(0,0,0,0.04)' }, border: { display: false } },
      y: { grid: { display: false } }
    }
  }
});

// ── Donut anônimo vs identificado ─────────────────────────────────────────
const _chartAnonimo = new Chart(document.getElementById('chartAnonimo'), {
  type: 'doughnut',
  data: {
    labels: ['Anônimas', 'Identificadas'],
    datasets: [{
      data: [<?= (int)($resumo['anonimas'] ?? 0) ?>, <?= (int)($resumo['identificadas'] ?? 0) ?>],
      backgroundColor: [C.azul, C.verde],
      borderWidth: 3,
      borderColor: '#fff',
      hoverOffset: 6,
    }]
  },
  options: {
    ...baseOptions,
    cutout: '68%',
    plugins: {
      ...baseOptions.plugins,
      legend: { position: 'bottom', labels: { boxWidth: 10, padding: 14, font: { size: 11 } } }
    }
  }
});

// ── Registrar charts para atualização via AJAX (app.js) ───────────────────
window._dashCharts = {
  evolucao: _chartEvolucao,
  status:   _chartStatus,
  tipos:    _chartTipos,
  cursos:   _chartCursos,
  anonimo:  _chartAnonimo,
};
window._baseUrl = '<?= BASE_URL ?>';
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
