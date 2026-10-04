<?php
Auth::requireLogin();
$db = Database::connection();
$userId = Auth::id();

$logins = AccountService::loginHistory($db, $userId, 10);
$currentLogin = $logins[0] ?? null;   // this session
$previousLogin = $logins[1] ?? null;  // the one before
$pwStmt = $db->prepare('SELECT created_at FROM activity_logs WHERE user_id = :uid AND action IN ("password_changed", "password_reset_completed") ORDER BY id DESC LIMIT 1');
$pwStmt->execute(['uid' => $userId]);
$passwordChangedAt = $pwStmt->fetchColumn();
// Two-factor status (v37: administrators confirm each sign-in with an emailed code).
$meStmt = $db->prepare('SELECT u.email, r.name AS role_name FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = :id');
$meStmt->execute(['id' => $userId]);
$me = $meStmt->fetch();
$otpOn = LoginOtpService::required($me);

$pageTitle = 'Security';
$activeRoute = 'account-security';
$accountTab = 'account-security';
include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
include EVORA_ROOT . '/modules/account/partials/account_nav.php';
?>
<div class="row g-3">
    <div class="col-lg-5">
        <div class="card-evora p-4 mb-3">
            <h6 class="fw-semibold mb-3">Account Security</h6>
            <dl class="row account-dl mb-3">
                <dt class="col-6">Last Login</dt>
                <dd class="col-6"><?= $previousLogin ? date('M d, Y g:i A', strtotime($previousLogin['created_at'])) : '<span class="text-muted">First sign-in</span>' ?></dd>
                <dt class="col-6">This Session</dt>
                <dd class="col-6"><?= $currentLogin ? date('M d, Y g:i A', strtotime($currentLogin['created_at'])) : '—' ?></dd>
                <dt class="col-6">Password Changed</dt>
                <dd class="col-6"><?= $passwordChangedAt ? date('M d, Y', strtotime($passwordChangedAt)) : '<span class="text-muted">Not recorded</span>' ?></dd>
            </dl>
            <a href="<?= url('account-settings') ?>#password" class="btn btn-evora-primary btn-sm"><i class="bi bi-key me-1"></i> Change Password</a>
        </div>
        <div class="card-evora p-4">
            <h6 class="fw-semibold mb-3">More Protection</h6>
            <div class="d-flex justify-content-between align-items-center border-bottom py-2 small">
                <span><i class="bi bi-phone me-1"></i> Two-factor Authentication</span>
                <?php if ($otpOn): ?>
                    <span class="badge bg-success" title="Each sign-in needs a 6-digit code sent to your email">On — code sent to your email</span>
                <?php elseif ($me['role_name'] === 'admin'): ?>
                    <span class="badge bg-warning text-dark"><?= filter_var($me['email'], FILTER_VALIDATE_EMAIL) ? 'Off — email is not set up on this EVORA' : 'Off — add an email address to your account' ?></span>
                <?php else: ?>
                    <span class="badge bg-light text-muted border">For administrators</span>
                <?php endif; ?>
            </div>
            <div class="d-flex justify-content-between align-items-center py-2 small">
                <span><i class="bi bi-display me-1"></i> Active Sessions</span>
                <span class="badge bg-light text-muted border">Coming soon</span>
            </div>
            <p class="small text-muted mb-0 mt-2">Signing out ends this session. Accounts lock for 15 minutes after 5 failed sign-in attempts.</p>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card-evora p-4">
            <h6 class="fw-semibold mb-3">Login History</h6>
            <?php if (!$logins): ?>
                <?= emptyState('bi-shield-check', 'No sign-ins recorded yet.') ?>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle small mb-0">
                    <thead><tr><th>Date</th><th>Time</th><th>Device</th><th>IP Address</th></tr></thead>
                    <tbody>
                    <?php foreach ($logins as $i => $login): ?>
                        <tr>
                            <td><?= date('M d, Y', strtotime($login['created_at'])) ?><?= $i === 0 ? ' <span class="badge bg-success-subtle text-success-emphasis border">Current</span>' : '' ?></td>
                            <td><?= date('g:i A', strtotime($login['created_at'])) ?></td>
                            <td><?= e(AccountService::deviceLabel($login['user_agent'])) ?></td>
                            <td class="text-muted"><?= e($login['ip_address'] ?? '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="small text-muted mb-0 mt-2">Device details are recorded from v33 onward. Don't recognize a sign-in? Change your password.</p>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php'; ?>
