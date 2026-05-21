<?php
if (defined('_APP_FUNCTIONS_LOADED')) return;
define('_APP_FUNCTIONS_LOADED', true);

require_once __DIR__ . '/paths.php';
require_once __DIR__ . '/config.php';
require_once LIB_PATH . '/src/PHPMailer.php';
require_once LIB_PATH . '/src/SMTP.php';
require_once LIB_PATH . '/src/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// ─── HELPERS GERAIS ───────────────────────────────────────────────────────

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function flash(?string $type = null, ?string $message = null): ?array
{
    if ($type !== null && $message !== null) {
        $_SESSION['flash'] = ['type' => $type, 'message' => $message];
        return null;
    }

    if (!isset($_SESSION['flash'])) {
        return null;
    }

    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}

// ─── CSRF ─────────────────────────────────────────────────────────────────

/**
 * Retorna o token CSRF da sessão, criando-o se ainda não existir.
 */
function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Valida o token CSRF enviado pelo formulário.
 * Encerra a requisição com 403 em caso de falha.
 */
function validarCSRF(): void
{
    $tokenEnviado = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!hash_equals(csrfToken(), $tokenEnviado)) {
        http_response_code(403);
        exit('Requisição inválida (CSRF).');
    }
}

/**
 * Renderiza o campo hidden com o token CSRF.
 * Use dentro de todo <form method="post">.
 */
function csrfInput(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrfToken()) . '">';
}

// ─── AUTENTICAÇÃO ─────────────────────────────────────────────────────────

function usuarioLogado(): bool
{
    return isset($_SESSION['usuario']) && !empty($_SESSION['usuario']['id']);
}

function administradorLogado(): bool
{
    return isset($_SESSION['admin']) && !empty($_SESSION['admin']['id']);
}

function exigirLoginAdm(): void
{
    if (!administradorLogado()) {
        flash('erro', 'Faça login como administrador para acessar o painel do Grêmio Escolar.');
        header('Location: ' . BASE_URL . 'app/auth/login.php');
        exit;
    }
}

function exigirLoginUsuario(): void
{
    if (!usuarioLogado()) {
        flash('erro', 'Faça login para acessar sua conta.');
        header('Location: ' . BASE_URL . 'app/auth/login.php');
        exit;
    }
}

/**
 * Verifica a senha usando apenas password_verify().
 * IMPORTANTE: nunca aplique trim() à senha antes de chamar esta função.
 * O bcrypt trunca senhas acima de 72 bytes — valide o tamanho máximo
 * antes do hash (veja validarTamanhoSenha()).
 */
function senhaConfere(string $senhaInformada, string $senhaBanco): bool
{
    return password_verify($senhaInformada, $senhaBanco);
}

/**
 * Valida o comprimento da senha: mínimo 8, máximo 72 caracteres (limite do bcrypt).
 * Retorna uma string de erro ou null se válida.
 */
function validarTamanhoSenha(string $senha): ?string
{
    $len = mb_strlen($senha);
    if ($len < 8)  return 'A senha deve ter pelo menos 8 caracteres.';
    if ($len > 72) return 'A senha deve ter no máximo 72 caracteres.';
    return null;
}

// ─── RATE LIMITING (login e recuperação de senha) ─────────────────────────

/**
 * Registra uma tentativa de login falhada para o IP/email e retorna
 * true se o limite foi atingido (bloqueado).
 */
function verificarRateLimit(PDO $pdo, string $chave, int $maxTentativas = 10, int $janelaSegundos = 600): bool
{
    try {
        // Tabela rate_limit é criada via database/schema.sql

        // Limpa entradas antigas
        $pdo->prepare("DELETE FROM rate_limit WHERE criado_em < DATE_SUB(NOW(), INTERVAL :s SECOND)")
            ->execute([':s' => $janelaSegundos]);

        // Conta tentativas no período
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM rate_limit WHERE chave = :c");
        $stmt->execute([':c' => $chave]);
        $count = (int) $stmt->fetchColumn();

        return $count >= $maxTentativas;
    } catch (\PDOException $e) {
        error_log('[rate_limit] ' . $e->getMessage());
        return false;
    }
}

function registrarTentativaFalhada(PDO $pdo, string $chave): void
{
    try {
        $pdo->prepare("INSERT INTO rate_limit (chave) VALUES (:c)")
            ->execute([':c' => $chave]);
    } catch (\PDOException $e) {
        error_log('[rate_limit] ' . $e->getMessage());
    }
}

