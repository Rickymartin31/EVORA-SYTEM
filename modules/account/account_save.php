<?php
Auth::requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('account-settings');
}
Csrf::requireValid();

$db = Database::connection();
$userId = Auth::id(); // settings are always saved for the signed-in user only
$form = $_POST['form'] ?? '';

try {
    if ($form === 'preferences') {
        AccountService::savePreferences($db, $userId, (string) ($_POST['language'] ?? ''), (string) ($_POST['timezone'] ?? ''));
        flash('success', 'Preferences saved.');
        redirect('account-settings');
    }
    if ($form === 'notifications') {
        $enabled = array_values(array_intersect(array_keys(AccountService::NOTIFY_OPTIONS), (array) ($_POST['enabled'] ?? [])));
        AccountService::saveNotificationPreferences($db, $userId, $enabled, !empty($_POST['email_notifications']));
        flash('success', 'Notification preferences saved.');
        redirect('notification-preferences');
    }
    flash('error', 'Invalid action.');
} catch (DomainException $e) {
    flash('error', $e->getMessage());
}
redirect('account-settings');
