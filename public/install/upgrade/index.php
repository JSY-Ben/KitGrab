<?php
define('APP_ROOT', dirname(__DIR__, 3));
define('CONFIG_PATH', APP_ROOT . '/config');

require_once APP_ROOT . '/src/bootstrap.php';
require_once APP_ROOT . '/src/layout.php';
require_once APP_ROOT . '/src/inventory_schema.php';

$configPath = CONFIG_PATH . '/config.php';
$legacyConfigPath = APP_ROOT . '/config.php';

function upgrade_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function upgrade_ensure_schema_version_table(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS schema_version (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            version VARCHAR(32) NOT NULL,
            applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_schema_version_version (version)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function upgrade_applied_versions(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT version FROM schema_version ORDER BY id ASC');
    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $versions = [];
    foreach ($rows as $row) {
        $version = trim((string)$row);
        if ($version !== '') {
            $versions[$version] = true;
        }
    }

    return $versions;
}

function upgrade_current_schema_version(PDO $pdo): string
{
    $stmt = $pdo->query('SELECT version FROM schema_version ORDER BY id DESC LIMIT 1');
    $version = $stmt->fetchColumn();
    $version = trim((string)$version);
    return $version !== '' ? $version : 'None recorded';
}

function upgrade_dump_identifier(string $name): string
{
    return '`' . str_replace('`', '``', $name) . '`';
}

function upgrade_dump_value(PDO $pdo, $value): string
{
    if ($value === null) {
        return 'NULL';
    }

    return $pdo->quote((string)$value);
}

function upgrade_dump_table_order(PDO $pdo, string $databaseName, array $tables): array
{
    $orderedTables = [];
    $tables = array_values(array_filter(array_map('strval', $tables), 'strlen'));
    $dependencies = [];
    foreach ($tables as $table) {
        $dependencies[$table] = [];
    }

    if ($databaseName !== '') {
        try {
            $stmt = $pdo->prepare("
                SELECT TABLE_NAME, REFERENCED_TABLE_NAME
                  FROM information_schema.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA = :schema
                   AND REFERENCED_TABLE_SCHEMA = :schema
                   AND REFERENCED_TABLE_NAME IS NOT NULL
            ");
            $stmt->execute([':schema' => $databaseName]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $table = (string)($row['TABLE_NAME'] ?? '');
                $refTable = (string)($row['REFERENCED_TABLE_NAME'] ?? '');
                if (isset($dependencies[$table], $dependencies[$refTable])) {
                    $dependencies[$table][$refTable] = true;
                }
            }
        } catch (Throwable $e) {
            return $tables;
        }
    }

    $temporary = [];
    $permanent = [];
    $visit = static function (string $table) use (&$visit, &$dependencies, &$temporary, &$permanent, &$orderedTables): void {
        if (isset($permanent[$table]) || isset($temporary[$table])) {
            return;
        }

        $temporary[$table] = true;
        foreach (array_keys($dependencies[$table] ?? []) as $dependency) {
            $visit($dependency);
        }
        unset($temporary[$table]);

        $permanent[$table] = true;
        $orderedTables[] = $table;
    };

    foreach ($tables as $table) {
        $visit($table);
    }

    return array_values(array_unique($orderedTables));
}

function upgrade_stream_database_backup(PDO $pdo, string $databaseName, string $appName): void
{
    $timestamp = date('Ymd-His');
    $safeDbName = preg_replace('/[^A-Za-z0-9._-]+/', '-', $databaseName);
    $safeDbName = $safeDbName !== '' ? $safeDbName : 'database';
    $fileName = $safeDbName . '-backup-' . $timestamp . '.sql';

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/sql; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');

    echo '-- ' . str_replace(["\r", "\n"], ' ', $appName) . " booking database backup\n";
    echo '-- Database: ' . str_replace(["\r", "\n"], ' ', $databaseName) . "\n";
    echo '-- Generated: ' . date('Y-m-d H:i:s') . "\n\n";
    echo "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n";
    echo "SET AUTOCOMMIT = 0;\n";
    echo "START TRANSACTION;\n";
    echo "SET time_zone = '+00:00';\n";
    echo "SET FOREIGN_KEY_CHECKS = 0;\n\n";

    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $tables = upgrade_dump_table_order($pdo, $databaseName, $tables);
    foreach ($tables as $tableName) {
        $tableRef = upgrade_dump_identifier((string)$tableName);
        $createStmt = $pdo->query('SHOW CREATE TABLE ' . $tableRef)->fetch(PDO::FETCH_ASSOC);
        if (!$createStmt) {
            continue;
        }

        $createSql = '';
        foreach ($createStmt as $key => $value) {
            if (stripos((string)$key, 'create table') !== false) {
                $createSql = (string)$value;
                break;
            }
        }
        if ($createSql === '') {
            continue;
        }

        echo "--\n-- Table structure for table {$tableName}\n--\n\n";
        echo "DROP TABLE IF EXISTS {$tableRef};\n";
        echo $createSql . ";\n\n";

        $rows = $pdo->query('SELECT * FROM ' . $tableRef);
        if (!$rows) {
            continue;
        }

        $rowCount = 0;
        foreach ($rows as $row) {
            if (!is_array($row) || empty($row)) {
                continue;
            }
            if ($rowCount === 0) {
                echo "--\n-- Dumping data for table {$tableName}\n--\n\n";
            }

            $columns = [];
            $values = [];
            foreach ($row as $column => $value) {
                if (is_int($column)) {
                    continue;
                }
                $columns[] = upgrade_dump_identifier((string)$column);
                $values[] = upgrade_dump_value($pdo, $value);
            }
            if (!empty($columns)) {
                echo 'INSERT INTO ' . $tableRef . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ");\n";
                $rowCount++;
            }
        }
        if ($rowCount > 0) {
            echo "\n";
        }
    }

    echo "SET FOREIGN_KEY_CHECKS = 1;\n";
    echo "COMMIT;\n";
}

$targetVersion = '1.2.2';
$configExists = is_file($configPath) || is_file($legacyConfigPath);
$messages = [];
$errors = [];
$pendingMigrations = [];
$currentSchemaVersion = 'Unavailable';
$upgradeReady = false;
$upgradeCompleted = false;
$pdo = null;

if (!$configExists) {
    $errors[] = 'No config.php found. Run the installer before using the upgrader.';
} else {
    try {
        require APP_ROOT . '/src/db.php';
        $upgradeReady = true;
    } catch (Throwable $e) {
        $errors[] = 'Could not connect to the database: ' . $e->getMessage();
    }
}

$migrations = [
    [
        'version' => '0.10.0 (Beta)',
        'label' => 'Add the asset location column and mark the schema as current',
        'is_applied' => static function (PDO $pdo, array $appliedVersions): bool {
            return isset($appliedVersions['0.10.0 (Beta)']) && inventory_asset_location_column_exists($pdo);
        },
        'run' => static function (PDO $pdo): void {
            inventory_ensure_asset_location_column($pdo);
        },
    ],
    [
        'version' => '0.12.0-Beta',
        'label' => 'Add user favourite models',
        'is_applied' => static function (PDO $pdo, array $appliedVersions): bool {
            try {
                $pdo->query('SELECT 1 FROM user_favourite_models LIMIT 1');
                return isset($appliedVersions['0.12.0-Beta']);
            } catch (Throwable $e) {
                return false;
            }
        },
        'run' => static function (PDO $pdo): void {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS user_favourite_models (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    user_email VARCHAR(255) NOT NULL,
                    model_id INT UNSIGNED NOT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

                    PRIMARY KEY (id),
                    UNIQUE KEY uq_user_favourite_models_user_model (user_email, model_id),
                    KEY idx_user_favourite_models_user (user_email),
                    KEY idx_user_favourite_models_model (model_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        },
    ],
    [
        'version' => '1.0.0',
        'label' => 'Add user groups',
        'is_applied' => static function (PDO $pdo, array $appliedVersions): bool {
            try {
                $pdo->query('SELECT id, is_admin, is_staff FROM user_groups LIMIT 1');
                $pdo->query('SELECT 1 FROM user_group_members LIMIT 1');
                return isset($appliedVersions['1.0.0']);
            } catch (Throwable $e) {
                return false;
            }
        },
        'run' => static function (PDO $pdo): void {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS user_groups (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    name VARCHAR(255) NOT NULL,
                    description TEXT DEFAULT NULL,
                    is_admin TINYINT(1) NOT NULL DEFAULT 0,
                    is_staff TINYINT(1) NOT NULL DEFAULT 0,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

                    PRIMARY KEY (id),
                    UNIQUE KEY uq_user_groups_name (name)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS user_group_members (
                    user_id INT UNSIGNED NOT NULL,
                    group_id INT UNSIGNED NOT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

                    PRIMARY KEY (user_id, group_id),
                    KEY idx_user_group_members_group (group_id),
                    CONSTRAINT fk_user_group_members_user
                        FOREIGN KEY (user_id)
                        REFERENCES users (id)
                        ON DELETE CASCADE,
                    CONSTRAINT fk_user_group_members_group
                        FOREIGN KEY (group_id)
                        REFERENCES user_groups (id)
                        ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            $columnExists = static function (PDO $pdo, string $table, string $column): bool {
                $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE :column");
                $stmt->execute([':column' => $column]);
                return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
            };
            if (!$columnExists($pdo, 'user_groups', 'is_admin')) {
                $pdo->exec('ALTER TABLE user_groups ADD COLUMN is_admin TINYINT(1) NOT NULL DEFAULT 0 AFTER description');
            }
            if (!$columnExists($pdo, 'user_groups', 'is_staff')) {
                $pdo->exec('ALTER TABLE user_groups ADD COLUMN is_staff TINYINT(1) NOT NULL DEFAULT 0 AFTER is_admin');
            }
            $pdo->exec("
                INSERT INTO user_groups (name, description, is_admin, is_staff, created_at)
                SELECT 'Administrators', 'Users in this group have admin access.', 1, 1, NOW()
                WHERE EXISTS (SELECT 1 FROM users WHERE is_admin = 1)
                ON DUPLICATE KEY UPDATE is_admin = 1, is_staff = 1
            ");
            $pdo->exec("
                INSERT IGNORE INTO user_group_members (user_id, group_id)
                SELECT u.id, ug.id
                  FROM users u
                  JOIN user_groups ug ON ug.name = 'Administrators'
                 WHERE u.is_admin = 1
            ");
            $pdo->exec("
                INSERT INTO user_groups (name, description, is_admin, is_staff, created_at)
                SELECT 'Checkout Users', 'Users in this group can check equipment in and out.', 0, 1, NOW()
                WHERE EXISTS (SELECT 1 FROM users WHERE is_staff = 1 AND is_admin = 0)
                ON DUPLICATE KEY UPDATE is_staff = 1
            ");
            $pdo->exec("
                INSERT IGNORE INTO user_group_members (user_id, group_id)
                SELECT u.id, ug.id
                  FROM users u
                  JOIN user_groups ug ON ug.name = 'Checkout Users'
                 WHERE u.is_staff = 1
                   AND u.is_admin = 0
            ");
        },
    ],
    [
        'version' => '1.0.5',
        'label' => 'Add catalogue group permissions',
        'is_applied' => static function (PDO $pdo, array $appliedVersions): bool {
            try {
                $pdo->query('SELECT 1 FROM catalogue_group_restrictions LIMIT 1');
                return isset($appliedVersions['1.0.5']);
            } catch (Throwable $e) {
                return false;
            }
        },
        'run' => static function (PDO $pdo): void {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS catalogue_group_restrictions (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    group_id INT UNSIGNED NOT NULL,
                    group_name VARCHAR(255) NOT NULL DEFAULT '',
                    item_type VARCHAR(32) NOT NULL,
                    item_id INT UNSIGNED NOT NULL,
                    item_name_cache VARCHAR(255) NOT NULL DEFAULT '',
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                    PRIMARY KEY (id),
                    UNIQUE KEY uq_catalogue_group_item (group_id, item_type, item_id),
                    KEY idx_catalogue_group_restrictions_group (group_id),
                    KEY idx_catalogue_group_restrictions_item (item_type, item_id),
                    CONSTRAINT fk_catalogue_group_restrictions_group
                        FOREIGN KEY (group_id)
                        REFERENCES user_groups (id)
                        ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        },
    ],
    [
        'version' => '1.2.0',
        'label' => 'Add reservation and checkout notes',
        'is_applied' => static function (PDO $pdo, array $appliedVersions): bool {
            try {
                $pdo->query('SELECT reservation_note, checkout_note FROM reservations LIMIT 1');
                return isset($appliedVersions['1.2.0']);
            } catch (Throwable $e) {
                return false;
            }
        },
        'run' => static function (PDO $pdo): void {
            $columnExists = static function (string $column) use ($pdo): bool {
                $stmt = $pdo->prepare("SHOW COLUMNS FROM reservations LIKE :column");
                $stmt->execute([':column' => $column]);
                return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
            };
            if (!$columnExists('reservation_note')) {
                $pdo->exec('ALTER TABLE reservations ADD COLUMN reservation_note TEXT NULL AFTER asset_name_cache');
            }
            if (!$columnExists('checkout_note')) {
                $pdo->exec('ALTER TABLE reservations ADD COLUMN checkout_note TEXT NULL AFTER reservation_note');
            }
        },
    ],
    [
        'version' => '1.2.1',
        'label' => 'Add secure password reset support',
        'is_applied' => static function (PDO $pdo, array $appliedVersions): bool {
            try {
                $pdo->query('SELECT token_hash, expires_at, used_at FROM password_reset_tokens LIMIT 1');
                return isset($appliedVersions['1.2.1']);
            } catch (Throwable $e) {
                return false;
            }
        },
        'run' => static function (PDO $pdo): void {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS password_reset_tokens (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    user_id INT UNSIGNED NOT NULL,
                    token_hash CHAR(64) NOT NULL,
                    expires_at DATETIME NOT NULL,
                    used_at DATETIME DEFAULT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY uq_password_reset_token_hash (token_hash),
                    KEY idx_password_reset_user (user_id),
                    KEY idx_password_reset_expires (expires_at),
                    CONSTRAINT fk_password_reset_user
                        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        },
    ],
    [
        'version' => '1.2.2',
        'label' => 'Add user registration, approval, and profile support',
        'is_applied' => static function (PDO $pdo, array $appliedVersions): bool {
            try {
                $pdo->query('SELECT is_approved, profile_photo_url FROM users LIMIT 1');
                return isset($appliedVersions['1.2.2']);
            } catch (Throwable $e) {
                return false;
            }
        },
        'run' => static function (PDO $pdo): void {
            $column = $pdo->query("SHOW COLUMNS FROM users LIKE 'is_approved'")->fetch(PDO::FETCH_ASSOC);
            if (!$column) {
                $pdo->exec('ALTER TABLE users ADD COLUMN is_approved TINYINT(1) NOT NULL DEFAULT 1 AFTER auth_source');
            }
            $photoColumn = $pdo->query("SHOW COLUMNS FROM users LIKE 'profile_photo_url'")->fetch(PDO::FETCH_ASSOC);
            if (!$photoColumn) {
                $pdo->exec('ALTER TABLE users ADD COLUMN profile_photo_url VARCHAR(1024) DEFAULT NULL AFTER is_approved');
            }
        },
    ],
];

if ($upgradeReady) {
    try {
        upgrade_ensure_schema_version_table($pdo);
        $appliedVersions = upgrade_applied_versions($pdo);

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'download_backup') {
            $databaseName = trim((string)($config['db_booking']['dbname'] ?? ''));
            upgrade_stream_database_backup($pdo, $databaseName !== '' ? $databaseName : 'kitgrab', layout_app_name($config));
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'run_upgrade') {
            foreach ($migrations as $migration) {
                $isApplied = $migration['is_applied'];
                if ($isApplied($pdo, $appliedVersions)) {
                    continue;
                }

                $runner = $migration['run'];
                $runner($pdo);

                $stmt = $pdo->prepare(
                    'INSERT INTO schema_version (version) VALUES (:version)
                     ON DUPLICATE KEY UPDATE applied_at = CURRENT_TIMESTAMP'
                );
                $stmt->execute([':version' => $migration['version']]);
                $appliedVersions[$migration['version']] = true;
                $messages[] = 'Applied: ' . $migration['label'] . '.';
            }

            if (empty($messages)) {
                $messages[] = 'No schema updates were needed.';
            }

            $upgradeCompleted = true;
        }

        $currentSchemaVersion = upgrade_current_schema_version($pdo);
        foreach ($migrations as $migration) {
            $isApplied = $migration['is_applied'];
            if (!$isApplied($pdo, $appliedVersions)) {
                $pendingMigrations[] = $migration['label'];
            }
        }
    } catch (Throwable $e) {
        $errors[] = 'Upgrade failed: ' . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Upgrade – KitGrab</title>
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="../../assets/style.css">
    <?= layout_theme_styles() ?>
    <style>
        body { background: #f7f9fc; }
        .installer-page {
            max-width: 760px;
            margin: 0 auto;
        }
    </style>
</head>
<body class="p-4">
<div class="container installer-page">
    <div class="page-shell">
        <?= str_replace('href="index.php"', 'href="../../index.php"', layout_logo_tag()) ?>
        <div class="page-header">
            <h1>KitGrab Upgrader</h1>
            <div class="page-subtitle">
                Apply pending database updates for an existing installation.
            </div>
        </div>

        <?php if ($messages): ?>
            <div class="alert alert-success">
                <?= implode('<br>', array_map('upgrade_h', $messages)) ?>
            </div>
        <?php endif; ?>

        <?php if ($errors): ?>
            <div class="alert alert-danger">
                <?= implode('<br>', array_map('upgrade_h', $errors)) ?>
            </div>
        <?php endif; ?>

        <div class="card mb-3">
            <div class="card-body">
                <h5 class="card-title">Backup current booking database</h5>
                <p class="text-muted mb-3">
                    Download a `.sql` backup of the configured KitGrab booking database before applying upgrades.
                </p>
                <form method="post" class="mb-0">
                    <input type="hidden" name="action" value="download_backup">
                    <button class="btn btn-outline-secondary" type="submit" <?= !$upgradeReady ? 'disabled' : '' ?>>
                        Download SQL backup
                    </button>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <div class="small text-muted">Current schema version</div>
                        <div class="fw-semibold"><?= upgrade_h($currentSchemaVersion) ?></div>
                    </div>
                    <div class="col-md-6">
                        <div class="small text-muted">Target schema version</div>
                        <div class="fw-semibold"><?= upgrade_h($targetVersion) ?></div>
                    </div>
                </div>

                <?php if (!$upgradeReady): ?>
                    <p class="mb-0">Fix the issues above, then reload this page.</p>
                <?php elseif (empty($pendingMigrations)): ?>
                    <div class="alert alert-info mb-3">
                        This installation is already up to date.
                    </div>
                    <a class="btn btn-outline-secondary" href="../">Back to install tools</a>
                <?php else: ?>
                    <p class="mb-2">Pending updates:</p>
                    <ul class="mb-3">
                        <?php foreach ($pendingMigrations as $migrationLabel): ?>
                            <li><?= upgrade_h($migrationLabel) ?></li>
                        <?php endforeach; ?>
                    </ul>

                    <form method="post">
                        <input type="hidden" name="action" value="run_upgrade">
                        <div class="d-flex flex-wrap gap-2">
                            <button type="submit" class="btn btn-primary">Run upgrade</button>
                            <a class="btn btn-outline-secondary" href="../">Back to install tools</a>
                        </div>
                    </form>
                <?php endif; ?>

                <?php if ($upgradeCompleted): ?>
                    <div class="small text-muted mt-3">
                        Protect or remove the install and upgrade tools after use.
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php layout_footer(); ?>
</body>
</html>
