<?php
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/activity_log.php';
require_once SRC_PATH . '/staff_group_visibility.php';

$reservationId = (int)($_POST['reservation_id'] ?? 0);
$email         = strtolower(trim((string)($currentUser['email'] ?? '')));
$isAdmin       = !empty($currentUser['is_admin']);
$isStaff       = !empty($currentUser['is_staff']) || $isAdmin;

if (!$reservationId || (!$isStaff && $email === '')) {
    die('Invalid request.');
}

// Load reservation
$sql = "
    SELECT *
    FROM reservations
    WHERE id = :id
      AND status IN ('pending','confirmed')
    LIMIT 1
";
$stmt = $pdo->prepare($sql);
$stmt->execute([':id' => $reservationId]);
$res = $stmt->fetch();

if (!$res || (!$isStaff && strtolower((string)$res['user_email']) !== $email)) {
    die('Booking not found or cannot be cancelled.');
}
if ($isStaff) {
    $config = load_config();
    $restricted = staff_group_visibility_restriction_enabled($config, $currentUser);
    if (!staff_group_visibility_reservation_visible($res, $currentUser, $restricted)) {
        http_response_code(403);
        die('Access denied.');
    }
}

// Check that start time is still in the future
$start = new DateTime($res['start_datetime']);
$now   = new DateTime();

if ($start <= $now) {
    die('You cannot cancel a booking that has already started.');
}

// Update status to cancelled
$upd = $pdo->prepare("
    UPDATE reservations
    SET status = 'cancelled'
    WHERE id = :id
");
$upd->execute([':id' => $reservationId]);

activity_log_event('reservation_cancelled', 'Reservation cancelled', [
    'subject_type' => 'reservation',
    'subject_id'   => $reservationId,
    'metadata'     => [
        'email' => $email,
    ],
]);

header('Location: ' . ($isStaff ? 'staff_reservations.php?cancelled=' . $reservationId : 'my_bookings.php?cancelled=' . $reservationId));
exit;
