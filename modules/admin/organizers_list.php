<?php
Auth::requireRole('admin');
$db = Database::connection();

$filter = $_GET['status'] ?? 'all';
$allowed = ['pending', 'approved', 'rejected', 'suspended'];

$sql = 'SELECT o.*, u.full_name, u.email, u.phone FROM organizers o JOIN users u ON u.id = o.user_id';
$params = [];
if (in_array($filter, $allowed, true)) {
    $sql .= ' WHERE o.status = :status';
    $params['status'] = $filter;
}
$sql .= ' ORDER BY o.created_at DESC';

$stmt = $db->prepare($sql);
$stmt->execute($params);
$organizers = $stmt->fetchAll();

$pageDescription = 'Review organizer applications and manage organizer accounts.';
$pageTitle = 'Organizer Verification';
$activeRoute = 'admin-organizers';
include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
?>
<div class="card-evora p-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h6 class="fw-semibold mb-0">Organizer Applications</h6>
        <div class="btn-group btn-group-sm">
            <a href="<?= url('admin-organizers') ?>" class="btn btn-outline-secondary <?= $filter === 'all' ? 'active' : '' ?>">All</a>
            <a href="<?= url('admin-organizers', ['status' => 'pending']) ?>" class="btn btn-outline-secondary <?= $filter === 'pending' ? 'active' : '' ?>">Pending</a>
            <a href="<?= url('admin-organizers', ['status' => 'approved']) ?>" class="btn btn-outline-secondary <?= $filter === 'approved' ? 'active' : '' ?>">Approved</a>
            <a href="<?= url('admin-organizers', ['status' => 'rejected']) ?>" class="btn btn-outline-secondary <?= $filter === 'rejected' ? 'active' : '' ?>">Rejected</a>
            <a href="<?= url('admin-organizers', ['status' => 'suspended']) ?>" class="btn btn-outline-secondary <?= $filter === 'suspended' ? 'active' : '' ?>">Suspended</a>
        </div>
    </div>
    <?php if (empty($organizers)): ?>
        <?= emptyState('bi-person-x', 'No organizer applications found.') ?>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table align-middle table-datatable">
            <thead><tr><th>Organization</th><th>Contact Person</th><th>Email</th><th>Phone</th><th>Submitted</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($organizers as $o): ?>
                <tr>
                    <td><?= e($o['organization_name']) ?></td>
                    <td><?= e($o['full_name']) ?></td>
                    <td><?= e($o['email']) ?></td>
                    <td><?= e($o['phone']) ?></td>
                    <td><?= date('M d, Y', strtotime($o['created_at'])) ?></td>
                    <td><span class="badge badge-status-<?= e($o['status']) ?>"><?= ucfirst(e($o['status'])) ?></span></td>
                    <td><a href="<?= url('admin-organizer-review', ['id' => $o['id']]) ?>" class="btn btn-sm btn-outline-secondary">Review</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
<?php include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php'; ?>
