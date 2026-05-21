<?php
require_once __DIR__ . '/../config/bootstrap.php';

$protocolo = trim($_GET['protocolo'] ?? ($_POST['protocolo'] ?? ''));
$manifestacao = null;
$erro = null;

// API endpoint para validação em tempo real via AJAX
if (isset($_GET['ajax_check']) && $_GET['ajax_check'] === '1') {
    header('Content-Type: application/json');
    $p = trim($_GET['protocolo'] ?? '');
    if ($p === '') {
        echo json_encode(['status' => 'empty']);
        exit;
    }
    if (!preg_match('/^GRE-\d{8}-[A-Z0-9]{6}$/', $p)) {
        echo json_encode(['status' => 'invalid']);
        exit;
    }
    try {
        $pdo = conectarPDO();
        $stmt = $pdo->prepare('SELECT IDmanifest, protocolo, status FROM tbmanifest WHERE protocolo = :protocolo LIMIT 1');
        $stmt->execute([':protocolo' => $p]);
        $row = $stmt->fetch();
        if ($row) {
            echo json_encode(['status' => 'found', 'manifest_status' => $row['status']]);
        } else {
            echo json_encode(['status' => 'not_found']);
        }
    } catch (PDOException $e) {
        echo json_encode(['status' => 'error']);
    }
    exit;
}

// Resposta do usuário à manifestação (apenas logado)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao']) && $_POST['acao'] === 'responder_usuario') {
    validarCSRF();
    if (!usuarioLogado()) {
        flash('erro', 'Faça login para responder.');
        header('Location: ' . BASE_URL . 'app/auth/login.php');
        exit;
    }
    $idManifest = (int)($_POST['id_manifest'] ?? 0);
    $protocolo  = trim($_POST['protocolo'] ?? '');
    $mensagem   = trim($_POST['mensagem'] ?? '');
    if ($idManifest > 0 && $mensagem !== '') {
        if (mb_strlen($mensagem) > 2000) {
            flash('erro', 'A mensagem deve ter no máximo 2000 caracteres.');
            header('Location: ' . BASE_URL . 'app/acompanhar.php?protocolo=' . urlencode($protocolo));
            exit;
        }
        $pdo2 = conectarPDO();
        // Verifica que a manifestação pertence ao usuário logado
        $stmtOwn = $pdo2->prepare('SELECT IDusu FROM tbmanifest WHERE IDmanifest=:id LIMIT 1');
        $stmtOwn->execute([':id'=>$idManifest]);
        $own = $stmtOwn->fetch(PDO::FETCH_ASSOC);
        if ($own && (int)$own['IDusu'] === (int)$_SESSION['usuario']['id']) {
            $pdo2->prepare("
                INSERT INTO respostas_manifest (IDmanifest, IDusu, mensagem, autor_nome, autor_tipo, lida_pelo_adm)
                VALUES (:idm, :idu, :msg, :nome, 'usuario', 0)
            ")->execute([':idm'=>$idManifest,':idu'=>(int)$_SESSION['usuario']['id'],':msg'=>$mensagem,':nome'=>$_SESSION['usuario']['nome']]);
            // Notifica TODOS os admins (não apenas o primeiro)
            $stmtAdmN = $pdo2->query('SELECT IDadm FROM tbadm');
            while ($admN = $stmtAdmN->fetch(PDO::FETCH_ASSOC)) {
                criarNotificacao($pdo2, [
                    'IDadm'    => (int)$admN['IDadm'],
                    'tipo'     => 'nova_resposta',
                    'titulo'   => 'Usuário respondeu uma manifestação',
                    'mensagem' => $_SESSION['usuario']['nome'].' respondeu ao protocolo '.$protocolo.'.',
                    'link'     => BASE_URL . 'app/painel/adm.php#manifest-'.$idManifest,
                ]);
            }
            flash('sucesso', 'Resposta enviada com sucesso.');
        } else {
            flash('erro', 'Você não pode responder a esta manifestação.');
        }
    }
    header('Location: ' . BASE_URL . 'app/acompanhar.php?protocolo='.urlencode($protocolo));
    exit;
}

