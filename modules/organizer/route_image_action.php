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
$eventStmt = $db->prepare('SELECT * FROM events WHERE id = :id AND organizer_id = :oid');
$eventStmt->execute(['id' => $eventId, 'oid' => $organizer['id'] ?? 0]);
$event = $eventStmt->fetch();
if (!$event) {
    flash('error', 'Event not found.');
    redirect('organizer-events');
}

$action = $_POST['route_image_action'] ?? '';

if ($action === 'upload') {
    if (empty($_FILES['route_image']['name'])) {
        flash('error', 'Please choose a picture to upload.');
        redirect('organizer-checkpoints', ['event_id' => $eventId]);
    }

    $imagePath = Upload::handle($_FILES['route_image'], 'route_images', UPLOAD_ALLOWED_IMAGE);
    if (!$imagePath) {
        flash('error', 'Route picture must be a JPG or PNG under 5MB.');
        redirect('organizer-checkpoints', ['event_id' => $eventId]);
    }

    $stmt = $db->prepare('INSERT INTO event_route_images (event_id, image_path) VALUES (:eid, :path)');
    $stmt->execute(['eid' => $eventId, 'path' => $imagePath]);
    Logger::log($userId, 'route_image_uploaded', 'Uploaded a route picture for event #' . $eventId);

    $participantsStmt = $db->prepare(
        'SELECT u.id FROM registrations r
         JOIN participants p ON p.id = r.participant_id
         JOIN users u ON u.id = p.user_id
         WHERE r.event_id = :eid AND r.status = "approved"'
    );
    $participantsStmt->execute(['eid' => $eventId]);
    $participants = $participantsStmt->fetchAll();
    foreach ($participants as $p) {
        notify((int) $p['id'], 'Route Map Uploaded', 'The race route map for "' . $event['name'] . '" has been uploaded. Check it out before race day!', 'route_update');
    }

    Logger::log($userId, 'route_image_reminder_sent', 'Notified ' . count($participants) . ' participant(s) about a new route picture for event #' . $eventId);
    flash('success', 'Route picture uploaded and ' . count($participants) . ' participant(s) notified.');
} elseif ($action === 'delete') {
    $imageId = (int) ($_POST['image_id'] ?? 0);
    $stmt = $db->prepare('DELETE FROM event_route_images WHERE id = :id AND event_id = :eid');
    $stmt->execute(['id' => $imageId, 'eid' => $eventId]);
    flash('success', 'Route picture removed.');
}

redirect('organizer-checkpoints', ['event_id' => $eventId]);
