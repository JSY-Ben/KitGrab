<?php
require_once __DIR__ . '/bootstrap.php';

function group_tables_exist(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT 1 FROM user_groups LIMIT 1');
        $pdo->query('SELECT 1 FROM user_group_members LIMIT 1');
        return true;
    } catch (Throwable $e) {
        return false;
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
        SELECT id, name, description, created_at
          FROM user_groups
         ORDER BY name ASC
    ');

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function group_user_membership_map(PDO $pdo): array
{
    $stmt = $pdo->query('
        SELECT ugm.user_id, ug.id, ug.name
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
