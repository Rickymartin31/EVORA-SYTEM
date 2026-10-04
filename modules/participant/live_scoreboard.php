<?php
// Public, read-only. No Auth check by design (same precedent as certificate_verify.php
// and event_view.php) — no POST is ever accepted here.
$db = Database::connection();

$matchId = (int) ($_GET['match_id'] ?? 0);
$displayMode = ($_GET['display'] ?? '') === '1';

$stmt = $db->prepare(
    'SELECT m.*, e.name AS event_name,
        s.name AS sport_name, s.scoring_method, s.sport_type,
        ta.team_name AS team_a_name, tb.team_name AS team_b_name,
        pau.full_name AS participant_a_name, pbu.full_name AS participant_b_name,
        sc.team_a_score, sc.team_b_score, sc.score_detail
     FROM matches m
     JOIN events e ON e.id = m.event_id
     JOIN sports s ON s.id = m.sport_id
     LEFT JOIN teams ta ON ta.id = m.team_a_id
     LEFT JOIN teams tb ON tb.id = m.team_b_id
     LEFT JOIN participants pa ON pa.id = m.participant_a_id
     LEFT JOIN users pau ON pau.id = pa.user_id
     LEFT JOIN participants pb ON pb.id = m.participant_b_id
     LEFT JOIN users pbu ON pbu.id = pb.user_id
     LEFT JOIN match_scores sc ON sc.match_id = m.id
     WHERE m.id = :id'
);
$stmt->execute(['id' => $matchId]);
$match = $stmt->fetch();

$statusMeta = [
    'scheduled' => 'UPCOMING',
    'ongoing' => 'LIVE NOW',
    'paused' => 'PAUSED',
    'completed' => 'FINAL',
    'cancelled' => 'CANCELLED',
];

$teamAName = $match ? ($match['team_a_name'] ?? $match['participant_a_name'] ?? 'TBD') : '';
$teamBName = $match ? ($match['team_b_name'] ?? $match['participant_b_name'] ?? 'TBD') : '';
$statusLabel = $match ? ($statusMeta[$match['status']] ?? strtoupper($match['status'])) : '';

$detail = [];
if ($match && $match['score_detail']) {
    $decoded = json_decode($match['score_detail'], true);
    if (is_array($decoded)) {
        $detail = $decoded;
    }
}
$isSets = $match && $match['scoring_method'] === 'sets';
$isPointsClock = $match && $match['sport_type'] === 'team' && $match['scoring_method'] === 'points' && isset($detail['period']);

