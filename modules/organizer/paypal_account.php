<?php
Auth::requireRole('organizer');
$db = Database::connection();
$orgStmt = $db->prepare('SELECT * FROM organizers WHERE user_id = :uid');
$orgStmt->execute(['uid' => Auth::id()]);
$organizer = $orgStmt->fetch();
if (!$organizer) {
    redirect('organizer-dashboard');
}
$account = PAYPAL_ENABLED ? PayPalAccountService::current($db, (int) $organizer['id']) : null;
$history = PayPalAccountService::history($db, (int) $organizer['id']);

// Disconnect warning: each PayPal event keeps the account saved when it opened, so
// disconnecting can stop new payments for events that are still running.
$activePayPalEvents = 0;
$awaitingPayment = 0;
if ($account) {
    $impact = $db->prepare(
        'SELECT COUNT(DISTINCT e.id) AS events,
                COUNT(DISTINCT CASE WHEN r.status IN ("pending", "approved") AND (pay.id IS NULL OR pay.status <> "approved") THEN r.id END) AS awaiting
         FROM events e
         LEFT JOIN registrations r ON r.event_id = e.id
         LEFT JOIN payments pay ON pay.registration_id = r.id
         WHERE e.organizer_id = :oid AND e.payment_provider = "paypal" AND e.organizer_paypal_account_id = :acct
           AND e.status IN ("open", "closed", "ongoing")'
    );
    $impact->execute(['oid' => (int) $organizer['id'], 'acct' => (int) $account['id']]);
    $impactRow = $impact->fetch();
    $activePayPalEvents = (int) $impactRow['events'];
    $awaitingPayment = (int) $impactRow['awaiting'];
}
$disconnectWarning = 'Active or open PayPal events may be affected. Each PayPal event uses the PayPal account saved for that event, '
    . 'so after you disconnect, participants of those events may be unable to complete new payments — connecting PayPal again does not change the account saved on events that are already open.'
    . ($activePayPalEvents > 0
        ? ' Right now ' . $activePayPalEvents . ' active PayPal event(s) use this account' . ($awaitingPayment > 0 ? ' and ' . $awaitingPayment . ' registration(s) are still waiting for payment' : '') . '.'
        : ' No active PayPal event uses this account right now.')
    . ' Please make sure no active registrations or payments would be disrupted. EVORA does not move or refund any money: payments already completed stay in your PayPal account.';

$pageTitle = 'PayPal Account';
$pageDescription = 'Participants of your paid events pay you directly through PayPal. EVORA only records the transactions.';
$activeRoute = 'organizer-paypal-account';
include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
?>
<?php renderBreadcrumb([['label' => 'Dashboard', 'route' => 'organizer-dashboard'], ['label' => 'PayPal Account']]); ?>

<?php if (!PAYPAL_ENABLED): ?>
    <?= evoraAlert('warning', '<strong>PayPal is not enabled on this EVORA installation yet.</strong> Paid events keep using the current payment flow.') ?>
