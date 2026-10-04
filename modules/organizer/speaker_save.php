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
         WHERE e.id = :id AND es.user_id = :uid AND sp.permission_key = 'manage_speakers'"
    );
    $eventStmt->execute(['id' => $eventId, 'uid' => $userId]);
}
if (!$eventStmt->fetch()) {
    flash('error', 'Event not found or you do not have speaker management access to it.');
    redirect($role === 'organizer' ? 'organizer-events' : 'staff-dashboard');
}

EventAccess::requireEventType($db, $eventId, 'seminar-workshop', $role === 'organizer' ? 'organizer-events' : 'staff-dashboard');

$speakerAction = $_POST['speaker_action'] ?? 'create';

if ($speakerAction === 'delete') {
    $speakerId = (int) ($_POST['speaker_id'] ?? 0);
    $stmt = $db->prepare('DELETE FROM event_speakers WHERE id = :id AND event_id = :eid');
    $stmt->execute(['id' => $speakerId, 'eid' => $eventId]);
    Logger::log($userId, 'speaker_removed', 'Removed speaker #' . $speakerId . ' from event #' . $eventId);
    flash('success', 'Speaker removed.');
    redirect('organizer-speakers', ['event_id' => $eventId]);
}

$speakerName = trim($_POST['speaker_name'] ?? '');
$topic = trim($_POST['topic'] ?? '');
$bio = trim($_POST['speaker_bio'] ?? '');
$organization = trim($_POST['organization'] ?? '');
$position = trim($_POST['position'] ?? '');
$contactEmail = trim($_POST['contact_email'] ?? '');
$contactPhone = trim($_POST['contact_phone'] ?? '');
$socialLinks = trim($_POST['social_links'] ?? '');

if ($speakerName === '') {
    flash('error', 'Speaker name is required.');
    redirect('organizer-speakers', ['event_id' => $eventId]);
}
if ($contactEmail !== '' && !filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
    flash('error', 'Please provide a valid contact email.');
    redirect('organizer-speakers', ['event_id' => $eventId]);
}

$photoPath = null;
if (!empty($_FILES['photo']['name'])) {
    $photoPath = Upload::handle($_FILES['photo'], 'speakers', UPLOAD_ALLOWED_IMAGE);
    if (!$photoPath) {
        flash('error', 'Photo must be a JPG or PNG under 5MB.');
        redirect('organizer-speakers', ['event_id' => $eventId]);
    }
}

if ($speakerAction === 'update') {
    $speakerId = (int) ($_POST['speaker_id'] ?? 0);
    if ($photoPath !== null) {
        $stmt = $db->prepare(
            'UPDATE event_speakers SET speaker_name = :name, speaker_bio = :bio, topic = :topic,
                organization = :org, position = :pos, contact_email = :email, contact_phone = :phone,
                social_links = :social, photo_path = :photo
             WHERE id = :id AND event_id = :eid'
        );
        $stmt->execute([
            'name' => $speakerName, 'bio' => $bio ?: null, 'topic' => $topic ?: null,
            'org' => $organization ?: null, 'pos' => $position ?: null, 'email' => $contactEmail ?: null,
            'phone' => $contactPhone ?: null, 'social' => $socialLinks ?: null, 'photo' => $photoPath,
            'id' => $speakerId, 'eid' => $eventId,
        ]);
    } else {
        $stmt = $db->prepare(
            'UPDATE event_speakers SET speaker_name = :name, speaker_bio = :bio, topic = :topic,
                organization = :org, position = :pos, contact_email = :email, contact_phone = :phone, social_links = :social
             WHERE id = :id AND event_id = :eid'
        );
        $stmt->execute([
            'name' => $speakerName, 'bio' => $bio ?: null, 'topic' => $topic ?: null,
            'org' => $organization ?: null, 'pos' => $position ?: null, 'email' => $contactEmail ?: null,
            'phone' => $contactPhone ?: null, 'social' => $socialLinks ?: null,
            'id' => $speakerId, 'eid' => $eventId,
        ]);
    }
    Logger::log($userId, 'speaker_updated', 'Updated speaker #' . $speakerId . ' for event #' . $eventId);
    flash('success', 'Speaker updated.');
} else {
    $stmt = $db->prepare(
        'INSERT INTO event_speakers (event_id, speaker_name, speaker_bio, topic, organization, position, contact_email, contact_phone, social_links, photo_path)
         VALUES (:eid, :name, :bio, :topic, :org, :pos, :email, :phone, :social, :photo)'
    );
    $stmt->execute([
        'eid' => $eventId, 'name' => $speakerName, 'bio' => $bio ?: null, 'topic' => $topic ?: null,
        'org' => $organization ?: null, 'pos' => $position ?: null, 'email' => $contactEmail ?: null,
        'phone' => $contactPhone ?: null, 'social' => $socialLinks ?: null, 'photo' => $photoPath,
    ]);
    Logger::log($userId, 'speaker_added', 'Added speaker "' . $speakerName . '" to event #' . $eventId);
    flash('success', 'Speaker added.');
}

redirect('organizer-speakers', ['event_id' => $eventId]);
