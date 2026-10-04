<?php
Auth::requireRole('admin');
$db = Database::connection();

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$userFilter = (int) ($_GET['user_id'] ?? 0);
$actionFilter = $_GET['action'] ?? '';

$where = [];
$params = [];

if ($dateFrom !== '') {
    $where[] = 'a.created_at >= :date_from';
    $params['date_from'] = $dateFrom . ' 00:00:00';
}
if ($dateTo !== '') {
    $where[] = 'a.created_at <= :date_to';
    $params['date_to'] = $dateTo . ' 23:59:59';
}
if ($userFilter > 0) {
    $where[] = 'a.user_id = :user_id';
    $params['user_id'] = $userFilter;
}
if ($actionFilter !== '') {
    $where[] = 'a.action = :action';
    $params['action'] = $actionFilter;
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$countStmt = $db->prepare("SELECT COUNT(*) c FROM activity_logs a $whereSql");
$countStmt->execute($params);
$total = $countStmt->fetch()['c'];

$stmt = $db->prepare(
    "SELECT a.*, u.full_name FROM activity_logs a LEFT JOIN users u ON u.id = a.user_id
     $whereSql ORDER BY a.created_at DESC LIMIT :limit OFFSET :offset"
);
foreach ($params as $key => $value) {
    $stmt->bindValue(':' . $key, $value);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$logs = $stmt->fetchAll();

$totalPages = (int) ceil($total / $perPage);

$users = $db->query('SELECT id, full_name FROM users ORDER BY full_name')->fetchAll();
$actions = $db->query('SELECT DISTINCT action FROM activity_logs ORDER BY action')->fetchAll(PDO::FETCH_COLUMN);

$queryBase = array_filter([
    'date_from' => $dateFrom, 'date_to' => $dateTo, 'user_id' => $userFilter ?: null, 'action' => $actionFilter,
]);

$pageDescription = 'System-wide audit trail of user actions.';
$pageTitle = 'Audit Trail';
$activeRoute = 'admin-logs';
include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
?>
<div class="card-evora p-4 mb-3">
    <form method="get" action="<?= url('admin-logs') ?>" class="row g-2 align-items-end">
        <input type="hidden" name="route" value="admin-logs">
        <div class="col-md-2">
            <label class="form-label small">From</label>
            <input type="date" name="date_from" class="form-control form-control-sm" value="<?= e($dateFrom) ?>">
        </div>
        <div class="col-md-2">
            <label class="form-label small">To</label>
            <input type="date" name="date_to" class="form-control form-control-sm" value="<?= e($dateTo) ?>">
        </div>
        <div class="col-md-3">
            <label class="form-label small">User</label>
            <select name="user_id" class="form-select form-select-sm">
                <option value="0">All Users</option>
                <?php foreach ($users as $u): ?>
                    <option value="<?= $u['id'] ?>" <?= $userFilter === (int) $u['id'] ? 'selected' : '' ?>><?= e($u['full_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label small">Activity Type</label>
            <select name="action" class="form-select form-select-sm">
                <option value="">All Activity Types</option>
                <?php foreach ($actions as $a): ?>
                    <option value="<?= e($a) ?>" <?= $actionFilter === $a ? 'selected' : '' ?>><?= e($a) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2 d-flex gap-2">
            <button type="submit" class="btn btn-evora-primary btn-sm w-100">Filter</button>
            <a href="<?= url('admin-logs') ?>" class="btn btn-outline-secondary btn-sm">Reset</a>
        </div>
    </form>
</div>

<div class="card-evora p-4">
    <h6 class="fw-semibold mb-3">Audit Trail — every user's actions <span class="text-muted small">(<?= $total ?> results)</span></h6>
    <div class="table-responsive">
        <table class="table align-middle table-sm table-datatable" data-page-length="25" data-paging="false">
            <thead><tr><th>Date/Time</th><th>User</th><th>Action</th><th>Description</th><th>IP</th></tr></thead>
            <tbody>
            <?php if (empty($logs)): ?>
                <tr><td colspan="5"><?= emptyState('bi-journal-x', 'No activity found for the selected filters.') ?></td></tr>
            <?php endif; ?>
            <?php foreach ($logs as $log): ?>
                <tr>
                    <td class="text-nowrap"><?= date('M d, Y H:i:s', strtotime($log['created_at'])) ?></td>
                    <td><?= e($log['full_name'] ?? 'System') ?></td>
                    <td><span class="badge bg-light text-dark border"><?= e($log['action']) ?></span></td>
                    <td class="small"><?= e($log['description']) ?></td>
                    <td class="small text-muted"><?= e($log['ip_address']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if ($totalPages > 1): ?>
    <nav>
        <ul class="pagination pagination-sm mb-0">
            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                    <a class="page-link" href="<?= url('admin-logs', array_merge($queryBase, ['page' => $i])) ?>"><?= $i ?></a>
                </li>
            <?php endfor; ?>
        </ul>
    </nav>
    <?php endif; ?>
</div>
<?php include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php'; ?>
