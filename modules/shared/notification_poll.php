<?php
/**
 * Live refresh for the bell (GET, read-only, JSON). Returns the viewer's own unread
 * count and the rendered dropdown — never anyone else's notifications. It changes
 * nothing (no mark-as-read), so it needs no CSRF token; the forms inside the
 * returned HTML carry the session's CSRF token like the server-rendered page does.
 * index.php starts the session in passive mode for this route, so polling does not
 * keep an idle session alive.
 */
header('Content-Type: application/json');
header('Cache-Control: no-store');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthenticated']);
    exit;
}

$db = Database::connection();
$mutedAlerts = AccountService::mutedAlerts($db, Auth::id());
$unreadCount = NotificationService::unreadCount($db, Auth::id(), $mutedAlerts);
$recentNotifications = NotificationService::recent($db, Auth::id(), 10, $mutedAlerts);

$newest = null;
foreach ($recentNotifications as $n) {
    if (!$n['is_read'] && ($newest === null || (int) $n['id'] > (int) $newest['id'])) {
        $newest = $n;
    }
}

ob_start();
include EVORA_ROOT . '/modules/shared/partials/notification_dropdown.php';
$html = ob_get_clean();

$json = json_encode([
    'unread' => $unreadCount,
    'newest_id' => $newest ? (int) $newest['id'] : 0,
    'newest_title' => $newest ? $newest['title'] : null,
    'html' => $html,
]);

// Queued notification emails (v35) are sent after the response is delivered, so the bell
// never waits on the mail server.
if (MailService::enabled()) {
    header('Content-Length: ' . strlen($json));
    header('Connection: close');
    echo $json;
    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    flush();
    ignore_user_abort(true);
    session_write_close();
    MailService::processQueue($db, 3);
    exit;
}
echo $json;