// Avaliação por estrelas (1-5) + comentário
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['nota_satisfacao'])) {
    validarCSRF();

    // Avaliação exige usuário logado — manifestações anônimas não podem ser avaliadas
    if (!usuarioLogado()) {
        flash('erro', 'Faça login para avaliar sua manifestação.');
        header('Location: ' . BASE_URL . 'app/auth/login.php');
        exit;
    }

    $idManifest = (int) ($_POST['id_manifest'] ?? 0);
    $nota       = (int) $_POST['nota_satisfacao'];
    $comentario = trim($_POST['comentario_satisfacao'] ?? '');

    if ($idManifest > 0 && $nota >= 1 && $nota <= 5) {
        try {
            $pdo = conectarPDO();

            // Verifica que a manifestação pertence ao usuário logado
            $stmtOwn = $pdo->prepare('SELECT IDusu, nota_satisfacao FROM tbmanifest WHERE IDmanifest = :id LIMIT 1');
            $stmtOwn->execute([':id' => $idManifest]);
            $owner = $stmtOwn->fetch();

            if (!$owner || (int)$owner['IDusu'] !== (int)$_SESSION['usuario']['id']) {
                flash('erro', 'Você não tem permissão para avaliar esta manifestação.');
                header('Location: ' . BASE_URL . 'app/acompanhar.php?protocolo=' . urlencode($protocolo));
                exit;
            }

            // Impede reavaliação: cada manifestação só pode ser avaliada uma vez
            if ($owner['nota_satisfacao'] !== null) {
                flash('erro', 'Você já avaliou esta manifestação.');
                header('Location: ' . BASE_URL . 'app/acompanhar.php?protocolo=' . urlencode($protocolo));
                exit;
            }

            // UPDATE inclui AND IDusu como segunda barreira no banco
            $pdo->prepare('UPDATE tbmanifest SET nota_satisfacao = :nota, comentario_satisfacao = :com WHERE IDmanifest = :id AND IDusu = :uid')
                ->execute([
                    ':nota' => $nota,
                    ':com'  => $comentario !== '' ? $comentario : null,
                    ':id'   => $idManifest,
                    ':uid'  => (int)$_SESSION['usuario']['id'],
                ]);

            flash('sucesso', 'Obrigado pela sua avaliação!');
        } catch (PDOException $e) {
            error_log('[nota_satisfacao] ' . $e->getMessage());
            flash('erro', 'Não foi possível salvar a avaliação. Tente novamente.');
        }
    }
    header('Location: ' . BASE_URL . 'app/acompanhar.php?protocolo=' . urlencode($protocolo));
    exit;
}

