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

EventAccess::requireEventType($db, $eventId, 'fun-run', 'organizer-events');

$checkpointsStmt = $db->prepare('SELECT * FROM event_checkpoints WHERE event_id = :eid ORDER BY sequence_no');
$checkpointsStmt->execute(['eid' => $eventId]);
$checkpoints = $checkpointsStmt->fetchAll();

$routeImagesStmt = $db->prepare('SELECT * FROM event_route_images WHERE event_id = :eid ORDER BY uploaded_at DESC');
$routeImagesStmt->execute(['eid' => $eventId]);
$routeImages = $routeImagesStmt->fetchAll();

$nextSequence = 1;
foreach ($checkpoints as $c) {
    $nextSequence = max($nextSequence, (int) $c['sequence_no'] + 1);
}

$editCheckpoint = null;
$editId = (int) ($_GET['edit'] ?? 0);
if ($editId > 0) {
    foreach ($checkpoints as $c) {
        if ((int) $c['id'] === $editId) {
            $editCheckpoint = $c;
            break;
        }
    }
}

$checkpointTypeLabels = [
    'start' => ['label' => 'Start', 'icon' => 'bi-flag-fill', 'badge' => 'bg-success'],
    'finish' => ['label' => 'Finish', 'icon' => 'bi-flag-fill', 'badge' => 'bg-danger'],
    'checkpoint' => ['label' => 'Timing Checkpoint', 'icon' => 'bi-signpost-split', 'badge' => 'bg-secondary'],
    'water_station' => ['label' => 'Water Station', 'icon' => 'bi-droplet-fill', 'badge' => 'bg-info text-dark'],
    'medical_station' => ['label' => 'Medical Station', 'icon' => 'bi-plus-circle-fill', 'badge' => 'bg-warning text-dark'],
    'emergency_point' => ['label' => 'Emergency Point', 'icon' => 'bi-exclamation-triangle-fill', 'badge' => 'bg-danger'],
    'turnaround_point' => ['label' => 'Turn-around Point', 'icon' => 'bi-arrow-repeat', 'badge' => 'bg-primary'],
];

