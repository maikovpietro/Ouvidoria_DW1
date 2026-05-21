<?php
require_once __DIR__ . '/../../config/bootstrap.php';
session_regenerate_id(true);
unset($_SESSION['admin'], $_SESSION['usuario'], $_SESSION['csrf_token']);
session_destroy();
clearRememberMeCookies();
flash('sucesso', 'Sessão encerrada com sucesso.');
header('Location: ' . BASE_URL . 'app/auth/login.php');
exit;
