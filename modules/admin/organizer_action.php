<?php
Auth::requireRole('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('admin-organizers');
}
Csrf::requireValid();

$db = Database::connection();
$organizerId = (int) ($_POST['organizer_id'] ?? 0);
$action = $_POST['action'] ?? '';
$remarks = trim($_POST['remarks'] ?? '');

$validActions = ['approve' => 'approved', 'reject' => 'rejected', 'suspend' => 'suspended'];

if (!isset($validActions[$action])) {
    flash('error', 'Invalid action.');
    redirect('admin-organizers');
}

$stmt = $db->prepare('SELECT o.*, u.full_name FROM organizers o JOIN users u ON u.id = o.user_id WHERE o.id = :id');
$stmt->execute(['id' => $organizerId]);
$organizer = $stmt->fetch();

if (!$organizer) {
    flash('error', 'Organizer not found.');
    redirect('admin-organizers');
}

$newStatus = $validActions[$action];

$update = $db->prepare(
    'UPDATE organizers SET status = :status, reviewed_by = :admin, reviewed_at = NOW(), remarks = :remarks WHERE id = :id'
);
$update->execute([
    'status' => $newStatus,
    'admin' => Auth::id(),
    'remarks' => $remarks ?: null,
    'id' => $organizerId,
]);

Logger::log(Auth::id(), 'organizer_' . $action, 'Organizer #' . $organizerId . ' (' . $organizer['organization_name'] . ') marked ' . $newStatus);

$messages = [
    'approved' => 'Your organizer application has been approved! You can now create events.',
    'rejected' => 'Your organizer application has been rejected.' . ($remarks ? ' Reason: ' . $remarks : ''),
    'suspended' => 'Your organizer account has been suspended.' . ($remarks ? ' Reason: ' . $remarks : ''),
];
notify((int) $organizer['user_id'], 'Organizer Status Update', $messages[$newStatus], 'organizer_status', ['category' => 'system', 'notice_type' => 'status', 'reference_type' => 'organizer', 'reference_id' => $organizerId]);
NotificationService::toOtherAdmins($db, (int) Auth::id(), 'Organizer ' . ucfirst($newStatus), $organizer['organization_name'] . ' was marked ' . $newStatus . ' by ' . ($_SESSION['full_name'] ?? 'an administrator') . '.', 'organizer_status', ['category' => 'system', 'notice_type' => 'informational', 'reference_type' => 'organizer', 'reference_id' => $organizerId]);

flash('success', 'Organizer application has been ' . $newStatus . '.');
redirect('admin-organizer-review', ['id' => $organizerId]);
