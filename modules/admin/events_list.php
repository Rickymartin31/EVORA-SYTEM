<?php
Auth::requireRole('admin');
$db = Database::connection();

$filter = $_GET['status'] ?? 'all';
$sql = 'SELECT e.*, o.organization_name, c.name AS category_name
        FROM events e
        JOIN organizers o ON o.id = e.organizer_id
        JOIN event_categories c ON c.id = e.category_id';
$params = [];
$allowed = ['draft','pending_approval','approved','open','closed','ongoing','completed','cancelled','removed','archived'];
if (in_array($filter, $allowed, true)) {
    $sql .= ' WHERE e.status = :status';
    $params['status'] = $filter;
}
$sql .= ' ORDER BY e.event_date DESC';
$stmt = $db->prepare($sql);
$stmt->execute($params);
$events = $stmt->fetchAll();
$cancelRequests = cancellationRequests($db);

$pageDescription = 'Monitor every event on the platform. Open an event to act on it (cancel, remove or archive).';
$pageTitle = 'Event Monitoring';
$activeRoute = 'admin-events';
include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
?>
<div class="card-evora p-4">
    <?php if ($cancelRequests): ?>
    <?= evoraAlert('action', '<strong>' . count($cancelRequests) . ' cancellation request' . (count($cancelRequests) > 1 ? 's' : '') . '</strong> from organizers. Open the event marked <em>Cancellation requested</em> to review it.', ['icon' => 'bi-exclamation-triangle', 'class' => 'small']) ?>
    <?php endif; ?>
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h6 class="fw-semibold mb-0">All Platform Events</h6>
        <select class="form-select form-select-sm w-auto" onchange="location.href=this.value">
            <option value="<?= url('admin-events') ?>" <?= $filter === 'all' ? 'selected' : '' ?>>All Statuses</option>
            <?php foreach ($allowed as $s): ?>
                <option value="<?= url('admin-events', ['status' => $s]) ?>" <?= $filter === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php if (empty($events)): ?>
        <?= emptyState('bi-calendar-x', 'No events match this filter. Choose another status to see more events.') ?>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table align-middle table-datatable">
            <thead><tr><th>Event</th><th>Organizer</th><th>Category</th><th>Date</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($events as $ev): ?>
                <tr>
                    <td><?= e($ev['name']) ?></td>
                    <td><?= e($ev['organization_name']) ?></td>
                    <td><?= e($ev['category_name']) ?></td>
                    <td><?= date('M d, Y', strtotime($ev['event_date'])) ?></td>
                    <td><span class="badge badge-status-<?= e($ev['status']) ?>"><?= ucfirst(str_replace('_', ' ', e($ev['status']))) ?></span>
                        <?php if (isset($cancelRequests[(int) $ev['id']])): ?><span class="badge badge-status-pending ms-1" title="The organizer asked EVORA to cancel this event"><i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>Cancellation requested</span><?php endif; ?></td>
                    <td>
                        <a href="<?= url('event-view', ['id' => $ev['id']]) ?>" class="btn btn-sm btn-outline-secondary">View</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
<?php include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php'; ?>
