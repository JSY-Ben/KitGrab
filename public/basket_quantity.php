<?php
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/booking_helpers.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/inventory_client.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: basket.php');
    exit;
}

$modelId = isset($_POST['model_id']) ? (int)$_POST['model_id'] : 0;
$direction = trim((string)($_POST['direction'] ?? ''));

if ($modelId <= 0 || empty($_SESSION['basket']) || !is_array($_SESSION['basket'])) {
    header('Location: basket.php');
    exit;
}

$currentQty = isset($_SESSION['basket'][$modelId]) ? (int)$_SESSION['basket'][$modelId] : 0;
if ($currentQty < 1) {
    unset($_SESSION['basket'][$modelId]);
    header('Location: basket.php');
    exit;
}

if ($direction === 'down') {
    $_SESSION['basket'][$modelId] = max(1, $currentQty - 1);
    header('Location: basket.php');
    exit;
}

if ($direction === 'up') {
    $newQty = min(100, $currentQty + 1);
    $windowStartTs = null;
    $windowStart = '';
    $windowEnd = '';

    $startRaw = trim((string)($_SESSION['reservation_window_start'] ?? ''));
    $endRaw = trim((string)($_SESSION['reservation_window_end'] ?? ''));
    if ($startRaw !== '' && $endRaw !== '') {
        $startTs = strtotime($startRaw);
        $endTs = strtotime($endRaw);
        if ($startTs !== false && $endTs !== false && $endTs > $startTs) {
            $windowStartTs = (int)$startTs;
            $windowStart = date('Y-m-d H:i:s', $startTs);
            $windowEnd = date('Y-m-d H:i:s', $endTs);
        }
    }

    try {
        $maxQty = count_requestable_assets_by_model($modelId);
        $maxQty -= booking_count_effective_checked_out_assets($modelId, $config, $windowStartTs);

        if ($windowStart !== '' && $windowEnd !== '') {
            $stmt = $pdo->prepare("
                SELECT COALESCE(SUM(ri.quantity), 0) AS booked_qty
                FROM reservation_items ri
                JOIN reservations r ON r.id = ri.reservation_id
                WHERE ri.model_id = :model_id
                  AND r.status IN ('pending','confirmed')
                  AND (r.start_datetime < :end AND r.end_datetime > :start)
            ");
            $stmt->execute([
                ':model_id' => $modelId,
                ':start' => $windowStart,
                ':end' => $windowEnd,
            ]);
            $maxQty -= (int)($stmt->fetchColumn() ?: 0);
        }

        $maxQty = max(0, $maxQty);
    } catch (Throwable $e) {
        $maxQty = 0;
    }

    if ($maxQty <= $currentQty) {
        $newQty = $currentQty;
    } elseif ($newQty > $maxQty) {
        $newQty = $maxQty;
    }

    $_SESSION['basket'][$modelId] = max(1, $newQty);
}

header('Location: basket.php');
exit;
