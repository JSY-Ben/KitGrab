<?php
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/layout.php';

session_start();
$config = load_config();
$authConfig = $config['auth'] ?? [];
$registrationEnabled = !empty($authConfig['registration_enabled']);
$requiresApproval = !empty($authConfig['registration_requires_approval']);
$errors = [];
$registered = false;

if (!$registrationEnabled) {
    http_response_code(404);
}

if (empty($_SESSION['registration_csrf'])) {
    $_SESSION['registration_csrf'] = bin2hex(random_bytes(32));
}

if ($registrationEnabled && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = (string)($_POST['csrf_token'] ?? '');
    $firstName = trim((string)($_POST['first_name'] ?? ''));
    $lastName = trim((string)($_POST['last_name'] ?? ''));
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $confirmation = (string)($_POST['password_confirmation'] ?? '');

    if (!hash_equals((string)$_SESSION['registration_csrf'], $csrf)) {
        $errors[] = 'Your session expired. Please refresh the page and try again.';
    }
    if ($firstName === '') {
        $errors[] = 'First name is required.';
    }
    if ($lastName === '') {
        $errors[] = 'Last name is required.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Enter a valid email address.';
    }
    if ($username === '' || !preg_match('/^[A-Za-z0-9._-]{3,64}$/', $username)) {
        $errors[] = 'Username must be 3–64 characters and use only letters, numbers, dots, underscores, or hyphens.';
    }
    if (strlen($password) < 12) {
        $errors[] = 'Password must contain at least 12 characters.';
    } elseif ($password !== $confirmation) {
        $errors[] = 'The passwords do not match.';
    }

    if (empty($errors)) {
        try {
            $duplicate = $pdo->prepare('SELECT id FROM users WHERE LOWER(email) = :email OR LOWER(username) = :username LIMIT 1');
            $duplicate->execute([':email' => $email, ':username' => strtolower($username)]);
            if ($duplicate->fetch()) {
                throw new RuntimeException('An account already uses that email address or username.');
            }

            $stmt = $pdo->prepare("
                INSERT INTO users
                    (user_id, first_name, last_name, email, username, is_admin, is_staff, password_hash, auth_source, is_approved, created_at)
                VALUES
                    (:user_id, :first_name, :last_name, :email, :username, 0, 0, :password_hash, 'local', :is_approved, NOW())
            ");
            $stmt->execute([
                ':user_id' => sprintf('%u', crc32($email)),
                ':first_name' => $firstName,
                ':last_name' => $lastName,
                ':email' => $email,
                ':username' => $username,
                ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
                ':is_approved' => $requiresApproval ? 0 : 1,
            ]);
            $registered = true;
            unset($_SESSION['registration_csrf']);
        } catch (Throwable $e) {
            if ($e instanceof PDOException && (string)$e->getCode() === '23000') {
                $errors[] = 'An account already uses that email address or username.';
            } elseif ($e instanceof RuntimeException && $e->getMessage() === 'An account already uses that email address or username.') {
                $errors[] = $e->getMessage();
            } else {
                error_log(layout_app_name($config) . ' registration failed: ' . $e->getMessage());
                $errors[] = 'We could not create your account. Please try again.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Register – <?= layout_html_escape(layout_app_name($config)) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/style.css">
    <?= layout_theme_styles($config) ?>
</head>
<body class="p-4">
<div class="container">
    <div class="page-shell auth-shell">
        <?= layout_logo_tag($config) ?>
        <div class="page-header">
            <h1>Create an account</h1>
            <div class="page-subtitle">Register for a local <?= layout_html_escape(layout_app_name($config)) ?> account.</div>
        </div>

        <?php if (!$registrationEnabled): ?>
            <div class="alert alert-info">User registration is not currently available.</div>
            <a href="login.php" class="btn btn-primary w-100">Back to sign in</a>
        <?php elseif ($registered): ?>
            <div class="alert alert-success">
                <?= $requiresApproval
                    ? 'Your account has been created and is waiting for administrator approval.'
                    : 'Your account has been created. You can now sign in.' ?>
            </div>
            <a href="login.php" class="btn btn-primary w-100">Back to sign in</a>
        <?php else: ?>
            <?php if ($errors): ?>
                <div class="alert alert-danger"><ul class="mb-0">
                    <?php foreach ($errors as $error): ?><li><?= layout_html_escape($error) ?></li><?php endforeach; ?>
                </ul></div>
            <?php endif; ?>
            <form method="post" class="card p-3 mt-3">
                <input type="hidden" name="csrf_token" value="<?= layout_html_escape($_SESSION['registration_csrf']) ?>">
                <div class="row g-3">
                    <div class="col-sm-6">
                        <label for="first_name" class="form-label">First name</label>
                        <input type="text" class="form-control" id="first_name" name="first_name" value="<?= layout_html_escape((string)($_POST['first_name'] ?? '')) ?>" autocomplete="given-name" required autofocus>
                    </div>
                    <div class="col-sm-6">
                        <label for="last_name" class="form-label">Last name</label>
                        <input type="text" class="form-control" id="last_name" name="last_name" value="<?= layout_html_escape((string)($_POST['last_name'] ?? '')) ?>" autocomplete="family-name" required>
                    </div>
                    <div class="col-12">
                        <label for="register_email" class="form-label">Email address</label>
                        <input type="email" class="form-control" id="register_email" name="email" value="<?= layout_html_escape((string)($_POST['email'] ?? '')) ?>" autocomplete="email" required>
                    </div>
                    <div class="col-12">
                        <label for="register_username" class="form-label">Username</label>
                        <input type="text" class="form-control" id="register_username" name="username" value="<?= layout_html_escape((string)($_POST['username'] ?? '')) ?>" autocomplete="username" minlength="3" maxlength="64" pattern="[A-Za-z0-9._-]+" required>
                    </div>
                    <div class="col-12">
                        <label for="register_password" class="form-label">Password</label>
                        <input type="password" class="form-control" id="register_password" name="password" autocomplete="new-password" minlength="12" required>
                        <div class="form-text">Use at least 12 characters.</div>
                    </div>
                    <div class="col-12">
                        <label for="register_password_confirmation" class="form-label">Confirm password</label>
                        <input type="password" class="form-control" id="register_password_confirmation" name="password_confirmation" autocomplete="new-password" minlength="12" required>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary w-100 mt-3">Create account</button>
            </form>
            <a href="login.php" class="btn btn-link w-100 mt-2">Already have an account? Sign in</a>
        <?php endif; ?>
    </div>
</div>
<?php layout_footer(); ?>
</body>
</html>
