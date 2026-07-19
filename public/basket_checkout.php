<?php
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/activity_log.php';
require_once SRC_PATH . '/email.php';
require_once SRC_PATH . '/booking_helpers.php';
require_once SRC_PATH . '/reservation_policy.php';
require_once SRC_PATH . '/inventory_client.php';
require_once SRC_PATH . '/layout.php';

$config = load_config();
$userOverride = $_SESSION['booking_user_override'] ?? null;
$user   = $userOverride ?: $currentUser;
$basket = $_SESSION['basket'] ?? [];

if (empty($basket)) {
    die('Your basket is empty.');
}

$startRaw = $_POST['start_datetime'] ?? '';
$endRaw   = $_POST['end_datetime'] ?? '';
$reservationNote = trim((string)($_POST['reservation_note'] ?? ''));

if (!$startRaw || !$endRaw) {
    die('Start and end date/time are required.');
}

$startTs = strtotime($startRaw);
$endTs   = strtotime($endRaw);

if ($startTs === false || $endTs === false) {
    die('Invalid date/time.');
}

$start = date('Y-m-d H:i:s', $startTs);
$end   = date('Y-m-d H:i:s', $endTs);

if ($end <= $start) {
    die('End time must be after start time.');
}

$isAdmin = !empty($currentUser['is_admin']);
$isStaff = !empty($currentUser['is_staff']) || $isAdmin;
$overrideEmail = strtolower(trim((string)($userOverride['email'] ?? '')));
$currentEmail = strtolower(trim((string)($currentUser['email'] ?? '')));
$isOnBehalfBooking = is_array($userOverride) && $overrideEmail !== '' && $overrideEmail !== $currentEmail;

// Build user info from local inventory user record
$userName  = trim((string)($user['first_name'] ?? '') . ' ' . (string)($user['last_name'] ?? ''));
if ($userName === '') {
    $userName = trim((string)($user['name'] ?? ''));
}
if ($userName === '') {
    $userName = (string)($user['email'] ?? 'Unknown user');
}
$userEmail = (string)($user['email'] ?? '');
$userId    = (string)($user['id'] ?? ''); // local inventory user id

$reservationPolicy = reservation_policy_get($config);
$policyViolations = reservation_policy_validate_booking($pdo, $reservationPolicy, [
    'start_ts' => $startTs,
    'end_ts' => $endTs,
    'target_user_id' => $userId,
    'target_user_email' => $userEmail,
    'is_admin' => $isAdmin,
    'is_staff' => $isStaff,
    'is_on_behalf' => $isOnBehalfBooking,
]);
if (!empty($policyViolations)) {
    die('Could not create booking: ' . htmlspecialchars($policyViolations[0]));
}

$pdo->beginTransaction();