function limparRateLimit(PDO $pdo, string $chave): void
{
    try {
        $pdo->prepare("DELETE FROM rate_limit WHERE chave = :c")
            ->execute([':c' => $chave]);
    } catch (\PDOException $e) {
        error_log('[rate_limit] ' . $e->getMessage());
    }
}

// ─── COOKIES "LEMBRE-ME" ──────────────────────────────────────────────────

/**
 * Grava um token opaco de "lembrar-me" no banco e define o cookie.
 * O e-mail NÃO é mais salvo em cookie — apenas um token aleatório de 32 bytes.
 */
function setRememberMeCookies(string $email, string $tipoAcesso): void
{
    $pdo    = conectarPDO();
    $token  = bin2hex(random_bytes(32));            // token bruto — vai no cookie
    $hash   = hash('sha256', $token);               // hash — vai no banco
    $expira = time() + (60 * 60 * 24 * 30);
    $expiresAt = date('Y-m-d H:i:s', $expira);

    // Limpa tokens antigos do mesmo e-mail/tipo antes de criar novo
    $pdo->prepare("DELETE FROM remember_tokens WHERE email = :email AND tipo = :tipo")
        ->execute([':email' => $email, ':tipo' => $tipoAcesso]);

    // Limpa tokens expirados globalmente
    $pdo->prepare("DELETE FROM remember_tokens WHERE expires_at < NOW()")->execute();

    $pdo->prepare("INSERT INTO remember_tokens (token_hash, email, tipo, expires_at) VALUES (:h, :e, :t, :exp)")
        ->execute([':h' => $hash, ':e' => $email, ':t' => $tipoAcesso, ':exp' => $expiresAt]);

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
               || (int)($_SERVER['SERVER_PORT'] ?? 80) === 443;

    $opts = [
        'expires'  => $expira,
        'path'     => '/',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Strict',
    ];

    setcookie('remember_token', $token, $opts);    // apenas o token opaco
    setcookie('remember_tipo',  $tipoAcesso, $opts);

    if (session_status() === PHP_SESSION_ACTIVE) {
        setcookie(session_name(), session_id(), $opts);
    }
}

/**
 * Resolve o e-mail e tipo a partir do cookie remember_token.
 * Retorna ['email' => ..., 'tipo' => ...] ou null se inválido/expirado.
 */
function resolverRememberMeCookie(): ?array
{
    $token = $_COOKIE['remember_token'] ?? '';
    $tipo  = $_COOKIE['remember_tipo']  ?? '';
    if ($token === '' || $tipo === '') return null;

    try {
        $pdo  = conectarPDO();
        $hash = hash('sha256', $token);
        $stmt = $pdo->prepare("SELECT email, tipo FROM remember_tokens WHERE token_hash = :h AND expires_at >= NOW() LIMIT 1");
        $stmt->execute([':h' => $hash]);
        $row = $stmt->fetch();
        if (!$row) return null;
        return ['email' => $row['email'], 'tipo' => $row['tipo']];
    } catch (\PDOException $e) {
        error_log('[remember_me] ' . $e->getMessage());
        return null;
    }
}

