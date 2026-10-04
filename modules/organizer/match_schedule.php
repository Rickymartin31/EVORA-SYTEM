<?php
Auth::requireRole('organizer');
$db = Database::connection();
$userId = Auth::id();

$orgStmt = $db->prepare('SELECT id FROM organizers WHERE user_id = :uid');
$orgStmt->execute(['uid' => $userId]);
$organizer = $orgStmt->fetch();

$eventId = (int) ($_GET['event_id'] ?? 0);
$eventStmt = $db->prepare(
    'SELECT e.*, s.name AS sport_name, s.sport_type, s.team_size FROM events e
     LEFT JOIN sports s ON s.id = e.sport_id WHERE e.id = :id AND e.organizer_id = :oid'
);
$eventStmt->execute(['id' => $eventId, 'oid' => $organizer['id'] ?? 0]);
$event = $eventStmt->fetch();

if (!$event) {
    flash('error', 'Event not found.');
    redirect('organizer-events');
}

EventAccess::requireEventType($db, $eventId, 'sports-tournament', 'organizer-events');

if (empty($event['sport_id'])) {
    flash('error', 'This event has no sport selected. Edit the event and choose a sport first.');
    redirect('organizer-event-manage', ['id' => $eventId]);
}

$usesTeams = $event['sport_type'] !== 'individual' || (int) $event['team_size'] !== 1;

$venuesStmt = $db->prepare('SELECT * FROM venues WHERE event_id = :eid ORDER BY name');
$venuesStmt->execute(['eid' => $eventId]);
$venues = $venuesStmt->fetchAll();
$availableVenues = array_values(array_filter($venues, fn ($v) => $v['status'] === 'available'));

if ($usesTeams) {
    $poolCountStmt = $db->prepare('SELECT COUNT(*) c FROM teams WHERE event_id = :eid');
} else {
    $poolCountStmt = $db->prepare('SELECT COUNT(*) c FROM registrations WHERE event_id = :eid AND status = "approved"');
}
$poolCountStmt->execute(['eid' => $eventId]);
$poolCount = (int) $poolCountStmt->fetch()['c'];

$matchesStmt = $db->prepare(
    'SELECT m.*, ta.team_name AS team_a_name, tb.team_name AS team_b_name,
        pau.full_name AS participant_a_name, pbu.full_name AS participant_b_name
     FROM matches m
     LEFT JOIN teams ta ON ta.id = m.team_a_id
     LEFT JOIN teams tb ON tb.id = m.team_b_id
     LEFT JOIN participants pa ON pa.id = m.participant_a_id
     LEFT JOIN users pau ON pau.id = pa.user_id
     LEFT JOIN participants pb ON pb.id = m.participant_b_id
     LEFT JOIN users pbu ON pbu.id = pb.user_id
     WHERE m.event_id = :eid ORDER BY m.round_no, m.scheduled_at IS NULL, m.scheduled_at, m.id'
);
$matchesStmt->execute(['eid' => $eventId]);
$matches = $matchesStmt->fetchAll();
$hasMatches = count($matches) > 0;

$rescheduleMatch = null;
$rescheduleId = (int) ($_GET['reschedule'] ?? 0);
if ($rescheduleId > 0) {
    foreach ($matches as $m) {
        if ((int) $m['id'] === $rescheduleId) {
            $rescheduleMatch = $m;
            break;
        }
    }
}

function opponentLabel(array $m, string $side): string
{
    if ($side === 'a') {
        return $m['team_a_name'] ?? $m['participant_a_name'] ?? 'TBD';
    }
    return $m['team_b_name'] ?? $m['participant_b_name'] ?? 'TBD';
}

$statusLabels = [
    'available' => ['label' => 'Available', 'badge' => 'bg-success'],
    'maintenance' => ['label' => 'Maintenance', 'badge' => 'bg-warning text-dark'],
    'closed' => ['label' => 'Closed', 'badge' => 'bg-secondary'],
];

require_once EVORA_ROOT . '/modules/shared/partials/sports_ui.php';
$statusCounts = array_count_values(array_column($matches, 'status'));
$pageDescription = 'Generate a full match schedule across your venues, then reschedule individual matches.';
$pageActions = '<a href="' . url('organizer-venues', ['event_id' => $eventId]) . '" class="btn btn-outline-secondary btn-sm"><i class="bi bi-geo-alt" aria-hidden="true"></i> Venues</a>';
$pageTitle = 'Match Schedule Generator';
$activeRoute = 'organizer-events';
include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
?>
<?php renderBreadcrumb([
    ['label' => 'My Events', 'route' => 'organizer-events'],
    ['label' => $event['name'], 'route' => 'organizer-event-manage', 'params' => ['id' => $eventId]],
    ['label' => 'Match Schedule Generator'],
]); ?>

