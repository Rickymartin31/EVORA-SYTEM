<?php
Auth::requireRole('organizer');
$db = Database::connection();
$userId = Auth::id();

$orgStmt = $db->prepare('SELECT id FROM organizers WHERE user_id = :uid');
$orgStmt->execute(['uid' => $userId]);
$organizer = $orgStmt->fetch();
$organizerId = (int) ($organizer['id'] ?? 0);

$myEventsStmt = $db->prepare('SELECT id, name FROM events WHERE organizer_id = :oid ORDER BY event_date DESC');
$myEventsStmt->execute(['oid' => $organizerId]);
$myEvents = $myEventsStmt->fetchAll();

// --- Filters (all optional, all server-validated against a fixed allow-list where relevant) ---
$eventId = (int) ($_GET['event_id'] ?? 0);
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$channelFilter = $_GET['channel'] ?? '';

$allowedStatuses = ['pending', 'approved', 'rejected', 'failed', 'cancelled', 'refunded'];
$allowedChannels = ['paypal', 'paymongo', 'cash_walkin', 'manual'];

$sql = "SELECT u.full_name AS participant_name, e.id AS event_id, e.name AS event_name,
            r.distance_category, pay.payment_channel, pay.amount, pay.status,
            pay.gateway_payment_id, pay.paid_at, pay.submitted_at, pay.reviewed_at,
            rv.full_name AS processed_by_name
        FROM payments pay
        JOIN registrations r ON r.id = pay.registration_id
        JOIN participants p ON p.id = r.participant_id
        JOIN users u ON u.id = p.user_id
        JOIN events e ON e.id = r.event_id
        LEFT JOIN users rv ON rv.id = pay.reviewed_by
        WHERE e.organizer_id = :oid";
$params = ['oid' => $organizerId];

if ($eventId > 0) {
    $sql .= ' AND e.id = :event_id';
    $params['event_id'] = $eventId;
}
if ($dateFrom !== '') {
    $sql .= ' AND DATE(pay.submitted_at) >= :date_from';
    $params['date_from'] = $dateFrom;
}
if ($dateTo !== '') {
    $sql .= ' AND DATE(pay.submitted_at) <= :date_to';
    $params['date_to'] = $dateTo;
}
if (in_array($statusFilter, $allowedStatuses, true)) {
    $sql .= ' AND pay.status = :status';
    $params['status'] = $statusFilter;
}
if (in_array($channelFilter, $allowedChannels, true)) {
    $sql .= ' AND pay.payment_channel = :channel';
    $params['channel'] = $channelFilter;
}
$sql .= ' ORDER BY pay.submitted_at DESC LIMIT 500';

$stmt = $db->prepare($sql);
$stmt->execute($params);
$transactions = $stmt->fetchAll();

