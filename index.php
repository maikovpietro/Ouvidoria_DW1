<?php
$tituloPagina = 'Ouvidoria do Grêmio Escolar — Dom Walfrido';
require_once __DIR__ . '/includes/header.php';

// Busca estatísticas para o admin
$_idx_stats = ['total'=>0,'recebidas'=>0,'andamento'=>0,'resolvidas'=>0];
if (administradorLogado()) {
    try {
        $_idx_pdo = conectarPDO();
        $_idx_row = $_idx_pdo->query("
            SELECT COUNT(*) AS total,
                   SUM(status='Recebida')      AS recebidas,
                   SUM(status='Em andamento')  AS andamento,
                   SUM(status='Resolvida')     AS resolvidas
            FROM tbmanifest
        ")->fetch();
        if ($_idx_row) $_idx_stats = $_idx_row;
    } catch(Exception $e) {}
}
?>

<?php if (administradorLogado()): ?>
<!-- ═══════════════════════════════════════════
     HERO — ADMIN
═══════════════════════════════════════════ -->
<section class="hero">
  <div class="hero-grid-bg"></div>
  <div class="hero-inner">
    <div class="hero-text">
      <div class="hero-eyebrow"><span class="eyebrow-dot"></span>Painel de Administração</div>
      <h1 class="hero-title">Bem-vindo,<br><em>Administrador</em>.</h1>
      <p class="hero-desc">Gerencie as manifestações, acompanhe os indicadores e responda aos alunos pelo Painel do Grêmio.</p>
      <div class="hero-actions">
        <a href="<?= $_base ?>app/painel/adm.php" class="btn-primary-main"><i class="fa-solid fa-shield-halved"></i> Painel do Grêmio</a>
        <a href="<?= $_base ?>app/painel/dashboard.php" class="btn-secondary-main"><i class="fa-solid fa-chart-line"></i> Dashboard</a>
      </div>
    </div>
    <div class="hero-logos-block">
      <div class="logos-frame">
        <img src="<?= $_base ?>assets/images/logo-escola.png" alt="EEEP Dom Walfrido" class="hero-logo-escola">
        <div class="logos-divider"></div>
        <img src="<?= $_base ?>assets/images/logo-ceara.png"  alt="Governo Ceará" class="hero-logo-ceara">
      </div>
      <div class="hero-card-float">
        <div class="float-item"><i class="fa-solid fa-inbox"></i><div><strong><?= (int)$_idx_stats['recebidas'] ?> recebida<?= (int)$_idx_stats['recebidas'] !== 1 ? 's' : '' ?></strong><span>Aguardando análise</span></div></div>
        <div class="float-item"><i class="fa-solid fa-spinner"></i><div><strong><?= (int)$_idx_stats['andamento'] ?> em andamento</strong><span>Em processo de resposta</span></div></div>
        <div class="float-item"><i class="fa-solid fa-circle-check"></i><div><strong><?= (int)$_idx_stats['resolvidas'] ?> resolvida<?= (int)$_idx_stats['resolvidas'] !== 1 ? 's' : '' ?></strong><span>Manifestações concluídas</span></div></div>
      </div>
    </div>
  </div>
</section>

<!-- ═══════════════════════════════════════════
     AÇÕES RÁPIDAS — ADMIN
═══════════════════════════════════════════ -->
<section class="section tipos-section">
  <div class="section-header">
    <div class="section-label">ACESSO RÁPIDO</div>
    <h2 class="section-title">Ferramentas de <em>gestão</em></h2>
    <p class="section-desc">Tudo que você precisa para administrar a Ouvidoria do Grêmio em um só lugar.</p>
  </div>
  <div class="tipos-grid">
    <a href="<?= $_base ?>app/painel/adm.php" class="tipo-card tipo-denuncia" style="text-decoration:none;display:block;">
      <div class="tipo-icon"><i class="fa-solid fa-shield-halved"></i></div>
      <h3>Painel do Grêmio</h3>
      <p>Visualize, filtre e responda todas as manifestações enviadas pelos alunos.</p>
      <span class="tipo-link">Acessar <i class="fa-solid fa-arrow-right"></i></span>
    </a>
    <a href="<?= $_base ?>app/painel/dashboard.php" class="tipo-card tipo-elogio" style="text-decoration:none;display:block;">
      <div class="tipo-icon"><i class="fa-solid fa-chart-line"></i></div>
      <h3>Dashboard</h3>
      <p>Gráficos e indicadores com visão geral das manifestações por período e tipo.</p>
      <span class="tipo-link">Visualizar <i class="fa-solid fa-arrow-right"></i></span>
    </a>
    <a href="<?= $_base ?>app/notificacoes.php" class="tipo-card tipo-sugestao" style="text-decoration:none;display:block;">
      <div class="tipo-icon"><i class="fa-solid fa-bell"></i></div>
      <h3>Notificações</h3>
      <p>Veja atualizações e alertas das manifestações que precisam de atenção.</p>
      <span class="tipo-link">Verificar <i class="fa-solid fa-arrow-right"></i></span>
    </a>
    <div class="tipo-card tipo-reclamacao">
      <div class="tipo-icon"><i class="fa-solid fa-chart-pie"></i></div>
      <h3>Resumo geral</h3>
      <p>
        <strong><?= (int)$_idx_stats['total'] ?></strong> manifestaç<?= (int)$_idx_stats['total'] === 1 ? 'ão registrada' : 'ões registradas' ?> no total —
        <strong><?= (int)$_idx_stats['recebidas'] ?></strong> pendente<?= (int)$_idx_stats['recebidas'] !== 1 ? 's' : '' ?>,
        <strong><?= (int)$_idx_stats['andamento'] ?></strong> em andamento,
        <strong><?= (int)$_idx_stats['resolvidas'] ?></strong> resolvida<?= (int)$_idx_stats['resolvidas'] !== 1 ? 's' : '' ?>.
      </p>
      <a href="<?= $_base ?>app/painel/adm.php" class="tipo-link">Ver todas <i class="fa-solid fa-arrow-right"></i></a>
    </div>
  </div>
</section>

<?php else: ?>
<!-- ═══════════════════════════════════════════
     HERO — PÚBLICO / ALUNO
═══════════════════════════════════════════ -->
<section class="hero">
  <div class="hero-grid-bg"></div>
  <div class="hero-inner">
    <div class="hero-text">
      <div class="hero-eyebrow"><span class="eyebrow-dot"></span>Canal oficial do Grêmio Escolar</div>
      <h1 class="hero-title">Sua voz <br><em>fortalece</em> a<br>escola.</h1>
      <p class="hero-desc">A Ouvidoria do Grêmio Escolar da EEEP Dom Walfrido Teixeira Vieira é um espaço de escuta, participação e melhoria da vida estudantil.</p>
      <div class="hero-actions">
        <a href="<?= $_base ?>app/manifestacao.php" class="btn-primary-main"><i class="fa-solid fa-paper-plane"></i> Fazer Manifestação</a>
        <?php if (usuarioLogado()): ?>
          <a href="<?= $_base ?>app/painel/minha_conta.php" class="btn-secondary-main"><i class="fa-solid fa-user"></i> Minha conta</a>
          <a href="<?= $_base ?>app/acompanhar.php" class="btn-outline-main"><i class="fa-solid fa-magnifying-glass"></i> Acompanhar</a>
        <?php else: ?>
          <a href="<?= $_base ?>app/auth/login.php" class="btn-secondary-main"><i class="fa-solid fa-right-to-bracket"></i> Login</a>
          <a href="<?= $_base ?>app/auth/forgot_password.php" class="btn-outline-main"><i class="fa-solid fa-key"></i> Esqueci a senha</a>
        <?php endif; ?>
      </div>
    </div>
    <div class="hero-logos-block">
      <div class="logos-frame">
        <img src="<?= $_base ?>assets/images/logo-escola.png" alt="EEEP Dom Walfrido" class="hero-logo-escola">
        <div class="logos-divider"></div>
        <img src="<?= $_base ?>assets/images/logo-ceara.png"  alt="Governo Ceará" class="hero-logo-ceara">
      </div>
      <div class="hero-card-float">
        <div class="float-item"><i class="fa-solid fa-comments"></i><div><strong>Escuta ativa</strong><span>Espaço para sugestões, elogios e denúncias</span></div></div>
        <div class="float-item"><i class="fa-solid fa-user-shield"></i><div><strong>Área do administrador</strong><span>Painel protegido por login</span></div></div>
        <div class="float-item"><i class="fa-solid fa-school"></i><div><strong>Grêmio Escolar</strong><span>Participação estudantil com responsabilidade</span></div></div>
      </div>
    </div>
  </div>
</section>

<section class="section tipos-section">
  <div class="section-header">
    <div class="section-label">PARTICIPE</div>
    <h2 class="section-title">Canais de <em>manifestação</em></h2>
    <p class="section-desc">Toda manifestação enviada ao grêmio é registrada para análise e encaminhamento.</p>
  </div>
  <div class="tipos-grid">
    <div class="tipo-card tipo-sugestao"><div class="tipo-icon"><i class="fa-solid fa-lightbulb"></i></div><h3>Sugestão</h3><p>Compartilhe ideias para melhorar eventos, espaços, comunicação e ações estudantis.</p><a href="<?= $_base ?>app/manifestacao.php?tipo=sugestao" class="tipo-link">Registrar <i class="fa-solid fa-arrow-right"></i></a></div>
    <div class="tipo-card tipo-elogio"><div class="tipo-icon"><i class="fa-solid fa-hands-clapping"></i></div><h3>Elogio</h3><p>Valorize projetos, professores, representantes e atitudes positivas na escola.</p><a href="<?= $_base ?>app/manifestacao.php?tipo=elogio" class="tipo-link">Registrar <i class="fa-solid fa-arrow-right"></i></a></div>
    <div class="tipo-card tipo-reclamacao"><div class="tipo-icon"><i class="fa-solid fa-triangle-exclamation"></i></div><h3>Reclamação</h3><p>Informe situações que precisam de correção, apoio ou mediação.</p><a href="<?= $_base ?>app/manifestacao.php?tipo=reclamacao" class="tipo-link">Registrar <i class="fa-solid fa-arrow-right"></i></a></div>
    <div class="tipo-card tipo-denuncia"><div class="tipo-icon"><i class="fa-solid fa-scale-balanced"></i></div><h3>Denúncia</h3><p>Relate fatos sensíveis com seriedade. O painel do grêmio exige autenticação para análise.</p><a href="<?= $_base ?>app/manifestacao.php?tipo=denuncia" class="tipo-link">Registrar <i class="fa-solid fa-arrow-right"></i></a></div>
  </div>
</section>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
