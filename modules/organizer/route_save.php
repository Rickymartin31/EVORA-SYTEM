<?php
/** Saves the official route drawn on the Route Management map (v56). POST: event_id, points (JSON [[lat,lng],...]). */
Auth::requireRole('organizer');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('organizer-events');
}
Csrf::requireValid();

$db = Database::connection();
$userId = Auth::id();

$eventId = (int) ($_POST['event_id'] ?? 0);
$eventStmt = $db->prepare('SELECT e.id FROM events e JOIN organizers o ON o.id = e.organizer_id WHERE e.id = :id AND o.user_id = :uid');
$eventStmt->execute(['id' => $eventId, 'uid' => $userId]);
if (!$eventStmt->fetch()) {
    flash('error', 'Event not found.');
    redirect('organizer-events');
}
EventAccess::requireEventType($db, $eventId, 'fun-run', 'organizer-events');

try {
    $meters = RaceTrackingService::saveRoute($db, $eventId, (string) ($_POST['points'] ?? ''), $userId);
    Logger::log($userId, 'race_route_saved', 'Saved the route map (' . number_format($meters / 1000, 2) . ' km) for event #' . $eventId);
    flash('success', $meters > 0 ? 'Route saved (' . number_format($meters / 1000, 2) . ' km).' : 'Route cleared.');
} catch (DomainException $e) {
    flash('error', $e->getMessage());
}
redirect('organizer-checkpoints', ['event_id' => $eventId]);