// Retired channels (PayMongo, manual) only appear in the filter when this organizer has old records in them.
$legacyStmt = $db->prepare("SELECT DISTINCT pay.payment_channel FROM payments pay JOIN registrations r ON r.id = pay.registration_id
    JOIN events e ON e.id = r.event_id WHERE e.organizer_id = :oid AND pay.payment_channel IN ('paymongo', 'manual')");
$legacyStmt->execute(['oid' => $organizerId]);
$legacyChannels = $legacyStmt->fetchAll(PDO::FETCH_COLUMN);

$pageDescription = 'Every payment made for your events.';
$pageTitle = 'Transaction History';
$activeRoute = 'organizer-payment-transactions';
include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
?>
<?php renderBreadcrumb([
    ['label' => 'Payment Dashboard', 'route' => 'organizer-payment-dashboard'],
    ['label' => 'Transaction History'],
]); ?>

<div class="card-evora p-4 mb-3">
    <form method="get" action="<?= url('organizer-payment-transactions') ?>" class="row g-2 align-items-end">
        <input type="hidden" name="route" value="organizer-payment-transactions">
        <div class="col-md-3">
            <label class="form-label small">Event</label>
            <select name="event_id" class="form-select form-select-sm">
                <option value="0">All Events</option>
                <?php foreach ($myEvents as $ev): ?>
                    <option value="<?= $ev['id'] ?>" <?= $eventId === (int) $ev['id'] ? 'selected' : '' ?>><?= e($ev['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label small">From</label>
            <input type="date" name="date_from" class="form-control form-control-sm" value="<?= e($dateFrom) ?>">
        </div>
        <div class="col-md-2">
            <label class="form-label small">To</label>
            <input type="date" name="date_to" class="form-control form-control-sm" value="<?= e($dateTo) ?>">
        </div>
        <div class="col-md-2">
            <label class="form-label small">Status</label>
            <select name="status" class="form-select form-select-sm">
                <option value="">Any</option>
                <?php foreach ($allowedStatuses as $s): ?>
                    <option value="<?= $s ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label small">Channel</label>
            <select name="channel" class="form-select form-select-sm">
                <option value="">Any</option>
                <option value="paypal" <?= $channelFilter === 'paypal' ? 'selected' : '' ?>>PayPal</option>
                <option value="cash_walkin" <?= $channelFilter === 'cash_walkin' ? 'selected' : '' ?>>Cash Walk-in</option>
                <?php if (in_array('paymongo', $legacyChannels, true) || $channelFilter === 'paymongo'): ?>
                <option value="paymongo" <?= $channelFilter === 'paymongo' ? 'selected' : '' ?>>PayMongo (historical)</option>
                <?php endif; ?>
                <?php if (in_array('manual', $legacyChannels, true) || $channelFilter === 'manual'): ?>
                <option value="manual" <?= $channelFilter === 'manual' ? 'selected' : '' ?>>Manual (historical)</option>
                <?php endif; ?>
            </select>
        </div>
        <div class="col-md-1">
            <button type="submit" class="btn btn-evora-primary btn-sm w-100">Filter</button>
        </div>
    </form>
</div>

<div class="card-evora p-4">
    <h6 class="fw-semibold mb-3"><?= count($transactions) ?> transaction(s)</h6>
    <?php if (empty($transactions)): ?>
        <?= emptyState('bi-receipt', 'No transactions match these filters.') ?>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-sm align-middle table-datatable">
            <thead><tr>
                <th>Participant</th><th>Event</th><th>Category</th><th>Channel</th><th>Amount</th><th>Status</th>
                <th>Transaction ID</th><th>Payment Date</th><th>Processed By</th><th>Created</th>
            </tr></thead>
            <tbody>
            <?php foreach ($transactions as $t): ?>
                <?php $paymentDate = $t['paid_at'] ?? $t['reviewed_at']; ?>
                <tr>
                    <td><?= e($t['participant_name']) ?></td>
                    <td><?= e($t['event_name']) ?></td>
                    <td><?= e($t['distance_category'] ?? '—') ?></td>
                    <td>
                        <span class="badge <?= in_array($t['payment_channel'], ['paymongo', 'paypal'], true) ? 'bg-primary' : ($t['payment_channel'] === 'cash_walkin' ? 'bg-warning text-dark' : 'bg-light text-dark border') ?>">
                            <?= e(paymentChannelLabel($t['payment_channel'])) ?>
                        </span>
                    </td>
                    <td class="fw-semibold"><?= currency($t['amount']) ?></td>
                    <td><span class="badge badge-status-<?= e($t['status']) ?>"><?= ucfirst(e($t['status'])) ?></span></td>
                    <td class="small"><?= e($t['gateway_payment_id'] ?? '—') ?></td>
                    <td><?= $paymentDate ? date('M d, Y H:i', strtotime($paymentDate)) : '—' ?></td>
                    <td><?= e($t['processed_by_name'] ?? '—') ?></td>
                    <td><?= date('M d, Y', strtotime($t['submitted_at'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if (count($transactions) === 500): ?>
        <div class="form-text mt-2">Showing the most recent 500 — narrow the filters above to see more specific results.</div>
    <?php endif; ?>
    <?php endif; ?>
</div>
<?php include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php'; ?>
