<?php
// layout.php
// Shared layout helpers (nav, logo, theme, footer) for KitGrab pages.

require_once __DIR__ . '/bootstrap.php';

/**
 * Cache config and expose helper functions for shared UI elements.
 */
if (!function_exists('layout_cached_config')) {
    function layout_cached_config(?array $cfg = null): array
    {
        static $cachedConfig = null;

        if ($cfg !== null) {
            return $cfg;
        }

        if ($cachedConfig === null) {
            try {
                $cachedConfig = load_config();
            } catch (Throwable $e) {
                $cachedConfig = [];
            }
        }

        return $cachedConfig ?? [];
    }
}

if (!function_exists('layout_default_app_name')) {
    function layout_default_app_name(): string
    {
        return 'KitGrab';
    }
}

if (!function_exists('layout_app_name')) {
    function layout_app_name(?array $cfg = null): string
    {
        $config = layout_cached_config($cfg);
        $name = trim((string)($config['app']['name'] ?? ''));
        if ($name === '') {
            return layout_default_app_name();
        }

        return $name;
    }
}

if (!function_exists('layout_html_escape')) {
    function layout_html_escape(?string $value): string
    {
        return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('layout_known_database_upgrades')) {
    function layout_known_database_upgrades(): array
    {
        return [
            [
                'version' => '0.10.0 (Beta)',
                'description' => 'Add asset location support.',
                'is_applied' => static function (PDO $pdo): bool {
                    require_once SRC_PATH . '/inventory_schema.php';
                    try {
                        $stmt = $pdo->prepare('SELECT 1 FROM schema_version WHERE version = :version LIMIT 1');
                        $stmt->execute([':version' => '0.10.0 (Beta)']);
                        return (bool)$stmt->fetchColumn() && inventory_asset_location_column_exists($pdo);
                    } catch (Throwable $e) {
                        return false;
                    }
                },
            ],
            [
                'version' => '0.12.0-Beta',
                'description' => 'Add user favourite models.',
                'is_applied' => static function (PDO $pdo): bool {
                    try {
                        $stmt = $pdo->prepare('SELECT 1 FROM schema_version WHERE version = :version LIMIT 1');
                        $stmt->execute([':version' => '0.12.0-Beta']);
                        $versionApplied = (bool)$stmt->fetchColumn();
                        $pdo->query('SELECT 1 FROM user_favourite_models LIMIT 1');
                        return $versionApplied;
                    } catch (Throwable $e) {
                        return false;
                    }
                },
            ],
            [
                'version' => '1.0.0',
                'description' => 'Add user groups.',
                'is_applied' => static function (PDO $pdo): bool {
                    try {
                        $stmt = $pdo->prepare('SELECT 1 FROM schema_version WHERE version = :version LIMIT 1');
                        $stmt->execute([':version' => '1.0.0']);
                        $versionApplied = (bool)$stmt->fetchColumn();
                        $pdo->query('SELECT id, is_admin, is_staff FROM user_groups LIMIT 1');
                        $pdo->query('SELECT 1 FROM user_group_members LIMIT 1');
                        return $versionApplied;
                    } catch (Throwable $e) {
                        return false;
                    }
                },
            ],
            [
                'version' => '1.0.5',
                'description' => 'Add catalogue group permissions.',
                'is_applied' => static function (PDO $pdo): bool {
                    try {
                        $stmt = $pdo->prepare('SELECT 1 FROM schema_version WHERE version = :version LIMIT 1');
                        $stmt->execute([':version' => '1.0.5']);
                        $versionApplied = (bool)$stmt->fetchColumn();
                        $pdo->query('SELECT 1 FROM catalogue_group_restrictions LIMIT 1');
                        return $versionApplied;
                    } catch (Throwable $e) {
                        return false;
                    }
                },
            ],
            [
                'version' => '1.2.0',
                'description' => 'Add reservation and checkout notes.',
                'is_applied' => static function (PDO $pdo): bool {
                    try {
                        $stmt = $pdo->prepare('SELECT 1 FROM schema_version WHERE version = :version LIMIT 1');
                        $stmt->execute([':version' => '1.2.0']);
                        $versionApplied = (bool)$stmt->fetchColumn();
                        $pdo->query('SELECT reservation_note, checkout_note FROM reservations LIMIT 1');
                        return $versionApplied;
                    } catch (Throwable $e) {
                        return false;
                    }
                },
            ],
            [
                'version' => '1.2.1',
                'description' => 'Add secure password reset support.',
                'is_applied' => static function (PDO $pdo): bool {
                    try {
                        $stmt = $pdo->prepare('SELECT 1 FROM schema_version WHERE version = :version LIMIT 1');
                        $stmt->execute([':version' => '1.2.1']);
                        $versionApplied = (bool)$stmt->fetchColumn();
                        $pdo->query('SELECT token_hash, expires_at, used_at FROM password_reset_tokens LIMIT 1');
                        return $versionApplied;
                    } catch (Throwable $e) {
                        return false;
                    }
                },
            ],
        ];
    }
}

if (!function_exists('layout_pending_database_upgrades')) {
    function layout_pending_database_upgrades(): array
    {
        $pending = [];
        $loadError = '';

        try {
            require_once SRC_PATH . '/db.php';
            global $pdo;
            foreach (layout_known_database_upgrades() as $upgrade) {
                $isApplied = $upgrade['is_applied'];
                if (!$isApplied($pdo)) {
                    $pending[] = $upgrade;
                }
            }
        } catch (Throwable $e) {
            $loadError = $e->getMessage();
            $pending = layout_known_database_upgrades();
        }

        return [
            'pending' => $pending,
            'load_error' => $loadError,
        ];
    }
}

if (!function_exists('layout_is_install_upgrade_page')) {
    function layout_is_install_upgrade_page(): bool
    {
        $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
        return (bool)preg_match('#/install/upgrade/(?:index\.php)?$#', $scriptName)
            || (bool)preg_match('#/install/upgrade$#', $scriptName);
    }
}

if (!function_exists('layout_upgrade_page_url')) {
    function layout_upgrade_page_url(): string
    {
        $scriptName = (string)($_SERVER['SCRIPT_NAME'] ?? '');
        $scriptDir = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
        $leaf = $scriptDir !== '' ? basename($scriptDir) : '';
        if ($leaf === 'upgrade' && basename(dirname($scriptDir)) === 'install') {
            return 'index.php';
        }
        if ($leaf === 'install') {
            return 'upgrade/';
        }
        return 'install/upgrade/';
    }
}

if (!function_exists('layout_render_pending_upgrade_modal')) {
    function layout_render_pending_upgrade_modal(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE || empty($_SESSION['show_pending_upgrade_modal'])) {
            return;
        }
        unset($_SESSION['show_pending_upgrade_modal']);

        $sessionUser = $_SESSION['user'] ?? [];
        if (empty($sessionUser['is_admin']) || layout_is_install_upgrade_page()) {
            return;
        }

        $upgradeStatus = layout_pending_database_upgrades();
        $pending = $upgradeStatus['pending'] ?? [];
        if (empty($pending)) {
            return;
        }

        echo '<div id="pending-upgrade-modal" class="catalogue-modal catalogue-modal--upgrade" role="dialog" aria-modal="true" aria-hidden="true" aria-labelledby="pending-upgrade-title" hidden>';
        echo '<div class="catalogue-modal__backdrop" data-pending-upgrade-close></div>';
        echo '<div class="catalogue-modal__dialog" role="document"><div class="catalogue-modal__header">';
        echo '<h2 id="pending-upgrade-title" class="catalogue-modal__title">Database Upgrade Pending</h2>';
        echo '<button type="button" class="btn btn-sm btn-outline-secondary" data-pending-upgrade-close>Close</button>';
        echo '</div><div class="catalogue-modal__body">';
        echo '<p class="text-muted mb-3">The booking database has pending upgrades. Review them before running the upgrader.</p>';
        if (trim((string)($upgradeStatus['load_error'] ?? '')) !== '') {
            echo '<div class="alert alert-warning small mb-3">Could not read the applied upgrade history, so known upgrades are listed for review.</div>';
        }
        echo '<ul class="upgrade-modal__list">';
        foreach ($pending as $item) {
            echo '<li class="upgrade-modal__item"><strong>'
                . layout_html_escape((string)($item['version'] ?? ''))
                . '</strong><span>'
                . layout_html_escape((string)($item['description'] ?? ''))
                . '</span></li>';
        }
        echo '</ul><div class="d-flex flex-wrap justify-content-end gap-2 mt-4">';
        echo '<button type="button" class="btn btn-outline-secondary" data-pending-upgrade-close>Remind me later</button>';
        echo '<a class="btn btn-primary" href="' . layout_html_escape(layout_upgrade_page_url()) . '">Open upgrade page</a>';
        echo '</div></div></div></div>';
        echo <<<'SCRIPT'
<script>
(function () {
    const openModal = function () {
        const modal = document.getElementById('pending-upgrade-modal');
        if (!modal) return;
        const closeModal = function () {
            modal.classList.remove('is-open');
            modal.setAttribute('aria-hidden', 'true');
            document.body.classList.remove('catalogue-modal-open');
            window.setTimeout(function () { modal.hidden = true; }, 220);
        };
        modal.hidden = false;
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('catalogue-modal-open');
        window.requestAnimationFrame(function () {
            modal.classList.add('is-open');
            const action = modal.querySelector('a.btn-primary');
            if (action && typeof action.focus === 'function') action.focus();
        });
        modal.querySelectorAll('[data-pending-upgrade-close]').forEach(function (button) {
            button.addEventListener('click', closeModal);
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && modal.classList.contains('is-open')) closeModal();
        });
    };
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', openModal);
    } else {
        openModal();
    }
})();
</script>
SCRIPT;
    }
}

