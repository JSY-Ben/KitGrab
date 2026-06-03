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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'save_group';

    if (!$groupsAvailable) {
        $errors[] = 'Run the database upgrader to enable groups.';
    } elseif ($action === 'save_group') {
        $groupId = (int)($_POST['group_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $userIds = group_normalize_ids($_POST['user_ids'] ?? []);

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
                               description = :description
                         WHERE id = :id
                    ');
                    $stmt->execute([
                        ':name' => $name,
                        ':description' => $description !== '' ? $description : null,
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
                        INSERT INTO user_groups (name, description, created_at)
                        VALUES (:name, :description, NOW())
                    ');
                    $stmt->execute([
                        ':name' => $name,
                        ':description' => $description !== '' ? $description : null,
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
    }
}

$groups = [];
$users = [];
$groupMembers = [];

try {
    $stmt = $pdo->query('
        SELECT id, first_name, last_name, email, username, is_admin, is_staff
          FROM users
         ORDER BY first_name ASC, last_name ASC, email ASC
    ');
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $errors[] = 'Could not load users: ' . $e->getMessage();
}

if ($groupsAvailable) {
    try {
        $stmt = $pdo->query('
            SELECT ug.id,
                   ug.name,
                   ug.description,
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

$renderUserCheckboxes = static function (array $users, array $selectedUserIds, string $prefix): void {
    if (empty($users)) {
        echo '<div class="text-muted small">No users found yet.</div>';
        return;
    }

    echo '<div class="row g-2">';
    foreach ($users as $user) {
        $userId = (int)$user['id'];
        $display = trim((string)($user['first_name'] ?? '') . ' ' . (string)($user['last_name'] ?? ''));
        if ($display === '') {
            $display = (string)($user['email'] ?? '');
        }
        $fieldId = $prefix . '_' . $userId;
        echo '<div class="col-md-6"><div class="form-check">';
        echo '<input class="form-check-input" type="checkbox" name="user_ids[]" value="' . $userId . '" id="' . h($fieldId) . '"'
            . (in_array($userId, $selectedUserIds, true) ? ' checked' : '') . '>';
        echo '<label class="form-check-label" for="' . h($fieldId) . '">'
            . h($display) . ' <span class="text-muted">(' . h($user['email'] ?? '') . ')</span></label>';
        echo '</div></div>';
    }
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
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createGroupModal" <?= !$groupsAvailable ? 'disabled' : '' ?>>Create Group</button>
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
                                    <th>Members</th>
                                    <th>Created</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($groups as $group): ?>
                                    <?php
                                    $groupId = (int)$group['id'];
                                    $createdAtRaw = $group['created_at'] ?? '';
                                    $createdAt = $createdAtRaw ? layout_format_datetime($createdAtRaw) : '';
                                    ?>
                                    <tr>
                                        <td><?= h($group['name'] ?? '') ?></td>
                                        <td><?= h($group['description'] ?? '') ?></td>
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
                        <div class="col-12">
                            <label class="form-label">Users</label>
                            <?php $renderUserCheckboxes($users, [], 'create_group_user'); ?>
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
                            <div class="col-12">
                                <label class="form-label">Users</label>
                                <?php $renderUserCheckboxes($users, $groupMembers[$groupId] ?? [], 'edit_group_' . $groupId . '_user'); ?>
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
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
