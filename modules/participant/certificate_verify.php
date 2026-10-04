<?php
$db = Database::connection();
$token = $_GET['token'] ?? '';
$certId = trim($_GET['cert_id'] ?? '');

// Two ways in: the QR code embedded in every PDF links here with a token
// (unguessable, generated at issuance), or a person can type the
// human-readable Certificate ID printed on the certificate itself. Either
// one resolves to the same lookup — only certificate-related fields are
// selected, never anything else about the participant's account.
$certificate = null;
$searched = false;
if ($token !== '') {
    $searched = true;
    $stmt = $db->prepare(
        'SELECT c.certificate_number, c.certificate_type, c.issued_at,
            u.full_name AS participant_name, e.name AS event_name, e.event_date, orgu.full_name AS organizer_name
         FROM certificates c
         JOIN registrations r ON r.id = c.registration_id
         JOIN participants p ON p.id = r.participant_id
         JOIN users u ON u.id = p.user_id
         JOIN events e ON e.id = r.event_id
         JOIN organizers o ON o.id = e.organizer_id
         JOIN users orgu ON orgu.id = o.user_id
         WHERE c.verification_token = :token'
    );
    $stmt->execute(['token' => $token]);
    $certificate = $stmt->fetch();
} elseif ($certId !== '') {
    $searched = true;
    $stmt = $db->prepare(
        'SELECT c.certificate_number, c.certificate_type, c.issued_at,
            u.full_name AS participant_name, e.name AS event_name, e.event_date, orgu.full_name AS organizer_name
         FROM certificates c
         JOIN registrations r ON r.id = c.registration_id
         JOIN participants p ON p.id = r.participant_id
         JOIN users u ON u.id = p.user_id
         JOIN events e ON e.id = r.event_id
         JOIN organizers o ON o.id = e.organizer_id
         JOIN users orgu ON orgu.id = o.user_id
         WHERE c.certificate_number = :cert_id'
    );
    $stmt->execute(['cert_id' => $certId]);
    $certificate = $stmt->fetch();
}

$pageTitle = 'Certificate Verification';
include EVORA_ROOT . '/modules/shared/partials/public_head.php';
?>
<div class="row justify-content-center">
    <div class="col-md-7">
        <div class="card-evora p-4 mb-3">
            <h6 class="fw-semibold mb-3">Verify a Certificate</h6>
            <form method="get" action="<?= url('certificate-verify') ?>" class="d-flex gap-2">
                <input type="hidden" name="route" value="certificate-verify">
                <input type="text" name="cert_id" class="form-control" placeholder="Enter Certificate ID (e.g. EVORA-2026-000123)" value="<?= e($certId) ?>" required>
                <button type="submit" class="btn btn-evora-primary text-nowrap"><i class="bi bi-search"></i> Verify</button>
            </form>
        </div>

        <?php if ($searched): ?>
        <div class="card-evora p-4 text-center">
            <?php if ($certificate): ?>
                <i class="bi bi-patch-check-fill text-success icon-xl" aria-hidden="true"></i>
                <h5 class="fw-bold mt-2">Valid Certificate</h5>
                <p class="text-muted mb-4">This certificate was issued by EVORA and is authentic.</p>
                <dl class="row text-start small mb-0">
                    <dt class="col-sm-4">Certificate ID</dt><dd class="col-sm-8"><?= e($certificate['certificate_number']) ?></dd>
                    <dt class="col-sm-4">Participant</dt><dd class="col-sm-8"><?= e($certificate['participant_name']) ?></dd>
                    <dt class="col-sm-4">Event</dt><dd class="col-sm-8"><?= e($certificate['event_name']) ?></dd>
                    <dt class="col-sm-4">Certificate Type</dt><dd class="col-sm-8"><?= e($certificate['certificate_type']) ?></dd>
                    <dt class="col-sm-4">Event Date</dt><dd class="col-sm-8"><?= date('F d, Y', strtotime($certificate['event_date'])) ?></dd>
                    <dt class="col-sm-4">Organizer</dt><dd class="col-sm-8"><?= e($certificate['organizer_name']) ?></dd>
                    <dt class="col-sm-4">Date Issued</dt><dd class="col-sm-8"><?= date('F d, Y', strtotime($certificate['issued_at'])) ?></dd>
                </dl>
            <?php else: ?>
                <i class="bi bi-x-octagon-fill text-danger icon-xl" aria-hidden="true"></i>
                <h5 class="fw-bold mt-2">Certificate Not Found</h5>
                <p class="text-muted mb-0">This certificate ID does not match any certificate on file.</p>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php include EVORA_ROOT . '/modules/shared/partials/public_foot.php'; ?>
