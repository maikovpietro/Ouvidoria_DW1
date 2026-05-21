<?php
require_once __DIR__ . '/../config/bootstrap.php';

$pdo = conectarPDO();
garantirTabelasExtras($pdo);

$ehUsuario = usuarioLogado();
$ehAdmin   = administradorLogado();

if (!$ehUsuario && !$ehAdmin) {
    flash('erro', 'Faça login para ver suas notificações.');
    header('Location: ' . BASE_URL . 'app/auth/login.php');
    exit;
}

$coluna = $ehAdmin ? 'IDadm' : 'IDusu';
$idPessoa = $ehAdmin ? (int)$_SESSION['admin']['id'] : (int)$_SESSION['usuario']['id'];

// AJAX: retorna JSON
if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    header('Content-Type: application/json');
    $notifs = buscarNotificacoes($pdo, $coluna, $idPessoa, 10);
    foreach ($notifs as &$n) {
        $n['criado_em_fmt'] = date('d/m/Y H:i', strtotime($n['criado_em']));
    }
    echo json_encode(array_values($notifs));
    exit;
}

// Marcar como lidas
if (isset($_GET['marcar_lidas'])) {
    marcarNotificacoesLidas($pdo, $coluna, $idPessoa);
    if (isset($_GET['ajax'])) { echo json_encode(['ok'=>true]); exit; }
    header('Location: ' . BASE_URL . 'app/notificacoes.php');
    exit;
}

// Página completa
marcarNotificacoesLidas($pdo, $coluna, $idPessoa);
$notifs = buscarNotificacoes($pdo, $coluna, $idPessoa, 50);

$tituloPagina = 'Notificações — Ouvidoria do Grêmio Escolar';
require_once __DIR__ . '/../includes/header.php';

?>

<div class="page-header">
  <div class="page-header-inner">
    <span class="section-label">CENTRAL DE AVISOS</span>
    <h1>Suas <em>Notificações</em></h1>
    <p>Acompanhe atualizações sobre suas manifestações e respostas do Grêmio Escolar.</p>
  </div>
</div>

<section class="form-section">
  <div class="container" style="max-width:760px;">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h5 class="mb-0 fw-bold" style="color:var(--verde-escuro)">
        <i class="fa-solid fa-bell me-2"></i><?= count($notifs) ?> notificação(ões)
      </h5>
      <?php if ($notifs): ?>
        <a href="<?= $_base ?>app/notificacoes.php?marcar_lidas=1" class="btn btn-sm btn-outline-secondary">
          <i class="fa-solid fa-check-double me-1"></i>Marcar todas como lidas
        </a>
      <?php endif; ?>
    </div>

    <?php if (empty($notifs)): ?>
      <div class="card border-0 shadow-sm text-center py-5">
        <div class="card-body">
          <i class="fa-solid fa-bell-slash fa-3x mb-3 text-muted"></i>
          <h6 class="text-muted">Nenhuma notificação por enquanto.</h6>
          <p class="text-muted small">Você será notificado quando houver atualizações nas suas manifestações.</p>
        </div>
      </div>
    <?php else: ?>
      <div class="card border-0 shadow-sm overflow-hidden">
        <?php foreach ($notifs as $i => $n): ?>
          <a href="<?= e($n['link'] ?? '#') ?>"
             class="d-flex align-items-start gap-3 p-3 text-decoration-none border-bottom notif-page-item <?= !$n['lida'] ? 'notif-page-nao-lida' : '' ?>">
            <div class="notif-page-icon flex-shrink-0">
              <i class="fa-solid <?= iconeNotificacao($n['tipo']) ?>"></i>
            </div>
            <div class="flex-grow-1">
              <div class="fw-semibold" style="color:var(--verde-escuro);font-size:.93rem"><?= e($n['titulo']) ?></div>
              <div class="text-muted" style="font-size:.85rem"><?= e($n['mensagem']) ?></div>
              <div class="text-muted" style="font-size:.78rem;margin-top:4px">
                <i class="fa-regular fa-clock me-1"></i><?= date('d/m/Y H:i', strtotime($n['criado_em'])) ?>
              </div>
            </div>
            <?php if (!$n['lida']): ?>
              <span class="flex-shrink-0 badge rounded-pill" style="background:var(--verde);font-size:.7rem;align-self:center;">Nova</span>
            <?php endif; ?>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</section>


<?php require_once __DIR__ . '/../includes/footer.php'; ?>