if (!function_exists('layout_render_admin_tabs')) {
    function layout_render_admin_tabs(string $active): string
    {
        $tabs = [
            'inventory_admin.php' => 'Inventory',
            'users.php' => 'Users',
            'groups.php' => 'Groups',
            'activity_log.php' => 'Activity Log',
            'settings.php' => 'Settings',
            'announcements.php' => 'Announcements',
            'reports.php' => 'Reports',
        ];

        $html = '<ul class="nav nav-tabs reservations-subtabs mb-3">';
        foreach ($tabs as $href => $label) {
            $class = $active === $href ? 'nav-link active' : 'nav-link';
            $html .= '<li class="nav-item"><a class="' . layout_html_escape($class) . '" href="'
                . layout_html_escape($href) . '">' . layout_html_escape($label) . '</a></li>';
        }
        $html .= '</ul>';

        return $html;
    }
}

// Backward-compatible wrappers retained for pages that still call legacy
// layout_* date formatting helpers.
if (!function_exists('layout_date_format')) {
    function layout_date_format(?array $cfg = null): string
    {
        return app_get_date_format($cfg ?? layout_cached_config());
    }
}

if (!function_exists('layout_time_format')) {
    function layout_time_format(?array $cfg = null): string
    {
        $format = app_get_time_format($cfg ?? layout_cached_config());
        return (strpos($format, 'h') !== false || strpos($format, 'g') !== false) ? '12h' : '24h';
    }
}

