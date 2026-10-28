<?php
Auth::requireRole('organizer');
$db = Database::connection();
$userId = Auth::id();

$orgStmt = $db->prepare('SELECT id FROM organizers WHERE user_id = :uid');
$orgStmt->execute(['uid' => $userId]);
$organizer = $orgStmt->fetch();

$eventId = (int) ($_GET['event_id'] ?? 0);
$eventStmt = $db->prepare(
    'SELECT e.*, c.slug AS category_slug, c.name AS category_name FROM events e
     JOIN event_categories c ON c.id = e.category_id
     WHERE e.id = :id AND e.organizer_id = :oid'
);
$eventStmt->execute(['id' => $eventId, 'oid' => $organizer['id'] ?? 0]);
$event = $eventStmt->fetch();

if (!$event) {
    flash('error', 'Event not found.');
    redirect('organizer-events');
}

$isFunRun = $event['category_slug'] === 'fun-run';
$isSeminar = $event['category_slug'] === 'seminar-workshop';

// Named $certSettings, not $settings — layout_top.php's sidebar sets its own
// $settings = systemSettings() in this same included scope, which would
// otherwise silently clobber this variable after the include below.
$certSettingsStmt = $db->prepare('SELECT * FROM event_certificate_settings WHERE event_id = :eid');
$certSettingsStmt->execute(['eid' => $eventId]);
$certSettings = $certSettingsStmt->fetch() ?: [];

$stmt = $db->prepare(
    'SELECT r.id AS registration_id, u.full_name, u.email,
        (SELECT COUNT(*) FROM attendance a WHERE a.registration_id = r.id) AS attended,
        (SELECT COUNT(*) FROM race_results rr WHERE rr.registration_id = r.id) AS finished,
        c.id AS certificate_id, c.certificate_path, c.certificate_type, c.issued_at, c.status AS certificate_status
     FROM registrations r
     JOIN participants p ON p.id = r.participant_id
     JOIN users u ON u.id = p.user_id
     LEFT JOIN certificates c ON c.registration_id = r.id
     WHERE r.event_id = :eid AND r.status = "approved"
     ORDER BY u.full_name'
);
$stmt->execute(['eid' => $eventId]);
$participants = $stmt->fetchAll();

$notYetIssuedCount = count(array_filter($participants, fn ($p) => !$p['certificate_id']));

$pageTitle = 'Certificate Management';
$activeRoute = 'organizer-events';
include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
?>
<?php renderBreadcrumb([
    ['label' => 'My Events', 'route' => 'organizer-events'],
    ['label' => $event['name'], 'route' => 'organizer-event-manage', 'params' => ['id' => $eventId]],
    ['label' => 'Certificate Management'],
]); ?>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card-evora p-4 mb-3">
            <h6 class="fw-semibold mb-3">Certificate Settings — <?= e($event['category_name']) ?></h6>
            <form method="post" action="<?= url('organizer-certificate-settings-save') ?>" enctype="multipart/form-data">
                <?= Csrf::field() ?>
                <input type="hidden" name="event_id" value="<?= $eventId ?>">
                <div class="mb-2">
                    <label class="form-label small">Certificate Title</label>
                    <input type="text" name="certificate_title" class="form-control form-control-sm" placeholder="e.g. Certificate of Participation" value="<?= e($certSettings['certificate_title'] ?? '') ?>">
                    <div class="form-text">Used when generating certificates in bulk. Leave blank to use the default for this event type.</div>
                </div>
                <div class="mb-2">
                    <label class="form-label small">Description (optional)</label>
                    <textarea name="description" class="form-control form-control-sm" rows="2" placeholder="Optional extra line shown on the certificate"><?= e($certSettings['description'] ?? '') ?></textarea>
                </div>
                <div class="row g-2">
                    <div class="col-md-6 mb-2">
                        <label class="form-label small">Venue (override)</label>
                        <input type="text" name="venue_override" class="form-control form-control-sm" placeholder="<?= e($event['venue']) ?>" value="<?= e($certSettings['venue_override'] ?? '') ?>">
                    </div>
                    <div class="col-md-6 mb-2">
                        <label class="form-label small">Organization Name (override)</label>
                        <input type="text" name="organization_name_override" class="form-control form-control-sm" value="<?= e($certSettings['organization_name_override'] ?? '') ?>">
                    </div>
                </div>
                <div class="mb-2">
                    <label class="form-label small">Organizer Name (override)</label>
                    <input type="text" name="organizer_name_override" class="form-control form-control-sm" value="<?= e($certSettings['organizer_name_override'] ?? '') ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label small">Logo</label>
                    <input type="file" name="logo" class="form-control form-control-sm" accept=".jpg,.jpeg,.png">
                    <?php if (!empty($certSettings['logo_path'])): ?>
                        <div class="form-text">Current: <a href="<?= uploadUrl($certSettings['logo_path']) ?>" target="_blank">view uploaded logo</a>. Leave blank to keep it.</div>
                    <?php else: ?>
                        <div class="form-text">Falls back to the system logo if not set.</div>
                    <?php endif; ?>
                </div>

                <h6 class="fw-semibold small text-uppercase text-muted mt-3 mb-2">Signatories</h6>
                <?php foreach ([1, 2] as $n): ?>
                <div class="row g-2 mb-2 pb-2 border-bottom">
                    <div class="col-md-6">
                        <label class="form-label small">Signatory <?= $n ?> Name</label>
                        <input type="text" name="signatory<?= $n ?>_name" class="form-control form-control-sm" value="<?= e($certSettings['signatory' . $n . '_name'] ?? '') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small">Position / Title</label>
                        <input type="text" name="signatory<?= $n ?>_title" class="form-control form-control-sm" placeholder="e.g. Event Director" value="<?= e($certSettings['signatory' . $n . '_title'] ?? '') ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label small">Signature Image</label>
                        <input type="file" name="signatory<?= $n ?>_signature" class="form-control form-control-sm" accept=".jpg,.jpeg,.png">
                        <?php if (!empty($certSettings['signatory' . $n . '_signature_path'])): ?>
                            <div class="form-text">Current: <a href="<?= uploadUrl($certSettings['signatory' . $n . '_signature_path']) ?>" target="_blank">view uploaded signature</a>. Leave blank to keep it.</div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>

                <h6 class="fw-semibold small text-uppercase text-muted mt-3 mb-2">Certificate Issuance Mode</h6>
                <div class="form-check mb-1">
                    <input type="checkbox" name="auto_issue_enabled" id="auto_issue_enabled" class="form-check-input" value="1" <?= !empty($certSettings['auto_issue_enabled']) ? 'checked' : '' ?>>
                    <label for="auto_issue_enabled" class="form-check-label small">Enable Automatic Certificate Issuance (when this event is marked Completed)</label>
                </div>
                <div class="form-check mb-2">
                    <input type="checkbox" name="attendance_required" id="attendance_required" class="form-check-input" value="1" <?= !empty($certSettings['attendance_required']) ? 'checked' : '' ?>>
                    <label for="attendance_required" class="form-check-label small">Require Attendance Before Issuing Certificate</label>
                </div>
                <div class="form-text mb-2">Default is manual — both boxes start unchecked. Approved registration and payment (if the event has a fee) are always required either way.</div>

                <button type="submit" class="btn btn-evora-primary btn-sm w-100 mt-2">Save Certificate Settings</button>
            </form>
        </div>

        <div class="card-evora p-4">
            <h6 class="fw-semibold mb-2">Generate Certificates</h6>
            <p class="text-muted small mb-3">
                <?= $notYetIssuedCount ?> approved participant(s) don't have a certificate yet.
                Attendance is shown below for reference only — it does not block generation.
            </p>
            <form method="post" action="<?= url('organizer-certificate-generate') ?>" data-confirm="Generate certificates for all <?= $notYetIssuedCount ?> participant(s) who don't have one yet?">
                <?= Csrf::field() ?>
                <input type="hidden" name="event_id" value="<?= $eventId ?>">
                <button type="submit" class="btn btn-evora-primary w-100" <?= $notYetIssuedCount === 0 ? 'disabled' : '' ?>>
                    <i class="bi bi-magic"></i> Generate Certificates for All (<?= $notYetIssuedCount ?>)
                </button>
            </form>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card-evora p-3 mb-3">
            <h6 class="fw-semibold mb-2">Live Preview</h6>
            <p class="text-muted small mb-2">Reflects your saved settings and this event's category theme. Updates after you save.</p>
            <div class="frame-bordered">
                <iframe src="<?= url('organizer-certificate-preview', ['event_id' => $eventId]) ?>" style="width:100%;height:360px;border:0;"></iframe>
            </div>
        </div>
    </div>
