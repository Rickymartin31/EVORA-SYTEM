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

$action = $_POST['checkpoint_action'] ?? '';

$allowedTypes = ['start', 'finish', 'checkpoint', 'water_station', 'medical_station', 'emergency_point', 'turnaround_point'];

// v56: map position (both or neither) and pass radius. Returns null when the position is invalid.
$readPosition = function (): ?array {
    $lat = trim((string) ($_POST['lat'] ?? ''));
    $lng = trim((string) ($_POST['lng'] ?? ''));
    $radius = min(500, max(20, (int) ($_POST['radius_m'] ?? 60)));
    if ($lat === '' && $lng === '') {
        return ['lat' => null, 'lng' => null, 'radius' => $radius];
    }
    if (!is_numeric($lat) || !is_numeric($lng) || abs((float) $lat) > 90 || abs((float) $lng) > 180) {
        return null;
    }

    return ['lat' => round((float) $lat, 6), 'lng' => round((float) $lng, 6), 'radius' => $radius];
};

if ($action === 'create') {
    $sequenceNo = max(1, (int) ($_POST['sequence_no'] ?? 1));
    $label = trim($_POST['label'] ?? '');
    $distanceKm = $_POST['distance_km'] !== '' ? (float) $_POST['distance_km'] : null;
    $checkpointType = $_POST['checkpoint_type'] ?? 'checkpoint';
    if (!in_array($checkpointType, $allowedTypes, true)) {
        $checkpointType = 'checkpoint';
    }

    $position = $readPosition();
    if ($label === '') {
        flash('error', 'Please provide a label for the checkpoint.');
    } elseif ($position === null) {
        flash('error', 'The map position is not valid. Use Pick on map, or leave both boxes empty.');
    } else {
        $stmt = $db->prepare('INSERT INTO event_checkpoints (event_id, sequence_no, label, checkpoint_type, distance_km, lat, lng, radius_m) VALUES (:eid, :seq, :label, :type, :dist, :lat, :lng, :rad)');
        $stmt->execute(['eid' => $eventId, 'seq' => $sequenceNo, 'label' => $label, 'type' => $checkpointType, 'dist' => $distanceKm,
            'lat' => $position['lat'], 'lng' => $position['lng'], 'rad' => $position['radius']]);
        Logger::log($userId, 'checkpoint_added', 'Added checkpoint "' . $label . '" for event #' . $eventId);
        flash('success', 'Checkpoint added.');
    }
} elseif ($action === 'update') {
    $checkpointId = (int) ($_POST['checkpoint_id'] ?? 0);
    $cpCheck = $db->prepare('SELECT id FROM event_checkpoints WHERE id = :id AND event_id = :eid');
    $cpCheck->execute(['id' => $checkpointId, 'eid' => $eventId]);
    if (!$cpCheck->fetch()) {
        flash('error', 'Checkpoint not found.');
        redirect('organizer-checkpoints', ['event_id' => $eventId]);
    }

    $sequenceNo = max(1, (int) ($_POST['sequence_no'] ?? 1));
    $label = trim($_POST['label'] ?? '');
    $distanceKm = $_POST['distance_km'] !== '' ? (float) $_POST['distance_km'] : null;
    $checkpointType = $_POST['checkpoint_type'] ?? 'checkpoint';
    if (!in_array($checkpointType, $allowedTypes, true)) {
        $checkpointType = 'checkpoint';
    }

    if ($label === '') {
        flash('error', 'Please provide a label for the checkpoint.');
        redirect('organizer-checkpoints', ['event_id' => $eventId, 'edit' => $checkpointId]);
    }
    $position = $readPosition();
    if ($position === null) {
        flash('error', 'The map position is not valid. Use Pick on map, or leave both boxes empty.');
        redirect('organizer-checkpoints', ['event_id' => $eventId, 'edit' => $checkpointId]);
    }

    // Update in place — never delete/reinsert, so existing checkpoint_scans
    // rows (which reference this checkpoint_id) are never orphaned.
    $stmt = $db->prepare(
        'UPDATE event_checkpoints SET sequence_no = :seq, label = :label, checkpoint_type = :type, distance_km = :dist, lat = :lat, lng = :lng, radius_m = :rad
         WHERE id = :id AND event_id = :eid'
    );
    $stmt->execute(['seq' => $sequenceNo, 'label' => $label, 'type' => $checkpointType, 'dist' => $distanceKm,
        'lat' => $position['lat'], 'lng' => $position['lng'], 'rad' => $position['radius'], 'id' => $checkpointId, 'eid' => $eventId]);
    Logger::log($userId, 'checkpoint_updated', 'Updated checkpoint "' . $label . '" for event #' . $eventId);
    flash('success', 'Checkpoint updated.');
} elseif ($action === 'delete') {
    $checkpointId = (int) ($_POST['checkpoint_id'] ?? 0);
    $stmt = $db->prepare('DELETE FROM event_checkpoints WHERE id = :id AND event_id = :eid');
    $stmt->execute(['id' => $checkpointId, 'eid' => $eventId]);
    flash('success', 'Checkpoint removed.');
} elseif ($action === 'toggle_status') {
    $checkpointId = (int) ($_POST['checkpoint_id'] ?? 0);
    $newStatus = ($_POST['new_status'] ?? '') === 'inactive' ? 'inactive' : 'active';
    $stmt = $db->prepare('UPDATE event_checkpoints SET status = :status WHERE id = :id AND event_id = :eid');
    $stmt->execute(['status' => $newStatus, 'id' => $checkpointId, 'eid' => $eventId]);
    Logger::log($userId, 'checkpoint_status_changed', 'Checkpoint #' . $checkpointId . ' set to ' . $newStatus . ' for event #' . $eventId);
    flash('success', 'Checkpoint status updated.');
}

redirect('organizer-checkpoints', ['event_id' => $eventId]);
