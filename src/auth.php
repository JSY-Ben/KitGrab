<?php
require_once __DIR__ . '/bootstrap.php';

// auth.php
// Simple authentication guard used by all protected pages.

session_start();

$script = basename($_SERVER['PHP_SELF']);
$scriptPath = trim(str_replace('\\', '/', (string)($_SERVER['PHP_SELF'] ?? '')), '/');
$loginPath = defined('AUTH_LOGIN_PATH') ? AUTH_LOGIN_PATH : 'login.php';
$loginProcessPath = defined('AUTH_LOGIN_PROCESS_PATH') ? AUTH_LOGIN_PROCESS_PATH : 'login_process.php';
$isAuthenticated = !empty($_SESSION['user']);
$isPublicGuestAccess = false;

$config = [];
try {
    $config = load_config();
} catch (Throwable $e) {
    $config = [];
}

$catalogueCfg = $config['catalogue'] ?? [];
$allowPublicCatalogueView = array_key_exists('allow_public_view', $catalogueCfg)
    ? !empty($catalogueCfg['allow_public_view'])
    : false;
$isRootAppScript = (bool)preg_match('#^(index|catalogue)\.php$#', $scriptPath)
    || (bool)preg_match('#(?:^|/)public/(index|catalogue)\.php$#', $scriptPath);
$isGuestAllowedPage = $allowPublicCatalogueView
    && $isRootAppScript
    && in_array($script, ['index.php', 'catalogue.php'], true);

// If no logged-in user, redirect to login.php (except allowed guest pages and login pages).
if (!$isAuthenticated) {
    if (
        !$isGuestAllowedPage
        && !in_array($script, [basename($loginPath), basename($loginProcessPath)], true)
    ) {
        header('Location: ' . $loginPath);
        exit;
    }

    if ($isGuestAllowedPage) {
        $isPublicGuestAccess = true;
    }

    $currentUser = [
        'id' => 0,
        'email' => '',
        'username' => '',
        'display_name' => 'Guest',
        'first_name' => 'Guest',
        'last_name' => '',
        'is_staff' => false,
        'is_admin' => false,
    ];
} else {
    // User is logged in – expose as $currentUser for the including script
    $currentUser = $_SESSION['user'];

    // Refresh role flags from the local users table when available.
    if (!empty($currentUser['email'])) {
        try {
            require_once SRC_PATH . '/db.php';
            $stmt = $pdo->prepare('SELECT is_admin, is_staff FROM users WHERE email = :email LIMIT 1');
            $stmt->execute([':email' => strtolower(trim($currentUser['email']))]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $currentUser['is_admin'] = !empty($row['is_admin']);
                $currentUser['is_staff'] = !empty($row['is_staff']) || $currentUser['is_admin'];
                $_SESSION['user']['is_admin'] = $currentUser['is_admin'];
                $_SESSION['user']['is_staff'] = $currentUser['is_staff'];
            }
        } catch (Throwable $e) {
            // Ignore role refresh failures to avoid blocking access.
        }
    }
}

// Global HTML output helper:
//  - Decodes any existing entities (e.g. &quot;) so they show as "
//  - Then safely escapes once for HTML output.
if (!function_exists('h')) {
    function h(?string $value): string
    {
        return htmlspecialchars(
            htmlspecialchars_decode($value ?? '', ENT_QUOTES),
            ENT_QUOTES
        );
    }
}
