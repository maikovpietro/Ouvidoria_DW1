<?php
require_once __DIR__ . '/../../config/bootstrap.php';
exigirLoginAdm();

$pdo   = conectarPDO();
$idAdm = (int)$_SESSION['admin']['id'];

// ── POST handler ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['acao'])) {
    validarCSRF();

    // Atualizar status + feedback
    if ($_POST['acao'] === 'atualizar_manifestacao') {
        $idM     = (int)($_POST['id_manifest'] ?? 0);
        $status  = trim($_POST['status'] ?? '');
        $feedback= trim($_POST['feedback'] ?? '');
        $ok      = ['Recebida','Em andamento','Resolvida'];

        if ($idM > 0 && in_array($status, $ok, true)) {
            // Busca dados atuais antes de alterar
            $ant = $pdo->prepare('
                SELECT m.status, m.IDusu, m.protocolo, m.contato, m.nome_manifestante,
                       u.email AS email_usuario
                FROM tbmanifest m
                LEFT JOIN tbusuarios u ON u.IDusu = m.IDusu
                WHERE m.IDmanifest = :id LIMIT 1
            ');
            $ant->execute([':id'=>$idM]);
            $m = $ant->fetch();

            $statusAnterior = $m['status'] ?? 'Recebida';
            $mudouStatus    = ($statusAnterior !== $status);

            $pdo->prepare("UPDATE tbmanifest SET status=:s, feedback=:f, IDadm=:a WHERE IDmanifest=:id")
                ->execute([':s'=>$status, ':f'=>$feedback!==''?$feedback:null, ':a'=>$idAdm, ':id'=>$idM]);

            // Registra no histórico sempre que houver mudança de status
            if ($mudouStatus) {
                registrarHistoricoStatus(
                    pdo:            $pdo,
                    idManifest:     $idM,
                    idAdm:          $idAdm,
                    statusAnterior: $statusAnterior,
                    statusNovo:     $status,
                    observacao:     $feedback !== '' ? $feedback : null
                );
            }

            // Notificação interna + e-mail ao usuário se status mudou
            if ($m && $mudouStatus) {
                $linkAcomp = APP_URL . '/app/acompanhar.php?protocolo=' . urlencode($m['protocolo'] ?? '');

                // Notificação no sistema
                if (!empty($m['IDusu'])) {
                    criarNotificacao($pdo, [
                        'IDusu'    => (int)$m['IDusu'],
                        'tipo'     => 'status_atualizado',
                        'titulo'   => 'Status da sua manifestação atualizado',
                        'mensagem' => 'Protocolo '.$m['protocolo'].': agora está como "'.$status.'".',
                        'link'     => BASE_URL . 'app/acompanhar.php?protocolo='.urlencode($m['protocolo']),
                    ]);
                }

                // E-mail: tenta e-mail do usuário logado, depois o contato informado
                $emailDestino = $m['email_usuario'] ?? $m['contato'] ?? '';
                if ($emailDestino !== '' && filter_var($emailDestino, FILTER_VALIDATE_EMAIL)) {
                    enviarEmailStatusAtualizado(
                        emailDestino:     $emailDestino,
                        nomeDestinatario: $m['nome_manifestante'] ?? 'Usuário',
                        protocolo:        $m['protocolo'] ?? '',
                        statusAnterior:   $statusAnterior,
                        statusNovo:       $status,
                        linkAcompanhar:   $linkAcomp,
                        mensagemAdm:      $feedback !== '' ? $feedback : null
                    );
                }
            }

            flash('sucesso', 'Manifestação atualizada' . ($mudouStatus ? ' — e-mail de notificação enviado ao usuário.' : '.'));
        } else {
            flash('erro', 'Dados inválidos.');
        }
        header('Location: ' . BASE_URL . 'app/painel/adm.php');
        exit;
    }

    // Arquivar manifestação
    if ($_POST['acao'] === 'arquivar') {
        $idM = (int)($_POST['id_manifest'] ?? 0);
        if ($idM > 0) {
            $pdo->prepare("UPDATE tbmanifest SET arquivada=1 WHERE IDmanifest=:id")->execute([':id'=>$idM]);
            flash('sucesso', 'Manifestação arquivada.');
        }
        header('Location: ' . BASE_URL . 'app/painel/adm.php'); exit;
    }

    // Desarquivar manifestação
    if ($_POST['acao'] === 'desarquivar') {
        $idM = (int)($_POST['id_manifest'] ?? 0);
        if ($idM > 0) {
            $pdo->prepare("UPDATE tbmanifest SET arquivada=0 WHERE IDmanifest=:id")->execute([':id'=>$idM]);
            flash('sucesso', 'Manifestação restaurada.');
        }
        header('Location: ' . BASE_URL . 'app/painel/adm.php?arquivadas=1'); exit;
    }

    // Excluir manifestação
    if ($_POST['acao'] === 'excluir') {
        $idM = (int)($_POST['id_manifest'] ?? 0);
        if ($idM > 0) {
            $pdo->prepare("DELETE FROM tbmanifest WHERE IDmanifest=:id")->execute([':id'=>$idM]);
            flash('sucesso', 'Manifestação excluída permanentemente.');
        }
        header('Location: ' . BASE_URL . 'app/painel/adm.php'); exit;
    }


    // Enviar resposta ao usuário
    if ($_POST['acao'] === 'responder') {
        $idM      = (int)($_POST['id_manifest'] ?? 0);
        $mensagem = trim($_POST['mensagem'] ?? '');

        if ($idM > 0 && $mensagem !== '') {
            $pdo->prepare("
                INSERT INTO respostas_manifest
                    (IDmanifest, IDadm, mensagem, autor_nome, autor_tipo, lida_pelo_usuario)
                VALUES (:idm, :ida, :msg, :nome, 'adm', 0)
            ")->execute([':idm'=>$idM, ':ida'=>$idAdm, ':msg'=>$mensagem, ':nome'=>$_SESSION['admin']['nome']]);

            $stmtM = $pdo->prepare('SELECT IDusu, protocolo FROM tbmanifest WHERE IDmanifest=:id LIMIT 1');
            $stmtM->execute([':id'=>$idM]);
            $m = $stmtM->fetch();

            if ($m && $m['IDusu']) {
                criarNotificacao($pdo, [
                    'IDusu'    => (int)$m['IDusu'],
                    'tipo'     => 'nova_resposta',
                    'titulo'   => 'O Grêmio respondeu sua manifestação',
                    'mensagem' => 'Protocolo '.$m['protocolo'].': clique para ver a resposta.',
                    'link'     => BASE_URL . 'app/acompanhar.php?protocolo='.urlencode($m['protocolo']),
                ]);
            }
            flash('sucesso', 'Resposta enviada com sucesso.');
        } else {
            flash('erro', 'Mensagem não pode estar vazia.');
        }
        header('Location: ' . BASE_URL . 'app/painel/adm.php#manifest-'.$idM);
        exit;
    }
}

// ── Exportar CSV ──────────────────────────────────────────────────────────
if (isset($_GET['exportar_csv'])) {
    $sqlCsv = "SELECT m.protocolo, t.descricao AS tipo, m.status, m.nome_manifestante,
                      m.perfil_manifestante, m.turma_setor, m.setor_relacionado,
                      m.assunto, m.criado_em, m.contato
               FROM tbmanifest m INNER JOIN tipos t ON t.IDtipo = m.IDtipo
               WHERE m.arquivada = 0 ORDER BY m.criado_em DESC";
    $rows = $pdo->query($sqlCsv)->fetchAll();
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="manifestacoes_' . date('Y-m-d') . '.csv"');
    header('Cache-Control: no-cache');
    echo "\xEF\xBB\xBF"; // BOM UTF-8 para Excel
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Protocolo','Tipo','Status','Nome','Perfil','Turma','Setor','Assunto','Data','Contato'], ';');
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['protocolo'], $r['tipo'], $r['status'], $r['nome_manifestante'],
            $r['perfil_manifestante'], $r['turma_setor'] ?? '', $r['setor_relacionado'] ?? '',
            $r['assunto'], date('d/m/Y H:i', strtotime($r['criado_em'])), $r['contato'] ?? ''
        ], ';');
    }
    fclose($out);
    exit;
}

