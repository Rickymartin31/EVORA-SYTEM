<?php
Auth::requireRole('admin');
$db = Database::connection();

$id = (int) ($_GET['id'] ?? 0);
$stmt = $db->prepare('SELECT o.*, u.full_name, u.email, u.phone, u.status AS user_status FROM organizers o JOIN users u ON u.id = o.user_id WHERE o.id = :id');
$stmt->execute(['id' => $id]);
$organizer = $stmt->fetch();

if (!$organizer) {
    flash('error', 'Organizer application not found.');
    redirect('admin-organizers');
}

$verStmt = $db->prepare('SELECT * FROM organizer_verifications WHERE organizer_id = :id ORDER BY submitted_at DESC LIMIT 1');
$verStmt->execute(['id' => $id]);
$verification = $verStmt->fetch();

$eventCountStmt = $db->prepare('SELECT COUNT(*) c FROM events WHERE organizer_id = :id');
$eventCountStmt->execute(['id' => $id]);
$eventCount = $eventCountStmt->fetch()['c'];

$pageDescription = 'Check the applicant’s details and documents, then approve or reject.';
$pageTitle = 'Review Organizer';
$activeRoute = 'admin-organizers';
include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
?>
<?php renderBreadcrumb([
    ['label' => 'Organizer Verification', 'route' => 'admin-organizers'],
    ['label' => $organizer['organization_name']],
]); ?>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card-evora p-4 mb-3">
            <h6 class="fw-semibold mb-3">Organization Details</h6>
            <dl class="row mb-0 small">
                <dt class="col-sm-4">Organization Name</dt><dd class="col-sm-8"><?= e($organizer['organization_name']) ?></dd>
                <dt class="col-sm-4">Contact Person</dt><dd class="col-sm-8"><?= e($organizer['full_name']) ?></dd>
                <dt class="col-sm-4">Email</dt><dd class="col-sm-8"><?= e($organizer['email']) ?></dd>
                <dt class="col-sm-4">Contact Number</dt><dd class="col-sm-8"><?= e($organizer['contact_number']) ?></dd>
                <dt class="col-sm-4">Organization Info</dt><dd class="col-sm-8"><?= nl2br(e($organizer['organization_info'] ?? '—')) ?></dd>
                <dt class="col-sm-4">Applied On</dt><dd class="col-sm-8"><?= date('M d, Y H:i', strtotime($organizer['created_at'])) ?></dd>
                <dt class="col-sm-4">Events Created</dt><dd class="col-sm-8"><?= $eventCount ?></dd>
                <dt class="col-sm-4">Current Status</dt><dd class="col-sm-8"><span class="badge badge-status-<?= e($organizer['status']) ?>"><?= ucfirst(e($organizer['status'])) ?></span></dd>
                <?php if ($organizer['remarks']): ?>
                <dt class="col-sm-4">Last Remarks</dt><dd class="col-sm-8"><?= e($organizer['remarks']) ?></dd>
                <?php endif; ?>
            </dl>
        </div>

        <div class="card-evora p-4">
            <h6 class="fw-semibold mb-3">Supporting Documents</h6>
            <?php if ($verification): ?>
                <div class="d-flex gap-2 flex-wrap">
                    <a href="<?= uploadUrl($verification['valid_id_path']) ?>" target="_blank" class="btn btn-sm btn-outline-primary"><i class="bi bi-file-earmark-person"></i> View Valid ID</a>
                    <?php if ($verification['supporting_docs_path']): ?>
                        <a href="<?= uploadUrl($verification['supporting_docs_path']) ?>" target="_blank" class="btn btn-sm btn-outline-primary"><i class="bi bi-file-earmark-text"></i> View Supporting Docs</a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <?= emptyState('bi-file-earmark-x', 'No documents on file.') ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card-evora p-4">
            <h6 class="fw-semibold mb-3">Decision</h6>
            <form method="post" action="<?= url('admin-organizer-action') ?>" id="organizerDecisionForm">
                <?= Csrf::field() ?>
                <input type="hidden" name="organizer_id" value="<?= $organizer['id'] ?>">
                <div class="mb-3">
                    <label class="form-label">Remarks (optional, shown to organizer on rejection/suspension)</label>
                    <textarea name="remarks" class="form-control" rows="3"></textarea>
                </div>
                <div class="d-grid gap-2">
                    <?php if ($organizer['status'] !== 'approved'): ?>
                        <button type="submit" name="action" value="approve" class="btn btn-success"><i class="bi bi-check-circle"></i> Approve</button>
                    <?php endif; ?>
                    <?php if ($organizer['status'] !== 'rejected'): ?>
                        <button type="submit" name="action" value="reject" class="btn btn-outline-danger" data-confirm-click="The applicant is notified and cannot create events." data-confirm-title="Reject this organizer application?" data-confirm-button="Reject Application" data-confirm-variant="danger"><i class="bi bi-x-circle" aria-hidden="true"></i> Reject</button>
                    <?php endif; ?>
                    <?php if ($organizer['status'] === 'approved'): ?>
                        <button type="submit" name="action" value="suspend" class="btn btn-outline-warning" data-confirm-click="They lose the ability to manage events until reinstated." data-confirm-title="Suspend this organizer?" data-confirm-button="Suspend Organizer" data-confirm-variant="danger"><i class="bi bi-pause-circle" aria-hidden="true"></i> Suspend</button>
                    <?php endif; ?>
                    <?php if ($organizer['status'] === 'suspended'): ?>
                        <button type="submit" name="action" value="approve" class="btn btn-success"><i class="bi bi-arrow-counterclockwise"></i> Reinstate (Approve)</button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
</div>
<?php include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php'; ?>
