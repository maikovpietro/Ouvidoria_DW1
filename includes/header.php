<?php
require_once __DIR__ . '/../config/bootstrap.php';
$paginaAtual = basename($_SERVER['PHP_SELF']);
$flashData   = flash();

// BASE_URL já definida em config.php com detecção robusta
$_base = BASE_URL;

// Contagem de notificações não lidas
$_pdo_hdr = null;
$_notifsNaoLidas = 0;
try {
    $_pdo_hdr = conectarPDO();
    garantirTabelasExtras($_pdo_hdr);
    if (usuarioLogado()) {
        $_notifsNaoLidas = contarNotificacoesNaoLidas($_pdo_hdr, 'IDusu', (int)$_SESSION['usuario']['id']);
    } elseif (administradorLogado()) {
        $_notifsNaoLidas = contarNotificacoesNaoLidas($_pdo_hdr, 'IDadm', (int)$_SESSION['admin']['id']);
    }
} catch (Exception $e) {}

// Monta URL absoluta do CSS para evitar problemas com caminhos relativos
$_cssUrl  = (defined('APP_URL') ? APP_URL : '') . '/assets/css/style.css';
// Fallback: usa BASE_URL relativo (funciona na maioria dos casos)
$_cssHref = $_base . 'assets/css/style.css?v=2.0.2';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?= isset($tituloPagina) ? e($tituloPagina) : 'Ouvidoria do Grêmio Escolar' ?></title>
  <!-- Bootstrap 5 -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/css/bootstrap.min.css">
  <!-- Google Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Sora:wght@600;700;800&display=swap" rel="stylesheet">
  <!-- Font Awesome -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
  <!-- Custom CSS: sempre depois do Bootstrap para sobrescrever -->
  <link rel="stylesheet" href="<?= $_cssHref ?>">
</head>
<body>

