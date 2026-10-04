<?php
Auth::requireRole('organizer');
$db = Database::connection();
$userId = Auth::id();

$orgStmt = $db->prepare('SELECT * FROM organizers WHERE user_id = :uid');
$orgStmt->execute(['uid' => $userId]);
$organizer = $orgStmt->fetch();

$pageDescription = 'Create, publish and manage your events.';
$pageTitle = 'My Events';
$activeRoute = 'organizer-events';

if (!$organizer || $organizer['status'] !== 'approved') {
    include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
    echo evoraAlert('action', 'Your organizer account is not yet approved. You can manage events once an administrator approves your organizer application.', ['icon' => 'bi-hourglass-split']);
    include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php';
    exit;
}

$stmt = $db->prepare(
    'SELECT e.*, c.name AS category_name,
        (SELECT COUNT(*) FROM registrations r WHERE r.event_id = e.id AND r.status = "approved") AS approved_count
     FROM events e JOIN event_categories c ON c.id = e.category_id
     WHERE e.organizer_id = :oid ORDER BY e.event_date DESC'
);
$stmt->execute(['oid' => $organizer['id']]);
$events = $stmt->fetchAll();

include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
?>
<div class="card-evora p-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h6 class="fw-semibold mb-0">My Events</h6>
        <a href="<?= url('organizer-event-type') ?>" class="btn btn-evora-primary btn-sm"><i class="bi bi-plus-lg"></i> Create Event</a>
    </div>
    <?php if (empty($events)): ?>
        <?= emptyState('bi-calendar-plus', "You haven't created any events yet.", 'Create Event', url('organizer-event-type')) ?>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table align-middle table-datatable">
            <thead><tr><th>Event</th><th>Category</th><th>Date</th><th>Fee</th><th>Approved</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($events as $ev): ?>
                <tr>
                    <td><?= e($ev['name']) ?></td>
                    <td><?= e($ev['category_name']) ?></td>
                    <td><?= date('M d, Y', strtotime($ev['event_date'])) ?></td>
                    <td><?= $ev['fee'] > 0 ? currency($ev['fee']) : 'Free' ?></td>
                    <td><?= $ev['approved_count'] ?> / <?= $ev['max_participants'] ?></td>
                    <td><span class="badge badge-status-<?= e($ev['status']) ?>"><?= ucfirst(e($ev['status'])) ?></span></td>
                    <td>
                        <a href="<?= url('organizer-event-manage', ['id' => $ev['id']]) ?>" class="btn btn-sm btn-outline-secondary">Manage</a>
                        <?php if ($ev['status'] === 'draft' && (int) $ev['approved_count'] === 0): ?>
                        <form method="post" action="<?= url('organizer-event-delete') ?>" class="d-inline" data-confirm="Delete this draft event? This cannot be undone.">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="event_id" value="<?= $ev['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
<?php include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php'; ?>
