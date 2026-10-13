<?php
/**
 * Quick checkpoint setup from the Route Management map (v69). POST, JSON reply.
 * map_action = add (type, lat, lng) | move (checkpoint_id, lat, lng) | rename (checkpoint_id, label)
 *            | delete (checkpoint_id) | auto_start_finish | water_every (km)
 * Every change re-orders the checkpoints along the route and fills in their km.
 */
header('Content-Type: application/json');
header('Cache-Control: no-store');
$reply = function (int $code, array $body): void {
    http_response_code($code);
    echo json_encode($body);
    exit;
};
if (!Auth::check() || Auth::role() !== 'organizer') {
    $reply(401, ['ok' => false, 'message' => 'Please log in again.']);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !Csrf::verify($_POST['csrf_token'] ?? null)) {
    $reply(403, ['ok' => false, 'message' => 'Your session expired. Reload the page.']);
}

$db = Database::connection();
$userId = (int) Auth::id();
$eventId = (int) ($_POST['event_id'] ?? 0);
$own = $db->prepare('SELECT e.id FROM events e JOIN organizers o ON o.id = e.organizer_id JOIN event_categories c ON c.id = e.category_id
                     WHERE e.id = :id AND o.user_id = :uid AND c.slug = "fun-run"');
$own->execute(['id' => $eventId, 'uid' => $userId]);
if (!$own->fetch()) {
    $reply(404, ['ok' => false, 'message' => 'Event not found.']);
}
$checkpointId = (int) ($_POST['checkpoint_id'] ?? 0);
$lat = (float) ($_POST['lat'] ?? 0);
$lng = (float) ($_POST['lng'] ?? 0);
// A checkpoint far from the drawn route is usually a slip of the mouse: say so.
$farNote = function (float $lat, float $lng) use ($db, $eventId): string {
    $route = RaceTrackingService::route($db, $eventId);
    if (!$route) {
        return '';
    }
    $off = RaceTrackingService::routeProgress($lat, $lng, $route['points'])[1];

    return $off > 150 ? ' Note: it is ' . (int) round($off) . ' m from the route.' : '';
};

try {
    switch ($_POST['map_action'] ?? '') {
        case 'add':
            RaceTrackingService::addCheckpointAt($db, $eventId, (string) ($_POST['type'] ?? ''), $lat, $lng, $userId);
            $message = 'Added.' . $farNote($lat, $lng);
            break;
        case 'move':
            RaceTrackingService::moveCheckpoint($db, $eventId, $checkpointId, $lat, $lng);
            $message = 'Moved.' . $farNote($lat, $lng);
            break;
        case 'rename':
            $label = trim((string) ($_POST['label'] ?? ''));
            if ($label === '' || mb_strlen($label) > 100) {
                throw new DomainException('Give the checkpoint a name (up to 100 characters).');
            }
            $db->prepare('UPDATE event_checkpoints SET label = :l WHERE id = :id AND event_id = :e')->execute(['l' => $label, 'id' => $checkpointId, 'e' => $eventId]);
            $message = 'Renamed.';
            break;
        case 'delete':
            $db->prepare('DELETE FROM event_checkpoints WHERE id = :id AND event_id = :e')->execute(['id' => $checkpointId, 'e' => $eventId]);
            RaceTrackingService::resequenceCheckpoints($db, $eventId);
            Logger::log($userId, 'checkpoint_removed', 'Removed checkpoint #' . $checkpointId . ' on the map for event #' . $eventId);
            $message = 'Removed.';
            break;
        case 'auto_start_finish':
            RaceTrackingService::autoStartFinish($db, $eventId, $userId);
            $message = 'Start and Finish placed at the ends of the route.';
            break;
        case 'water_every':
            $n = RaceTrackingService::waterEvery($db, $eventId, (float) ($_POST['km'] ?? 0), $userId);
            $message = $n ? $n . ' water station' . ($n > 1 ? 's' : '') . ' added.' : 'Every spot already has a water station.';
            break;
        default:
            throw new DomainException('Unknown action.');
    }
} catch (DomainException $e) {
    $reply(422, ['ok' => false, 'message' => $e->getMessage()]);
}

$reply(200, ['ok' => true, 'message' => $message, 'checkpoints' => RaceTrackingService::checkpointsForMap($db, $eventId)]);
