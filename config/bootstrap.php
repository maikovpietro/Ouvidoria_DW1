<?php
/**
 * Bootstrap do sistema.
 * Todo arquivo PHP deve incluir apenas este arquivo.
 * Seguro para múltiplos includes — cada sub-arquivo tem seu próprio guard.
 */

if (defined('_APP_BOOTSTRAP_LOADED')) return;
define('_APP_BOOTSTRAP_LOADED', true);

// Output buffering: garante que warnings/notices do PHP não quebrem
// os headers HTTP (sessão, cookies, redirects) nem o CSS da página.
if (!ob_get_level()) {
    ob_start();
}

require_once __DIR__ . '/paths.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
