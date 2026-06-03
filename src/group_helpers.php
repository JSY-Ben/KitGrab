<?php
require_once __DIR__ . '/bootstrap.php';

function group_tables_exist(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT id, is_admin, is_staff FROM user_groups LIMIT 1');
        $pdo->query('SELECT 1 FROM user_group_members LIMIT 1');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function group_user_roles(PDO $pdo, int $userId): array
{
    $roles = [
        'is_admin' => false,
        'is_staff' => false,
    ];

    if ($userId <= 0) {
        return $roles;
    }

    try {
        $stmt = $pdo->prepare('
            SELECT MAX(ug.is_admin) AS is_admin,
                   MAX(ug.is_staff) AS is_staff
              FROM user_group_members ugm
              JOIN user_groups ug ON ug.id = ugm.group_id
             WHERE ugm.user_id = :user_id
        ');
        $stmt->execute([':user_id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $roles['is_admin'] = !empty($row['is_admin']);
            $roles['is_staff'] = !empty($row['is_staff']) || $roles['is_admin'];
            return $roles;
        }
    } catch (Throwable $e) {
        // Fall back to legacy user columns when group-role columns are absent.
    }

    try {
        $stmt = $pdo->prepare('SELECT is_admin, is_staff FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $roles['is_admin'] = !empty($row['is_admin']);
            $roles['is_staff'] = !empty($row['is_staff']) || $roles['is_admin'];
        }
    } catch (Throwable $e) {
        // Ignore missing legacy columns on future schemas.
    }

    return $roles;
}

function group_role_notification_recipients(PDO $pdo, bool $includeCheckoutUsers, bool $includeAdministrators): array
{
    if (!$includeCheckoutUsers && !$includeAdministrators) {
        return [];
    }

    $clauses = [];
    if ($includeCheckoutUsers) {
        $clauses[] = 'ug.is_staff = 1';
    }
    if ($includeAdministrators) {
        $clauses[] = 'ug.is_admin = 1';
    }

    $sql = "
        SELECT DISTINCT u.first_name, u.last_name, u.email
          FROM users u
          JOIN user_group_members ugm ON ugm.user_id = u.id
          JOIN user_groups ug ON ug.id = ugm.group_id
         WHERE u.email <> ''
           AND (" . implode(' OR ', $clauses) . ")
         ORDER BY u.first_name ASC, u.last_name ASC, u.email ASC
    ";

    try {
        return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $legacyClauses = [];
        if ($includeCheckoutUsers) {
            $legacyClauses[] = 'is_staff = 1';
        }
        if ($includeAdministrators) {
            $legacyClauses[] = 'is_admin = 1';
        }

        $legacySql = "
            SELECT first_name, last_name, email
              FROM users
             WHERE email <> ''
               AND (" . implode(' OR ', $legacyClauses) . ")
             ORDER BY first_name ASC, last_name ASC, email ASC
        ";

        return $pdo->query($legacySql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

function group_normalize_ids(array $ids): array
{
    $normalized = [];
    foreach ($ids as $id) {
        $id = (int)$id;
        if ($id > 0) {
            $normalized[$id] = $id;
        }
    }

    return array_values($normalized);
}

function group_valid_ids(PDO $pdo, array $ids): array
{
    $ids = group_normalize_ids($ids);
    if (empty($ids)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT id FROM user_groups WHERE id IN ($placeholders)");
    $stmt->execute($ids);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
}

function group_options(PDO $pdo): array
{
    $stmt = $pdo->query('
        SELECT id, name, description, is_admin, is_staff, created_at
          FROM user_groups
         ORDER BY name ASC
    ');

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function group_user_membership_map(PDO $pdo): array
{
    $stmt = $pdo->query('
        SELECT ugm.user_id, ug.id, ug.name, ug.is_admin, ug.is_staff
          FROM user_group_members ugm
          JOIN user_groups ug ON ug.id = ugm.group_id
         ORDER BY ug.name ASC
    ');

    $map = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $userId = (int)($row['user_id'] ?? 0);
        $groupId = (int)($row['id'] ?? 0);
        if ($userId <= 0 || $groupId <= 0) {
            continue;
        }
        if (!isset($map[$userId])) {
            $map[$userId] = [];
        }
        $map[$userId][] = [
            'id' => $groupId,
            'name' => (string)($row['name'] ?? ''),
            'is_admin' => !empty($row['is_admin']),
            'is_staff' => !empty($row['is_staff']),
        ];
    }

    return $map;
}

function group_replace_user_memberships(PDO $pdo, int $userId, array $groupIds): void
{
    if ($userId <= 0) {
        return;
    }

    $groupIds = group_valid_ids($pdo, $groupIds);

    $stmt = $pdo->prepare('DELETE FROM user_group_members WHERE user_id = :user_id');
    $stmt->execute([':user_id' => $userId]);

    if (empty($groupIds)) {
        return;
    }

    $stmt = $pdo->prepare('
        INSERT INTO user_group_members (user_id, group_id)
        VALUES (:user_id, :group_id)
    ');
    foreach ($groupIds as $groupId) {
        $stmt->execute([
            ':user_id' => $userId,
            ':group_id' => (int)$groupId,
        ]);
    }
}

function group_replace_group_members(PDO $pdo, int $groupId, array $userIds): void
{
    if ($groupId <= 0) {
        return;
    }

    $userIds = group_normalize_ids($userIds);
    if (!empty($userIds)) {
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $stmt = $pdo->prepare("SELECT id FROM users WHERE id IN ($placeholders)");
        $stmt->execute($userIds);
        $userIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    $stmt = $pdo->prepare('DELETE FROM user_group_members WHERE group_id = :group_id');
    $stmt->execute([':group_id' => $groupId]);

    if (empty($userIds)) {
        return;
    }

    $stmt = $pdo->prepare('
        INSERT INTO user_group_members (user_id, group_id)
        VALUES (:user_id, :group_id)
    ');
    foreach ($userIds as $userId) {
        $stmt->execute([
            ':user_id' => (int)$userId,
            ':group_id' => $groupId,
        ]);
    }
}