// ── Filtros ───────────────────────────────────────────────────────────────
$fTipo       = trim($_GET['tipo']        ?? '');
$fStatus     = trim($_GET['status']      ?? '');
$fTurma      = trim($_GET['turma_setor'] ?? '');
$fBusca      = trim($_GET['busca']       ?? '');
$fDataInicio = trim($_GET['data_inicio'] ?? '');
$fDataFim    = trim($_GET['data_fim']    ?? '');
$verArquivadas = isset($_GET['arquivadas']);

// ── Paginação ─────────────────────────────────────────────────────────────
$porPagina  = 10;
$paginaAtual_adm = max(1, (int)($_GET['pagina'] ?? 1));

$sqlBase = "
    FROM tbmanifest m
    INNER JOIN tipos t ON t.IDtipo = m.IDtipo
    WHERE m.arquivada = " . ($verArquivadas ? 1 : 0) . "
";
$params = [];
if ($fTipo)       { $sqlBase .= " AND t.descricao = :tipo";    $params[':tipo']   = $fTipo; }
if ($fStatus)     { $sqlBase .= " AND m.status = :status";     $params[':status'] = $fStatus; }
if ($fTurma)      { $sqlBase .= " AND m.turma_setor = :turma"; $params[':turma']  = $fTurma; }
if ($fBusca)      { $sqlBase .= " AND (m.assunto LIKE :busca OR m.protocolo LIKE :busca OR m.nome_manifestante LIKE :busca)";
                    $params[':busca'] = '%'.$fBusca.'%'; }