<?= moduleOverview('bi-calendar2-week', $event['name'], [$event['sport_name'], $event['event_date'] ? date('M d, Y', strtotime($event['event_date'])) : null], [
    ['label' => $usesTeams ? 'Teams' : 'Approved Players', 'value' => $poolCount],
    ['label' => 'Venues Available', 'value' => count($availableVenues) . ' / ' . count($venues), 'tone' => count($availableVenues) ? 'success' : 'warning'],
    ['label' => 'Matches', 'value' => count($matches)],
    ['label' => 'Upcoming', 'value' => $statusCounts['scheduled'] ?? 0, 'tone' => 'info'],
]) ?>

<?php if (!$hasMatches): ?>
<div class="row g-3">
    <div class="col-lg-5">
        <div class="card-evora p-4">
            <h6 class="fw-semibold mb-3">Generate Schedule</h6>
            <?php if ($poolCount < 2): ?>
                <?= $usesTeams ? emptyState('bi-people', 'Need at least 2 teams before a schedule can be generated.', 'Manage Teams', url('organizer-teams', ['event_id' => $eventId])) : emptyState('bi-person-check', 'Need at least 2 approved participants before a schedule can be generated.', 'Review Registrations', url('organizer-registrations')) ?>
            <?php elseif (empty($availableVenues)): ?>
                <?= emptyState('bi-geo-alt', 'No available venues. Add a venue or reopen one under maintenance first.', 'Manage Venues', url('organizer-venues', ['event_id' => $eventId])) ?>
            <?php else: ?>
            <form method="post" action="<?= url('organizer-match-schedule-save') ?>" data-confirm="This creates every match for the tournament. It can't be undone once matches exist." data-confirm-title="Generate the full schedule?" data-confirm-button="Generate Schedule">
                <?= Csrf::field() ?>
                <input type="hidden" name="schedule_action" value="generate">
                <input type="hidden" name="event_id" value="<?= $eventId ?>">
                <div class="mb-2">
                    <label class="form-label small">Format *</label>
                    <select name="format" class="form-select form-select-sm">
                        <option value="single_elim" <?= ($event['tournament_format'] ?? '') === 'single_elim' ? 'selected' : '' ?>>Single Elimination</option>
                        <option value="round_robin" <?= ($event['tournament_format'] ?? '') === 'round_robin' ? 'selected' : '' ?>>Round Robin</option>
                    </select>
                    <?php if (in_array($event['tournament_format'], ['double_elim', 'league'], true)): ?>
                        <div class="form-text">This event is configured for <?= e(ucfirst(str_replace('_', ' ', $event['tournament_format']))) ?>, which the generator doesn't support yet — choose Single Elimination or Round Robin, or build the schedule manually on the Matches &amp; Scores page.</div>
                    <?php endif; ?>
                </div>
                <div class="mb-2">
                    <label class="form-label small">Tournament Date *</label>
                    <input type="date" name="tournament_date" class="form-control form-control-sm" value="<?= e($event['event_date']) ?>" required>
                </div>
                <div class="row g-2">
                    <div class="col-6">
                        <label class="form-label small">Start Time *</label>
                        <input type="time" name="start_time" class="form-control form-control-sm" value="<?= e(substr($event['event_time'], 0, 5)) ?>" required>
                    </div>
                    <div class="col-6">
                        <label class="form-label small">End Time *</label>
                        <input type="time" name="end_time" class="form-control form-control-sm" required>
                    </div>
                </div>
                <div class="row g-2 mt-0">
                    <div class="col-6">
                        <label class="form-label small">Match Duration (min) *</label>
                        <input type="number" name="match_duration" min="1" value="30" class="form-control form-control-sm" required>
                    </div>
                    <div class="col-6">
                        <label class="form-label small">Break Between (min)</label>
                        <input type="number" name="break_duration" min="0" value="10" class="form-control form-control-sm">
                    </div>
                </div>
                <div class="mb-3 mt-2">
                    <label class="form-label small">Venues *</label>
                    <?php foreach ($availableVenues as $v): ?>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="venue_ids[]" value="<?= $v['id'] ?>" id="v<?= $v['id'] ?>" checked>
                            <label class="form-check-label small" for="v<?= $v['id'] ?>"><?= e($v['name']) ?><?= $v['code'] ? ' (' . e($v['code']) . ')' : '' ?></label>
                        </div>
                    <?php endforeach; ?>
                    <div class="form-text">Maintenance and closed venues aren't selectable.</div>
                </div>
                <button type="submit" class="btn btn-evora-primary btn-sm w-100"><i class="bi bi-magic"></i> Generate Schedule</button>
            </form>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card-evora p-4">
            <h6 class="fw-semibold mb-3">Venues</h6>
            <?php if (empty($venues)): ?>
                <?= emptyState('bi-geo-alt', 'No venues defined yet. Matches need at least one available venue.', 'Add a Venue', url('organizer-venues', ['event_id' => $eventId])) ?>
            <?php else: ?>
            <div class="table-responsive"><table class="table table-sm align-middle mb-0">
                <thead><tr><th scope="col">Venue</th><th scope="col">Status</th></tr></thead>
                <tbody>
                <?php foreach ($venues as $v): ?>
                    <?php $s = $statusLabels[$v['status']] ?? $statusLabels['available']; ?>
                    <tr>
                        <td><?= e($v['name']) ?><?= $v['code'] ? ' <span class="badge bg-secondary">' . e($v['code']) . '</span>' : '' ?></td>
                        <td><span class="badge <?= $s['badge'] ?>"><?= e($s['label']) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php else: ?>
