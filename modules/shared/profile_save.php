<?php
Auth::requireLogin();

// Only the account pages post here; the user is always the signed-in user.
$return = in_array($_POST['return'] ?? '', ['profile', 'account-settings', 'account-security'], true) ? $_POST['return'] : 'profile';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect($return);
}
Csrf::requireValid();

$db = Database::connection();
$userId = Auth::id();
$form = $_POST['form'] ?? '';

if ($form === 'profile') {
    $fullName = trim($_POST['full_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');

    if ($fullName === '' || mb_strlen($fullName) > 150) {
        flash('error', 'Full name is required (up to 150 characters).');
        redirect('profile', ['edit' => 1]);
    }
    if ($phone !== '' && !preg_match('/^[0-9+\-\s()]{7,30}$/', $phone)) {
        flash('error', 'Enter a valid phone number.');
        redirect('profile', ['edit' => 1]);
    }

    $avatarPath = null;
    if (!empty($_FILES['avatar']['name'])) {
        $avatarPath = Upload::handle($_FILES['avatar'], 'avatars', UPLOAD_ALLOWED_IMAGE);
        if (!$avatarPath) {
            flash('error', 'Profile picture must be a JPG or PNG under 5MB.');
            redirect('profile', ['edit' => 1]);
        }
    }

    if ($avatarPath) {
        $stmt = $db->prepare('UPDATE users SET full_name = :name, phone = :phone, profile_picture_path = :avatar WHERE id = :id');
        $stmt->execute(['name' => $fullName, 'phone' => $phone ?: null, 'avatar' => $avatarPath, 'id' => $userId]);
    } else {
        $stmt = $db->prepare('UPDATE users SET full_name = :name, phone = :phone WHERE id = :id');
        $stmt->execute(['name' => $fullName, 'phone' => $phone ?: null, 'id' => $userId]);
    }
    $_SESSION['full_name'] = $fullName;

    Logger::log($userId, 'profile_updated', 'Updated profile information' . ($avatarPath ? ' and picture' : ''));
    flash('success', 'Profile updated.');
    redirect('profile');
} elseif ($form === 'password') {
    $current = (string) ($_POST['current_password'] ?? '');
    $new = (string) ($_POST['new_password'] ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? '');

    $stmt = $db->prepare('SELECT password_hash FROM users WHERE id = :id');
    $stmt->execute(['id' => $userId]);
    $user = $stmt->fetch();

    $error = match (true) {
        !$user || !password_verify($current, $user['password_hash']) => 'Current password is incorrect.',
        strlen($new) < 8 => 'New password must be at least 8 characters.',
        strlen($new) > 72 => 'New password can be at most 72 characters.',
        !preg_match('/[A-Za-z]/', $new) || !preg_match('/\d/', $new) => 'New password must contain at least one letter and one number.',
        $new !== $confirm => 'New password confirmation does not match.',
        password_verify($new, $user['password_hash']) => 'New password must be different from your current password.',
        default => null,
    };
    if ($error) {
        Logger::log($userId, 'password_change_failed', $error);
        flash('error', $error);
        redirect($return);
    }

    $update = $db->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
    $update->execute(['hash' => password_hash($new, PASSWORD_DEFAULT), 'id' => $userId]);
    session_regenerate_id(true);

    Logger::log($userId, 'password_changed', 'User changed their password');
    notify($userId, 'Password Changed', 'Your EVORA password was changed on ' . date('M d, Y g:i A') . '. If this was not you, contact an administrator immediately.', 'account_security',
        ['category' => 'system', 'notice_type' => 'status']);
    flash('success', 'Password updated successfully.');
}

redirect($return);
