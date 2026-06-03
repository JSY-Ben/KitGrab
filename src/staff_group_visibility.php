<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';

function staff_group_visibility_restriction_enabled(array $config, array $currentUser): bool
{
    $catalogueCfg = is_array($config['catalogue'] ?? null) ? $config['catalogue'] : [];

    return empty($currentUser['is_admin'])
        && !empty($catalogueCfg['restrict_checkout_reservations_to_same_group']);
}

function staff_group_visibility_local_user_id(array $user): int
{
    $id = (int)($user['id'] ?? 0);
    if ($id > 0) {
        return $id;
    }

    $email = strtolower(trim((string)($user['email'] ?? '')));
    if ($email === '') {
        return 0;
    }

    try {
        global $pdo;
        $stmt = $pdo->prepare('SELECT id FROM users WHERE LOWER(email) = :email LIMIT 1');
        $stmt->execute([':email' => $email]);
        return (int)($stmt->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        return 0;
    }
}

function staff_group_visibility_group_ids_for_user(array $user): array
{
    static $cache = [];

    $userId = staff_group_visibility_local_user_id($user);
    if ($userId <= 0) {
        return [];
    }
    if (array_key_exists($userId, $cache)) {
        return $cache[$userId];
    }

    try {
        global $pdo;
        $stmt = $pdo->prepare('
            SELECT group_id
              FROM user_group_members
             WHERE user_id = :user_id
        ');
        $stmt->execute([':user_id' => $userId]);

        $groupIds = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $groupId) {
            $groupId = (int)$groupId;
            if ($groupId > 0) {
                $groupIds[$groupId] = $groupId;
            }
        }

        $cache[$userId] = array_values($groupIds);
        return $cache[$userId];
    } catch (Throwable $e) {
        $cache[$userId] = [];
        return [];
    }
}

function staff_group_visibility_visible_user_emails_for_current_user(array $currentUser, bool $restrictionEnabled): ?array
{
    static $cache = [];

    if (!$restrictionEnabled) {
        return null;
    }

    $currentUserId = staff_group_visibility_local_user_id($currentUser);
    if ($currentUserId <= 0) {
        return [];
    }

    $groupIds = staff_group_visibility_group_ids_for_user($currentUser);
    if (empty($groupIds)) {
        return [];
    }

    sort($groupIds);
    $cacheKey = $currentUserId . '|' . implode(',', $groupIds);
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    try {
        global $pdo;
        $placeholders = implode(',', array_fill(0, count($groupIds), '?'));
        $stmt = $pdo->prepare("
            SELECT DISTINCT LOWER(u.email)
              FROM users u
              JOIN user_group_members ugm ON ugm.user_id = u.id
             WHERE ugm.group_id IN ({$placeholders})
               AND u.email <> ''
        ");
        $stmt->execute($groupIds);

        $emails = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $email) {
            $email = strtolower(trim((string)$email));
            if ($email !== '') {
                $emails[$email] = $email;
            }
        }

        $currentEmail = strtolower(trim((string)($currentUser['email'] ?? '')));
        if ($currentEmail !== '') {
            $emails[$currentEmail] = $currentEmail;
        }

        $cache[$cacheKey] = array_values($emails);
        sort($cache[$cacheKey]);
        return $cache[$cacheKey];
    } catch (Throwable $e) {
        return [];
    }
}

function staff_group_visibility_user_can_see_email(array $currentUser, string $targetEmail, bool $restrictionEnabled): bool
{
    if (!$restrictionEnabled) {
        return true;
    }

    $targetEmail = strtolower(trim($targetEmail));
    if ($targetEmail === '') {
        return false;
    }

    $visibleEmails = staff_group_visibility_visible_user_emails_for_current_user($currentUser, $restrictionEnabled);
    return is_array($visibleEmails) && in_array($targetEmail, $visibleEmails, true);
}

function staff_group_visibility_reservation_visible(array $reservation, array $currentUser, bool $restrictionEnabled): bool
{
    return staff_group_visibility_user_can_see_email(
        $currentUser,
        (string)($reservation['user_email'] ?? ''),
        $restrictionEnabled
    );
}

function staff_group_visibility_checked_out_row_visible(array $row, array $currentUser, bool $restrictionEnabled): bool
{
    return staff_group_visibility_user_can_see_email(
        $currentUser,
        staff_group_visibility_checked_out_row_email($row),
        $restrictionEnabled
    );
}

function staff_group_visibility_checked_out_row_email(array $row): string
{
    $assigned = $row['assigned_to'] ?? null;
    if (is_array($assigned)) {
        $email = trim((string)($assigned['email'] ?? ''));
        if ($email !== '') {
            return $email;
        }
    }

    foreach (['assigned_to_email', 'user_email', 'email'] as $key) {
        $email = trim((string)($row[$key] ?? ''));
        if ($email !== '') {
            return $email;
        }
    }

    return '';
}
