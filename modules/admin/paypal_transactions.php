<?php
// Admin: READ-ONLY monitor of PayPal payments. These go straight to organizers' PayPal
// accounts — there is nothing to approve, process or pay out here.
Auth::requireRole('admin');
$db = Database::connection();
$statuses = ['created', 'approved', 'completed', 'failed', 'cancelled', 'expired', 'refunded'];
$status = in_array($_GET['status'] ?? '', $statuses, true) ? $_GET['status'] : '';
$sql = 'SELECT t.*, e.name AS event_name, u.full_name AS participant_name, o.organization_name, a.verification_method
        FROM paypal_transactions t
        JOIN events e ON e.id = t.event_id
        JOIN registrations r ON r.id = t.registration_id JOIN participants p ON p.id = r.participant_id JOIN users u ON u.id = p.user_id
        JOIN organizers o ON o.id = t.organizer_id
        JOIN organizer_paypal_accounts a ON a.id = t.organizer_paypal_account_id'
     . ($status ? ' WHERE t.paypal_status = ' . $db->quote($status) : '') . ' ORDER BY t.id DESC LIMIT 500';
$rows = $db->query($sql)->fetchAll();
$totals = $db->query('SELECT paypal_status, COUNT(*) c, COALESCE(SUM(amount), 0) amt FROM paypal_transactions GROUP BY paypal_status')->fetchAll(PDO::FETCH_UNIQUE);

$pageTitle = 'PayPal Transactions';
$pageDescription = 'PayPal Transaction Monitoring (read-only): payments paid directly to organizers through PayPal and verified by EVORA.';
$activeRoute = 'admin-paypal-transactions';
include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
?>
<?php renderBreadcrumb([['label' => 'PayPal Transactions']]); ?>
<?= evoraAlert('info', '<strong>PayPal Transaction Monitoring.</strong> These payments were <strong>paid directly to each organizer\'s PayPal account</strong>. EVORA does not collect or hold PayPal funds: it only verified each payment with PayPal and recorded it. Payments are approved automatically after verification — there is nothing to approve, and no payout to send.', ['class' => 'small']) ?>
<div class="row g-3 mb-3">
    <?php foreach (['completed' => 'Completed', 'failed' => 'Failed', 'cancelled' => 'Cancelled'] as $k => $label): ?>
        <div class="col-sm-4"><div class="card-evora p-3"><div class="small text-muted"><?= $label ?></div><div class="h5 mb-0"><?= (int) ($totals[$k]['c'] ?? 0) ?><?= $k === 'completed' ? ' · ' . currency($totals[$k]['amt'] ?? 0) : '' ?></div></div></div>
    <?php endforeach; ?>
</div>
<section class="card-evora p-4" aria-labelledby="pptHeading">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h2 class="h6 fw-semibold mb-0" id="pptHeading">PayPal Payment Status</h2>
        <form method="get" class="d-flex gap-2"><input type="hidden" name="route" value="admin-paypal-transactions">
            <select name="status" class="form-select form-select-sm" aria-label="Status"><option value="">All statuses</option><?php foreach ($statuses as $s): ?><option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option><?php endforeach; ?></select>
            <button class="btn btn-sm btn-outline-secondary">Filter</button></form>
    </div>
    <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
        <thead><tr><th>Created / Updated</th><th>Registration</th><th>Event</th><th>Participant</th><th>Organizer (paid directly)</th><th>PayPal order</th><th>Capture (transaction)</th><th class="text-end">Amount</th><th class="text-end">PayPal fee</th><th class="text-end">Net to organizer</th><th>Status</th><th>Verification</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $t): ?>
            <tr>
                <td class="small text-nowrap"><?= date('M d, Y g:i A', strtotime($t['created_at'])) ?><div class="text-muted"><?= date('M d, Y g:i A', strtotime($t['updated_at'])) ?></div></td>
                <td class="small">#<?= (int) $t['registration_id'] ?></td>
                <td class="small"><?= e($t['event_name']) ?></td>
                <td class="small"><?= e($t['participant_name']) ?></td>
                <td class="small"><?= e($t['organization_name']) ?><div class="text-muted">Payee <code><?= e(PayPalAccountService::maskId((string) $t['merchant_id'])) ?></code></div></td>
                <td><code class="small"><?= e((string) $t['paypal_order_id']) ?></code></td>
                <td><code class="small"><?= e((string) ($t['paypal_capture_id'] ?? '—')) ?></code></td>
                <td class="text-end text-nowrap"><?= currency($t['amount']) ?> <span class="small text-muted"><?= e($t['currency']) ?></span></td>
                <td class="text-end"><?= $t['paypal_fee'] !== null ? currency($t['paypal_fee']) : '—' ?></td>
                <td class="text-end"><?= $t['net_amount'] !== null ? currency($t['net_amount']) : '—' ?></td>
                <td><span class="badge <?= ['completed' => 'bg-success', 'failed' => 'bg-danger', 'cancelled' => 'bg-secondary', 'expired' => 'bg-secondary', 'refunded' => 'bg-info'][$t['paypal_status']] ?? 'bg-warning text-dark' ?>"><?= e(ucfirst($t['paypal_status'])) ?></span><?php if ($t['failure_reason']): ?><div class="small text-muted"><?= e($t['failure_reason']) ?></div><?php endif; ?></td>
                <td class="small"><?= $t['paypal_status'] === 'completed' ? 'Captured &amp; verified with PayPal (server) — approved automatically' : ($t['paypal_status'] === 'approved' ? 'Approved by buyer, not captured yet' : '—') ?><div class="text-muted">Payee account: <?= $t['verification_method'] === 'paypal_login' ? 'PayPal sign-in' : 'admin-confirmed link' ?></div></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="12" class="text-muted small">No PayPal transactions yet.</td></tr><?php endif; ?>
        </tbody>
    </table>
    </div>
</section>
<?php include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php'; ?>
