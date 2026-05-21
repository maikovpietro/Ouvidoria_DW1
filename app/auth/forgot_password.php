<?php
require_once __DIR__ . '/../../config/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validarCSRF();

    $email = trim($_POST['email'] ?? '');

    if ($email === '') {
        flash('erro', 'Informe seu e-mail.');
        header('Location: ' . BASE_URL . 'app/auth/forgot_password.php');
        exit;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash('erro', 'Informe um e-mail válido.');
        header('Location: ' . BASE_URL . 'app/auth/forgot_password.php');
        exit;
    }

    try {
        $pdo = conectarPDO();

        // Rate limiting: máximo 3 envios por e-mail a cada 15 minutos
        $chaveRL = 'reset:' . substr($email, 0, 100);
        if (verificarRateLimit($pdo, $chaveRL, 3, 900)) {
            // Redireciona para a mesma tela de "e-mail enviado" sem revelar o bloqueio
            header('Location: ' . BASE_URL . 'app/auth/email_enviado.php');
            exit;
        }

        if (emailExisteNoSistema($pdo, $email)) {
            $tokenBruto = criarTokenRecuperacao($pdo, $email);
            // URL corrigida — aponta para o arquivo correto
            $link = APP_URL . '/app/auth/reset_password.php?token=' . urlencode($tokenBruto);
            enviarEmailRecuperacao($email, $link);
            registrarTentativaFalhada($pdo, $chaveRL);
        }

        // Sempre redireciona — não revela se o e-mail existe ou não
        header('Location: ' . BASE_URL . 'app/auth/email_enviado.php');
        exit;

    } catch (PDOException $e) {
        error_log('[forgot_password] ' . $e->getMessage());
        flash('erro', 'Não foi possível processar sua solicitação agora. Tente novamente.');
        header('Location: ' . BASE_URL . 'app/auth/forgot_password.php');
        exit;
    }
}

$tituloPagina = 'Recuperar Senha — Ouvidoria do Grêmio Escolar';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header">
  <div class="page-header-inner">
    <span class="section-label">RECUPERAÇÃO DE ACESSO</span>
    <h1>Recuperar <em>senha</em></h1>
    <p>Informe apenas seu e-mail. Se ele estiver cadastrado, enviaremos um link seguro para redefinição da senha.</p>
  </div>
</div>

<section class="auth-section">
  <div class="auth-card">
    <div class="auth-body">
      <h2 class="auth-title">Enviar link de recuperação</h2>
      <p class="auth-sub">Você receberá um link no e-mail informado (válido por 1 hora).</p>

      <form method="post" autocomplete="off" id="formForgotPassword">
        <?= csrfInput() ?>
        <div class="form-group">
          <label for="email">E-mail cadastrado</label>
          <input type="email" name="email" id="email" class="form-control" placeholder="seuemail@exemplo.com" required>
        </div>

        <button type="submit" class="btn-submit" id="btnEnviar">
          <i class="fa-solid fa-paper-plane"></i> Enviar link
        </button>
      </form>

      <div style="margin-top:18px;text-align:center;">
        <a href="<?= $_base ?>app/auth/login.php" style="color:var(--verde);font-weight:700;text-decoration:none;">Voltar para o login</a>
      </div>
    </div>
  </div>
</section>

<script>
document.getElementById('formForgotPassword').addEventListener('submit', function() {
  const btn = document.getElementById('btnEnviar');
  btn.disabled = true;
  btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Enviando...';
  const status = document.createElement('p');
  status.style.cssText = 'font-size:.83rem;color:var(--texto-suave);margin-top:10px;text-align:center;';
  status.textContent = 'Verificando e-mail e enviando o link, aguarde…';
  btn.parentNode.insertBefore(status, btn.nextSibling);
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
