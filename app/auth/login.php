<?php
require_once __DIR__ . '/../../config/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validarCSRF();

    $acao = $_POST['acao'] ?? 'login';

    if ($acao === 'login') {
        $email      = trim($_POST['email'] ?? '');
        $senha      = $_POST['senha'] ?? '';   // SEM trim — bcrypt é sensível a espaços
        $tiposValidos = ['adm', 'usuario'];
        $tipoAcesso = in_array($_POST['tipo_acesso'] ?? '', $tiposValidos, true)
                      ? $_POST['tipo_acesso']
                      : 'usuario';
        $lembrar    = isset($_POST['lembrar_me']);

        if ($email === '' || $senha === '') {
            $_SESSION['flash_form'] = 'Verifique seus dados e tente novamente.';
            header('Location: ' . BASE_URL . 'app/auth/login.php#login');
            exit;
        }

        try {
            $pdo = conectarPDO();

            // Rate limiting por IP + e-mail
            $chaveRL = 'login:' . $_SERVER['REMOTE_ADDR'] . ':' . substr($email, 0, 100);
            if (verificarRateLimit($pdo, $chaveRL)) {
                $_SESSION['flash_form'] = 'Muitas tentativas incorretas. Aguarde alguns minutos e tente novamente.';
                header('Location: ' . BASE_URL . 'app/auth/login.php#login');
                exit;
            }

            if ($tipoAcesso === 'adm') {
                $stmt = $pdo->prepare('SELECT IDadm, nome, email, senha FROM tbadm WHERE email = :email LIMIT 1');
                $stmt->execute([':email' => $email]);
                $admin = $stmt->fetch();

                if (!$admin || !senhaConfere($senha, (string)$admin['senha'])) {
                    registrarTentativaFalhada($pdo, $chaveRL);
                    $_SESSION['flash_form'] = 'Verifique seus dados e tente novamente.';
                    header('Location: ' . BASE_URL . 'app/auth/login.php#login');
                    exit;
                }

                limparRateLimit($pdo, $chaveRL);
                session_regenerate_id(true);

                $_SESSION['admin'] = [
                    'id'    => $admin['IDadm'],
                    'nome'  => $admin['nome'],
                    'email' => $admin['email'],
                ];
                unset($_SESSION['usuario']);

                if ($lembrar) setRememberMeCookies($email, $tipoAcesso);
                else          clearRememberMeCookies();

                flash('sucesso', 'Login administrativo realizado com sucesso.');
                header('Location: ' . BASE_URL . 'app/painel/dashboard.php');
                exit;
            }

            $stmt = $pdo->prepare('SELECT IDusu, nome, email, senha FROM tbusuarios WHERE email = :email LIMIT 1');
            $stmt->execute([':email' => $email]);
            $usuario = $stmt->fetch();

            if (!$usuario || !senhaConfere($senha, (string)$usuario['senha'])) {
                registrarTentativaFalhada($pdo, $chaveRL);
                $_SESSION['flash_form'] = 'Verifique seus dados e tente novamente.';
                header('Location: ' . BASE_URL . 'app/auth/login.php#login');
                exit;
            }

            limparRateLimit($pdo, $chaveRL);
            session_regenerate_id(true);

            $_SESSION['usuario'] = [
                'id'    => $usuario['IDusu'],
                'nome'  => $usuario['nome'],
                'email' => $usuario['email'],
            ];
            unset($_SESSION['admin']);

            if ($lembrar) setRememberMeCookies($email, $tipoAcesso);
            else          clearRememberMeCookies();

            flash('sucesso', 'Login realizado com sucesso.');
            header('Location: ' . BASE_URL . 'app/painel/minha_conta.php');
            exit;

        } catch (PDOException $e) {
            error_log('[login] ' . $e->getMessage());
            $_SESSION['flash_form'] = 'Erro ao conectar ao banco de dados. Tente novamente.';
            header('Location: ' . BASE_URL . 'app/auth/login.php#login');
            exit;
        }
    }

    if ($acao === 'cadastro') {
        $nome      = trim($_POST['nome']            ?? '');
        $cpf       = trim($_POST['cpf']             ?? '');
        $perfil    = trim($_POST['perfil']           ?? '');
        $email     = trim($_POST['email_cadastro']   ?? '');
        $senha     = $_POST['senha_cadastro']   ?? '';   // SEM trim
        $confirmar = $_POST['confirmar_senha']  ?? '';   // SEM trim

        if ($nome === '' || $cpf === '' || $email === '' || $senha === '' || $confirmar === '') {
            $_SESSION['flash_form_cad'] = 'Preencha os campos obrigatórios do cadastro.';
            header('Location: ' . BASE_URL . 'app/auth/login.php#cadastro');
            exit;
        }

        if (mb_strlen($nome) > 80) {
            $_SESSION['flash_form_cad'] = 'O nome deve ter no máximo 80 caracteres.';
            header('Location: ' . BASE_URL . 'app/auth/login.php#cadastro');
            exit;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['flash_form_cad'] = 'Informe um e-mail válido.';
            header('Location: ' . BASE_URL . 'app/auth/login.php#cadastro');
            exit;
        }

        if (!validarCPF($cpf)) {
            $_SESSION['flash_form_cad'] = 'CPF inválido.';
            header('Location: ' . BASE_URL . 'app/auth/login.php#cadastro');
            exit;
        }

        // Valida perfil contra a lista permitida
        if ($perfil !== '' && !validarPerfil($perfil)) {
            $_SESSION['flash_form_cad'] = 'Perfil inválido.';
            header('Location: ' . BASE_URL . 'app/auth/login.php#cadastro');
            exit;
        }

        if ($senha !== $confirmar) {
            $_SESSION['flash_form_cad'] = 'As senhas não coincidem.';
            header('Location: ' . BASE_URL . 'app/auth/login.php#cadastro');
            exit;
        }

        if (mb_strlen($senha) < 8) {
            $_SESSION['flash_form_cad'] = 'A senha deve ter pelo menos 8 caracteres.';
            header('Location: ' . BASE_URL . 'app/auth/login.php#cadastro');
            exit;
        }

        if (mb_strlen($senha) > 72) {
            $_SESSION['flash_form_cad'] = 'A senha deve ter no máximo 72 caracteres.';
            header('Location: ' . BASE_URL . 'app/auth/login.php#cadastro');
            exit;
        }

        try {
            $pdo     = conectarPDO();
            $cpfLimpo = preg_replace('/\D/', '', $cpf);

            if (cpfJaCadastrado($pdo, $cpfLimpo)) {
                $_SESSION['flash_form_cad'] = 'Este CPF já está cadastrado no sistema.';
            header('Location: ' . BASE_URL . 'app/auth/login.php#cadastro');
                exit;
            }

            // Verifica duplicata em tbusuarios E em tbadm (e-mail de admin não pode virar conta de aluno)
            $verificaUsu = $pdo->prepare('SELECT IDusu FROM tbusuarios WHERE email = :email LIMIT 1');
            $verificaUsu->execute([':email' => $email]);
            $verificaAdm = $pdo->prepare('SELECT IDadm FROM tbadm WHERE email = :email LIMIT 1');
            $verificaAdm->execute([':email' => $email]);
            if ($verificaUsu->fetch() || $verificaAdm->fetch()) {
                $_SESSION['flash_form_cad'] = 'Já existe um cadastro com esse e-mail.';
            header('Location: ' . BASE_URL . 'app/auth/login.php#cadastro');
                exit;
            }

            $stmt = $pdo->prepare('INSERT INTO tbusuarios (nome, cpf, perfil, email, senha) VALUES (:nome, :cpf, :perfil, :email, :senha)');
            $stmt->execute([
                ':nome'   => $nome,
                ':cpf'    => $cpfLimpo,
                ':perfil' => $perfil !== '' ? $perfil : null,
                ':email'  => $email,
                ':senha'  => password_hash($senha, PASSWORD_DEFAULT),
            ]);

            flash('sucesso', 'Cadastro realizado com sucesso. Agora você já pode entrar.');
            header('Location: ' . BASE_URL . 'app/auth/login.php?cadastro=ok');
            exit;

        } catch (PDOException $e) {
            error_log('[cadastro] ' . $e->getMessage());
            $_SESSION['flash_form_cad'] = 'Não foi possível concluir o cadastro. Tente novamente.';
            header('Location: ' . BASE_URL . 'app/auth/login.php#cadastro');
            exit;
        }
    }
}

