<?php
// Organizer records a refund they made in PayPal (POST + CSRF).
Auth::requireRole('organizer');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('organizer-refunds');
}
Csrf::requireValid();
$db = Database::connection();
$orgStmt = $db->prepare('SELECT id FROM organizers WHERE user_id = :uid AND status = "approved"');
$orgStmt->execute(['uid' => Auth::id()]);
$organizerId = (int) ($orgStmt->fetchColumn() ?: 0);
try {
    if (!$organizerId) {
        throw new DomainException('Only approved organizers can record refunds.');
    }
    RefundTrackingService::record($db, $organizerId, (int) Auth::id(), (int) ($_POST['payment_id'] ?? 0), (string) ($_POST['paypal_refund_id'] ?? ''), (string) ($_POST['note'] ?? ''));
    flash('success', 'Refund recorded. The participant was notified and asked to confirm they received it.');
} catch (DomainException $e) {
    flash('error', $e->getMessage());
} catch (PDOException $e) {
    // e.g. the same refund submitted twice at once (unique keys / guard trigger)
    flash('error', 'This refund could not be recorded — it may already have been recorded. Please check the list below.');
}
redirect('organizer-refunds');
