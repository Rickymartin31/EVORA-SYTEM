<?php
/**
 * Turns GPS live tracking (anti-shortcut) on or off for one fun run (v70). POST: event_id, enable (1|0).
 * Turning it off only hides the tracker from runners; the route, checkpoints and recorded tracks are kept.
 */
Auth::requireRole('organizer');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('organizer-events');
}
Csrf::requireValid();

$db = Database::connection();
$userId = (int) Auth::id();
$eventId = (int) ($_POST['event_id'] ?? 0);
$own = $db->prepare('SELECT e.id FROM events e JOIN organizers o ON o.id = e.organizer_id WHERE e.id = :id AND o.user_id = :uid');
$own->execute(['id' => $eventId, 'uid' => $userId]);
if (!$own->fetch()) {
    flash('error', 'Event not found.');
    redirect('organizer-events');
}
EventAccess::requireEventType($db, $eventId, 'fun-run', 'organizer-events');

$enable = ($_POST['enable'] ?? '') === '1';
$db->prepare('UPDATE events SET gps_tracking = :g WHERE id = :id')->execute(['g' => $enable ? 1 : 0, 'id' => $eventId]);
Logger::log($userId, $enable ? 'gps_tracking_on' : 'gps_tracking_off', 'GPS live tracking turned ' . ($enable ? 'on' : 'off') . ' for event #' . $eventId);
flash('success', $enable
    ? 'GPS live tracking is on. Draw the route, set the checkpoints, then follow your runners in Live Runners on race day.'
    : 'GPS live tracking is off. Runners no longer see the Race Tracker. Your route and checkpoints are kept.');
redirect('organizer-checkpoints', ['event_id' => $eventId]);
