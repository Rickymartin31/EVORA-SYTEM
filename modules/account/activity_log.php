<?php
Auth::requireLogin();
$db = Database::connection();

// Own activity only: the query is always scoped to the signed-in user.
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;
[$activities, $total] = AccountService::activity($db, Auth::id(), $perPage, ($page - 1) * $perPage);
$totalPages = (int) ceil($total / $perPage);

$pageTitle = 'Activity Log';
$activeRoute = 'activity-log';
$accountTab = 'activity-log';
include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
include EVORA_ROOT . '/modules/account/partials/account_nav.php';
?>
<div class="card-evora p-4">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <div>
            <h6 class="fw-semibold mb-0">My Activity <span class="text-muted fw-normal small">(<?= $total ?>)</span></h6>
            <div class="small text-muted">Only your own account activity is shown.</div>
        </div>
        <?php if (Auth::role() === 'admin'): ?>
            <a href="<?= url('admin-logs') ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-journal-text me-1"></i> System Audit Log (all users)</a>
        <?php endif; ?>
    </div>
    <?php if (!$activities): ?>
        <?= emptyState('bi-clock-history', 'No activity yet.') ?>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-sm align-middle small mb-0">
            <thead><tr><th>Action</th><th>Details</th><th>Date</th><th>Time</th><th>Device</th></tr></thead>
            <tbody>
            <?php foreach ($activities as $log): ?>
                <tr>
                    <td class="fw-semibold"><?= e(AccountService::actionLabel($log['action'])) ?></td>
                    <td class="text-muted"><?= e(mb_strimwidth((string) $log['description'], 0, 90, '…')) ?></td>
                    <td class="text-nowrap"><?= date('M d, Y', strtotime($log['created_at'])) ?></td>
                    <td class="text-nowrap"><?= date('g:i A', strtotime($log['created_at'])) ?></td>
                    <td class="text-nowrap"><?= e(AccountService::deviceLabel($log['user_agent'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if ($totalPages > 1): ?>
    <nav class="mt-3">
        <ul class="pagination pagination-sm mb-0 flex-wrap">
            <?php for ($i = max(1, $page - 3); $i <= min($totalPages, $page + 3); $i++): ?>
                <li class="page-item <?= $i === $page ? 'active' : '' ?>"><a class="page-link" href="<?= url('activity-log', ['page' => $i]) ?>"><?= $i ?></a></li>
            <?php endfor; ?>
        </ul>
    </nav>
    <?php endif; ?>
    <?php endif; ?>
</div>
<?php include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php'; ?>
