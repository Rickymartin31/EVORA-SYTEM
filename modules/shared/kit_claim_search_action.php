<?php
Auth::requireRole(['organizer', 'staff']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('kit-claim-search');
}
Csrf::requireValid();

$db = Database::connection();
$userId = Auth::id();
$role = Auth::role();

$eventId = (int) ($_POST['event_id'] ?? 0);
$search = $_POST['search'] ?? '';

if ($role === 'organizer') {
    $orgStmt = $db->prepare('SELECT id FROM organizers WHERE user_id = :uid');
    $orgStmt->execute(['uid' => $userId]);
    $organizer = $orgStmt->fetch();
    $eventStmt = $db->prepare('SELECT id FROM events WHERE id = :id AND organizer_id = :oid');
    $eventStmt->execute(['id' => $eventId, 'oid' => $organizer['id'] ?? 0]);
} else {
    $eventStmt = $db->prepare(
        "SELECT e.id FROM events e
         JOIN event_staff es ON es.event_id = e.id
         JOIN staff_permissions sp ON sp.event_staff_id = es.id
         WHERE e.id = :id AND es.user_id = :uid AND sp.permission_key = 'manage_claims'"
    );
    $eventStmt->execute(['id' => $eventId, 'uid' => $userId]);
}
if (!$eventStmt->fetch()) {
    flash('error', 'Event not found or you do not have kit-claiming access to it.');
    redirect('kit-claim-search');
}

$registrationId = (int) ($_POST['registration_id'] ?? 0);
$itemId = (int) ($_POST['item_id'] ?? 0);

// Never trust posted registration_id/item_id beyond this: both must belong
// to this exact event.
$regStmt = $db->prepare(
    'SELECT r.*, u.full_name, COALESCE(rc.fee, e.fee) AS fee
     FROM registrations r
     JOIN participants p ON p.id = r.participant_id
     JOIN users u ON u.id = p.user_id
     JOIN events e ON e.id = r.event_id
     LEFT JOIN race_categories rc ON rc.id = r.race_category_id
     WHERE r.id = :rid AND r.event_id = :eid'
);
$regStmt->execute(['rid' => $registrationId, 'eid' => $eventId]);
$registration = $regStmt->fetch();

if (!$registration) {
    flash('error', 'Registration not found for this event.');
    redirect('kit-claim-search', ['event_id' => $eventId, 'search' => $search]);
}

if ($registration['status'] !== 'approved') {
    flash('error', 'Registration is not approved.');
    redirect('kit-claim-search', ['event_id' => $eventId, 'search' => $search]);
}

if ((float) $registration['fee'] > 0) {
    $payStmt = $db->prepare('SELECT status FROM payments WHERE registration_id = :id ORDER BY id DESC LIMIT 1');
    $payStmt->execute(['id' => $registrationId]);
    $payment = $payStmt->fetch();
    if (!$payment || $payment['status'] !== 'approved') {
        flash('error', 'Payment has not been approved for this registration.');
        redirect('kit-claim-search', ['event_id' => $eventId, 'search' => $search]);
    }
}

$itemStmt = $db->prepare('SELECT * FROM event_items WHERE id = :id AND event_id = :eid');
$itemStmt->execute(['id' => $itemId, 'eid' => $eventId]);
$item = $itemStmt->fetch();

if (!$item) {
    flash('error', 'Item not found for this event.');
    redirect('kit-claim-search', ['event_id' => $eventId, 'search' => $search]);
}

// v73: one item per group, so a participant cannot claim a second size of the same item
$alreadyClaimed = EventItemService::claimedInGroup($db, $registrationId, $item);
if ($alreadyClaimed) {
    flash('error', $registration['full_name'] . ' has already claimed "' . EventItemService::label($alreadyClaimed) . '".');
    redirect('kit-claim-search', ['event_id' => $eventId, 'search' => $search]);
}

$db->beginTransaction();
try {
    // Same atomic conditional decrement as the QR claim flow — closes the
    // race where two staff release the last unit at the same time.
    $update = $db->prepare('UPDATE event_items SET claimed_quantity = claimed_quantity + 1 WHERE id = :id AND claimed_quantity < total_quantity');
    $update->execute(['id' => $itemId]);

    if ($update->rowCount() === 0) {
        $db->rollBack();
        flash('error', 'No remaining stock for "' . $item['item_name'] . '".');
        redirect('kit-claim-search', ['event_id' => $eventId, 'search' => $search]);
    }

    $insert = $db->prepare('INSERT INTO item_claims (event_item_id, registration_id, staff_id) VALUES (:iid, :rid, :sid)');
    $insert->execute(['iid' => $itemId, 'rid' => $registrationId, 'sid' => $userId]);

    $db->commit();
} catch (PDOException $e) {
    $db->rollBack();
    if ($e->getCode() === '23000') {
        flash('error', $registration['full_name'] . ' has already claimed "' . $item['item_name'] . '".');
        redirect('kit-claim-search', ['event_id' => $eventId, 'search' => $search]);
    }
    flash('error', 'Something went wrong while releasing the item.');
    redirect('kit-claim-search', ['event_id' => $eventId, 'search' => $search]);
}

Logger::log($userId, 'item_claimed', $item['item_name'] . ' released to registration #' . $registrationId . ' via search');
flash('success', EventItemService::label($item) . ' released to ' . $registration['full_name'] . '.');
redirect('kit-claim-search', ['event_id' => $eventId, 'search' => $search]);
