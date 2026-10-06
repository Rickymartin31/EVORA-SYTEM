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

// v56: the route on the map, mapped checkpoints and live tracking
$routePath = RaceTrackingService::route($db, $eventId);
$mapCheckpoints = array_map(fn ($c) => ['label' => $c['sequence_no'] . '. ' . $c['label'], 'type' => $c['checkpoint_type'], 'lat' => $c['lat_f'], 'lng' => $c['lng_f'], 'radius' => (int) $c['radius_m']],
    RaceTrackingService::mappedCheckpoints($db, $eventId));
$trackerOpen = date('Y-m-d') >= $event['event_date'] && date('Y-m-d') <= date('Y-m-d', strtotime($event['event_date'] . ' +1 day'));

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
<link rel="stylesheet" href="<?= assetUrl('vendor/leaflet/leaflet.css') ?>">

<div class="card-evora p-4" data-race-map-root>
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h6 class="fw-semibold mb-0"><i class="bi bi-map me-1"></i> Route Map &amp; Live Tracking</h6>
        <div class="btn-group btn-group-sm" role="group" aria-label="Map view">
            <button type="button" class="btn btn-outline-primary active" data-rm="tab-route"><i class="bi bi-bezier2"></i> Route</button>
            <button type="button" class="btn btn-outline-primary" data-rm="tab-live"><i class="bi bi-broadcast"></i> Live Runners</button>
        </div>
    </div>
    <div data-rm-pane="route">
        <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
            <button type="button" class="btn btn-sm btn-outline-primary" data-rm="draw"><i class="bi bi-pencil"></i> Draw route</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-rm="undo"><i class="bi bi-arrow-counterclockwise"></i> Undo</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-rm="clear"><i class="bi bi-trash"></i> Clear</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-rm="locate"><i class="bi bi-crosshair"></i> My location</button>
            <span class="ms-auto small">Route length: <strong data-rm="distance">0.00 km</strong></span>
            <form method="post" action="<?= url('organizer-route-save') ?>" class="d-inline">
                <?= Csrf::field() ?>
                <input type="hidden" name="event_id" value="<?= $eventId ?>">
                <input type="hidden" name="points" data-rm="points" value="">
                <button type="submit" class="btn btn-sm btn-evora-primary" data-rm="save"><i class="bi bi-save"></i> Save Route</button>
            </form>
        </div>
        <div class="small text-muted mb-2" data-rm="hint" aria-live="polite"></div>
    </div>
    <div data-rm-pane="live" class="d-none">
        <div class="d-flex flex-wrap gap-2 align-items-center mb-2 small">
            <strong data-rm="summary">Loading…</strong>
            <span class="text-muted ms-auto" data-rm="updated"></span>
        </div>
        <?php if (!$trackerOpen): ?>
            <div class="alert alert-info small py-2 mb-2">Runners can start the tracker on race day (<?= date('M d, Y', strtotime($event['event_date'])) ?>) from their registration. This list refreshes every 10 seconds.</div>
        <?php endif; ?>
    </div>
    <div id="raceMap" class="race-map"
         data-route="<?= e(json_encode($routePath['points'] ?? [])) ?>"
         data-checkpoints="<?= e(json_encode($mapCheckpoints)) ?>"
         data-live-url="<?= e(url('organizer-race-live', ['event_id' => $eventId])) ?>"></div>
    <div data-rm-pane="route" class="form-text">Draw the official course in running order, then give each checkpoint a map position (use <strong>Pick on map</strong> in the checkpoint form). Runners who skip a checkpoint or a big part of the course are flagged for your review.</div>
    <div data-rm-pane="live" class="d-none mt-3">
        <div class="table-responsive">
            <table class="table align-middle table-sm mb-0">
                <thead><tr><th>Bib</th><th>Runner</th><th>Distance</th><th>Last GPS</th><th>Route check</th><th></th></tr></thead>
                <tbody data-rm="live-body"></tbody>
            </table>
        </div>
        <div class="form-text">Phone GPS can be off by 10–30 m. A "Possible shortcut" is a flag to review, not an automatic disqualification.</div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card-evora p-4">
            <h6 class="fw-semibold mb-3">Race Route</h6>
            <?php if (empty($checkpoints)): ?>
                <?= emptyState('bi-signpost-split', 'No route defined yet. Add a Start, checkpoints, and a Finish Line below.') ?>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table align-middle table-datatable">
                    <thead><tr><th>#</th><th>Label</th><th>Type</th><th>Distance (km)</th><th>Map</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($checkpoints as $c): ?>
                        <?php $ct = $checkpointTypeLabels[$c['checkpoint_type']] ?? $checkpointTypeLabels['checkpoint']; ?>
                        <tr>
                            <td><?= (int) $c['sequence_no'] ?></td>
                            <td><?= e($c['label']) ?></td>
                            <td><span class="badge <?= $ct['badge'] ?>"><i class="bi <?= $ct['icon'] ?>"></i> <?= e($ct['label']) ?></span></td>
                            <td><?= $c['distance_km'] !== null ? number_format((float) $c['distance_km'], 2) . ' km' : '—' ?></td>
                            <td><?= $c['lat'] !== null ? '<i class="bi bi-geo-alt-fill text-success" title="On the map"></i><span class="visually-hidden">On the map</span>' : '<span class="text-muted small">Not set</span>' ?></td>
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
                <div class="mb-2">
                    <label class="form-label small">Distance (km)</label>
                    <input type="number" step="0.01" min="0" name="distance_km" class="form-control form-control-sm" placeholder="e.g. 3.00" value="<?= e($editCheckpoint['distance_km'] ?? '') ?>">
                </div>
                <div class="mb-2">
                    <label class="form-label small">Map position</label>
                    <div class="input-group input-group-sm">
                        <input type="number" step="0.000001" min="-90" max="90" name="lat" class="form-control" placeholder="Latitude" aria-label="Latitude" data-rm="cp-lat" value="<?= e($editCheckpoint['lat'] ?? '') ?>">
                        <input type="number" step="0.000001" min="-180" max="180" name="lng" class="form-control" placeholder="Longitude" aria-label="Longitude" data-rm="cp-lng" value="<?= e($editCheckpoint['lng'] ?? '') ?>">
                    </div>
                    <button type="button" class="btn btn-outline-primary btn-sm w-100 mt-1" data-rm="pick"><i class="bi bi-geo-alt"></i> Pick on map</button>
                </div>
                <div class="mb-3">
                    <label class="form-label small">Pass radius (meters)</label>
                    <input type="number" name="radius_m" min="20" max="500" class="form-control form-control-sm" value="<?= (int) ($editCheckpoint['radius_m'] ?? 60) ?>">
                    <div class="form-text">A runner passes the checkpoint within this distance.</div>
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
<script src="<?= assetUrl('vendor/leaflet/leaflet.js') ?>"></script>
<script src="<?= assetUrl('js/race_map.js') ?>"></script>
<?php include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php'; ?>
