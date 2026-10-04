<?php
Auth::requireRole('participant');
$db = Database::connection();
$userId = Auth::id();

$registrationId = (int) ($_GET['registration_id'] ?? 0);

$stmt = $db->prepare(
    'SELECT r.*, e.name AS event_name, e.payment_provider, COALESCE(rc.fee, e.fee) AS fee, p.user_id
     FROM registrations r
     JOIN events e ON e.id = r.event_id
     JOIN participants p ON p.id = r.participant_id
     LEFT JOIN race_categories rc ON rc.id = r.race_category_id
     WHERE r.id = :id AND p.user_id = :uid AND r.status NOT IN ("rejected", "cancelled")'
);
$stmt->execute(['id' => $registrationId, 'uid' => $userId]);
$registration = $stmt->fetch();

if (!$registration || (float) $registration['fee'] <= 0) {
    flash('error', 'No payment is required for this registration.');
    redirect('my-registrations');
}

// PayPal events are paid directly to the organizer through PayPal; legacy (PayMongo) events take no new online payments.
$isPayPalEvent = $registration['payment_provider'] === 'paypal';
$onlineAvailable = $isPayPalEvent ? PAYPAL_ENABLED : PAYMONGO_ENABLED;

$existingPay = $db->prepare('SELECT * FROM payments WHERE registration_id = :id');
$existingPay->execute(['id' => $registrationId]);
$payment = $existingPay->fetch();
// A PayPal checkout left open (window closed without paying or cancelling) can be continued:
// EVORA reuses the open PayPal order or starts a fresh one, and still verifies the capture.
$canPay = !$payment || in_array($payment['status'], ['rejected', 'failed', 'cancelled'], true)
    || ($isPayPalEvent && $payment['status'] === 'pending' && $payment['payment_channel'] === 'paypal');

$pageDescription = 'Pay your registration fee securely.';
$pageTitle = $isPayPalEvent ? 'Pay with PayPal' : 'Upload Payment';
$activeRoute = 'my-registrations';
include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
?>
<a href="<?= url('my-registrations') ?>" class="text-decoration-none small mb-3 d-inline-block"><i class="bi bi-arrow-left"></i> Back to My Registrations</a>

<div class="card-evora p-4 mw-form">
    <h6 class="fw-semibold mb-3">Payment for <?= e($registration['event_name']) ?></h6>
    <p class="small">Amount Due: <strong><?= currency($registration['fee']) ?></strong></p>

    <?php if ($payment): ?>
        <div class="alert alert-<?= in_array($payment['status'], ['rejected', 'failed', 'cancelled'], true) ? 'danger' : 'info' ?>">
            Current payment status: <span class="badge badge-status-<?= e($payment['status']) ?>"><?= ucfirst(e($payment['status'])) ?></span>
            <?php if ($payment['payment_channel'] === 'paymongo' && $payment['status'] === 'approved'): ?><br>Paid via PayMongo<?= $payment['gateway_payment_method'] ? ' (' . e(ucfirst($payment['gateway_payment_method'])) . ')' : '' ?>.<?php endif; ?>
            <?php if ($payment['payment_channel'] === 'paypal' && $payment['status'] === 'approved'): ?><br>Paid via PayPal, directly to the organizer.<?php endif; ?>
            <?php if ($payment['status'] === 'rejected' && $payment['rejection_reason']): ?><br><strong>Reason:</strong> <?= e($payment['rejection_reason']) ?><?php endif; ?>
            <?php if (in_array($payment['payment_channel'], ['paymongo', 'paypal'], true) && in_array($payment['status'], ['failed', 'cancelled'], true)): ?>
                <br><strong>Payment failed. Please try again below.</strong>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($canPay): ?>
    <?php if ($isPayPalEvent && $onlineAvailable): ?>
    <div>
        <p class="small text-muted">Please complete your payment through PayPal. Your payment goes directly to the organizer's PayPal account and your registration is confirmed automatically.</p>
        <form method="get" action="<?= url('paypal-checkout') ?>">
            <input type="hidden" name="route" value="paypal-checkout">
            <input type="hidden" name="registration_id" value="<?= $registrationId ?>">
            <button type="submit" class="btn btn-evora-primary w-100"><i class="bi bi-paypal"></i> <?= $payment && $payment['status'] === 'pending' ? 'Continue Payment with PayPal' : 'Pay Now with PayPal' ?></button>
        </form>
    </div>
    <?php elseif ($isPayPalEvent): ?>
    <div class="alert alert-warning small">PayPal payment is paused right now. Your registration is kept — please try again later or contact the organizer.</div>
    <?php elseif (PayPalPaymentService::legacyOnlinePaymentPaused($registration['payment_provider'])): ?>
    <div class="alert alert-warning small"><?= e(PayPalPaymentService::LEGACY_PAUSED_MESSAGE) ?></div>
    <?php else: ?>
    <div class="alert alert-warning small">Online payment is not available right now. Please contact the organizer.</div>
    <?php endif; ?>
    <?php endif; ?>
</div>
<?php include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php'; ?>