// Resolve o cookie lembrar-me por token opaco (sem expor e-mail no cookie)
$_lembrado     = resolverRememberMeCookie();
$emailLembrado = $_lembrado['email'] ?? '';
$tipoLembrado  = $_lembrado['tipo']  ?? 'adm';

$tituloPagina = 'Login e Cadastro — Ouvidoria do Grêmio Escolar';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header">
  <div class="page-header-inner">
    <span class="section-label">ACESSO À PLATAFORMA</span>
    <h1>Login & <em>Cadastro</em></h1>
    <p>Entre no sistema, acompanhe sua conta e participe da melhoria da vida escolar.</p>
  </div>
</div>

<div class="auth-section">
  <div class="auth-card">
    <div class="auth-tabs">
      <button class="auth-tab active" type="button" data-panel="login"><i class="fa-solid fa-right-to-bracket" style="margin-right:8px;color:var(--laranja)"></i>Login</button>
      <button class="auth-tab" type="button" data-panel="cadastro"><i class="fa-solid fa-user-plus" style="margin-right:8px;color:var(--verde)"></i>Cadastrar-se</button>
    </div>

    <div class="auth-body">
      <div class="auth-panel active" id="login">
        <h2 class="auth-title">Entrar na ouvidoria</h2>
        <p class="auth-sub">Use sua conta para registrar manifestações e acompanhar seus dados.</p>

        <form method="post" autocomplete="off" id="formLogin">
          <?= csrfInput() ?>
          <input type="hidden" name="acao" value="login">

          <?php
            $flashForm = $_SESSION['flash_form'] ?? null;
            unset($_SESSION['flash_form']);
            if ($flashForm):
          ?>
          <div class="form-erro-inline" style="font-size:.84rem;color:#b91c1c;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:10px 14px;margin-bottom:16px;text-align:center;">
            <?= e($flashForm) ?>
          </div>
          <?php endif; ?>

          <div class="form-group">
            <label for="tipo_acesso">Tipo de acesso</label>
            <select name="tipo_acesso" id="tipo_acesso" class="form-control">
              <option value="adm"     <?= $tipoLembrado === 'adm'     ? 'selected' : '' ?>>Administrador / Grêmio</option>
              <option value="usuario" <?= $tipoLembrado === 'usuario'  ? 'selected' : '' ?>>Usuário</option>
            </select>
          </div>

          <div class="form-group">
            <label for="loginEmail"><i class="fa-solid fa-envelope" style="color:var(--laranja);margin-right:6px"></i>E-mail</label>
            <input type="email" name="email" id="loginEmail" class="form-control" placeholder="seunome@exemplo.com" value="<?= e($emailLembrado) ?>">
          </div>

          <div class="form-group">
            <label for="loginSenha"><i class="fa-solid fa-lock" style="color:var(--laranja);margin-right:6px"></i>Senha</label>
            <input type="password" name="senha" id="loginSenha" class="form-control" placeholder="••••••••">
          </div>

          <label class="remember-check">
            <input type="checkbox" name="lembrar_me" <?= $emailLembrado !== '' ? 'checked' : '' ?>>
            <span>Lembrar de mim</span>
          </label>

          <div style="text-align:right;margin-bottom:24px">
            <a href="<?= BASE_URL ?>app/auth/forgot_password.php" style="font-size:0.82rem;color:var(--laranja);text-decoration:none;font-weight:600">Esqueci minha senha</a>
          </div>

          <button type="submit" class="btn-submit" id="btnLogin">
            <i class="fa-solid fa-right-to-bracket"></i> Entrar na plataforma
          </button>
        </form>
      </div>

      <div class="auth-panel" id="cadastro">
        <h2 class="auth-title">Criar conta de usuário</h2>
        <p class="auth-sub">Cadastre-se para registrar manifestações e gerenciar seus dados pessoais.</p>

        <form method="post" autocomplete="off" id="formCadastro">
          <?= csrfInput() ?>
          <input type="hidden" name="acao" value="cadastro">

          <?php
            $flashFormC = $_SESSION['flash_form_cad'] ?? null;
            unset($_SESSION['flash_form_cad']);
            if ($flashFormC):
          ?>
          <div class="form-erro-inline" style="font-size:.84rem;color:#b91c1c;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:10px 14px;margin-bottom:16px;text-align:center;">
            <?= e($flashFormC) ?>
          </div>
          <?php endif; ?>

          <div class="form-group">
            <label for="cadNome">Nome completo</label>
            <input type="text" name="nome" id="cadNome" class="form-control" placeholder="Seu nome completo" maxlength="80">
          </div>

          <div class="form-group">
            <label for="cadCPF">CPF</label>
            <input type="text" name="cpf" id="cadCPF" class="form-control" data-mask="cpf" placeholder="000.000.000-00">
          </div>

          <div class="form-group">
            <label for="cadPerfil">Perfil</label>
            <select name="perfil" id="cadPerfil" class="form-control">
              <option value="">Selecione</option>
              <option value="Aluno(a)">Aluno(a)</option>
              <option value="Responsável">Responsável</option>
              <option value="Professor(a)">Professor(a)</option>
              <option value="Servidor(a)">Servidor(a)</option>
            </select>
          </div>

          <div class="form-group">
            <label for="cadEmail">E-mail</label>
            <input type="email" name="email_cadastro" id="cadEmail" class="form-control" placeholder="seunome@exemplo.com">
          </div>

          <div class="form-group">
            <label for="cadSenha">Senha</label>
            <input type="password" name="senha_cadastro" id="cadSenha" class="form-control" placeholder="Crie uma senha segura">
            <div class="forca-wrap">
              <div class="forca-trilho"><div id="barraSenha"></div></div>
              <span id="labelSenha"></span>
            </div>
          </div>

          <div class="form-group">
            <label for="cadConfSenha">Confirmar senha</label>
            <input type="password" name="confirmar_senha" id="cadConfSenha" class="form-control" placeholder="Repita a senha">
          </div>

          <button type="submit" class="btn-green" id="btnCadastro">
            <i class="fa-solid fa-user-plus"></i> Concluir cadastro
          </button>
        </form>
      </div>
    </div>
  </div>
