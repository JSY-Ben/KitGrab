<?php

function inventory_asset_location_column_exists(PDO $pdo, bool $refresh = false): bool
{
    static $cache = [];

    $cacheKey = spl_object_id($pdo);
    if (!$refresh && array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM assets LIKE 'location'");
        $cache[$cacheKey] = (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $cache[$cacheKey] = false;
    }

    return $cache[$cacheKey];
}

function inventory_ensure_asset_location_column(PDO $pdo): void
{
    if (inventory_asset_location_column_exists($pdo)) {
        return;
    }

    try {
        $pdo->exec("ALTER TABLE assets ADD COLUMN location VARCHAR(255) DEFAULT NULL AFTER name");
    } catch (Throwable $e) {
        if (!inventory_asset_location_column_exists($pdo, true)) {
            throw $e;
        }

        return;
    }

    inventory_asset_location_column_exists($pdo, true);
}

function inventory_asset_location_select_sql(PDO $pdo, string $tableAlias = 'a'): string
{
    $alias = trim($tableAlias);
    if ($alias === '') {
        $alias = 'a';
    }

    if (inventory_asset_location_column_exists($pdo)) {
        return $alias . '.location AS location';
    }

    return 'NULL AS location';
}
