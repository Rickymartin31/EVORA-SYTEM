<?php
// PayPal redirect fallback: after approving (or cancelling) on PayPal's site the buyer
// lands here (?registration_id=…&token=<order ID>[&cancel=1]). Nothing in the URL is
// trusted — capture() asks PayPal for the real result and verifies it server-side, and
// only the logged-in owner of the registration can capture its own order.
Auth::requireRole('participant');
$db = Database::connection();
$registrationId = (int) ($_GET['registration_id'] ?? 0);
$orderId = (string) ($_GET['token'] ?? '');
if (!preg_match('/^[A-Z0-9]{8,40}$/', $orderId)) {
    redirect('paypal-result', ['registration_id' => $registrationId, 'state' => 'failed']);
}
try {
    if (!empty($_GET['cancel'])) {
        PayPalPaymentService::cancel($db, $orderId, $registrationId, (int) Auth::id());
        redirect('paypal-result', ['registration_id' => $registrationId, 'state' => 'cancelled']);
    }
    $result = PayPalPaymentService::capture($db, $orderId, $registrationId, (int) Auth::id());
    redirect('paypal-result', ['registration_id' => $registrationId, 'state' => $result['state'] === 'failed' ? 'failed' : 'success', 'message' => $result['state'] === 'failed' ? $result['message'] : null]);
} catch (DomainException $e) {
    flash('error', $e->getMessage());
    redirect('my-registrations');
}
