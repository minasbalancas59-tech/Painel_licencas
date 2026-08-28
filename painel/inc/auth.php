<?php
/**
 * Autenticacao e sessao do painel.
 * Inclua no topo de toda pagina protegida:  require 'inc/auth.php';
 */
require_once __DIR__ . '/../../api/lib/licenca.php';

// --- faz o PHP respeitar o tempo de sessao definido em config.php ----
// Sem isto, o PHP encerra a sessao no seu proprio limite padrao
// (session.gc_maxlifetime, ~24 min), antes do SESSAO_TIMEOUT.
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.gc_maxlifetime', (string)SESSAO_TIMEOUT);
}

session_start();

// timeout por inatividade
if (isset($_SESSION['ultimo_acesso']) &&
    (time() - $_SESSION['ultimo_acesso'] > SESSAO_TIMEOUT)) {
    session_unset();
    session_destroy();
    header('Location: login.php?timeout=1');
    exit;
}
$_SESSION['ultimo_acesso'] = time();

function usuario_logado(): ?array {
    return $_SESSION['usuario'] ?? null;
}

function exige_login(): void {
    if (!usuario_logado()) {
        header('Location: login.php');
        exit;
    }
}

function exige_admin(): void {
    exige_login();
    if (($_SESSION['usuario']['papel'] ?? '') !== 'admin') {
        http_response_code(403);
        exit('Acesso restrito a administradores.');
    }
}

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_valido(): bool {
    return isset($_POST['csrf']) && hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf']);
}

function e(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
