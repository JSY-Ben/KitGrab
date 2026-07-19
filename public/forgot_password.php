<?php
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/email.php';
require_once SRC_PATH . '/layout.php';
require_once SRC_PATH . '/password_reset.php';

session_start();
$config = load_config();
$submitted = false;
$error = '';

if (empty($_SESSION['password_reset_csrf'])) {
    $_SESSION['password_reset_csrf'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals((string)$_SESSION['password_reset_csrf'], $csrf)) {
        $error = 'Your session expired. Please refresh the page and try again.';
    } else {
        $submitted = true;
        $email = password_reset_normalize_email((string)($_POST['email'] ?? ''));
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            try {
                $stmt = $pdo->prepare("
                    SELECT id, email, first_name, last_name
                      FROM users
                     WHERE LOWER(email) = :email
                       AND (auth_source = 'local' OR auth_source IS NULL OR auth_source = '')
                     LIMIT 1
                ");
                $stmt->execute([':email' => $email]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($user && !password_reset_request_is_throttled($pdo, (int)$user['id'])) {
                    $token = password_reset_create($pdo, (int)$user['id']);
                    $resetUrl = layout_app_page_url('reset_password.php', $config, ['token' => $token]);
                    if ($resetUrl === '') {
                        throw new RuntimeException('The application base URL could not be resolved.');
                    }
                    $name = trim((string)$user['first_name'] . ' ' . (string)$user['last_name']);
                    $name = $name !== '' ? $name : (string)$user['email'];
                    $appName = layout_app_name($config);
                    $plain = "Hello {$name},\n\nUse the link below to reset your {$appName} password:\n{$resetUrl}\n\nThis link expires in one hour and can only be used once. If you did not request this, you can ignore this email.";
                    $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
                    $safeUrl = htmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8');
                    $html = '<p>Hello ' . $safeName . ',</p><p>Use the button below to reset your ' . htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') . ' password.</p>'
                        . '<p><a href="' . $safeUrl . '" style="display:inline-block;padding:12px 18px;background:#660000;color:#fff;text-decoration:none;border-radius:6px">Reset password</a></p>'
                        . '<p>This link expires in one hour and can only be used once. If you did not request this, you can ignore this email.</p>';
                    if (!layout_send_mail((string)$user['email'], $name, 'Reset your ' . $appName . ' password', $plain, $config, $html)) {
                        error_log($appName . ' could not send a password reset email to user ID ' . (int)$user['id']);
                    }
                }
            } catch (Throwable $e) {
                error_log(layout_app_name($config) . ' password reset request failed: ' . $e->getMessage());
            }
        }
        $_SESSION['password_reset_csrf'] = bin2hex(random_bytes(32));
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Forgot password – <?= layout_html_escape(layout_app_name($config)) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/style.css">
    <?= layout_theme_styles($config) ?>
</head>
<body class="p-4">
<div class="container">
    <div class="page-shell auth-shell">
        <?= layout_logo_tag($config) ?>
        <div class="page-header">
            <h1>Forgot your password?</h1>
            <div class="page-subtitle">Enter the email address for your local account.</div>
        </div>
        <?php if ($submitted && $error === ''): ?>
            <div class="alert alert-success">If a local account exists for that email, a password reset link has been sent.</div>
            <a href="login.php" class="btn btn-primary w-100">Back to sign in</a>
        <?php else: ?>
            <?php if ($error !== ''): ?><div class="alert alert-danger"><?= layout_html_escape($error) ?></div><?php endif; ?>
            <form method="post" class="card p-3 mt-3">
                <input type="hidden" name="csrf_token" value="<?= layout_html_escape($_SESSION['password_reset_csrf']) ?>">
                <div class="mb-3">
                    <label for="reset_email" class="form-label">Email address</label>
                    <input type="email" class="form-control" id="reset_email" name="email" autocomplete="email" required autofocus>
                </div>
                <button type="submit" class="btn btn-primary w-100">Email reset link</button>
            </form>
            <a href="login.php" class="btn btn-link w-100 mt-2">Back to sign in</a>
        <?php endif; ?>
    </div>
</div>
<?php layout_footer(); ?>
</body>
</html>
