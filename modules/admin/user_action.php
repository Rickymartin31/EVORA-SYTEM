<?php
Auth::requireRole('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('admin-users');
}
Csrf::requireValid();

$db = Database::connection();
$userId = (int) ($_POST['user_id'] ?? 0);
$action = $_POST['action'] ?? '';
$returnParams = !empty($_POST['return_role']) ? ['role' => $_POST['return_role']] : [];

if ($userId === Auth::id()) {
    flash('error', 'You cannot change your own account status.');
    redirect('admin-users', $returnParams);
}

$newStatus = match ($action) {
    'activate' => 'active',
    'deactivate' => 'inactive',
    default => null,
};

if ($newStatus === null) {
    flash('error', 'Invalid action.');
    redirect('admin-users', $returnParams);
}

$existing = $db->prepare('SELECT id FROM users WHERE id = :id');
$existing->execute(['id' => $userId]);
if (!$existing->fetch()) {
    flash('error', 'User not found.');
    redirect('admin-users', $returnParams);
}

$stmt = $db->prepare('UPDATE users SET status = :status WHERE id = :id');
$stmt->execute(['status' => $newStatus, 'id' => $userId]);

Logger::log(Auth::id(), 'user_' . $action, 'Admin set user #' . $userId . ' to ' . $newStatus);
// Security: the account owner and the other admins learn about the status change.
NotificationService::safely(function () use ($db, $userId, $newStatus) {
    notify($userId, 'Account ' . ($newStatus === 'active' ? 'Activated' : 'Deactivated'), 'Your EVORA account was ' . ($newStatus === 'active' ? 'activated' : 'deactivated') . ' by an administrator on ' . date('M d, Y g:i A') . '.', 'account_status', ['category' => 'system', 'notice_type' => 'status']);
});
NotificationService::toOtherAdmins($db, (int) Auth::id(), 'Account ' . ($newStatus === 'active' ? 'Activated' : 'Deactivated'), 'User #' . $userId . ' was set to ' . $newStatus . ' by ' . ($_SESSION['full_name'] ?? 'an administrator') . '.', 'account_status', ['category' => 'system', 'notice_type' => 'informational', 'reference_type' => 'user', 'reference_id' => $userId]);
flash('success', 'User account ' . ($newStatus === 'active' ? 'activated' : 'deactivated') . '.');
redirect('admin-users', $returnParams);