function clearRememberMeCookies(): void
{
    // Remove token do banco se existir
    $token = $_COOKIE['remember_token'] ?? '';
    if ($token !== '') {
        try {
            $pdo  = conectarPDO();
            $hash = hash('sha256', $token);
            $pdo->prepare("DELETE FROM remember_tokens WHERE token_hash = :h")->execute([':h' => $hash]);
        } catch (\PDOException $e) {
            error_log('[remember_me] ' . $e->getMessage());
        }
    }
    $past = ['expires' => time() - 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Strict'];
    setcookie('remember_token', '', $past);
    setcookie('remember_tipo',  '', $past);
}

// ─── HELPERS DE APRESENTAÇÃO ──────────────────────────────────────────────

function topoAtivo(string $arquivoAtual, string $arquivoLink): string
{
    return basename($arquivoAtual) === $arquivoLink ? 'active' : '';
}

function tipoSlugParaDescricao(string $slug): string
{
    $mapa = [
        'sugestao'   => 'Sugestão',
        'elogio'     => 'Elogio',
        'reclamacao' => 'Reclamação',
        'denuncia'   => 'Denúncia',
    ];
    return $mapa[$slug] ?? '';
}

function gerarProtocoloManifestacao(): string
{
    return 'GRE-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
}

/**
 * Gera um protocolo único aproveitando a constraint UNIQUE do banco.
 * Elimina a race condition de SELECT-then-INSERT: se dois processos gerarem
 * o mesmo protocolo ao mesmo tempo, o PDOException SQLSTATE 23000 dispara
 * e um novo protocolo é gerado automaticamente (até 5 tentativas).
 * Retorna apenas o protocolo gerado — o INSERT real é feito pelo chamador.
 */
function gerarProtocoloUnico(PDO $pdo): string
{
    // Ainda geramos candidatos aleatórios; a unicidade real é garantida
    // pela constraint UNIQUE(protocolo) em tbmanifest no momento do INSERT.
    // Esta função apenas entrega um candidato com entropia suficiente.
    // A colisão é tratada em manifestacao.php via retry no SQLSTATE 23000.
    return gerarProtocoloManifestacao();
}

function classeStatus(string $status): string
{
    return match($status) {
        'Recebida'     => 'status-recebida',
        'Em andamento' => 'status-andamento',
        'Resolvida'    => 'status-resolvida',
        default        => 'status-neutro',
    };
}

function iconeStatus(string $status): string
{
    return match($status) {
        'Recebida'     => 'fa-inbox',
        'Em andamento' => 'fa-spinner',
        'Resolvida'    => 'fa-circle-check',
        default        => 'fa-question',
    };
}

function classeStatusAdm(?string $s): string  { return classeStatus((string)$s); }
function classeStatusConta(string $s): string  { return classeStatus($s); }

function classeCursoAdm(?string $v): string
{
    $v = (string)$v;
    if (str_contains($v, 'Informática'))  return 'curso-informatica';
    if (str_contains($v, 'Saúde Bucal'))  return 'curso-saude';
    if (str_contains($v, 'Energias'))     return 'curso-energias';
    if (str_contains($v, 'Enfermagem'))   return 'curso-enfermagem';
    return 'curso-neutro';
}

function iconeNotificacao(string $tipo): string
{
    return match($tipo) {
        'nova_resposta'     => 'fa-comment',
        'nova_manifestacao' => 'fa-paper-plane',
        'status_atualizado' => 'fa-circle-check',
        default             => 'fa-bell',
    };
}

function iconeArquivo(string $mime): string
{
    if (str_starts_with($mime, 'image/'))                                   return 'fa-file-image';
    if ($mime === 'application/pdf')                                        return 'fa-file-pdf';
    if (str_contains($mime, 'word'))                                        return 'fa-file-word';
    if (str_contains($mime, 'excel') || str_contains($mime, 'spreadsheet')) return 'fa-file-excel';
    if (str_starts_with($mime, 'video/'))                                   return 'fa-file-video';
    if (str_starts_with($mime, 'audio/'))                                   return 'fa-file-audio';
    return 'fa-file';
}

/**
 * Formata um CPF numérico (11 dígitos) para exibição: 000.000.000-00
 * Seguro para usar com e() antes de renderizar em HTML.
 */
function formatarCPF(string $cpf): string
{
    $cpf = preg_replace('/\D/', '', $cpf);
    if (strlen($cpf) !== 11) return $cpf; // devolve como veio se inválido
    return substr($cpf, 0, 3) . '.' . substr($cpf, 3, 3) . '.' . substr($cpf, 6, 3) . '-' . substr($cpf, 9, 2);
}

function formatarTamanho(int $bytes): string
{
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024)    return round($bytes / 1024, 0) . ' KB';
    return $bytes . ' B';
}

// ─── VALIDAÇÕES ───────────────────────────────────────────────────────────

function validarCPF(string $cpf): bool
{
    $cpf = preg_replace('/\D/', '', $cpf);

    if (strlen($cpf) !== 11) return false;
    if (preg_match('/^(\d)\1{10}$/', $cpf)) return false;

    $soma = 0;
    for ($i = 0; $i < 9; $i++) $soma += (int)$cpf[$i] * (10 - $i);
    $dig1 = ($soma * 10) % 11;
    if ($dig1 === 10) $dig1 = 0;
    if ($dig1 !== (int)$cpf[9]) return false;

    $soma = 0;
    for ($i = 0; $i < 10; $i++) $soma += (int)$cpf[$i] * (11 - $i);
    $dig2 = ($soma * 10) % 11;
    if ($dig2 === 10) $dig2 = 0;

    return $dig2 === (int)$cpf[10];
}

/**
 * Valida se o perfil enviado pelo usuário está na lista permitida.
 */
function validarPerfil(string $perfil): bool
{
    return in_array($perfil, ['Aluno(a)', 'Responsável', 'Professor(a)', 'Servidor(a)'], true);
}

