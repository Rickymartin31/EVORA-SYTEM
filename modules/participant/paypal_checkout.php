<?php
// PayPal checkout for one registration. The amount, payee and order are decided on
// EVORA's server (paypal-api); this page only shows PayPal's own button.
Auth::requireRole('participant');
$db = Database::connection();
$registrationId = (int) ($_GET['registration_id'] ?? 0);

try {
    $ctx = PayPalPaymentService::checkoutContext($db, $registrationId, (int) Auth::id());
} catch (DomainException $e) {
    flash('error', $e->getMessage());
    redirect('my-registrations');
}
$paidStmt = $db->prepare('SELECT status FROM payments WHERE registration_id = :r');
$paidStmt->execute(['r' => $registrationId]);
if (in_array($paidStmt->fetchColumn(), ['approved', 'refunded'], true)) {
    flash('error', 'This registration is already paid.');
    redirect('my-registrations');
}
if (!PAYPAL_ENABLED) {
    flash('error', 'PayPal payment is not available right now. Please try again later.');
    redirect('my-registrations');
}

$sdkUrl = 'https://www.paypal.com/sdk/js?' . http_build_query([
    'client-id' => PAYPAL_CLIENT_ID,             // public identifier by PayPal's design — the secret never leaves the server
    'merchant-id' => $ctx['account']['merchant_id'],
    'currency' => PAYPAL_CURRENCY,
    'intent' => 'capture',
    'commit' => 'true',
    'components' => 'buttons',
]);

$pageTitle = 'Pay with PayPal';
$pageDescription = 'Your payment goes directly to the event organizer through PayPal.';
$activeRoute = 'my-registrations';
include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
?>
<?php renderBreadcrumb([['label' => 'My Registrations', 'route' => 'my-registrations'], ['label' => 'Pay with PayPal']]); ?>
<div class="row g-3 justify-content-center">
    <div class="col-lg-6">
        <section class="card-evora p-4" aria-labelledby="payHeading">
            <h2 class="h5 fw-semibold mb-1" id="payHeading"><?= e($ctx['event_name']) ?></h2>
            <p class="text-muted small mb-3">Registration #<?= (int) $registrationId ?><?= PAYPAL_ENV === 'sandbox' ? ' · <span class="badge bg-warning text-dark">PayPal Sandbox — test money only</span>' : '' ?></p>
            <dl class="row small mb-3">
                <dt class="col-5">Amount</dt><dd class="col-7 fw-bold"><?= currency($ctx['fee']) ?> <?= e(PAYPAL_CURRENCY) ?></dd>
                <dt class="col-5">Paid to</dt><dd class="col-7">The event organizer's PayPal account</dd>
            </dl>
            <div id="paypalStatus" class="small mb-2" role="status" aria-live="polite"></div>
            <div id="paypalButtons" data-api="<?= e(url('paypal-api')) ?>" data-registration="<?= (int) $registrationId ?>" data-csrf="<?= e(Csrf::token()) ?>"
                 data-result="<?= e(url('paypal-result', ['registration_id' => $registrationId])) ?>"></div>
            <p class="small text-muted mt-3 mb-0">EVORA confirms the payment with PayPal before approving your registration and issuing your QR code. EVORA never sees your PayPal password or card details.</p>
        </section>
    </div>
</div>
<script src="<?= e($sdkUrl) ?>" data-namespace="paypalSdk"></script>
<script>
(function () {
    var box = document.getElementById('paypalButtons');
    var statusEl = document.getElementById('paypalStatus');
    if (!window.paypalSdk) { statusEl.textContent = 'PayPal could not be loaded. Check your connection and refresh the page.'; return; }
    var post = function (action, extra) {
        var body = new URLSearchParams(Object.assign({ action: action, registration_id: box.dataset.registration, csrf_token: box.dataset.csrf }, extra || {}));
        return fetch(box.dataset.api, { method: 'POST', credentials: 'same-origin', body: body }).then(function (r) { return r.json(); });
    };
    var go = function (state, extra) {
        var u = new URL(box.dataset.result, window.location.href);
        u.searchParams.set('state', state);
        Object.keys(extra || {}).forEach(function (k) { if (extra[k]) u.searchParams.set(k, extra[k]); });
        window.location.href = u.toString();
    };
    window.paypalSdk.Buttons({
        style: { layout: 'vertical', label: 'pay' },
        createOrder: function () {
            statusEl.textContent = '';
            return post('create').then(function (res) {
                if (!res.ok) { statusEl.textContent = res.message || 'Could not start the payment.'; throw new Error(res.message); }
                return res.order_id;
            });
        },
        onApprove: function (data) {
            statusEl.textContent = 'Confirming your payment with PayPal…';
            return post('capture', { order_id: data.orderID }).then(function (res) {
                go(res.state === 'completed' || res.state === 'already_paid' ? 'success' : 'failed', { order_id: data.orderID, message: res.state === 'failed' ? res.message : '' });
            });
        },
        onCancel: function (data) {
            post('cancel', { order_id: data.orderID }).finally(function () { go('cancelled'); });
        },
        onError: function () { go('failed', { message: 'PayPal reported an error. You were not charged.' }); }
    }).render('#paypalButtons');
})();
</script>
<?php include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php'; ?>
