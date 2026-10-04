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
$action = $_POST['venue_action'] ?? '';

$eventStmt = $db->prepare('SELECT * FROM events WHERE id = :id AND organizer_id = :oid');
$eventStmt->execute(['id' => $eventId, 'oid' => $organizer['id'] ?? 0]);
$event = $eventStmt->fetch();

if (!$event) {
    flash('error', 'Event not found.');
    redirect('organizer-events');
}

EventAccess::requireEventType($db, $eventId, 'sports-tournament', 'organizer-events');

$validStatuses = ['available', 'maintenance', 'closed'];

if ($action === 'create') {
    $name = trim((string) ($_POST['name'] ?? ''));
    $code = trim((string) ($_POST['code'] ?? ''));
    $venueType = trim((string) ($_POST['venue_type'] ?? ''));
    $location = trim((string) ($_POST['location'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));
    $capacityRaw = trim((string) ($_POST['capacity'] ?? ''));
    $capacity = $capacityRaw === '' ? null : max(0, (int) $capacityRaw);
    $status = in_array($_POST['status'] ?? '', $validStatuses, true) ? $_POST['status'] : 'available';

    if ($name === '' || $venueType === '') {
        flash('error', 'Venue name and type are required.');
        redirect('organizer-venues', ['event_id' => $eventId]);
    }

    $ins = $db->prepare(
        'INSERT INTO venues (event_id, name, code, venue_type, location, description, capacity, status)
         VALUES (:eid, :name, :code, :vtype, :loc, :desc, :cap, :status)'
    );
    $ins->execute([
        'eid' => $eventId,
        'name' => $name,
        'code' => $code !== '' ? $code : null,
        'vtype' => $venueType,
        'loc' => $location !== '' ? $location : null,
        'desc' => $description !== '' ? $description : null,
        'cap' => $capacity,
        'status' => $status,
    ]);

    Logger::log($userId, 'venue_created', 'Venue "' . $name . '" added to event #' . $eventId);
    flash('success', 'Venue added.');
} elseif ($action === 'update') {
    $venueId = (int) ($_POST['venue_id'] ?? 0);
    $venueStmt = $db->prepare('SELECT id FROM venues WHERE id = :id AND event_id = :eid');
    $venueStmt->execute(['id' => $venueId, 'eid' => $eventId]);
    if (!$venueStmt->fetch()) {
        flash('error', 'Venue not found.');
        redirect('organizer-venues', ['event_id' => $eventId]);
    }

    $name = trim((string) ($_POST['name'] ?? ''));
    $code = trim((string) ($_POST['code'] ?? ''));
    $venueType = trim((string) ($_POST['venue_type'] ?? ''));
    $location = trim((string) ($_POST['location'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));
    $capacityRaw = trim((string) ($_POST['capacity'] ?? ''));
    $capacity = $capacityRaw === '' ? null : max(0, (int) $capacityRaw);
    $status = in_array($_POST['status'] ?? '', $validStatuses, true) ? $_POST['status'] : 'available';

    if ($name === '' || $venueType === '') {
        flash('error', 'Venue name and type are required.');
        redirect('organizer-venues', ['event_id' => $eventId, 'edit' => $venueId]);
    }

    $upd = $db->prepare(
        'UPDATE venues SET name = :name, code = :code, venue_type = :vtype, location = :loc,
            description = :desc, capacity = :cap, status = :status
         WHERE id = :id AND event_id = :eid'
    );
    $upd->execute([
        'name' => $name,
        'code' => $code !== '' ? $code : null,
        'vtype' => $venueType,
        'loc' => $location !== '' ? $location : null,
        'desc' => $description !== '' ? $description : null,
        'cap' => $capacity,
        'status' => $status,
        'id' => $venueId,
        'eid' => $eventId,
    ]);

    Logger::log($userId, 'venue_updated', 'Venue #' . $venueId . ' updated on event #' . $eventId);
    flash('success', 'Venue updated.');
} elseif ($action === 'delete') {
    $venueId = (int) ($_POST['venue_id'] ?? 0);
    $del = $db->prepare('DELETE FROM venues WHERE id = :id AND event_id = :eid');
    $del->execute(['id' => $venueId, 'eid' => $eventId]);

    Logger::log($userId, 'venue_deleted', 'Venue #' . $venueId . ' removed from event #' . $eventId);
    flash('success', 'Venue removed.');
} else {
    flash('error', 'Invalid action.');
}

redirect('organizer-venues', ['event_id' => $eventId]);
