<?php
// My Results (v39): events the participant took part in — attendance, race results, certificates.
Auth::requireRole('participant');
$db = Database::connection();
$history = ParticipantToolsService::history($db, (int) Auth::id());

$attended = count(array_filter($history, fn($h) => $h['checked_in_at']));
$finished = array_filter($history, fn($h) => $h['duration_seconds'] !== null);
$best = $finished ? min(array_map(fn($h) => (int) $h['duration_seconds'], $finished)) : null;
$certs = count(array_filter($history, fn($h) => $h['certificate_id']));

$pageTitle = 'My Results';
$pageDescription = 'The events you joined, your attendance, race results and certificates.';
$activeRoute = 'my-history';
include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
?>
<?php renderBreadcrumb([['label' => 'My Results']]); ?>
<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3"><div class="card-evora p-3"><div class="small text-muted">Events joined</div><div class="h4 fw-bold mb-0"><?= count($history) ?></div></div></div>
    <div class="col-6 col-lg-3"><div class="card-evora p-3"><div class="small text-muted">Checked in</div><div class="h4 fw-bold mb-0"><?= $attended ?></div></div></div>
    <div class="col-6 col-lg-3"><div class="card-evora p-3"><div class="small text-muted">Races finished</div><div class="h4 fw-bold mb-0"><?= count($finished) ?></div></div></div>
    <div class="col-6 col-lg-3"><div class="card-evora p-3"><div class="small text-muted">Certificates</div><div class="h4 fw-bold mb-0"><?= $certs ?></div></div></div>
</div>
<section class="card-evora p-4" aria-labelledby="histHeading">
    <h2 class="h6 fw-semibold mb-3" id="histHeading">Event history</h2>
    <?php if (!$history): ?>
        <?= emptyState('bi-trophy', 'Your past events and results will appear here after you take part in an event.', 'Browse Events', url('events')) ?>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table align-middle table-datatable" data-order='[[0,"desc"]]'>
            <thead><tr><th>Date</th><th>Event</th><th>Attendance</th><th>Result</th><th class="text-end">Certificate</th></tr></thead>
            <tbody>
            <?php foreach ($history as $h): ?>
                <tr>
                    <td class="small text-nowrap" data-order="<?= e($h['event_date']) ?>"><?= date('M d, Y', strtotime($h['event_date'])) ?></td>
                    <td><a href="<?= url('event-view', ['id' => $h['event_id']]) ?>" class="fw-semibold"><?= e($h['event_name']) ?></a>
                        <div class="small text-muted"><?= e($h['category_name']) ?><?= $h['distance_category'] ? ' · ' . e($h['distance_category']) : '' ?><?= $h['bib_number'] ? ' · Bib ' . e($h['bib_number']) : '' ?></div></td>
                    <td class="small"><?= $h['checked_in_at'] ? '<span class="badge bg-success">Checked in</span> <span class="text-muted">' . date('g:i A', strtotime($h['checked_in_at'])) . '</span>' : '<span class="badge bg-secondary">No check-in</span>' ?></td>
                    <td class="small">
                        <?php if ($h['duration_seconds'] !== null): ?>
                            <strong><?= e(ParticipantToolsService::formatDuration((int) $h['duration_seconds'])) ?></strong><?= (int) $h['duration_seconds'] === $best && count($finished) > 1 ? ' <span class="badge bg-warning text-dark">Personal best</span>' : '' ?>
                            <div class="text-muted">#<?= (int) $h['rank_category'] ?> of <?= (int) $h['finishers_category'] ?> in <?= e($h['distance_category'] ?: 'category') ?> · #<?= (int) $h['rank_overall'] ?> of <?= (int) $h['finishers_overall'] ?> overall</div>
                        <?php elseif ($h['category_slug'] === 'fun-run'): ?>
                            <span class="text-muted">No finish time recorded</span>
                        <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                    </td>
                    <td class="text-end"><?= $h['certificate_id'] ? '<a class="btn btn-sm btn-outline-secondary" href="' . url('my-certificate') . '"><i class="bi bi-award me-1" aria-hidden="true"></i>View</a>' : '<span class="small text-muted">—</span>' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</section>
<?php include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php'; ?>
