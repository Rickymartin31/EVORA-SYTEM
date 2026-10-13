<?php
/**
 * Live race tracker for a runner (v56). Opened on the runner's phone on race day: Start sends GPS
 * positions while running, Finish ends the track and checks it against the official route.
 */
Auth::requireRole('participant');
$db = Database::connection();
$userId = Auth::id();

$registrationId = (int) ($_GET['registration_id'] ?? 0);
$check = RaceTrackingService::trackable($db, $registrationId, $userId);
if (!$check['reg']) {
    flash('error', 'Registration not found.');
    redirect('my-registrations');
}
$reg = $check['reg'];
$track = RaceTrackingService::trackFor($db, $registrationId);
$route = RaceTrackingService::route($db, (int) $reg['event_id']);
$gpxWhy = RaceTrackingService::gpxBlockedReason($reg); // v68: Strava / watch upload
$checkpoints = array_map(fn ($c) => ['label' => $c['label'], 'type' => $c['checkpoint_type'], 'lat' => $c['lat_f'], 'lng' => $c['lng_f'], 'radius' => (int) $c['radius_m']],
    RaceTrackingService::mappedCheckpoints($db, (int) $reg['event_id']));

$verdictLabels = [
    'verified' => ['Route verified', 'bg-success'],
    'possible_shortcut' => ['Under review by the organizer', 'bg-warning text-dark'],
    'insufficient' => ['Not enough GPS data', 'bg-secondary'],
    'pending' => ['Checking…', 'bg-secondary'],
];

$pageDescription = 'Keep this page open while you run so the organizer can follow you on the route.';
$pageTitle = 'Race Tracker';
$activeRoute = 'my-registrations';
include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
?>
<link rel="stylesheet" href="<?= assetUrl('vendor/leaflet/leaflet.css') ?>">
<?php renderBreadcrumb([
    ['label' => 'My Registrations', 'route' => 'my-registrations'],
    ['label' => $reg['event_name'], 'route' => 'event-view', 'params' => ['id' => $reg['event_id']]],
    ['label' => 'Race Tracker'],
]); ?>