</div>

<div class="card-evora p-4 mt-3">
    <h6 class="fw-semibold mb-3">Participants &amp; Individual Issuance</h6>
    <?php if (empty($participants)): ?>
        <?= emptyState('bi-award', 'No approved participants yet.') ?>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table align-middle table-datatable">
            <thead><tr><th>Participant</th><th>Email</th><th>Attended</th><?php if ($isFunRun): ?><th>Finished</th><?php endif; ?><th>Certificate</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($participants as $p): ?>
                <tr>
                    <td><?= e($p['full_name']) ?></td>
                    <td><?= e($p['email']) ?></td>
                    <td><?= $p['attended'] > 0 ? '<span class="text-success">Yes</span>' : '<span class="text-muted">No</span>' ?></td>
                    <?php if ($isFunRun): ?>
                        <td><?= $p['finished'] > 0 ? '<span class="text-success">Yes</span>' : '<span class="text-muted">No</span>' ?></td>
                    <?php endif; ?>
                    <td>
                        <?php if ($p['certificate_id']): ?>
                            <span class="badge badge-status-approved">Issued</span>
                            <?php if (($p['certificate_status'] ?? 'issued') === 'revoked'): ?>
                                <span class="badge bg-secondary">Revoked</span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="text-muted">Not issued</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!$p['certificate_id']): ?>
                        <form method="post" action="<?= url('organizer-certificate-issue') ?>" class="d-flex flex-wrap gap-1">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="registration_id" value="<?= $p['registration_id'] ?>">
                            <input type="hidden" name="event_id" value="<?= $eventId ?>">
                            <?php if ($isFunRun): ?>
                                <select name="cert_type" class="form-select form-select-sm" style="width:auto;">
                                    <option value="participation">Participation</option>
                                    <option value="finisher" <?= $p['finished'] > 0 ? 'selected' : '' ?>>Finisher</option>
                                    <option value="winner">Winner</option>
                                </select>
                            <?php elseif ($isSeminar): ?>
                                <select name="cert_type" class="form-select form-select-sm" style="width:auto;">
                                    <option value="participation">Participation</option>
                                    <option value="attendance" <?= $p['attended'] > 0 ? 'selected' : '' ?>>Attendance</option>
                                    <option value="completion">Completion</option>
                                </select>
                            <?php endif; ?>
                            <button type="submit" class="btn btn-sm btn-outline-primary text-nowrap">Issue Certificate</button>
                        </form>
                        <?php else: ?>
                            <a href="<?= uploadUrl($p['certificate_path']) ?>" target="_blank" class="btn btn-sm btn-outline-secondary">View</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
<?php include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php'; ?>
