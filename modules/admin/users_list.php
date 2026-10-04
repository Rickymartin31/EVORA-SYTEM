<?php
Auth::requireRole('admin');
$db = Database::connection();

$roleFilter = $_GET['role'] ?? 'all';
$sql = 'SELECT u.*, r.name AS role_name FROM users u JOIN roles r ON r.id = u.role_id';
$params = [];
if (in_array($roleFilter, ['admin','organizer','staff','participant'], true)) {
    $sql .= ' WHERE r.name = :role';
    $params['role'] = $roleFilter;
}
$sql .= ' ORDER BY u.created_at DESC';
$stmt = $db->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();

$pageDescription = 'Manage user accounts and their access.';
$pageTitle = 'User Management';
$activeRoute = 'admin-users';
include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
?>
<div class="card-evora p-4">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h6 class="fw-semibold mb-0">All Users</h6>
        <div class="btn-group btn-group-sm">
            <a href="<?= url('admin-users') ?>" class="btn btn-outline-secondary <?= $roleFilter === 'all' ? 'active' : '' ?>">All</a>
            <a href="<?= url('admin-users', ['role' => 'admin']) ?>" class="btn btn-outline-secondary <?= $roleFilter === 'admin' ? 'active' : '' ?>">Admins</a>
            <a href="<?= url('admin-users', ['role' => 'organizer']) ?>" class="btn btn-outline-secondary <?= $roleFilter === 'organizer' ? 'active' : '' ?>">Organizers</a>
            <a href="<?= url('admin-users', ['role' => 'staff']) ?>" class="btn btn-outline-secondary <?= $roleFilter === 'staff' ? 'active' : '' ?>">Staff</a>
            <a href="<?= url('admin-users', ['role' => 'participant']) ?>" class="btn btn-outline-secondary <?= $roleFilter === 'participant' ? 'active' : '' ?>">Participants</a>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table align-middle table-datatable">
            <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Joined</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($users as $u): ?>
                <tr>
                    <td><?= e($u['full_name']) ?></td>
                    <td><?= e($u['email']) ?></td>
                    <td><span class="badge bg-light text-dark border"><?= ucfirst(e($u['role_name'])) ?></span></td>
                    <td><span class="badge badge-status-<?= e($u['status']) ?>"><?= ucfirst(e($u['status'])) ?></span></td>
                    <td><?= date('M d, Y', strtotime($u['created_at'])) ?></td>
                    <td>
                        <?php if ((int) $u['id'] !== Auth::id()): ?>
                        <form method="post" action="<?= url('admin-user-action') ?>" class="d-inline" data-confirm="<?= $u['status'] === 'active' ? 'Deactivate this account? The user will be unable to log in.' : 'Reactivate this account?' ?>">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                            <input type="hidden" name="action" value="<?= $u['status'] === 'active' ? 'deactivate' : 'activate' ?>">
                            <input type="hidden" name="return_role" value="<?= e($roleFilter) ?>">
                            <button type="submit" class="btn btn-sm btn-outline-<?= $u['status'] === 'active' ? 'warning' : 'success' ?>">
                                <?= $u['status'] === 'active' ? 'Deactivate' : 'Activate' ?>
                            </button>
                        </form>
                        <?php else: ?>
                            <span class="text-muted small">You</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php'; ?>