<header class="topbar">
  <button class="topbar-hamburger" id="hamburgerBtn" aria-label="Menu"><span></span><span></span><span></span></button>
  <a href="<?= $_base ?>index.php" class="topbar-brand">
    <div><span class="topbar-brand-name">Dom Walfrido</span><span class="topbar-brand-sub">Ouvidoria do Grêmio Escolar</span></div>
  </a>
  <div class="topbar-divider"></div>
  <nav class="topbar-nav">
    <a href="<?= $_base ?>index.php"        class="topbar-link <?= topoAtivo($paginaAtual,'index.php') ?>"><i class="fa-solid fa-house"></i> Início</a>
    <?php if (!administradorLogado()): ?>
    <a href="<?= $_base ?>app/sobre.php"        class="topbar-link <?= topoAtivo($paginaAtual,'sobre.php') ?>"><i class="fa-solid fa-school"></i> Sobre a Escola</a>
    <a href="<?= $_base ?>app/manifestacao.php" class="topbar-link <?= topoAtivo($paginaAtual,'manifestacao.php') ?>"><i class="fa-solid fa-paper-plane"></i> Fazer Manifestação</a>
    <a href="<?= $_base ?>app/acompanhar.php"   class="topbar-link <?= topoAtivo($paginaAtual,'acompanhar.php') ?>"><i class="fa-solid fa-magnifying-glass"></i> Acompanhar</a>
    <?php endif; ?>
    <?php if (usuarioLogado()): ?>
      <a href="<?= $_base ?>app/painel/minha_conta.php" class="topbar-link <?= topoAtivo($paginaAtual,'minha_conta.php') ?>"><i class="fa-solid fa-user"></i> Minha conta</a>
    <?php elseif (!administradorLogado()): ?>
      <a href="<?= $_base ?>app/auth/login.php" class="topbar-link <?= topoAtivo($paginaAtual,'login.php') ?>"><i class="fa-solid fa-right-to-bracket"></i> Login</a>
    <?php endif; ?>
    <?php if (administradorLogado()): ?>
      <a href="<?= $_base ?>app/painel/dashboard.php" class="topbar-link <?= topoAtivo($paginaAtual,'dashboard.php') ?>"><i class="fa-solid fa-chart-line"></i> Dashboard</a>
      <a href="<?= $_base ?>app/painel/adm.php" class="topbar-link <?= topoAtivo($paginaAtual,'adm.php') ?>"><i class="fa-solid fa-shield-halved"></i> Painel do Grêmio</a>
    <?php endif; ?>
  </nav>

  <div class="topbar-actions">
    <?php if (administradorLogado() || usuarioLogado()): ?>
      <!-- Sino de notificações -->
      <div class="notif-bell-wrap dropdown">
        <button class="notif-bell btn p-0" id="notifDropdown" data-bs-toggle="dropdown" data-notif-url="<?= $_base ?>app/notificacoes.php" aria-expanded="false"
                data-coluna="<?= administradorLogado() ? 'IDadm' : 'IDusu' ?>"
                data-id="<?= administradorLogado() ? (int)$_SESSION['admin']['id'] : (int)$_SESSION['usuario']['id'] ?>">
          <i class="fa-solid fa-bell"></i>
          <?php if ($_notifsNaoLidas > 0): ?>
            <span class="notif-badge"><?= $_notifsNaoLidas > 9 ? '9+' : $_notifsNaoLidas ?></span>
          <?php endif; ?>
        </button>
        <div class="dropdown-menu dropdown-menu-end notif-dropdown" aria-labelledby="notifDropdown">
          <div class="notif-header d-flex justify-content-between align-items-center px-3 py-2">
            <strong>Notificações</strong>
            <a href="<?= $_base ?>app/notificacoes.php?marcar_lidas=1" class="text-muted small notif-marcar-lidas">Marcar todas como lidas</a>
          </div>
          <div class="notif-lista" id="notifLista">
            <div class="text-center py-3 text-muted small"><i class="fa-solid fa-spinner fa-spin"></i> Carregando...</div>
          </div>
          <div class="notif-footer text-center py-2">
            <a href="<?= $_base ?>app/notificacoes.php" class="small text-decoration-none" style="color:var(--verde)">Ver todas as notificações</a>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <?php if (administradorLogado()):
      $_m3_nome     = e($_SESSION['admin']['nome']);
      $_m3_inicial  = mb_strtoupper(mb_substr($_SESSION['admin']['nome'], 0, 1));
      $_m3_role     = 'Administrador';
      $_m3_is_admin = true;
    elseif (usuarioLogado()):
      $_m3_nome     = e($_SESSION['usuario']['nome']);
      $_m3_inicial  = mb_strtoupper(mb_substr($_SESSION['usuario']['nome'], 0, 1));
      $_m3_role     = 'Aluno';
      $_m3_is_admin = false;
    endif; ?>

    <?php if (administradorLogado() || usuarioLogado()): ?>
    <!-- ── M3 User Dropdown ── -->
    <div id="m3DropOverlay"></div>
    <div class="m3-user-trigger-wrap" id="m3UserWrap">
      <button class="m3-user-trigger" id="m3UserTrigger" aria-haspopup="true" aria-expanded="false" aria-controls="m3DropPanel">
        <div class="m3-ripple-surface"><div class="m3-ripple-circle" id="m3TriggerRipple"></div></div>
        <span class="m3-avatar"><?= $_m3_inicial ?></span>
        <span class="m3-trigger-name"><?= $_m3_nome ?></span>
        <svg class="m3-trigger-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
          <polyline points="6 9 12 15 18 9"/>
        </svg>
      </button>

      <div class="m3-dropdown-panel" id="m3DropPanel" role="menu">
        <div class="m3-panel-header">
          <div class="m3-panel-avatar"><?= $_m3_inicial ?></div>
          <div style="overflow:hidden">
            <span class="m3-panel-info-name"><?= $_m3_nome ?></span>
            <span class="m3-panel-info-role"><?= $_m3_role ?></span>
          </div>
        </div>

        <div class="m3-page" id="m3PageMain">
          <?php if ($_m3_is_admin): ?>
            <span class="m3-item-label">Administração</span>
            <a href="<?= $_base ?>app/painel/dashboard.php" class="m3-item" role="menuitem" style="--i:0">
              <span class="m3-item-hover-bg"></span>
              <span class="m3-item-icon"><i class="fa-solid fa-chart-line" style="font-size:0.82rem"></i></span>
              Dashboard
            </a>
            <a href="<?= $_base ?>app/painel/adm.php" class="m3-item" role="menuitem" style="--i:1">
              <span class="m3-item-hover-bg"></span>
              <span class="m3-item-icon"><i class="fa-solid fa-shield-halved" style="font-size:0.82rem"></i></span>
              Painel do Grêmio
            </a>
            <div class="m3-sep" style="--i:2"></div>
            <span class="m3-item-label">Conta</span>
          <?php else: ?>
            <span class="m3-item-label">Minha conta</span>
            <a href="<?= $_base ?>app/painel/minha_conta.php" class="m3-item" role="menuitem" style="--i:0">
              <span class="m3-item-hover-bg"></span>
              <span class="m3-item-icon"><i class="fa-solid fa-user" style="font-size:0.82rem"></i></span>
              Perfil
            </a>
            <a href="<?= $_base ?>app/painel/minha_conta.php#manifestacoes" class="m3-item" role="menuitem" style="--i:1">
              <span class="m3-item-hover-bg"></span>
              <span class="m3-item-icon"><i class="fa-solid fa-list-check" style="font-size:0.82rem"></i></span>
              Minhas Manifestações
            </a>
            <div class="m3-sep" style="--i:2"></div>
            <span class="m3-item-label">Ações</span>
          <?php endif; ?>
          <a href="<?= $_base ?>app/notificacoes.php" class="m3-item" role="menuitem" style="--i:<?= $_m3_is_admin ? 3 : 2 ?>">
            <span class="m3-item-hover-bg"></span>
            <span class="m3-item-icon"><i class="fa-solid fa-bell" style="font-size:0.82rem"></i></span>
            Notificações
            <?php if ($_notifsNaoLidas > 0): ?>
              <span class="notif-badge" style="position:relative;top:0;right:0;margin-left:auto"><?= $_notifsNaoLidas > 9 ? '9+' : $_notifsNaoLidas ?></span>
            <?php endif; ?>
          </a>
          <div class="m3-sep" style="--i:<?= $_m3_is_admin ? 4 : 3 ?>"></div>
          <a href="<?= $_base ?>app/auth/logout.php" class="m3-item m3-danger" role="menuitem" style="--i:<?= $_m3_is_admin ? 5 : 4 ?>">
            <span class="m3-item-hover-bg"></span>
            <span class="m3-item-icon"><i class="fa-solid fa-right-from-bracket" style="font-size:0.82rem"></i></span>
            Sair
          </a>
        </div>
      </div>
    </div>

    <?php else: ?>
      <a href="<?= $_base ?>app/auth/login.php"          class="topbar-btn topbar-btn-login"><i class="fa-solid fa-right-to-bracket"></i> <span>Login</span></a>
      <a href="<?= $_base ?>app/auth/login.php#cadastro" class="topbar-btn topbar-btn-cadastro"><i class="fa-solid fa-user-plus"></i> <span>Cadastrar-se</span></a>
    <?php endif; ?>
  </div>