if (!function_exists('layout_format_date')) {
    function layout_format_date($value, ?array $cfg = null): string
    {
        return app_format_date($value, $cfg ?? layout_cached_config());
    }
}

if (!function_exists('layout_format_datetime')) {
    function layout_format_datetime($value, ?array $cfg = null): string
    {
        return app_format_datetime($value, $cfg ?? layout_cached_config());
    }
}

/**
 * Normalize a hex color string to #rrggbb.
 */
if (!function_exists('layout_normalize_hex_color')) {
    function layout_normalize_hex_color(?string $color, string $fallback): string
    {
        $fallback = ltrim($fallback, '#');
        $candidate = trim((string)$color);

        if (preg_match('/^#?([0-9a-fA-F]{6})$/', $candidate, $m)) {
            $hex = strtolower($m[1]);
        } elseif (preg_match('/^#?([0-9a-fA-F]{3})$/', $candidate, $m)) {
            $hex = strtolower($m[1]);
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        } else {
            $hex = strtolower($fallback);
        }

        return '#' . $hex;
    }
}

/**
 * Convert #rrggbb to [r, g, b].
 */
if (!function_exists('layout_color_to_rgb')) {
    function layout_color_to_rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        return [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        ];
    }
}

/**
 * Adjust lightness: positive to lighten, negative to darken.
 */
if (!function_exists('layout_adjust_lightness')) {
    function layout_adjust_lightness(string $hex, float $ratio): string
    {
        $ratio = max(-1.0, min(1.0, $ratio));
        [$r, $g, $b] = layout_color_to_rgb($hex);

        $adjust = static function (int $channel) use ($ratio): int {
            if ($ratio >= 0) {
                return (int)round($channel + (255 - $channel) * $ratio);
            }
            return (int)round($channel * (1 + $ratio));
        };

        $nr = str_pad(dechex($adjust($r)), 2, '0', STR_PAD_LEFT);
        $ng = str_pad(dechex($adjust($g)), 2, '0', STR_PAD_LEFT);
        $nb = str_pad(dechex($adjust($b)), 2, '0', STR_PAD_LEFT);

        return '#' . $nr . $ng . $nb;
    }
}

