<?php
// Organizer: Verified Organizer Badge (v78) — status, requirements, application and resubmission.
Auth::requireRole('organizer');
$db = Database::connection();
$orgStmt = $db->prepare('SELECT * FROM organizers WHERE user_id = :uid');
$orgStmt->execute(['uid' => Auth::id()]);
$organizer = $orgStmt->fetch();
if (!$organizer) {
    redirect('organizer-dashboard');
}
$application = OrganizerBadgeService::latestApplication($db, (int) $organizer['id']);
$documents = $application ? OrganizerBadgeService::documents($db, (int) $application['id']) : [];
$state = OrganizerBadgeService::badgeState($organizer);
$canApply = OrganizerBadgeService::canApply($organizer, $application);
$appStatus = $application['status'] ?? null;

$docStatus = [
    'pending' => ['Waiting for review', 'bg-secondary'],
    'accepted' => ['Accepted', 'bg-success'],
    'rejected' => ['Rejected', 'bg-danger'],
];
$stateCard = match (true) {
    $state === 'verified' => ['bi-patch-check-fill', 'text-primary', 'Verified Organizer', 'Your badge shows next to your organization name on every event until ' . date('M d, Y', strtotime($organizer['badge_expires_on'])) . '.'],
    $state === 'expiring' => ['bi-patch-check-fill', 'text-warning', 'Verified — expiring soon', 'Your badge expires on ' . date('M d, Y', strtotime($organizer['badge_expires_on'])) . '. Submit a renewal below so your events keep the badge.'],
    $appStatus === 'pending' => ['bi-hourglass-split', 'text-info', 'Application under review', 'An administrator is validating your documents. You will be notified of the result.'],
    $appStatus === 'needs_resubmission' => ['bi-arrow-repeat', 'text-warning', 'Some documents need to be replaced', 'Upload new files for the rejected documents below.'],
    $state === 'expired' => ['bi-patch-exclamation', 'text-danger', 'Badge expired', 'Your badge expired on ' . date('M d, Y', strtotime($organizer['badge_expires_on'])) . '. Submit a renewal to get it back.'],
    $state === 'revoked' => ['bi-patch-minus', 'text-danger', 'Badge revoked', 'Reason: ' . $organizer['badge_revoke_reason']],
    $appStatus === 'rejected' => ['bi-patch-exclamation', 'text-danger', 'Application not approved', 'Reason: ' . ($application['remarks'] ?: '—') . ' You can apply again.'],
    default => ['bi-patch-question', 'text-muted', 'Not verified yet', 'Apply for the badge so participants know your organization is real and checked by EVORA.'],
};

