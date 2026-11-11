<?php
// Admin: validate a Verified badge application document by document, then decide (v78).
Auth::requireRole('admin');
$db = Database::connection();
$id = (int) ($_GET['id'] ?? 0);
$stmt = $db->prepare('SELECT a.*, o.organization_name, o.contact_number, o.organization_info, o.status AS organizer_status, o.user_id, o.id AS organizer_id,
                             o.badge_verified_at, o.badge_expires_on, o.badge_application_id, o.badge_revoked_at, o.badge_revoke_reason, o.reviewed_at AS organizer_reviewed_at, o.created_at,
                             u.full_name, u.email, u.phone, rv.full_name AS reviewer_name
                      FROM organizer_badge_applications a
                      JOIN organizers o ON o.id = a.organizer_id JOIN users u ON u.id = o.user_id
                      LEFT JOIN users rv ON rv.id = a.reviewed_by WHERE a.id = :id');
$stmt->execute(['id' => $id]);
$app = $stmt->fetch();
if (!$app) {
    flash('error', 'Badge application not found.');
    redirect('admin-badges');
}
$organizer = ['id' => $app['organizer_id'], 'user_id' => $app['user_id'], 'status' => $app['organizer_status'], 'reviewed_at' => $app['organizer_reviewed_at'], 'created_at' => $app['created_at'],
    'organization_name' => $app['organization_name'], 'badge_verified_at' => $app['badge_verified_at'], 'badge_expires_on' => $app['badge_expires_on'], 'badge_revoked_at' => $app['badge_revoked_at']];
$documents = OrganizerBadgeService::documents($db, $id);
$trust = OrganizerBadgeService::trustSummary($db, $organizer);
$badgeState = OrganizerBadgeService::badgeState($organizer);
$isPending = $app['status'] === 'pending';
$reviewed = count(array_filter($documents, fn ($d) => $d['status'] !== 'pending'));
$history = $db->prepare('SELECT id, status, submitted_at, reviewed_at FROM organizer_badge_applications WHERE organizer_id = :o AND id <> :id ORDER BY id DESC');
$history->execute(['o' => $app['organizer_id'], 'id' => $id]);
$history = $history->fetchAll();

$statusText = ['pending' => ['Waiting for review', 'bg-warning text-dark'], 'needs_resubmission' => ['Resubmission requested', 'bg-info text-dark'], 'approved' => ['Approved', 'bg-success'], 'rejected' => ['Rejected', 'bg-danger']];
$docStatus = ['pending' => ['Not reviewed', 'bg-secondary'], 'accepted' => ['Accepted', 'bg-success'], 'rejected' => ['Rejected', 'bg-danger']];
$pageTitle = 'Validate Badge Application';
$pageDescription = 'Check every document against the checklist, then approve the badge, ask for replacements or reject.';
$activeRoute = 'admin-badges';
include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
?>
<?php renderBreadcrumb([['label' => 'Verified Badges', 'route' => 'admin-badges'], ['label' => $app['organization_name']]]); ?>
<div class="row g-3">
    <div class="col-xl-8">
        <section class="card-evora p-4 mb-3" aria-labelledby="appHeading">
            <div class="d-flex flex-wrap justify-content-between gap-2 mb-3">
                <h2 class="h6 fw-semibold mb-0" id="appHeading"><?= e($app['organization_name']) ?><?= verifiedBadge(in_array($badgeState, ['verified', 'expiring'], true)) ?></h2>
                <span class="badge <?= $statusText[$app['status']][1] ?>"><?= e($statusText[$app['status']][0]) ?></span>
            </div>
            <dl class="row small mb-0">
                <dt class="col-sm-4">Organization type</dt><dd class="col-sm-8"><?= e(OrganizerBadgeService::ORG_TYPES[$app['organization_type']][0] ?? $app['organization_type']) ?> <span class="text-muted">· expected proof: <?= e(OrganizerBadgeService::ORG_TYPES[$app['organization_type']][1] ?? '') ?></span></dd>
                <dt class="col-sm-4">Contact person (account name)</dt><dd class="col-sm-8"><?= e($app['full_name']) ?> · <?= e($app['email']) ?> · <?= e($app['contact_number']) ?></dd>
                <dt class="col-sm-4">Official page / website</dt><dd class="col-sm-8 text-break"><?= $app['social_link'] ? '<a href="' . e($app['social_link']) . '" target="_blank" rel="noopener noreferrer">' . e($app['social_link']) . '</a>' : '—' ?></dd>
                <dt class="col-sm-4">Past events (as stated)</dt><dd class="col-sm-8"><?= $app['past_events_note'] ? nl2br(e($app['past_events_note'])) : '—' ?></dd>
                <dt class="col-sm-4">Submitted</dt><dd class="col-sm-8"><?= date('M d, Y g:i A', strtotime($app['submitted_at'])) ?></dd>
                <?php if ($app['reviewed_at']): ?>
                <dt class="col-sm-4">Last decision</dt><dd class="col-sm-8"><?= date('M d, Y g:i A', strtotime($app['reviewed_at'])) ?> by <?= e($app['reviewer_name'] ?? '—') ?><?= $app['remarks'] ? ' · ' . e($app['remarks']) : '' ?></dd>
                <?php endif; ?>
            </dl>
        </section>

        <h2 class="h6 fw-semibold mb-2">Documents <span class="text-muted fw-normal small">· <?= $reviewed ?> of <?= count($documents) ?> validated</span></h2>
        <?php foreach ($documents as $d): [$dLabel, $dClass] = $docStatus[$d['status']]; $isImage = preg_match('/\.(jpe?g|png)$/i', $d['file_path']); ?>
        <section class="card-evora p-4 mb-3 badge-doc-card" aria-label="<?= e(OrganizerBadgeService::DOCS[$d['doc_type']][0]) ?>">
            <div class="d-flex flex-wrap justify-content-between gap-2 mb-2">
                <h3 class="h6 fw-semibold mb-0"><?= e(OrganizerBadgeService::DOCS[$d['doc_type']][0]) ?></h3>
                <span class="badge <?= $dClass ?>"><?= e($dLabel) ?></span>
            </div>
            <div class="row g-3">
                <div class="col-md-5">
                    <a href="<?= uploadUrl($d['file_path']) ?>" target="_blank" rel="noopener" class="badge-doc-preview" title="Open the full file">
                        <?php if ($isImage): ?><img src="<?= uploadUrl($d['file_path']) ?>" alt="<?= e(OrganizerBadgeService::DOCS[$d['doc_type']][0]) ?>">
                        <?php else: ?><span><i class="bi bi-file-earmark-pdf" aria-hidden="true"></i> Open PDF</span><?php endif; ?>
                    </a>
                </div>
                <div class="col-md-7 min-w-0">
                    <dl class="row small mb-2">
                        <?php if ($d['document_kind']): ?><dt class="col-5">Document type</dt><dd class="col-7"><?= e($d['document_kind']) ?></dd><?php endif; ?>
                        <?php if ($d['reference_no']): ?><dt class="col-5"><?= $d['doc_type'] === 'government_id' ? 'ID no. (last 4)' : 'Reg./permit no.' ?></dt><dd class="col-7"><?= e($d['reference_no']) ?></dd><?php endif; ?>
                        <dt class="col-5">Valid until</dt><dd class="col-7"><?= $d['expires_on'] ? date('M d, Y', strtotime($d['expires_on'])) : 'No expiry stated' ?></dd>
                        <dt class="col-5">Uploaded</dt><dd class="col-7"><?= date('M d, Y g:i A', strtotime($d['uploaded_at'])) ?></dd>
                    </dl>
                    <div class="small mb-2">
                        <span class="text-muted">Automatic checks:</span>
                        <?php if ($d['auto_flags']): foreach (explode(',', $d['auto_flags']) as $flag): ?>
                            <span class="badge bg-danger-subtle text-danger-emphasis"><i class="bi bi-flag-fill" aria-hidden="true"></i> <?= e(OrganizerBadgeService::FLAG_LABELS[$flag] ?? $flag) ?></span>
                        <?php endforeach; else: ?>
                            <span class="badge bg-success-subtle text-success-emphasis"><i class="bi bi-check2" aria-hidden="true"></i> File type, size and date OK; not used by another organizer</span>
                        <?php endif; ?>
                    </div>
                    <?php if ($isPending): ?>
                    <form method="post" action="<?= url('admin-badge-action') ?>">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="action" value="review_document">
                        <input type="hidden" name="application_id" value="<?= $id ?>">
                        <input type="hidden" name="document_id" value="<?= (int) $d['id'] ?>">
                        <fieldset class="mb-2"><legend class="small fw-semibold mb-1">Checklist</legend>
                        <?php foreach (OrganizerBadgeService::CHECKS as $key => $checkLabel): ?>
                            <div class="form-check small"><input class="form-check-input" type="checkbox" name="checks[<?= $key ?>]" value="1" id="c<?= (int) $d['id'] . $key ?>" <?= $d[$key] ? 'checked' : '' ?>><label class="form-check-label" for="c<?= (int) $d['id'] . $key ?>"><?= e($checkLabel) ?></label></div>
                        <?php endforeach; ?>
                        </fieldset>
                        <input type="text" name="reason" class="form-control form-control-sm mb-2" maxlength="255" placeholder="Reason if rejected, e.g. ID photo is blurred" value="<?= e((string) $d['rejection_reason']) ?>" aria-label="Rejection reason">
                        <div class="d-flex flex-wrap gap-2">
                            <button type="submit" name="decision" value="accepted" class="btn btn-sm btn-success"><i class="bi bi-check-circle" aria-hidden="true"></i> Accept document</button>
                            <button type="submit" name="decision" value="rejected" class="btn btn-sm btn-outline-danger"><i class="bi bi-x-circle" aria-hidden="true"></i> Reject document</button>
                        </div>
                    </form>
                    <?php elseif ($d['status'] !== 'pending'): ?>
                        <ul class="list-unstyled small mb-0">
                            <?php foreach (OrganizerBadgeService::CHECKS as $key => $checkLabel): ?>
                                <li><i class="bi <?= $d[$key] ? 'bi-check-square-fill text-success' : 'bi-x-square text-danger' ?>" aria-hidden="true"></i> <?= e($checkLabel) ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <?php if ($d['rejection_reason']): ?><p class="small text-danger mt-1 mb-0">Reason: <?= e($d['rejection_reason']) ?></p><?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </section>
        <?php endforeach; ?>
    </div>

    <div class="col-xl-4">
        <section class="card-evora p-4 mb-3" aria-labelledby="trustHeading">
            <h2 class="h6 fw-semibold mb-1" id="trustHeading">Trust summary</h2>
            <p class="small text-muted mb-3">What EVORA already knows about this organizer.</p>
            <ul class="list-unstyled small mb-0 trust-list">
                <li><span>Account created</span><b><?= date('M d, Y', strtotime($trust['account_since'])) ?></b></li>
                <li><span>Email verified</span><b><?= $trust['email_verified'] ? 'Yes' : 'No' ?></b></li>
                <li><span>Organizer approved</span><b><?= $trust['organizer_approved_at'] ? date('M d, Y', strtotime($trust['organizer_approved_at'])) : ucfirst(e($app['organizer_status'])) ?></b></li>
                <li><span>Completed events</span><b><?= $trust['events_completed'] ?></b></li>
                <li><span>Participants served</span><b><?= $trust['participants_served'] ?></b></li>
                <li><span>Average event rating</span><b><?= $trust['average_rating'] !== null ? $trust['average_rating'] . ' / 5' : 'No ratings yet' ?></b></li>
                <li><span>Refunds still owed</span><b class="<?= $trust['refunds_outstanding'] ? 'text-danger' : '' ?>"><?= $trust['refunds_outstanding'] ?></b></li>
                <li><span>Cancelled or removed events</span><b><?= $trust['cancelled_events'] ?></b></li>
                <li><span>Badges granted before</span><b><?= $trust['previous_badges'] ?></b></li>
            </ul>
        </section>

        <?php if ($isPending): ?>
        <section class="card-evora p-4 mb-3" aria-labelledby="decisionHeading">
            <h2 class="h6 fw-semibold mb-2" id="decisionHeading">Decision</h2>
            <p class="small text-muted">Approve only when every required document is accepted. The badge lasts <?= OrganizerBadgeService::VALID_MONTHS ?> months, or until the earliest document expiry date.</p>
            <form method="post" action="<?= url('admin-badge-action') ?>">
                <?= Csrf::field() ?>
                <input type="hidden" name="application_id" value="<?= $id ?>">
                <label class="form-label small" for="decisionRemarks">Remarks to the organizer</label>
                <textarea name="remarks" id="decisionRemarks" class="form-control mb-3" rows="3" maxlength="500" placeholder="Required when rejecting"></textarea>
                <div class="d-grid gap-2">
                    <button type="submit" name="action" value="approve" class="btn btn-success"><i class="bi bi-patch-check-fill" aria-hidden="true"></i> Approve Verified badge</button>
                    <button type="submit" name="action" value="request_resubmission" class="btn btn-outline-primary"><i class="bi bi-arrow-repeat" aria-hidden="true"></i> Ask to replace rejected documents</button>
                    <button type="submit" name="action" value="reject" class="btn btn-outline-danger" data-confirm-click="The organizer is notified with your remarks and can apply again later." data-confirm-title="Reject this badge application?" data-confirm-button="Reject Application" data-confirm-variant="danger"><i class="bi bi-x-circle" aria-hidden="true"></i> Reject application</button>
                </div>
            </form>
        </section>
        <?php endif; ?>

        <?php if (in_array($badgeState, ['verified', 'expiring'], true)): ?>
        <section class="card-evora p-4 mb-3" aria-labelledby="activeHeading">
            <h2 class="h6 fw-semibold mb-2" id="activeHeading">Active badge</h2>
            <p class="small mb-3">Verified since <?= date('M d, Y', strtotime($app['badge_verified_at'])) ?>, valid until <b><?= date('M d, Y', strtotime($app['badge_expires_on'])) ?></b>.</p>
            <form method="post" action="<?= url('admin-badge-action') ?>">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="revoke">
                <input type="hidden" name="application_id" value="<?= $id ?>">
                <input type="hidden" name="organizer_id" value="<?= (int) $app['organizer_id'] ?>">
                <input type="text" name="reason" class="form-control form-control-sm mb-2" maxlength="255" placeholder="Reason, e.g. confirmed complaints from participants" aria-label="Reason for revoking" required>
                <button type="submit" class="btn btn-sm btn-outline-danger w-100" data-confirm-click="The badge disappears from all of this organizer's events right away." data-confirm-title="Revoke the Verified badge?" data-confirm-button="Revoke Badge" data-confirm-variant="danger"><i class="bi bi-patch-minus" aria-hidden="true"></i> Revoke badge</button>
            </form>
        </section>
        <?php elseif ($badgeState === 'revoked'): ?>
            <?= evoraAlert('warning', 'Badge revoked on ' . date('M d, Y', strtotime($app['badge_revoked_at'])) . '. Reason: ' . e($app['badge_revoke_reason'])) ?>
        <?php endif; ?>

        <?php if ($history): ?>
        <section class="card-evora p-4" aria-labelledby="historyHeading">
            <h2 class="h6 fw-semibold mb-2" id="historyHeading">Earlier applications</h2>
            <ul class="list-unstyled small mb-0">
                <?php foreach ($history as $h): ?>
                    <li class="mb-1"><a href="<?= url('admin-badge-review', ['id' => $h['id']]) ?>"><?= date('M d, Y', strtotime($h['submitted_at'])) ?></a> · <?= e($statusText[$h['status']][0] ?? $h['status']) ?></li>
                <?php endforeach; ?>
            </ul>
        </section>
        <?php endif; ?>
    </div>
</div>
<?php include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php'; ?>
