<?php
require_once __DIR__ . '/../config/bootstrap.php';

// Administrador não deve acessar a página de manifestação — redireciona para o painel
if (administradorLogado()) {
    header('Location: ' . BASE_URL . 'app/painel/adm.php');
    exit;
}

$pdo = conectarPDO();
$tipoSelecionado = $_GET['tipo'] ?? ($_POST['tipo'] ?? '');

// Lógica do modo: anonimo / identificado
$modoEnvio = $_GET['modo'] ?? null;


$protocoloGerado = null;

$dadosFormulario = [
    'tipo'              => $tipoSelecionado,
    'nome'              => (usuarioLogado() && $modoEnvio === 'identificado') ? ($_SESSION['usuario']['nome'] ?? '') : '',
    'perfil'            => '',
    'email'             => (usuarioLogado() && $modoEnvio === 'identificado') ? ($_SESSION['usuario']['email'] ?? '') : '',
    'turma_setor'       => '',
    'assunto'           => '',
    'setor_relacionado' => '',
    'descricao'         => '',
    'data_ocorrencia'   => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validarCSRF();
    $modoEnvio = trim($_POST['modo'] ?? 'anonimo');

    $dadosFormulario = [
        'tipo'              => trim($_POST['tipo'] ?? ''),
        'nome'              => $modoEnvio === 'anonimo' ? '' : trim($_POST['nome'] ?? ''),
        'perfil'            => $modoEnvio === 'anonimo' ? '' : trim($_POST['perfil'] ?? ''),
        'email'             => $modoEnvio === 'anonimo' ? trim($_POST['email_anonimo'] ?? '') : trim($_POST['email'] ?? ''),
        'turma_setor'       => trim($_POST['turma_setor'] ?? ''),
        'assunto'           => trim($_POST['assunto'] ?? ''),
        'setor_relacionado' => trim($_POST['setor_relacionado'] ?? ''),
        'descricao'         => trim($_POST['descricao'] ?? ''),
        'data_ocorrencia'   => trim($_POST['data_ocorrencia'] ?? ''),
    ];

    $descricaoTipo = tipoSlugParaDescricao($dadosFormulario['tipo']);

    if ($descricaoTipo === '') {
        flash('erro', 'Selecione o tipo da manifestação.');
        header('Location: ' . BASE_URL . 'app/manifestacao.php?modo=' . urlencode($modoEnvio));
        exit;
    }

    if ($dadosFormulario['assunto'] === '' || mb_strlen($dadosFormulario['assunto']) < 5) {
        flash('erro', 'Informe um assunto com pelo menos 5 caracteres.');
        header('Location: ' . BASE_URL . 'app/manifestacao.php?tipo=' . urlencode($dadosFormulario['tipo']) . '&modo=' . urlencode($modoEnvio));
        exit;
    }

    if (mb_strlen($dadosFormulario['assunto']) > 180) {
        flash('erro', 'O assunto deve ter no máximo 180 caracteres.');
        header('Location: ' . BASE_URL . 'app/manifestacao.php?tipo=' . urlencode($dadosFormulario['tipo']) . '&modo=' . urlencode($modoEnvio));
        exit;
    }

    if ($dadosFormulario['descricao'] === '' || mb_strlen($dadosFormulario['descricao']) < 15) {
        flash('erro', 'Descreva a manifestação com pelo menos 15 caracteres.');
        header('Location: ' . BASE_URL . 'app/manifestacao.php?tipo=' . urlencode($dadosFormulario['tipo']) . '&modo=' . urlencode($modoEnvio));
        exit;
    }

    if (mb_strlen($dadosFormulario['descricao']) > 5000) {
        flash('erro', 'A descrição deve ter no máximo 5000 caracteres.');
        header('Location: ' . BASE_URL . 'app/manifestacao.php?tipo=' . urlencode($dadosFormulario['tipo']) . '&modo=' . urlencode($modoEnvio));
        exit;
    }

    if ($dadosFormulario['email'] !== '' && !filter_var($dadosFormulario['email'], FILTER_VALIDATE_EMAIL)) {
        flash('erro', 'Informe um e-mail válido para retorno ou deixe o campo em branco.');
        header('Location: ' . BASE_URL . 'app/manifestacao.php?tipo=' . urlencode($dadosFormulario['tipo']) . '&modo=' . urlencode($modoEnvio));
        exit;
    }

    // Valida data_ocorrencia: formato correto e não futura
    if ($dadosFormulario['data_ocorrencia'] !== '') {
        $dataObj = DateTime::createFromFormat('Y-m-d', $dadosFormulario['data_ocorrencia']);
        $dataValida = $dataObj && $dataObj->format('Y-m-d') === $dadosFormulario['data_ocorrencia'];
        if (!$dataValida) {
            flash('erro', 'Data de ocorrência inválida.');
            header('Location: ' . BASE_URL . 'app/manifestacao.php?tipo=' . urlencode($dadosFormulario['tipo']) . '&modo=' . urlencode($modoEnvio));
            exit;
        }
        if ($dataObj > new DateTime('today')) {
            flash('erro', 'A data de ocorrência não pode ser no futuro.');
            header('Location: ' . BASE_URL . 'app/manifestacao.php?tipo=' . urlencode($dadosFormulario['tipo']) . '&modo=' . urlencode($modoEnvio));
            exit;
        }
    }

    $stmtTipo = $pdo->prepare('SELECT IDtipo, descricao FROM tipos WHERE descricao = :descricao LIMIT 1');
    $stmtTipo->execute([':descricao' => $descricaoTipo]);
    $tipoBanco = $stmtTipo->fetch(PDO::FETCH_ASSOC);

    if (!$tipoBanco) {
        flash('erro', 'Não foi possível localizar o tipo de manifestação no banco.');
        header('Location: ' . BASE_URL . 'app/manifestacao.php?modo=' . urlencode($modoEnvio));
        exit;
    }

    $anonimo = ($modoEnvio === 'anonimo');
    $nomeManifestante  = $anonimo ? 'Anônimo' : ($dadosFormulario['nome'] !== '' ? $dadosFormulario['nome'] : 'Identificado');
    $perfilManifestante = $anonimo
        ? 'Anônimo'
        : ($dadosFormulario['perfil'] !== '' ? $dadosFormulario['perfil'] : 'Não informado');

    $usuarioId = null;
    if (usuarioLogado() && !$anonimo && isset($_SESSION['usuario']['id'])) {
        $idSessao = (int) $_SESSION['usuario']['id'];
        $stmtUsuario = $pdo->prepare('SELECT IDusu FROM tbusuarios WHERE IDusu = :id LIMIT 1');
        $stmtUsuario->execute([':id' => $idSessao]);
        if ($stmtUsuario->fetch(PDO::FETCH_ASSOC)) {
            $usuarioId = $idSessao;
        }
    }

    // INSERT com retry automático em caso de colisão de protocolo (SQLSTATE 23000).
    // A constraint UNIQUE(protocolo) no banco é a barreira definitiva contra race conditions.
    $idManifestInserted = null;
    for ($tentativa = 0; $tentativa < 5; $tentativa++) {
        $protocoloGerado = gerarProtocoloUnico($pdo);

        $stmt = $pdo->prepare('
            INSERT INTO tbmanifest (
                IDusu, IDadm, IDtipo, protocolo, assunto, manifest, status,
                feedback, contato, nome_manifestante, perfil_manifestante,
                turma_setor, setor_relacionado, data_ocorrencia, criado_em
            ) VALUES (
                :idusu, NULL, :idtipo, :protocolo, :assunto, :manifest, :status,
                NULL, :contato, :nome_manifestante, :perfil_manifestante,
                :turma_setor, :setor_relacionado, :data_ocorrencia, NOW()
            )
        ');

        $stmt->bindValue(':idusu',               $usuarioId,                    $usuarioId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':idtipo',              (int) $tipoBanco['IDtipo'],    PDO::PARAM_INT);
        $stmt->bindValue(':protocolo',           $protocoloGerado,              PDO::PARAM_STR);
        $stmt->bindValue(':assunto',             $dadosFormulario['assunto'],   PDO::PARAM_STR);
        $stmt->bindValue(':manifest',            $dadosFormulario['descricao'], PDO::PARAM_STR);
        $stmt->bindValue(':status',              'Recebida',                    PDO::PARAM_STR);
        $stmt->bindValue(':contato',             $dadosFormulario['email'] !== '' ? $dadosFormulario['email'] : null,
                                                 $dadosFormulario['email'] !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':nome_manifestante',   $nomeManifestante,             PDO::PARAM_STR);
        $stmt->bindValue(':perfil_manifestante', $perfilManifestante,           PDO::PARAM_STR);
        $stmt->bindValue(':turma_setor',         $dadosFormulario['turma_setor'] !== '' ? $dadosFormulario['turma_setor'] : null,
                                                 $dadosFormulario['turma_setor'] !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':data_ocorrencia',
            $dadosFormulario['data_ocorrencia'] !== '' ? $dadosFormulario['data_ocorrencia'] : null,
            $dadosFormulario['data_ocorrencia'] !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL
        );
        $stmt->bindValue(':setor_relacionado',   $dadosFormulario['setor_relacionado'] !== '' ? $dadosFormulario['setor_relacionado'] : null,
                                                 $dadosFormulario['setor_relacionado'] !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);

        try {
            $stmt->execute();
            $idManifestInserted = (int) $pdo->lastInsertId();
            break; // sucesso — sai do loop
        } catch (PDOException $e) {
            // SQLSTATE 23000 = Integrity Constraint Violation (protocolo duplicado)
            if ($e->getCode() === '23000' && $tentativa < 4) {
                continue; // tenta com outro protocolo
            }
            throw $e; // outros erros ou esgotou tentativas — propaga
        }
    }

    if ($idManifestInserted === null) {
        throw new \RuntimeException('Não foi possível gerar um protocolo único após 5 tentativas.');
    }

    // Registra status inicial no histórico
    registrarHistoricoStatus(
        pdo:            $pdo,
        idManifest:     $idManifestInserted,
        idAdm:          null,
        statusAnterior: 'Nova',
        statusNovo:     'Recebida',
        observacao:     'Manifestação criada'
    );

    // Upload de arquivos (se houver)
    if (!empty($_FILES['arquivos']['tmp_name'][0])) {
        salvarArquivosManifestacao($pdo, $idManifestInserted, $_FILES['arquivos']);
    }

    // Notificação para TODOS os administradores (consistente com acompanhar.php)
    try {
        $stmtAdmN = $pdo->query('SELECT IDadm FROM tbadm');
        while ($admRow = $stmtAdmN->fetch(PDO::FETCH_ASSOC)) {
            criarNotificacao($pdo, [
                'IDadm'    => (int)$admRow['IDadm'],
                'tipo'     => 'nova_manifestacao',
                'titulo'   => 'Nova manifestação recebida',
                'mensagem' => 'Protocolo ' . $protocoloGerado . ': ' . mb_substr($dadosFormulario['assunto'], 0, 80),
                'link'     => BASE_URL . 'app/painel/adm.php#manifest-' . $idManifestInserted,
            ]);
        }
    } catch (Exception $e) {}

    // ── E-mail de confirmação do protocolo ──────────────────────────────
    $emailParaEnvio = $dadosFormulario['email'] ?? '';
    // Se logado e identificado, usa o e-mail da sessão como fallback
    if ($emailParaEnvio === '' && !$anonimo && usuarioLogado()) {
        $emailParaEnvio = $_SESSION['usuario']['email'] ?? '';
    }

    if ($emailParaEnvio !== '' && filter_var($emailParaEnvio, FILTER_VALIDATE_EMAIL)) {
        $linkAcomp = APP_URL . '/app/acompanhar.php?protocolo=' . urlencode($protocoloGerado);
        enviarEmailProtocolo(
            emailDestino:     $emailParaEnvio,
            nomeDestinatario: $nomeManifestante,
            protocolo:        $protocoloGerado,
            assunto:          $dadosFormulario['assunto'],
            tipo:             $descricaoTipo,
            linkAcompanhar:   $linkAcomp
        );
        // Erro de e-mail não impede o fluxo — apenas silencioso
    }

    flash('sucesso', 'Manifestação enviada com sucesso. Seu protocolo é ' . $protocoloGerado . '.');
    header('Location: ' . BASE_URL . 'app/manifestacao.php?sucesso=1&protocolo=' . urlencode($protocoloGerado) . '&modo=' . urlencode($modoEnvio));
    exit;
}

$protocoloGerado = $_GET['protocolo'] ?? null;
$tituloPagina = 'Fazer Manifestação — Ouvidoria do Grêmio Escolar';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
  <div class="page-header-inner">
    <span class="section-label">OUVIDORIA DO GRÊMIO ESCOLAR</span>
    <h1>Fazer <em>Manifestação</em></h1>
    <p>Registre sua sugestão, elogio, reclamação ou denúncia para o Grêmio Escolar da EEEP Dom Walfrido Teixeira Vieira.</p>
  </div>
</div>

<?php if ($modoEnvio === null): ?>
<!-- ================================================
     TELA DE ESCOLHA: ANÔNIMO OU IDENTIFICADO
     ================================================ -->
<section class="form-section">
  <div class="form-card" style="max-width:680px;margin:0 auto;">
    <div style="text-align:center;margin-bottom:32px;">
      <div style="width:64px;height:64px;background:#e8f5ee;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;">
        <i class="fa-solid fa-shield-halved" style="font-size:1.8rem;color:var(--verde,#1a6b40);"></i>
      </div>
      <h2 style="font-family:'Playfair Display',serif;font-size:1.6rem;font-weight:900;color:var(--verde-escuro,#0d3d24);margin-bottom:10px;">
        Como deseja se identificar?
      </h2>
      <p style="color:var(--texto-suave,#666);font-size:0.95rem;max-width:460px;margin:0 auto;">
        Você pode enviar sua manifestação de forma anônima ou se identificar para acompanhá-la com mais facilidade.
      </p>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:18px;">

      <!-- Cartão Anônimo -->
      <a href="<?= $_base ?>app/manifestacao.php?modo=anonimo<?= $tipoSelecionado ? '&tipo='.urlencode($tipoSelecionado) : '' ?>"
         class="escolha-modo-card">
        <div class="escolha-icon-wrap" style="background:#e8eeff;">
          <i class="fa-solid fa-user-secret" style="color:#3b5bdb;"></i>
        </div>
        <h3>Anônimo</h3>
        <p>Sua identidade não será revelada. Apenas assunto, tipo e descrição serão registrados.</p>
        <?php if (usuarioLogado()): ?>
          <div style="margin-top:10px;font-size:0.8rem;color:#3b5bdb;font-weight:600;">
            <i class="fa-solid fa-shield-halved"></i> Sua conta não será vinculada
          </div>
        <?php endif; ?>
      </a>

      <!-- Cartão Identificado -->
      <?php if (usuarioLogado()): ?>
        <a href="<?= $_base ?>app/manifestacao.php?modo=identificado<?= $tipoSelecionado ? '&tipo='.urlencode($tipoSelecionado) : '' ?>" class="escolha-modo-card escolha-modo-card--destaque">
        <div class="escolha-icon-wrap" style="background:#e0f5ea;">
          <i class="fa-solid fa-id-card" style="color:var(--verde,#1a6b40);"></i>
        </div>
        <h3>Identificado</h3>
        <p>Enviar como <strong><?= e($_SESSION['usuario']['nome']) ?></strong>. Seus dados serão preenchidos automaticamente.</p>
        <div style="margin-top:10px;font-size:0.8rem;color:var(--verde,#1a6b40);font-weight:600;">
          <i class="fa-solid fa-circle-check"></i> Conta conectada
        </div>
      <?php else: ?>
        <a href="<?= $_base ?>app/auth/login.php" class="escolha-modo-card">
        <div class="escolha-icon-wrap" style="background:#e0f5ea;">
          <i class="fa-solid fa-id-card" style="color:var(--verde,#1a6b40);"></i>
        </div>
        <h3>Identificado</h3>
        <p>Faça login para vincular a manifestação à sua conta e acompanhar seu andamento.</p>
        <div style="margin-top:10px;font-size:0.82rem;color:var(--verde,#1a6b40);font-weight:600;">
          <i class="fa-solid fa-right-to-bracket"></i> Ir para o login
        </div>
      <?php endif; ?>
      </a>
    </div>

    <p style="text-align:center;margin-top:24px;font-size:0.82rem;color:#999;">
      <i class="fa-solid fa-lock" style="margin-right:4px;"></i>
      Todas as manifestações são tratadas com sigilo pelo Grêmio Escolar.
    </p>
  </div>
</section>


<?php else: ?>
<!-- ================================================
     FORMULÁRIO DE MANIFESTAÇÃO
     ================================================ -->
<section class="form-section">
  <div class="form-card">
    <?php if (isset($_GET['sucesso']) && $protocoloGerado): ?>
      <div style="margin-bottom:24px;padding:18px 20px;border-radius:16px;background:#ecf9f0;border:1px solid #bfe5cb;color:#14532d;">
        <strong>Manifestação registrada com sucesso.</strong><br>
        Protocolo: <strong><?= e($protocoloGerado) ?></strong>
      </div>
    <?php endif; ?>

    <!-- Badge de modo -->
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:24px;padding:12px 16px;border-radius:12px;
      background:<?= $modoEnvio === 'anonimo' ? '#f0f4ff' : '#ecf9f0' ?>;
      border:1px solid <?= $modoEnvio === 'anonimo' ? '#c7d4f7' : '#bfe5cb' ?>;">
      <i class="fa-solid <?= $modoEnvio === 'anonimo' ? 'fa-user-secret' : 'fa-id-card' ?>"
         style="color:<?= $modoEnvio === 'anonimo' ? '#3b5bdb' : '#1a6b40' ?>;font-size:1.1rem;"></i>
      <div>
        <strong style="font-size:0.9rem;color:<?= $modoEnvio === 'anonimo' ? '#1e3a8a' : '#14532d' ?>;">
          <?= $modoEnvio === 'anonimo' ? 'Modo anônimo ativado' : 'Modo identificado ativado' ?>
        </strong>
        <span style="font-size:0.82rem;color:#666;margin-left:6px;">
          <?= $modoEnvio === 'anonimo'
            ? 'Seus dados pessoais não serão registrados.'
            : 'Seus dados serão vinculados à manifestação.' ?>
        </span>
      </div>
      <a href="<?= $_base ?>app/manifestacao.php<?= $tipoSelecionado ? '?tipo='.urlencode($tipoSelecionado) : '' ?>"
         style="margin-left:auto;font-size:0.8rem;color:var(--verde,#1a6b40);text-decoration:none;font-weight:600;white-space:nowrap;">
        <i class="fa-solid fa-rotate-left"></i> Alterar
      </a>
    </div>

    <div style="margin-bottom:32px">
      <h3 style="font-family:'Playfair Display',serif;font-size:1.5rem;font-weight:900;color:var(--verde-escuro);margin-bottom:8px">
        Canal do Grêmio Escolar
      </h3>
      <p style="color:var(--texto-suave);font-size:0.92rem">
        Antes do envio final, você poderá revisar todos os dados informados.
      </p>
    </div>

    <form method="post" novalidate id="formManifestacao" enctype="multipart/form-data">
      <?= csrfInput() ?>
      <input type="hidden" name="modo" value="<?= e($modoEnvio) ?>">

      <div class="form-group">
        <label for="tipo">Tipo de manifestação <span style="color:#e84040">*</span></label>
        <select id="tipo" name="tipo" class="form-control" required>
          <option value="">Selecione</option>
          <option value="sugestao"   <?= $dadosFormulario['tipo'] === 'sugestao'   ? 'selected' : '' ?>>Sugestão</option>
          <option value="elogio"     <?= $dadosFormulario['tipo'] === 'elogio'     ? 'selected' : '' ?>>Elogio</option>
          <option value="reclamacao" <?= $dadosFormulario['tipo'] === 'reclamacao' ? 'selected' : '' ?>>Reclamação</option>
          <option value="denuncia"   <?= $dadosFormulario['tipo'] === 'denuncia'   ? 'selected' : '' ?>>Denúncia</option>
        </select>
      </div>

      <?php if ($modoEnvio === 'identificado'): ?>
      <!-- Campos de identificação — ocultos no modo anônimo -->
      <div class="form-row">
        <div class="form-group">
          <label for="nome">Nome completo</label>
          <input type="text" id="nome" name="nome" class="form-control"
            placeholder="Seu nome completo"
            value="<?= e($dadosFormulario['nome']) ?>">
        </div>
        <div class="form-group">
          <label for="perfil">Perfil</label>
          <select id="perfil" name="perfil" class="form-control">
            <option value="">Selecione</option>
            <?php foreach (['Aluno(a)', 'Responsável', 'Professor(a)', 'Servidor(a)'] as $perfil): ?>
              <option value="<?= e($perfil) ?>" <?= $dadosFormulario['perfil'] === $perfil ? 'selected' : '' ?>><?= e($perfil) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label for="email">E-mail para retorno</label>
          <input type="email" id="email" name="email" class="form-control"
            placeholder="opcional@email.com"
            value="<?= e($dadosFormulario['email']) ?>">
        </div>
        <div class="form-group">
          <label for="turma_setor">Turma / Curso</label>
          <select id="turma_setor" name="turma_setor" class="form-control">
            <option value="">Selecione sua turma</option>
            <optgroup label="Informática">
              <?php foreach (['1°Informática','2°Informática','3°Informática'] as $op): ?>
                <option value="<?= e($op) ?>" <?= $dadosFormulario['turma_setor'] === $op ? 'selected' : '' ?>><?= e($op) ?></option>
              <?php endforeach; ?>
            </optgroup>
            <optgroup label="Saúde Bucal">
              <?php foreach (['1°Saúde Bucal','2°Saúde Bucal','3°Saúde Bucal'] as $op): ?>
                <option value="<?= e($op) ?>" <?= $dadosFormulario['turma_setor'] === $op ? 'selected' : '' ?>><?= e($op) ?></option>
              <?php endforeach; ?>
            </optgroup>
            <optgroup label="Energias Renováveis">
              <?php foreach (['1°Energias renováveis','2°Energias renováveis','3°Energias renováveis'] as $op): ?>
                <option value="<?= e($op) ?>" <?= $dadosFormulario['turma_setor'] === $op ? 'selected' : '' ?>><?= e($op) ?></option>
              <?php endforeach; ?>
            </optgroup>
            <optgroup label="Enfermagem">
              <?php foreach (['1°Enfermagem','2°Enfermagem','3°Enfermagem'] as $op): ?>
                <option value="<?= e($op) ?>" <?= $dadosFormulario['turma_setor'] === $op ? 'selected' : '' ?>><?= e($op) ?></option>
              <?php endforeach; ?>
            </optgroup>
          </select>
        </div>
      </div>
      <?php endif; ?>

      <!-- E-mail opcional para receber protocolo (modo anônimo) -->
      <?php if ($modoEnvio === 'anonimo'): ?>
      <div class="form-group full-width">
        <label for="email_anonimo">
          <i class="fa-regular fa-envelope me-1"></i>E-mail para receber o protocolo
          <span class="badge bg-secondary ms-1" style="font-size:.7rem;font-weight:500;">Opcional</span>
        </label>
        <input type="email" id="email_anonimo" name="email_anonimo" class="form-control"
          placeholder="seuemail@exemplo.com"
          value="<?= e($dadosFormulario['email'] ?? '') ?>">
        <div class="form-text text-muted">
          <i class="fa-solid fa-shield-halved me-1" style="color:var(--verde)"></i>
          Usado apenas para enviar o protocolo. Sua identidade permanece anônima.
        </div>
      </div>
      <?php endif; ?>

      <!-- Campos sempre visíveis -->
      <div class="form-row">
        <div class="form-group">
          <label for="assunto">Assunto <span style="color:#e84040">*</span></label>
          <input type="text" id="assunto" name="assunto" class="form-control"
            placeholder="Descreva o assunto em poucas palavras"
            value="<?= e($dadosFormulario['assunto']) ?>" required>
        </div>
        <div class="form-group">
          <label for="setor_relacionado">Setor relacionado</label>
          <select id="setor_relacionado" name="setor_relacionado" class="form-control">
            <option value="">Selecione</option>
            <?php foreach (['Grêmio Escolar','Coordenação','Professores','Secretaria','Infraestrutura','Laboratório','Outro'] as $setor): ?>
              <option value="<?= e($setor) ?>" <?= $dadosFormulario['setor_relacionado'] === $setor ? 'selected' : '' ?>><?= e($setor) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <!-- Data de Ocorrência e Upload -->
      <div class="form-row">
        <div class="form-group">
          <label for="data_ocorrencia">
            <i class="fa-regular fa-calendar me-1"></i>Data da ocorrência
            <span class="badge bg-secondary ms-1" style="font-size:.7rem;font-weight:500;">Opcional</span>
          </label>
          <input type="date" id="data_ocorrencia" name="data_ocorrencia" class="form-control"
            max="<?= date('Y-m-d') ?>"
            value="<?= e($dadosFormulario['data_ocorrencia'] ?? '') ?>">
          <div class="form-text text-muted">Se a situação ocorreu em uma data específica.</div>
        </div>
        <div class="form-group">
          <label for="arquivos">
            <i class="fa-solid fa-paperclip me-1"></i>Anexar arquivos
            <span class="badge bg-secondary ms-1" style="font-size:.7rem;font-weight:500;">Opcional</span>
          </label>
          <input type="file" id="arquivos" name="arquivos[]" class="form-control" multiple
            accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.xls,.xlsx,.txt,.mp4,.mp3">
          <div class="form-text text-muted">Imagens, PDFs ou documentos. Máx. 10 MB por arquivo.</div>
          <div id="arquivosPreview" class="mt-2 d-flex flex-wrap gap-2"></div>
        </div>
      </div>

      <div class="form-group full-width">
        <label for="descricao">Descrição <span style="color:#e84040">*</span></label>
        <textarea id="descricao" name="descricao" class="form-control" rows="7"
          placeholder="Explique sua manifestação com detalhes"><?= e($dadosFormulario['descricao']) ?></textarea>
        <div class="contador-caracteres" id="contadorWrapDescricao">
          <span id="contadorDescricao">0</span>/15 caracteres mínimos
        </div>
      </div>

      <div class="form-group full-width">
        <button type="button" class="btn-revisar" id="btnAbrirConfirmacao">
          <i class="fa-solid fa-paper-plane"></i> Revisar antes de enviar
        </button>
      </div>
    </form>
  </div>
</section>

<!-- Modal de confirmação -->
<div id="modalConfirmacao" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:9999;align-items:center;justify-content:center;padding:16px;overflow-y:auto;">
  <div style="width:100%;max-width:680px;background:#fff;border-radius:22px;padding:24px;box-shadow:0 25px 70px rgba(0,0,0,0.22);margin:auto;">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:16px;">
      <div>
        <h3 style="margin:0;font-size:1.3rem;color:var(--verde-escuro);font-family:'Playfair Display',serif;">Confirmar manifestação</h3>
        <p style="margin:4px 0 0;color:var(--texto-suave);font-size:0.88rem;">Confira os dados antes do envio final.</p>
      </div>
      <button type="button" id="fecharModalConfirmacao" style="border:none;background:#f1f1f1;width:36px;height:36px;border-radius:50%;cursor:pointer;font-size:18px;flex-shrink:0;">×</button>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:12px;">
      <div style="background:#f8f8f8;padding:12px;border-radius:12px;"><strong style="font-size:.8rem;">Tipo:</strong><br><span id="confTipo" style="font-size:.9rem;"></span></div>
      <div id="confNomeWrap" style="background:#f8f8f8;padding:12px;border-radius:12px;"><strong style="font-size:.8rem;">Nome:</strong><br><span id="confNome" style="font-size:.9rem;"></span></div>
      <div id="confPerfilWrap" style="background:#f8f8f8;padding:12px;border-radius:12px;"><strong style="font-size:.8rem;">Perfil:</strong><br><span id="confPerfil" style="font-size:.9rem;"></span></div>
      <div id="confEmailWrap" style="background:#f8f8f8;padding:12px;border-radius:12px;"><strong style="font-size:.8rem;">E-mail:</strong><br><span id="confEmail" style="font-size:.9rem;"></span></div>
      <div id="confTurmaWrap" style="background:#f8f8f8;padding:12px;border-radius:12px;">
        <strong style="font-size:.8rem;">Turma / Curso:</strong><br><span id="confTurmaSetor" class="badge-curso-preview" style="font-size:.9rem;"></span>
      </div>
      <div style="background:#f8f8f8;padding:12px;border-radius:12px;"><strong style="font-size:.8rem;">Setor:</strong><br><span id="confSetorRelacionado" style="font-size:.9rem;"></span></div>
    </div>

    <div style="background:#f8f8f8;padding:12px;border-radius:12px;margin-bottom:10px;">
      <strong style="font-size:.8rem;">Assunto:</strong><br><span id="confAssunto" style="font-size:.9rem;"></span>
    </div>
    <div style="background:#f8f8f8;padding:12px;border-radius:12px;margin-bottom:18px;max-height:150px;overflow-y:auto;">
      <strong style="font-size:.8rem;">Descrição:</strong><br><span id="confDescricao" style="white-space:pre-wrap;font-size:.88rem;"></span>
    </div>

    <div style="display:flex;justify-content:flex-end;gap:10px;flex-wrap:wrap;">
      <button type="button" id="btnVoltarEdicao" style="padding:11px 16px;border-radius:999px;border:1px solid #ccc;background:#fff;cursor:pointer;font-weight:600;font-size:.9rem;flex:1;min-width:120px;">
        Voltar e editar
      </button>
      <button type="button" id="btnConfirmarEnvio" style="padding:11px 18px;border-radius:999px;border:none;background:var(--verde);color:#fff;cursor:pointer;font-weight:700;font-size:.9rem;flex:1;min-width:120px;">
        Confirmar envio
      </button>
    </div>
  </div>
</div>

<script>
const MODO_ENVIO = <?= json_encode($modoEnvio) ?>;

const formManifestacao    = document.getElementById('formManifestacao');
const modalConfirmacao    = document.getElementById('modalConfirmacao');
const btnAbrirConfirmacao = document.getElementById('btnAbrirConfirmacao');
const btnFecharModal      = document.getElementById('fecharModalConfirmacao');
const btnVoltarEdicao     = document.getElementById('btnVoltarEdicao');
const btnConfirmarEnvio   = document.getElementById('btnConfirmarEnvio');

function valorOuPadrao(valor, padrao = 'Não informado') {
  return (valor || '').trim() !== '' ? (valor || '').trim() : padrao;
}

function classeCurso(valor) {
  if (valor.includes('Informática')) return 'curso-informatica';
  if (valor.includes('Saúde Bucal')) return 'curso-saude';
  if (valor.includes('Energias'))    return 'curso-energias';
  if (valor.includes('Enfermagem'))  return 'curso-enfermagem';
  return 'curso-neutro';
}

function aplicarModoModal() {
  const ocultar = MODO_ENVIO === 'anonimo';
  ['confNomeWrap','confPerfilWrap','confEmailWrap','confTurmaWrap'].forEach(function(id) {
    const el = document.getElementById(id);
    if (el) el.style.display = ocultar ? 'none' : '';
  });
}

function preencherConfirmacao() {
  const tipoSelect  = document.getElementById('tipo');
  const setorSelect = document.getElementById('setor_relacionado');

  document.getElementById('confTipo').textContent =
    tipoSelect.options[tipoSelect.selectedIndex]?.text || 'Não informado';

  if (MODO_ENVIO === 'identificado') {
    const perfilSelect = document.getElementById('perfil');
    const turmaSelect  = document.getElementById('turma_setor');
    document.getElementById('confNome').textContent =
      valorOuPadrao(document.getElementById('nome')?.value, 'Não informado');
    document.getElementById('confPerfil').textContent =
      valorOuPadrao(perfilSelect?.options[perfilSelect?.selectedIndex]?.text, 'Não informado');
    document.getElementById('confEmail').textContent =
      valorOuPadrao(document.getElementById('email')?.value);
    const turmaValor = valorOuPadrao(turmaSelect?.options[turmaSelect?.selectedIndex]?.text);
    const confTurma  = document.getElementById('confTurmaSetor');
    if (confTurma) { confTurma.textContent = turmaValor; confTurma.className = 'badge-curso-preview ' + classeCurso(turmaValor); }
  }

  document.getElementById('confSetorRelacionado').textContent =
    valorOuPadrao(setorSelect.options[setorSelect.selectedIndex]?.text);
  document.getElementById('confAssunto').textContent =
    valorOuPadrao(document.getElementById('assunto').value);
  document.getElementById('confDescricao').textContent =
    valorOuPadrao(document.getElementById('descricao').value);
}

function abrirModalConfirmacao() {
  preencherConfirmacao();
  aplicarModoModal();
  modalConfirmacao.style.display = 'flex';
  document.body.style.overflow   = 'hidden';
}

function fecharModalConfirmacao() {
  modalConfirmacao.style.display = 'none';
  document.body.style.overflow   = '';
}

btnAbrirConfirmacao.addEventListener('click', function () {
  const tipo      = document.getElementById('tipo').value.trim();
  const assunto   = document.getElementById('assunto').value.trim();
  const descricao = document.getElementById('descricao').value.trim();
  const emailEl   = MODO_ENVIO === 'anonimo'
    ? document.getElementById('email_anonimo')
    : document.getElementById('email');
  const email     = emailEl ? emailEl.value.trim() : '';

  if (!tipo)           { alert('Selecione o tipo da manifestação.'); return; }
  if (assunto.length < 5)  { alert('Informe um assunto com pelo menos 5 caracteres.'); return; }
  if (descricao.length < 15) { alert('Descreva a manifestação com pelo menos 15 caracteres.'); return; }
  if (email !== '' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
    alert('Informe um e-mail válido ou deixe o campo em branco.'); return;
  }
  abrirModalConfirmacao();
});

btnFecharModal.addEventListener('click', fecharModalConfirmacao);
btnVoltarEdicao.addEventListener('click', fecharModalConfirmacao);
modalConfirmacao.addEventListener('click', function(e) { if (e.target === modalConfirmacao) fecharModalConfirmacao(); });
btnConfirmarEnvio.addEventListener('click', function () { formManifestacao.submit(); });

// Contador de caracteres
const descricaoEl = document.getElementById('descricao');
const contadorEl  = document.getElementById('contadorDescricao');
if (descricaoEl && contadorEl) {
  descricaoEl.addEventListener('input', function () { contadorEl.textContent = this.value.length; });
  // Inicializa se já tiver conteúdo (recarregamento de página)
  contadorEl.textContent = descricaoEl.value.length;
}
</script>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
