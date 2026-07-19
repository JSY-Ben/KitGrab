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
$errors   = [];

$editRoleOnly = false;
$groupsAvailable = false;
try {
    $groupsAvailable = group_tables_exist($pdo);
} catch (Throwable $e) {
    $groupsAvailable = false;
}

$exportType = $_GET['export'] ?? '';
if ($exportType === 'users') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="users.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['id', 'first_name', 'last_name', 'email', 'username', 'auth_source', 'is_approved', 'created_at']);
    $rows = $pdo->query('
        SELECT id, first_name, last_name, email, username, is_admin, is_staff, auth_source, is_approved, created_at
          FROM users
         ORDER BY first_name ASC, last_name ASC, email ASC
    ')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as $row) {
        fputcsv($out, [
            (int)$row['id'],
            $row['first_name'] ?? '',
            $row['last_name'] ?? '',
            $row['email'] ?? '',
            $row['username'] ?? '',
            $row['auth_source'] ?? 'local',
            !empty($row['is_approved']) ? '1' : '0',
            $row['created_at'] ?? '',
        ]);
    }
    fclose($out);
    exit;
}

$templateType = $_GET['template'] ?? '';
if ($templateType === 'users') {
    $path = APP_ROOT . '/templates/csv/users_template.csv';
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'save_user';

    if ($action === 'save_user') {
        $editId = (int)($_POST['user_id'] ?? 0);
        $email = strtolower(trim($_POST['email'] ?? ''));
        $firstName = trim($_POST['first_name'] ?? '');
        $lastName = trim($_POST['last_name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $selectedGroupIds = group_normalize_ids($_POST['group_ids'] ?? []);

        if ($email === '') {
            if ($editId > 0) {
                try {
                    $stmt = $pdo->prepare('SELECT email FROM users WHERE id = :id LIMIT 1');
                    $stmt->execute([':id' => $editId]);
                    $email = strtolower(trim((string)$stmt->fetchColumn()));
                } catch (Throwable $e) {
                    $email = '';
                }
            }
        }
        if ($email === '') {
            $errors[] = 'User email is required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'User email is not valid.';
        }

        if (empty($errors)) {
            try {
                $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email AND id <> :id LIMIT 1');
                $stmt->execute([
                    ':email' => $email,
                    ':id' => $editId,
                ]);
                if ($stmt->fetch()) {
                    throw new Exception('That email address is already in use.');
                }

                $existing = null;
                if ($editId > 0) {
                    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
                    $stmt->execute([':id' => $editId]);
                    $existing = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
                    if (!$existing) {
                        throw new Exception('User not found.');
                    }
                    if (!empty($existing['auth_source']) && $existing['auth_source'] !== 'local') {
                        $editRoleOnly = true;
                    }
                } elseif ($password === '') {
                    throw new Exception('Password is required for new users.');
                }

                $firstNameValue = $firstName !== '' ? $firstName : $email;
                $lastNameValue = $lastName !== '' ? $lastName : '';
                $usernameValue = $username !== '' ? $username : null;
                $passwordHash = $password !== ''
                    ? password_hash($password, PASSWORD_DEFAULT)
                    : ($existing['password_hash'] ?? null);
                $userId = sprintf('%u', crc32($email));

                if ($editId > 0) {
                    if ($editRoleOnly) {
                        if ($groupsAvailable) {
                            group_replace_user_memberships($pdo, $editId, $selectedGroupIds);
                        }
                        $messages[] = 'User groups updated.';
                    } else {
                        $stmt = $pdo->prepare("
                            UPDATE users
                               SET user_id = :user_id,
                                   first_name = :first_name,
                                   last_name = :last_name,
                                   email = :email,
                                   username = :username,
                                   password_hash = :password_hash,
                                   auth_source = 'local'
                             WHERE id = :id
                        ");
                        $stmt->execute([
                            ':user_id' => $userId,
                            ':first_name' => $firstNameValue,
                            ':last_name' => $lastNameValue,
                            ':email' => $email,
                            ':username' => $usernameValue,
                            ':password_hash' => $passwordHash,
                            ':id' => $editId,
                        ]);
                        if ($groupsAvailable) {
                            group_replace_user_memberships($pdo, $editId, $selectedGroupIds);
                        }
                        $messages[] = 'User updated.';
                    }
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO users (user_id, first_name, last_name, email, username, is_admin, is_staff, password_hash, auth_source, created_at)
                        VALUES (:user_id, :first_name, :last_name, :email, :username, :is_admin, :is_staff, :password_hash, 'local', NOW())
                    ");
                    $stmt->execute([
                        ':user_id' => $userId,
                        ':first_name' => $firstNameValue,
                        ':last_name' => $lastNameValue,
                        ':email' => $email,
                        ':username' => $usernameValue,
                        ':is_admin' => 0,
                        ':is_staff' => 0,
                        ':password_hash' => $passwordHash,
                    ]);
                    if ($groupsAvailable) {
                        group_replace_user_memberships($pdo, (int)$pdo->lastInsertId(), $selectedGroupIds);
                    }
                    $messages[] = 'User created.';
                }
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }
    } elseif ($action === 'approve_user') {
        $approveId = (int)($_POST['user_id'] ?? 0);
        if ($approveId <= 0) {
            $errors[] = 'Invalid user to approve.';
        } else {
            try {
                $stmt = $pdo->prepare('UPDATE users SET is_approved = 1 WHERE id = :id');
                $stmt->execute([':id' => $approveId]);
                $messages[] = $stmt->rowCount() > 0 ? 'User approved.' : 'User was already approved.';
            } catch (Throwable $e) {
                $errors[] = 'Approval failed: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'delete_user') {
        $deleteId = (int)($_POST['user_id'] ?? 0);
        if ($deleteId <= 0) {
            $errors[] = 'Invalid user to delete.';
        } elseif (!empty($currentUser['id']) && (int)$currentUser['id'] === $deleteId) {
            $errors[] = 'You cannot delete your own account.';
        } else {
            try {
                $stmt = $pdo->prepare('DELETE FROM users WHERE id = :id');
                $stmt->execute([':id' => $deleteId]);
                if ($stmt->rowCount() > 0) {
                    $messages[] = 'User deleted.';
                } else {
                    $errors[] = 'User not found.';
                }
            } catch (Throwable $e) {
                $errors[] = 'Delete failed: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'import_users') {
        $rows = $readCsvUpload('users_csv', $errors);
        if ($rows && !$errors) {
            $imported = 0;
            $rowErrors = [];
            foreach ($rows as $idx => $row) {
                $email = strtolower(trim($row['email'] ?? ''));
                $firstName = trim($row['first_name'] ?? '');
                $lastName = trim($row['last_name'] ?? '');
                $username = trim($row['username'] ?? '');
                $password = $row['password'] ?? '';
                if ($email === '') {
                    $rowErrors[] = 'Row ' . ($idx + 2) . ': email is required.';
                    continue;
                }
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $rowErrors[] = 'Row ' . ($idx + 2) . ': invalid email.';
                    continue;
                }
                try {
                    $stmt = $pdo->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
                    $stmt->execute([':email' => $email]);
                    $existing = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
                    $firstNameValue = $firstName !== '' ? $firstName : $email;
                    $lastNameValue = $lastName !== '' ? $lastName : '';
                    $usernameValue = $username !== '' ? $username : null;
                    if ($existing) {
                        $isExternal = !empty($existing['auth_source']) && $existing['auth_source'] !== 'local';
                        if (!$isExternal) {
                            $passwordHash = $password !== '' ? password_hash($password, PASSWORD_DEFAULT) : ($existing['password_hash'] ?? null);
                            $userId = sprintf('%u', crc32($email));
                            $stmt = $pdo->prepare("
                                UPDATE users
                                   SET user_id = :user_id,
                                       first_name = :first_name,
                                       last_name = :last_name,
                                       email = :email,
                                       username = :username,
                                       password_hash = :password_hash,
                                       auth_source = 'local'
                                 WHERE id = :id
                            ");
                            $stmt->execute([
                                ':user_id' => $userId,
                                ':first_name' => $firstNameValue,
                                ':last_name' => $lastNameValue,
                                ':email' => $email,
                                ':username' => $usernameValue,
                                ':password_hash' => $passwordHash,
                                ':id' => (int)$existing['id'],
                            ]);
                        }
                    } else {
                        if ($password === '') {
                            $rowErrors[] = 'Row ' . ($idx + 2) . ': password is required for new users.';
                            continue;
                        }
                        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                        $userId = sprintf('%u', crc32($email));
                        $stmt = $pdo->prepare("
                            INSERT INTO users (user_id, first_name, last_name, email, username, is_admin, is_staff, password_hash, auth_source, created_at)
                            VALUES (:user_id, :first_name, :last_name, :email, :username, :is_admin, :is_staff, :password_hash, 'local', NOW())
                        ");
                        $stmt->execute([
                            ':user_id' => $userId,
                            ':first_name' => $firstNameValue,
                            ':last_name' => $lastNameValue,
                            ':email' => $email,
                            ':username' => $usernameValue,
                            ':is_admin' => 0,
                            ':is_staff' => 0,
                            ':password_hash' => $passwordHash,
                        ]);
                    }
                    $imported++;
                } catch (Throwable $e) {
                    $rowErrors[] = 'Row ' . ($idx + 2) . ': ' . $e->getMessage();
                }
            }
            if ($rowErrors) {
                $errors[] = 'User import completed with errors: ' . implode(' | ', array_slice($rowErrors, 0, 5));
            }
            if ($imported > 0) {
                $messages[] = 'Users imported: ' . $imported . '.';
            }
        }
    }
}

$users = [];
$groups = [];
$userGroups = [];
try {
    $stmt = $pdo->query('
        SELECT id, first_name, last_name, email, username, is_admin, is_staff, auth_source, is_approved, created_at
          FROM users
         ORDER BY first_name ASC, last_name ASC, email ASC
    ');
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $errors[] = 'Could not load users: ' . $e->getMessage();
}

if ($groupsAvailable) {
    try {
        $groups = group_options($pdo);
        $userGroups = group_user_membership_map($pdo);
    } catch (Throwable $e) {
        $errors[] = 'Could not load groups: ' . $e->getMessage();
        $groups = [];
        $userGroups = [];
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin – Users</title>
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
            <h1>Users</h1>
            <div class="page-subtitle">
                Manage local user accounts and access levels.
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

        <div class="card">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-1">
                    <h5 class="card-title mb-0">All users</h5>
                    <div class="d-flex gap-2 flex-wrap">
                        <a class="btn btn-outline-secondary" href="users.php?export=users">Export CSV</a>
                        <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#importUsersModal">Import CSV</button>
                        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createUserModal">Create User</button>
                    </div>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-md-4">
                        <input type="text" class="form-control" id="users-filter" placeholder="Filter users...">
                    </div>
                    <div class="col-md-3">
                        <select class="form-select" id="users-sort">
                            <option value="first:asc">Sort by first name (A-Z)</option>
                            <option value="first:desc">Sort by first name (Z-A)</option>
                            <option value="last:asc">Sort by last name (A-Z)</option>
                            <option value="last:desc">Sort by last name (Z-A)</option>
                            <option value="email:asc">Sort by email (A-Z)</option>
                            <option value="email:desc">Sort by email (Z-A)</option>
                            <option value="role:asc">Sort by role (A-Z)</option>
                            <option value="role:desc">Sort by role (Z-A)</option>
                            <option value="source:asc">Sort by source (A-Z)</option>
                            <option value="source:desc">Sort by source (Z-A)</option>
                            <option value="groups:asc">Sort by groups (A-Z)</option>
                            <option value="groups:desc">Sort by groups (Z-A)</option>
                            <option value="created:desc">Sort by created (newest)</option>
                            <option value="created:asc">Sort by created (oldest)</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select class="form-select" id="users-role-filter">
                            <option value="">All roles</option>
                            <option value="admin">Admin</option>
                            <option value="checkout">Checkout user</option>
                            <option value="user">User</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select class="form-select" id="users-source-filter">
                            <option value="">All sources</option>
                            <option value="local">Local</option>
                            <option value="external">External</option>
                        </select>
                    </div>
                </div>
                <p class="text-muted small mb-3"><?= count($users) ?> total.</p>
                <?php if (empty($users)): ?>
                    <div class="text-muted small">No users found yet.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-striped align-middle">
                            <thead>
                                <tr>
                                    <th>First name</th>
                                    <th>Last name</th>
                                    <th>Email</th>
                                    <th>Username</th>
                                    <th>Role</th>
                                    <th>Source</th>
                                    <th>Status</th>
                                    <th>Groups</th>
                                    <th>Created</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody id="users-table">
                                <?php foreach ($users as $user): ?>
                                    <?php
                                    $memberships = $userGroups[(int)$user['id']] ?? [];
                                    $hasAdminGroup = false;
                                    $hasStaffGroup = false;
                                    foreach ($memberships as $membership) {
                                        $hasAdminGroup = $hasAdminGroup || !empty($membership['is_admin']);
                                        $hasStaffGroup = $hasStaffGroup || !empty($membership['is_staff']);
                                    }
                                    if (!$groupsAvailable) {
                                        $hasAdminGroup = !empty($user['is_admin']);
                                        $hasStaffGroup = !empty($user['is_staff']);
                                    }
                                    $roleLabel = $hasAdminGroup ? 'Admin' : ($hasStaffGroup ? 'Checkout user' : 'User');
                                    $roleValue = $hasAdminGroup ? 'admin' : ($hasStaffGroup ? 'checkout' : 'user');
                                    $sourceRaw = trim((string)($user['auth_source'] ?? ''));
                                    $sourceLabel = $sourceRaw !== '' ? ucfirst($sourceRaw) : 'Local';
                                    $sourceValue = $sourceRaw !== '' ? strtolower($sourceRaw) : 'local';
                                    $sourceValue = $sourceValue === 'local' ? 'local' : 'external';
                                    $createdAtRaw = $user['created_at'] ?? '';
                                    $createdAt = $createdAtRaw ? layout_format_datetime($createdAtRaw) : '';
                                    $createdAtSort = $createdAtRaw ? date('Y-m-d H:i:s', strtotime($createdAtRaw)) : '';
                                    $isRoleOnly = !empty($user['auth_source']) && $user['auth_source'] !== 'local';
                                    $groupLabels = array_map(static function (array $group): string {
                                        return (string)($group['name'] ?? '');
                                    }, $memberships);
                                    $groupLabelText = implode(', ', array_filter($groupLabels, 'strlen'));
                                    ?>
                                    <tr data-first="<?= h($user['first_name'] ?? '') ?>"
                                        data-last="<?= h($user['last_name'] ?? '') ?>"
                                        data-email="<?= h($user['email'] ?? '') ?>"
                                        data-username="<?= h($user['username'] ?? '') ?>"
                                        data-role="<?= h($roleValue) ?>"
                                        data-source="<?= h($sourceValue) ?>"
                                        data-groups="<?= h($groupLabelText) ?>"
                                        data-created="<?= h($createdAtSort) ?>">
                                        <td><?= h($user['first_name'] ?? '') ?></td>
                                        <td><?= h($user['last_name'] ?? '') ?></td>
                                        <td><?= h($user['email'] ?? '') ?></td>
                                        <td><?= h($user['username'] ?? '') ?></td>
                                        <td><?= h($roleLabel) ?></td>
                                        <td><?= h($sourceLabel) ?></td>
                                        <td>
                                            <?php if (!empty($user['is_approved'])): ?>
                                                <span class="badge text-bg-success">Approved</span>
                                            <?php else: ?>
                                                <span class="badge text-bg-warning">Pending approval</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= $groupLabelText !== '' ? h($groupLabelText) : '<span class="text-muted">None</span>' ?></td>
                                        <td><?= h($createdAt) ?></td>
                                        <td class="text-end">
                                            <?php if (empty($user['is_approved'])): ?>
                                                <form method="post" class="d-inline">
                                                    <input type="hidden" name="action" value="approve_user">
                                                    <input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-success">Approve</button>
                                                </form>
                                            <?php endif; ?>
                                            <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#editUserModal-<?= (int)$user['id'] ?>">Edit</button>
                                            <form method="post" class="d-inline" onsubmit="return confirm('Delete this user?');">
                                                <input type="hidden" name="action" value="delete_user">
                                                <input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>">
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
<div class="modal fade" id="createUserModal" tabindex="-1" aria-labelledby="createUserModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="post">
                <input type="hidden" name="action" value="save_user">
                <input type="hidden" name="user_id" value="0">
                <div class="modal-header">
                    <h5 class="modal-title" id="createUserModalLabel">Create user</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted small mb-3">Password is required for new users.</p>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-control" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">First name</label>
                            <input type="text" name="first_name" class="form-control" placeholder="First name">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Last name</label>
                            <input type="text" name="last_name" class="form-control" placeholder="Last name">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Username</label>
                            <input type="text" name="username" class="form-control" placeholder="username">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Password</label>
                            <input type="password" name="password" class="form-control" placeholder="Set a password" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Groups</label>
                            <?php if (!$groupsAvailable): ?>
                                <div class="text-muted small">Run the database upgrader to enable groups.</div>
                            <?php elseif (empty($groups)): ?>
                                <div class="text-muted small">No groups have been created yet.</div>
                            <?php else: ?>
                                <div class="row g-2">
                                    <?php foreach ($groups as $group): ?>
                                        <div class="col-md-4">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" name="group_ids[]" value="<?= (int)$group['id'] ?>" id="create_group_<?= (int)$group['id'] ?>">
                                                <label class="form-check-label" for="create_group_<?= (int)$group['id'] ?>"><?= h($group['name'] ?? '') ?></label>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create user</button>
                </div>
            </form>
        </div>
    </div>
</div>
<div class="modal fade" id="importUsersModal" tabindex="-1" aria-labelledby="importUsersModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="action" value="import_users">
                <div class="modal-header">
                    <h5 class="modal-title" id="importUsersModalLabel">Import users CSV</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted small mb-3">Columns: email, first_name, last_name, username, password (required for new users)</p>
                    <div class="mb-3">
                        <a class="btn btn-outline-secondary btn-sm" href="users.php?template=users">Download template CSV</a>
                    </div>
                    <input type="file" name="users_csv" class="form-control" accept=".csv,text/csv" required>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Import users</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php foreach ($users as $user): ?>
    <?php
    $roleOnly = !empty($user['auth_source']) && $user['auth_source'] !== 'local';
    $selectedUserGroupIds = array_map(static function (array $group): int {
        return (int)($group['id'] ?? 0);
    }, $userGroups[(int)$user['id']] ?? []);
    ?>
    <div class="modal fade" id="editUserModal-<?= (int)$user['id'] ?>" tabindex="-1" aria-labelledby="editUserModalLabel-<?= (int)$user['id'] ?>" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="post">
                    <input type="hidden" name="action" value="save_user">
                    <input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>">
                    <div class="modal-header">
                        <h5 class="modal-title" id="editUserModalLabel-<?= (int)$user['id'] ?>">Edit user</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p class="text-muted small mb-3">
                            <?= $roleOnly ? 'External users can only have groups updated here.' : 'Leave password blank to keep the existing password.' ?>
                        </p>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Email</label>
                                <input type="email" name="email" class="form-control" value="<?= h($user['email'] ?? '') ?>" required <?= $roleOnly ? 'disabled' : '' ?>>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">First name</label>
                                <input type="text" name="first_name" class="form-control" value="<?= h($user['first_name'] ?? '') ?>" placeholder="First name" <?= $roleOnly ? 'disabled' : '' ?>>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Last name</label>
                                <input type="text" name="last_name" class="form-control" value="<?= h($user['last_name'] ?? '') ?>" placeholder="Last name" <?= $roleOnly ? 'disabled' : '' ?>>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Username</label>
                                <input type="text" name="username" class="form-control" value="<?= h($user['username'] ?? '') ?>" placeholder="username" <?= $roleOnly ? 'disabled' : '' ?>>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Password</label>
                                <input type="password" name="password" class="form-control" placeholder="Set a password" <?= $roleOnly ? 'disabled' : '' ?>>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Groups</label>
                                <?php if (!$groupsAvailable): ?>
                                    <div class="text-muted small">Run the database upgrader to enable groups.</div>
                                <?php elseif (empty($groups)): ?>
                                    <div class="text-muted small">No groups have been created yet.</div>
                                <?php else: ?>
                                    <div class="row g-2">
                                        <?php foreach ($groups as $group): ?>
                                            <?php $groupId = (int)$group['id']; ?>
                                            <div class="col-md-4">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" name="group_ids[]" value="<?= $groupId ?>" id="edit_group_<?= (int)$user['id'] ?>_<?= $groupId ?>" <?= in_array($groupId, $selectedUserGroupIds, true) ? 'checked' : '' ?>>
                                                    <label class="form-check-label" for="edit_group_<?= (int)$user['id'] ?>_<?= $groupId ?>"><?= h($group['name'] ?? '') ?></label>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update user</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
<?php endforeach; ?>
<script>
    function wireUsersTable(config) {
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
            var av = (a.dataset[key] || '').toLowerCase();
            var bv = (b.dataset[key] || '').toLowerCase();
            if (av === bv) {
                return 0;
            }
            var result = av < bv ? -1 : 1;
            return dir === 'desc' ? -result : result;
        }

        function matchesFilters(row) {
            var query = input.value.trim().toLowerCase();
            if (query && row.textContent.toLowerCase().indexOf(query) === -1) {
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

    wireUsersTable({
        filterId: 'users-filter',
        sortId: 'users-sort',
        tableId: 'users-table',
        filterSelectIds: ['users-role-filter', 'users-source-filter'],
        filterPredicates: {
            'users-role-filter': function (row, value) {
                return (row.dataset.role || '') === value;
            },
            'users-source-filter': function (row, value) {
                return (row.dataset.source || '') === value;
            },
        },
    });
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
