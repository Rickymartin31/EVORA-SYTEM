<?php
Auth::requireRole('organizer');
$db = Database::connection();
$userId = Auth::id();

$orgStmt = $db->prepare('SELECT id FROM organizers WHERE user_id = :uid');
$orgStmt->execute(['uid' => $userId]);
$organizer = $orgStmt->fetch();
$organizerId = (int) ($organizer['id'] ?? 0);

// --- Feature 1: per-event payment summary ---
$eventsStmt = $db->prepare(
    "SELECT e.id, e.name, e.event_date, e.status,
        (SELECT COUNT(*) FROM registrations r WHERE r.event_id = e.id) AS registered,
        (SELECT COUNT(*) FROM payments p JOIN registrations r ON r.id = p.registration_id WHERE r.event_id = e.id AND p.status = 'approved') AS paid,
        (SELECT COUNT(*) FROM payments p JOIN registrations r ON r.id = p.registration_id WHERE r.event_id = e.id AND p.status = 'pending') AS pending_pay,
        (SELECT COUNT(*) FROM payments p JOIN registrations r ON r.id = p.registration_id WHERE r.event_id = e.id AND p.status IN ('failed','cancelled')) AS failed_pay,
        (SELECT COUNT(*) FROM payments p JOIN registrations r ON r.id = p.registration_id WHERE r.event_id = e.id AND p.payment_channel = 'cash_walkin' AND p.status = 'approved') AS cash_count,
        (SELECT COALESCE(SUM(p.amount), 0) FROM payments p JOIN registrations r ON r.id = p.registration_id WHERE r.event_id = e.id AND p.status = 'approved') AS revenue
     FROM events e WHERE e.organizer_id = :oid ORDER BY e.event_date DESC"
);
$eventsStmt->execute(['oid' => $organizerId]);
$eventSummaries = $eventsStmt->fetchAll();

// --- Feature 2: payment channel breakdown (current channels; manual shown separately as historical) ---
$channelStmt = $db->prepare(
    "SELECT pay.payment_channel, COUNT(*) c, COALESCE(SUM(pay.amount), 0) total
     FROM payments pay
     JOIN registrations r ON r.id = pay.registration_id
     JOIN events e ON e.id = r.event_id
     WHERE e.organizer_id = :oid AND pay.status = 'approved'
     GROUP BY pay.payment_channel"
);
$channelStmt->execute(['oid' => $organizerId]);
$channelRaw = [];
foreach ($channelStmt->fetchAll() as $row) {
    $channelRaw[$row['payment_channel']] = ['count' => (int) $row['c'], 'total' => (float) $row['total']];
}
$paypalChannel = $channelRaw['paypal'] ?? ['count' => 0, 'total' => 0];
$paymongoChannel = $channelRaw['paymongo'] ?? ['count' => 0, 'total' => 0];
$cashChannel = $channelRaw['cash_walkin'] ?? ['count' => 0, 'total' => 0];
$manualChannel = $channelRaw['manual'] ?? ['count' => 0, 'total' => 0];

// --- Feature 5: cash walk-in accountability, grouped by the staff/organizer who recorded it ---
$cashAcctStmt = $db->prepare(
    "SELECT u.full_name AS staff_name, COUNT(*) transactions, COALESCE(SUM(pay.amount), 0) collected
     FROM payments pay
     JOIN registrations r ON r.id = pay.registration_id
     JOIN events e ON e.id = r.event_id
     JOIN users u ON u.id = pay.reviewed_by
     WHERE e.organizer_id = :oid AND pay.payment_channel = 'cash_walkin' AND pay.status = 'approved'
     GROUP BY pay.reviewed_by, u.full_name
     ORDER BY collected DESC"
);
$cashAcctStmt->execute(['oid' => $organizerId]);
$cashAccountability = $cashAcctStmt->fetchAll();

$totalRevenue = array_sum(array_column($eventSummaries, 'revenue'));

$pageDescription = 'Payments collected for your events by channel and status.';
$pageTitle = 'Payment Dashboard';
$activeRoute = 'organizer-payment-dashboard';
include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
?>
<?php renderBreadcrumb([
    ['label' => 'Payment Dashboard'],
]); ?>