<div class="row g-3">
    <div class="col-lg-<?= $rescheduleMatch ? '8' : '12' ?>">
        <div class="card-evora p-4">
            <h6 class="fw-semibold mb-3">Generated Schedule</h6>
            <div class="table-responsive">
                <table class="table align-middle table-datatable">
                    <thead><tr><th scope="col">#</th><th scope="col">Round</th><th scope="col">Matchup</th><th scope="col">Time</th><th scope="col">Venue</th><th scope="col">Status</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
                    <tbody>
                    <?php foreach ($matches as $i => $m): ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td><?= $m['round_no'] ?></td>
                            <td><?= e(opponentLabel($m, 'a')) ?> vs <?= e(opponentLabel($m, 'b')) ?></td>
                            <td class="small nowrap"><?= $m['scheduled_at'] ? date('M d, h:i A', strtotime($m['scheduled_at'])) : '—' ?></td>
                            <td class="small"><?= $m['venue'] ? e($m['venue']) : '—' ?></td>
                            <td><?= matchStatusBadge($m['status']) ?></td>
                            <td>
                                <?php if ($m['status'] === 'scheduled' && $m['team_b_id'] !== null || $m['status'] === 'scheduled' && $m['participant_b_id'] !== null): ?>
                                    <a href="<?= url('organizer-match-schedule', ['event_id' => $eventId, 'reschedule' => $m['id']]) ?>" class="btn btn-sm btn-outline-secondary">Reschedule</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="form-text mt-2">To progress a bracket round or change formats, use the <a href="<?= url('organizer-matches', ['event_id' => $eventId]) ?>">Matches &amp; Scores</a> page.</div>
        </div>
    </div>
    <?php if ($rescheduleMatch): ?>
    <div class="col-lg-4">
        <div class="card-evora p-4">
            <h6 class="fw-semibold mb-3">Reschedule Match</h6>
            <p class="small text-muted"><?= e(opponentLabel($rescheduleMatch, 'a')) ?> vs <?= e(opponentLabel($rescheduleMatch, 'b')) ?></p>
            <form method="post" action="<?= url('organizer-match-schedule-save') ?>">
                <?= Csrf::field() ?>
                <input type="hidden" name="schedule_action" value="reschedule">
                <input type="hidden" name="event_id" value="<?= $eventId ?>">
                <input type="hidden" name="match_id" value="<?= $rescheduleMatch['id'] ?>">
                <div class="mb-2">
                    <label class="form-label small">Date *</label>
                    <input type="date" name="reschedule_date" class="form-control form-control-sm" value="<?= $rescheduleMatch['scheduled_at'] ? date('Y-m-d', strtotime($rescheduleMatch['scheduled_at'])) : e($event['event_date']) ?>" required>
                </div>
                <div class="mb-2">
                    <label class="form-label small">Time *</label>
                    <input type="time" name="reschedule_time" class="form-control form-control-sm" value="<?= $rescheduleMatch['scheduled_at'] ? date('H:i', strtotime($rescheduleMatch['scheduled_at'])) : '' ?>" required>
                </div>
                <div class="mb-3">
                    <label class="form-label small">Venue *</label>
                    <select name="venue_id" class="form-select form-select-sm" required>
                        <option value="">Choose a venue</option>
                        <?php foreach ($availableVenues as $v): ?>
                            <option value="<?= $v['id'] ?>"><?= e($v['name']) ?><?= $v['code'] ? ' (' . e($v['code']) . ')' : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Only available venues can be selected.</div>
                </div>
                <button type="submit" class="btn btn-evora-primary btn-sm w-100">Save New Time/Venue</button>
                <a href="<?= url('organizer-match-schedule', ['event_id' => $eventId]) ?>" class="btn btn-outline-secondary btn-sm w-100 mt-2">Cancel</a>
            </form>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>
<?php include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php'; ?>
