<?php
Auth::requireRole('organizer');
$db = Database::connection();
$userId = Auth::id();

$orgStmt = $db->prepare('SELECT * FROM organizers WHERE user_id = :uid AND status = "approved"');
$orgStmt->execute(['uid' => $userId]);
$organizer = $orgStmt->fetch();

if (!$organizer) {
    flash('error', 'Your organizer account must be approved before creating events.');
    redirect('organizer-dashboard');
}

$catStmt = $db->query("SELECT id FROM event_categories WHERE slug = 'sports-tournament'");
$sportsCategoryId = (int) ($catStmt->fetch()['id'] ?? 0);

$sports = $db->query('SELECT * FROM sports WHERE is_active = 1 ORDER BY name')->fetchAll();

/**
 * Hand-written guidance for the sports organizers most commonly run.
 * Every other sport still gets a fully working page — its guidance is
 * generated from its DB attributes instead (see sportBlurb() below).
 */
$curatedNotes = [
    'Basketball' => 'Teams of 5 on court. Track points per quarter, keep a running scoreboard, and use the bracket for standings.',
    'Volleyball' => 'Best-of-5 sets is standard. Record sets won (not raw points) as the match score, and track team standings across the tournament.',
    'Pickleball' => 'Decide per team whether it is Singles (1 player), Doubles, or Mixed Doubles (2 players) — create the team size that matches.',
    'Badminton' => 'Decide per team whether it is Singles or Doubles — create a team of 1 or 2 accordingly. Matches are scored in sets.',
    'Football / Soccer' => 'Full squads with a starting line-up. Track goals as points; consider round-robin group stages before knockout rounds.',
    'Table Tennis' => 'Best-of-5 or best-of-7 games is common — record games won as the match score. Singles or Doubles teams.',
    'Boxing' => 'Individual bouts scored by rounds/judges\' decision — no teams needed, schedule matches directly between participants.',
    'Chess' => 'Individual, no teams needed. Standard scoring is Win = 1, Draw = 0.5, Loss = 0 — round-robin is the traditional format.',
    'Running' => 'Individual, time-based. EVORA can schedule head-to-head heats via Matches, but a full race leaderboard by finish time is not yet built — plan to also track finish times separately for now.',
];

$pageDescription = 'Pick the exact sport so team, scoring and bracket tools match how it is played.';
$pageTitle = 'Choose Your Sport';
$activeRoute = 'organizer-events';
include EVORA_ROOT . '/modules/shared/partials/layout_top.php';

function sportBlurb(array $s): string
{
    $bits = [];
    $bits[] = $s['sport_type'] === 'team' ? 'Team sport' : 'Individual sport';
    if ($s['player_format']) {
        $bits[] = $s['player_format'];
    }
    $scoringLabels = [
        'points' => 'scored by points', 'sets' => 'scored by sets won', 'rounds' => 'scored by rounds won',
        'win_draw_loss' => 'scored win/draw/loss', 'time_rank' => 'ranked by time',
    ];
    $bits[] = $scoringLabels[$s['scoring_method']] ?? 'scored by points';
    $formatLabels = [
        'single_elim' => 'single-elimination bracket', 'double_elim' => 'double-elimination bracket',
        'round_robin' => 'round-robin', 'league' => 'league/ranking format',
    ];
    $bits[] = 'typically run as a ' . ($formatLabels[$s['default_tournament_format']] ?? 'bracket');
    return ucfirst(implode(', ', $bits)) . '.';
}
?>
<?php renderBreadcrumb([
    ['label' => 'My Events', 'route' => 'organizer-events'],
    ['label' => 'Choose Event Type', 'route' => 'organizer-event-type'],
    ['label' => 'Choose Your Sport'],
]); ?>

<div class="card-evora p-4 mb-3">
    <h6 class="fw-semibold mb-1">Which sport are you managing?</h6>
    <p class="text-muted small mb-0">Pick the specific sport, not just "Sports Tournament" — EVORA will tailor team
        or player management, scoring labels, and tournament tools to it.</p>
    <label for="sportSearch" class="visually-hidden">Search sports</label>
    <input type="search" id="sportSearch" class="form-control mt-3" placeholder="Search sports (e.g. Volleyball, Chess, Running)..." aria-describedby="sportCount">
    <div id="sportCount" class="small text-muted mt-2" aria-live="polite"></div>
</div>

<div class="card-evora p-4 mb-3 d-none" id="sportNoMatch"><?= emptyState('bi-search', 'No sport matches your search. Try another name, or choose Other.') ?></div>
<div class="row g-3" id="sportGrid">
    <?php foreach ($sports as $s): if ($s['name'] === 'Other') continue; ?>
        <div class="col-sm-6 col-lg-4 sport-card" data-name="<?= e(strtolower($s['name'])) ?>">
            <div class="card-evora p-3 h-100 d-flex flex-column">
                <div class="d-flex justify-content-between align-items-start mb-1">
                    <h2 class="h6 fw-semibold mb-0"><?= e($s['name']) ?></h2>
                    <span class="badge <?= $s['sport_type'] === 'team' ? 'bg-primary' : 'bg-secondary' ?> flex-shrink-0"><?= $s['sport_type'] === 'team' ? 'Team' : 'Individual' ?></span>
                </div>
                <p class="text-muted small mb-2 flex-grow-1"><?= e($curatedNotes[$s['name']] ?? sportBlurb($s)) ?></p>
                <div class="small text-muted mb-2">
                    <?php if ($s['player_format']): ?><span class="badge bg-secondary me-1"><?= e($s['player_format']) ?></span><?php endif; ?>
                    <span class="badge bg-secondary me-1"><?= e(ucfirst(str_replace('_', ' ', $s['scoring_method']))) ?></span>
                    <span class="badge bg-secondary"><?= e(ucfirst(str_replace('_', ' ', $s['default_tournament_format']))) ?></span>
                </div>
                <a href="<?= url('organizer-event-form', ['category_id' => $sportsCategoryId, 'sport_id' => $s['id']]) ?>" class="btn btn-evora-primary btn-sm w-100">
                    Manage <?= e($s['name']) ?>
                </a>
            </div>
        </div>
    <?php endforeach; ?>
    <div class="col-sm-6 col-lg-4 sport-card" data-name="other">
        <div class="card-evora p-3 h-100 d-flex flex-column">
            <h2 class="h6 fw-semibold mb-1">Other</h2>
            <p class="text-muted small mb-2 flex-grow-1">Not listed above? Use this and EVORA will fall back to generic team-based tools.</p>
            <?php $other = array_values(array_filter($sports, fn ($s) => $s['name'] === 'Other'))[0] ?? null; ?>
            <?php if ($other): ?>
            <a href="<?= url('organizer-event-form', ['category_id' => $sportsCategoryId, 'sport_id' => $other['id']]) ?>" class="btn btn-outline-primary btn-sm w-100">Manage Other Sport</a>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
document.getElementById('sportSearch').addEventListener('input', function (e) {
    const q = e.target.value.trim().toLowerCase();
    let shown = 0;
    document.querySelectorAll('.sport-card').forEach(function (card) {
        const match = card.dataset.name.includes(q);
        card.classList.toggle('d-none', !match);
        if (match) shown++;
    });
    document.getElementById('sportNoMatch').classList.toggle('d-none', shown > 0);
    document.getElementById('sportCount').textContent = q ? shown + ' sport' + (shown === 1 ? '' : 's') + ' found' : '';
});
</script>
<?php include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php'; ?>
