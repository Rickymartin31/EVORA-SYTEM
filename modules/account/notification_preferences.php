<?php
Auth::requireLogin();
$db = Database::connection();
// Not $settings: the sidebar reuses that name for the system settings (v35 fix).
$userSettings = AccountService::settings($db, Auth::id());
$emailStmt = $db->prepare('SELECT email FROM users WHERE id = :id');
$emailStmt->execute(['id' => Auth::id()]);
$myEmail = (string) $emailStmt->fetchColumn();
$hasEmail = filter_var($myEmail, FILTER_VALIDATE_EMAIL) !== false;

$pageTitle = 'Notification Preferences';
$activeRoute = 'notification-preferences';
$accountTab = 'notification-preferences';
include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
include EVORA_ROOT . '/modules/account/partials/account_nav.php';
?>
<div class="card-evora p-4" style="max-width:760px;">
    <h6 class="fw-semibold mb-1">Notification Settings</h6>
    <p class="small text-muted">Choose which updates raise an alert on the bell and sidebar. Turned-off categories are still saved on your
        <a href="<?= url('notifications') ?>">Notifications</a> page, and <strong>Action Required</strong> notifications always alert you.</p>
    <form method="post" action="<?= url('account-save') ?>">
        <?= Csrf::field() ?>
        <input type="hidden" name="form" value="notifications">
        <div class="list-group mb-3">
            <?php foreach (AccountService::NOTIFY_OPTIONS as $key => [$label, $desc]): ?>
                <label class="list-group-item d-flex justify-content-between align-items-center gap-3 account-toggle">
                    <span>
                        <span class="fw-semibold small d-block"><?= NotificationService::icon($key === 'evaluation' ? 'event' : $key) ?> <?= e($label) ?></span>
                        <span class="small text-muted"><?= e($desc) ?></span>
                    </span>
                    <span class="form-check form-switch m-0">
                        <input class="form-check-input" type="checkbox" role="switch" name="enabled[]" value="<?= e($key) ?>" <?= (int) $userSettings['notify_' . $key] === 1 ? 'checked' : '' ?> aria-label="<?= e($label) ?>">
                    </span>
                </label>
            <?php endforeach; ?>
        </div>
        <h6 class="fw-semibold mb-1">Email</h6>
        <label class="list-group-item d-flex justify-content-between align-items-center gap-3 account-toggle border rounded p-3 mb-3">
            <span>
                <span class="fw-semibold small d-block"><i class="bi bi-envelope me-1" aria-hidden="true"></i> Email me my notifications</span>
                <span class="small text-muted">Also sends the categories you keep on above <?= $hasEmail ? 'to <strong>' . e($myEmail) . '</strong>' : 'by email — <em>your account has no email address, so nothing can be emailed</em>' ?>. Action Required notifications are always emailed while this is on.<?php if (!MailService::enabled()): ?> <em>Email is not set up on this EVORA installation yet.</em><?php endif; ?></span>
            </span>
            <span class="form-check form-switch m-0">
                <input class="form-check-input" type="checkbox" role="switch" name="email_notifications" value="1" <?= (int) ($userSettings['notify_email'] ?? 1) === 1 ? 'checked' : '' ?> aria-label="Email me my notifications">
            </span>
        </label>
        <button type="submit" class="btn btn-evora-primary">Save Preferences</button>
    </form>
</div>
<?php include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php'; ?>
