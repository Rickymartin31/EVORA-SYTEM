<?php
Auth::requireRole('organizer');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('organizer-events');
}
Csrf::requireValid();

$db = Database::connection();
$userId = Auth::id();

$orgStmt = $db->prepare('SELECT id FROM organizers WHERE user_id = :uid');
$orgStmt->execute(['uid' => $userId]);
$organizer = $orgStmt->fetch();

$eventId = (int) ($_POST['event_id'] ?? 0);
$eventStmt = $db->prepare('SELECT id FROM events WHERE id = :id AND organizer_id = :oid');
$eventStmt->execute(['id' => $eventId, 'oid' => $organizer['id'] ?? 0]);
if (!$eventStmt->fetch()) {
    flash('error', 'Event not found.');
    redirect('organizer-events');
}

EventAccess::requireEventType($db, $eventId, 'fun-run', 'organizer-events');

$action = $_POST['race_category_action'] ?? '';

// A paid race category makes the event a paid event: it takes PayPal, paid directly to the
// organizer's confirmed PayPal Business account (no PayMongo setup or payout agreement needed).
// Events that are already paid are not blocked from editing their categories.
$paidStmt = $db->prepare('SELECT (e.fee > 0 OR EXISTS (SELECT 1 FROM race_categories rc WHERE rc.event_id = e.id AND rc.fee > 0)) FROM events e WHERE e.id = :id');
$paidStmt->execute(['id' => $eventId]);
$eventAlreadyPaid = (bool) $paidStmt->fetchColumn();
$paidCategoryBlocked = fn (float $fee): ?string => $fee <= 0 || $eventAlreadyPaid ? null
    : (!PAYPAL_ENABLED ? 'Paid race categories are not available right now — online payment (PayPal) is temporarily unavailable.'
        : (!PayPalAccountService::current($db, (int) $organizer['id']) ? 'Paid race categories need your PayPal Business account — connect it on your PayPal Account page (Finance → PayPal Account).' : null));

if ($action === 'create') {
    $name = trim($_POST['name'] ?? '');
    $fee = (float) ($_POST['fee'] ?? 0);
    $maxParticipants = max(1, (int) ($_POST['max_participants'] ?? 0));

    if ($name === '' || $fee < 0) {
        flash('error', 'Please provide a valid category name and fee.');
    } elseif ($blocked = $paidCategoryBlocked($fee)) {
        flash('error', $blocked);
    } else {
        $existing = $db->prepare('SELECT id FROM race_categories WHERE event_id = :eid AND name = :name');
        $existing->execute(['eid' => $eventId, 'name' => $name]);
        if ($existing->fetch()) {
            flash('error', 'A category with that name already exists for this event.');
        } else {
            $stmt = $db->prepare('INSERT INTO race_categories (event_id, name, fee, max_participants) VALUES (:eid, :name, :fee, :max)');
            $stmt->execute(['eid' => $eventId, 'name' => $name, 'fee' => $fee, 'max' => $maxParticipants]);
            Logger::log($userId, 'race_category_added', 'Added race category "' . $name . '" for event #' . $eventId);
            flash('success', 'Race category added.');
        }
    }
} elseif ($action === 'update') {
    $categoryId = (int) ($_POST['race_category_id'] ?? 0);
    $catCheck = $db->prepare('SELECT id FROM race_categories WHERE id = :id AND event_id = :eid');
    $catCheck->execute(['id' => $categoryId, 'eid' => $eventId]);
    if (!$catCheck->fetch()) {
        flash('error', 'Race category not found.');
        redirect('organizer-race-categories', ['event_id' => $eventId]);
    }

    $name = trim($_POST['name'] ?? '');
    $fee = (float) ($_POST['fee'] ?? 0);
    $maxParticipants = max(1, (int) ($_POST['max_participants'] ?? 0));

    if ($name === '' || $fee < 0) {
        flash('error', 'Please provide a valid category name and fee.');
    } elseif ($blocked = $paidCategoryBlocked($fee)) {
        flash('error', $blocked);
    } else {
        $existing = $db->prepare('SELECT id FROM race_categories WHERE event_id = :eid AND name = :name AND id != :id');
        $existing->execute(['eid' => $eventId, 'name' => $name, 'id' => $categoryId]);
        if ($existing->fetch()) {
            flash('error', 'A category with that name already exists for this event.');
        } else {
            $stmt = $db->prepare('UPDATE race_categories SET name = :name, fee = :fee, max_participants = :max WHERE id = :id AND event_id = :eid');
            $stmt->execute(['name' => $name, 'fee' => $fee, 'max' => $maxParticipants, 'id' => $categoryId, 'eid' => $eventId]);
            Logger::log($userId, 'race_category_updated', 'Updated race category "' . $name . '" for event #' . $eventId);
            flash('success', 'Race category updated.');
        }
    }
} elseif ($action === 'delete') {
    $categoryId = (int) ($_POST['race_category_id'] ?? 0);
    $stmt = $db->prepare('DELETE FROM race_categories WHERE id = :id AND event_id = :eid');
    $stmt->execute(['id' => $categoryId, 'eid' => $eventId]);
    flash('success', 'Race category removed.');
}

redirect('organizer-race-categories', ['event_id' => $eventId]);
