<?php
Auth::requireLogin();
$db = Database::connection();
$userId = Auth::id(); // always the signed-in user — there is no ?id= parameter

$stmt = $db->prepare('SELECT id, full_name, email, phone, profile_picture_path, status, created_at FROM users WHERE id = :id');
$stmt->execute(['id' => $userId]);
$user = $stmt->fetch();

$organizer = null;
if (Auth::role() === 'organizer') {
    $orgStmt = $db->prepare('SELECT organization_name, status FROM organizers WHERE user_id = :uid');
    $orgStmt->execute(['uid' => $userId]);
    $organizer = $orgStmt->fetch();
}
$editing = ($_GET['edit'] ?? '') === '1';

$pageTitle = 'My Profile';
$activeRoute = 'profile';
$accountTab = 'profile';
include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
include EVORA_ROOT . '/modules/account/partials/account_nav.php';
?>
<div class="row g-3">
    <div class="col-lg-4">
        <div class="card-evora p-4 text-center h-100">
            <?php if (!empty($user['profile_picture_path'])): ?>
                <img src="<?= uploadUrl($user['profile_picture_path']) ?>" alt="Profile picture" class="rounded-circle mb-3 account-avatar-lg" data-fallback-initial="<?= e(mb_strtoupper(mb_substr($user['full_name'], 0, 1))) ?>">
            <?php else: ?>
                <div class="account-avatar-lg account-avatar-initials mx-auto mb-3"><?= e(mb_strtoupper(mb_substr($user['full_name'], 0, 1))) ?></div>
            <?php endif; ?>
            <h5 class="fw-bold mb-1"><?= e($user['full_name']) ?></h5>
            <div class="small text-muted mb-2"><?= e($user['email']) ?></div>
            <div class="d-flex justify-content-center gap-1 flex-wrap">
                <span class="badge bg-light text-dark border"><?= e(AccountService::roleLabel((string) Auth::role())) ?></span>
                <span class="badge badge-status-<?= e($user['status']) ?>"><?= e(ucfirst($user['status'])) ?></span>
            </div>
            <?php if (!$editing): ?>
                <a href="<?= url('profile', ['edit' => 1]) ?>" class="btn btn-evora-primary btn-sm mt-3"><i class="bi bi-pencil me-1"></i> Edit Profile</a>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-lg-8">
        <?php if (!$editing): ?>
        <div class="card-evora p-4 mb-3">
            <h6 class="fw-semibold mb-3">Profile Details</h6>
            <dl class="row account-dl mb-0">
                <dt class="col-sm-4">Full Name</dt><dd class="col-sm-8"><?= e($user['full_name']) ?></dd>
                <dt class="col-sm-4">Email Address</dt><dd class="col-sm-8"><?= e($user['email']) ?></dd>
                <dt class="col-sm-4">Phone</dt><dd class="col-sm-8"><?= $user['phone'] ? e($user['phone']) : '<span class="text-muted">Not set</span>' ?></dd>
                <dt class="col-sm-4">Role</dt><dd class="col-sm-8"><?= e(AccountService::roleLabel((string) Auth::role())) ?></dd>
                <dt class="col-sm-4">Account Status</dt><dd class="col-sm-8"><span class="badge badge-status-<?= e($user['status']) ?>"><?= e(ucfirst($user['status'])) ?></span></dd>
                <dt class="col-sm-4">Date Joined</dt><dd class="col-sm-8"><?= date('F d, Y', strtotime($user['created_at'])) ?></dd>
            </dl>
        </div>
        <?php else: ?>
        <div class="card-evora p-4 mb-3">
            <h6 class="fw-semibold mb-3">Edit Profile</h6>
            <form method="post" action="<?= url('profile-save') ?>" enctype="multipart/form-data">
                <?= Csrf::field() ?>
                <input type="hidden" name="form" value="profile">
                <div class="mb-3">
                    <label class="form-label small fw-semibold" for="pfAvatar">Profile Picture</label>
                    <input type="file" name="avatar" id="pfAvatar" class="form-control" accept=".jpg,.jpeg,.png" data-preview="#avatarPreview">
                    <div class="form-text">JPG or PNG, up to 5 MB.</div>
                    <div id="avatarPreview"></div>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold" for="pfName">Full Name</label>
                    <input type="text" name="full_name" id="pfName" class="form-control" value="<?= e($user['full_name']) ?>" maxlength="150" required>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold" for="pfEmail">Email Address</label>
                    <input type="email" id="pfEmail" class="form-control" value="<?= e($user['email']) ?>" disabled>
                    <div class="form-text">Your email is your sign-in name and cannot be changed here.</div>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold" for="pfPhone">Phone</label>
                    <input type="text" name="phone" id="pfPhone" class="form-control" value="<?= e($user['phone']) ?>" maxlength="30">
                </div>
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-evora-primary">Save Changes</button>
                    <a href="<?= url('profile') ?>" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </div>
        <?php endif; ?>
        <?php if ($organizer): ?>
        <div class="card-evora p-4">
            <h6 class="fw-semibold mb-3">Organization</h6>
            <dl class="row account-dl mb-0">
                <dt class="col-sm-4">Organization</dt><dd class="col-sm-8"><?= e($organizer['organization_name']) ?></dd>
                <dt class="col-sm-4">Status</dt><dd class="col-sm-8"><span class="badge badge-status-<?= e($organizer['status']) ?>"><?= e(ucfirst($organizer['status'])) ?></span></dd>
            </dl>
            <p class="small text-muted mb-0 mt-2">Contact an administrator to update organization details.</p>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php'; ?>