function cpfJaCadastrado(PDO $pdo, string $cpf, ?int $ignorarId = null): bool
{
    $cpf = preg_replace('/\D/', '', $cpf);

    if ($ignorarId) {
        $stmt = $pdo->prepare('SELECT IDusu FROM tbusuarios WHERE cpf = :cpf AND IDusu <> :id LIMIT 1');
        $stmt->execute([':cpf' => $cpf, ':id' => $ignorarId]);
    } else {
        $stmt = $pdo->prepare('SELECT IDusu FROM tbusuarios WHERE cpf = :cpf LIMIT 1');
        $stmt->execute([':cpf' => $cpf]);
    }

    return (bool) $stmt->fetch();
}

function emailExisteNoSistema(PDO $pdo, string $email): bool
{
    $stmtAdm = $pdo->prepare('SELECT IDadm FROM tbadm WHERE email = :email LIMIT 1');
    $stmtAdm->execute([':email' => $email]);
    if ($stmtAdm->fetch()) return true;

    $stmtUsu = $pdo->prepare('SELECT IDusu FROM tbusuarios WHERE email = :email LIMIT 1');
    $stmtUsu->execute([':email' => $email]);
    return (bool) $stmtUsu->fetch();
}

// ─── RECUPERAÇÃO DE SENHA ─────────────────────────────────────────────────

function criarTokenRecuperacao(PDO $pdo, string $email): string
{
    $tokenBruto = bin2hex(random_bytes(32));
    $tokenHash  = hash('sha256', $tokenBruto);
    $expiresAt  = date('Y-m-d H:i:s', strtotime('+1 hour'));

    // Invalida tokens anteriores do mesmo e-mail
    $pdo->prepare("UPDATE password_resets SET used_at = NOW() WHERE email = :email AND used_at IS NULL")
        ->execute([':email' => $email]);

    $pdo->prepare('INSERT INTO password_resets (email, token, expires_at) VALUES (:email, :token, :expires_at)')
        ->execute([':email' => $email, ':token' => $tokenHash, ':expires_at' => $expiresAt]);

    return $tokenBruto;
}

function buscarResetValidoPorToken(PDO $pdo, string $tokenBruto): array|false
{
    $tokenHash = hash('sha256', $tokenBruto);

    $stmt = $pdo->prepare('
        SELECT *
        FROM password_resets
        WHERE token = :token
          AND used_at IS NULL
          AND expires_at >= NOW()
        ORDER BY id DESC
        LIMIT 1
    ');
    $stmt->execute([':token' => $tokenHash]);
    return $stmt->fetch();
}

function marcarTokenComoUsado(PDO $pdo, int $id): void
{
    $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE id = :id')
        ->execute([':id' => $id]);
}

/**
 * Atualiza senha apenas na tabela correta, com base no tipo de conta do reset.
 * Usa transação para consistência.
 */
function atualizarSenhaPorReset(PDO $pdo, array $reset, string $novaSenhaHash): void
{
    $pdo->beginTransaction();
    try {
        // Descobre em qual tabela o e-mail está
        $stmtAdm = $pdo->prepare('SELECT IDadm FROM tbadm WHERE email = :email LIMIT 1');
        $stmtAdm->execute([':email' => $reset['email']]);
        if ($stmtAdm->fetch()) {
            $pdo->prepare('UPDATE tbadm SET senha = :senha WHERE email = :email')
                ->execute([':senha' => $novaSenhaHash, ':email' => $reset['email']]);
        } else {
            $pdo->prepare('UPDATE tbusuarios SET senha = :senha WHERE email = :email')
                ->execute([':senha' => $novaSenhaHash, ':email' => $reset['email']]);
        }
        marcarTokenComoUsado($pdo, (int)$reset['id']);
        $pdo->commit();
    } catch (\PDOException $e) {
        $pdo->rollBack();
        error_log('[atualizarSenhaPorReset] ' . $e->getMessage());
        throw $e;
    }
}

// ─── UPLOAD DE ARQUIVOS ───────────────────────────────────────────────────

function salvarArquivosManifestacao(PDO $pdo, int $idManifest, array $files): void
{
    $dir = STORAGE_MANIFESTACOES;
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    // Extensões permitidas
    $extPermitidas  = ['jpg','jpeg','png','gif','webp','pdf','doc','docx','xls','xlsx','txt','mp4','mp3'];
    // MIME types reais permitidos (detectados pelo conteúdo, não pelo nome)
    $mimesPermitidos = [
        'image/jpeg','image/png','image/gif','image/webp',
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'text/plain',
        'video/mp4',
        'audio/mpeg',
    ];
    $maxSize = 10 * 1024 * 1024; // 10 MB

    $finfo = new \finfo(FILEINFO_MIME_TYPE);

    foreach ($files['tmp_name'] as $i => $tmp) {
        if (empty($tmp) || !is_uploaded_file($tmp)) continue;
        if ($files['error'][$i] !== UPLOAD_ERR_OK)  continue;
        if ($files['size'][$i] > $maxSize)           continue;

        $ext = strtolower(pathinfo($files['name'][$i], PATHINFO_EXTENSION));
        if (!in_array($ext, $extPermitidas, true)) continue;

        // Detecta MIME real pelo conteúdo do arquivo
        $mimeReal = $finfo->file($tmp);
        if (!in_array($mimeReal, $mimesPermitidos, true)) continue;

        // Validação extra para imagens
        if (str_starts_with($mimeReal, 'image/') && !@getimagesize($tmp)) continue;

        $nomeArquivo = 'mf_' . $idManifest . '_' . uniqid() . '.' . $ext;
        // Sanitiza o nome original: mantém apenas chars seguros para exibição
        $nomeOriginalSanitizado = mb_substr(
            preg_replace('/[^\w\s.\-()]/u', '_', $files['name'][$i]),
            0, 255
        );
        if (move_uploaded_file($tmp, $dir . $nomeArquivo)) {
            $pdo->prepare('
                INSERT INTO arquivos_manifest (IDmanifest, nome_original, nome_arquivo, tamanho, mime_type)
                VALUES (:idm, :nom, :arq, :tam, :mime)
            ')->execute([
                ':idm'  => $idManifest,
                ':nom'  => $nomeOriginalSanitizado,
                ':arq'  => $nomeArquivo,
                ':tam'  => $files['size'][$i],
                ':mime' => $mimeReal,        // salva o MIME real, não o do browser
            ]);
        }
    }
}

