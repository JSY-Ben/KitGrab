<?php
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/layout.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/group_helpers.php';

$active  = basename($_SERVER['PHP_SELF']);
$isAdmin = !empty($currentUser['is_admin']);
$isStaff = !empty($currentUser['is_staff']) || $isAdmin;

if (!$isAdmin) {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

$messages = [];
$errors = [];
$groupsAvailable = group_tables_exist($pdo);

$readCsvUpload = static function (string $field, array &$errors): array {
    if (empty($_FILES[$field]) || !is_array($_FILES[$field])) {
        $errors[] = 'CSV upload is required.';
        return [];
    }
    $file = $_FILES[$field];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $errors[] = 'CSV upload failed.';
        return [];
    }
    $handle = fopen($file['tmp_name'], 'r');
    if (!$handle) {
        $errors[] = 'Could not read uploaded CSV.';
        return [];
    }
    $header = fgetcsv($handle);
    if (!$header) {
        fclose($handle);
        $errors[] = 'CSV header row is missing.';
        return [];
    }
    $header = array_map(static function ($value) {
        $value = trim((string)$value);
        return strtolower(preg_replace('/^\xEF\xBB\xBF/', '', $value));
    }, $header);
    $rows = [];
    while (($row = fgetcsv($handle)) !== false) {
        if ($row === [null] || $row === false) {
            continue;
        }
        $row = array_pad($row, count($header), '');
        $rows[] = array_combine($header, $row);
    }
    fclose($handle);
    return $rows;
};

$parseGroupMemberEmails = static function (string $value): array {
    $emails = [];
    foreach (preg_split('/[;|,\n\r]+/', $value) ?: [] as $email) {
        $email = strtolower(trim($email));
        if ($email !== '') {
            $emails[$email] = $email;
        }
    }

    return array_values($emails);
};

$findUserIdsByEmails = static function (PDO $pdo, array $emails): array {
    $emails = array_values(array_unique(array_filter(array_map(static function ($email) {
        return strtolower(trim((string)$email));
    }, $emails), 'strlen')));
    if (empty($emails)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($emails), '?'));
    $stmt = $pdo->prepare("SELECT id, email FROM users WHERE LOWER(email) IN ($placeholders)");
    $stmt->execute($emails);

    $ids = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $ids[] = (int)$row['id'];
    }

    return $ids;
};

