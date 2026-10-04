<?php
Auth::requireRole('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('admin-settings');
}
Csrf::requireValid();

$db = Database::connection();
$userId = Auth::id();

$systemName = trim($_POST['system_name'] ?? '');
$systemDescription = trim($_POST['system_description'] ?? '');
$contactEmail = trim($_POST['contact_email'] ?? '');
$contactPhone = trim($_POST['contact_phone'] ?? '');
$defaultAnnouncementNote = trim($_POST['default_announcement_note'] ?? '');
$removeLogo = !empty($_POST['remove_logo']);

if ($systemName === '') {
    flash('error', 'System name is required.');
    redirect('admin-settings');
}

$logoPath = null;
if (!empty($_FILES['logo']['name'])) {
    $logoPath = Upload::handle($_FILES['logo'], 'logo', UPLOAD_ALLOWED_IMAGE);
    if (!$logoPath) {
        flash('error', 'Logo must be a JPG or PNG under 5MB.');
        redirect('admin-settings');
    }
}

$sql = 'UPDATE system_settings SET system_name = :name, system_description = :desc, contact_email = :email,
        contact_phone = :phone, default_announcement_note = :note, updated_by = :by';
$params = [
    'name' => $systemName, 'desc' => $systemDescription ?: null, 'email' => $contactEmail ?: null,
    'phone' => $contactPhone ?: null, 'note' => $defaultAnnouncementNote ?: null, 'by' => $userId,
];
if ($logoPath) {
    $sql .= ', logo_path = :logo';
    $params['logo'] = $logoPath;
} elseif ($removeLogo) {
    $sql .= ', logo_path = NULL';
}
$sql .= ' WHERE id = 1';
$stmt = $db->prepare($sql);
$stmt->execute($params);

Logger::log($userId, 'settings_updated', 'Updated system settings');
flash('success', 'System settings saved.');
redirect('admin-settings');
