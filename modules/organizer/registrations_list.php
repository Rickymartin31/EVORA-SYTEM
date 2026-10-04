<?php
Auth::requireRole('organizer');
$db = Database::connection();
$userId = Auth::id();

$orgStmt = $db->prepare('SELECT id FROM organizers WHERE user_id = :uid');
$orgStmt->execute(['uid' => $userId]);
$organizer = $orgStmt->fetch();

$eventId = (int) ($_GET['event_id'] ?? 0);
$event = null;
if ($eventId) {
    $eventStmt = $db->prepare('SELECT * FROM events WHERE id = :id AND organizer_id = :oid');
    $eventStmt->execute(['id' => $eventId, 'oid' => $organizer['id'] ?? 0]);
    $event = $eventStmt->fetch();
    if (!$event) {
        flash('error', 'Event not found.');
        redirect('organizer-events');
    }
}

$filter = $_GET['status'] ?? 'all';
$sql = 'SELECT r.*, u.full_name, u.email, u.phone, e.name AS event_name,
            COALESCE(rc.fee, e.fee) AS fee,
            pay.id AS payment_id, pay.status AS payment_status, pay.payment_channel,
            pay.payment_method, pay.reference_no, pay.proof_path, pay.proof_hash,
            pay.rejection_reason AS payment_rejection_reason, pay.gateway_payment_method
        FROM registrations r
        JOIN participants p ON p.id = r.participant_id
        JOIN users u ON u.id = p.user_id
        JOIN events e ON e.id = r.event_id
        LEFT JOIN race_categories rc ON rc.id = r.race_category_id
        LEFT JOIN payments pay ON pay.registration_id = r.id
        WHERE e.organizer_id = :oid';
$params = ['oid' => $organizer['id'] ?? 0];
if ($eventId) {
    $sql .= ' AND r.event_id = :eid';
    $params['eid'] = $eventId;
}
if (in_array($filter, ['pending', 'approved', 'rejected', 'cancelled'], true)) {
    $sql .= ' AND r.status = :status';
    $params['status'] = $filter;
} elseif ($filter === 'payment_issues') {
    $sql .= ' AND pay.status IN ("failed", "cancelled")';
}
$sql .= ' ORDER BY r.registered_at DESC';
$stmt = $db->prepare($sql);
$stmt->execute($params);
$registrations = $stmt->fetchAll();

// Duplicate proof-image detection: any proof_hash shared by 2+ payments is a fraud signal
// (a reused screenshot) worth flagging to the organizer before they approve.
$dupStmt = $db->query(
    'SELECT proof_hash FROM payments WHERE proof_hash IS NOT NULL GROUP BY proof_hash HAVING COUNT(*) > 1'
);
$duplicateHashes = array_flip($dupStmt->fetchAll(PDO::FETCH_COLUMN));

$pageDescription = 'Review registrations and payments for your events.';
$pageTitle = 'Registrations';
$activeRoute = 'organizer-registrations';
include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
?>
<?php renderBreadcrumb($event ? [
    ['label' => 'My Events', 'route' => 'organizer-events'],
    ['label' => $event['name'], 'route' => 'organizer-event-manage', 'params' => ['id' => $eventId]],
    ['label' => 'Registrations'],
] : [
    ['label' => 'My Events', 'route' => 'organizer-events'],
    ['label' => 'Registrations'],
]); ?>