$pageTitle = 'Verified Badge';
$pageDescription = 'Get the Verified Organizer badge: submit the requirements and an administrator validates each document.';
$activeRoute = 'organizer-verified-badge';
include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
?>
<?php renderBreadcrumb([['label' => 'Verified Badge']]); ?>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card-evora p-4 mb-3 d-flex flex-row gap-3 align-items-start">
            <i class="bi <?= $stateCard[0] ?> <?= $stateCard[1] ?> badge-state-icon" aria-hidden="true"></i>
            <div class="min-w-0">
                <h2 class="h5 fw-semibold mb-1"><?= e($stateCard[2]) ?><?= verifiedBadge(in_array($state, ['verified', 'expiring'], true)) ?></h2>
                <p class="text-muted mb-0"><?= e($stateCard[3]) ?></p>
                <?php if (in_array($state, ['verified', 'expiring'], true)): ?>
                    <p class="small text-muted mt-2 mb-0">Verified on <?= date('M d, Y', strtotime($organizer['badge_verified_at'])) ?> · <?= e($organizer['organization_name']) ?><?= verifiedBadge(true, true) ?></p>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($organizer['status'] !== 'approved'): ?>
            <?= evoraAlert('info', 'The Verified badge is available after your organizer account is approved by an administrator.') ?>
        <?php endif; ?>

        <?php if ($application && in_array($appStatus, ['pending', 'needs_resubmission'], true)): ?>
        <section class="card-evora p-4 mb-3" aria-labelledby="submittedHeading">
            <h2 class="h6 fw-semibold mb-3" id="submittedHeading">Submitted documents <span class="text-muted small fw-normal">· sent <?= date('M d, Y g:i A', strtotime($application['submitted_at'])) ?></span></h2>
            <?php if ($appStatus === 'needs_resubmission' && $application['remarks']): ?>
                <?= evoraAlert('action', '<b>Administrator note:</b> ' . e($application['remarks'])) ?>
            <?php endif; ?>
            <ul class="list-group list-group-flush">
                <?php foreach ($documents as $d): [$label, $class] = $docStatus[$d['status']] ?? [$d['status'], 'bg-secondary']; ?>
                <li class="list-group-item px-0 d-flex flex-wrap justify-content-between gap-2">
                    <div class="min-w-0">
                        <div class="fw-semibold"><?= e(OrganizerBadgeService::DOCS[$d['doc_type']][0]) ?></div>
                        <div class="small text-muted"><?= e($d['document_kind'] ?: '') ?><?= $d['expires_on'] ? ' · valid until ' . date('M d, Y', strtotime($d['expires_on'])) : '' ?></div>
                        <?php if ($d['status'] === 'rejected'): ?><div class="small text-danger mt-1"><i class="bi bi-x-circle" aria-hidden="true"></i> <?= e($d['rejection_reason']) ?></div><?php endif; ?>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <a href="<?= uploadUrl($d['file_path']) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye" aria-hidden="true"></i> View</a>
                        <span class="badge <?= $class ?>"><?= e($label) ?></span>
                    </div>
                </li>
                <?php endforeach; ?>
            </ul>
        </section>
        <?php endif; ?>

        <?php if ($appStatus === 'needs_resubmission'): $rejectedDocs = array_filter($documents, fn ($d) => $d['status'] === 'rejected'); ?>
        <section class="card-evora p-4 mb-3" aria-labelledby="resubmitHeading">
            <h2 class="h6 fw-semibold mb-3" id="resubmitHeading">Upload replacements</h2>
            <form method="post" action="<?= url('organizer-verified-badge-save') ?>" enctype="multipart/form-data">
                <?= Csrf::field() ?>
                <input type="hidden" name="mode" value="resubmit">
                <?php foreach ($rejectedDocs as $d) { $type = $d['doc_type']; include __DIR__ . '/partials/badge_document_fields.php'; } ?>
                <button type="submit" class="btn btn-evora-primary"><i class="bi bi-upload" aria-hidden="true"></i> Send new documents</button>
            </form>
        </section>
        <?php endif; ?>

        <?php if ($canApply): ?>
        <section class="card-evora p-4 mb-3" aria-labelledby="applyHeading">
            <h2 class="h6 fw-semibold mb-1" id="applyHeading"><?= in_array($state, ['expiring', 'expired', 'revoked'], true) ? 'Renew the Verified badge' : 'Apply for the Verified badge' ?></h2>
            <p class="small text-muted mb-3">Files: JPG, PNG or PDF, up to 5 MB each. Your documents are visible only to you and EVORA administrators.</p>
            <form method="post" action="<?= url('organizer-verified-badge-save') ?>" enctype="multipart/form-data">
                <?= Csrf::field() ?>
                <input type="hidden" name="mode" value="apply">
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label" for="orgType">Organization type <span class="text-danger">*</span></label>
                        <select class="form-select" id="orgType" name="organization_type" required>
                            <option value="">Choose…</option>
                            <?php foreach (OrganizerBadgeService::ORG_TYPES as $key => [$label]): ?>
                                <option value="<?= e($key) ?>" <?= old('organization_type') === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="socialLink">Official page or website <span class="text-muted small">(optional)</span></label>
                        <input type="url" class="form-control" id="socialLink" name="social_link" maxlength="255" placeholder="https://facebook.com/your-organization" value="<?= e(old('social_link')) ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="pastEvents">Events you organized before <span class="text-muted small">(optional)</span></label>
                        <textarea class="form-control" id="pastEvents" name="past_events_note" rows="2" maxlength="1000" placeholder="e.g. Cantilan Fun Run 2025 (300 runners), Barangay Sports Fest 2024"><?= e(old('past_events_note')) ?></textarea>
                    </div>
                </div>
                <?php foreach (array_keys(OrganizerBadgeService::DOCS) as $type) { include __DIR__ . '/partials/badge_document_fields.php'; } ?>
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" id="badgeConfirm" required>
                    <label class="form-check-label small" for="badgeConfirm">I confirm these documents are genuine and belong to me or my organization. False documents lead to rejection and suspension of the account.</label>
                </div>
                <button type="submit" class="btn btn-evora-primary"><i class="bi bi-send-check" aria-hidden="true"></i> Submit for verification</button>
            </form>
        </section>
        <?php endif; ?>
    </div>

    <div class="col-lg-5">
        <section class="card-evora p-4 mb-3" aria-labelledby="howHeading">
            <h2 class="h6 fw-semibold mb-3" id="howHeading">How verification works</h2>
            <ol class="badge-steps small mb-0">
                <li><b>Submit the requirements</b> below.</li>
                <li><b>Automatic checks:</b> file type and size, documents not expired, and the same file not used by another organizer.</li>
                <li><b>An administrator validates each document</b> with a checklist: readable, name matches, valid date, genuine. Rejected documents can be replaced.</li>
                <li><b>Badge granted</b> for <?= OrganizerBadgeService::VALID_MONTHS ?> months (or until a document expires). You are reminded <?= OrganizerBadgeService::REMIND_DAYS ?> days before it expires.</li>
            </ol>
        </section>
        <section class="card-evora p-4 mb-3" aria-labelledby="reqHeading">
            <h2 class="h6 fw-semibold mb-3" id="reqHeading">Requirements</h2>
            <ul class="list-unstyled small mb-3">
                <?php foreach (OrganizerBadgeService::DOCS as [$label, $required, $help]): ?>
                    <li class="mb-2"><i class="bi <?= $required ? 'bi-check2-square text-primary' : 'bi-square text-muted' ?>" aria-hidden="true"></i>
                        <b><?= e($label) ?></b><?= $required ? '' : ' <span class="text-muted">(optional)</span>' ?><br><span class="text-muted"><?= e($help) ?></span></li>
                <?php endforeach; ?>
            </ul>
            <h3 class="small fw-semibold mb-2">Accepted proof of organization</h3>
            <table class="table table-sm small mb-0" data-no-stack>
                <tbody>
                <?php foreach (OrganizerBadgeService::ORG_TYPES as [$label, $proof]): ?>
                    <tr><td class="fw-semibold"><?= e($label) ?></td><td><?= e($proof) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </section>
        <section class="card-evora p-4" aria-labelledby="whyHeading">
            <h2 class="h6 fw-semibold mb-2" id="whyHeading">Why get verified?</h2>
            <p class="small text-muted mb-0">Participants see the <?= verifiedBadge(true, true) ?> badge beside your organization name on the home page, in Browse Events and on the event page, and can filter for verified organizers only.</p>
        </section>
    </div>
</div>
<?php clearOld(); ?>
<?php include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php'; ?>