</header>

<!-- Drawer mobile -->
<aside class="drawer" id="drawerSidebar">
  <div class="drawer-header">
    <div class="drawer-logos">
      <img src="<?= $_base ?>assets/images/logo-escola.png" alt="Logo EEEP Dom Walfrido" class="drawer-logo-img" onerror="this.style.display='none'">
      <img src="<?= $_base ?>assets/images/logo-ceara.png" alt="Governo do Ceará" class="drawer-logo-img" onerror="this.style.display='none'">
    </div>
    <button class="drawer-close" id="drawerClose"><i class="fa-solid fa-xmark"></i></button>
  </div>
  <div class="drawer-brand"><span class="drawer-brand-name">Dom Walfrido</span><span class="drawer-brand-sub">Ouvidoria do Grêmio Escolar</span></div>
  <nav class="drawer-nav">
    <div class="drawer-section-label">NAVEGAÇÃO</div>
    <a href="<?= $_base ?>index.php"        class="drawer-item <?= topoAtivo($paginaAtual,'index.php') ?>"><span class="drawer-item-icon"><i class="fa-solid fa-house"></i></span><span class="drawer-item-label">Início</span></a>
    <?php if (!administradorLogado()): ?>
    <a href="<?= $_base ?>app/sobre.php"        class="drawer-item <?= topoAtivo($paginaAtual,'sobre.php') ?>"><span class="drawer-item-icon"><i class="fa-solid fa-school"></i></span><span class="drawer-item-label">Sobre a Escola</span></a>
    <a href="<?= $_base ?>app/manifestacao.php" class="drawer-item <?= topoAtivo($paginaAtual,'manifestacao.php') ?>"><span class="drawer-item-icon"><i class="fa-solid fa-paper-plane"></i></span><span class="drawer-item-label">Fazer Manifestação</span></a>
    <a href="<?= $_base ?>app/acompanhar.php"   class="drawer-item <?= topoAtivo($paginaAtual,'acompanhar.php') ?>"><span class="drawer-item-icon"><i class="fa-solid fa-magnifying-glass"></i></span><span class="drawer-item-label">Acompanhar Protocolo</span></a>
    <?php endif; ?>
    <?php if (administradorLogado() || usuarioLogado()): ?>
      <a href="<?= $_base ?>app/notificacoes.php" class="drawer-item <?= topoAtivo($paginaAtual,'notificacoes.php') ?>">
        <span class="drawer-item-icon"><i class="fa-solid fa-bell"></i></span>
        <span class="drawer-item-label">Notificações</span>
        <?php if ($_notifsNaoLidas > 0): ?><span class="drawer-badge"><?= $_notifsNaoLidas ?></span><?php endif; ?>
      </a>
    <?php endif; ?>

    <?php if (usuarioLogado()): ?>
      <a href="<?= $_base ?>app/painel/minha_conta.php" class="drawer-item <?= topoAtivo($paginaAtual,'minha_conta.php') ?>"><span class="drawer-item-icon"><i class="fa-solid fa-user"></i></span><span class="drawer-item-label">Minha conta</span></a>
      <a href="<?= $_base ?>app/painel/minha_conta.php#manifestacoes" class="drawer-item"><span class="drawer-item-icon"><i class="fa-solid fa-list-check"></i></span><span class="drawer-item-label">Minhas manifestações</span></a>
      <a href="<?= $_base ?>app/auth/logout.php" class="drawer-item"><span class="drawer-item-icon"><i class="fa-solid fa-right-from-bracket"></i></span><span class="drawer-item-label">Logout</span></a>
    <?php elseif (administradorLogado()): ?>
      <div class="drawer-section-label">ADMINISTRAÇÃO</div>
      <a href="<?= $_base ?>app/painel/dashboard.php" class="drawer-item <?= topoAtivo($paginaAtual,'dashboard.php') ?>"><span class="drawer-item-icon"><i class="fa-solid fa-chart-line"></i></span><span class="drawer-item-label">Dashboard</span></a>
      <a href="<?= $_base ?>app/painel/adm.php" class="drawer-item <?= topoAtivo($paginaAtual,'adm.php') ?>"><span class="drawer-item-icon"><i class="fa-solid fa-shield-halved"></i></span><span class="drawer-item-label">Painel do Grêmio</span></a>
      <a href="<?= $_base ?>app/auth/logout.php" class="drawer-item"><span class="drawer-item-icon"><i class="fa-solid fa-right-from-bracket"></i></span><span class="drawer-item-label">Sair</span></a>
    <?php else: ?>
      <div class="drawer-section-label">ACESSO</div>
      <a href="<?= $_base ?>app/auth/login.php"          class="drawer-item <?= topoAtivo($paginaAtual,'login.php') ?>"><span class="drawer-item-icon"><i class="fa-solid fa-right-to-bracket"></i></span><span class="drawer-item-label">Login</span></a>
      <a href="<?= $_base ?>app/auth/login.php#cadastro" class="drawer-item"><span class="drawer-item-icon"><i class="fa-solid fa-user-plus"></i></span><span class="drawer-item-label">Cadastrar-se</span></a>
      <a href="<?= $_base ?>app/auth/forgot_password.php" class="drawer-item <?= topoAtivo($paginaAtual,'forgot_password.php') ?>"><span class="drawer-item-icon"><i class="fa-solid fa-key"></i></span><span class="drawer-item-label">Esqueci a senha</span></a>
    <?php endif; ?>
  </nav>
  <div class="drawer-footer">
    <div class="drawer-contact"><i class="fa-solid fa-phone"></i><span>(88) 93677-4295</span></div>
    <div class="drawer-contact"><i class="fa-solid fa-envelope"></i><span>walfridoteixeira@escola.ce.gov.br</span></div>
  </div>