<?php elseif (!$account): ?>
    <?= evoraAlert('action', '<strong>Connect your PayPal Business account</strong> before publishing a paid event. Participants will pay you directly; EVORA never holds the money.', ['icon' => 'bi-paypal']) ?>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-7">
        <section class="card-evora p-4" aria-labelledby="ppHeading">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                <h2 class="h6 fw-semibold mb-0" id="ppHeading"><i class="bi bi-paypal text-primary me-1" aria-hidden="true"></i> Linked PayPal account</h2>
                <?php if (PAYPAL_ENV === 'sandbox'): ?><span class="badge bg-warning text-dark">Sandbox (test money)</span><?php endif; ?>
            </div>
            <?php if ($account): ?>
                <dl class="row small mb-3">
                    <dt class="col-sm-4">Status</dt><dd class="col-sm-8"><span class="badge bg-success">PayPal Connected</span> <span class="text-muted">· <?= $account['verification_method'] === 'paypal_login' ? 'Connected via PayPal' : 'Linked earlier by an administrator' ?></span></dd>
                    <dt class="col-sm-4">PayPal email</dt><dd class="col-sm-8"><?= e($account['email_masked'] ?? '—') ?></dd>
                    <dt class="col-sm-4">PayPal account ID</dt><dd class="col-sm-8"><code><?= e(PayPalAccountService::maskId($account['merchant_id'])) ?></code></dd>
                    <dt class="col-sm-4">Connected</dt><dd class="col-sm-8"><?= date('M d, Y g:i A', strtotime($account['connected_at'])) ?></dd>
                </dl>
                <p class="small text-muted">New paid events you open for registration use this account. Events already open keep the account they started with.</p>
                <?php if ($activePayPalEvents > 0): ?>
                <p class="small text-warning-emphasis"><i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i><?= $activePayPalEvents ?> active PayPal event(s) use this account<?= $awaitingPayment > 0 ? ' and ' . $awaitingPayment . ' registration(s) are still waiting for payment' : '' ?>. Disconnecting may stop participants of those events from completing new payments.</p>
                <?php endif; ?>
                <p class="small text-muted">Only you can connect or disconnect your PayPal account. It is not payment approval: each participant payment is verified and approved automatically.</p>
                <div class="d-flex flex-wrap gap-2">
                    <form method="post" action="<?= url('organizer-paypal-disconnect') ?>" class="m-0" data-confirm="<?= e($disconnectWarning) ?>" data-confirm-title="Disconnect PayPal?" data-confirm-variant="danger" data-confirm-button="Yes, disconnect">
                        <?= Csrf::field() ?><button class="btn btn-sm btn-outline-danger">Disconnect</button>
                    </form>
                </div>
            <?php elseif (PAYPAL_ENABLED && PayPalAccountService::oauthAvailable()): ?>
                <p class="small">Sign in to PayPal with your <strong>Business</strong> account. PayPal tells EVORA your account ID and EVORA checks with PayPal that it can receive payments. You never type your account ID, and EVORA never sees or stores your PayPal password.</p>
                <?php if ($hostSwitchUrl = PayPalAccountService::hostSwitchUrl()): ?>
                    <a href="<?= e($hostSwitchUrl) ?>" class="btn btn-evora-primary"><i class="bi bi-paypal me-1" aria-hidden="true"></i>Connect PayPal</a>
                    <p class="small text-muted mt-2 mb-0">EVORA will open at <?= e((string) parse_url($hostSwitchUrl, PHP_URL_HOST)) ?> (the address registered with PayPal). Sign in there once more, then click Connect PayPal.</p>
                <?php else: ?>
                <form method="post" action="<?= url('organizer-paypal-connect') ?>" class="m-0"><?= Csrf::field() ?>
                    <button class="btn btn-evora-primary"><i class="bi bi-paypal me-1" aria-hidden="true"></i>Connect PayPal</button>
                </form>
                <?php endif; ?>
            <?php elseif (PAYPAL_ENABLED): ?>
                <p class="small mb-2"><strong>Connect PayPal is not available yet.</strong> You will connect your own PayPal Business account here by signing in to PayPal — please check back later. Never share your PayPal password with anyone.</p>
                <p class="small text-muted mb-0">Connecting is separate from payments: once connected, each participant payment is verified and approved automatically — no one approves payments by hand.</p>
            <?php else: ?>
                <p class="small text-muted mb-0">Not available yet.</p>
            <?php endif; ?>
        </section>
    </div>
    <div class="col-lg-5">
        <section class="card-evora p-4" aria-labelledby="ppHowHeading">
            <h2 class="h6 fw-semibold mb-2" id="ppHowHeading">How direct payment works</h2>
            <ol class="small mb-0 ps-3">
                <li>A participant registers and pays with PayPal.</li>
                <li>The money goes straight to your PayPal account (PayPal deducts its own fee).</li>
                <li>EVORA confirms the payment with PayPal, approves the registration and issues the QR code.</li>
                <li>No payout request — you never wait for an administrator to send your earnings.</li>
            </ol>
        </section>
        <?php if ($history): ?>
        <section class="card-evora p-4 mt-3" aria-labelledby="ppHistHeading">
            <h2 class="h6 fw-semibold mb-2" id="ppHistHeading">History</h2>
            <ul class="list-unstyled small mb-0">
                <?php foreach ($history as $h): ?>
                    <li class="py-1 border-bottom"><?= e(PayPalAccountService::maskId($h['merchant_id'])) ?> · <?= e($h['environment']) ?> · <?= $h['status'] === 'connected' ? '<span class="text-success">connected</span>' : 'disconnected ' . date('M d, Y', strtotime((string) $h['disconnected_at'])) ?></li>
                <?php endforeach; ?>
            </ul>
        </section>
        <?php endif; ?>
    </div>
</div>
<?php include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php'; ?>
