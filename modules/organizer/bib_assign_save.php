<?php
Auth::requireRole(['organizer', 'staff']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('organizer-events');
}
Csrf::requireValid();

$db = Database::connection();
$userId = Auth::id();
$role = Auth::role();

$eventId = (int) ($_POST['event_id'] ?? 0);

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
         WHERE e.id = :id AND es.user_id = :uid AND sp.permission_key = 'manage_bibs'"
    );
    $eventStmt->execute(['id' => $eventId, 'uid' => $userId]);
}
if (!$eventStmt->fetch()) {
    flash('error', 'Event not found or you do not have bib assignment access to it.');
    redirect($role === 'organizer' ? 'organizer-events' : 'staff-dashboard');
}

EventAccess::requireEventType($db, $eventId, 'fun-run', $role === 'organizer' ? 'organizer-events' : 'staff-dashboard');

$action = $_POST['bib_action'] ?? '';

function bibPrefix(?string $categoryName): string
{
    $clean = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $categoryName ?? ''));
    return $clean !== '' ? $clean : 'BIB';
}

if ($action === 'manual') {
    $registrationId = (int) ($_POST['registration_id'] ?? 0);
    $bibNumber = trim($_POST['bib_number'] ?? '');

    $regCheck = $db->prepare('SELECT id FROM registrations WHERE id = :id AND event_id = :eid AND status = "approved"');
    $regCheck->execute(['id' => $registrationId, 'eid' => $eventId]);
    if (!$regCheck->fetch()) {
        flash('error', 'Registration not found or not approved.');
        redirect('organizer-bib-assign', ['event_id' => $eventId]);
    }

    try {
        $stmt = $db->prepare('UPDATE registrations SET bib_number = :bib WHERE id = :id');
        $stmt->execute(['bib' => $bibNumber !== '' ? $bibNumber : null, 'id' => $registrationId]);
        Logger::log($userId, 'bib_assigned', 'Assigned bib "' . $bibNumber . '" to registration #' . $registrationId);
        flash('success', 'Bib number saved.');
    } catch (PDOException $e) {
        flash('error', 'That bib number is already assigned to someone else in this event.');
    }
} elseif ($action === 'auto_assign') {
    $regStmt = $db->prepare(
        "SELECT id, distance_category FROM registrations
         WHERE event_id = :eid AND status = 'approved' AND (bib_number IS NULL OR bib_number = '')
         ORDER BY distance_category, registered_at"
    );
    $regStmt->execute(['eid' => $eventId]);
    $pending = $regStmt->fetchAll();

    $counters = [];
    $maxStmt = $db->prepare("SELECT bib_number FROM registrations WHERE event_id = :eid AND bib_number LIKE :prefix");
    $updateStmt = $db->prepare('UPDATE registrations SET bib_number = :bib WHERE id = :id');
    $assigned = 0;

    foreach ($pending as $reg) {
        $prefix = bibPrefix($reg['distance_category']);
        if (!isset($counters[$prefix])) {
            $maxStmt->execute(['eid' => $eventId, 'prefix' => $prefix . '-%']);
            $highest = 0;
            foreach ($maxStmt->fetchAll() as $row) {
                if (preg_match('/-(\d+)$/', $row['bib_number'], $m)) {
                    $highest = max($highest, (int) $m[1]);
                }
            }
            $counters[$prefix] = $highest;
        }
        $counters[$prefix]++;
        $bib = $prefix . '-' . str_pad((string) $counters[$prefix], 3, '0', STR_PAD_LEFT);
        $updateStmt->execute(['bib' => $bib, 'id' => $reg['id']]);
        $assigned++;
    }

    Logger::log($userId, 'bib_auto_assigned', 'Auto-assigned ' . $assigned . ' bib number(s) for event #' . $eventId);
    flash('success', $assigned > 0 ? "Auto-assigned $assigned bib number(s)." : 'Everyone already has a bib number.');
}

redirect('organizer-bib-assign', ['event_id' => $eventId]);
