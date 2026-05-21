<?php
// ── SEGURANÇA: nunca deixe este arquivo acessível em produção ─────────────
// Defina DEV_MODE=true no .env apenas em ambiente local/dev.
// Em produção a constante não existirá e o script encerra imediatamente.
require_once __DIR__ . '/config/bootstrap.php';
if (!defined('DEV_MODE') || DEV_MODE !== true) {
    http_response_code(404);
    exit('Not found.');
}
echo '<pre>';
echo 'BASE_URL: ' . BASE_URL . "\n";
echo 'APP_URL: ' . APP_URL . "\n";
echo 'CSS URL: ' . BASE_URL . 'assets/css/style.css' . "\n";
echo 'CSS exists: ' . (file_exists(__DIR__ . '/assets/css/style.css') ? 'SIM' : 'NÃO') . "\n";
echo 'SCRIPT_NAME: ' . ($_SERVER['SCRIPT_NAME'] ?? '') . "\n";
echo 'HTTP_HOST: ' . ($_SERVER['HTTP_HOST'] ?? '') . "\n";
echo '</pre>';
echo '<link rel="stylesheet" href="' . BASE_URL . 'assets/css/style.css">';
echo '<p style="font-size:2rem">Se esse texto estiver verde, o CSS carregou ✅</p>';
