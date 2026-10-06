<?php
/**
 * The runner's phone talks to this (v56): POST, JSON reply. race_track_action = start | push | finish,
 * registration_id, csrf_token; push also sends points = JSON [{lat,lng,acc,t},...].
 */
header('Content-Type: application/json');
header('Cache-Control: no-store');

$reply = function (int $code, array $body): void {
    http_response_code($code);
    echo json_encode($body);
    exit;
};
if (!Auth::check() || Auth::role() !== 'participant') {
    $reply(401, ['error' => 'Your session ended. Log in again; your positions are kept on this phone.']);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !Csrf::verify($_POST['csrf_token'] ?? null)) {
    $reply(403, ['error' => 'Security token expired. Reload the page; your positions are kept on this phone.']);
}

$db = Database::connection();
$check = RaceTrackingService::trackable($db, (int) ($_POST['registration_id'] ?? 0), Auth::id());
if (!$check['reg']) {
    $reply(404, ['error' => $check['why']]);
}
$action = $_POST['race_track_action'] ?? '';
$track = RaceTrackingService::trackFor($db, (int) $check['reg']['id']);

try {
    if ($action === 'start') {
        if ($check['why']) {
            $reply(422, ['error' => $check['why']]);
        }
        $track = RaceTrackingService::start($db, $check['reg']);
    } elseif ($action === 'push' || $action === 'finish') {
        if (!$track) {
            $reply(409, ['error' => 'Tap Start first.']);
        }
        $points = json_decode((string) ($_POST['points'] ?? '[]'), true);
        if (is_array($points) && $points && $track['status'] === 'active') {
            $track = RaceTrackingService::push($db, $track, $points);
        }
        if ($action === 'finish') {
            $track = RaceTrackingService::finish($db, $track);
        }
    } else {
        $reply(400, ['error' => 'Unknown action.']);
    }
} catch (DomainException $e) {
    $reply(409, ['error' => $e->getMessage(), 'status' => $track['status'] ?? null]);
}

$reply(200, [
    'status' => $track['status'],
    'distance_km' => round((int) $track['distance_m'] / 1000, 2),
    'points' => (int) $track['point_count'],
    'started_at' => $track['started_at'],
    'verdict' => $track['verdict'],
    'detail' => $track['verdict_detail'],
]);