if (!function_exists('layout_primary_color')) {
    function layout_primary_color(?array $cfg = null): string
    {
        $config = layout_cached_config($cfg);
        $raw    = $config['app']['primary_color'] ?? '#660000';

        return layout_normalize_hex_color($raw, '#660000');
    }
}

if (!function_exists('layout_theme_styles')) {
    function layout_theme_styles(?array $cfg = null): string
    {
        $primary      = layout_primary_color($cfg);
        $primarySoft  = layout_adjust_lightness($primary, 0.3);   // subtle gradient partner
        $primaryStrong = layout_adjust_lightness($primary, -0.08); // slightly deeper for contrast

        [$r, $g, $b]          = layout_color_to_rgb($primary);
        [$rs, $gs, $bs]       = layout_color_to_rgb($primaryStrong);
        [$rl, $gl, $bl]       = layout_color_to_rgb($primarySoft);

        $style = '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">' . "\n"
            . '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/plugins/confirmDate/confirmDate.css">' . "\n"
            . <<<CSS
<style>
:root {
    --primary: {$primary};
    --primary-strong: {$primaryStrong};
    --primary-soft: {$primarySoft};
    --primary-rgb: {$r}, {$g}, {$b};
    --primary-strong-rgb: {$rs}, {$gs}, {$bs};
    --primary-soft-rgb: {$rl}, {$gl}, {$bl};
    --accent: var(--primary-strong);
    --accent-2: var(--primary-soft);
}

.flatpickr-day.selected,
.flatpickr-day.startRange,
.flatpickr-day.endRange,
.flatpickr-day.selected.inRange,
.flatpickr-day.startRange.inRange,
.flatpickr-day.endRange.inRange,
.flatpickr-day.selected:hover,
.flatpickr-day.startRange:hover,
.flatpickr-day.endRange:hover,
.flatpickr-day.selected:focus,
.flatpickr-day.startRange:focus,
.flatpickr-day.endRange:focus {
    background: var(--primary);
    border-color: var(--primary);
}

.flatpickr-day.today {
    border-color: var(--primary);
}

.flatpickr-day.today:hover,
.flatpickr-day.today:focus {
    background: var(--primary-soft);
    border-color: var(--primary-soft);
}

.flatpickr-months .flatpickr-prev-month:hover svg,
.flatpickr-months .flatpickr-next-month:hover svg {
    fill: var(--primary);
}

.flatpickr-time input:hover,
.flatpickr-time .flatpickr-am-pm:hover,
.flatpickr-time input:focus,
.flatpickr-time .flatpickr-am-pm:focus {
    background: rgba(var(--primary-rgb), 0.12);
}

.flatpickr-calendar .flatpickr-confirm {
    background: var(--primary) !important;
    border-color: var(--primary) !important;
    color: #fff !important;
}

.flatpickr-calendar .flatpickr-confirm:hover,
.flatpickr-calendar .flatpickr-confirm:focus {
    background: var(--primary-strong) !important;
    border-color: var(--primary-strong) !important;
    color: #fff !important;
}

.flatpickr-calendar .flatpickr-confirm svg {
    fill: currentColor;
}
</style>
CSS;

        return $style;
    }
}

