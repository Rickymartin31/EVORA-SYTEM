<?php
// Participant confirms or disputes a refund the organizer recorded (POST + CSRF).
Auth::requireRole('participant');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('my-payments');
}
Csrf::requireValid();
$db = Database::connection();
$response = (string) ($_POST['response'] ?? '');
try {
    RefundTrackingService::respond($db, (int) Auth::id(), (int) ($_POST['payment_id'] ?? 0), $response, (string) ($_POST['note'] ?? ''));
    flash('success', $response === 'received'
        ? 'Thank you — the refund is confirmed as received.'
        : 'Reported. The organizer and the EVORA administrators were notified and will follow up.');
} catch (DomainException $e) {
    flash('error', $e->getMessage());
} catch (PDOException $e) {
    flash('error', 'Your answer could not be saved — you may have already answered. Please check below.');
}
redirect('my-payments');
