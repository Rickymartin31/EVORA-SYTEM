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
$toolKey = trim((string) ($_POST['tool_key'] ?? ''));
$enabled = ($_POST['enabled'] ?? '') === '1';

$stmt = $db->prepare(
    'SELECT e.id, c.slug AS category_slug
     FROM events e
     JOIN event_categories c ON c.id = e.category_id
     WHERE e.id = :id AND e.organizer_id = :oid'
);
$stmt->execute(['id' => $eventId, 'oid' => $organizer['id'] ?? 0]);
$event = $stmt->fetch();

if (!$event) {
    flash('error', 'Event not found.');
    redirect('organizer-events');
}

$tool = EventTools::findTool($event['category_slug'], $toolKey);

if (!$tool || empty($tool['optional'])) {
    flash('error', 'This tool cannot be customized.');
    redirect('organizer-event-manage', ['id' => $eventId]);
}

$existing = $db->prepare('SELECT id FROM event_tool_overrides WHERE event_id = :eid AND tool_key = :key');
$existing->execute(['eid' => $eventId, 'key' => $toolKey]);
$row = $existing->fetch();

if ($row) {
    $upd = $db->prepare('UPDATE event_tool_overrides SET enabled = :en WHERE id = :id');
    $upd->execute(['en' => $enabled ? 1 : 0, 'id' => $row['id']]);
} else {
    $ins = $db->prepare('INSERT INTO event_tool_overrides (event_id, tool_key, enabled) VALUES (:eid, :key, :en)');
    $ins->execute(['eid' => $eventId, 'key' => $toolKey, 'en' => $enabled ? 1 : 0]);
}

Logger::log($userId, 'event_tool_toggled', 'Event #' . $eventId . ' tool "' . $toolKey . '" set to ' . ($enabled ? 'enabled' : 'disabled'));
flash('success', ($enabled ? 'Tool enabled.' : 'Tool disabled.'));
redirect('organizer-event-manage', ['id' => $eventId]);