if (!function_exists('layout_render_nav')) {
    /**
     * Render the main app navigation. Guests only see public pages.
     */
    function layout_render_nav(string $active, bool $isStaff, bool $isAdmin = false, bool $isAuthenticated = true): string
    {
        if (!$isAuthenticated) {
            $links = [
                ['href' => 'index.php',     'label' => 'Dashboard', 'staff' => false],
                ['href' => 'catalogue.php', 'label' => 'Catalogue', 'staff' => false],
                ['href' => 'my_bookings.php', 'label' => 'My Reservations', 'staff' => false],
            ];
        } else {
            $links = [
                ['href' => 'index.php',          'label' => 'Dashboard',           'staff' => false],
                ['href' => 'catalogue.php',      'label' => 'Catalogue',           'staff' => false],
                ['href' => 'my_bookings.php',    'label' => 'My Reservations',     'staff' => false],
                ['href' => 'reservations.php',   'label' => 'Reservations',        'staff' => true],
                ['href' => 'quick_checkout.php', 'label' => 'Quick Checkout',      'staff' => true],
                ['href' => 'quick_checkin.php',  'label' => 'Quick Checkin',       'staff' => true],
                ['href' => 'activity_log.php',   'label' => 'Admin',               'staff' => false, 'admin_only' => true],
            ];
        }

        $html = '<nav class="app-nav">';
        foreach ($links as $link) {
            if (!empty($link['admin_only'])) {
                if (!$isAdmin) {
                    continue;
                }
            } elseif ($link['staff'] && !$isStaff) {
                continue;
            }

            $href    = htmlspecialchars($link['href'], ENT_QUOTES, 'UTF-8');
            $label   = htmlspecialchars($link['label'], ENT_QUOTES, 'UTF-8');
            $classes = 'app-nav-link' . ($active === $link['href'] ? ' active' : '');

            $html .= '<a href="' . $href . '" class="' . $classes . '">' . $label . '</a>';
        }
        $html .= '</nav>';

        return $html;
    }
}

if (!function_exists('layout_sortable_column_header')) {
    function layout_sortable_column_header(string $label, string $ascendingSort, string $descendingSort, string $currentSort): string
    {
        $params = $_GET;
        unset($params['page']);
        $script = basename((string)($_SERVER['PHP_SELF'] ?? ''));
        $buildUrl = static function (string $sort) use ($params, $script): string {
            $next = $params; $next['sort'] = $sort;
            return $script . '?' . http_build_query($next);
        };
        $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return '<span class="table-sort-heading"><span class="table-sort-heading__label">' . $escape($label) . '</span>'
            . '<span class="table-sort-heading__buttons">'
            . '<a class="table-sort-button' . ($currentSort === $ascendingSort ? ' is-active' : '') . '" href="' . $escape($buildUrl($ascendingSort)) . '" aria-label="Sort ' . $escape($label) . ' ascending" title="Sort ascending">↑</a>'
            . '<a class="table-sort-button' . ($currentSort === $descendingSort ? ' is-active' : '') . '" href="' . $escape($buildUrl($descendingSort)) . '" aria-label="Sort ' . $escape($label) . ' descending" title="Sort descending">↓</a>'
            . '</span></span>';
    }
}

