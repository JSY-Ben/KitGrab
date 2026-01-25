<?php
require_once __DIR__ . '/bootstrap.php';

// auth.php
// Simple authentication guard used by all protected pages.

session_start();

$script = basename($_SERVER['PHP_SELF']);
$loginPath = defined('AUTH_LOGIN_PATH') ? AUTH_LOGIN_PATH : 'login.php';
$loginProcessPath = defined('AUTH_LOGIN_PROCESS_PATH') ? AUTH_LOGIN_PROCESS_PATH : 'login_process.php';

// If no logged-in user, redirect to login.php (except on login pages themselves)
if (empty($_SESSION['user'])) {
    if (!in_array($script, [basename($loginPath), basename($loginProcessPath)], true)) {
        header('Location: ' . $loginPath);
        exit;
    }
    // On login pages, do nothing more
    return;
}

// User is logged in – expose as $currentUser for the including script
$currentUser = $_SESSION['user'];

// Refresh role flags from the local users table when available.
if (!empty($currentUser['email'])) {
    try {
        require_once SRC_PATH . '/db.php';
        $config = load_config();
        $authCfg = $config['auth'] ?? [];

        $normalizeEmailList = static function ($raw): array {
            if (!is_array($raw)) {
                $raw = [];
            }
            return array_values(array_filter(array_map('strtolower', array_map('trim', $raw))));
        };

        $stmt = $pdo->prepare('SELECT is_admin, is_staff, auth_source FROM users WHERE email = :email LIMIT 1');
        $emailLower = strtolower(trim($currentUser['email']));
        $stmt->execute([':email' => $emailLower]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $dbIsAdmin = !empty($row['is_admin']);
            $dbIsStaff = !empty($row['is_staff']) || $dbIsAdmin;
            $authSource = strtolower(trim((string)($row['auth_source'] ?? '')));

            $configIsAdmin = false;
            $configIsStaff = false;
            if ($authSource === 'google') {
                $googleAdminEmails = $normalizeEmailList($authCfg['google_admin_emails'] ?? []);
                $googleCheckoutEmails = $normalizeEmailList($authCfg['google_checkout_emails'] ?? []);
                $configIsAdmin = in_array($emailLower, $googleAdminEmails, true);
                $configIsStaff = in_array($emailLower, $googleCheckoutEmails, true);
            } elseif ($authSource === 'microsoft') {
                $msAdminEmails = $normalizeEmailList($authCfg['microsoft_admin_emails'] ?? []);
                $msCheckoutEmails = $normalizeEmailList($authCfg['microsoft_checkout_emails'] ?? []);
                $configIsAdmin = in_array($emailLower, $msAdminEmails, true);
                $configIsStaff = in_array($emailLower, $msCheckoutEmails, true);
            }

            if ($authSource === 'google' || $authSource === 'microsoft') {
                $currentUser['is_admin'] = $dbIsAdmin || $configIsAdmin;
                $currentUser['is_staff'] = $dbIsStaff || $configIsStaff || $currentUser['is_admin'];
            } else {
                $currentUser['is_admin'] = $dbIsAdmin || !empty($currentUser['is_admin']);
                $currentUser['is_staff'] = $dbIsStaff || !empty($currentUser['is_staff']) || $currentUser['is_admin'];
            }
            $_SESSION['user']['is_admin'] = $currentUser['is_admin'];
            $_SESSION['user']['is_staff'] = $currentUser['is_staff'];
        }
    } catch (Throwable $e) {
        // Ignore role refresh failures to avoid blocking access.
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
