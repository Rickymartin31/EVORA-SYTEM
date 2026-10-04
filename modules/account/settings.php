<?php
Auth::requireLogin();
$db = Database::connection();
$userId = Auth::id();

$stmt = $db->prepare('SELECT full_name, email FROM users WHERE id = :id');
$stmt->execute(['id' => $userId]);
$user = $stmt->fetch();
// Not $settings: the sidebar reuses that name for the system settings (v35 fix).
$userSettings = AccountService::settings($db, $userId);

$pageTitle = 'Account Settings';
$activeRoute = 'account-settings';
$accountTab = 'account-settings';
include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
include EVORA_ROOT . '/modules/account/partials/account_nav.php';
?>
<div class="row g-3">
    <div class="col-lg-6">
        <div class="card-evora p-4 mb-3">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="fw-semibold mb-0">Account Information</h6>
                <a href="<?= url('profile', ['edit' => 1]) ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil me-1"></i> Edit</a>
            </div>
            <dl class="row account-dl mb-0">
                <dt class="col-sm-4">Name</dt><dd class="col-sm-8"><?= e($user['full_name']) ?></dd>
                <dt class="col-sm-4">Email</dt><dd class="col-sm-8"><?= e($user['email']) ?></dd>
            </dl>
        </div>

        <div class="card-evora p-4">
            <h6 class="fw-semibold mb-1">Preferences</h6>
            <p class="small text-muted">Saved to your account. More languages and time zones are coming.</p>
            <form method="post" action="<?= url('account-save') ?>">
                <?= Csrf::field() ?>
                <input type="hidden" name="form" value="preferences">
                <div class="mb-3">
                    <label class="form-label small fw-semibold" for="prefLanguage">Language</label>
                    <select name="language" id="prefLanguage" class="form-select">
                        <?php foreach (AccountService::LANGUAGES as $code => $label): ?>
                            <option value="<?= e($code) ?>" <?= $userSettings['language'] === $code ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold" for="prefTimezone">Timezone</label>
                    <select name="timezone" id="prefTimezone" class="form-select">
                        <?php foreach (AccountService::TIMEZONES as $tz => $label): ?>
                            <option value="<?= e($tz) ?>" <?= $userSettings['timezone'] === $tz ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-evora-primary">Save Preferences</button>
            </form>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card-evora p-4" id="password">
            <h6 class="fw-semibold mb-1">Change Password</h6>
            <p class="small text-muted">At least 8 characters, with a letter and a number.</p>
            <form method="post" action="<?= url('profile-save') ?>" autocomplete="off">
                <?= Csrf::field() ?>
                <input type="hidden" name="form" value="password">
                <input type="hidden" name="return" value="account-settings">
                <div class="mb-3">
                    <label class="form-label small fw-semibold" for="pwCurrent">Current Password</label>
                    <input type="password" name="current_password" id="pwCurrent" class="form-control" autocomplete="current-password" required>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold" for="pwNew">New Password</label>
                    <input type="password" name="new_password" id="pwNew" class="form-control" data-strength autocomplete="new-password" minlength="8" maxlength="72" required>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold" for="pwConfirm">Confirm New Password</label>
                    <input type="password" name="confirm_password" id="pwConfirm" class="form-control" data-match="#pwNew" autocomplete="new-password" minlength="8" maxlength="72" required>
                </div>
                <button type="submit" class="btn btn-evora-primary">Update Password</button>
            </form>
        </div>
    </div>
</div>
<?php include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php'; ?>
