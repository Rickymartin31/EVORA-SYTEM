<?php
/**
 * Live numbers for the "Today" panel (v54): GET, read-only, JSON. Only the signed-in organizer's
 * own events or the staff member's assigned events. Polled in passive session mode (index.php),
 * so it never keeps an idle session alive.
 */
header('Content-Type: application/json');
header('Cache-Control: no-store');

if (!Auth::check() || !in_array(Auth::role(), ['organizer', 'staff'], true)) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthenticated']);
    exit;
}
$events = todayEventsFor(Database::connection());
echo json_encode([
    'updated' => date('g:i A'),
    'events' => array_map(fn ($e) => ['id' => (int) $e['id'], 'approved' => (int) $e['approved'], 'checked_in' => (int) $e['checked_in']], $events),
]);
