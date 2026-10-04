<?php
Auth::requireRole('organizer');
$db = Database::connection();
$userId = Auth::id();

$orgStmt = $db->prepare('SELECT id FROM organizers WHERE user_id = :uid');
$orgStmt->execute(['uid' => $userId]);
$organizer = $orgStmt->fetch();

$eventId = (int) ($_GET['event_id'] ?? 0);
$eventStmt = $db->prepare('SELECT * FROM events WHERE id = :id AND organizer_id = :oid');
$eventStmt->execute(['id' => $eventId, 'oid' => $organizer['id'] ?? 0]);
$event = $eventStmt->fetch();

if (!$event) {
    flash('error', 'Event not found.');
    redirect('organizer-events');
}

EventAccess::requireEventType($db, $eventId, 'sports-tournament', 'organizer-events');

$venuesStmt = $db->prepare('SELECT * FROM venues WHERE event_id = :eid ORDER BY name');
$venuesStmt->execute(['eid' => $eventId]);
$venues = $venuesStmt->fetchAll();

$editVenue = null;
$editId = (int) ($_GET['edit'] ?? 0);
if ($editId > 0) {
    $editStmt = $db->prepare('SELECT * FROM venues WHERE id = :id AND event_id = :eid');
    $editStmt->execute(['id' => $editId, 'eid' => $eventId]);
    $editVenue = $editStmt->fetch() ?: null;
}

$statusLabels = [
    'available' => ['label' => 'Available', 'badge' => 'bg-success'],
    'maintenance' => ['label' => 'Maintenance', 'badge' => 'bg-warning text-dark'],
    'closed' => ['label' => 'Closed', 'badge' => 'bg-secondary'],
];

$pageDescription = 'Courts, fields and rooms used for matches, with their availability.';
$pageTitle = 'Venue Management';
$activeRoute = 'organizer-events';
include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
?>
<?php renderBreadcrumb([
    ['label' => 'My Events', 'route' => 'organizer-events'],
    ['label' => $event['name'], 'route' => 'organizer-event-manage', 'params' => ['id' => $eventId]],
    ['label' => 'Venue Management'],
]); ?>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card-evora p-4">
            <h6 class="fw-semibold mb-3">Venues for This Event</h6>
            <?php if (empty($venues)): ?>
                <?= emptyState('bi-geo-alt', 'No venues defined yet (e.g. Court 1, Court 2, Field A).') ?>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table align-middle table-datatable">
                    <thead><tr><th>Venue</th><th>Type</th><th>Location</th><th>Capacity</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($venues as $v): ?>
                        <?php $s = $statusLabels[$v['status']] ?? $statusLabels['available']; ?>
                        <tr>
                            <td><?= e($v['name']) ?><?= $v['code'] ? ' <span class="badge bg-secondary">' . e($v['code']) . '</span>' : '' ?></td>
                            <td><?= e($v['venue_type']) ?></td>
                            <td><?= $v['location'] ? e($v['location']) : '—' ?></td>
                            <td><?= $v['capacity'] !== null ? (int) $v['capacity'] : '—' ?></td>
                            <td><span class="badge <?= $s['badge'] ?>"><?= e($s['label']) ?></span></td>
                            <td class="text-nowrap">
                                <a href="<?= url('organizer-venues', ['event_id' => $eventId, 'edit' => $v['id']]) ?>" class="btn btn-sm btn-outline-secondary">Edit</a>
                                <form method="post" action="<?= url('organizer-venue-save') ?>" class="d-inline" data-confirm="The venue will no longer be available for scheduling matches." data-confirm-title="Remove this venue?" data-confirm-button="Remove Venue" data-confirm-variant="danger">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="venue_action" value="delete">
                                    <input type="hidden" name="event_id" value="<?= $eventId ?>">
                                    <input type="hidden" name="venue_id" value="<?= $v['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">Remove</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card-evora p-4">
            <h6 class="fw-semibold mb-3"><?= $editVenue ? 'Edit Venue' : 'Add Venue' ?></h6>
            <form method="post" action="<?= url('organizer-venue-save') ?>">
                <?= Csrf::field() ?>
                <input type="hidden" name="venue_action" value="<?= $editVenue ? 'update' : 'create' ?>">
                <input type="hidden" name="event_id" value="<?= $eventId ?>">
                <?php if ($editVenue): ?>
                    <input type="hidden" name="venue_id" value="<?= $editVenue['id'] ?>">
                <?php endif; ?>
                <div class="mb-2">
                    <label class="form-label small">Venue Name *</label>
                    <input type="text" name="name" class="form-control form-control-sm" placeholder="e.g. Court 1" value="<?= e($editVenue['name'] ?? '') ?>" required>
                </div>
                <div class="mb-2">
                    <label class="form-label small">Code</label>
                    <input type="text" name="code" class="form-control form-control-sm" placeholder="e.g. C1 (optional)" value="<?= e($editVenue['code'] ?? '') ?>">
                    <div class="form-text">Short tag for future match scheduling &amp; scoreboard display.</div>
                </div>
                <div class="mb-2">
                    <label class="form-label small">Venue Type *</label>
                    <input type="text" name="venue_type" class="form-control form-control-sm" placeholder="e.g. Indoor Basketball Court" value="<?= e($editVenue['venue_type'] ?? '') ?>" required>
                </div>
                <div class="mb-2">
                    <label class="form-label small">Location / Area</label>
                    <input type="text" name="location" class="form-control form-control-sm" placeholder="e.g. Gym Building B" value="<?= e($editVenue['location'] ?? '') ?>">
                </div>
                <div class="mb-2">
                    <label class="form-label small">Description</label>
                    <input type="text" name="description" class="form-control form-control-sm" placeholder="Optional notes" value="<?= e($editVenue['description'] ?? '') ?>">
                </div>
                <div class="mb-2">
                    <label class="form-label small">Capacity</label>
                    <input type="number" min="0" name="capacity" class="form-control form-control-sm" placeholder="e.g. 100" value="<?= $editVenue['capacity'] ?? '' ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label small">Status *</label>
                    <select name="status" class="form-select form-select-sm">
                        <?php foreach ($statusLabels as $key => $info): ?>
                            <option value="<?= $key ?>" <?= ($editVenue['status'] ?? 'available') === $key ? 'selected' : '' ?>><?= e($info['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-evora-primary btn-sm w-100"><?= $editVenue ? 'Update Venue' : 'Add Venue' ?></button>
                <?php if ($editVenue): ?>
                    <a href="<?= url('organizer-venues', ['event_id' => $eventId]) ?>" class="btn btn-outline-secondary btn-sm w-100 mt-2">Cancel</a>
                <?php endif; ?>
            </form>
        </div>
    </div>
</div>
<?php include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php'; ?>
