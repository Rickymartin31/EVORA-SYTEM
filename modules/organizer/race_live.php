<?php
/**
 * Live race data for the organizer's map (v56): GET, read-only, JSON. Polled in passive session mode
 * (index.php). ?event_id=N lists the tracked runners; add &track=ID for one runner's full path.
 */
header('Content-Type: application/json');
header('Cache-Control: no-store');

if (!Auth::check() || Auth::role() !== 'organizer') {
    http_response_code(401);
    echo json_encode(['error' => 'unauthenticated']);
    exit;
}
$db = Database::connection();
$eventId = (int) ($_GET['event_id'] ?? 0);
$own = $db->prepare('SELECT e.id FROM events e JOIN organizers o ON o.id = e.organizer_id WHERE e.id = :id AND o.user_id = :uid');
$own->execute(['id' => $eventId, 'uid' => Auth::id()]);
if (!$own->fetch()) {
    http_response_code(404);
    echo json_encode(['error' => 'not_found']);
    exit;
}

if (isset($_GET['track'])) {
    echo json_encode(['path' => RaceTrackingService::trackPath($db, $eventId, (int) $_GET['track'])]);
    exit;
}
echo json_encode([
    'updated' => date('g:i:s A'),
    'runners' => array_map(fn ($r) => [
        'id' => (int) $r['id'],
        'name' => $r['full_name'],
        'bib' => $r['bib_number'],
        'category' => $r['distance_category'],
        'status' => $r['status'],
        'lat' => $r['last_lat'] !== null ? (float) $r['last_lat'] : null,
        'lng' => $r['last_lng'] !== null ? (float) $r['last_lng'] : null,
        'accuracy' => $r['last_accuracy_m'] !== null ? (int) $r['last_accuracy_m'] : null,
        'seconds_ago' => $r['seconds_ago'] !== null ? (int) $r['seconds_ago'] : null,
        'distance_km' => round((int) $r['distance_m'] / 1000, 2),
        'points' => (int) $r['point_count'],
        'verdict' => $r['verdict'],
        'detail' => $r['verdict_detail'],
    ], RaceTrackingService::liveRunners($db, $eventId)),
]);
