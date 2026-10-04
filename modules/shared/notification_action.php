<?php
Auth::requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('notifications');
}
Csrf::requireValid();

$db = Database::connection();
$userId = Auth::id();
$do = $_POST['do'] ?? '';
$notificationId = (int) ($_POST['notification_id'] ?? 0);

// Every statement is scoped to the signed-in user: another user's
// notification id simply matches nothing.
if ($do === 'open' && $notificationId > 0) {
    $notification = NotificationService::findForUser($db, $notificationId, $userId);
    if (!$notification) {
        flash('error', 'Notification not found.');
        redirect('notifications');
    }
    if (NotificationService::markRead($db, $notificationId, $userId)) {
        Logger::log($userId, 'notification_read', 'Opened "' . mb_substr($notification['title'], 0, 120) . '"');
    }
    // The target is built for the viewer's own role and is always an internal route.
    [$route, $params] = NotificationService::link($notification, (string) Auth::role());
    redirect($route, $params);
} elseif ($do === 'mark_all_read') {
    if ($marked = NotificationService::markAllRead($db, $userId, (string) ($_POST['category'] ?? ''))) {
        Logger::log($userId, 'notification_read', 'Marked ' . $marked . ' notification(s) as read');
    }
} elseif ($do === 'mark_read' && $notificationId > 0) {
    if (NotificationService::markRead($db, $notificationId, $userId)) {
        Logger::log($userId, 'notification_read', 'Marked notification #' . $notificationId . ' as read');
    }
} elseif ($do === 'delete' && $notificationId > 0) {
    $stmt = $db->prepare('DELETE FROM notifications WHERE id = :id AND user_id = :uid');
    $stmt->execute(['id' => $notificationId, 'uid' => $userId]);
}

// Return the user to whatever page they triggered this from (the notification
// bell dropdown appears on every page) — but rebuild a clean same-origin
// path+query from the referer instead of trusting the raw client-supplied
// string, dropping scheme/host/fragment/userinfo entirely.
$refererParts = !empty($_SERVER['HTTP_REFERER']) ? parse_url($_SERVER['HTTP_REFERER']) : false;
if ($refererParts && ($refererParts['host'] ?? '') === ($_SERVER['HTTP_HOST'] ?? '')) {
    $path = $refererParts['path'] ?? '/';
    $query = isset($refererParts['query']) ? '?' . $refererParts['query'] : '';
    header('Location: ' . $path . $query);
    exit;
}

redirect('notifications');