<div class="card-evora p-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h6 class="fw-semibold mb-0"><?= $event ? 'Registrations for ' . e($event['name']) : 'Registrations — All Events' ?></h6>
        <div class="btn-group btn-group-sm">
            <a href="<?= url('organizer-registrations', ['event_id' => $eventId]) ?>" class="btn btn-outline-secondary <?= $filter === 'all' ? 'active' : '' ?>">All</a>
            <a href="<?= url('organizer-registrations', ['event_id' => $eventId, 'status' => 'pending']) ?>" class="btn btn-outline-secondary <?= $filter === 'pending' ? 'active' : '' ?>">Pending</a>
            <a href="<?= url('organizer-registrations', ['event_id' => $eventId, 'status' => 'approved']) ?>" class="btn btn-outline-secondary <?= $filter === 'approved' ? 'active' : '' ?>">Approved</a>
            <a href="<?= url('organizer-registrations', ['event_id' => $eventId, 'status' => 'rejected']) ?>" class="btn btn-outline-secondary <?= $filter === 'rejected' ? 'active' : '' ?>">Rejected</a>
            <a href="<?= url('organizer-registrations', ['event_id' => $eventId, 'status' => 'cancelled']) ?>" class="btn btn-outline-secondary <?= $filter === 'cancelled' ? 'active' : '' ?>">Cancelled / Refunded</a>
            <a href="<?= url('organizer-registrations', ['event_id' => $eventId, 'status' => 'payment_issues']) ?>" class="btn btn-outline-secondary <?= $filter === 'payment_issues' ? 'active' : '' ?>">Payment Issues</a>
        </div>
    </div>
    <?php if (empty($registrations)): ?>
        <?= emptyState('bi-clipboard-x', 'No registrations here yet. They appear as participants register; try another filter to see more.') ?>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table align-middle table-datatable">
            <thead><tr><th>Participant</th><th>Email</th><?php if (!$event): ?><th>Event</th><?php endif; ?><th>Category</th><th>Fee</th><th>Payment</th><th>Registered</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($registrations as $r): ?>
                <?php
                $fee = (float) $r['fee'];
                $isDuplicateProof = $r['proof_hash'] && isset($duplicateHashes[$r['proof_hash']]);
                ?>
                <tr>
                    <td><?= e($r['full_name']) ?></td>
                    <td><?= e($r['email']) ?></td>
                    <?php if (!$event): ?><td><?= e($r['event_name']) ?></td><?php endif; ?>
                    <td><?= e($r['distance_category'] ?? '—') ?></td>
                    <td><?= $fee > 0 ? currency($fee) : 'Free' ?></td>
                    <td class="small">
                        <?php if (!$r['payment_id']): ?>
                            <?= $fee > 0 ? '<span class="text-muted">Awaiting payment</span>' : '—' ?>
                        <?php else: ?>
                            <span class="badge <?= in_array($r['payment_channel'], ['paymongo', 'paypal'], true) ? 'bg-primary' : 'bg-light text-dark border' ?>"><?= e(paymentChannelLabel($r['payment_channel'])) ?></span>
                            <span class="badge badge-status-<?= e($r['payment_status']) ?>"><?= ucfirst(e($r['payment_status'])) ?></span>
                            <div class="mt-1">
                                <?php if (in_array($r['payment_channel'], ['paymongo', 'paypal'], true)): ?>
                                    <?= $r['gateway_payment_method'] ? e(ucfirst($r['gateway_payment_method'])) : '' ?>
                                <?php else: ?>
                                    <?= $r['payment_method'] ? e(ucfirst(str_replace('_', ' ', $r['payment_method']))) : '' ?><?= $r['reference_no'] ? ' &bull; ' . e($r['reference_no']) : '' ?>
                                <?php endif; ?>
                                <?php if ($r['proof_path']): ?> &bull; <a href="<?= uploadUrl($r['proof_path']) ?>" target="_blank">View Proof</a><?php endif; ?>
                            </div>
                            <?php if ($isDuplicateProof): ?>
                                <div class="text-danger fw-semibold mt-1"><i class="bi bi-exclamation-triangle-fill"></i> Same proof image used in another submission — verify carefully.</div>
                            <?php endif; ?>
                            <?php if ($r['payment_status'] === 'rejected' && $r['payment_rejection_reason']): ?>
                                <div class="text-danger mt-1">Reason: <?= e($r['payment_rejection_reason']) ?></div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td><?= date('M d, Y', strtotime($r['registered_at'])) ?></td>
                    <td><span class="badge badge-status-<?= e($r['status']) ?>"><?= ucfirst(e($r['status'])) ?></span></td>
                    <td>
                        <?php if ($r['status'] === 'pending' && !$r['payment_id']): ?>
                            <form method="post" action="<?= url('organizer-registration-action') ?>" class="d-inline">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="registration_id" value="<?= $r['id'] ?>">
                                <input type="hidden" name="event_id" value="<?= $r['event_id'] ?>">
                                <input type="hidden" name="return_event_id" value="<?= $eventId ?>">
                                <input type="hidden" name="return_status" value="<?= e($filter) ?>">
                                <button type="submit" name="action" value="approve" class="btn btn-sm btn-outline-success">Approve</button>
                                <button type="submit" name="action" value="reject" class="btn btn-sm btn-outline-danger">Reject</button>
                            </form>
                        <?php elseif (in_array($r['payment_channel'], ['paymongo', 'paypal'], true)): ?>
                            <span class="text-muted small">Gateway-verified</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
<?php include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php'; ?>
