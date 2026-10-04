<?php
// Admin: organizer PayPal accounts — monitoring only. Each organizer connects (and disconnects)
// their own PayPal account by signing in to PayPal; administrators cannot link, approve or change
// it. Account linking is NOT payment approval, and no money is handled here.
Auth::requireRole('admin');
$db = Database::connection();
$organizers = $db->query(
    'SELECT o.id, o.organization_name, u.full_name, u.email,
            a.id AS account_id, a.merchant_id, a.email_masked, a.verification_method, a.verified_at, a.connected_at,
            (SELECT x.status FROM organizer_paypal_accounts x WHERE x.organizer_id = o.id ORDER BY x.id DESC LIMIT 1) AS last_status,
            (SELECT COUNT(*) FROM events ev WHERE ev.organizer_id = o.id AND ev.payment_provider = "paypal" AND ev.status IN ("open", "closed", "ongoing")) AS active_paypal_events
     FROM organizers o JOIN users u ON u.id = o.user_id
     LEFT JOIN organizer_paypal_accounts a ON a.organizer_id = o.id AND a.status = "connected" AND a.environment = ' . $db->quote(PAYPAL_ENV) . '
     WHERE o.status = "approved" ORDER BY o.organization_name'
)->fetchAll();

$pageTitle = 'Organizer PayPal Accounts';
$pageDescription = 'Which PayPal account each organizer is paid into. EVORA does not collect or hold PayPal funds.';
$activeRoute = 'admin-paypal-accounts';
include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
?>
<?php renderBreadcrumb([['label' => 'Organizer PayPal Accounts']]); ?>
<?php if (!PAYPAL_ENABLED): ?>
    <?= evoraAlert('warning', 'PayPal is not enabled on this installation.') ?>
<?php endif; ?>
<?= evoraAlert('info', '<strong>Monitoring only.</strong> Each organizer connects their own PayPal account by signing in to PayPal (Organizer → PayPal Account → Connect PayPal), and can disconnect it themselves. Administrators cannot link, approve or change an organizer\'s PayPal account. Participant PayPal payments go directly to the organizer and are verified and approved automatically — EVORA does not collect or hold PayPal funds.'
    . (PAYPAL_ENABLED && !PayPalAccountService::oauthAvailable() ? ' <strong>Connect PayPal is not switched on yet</strong> (its Return URL must first be registered in the PayPal app), so organizers cannot connect a new account right now.' : ''), ['class' => 'small']) ?>

<section class="card-evora p-4" aria-labelledby="ppaHeading">
    <h2 class="h6 fw-semibold mb-3" id="ppaHeading">Approved organizers <?= PAYPAL_ENV === 'sandbox' ? '<span class="badge bg-warning text-dark ms-1">Sandbox</span>' : '' ?></h2>
    <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
        <thead><tr><th>Organizer</th><th>PayPal account (link status)</th><th>Environment</th><th>Connected by</th><th>Since</th><th>Active PayPal events</th></tr></thead>
        <tbody>
        <?php foreach ($organizers as $o): ?>
            <tr>
                <td><div class="fw-semibold"><?= e($o['organization_name']) ?></div><div class="small text-muted"><?= e($o['full_name']) ?></div></td>
                <td><?php if ($o['account_id']): ?><span class="badge bg-success">Connected</span> <code><?= e(PayPalAccountService::maskId($o['merchant_id'])) ?></code><?= $o['email_masked'] ? ' <span class="small text-muted">' . e($o['email_masked']) . '</span>' : '' ?><?php elseif ($o['last_status'] === 'disconnected'): ?><span class="badge bg-secondary">Disconnected</span> <span class="small text-muted">(previous link)</span><?php else: ?><span class="badge bg-secondary">Not connected</span><?php endif; ?></td>
                <td class="small"><?= $o['account_id'] ? '<span class="badge ' . (PAYPAL_ENV === 'sandbox' ? 'bg-warning text-dark' : 'bg-success') . '">' . e(ucfirst(PAYPAL_ENV)) . '</span>' : '—' ?></td>
                <td class="small"><?php if ($o['account_id']): ?><?= $o['verification_method'] === 'paypal_login' ? 'Organizer (PayPal sign-in)' : 'Administrator (earlier, before self-service)' ?><?php else: ?>—<?php endif; ?></td>
                <td class="small text-nowrap"><?= $o['verified_at'] ? date('M d, Y g:i A', strtotime($o['verified_at'])) : '—' ?></td>
                <td class="small text-center"><?= (int) $o['active_paypal_events'] ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$organizers): ?><tr><td colspan="6" class="text-muted small">No approved organizers.</td></tr><?php endif; ?>
        </tbody>
    </table>
    </div>
</section>
<?php include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php'; ?>
