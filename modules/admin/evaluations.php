<?php
Auth::requireRole('admin');
$db = Database::connection();

// Admin System Feedback (Reports / Analytics). Read-only: admins view ratings,
// they never submit, edit or delete them (the database refuses that too).

$categories = [
    'functionality' => 'Functionality',
    'usability' => 'Usability',
    'reliability' => 'Reliability',
    'performance_efficiency' => 'Performance Efficiency',
    'security' => 'Security',
    'compatibility' => 'Compatibility',
    'maintainability' => 'Maintainability',
    'portability' => 'Portability',
];

$analytics = EvaluationService::platformAnalytics($db, 5);
$totalEvaluations = $analytics['responses'];
$avgColumns = implode(', ', array_map(fn($c) => "AVG($c) avg_$c", array_keys($categories)));
$averages = $db->query("SELECT $avgColumns FROM system_evaluations")->fetch();
$surveyCount = (int) $db->query('SELECT COUNT(*) FROM system_evaluations WHERE functionality IS NOT NULL')->fetchColumn();

// Feedback list: role / rating / date range filters (validated server-side).
$filters = EvaluationService::feedbackFilters($_GET);
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 20;
[$evaluations, $filteredTotal] = EvaluationService::feedbackList($db, $filters, $perPage, ($page - 1) * $perPage);
$totalPages = (int) ceil($filteredTotal / $perPage);

$pageDescription = 'How participants, organizers and staff rate EVORA.';
$pageTitle = 'System Feedback';
$activeRoute = 'admin-reports'; // lives under Reports / Analytics (no sidebar item of its own)
include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
?>
<?php renderBreadcrumb([['label' => 'Reports', 'route' => 'admin-reports'], ['label' => 'System Feedback']]); ?>

