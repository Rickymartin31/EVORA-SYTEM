<?php
// Admin: Refund Tracking (v36) — monitoring only. PayPal refunds are made by organizers from their
// own PayPal accounts; this shows which are still owed, recorded, confirmed or reported missing.
Auth::requireRole('admin');
$db = Database::connection();
$rows = RefundTrackingService::all($db);

$count = ['due' => 0, 'waiting' => 0, 'received' => 0, 'not_received' => 0];
$dueTotal = 0.0;
foreach ($rows as $r) {
    if (!$r['refund_id']) {
        $count['due']++;
        $dueTotal += (float) $r['amount'];
    } else {
        $count[$r['participant_response'] === 'none' ? 'waiting' : $r['participant_response']]++;
    }
}

$pageTitle = 'Refund Tracking';
$pageDescription = 'PayPal refunds organizers owe and have made after cancellations.';
$activeRoute = 'admin-refund-tracking';
include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
?>
<?php renderBreadcrumb([['label' => 'Refund Tracking']]); ?>
<?= evoraAlert('info', '<strong>Monitoring only.</strong> PayPal payments go directly to organizers, so organizers refund them from their own PayPal accounts and record the refund ID in EVORA. Participants then confirm whether they received it. Follow up with the organizer on anything still owed or reported as not received.', ['class' => 'small']) ?>
<div class="row g-3 mb-3">
    <div class="col-sm-6 col-lg-3"><div class="card-evora p-3"><div class="small text-muted">Still owed by organizers</div><div class="h4 fw-bold mb-0 <?= $count['due'] ? 'text-danger' : '' ?>"><?= $count['due'] ?> <span class="small fw-normal text-muted">(<?= currency($dueTotal) ?>)</span></div></div></div>
    <div class="col-sm-6 col-lg-3"><div class="card-evora p-3"><div class="small text-muted">Recorded, awaiting participant</div><div class="h4 fw-bold mb-0"><?= $count['waiting'] ?></div></div></div>
    <div class="col-sm-6 col-lg-3"><div class="card-evora p-3"><div class="small text-muted">Confirmed received</div><div class="h4 fw-bold mb-0"><?= $count['received'] ?></div></div></div>
    <div class="col-sm-6 col-lg-3"><div class="card-evora p-3"><div class="small text-muted">Reported NOT received</div><div class="h4 fw-bold mb-0 <?= $count['not_received'] ? 'text-danger' : '' ?>"><?= $count['not_received'] ?></div></div></div>
</div>
<section class="card-evora p-4" aria-labelledby="rtHeading">
    <h2 class="h6 fw-semibold mb-3" id="rtHeading">PayPal refunds</h2>
    <?php if (!$rows): ?>
        <?= emptyState('bi-check2-circle', 'No PayPal refunds are owed or recorded.') ?>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-sm align-middle table-datatable">
            <thead><tr><th>Status</th><th>Organizer</th><th>Event</th><th>Participant</th><th class="text-end">Amount</th><th>PayPal payment / refund ID</th><th>Recorded</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <?php
                [$label, $badge] = !$r['refund_id'] ? ['Owed', 'bg-danger'] : match ($r['participant_response']) {
                    'received' => ['Received', 'bg-success'],
                    'not_received' => ['NOT received', 'bg-danger'],
                    default => ['Recorded', 'bg-info'],
                };
                ?>
                <tr>
                    <td><span class="badge <?= $badge ?>"><?= e($label) ?></span></td>
                    <td class="small"><?= e($r['organization_name']) ?></td>
                    <td class="small"><?= e($r['event_name']) ?><div class="text-muted"><?= $r['event_status'] === 'cancelled' ? 'Event cancelled' : 'Registration ' . e($r['registration_status']) ?></div></td>
                    <td class="small"><?= e($r['participant_name']) ?></td>
                    <td class="text-end"><?= currency($r['amount']) ?></td>
                    <td class="small"><code><?= e($r['capture_id'] ?: '—') ?></code><?= $r['paypal_refund_id'] ? '<div>Refund: <code>' . e($r['paypal_refund_id']) . '</code></div>' : '' ?><?= $r['participant_note'] ? '<div class="text-muted">Participant: ' . e($r['participant_note']) . '</div>' : '' ?></td>
                    <td class="small text-nowrap"><?= $r['recorded_at'] ? date('M d, Y', strtotime($r['recorded_at'])) : '—' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</section>
<?php include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php'; ?>