</aside>
<div class="drawer-overlay" id="drawerOverlay"></div>

<!-- ── M3 Dropdown JS ── -->
<script>
(function () {
  const trigger  = document.getElementById("m3UserTrigger");
  const panel    = document.getElementById("m3DropPanel");
  const overlay  = document.getElementById("m3DropOverlay");
  const ripple   = document.getElementById("m3TriggerRipple");
  if (!trigger || !panel) return;
  let open = false, closeTimer = null;
  function openPanel() {
    if (open) return; open = true;
    clearTimeout(closeTimer);
    overlay.classList.add("active");
    panel.classList.remove("m3-closing");
    panel.classList.add("m3-open");
    trigger.setAttribute("aria-expanded","true");
  }
  function closePanel() {
    if (!open) return; open = false;
    panel.classList.add("m3-closing");
    trigger.setAttribute("aria-expanded","false");
    overlay.classList.remove("active");
    closeTimer = setTimeout(() => panel.classList.remove("m3-open","m3-closing"), 300);
  }
  trigger.addEventListener("click", (e) => { e.stopPropagation(); open ? closePanel() : openPanel(); doRipple(e); });
  overlay.addEventListener("click", closePanel);
  document.addEventListener("keydown", (e) => { if (e.key==="Escape"&&open){ closePanel(); trigger.focus(); } });
  function doRipple(e) {
    const rect = trigger.getBoundingClientRect();
    const cx = e.clientX-rect.left, cy = e.clientY-rect.top;
    const maxR = Math.max(Math.hypot(cx,cy),Math.hypot(rect.width-cx,cy),Math.hypot(cx,rect.height-cy),Math.hypot(rect.width-cx,rect.height-cy));
    const size = (maxR/0.65)*2;
    ripple.style.width = size+"px"; ripple.style.height = size+"px";
    const left=cx-size/2, top=cy-size/2, cl=(rect.width-size)/2, ct=(rect.height-size)/2;
    ripple.getAnimations().forEach(a=>a.cancel());
    ripple.animate([{transform:`translate(${left}px,${top}px) scale(0.04)`,opacity:0.12},{transform:`translate(${cl}px,${ct}px) scale(1)`,opacity:0.12}],{duration:700,easing:"cubic-bezier(0.4,0,0.2,1)",fill:"forwards"});
  }
  panel.querySelectorAll(".m3-item").forEach(item => {
    item.addEventListener("pointerdown", function(e) {
      if(e.button!==0) return;
      let rip=this.querySelector(".m3-item-ripple");
      if(!rip){rip=document.createElement("div");rip.className="m3-item-ripple";this.appendChild(rip);}
      const rect=this.getBoundingClientRect(), cx=e.clientX-rect.left, cy=e.clientY-rect.top;
      const hyp=Math.sqrt(rect.width**2+rect.height**2), init=Math.max(2,rect.height*0.2), scale=(hyp+40)/init;
      rip.style.width=init+"px"; rip.style.height=init+"px";
      rip.getAnimations?.().forEach(a=>a.cancel());
      rip.animate([{transform:`translate(${cx-init/2}px,${cy-init/2}px) scale(1)`},{transform:`translate(${(rect.width-init)/2}px,${(rect.height-init)/2}px) scale(${scale})`}],{duration:500,easing:"cubic-bezier(0.2,0,0,1)",fill:"forwards"});
      rip.classList.add("active");
      const end=()=>{setTimeout(()=>rip.classList.remove("active"),200);this.removeEventListener("pointerup",end);this.removeEventListener("pointerleave",end);};
      this.addEventListener("pointerup",end); this.addEventListener("pointerleave",end);
    });
  });
})();
</script>

