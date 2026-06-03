<?php

require_once __DIR__ . '/bootstrap.php';
require_once SRC_PATH . '/booking_helpers.php';
require_once SRC_PATH . '/group_helpers.php';

function catalogue_permissions_pdo(): PDO
{
    if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
        return $GLOBALS['pdo'];
    }

    global $pdo;
    require_once SRC_PATH . '/db.php';
    if (isset($pdo) && $pdo instanceof PDO) {
        $GLOBALS['pdo'] = $pdo;
        return $pdo;
    }

    throw new RuntimeException('Database connection is unavailable.');
}

function catalogue_permissions_last_db_error(?Throwable $error = null): string
{
    static $lastError = '';

    if ($error !== null) {
        $lastError = $error->getMessage();
    }

    return $lastError;
}

function catalogue_permissions_table_exists(bool $create = true): bool
{
    static $exists = null;

    if ($exists === true) {
        return true;
    }

    try {
        $pdo = catalogue_permissions_pdo();
        $pdo->query('SELECT 1 FROM catalogue_group_restrictions LIMIT 1');
        $exists = true;
        return true;
    } catch (Throwable $e) {
        catalogue_permissions_last_db_error($e);
        $exists = false;
    }

    if (!$create) {
        return false;
    }

    try {
        $pdo = catalogue_permissions_pdo();
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
                KEY idx_catalogue_group_restrictions_item (item_type, item_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $exists = true;
        return true;
    } catch (Throwable $e) {
        catalogue_permissions_last_db_error($e);
        $exists = false;
        return false;
    }
}

function catalogue_permissions_groups(): array
{
    $pdo = catalogue_permissions_pdo();
    return group_options($pdo);
}

function catalogue_permissions_user_group_ids(array $user): array
{
    $userId = (int)($user['id'] ?? 0);
    $email = strtolower(trim((string)($user['email'] ?? '')));

    try {
        $pdo = catalogue_permissions_pdo();
        if ($userId <= 0 && $email !== '') {
            $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
            $stmt->execute([':email' => $email]);
            $userId = (int)$stmt->fetchColumn();
        }

        if ($userId <= 0) {
            return [];
        }

        $stmt = $pdo->prepare('
            SELECT group_id
              FROM user_group_members
             WHERE user_id = :user_id
             ORDER BY group_id ASC
        ');
        $stmt->execute([':user_id' => $userId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    } catch (Throwable $e) {
        return [];
    }
}

function catalogue_permissions_denied_item_map_for_group(int $groupId): array
{
    if ($groupId <= 0 || !catalogue_permissions_table_exists()) {
        return [];
    }

    $pdo = catalogue_permissions_pdo();
    $stmt = $pdo->prepare("
        SELECT item_type, item_id
          FROM catalogue_group_restrictions
         WHERE group_id = :group_id
    ");
    $stmt->execute([':group_id' => $groupId]);

    $map = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $type = booking_normalize_item_type((string)($row['item_type'] ?? ''));
        $id = (int)($row['item_id'] ?? 0);
        if ($type !== 'model' || $id <= 0) {
            continue;
        }
        $map[$type . ':' . $id] = true;
    }

    return $map;
}

function catalogue_permissions_denied_item_map_for_groups(array $groupIds): array
{
    $groupIds = array_values(array_filter(array_unique(array_map('intval', $groupIds)), static function (int $id): bool {
        return $id > 0;
    }));

    if (empty($groupIds) || !catalogue_permissions_table_exists(false)) {
        return [];
    }

    $pdo = catalogue_permissions_pdo();
    $placeholders = implode(',', array_fill(0, count($groupIds), '?'));
    $stmt = $pdo->prepare("
        SELECT item_type, item_id
          FROM catalogue_group_restrictions
         WHERE group_id IN ({$placeholders})
    ");
    $stmt->execute($groupIds);

    $map = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $type = booking_normalize_item_type((string)($row['item_type'] ?? ''));
        $id = (int)($row['item_id'] ?? 0);
        if ($type !== 'model' || $id <= 0) {
            continue;
        }
        $map[$type . ':' . $id] = true;
    }

    return $map;
}

function catalogue_permissions_is_item_allowed(array $user, string $itemType, int $itemId): bool
{
    $itemType = booking_normalize_item_type($itemType);
    if ($itemType !== 'model' || $itemId <= 0) {
        return true;
    }

    $groupIds = catalogue_permissions_user_group_ids($user);
    if (empty($groupIds)) {
        return true;
    }

    $denied = catalogue_permissions_denied_item_map_for_groups($groupIds);
    return empty($denied[$itemType . ':' . $itemId]);
}

function catalogue_permissions_save_group_restrictions(int $groupId, string $groupName, array $catalogueItems, array $allowedKeys): void
{
    if ($groupId <= 0) {
        throw new InvalidArgumentException('Select a valid group.');
    }
    if (!catalogue_permissions_table_exists()) {
        $details = catalogue_permissions_last_db_error();
        throw new RuntimeException(
            'Catalogue permissions table is not available.'
            . ($details !== '' ? ' Database error: ' . $details : '')
        );
    }

    $allowedLookup = [];
    foreach ($allowedKeys as $key) {
        $key = trim((string)$key);
        if ($key !== '') {
            $allowedLookup[$key] = true;
        }
    }

    $pdo = catalogue_permissions_pdo();
    $pdo->beginTransaction();
    try {
        $delete = $pdo->prepare('DELETE FROM catalogue_group_restrictions WHERE group_id = :group_id');
        $delete->execute([':group_id' => $groupId]);

        $insert = $pdo->prepare("
            INSERT INTO catalogue_group_restrictions (
                group_id,
                group_name,
                item_type,
                item_id,
                item_name_cache,
                created_at,
                updated_at
            ) VALUES (
                :group_id,
                :group_name,
                :item_type,
                :item_id,
                :item_name_cache,
                NOW(),
                NOW()
            )
        ");

        foreach ($catalogueItems as $item) {
            $type = booking_normalize_item_type((string)($item['type'] ?? ''));
            $id = (int)($item['id'] ?? 0);
            $name = trim((string)($item['name'] ?? ''));
            if ($type !== 'model' || $id <= 0) {
                continue;
            }

            $key = $type . ':' . $id;
            if (isset($allowedLookup[$key])) {
                continue;
            }

            $insert->execute([
                ':group_id' => $groupId,
                ':group_name' => $groupName,
                ':item_type' => $type,
                ':item_id' => $id,
                ':item_name_cache' => $name,
            ]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function catalogue_permissions_bookable_items(): array
{
    try {
        $data = get_bookable_models(1, '', null, 'name_asc', 10000, [], true, []);
        $items = [];
        foreach (($data['rows'] ?? []) as $model) {
            $id = (int)($model['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $items[] = [
                'type' => 'model',
                'id' => $id,
                'name' => trim((string)($model['name'] ?? ('Model #' . $id))),
                'category' => trim((string)($model['category']['name'] ?? '')),
                'manufacturer' => trim((string)($model['manufacturer']['name'] ?? '')),
                'image_path' => trim((string)($model['image'] ?? '')),
            ];
        }

        return $items;
    } catch (Throwable $e) {
        return [];
    }
}
