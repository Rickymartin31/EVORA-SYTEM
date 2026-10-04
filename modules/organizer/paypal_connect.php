<?php
// Starts "Log in with PayPal" (POST + CSRF). The organizer signs in on PayPal's own page.
Auth::requireRole('organizer');
Csrf::requireValid();
$db = Database::connection();
if (!PAYPAL_ENABLED) {
    flash('error', 'PayPal is not enabled yet.');
    redirect('organizer-paypal-account');
}
if (!PayPalAccountService::oauthAvailable()) {
    flash('error', 'Connecting PayPal is not available yet. Please try again later.');
    redirect('organizer-paypal-account');
}
$orgStmt = $db->prepare('SELECT id, status FROM organizers WHERE user_id = :uid');
$orgStmt->execute(['uid' => Auth::id()]);
$organizer = $orgStmt->fetch();
if (!$organizer || $organizer['status'] !== 'approved') {
    flash('error', 'Only approved organizers can connect a PayPal account.');
    redirect('organizer-paypal-account');
}
// PayPal returns to the registered Return URL; the EVORA sign-in cookie only exists on the
// host the organizer is using now. On another host (e.g. localhost vs 127.0.0.1) the return
// would arrive signed-out, so move them to the right host (one more sign-in there) instead.
if ($hostSwitchUrl = PayPalAccountService::hostSwitchUrl()) {
    header('Location: ' . $hostSwitchUrl);
    exit;
}
try {
    $connectUrl = PayPalAccountService::startConnect((int) $organizer['id']);
} catch (DomainException $e) {
    flash('error', $e->getMessage());
    redirect('organizer-paypal-account');
}
header('Location: ' . $connectUrl);
exit;