<main class="main-content">
<?php if ($flashData): ?>
  <div class="flash-wrap" id="flashWrap">
    <div class="flash-msg flash-<?= e($flashData['type']) ?>" id="flashMsg" role="alert" aria-live="assertive">
      <i class="fa-solid <?= $flashData['type'] === 'sucesso' ? 'fa-circle-check' : 'fa-circle-xmark' ?>" style="flex-shrink:0;margin-top:1px;"></i>
      <span style="flex:1;"><?= e($flashData['message']) ?></span>
      <button id="flashClose" aria-label="Fechar" style="background:none;border:none;cursor:pointer;padding:0 0 0 8px;opacity:.6;line-height:1;font-size:1rem;color:inherit;" onclick="fecharFlash()">
        <i class="fa-solid fa-xmark"></i>
      </button>
      <div id="flashProgress" style="position:absolute;bottom:0;left:0;height:3px;border-radius:0 0 12px 12px;width:100%;background:currentColor;opacity:.25;transform-origin:left;animation:flashBar 6s linear forwards;"></div>
    </div>
  </div>
  <style>
    #flashMsg { position:relative; overflow:hidden; }
    @keyframes flashBar { from{transform:scaleX(1)} to{transform:scaleX(0)} }
  </style>
  <script>
    (function() {
      var timer = setTimeout(fecharFlash, 6000);
      function fecharFlash() {
        clearTimeout(timer);
        var el = document.getElementById('flashMsg');
        if (!el) return;
        el.style.transition = 'opacity .3s ease, transform .3s ease';
        el.style.opacity = '0';
        el.style.transform = 'translateY(-8px)';
        setTimeout(function(){ var w = document.getElementById('flashWrap'); if(w) w.remove(); }, 320);
      }
      window.fecharFlash = fecharFlash;
    })();
  </script>
<?php endif; ?>