if ($fDataInicio) { $sqlBase .= " AND DATE(m.criado_em) >= :di"; $params[':di'] = $fDataInicio; }
if ($fDataFim)    { $sqlBase .= " AND DATE(m.criado_em) <= :df"; $params[':df'] = $fDataFim; }

// Total para paginação
$totalStmt = $pdo->prepare("SELECT COUNT(*) " . $sqlBase);
$totalStmt->execute($params);
$totalRegistros = (int)$totalStmt->fetchColumn();
$totalPaginas   = max(1, (int)ceil($totalRegistros / $porPagina));
$paginaAtual_adm = min($paginaAtual_adm, $totalPaginas);
$offset = ($paginaAtual_adm - 1) * $porPagina;

$sql = "SELECT m.*, t.descricao AS tipo_descricao,
        (SELECT COUNT(*) FROM respostas_manifest r
         WHERE r.IDmanifest = m.IDmanifest AND r.lida_pelo_adm = 0 AND r.autor_tipo = 'usuario') AS novas_respostas
        " . $sqlBase . " ORDER BY novas_respostas DESC, m.criado_em DESC
        LIMIT :lim OFFSET :off";

$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->bindValue(':lim', $porPagina, PDO::PARAM_INT);
$stmt->bindValue(':off', $offset,    PDO::PARAM_INT);
$stmt->execute();
$manifestacoes = $stmt->fetchAll();

