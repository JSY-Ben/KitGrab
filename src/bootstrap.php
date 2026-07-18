<?php
// src/bootstrap.php
// Sets up shared paths and config loader for the application.

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

if (!defined('SRC_PATH')) {
    define('SRC_PATH', APP_ROOT . '/src');
}

if (!defined('CONFIG_PATH')) {
    define('CONFIG_PATH', APP_ROOT . '/config');
}

require_once SRC_PATH . '/config_loader.php';

// Application date/time values are stored as local wall-clock times. Make the
// configured application timezone PHP's default before any request code uses
// strtotime(), date(), DateTime, or DateTimeImmutable. Explicit timezone usage
// elsewhere remains valid, while legacy calls now behave consistently too.
(static function (): void {
    try {
        $config = load_config();
        $timezone = trim((string)($config['app']['timezone'] ?? 'Europe/Jersey'));
        if ($timezone !== '' && in_array($timezone, timezone_identifiers_list(), true)) {
            date_default_timezone_set($timezone);
        }
    } catch (Throwable $e) {
        // A config file does not exist yet on a fresh installation. Leave PHP's
        // current default in place so the installer can still be displayed.
    }
})();

require_once SRC_PATH . '/datetime_helpers.php';
require_once SRC_PATH . '/announcement_helpers.php';
