<?php
// Admin: Verified Organizer Badge applications and active badges (v78).
Auth::requireRole('admin');
$db = Database::connection();

$filter = $_GET['status'] ?? 'pending';
$tabs = ['pending' => 'Waiting for review', 'needs_resubmission' => 'Resubmission requested', 'approved' => 'Approved', 'rejected' => 'Rejected', 'all' => 'All'];
if (!isset($tabs[$filter])) {
    $filter = 'pending';
}

$sql = 'SELECT a.*, o.organization_name, o.badge_expires_on, o.badge_revoked_at, o.badge_application_id, u.full_name,
               (SELECT COUNT(*) FROM organizer_badge_documents d WHERE d.application_id = a.id AND d.status <> "replaced") AS doc_count,
               (SELECT COUNT(*) FROM organizer_badge_documents d WHERE d.application_id = a.id AND d.status <> "replaced" AND d.auto_flags IS NOT NULL) AS flagged
        FROM organizer_badge_applications a JOIN organizers o ON o.id = a.organizer_id JOIN users u ON u.id = o.user_id';
$params = [];
if ($filter !== 'all') {
    $sql .= ' WHERE a.status = :s';
    $params['s'] = $filter;
}
$sql .= ' ORDER BY a.submitted_at ' . ($filter === 'pending' ? 'ASC' : 'DESC');
$stmt = $db->prepare($sql);
$stmt->execute($params);
$applications = $stmt->fetchAll();

$counts = $db->query('SELECT status, COUNT(*) c FROM organizer_badge_applications GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
$activeBadges = (int) $db->query('SELECT COUNT(*) FROM organizers o WHERE ' . OrganizerBadgeService::sqlVerified('o'))->fetchColumn();
$expiringSoon = (int) $db->query('SELECT COUNT(*) FROM organizers o WHERE ' . OrganizerBadgeService::sqlVerified('o') . ' AND o.badge_expires_on <= CURDATE() + INTERVAL ' . OrganizerBadgeService::REMIND_DAYS . ' DAY')->fetchColumn();

$statusBadge = ['pending' => 'bg-warning text-dark', 'needs_resubmission' => 'bg-info text-dark', 'approved' => 'bg-success', 'rejected' => 'bg-danger'];
$pageTitle = 'Verified Badges';
$pageDescription = 'Validate organizers\' documents and grant the Verified Organizer badge.';
$activeRoute = 'admin-badges';
include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
?>
<div class="row g-3 mb-3">
    <div class="col-sm-6 col-lg-3"><div class="card-evora p-3"><div class="small text-muted">Waiting for review</div><div class="h4 fw-bold mb-0 <?= ($counts['pending'] ?? 0) ? 'text-warning' : '' ?>"><?= (int) ($counts['pending'] ?? 0) ?></div></div></div>
    <div class="col-sm-6 col-lg-3"><div class="card-evora p-3"><div class="small text-muted">Resubmission requested</div><div class="h4 fw-bold mb-0"><?= (int) ($counts['needs_resubmission'] ?? 0) ?></div></div></div>
    <div class="col-sm-6 col-lg-3"><div class="card-evora p-3"><div class="small text-muted">Verified organizers</div><div class="h4 fw-bold mb-0 text-primary"><?= $activeBadges ?><?= verifiedBadge($activeBadges > 0) ?></div></div></div>
    <div class="col-sm-6 col-lg-3"><div class="card-evora p-3"><div class="small text-muted">Expiring in <?= OrganizerBadgeService::REMIND_DAYS ?> days</div><div class="h4 fw-bold mb-0"><?= $expiringSoon ?></div></div></div>
</div>
<section class="card-evora p-4" aria-labelledby="badgeAppsHeading">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h2 class="h6 fw-semibold mb-0" id="badgeAppsHeading">Badge applications</h2>
        <div class="btn-group btn-group-sm flex-wrap">
            <?php foreach ($tabs as $key => $label): ?>
                <a href="<?= url('admin-badges', ['status' => $key]) ?>" class="btn btn-outline-secondary <?= $filter === $key ? 'active' : '' ?>"><?= e($label) ?></a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php if (!$applications): ?>
        <?= emptyState('bi-patch-check', $filter === 'pending' ? 'No badge applications are waiting for review.' : 'No applications in this list.') ?>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table align-middle table-datatable">
            <thead><tr><th>Organization</th><th>Contact person</th><th>Type</th><th>Submitted</th><th>Documents</th><th>Status</th><th>Badge valid until</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($applications as $a): $grantedHere = (int) $a['badge_application_id'] === (int) $a['id']; ?>
                <tr>
                    <td class="fw-semibold"><?= e($a['organization_name']) ?></td>
                    <td><?= e($a['full_name']) ?></td>
                    <td><?= e(OrganizerBadgeService::ORG_TYPES[$a['organization_type']][0] ?? $a['organization_type']) ?></td>
                    <td data-order="<?= e($a['submitted_at']) ?>"><?= date('M d, Y', strtotime($a['submitted_at'])) ?></td>
                    <td><?= (int) $a['doc_count'] ?><?= $a['flagged'] ? ' <span class="badge bg-danger-subtle text-danger-emphasis" title="Automatic checks found something to look at"><i class="bi bi-flag-fill" aria-hidden="true"></i> ' . (int) $a['flagged'] . ' flagged</span>' : '' ?></td>
                    <td><span class="badge <?= $statusBadge[$a['status']] ?? 'bg-secondary' ?>"><?= e($tabs[$a['status']] ?? $a['status']) ?></span></td>
                    <td><?= $grantedHere && $a['badge_expires_on'] ? ($a['badge_revoked_at'] ? '<span class="text-danger">Revoked</span>' : date('M d, Y', strtotime($a['badge_expires_on']))) : '—' ?></td>
                    <td><a href="<?= url('admin-badge-review', ['id' => $a['id']]) ?>" class="btn btn-sm btn-outline-secondary"><?= $a['status'] === 'pending' ? 'Validate' : 'View' ?></a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</section>
<?php include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php'; ?>
