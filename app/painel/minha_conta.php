<?php
require_once __DIR__ . '/../../config/bootstrap.php';
exigirLoginUsuario();

$pdo = conectarPDO();
garantirTabelasExtras($pdo);
$idUsuario = (int) $_SESSION['usuario']['id'];

$stmt = $pdo->prepare('SELECT IDusu, nome, cpf, perfil, email, telefone, foto_perfil, senha FROM tbusuarios WHERE IDusu = :id LIMIT 1');
$stmt->execute([':id' => $idUsuario]);
$usuario = $stmt->fetch();

if (!$usuario) {
    unset($_SESSION['usuario']);
    flash('erro', 'Sua conta não foi encontrada.');
    header('Location: ' . BASE_URL . 'app/auth/login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validarCSRF();
    $acao = $_POST['acao'] ?? '';

    // Upload de foto de perfil
    if ($acao === 'upload_foto') {
        if (!empty($_FILES['foto_perfil']['tmp_name']) && is_uploaded_file($_FILES['foto_perfil']['tmp_name'])) {
            $file = $_FILES['foto_perfil'];

            if ($file['error'] !== UPLOAD_ERR_OK) {
                flash('erro', 'Erro no upload. Tente novamente.');
            } else {
                $ext     = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

                // MIMEs permitidos para foto de perfil
                $mimesPermitidos = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

                // Detecta o MIME real pelo conteúdo do arquivo (não pelo nome)
                $finfo    = new \finfo(FILEINFO_MIME_TYPE);
                $mimeReal = $finfo->file($file['tmp_name']);

                if (!in_array($ext, $allowed, true)) {
                    flash('erro', 'Extensão inválida. Use JPG, PNG, WEBP ou GIF.');
                } elseif (!in_array($mimeReal, $mimesPermitidos, true)) {
                    flash('erro', 'Tipo de arquivo inválido. Envie apenas imagens reais.');
                } elseif (!@getimagesize($file['tmp_name'])) {
                    flash('erro', 'O arquivo não é uma imagem válida.');
                } elseif ($file['size'] > 2 * 1024 * 1024) {
                    flash('erro', 'A imagem deve ter no máximo 2 MB.');
                } else {
                    $dir = __DIR__ . '/../../storage/fotos/';
                    if (!is_dir($dir)) mkdir($dir, 0755, true);
                    $nomeArquivo = 'user_' . $idUsuario . '_' . time() . '.' . $ext;
                    if (move_uploaded_file($file['tmp_name'], $dir . $nomeArquivo)) {
                        // Apagar foto antiga
                        if (!empty($usuario['foto_perfil'])) {
                            $antiga = $dir . basename($usuario['foto_perfil']);
                            if (file_exists($antiga)) @unlink($antiga);
                        }
                        $updateFoto = $pdo->prepare('UPDATE tbusuarios SET foto_perfil = :foto WHERE IDusu = :id');
                        $updateFoto->execute([':foto' => $nomeArquivo, ':id' => $idUsuario]);
                        $usuario['foto_perfil'] = $nomeArquivo;
                        flash('sucesso', 'Foto de perfil atualizada com sucesso!');
                    } else {
                        flash('erro', 'Não foi possível salvar a imagem. Verifique as permissões do servidor.');
                    }
                }
            }
        } else {
            flash('erro', 'Nenhuma imagem selecionada.');
        }
        header('Location: ' . BASE_URL . 'app/painel/minha_conta.php');
        exit;
    }

    if ($acao === 'atualizar_dados') {
        $nome = trim($_POST['nome'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $telefone = trim($_POST['telefone'] ?? '');
        $perfil = trim($_POST['perfil'] ?? '');

        if ($nome === '' || $email === '') {
            flash('erro', 'Nome e e-mail são obrigatórios.');
            header('Location: ' . BASE_URL . 'app/painel/minha_conta.php');
            exit;
        }

        if (mb_strlen($nome) > 80) {
            flash('erro', 'O nome deve ter no máximo 80 caracteres.');
            header('Location: ' . BASE_URL . 'app/painel/minha_conta.php');
            exit;
        }

        if ($telefone !== '' && !preg_match('/^\(?\d{2}\)?\s?\d{4,5}-?\d{4}$/', $telefone)) {
            flash('erro', 'Telefone inválido. Use o formato (88) 99999-9999.');
            header('Location: ' . BASE_URL . 'app/painel/minha_conta.php');
            exit;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('erro', 'Informe um e-mail válido.');
            header('Location: ' . BASE_URL . 'app/painel/minha_conta.php');
            exit;
        }

        $verifica = $pdo->prepare('SELECT IDusu FROM tbusuarios WHERE email = :email AND IDusu <> :id LIMIT 1');
        $verifica->execute([':email' => $email, ':id' => $idUsuario]);
        if ($verifica->fetch()) {
            flash('erro', 'Esse e-mail já está em uso por outra conta.');
            header('Location: ' . BASE_URL . 'app/painel/minha_conta.php');
            exit;
        }

        $update = $pdo->prepare('UPDATE tbusuarios SET nome = :nome, email = :email, telefone = :telefone, perfil = :perfil WHERE IDusu = :id');
        $update->execute([
            ':nome' => $nome,
            ':email' => $email,
            ':telefone' => $telefone !== '' ? $telefone : null,
            ':perfil' => $perfil !== '' ? $perfil : null,
            ':id' => $idUsuario,
        ]);

        $_SESSION['usuario']['nome'] = $nome;
        $_SESSION['usuario']['email'] = $email;

        flash('sucesso', 'Dados atualizados com sucesso.');
        header('Location: ' . BASE_URL . 'app/painel/minha_conta.php');
        exit;
    }

    if ($acao === 'alterar_senha') {
        $senhaAtual     = $_POST['senha_atual']     ?? '';   // SEM trim
        $novaSenha      = $_POST['nova_senha']      ?? '';   // SEM trim
        $confirmarSenha = $_POST['confirmar_senha'] ?? '';   // SEM trim

        if ($senhaAtual === '' || $novaSenha === '' || $confirmarSenha === '') {
            flash('erro', 'Preencha todos os campos da senha.');
            header('Location: ' . BASE_URL . 'app/painel/minha_conta.php');
            exit;
        }

        if (!senhaConfere($senhaAtual, (string) $usuario['senha'])) {
            flash('erro', 'A senha atual está incorreta.');
            header('Location: ' . BASE_URL . 'app/painel/minha_conta.php');
            exit;
        }

        $erroSenha = validarTamanhoSenha($novaSenha);
        if ($erroSenha) {
            flash('erro', $erroSenha);
            header('Location: ' . BASE_URL . 'app/painel/minha_conta.php');
            exit;
        }

        if ($novaSenha !== $confirmarSenha) {
            flash('erro', 'As novas senhas não coincidem.');
            header('Location: ' . BASE_URL . 'app/painel/minha_conta.php');
            exit;
        }

        $updateSenha = $pdo->prepare('UPDATE tbusuarios SET senha = :senha WHERE IDusu = :id');
        $updateSenha->execute([
            ':senha' => password_hash($novaSenha, PASSWORD_DEFAULT),
            ':id' => $idUsuario,
        ]);

        flash('sucesso', 'Senha alterada com sucesso.');
        header('Location: ' . BASE_URL . 'app/painel/minha_conta.php');
        exit;
    }

    if ($acao === 'excluir_conta') {
        $senhaConfirmacao = $_POST['senha_confirmacao'] ?? '';
        if ($senhaConfirmacao === '') {
            flash('erro', 'Confirme sua senha para excluir a conta.');
            header('Location: ' . BASE_URL . 'app/painel/minha_conta.php#excluir');
            exit;
        }
        // Busca senha atual do banco
        $stmtSenha = $pdo->prepare('SELECT senha FROM tbusuarios WHERE IDusu = :id LIMIT 1');
        $stmtSenha->execute([':id' => $idUsuario]);
        $rowSenha = $stmtSenha->fetch();
        if (!$rowSenha || !senhaConfere($senhaConfirmacao, (string)$rowSenha['senha'])) {
            flash('erro', 'Senha incorreta. A conta não foi excluída.');
            header('Location: ' . BASE_URL . 'app/painel/minha_conta.php#excluir');
            exit;
        }
        $delete = $pdo->prepare('DELETE FROM tbusuarios WHERE IDusu = :id');
        $delete->execute([':id' => $idUsuario]);

        // Encerra a sessão completamente — não apenas faz unset
        clearRememberMeCookies();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
        flash('sucesso', 'Conta excluída com sucesso.');
        header('Location: ' . BASE_URL . 'index.php');
        exit;
    }
}

// Buscar manifestações do usuário
$stmtManif = $pdo->prepare('
    SELECT m.*, t.descricao AS tipo_descricao
    FROM tbmanifest m
    INNER JOIN tipos t ON t.IDtipo = m.IDtipo
    WHERE m.IDusu = :id
    ORDER BY m.criado_em DESC
');
$stmtManif->execute([':id' => $idUsuario]);
$minhasManifestacoes = $stmtManif->fetchAll();


$tituloPagina = 'Minha Conta — Ouvidoria do Grêmio Escolar';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
  <div class="page-header-inner">
    <span class="section-label">ÁREA DO ALUNO</span>
    <h1>Minha <em>conta</em></h1>
    <p>Atualize seus dados, altere sua senha ou acompanhe suas manifestações.</p>
  </div>
</div>

<!-- Abas -->
<div class="conta-tabs-wrap">
  <div class="conta-tabs">
    <button class="conta-tab active" data-panel="dados"><i class="fa-solid fa-user"></i> Meus Dados</button>
    <button class="conta-tab" data-panel="manifestacoes"><i class="fa-solid fa-paper-plane"></i> Minhas Manifestações <span class="conta-badge"><?= count($minhasManifestacoes) ?></span></button>
    <button class="conta-tab" data-panel="senha"><i class="fa-solid fa-lock"></i> Alterar Senha</button>
    <button class="conta-tab conta-tab-danger" data-panel="excluir"><i class="fa-solid fa-trash"></i> Excluir Conta</button>
  </div>
</div>

<section class="form-section">

  <!-- PAINEL: DADOS -->
  <div class="conta-panel active" id="painel-dados">
    <div class="form-card">

      <!-- Foto de perfil -->
      <div class="perfil-foto-wrap">
        <div class="perfil-foto-container">
          <?php
            // Suporta tanto nome simples (novo padrão) quanto caminho legado
            $fotoNome = $usuario['foto_perfil'] ?? '';
            $fotoNome = basename($fotoNome); // garante só o nome, compatível com registros antigos
            $fotoUrl  = !empty($fotoNome) ? BASE_URL . 'storage/fotos/' . $fotoNome : '';
            $fotoExiste = !empty($fotoNome) && file_exists(__DIR__ . '/../../storage/fotos/' . $fotoNome);
          ?>
          <?php if ($fotoExiste): ?>
            <img src="<?= e($fotoUrl) ?>" alt="Foto de perfil" class="perfil-foto-img" id="fotoPreview">
          <?php else: ?>
            <div class="perfil-foto-placeholder" id="fotoPlaceholder">
              <i class="fa-solid fa-user"></i>
            </div>
            <img src="" alt="Foto de perfil" class="perfil-foto-img" id="fotoPreview" style="display:none;">
          <?php endif; ?>
          <label for="fotoInput" class="perfil-foto-edit" title="Alterar foto">
            <i class="fa-solid fa-camera"></i>
          </label>
        </div>
        <div class="perfil-foto-info">
          <div class="perfil-nome"><?= e($usuario['nome'] ?? '') ?></div>
          <div class="perfil-perfil"><?= e($usuario['perfil'] ?? 'Usuário') ?></div>
          <form method="post" enctype="multipart/form-data" id="formFoto">
            <?= csrfInput() ?>
            <input type="hidden" name="acao" value="upload_foto">
            <input type="file" name="foto_perfil" id="fotoInput" accept="image/*" style="display:none;">
            <button type="submit" id="btnSalvarFoto" class="btn-foto-salvar" style="display:none;">
              <i class="fa-solid fa-check"></i> Salvar foto
            </button>
          </form>
          <div style="font-size:0.75rem;color:var(--texto-suave);margin-top:4px;">Clique no ícone para trocar a foto (máx. 2MB)</div>
        </div>
      </div>

      <hr style="margin:28px 0;border:none;border-top:1px solid #e5e7eb;">

      <h2 class="auth-title">Dados pessoais</h2>
      <form method="post" id="formMinhaContaDados" autocomplete="off">
        <?= csrfInput() ?>
        <input type="hidden" name="acao" value="atualizar_dados">

        <div class="form-group">
          <label for="mcNome">Nome</label>
          <input type="text" id="mcNome" name="nome" class="form-control" value="<?= e($usuario['nome'] ?? '') ?>" maxlength="80">
        </div>

        <div class="form-group">
          <label>CPF</label>
          <input type="text" class="form-control" value="<?= e(formatarCPF($usuario['cpf'] ?? '')) ?>" readonly disabled style="background:var(--color-background-secondary,#f4f5f0);cursor:not-allowed;">
          <div class="form-text text-muted">O CPF não pode ser alterado após o cadastro.</div>
        </div>

        <div class="form-group">
          <label for="mcEmail">E-mail</label>
          <input type="email" id="mcEmail" name="email" class="form-control" value="<?= e($usuario['email'] ?? '') ?>">
        </div>

        <div class="form-group">
          <label for="mcTelefone">Telefone</label>
          <input type="tel" id="mcTelefone" name="telefone" class="form-control" value="<?= e($usuario['telefone'] ?? '') ?>" placeholder="(88) 99999-9999" inputmode="numeric" maxlength="15" autocomplete="tel">
          <div class="form-text text-muted">Apenas números. Formato: (88) 99999-9999</div>
        </div>

        <div class="form-group">
          <label for="mcPerfil">Perfil</label>
          <select id="mcPerfil" name="perfil" class="form-control">
            <option value="">Selecione</option>
            <?php foreach (['Aluno(a)', 'Responsável', 'Professor(a)', 'Servidor(a)'] as $perfil): ?>
              <option value="<?= e($perfil) ?>" <?= ($usuario['perfil'] ?? '') === $perfil ? 'selected' : '' ?>><?= e($perfil) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <button type="submit" class="btn-submit">Salvar dados</button>
      </form>
    </div>
  </div>

  <!-- PAINEL: MANIFESTAÇÕES -->
  <div class="conta-panel" id="painel-manifestacoes">
    <div class="form-card">
      <h2 class="auth-title">Minhas manifestações</h2>
      <p style="color:var(--texto-suave);margin-bottom:24px;font-size:0.93rem;">
        Acompanhe o status de todas as manifestações que você enviou pelo sistema.
      </p>

      <?php if (empty($minhasManifestacoes)): ?>
        <div style="text-align:center;padding:48px 24px;color:var(--texto-suave);">
          <div style="font-size:3rem;margin-bottom:16px;">📭</div>
          <p style="font-weight:600;font-size:1rem;">Nenhuma manifestação encontrada.</p>
          <p style="margin-top:8px;font-size:0.88rem;">Quando você enviar uma manifestação, ela aparecerá aqui.</p>
          <a href="<?= $_base ?>app/manifestacao.php" class="btn-submit" style="display:inline-flex;width:auto;margin-top:20px;text-decoration:none;">
            <i class="fa-solid fa-paper-plane"></i> Fazer manifestação
          </a>
        </div>
      <?php else: ?>
        <div style="display:grid;gap:18px;">
          <?php foreach ($minhasManifestacoes as $m): ?>
            <div class="mc-manifest-card">
              <div class="mc-manifest-header">
                <div>
                  <div class="mc-manifest-protocolo"><?= e($m['protocolo']) ?></div>
                  <div class="mc-manifest-data"><?= date('d/m/Y \à\s H:i', strtotime($m['criado_em'])) ?></div>
                </div>
                <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                  <span class="mc-tipo-badge"><?= e($m['tipo_descricao']) ?></span>
                  <span class="<?= e(classeStatusConta($m['status'])) ?>" style="display:inline-flex;align-items:center;padding:5px 12px;border-radius:999px;font-size:0.78rem;font-weight:700;">
                    <?= e($m['status']) ?>
                  </span>
                  <?php
                    try {
                      $stmtNR = $pdo->prepare("SELECT COUNT(*) FROM respostas_manifest WHERE IDmanifest=:id AND autor_tipo='adm' AND lida_pelo_usuario=0");
                      $stmtNR->execute([':id'=>(int)$m['IDmanifest']]);
                      $nrCount = (int)$stmtNR->fetchColumn();
                    } catch(Exception $e) { $nrCount = 0; }
                  ?>
                  <?php if ($nrCount > 0): ?>
                    <span class="badge rounded-pill" style="background:#e84040;color:#fff;font-size:.72rem">
                      <i class="fa-solid fa-comment me-1"></i><?= $nrCount ?> nova(s)
                    </span>
                  <?php endif; ?>
                </div>
              </div>
              <div class="mc-manifest-assunto"><?= e($m['assunto']) ?></div>
              <?php if (!empty($m['feedback'])): ?>
                <div class="mc-manifest-feedback">
                  <span><i class="fa-solid fa-reply" style="color:var(--verde);margin-right:6px;"></i>Resposta do Grêmio:</span>
                  <p><?= nl2br(e($m['feedback'])) ?></p>
                </div>
              <?php endif; ?>
              <div style="margin-top:12px;">
                <a href="<?= $_base ?>app/acompanhar.php?protocolo=<?= urlencode($m['protocolo']) ?>" class="mc-manifest-link">
                  <i class="fa-solid fa-arrow-up-right-from-square"></i> Ver detalhes completos
                </a>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- PAINEL: SENHA -->
  <div class="conta-panel" id="painel-senha">
    <div class="form-card">
      <h2 class="auth-title">Alterar senha</h2>
      <form method="post" id="formMinhaContaSenha" autocomplete="off">
        <?= csrfInput() ?>
        <input type="hidden" name="acao" value="alterar_senha">

        <div class="form-group">
          <label for="mcSenhaAtual">Senha atual</label>
          <input type="password" id="mcSenhaAtual" name="senha_atual" class="form-control">
        </div>

        <div class="form-group">
          <label for="mcNovaSenha">Nova senha</label>
          <input type="password" id="mcNovaSenha" name="nova_senha" class="form-control">
        </div>

        <div class="form-group">
          <label for="mcConfirmarSenha">Confirmar nova senha</label>
          <input type="password" id="mcConfirmarSenha" name="confirmar_senha" class="form-control">
        </div>

        <button type="submit" class="btn-submit">Alterar senha</button>
      </form>
    </div>
  </div>

  <!-- PAINEL: EXCLUIR -->
  <div class="conta-panel" id="painel-excluir">
    <div class="form-card">
      <h2 class="auth-title" style="color:#b91c1c;">Excluir conta</h2>
      <p style="color:var(--texto-suave);margin-bottom:24px;">Esta ação é irreversível. Todos os seus dados serão removidos permanentemente. Confirme sua senha para continuar.</p>
      <form method="post" onsubmit="return confirm('Tem certeza que deseja excluir sua conta? Esta ação não poderá ser desfeita.');">
        <?= csrfInput() ?>
        <input type="hidden" name="acao" value="excluir_conta">
        <div class="form-group">
          <label for="senha_confirmacao"><i class="fa-solid fa-lock" style="color:#b91c1c;margin-right:6px"></i>Confirmar senha atual</label>
          <input type="password" name="senha_confirmacao" id="senha_confirmacao" class="form-control" placeholder="Digite sua senha para confirmar" required>
        </div>
        <button type="submit" class="btn-submit btn-danger">Excluir minha conta</button>
      </form>
    </div>
  </div>

</section>


<script>
// Abas da conta
(function() {
  const tabs = document.querySelectorAll('.conta-tab');
  const panels = document.querySelectorAll('.conta-panel');

  tabs.forEach(tab => {
    tab.addEventListener('click', () => {
      tabs.forEach(t => t.classList.remove('active'));
      panels.forEach(p => p.classList.remove('active'));
      tab.classList.add('active');
      const panel = document.getElementById('painel-' + tab.dataset.panel);
      if (panel) panel.classList.add('active');
    });
  });

  // Redirecionar para aba de manifestações se URL tiver #manifestacoes
  if (window.location.hash === '#manifestacoes') {
    document.querySelector('[data-panel="manifestacoes"]')?.click();
  }
})();

// Preview de foto antes de salvar
(function() {
  const fotoInput = document.getElementById('fotoInput');
  const preview = document.getElementById('fotoPreview');
  const placeholder = document.getElementById('fotoPlaceholder');
  const btnSalvar = document.getElementById('btnSalvarFoto');

  if (!fotoInput) return;

  fotoInput.addEventListener('change', function() {
    if (!this.files || !this.files[0]) return;
    const reader = new FileReader();
    reader.onload = function(e) {
      if (preview) {
        preview.src = e.target.result;
        preview.style.display = 'block';
      }
      if (placeholder) placeholder.style.display = 'none';
      if (btnSalvar) btnSalvar.style.display = 'inline-flex';
    };
    reader.readAsDataURL(this.files[0]);
  });
})();
</script>

<script>
(function() {
  const mensagens = {
    formMinhaContaDados:  'Salvando seus dados...',
    formMinhaContaSenha:  'Alterando senha...',
    formFoto:             'Enviando foto...',
  };
  document.querySelectorAll('form').forEach(function(form) {
    form.addEventListener('submit', function() {
      var btn = this.querySelector('[type=submit]');
      if (!btn || btn.disabled) return;
      var msg = mensagens[this.id] || 'Aguarde...';
      btn.disabled = true;
      btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> ' + msg;
      var existing = this.querySelector('.submit-status');
      if (!existing) {
        var status = document.createElement('p');
        status.className = 'submit-status';
        status.style.cssText = 'font-size:.83rem;color:var(--texto-suave);margin-top:10px;text-align:center;animation:flashIn .2s ease;';
        status.textContent = 'Processando, aguarde…';
        btn.parentNode.insertBefore(status, btn.nextSibling);
      }
    });
  });
})();
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
