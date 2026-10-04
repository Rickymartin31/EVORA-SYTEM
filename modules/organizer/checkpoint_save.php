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

if ($action === 'create') {
    $sequenceNo = max(1, (int) ($_POST['sequence_no'] ?? 1));
    $label = trim($_POST['label'] ?? '');
    $distanceKm = $_POST['distance_km'] !== '' ? (float) $_POST['distance_km'] : null;
    $checkpointType = $_POST['checkpoint_type'] ?? 'checkpoint';
    if (!in_array($checkpointType, $allowedTypes, true)) {
        $checkpointType = 'checkpoint';
    }

    if ($label === '') {
        flash('error', 'Please provide a label for the checkpoint.');
    } else {
        $stmt = $db->prepare('INSERT INTO event_checkpoints (event_id, sequence_no, label, checkpoint_type, distance_km) VALUES (:eid, :seq, :label, :type, :dist)');
        $stmt->execute(['eid' => $eventId, 'seq' => $sequenceNo, 'label' => $label, 'type' => $checkpointType, 'dist' => $distanceKm]);
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

    // Update in place — never delete/reinsert, so existing checkpoint_scans
    // rows (which reference this checkpoint_id) are never orphaned.
    $stmt = $db->prepare(
        'UPDATE event_checkpoints SET sequence_no = :seq, label = :label, checkpoint_type = :type, distance_km = :dist
         WHERE id = :id AND event_id = :eid'
    );
    $stmt->execute(['seq' => $sequenceNo, 'label' => $label, 'type' => $checkpointType, 'dist' => $distanceKm, 'id' => $checkpointId, 'eid' => $eventId]);
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
