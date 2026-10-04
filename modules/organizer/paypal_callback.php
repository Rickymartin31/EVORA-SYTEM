<?php
// PayPal sends the organizer back here after signing in (?code=…&state=…).
Auth::requireRole('organizer');
$db = Database::connection();
$orgStmt = $db->prepare('SELECT id FROM organizers WHERE user_id = :uid AND status = "approved"');
$orgStmt->execute(['uid' => Auth::id()]);
$organizerId = (int) ($orgStmt->fetchColumn() ?: 0);
if (!$organizerId || !PAYPAL_ENABLED || !PayPalAccountService::oauthAvailable()) {
    unset($_SESSION['paypal_connect']);
    redirect('organizer-paypal-account');
}
if (isset($_GET['error'])) {
    unset($_SESSION['paypal_connect']);
    flash('error', 'PayPal sign-in was cancelled. Your PayPal account was not connected.');
    redirect('organizer-paypal-account');
}
try {
    PayPalAccountService::completeConnect($db, $organizerId, (int) Auth::id(), (string) ($_GET['code'] ?? ''), (string) ($_GET['state'] ?? ''));
    flash('success', 'PayPal account connected. New paid events will be paid directly to this account.');
} catch (DomainException $e) {
    flash('error', $e->getMessage());
}
redirect('organizer-paypal-account');
