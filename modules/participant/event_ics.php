<?php
// "Add to calendar" (.ics) for a published event. v39 — same visibility as the public event page.
$stmt = Database::connection()->prepare('SELECT * FROM events WHERE id = :id AND status IN ("approved", "open", "closed", "ongoing", "completed")');
$stmt->execute(['id' => (int) ($_GET['id'] ?? 0)]);
$event = $stmt->fetch();
if (!$event) {
    http_response_code(404);
    exit('Event not found.');
}
header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="evora-event-' . (int) $event['id'] . '.ics"');
echo ParticipantToolsService::ics($event);
exit;
