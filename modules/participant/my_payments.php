<?php
// Payments & Receipts (v35): every payment the participant made, with a printable receipt for paid ones.
Auth::requireRole('participant');
$db = Database::connection();

$pStmt = $db->prepare('SELECT id FROM participants WHERE user_id = :uid');
$pStmt->execute(['uid' => Auth::id()]);
$participantId = (int) ($pStmt->fetchColumn() ?: 0);

$stmt = $db->prepare(
    'SELECT pay.id, pay.reference_no, pay.amount, pay.payment_channel, pay.status, pay.gateway_payment_id, COALESCE(pay.paid_at, pay.reviewed_at, pay.submitted_at) AS pay_date,
            e.name AS event_name, e.event_date, o.organization_name
     FROM payments pay
     JOIN registrations r ON r.id = pay.registration_id
     JOIN events e ON e.id = r.event_id
     JOIN organizers o ON o.id = e.organizer_id
     WHERE r.participant_id = :pid
     ORDER BY pay_date DESC, pay.id DESC'
);
$stmt->execute(['pid' => $participantId]);
$payments = $stmt->fetchAll();

// PayPal refunds the organizer owes / recorded (v36)
$refunds = RefundTrackingService::forParticipant($db, (int) Auth::id());
$totalPaid = $totalRefunded = 0.0;
foreach ($payments as $p) {
    if ($p['status'] === 'approved') {
        $totalPaid += (float) $p['amount'];
    }
    if ($p['status'] === 'refunded' || !empty($refunds[(int) $p['id']]['refund_id'])) {
        $totalRefunded += (float) $p['amount'];
    }
}
$statusBadge = ['approved' => ['Paid', 'bg-success'], 'pending' => ['Pending', 'bg-warning text-dark'], 'refunded' => ['Refunded', 'bg-info'],
    'failed' => ['Failed', 'bg-danger'], 'rejected' => ['Rejected', 'bg-danger'], 'cancelled' => ['Cancelled', 'bg-secondary']];

$pageTitle = 'Payments & Receipts';
$pageDescription = 'Every payment you made for your registrations, with receipts.';
$activeRoute = 'my-payments';
include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
?>
<?php renderBreadcrumb([['label' => 'Payments & Receipts']]); ?>
<div class="row g-3 mb-3">
    <div class="col-sm-6 col-lg-4"><div class="card-evora p-3"><div class="small text-muted">Total paid</div><div class="h4 fw-bold mb-0"><?= currency($totalPaid) ?></div></div></div>
    <div class="col-sm-6 col-lg-4"><div class="card-evora p-3"><div class="small text-muted">Refunded to you</div><div class="h4 fw-bold mb-0"><?= currency($totalRefunded) ?></div></div></div>
    <div class="col-sm-6 col-lg-4"><div class="card-evora p-3"><div class="small text-muted">Payments</div><div class="h4 fw-bold mb-0"><?= count($payments) ?></div></div></div>
</div>
<section class="card-evora p-4" aria-labelledby="myPayHeading">
    <h2 class="h6 fw-semibold mb-3" id="myPayHeading">Payment history</h2>
    <?php if (!$payments): ?>
        <?= emptyState('bi-receipt', 'You have no payments yet.', 'Browse Events', url('events')) ?>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table align-middle table-datatable" data-order='[[0,"desc"]]'>
            <thead><tr><th>Date</th><th>Event</th><th>Paid to</th><th>Method</th><th class="text-end">Amount</th><th>Status</th><th class="text-end">Receipt</th></tr></thead>
            <tbody>
            <?php foreach ($payments as $p): ?>
                <?php [$label, $badge] = $statusBadge[$p['status']] ?? [ucfirst($p['status']), 'bg-secondary']; ?>
                <tr>
                    <td class="small text-nowrap" data-order="<?= $p['pay_date'] ? strtotime($p['pay_date']) : 0 ?>"><?= $p['pay_date'] ? date('M d, Y g:i A', strtotime($p['pay_date'])) : '—' ?></td>
                    <td><div class="fw-semibold"><?= e($p['event_name']) ?></div><div class="small text-muted">Ref. <?= e($p['reference_no'] ?: ('#' . $p['id'])) ?></div></td>
                    <td class="small"><?= $p['payment_channel'] === 'paymongo' ? 'EVORA (PayMongo)' : e($p['organization_name']) ?></td>
                    <td class="small"><?= e(paymentChannelLabel($p['payment_channel'])) ?></td>
                    <td class="text-end fw-semibold text-nowrap"><?= currency($p['amount']) ?></td>
                    <td>
                        <span class="badge <?= $badge ?>"><?= e($label) ?></span>
                        <?php if ($rf = $refunds[(int) $p['id']] ?? null): ?>
                            <?php if (!$rf['refund_id']): ?>
                                <div class="small text-warning-emphasis mt-1"><i class="bi bi-hourglass-split" aria-hidden="true"></i> Refund due from the organizer (<?= $rf['event_status'] === 'cancelled' ? 'event cancelled' : 'registration ' . e($rf['registration_status']) ?>)</div>
                            <?php else: ?>
                                <div class="small mt-1"><span class="badge bg-info">Refunded by organizer</span><?php if ($rf['paypal_refund_id']): ?> <span class="text-muted">PayPal refund ID</span> <code><?= e($rf['paypal_refund_id']) ?></code><?php endif; ?><?php if ($rf['refund_proof_path']): ?> <a href="<?= uploadUrl($rf['refund_proof_path']) ?>"><i class="bi bi-image" aria-hidden="true"></i> View refund screenshot</a><?php endif; ?></div>
                                <?php if ($rf['participant_response'] === 'none'): ?>
                                    <form method="post" action="<?= url('my-refund-respond') ?>" class="d-flex flex-wrap gap-1 mt-1">
                                        <?= Csrf::field() ?><input type="hidden" name="payment_id" value="<?= (int) $p['id'] ?>">
                                        <input type="text" name="note" class="form-control form-control-sm" style="max-width:200px" placeholder="Note (optional)" maxlength="500" aria-label="Note about this refund">
                                        <button class="btn btn-sm btn-outline-success" name="response" value="received">I received it</button>
                                        <button class="btn btn-sm btn-outline-danger" name="response" value="not_received">Not received</button>
                                    </form>
                                <?php else: ?>
                                    <div class="small text-muted mt-1">You answered: <?= $rf['participant_response'] === 'received' ? 'received' : '<strong class="text-danger">not received</strong> — the organizer and administrators were notified' ?></div>
                                <?php endif; ?>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td class="text-end">
                        <?php if (in_array($p['status'], ['approved', 'refunded'], true)): ?>
                            <a class="btn btn-sm btn-outline-secondary" href="<?= url('my-payment-receipt', ['id' => $p['id']]) ?>" target="_blank" rel="noopener"><i class="bi bi-receipt me-1" aria-hidden="true"></i>View</a>
                        <?php else: ?><span class="small text-muted">—</span><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="small text-muted mt-3 mb-0">PayPal payments go directly to the event organizer; EVORA only records and verifies them. For refunds of PayPal payments, the organizer refunds you from their PayPal account.</p>
    <?php endif; ?>
</section>
<?php include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php'; ?>
