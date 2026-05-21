<?php
/**
 * Configurações do sistema — banco de dados, e-mail, sessão.
 * Todas as credenciais são lidas do arquivo .env (nunca hardcoded aqui).
 * Seguro para múltiplos includes (todas as constantes usam defined()).
 */

if (defined('_APP_CONFIG_LOADED')) return;
define('_APP_CONFIG_LOADED', true);

// ── Carrega variáveis do .env ─────────────────────────────────────────────
$_envFile = dirname(__DIR__) . '/.env';
if (file_exists($_envFile)) {
    $lines = file($_envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $_line) {
        $_line = trim($_line);
        if ($_line === '' || $_line[0] === '#' || strpos($_line, '=') === false) continue;
        $parts = explode('=', $_line, 2);
        if (count($parts) === 2) {
            $k = trim($parts[0]);
            $v = trim($parts[1]);
            if (strlen($v) >= 2 && $v[0] === '"'  && substr($v,-1) === '"')  $v = substr($v,1,-1);
            if (strlen($v) >= 2 && $v[0] === "'"  && substr($v,-1) === "'")  $v = substr($v,1,-1);
            if (!isset($_ENV[$k])) $_ENV[$k] = $v;
        }
    }
}
unset($_envFile, $lines, $_line, $parts, $k, $v);

// ── Identidade da escola (personalizável via .env) ────────────────────────
define('SCHOOL_NAME',  $_ENV['SCHOOL_NAME']  ?? 'EEEP Dom Walfrido Teixeira Vieira');
define('SCHOOL_SHORT', $_ENV['SCHOOL_SHORT'] ?? 'Dom Walfrido');

// ── Modo de desenvolvimento (NUNCA defina como true em produção) ──────────
define('DEV_MODE', filter_var($_ENV['DEV_MODE'] ?? false, FILTER_VALIDATE_BOOLEAN));

// ── Banco de dados ────────────────────────────────────────────────────────
define('DB_HOST', $_ENV['DB_HOST'] ?? 'localhost');
define('DB_NAME', $_ENV['DB_NAME'] ?? 'dbouvidoria');
define('DB_USER', $_ENV['DB_USER'] ?? 'root');
define('DB_PASS', $_ENV['DB_PASS'] ?? '');

// ── URL base — detecção automática robusta ────────────────────────────────
// Funciona em qualquer nome de pasta, qualquer profundidade, XAMPP/WAMP/Linux.
// Prioridade: 1) .env  2) auto-detect pelo ROOT_PATH vs DOCUMENT_ROOT
if (!empty($_ENV['BASE_URL'])) {
    // Usuário definiu manualmente — usa direto (garante barra final)
    define('BASE_URL', rtrim($_ENV['BASE_URL'], '/') . '/');
} else {
    // Auto-detecta comparando o caminho absoluto da raiz do projeto
    // com o document root do servidor web.
    $docRoot    = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
    $rootPath   = rtrim(str_replace('\\', '/', dirname(__DIR__)), '/');

    if ($docRoot !== '' && strpos($rootPath, $docRoot) === 0) {
        // Caminho relativo ao document root
        $relative = substr($rootPath, strlen($docRoot));
        $relative = str_replace('\\', '/', $relative);
        $relative = trim($relative, '/');
        define('BASE_URL', $relative === '' ? '/' : '/' . $relative . '/');
    } else {
        // Fallback: deriva pelo SCRIPT_NAME (comportamento anterior, mais tolerante)
        $script   = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
        $rootDir  = basename(dirname(__DIR__));
        $pos      = strpos($script, '/' . $rootDir . '/');
        if ($pos !== false) {
            define('BASE_URL', substr($script, 0, $pos) . '/' . $rootDir . '/');
        } else {
            define('BASE_URL', '/' . $rootDir . '/');
        }
    }
}

define('APP_URL', rtrim(
    $_ENV['APP_URL'] ?? ('http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(BASE_URL, '/')),
    '/'
));

// ── Configurações de e-mail (SMTP) ────────────────────────────────────────
define('MAIL_HOST',         $_ENV['MAIL_HOST']         ?? 'smtp.gmail.com');
define('MAIL_PORT',         (int)($_ENV['MAIL_PORT']   ?? 587));
define('MAIL_USERNAME',     $_ENV['MAIL_USERNAME']     ?? '');
define('MAIL_PASSWORD',     $_ENV['MAIL_PASSWORD']     ?? '');
define('MAIL_FROM_ADDRESS', $_ENV['MAIL_FROM_ADDRESS'] ?? '');
define('MAIL_FROM_NAME',    $_ENV['MAIL_FROM_NAME']    ?? 'Ouvidoria do Grêmio Escolar');

// ── Sessão segura (apenas se ainda não iniciada) ──────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
               || (int)($_SERVER['SERVER_PORT'] ?? 80) === 443;
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

// ── Conexão PDO (singleton) ───────────────────────────────────────────────
if (!function_exists('conectarPDO')) {
    function conectarPDO(): PDO {
        static $pdo = null;
        if ($pdo instanceof PDO) return $pdo;
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        return $pdo;
    }
}
