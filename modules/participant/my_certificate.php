<?php
Auth::requireRole('participant');
$db = Database::connection();
$userId = Auth::id();

$stmt = $db->prepare(
    'SELECT c.*, e.name AS event_name, e.event_date
     FROM certificates c
     JOIN registrations r ON r.id = c.registration_id
     JOIN participants p ON p.id = r.participant_id
     JOIN events e ON e.id = r.event_id
     WHERE p.user_id = :uid
     ORDER BY c.issued_at DESC'
);
$stmt->execute(['uid' => $userId]);
$certificates = $stmt->fetchAll();

$pageDescription = 'Certificates from events you attended.';
$pageTitle = 'Certificates';
$activeRoute = 'my-certificate';
include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
?>
<?php if (empty($certificates)): ?>
    <div class="card-evora p-4"><?= emptyState('bi-award', 'No certificates yet. Certificates are issued after you attend a seminar or workshop.', 'Browse Events', url('events')) ?></div>
<?php else: ?>
<div class="row g-3">
    <?php foreach ($certificates as $c): ?>
    <div class="col-md-6 col-lg-4">
        <div class="card-evora p-4 h-100 d-flex flex-column">
            <div class="d-flex align-items-start gap-2 mb-2">
                <i class="bi bi-award-fill text-warning fs-3"></i>
                <div>
                    <h6 class="fw-semibold mb-0"><?= e($c['event_name']) ?></h6>
                    <span class="badge bg-light text-dark border mt-1"><?= e($c['certificate_type']) ?></span>
                </div>
            </div>
            <dl class="row small mb-3 mt-2">
                <dt class="col-6 text-muted fw-normal">Date Issued</dt>
                <dd class="col-6 text-end"><?= date('M d, Y', strtotime($c['issued_at'])) ?></dd>
                <dt class="col-6 text-muted fw-normal">Certificate ID</dt>
                <dd class="col-6 text-end"><?= e($c['certificate_number']) ?></dd>
            </dl>
            <div class="d-flex gap-2 mt-auto">
                <a href="<?= uploadUrl($c['certificate_path']) ?>" target="_blank" class="btn btn-sm btn-outline-primary flex-fill"><i class="bi bi-eye"></i> View Certificate</a>
                <a href="<?= uploadUrl($c['certificate_path']) ?>" download class="btn btn-sm btn-evora-primary flex-fill"><i class="bi bi-download"></i> Download PDF</a>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
<?php include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php'; ?>