if ($displayMode) {
    // Minimal, large-text, auto-refreshing layout for a TV/LED/projector — no
    // nav chrome, no controls, nothing but the score.
    ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="refresh" content="6">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $match ? e($teamAName . ' vs ' . $teamBName) : 'Live Scoreboard' ?> — EVORA</title>
    <style>
        * { box-sizing: border-box; }
        body { margin:0; background:#0b0f1a; color:#fff; font-family:'Segoe UI',Arial,sans-serif; height:100vh; display:flex; align-items:center; justify-content:center; }
        .board { width:100%; max-width:1100px; text-align:center; padding:2rem; }
        .venue { font-size:2rem; letter-spacing:.3rem; color:#9fb3ff; margin-bottom:.5rem; }
        .status { font-size:1.8rem; font-weight:800; letter-spacing:.4rem; color:#ff4d4f; margin-bottom:1.5rem; }
        .status.final { color:#4dff88; }
        .status.upcoming, .status.paused { color:#ffd24d; }
        .teams { display:flex; justify-content:space-between; align-items:center; gap:2rem; }
        .team { flex:1; }
        .team-name { font-size:2.5rem; font-weight:700; text-transform:uppercase; margin-bottom:.5rem; }
        .team-score { font-size:9rem; font-weight:900; line-height:1; }
        .vs { font-size:2rem; color:#666; }
        .meta { margin-top:1.5rem; font-size:1.3rem; color:#aaa; }
        .not-found { font-size:2rem; }
    </style>
</head>
<body>
<?php if (!$match): ?>
    <div class="not-found">Match not found.</div>
<?php else: ?>
    <div class="board">
        <div class="venue"><?= $match['venue'] ? e($match['venue']) : e($match['event_name']) ?></div>
        <div class="status <?= $match['status'] === 'completed' ? 'final' : ($match['status'] === 'scheduled' || $match['status'] === 'paused' ? 'upcoming' : '') ?>"><?= e($statusLabel) ?></div>
        <div class="teams">
            <div class="team">
                <div class="team-name"><?= e($teamAName) ?></div>
                <div class="team-score"><?= $match['team_a_score'] !== null ? (int) $match['team_a_score'] : '-' ?></div>
            </div>
            <div class="vs">VS</div>
            <div class="team">
                <div class="team-name"><?= e($teamBName) ?></div>
                <div class="team-score"><?= $match['team_b_score'] !== null ? (int) $match['team_b_score'] : '-' ?></div>
            </div>
        </div>
        <div class="meta">
            Round <?= (int) $match['round_no'] ?>
            <?php if ($isPointsClock): ?> &bull; Period <?= (int) $detail['period'] ?><?php endif; ?>
            <?php if ($isSets && !empty($detail['sets'])): ?> &bull; Sets: <?php foreach ($detail['sets'] as $s): ?><?= (int) $s['a'] ?>-<?= (int) $s['b'] ?> <?php endforeach; ?><?php endif; ?>
        </div>
    </div>
<?php endif; ?>
</body>
</html>
    <?php
    exit;
}

$pageTitle = $match ? ($teamAName . ' vs ' . $teamBName) : 'Live Scoreboard';
require_once EVORA_ROOT . '/modules/shared/partials/sports_ui.php';
include EVORA_ROOT . '/modules/shared/partials/public_head.php';
?>
<div class="row justify-content-center">
    <div class="col-md-10 col-lg-8">
        <?php if (!$match): ?>
            <div class="card-evora p-4 text-center">
                <i class="bi bi-broadcast text-muted icon-xl" aria-hidden="true"></i>
                <h1 class="h5 fw-bold mt-2">Match Not Found</h1>
                <p class="text-muted mb-0">This scoreboard link doesn't match any match on file.</p>
            </div>
        <?php else: ?>
            <section class="card-evora p-4 text-center" aria-label="Live scoreboard">
                <div class="d-flex justify-content-between align-items-center gap-2 mb-3 text-start">
                    <div class="min-w-0">
                        <h1 class="h6 fw-semibold mb-0"><?= e($match['event_name']) ?></h1>
                        <div class="small text-muted">
                            <?= e($match['sport_name']) ?> · Round <?= (int) $match['round_no'] ?><?= $match['venue'] ? ' · ' . e($match['venue']) : '' ?>
                            <?= $match['scheduled_at'] ? ' · ' . date('M d, h:i A', strtotime($match['scheduled_at'])) : '' ?>
                        </div>
                    </div>
                    <?= matchStatusBadge($match['status'], ['scheduled' => 'Upcoming', 'ongoing' => 'Live Now', 'completed' => 'Final']) ?>
                </div>
                <div class="scoreboard scoreboard-lg<?= $match['status'] === 'ongoing' ? ' is-live' : '' ?>">
                    <div class="scoreboard-team">
                        <div class="scoreboard-name"><?= e($teamAName) ?></div>
                        <div class="scoreboard-score"><?= $match['team_a_score'] !== null ? (int) $match['team_a_score'] : '–' ?></div>
                    </div>
                    <div class="scoreboard-vs" aria-hidden="true">VS</div>
                    <div class="scoreboard-team">
                        <div class="scoreboard-name"><?= e($teamBName) ?></div>
                        <div class="scoreboard-score"><?= $match['team_b_score'] !== null ? (int) $match['team_b_score'] : '–' ?></div>
                    </div>
                </div>
                <?php if ($isPointsClock): ?>
                    <p class="text-muted small mt-3 mb-0">Period <?= (int) $detail['period'] ?></p>
                <?php elseif ($isSets && !empty($detail['sets'])): ?>
                    <p class="text-muted small mt-3 mb-0">Sets:
                        <?php foreach ($detail['sets'] as $s): ?>
                            <span class="badge bg-secondary font-tabular"><?= (int) $s['a'] ?>-<?= (int) $s['b'] ?></span>
                        <?php endforeach; ?>
                    </p>
                <?php endif; ?>
                <div class="d-flex justify-content-center gap-2 flex-wrap mt-3">
                    <a href="<?= url('match-hub', ['event_id' => $match['event_id']]) ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-grid" aria-hidden="true"></i> All Matches</a>
                    <a href="<?= url('live-scoreboard', ['match_id' => $matchId, 'display' => 1]) ?>" class="btn btn-outline-secondary btn-sm" target="_blank" rel="noopener"><i class="bi bi-tv" aria-hidden="true"></i> Display Mode (TV/Projector)<span class="visually-hidden"> (opens in a new tab)</span></a>
                </div>
                <p class="small text-muted mt-3 mb-0">Scores refresh automatically every 15 seconds.</p>
            </section>
        <?php endif; ?>
    </div>
</div>
<?php if ($match): ?>
<script>setTimeout(function () { window.location.reload(); }, 15000);</script>
<?php endif; ?>
<?php include EVORA_ROOT . '/modules/shared/partials/public_foot.php'; ?>
