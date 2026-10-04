<?php
/**
 * "Stay signed in" (v54): any normal request already counts as activity in Auth::start(), so this
 * only answers how long the session now has. 401 once the session is gone.
 */
header('Content-Type: application/json');
header('Cache-Control: no-store');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['ok' => false]);
    exit;
}
echo json_encode(['ok' => true, 'timeout' => SESSION_TIMEOUT]);
