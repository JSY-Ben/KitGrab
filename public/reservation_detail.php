<?php
// reservation_detail.php
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/booking_helpers.php';
require_once SRC_PATH . '/staff_group_visibility.php';
require_once SRC_PATH . '/layout.php';

$isAdmin = !empty($currentUser['is_admin']);
$isStaff = !empty($currentUser['is_staff']) || $isAdmin;
$config = load_config();
$restrictReservationsToSameGroup = staff_group_visibility_restriction_enabled($config, $currentUser);

function display_date(?string $isoDate): string
{
    return app_format_date($isoDate);
}

function display_datetime(?string $isoDatetime): string
{
    return app_format_datetime($isoDatetime);
}

function reservation_detail_checkin_notes(PDO $pdo, string $assetCache): string
{
    $tags = [];
    foreach (preg_split('/,(?![^()]*\))/', $assetCache) ?: [] as $part) {
        $part = trim($part);
        if ($part === '' || strcasecmp($part, 'Pending checkout') === 0) { continue; }
        $open = strpos($part, ' (');
        $tag = trim($open === false ? $part : substr($part, 0, $open));
        if ($tag !== '') { $tags[$tag] = true; }
    }
    if (!$tags) { return ''; }
    try {
        $placeholders = implode(',', array_fill(0, count($tags), '?'));
        $stmt = $pdo->prepare("SELECT a.asset_tag, n.note, n.actor_name, n.created_at
            FROM assets a JOIN asset_notes n ON n.asset_id = a.id
            WHERE n.note_type = 'checkin' AND a.asset_tag IN ({$placeholders})
            ORDER BY a.asset_tag ASC, n.created_at DESC, n.id DESC");
        $stmt->execute(array_keys($tags));
        $latest = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $tag = (string)($row['asset_tag'] ?? '');
            $note = trim((string)($row['note'] ?? ''));
            if ($tag === '' || $note === '' || isset($latest[$tag])) { continue; }
            $meta = [];
            if (!empty($row['actor_name'])) { $meta[] = (string)$row['actor_name']; }
            if (!empty($row['created_at'])) { $meta[] = display_datetime((string)$row['created_at']); }
            $latest[$tag] = $tag . ($meta ? ' — ' . implode(', ', $meta) : '') . "\n" . $note;
        }
        return implode("\n\n", array_values($latest));
    } catch (Throwable $e) {
        return '';
    }
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    echo 'Invalid reservation ID.';
    exit;
}

// Load reservation
try {
    $stmt = $pdo->prepare("SELECT * FROM reservations WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $reservation = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    http_response_code(500);
    echo 'Error loading reservation: ' . htmlspecialchars($e->getMessage());
    exit;
}

if (!$reservation) {
    http_response_code(404);
    echo 'Reservation not found.';
    exit;
}

$ownsReservation = (string)($reservation['user_id'] ?? '') === (string)($currentUser['id'] ?? '')
    || strtolower(trim((string)($reservation['user_email'] ?? ''))) === strtolower(trim((string)($currentUser['email'] ?? '')));
if ((!$isStaff && !$ownsReservation) || ($isStaff && !staff_group_visibility_reservation_visible($reservation, $currentUser, $restrictReservationsToSameGroup))) {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

// Load items via shared helper
$items = get_reservation_items_with_names($pdo, $id);
$checkinNotes = $isStaff ? reservation_detail_checkin_notes($pdo, (string)($reservation['asset_name_cache'] ?? '')) : '';

$active  = $isStaff ? 'staff_reservations.php' : 'my_bookings.php';
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Booking #<?= (int)$id ?> – Details</title>

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
            <h1>Booking #<?= (int)$id ?> – Details</h1>
            <div class="page-subtitle">
                Full details for this booking.
            </div>
        </div>

        <!-- App navigation -->
        <?= layout_render_nav($active, $isStaff, $isAdmin) ?>

        <div class="top-bar mb-3">
            <div class="top-bar-user">
                Logged in as:
                <?= layout_user_identity($currentUser, true) ?>
            </div>
            <div class="top-bar-actions d-flex align-items-center gap-2">
                <?= layout_edit_profile_button($currentUser) ?>
                <a href="<?= $isStaff ? 'staff_reservations.php' : 'my_bookings.php' ?>" class="btn btn-outline-secondary btn-sm"><?= $isStaff ? 'Back to all bookings' : 'Back to My Reservations' ?></a>
                <a href="logout.php" class="btn btn-link btn-sm">Log out</a>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-body">
                <h5 class="card-title">Booking information</h5>
                <p class="card-text">
                    <strong>User Name:</strong>
                    <?= layout_user_identity_by_email((string)($reservation['user_name'] ?? 'Unknown'), (string)($reservation['user_email'] ?? ''), true) ?><br>

                    <strong>Start:</strong>
                    <?= display_datetime($reservation['start_datetime'] ?? '') ?><br>

                    <strong>End:</strong>
                    <?= display_datetime($reservation['end_datetime'] ?? '') ?><br>

                    <strong>Status:</strong>
                    <?= h($reservation['status'] ?? '') ?><br>

                    <?php if (!empty($reservation['asset_name_cache'])): ?>
                        <strong>Checked-out assets:</strong>
                        <?= h($reservation['asset_name_cache']) ?><br>
                    <?php endif; ?>
                    <?php if (!empty($reservation['reservation_note'])): ?>
                        <strong>Reservation note:</strong> <?= nl2br(h($reservation['reservation_note'])) ?><br>
                    <?php endif; ?>
                    <?php if ($isStaff && !empty($reservation['checkout_note'])): ?>
                        <strong>Checkout note:</strong> <?= nl2br(h($reservation['checkout_note'])) ?><br>
                    <?php endif; ?>
                    <?php if ($isStaff && $checkinNotes !== ''): ?>
                        <strong>Check-in notes:</strong><br><?= nl2br(h($checkinNotes)) ?><br>
                    <?php endif; ?>
                </p>
            </div>
        </div>

        <h5>Items reserved</h5>

        <?php if (empty($items)): ?>
            <div class="alert alert-info">
                No item records found for this booking.
            </div>
        <?php else: ?>
            <div class="table-responsive mb-3">
                <table class="table table-sm table-striped align-middle">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th style="width: 80px;">Quantity</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item): ?>
                            <tr>
                                <td><?= h($item['name'] ?? '') ?></td>
                                <td><?= (int)$item['qty'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <?php if ($isAdmin): ?><form method="post" action="delete_reservation.php"
              onsubmit="return confirm('Permanently delete this booking and all its history? This cannot be undone.');">
            <input type="hidden" name="reservation_id" value="<?= (int)$id ?>">
            <input type="hidden" name="force_delete" value="1">
            <?php if (strtolower((string)$reservation['status']) === 'completed'): ?>
                <input type="hidden" name="completed_delete_ack" value="1">
            <?php endif; ?>
            <button class="btn btn-outline-danger" type="submit">
                Delete this booking
            </button>
        </form><?php endif; ?>

    </div>
</div>
<?php layout_footer(); ?>
</body>
</html>