// ─── NOTIFICAÇÕES ─────────────────────────────────────────────────────────

/**
 * Colunas permitidas para filtro em notificações (evita SQL injection).
 */
function _validarColunaNotif(string $coluna): void
{
    if (!in_array($coluna, ['IDusu', 'IDadm'], true)) {
        throw new \InvalidArgumentException("Coluna inválida para notificações: $coluna");
    }
}

function criarNotificacao(PDO $pdo, array $dados): void
{
    $stmt = $pdo->prepare('
        INSERT INTO notificacoes (IDusu, IDadm, tipo, titulo, mensagem, link)
        VALUES (:idusu, :idadm, :tipo, :titulo, :mensagem, :link)
    ');
    $stmt->execute([
        ':idusu'    => $dados['IDusu']    ?? null,
        ':idadm'    => $dados['IDadm']    ?? null,
        ':tipo'     => $dados['tipo']     ?? 'geral',
        ':titulo'   => $dados['titulo']   ?? '',
        ':mensagem' => $dados['mensagem'] ?? '',
        ':link'     => $dados['link']     ?? null,
    ]);
}

function contarNotificacoesNaoLidas(PDO $pdo, string $coluna, int $id): int
{
    _validarColunaNotif($coluna);
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM notificacoes WHERE {$coluna} = :id AND lida = 0");
        $stmt->execute([':id' => $id]);
        return (int) $stmt->fetchColumn();
    } catch (\PDOException $e) {
        error_log('[notificacoes] ' . $e->getMessage());
        return 0;
    }
}

