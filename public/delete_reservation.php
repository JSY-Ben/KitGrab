<?php
// delete_reservation.php
// Deletes a reservation and its items (admins or the owning user).

require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/activity_log.php';
require_once SRC_PATH . '/staff_group_visibility.php';

$isAdmin       = !empty($currentUser['is_admin']);
$isStaff       = !empty($currentUser['is_staff']) || $isAdmin;
$currentUserId = (string)($currentUser['id'] ?? '');
$config        = load_config();
$restrictReservationsToSameGroup = staff_group_visibility_restriction_enabled($config, $currentUser);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Method not allowed.';
    exit;
}

$resId = isset($_POST['reservation_id']) ? (int)$_POST['reservation_id'] : 0;
if ($resId <= 0) {
    http_response_code(400);
    echo 'Invalid reservation ID.';
    exit;
}

// Load reservation to check ownership
$stmt = $pdo->prepare("
    SELECT id, user_id, user_email, status
    FROM reservations
    WHERE id = :id
    LIMIT 1
");
$stmt->execute([':id' => $resId]);
$reservation = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$reservation) {
    http_response_code(404);
    echo 'Reservation not found.';
    exit;
}

if (!$isStaff) {
    http_response_code(403);
    echo 'Users can cancel reservations but cannot permanently delete them.';
    exit;
}

if (!staff_group_visibility_reservation_visible($reservation, $currentUser, $restrictReservationsToSameGroup)) {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

$status = strtolower((string)($reservation['status'] ?? ''));
$forceDelete = $isAdmin && !empty($_POST['force_delete']);
if ($status === 'pending' && !$forceDelete) {
    $stmt = $pdo->prepare("UPDATE reservations SET status = 'cancelled' WHERE id = :id");
    $stmt->execute([':id' => $resId]);
    activity_log_event('reservation_cancelled', 'Reservation cancelled by staff', [
        'subject_type' => 'reservation', 'subject_id' => $resId,
    ]);
    header('Location: staff_reservations.php?cancelled=' . $resId);
    exit;
}
if (!$isAdmin) {
    http_response_code(403);
    echo 'Only administrators can permanently delete reservation history.';
    exit;
}
if ($status === 'completed' && empty($_POST['completed_delete_ack'])) {
    http_response_code(400);
    echo 'Completed reservations require confirmation because deleting them can make checkout history inconsistent.';
    exit;
}

try {
    $pdo->beginTransaction();

    // 🔴 ADJUST TABLE NAMES HERE IF NEEDED
    // First delete child items
    $stmt = $pdo->prepare("DELETE FROM reservation_items WHERE reservation_id = :id");
    $stmt->execute([':id' => $resId]);

    // Then delete the reservation itself
    $stmt = $pdo->prepare("DELETE FROM reservations WHERE id = :id");
    $stmt->execute([':id' => $resId]);

    $pdo->commit();

    activity_log_event('reservation_deleted', 'Reservation deleted', [
        'subject_type' => 'reservation',
        'subject_id'   => $resId,
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo 'Error deleting reservation: ' . htmlspecialchars($e->getMessage());
    exit;
}

// Redirect back with a “deleted” flag
$redirect = $isStaff
    ? 'staff_reservations.php?deleted=' . $resId
    : 'my_bookings.php?deleted=' . $resId;

header('Location: ' . $redirect);
exit;