$resumo = $pdo->query("
    SELECT COUNT(*) AS total,
           SUM(status='Recebida') AS recebidas,
           SUM(status='Em andamento') AS andamento,
           SUM(status='Resolvida') AS resolvidas
    FROM tbmanifest
    WHERE arquivada = 0
")->fetch() ?: ['total'=>0,'recebidas'=>0,'andamento'=>0,'resolvidas'=>0];

$tituloPagina = 'Painel ADM — Ouvidoria do Grêmio';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
  <div class="page-header-inner">
    <span class="section-label">PAINEL ADMINISTRATIVO</span>
    <h1>Painel <em>do Grêmio</em></h1>
    <p>Gerencie manifestações, responda alunos e atualize status.</p>
  </div>
</div>

<section class="form-section">
<div class="container-fluid px-3 px-md-4" style="max-width:1200px;">

  <!-- Cards resumo -->
  <div class="row g-3 mb-4">
    <?php
    $resumoCards = [
      ['label'=>'Total',        'val'=>$resumo['total'],     'cor'=>'var(--verde-escuro)','bg'=>'#f8fafc','borda'=>'#e5e7eb'],
      ['label'=>'Recebidas',    'val'=>$resumo['recebidas'], 'cor'=>'#c2410c',            'bg'=>'#fff7ed','borda'=>'#fed7aa'],
      ['label'=>'Em andamento', 'val'=>$resumo['andamento'], 'cor'=>'#1d4ed8',            'bg'=>'#eff6ff','borda'=>'#bfdbfe'],
      ['label'=>'Resolvidas',   'val'=>$resumo['resolvidas'],'cor'=>'#047857',            'bg'=>'#ecfdf5','borda'=>'#bbf7d0'],
    ];
    foreach ($resumoCards as $rc): ?>
    <div class="col-6 col-md-3">
      <div class="card border-0 shadow-sm text-center py-3" style="background:<?= $rc['bg'] ?>;border:1px solid <?= $rc['borda'] ?> !important;border-radius:16px">
        <div class="small" style="color:<?= $rc['cor'] ?>;font-weight:600"><?= $rc['label'] ?></div>
        <div class="fw-bold" style="font-size:2rem;color:<?= $rc['cor'] ?>"><?= (int)$rc['val'] ?></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Filtros -->
  <div class="card border-0 shadow-sm mb-4">
    <div class="card-body py-3">
      <form method="get" class="row g-2 align-items-end">
        <div class="col-12 col-sm-6 col-md-2">
          <label class="form-label small fw-semibold mb-1">Tipo</label>
          <select name="tipo" class="form-select form-select-sm">
            <option value="">Todos</option>
            <?php foreach(['Sugestão','Elogio','Reclamação','Denúncia'] as $t): ?>
              <option value="<?= e($t) ?>" <?= $fTipo===$t?'selected':'' ?>><?= e($t) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-12 col-sm-6 col-md-2">
          <label class="form-label small fw-semibold mb-1">Status</label>
          <select name="status" class="form-select form-select-sm">
            <option value="">Todos</option>
            <?php foreach(['Recebida','Em andamento','Resolvida'] as $s): ?>
              <option value="<?= e($s) ?>" <?= $fStatus===$s?'selected':'' ?>><?= e($s) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-12 col-sm-6 col-md-2">
          <label class="form-label small fw-semibold mb-1">Data início</label>
          <input type="date" name="data_inicio" class="form-control form-control-sm" value="<?= e($fDataInicio) ?>">
        </div>
        <div class="col-12 col-sm-6 col-md-2">
          <label class="form-label small fw-semibold mb-1">Data fim</label>
          <input type="date" name="data_fim" class="form-control form-control-sm" value="<?= e($fDataFim) ?>">
        </div>
        <div class="col-12 col-sm-6 col-md-2">
          <label class="form-label small fw-semibold mb-1">Busca</label>
          <input type="text" name="busca" class="form-control form-control-sm"
                 placeholder="Assunto, protocolo…" value="<?= e($fBusca) ?>">
        </div>
        <div class="col-12 col-md-2 d-flex gap-2 flex-wrap">
          <button type="submit" class="btn btn-sm flex-fill" style="background:var(--verde);color:#fff">
            <i class="fa-solid fa-filter me-1"></i>Filtrar
          </button>
          <a href="adm.php" class="btn btn-sm btn-outline-secondary flex-fill">Limpar</a>
        </div>
      </form>

      <!-- Linha de ações secundárias -->
      <div class="d-flex gap-2 flex-wrap mt-2 pt-2 border-top">
        <a href="adm.php?exportar_csv=1" class="btn btn-sm btn-outline-secondary">
          <i class="fa-solid fa-file-csv me-1"></i>Exportar CSV
        </a>
        <?php if ($verArquivadas): ?>
          <a href="adm.php" class="btn btn-sm btn-outline-secondary">
            <i class="fa-solid fa-inbox me-1"></i>Ver ativas
          </a>
        <?php else: ?>
          <a href="adm.php?arquivadas=1" class="btn btn-sm btn-outline-secondary">
            <i class="fa-solid fa-box-archive me-1"></i>Ver arquivadas
          </a>
        <?php endif; ?>
        <span class="text-muted small align-self-center ms-auto">
          <?= $totalRegistros ?> resultado(s) · Página <?= $paginaAtual_adm ?> de <?= $totalPaginas ?>
        </span>
      </div>
    </div>
  </div>

  <!-- Lista de manifestações -->
  <?php if (empty($manifestacoes)): ?>
    <div class="card border-0 shadow-sm text-center py-5">
      <div class="text-muted"><i class="fa-solid fa-inbox fa-2x mb-2 d-block"></i>Nenhuma manifestação encontrada.</div>
    </div>
  <?php else: ?>
    <?php foreach ($manifestacoes as $m): ?>
      <?php
        $idM = (int)$m['IDmanifest'];

        // Respostas
        $stmtR = $pdo->prepare('SELECT * FROM respostas_manifest WHERE IDmanifest=:id ORDER BY criado_em ASC');
        $stmtR->execute([':id'=>$idM]);
        $respostas = $stmtR->fetchAll();
        // Marcar como lidas pelo adm
        $pdo->prepare("UPDATE respostas_manifest SET lida_pelo_adm=1 WHERE IDmanifest=:id AND autor_tipo='usuario'")->execute([':id'=>$idM]);

        // Arquivos
        $stmtA = $pdo->prepare('SELECT * FROM arquivos_manifest WHERE IDmanifest=:id ORDER BY criado_em ASC');
        $stmtA->execute([':id'=>$idM]);
        $arquivos = $stmtA->fetchAll();
      ?>
      <div class="card border-0 shadow-sm mb-4" id="manifest-<?= $idM ?>">

        <!-- Cabeçalho do card -->
        <div class="card-header bg-white border-bottom py-3">
          <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
            <div>
              <div class="text-muted" style="font-size:.7rem;letter-spacing:1px;text-transform:uppercase">Protocolo</div>
              <strong style="color:var(--verde-escuro);font-size:1rem"><?= e($m['protocolo']) ?></strong>
              <div class="text-muted" style="font-size:.78rem;margin-top:2px">
                Enviado em <?= date('d/m/Y H:i', strtotime($m['criado_em'])) ?>
                <?php if ($m['data_ocorrencia']): ?>· Ocorrência: <?= date('d/m/Y', strtotime($m['data_ocorrencia'])) ?><?php endif; ?>
              </div>
            </div>
            <div class="d-flex gap-2 flex-wrap align-items-center">
              <?php if ((int)$m['novas_respostas'] > 0): ?>
                <span class="badge rounded-pill" style="background:#e84040;font-size:.72rem">
                  <i class="fa-solid fa-comment me-1"></i><?= (int)$m['novas_respostas'] ?> nova(s)
                </span>
              <?php endif; ?>
              <span class="badge-curso <?= e(classeCursoAdm($m['turma_setor'] ?? '')) ?>"><?= e($m['turma_setor'] ?: 'Sem turma') ?></span>
              <span class="<?= e(classeStatusAdm($m['status'] ?? '')) ?>"
                    style="padding:4px 12px;border-radius:999px;font-size:.78rem;font-weight:700;display:inline-block">
                <?= e($m['status'] ?? '—') ?>
              </span>
              <!-- Ações -->
              <?php if (!($m['arquivada'] ?? 0)): ?>
              <form method="post" class="d-inline" onsubmit="return confirm('Arquivar esta manifestação?')">
                <?= csrfInput() ?>
                <input type="hidden" name="acao" value="arquivar">
                <input type="hidden" name="id_manifest" value="<?= $idM ?>">
                <button type="submit" class="btn btn-sm btn-outline-secondary" title="Arquivar" style="border-radius:8px;padding:3px 8px">
                  <i class="fa-solid fa-box-archive" style="font-size:.75rem"></i>
                </button>
              </form>
              <?php else: ?>
              <form method="post" class="d-inline">
                <?= csrfInput() ?>
                <input type="hidden" name="acao" value="desarquivar">
                <input type="hidden" name="id_manifest" value="<?= $idM ?>">
                <button type="submit" class="btn btn-sm btn-outline-success" title="Restaurar" style="border-radius:8px;padding:3px 8px">
                  <i class="fa-solid fa-rotate-left" style="font-size:.75rem"></i>
                </button>
              </form>
              <?php endif; ?>
              <form method="post" class="d-inline" onsubmit="return confirm('Excluir permanentemente? Esta ação não pode ser desfeita.')">
                <?= csrfInput() ?>
                <input type="hidden" name="acao" value="excluir">
                <input type="hidden" name="id_manifest" value="<?= $idM ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger" title="Excluir" style="border-radius:8px;padding:3px 8px">
                  <i class="fa-solid fa-trash" style="font-size:.75rem"></i>
                </button>
              </form>
            </div>
          </div>
        </div>

        <!-- Corpo: accordion para economizar espaço -->
        <div class="card-body p-0">

          <!-- Dados resumidos (sempre visível) -->
          <div class="px-4 py-3 border-bottom">
            <div class="row g-2">
              <div class="col-6 col-md-3"><small class="text-muted d-block">Tipo</small><span class="fw-semibold"><?= e($m['tipo_descricao']) ?></span></div>
              <div class="col-6 col-md-3"><small class="text-muted d-block">Nome</small><span class="fw-semibold"><?= e($m['nome_manifestante']) ?></span></div>
              <div class="col-6 col-md-3"><small class="text-muted d-block">Perfil</small><?= e($m['perfil_manifestante']) ?></div>
              <div class="col-6 col-md-3"><small class="text-muted d-block">Contato</small><?= e($m['contato'] ?? '—') ?></div>
              <div class="col-6 col-md-3"><small class="text-muted d-block">Setor</small><?= e($m['setor_relacionado'] ?? '—') ?></div>
              <div class="col-6 col-md-3"><small class="text-muted d-block">Turma</small><?= e($m['turma_setor'] ?? '—') ?></div>
              <div class="col-12 col-md-6"><small class="text-muted d-block">Assunto</small><span class="fw-semibold"><?= e($m['assunto']) ?></span></div>
            </div>
          </div>

          <!-- Accordion: Descrição + Arquivos + Conversa + Atualizar -->
          <div class="accordion accordion-flush" id="acc-<?= $idM ?>">

            <!-- Descrição -->
            <div class="accordion-item border-0 border-bottom">
              <h2 class="accordion-header">
                <button class="accordion-button collapsed py-2 px-4" style="font-size:.85rem;font-weight:700;color:var(--verde-escuro)"
                        type="button" data-bs-toggle="collapse" data-bs-target="#desc-<?= $idM ?>">
                  <i class="fa-solid fa-align-left me-2"></i>Ver descrição completa
                </button>
              </h2>
              <div id="desc-<?= $idM ?>" class="accordion-collapse collapse" data-bs-parent="">
                <div class="accordion-body px-4 py-3">
                  <div class="p-3 rounded-3" style="background:#f9fafb;border:1px solid #e5e7eb;white-space:pre-wrap;font-size:.88rem"><?= e($m['manifest']) ?></div>
                </div>
              </div>
            </div>

            <!-- Arquivos -->
            <?php if ($arquivos): ?>
            <div class="accordion-item border-0 border-bottom">
              <h2 class="accordion-header">
                <button class="accordion-button collapsed py-2 px-4" style="font-size:.85rem;font-weight:700;color:var(--verde-escuro)"
                        type="button" data-bs-toggle="collapse" data-bs-target="#arq-<?= $idM ?>">
                  <i class="fa-solid fa-paperclip me-2"></i>Arquivos (<?= count($arquivos) ?>)
                </button>
              </h2>
              <div id="arq-<?= $idM ?>" class="accordion-collapse collapse">
                <div class="accordion-body px-4 py-3">
                  <div class="d-flex flex-wrap gap-2">
                    <?php foreach ($arquivos as $arq): ?>
                      <a href="<?= $_base ?>storage/manifestacoes/<?= e($arq['nome_arquivo']) ?>" target="_blank"
                         class="badge d-inline-flex align-items-center gap-1 text-decoration-none"
                         style="background:#e8f5ee;color:#1a6b40;border:1px solid #b7ebd2;padding:7px 12px;font-size:.82rem;border-radius:999px">
                        <i class="fa-solid <?= iconeArquivo($arq['mime_type']) ?>"></i>
                        <?= e(mb_strimwidth($arq['nome_original'],0,30,'…')) ?>
                        <span class="text-muted">(<?= formatarTamanho((int)$arq['tamanho']) ?>)</span>
                      </a>
                    <?php endforeach; ?>
                  </div>
                </div>
              </div>
            </div>
            <?php endif; ?>

            <!-- Conversa -->
            <div class="accordion-item border-0 border-bottom">
              <h2 class="accordion-header">
                <button class="accordion-button py-2 px-4" style="font-size:.85rem;font-weight:700;color:var(--verde-escuro)"
                        type="button" data-bs-toggle="collapse" data-bs-target="#chat-<?= $idM ?>">
                  <i class="fa-solid fa-comments me-2"></i>Conversa
                  (<?= count($respostas) ?>)
                  <?php if ((int)$m['novas_respostas'] > 0): ?>
                    <span class="badge rounded-pill ms-2" style="background:#e84040;font-size:.65rem"><?= (int)$m['novas_respostas'] ?> nova(s)</span>
                  <?php endif; ?>
                </button>
              </h2>
              <div id="chat-<?= $idM ?>" class="accordion-collapse collapse show">
                <div class="accordion-body px-4 py-3">
                  <!-- Mensagens -->
                  <div class="d-flex flex-column gap-2 mb-3" style="max-height:320px;overflow-y:auto;padding-right:4px">
                    <?php if (empty($respostas)): ?>
                      <p class="text-muted small fst-italic mb-0">Nenhuma mensagem ainda. Use o campo abaixo para iniciar a conversa.</p>
                    <?php else: ?>
                      <?php foreach ($respostas as $r): ?>
                        <?php $isAdm = $r['autor_tipo'] === 'adm'; ?>
                        <div class="d-flex <?= $isAdm ? 'justify-content-end' : 'justify-content-start' ?>">
                          <div class="chat-bubble <?= $isAdm ? 'chat-adm' : 'chat-usu' ?>">
                            <div class="chat-autor"><?= e($r['autor_nome']) ?> · <?= date('d/m/Y H:i', strtotime($r['criado_em'])) ?></div>
                            <div class="chat-msg"><?= nl2br(e($r['mensagem'])) ?></div>
                          </div>
                        </div>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </div>
                  <!-- Input rápido de resposta -->
                  <form method="post" class="d-flex gap-2 align-items-end">
                    <?= csrfInput() ?>
                    <input type="hidden" name="acao" value="responder">
                    <input type="hidden" name="id_manifest" value="<?= $idM ?>">
                    <textarea name="mensagem" class="form-control form-control-sm" rows="2"
                              placeholder="Digite sua resposta ao aluno…" style="resize:none;border-radius:12px"></textarea>
                    <button type="submit" class="btn btn-sm flex-shrink-0"
                            style="background:var(--verde);color:#fff;border-radius:12px;padding:10px 16px">
                      <i class="fa-solid fa-paper-plane"></i>
                    </button>
                  </form>
                </div>
              </div>
            </div>

            <!-- Histórico de status -->
            <?php $historico = buscarHistoricoStatus($pdo, $idM); ?>
            <?php if (!empty($historico)): ?>
            <div class="accordion-item border-0 border-bottom">
              <h2 class="accordion-header">
                <button class="accordion-button collapsed py-2 px-4" style="font-size:.85rem;font-weight:700;color:var(--verde-escuro)"
                        type="button" data-bs-toggle="collapse" data-bs-target="#hist-<?= $idM ?>">
                  <i class="fa-solid fa-clock-rotate-left me-2"></i>Histórico de status (<?= count($historico) ?>)
                </button>
              </h2>
              <div id="hist-<?= $idM ?>" class="accordion-collapse collapse">
                <div class="accordion-body px-4 py-3">
                  <div class="timeline-hist">
                    <?php foreach ($historico as $hi): ?>
                      <div class="timeline-hist-item">
                        <div class="timeline-hist-dot"></div>
                        <div class="timeline-hist-content">
                          <div class="d-flex align-items-center gap-2 flex-wrap mb-1">
                            <span class="badge" style="background:#f3f4f6;color:#4b5563;font-size:.75rem;font-weight:600"><?= e($hi['status_anterior']) ?></span>
                            <i class="fa-solid fa-arrow-right" style="color:#9ca3af;font-size:.7rem"></i>
                            <span class="<?= classeStatus($hi['status_novo']) ?>" style="padding:2px 10px;border-radius:999px;font-size:.75rem;font-weight:700"><?= e($hi['status_novo']) ?></span>
                          </div>
                          <div class="text-muted" style="font-size:.78rem">
                            <i class="fa-regular fa-clock me-1"></i><?= date('d/m/Y H:i', strtotime($hi['criado_em'])) ?>
                            <?php if ($hi['adm_nome']): ?> · <i class="fa-solid fa-user-shield me-1"></i><?= e($hi['adm_nome']) ?><?php endif; ?>
                          </div>
                          <?php if ($hi['observacao']): ?>
                            <div class="mt-1 p-2 rounded-2" style="background:#f9fafb;border:1px solid #e5e7eb;font-size:.82rem;color:#374151"><?= nl2br(e($hi['observacao'])) ?></div>
                          <?php endif; ?>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  </div>
                </div>
              </div>
            </div>
            <?php endif; ?>

            <!-- Atualizar status + feedback -->
            <div class="accordion-item border-0">
              <h2 class="accordion-header">
                <button class="accordion-button collapsed py-2 px-4" style="font-size:.85rem;font-weight:700;color:var(--verde-escuro)"
                        type="button" data-bs-toggle="collapse" data-bs-target="#upd-<?= $idM ?>">
                  <i class="fa-solid fa-pen-to-square me-2"></i>Atualizar status / feedback interno
                </button>
              </h2>
              <div id="upd-<?= $idM ?>" class="accordion-collapse collapse">
                <div class="accordion-body px-4 py-3">
                  <form method="post">
                    <?= csrfInput() ?>
                    <input type="hidden" name="acao" value="atualizar_manifestacao">
                    <input type="hidden" name="id_manifest" value="<?= $idM ?>">
                    <div class="row g-3">
                      <div class="col-12 col-md-4">
                        <label class="form-label small fw-semibold">Status</label>
                        <select name="status" class="form-select form-select-sm">
                          <?php foreach(['Recebida','Em andamento','Resolvida'] as $s): ?>
                            <option value="<?= e($s) ?>" <?= ($m['status']===$s)?'selected':'' ?>><?= e($s) ?></option>
                          <?php endforeach; ?>
                        </select>
                      </div>
                      <div class="col-12 col-md-8">
                        <label class="form-label small fw-semibold">Notas internas <span class="text-muted">(não exibidas ao usuário)</span></label>
                        <textarea name="feedback" class="form-control form-control-sm" rows="2"><?= e($m['feedback'] ?? '') ?></textarea>
                      </div>
                    </div>
                    <div class="d-flex justify-content-end mt-2">
                      <button type="submit" class="btn btn-sm btn-outline-secondary">
                        <i class="fa-solid fa-floppy-disk me-1"></i>Salvar
                      </button>
                    </div>
                  </form>
                </div>
              </div>
            </div>

          </div><!-- /accordion -->
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>

  <!-- Paginação -->
  <?php if ($totalPaginas > 1): ?>
  <nav class="d-flex justify-content-center mt-4">
    <ul class="pagination pagination-sm">
      <?php
        $qBase = http_build_query(array_filter([
          'tipo'        => $fTipo,
          'status'      => $fStatus,
          'turma_setor' => $fTurma,
          'busca'       => $fBusca,
          'data_inicio' => $fDataInicio,
          'data_fim'    => $fDataFim,
          'arquivadas'  => $verArquivadas ? '1' : '',
        ]));
      ?>
      <li class="page-item <?= $paginaAtual_adm <= 1 ? 'disabled' : '' ?>">
        <a class="page-link" href="?<?= $qBase ?>&pagina=<?= $paginaAtual_adm - 1 ?>">‹</a>
      </li>
      <?php for ($p = max(1, $paginaAtual_adm-2); $p <= min($totalPaginas, $paginaAtual_adm+2); $p++): ?>
        <li class="page-item <?= $p === $paginaAtual_adm ? 'active' : '' ?>">
          <a class="page-link" href="?<?= $qBase ?>&pagina=<?= $p ?>"
             style="<?= $p === $paginaAtual_adm ? 'background:var(--verde);border-color:var(--verde)' : '' ?>"><?= $p ?></a>
        </li>
      <?php endfor; ?>
      <li class="page-item <?= $paginaAtual_adm >= $totalPaginas ? 'disabled' : '' ?>">
        <a class="page-link" href="?<?= $qBase ?>&pagina=<?= $paginaAtual_adm + 1 ?>">›</a>
      </li>
    </ul>
  </nav>
  <?php endif; ?>

</div>
</section>

<script>
// Spinner de carregamento em todos os botões de submit do painel ADM
document.querySelectorAll('form').forEach(function(form) {
  form.addEventListener('submit', function() {
    var btn = this.querySelector('[type=submit]');
    if (btn && !btn.dataset.noSpinner) {
      btn.disabled = true;
      var icon = btn.querySelector('i');
      if (icon) {
        icon.className = 'fa-solid fa-circle-notch fa-spin';
      }
    }
  });
});
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
