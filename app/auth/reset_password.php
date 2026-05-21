<?php
require_once __DIR__ . '/../../config/bootstrap.php';

$token = $_GET['token'] ?? ($_POST['token'] ?? '');

if ($token === '') {
    flash('erro', 'Token inválido.');
    header('Location: ' . BASE_URL . 'app/auth/login.php');
    exit;
}

$pdo   = conectarPDO();
$reset = buscarResetValidoPorToken($pdo, $token);

if (!$reset) {
    flash('erro', 'Link inválido ou expirado.');
    header('Location: ' . BASE_URL . 'app/auth/login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validarCSRF();

    $novaSenha      = $_POST['nova_senha']      ?? '';   // SEM trim
    $confirmarSenha = $_POST['confirmar_senha'] ?? '';   // SEM trim

    if ($novaSenha === '' || $confirmarSenha === '') {
        flash('erro', 'Preencha os dois campos de senha.');
        header('Location: ' . BASE_URL . 'app/auth/reset_password.php?token=' . urlencode($token));
        exit;
    }

    if (mb_strlen($novaSenha) < 8) {
        flash('erro', 'A nova senha deve ter pelo menos 8 caracteres.');
        header('Location: ' . BASE_URL . 'app/auth/reset_password.php?token=' . urlencode($token));
        exit;
    }

    if (mb_strlen($novaSenha) > 72) {
        flash('erro', 'A senha deve ter no máximo 72 caracteres.');
        header('Location: ' . BASE_URL . 'app/auth/reset_password.php?token=' . urlencode($token));
        exit;
    }

    if ($novaSenha !== $confirmarSenha) {
        flash('erro', 'As senhas não coincidem.');
        header('Location: ' . BASE_URL . 'app/auth/reset_password.php?token=' . urlencode($token));
        exit;
    }

    try {
        $novaSenhaHash = password_hash($novaSenha, PASSWORD_DEFAULT);
        // Usa a função corrigida: transação + atualiza apenas a tabela certa
        atualizarSenhaPorReset($pdo, $reset, $novaSenhaHash);

        flash('sucesso', 'Senha redefinida com sucesso. Agora você já pode fazer login.');
        header('Location: ' . BASE_URL . 'app/auth/login.php');
        exit;
    } catch (PDOException $e) {
        error_log('[reset_password] ' . $e->getMessage());
        flash('erro', 'Não foi possível atualizar a senha. Tente novamente.');
        header('Location: ' . BASE_URL . 'app/auth/reset_password.php?token=' . urlencode($token));
        exit;
    }
}

$tituloPagina = 'Redefinir Senha — Ouvidoria do Grêmio Escolar';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header">
  <div class="page-header-inner">
    <span class="section-label">NOVA SENHA</span>
    <h1>Redefinir <em>senha</em></h1>
    <p>Crie uma nova senha para sua conta.</p>
  </div>
</div>

<section class="auth-section">
  <div class="auth-card">
    <div class="auth-body">
      <h2 class="auth-title">Definir nova senha</h2>
      <p class="auth-sub">Escolha uma senha forte e confirme abaixo.</p>

      <form method="post" autocomplete="off" id="formResetPassword">
        <?= csrfInput() ?>
        <input type="hidden" name="token" value="<?= e($token) ?>">

        <div class="form-group">
          <label for="nova_senha">Nova senha</label>
          <input type="password" name="nova_senha" id="cadSenha" class="form-control" placeholder="Digite a nova senha" required>
          <div class="forca-wrap">
            <div class="forca-trilho"><div id="barraSenha"></div></div>
            <span id="labelSenha"></span>
          </div>
        </div>

        <div class="form-group">
          <label for="confirmar_senha">Confirmar nova senha</label>
          <input type="password" name="confirmar_senha" id="confirmar_senha" class="form-control" placeholder="Repita a nova senha" required>
        </div>

        <button type="submit" class="btn-submit" id="btnReset">
          <i class="fa-solid fa-key"></i> Salvar nova senha
        </button>
      </form>
    </div>
  </div>
</section>

<script>
document.getElementById('formResetPassword').addEventListener('submit', function() {
  const btn = document.getElementById('btnReset');
  btn.disabled = true;
  btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Salvando...';
  const status = document.createElement('p');
  status.style.cssText = 'font-size:.83rem;color:var(--texto-suave);margin-top:10px;text-align:center;';
  status.textContent = 'Atualizando sua senha, aguarde…';
  btn.parentNode.insertBefore(status, btn.nextSibling);
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