<div class="row g-3 mb-3">
    <div class="col-sm-6 col-lg-3">
        <div class="stat-card accent-success"><i class="bi bi-cash-stack fs-4 text-muted"></i><div class="stat-value"><?= currency($totalRevenue) ?></div><div class="stat-label">Total Revenue</div><div class="stat-desc">All channels, all events</div></div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="stat-card accent-info"><i class="bi bi-paypal fs-4 text-muted"></i><div class="stat-value"><?= currency($paypalChannel['total']) ?></div><div class="stat-label">PayPal</div><div class="stat-desc"><?= $paypalChannel['count'] ?> transaction(s), paid directly to you</div></div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="stat-card accent-warning"><i class="bi bi-cash fs-4 text-muted"></i><div class="stat-value"><?= currency($cashChannel['total']) ?></div><div class="stat-label">Cash Walk-in</div><div class="stat-desc"><?= $cashChannel['count'] ?> transaction(s)</div></div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <a href="<?= url('organizer-payment-transactions') ?>" class="text-decoration-none">
        <div class="stat-card"><i class="bi bi-list-check fs-4 text-muted"></i><div class="stat-value">View</div><div class="stat-label">Transaction History</div><div class="stat-desc">Filter by event, date, status, channel</div></div>
        </a>
    </div>
</div>

<div class="alert alert-light border small mb-3">
    <i class="bi bi-wallet2 text-primary me-1"></i> PayPal payments go directly to your PayPal Business account — EVORA verifies them automatically and does not hold the money.
    Cash walk-in money above is already with you.
</div>

<?php if ($paymongoChannel['count'] > 0): ?>
<div class="alert alert-light border small mb-3">
    <i class="bi bi-archive text-muted me-1"></i> Historical (PayMongo — legacy): <strong><?= currency($paymongoChannel['total']) ?></strong> across <?= $paymongoChannel['count'] ?> transaction(s) from before PayPal. Included in Total Revenue above.
</div>
<?php endif; ?>

<?php if ($manualChannel['count'] > 0): ?>
<div class="alert alert-light border small mb-3">
    <i class="bi bi-archive text-muted me-1"></i> Historical (Manual — deprecated): <strong><?= currency($manualChannel['total']) ?></strong> across <?= $manualChannel['count'] ?> transaction(s) approved before manual payment was phased out. Included in Total Revenue above, excluded from the PayPal/Cash Walk-in channel figures.
</div>
<?php endif; ?>

<div class="card-evora p-4 mb-3">
    <h6 class="fw-semibold mb-3"><i class="bi bi-clipboard-data text-primary me-1"></i> Event Payment Summary</h6>
    <?php if (empty($eventSummaries)): ?>
        <?= emptyState('bi-calendar-x', 'No events yet.', 'Create Event', url('organizer-event-type')) ?>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table align-middle table-datatable">
            <thead><tr><th>Event</th><th>Date</th><th>Registered</th><th>Paid</th><th>Pending</th><th>Failed</th><th>Cash Walk-in</th><th>Revenue</th><th>Export</th></tr></thead>
            <tbody>
            <?php foreach ($eventSummaries as $ev): ?>
                <tr>
                    <td><a href="<?= url('organizer-payment-transactions', ['event_id' => $ev['id']]) ?>"><?= e($ev['name']) ?></a></td>
                    <td><?= date('M d, Y', strtotime($ev['event_date'])) ?></td>
                    <td><?= (int) $ev['registered'] ?></td>
                    <td class="text-success fw-semibold"><?= (int) $ev['paid'] ?></td>
                    <td class="text-warning"><?= (int) $ev['pending_pay'] ?></td>
                    <td class="text-danger"><?= (int) $ev['failed_pay'] ?></td>
                    <td><?= (int) $ev['cash_count'] ?></td>
                    <td class="fw-semibold"><?= currency($ev['revenue']) ?></td>
                    <td>
                        <a href="<?= url('organizer-financial-report-export', ['event_id' => $ev['id'], 'format' => 'pdf']) ?>" class="btn btn-sm btn-outline-danger" title="Export PDF" aria-label="Export PDF" data-bs-toggle="tooltip"><i class="bi bi-file-earmark-pdf" aria-hidden="true"></i></a>
                        <a href="<?= url('organizer-financial-report-export', ['event_id' => $ev['id'], 'format' => 'xlsx']) ?>" class="btn btn-sm btn-outline-success" title="Export Excel" aria-label="Export Excel" data-bs-toggle="tooltip"><i class="bi bi-file-earmark-excel" aria-hidden="true"></i></a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<div class="card-evora p-4">
    <h6 class="fw-semibold mb-3"><i class="bi bi-person-badge text-primary me-1"></i> Cash Walk-in Collection Report</h6>
    <?php if (empty($cashAccountability)): ?>
        <?= emptyState('bi-cash', 'No cash walk-in payments recorded yet.') ?>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table align-middle table-datatable">
            <thead><tr><th>Staff / Organizer</th><th>Transactions</th><th>Collected</th></tr></thead>
            <tbody>
            <?php foreach ($cashAccountability as $row): ?>
                <tr>
                    <td><?= e($row['staff_name']) ?></td>
                    <td><?= (int) $row['transactions'] ?></td>
                    <td class="fw-semibold"><?= currency($row['collected']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
<?php include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php'; ?>
