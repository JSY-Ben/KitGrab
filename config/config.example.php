<?php
/**
 * Global configuration for KitGrab.
 *
 * Edit the values below to match your environment.
 *
 * Copy this file to config/config.php and keep your secrets out of version control.
 */

/**
 * Paging / limits for catalogue
 * These run as soon as config.php is required.
 */
if (!defined('CATALOGUE_ITEMS_PER_PAGE')) {
    define('CATALOGUE_ITEMS_PER_PAGE', 12);
}

// ---------------------------------------------------------------------
// Main config array (keep your existing values here)
// ---------------------------------------------------------------------
return [

    'db_booking' => [
        'host'     => 'localhost',
        'port'     => 3306,
        'dbname'   => '',
        'username' => '',
        'password' => '',      // keep your existing password
        'charset'  => 'utf8mb4',
    ],

    'ldap' => [
        'host'          => 'ldaps://',
        'base_dn'       => '',
        'bind_dn'       => '',
        'bind_password' => '', // keep your existing password
        'ignore_cert'   => true,
    ],

    'auth' => [
        'ldap_enabled' => true,
        'google_oauth_enabled' => false,
        'microsoft_oauth_enabled' => false,
        // Optional role mappings for external auth providers
        'admin_group_cn' => [],
        'checkout_group_cn' => [],
        'google_admin_emails' => [],
        'google_checkout_emails' => [],
        'microsoft_admin_emails' => [],
        'microsoft_checkout_emails' => [],
    ],

    'google_oauth' => [
        'client_id'     => '',
        'client_secret' => '',
        // Leave blank to auto-detect the login_process.php callback URL
        'redirect_uri'  => '',
        // Optional restriction to specific Google Workspace domains
        'allowed_domains' => [
            // 'example.com',
        ],
    ],

    // Optional: Google Workspace directory search (requires Admin SDK + service account)
    'google_directory' => [
        // Provide either a raw JSON string or a filesystem path to the JSON file
        'service_account_json' => '',
        'service_account_path' => '',
        // Admin user email to impersonate for directory read access
        'impersonated_user'     => '',
    ],

    'microsoft_oauth' => [
        'client_id'     => '',
        'client_secret' => '',
        // Tenant ID (GUID)
        'tenant'        => '',
        // Leave blank to auto-detect the login_process.php callback URL
        'redirect_uri'  => '',
        // Optional restriction to specific domains
        'allowed_domains' => [
            // 'example.com',
        ],
    ],

    // Optional: Entra directory search (defaults to microsoft_oauth client_id/secret/tenant)
    'entra_directory' => [
        'client_id'     => '',
        'client_secret' => '',
        'tenant'        => '',
    ],

    'app' => [
        'name' => 'KitGrab',
        'timezone' => 'Europe/Jersey',
        'debug'    => true,
        'logo_url' => 'kitgrab-logo.png', // optional: full URL or relative path to logo image
        'date_format' => 'd/m/Y', // display date format (PHP format string)
        'time_format' => 'H:i', // see settings for supported formats
        'primary_color' => '#660000', // main UI colour for gradients/buttons
        'missed_cutoff_minutes' => 60, // minutes after start time before marking reservation as missed
        'overdue_staff_email' => '', // overdue report recipients (comma/newline separated)
        'overdue_staff_name'  => '', // optional names for recipients (comma/newline separated)
        'block_catalogue_overdue' => true, // block catalogue for users with overdue checkouts
        'catalogue_cache_ttl' => 0, // in seconds; set 0 to disable
        // Reservation controls
        'reservation_notice_minutes' => 0,
        'reservation_notice_bypass_checkout_staff' => false,
        'reservation_notice_bypass_admins' => false,
        'reservation_min_duration_minutes' => 0,
        'reservation_max_duration_minutes' => 0,
        'reservation_duration_bypass_checkout_staff' => false,
        'reservation_duration_bypass_admins' => false,
        'reservation_max_concurrent_reservations' => 0,
        'reservation_concurrent_bypass_checkout_staff' => false,
        'reservation_concurrent_bypass_admins' => false,
        'reservation_blackout_slots' => [
            // ['start' => '2026-03-01 09:00:00', 'end' => '2026-03-01 17:00:00', 'reason' => 'Maintenance'],
        ],
        'reservation_blackout_bypass_checkout_staff' => false,
        'reservation_blackout_bypass_admins' => false,
        // Timed catalogue announcements
        'announcements' => [
            // ['message' => 'Notice', 'start_datetime' => '2026-01-01 09:00:00', 'end_datetime' => '2026-01-01 17:00:00'],
        ],
        // Backward-compatible single announcement fields
        'announcement_message' => '',
        'announcement_start_ts' => 0,
        'announcement_end_ts' => 0,
        'announcement_start_datetime' => '',
        'announcement_end_datetime' => '',
    ],

    'catalogue' => [
        // Restrict which categories appear in the catalogue filter.
        // Leave empty to show all categories.
        'allowed_categories' => [],
    ],

    'smtp' => [
        'host'       => '',
        'port'       => 587,
        'username'   => '',
        'password'   => '',
        'encryption' => 'tls', // none|ssl|tls
        'auth_method'=> 'login', // login|plain|none
        'from_email' => '',
        'from_name'  => 'KitGrab',
    ],
];
