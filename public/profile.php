<?php
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/layout.php';
require_once SRC_PATH . '/user_profile.php';
require_once SRC_PATH . '/activity_log.php';

$config = load_config();
$authConfig = $config['auth'] ?? [];
$canEdit = !empty($authConfig['users_can_edit_profile']);
$photosEnabled = !empty($authConfig['user_photos_enabled']);
$isLocal = strtolower((string)($currentUser['auth_source'] ?? 'local')) === 'local';
if (!$canEdit || !$isLocal) {
    http_response_code(403);
    echo 'Profile editing is not available for this account.';
    exit;
}

$errors = [];
$messages = [];
if (empty($_SESSION['profile_csrf'])) $_SESSION['profile_csrf'] = bin2hex(random_bytes(32));

$stmt = $pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
$stmt->execute([':id' => (int)$currentUser['id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) { http_response_code(404); exit('User not found.'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $firstName = trim((string)($_POST['first_name'] ?? ''));
    $lastName = trim((string)($_POST['last_name'] ?? ''));
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $confirmation = (string)($_POST['password_confirmation'] ?? '');
    if (!hash_equals((string)$_SESSION['profile_csrf'], (string)($_POST['csrf_token'] ?? ''))) $errors[] = 'Your session expired. Please try again.';
    if ($firstName === '' || $lastName === '') $errors[] = 'First and last name are required.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid email address.';
    if (!preg_match('/^[A-Za-z0-9._-]{3,64}$/', $username)) $errors[] = 'Username must be 3–64 characters and use only letters, numbers, dots, underscores, or hyphens.';
    if ($password !== '' && strlen($password) < 12) $errors[] = 'A new password must contain at least 12 characters.';
    if ($password !== $confirmation) $errors[] = 'The passwords do not match.';

    if (!$errors) {
        try {
            $duplicate = $pdo->prepare('SELECT id FROM users WHERE (LOWER(email) = :email OR LOWER(username) = :username) AND id <> :id LIMIT 1');
            $duplicate->execute([':email' => $email, ':username' => strtolower($username), ':id' => (int)$user['id']]);
            if ($duplicate->fetch()) throw new RuntimeException('That email address or username is already in use.');
            $photoPath = null;
            if ($photosEnabled && isset($_FILES['profile_photo']) && is_array($_FILES['profile_photo'])) {
                $photoPath = user_profile_photo_upload($_FILES['profile_photo'], (int)$user['id'], $errors);
            }
            if (!$errors) {
                $sql = 'UPDATE users SET first_name=:first_name,last_name=:last_name,email=:email,username=:username';
                $params = [':first_name'=>$firstName,':last_name'=>$lastName,':email'=>$email,':username'=>$username,':id'=>(int)$user['id']];
                if ($password !== '') { $sql .= ',password_hash=:password_hash'; $params[':password_hash'] = password_hash($password, PASSWORD_DEFAULT); }
                if ($photoPath !== null) { $sql .= ',profile_photo_url=:photo'; $params[':photo'] = $photoPath; }
                $sql .= ' WHERE id=:id';
                $pdo->prepare($sql)->execute($params);
                $_SESSION['user']['first_name']=$firstName; $_SESSION['user']['last_name']=$lastName; $_SESSION['user']['email']=$email; $_SESSION['user']['username']=$username; $_SESSION['user']['display_name']=trim($firstName . ' ' . $lastName);
                if ($photoPath !== null) $_SESSION['user']['profile_photo_url']=$photoPath;
                $currentUser = $_SESSION['user'];
                activity_log_event('user_profile_updated', 'User updated their profile', ['subject_type'=>'user','subject_id'=>(int)$user['id']]);
                $messages[] = 'Your profile has been updated.';
                $stmt->execute([':id'=>(int)$user['id']]); $user=$stmt->fetch(PDO::FETCH_ASSOC);
            }
        } catch (Throwable $e) { $errors[] = $e->getMessage(); }
    }
}
?>
<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Edit profile – <?= layout_html_escape(layout_app_name($config)) ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css"><link rel="stylesheet" href="assets/style.css"><?= layout_theme_styles($config) ?></head>
<body class="p-4"><div class="container"><div class="page-shell auth-shell"><?= layout_logo_tag($config) ?>
<div class="page-header"><h1>Edit user profile</h1><div class="page-subtitle">Update your local account details.</div></div>
<?php foreach($messages as $message): ?><div class="alert alert-success"><?= layout_html_escape($message) ?></div><?php endforeach; ?>
<?php if($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach($errors as $error): ?><li><?= layout_html_escape($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<form method="post" enctype="multipart/form-data" class="card p-3"><input type="hidden" name="csrf_token" value="<?= layout_html_escape($_SESSION['profile_csrf']) ?>">
<div class="row g-3"><div class="col-sm-6"><label class="form-label">First name</label><input class="form-control" name="first_name" value="<?= layout_html_escape($user['first_name'] ?? '') ?>" required></div>
<div class="col-sm-6"><label class="form-label">Last name</label><input class="form-control" name="last_name" value="<?= layout_html_escape($user['last_name'] ?? '') ?>" required></div>
<div class="col-12"><label class="form-label">Email address</label><input type="email" class="form-control" name="email" value="<?= layout_html_escape($user['email'] ?? '') ?>" required></div>
<div class="col-12"><label class="form-label">Username</label><input class="form-control" name="username" value="<?= layout_html_escape($user['username'] ?? '') ?>" minlength="3" maxlength="64" pattern="[A-Za-z0-9._-]+" required></div>
<?php if($photosEnabled): ?><div class="col-12"><label class="form-label">Profile photo</label><div class="mb-2"><?= layout_user_identity($user, false, $config) ?></div><input type="file" class="form-control" name="profile_photo" accept="image/jpeg,image/png,image/gif,image/webp"><div class="form-text">Leave blank to keep your current photo. Maximum 4 MB.</div></div><?php endif; ?>
<div class="col-12"><label class="form-label">New password <span class="text-muted">(optional)</span></label><input type="password" class="form-control" name="password" minlength="12" autocomplete="new-password"></div>
<div class="col-12"><label class="form-label">Confirm new password</label><input type="password" class="form-control" name="password_confirmation" minlength="12" autocomplete="new-password"></div></div>
<button class="btn btn-primary w-100 mt-3" type="submit">Save profile</button></form><a href="index.php" class="btn btn-link w-100 mt-2">Back to dashboard</a>
</div></div><?= layout_footer() ?></body></html>
