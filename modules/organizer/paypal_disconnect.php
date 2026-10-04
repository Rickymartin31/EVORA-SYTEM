<?php
Auth::requireRole('organizer');
Csrf::requireValid();
$db = Database::connection();
$orgStmt = $db->prepare('SELECT id FROM organizers WHERE user_id = :uid');
$orgStmt->execute(['uid' => Auth::id()]);
$organizerId = (int) ($orgStmt->fetchColumn() ?: 0);
try {
    PayPalAccountService::disconnect($db, $organizerId, (int) Auth::id());
    flash('success', 'PayPal account disconnected.');
} catch (DomainException $e) {
    flash('error', $e->getMessage());
}
redirect('organizer-paypal-account');