function buscarNotificacoes(PDO $pdo, string $coluna, int $id, int $limite = 20): array
{
    _validarColunaNotif($coluna);
    try {
        $stmt = $pdo->prepare("
            SELECT * FROM notificacoes
            WHERE {$coluna} = :id
            ORDER BY criado_em DESC
            LIMIT {$limite}
        ");
        $stmt->execute([':id' => $id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\PDOException $e) {
        error_log('[notificacoes] ' . $e->getMessage());
        return [];
    }
}

function marcarNotificacoesLidas(PDO $pdo, string $coluna, int $id): void
{
    _validarColunaNotif($coluna);
    try {
        $stmt = $pdo->prepare("UPDATE notificacoes SET lida = 1 WHERE {$coluna} = :id AND lida = 0");
        $stmt->execute([':id' => $id]);
    } catch (\PDOException $e) {
        error_log('[notificacoes] ' . $e->getMessage());
    }
}

// ─── HISTÓRICO DE STATUS ──────────────────────────────────────────────────

function registrarHistoricoStatus(
    PDO $pdo,
    int $idManifest,
    ?int $idAdm,
    string $statusAnterior,
    string $statusNovo,
    ?string $observacao = null
): void {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO historico_status
                (IDmanifest, IDadm, status_anterior, status_novo, observacao)
            VALUES (:idm, :ida, :sant, :snov, :obs)
        ");
        $stmt->execute([
            ':idm'  => $idManifest,
            ':ida'  => $idAdm,
            ':sant' => $statusAnterior,
            ':snov' => $statusNovo,
            ':obs'  => $observacao,
        ]);
    } catch (\PDOException $e) {
        error_log('[historico_status] ' . $e->getMessage());
    }
}

function buscarHistoricoStatus(PDO $pdo, int $idManifest): array
{
    try {
        $stmt = $pdo->prepare("
            SELECT h.*, a.nome AS adm_nome
            FROM historico_status h
            LEFT JOIN tbadm a ON a.IDadm = h.IDadm
            WHERE h.IDmanifest = :id
            ORDER BY h.criado_em ASC
        ");
        $stmt->execute([':id' => $idManifest]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\PDOException $e) {
        error_log('[historico_status] ' . $e->getMessage());
        return [];
    }
}

// ─── TABELAS EXTRAS (executar apenas via schema.sql, não em runtime) ──────

/**
 * Mantido por compatibilidade, mas agora não faz DDL em runtime.
 * Todas as tabelas devem existir após rodar database/schema.sql.
 */
function garantirTabelasExtras(PDO $pdo): void
{
    // DDL removido do runtime — execute database/schema.sql uma única vez.
    // Esta função existe apenas para não quebrar chamadas existentes.
}

// ─── E-MAIL DE RECUPERAÇÃO ────────────────────────────────────────────────

function enviarEmailRecuperacao(string $emailDestino, string $link): bool
{
    try {
        $mail = _criarMailer();
        $mail->addAddress($emailDestino);
        $mail->isHTML(true);
        $mail->Subject = 'Recuperação de senha - Ouvidoria do Grêmio Escolar - ' . SCHOOL_SHORT;

        $linkEsc = e($link);
        $mail->Body = '
            <div style="font-family:Arial,sans-serif;color:#1a1f1a;line-height:1.6;">
                <h2 style="color:#1a6b40;">Recuperação de senha</h2>
                <p>Recebemos uma solicitação para redefinir a senha da sua conta.</p>
                <p>Clique no botão abaixo para continuar:</p>
                <p><a href="' . $linkEsc . '" style="display:inline-block;padding:12px 20px;background:#e8820a;color:#fff;text-decoration:none;border-radius:8px;font-weight:700;">Redefinir senha</a></p>
                <p>Se preferir, copie este link:</p>
                <p><a href="' . $linkEsc . '">' . $linkEsc . '</a></p>
                <p>Este link expira em <strong>1 hora</strong>. Se você não solicitou esta alteração, ignore este e-mail.</p>
            </div>
        ';
        $mail->AltBody = "Recuperação de senha\n\nAcesse o link abaixo (válido por 1 hora):\n" . $link;

        return $mail->send();
    } catch (Exception $e) {
        error_log('[enviarEmailRecuperacao] ' . $e->getMessage());
        return false;
    }
}

// ─── E-MAIL DE CONFIRMAÇÃO DE PROTOCOLO ──────────────────────────────────

function enviarEmailProtocolo(
    string $emailDestino,
    string $nomeDestinatario,
    string $protocolo,
    string $assunto,
    string $tipo,
    string $linkAcompanhar
): bool {
    try {
        $mail = _criarMailer();
        $mail->addAddress($emailDestino);
        $mail->isHTML(true);
        $mail->Subject = 'Manifestação recebida — Protocolo ' . $protocolo;

        $saudacao   = $nomeDestinatario !== 'Anônimo' ? 'Olá, <strong>' . e($nomeDestinatario) . '</strong>!' : 'Olá!';
        $linkEsc    = e($linkAcompanhar);
        $protEsc    = e($protocolo);
        $assuntoEsc = e($assunto);
        $tipoEsc    = e($tipo);

        $mail->Body = '
        <!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"></head>
        <body style="margin:0;padding:0;background:#f4f5f0;font-family:Arial,sans-serif;">
          <table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f0;padding:40px 20px;">
            <tr><td align="center">
              <table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#fff;border-radius:20px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,0.08);">
                <tr><td style="background:linear-gradient(135deg,#0f4228,#1a6b40);padding:36px 40px;text-align:center;">
                  <p style="margin:0 0 8px;color:rgba(255,255,255,0.7);font-size:12px;letter-spacing:2px;text-transform:uppercase;">OUVIDORIA DO GRÊMIO ESCOLAR</p>
                  <h1 style="margin:0;color:#fff;font-size:26px;font-weight:900;">' . e(SCHOOL_SHORT) . '</h1>
                </td></tr>
                <tr><td style="padding:36px 40px 0;text-align:center;">
                  <h2 style="margin:0 0 8px;color:#0f4228;font-size:22px;">Manifestação recebida!</h2>
                  <p style="margin:0;color:#5a6055;font-size:15px;">' . $saudacao . ' Sua manifestação foi registrada com sucesso.</p>
                </td></tr>
                <tr><td style="padding:28px 40px;">
                  <div style="background:#f0fdf4;border:2px solid #bbf7d0;border-radius:16px;padding:24px;text-align:center;">
                    <p style="margin:0 0 6px;color:#047857;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1.5px;">Protocolo de acompanhamento</p>
                    <p style="margin:0;color:#0f4228;font-size:28px;font-weight:900;letter-spacing:2px;">' . $protEsc . '</p>
                  </div>
                </td></tr>
                <tr><td style="padding:0 40px 28px;">
                  <table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;">
                    <tr style="background:#f9fafb;"><td style="padding:12px 16px;font-size:12px;font-weight:700;color:#6b7280;width:40%">Tipo</td><td style="padding:12px 16px;font-size:14px;color:#1a1f1a;">' . $tipoEsc . '</td></tr>
                    <tr><td style="padding:12px 16px;font-size:12px;font-weight:700;color:#6b7280;border-top:1px solid #e5e7eb">Assunto</td><td style="padding:12px 16px;font-size:14px;color:#1a1f1a;border-top:1px solid #e5e7eb">' . $assuntoEsc . '</td></tr>
                    <tr style="background:#f9fafb;"><td style="padding:12px 16px;font-size:12px;font-weight:700;color:#6b7280;border-top:1px solid #e5e7eb">Status</td><td style="padding:12px 16px;border-top:1px solid #e5e7eb"><span style="background:#fff7ed;color:#c2410c;border:1px solid #fed7aa;padding:3px 12px;border-radius:999px;font-size:12px;font-weight:700;">Recebida</span></td></tr>
                  </table>
                </td></tr>
                <tr><td style="padding:0 40px 36px;text-align:center;">
                  <a href="' . $linkEsc . '" style="display:inline-block;padding:14px 32px;background:linear-gradient(135deg,#e8820a,#f5a340);color:#fff;text-decoration:none;border-radius:12px;font-weight:700;font-size:15px;">Acompanhar minha manifestação</a>
                </td></tr>
                <tr><td style="background:#f9fafb;border-top:1px solid #e5e7eb;padding:20px 40px;text-align:center;">
                  <p style="margin:0;color:#9ca3af;font-size:12px;line-height:1.6;">' . e(SCHOOL_NAME) . ' — Ouvidoria do Grêmio Escolar<br><span style="color:#d1d5db;">E-mail gerado automaticamente, não responda.</span></p>
                </td></tr>
              </table>
            </td></tr>
          </table>
        </body></html>';

        $mail->AltBody = "Manifestação recebida!\nProtocolo: {$protocolo}\nTipo: {$tipo}\nAssunto: {$assunto}\n\nAcompanhe: {$linkAcompanhar}";
        return $mail->send();
    } catch (Exception $e) {
        error_log('[enviarEmailProtocolo] ' . $e->getMessage());
        return false;
    }
}

// ─── E-MAIL DE ATUALIZAÇÃO DE STATUS ─────────────────────────────────────

function enviarEmailStatusAtualizado(
    string $emailDestino,
    string $nomeDestinatario,
    string $protocolo,
    string $statusAnterior,
    string $statusNovo,
    string $linkAcompanhar,
    ?string $mensagemAdm = null
): bool {
    try {
        $mail = _criarMailer();
        $mail->addAddress($emailDestino);
        $mail->isHTML(true);
        $mail->Subject = 'Status atualizado — Protocolo ' . $protocolo;

        $icone    = $statusNovo === 'Resolvida' ? '✅' : ($statusNovo === 'Em andamento' ? '🔄' : '📬');
        $saudacao = $nomeDestinatario !== 'Anônimo' ? 'Olá, <strong>' . e($nomeDestinatario) . '</strong>!' : 'Olá!';
        $linkEsc  = e($linkAcompanhar);
        $protEsc  = e($protocolo);
        $stNovo   = e($statusNovo);
        $stAnt    = e($statusAnterior);

        $blocoMensagem = '';
        if ($mensagemAdm) {
            $msgEsc = nl2br(e($mensagemAdm));
            $blocoMensagem = '<tr><td style="padding:0 40px 28px;"><div style="background:#f0fdf4;border-left:4px solid #1a6b40;padding:18px 20px;"><p style="margin:0 0 6px;font-size:11px;font-weight:700;color:#047857;">MENSAGEM DO GRÊMIO</p><p style="margin:0;font-size:14px;color:#1a1f1a;line-height:1.7;">' . $msgEsc . '</p></div></td></tr>';
        }

        $mail->Body = '
        <!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"></head>
        <body style="margin:0;padding:0;background:#f4f5f0;font-family:Arial,sans-serif;">
          <table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f0;padding:40px 20px;">
            <tr><td align="center">
              <table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#fff;border-radius:20px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,0.08);">
                <tr><td style="background:linear-gradient(135deg,#0f4228,#1a6b40);padding:36px 40px;text-align:center;">
                  <h1 style="margin:0;color:#fff;font-size:26px;font-weight:900;">' . e(SCHOOL_SHORT) . '</h1>
                </td></tr>
                <tr><td style="padding:36px 40px 20px;text-align:center;">
                  <div style="font-size:48px;margin-bottom:16px;">' . $icone . '</div>
                  <h2 style="margin:0 0 8px;color:#0f4228;">Status atualizado</h2>
                  <p style="margin:0;color:#5a6055;">' . $saudacao . ' Sua manifestação foi atualizada.</p>
                </td></tr>
                <tr><td style="padding:0 40px 24px;">
                  <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:12px;padding:20px;text-align:center;">
                    <p style="margin:0 0 4px;color:#6b7280;font-size:12px;">Protocolo</p>
                    <p style="margin:0 0 16px;color:#0f4228;font-size:22px;font-weight:900;">' . $protEsc . '</p>
                    <span style="background:#f3f4f6;color:#6b7280;border:1px solid #e5e7eb;padding:5px 14px;border-radius:999px;font-size:13px;">' . $stAnt . '</span>
                    <span style="color:#9ca3af;font-size:18px;margin:0 8px;">→</span>
                    <span style="padding:5px 14px;border-radius:999px;font-size:13px;font-weight:700;background:#ecfdf5;color:#047857;">' . $stNovo . '</span>
                  </div>
                </td></tr>
                ' . $blocoMensagem . '
                <tr><td style="padding:0 40px 36px;text-align:center;">
                  <a href="' . $linkEsc . '" style="display:inline-block;padding:14px 32px;background:linear-gradient(135deg,#e8820a,#f5a340);color:#fff;text-decoration:none;border-radius:12px;font-weight:700;font-size:15px;">Ver minha manifestação</a>
                </td></tr>
                <tr><td style="background:#f9fafb;border-top:1px solid #e5e7eb;padding:20px 40px;text-align:center;">
                  <p style="margin:0;color:#9ca3af;font-size:12px;">' . e(SCHOOL_NAME) . ' — Ouvidoria do Grêmio Escolar</p>
                </td></tr>
              </table>
            </td></tr>
          </table>
        </body></html>';

        $mail->AltBody = "Status atualizado!\nProtocolo: {$protocolo}\nDe: {$statusAnterior} → Para: {$statusNovo}\n" .
            ($mensagemAdm ? "\nMensagem: {$mensagemAdm}\n" : '') . "\nVer: {$linkAcompanhar}";

        return $mail->send();
    } catch (Exception $e) {
        error_log('[enviarEmailStatusAtualizado] ' . $e->getMessage());
        return false;
    }
}

// ─── HELPER INTERNO: MAILER ───────────────────────────────────────────────

/**
 * Cria e configura uma instância PHPMailer com as credenciais do .env.
 */
function _criarMailer(): PHPMailer
{
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = MAIL_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = MAIL_USERNAME;
    $mail->Password   = MAIL_PASSWORD;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = MAIL_PORT;
    $mail->CharSet    = 'UTF-8';
    $mail->setFrom(MAIL_FROM_ADDRESS, MAIL_FROM_NAME);
    return $mail;
}