</div>

<script>
// Feedback de envio com mensagem contextual por formulário
(function() {
  const mensagens = {
    formLogin:    'Entrando...',
    formCadastro: 'Criando sua conta...',
  };

  /* ── Tab switching ── */
  function ativarAba(nome) {
    document.querySelectorAll('.auth-tab').forEach(t => t.classList.toggle('active', t.dataset.panel === nome));
    document.querySelectorAll('.auth-panel').forEach(p => p.classList.toggle('active', p.id === nome));
  }

  // Abre a aba correta via hash da URL (#login ou #cadastro)
  const hash = location.hash.replace('#', '');
  if (hash === 'login' || hash === 'cadastro') ativarAba(hash);

  // Clique nas abas
  document.querySelectorAll('.auth-tab').forEach(tab => {
    tab.addEventListener('click', function() {
      ativarAba(this.dataset.panel);
      history.replaceState(null, '', '#' + this.dataset.panel);
    });
  });

  /* ── Helpers ── */
  function mostrarErro(form, msg) {
    let box = form.querySelector('.form-erro-inline');
    if (!box) {
      box = document.createElement('div');
      box.className = 'form-erro-inline';
      box.style.cssText = 'font-size:.84rem;color:#b91c1c;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:10px 14px;margin-bottom:16px;text-align:center;';
      // Insere no topo do formulário, após os hidden inputs
      const firstField = form.querySelector('.form-group') || form.querySelector('[type=submit]');
      form.insertBefore(box, firstField);
    }
    box.textContent = msg;
    box.style.display = 'block';
    box.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }

  function limparErro(form) {
    const box = form.querySelector('.form-erro-inline');
    if (box) box.style.display = 'none';
  }

  function setLoading(form, msg) {
    const btn = form.querySelector('[type=submit]');
    if (!btn || btn.disabled) return false;
    btn.disabled = true;
    btn.dataset.original = btn.innerHTML;
    btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> ' + msg;
    let status = form.querySelector('.submit-status');
    if (!status) {
      status = document.createElement('p');
      status.className = 'submit-status';
      status.style.cssText = 'font-size:.83rem;color:var(--texto-suave);margin-top:10px;text-align:center;animation:flashIn .2s ease;';
      btn.after(status);
    }
    status.textContent = 'Processando sua solicitação, por favor aguarde…';
    return true;
  }

  /* ── Validação do formulário de LOGIN ── */
  const formLogin = document.getElementById('formLogin');
  if (formLogin) {
    formLogin.addEventListener('submit', function(e) {
      limparErro(this);
      const email = this.querySelector('#loginEmail').value.trim();
      const senha = this.querySelector('#loginSenha').value;

      if (!email) {
        e.preventDefault();
        mostrarErro(this, 'Verifique seus dados e tente novamente.');
        this.querySelector('#loginEmail').focus();
        return;
      }
      if (!senha) {
        e.preventDefault();
        mostrarErro(this, 'Verifique seus dados e tente novamente.');
        this.querySelector('#loginSenha').focus();
        return;
      }

      setLoading(this, mensagens['formLogin']);
    });
  }

  /* ── Validação do formulário de CADASTRO ── */
  const formCadastro = document.getElementById('formCadastro');
  if (formCadastro) {
    formCadastro.addEventListener('submit', function(e) {
      limparErro(this);
      const nome    = this.querySelector('#cadNome').value.trim();
      const cpf     = this.querySelector('#cadCPF').value.replace(/\D/g, '');
      const perfil  = this.querySelector('#cadPerfil').value;
      const email   = this.querySelector('#cadEmail').value.trim();
      const senha   = this.querySelector('#cadSenha').value;
      const conf    = this.querySelector('#cadConfSenha').value;

      if (!nome) {
        e.preventDefault(); mostrarErro(this, 'Informe seu nome completo.');
        this.querySelector('#cadNome').focus(); return;
      }
      if (cpf.length !== 11) {
        e.preventDefault(); mostrarErro(this, 'CPF inválido. Use o formato 000.000.000-00.');
        this.querySelector('#cadCPF').focus(); return;
      }
      if (!perfil) {
        e.preventDefault(); mostrarErro(this, 'Selecione seu perfil.');
        this.querySelector('#cadPerfil').focus(); return;
      }
      if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        e.preventDefault(); mostrarErro(this, 'Informe um e-mail válido.');
        this.querySelector('#cadEmail').focus(); return;
      }
      if (senha.length < 8) {
        e.preventDefault(); mostrarErro(this, 'A senha deve ter pelo menos 8 caracteres.');
        this.querySelector('#cadSenha').focus(); return;
      }
      if (senha !== conf) {
        e.preventDefault(); mostrarErro(this, 'As senhas não coincidem.');
        this.querySelector('#cadConfSenha').focus(); return;
      }

      setLoading(this, mensagens['formCadastro']);
    });
  }
})();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