if ($groupsAvailable && ($_GET['export'] ?? '') === 'groups') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="groups.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['id', 'name', 'description', 'is_admin', 'is_staff', 'member_emails', 'created_at']);
    $rows = $pdo->query('
        SELECT ug.id,
               ug.name,
               ug.description,
               ug.is_admin,
               ug.is_staff,
               ug.created_at,
               COALESCE(member_exports.member_emails, \'\') AS member_emails
          FROM user_groups ug
          LEFT JOIN (
                SELECT ugm.group_id,
                       GROUP_CONCAT(u.email ORDER BY u.email SEPARATOR \';\') AS member_emails
                  FROM user_group_members ugm
                  JOIN users u ON u.id = ugm.user_id
                 WHERE u.email <> \'\'
                 GROUP BY ugm.group_id
          ) member_exports ON member_exports.group_id = ug.id
         ORDER BY ug.name ASC
    ')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as $row) {
        fputcsv($out, [
            (int)$row['id'],
            $row['name'] ?? '',
            $row['description'] ?? '',
            !empty($row['is_admin']) ? 1 : 0,
            (!empty($row['is_staff']) || !empty($row['is_admin'])) ? 1 : 0,
            $row['member_emails'] ?? '',
            $row['created_at'] ?? '',
        ]);
    }
    fclose($out);
    exit;
}

if (($_GET['template'] ?? '') === 'groups') {
    $path = APP_ROOT . '/templates/csv/groups_template.csv';
    if (is_file($path)) {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . basename($path) . '"');
        readfile($path);
        exit;
    }
    http_response_code(404);
    echo 'Template not found.';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'save_group';

    if (!$groupsAvailable) {
        $errors[] = 'Run the database upgrader to enable groups.';
    } elseif ($action === 'save_group') {
        $groupId = (int)($_POST['group_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $userIds = group_normalize_ids($_POST['user_ids'] ?? []);
        $isAdminFlag = isset($_POST['is_admin']);
        $isStaffFlag = isset($_POST['is_staff']) || $isAdminFlag;

        if ($name === '') {
            $errors[] = 'Group name is required.';
        }

        if (empty($errors)) {
            try {
                $stmt = $pdo->prepare('SELECT id FROM user_groups WHERE name = :name AND id <> :id LIMIT 1');
                $stmt->execute([
                    ':name' => $name,
                    ':id' => $groupId,
                ]);
                if ($stmt->fetch()) {
                    throw new Exception('That group name is already in use.');
                }

                if ($groupId > 0) {
                    $stmt = $pdo->prepare('
                        UPDATE user_groups
                           SET name = :name,
                               description = :description,
                               is_admin = :is_admin,
                               is_staff = :is_staff
                         WHERE id = :id
                    ');
                    $stmt->execute([
                        ':name' => $name,
                        ':description' => $description !== '' ? $description : null,
                        ':is_admin' => $isAdminFlag ? 1 : 0,
                        ':is_staff' => $isStaffFlag ? 1 : 0,
                        ':id' => $groupId,
                    ]);
                    if ($stmt->rowCount() === 0) {
                        $exists = $pdo->prepare('SELECT id FROM user_groups WHERE id = :id LIMIT 1');
                        $exists->execute([':id' => $groupId]);
                        if (!$exists->fetch()) {
                            throw new Exception('Group not found.');
                        }
                    }
                    group_replace_group_members($pdo, $groupId, $userIds);
                    $messages[] = 'Group updated.';
                } else {
                    $stmt = $pdo->prepare('
                        INSERT INTO user_groups (name, description, is_admin, is_staff, created_at)
                        VALUES (:name, :description, :is_admin, :is_staff, NOW())
                    ');
                    $stmt->execute([
                        ':name' => $name,
                        ':description' => $description !== '' ? $description : null,
                        ':is_admin' => $isAdminFlag ? 1 : 0,
                        ':is_staff' => $isStaffFlag ? 1 : 0,
                    ]);
                    $groupId = (int)$pdo->lastInsertId();
                    group_replace_group_members($pdo, $groupId, $userIds);
                    $messages[] = 'Group created.';
                }
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }
    } elseif ($action === 'delete_group') {
        $groupId = (int)($_POST['group_id'] ?? 0);
        if ($groupId <= 0) {
            $errors[] = 'Invalid group to delete.';
        } else {
            try {
                $stmt = $pdo->prepare('DELETE FROM user_groups WHERE id = :id');
                $stmt->execute([':id' => $groupId]);
                if ($stmt->rowCount() > 0) {
                    $messages[] = 'Group deleted.';
                } else {
                    $errors[] = 'Group not found.';
                }
            } catch (Throwable $e) {
                $errors[] = 'Delete failed: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'import_groups') {
        $rows = $readCsvUpload('groups_csv', $errors);
        if ($rows && !$errors) {
            $imported = 0;
            $rowErrors = [];
            foreach ($rows as $idx => $row) {
                $name = trim($row['name'] ?? '');
                $description = trim($row['description'] ?? '');
                $isAdminFlag = !empty($row['is_admin']) && (int)$row['is_admin'] === 1;
                $isStaffFlag = (!empty($row['is_staff']) && (int)$row['is_staff'] === 1) || $isAdminFlag;
                $hasMemberEmailsColumn = array_key_exists('member_emails', $row);
                $memberEmails = $hasMemberEmailsColumn
                    ? $parseGroupMemberEmails((string)($row['member_emails'] ?? ''))
                    : [];

                if ($name === '') {
                    $rowErrors[] = 'Row ' . ($idx + 2) . ': group name is required.';
                    continue;
                }

                try {
                    $stmt = $pdo->prepare('SELECT id FROM user_groups WHERE name = :name LIMIT 1');
                    $stmt->execute([':name' => $name]);
                    $groupId = (int)$stmt->fetchColumn();

                    if ($groupId > 0) {
                        $stmt = $pdo->prepare('
                            UPDATE user_groups
                               SET description = :description,
                                   is_admin = :is_admin,
                                   is_staff = :is_staff
                             WHERE id = :id
                        ');
                        $stmt->execute([
                            ':description' => $description !== '' ? $description : null,
                            ':is_admin' => $isAdminFlag ? 1 : 0,
                            ':is_staff' => $isStaffFlag ? 1 : 0,
                            ':id' => $groupId,
                        ]);
                    } else {
                        $stmt = $pdo->prepare('
                            INSERT INTO user_groups (name, description, is_admin, is_staff, created_at)
                            VALUES (:name, :description, :is_admin, :is_staff, NOW())
                        ');
                        $stmt->execute([
                            ':name' => $name,
                            ':description' => $description !== '' ? $description : null,
                            ':is_admin' => $isAdminFlag ? 1 : 0,
                            ':is_staff' => $isStaffFlag ? 1 : 0,
                        ]);
                        $groupId = (int)$pdo->lastInsertId();
                    }

                    if ($hasMemberEmailsColumn) {
                        $userIds = $findUserIdsByEmails($pdo, $memberEmails);
                        group_replace_group_members($pdo, $groupId, $userIds);
                        $missingCount = count($memberEmails) - count($userIds);
                        if ($missingCount > 0) {
                            $rowErrors[] = 'Row ' . ($idx + 2) . ': ' . $missingCount . ' member email(s) were not found.';
                        }
                    }
                    $imported++;
                } catch (Throwable $e) {
                    $rowErrors[] = 'Row ' . ($idx + 2) . ': ' . $e->getMessage();
                }
            }
            if ($rowErrors) {
                $errors[] = 'Group import completed with errors: ' . implode(' | ', array_slice($rowErrors, 0, 5));
            }
            if ($imported > 0) {
                $messages[] = 'Groups imported: ' . $imported . '.';
            }
        }
    }
}

$groups = [];
$users = [];
$usersById = [];
$groupMembers = [];

try {
    $stmt = $pdo->query('
        SELECT id, first_name, last_name, email, username, is_admin, is_staff
          FROM users
         ORDER BY first_name ASC, last_name ASC, email ASC
    ');
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($users as $user) {
        $usersById[(int)$user['id']] = $user;
    }
} catch (Throwable $e) {
    $errors[] = 'Could not load users: ' . $e->getMessage();
}

if ($groupsAvailable) {
    try {
        $stmt = $pdo->query('
            SELECT ug.id,
                   ug.name,
                   ug.description,
                   ug.is_admin,
                   ug.is_staff,
                   ug.created_at,
                   COALESCE(member_counts.member_count, 0) AS member_count
              FROM user_groups ug
              LEFT JOIN (
                    SELECT group_id, COUNT(user_id) AS member_count
                      FROM user_group_members
                     GROUP BY group_id
              ) member_counts ON member_counts.group_id = ug.id
             ORDER BY ug.name ASC
        ');
        $groups = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $stmt = $pdo->query('
            SELECT group_id, user_id
              FROM user_group_members
             ORDER BY group_id ASC, user_id ASC
        ');
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $groupId = (int)($row['group_id'] ?? 0);
            $userId = (int)($row['user_id'] ?? 0);
            if ($groupId <= 0 || $userId <= 0) {
                continue;
            }
            if (!isset($groupMembers[$groupId])) {
                $groupMembers[$groupId] = [];
            }
            $groupMembers[$groupId][] = $userId;
        }
    } catch (Throwable $e) {
        $errors[] = 'Could not load groups: ' . $e->getMessage();
    }
}

$renderUserPicker = static function (array $users, array $selectedUserIds, string $prefix): void {
    if (empty($users)) {
        echo '<div class="text-muted small">No users found yet.</div>';
        return;
    }

    $pickerUsers = [];
    foreach ($users as $user) {
        $userId = (int)$user['id'];
        $display = trim((string)($user['first_name'] ?? '') . ' ' . (string)($user['last_name'] ?? ''));
        if ($display === '') {
            $display = (string)($user['email'] ?? '');
        }
        $email = (string)($user['email'] ?? '');
        $pickerUsers[] = [
            'id' => $userId,
            'label' => $display,
            'email' => $email,
            'search' => strtolower(trim($display . ' ' . $email . ' ' . (string)($user['username'] ?? ''))),
        ];
    }

    $selectedUserIds = group_normalize_ids($selectedUserIds);
    $usersJson = htmlspecialchars(json_encode($pickerUsers, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8');
    $selectedJson = htmlspecialchars(json_encode($selectedUserIds, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8');

    echo '<div class="user-picker" data-user-picker data-users="' . $usersJson . '" data-selected="' . $selectedJson . '">';
    echo '<div class="user-picker__selected" data-user-picker-selected></div>';
    echo '<div class="position-relative">';
    echo '<input type="text" class="form-control" id="' . h($prefix . '_search') . '" data-user-picker-input placeholder="Search users by name, email, or username" autocomplete="off">';
    echo '<div class="user-picker__results list-group shadow-sm" data-user-picker-results hidden></div>';
    echo '</div>';
    echo '<div class="text-muted small mt-2" data-user-picker-empty>No users selected.</div>';
    echo '<div data-user-picker-inputs></div>';
    echo '</div>';
};
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin - Groups</title>
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/style.css">
    <?= layout_theme_styles() ?>
    <style>
        .user-picker__selected {
            display: flex;
            flex-wrap: wrap;
            gap: .5rem;
            margin-bottom: .75rem;
            min-height: 2rem;
        }
        .user-picker__badge {
            align-items: center;
            background: #eef2f7;
            border: 1px solid #d7dee8;
            border-radius: 999px;
            display: inline-flex;
            gap: .4rem;
            max-width: 100%;
            padding: .3rem .55rem;
        }
        .user-picker__badge-label {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .user-picker__remove {
            align-items: center;
            border-radius: 999px;
            display: inline-flex;
            height: 1.2rem;
            justify-content: center;
            line-height: 1;
            padding: 0;
            width: 1.2rem;
        }
        .user-picker__results {
            left: 0;
            max-height: 16rem;
            overflow-y: auto;
            position: absolute;
            right: 0;
            top: calc(100% + .25rem);
            z-index: 1060;
        }
    </style>
</head>
<body class="p-4">
<div class="container">
    <div class="page-shell">
        <?= layout_logo_tag() ?>
        <div class="page-header">
            <h1>Groups</h1>
            <div class="page-subtitle">
                Manage user groups and membership.
            </div>
        </div>

        <?= layout_render_nav($active, $isStaff, $isAdmin) ?>

        <div class="top-bar mb-3">
            <div class="top-bar-user">
                Logged in as:
                <strong><?= h(trim(($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? ''))) ?></strong>
                (<?= h($currentUser['email'] ?? '') ?>)
            </div>
            <div class="top-bar-actions">
                <a href="logout.php" class="btn btn-link btn-sm">Log out</a>
            </div>
        </div>

        <?php if ($messages): ?>
            <div class="alert alert-success">
                <?= implode('<br>', array_map('h', $messages)) ?>
            </div>
        <?php endif; ?>

        <?php if ($errors): ?>
            <div class="alert alert-danger">
                <?= implode('<br>', array_map('h', $errors)) ?>
            </div>
        <?php endif; ?>

        <?= layout_render_admin_tabs($active) ?>

        <?php if (!$groupsAvailable): ?>
            <div class="alert alert-warning">
                Groups require a database upgrade. Open the installer upgrader to apply pending schema updates.
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
                    <div>
                        <h5 class="card-title mb-0">All groups</h5>
                        <p class="text-muted small mb-0"><?= count($groups) ?> total.</p>
                    </div>
                    <div class="d-flex gap-2 flex-wrap">
                        <a class="btn btn-outline-secondary <?= !$groupsAvailable ? 'disabled' : '' ?>" href="groups.php?export=groups" <?= !$groupsAvailable ? 'aria-disabled="true"' : '' ?>>Export CSV</a>
                        <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#importGroupsModal" <?= !$groupsAvailable ? 'disabled' : '' ?>>Import CSV</button>
                        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createGroupModal" <?= !$groupsAvailable ? 'disabled' : '' ?>>Create Group</button>
                    </div>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-md-4">
                        <input type="text" class="form-control" id="groups-filter" placeholder="Filter groups...">
                    </div>
                    <div class="col-md-3">
                        <select class="form-select" id="groups-sort">
                            <option value="name:asc">Sort by name (A-Z)</option>
                            <option value="name:desc">Sort by name (Z-A)</option>
                            <option value="role:asc">Sort by role (A-Z)</option>
                            <option value="role:desc">Sort by role (Z-A)</option>
                            <option value="members:desc">Sort by members (most)</option>
                            <option value="members:asc">Sort by members (fewest)</option>
                            <option value="created:desc">Sort by created (newest)</option>
                            <option value="created:asc">Sort by created (oldest)</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select class="form-select" id="groups-role-filter">
                            <option value="">All roles</option>
                            <option value="admin">Admin</option>
                            <option value="checkout">Checkout user</option>
                            <option value="none">No role</option>
                        </select>
                    </div>
                </div>

                <?php if (empty($groups)): ?>
                    <div class="text-muted small">No groups found yet.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-striped align-middle">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Description</th>
                                    <th>Role</th>
                                    <th>Members</th>
                                    <th>Created</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody id="groups-table">
                                <?php foreach ($groups as $group): ?>
                                    <?php
                                    $groupId = (int)$group['id'];
                                    $createdAtRaw = $group['created_at'] ?? '';
                                    $createdAt = $createdAtRaw ? layout_format_datetime($createdAtRaw) : '';
                                    $createdAtSort = $createdAtRaw ? date('Y-m-d H:i:s', strtotime($createdAtRaw)) : '';
                                    $roleLabel = !empty($group['is_admin']) ? 'Admin' : (!empty($group['is_staff']) ? 'Checkout user' : 'None');
                                    $roleValue = !empty($group['is_admin']) ? 'admin' : (!empty($group['is_staff']) ? 'checkout' : 'none');
                                    $memberEmails = [];
                                    $memberNames = [];
                                    foreach ($groupMembers[$groupId] ?? [] as $memberUserId) {
                                        $memberUser = $usersById[(int)$memberUserId] ?? null;
                                        if (!$memberUser) {
                                            continue;
                                        }
                                        $memberEmail = trim((string)($memberUser['email'] ?? ''));
                                        $memberName = trim((string)($memberUser['first_name'] ?? '') . ' ' . (string)($memberUser['last_name'] ?? ''));
                                        if ($memberName === '') {
                                            $memberName = $memberEmail;
                                        }
                                        if ($memberEmail !== '') {
                                            $memberEmails[] = $memberEmail;
                                        }
                                        if ($memberName !== '') {
                                            $memberNames[] = $memberName;
                                        }
                                    }
                                    $memberSearchText = implode(' ', array_merge($memberNames, $memberEmails));
                                    $searchText = trim((string)($group['name'] ?? '') . ' ' . (string)($group['description'] ?? '') . ' ' . $roleLabel . ' ' . $memberSearchText);
                                    ?>
                                    <tr data-name="<?= h($group['name'] ?? '') ?>"
                                        data-role="<?= h($roleValue) ?>"
                                        data-members="<?= (int)($group['member_count'] ?? 0) ?>"
                                        data-created="<?= h($createdAtSort) ?>"
                                        data-search="<?= h($searchText) ?>">
                                        <td><?= h($group['name'] ?? '') ?></td>
                                        <td><?= h($group['description'] ?? '') ?></td>
                                        <td><?= $roleValue !== 'none' ? h($roleLabel) : '<span class="text-muted">None</span>' ?></td>
                                        <td><?= (int)($group['member_count'] ?? 0) ?></td>
                                        <td><?= h($createdAt) ?></td>
                                        <td class="text-end">
                                            <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#editGroupModal-<?= $groupId ?>">Edit</button>
                                            <form method="post" class="d-inline" onsubmit="return confirm('Delete this group?');">
                                                <input type="hidden" name="action" value="delete_group">
                                                <input type="hidden" name="group_id" value="<?= $groupId ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php layout_footer(); ?>
<div class="modal fade" id="importGroupsModal" tabindex="-1" aria-labelledby="importGroupsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="action" value="import_groups">
                <div class="modal-header">
                    <h5 class="modal-title" id="importGroupsModalLabel">Import groups CSV</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted small mb-3">Columns: name, description, is_admin, is_staff, member_emails. Separate multiple member emails with semicolons.</p>
                    <div class="mb-3">
                        <a class="btn btn-outline-secondary btn-sm" href="groups.php?template=groups">Download template CSV</a>
                    </div>
                    <input type="file" name="groups_csv" class="form-control" accept=".csv,text/csv" required>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Import groups</button>
                </div>
            </form>
        </div>
    </div>
</div>
<div class="modal fade" id="createGroupModal" tabindex="-1" aria-labelledby="createGroupModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="post">
                <input type="hidden" name="action" value="save_group">
                <input type="hidden" name="group_id" value="0">
                <div class="modal-header">
                    <h5 class="modal-title" id="createGroupModalLabel">Create group</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Name</label>
                            <input type="text" name="name" class="form-control" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Description</label>
                            <textarea name="description" class="form-control" rows="3"></textarea>
                        </div>
                        <div class="col-md-4">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_admin" id="create_group_is_admin">
                                <label class="form-check-label" for="create_group_is_admin">Admin</label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_staff" id="create_group_is_staff">
                                <label class="form-check-label" for="create_group_is_staff">Checkout user</label>
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Users</label>
                            <?php $renderUserPicker($users, [], 'create_group_user'); ?>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create group</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php foreach ($groups as $group): ?>
    <?php $groupId = (int)$group['id']; ?>
    <div class="modal fade" id="editGroupModal-<?= $groupId ?>" tabindex="-1" aria-labelledby="editGroupModalLabel-<?= $groupId ?>" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="post">
                    <input type="hidden" name="action" value="save_group">
                    <input type="hidden" name="group_id" value="<?= $groupId ?>">
                    <div class="modal-header">
                        <h5 class="modal-title" id="editGroupModalLabel-<?= $groupId ?>">Edit group</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Name</label>
                                <input type="text" name="name" class="form-control" value="<?= h($group['name'] ?? '') ?>" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Description</label>
                                <textarea name="description" class="form-control" rows="3"><?= h($group['description'] ?? '') ?></textarea>
                            </div>
                            <div class="col-md-4">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="is_admin" id="edit_group_is_admin_<?= $groupId ?>" <?= !empty($group['is_admin']) ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="edit_group_is_admin_<?= $groupId ?>">Admin</label>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="is_staff" id="edit_group_is_staff_<?= $groupId ?>" <?= (!empty($group['is_staff']) || !empty($group['is_admin'])) ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="edit_group_is_staff_<?= $groupId ?>">Checkout user</label>
                                </div>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Users</label>
                                <?php $renderUserPicker($users, $groupMembers[$groupId] ?? [], 'edit_group_' . $groupId . '_user'); ?>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update group</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
<?php endforeach; ?>
<script>
    function wireGroupsTable(config) {
        var input = document.getElementById(config.filterId);
        var table = document.getElementById(config.tableId);
        var sortSelect = document.getElementById(config.sortId);
        var filterSelects = (config.filterSelectIds || []).map(function (id) {
            return document.getElementById(id);
        }).filter(Boolean);
        if (!input || !table) {
            return;
        }
        var rows = Array.from(table.querySelectorAll('tr'));

        function getSortParts() {
            var value = sortSelect && sortSelect.value ? sortSelect.value : '';
            var parts = value.split(':');
            return {
                key: parts[0] || '',
                dir: parts[1] || 'asc',
            };
        }

        function compareRows(a, b, key, dir) {
            var av = a.dataset[key] || '';
            var bv = b.dataset[key] || '';
            if (key === 'members') {
                av = parseInt(av, 10) || 0;
                bv = parseInt(bv, 10) || 0;
                if (av === bv) {
                    return 0;
                }
                return dir === 'desc' ? bv - av : av - bv;
            }

            av = av.toLowerCase();
            bv = bv.toLowerCase();
            if (av === bv) {
                return 0;
            }
            var result = av < bv ? -1 : 1;
            return dir === 'desc' ? -result : result;
        }

        function matchesFilters(row) {
            var query = input.value.trim().toLowerCase();
            var searchText = (row.dataset.search || row.textContent || '').toLowerCase();
            if (query && searchText.indexOf(query) === -1) {
                return false;
            }
            return filterSelects.every(function (select) {
                var value = select.value;
                if (value === '') {
                    return true;
                }
                return config.filterPredicates[select.id](row, value);
            });
        }

        function render() {
            var sort = getSortParts();
            var ordered = rows.slice();
            if (sort.key) {
                ordered.sort(function (a, b) {
                    return compareRows(a, b, sort.key, sort.dir);
                });
            }
            ordered.forEach(function (row) {
                row.style.display = matchesFilters(row) ? '' : 'none';
                table.appendChild(row);
            });
        }

        input.addEventListener('input', render);
        if (sortSelect) {
            sortSelect.addEventListener('change', render);
        }
        filterSelects.forEach(function (select) {
            select.addEventListener('change', render);
        });
        render();
    }

    wireGroupsTable({
        filterId: 'groups-filter',
        sortId: 'groups-sort',
        tableId: 'groups-table',
        filterSelectIds: ['groups-role-filter'],
        filterPredicates: {
            'groups-role-filter': function (row, value) {
                return (row.dataset.role || '') === value;
            },
        },
    });

    function wireUserPicker(picker) {
        var users = [];
        var selectedIds = [];
        try {
            users = JSON.parse(picker.dataset.users || '[]');
            selectedIds = JSON.parse(picker.dataset.selected || '[]');
        } catch (error) {
            users = [];
            selectedIds = [];
        }

        var selected = new Map();
        var usersById = new Map();
        var input = picker.querySelector('[data-user-picker-input]');
        var selectedWrap = picker.querySelector('[data-user-picker-selected]');
        var results = picker.querySelector('[data-user-picker-results]');
        var hiddenInputs = picker.querySelector('[data-user-picker-inputs]');
        var emptyText = picker.querySelector('[data-user-picker-empty]');
        var currentMatches = [];

        users.forEach(function (user) {
            usersById.set(String(user.id), user);
        });
        selectedIds.forEach(function (id) {
            var user = usersById.get(String(id));
            if (user) {
                selected.set(String(user.id), user);
            }
        });

        function clearResults() {
            results.hidden = true;
            results.innerHTML = '';
            currentMatches = [];
        }

        function renderSelected() {
            selectedWrap.innerHTML = '';
            hiddenInputs.innerHTML = '';
            emptyText.hidden = selected.size > 0;

            selected.forEach(function (user) {
                var badge = document.createElement('span');
                badge.className = 'user-picker__badge';

                var label = document.createElement('span');
                label.className = 'user-picker__badge-label';
                label.textContent = user.label + ' (' + user.email + ')';

                var remove = document.createElement('button');
                remove.type = 'button';
                remove.className = 'btn btn-sm btn-outline-secondary user-picker__remove';
                remove.setAttribute('aria-label', 'Remove ' + user.label);
                remove.innerHTML = '&times;';
                remove.addEventListener('click', function () {
                    selected.delete(String(user.id));
                    renderSelected();
                    renderResults();
                    input.focus();
                });

                var hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = 'user_ids[]';
                hidden.value = user.id;

                badge.appendChild(label);
                badge.appendChild(remove);
                selectedWrap.appendChild(badge);
                hiddenInputs.appendChild(hidden);
            });
        }

        function resultButton(user) {
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'list-group-item list-group-item-action';
            button.textContent = user.label + ' (' + user.email + ')';
            button.addEventListener('click', function () {
                selected.set(String(user.id), user);
                input.value = '';
                clearResults();
                renderSelected();
                input.focus();
            });
            return button;
        }

        function renderResults() {
            var query = input.value.trim().toLowerCase();
            results.innerHTML = '';

            if (query.length < 1) {
                clearResults();
                return;
            }

            var matches = users.filter(function (user) {
                return !selected.has(String(user.id)) && (user.search || '').indexOf(query) !== -1;
            }).slice(0, 8);
            currentMatches = matches;

            if (matches.length === 0) {
                var empty = document.createElement('div');
                empty.className = 'list-group-item text-muted small';
                empty.textContent = 'No matching users.';
                results.appendChild(empty);
            } else {
                matches.forEach(function (user) {
                    results.appendChild(resultButton(user));
                });
            }
            results.hidden = false;
        }

        input.addEventListener('input', renderResults);
        input.addEventListener('focus', renderResults);
        input.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                clearResults();
            } else if (event.key === 'Enter' && currentMatches.length > 0) {
                event.preventDefault();
                selected.set(String(currentMatches[0].id), currentMatches[0]);
                input.value = '';
                clearResults();
                renderSelected();
            }
        });
        document.addEventListener('click', function (event) {
            if (!picker.contains(event.target)) {
                clearResults();
            }
        });

        renderSelected();
    }

    document.querySelectorAll('[data-user-picker]').forEach(wireUserPicker);
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
