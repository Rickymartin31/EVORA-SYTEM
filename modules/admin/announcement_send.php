<?php
Auth::requireRole('admin');
$db = Database::connection();
$settings = systemSettings();

$counts = [];
$countStmt = $db->prepare(
    "SELECT r.name AS role_name, COUNT(*) c FROM users u JOIN roles r ON r.id = u.role_id
     WHERE u.status = 'active' GROUP BY r.name"
);
$countStmt->execute();
foreach ($countStmt->fetchAll() as $row) {
    $counts[$row['role_name']] = (int) $row['c'];
}
$totalActive = array_sum($counts);

$pageDescription = 'Send an announcement to users by role.';
$pageTitle = 'Announcements';
$activeRoute = 'admin-announcements';
include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
?>
<div class="row g-3">
    <div class="col-lg-7">
        <div class="card-evora p-4">
            <h6 class="fw-semibold mb-3">Broadcast a Notification</h6>
            <form method="post" action="<?= url('admin-announcement-send') ?>" data-confirm="Send this notification to every matching user right now? This cannot be undone.">
                <?= Csrf::field() ?>
                <div class="mb-3">
                    <label class="form-label small">Audience *</label>
                    <select name="audience" class="form-select" required>
                        <option value="all">All Active Users (<?= $totalActive ?>)</option>
                        <option value="organizer">Organizers Only (<?= $counts['organizer'] ?? 0 ?>)</option>
                        <option value="staff">Staff Only (<?= $counts['staff'] ?? 0 ?>)</option>
                        <option value="participant">Participants Only (<?= $counts['participant'] ?? 0 ?>)</option>
                        <option value="admin">Admins Only (<?= $counts['admin'] ?? 0 ?>)</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label small">Title *</label>
                    <input type="text" name="title" class="form-control" placeholder="e.g. Scheduled Maintenance" maxlength="200" required>
                </div>
                <div class="mb-3">
                    <label class="form-label small">Message *</label>
                    <textarea name="message" class="form-control" rows="4" maxlength="500" required><?= e($settings['default_announcement_note'] ?? '') ?></textarea>
                    <div class="form-text">Max 500 characters.</div>
                </div>
                <button type="submit" class="btn btn-evora-primary"><i class="bi bi-megaphone"></i> Send Notification</button>
            </form>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card-evora p-4">
            <h6 class="fw-semibold mb-2">Active Users by Role</h6>
            <ul class="list-unstyled small mb-0">
                <li class="d-flex justify-content-between border-bottom py-1"><span>Admins</span><strong><?= $counts['admin'] ?? 0 ?></strong></li>
                <li class="d-flex justify-content-between border-bottom py-1"><span>Organizers</span><strong><?= $counts['organizer'] ?? 0 ?></strong></li>
                <li class="d-flex justify-content-between border-bottom py-1"><span>Staff</span><strong><?= $counts['staff'] ?? 0 ?></strong></li>
                <li class="d-flex justify-content-between py-1"><span>Participants</span><strong><?= $counts['participant'] ?? 0 ?></strong></li>
            </ul>
            <div class="form-text mt-2">This reaches users platform-wide via their notification bell. For an event-specific message, use the Organizer's "Send Announcement" tool on that event instead.</div>
        </div>
    </div>
</div>
<?php include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php'; ?>