if (!function_exists('layout_footer')) {
    function layout_footer(): void
    {
        $versionFile = APP_ROOT . '/version.txt';
        $versionRaw  = is_file($versionFile) ? trim((string)@file_get_contents($versionFile)) : '';
        $version     = $versionRaw !== '' ? $versionRaw : 'dev';
        $versionEsc  = htmlspecialchars($version, ENT_QUOTES, 'UTF-8');
        $flatpickrCfg = app_flatpickr_settings(layout_cached_config());
        $flatpickrCfgJson = json_encode($flatpickrCfg, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
        $todayLabelJson = json_encode('Today', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?: '"Today"';
        $dateLabelJson = json_encode('Date', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?: '"Date"';
        $timeLabelJson = json_encode('Time', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?: '"Time"';

        echo '<div id="app-busy-overlay" class="app-busy-overlay" aria-hidden="true"><div class="app-busy-card"><div class="spinner-border" aria-hidden="true"></div><strong>Please wait…</strong></div></div>';
        echo '<script src="assets/nav.js"></script>';
        echo '<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>';
        echo '<script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/plugins/confirmDate/confirmDate.js"></script>';
        echo '<script>window.KitGrabFlatpickr=' . ($flatpickrCfgJson ?: '{}') . ';</script>';
        echo <<<SCRIPT
<script>
(function () {
    const boot = () => {
        if (typeof window.flatpickr !== 'function') return;
        const cfg = window.KitGrabFlatpickr || {};

        const normalizeFormats = (formats) => {
            const unique = [];
            formats.forEach((fmt) => {
                const v = String(fmt || '').trim();
                if (!v || unique.indexOf(v) !== -1) return;
                unique.push(v);
            });
            return unique;
        };

        const fallbackFormats = {
            date: normalizeFormats([
                cfg.machine_date_format,
                cfg.alt_date_format,
                'Y-m-d',
            ]),
            datetime: normalizeFormats([
                cfg.machine_datetime_format,
                cfg.alt_datetime_format,
                'Y-m-d\\TH:i:S',
                'Y-m-d\\TH:i',
                'Y-m-d H:i:S',
                'Y-m-d H:i',
            ]),
            time: normalizeFormats([
                cfg.machine_time_format,
                cfg.alt_time_format,
                'H:i:S',
                'H:i',
                'h:i:S K',
                'h:i K',
            ]),
        };

        const detectPickerType = (input) => {
            const explicit = String(input.getAttribute('data-flatpickr') || '').toLowerCase().trim();
            if (explicit === 'date' || explicit === 'time' || explicit === 'datetime') {
                return explicit;
            }

            const inputType = String(input.getAttribute('type') || '').toLowerCase().trim();
            if (inputType === 'date') return 'date';
            if (inputType === 'time') return 'time';
            if (inputType === 'datetime-local') return 'datetime';
            return '';
        };

        const isMobilePickerMode = () => {
            const hasMatchMedia = typeof window.matchMedia === 'function';
            const narrowViewport = hasMatchMedia && window.matchMedia('(max-width: 768px)').matches;
            const coarsePointer = hasMatchMedia && window.matchMedia('(pointer: coarse)').matches;
            return narrowViewport || coarsePointer;
        };

        const parseDateFactory = (formats) => (raw, formatHint) => {
            const value = String(raw || '').trim();
            if (!value) return undefined;

            const parseFormats = normalizeFormats([formatHint].concat(formats || []));
            for (let i = 0; i < parseFormats.length; i += 1) {
                const parsed = window.flatpickr.parseDate(value, parseFormats[i]);
                if (parsed instanceof Date && !Number.isNaN(parsed.getTime())) {
                    return parsed;
                }
            }

            // Only allow native parsing for strict ISO-like values to avoid
            // locale-dependent reinterpretation on blur/close.
            if (/^\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}(?::\d{2})?)?$/.test(value)) {
                const timestamp = Date.parse(value.replace(' ', 'T'));
                if (!Number.isNaN(timestamp)) {
                    return new Date(timestamp);
                }
            }

            return undefined;
        };

        const centerOpenCalendar = (_selectedDates, _dateStr, instance) => {
            if (!instance || !instance.calendarContainer) return;

            const viewportHeight = window.innerHeight || document.documentElement.clientHeight || 0;
            if (viewportHeight <= 0) return;

            const calendar = instance.calendarContainer;
            const topPadding = 16;
            const bottomPadding = 16;
            const availableHeight = Math.max(1, viewportHeight - topPadding - bottomPadding);

            const scrollCalendarIntoView = () => {
                const rect = calendar.getBoundingClientRect();
                if (rect.width <= 0 || rect.height <= 0) return;

                let targetTop = topPadding;
                if (rect.height < availableHeight) {
                    targetTop = topPadding + ((availableHeight - rect.height) / 2);
                }

                const desiredY = window.scrollY + (rect.top - targetTop);
                const maxScrollY = Math.max(0, document.documentElement.scrollHeight - viewportHeight);
                const nextY = Math.max(0, Math.min(maxScrollY, desiredY));
                if (Math.abs(nextY - window.scrollY) < 2) return;

                const reduceMotion = window.matchMedia
                    && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                window.scrollTo({
                    top: nextY,
                    behavior: reduceMotion ? 'auto' : 'smooth',
                });
            };

            window.requestAnimationFrame(() => {
                scrollCalendarIntoView();
                window.setTimeout(scrollCalendarIntoView, 90);
            });
        };

        const addTodayAction = (input, picker, pickerType) => {
            if (!picker || !picker.calendarContainer || pickerType === 'time') return;
            const calendar = picker.calendarContainer;
            if (calendar.querySelector('.flatpickr-today-action')) return;
            const button = document.createElement('button');
            button.type = 'button'; button.className = 'flatpickr-today-action'; button.textContent = {$todayLabelJson};
            button.addEventListener('click', (event) => {
                event.preventDefault(); event.stopPropagation();
                const today = new Date();
                if (pickerType === 'date') today.setHours(0, 0, 0, 0);
                picker.setDate(today, true, picker.config.dateFormat);
                if (pickerType === 'date') picker.close();
            });
            const weekdays = calendar.querySelector('.flatpickr-weekdays');
            const days = calendar.querySelector('.flatpickr-days');
            if (weekdays && weekdays.parentNode) weekdays.parentNode.insertBefore(button, weekdays);
            else if (days && days.parentNode) days.parentNode.insertBefore(button, days);
            else calendar.appendChild(button);
        };

        const emphasizeDateSelection = (picker, pickerType) => {
            if (!picker || !picker.calendarContainer || pickerType === 'time') return;
            const calendar = picker.calendarContainer;
            if (calendar.querySelector('.flatpickr-date-heading')) return;
            const months = calendar.querySelector('.flatpickr-months');
            const heading = document.createElement('div');
            heading.className = 'flatpickr-date-heading'; heading.textContent = {$dateLabelJson};
            calendar.classList.add('flatpickr-has-date-heading');
            if (months && months.parentNode) months.parentNode.insertBefore(heading, months);
            else calendar.insertBefore(heading, calendar.firstChild);
        };

        const emphasizeTimeSelection = (picker, pickerType) => {
            if (!picker || !picker.calendarContainer || !picker.timeContainer || pickerType === 'date') return;
            const calendar = picker.calendarContainer;
            calendar.classList.add('flatpickr-has-prominent-time');
            if (calendar.querySelector('.flatpickr-time-heading')) return;
            const heading = document.createElement('div');
            heading.className = 'flatpickr-time-heading'; heading.textContent = {$timeLabelJson};
            calendar.insertBefore(heading, picker.timeContainer);
        };

        const initInput = (input) => {
            if (!(input instanceof HTMLInputElement)) return;
            if (input.dataset.flatpickrBound === '1') return;
            if (input.getAttribute('data-flatpickr') === 'off') return;

            const pickerType = detectPickerType(input);
            if (!pickerType) return;

            const originalType = String(input.getAttribute('type') || '').toLowerCase().trim();
            const nativeType = originalType === 'date' || originalType === 'time' || originalType === 'datetime-local';
            const mobilePickerMode = isMobilePickerMode();
            if (mobilePickerMode && nativeType) {
                // Keep native phone picker controls on mobile devices.
                input.dataset.flatpickrBound = '1';
                return;
            }
            if (nativeType) {
                input.setAttribute('type', 'text');
            }

            const baseOptions = {
                allowInput: true,
                disableMobile: true,
                altInput: true,
                altInputClass: (input.className ? input.className + ' ' : '') + 'flatpickr-alt-input',
                parseDate: parseDateFactory(fallbackFormats[pickerType] || []),
                onOpen: [centerOpenCalendar],
            };
            if ((pickerType === 'time' || pickerType === 'datetime') && typeof window.confirmDatePlugin === 'function') {
                baseOptions.plugins = [
                    new window.confirmDatePlugin({
                        confirmText: 'Apply',
                        showAlways: false,
                        theme: 'light',
                    }),
                ];
            }
            const parsedInitialDate = baseOptions.parseDate(input.value);
            if (parsedInitialDate instanceof Date && !Number.isNaN(parsedInitialDate.getTime())) {
                baseOptions.defaultDate = parsedInitialDate;
            }

            if (pickerType === 'date') {
                baseOptions.dateFormat = String(cfg.machine_date_format || 'Y-m-d');
                baseOptions.altFormat = String(cfg.alt_date_format || 'Y-m-d');
            } else if (pickerType === 'time') {
                baseOptions.enableTime = true;
                baseOptions.noCalendar = true;
                baseOptions.time_24hr = !!cfg.time_24hr;
                baseOptions.enableSeconds = !!cfg.enable_seconds;
                baseOptions.dateFormat = String(cfg.machine_time_format || 'H:i');
                baseOptions.altFormat = String(cfg.alt_time_format || 'H:i');
            } else {
                baseOptions.enableTime = true;
                baseOptions.time_24hr = !!cfg.time_24hr;
                baseOptions.enableSeconds = !!cfg.enable_seconds;
                baseOptions.dateFormat = String(cfg.machine_datetime_format || 'Y-m-d\\TH:i');
                baseOptions.altFormat = String(cfg.alt_datetime_format || 'Y-m-d H:i');
            }

            try {
                const picker = window.flatpickr(input, baseOptions);
                emphasizeDateSelection(picker, pickerType);
                emphasizeTimeSelection(picker, pickerType);
                addTodayAction(input, picker, pickerType);
                if (picker && picker.altInput) {
                    if (input.style && input.style.cssText) {
                        picker.altInput.style.cssText = input.style.cssText;
                    }
                    if (input.hasAttribute('placeholder')) {
                        picker.altInput.setAttribute('placeholder', input.getAttribute('placeholder') || '');
                    }
                    picker.altInput.required = input.required;
                    picker.altInput.disabled = input.disabled;
                    picker.altInput.readOnly = input.readOnly;
                }
                input.dataset.flatpickrBound = '1';
            } catch (e) {
                // Keep native input as fallback if Flatpickr fails for this field.
            }
        };

        const scan = (root) => {
            if (!(root instanceof Element || root instanceof Document)) return;
            if (root instanceof HTMLInputElement) {
                initInput(root);
            }
            root.querySelectorAll('input').forEach(initInput);
        };

        scan(document);

        if (document.body && typeof MutationObserver === 'function') {
            const observer = new MutationObserver((mutations) => {
                mutations.forEach((mutation) => {
                    mutation.addedNodes.forEach((node) => {
                        if (node instanceof Element) {
                            scan(node);
                        }
                    });
                });
            });
            observer.observe(document.body, { childList: true, subtree: true });
        }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
(function () {
    const overlay = document.getElementById('app-busy-overlay');
    if (!overlay) return;
    const show = () => { overlay.classList.add('is-visible'); overlay.setAttribute('aria-hidden', 'false'); };
    document.addEventListener('submit', (event) => {
        if (event.defaultPrevented || event.target.hasAttribute('data-no-busy')) return;
        window.setTimeout(show, 0);
    });
    document.addEventListener('click', (event) => {
        const link = event.target.closest('a[href]');
        if (!link || link.target === '_blank' || link.hasAttribute('download') || link.dataset.noBusy !== undefined) return;
        const href = link.getAttribute('href') || '';
        if (!href || href[0] === '#' || /^(mailto:|tel:|javascript:)/i.test(href)) return;
        show();
    });
    window.addEventListener('pageshow', () => { overlay.classList.remove('is-visible'); overlay.setAttribute('aria-hidden', 'true'); });
})();
</script>
SCRIPT;
        layout_render_pending_upgrade_modal();
        $cfg = layout_cached_config();
        $appName = layout_app_name($cfg);
        $appNameEsc = htmlspecialchars($appName, ENT_QUOTES, 'UTF-8');

        echo '<footer class="text-center text-muted mt-4 small">'
            . $appNameEsc . ' Version ' . $versionEsc . ' - Created by '
            . '<a href="https://www.linkedin.com/in/ben-pirozzolo-76212a88" target="_blank" rel="noopener noreferrer">Ben Pirozzolo</a>'
            . '</footer>';
    }
}

if (!function_exists('layout_logo_tag')) {
    function layout_default_logo_url(): string
    {
        $scriptName = (string)($_SERVER['SCRIPT_NAME'] ?? '');
        $scriptDir  = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
        $baseDir    = $scriptDir;

        $leaf = $scriptDir !== '' ? basename($scriptDir) : '';
        if ($leaf === 'install') {
            $baseDir = rtrim(str_replace('\\', '/', dirname($scriptDir)), '/');
        } elseif ($leaf === 'upgrade' && basename(dirname($scriptDir)) === 'install') {
            $baseDir = rtrim(str_replace('\\', '/', dirname(dirname($scriptDir))), '/');
        }

        if ($baseDir === '') {
            return '/kitgrab-logo.png';
        }

        return $baseDir . '/kitgrab-logo.png';
    }

    function layout_logo_tag(?array $cfg = null): string
    {
        $cfg = layout_cached_config($cfg);

        $logoUrl = '';
        if (isset($cfg['app']['logo_url']) && trim($cfg['app']['logo_url']) !== '') {
            $logoUrl = trim($cfg['app']['logo_url']);
        }

        if ($logoUrl === '') {
            $logoUrl = layout_default_logo_url();
        }

        $urlEsc = htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8');
        $appNameEsc = htmlspecialchars(layout_app_name($cfg), ENT_QUOTES, 'UTF-8');
        return '<div class="app-logo text-center mb-3">'
            . '<a href="index.php" aria-label="Go to dashboard">'
            . '<img src="' . $urlEsc . '" alt="' . $appNameEsc . ' logo" style="max-height:80px; width:auto; height:auto; max-width:100%; object-fit:contain;">'
            . '</a>'
            . '</div>';
    }
}