try {
    $models = [];
    $totalRequestedItems = 0;

    foreach ($basket as $modelId => $qty) {
        $modelId = (int)$modelId;
        $qty     = (int)$qty;

        if ($modelId <= 0 || $qty < 1) {
            throw new Exception('Invalid model/quantity in basket.');
        }

        $model = get_model($modelId);
        if (empty($model['id'])) {
            throw new Exception('Model not found in local inventory: ID ' . $modelId);
        }

        // How many units of this model are already booked for this time range?
        $sql = "
            SELECT COALESCE(SUM(ri.quantity), 0) AS booked_qty
            FROM reservation_items ri
            JOIN reservations r ON r.id = ri.reservation_id
            WHERE ri.model_id = :model_id
              AND r.status IN ('pending','confirmed')
              AND (r.start_datetime < :end AND r.end_datetime > :start)
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':model_id' => $modelId,
            ':start'    => $start,
            ':end'      => $end,
        ]);
        $row = $stmt->fetch();
        $existingBooked = $row ? (int)$row['booked_qty'] : 0;

        // Total requestable units in local inventory
        $totalRequestable = count_requestable_assets_by_model($modelId);
        $activeCheckedOut = booking_count_effective_checked_out_assets($modelId, $config, (int)$startTs);
        $availableNow = $totalRequestable > 0 ? max(0, $totalRequestable - $activeCheckedOut) : 0;

        if ($totalRequestable > 0 && $existingBooked + $qty > $availableNow) {
            throw new Exception(
                'Not enough units available for "' . ($model['name'] ?? ('ID '.$modelId)) . '" '
                . 'in that time period. Requested ' . $qty . ', already booked ' . $existingBooked
                . ', total available ' . $availableNow . '.'
            );
        }

        $models[] = [
            'model' => $model,
            'qty'   => $qty,
        ];
        $totalRequestedItems += $qty;
    }

    // Reservation header summary text
    if (!empty($models)) {
        $firstName = $models[0]['model']['name'] ?? 'Multiple models';
    } else {
        $firstName = 'Multiple models';
    }

    $label = $firstName;
    if ($totalRequestedItems > 1) {
        $label .= ' +' . ($totalRequestedItems - 1) . ' more item(s)';
    }

    $insertRes = $pdo->prepare("
        INSERT INTO reservations (
            user_name, user_email, user_id,
            asset_id, asset_name_cache,
            start_datetime, end_datetime, status, reservation_note
        ) VALUES (
            :user_name, :user_email, :user_id,
            0, :asset_name_cache,
            :start_datetime, :end_datetime, 'pending', :reservation_note
        )
    ");
    $insertRes->execute([
        ':user_name'        => $userName,
        ':user_email'       => $userEmail,
        ':user_id'          => $userId,
        ':asset_name_cache' => 'Pending checkout',
        ':start_datetime'   => $start,
        ':end_datetime'     => $end,
        ':reservation_note' => $reservationNote !== '' ? $reservationNote : null,
    ]);

    $reservationId = (int)$pdo->lastInsertId();

    // Insert reservation_items as model-level rows with quantity
    $insertItem = $pdo->prepare("
        INSERT INTO reservation_items (
            reservation_id, model_id, model_name_cache, quantity
        ) VALUES (
            :reservation_id, :model_id, :model_name_cache, :quantity
        )
    ");

    foreach ($models as $entry) {
        $model = $entry['model'];
        $qty   = (int)$entry['qty'];

        $insertItem->execute([
            ':reservation_id'   => $reservationId,
            ':model_id'         => (int)$model['id'],
            ':model_name_cache' => $model['name'] ?? ('Model #' . $model['id']),
            ':quantity'         => $qty,
        ]);
    }

    $pdo->commit();
    $_SESSION['basket'] = []; // clear basket

    activity_log_event('reservation_submitted', 'Reservation submitted', [
        'subject_type' => 'reservation',
        'subject_id'   => $reservationId,
        'metadata'     => [
            'items'     => $totalRequestedItems,
            'start'     => $start,
            'end'       => $end,
            'booked_for'=> $userEmail,
        ],
    ]);

    $appCfg = $config['app'] ?? [];
    $notifyEnabled = array_key_exists('notification_reservation_submitted_enabled', $appCfg)
        ? !empty($appCfg['notification_reservation_submitted_enabled'])
        : true;
    if ($notifyEnabled) {
        $sendUserDefault = array_key_exists('notification_reservation_submitted_send_user', $appCfg)
            ? !empty($appCfg['notification_reservation_submitted_send_user'])
            : true;
        $legacySendStaffDefault = array_key_exists('notification_reservation_submitted_send_staff', $appCfg)
            ? !empty($appCfg['notification_reservation_submitted_send_staff'])
            : true;
        $sendCheckoutUsersDefault = array_key_exists('notification_reservation_submitted_send_checkout_users', $appCfg)
            ? !empty($appCfg['notification_reservation_submitted_send_checkout_users'])
            : $legacySendStaffDefault;
        $sendAdminsDefault = array_key_exists('notification_reservation_submitted_send_admins', $appCfg)
            ? !empty($appCfg['notification_reservation_submitted_send_admins'])
            : $legacySendStaffDefault;

        $startDisplay = app_format_datetime($start, $config);
        $endDisplay = app_format_datetime($end, $config);
        $submittedByName = trim((string)($currentUser['first_name'] ?? '') . ' ' . (string)($currentUser['last_name'] ?? ''));
        $submittedByEmail = trim((string)($currentUser['email'] ?? ''));
        $submittedByDisplay = $submittedByName !== '' ? $submittedByName : ($submittedByEmail !== '' ? $submittedByEmail : 'Unknown user');
        if ($submittedByName !== '' && $submittedByEmail !== '' && strcasecmp($submittedByName, $submittedByEmail) !== 0) {
            $submittedByDisplay .= " ({$submittedByEmail})";
        }

        $bookedForDisplay = $userName !== '' ? $userName : ($userEmail !== '' ? $userEmail : 'Unknown user');
        if ($userName !== '' && $userEmail !== '' && strcasecmp($userName, $userEmail) !== 0) {
            $bookedForDisplay .= " ({$userEmail})";
        }

        $modelLabels = [];
        foreach ($models as $entry) {
            $modelName = trim((string)($entry['model']['name'] ?? 'Item'));
            if ($modelName === '') {
                $modelName = 'Item';
            }
            $qty = (int)($entry['qty'] ?? 0);
            $modelLabels[] = $qty > 1 ? ($modelName . " (x{$qty})") : $modelName;
        }
        $itemsSummary = !empty($modelLabels)
            ? implode(', ', $modelLabels)
            : ((int)$totalRequestedItems . ' item(s)');

        $userBody = [
            "Reservation #{$reservationId} has been submitted.",
            "Items: {$itemsSummary}",
            "Start: {$startDisplay}",
            "End: {$endDisplay}",
        ];
        if ($reservationNote !== '') { $userBody[] = "Reservation note: {$reservationNote}"; }
        if ($isOnBehalfBooking) {
            $userBody[] = "Submitted by: {$submittedByDisplay}";
        }

        $adminBody = [
            "Reservation #{$reservationId} has been submitted.",
            "Booked for: {$bookedForDisplay}",
            "Items: {$itemsSummary}",
            "Start: {$startDisplay}",
            "End: {$endDisplay}",
            "Submitted by: {$submittedByDisplay}",
        ];
        if ($reservationNote !== '') { $adminBody[] = "Reservation note: {$reservationNote}"; }

        $templateVariables = [
            'person_name' => $userName,
            'person_email' => $userEmail,
            'equipment_list' => $itemsSummary,
            'start_date' => $startDisplay,
            'return_date' => $endDisplay,
            'reservation_id' => (string)$reservationId,
            'reservation_link' => layout_reservation_detail_url($reservationId, $config),
            'my_reservations_link' => layout_my_reservations_url($config),
            'staff_reservations_link' => layout_staff_reservations_url($config),
            'staff_name' => $submittedByName !== '' ? $submittedByName : $submittedByEmail,
            'staff_email' => $submittedByEmail,
            'reservation_note' => $reservationNote,
        ];

        $notifiedEmails = [];
        if ($sendUserDefault && $userEmail !== '') {
            layout_send_notification(
                $userEmail,
                $userName !== '' ? $userName : $userEmail,
                'Reservation submitted',
                $userBody,
                $config,
                true,
                'reservation_submitted',
                $templateVariables
            );
            $notifiedEmails[] = $userEmail;
        }

        if ($sendCheckoutUsersDefault || $sendAdminsDefault) {
            $roleRecipients = layout_role_notification_recipients(
                $sendCheckoutUsersDefault,
                $sendAdminsDefault,
                $config,
                $notifiedEmails
            );
            foreach ($roleRecipients as $recipient) {
                layout_send_notification(
                    $recipient['email'],
                    $recipient['name'],
                    'New reservation submitted',
                    $adminBody,
                    $config,
                    true,
                    'reservation_submitted',
                    $templateVariables
                );
                $notifiedEmails[] = $recipient['email'];
            }
        }

        $extraRecipients = layout_extra_notification_recipients(
            (string)($appCfg['notification_reservation_submitted_extra_emails'] ?? ''),
            $notifiedEmails
        );
        foreach ($extraRecipients as $recipient) {
            layout_send_notification(
                $recipient['email'],
                $recipient['name'],
                'New reservation submitted',
                $adminBody,
                $config,
                true,
                'reservation_submitted',
                $templateVariables
            );
        }
    }

} catch (Exception $e) {
    $pdo->rollBack();
    die('Could not create booking: ' . htmlspecialchars($e->getMessage()));
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Booking submitted</title>
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
            <h1>Reservation submitted</h1>
            <div class="page-subtitle">Your reservation request has been recorded successfully.</div>
        </div>

        <?= layout_render_nav(
            'my_bookings.php',
            !empty($currentUser['is_staff']) || !empty($currentUser['is_admin']),
            !empty($currentUser['is_admin']),
            true
        ) ?>

        <div class="card mx-auto" style="max-width:720px">
            <div class="card-body p-4 p-md-5 text-center">
                <div class="alert alert-success mb-4" role="status">
                    <div class="fs-1 lh-1 mb-2" aria-hidden="true">&#10003;</div>
                    <h2 class="h4 mb-1">Thank you</h2>
                    <p class="mb-0">Your booking has been submitted.</p>
                </div>

                <dl class="row text-start mb-4">
                    <dt class="col-sm-4">Reservation</dt>
                    <dd class="col-sm-8">#<?= (int)$reservationId ?></dd>
                    <dt class="col-sm-4">Starts</dt>
                    <dd class="col-sm-8"><?= h(app_format_datetime($start)) ?></dd>
                    <dt class="col-sm-4">Returns</dt>
                    <dd class="col-sm-8 mb-0"><?= h(app_format_datetime($end)) ?></dd>
                </dl>

                <p class="text-muted mb-4">You can review the reservation and its current status from My Reservations.</p>

                <div class="d-flex flex-column flex-sm-row justify-content-center gap-2">
                    <a href="my_bookings.php" class="btn btn-primary">View my reservations</a>
                    <a href="catalogue.php" class="btn btn-outline-primary">Book more equipment</a>
                </div>
            </div>
        </div>
    </div>
</div>
<?php layout_footer(); ?>
</body>
</html>