$pageDescription = 'Set up route checkpoints and route maps for runners and marshals.';
$pageTitle = 'Route Management';
$activeRoute = 'organizer-events';
include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
?>
<?php renderBreadcrumb([
    ['label' => 'My Events', 'route' => 'organizer-events'],
    ['label' => $event['name'], 'route' => 'organizer-event-manage', 'params' => ['id' => $eventId]],
    ['label' => 'Route Management'],
]); ?>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card-evora p-4">
            <h6 class="fw-semibold mb-3">Race Route</h6>
            <?php if (empty($checkpoints)): ?>
                <?= emptyState('bi-signpost-split', 'No route defined yet. Add a Start, checkpoints, and a Finish Line below.') ?>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table align-middle table-datatable">
                    <thead><tr><th>#</th><th>Label</th><th>Type</th><th>Distance (km)</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($checkpoints as $c): ?>
                        <?php $ct = $checkpointTypeLabels[$c['checkpoint_type']] ?? $checkpointTypeLabels['checkpoint']; ?>
                        <tr>
                            <td><?= (int) $c['sequence_no'] ?></td>
                            <td><?= e($c['label']) ?></td>
                            <td><span class="badge <?= $ct['badge'] ?>"><i class="bi <?= $ct['icon'] ?>"></i> <?= e($ct['label']) ?></span></td>
                            <td><?= $c['distance_km'] !== null ? number_format((float) $c['distance_km'], 2) . ' km' : '—' ?></td>
                            <td><span class="badge <?= $c['status'] === 'active' ? 'bg-success' : 'bg-secondary' ?>"><?= $c['status'] === 'active' ? 'Active' : 'Inactive' ?></span></td>
                            <td class="text-nowrap">
                                <a href="<?= url('organizer-checkpoints', ['event_id' => $eventId, 'edit' => $c['id']]) ?>" class="btn btn-sm btn-outline-secondary">Edit</a>
                                <form method="post" action="<?= url('organizer-checkpoint-save') ?>" class="d-inline">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="checkpoint_action" value="toggle_status">
                                    <input type="hidden" name="event_id" value="<?= $eventId ?>">
                                    <input type="hidden" name="checkpoint_id" value="<?= $c['id'] ?>">
                                    <input type="hidden" name="new_status" value="<?= $c['status'] === 'active' ? 'inactive' : 'active' ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-secondary"><?= $c['status'] === 'active' ? 'Deactivate' : 'Activate' ?></button>
                                </form>
                                <form method="post" action="<?= url('organizer-checkpoint-save') ?>" class="d-inline" data-confirm="The checkpoint and every runner scan recorded at it will be deleted." data-confirm-title="Remove this checkpoint?" data-confirm-button="Remove Checkpoint" data-confirm-variant="danger">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="checkpoint_action" value="delete">
                                    <input type="hidden" name="event_id" value="<?= $eventId ?>">
                                    <input type="hidden" name="checkpoint_id" value="<?= $c['id'] ?>">
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
            <h6 class="fw-semibold mb-3"><?= $editCheckpoint ? 'Edit Checkpoint' : 'Add Checkpoint' ?></h6>
            <form method="post" action="<?= url('organizer-checkpoint-save') ?>">
                <?= Csrf::field() ?>
                <input type="hidden" name="checkpoint_action" value="<?= $editCheckpoint ? 'update' : 'create' ?>">
                <input type="hidden" name="event_id" value="<?= $eventId ?>">
                <?php if ($editCheckpoint): ?>
                    <input type="hidden" name="checkpoint_id" value="<?= $editCheckpoint['id'] ?>">
                <?php endif; ?>
                <div class="mb-2">
                    <label class="form-label small">Order *</label>
                    <input type="number" name="sequence_no" min="1" value="<?= $editCheckpoint ? (int) $editCheckpoint['sequence_no'] : $nextSequence ?>" class="form-control form-control-sm" required>
                </div>
                <div class="mb-2">
                    <label class="form-label small">Label *</label>
                    <input type="text" name="label" class="form-control form-control-sm" placeholder="e.g. Start, Checkpoint 1, Finish Line" value="<?= e($editCheckpoint['label'] ?? '') ?>" required>
                </div>
                <div class="mb-2">
                    <label class="form-label small">Type *</label>
                    <select name="checkpoint_type" class="form-select form-select-sm">
                        <?php foreach ($checkpointTypeLabels as $key => $info): ?>
                            <option value="<?= $key ?>" <?= ($editCheckpoint['checkpoint_type'] ?? '') === $key ? 'selected' : '' ?>><?= e($info['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label small">Distance (km)</label>
                    <input type="number" step="0.01" min="0" name="distance_km" class="form-control form-control-sm" placeholder="e.g. 3.00" value="<?= e($editCheckpoint['distance_km'] ?? '') ?>">
                </div>
                <button type="submit" class="btn btn-evora-primary btn-sm w-100"><?= $editCheckpoint ? 'Update Checkpoint' : 'Add Checkpoint' ?></button>
                <?php if ($editCheckpoint): ?>
                    <a href="<?= url('organizer-checkpoints', ['event_id' => $eventId]) ?>" class="btn btn-outline-secondary btn-sm w-100 mt-2">Cancel</a>
                <?php endif; ?>
            </form>
        </div>
    </div>
</div>

<div class="card-evora p-4">
    <h6 class="fw-semibold mb-3"><i class="bi bi-image me-1"></i> Route Pictures</h6>
    <?php if (empty($routeImages)): ?>
        <?= emptyState('bi-image', 'No route pictures uploaded yet.') ?>
    <?php else: ?>
    <div class="row g-3 mb-3">
        <?php foreach ($routeImages as $img): ?>
            <div class="col-6 col-md-3">
                <div class="border rounded p-1">
                    <img src="<?= uploadUrl($img['image_path']) ?>" class="img-fluid rounded mb-1 route-thumb" alt="Route map">
                    <form method="post" action="<?= url('organizer-route-image-action') ?>" data-confirm="The picture is removed from the route page for runners and staff." data-confirm-title="Remove this route picture?" data-confirm-button="Remove Picture" data-confirm-variant="danger">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="route_image_action" value="delete">
                        <input type="hidden" name="event_id" value="<?= $eventId ?>">
                        <input type="hidden" name="image_id" value="<?= $img['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger w-100">Remove</button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <form method="post" action="<?= url('organizer-route-image-action') ?>" enctype="multipart/form-data" class="d-flex gap-2 align-items-start flex-wrap">
        <?= Csrf::field() ?>
        <input type="hidden" name="route_image_action" value="upload">
        <input type="hidden" name="event_id" value="<?= $eventId ?>">
        <input type="file" name="route_image" class="form-control form-control-sm mw-320" accept=".jpg,.jpeg,.png" required aria-label="Route map image">
        <button type="submit" class="btn btn-sm btn-evora-primary"><i class="bi bi-upload"></i> Upload &amp; Notify Participants</button>
    </form>
    <div class="form-text">Approved participants get notified automatically when a new route picture is uploaded.</div>
</div>
<?php include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php'; ?>
