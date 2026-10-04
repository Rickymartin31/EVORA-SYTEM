<?php
Auth::requireRole('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('admin-announcements');
}
Csrf::requireValid();

$db = Database::connection();
$userId = Auth::id();

$audience = $_POST['audience'] ?? '';
$title = mb_substr(trim($_POST['title'] ?? ''), 0, 200);
$message = mb_substr(trim($_POST['message'] ?? ''), 0, 500);

$allowedAudiences = ['all', 'admin', 'organizer', 'staff', 'participant'];
if (!in_array($audience, $allowedAudiences, true) || $title === '' || $message === '') {
    flash('error', 'Please choose an audience and fill in both the title and message.');
    redirect('admin-announcements');
}

if ($audience === 'all') {
    $stmt = $db->prepare("SELECT id FROM users WHERE status = 'active'");
    $stmt->execute();
} else {
    $stmt = $db->prepare(
        "SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE r.name = :role AND u.status = 'active'"
    );
    $stmt->execute(['role' => $audience]);
}
$recipients = $stmt->fetchAll();

foreach ($recipients as $u) {
    notify((int) $u['id'], $title, $message, 'admin_announcement');
}

Logger::log($userId, 'admin_announcement_sent', 'Broadcast "' . $title . '" to ' . count($recipients) . ' user(s) (audience: ' . $audience . ')');
flash('success', 'Notification sent to ' . count($recipients) . ' user(s).');
redirect('admin-announcements');