<div class="row g-3">
    <div class="col-lg-5">
        <div class="card-evora p-4" id="raceTracker"
             data-action-url="<?= e(url('race-track-action')) ?>"
             data-csrf="<?= e(Csrf::token()) ?>"
             data-registration="<?= $registrationId ?>"
             data-status="<?= e($track['status'] ?? 'none') ?>"
             data-started="<?= e($track['started_at'] ?? '') ?>"
             data-blocked="<?= e($check['why'] ?? '') ?>">
            <div class="d-flex justify-content-between align-items-start mb-2">
                <div>
                    <h6 class="fw-semibold mb-0"><?= e($reg['event_name']) ?></h6>
                    <div class="small text-muted"><?= date('M d, Y', strtotime($reg['event_date'])) ?><?= $reg['distance_category'] ? ' · ' . e($reg['distance_category']) : '' ?></div>
                </div>
                <?php if ($reg['bib_number']): ?><span class="badge bg-dark fs-6">Bib <?= e($reg['bib_number']) ?></span><?php endif; ?>
            </div>

            <?php if ($track && $track['status'] === 'finished'): ?>
                <?php [$vLabel, $vBadge] = $verdictLabels[$track['verdict']] ?? $verdictLabels['pending']; ?>
                <div class="alert alert-success small mb-3"><i class="bi bi-flag-fill me-1"></i> <?= ($track['source'] ?? 'live') === 'gpx' ? 'Your run was uploaded from a GPX file (Strava or a sports watch).' : 'You finished. Your track was saved.' ?></div>
                <div class="row text-center g-2 mb-3">
                    <div class="col-6"><div class="race-stat"><div class="race-stat-value"><?= number_format((int) $track['distance_m'] / 1000, 2) ?></div><div class="race-stat-label">km</div></div></div>
                    <div class="col-6"><div class="race-stat"><div class="race-stat-value"><?= gmdate('H:i:s', max(0, strtotime((string) $track['finished_at']) - strtotime($track['started_at']))) ?></div><div class="race-stat-label">time</div></div></div>
                </div>
                <p class="small mb-1">Route check: <span class="badge <?= $vBadge ?>"><?= e($vLabel) ?></span></p>
                <?php if ($track['verdict'] === 'possible_shortcut' || $track['verdict'] === 'insufficient'): ?>
                    <p class="small text-muted mb-0">GPS on phones can be off. The organizer reviews this before any decision.</p>
                <?php endif; ?>
            <?php elseif ($check['why']): ?>
                <?= emptyState('bi-geo-alt', $check['why']) ?>
            <?php else: ?>
                <div class="row text-center g-2 mb-3">
                    <div class="col-4"><div class="race-stat"><div class="race-stat-value" data-rt="distance">0.00</div><div class="race-stat-label">km</div></div></div>
                    <div class="col-4"><div class="race-stat"><div class="race-stat-value" data-rt="time">00:00:00</div><div class="race-stat-label">time</div></div></div>
                    <div class="col-4"><div class="race-stat"><div class="race-stat-value" data-rt="gps">—</div><div class="race-stat-label">GPS</div></div></div>
                </div>
                <div class="small mb-3" data-rt="message" role="status" aria-live="polite">Tap <strong>Start</strong> at the starting line.</div>
                <button type="button" class="btn btn-evora-primary btn-lg w-100" data-rt="start"><i class="bi bi-play-fill"></i> Start</button>
                <button type="button" class="btn btn-danger btn-lg w-100 d-none" data-rt="finish"><i class="bi bi-flag-fill"></i> Finish</button>
                <ul class="small text-muted mt-3 mb-0 ps-3">
                    <li>Keep this page open and the screen on while you run.</li>
                    <li>No signal? Your positions are kept on the phone and sent when you are back online.</li>
                    <li>Tap Finish only after you cross the finish line.</li>
                </ul>
            <?php endif; ?>
        </div>
        <?php if ((!$track || (int) $track['point_count'] < 10) && $gpxWhy === null): ?>
        <div class="card-evora p-4 mt-3">
            <h6 class="fw-semibold mb-1"><i class="bi bi-smartwatch me-1"></i> Ran with Strava or a sports watch?</h6>
            <p class="small text-muted mb-3">Upload the GPX file of your run instead of using this tracker. It is checked against the route the same way.</p>
            <form method="post" action="<?= url('race-track-gpx') ?>" enctype="multipart/form-data" class="d-flex flex-column gap-2">
                <?= Csrf::field() ?>
                <input type="hidden" name="registration_id" value="<?= $registrationId ?>">
                <input type="file" name="gpx" accept=".gpx,application/gpx+xml" class="form-control" required aria-label="GPX file">
                <button type="submit" class="btn btn-outline-primary"><i class="bi bi-upload"></i> Upload GPX</button>
            </form>
            <details class="small text-muted mt-3">
                <summary>How to get the GPX file</summary>
                <ul class="mt-2 mb-0 ps-3">
                    <li><strong>Strava:</strong> open the activity on strava.com, then <em>⋯ → Export GPX</em>.</li>
                    <li><strong>Garmin Connect:</strong> activity → gear icon → <em>Export to GPX</em>.</li>
                    <li><strong>Other watches:</strong> look for <em>Export</em> or <em>Share → GPX</em> in the app.</li>
                    <li>Upload until <?= date('M d, Y', strtotime($reg['event_date'] . ' +' . RaceTrackingService::GPX_UPLOAD_DAYS . ' days')) ?>. The run must be recorded on race day.</li>
                </ul>
            </details>
        </div>
        <?php endif; ?>
    </div>
    <div class="col-lg-7">
        <div class="card-evora p-2">
            <?php if ($route || $checkpoints): ?>
                <div id="raceTrackerMap" class="race-map race-map-sm"
                     data-route="<?= e(json_encode($route['points'] ?? [])) ?>"
                     data-checkpoints="<?= e(json_encode($checkpoints)) ?>"></div>
            <?php else: ?>
                <div class="p-3"><?= emptyState('bi-map', 'The organizer has not put the route on the map yet.') ?></div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="<?= assetUrl('vendor/leaflet/leaflet.js') ?>"></script>
<script src="<?= assetUrl('js/race_map.js') ?>"></script>
<script src="<?= assetUrl('js/race_tracker.js') ?>"></script>
<?php include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php'; ?>
