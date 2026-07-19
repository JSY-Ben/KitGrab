<?php
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/layout.php';
require_once SRC_PATH . '/password_reset.php';

session_start();
$config = load_config();
$token = trim((string)($_POST['token'] ?? $_GET['token'] ?? ''));
$reset = password_reset_find_valid($pdo, $token);
$error = '';
$complete = false;

if (empty($_SESSION['password_reset_form_csrf'])) {
    $_SESSION['password_reset_form_csrf'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $reset) {
    $csrf = (string)($_POST['csrf_token'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    $confirmation = (string)($_POST['password_confirmation'] ?? '');
    if (!hash_equals((string)$_SESSION['password_reset_form_csrf'], $csrf)) {
        $error = 'Your session expired. Please refresh the reset link and try again.';
    } elseif (strlen($password) < 12) {
        $error = 'Choose a password containing at least 12 characters.';
    } elseif ($password !== $confirmation) {
        $error = 'The passwords do not match.';
    } else {
        try {
            $complete = password_reset_complete($pdo, (int)$reset['reset_id'], (int)$reset['user_id'], password_hash($password, PASSWORD_DEFAULT));
            if (!$complete) {
                $error = 'This reset link is no longer valid. Please request a new one.';
            } else {
                unset($_SESSION['password_reset_form_csrf']);
            }
        } catch (Throwable $e) {
            error_log(layout_app_name($config) . ' password reset failed: ' . $e->getMessage());
            $error = 'We could not update your password. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reset password – <?= layout_html_escape(layout_app_name($config)) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/style.css">
    <?= layout_theme_styles($config) ?>
</head>
<body class="p-4">
<div class="container">
    <div class="page-shell auth-shell">
        <?= layout_logo_tag($config) ?>
        <div class="page-header">
            <h1>Reset password</h1>
            <div class="page-subtitle">Choose a new password for your local account.</div>
        </div>
        <?php if ($complete): ?>
            <div class="alert alert-success">Your password has been updated. You can now sign in.</div>
            <a href="login.php" class="btn btn-primary w-100">Sign in</a>
        <?php elseif (!$reset): ?>
            <div class="alert alert-danger">This password reset link is invalid or has expired.</div>
            <a href="forgot_password.php" class="btn btn-primary w-100">Request a new link</a>
        <?php else: ?>
            <?php if ($error !== ''): ?><div class="alert alert-danger"><?= layout_html_escape($error) ?></div><?php endif; ?>
            <form method="post" class="card p-3 mt-3">
                <input type="hidden" name="token" value="<?= layout_html_escape($token) ?>">
                <input type="hidden" name="csrf_token" value="<?= layout_html_escape($_SESSION['password_reset_form_csrf']) ?>">
                <div class="mb-3">
                    <label for="new_password" class="form-label">New password</label>
                    <input type="password" class="form-control" id="new_password" name="password" autocomplete="new-password" minlength="12" required autofocus>
                    <div class="form-text">Use at least 12 characters.</div>
                </div>
                <div class="mb-3">
                    <label for="password_confirmation" class="form-label">Confirm new password</label>
                    <input type="password" class="form-control" id="password_confirmation" name="password_confirmation" autocomplete="new-password" minlength="12" required>
                </div>
                <button type="submit" class="btn btn-primary w-100">Reset password</button>
            </form>
        <?php endif; ?>
    </div>
</div>
<?php layout_footer(); ?>
</body>
</html>
