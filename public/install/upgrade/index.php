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

$targetVersion = '0.10.0 (Beta)';
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
        'version' => $targetVersion,
        'label' => 'Add the asset location column and mark the schema as current',
        'is_applied' => static function (PDO $pdo, array $appliedVersions) use ($targetVersion): bool {
            return isset($appliedVersions[$targetVersion]) && inventory_asset_location_column_exists($pdo);
        },
        'run' => static function (PDO $pdo): void {
            inventory_ensure_asset_location_column($pdo);
        },
    ],
];

if ($upgradeReady) {
    try {
        upgrade_ensure_schema_version_table($pdo);
        $appliedVersions = upgrade_applied_versions($pdo);

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
