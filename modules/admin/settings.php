<?php
Auth::requireRole('admin');
$settings = systemSettings();

$pageDescription = 'Platform name, branding and system-wide settings.';
$pageTitle = 'System Settings';
$activeRoute = 'admin-settings';
include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
?>
<div class="row g-3">
    <div class="col-lg-7">
        <div class="card-evora p-4">
            <h6 class="fw-semibold mb-3">System Settings</h6>
            <form method="post" action="<?= url('admin-settings-save') ?>" enctype="multipart/form-data">
                <?= Csrf::field() ?>
                <div class="mb-3">
                    <label class="form-label small">System Name *</label>
                    <input type="text" name="system_name" class="form-control" value="<?= e($settings['system_name']) ?>" required>
                    <div class="form-text">Shown in the browser tab title across the whole system.</div>
                </div>
                <div class="mb-3">
                    <label class="form-label small">System Description</label>
                    <textarea name="system_description" class="form-control" rows="2"><?= e($settings['system_description'] ?? '') ?></textarea>
                </div>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label small">Contact Email</label>
                        <input type="email" name="contact_email" class="form-control" value="<?= e($settings['contact_email'] ?? '') ?>">
                        <div class="form-text">Shown on the Privacy Policy and User Agreement as the official EVORA contact. Use an official EVORA address, not a personal one. Leave blank to show none.</div>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label small">Contact Phone</label>
                        <input type="text" name="contact_phone" class="form-control" value="<?= e($settings['contact_phone'] ?? '') ?>">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label small">Logo</label>
                    <input type="file" name="logo" class="form-control" accept=".jpg,.jpeg,.png" data-preview="#logoPreview">
                    <?php if (!empty($settings['logo_path'])): ?>
                        <div class="form-text">Current: <a href="<?= uploadUrl($settings['logo_path']) ?>" target="_blank">view logo</a> (upload a new file to replace, or check "Remove" below to clear it)</div>
                        <div class="form-check mt-1">
                            <input class="form-check-input" type="checkbox" name="remove_logo" id="removeLogo" value="1">
                            <label class="form-check-label small" for="removeLogo">Remove current logo (revert to the default EVORA brand mark)</label>
                        </div>
                    <?php endif; ?>
                    <div id="logoPreview"></div>
                </div>
                <div class="mb-3">
                    <label class="form-label small">Default Announcement Note</label>
                    <textarea name="default_announcement_note" class="form-control" rows="2" placeholder="Optional boilerplate text pre-filled when sending a new announcement."><?= e($settings['default_announcement_note'] ?? '') ?></textarea>
                </div>
                <button type="submit" class="btn btn-evora-primary">Save Settings</button>
            </form>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card-evora p-4">
            <h6 class="fw-semibold mb-2">Last Updated</h6>
            <?php if (!empty($settings['updated_at'])): ?>
                <p class="small text-muted mb-0"><?= date('M d, Y h:i A', strtotime($settings['updated_at'])) ?></p>
            <?php else: ?>
                <p class="small text-muted mb-0">Never updated.</p>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php'; ?>
