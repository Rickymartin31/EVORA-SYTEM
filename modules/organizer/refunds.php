<?php
// Organizer: Refunds (v36) — PayPal payments to refund after a cancellation, and refunds already recorded.
Auth::requireRole('organizer');
$db = Database::connection();
$orgStmt = $db->prepare('SELECT id FROM organizers WHERE user_id = :uid');
$orgStmt->execute(['uid' => Auth::id()]);
$organizerId = (int) ($orgStmt->fetchColumn() ?: 0);

$rows = RefundTrackingService::forOrganizer($db, $organizerId);
$due = array_values(array_filter($rows, fn($r) => !$r['refund_id']));
$done = array_values(array_filter($rows, fn($r) => (bool) $r['refund_id']));
$dueTotal = array_sum(array_map(fn($r) => (float) $r['amount'], $due));
$responseBadge = ['none' => ['Waiting for participant', 'bg-secondary'], 'received' => ['Participant confirmed', 'bg-success'], 'not_received' => ['Participant: NOT received', 'bg-danger']];

$pageTitle = 'Refunds';
$pageDescription = 'Refund participants who paid you through PayPal for an event that will not happen.';
$activeRoute = 'organizer-refunds';
include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
?>
<?php renderBreadcrumb([['label' => 'Refunds']]); ?>
<?= evoraAlert('info', '<strong>How refunds work.</strong> PayPal payments went straight to <strong>your</strong> PayPal account, so you refund them from PayPal: open PayPal → Activity → find the payment (search the transaction ID below) → <strong>Issue a refund</strong> of the full amount. Then copy the refund\'s transaction ID and record it here. The participant is notified and confirms whether they received it.', ['class' => 'small']) ?>

<div class="row g-3 mb-3">
    <div class="col-sm-6 col-lg-3"><div class="card-evora p-3"><div class="small text-muted">Refunds to make</div><div class="h4 fw-bold mb-0 <?= $due ? 'text-danger' : '' ?>"><?= count($due) ?></div></div></div>
    <div class="col-sm-6 col-lg-3"><div class="card-evora p-3"><div class="small text-muted">Amount to refund</div><div class="h4 fw-bold mb-0"><?= currency($dueTotal) ?></div></div></div>
    <div class="col-sm-6 col-lg-3"><div class="card-evora p-3"><div class="small text-muted">Refunds recorded</div><div class="h4 fw-bold mb-0"><?= count($done) ?></div></div></div>
</div>

<section class="card-evora p-4 mb-3" aria-labelledby="dueHeading">
    <h2 class="h6 fw-semibold mb-3" id="dueHeading">Refunds to make</h2>
    <?php if (!$due): ?>
        <?= emptyState('bi-check2-circle', 'Nothing to refund. When a paid event is cancelled, its PayPal payments appear here.') ?>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table align-middle">
            <thead><tr><th>Participant</th><th>Event</th><th>Why</th><th class="text-end">Amount</th><th>PayPal payment ID</th><th style="min-width:300px;">Record the refund</th></tr></thead>
            <tbody>
            <?php foreach ($due as $r): ?>
                <tr>
                    <td><div class="fw-semibold"><?= e($r['participant_name']) ?></div><div class="small text-muted"><?= e($r['participant_email']) ?></div></td>
                    <td class="small"><?= e($r['event_name']) ?></td>
                    <td class="small"><?= $r['event_status'] === 'cancelled' ? 'Event cancelled' : 'Registration ' . e($r['registration_status']) ?></td>
                    <td class="text-end fw-semibold"><?= currency($r['amount']) ?></td>
                    <td class="small"><code><?= e($r['capture_id'] ?: '—') ?></code><div class="text-muted">paid <?= $r['paid_at'] ? date('M d, Y', strtotime($r['paid_at'])) : '—' ?></div></td>
                    <td>
                        <form method="post" action="<?= url('organizer-refund-record') ?>" class="d-flex flex-column gap-1" data-confirm="Record this refund? It cannot be edited afterwards, so make sure you already refunded <?= e(currency($r['amount'])) ?> in PayPal." data-confirm-title="Record refund?" data-confirm-button="Yes, record it">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="payment_id" value="<?= (int) $r['payment_id'] ?>">
                            <input type="text" name="paypal_refund_id" class="form-control form-control-sm" placeholder="PayPal refund transaction ID" required maxlength="25" pattern="[A-Za-z0-9]{12,25}" aria-label="PayPal refund transaction ID for <?= e($r['participant_name']) ?>">
                            <input type="text" name="note" class="form-control form-control-sm" placeholder="Note (optional)" maxlength="500" aria-label="Note">
                            <button class="btn btn-sm btn-evora-primary">Record Refund</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</section>

<section class="card-evora p-4" aria-labelledby="doneHeading">
    <h2 class="h6 fw-semibold mb-3" id="doneHeading">Recorded refunds</h2>
    <?php if (!$done): ?>
        <?= emptyState('bi-arrow-counterclockwise', 'No refunds recorded yet. Refunds you record for cancelled paid registrations appear here.') ?>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-sm align-middle table-datatable">
            <thead><tr><th>Recorded</th><th>Participant</th><th>Event</th><th class="text-end">Amount</th><th>PayPal refund ID</th><th>Participant answer</th></tr></thead>
            <tbody>
            <?php foreach ($done as $r): ?>
                <?php [$label, $badge] = $responseBadge[$r['participant_response']] ?? ['—', 'bg-secondary']; ?>
                <tr>
                    <td class="small text-nowrap" data-order="<?= strtotime($r['recorded_at']) ?>"><?= date('M d, Y g:i A', strtotime($r['recorded_at'])) ?></td>
                    <td class="small"><?= e($r['participant_name']) ?></td>
                    <td class="small"><?= e($r['event_name']) ?></td>
                    <td class="text-end"><?= currency($r['amount']) ?></td>
                    <td class="small"><code><?= e($r['paypal_refund_id']) ?></code><?= $r['refund_note'] ? '<div class="text-muted">' . e($r['refund_note']) . '</div>' : '' ?></td>
                    <td class="small"><span class="badge <?= $badge ?>"><?= e($label) ?></span><?= $r['participant_note'] ? '<div class="text-muted">' . e($r['participant_note']) . '</div>' : '' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</section>
<?php include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php'; ?>
