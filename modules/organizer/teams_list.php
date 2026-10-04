<?php
Auth::requireRole('organizer');
$db = Database::connection();
$userId = Auth::id();

$orgStmt = $db->prepare('SELECT id FROM organizers WHERE user_id = :uid');
$orgStmt->execute(['uid' => $userId]);
$organizer = $orgStmt->fetch();

$eventId = (int) ($_GET['event_id'] ?? 0);
$eventStmt = $db->prepare(
    'SELECT e.*, s.name AS sport_name, s.team_size, s.player_format FROM events e
     LEFT JOIN sports s ON s.id = e.sport_id
     WHERE e.id = :id AND e.organizer_id = :oid'
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

$teamsStmt = $db->prepare(
    'SELECT t.*, s.name AS sport_name FROM teams t JOIN sports s ON s.id = t.sport_id WHERE t.event_id = :eid ORDER BY t.created_at DESC'
);
$teamsStmt->execute(['eid' => $eventId]);
$teams = $teamsStmt->fetchAll();

$memberStmt = $db->prepare(
    'SELECT tm.*, u.full_name FROM team_members tm
     JOIN participants p ON p.id = tm.participant_id
     JOIN users u ON u.id = p.user_id
     WHERE tm.team_id = :tid'
);

$approvedParticipants = $db->prepare(
    'SELECT p.id, u.full_name FROM registrations r
     JOIN participants p ON p.id = r.participant_id
     JOIN users u ON u.id = p.user_id
     WHERE r.event_id = :eid AND r.status = "approved" ORDER BY u.full_name'
);
$approvedParticipants->execute(['eid' => $eventId]);
$participantsPool = $approvedParticipants->fetchAll();

$editTeam = null;
$editId = (int) ($_GET['edit'] ?? 0);
if ($editId > 0) {
    foreach ($teams as $t) {
        if ((int) $t['id'] === $editId) {
            $editTeam = $t;
            break;
        }
    }
}

$pageDescription = 'Create ' . $event['sport_name'] . ' teams and assign approved participants to each roster.';
$pageActions = '<a href="' . url('organizer-matches', ['event_id' => $eventId]) . '" class="btn btn-outline-secondary btn-sm"><i class="bi bi-trophy" aria-hidden="true"></i> Matches &amp; Scores</a>';
$pageTitle = 'Teams';
$activeRoute = 'organizer-events';
include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
?>
<?php renderBreadcrumb([
    ['label' => 'My Events', 'route' => 'organizer-events'],
    ['label' => $event['name'], 'route' => 'organizer-event-manage', 'params' => ['id' => $eventId]],
    ['label' => 'Teams'],
]); ?>

<div class="row g-3">
    <div class="col-lg-8">
        <?php if (empty($teams)): ?>
            <div class="card-evora p-4"><?= emptyState('bi-people', 'No teams created yet. Create your first team with the form, then add approved participants to its roster.') ?></div>
        <?php else: ?>
        <div class="d-flex justify-content-between align-items-center mb-2">
            <h2 class="h6 fw-semibold mb-0"><?= count($teams) ?> team<?= count($teams) === 1 ? '' : 's' ?></h2>
            <span class="small text-muted"><?= count($participantsPool) ?> approved participant<?= count($participantsPool) === 1 ? '' : 's' ?> available</span>
        </div>
        <div class="row g-3">
        <?php foreach ($teams as $team): $memberStmt->execute(['tid' => $team['id']]); $members = $memberStmt->fetchAll(); ?>
            <div class="col-md-6">
            <section class="card-evora p-3 h-100 d-flex flex-column" aria-label="<?= e($team['team_name']) ?>">
                <div class="d-flex justify-content-between align-items-start gap-2 mb-1">
                    <h3 class="h6 fw-semibold mb-0"><?= e($team['team_name']) ?></h3>
                    <a href="<?= url('organizer-teams', ['event_id' => $eventId, 'edit' => $team['id']]) ?>" class="btn btn-sm btn-outline-secondary text-nowrap flex-shrink-0" aria-label="Edit <?= e($team['team_name']) ?>"><i class="bi bi-pencil" aria-hidden="true"></i> Edit</a>
                </div>
                <div class="small text-muted mb-2">
                    <span class="badge bg-secondary"><?= count($members) ?><?= $event['team_size'] ? ' / ' . (int) $event['team_size'] : '' ?> members</span>
                    <?php if ($team['coach_name']): ?><span class="ms-1"><i class="bi bi-person-badge" aria-hidden="true"></i> Coach: <?= e($team['coach_name']) ?></span><?php endif; ?>
                </div>
                <ul class="list-unstyled small mb-3 flex-grow-1">
                    <?php foreach ($members as $m): ?>
                        <li class="d-flex justify-content-between align-items-center border-bottom py-1 gap-2">
                            <span><?php if (!empty($m['jersey_number'])): ?><span class="text-muted font-tabular">#<?= e($m['jersey_number']) ?></span> <?php endif; ?><?= e($m['full_name']) ?></span>
                            <form method="post" action="<?= url('organizer-team-save') ?>" data-confirm="<?= e($m['full_name']) ?> will be removed from <?= e($team['team_name']) ?>. Their registration is not affected." data-confirm-title="Remove team member?" data-confirm-button="Remove Member" data-confirm-variant="danger">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="team_action" value="remove_member">
                                <input type="hidden" name="event_id" value="<?= $eventId ?>">
                                <input type="hidden" name="team_member_id" value="<?= $m['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-link text-danger p-0" aria-label="Remove <?= e($m['full_name']) ?> from <?= e($team['team_name']) ?>">Remove</button>
                            </form>
                        </li>
                    <?php endforeach; ?>
                    <?php if (empty($members)): ?><li class="text-muted py-1">No members yet — add one below.</li><?php endif; ?>
                </ul>
                <form method="post" action="<?= url('organizer-team-save') ?>" class="d-flex gap-2">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="team_action" value="add_member">
                    <input type="hidden" name="event_id" value="<?= $eventId ?>">
                    <input type="hidden" name="team_id" value="<?= $team['id'] ?>">
                    <select name="participant_id" class="form-select form-select-sm" required aria-label="Participant to add to <?= e($team['team_name']) ?>">
                        <option value="">Select approved participant...</option>
                        <?php foreach ($participantsPool as $p): ?>
                            <option value="<?= $p['id'] ?>"><?= e($p['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-sm btn-outline-secondary text-nowrap"><i class="bi bi-person-plus" aria-hidden="true"></i> Add</button>
                </form>
            </section>
            </div>
        <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <div class="col-lg-4">
        <div class="card-evora p-4">
            <h6 class="fw-semibold mb-3"><?= $editTeam ? 'Edit Team' : 'Create ' . e($event['sport_name']) . ' Team' ?></h6>
            <?php if (!$editTeam && ($event['player_format'] || $event['team_size'])): ?>
                <p class="text-muted small">
                    <?= $event['player_format'] ? e($event['player_format']) : '' ?>
                    <?= $event['team_size'] ? ' — recommended team size: ' . (int) $event['team_size'] : '' ?>.
                </p>
            <?php endif; ?>
            <form method="post" action="<?= url('organizer-team-save') ?>">
                <?= Csrf::field() ?>
                <input type="hidden" name="team_action" value="<?= $editTeam ? 'update_team' : 'create_team' ?>">
                <input type="hidden" name="event_id" value="<?= $eventId ?>">
                <?php if ($editTeam): ?>
                    <input type="hidden" name="team_id" value="<?= $editTeam['id'] ?>">
                <?php endif; ?>
                <div class="mb-2">
                    <label class="form-label small" for="teamName">Team Name *</label>
                    <input type="text" id="teamName" name="team_name" class="form-control form-control-sm" value="<?= e($editTeam['team_name'] ?? '') ?>" required>
                </div>
                <div class="mb-3">
                    <label class="form-label small" for="coachName">Coach Name</label>
                    <input type="text" id="coachName" name="coach_name" class="form-control form-control-sm" value="<?= e($editTeam['coach_name'] ?? '') ?>">
                </div>
                <button type="submit" class="btn btn-evora-primary btn-sm w-100"><?= $editTeam ? 'Update Team' : 'Create Team' ?></button>
                <?php if ($editTeam): ?>
                    <a href="<?= url('organizer-teams', ['event_id' => $eventId]) ?>" class="btn btn-outline-secondary btn-sm w-100 mt-2">Cancel</a>
                <?php endif; ?>
            </form>
        </div>
    </div>
</div>
<?php include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php'; ?>
