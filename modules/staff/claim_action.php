<?php
header('Content-Type: application/json');

if (!Auth::check() || Auth::role() !== 'staff') {
    http_response_code(403);
    echo json_encode(['valid' => false, 'message' => 'Unauthorized.']);
    exit;
}

if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
    echo json_encode(['valid' => false, 'message' => 'Invalid security token. Please refresh the page.']);
    exit;
}

$db = Database::connection();
$userId = Auth::id();
$token = trim($_POST['token'] ?? '');
$eventId = (int) ($_POST['event_id'] ?? 0);
$itemId = (int) ($_POST['item_id'] ?? 0);

$registrationId = QrService::verifyToken($token);
if ($registrationId === null) {
    echo json_encode(['valid' => false, 'message' => 'QR code signature is invalid or has been tampered with.']);
    exit;
}

$permCheck = $db->prepare(
    'SELECT 1 FROM event_staff es
     JOIN staff_permissions sp ON sp.event_staff_id = es.id
     WHERE es.user_id = :uid AND es.event_id = :eid AND sp.permission_key = "manage_claims"'
);
$permCheck->execute(['uid' => $userId, 'eid' => $eventId]);
if (!$permCheck->fetch()) {
    echo json_encode(['valid' => false, 'message' => 'You do not have item-claiming permission for this event.']);
    exit;
}

$regStmt = $db->prepare(
    'SELECT r.*, e.id AS reg_event_id, COALESCE(rc.fee, e.fee) AS fee, u.full_name
     FROM registrations r
     JOIN events e ON e.id = r.event_id
     JOIN participants p ON p.id = r.participant_id
     JOIN users u ON u.id = p.user_id
     LEFT JOIN race_categories rc ON rc.id = r.race_category_id
     WHERE r.id = :id'
);
$regStmt->execute(['id' => $registrationId]);
$registration = $regStmt->fetch();

if (!$registration || (int) $registration['reg_event_id'] !== $eventId) {
    echo json_encode(['valid' => false, 'message' => 'This QR code does not belong to this event.']);
    exit;
}

if ($registration['status'] !== 'approved') {
    echo json_encode(['valid' => false, 'message' => 'Registration is not approved.']);
    exit;
}

if ((float) $registration['fee'] > 0) {
    $payStmt = $db->prepare('SELECT status FROM payments WHERE registration_id = :id');
    $payStmt->execute(['id' => $registrationId]);
    $payment = $payStmt->fetch();
    if (!$payment || $payment['status'] !== 'approved') {
        echo json_encode(['valid' => false, 'message' => 'Payment has not been approved for this registration.']);
        exit;
    }
}

$itemStmt = $db->prepare('SELECT * FROM event_items WHERE id = :id AND event_id = :eid');
$itemStmt->execute(['id' => $itemId, 'eid' => $eventId]);
$item = $itemStmt->fetch();

if (!$item) {
    echo json_encode(['valid' => false, 'message' => 'Item not found for this event.']);
    exit;
}

if ($item['claimed_quantity'] >= $item['total_quantity']) {
    echo json_encode(['valid' => false, 'message' => 'No remaining stock for "' . $item['item_name'] . '".']);
    exit;
}

// v73: one item per group, so a participant cannot claim a second size of the same item
$alreadyClaimed = EventItemService::claimedInGroup($db, $registrationId, $item);
if ($alreadyClaimed) {
    echo json_encode(['valid' => false, 'message' => $registration['full_name'] . ' has already claimed "' . EventItemService::label($alreadyClaimed) . '".']);
    exit;
}

$db->beginTransaction();
try {
    // Atomic, conditional decrement — closes the race where two staff scan the
    // last unit at the same time (a plain SELECT-then-UPDATE could let both
    // through before either commits).
    $update = $db->prepare('UPDATE event_items SET claimed_quantity = claimed_quantity + 1 WHERE id = :id AND claimed_quantity < total_quantity');
    $update->execute(['id' => $itemId]);

    if ($update->rowCount() === 0) {
        $db->rollBack();
        echo json_encode(['valid' => false, 'message' => 'No remaining stock for "' . $item['item_name'] . '".']);
        exit;
    }

    $insert = $db->prepare('INSERT INTO item_claims (event_item_id, registration_id, staff_id) VALUES (:iid, :rid, :sid)');
    $insert->execute(['iid' => $itemId, 'rid' => $registrationId, 'sid' => $userId]);

    $db->commit();
} catch (PDOException $e) {
    $db->rollBack();
    if ($e->getCode() === '23000') {
        echo json_encode(['valid' => false, 'message' => $registration['full_name'] . ' has already claimed "' . $item['item_name'] . '".']);
        exit;
    }
    echo json_encode(['valid' => false, 'message' => 'Something went wrong while releasing the item.']);
    exit;
}

Logger::log($userId, 'item_claimed', $item['item_name'] . ' released to registration #' . $registrationId);
NotificationService::safely(function () use ($db, $registrationId, $item) {
    $owner = $db->prepare('SELECT p.user_id, e.name FROM registrations r JOIN participants p ON p.id = r.participant_id JOIN events e ON e.id = r.event_id WHERE r.id = :id');
    $owner->execute(['id' => $registrationId]);
    if ($row = $owner->fetch()) {
        notify((int) $row['user_id'], 'Item Released', '"' . EventItemService::label($item) . '" was released to you for "' . $row['name'] . '" at ' . date('g:i A') . '.', 'item_release',
            ['category' => 'event', 'notice_type' => 'status', 'reference_type' => 'registration', 'reference_id' => $registrationId]);
    }
});

echo json_encode([
    'valid' => true,
    // v74: the bib number, so staff hand out the right bib with the race kit
    'message' => EventItemService::label($item) . ' released to ' . $registration['full_name'] . (!empty($registration['bib_number']) ? ' · Bib ' . $registration['bib_number'] : '') . '.',
]);