if ($protocolo !== '') {
    try {
        $pdo = conectarPDO();

        // Rate limiting: máximo 30 buscas por IP a cada 10 minutos
        // Evita enumeração de protocolos por força bruta
        $chaveRL = 'busca_protocolo:' . ($_SERVER['REMOTE_ADDR'] ?? '');
        if (verificarRateLimit($pdo, $chaveRL, 30, 600)) {
            $erro = 'Muitas tentativas. Aguarde alguns minutos e tente novamente.';
        } else {
            $stmt = $pdo->prepare('
                SELECT m.*, t.descricao AS tipo_descricao
                FROM tbmanifest m
                INNER JOIN tipos t ON t.IDtipo = m.IDtipo
                WHERE m.protocolo = :protocolo
                LIMIT 1
            ');
            $stmt->execute([':protocolo' => $protocolo]);
            $manifestacao = $stmt->fetch();
            if (!$manifestacao) {
                registrarTentativaFalhada($pdo, $chaveRL);
                $erro = 'Protocolo não encontrado. Verifique o código e tente novamente.';
            }
        }
    } catch (PDOException $e) {
        $erro = 'Não foi possível consultar o banco de dados.';
    }
}


$tituloPagina = 'Acompanhar Protocolo — Ouvidoria do Grêmio Escolar';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
  <div class="page-header-inner">
    <span class="section-label">ACOMPANHAMENTO</span>
    <h1>Acompanhar <em>manifestação</em></h1>
    <p>Informe o código de protocolo para verificar o status da sua manifestação.</p>
  </div>
</div>

<section class="auth-section">
  <div class="form-card" style="max-width:680px;margin:0 auto;">

    <h2 style="font-family:'Playfair Display',serif;font-size:1.6rem;font-weight:900;color:var(--verde-escuro);margin-bottom:8px;">
      Consultar protocolo
    </h2>
    <p style="color:var(--texto-suave);margin-bottom:28px;font-size:0.93rem;">
      O protocolo tem o formato <strong>GRE-AAAAMMDD-XXXXXX</strong> e foi exibido após o envio da manifestação.
    </p>

    <form method="get" autocomplete="off" id="formAcompanhar">
      <div class="form-group" style="position:relative;">
        <label for="protocolo_input">Código do protocolo</label>
        <input
          type="text"
          id="protocolo_input"
          name="protocolo"
          class="form-control"
          placeholder="Ex.: GRE-20260330-A1B2C3"
          value="<?= e($protocolo) ?>"
          style="text-transform:uppercase;letter-spacing:1px;font-weight:700;font-size:1rem;"
          maxlength="22"
          autocomplete="off"
        >
        <div id="protocolo_feedback" style="margin-top:8px;font-size:0.84rem;font-weight:700;min-height:20px;display:flex;align-items:center;gap:6px;"></div>
      </div>

      <button type="submit" class="btn-submit" id="btnConsultar">
        <i class="fa-solid fa-magnifying-glass"></i> Consultar status
      </button>
    </form>

    <?php if ($erro): ?>
      <div style="margin-top:28px;padding:18px 20px;border-radius:16px;background:#fef2f2;border:1px solid #fecaca;color:#b91c1c;display:flex;align-items:center;gap:12px;">
        <i class="fa-solid fa-circle-xmark" style="font-size:1.4rem;flex-shrink:0;"></i>
        <span><?= e($erro) ?></span>
      </div>
    <?php endif; ?>

    <?php if ($manifestacao): ?>
      <?php
        $status = $manifestacao['status'] ?? 'Recebida';
        $passos = ['Recebida', 'Em andamento', 'Resolvida'];
        $passoAtual = array_search($status, $passos);
      ?>

      <div class="acomp-card">

        <div class="acomp-header">
          <div>
            <div class="acomp-label">PROTOCOLO</div>
            <div class="acomp-protocolo"><?= e($manifestacao['protocolo']) ?></div>
            <div class="acomp-data">Enviado em <?= date('d/m/Y \à\s H:i', strtotime($manifestacao['criado_em'])) ?></div>
          </div>
          <div class="acomp-status <?= e(classeStatus($status)) ?>" id="statusAtualBadge">
            <i class="fa-solid <?= e(iconeStatus($status)) ?>"></i>
            <?= e($status) ?>
          </div>
        </div>

        <!-- Timeline de progresso -->
        <div class="acomp-timeline">
          <?php foreach ($passos as $i => $passo): ?>
            <div class="acomp-step <?= $i <= $passoAtual ? 'acomp-step-done' : '' ?> <?= $i === $passoAtual ? 'acomp-step-current' : '' ?>">
              <div class="acomp-step-circle">
                <?php if ($i < $passoAtual): ?>
                  <i class="fa-solid fa-check"></i>
                <?php elseif ($i === $passoAtual): ?>
                  <i class="fa-solid fa-circle-dot"></i>
                <?php else: ?>
                  <span><?= $i + 1 ?></span>
                <?php endif; ?>
              </div>
              <div class="acomp-step-label"><?= e($passo) ?></div>
            </div>
            <?php if ($i < count($passos) - 1): ?>
              <div class="acomp-step-line <?= $i < $passoAtual ? 'acomp-step-line-done' : '' ?>"></div>
            <?php endif; ?>
          <?php endforeach; ?>
        </div>

        <!-- Dados da manifestação -->
        <div class="acomp-grid">
          <div class="acomp-field">
            <div class="acomp-field-label">Tipo</div>
            <div class="acomp-field-value"><?= e($manifestacao['tipo_descricao'] ?? 'Não informado') ?></div>
          </div>
          <div class="acomp-field">
            <div class="acomp-field-label">Assunto</div>
            <div class="acomp-field-value"><?= e($manifestacao['assunto'] ?? '') ?></div>
          </div>
          <?php if (!empty($manifestacao['setor_relacionado'])): ?>
          <div class="acomp-field">
            <div class="acomp-field-label">Setor relacionado</div>
            <div class="acomp-field-value"><?= e($manifestacao['setor_relacionado']) ?></div>
          </div>
          <?php endif; ?>
          <?php if (!empty($manifestacao['turma_setor'])): ?>
          <div class="acomp-field">
            <div class="acomp-field-label">Turma / Curso</div>
            <div class="acomp-field-value"><?= e($manifestacao['turma_setor']) ?></div>
          </div>
          <?php endif; ?>
        </div>

        <?php if (!empty($manifestacao['feedback'])): ?>
          <div class="acomp-feedback-box">
            <div class="acomp-feedback-title"><i class="fa-solid fa-reply"></i> Resposta do Grêmio</div>
            <div class="acomp-feedback-text"><?= nl2br(e($manifestacao['feedback'])) ?></div>
          </div>
        <?php endif; ?>

        <!-- Arquivos anexados -->
        <?php
          try {
            $stmtArqAc = $pdo->prepare('SELECT * FROM arquivos_manifest WHERE IDmanifest=:id ORDER BY criado_em ASC');
            $stmtArqAc->execute([':id'=>(int)$manifestacao['IDmanifest']]);
            $arquivosAc = $stmtArqAc->fetchAll(PDO::FETCH_ASSOC);
          } catch(Exception $e) { $arquivosAc = []; }
        ?>
        <?php if (!empty($arquivosAc)): ?>
          <div style="padding:20px 28px;border-bottom:1px solid #e5e7eb;">
            <div style="font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:var(--texto-suave);margin-bottom:10px;">
              <i class="fa-solid fa-paperclip me-1"></i>Arquivos anexados
            </div>
            <div class="d-flex flex-wrap gap-2">
              <?php foreach ($arquivosAc as $arqAc): ?>
                <a href="<?= $_base ?>storage/manifestacoes/<?= e($arqAc['nome_arquivo']) ?>" target="_blank"
                   class="badge d-inline-flex align-items-center gap-1 text-decoration-none"
                   style="background:#e8f5ee;color:#1a6b40;border:1px solid #b7ebd2;padding:7px 12px;font-size:.82rem;font-weight:500;border-radius:999px">
                  <i class="fa-solid <?= iconeArquivo($arqAc['mime_type']) ?>"></i>
                  <?= e(mb_strimwidth($arqAc['nome_original'],0,28,'…')) ?>
                </a>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>

        <!-- Histórico de status -->
        <?php
          $historicoAc = buscarHistoricoStatus($pdo, (int)$manifestacao['IDmanifest']);
        ?>
        <?php if (!empty($historicoAc)): ?>
          <div style="padding:20px 28px;border-bottom:1px solid #e5e7eb;">
            <div class="acomp-field-label" style="margin-bottom:14px;">
              <i class="fa-solid fa-clock-rotate-left me-1"></i>Histórico de movimentações
            </div>
            <div class="timeline-hist">
              <?php foreach ($historicoAc as $hi): ?>
                <div class="timeline-hist-item">
                  <div class="timeline-hist-dot"></div>
                  <div class="timeline-hist-content">
                    <div class="d-flex align-items-center gap-2 flex-wrap mb-1">
                      <span style="background:#f3f4f6;color:#4b5563;border:1px solid #e5e7eb;padding:2px 10px;border-radius:999px;font-size:.75rem;font-weight:600"><?= e($hi['status_anterior']) ?></span>
                      <i class="fa-solid fa-arrow-right" style="color:#9ca3af;font-size:.7rem"></i>
                      <span class="<?= classeStatus($hi['status_novo']) ?>" style="padding:2px 10px;border-radius:999px;font-size:.75rem;font-weight:700"><?= e($hi['status_novo']) ?></span>
                    </div>
                    <div class="acomp-data">
                      <i class="fa-regular fa-clock me-1"></i><?= date('d/m/Y \à\s H:i', strtotime($hi['criado_em'])) ?>
                      <?php if ($hi['adm_nome']): ?> · <i class="fa-solid fa-user-shield me-1"></i><?= e($hi['adm_nome']) ?><?php endif; ?>
                    </div>
                    <?php if ($hi['observacao']): ?>
                      <div style="margin-top:6px;padding:8px 12px;border-radius:8px;background:#f0fdf4;border:1px solid #bbf7d0;font-size:.82rem;color:#374151;line-height:1.5"><?= nl2br(e($hi['observacao'])) ?></div>
                    <?php endif; ?>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>

        <!-- Conversa de respostas -->
        <?php
          try {
            $stmtResAc = $pdo->prepare('SELECT * FROM respostas_manifest WHERE IDmanifest=:id ORDER BY criado_em ASC');
            $stmtResAc->execute([':id'=>(int)$manifestacao['IDmanifest']]);
            $respostasAc = $stmtResAc->fetchAll(PDO::FETCH_ASSOC);
            // Marcar respostas do adm como lidas pelo usuário
            if (usuarioLogado()) {
                $pdo->prepare("UPDATE respostas_manifest SET lida_pelo_usuario=1 WHERE IDmanifest=:id AND autor_tipo='adm'")->execute([':id'=>(int)$manifestacao['IDmanifest']]);
            }
          } catch(Exception $e) { $respostasAc = []; }
        ?>
        <?php if (!empty($respostasAc)): ?>
          <div style="padding:20px 28px;border-bottom:1px solid #e5e7eb;">
            <div style="font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:var(--texto-suave);margin-bottom:12px;">
              <i class="fa-solid fa-comments me-1"></i>Conversa com o Grêmio
            </div>
            <div class="d-flex flex-column gap-2" id="chatRespostas">
              <?php foreach ($respostasAc as $rAc): ?>
                <?php $ehAdmResposta = $rAc['autor_tipo'] === 'adm'; ?>
                <div class="d-flex <?= $ehAdmResposta ? 'justify-content-start' : 'justify-content-end' ?>">
                  <div class="chat-bubble-acomp <?= $ehAdmResposta ? 'chat-acomp-adm' : 'chat-acomp-usu' ?>" data-msg-id="<?= (int)$rAc['IDresposta'] ?>">
                    <div class="chat-autor-acomp"><?= e($rAc['autor_nome']) ?> · <?= date('d/m/Y H:i', strtotime($rAc['criado_em'])) ?></div>
                    <div><?= nl2br(e($rAc['mensagem'])) ?></div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>

        <!-- Caixa de resposta do usuário (apenas para manifestações vinculadas à conta) -->
        <?php if (usuarioLogado() && isset($manifestacao['IDusu']) && (int)$manifestacao['IDusu'] === (int)$_SESSION['usuario']['id']): ?>
          <div style="padding:20px 28px;border-bottom:1px solid #e5e7eb;">
            <div style="font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:var(--texto-suave);margin-bottom:10px;">
              <i class="fa-solid fa-reply me-1"></i>Enviar resposta
            </div>
            <form method="post">
              <?= csrfInput() ?>
              <input type="hidden" name="acao" value="responder_usuario">
              <input type="hidden" name="protocolo" value="<?= e($protocolo) ?>">
              <input type="hidden" name="id_manifest" value="<?= (int)$manifestacao['IDmanifest'] ?>">
              <textarea name="mensagem" class="form-control mb-2" rows="3"
                placeholder="Escreva uma resposta ao Grêmio Escolar..."></textarea>
              <button type="submit" class="btn btn-sm" style="background:var(--verde);color:#fff;border-radius:999px;padding:8px 18px;">
                <i class="fa-solid fa-paper-plane me-1"></i>Enviar
              </button>
            </form>
          </div>
        <?php endif; ?>

        <!-- Avaliação por estrelas -->
        <div class="acomp-util-box">
          <div class="acomp-util-title">Como você avalia o atendimento do Grêmio?</div>
          <?php
            $notaAtual   = $manifestacao['nota_satisfacao'] ?? null;
            $comentAtual = $manifestacao['comentario_satisfacao'] ?? '';
          ?>
          <?php if ($notaAtual): ?>
            <div style="margin-top:10px;">
              <div style="font-size:1.5rem;letter-spacing:4px;margin-bottom:6px;">
                <?php for($i=1;$i<=5;$i++) echo $i<=(int)$notaAtual ? '★' : '☆'; ?>
              </div>
              <p style="font-size:.85rem;color:var(--texto-suave);">Você avaliou com <?= (int)$notaAtual ?> estrela<?= $notaAtual>1?'s':'' ?>. <?= $comentAtual ? 'Comentário: "'.e($comentAtual).'"' : '' ?></p>
            </div>
          <?php else: ?>
            <form method="post" style="margin-top:12px;" id="formEstrelas">
              <?= csrfInput() ?>
              <input type="hidden" name="protocolo" value="<?= e($protocolo) ?>">
              <input type="hidden" name="id_manifest" value="<?= (int)$manifestacao['IDmanifest'] ?>">
              <input type="hidden" name="nota_satisfacao" id="inputNota" value="">
              <div class="star-rating" id="starRating">
                <?php for($i=1;$i<=5;$i++): ?>
                  <button type="button" class="star-btn" data-val="<?= $i ?>" title="<?= $i ?> estrela<?= $i>1?'s':'' ?>">★</button>
                <?php endfor; ?>
              </div>
              <div style="margin-top:10px;" id="comentarioWrap" hidden>
                <textarea name="comentario_satisfacao" class="form-control" rows="2"
                  placeholder="Deixe um comentário opcional..." style="font-size:.88rem;border-radius:12px;resize:none;"></textarea>
                <button type="submit" class="btn btn-sm mt-2" style="background:var(--verde);color:#fff;border-radius:999px;padding:6px 18px;">
                  <i class="fa-solid fa-check me-1"></i>Enviar avaliação
                </button>
              </div>
            </form>
            <style>
              .star-rating { display:flex; gap:6px; }
              .star-btn { background:none; border:none; font-size:2rem; color:#d1d5db; cursor:pointer; padding:0; transition:color .15s, transform .1s; }
              .star-btn.ativo, .star-btn:hover { color:#f59e0b; transform:scale(1.15); }
            </style>
            <script>
            (function(){
              const stars = document.querySelectorAll('.star-btn');
              const inputNota = document.getElementById('inputNota');
              const comentWrap = document.getElementById('comentarioWrap');
              stars.forEach(s => {
                s.addEventListener('mouseenter', () => {
                  stars.forEach(x => x.classList.toggle('ativo', +x.dataset.val <= +s.dataset.val));
                });
                s.addEventListener('mouseleave', () => {
                  const sel = +inputNota.value;
                  stars.forEach(x => x.classList.toggle('ativo', sel && +x.dataset.val <= sel));
                });
                s.addEventListener('click', () => {
                  inputNota.value = s.dataset.val;
                  stars.forEach(x => x.classList.toggle('ativo', +x.dataset.val <= +s.dataset.val));
                  comentWrap.hidden = false;
                });
              });
            })();
            </script>
          <?php endif; ?>
        </div>

      </div>
    <?php endif; ?>

  </div>
</section>


<script>
(function() {
  const input = document.getElementById('protocolo_input');
  const feedback = document.getElementById('protocolo_feedback');
  let debounceTimer;

  if (!input) return;

  // Força maiúsculas automaticamente
  input.addEventListener('input', function() {
    const pos = this.selectionStart;
    this.value = this.value.toUpperCase();
    this.setSelectionRange(pos, pos);
    clearTimeout(debounceTimer);
    const val = this.value.trim();

    if (!val) {
      feedback.innerHTML = '';
      resetEstilo();
      return;
    }

    // Validação de formato em tempo real (visual imediato)
    if (!formatoValido(val)) {
      if (val.length < 3) {
        feedback.innerHTML = '';
        resetEstilo();
      } else {
        mostrarErro('Formato inválido — use: GRE-AAAAMMDD-XXXXXX');
        input.classList.remove('campo-ok');
        input.classList.add('campo-erro');
        tremerCampo();
      }
      return;
    }

    feedback.innerHTML = '<i class="fa-solid fa-spinner fa-spin" style="color:#9ca3af"></i> <span style="color:#9ca3af">Verificando...</span>';

    debounceTimer = setTimeout(() => {
      fetch('<?= $_base ?>app/acompanhar.php?ajax_check=1&protocolo=' + encodeURIComponent(val))
        .then(r => r.json())
        .then(data => {
          if (data.status === 'found') {
            feedback.innerHTML = '<i class="fa-solid fa-circle-check" style="color:#16a34a"></i> <span style="color:#16a34a">Protocolo encontrado — status: <strong>' + data.manifest_status + '</strong></span>';
            input.classList.remove('campo-erro');
            input.classList.add('campo-ok');
          } else if (data.status === 'not_found') {
            mostrarErro('Protocolo não encontrado no sistema.');
            input.classList.remove('campo-ok');
            input.classList.add('campo-erro');
            tremerCampo();
          } else if (data.status === 'invalid') {
            mostrarErro('Formato inválido — use: GRE-AAAAMMDD-XXXXXX');
            input.classList.remove('campo-ok');
            input.classList.add('campo-erro');
          }
        })
        .catch(() => {
          feedback.innerHTML = '';
          resetEstilo();
        });
    }, 500);
  });

  function formatoValido(val) {
    return /^GRE-\d{8}-[A-Z0-9]{6}$/.test(val);
  }

  function mostrarErro(msg) {
    feedback.innerHTML = '<i class="fa-solid fa-circle-xmark" style="color:#e84040"></i> <span style="color:#e84040">' + msg + '</span>';
  }

  function resetEstilo() {
    input.classList.remove('campo-erro', 'campo-ok');
  }

  function tremerCampo() {
    input.style.animation = 'none';
    setTimeout(() => {
      input.style.animation = 'tremerCampo .2s linear 2';
    }, 10);
  }
})();
</script>

<?php if ($manifestacao): ?>
<!-- Dados para o módulo de polling (app.js) -->
<input type="hidden" id="protocoloAtual" value="<?= e($manifestacao['protocolo']) ?>">
<script>window._baseUrl = '<?= BASE_URL ?>';</script>
<?php endif; ?>

<style>
@keyframes chatEntrar {
  from { opacity: 0; transform: translateY(8px); }
  to   { opacity: 1; transform: translateY(0); }
}
</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