<div class="row g-3 mb-3">
    <div class="col-md-4">
        <div class="stat-card accent-success">
            <div class="stat-value"><span class="rating-stars"><?= EvaluationService::stars($analytics['average']) ?></span> <?= $totalEvaluations ? number_format($analytics['average'], 1) : '—' ?> / 5</div>
            <div class="stat-label">Average Rating</div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-card"><div class="stat-value"><?= $totalEvaluations ?></div><div class="stat-label">Total Responses</div><div class="stat-desc"><?= $surveyCount ?> with the full ISO survey</div></div>
    </div>
    <div class="col-md-4">
        <div class="stat-card"><div class="stat-value"><?= $totalEvaluations ? $analytics['satisfaction'] . '%' : '—' ?></div><div class="stat-label">Overall Satisfaction</div><div class="stat-desc">Rated 4–5 stars</div></div>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-5">
        <div class="card-evora p-4 h-100">
            <h6 class="fw-semibold mb-3">Role Satisfaction Comparison</h6>
            <?php foreach ($analytics['by_role'] as $roleName => $roleRow): ?>
                <div class="mb-3">
                    <div class="d-flex justify-content-between small">
                        <span class="fw-semibold"><?= e($roleName === 'staff' ? 'Staff' : ucfirst($roleName) . 's') ?></span>
                        <span><?php if ($roleRow['responses']): ?><span class="rating-stars"><?= EvaluationService::stars($roleRow['average']) ?></span> <?= number_format($roleRow['average'], 1) ?><?php else: ?><span class="text-muted">no ratings yet</span><?php endif; ?>
                            <span class="text-muted">· <?= $roleRow['responses'] ?> response<?= $roleRow['responses'] === 1 ? '' : 's' ?></span></span>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <div class="progress progress-thin flex-grow-1"><div class="progress-bar bg-success" style="width:<?= (int) ($roleRow['satisfaction'] ?? 0) ?>%"></div></div>
                        <span class="small fw-semibold" style="width:3rem;"><?= $roleRow['satisfaction'] !== null ? $roleRow['satisfaction'] . '%' : '—' ?></span>
                    </div>
                </div>
            <?php endforeach; ?>
            <p class="small text-muted mb-0">Satisfaction = share of ratings with 4 or 5 stars.</p>
        </div>
    </div>
    <div class="col-lg-3">
        <div class="card-evora p-4 h-100">
            <h6 class="fw-semibold mb-3">Rating Distribution</h6>
            <?php for ($i = 5; $i >= 1; $i--): $pct = $totalEvaluations ? round(100 * $analytics['distribution'][$i] / $totalEvaluations) : 0; ?>
                <a href="<?= url('admin-evaluations', array_merge($filters, ['rating' => $i])) ?>" class="d-flex align-items-center gap-2 small mb-1 text-decoration-none text-dark" title="Show <?= $i ?>-star feedback">
                    <span class="text-nowrap" style="width:2.5rem;"><?= $i ?> ★</span>
                    <div class="progress progress-thin flex-grow-1"><div class="progress-bar bg-warning" style="width:<?= $pct ?>%"></div></div>
                    <span class="text-muted text-end" style="width:2rem;"><?= $analytics['distribution'][$i] ?></span>
                </a>
            <?php endfor; ?>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card-evora p-4 h-100">
            <h6 class="fw-semibold mb-3">Experience Categories <span class="small text-muted fw-normal">(optional answers)</span></h6>
            <?php foreach ($analytics['categories'] as $cat): ?>
                <div class="d-flex justify-content-between border-bottom py-2 small">
                    <span><?= e($cat['label']) ?></span>
                    <span><?php if ($cat['responses']): ?><span class="rating-stars"><?= EvaluationService::stars($cat['average']) ?></span> <?= number_format($cat['average'], 1) ?> <span class="text-muted">(<?= $cat['responses'] ?>)</span><?php else: ?><span class="text-muted">—</span><?php endif; ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<div class="card-evora p-4 mb-3">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <h6 class="fw-semibold mb-0">All Feedback <span class="text-muted fw-normal small">(<?= $filteredTotal ?> of <?= $totalEvaluations ?>)</span></h6>
    </div>
    <form method="get" action="<?= url('admin-evaluations') ?>" class="row g-2 align-items-end mb-3">
        <input type="hidden" name="route" value="admin-evaluations">
        <div class="col-sm-6 col-lg-3">
            <label class="form-label small mb-1" for="fbRole">Role</label>
            <select name="role" id="fbRole" class="form-select form-select-sm">
                <option value="">All</option>
                <?php foreach (EvaluationService::PLATFORM_ROLES as $roleOption): ?>
                    <option value="<?= $roleOption ?>" <?= ($filters['role'] ?? '') === $roleOption ? 'selected' : '' ?>><?= ucfirst($roleOption) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-sm-6 col-lg-2">
            <label class="form-label small mb-1" for="fbRating">Rating</label>
            <select name="rating" id="fbRating" class="form-select form-select-sm">
                <option value="">All</option>
                <?php for ($i = 5; $i >= 1; $i--): ?>
                    <option value="<?= $i ?>" <?= ($filters['rating'] ?? 0) === $i ? 'selected' : '' ?>><?= $i ?> star<?= $i > 1 ? 's' : '' ?></option>
                <?php endfor; ?>
            </select>
        </div>
        <div class="col-sm-6 col-lg-2">
            <label class="form-label small mb-1" for="fbFrom">From</label>
            <input type="date" name="date_from" id="fbFrom" class="form-control form-control-sm" value="<?= e($filters['date_from'] ?? '') ?>">
        </div>
        <div class="col-sm-6 col-lg-2">
            <label class="form-label small mb-1" for="fbTo">To</label>
            <input type="date" name="date_to" id="fbTo" class="form-control form-control-sm" value="<?= e($filters['date_to'] ?? '') ?>">
        </div>
        <div class="col-lg-3 d-flex gap-2">
            <button type="submit" class="btn btn-sm btn-evora-primary"><i class="bi bi-funnel me-1"></i> Filter</button>
            <?php if ($filters): ?><a href="<?= url('admin-evaluations') ?>" class="btn btn-sm btn-outline-secondary">Clear</a><?php endif; ?>
        </div>
    </form>

    <?php if (empty($evaluations)): ?>
        <?= emptyState('bi-star', $filters ? 'No feedback matches these filters.' : 'No evaluations submitted yet.') ?>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-sm align-middle">
            <thead><tr><th>User Role</th><th>Rating</th><th>Comment</th><th>Date Submitted</th><th>User</th><th>Type</th></tr></thead>
            <tbody>
            <?php foreach ($evaluations as $ev): ?>
                <tr>
                    <td><span class="badge bg-light text-dark border"><?= e(ucfirst($ev['rater_role'])) ?></span></td>
                    <td class="text-nowrap"><span class="rating-stars"><?= EvaluationService::stars($ev['rating']) ?></span> <span class="small text-muted"><?= e($ev['rating']) ?></span></td>
                    <td class="small"><?= $ev['comment'] ? '"' . e($ev['comment']) . '"' : '<span class="text-muted">—</span>' ?></td>
                    <td class="small text-nowrap"><?= date('M d, Y', strtotime($ev['created_at'])) ?></td>
                    <td class="small"><?= e($ev['full_name']) ?></td>
                    <td class="small text-muted"><?= $ev['functionality'] !== null ? 'ISO survey' : ($ev['source'] === 'star_button' ? '⭐ Rate EVORA' : 'Quick rating') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if ($totalPages > 1): ?>
    <nav>
        <ul class="pagination pagination-sm mb-0">
            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                <li class="page-item <?= $i === $page ? 'active' : '' ?>"><a class="page-link" href="<?= url('admin-evaluations', array_merge($filters, ['page' => $i])) ?>"><?= $i ?></a></li>
            <?php endfor; ?>
        </ul>
    </nav>
    <?php endif; ?>
    <?php endif; ?>
</div>

<?php if ($surveyCount > 0): ?>
<div class="card-evora p-4">
    <h6 class="fw-semibold mb-3">Average Score per ISO/IEC 25010 Category <span class="small text-muted fw-normal">(<?= $surveyCount ?> full survey<?= $surveyCount === 1 ? '' : 's' ?>)</span></h6>
    <canvas id="evalChart" height="220"></canvas>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
new Chart(document.getElementById('evalChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_values($categories)) ?>,
        datasets: [{
            label: 'Average Score',
            data: <?= json_encode(array_map(fn($c) => round((float) $averages['avg_' . $c], 2), array_keys($categories))) ?>,
            backgroundColor: '#4f46e5'
        }]
    },
    options: { indexAxis: 'y', plugins: { legend: { display: false } }, scales: { x: { min: 0, max: 5 } } }
});
});
</script>
<?php endif; ?>
<?php include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php'; ?>
