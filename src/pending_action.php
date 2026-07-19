<?php

function app_capture_pending_login_action(): void
{
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $script = basename((string)($_SERVER['PHP_SELF'] ?? ''));
    if ($script === '' || in_array($script, ['login.php', 'login_process.php', 'resume_action.php'], true)) {
        return;
    }
    if ($method === 'GET') {
        $_SESSION['pending_login_action'] = [
            'method' => 'GET',
            'target' => $script . (!empty($_GET) ? '?' . http_build_query($_GET) : ''),
            'created_at' => time(),
        ];
    } elseif ($method === 'POST' && $script === 'basket_add.php' && strlen(serialize($_POST)) <= 65536) {
        $_SESSION['pending_login_action'] = [
            'method' => 'POST', 'target' => $script, 'payload' => $_POST, 'created_at' => time(),
        ];
    }
}

function app_pending_login_redirect(): string
{
    $pending = $_SESSION['pending_login_action'] ?? null;
    if (!is_array($pending) || time() - (int)($pending['created_at'] ?? 0) > 1800) {
        unset($_SESSION['pending_login_action']);
        return 'index.php';
    }
    if (($pending['method'] ?? '') === 'POST') {
        return 'resume_action.php';
    }
    $target = (string)($pending['target'] ?? '');
    unset($_SESSION['pending_login_action']);
    return $target !== '' && !preg_match('~^(?:[a-z][a-z0-9+.-]*:|//|/)~i', $target) ? $target : 'index.php';
}
