<?php
// Success / failed / cancelled page. It shows what EVORA recorded — the URL's "state" only picks
// the wording; a payment is "successful" here only if the database says it was captured.
Auth::requireRole('participant');
$db = Database::connection();
$registrationId = (int) ($_GET['registration_id'] ?? 0);

$stmt = $db->prepare('SELECT r.id, r.status, e.name AS event_name, pay.status AS payment_status FROM registrations r JOIN events e ON e.id = r.event_id
                      JOIN participants p ON p.id = r.participant_id LEFT JOIN payments pay ON pay.registration_id = r.id WHERE r.id = :id AND p.user_id = :u');
$stmt->execute(['id' => $registrationId, 'u' => Auth::id()]);
$reg = $stmt->fetch();
if (!$reg) {
    redirect('my-registrations');
}
$tx = PayPalPaymentService::latestForRegistration($db, $registrationId);
$paid = $reg['payment_status'] === 'approved' && $tx && $tx['paypal_status'] === 'completed';
$state = $paid ? 'success' : (($_GET['state'] ?? '') === 'cancelled' ? 'cancelled' : 'failed');
$message = $state === 'failed' ? mb_substr(trim((string) ($_GET['message'] ?? '')), 0, 200) : '';

$view = [
    'success' => ['bi-check-circle-fill text-success', 'Payment successful', 'Your registration is confirmed and your QR code is ready.'],
    'failed' => ['bi-x-octagon-fill text-danger', 'Payment failed', 'You were not charged. You can try again.'],
    'cancelled' => ['bi-dash-circle text-secondary', 'Payment cancelled', 'You closed PayPal before paying. Nothing was charged and your registration is still waiting for payment.'],
][$state];

$pageTitle = $view[1];
$activeRoute = 'my-registrations';
include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
?>
<div class="row justify-content-center">
    <div class="col-lg-6">
        <section class="card-evora p-4 text-center" aria-labelledby="resHeading">
            <i class="bi <?= $view[0] ?> display-5" aria-hidden="true"></i>
            <h2 class="h5 fw-semibold mt-2" id="resHeading"><?= e($view[1]) ?></h2>
            <p class="text-muted"><?= e($view[2]) ?></p>
            <?php if ($message !== ''): ?><p class="small"><?= e($message) ?></p><?php endif; ?>
            <dl class="row small text-start mx-auto" style="max-width:420px;">
                <dt class="col-5">Event</dt><dd class="col-7"><?= e($reg['event_name']) ?></dd>
                <dt class="col-5">Registration</dt><dd class="col-7">#<?= (int) $reg['id'] ?> · <?= e(ucfirst($reg['status'])) ?></dd>
                <?php if ($tx): ?>
                    <dt class="col-5">Amount</dt><dd class="col-7"><?= currency($tx['amount']) ?> <?= e($tx['currency']) ?></dd>
                    <dt class="col-5">PayPal order</dt><dd class="col-7"><code><?= e((string) $tx['paypal_order_id']) ?></code></dd>
                    <?php if ($paid): ?><dt class="col-5">Transaction ID</dt><dd class="col-7"><code><?= e((string) $tx['paypal_capture_id']) ?></code></dd><?php endif; ?>
                <?php endif; ?>
            </dl>
            <div class="d-flex justify-content-center gap-2 mt-2">
                <?php if (!$paid && !in_array($reg['status'], ['rejected', 'cancelled'], true)): ?>
                    <a class="btn btn-evora-primary" href="<?= url('paypal-checkout', ['registration_id' => $registrationId]) ?>">Try again</a>
                <?php endif; ?>
                <a class="btn btn-outline-secondary" href="<?= url('my-registrations') ?>">My Registrations</a>
            </div>
        </section>
    </div>
</div>
<?php include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php'; ?>
