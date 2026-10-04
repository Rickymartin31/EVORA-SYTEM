<?php
Auth::requireRole('staff');
$db = Database::connection();
$userId = Auth::id();

$assignmentsStmt = $db->prepare(
    'SELECT es.id AS event_staff_id, e.id AS event_id, e.name AS event_name
     FROM event_staff es JOIN events e ON e.id = es.event_id
     WHERE es.user_id = :uid
     AND es.id IN (SELECT event_staff_id FROM staff_permissions WHERE permission_key = "view_announcements")
     AND e.status NOT IN ("removed", "archived") -- events an admin removed are not shown to staff
     ORDER BY (e.event_date < CURDATE()), CASE WHEN e.event_date >= CURDATE() THEN e.event_date END, e.event_date DESC'
);
$assignmentsStmt->execute(['uid' => $userId]);
$assignments = $assignmentsStmt->fetchAll();

$eventId = (int) ($_GET['event_id'] ?? ($assignments[0]['event_id'] ?? 0));
$allowedIds = array_column($assignments, 'event_id');
if (!in_array($eventId, $allowedIds, true)) {
    $eventId = $allowedIds[0] ?? 0;
}

$announcements = [];
if ($eventId) {
    $annStmt = $db->prepare('SELECT * FROM event_announcements WHERE event_id = :id ORDER BY created_at DESC');
    $annStmt->execute(['id' => $eventId]);
    $announcements = $annStmt->fetchAll();
}

$pageDescription = 'Messages from the organizers of your events.';
$pageTitle = 'Announcements';
$activeRoute = 'staff-announcements';
include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
?>
<?php if (empty($assignments)): ?>
    <div class="card-evora p-4 text-center text-muted">You do not have announcement-viewing permission for any assigned event.</div>
<?php else: ?>
<div class="card-evora p-4 mb-3">
    <form method="get" action="<?= url('staff-announcements') ?>" class="row g-2 align-items-end">
        <input type="hidden" name="route" value="staff-announcements">
        <div class="col-md-8">
            <label class="form-label small">Event</label>
            <select name="event_id" class="form-select" onchange="this.form.submit()">
                <?php foreach ($assignments as $a): ?>
                    <option value="<?= $a['event_id'] ?>" <?= $a['event_id'] == $eventId ? 'selected' : '' ?>><?= e($a['event_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>
</div>

<div class="card-evora p-4">
    <h6 class="fw-semibold mb-3"><i class="bi bi-megaphone text-primary me-1"></i> Announcements</h6>
    <?php if (empty($announcements)): ?>
        <?= emptyState('bi-megaphone', 'No announcements for this event yet.') ?>
    <?php else: ?>
    <ul class="list-unstyled mb-0">
        <?php foreach ($announcements as $a): ?>
            <li class="border-bottom py-3">
                <p class="small mb-1"><?= nl2br(e($a['message'])) ?></p>
                <span class="text-muted small"><?= date('M d, Y H:i', strtotime($a['created_at'])) ?></span>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</div>
<?php endif; ?>
<?php include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php'; ?>
