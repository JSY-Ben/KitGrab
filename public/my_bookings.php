<?php
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/booking_helpers.php';
require_once SRC_PATH . '/layout.php';

function display_date(?string $isoDate): string
{
    return app_format_date($isoDate);
}

function display_datetime(?string $isoDatetime): string
{
    return app_format_datetime($isoDatetime);
}

$active        = basename($_SERVER['PHP_SELF']);
$isAdmin       = !empty($currentUser['is_admin']);
$isStaff       = !empty($currentUser['is_staff']) || $isAdmin;
$currentUserId = (string)($currentUser['id'] ?? '');

$userName = trim(($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? ''));
$tabRaw = $_GET['tab'] ?? 'reservations';
$tab = $tabRaw === 'checked_out' ? 'checked_out' : 'reservations';
$qRaw = trim((string)($_GET['q'] ?? ''));
$fromRaw = trim((string)($_GET['from'] ?? ''));
$toRaw = trim((string)($_GET['to'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPageOptions = [10, 25, 50, 100];
$perPageRaw = (int)($_GET['per_page'] ?? 10);
$perPage = in_array($perPageRaw, $perPageOptions, true) ? $perPageRaw : 10;
$sortOptions = [
    'start_desc' => 'start_datetime DESC', 'start_asc' => 'start_datetime ASC',
    'end_desc' => 'end_datetime DESC', 'end_asc' => 'end_datetime ASC',
    'status_asc' => 'status ASC', 'status_desc' => 'status DESC',
    'id_desc' => 'id DESC', 'id_asc' => 'id ASC',
];
$sortRaw = trim((string)($_GET['sort'] ?? ''));
if ($sortRaw !== '' && isset($sortOptions[$sortRaw])) { $_SESSION['my_reservations_sort'] = $sortRaw; }
$sort = isset($sortOptions[$sortRaw]) ? $sortRaw : (string)($_SESSION['my_reservations_sort'] ?? 'start_desc');
if (!isset($sortOptions[$sort])) { $sort = 'start_desc'; }
$totalRows = 0; $totalPages = 1;

// Load this user's reservations
try {
    $where = ['user_id = :user_id'];
    $params = [':user_id' => $currentUserId];
    if ($qRaw !== '') {
        $where[] = "(CAST(id AS CHAR) LIKE :q_id OR asset_name_cache LIKE :q_assets OR reservation_note LIKE :q_note OR EXISTS (SELECT 1 FROM reservation_items ri WHERE ri.reservation_id = reservations.id AND ri.model_name_cache LIKE :q_item))";
        $like = '%' . $qRaw . '%';
        $params[':q_id'] = $like; $params[':q_assets'] = $like; $params[':q_note'] = $like; $params[':q_item'] = $like;
    }
    if ($fromRaw !== '') { $where[] = 'start_datetime >= :from_date'; $params[':from_date'] = $fromRaw . ' 00:00:00'; }
    if ($toRaw !== '') { $where[] = 'end_datetime <= :to_date'; $params[':to_date'] = $toRaw . ' 23:59:59'; }
    $whereSql = ' WHERE ' . implode(' AND ', $where);
    $count = $pdo->prepare('SELECT COUNT(*) FROM reservations' . $whereSql);
    $count->execute($params); $totalRows = (int)$count->fetchColumn();
    $totalPages = max(1, (int)ceil($totalRows / $perPage)); $page = min($page, $totalPages);
    $sql = 'SELECT * FROM reservations' . $whereSql . ' ORDER BY ' . $sortOptions[$sort] . ' LIMIT :limit OFFSET :offset';
    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) { $stmt->bindValue($key, $value); }
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
    $stmt->execute();
    $reservations = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $reservations = [];
    $loadError = $e->getMessage();
}

$checkedOutItems = [];
$checkedOutError = '';
if ($tab === 'checked_out') {
    try {
        $email = strtolower(trim($currentUser['email'] ?? ''));
        $username = strtolower(trim($currentUser['username'] ?? ''));
        $name = strtolower(trim($userName));

        $stmt = $pdo->prepare("
            SELECT checked_out_asset_cache.*, asset_models.image_url AS model_image_url
              FROM checked_out_asset_cache
              LEFT JOIN asset_models ON asset_models.id = checked_out_asset_cache.model_id
             WHERE (assigned_to_email IS NOT NULL AND LOWER(assigned_to_email) = :email)
                OR (assigned_to_username IS NOT NULL AND LOWER(assigned_to_username) = :username)
                OR (assigned_to_name IS NOT NULL AND LOWER(assigned_to_name) = :name)
             ORDER BY
                CASE WHEN expected_checkin IS NULL OR expected_checkin = '' THEN 1 ELSE 0 END,
                expected_checkin ASC,
                last_checkout DESC
        ");
        $stmt->execute([
            ':email' => $email,
            ':username' => $username,
            ':name' => $name,
        ]);
        $checkedOutItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $checkedOutItems = [];
        $checkedOutError = $e->getMessage();
    }
}

$deletedMsg = '';
if (!empty($_GET['deleted'])) {
    $deletedMsg = 'Reservation #' . (int)$_GET['deleted'] . ' has been deleted.';
} elseif (!empty($_GET['cancelled'])) {
    $deletedMsg = 'Reservation #' . (int)$_GET['cancelled'] . ' has been cancelled and retained in your history.';
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My Reservations</title>

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
            <h1>My Reservations</h1>
            <div class="page-subtitle">
                View all your past, current and future reservations.
            </div>
        </div>

        <!-- App navigation -->
        <?= layout_render_nav($active, $isStaff, $isAdmin) ?>

        <!-- Top bar -->
        <div class="top-bar mb-3">
            <div class="top-bar-user">
                Logged in as:
                <strong><?= h($userName) ?></strong>
                (<?= h($currentUser['email'] ?? '') ?>)
            </div>
            <div class="top-bar-actions">
                <a href="logout.php" class="btn btn-link btn-sm">Log out</a>
            </div>
        </div>

        <?php if (!empty($deletedMsg)): ?>
            <div class="alert alert-success">
                <?= htmlspecialchars($deletedMsg) ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($loadError ?? '')): ?>
            <div class="alert alert-danger">
                Error loading your reservations: <?= htmlspecialchars($loadError) ?>
            </div>
        <?php endif; ?>

        <?php
            $reservationsUrl = 'my_bookings.php?tab=reservations';
            $checkedOutUrl = 'my_bookings.php?tab=checked_out';
        ?>
        <ul class="nav nav-tabs reservations-subtabs mb-3">
            <li class="nav-item">
                <a class="nav-link <?= $tab === 'reservations' ? 'active' : '' ?>"
                   href="<?= h($reservationsUrl) ?>">My Reservations</a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $tab === 'checked_out' ? 'active' : '' ?>"
                   href="<?= h($checkedOutUrl) ?>">My Checked Out Items</a>
            </li>
        </ul>

        <?php if ($tab === 'checked_out'): ?>
            <?php if (!empty($checkedOutError)): ?>
                <div class="alert alert-danger">
                    Error loading checked-out items: <?= htmlspecialchars($checkedOutError) ?>
                </div>
            <?php elseif (empty($checkedOutItems)): ?>
                <div class="alert alert-info">
                    You don’t have any checked-out items right now.
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-striped align-middle">
                        <thead>
                            <tr>
                                <th>Image</th>
                                <th>Asset Tag</th>
                                <th>Name</th>
                                <th>Model</th>
                                <th>Assigned Since</th>
                                <th>Expected Check-in</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($checkedOutItems as $row): ?>
                                <tr>
                                    <td><?php if (!empty($row['model_image_url'])): ?><img src="<?= h($row['model_image_url']) ?>" alt="" class="item-thumbnail" loading="lazy"><?php endif; ?></td>
                                    <td><?= h($row['asset_tag'] ?? '') ?></td>
                                    <td><?= h($row['asset_name'] ?? '') ?></td>
                                    <td><?= h($row['model_name'] ?? '') ?></td>
                                    <td><?= h(display_datetime($row['last_checkout'] ?? '')) ?></td>
                                    <td><?= h(display_date($row['expected_checkin'] ?? '')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <form method="get" class="card card-body mb-3">
                <input type="hidden" name="tab" value="reservations">
                <div class="row g-2 align-items-end">
                    <div class="col-lg-4"><label class="form-label">Search</label><input class="form-control" name="q" value="<?= h($qRaw) ?>" placeholder="Reservation, item or note"></div>
                    <div class="col-sm-6 col-lg-2"><label class="form-label">From</label><input type="date" class="form-control" name="from" value="<?= h($fromRaw) ?>"></div>
                    <div class="col-sm-6 col-lg-2"><label class="form-label">To</label><input type="date" class="form-control" name="to" value="<?= h($toRaw) ?>"></div>
                    <div class="col-lg-2"><label class="form-label">Sort</label><select class="form-select" name="sort">
                        <option value="start_desc" <?= $sort === 'start_desc' ? 'selected' : '' ?>>Start ↓</option><option value="start_asc" <?= $sort === 'start_asc' ? 'selected' : '' ?>>Start ↑</option>
                        <option value="end_desc" <?= $sort === 'end_desc' ? 'selected' : '' ?>>End ↓</option><option value="end_asc" <?= $sort === 'end_asc' ? 'selected' : '' ?>>End ↑</option>
                        <option value="status_asc" <?= $sort === 'status_asc' ? 'selected' : '' ?>>Status ↑</option><option value="status_desc" <?= $sort === 'status_desc' ? 'selected' : '' ?>>Status ↓</option>
                    </select></div>
                    <div class="col-lg-2 d-flex gap-2"><button class="btn btn-primary flex-fill">Apply</button><a class="btn btn-outline-secondary" href="my_bookings.php">Reset</a></div>
                </div>
            </form>
            <div class="d-flex flex-wrap gap-2 mb-3" aria-label="Quick sorting">
                <span class="small text-muted align-self-center">Sort columns:</span>
                <?php foreach ([['Start','start_asc','start_desc'],['End','end_asc','end_desc'],['Status','status_asc','status_desc'],['ID','id_asc','id_desc']] as $quickSort): ?>
                    <span class="btn-group btn-group-sm"><span class="btn btn-outline-secondary disabled"><?= h($quickSort[0]) ?></span><a class="btn btn-outline-secondary" href="?<?= h(http_build_query(['q'=>$qRaw,'from'=>$fromRaw,'to'=>$toRaw,'sort'=>$quickSort[1]])) ?>" aria-label="<?= h($quickSort[0]) ?> ascending">↑</a><a class="btn btn-outline-secondary" href="?<?= h(http_build_query(['q'=>$qRaw,'from'=>$fromRaw,'to'=>$toRaw,'sort'=>$quickSort[2]])) ?>" aria-label="<?= h($quickSort[0]) ?> descending">↓</a></span>
                <?php endforeach; ?>
            </div>
            <?php if (empty($reservations)): ?>
                <div class="alert alert-info">
                    You don’t have any reservations yet.
                </div>
            <?php else: ?>
                <div class="table-responsive">
                <table class="table table-striped align-middle">
                    <thead><tr><th>ID</th><th>Items</th><th>Start</th><th>End</th><th>Status</th><th>Notes</th><th>Actions</th></tr></thead>
                    <tbody>
                <?php foreach ($reservations as $res): ?>
                    <?php
                        $resId   = (int)$res['id'];
                        $items   = get_reservation_items_with_names($pdo, $resId);
                        $summary = build_items_summary_text($items);
                        $status  = strtolower((string)($res['status'] ?? ''));
                    ?>
                    <tr>
                        <td>#<?= $resId ?></td>
                        <td><?php if (!empty($items[0]['image'])): ?><img src="<?= h($items[0]['image']) ?>" alt="" class="item-thumbnail me-2" loading="lazy"><?php endif; ?><?= h($summary) ?></td>
                        <td><?= h(display_datetime($res['start_datetime'] ?? '')) ?></td>
                        <td><?= h(display_datetime($res['end_datetime'] ?? '')) ?></td>
                        <td><span class="badge text-bg-secondary"><?= h($res['status'] ?? '') ?></span></td>
                        <td><?php if (!empty($res['reservation_note'])): ?><details><summary class="btn btn-sm btn-outline-secondary">View note</summary><div class="small mt-2"><?= nl2br(h($res['reservation_note'])) ?></div></details><?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
                        <td><div class="d-flex flex-wrap gap-2">
                                <?php if ($status === 'pending'): ?>
                                    <a href="reservation_edit.php?id=<?= $resId ?>&from=my_bookings"
                                       class="btn btn-outline-primary btn-sm btn-action">
                                        Edit
                                    </a>
                                <?php endif; ?>
                                <?php if ($status === 'pending'): ?>
                                <form method="post" action="cancel_reservation.php"
                                      onsubmit="return confirm('Cancel this reservation? It will remain in your history.');">
                                    <input type="hidden" name="reservation_id" value="<?= $resId ?>">
                                    <button type="submit" class="btn btn-outline-danger btn-sm">
                                        Cancel reservation
                                    </button>
                                </form>
                                <?php endif; ?>
                        </div></td>
                    </tr>
                <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php if ($totalPages > 1): ?><nav><ul class="pagination justify-content-center">
                    <?php for ($p = 1; $p <= $totalPages; $p++): $query = http_build_query(['q'=>$qRaw,'from'=>$fromRaw,'to'=>$toRaw,'sort'=>$sort,'per_page'=>$perPage,'page'=>$p]); ?>
                        <li class="page-item <?= $p === $page ? 'active' : '' ?>"><a class="page-link" href="my_bookings.php?<?= h($query) ?>"><?= $p ?></a></li>
                    <?php endfor; ?>
                </ul></nav><?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>

    </div>
</div>
<?php layout_footer(); ?>
</body>
</html>
