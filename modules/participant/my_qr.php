<?php
Auth::requireRole('participant');
$db = Database::connection();
$userId = Auth::id();

$stmt = $db->prepare(
    'SELECT q.*, e.name AS event_name, e.event_date, e.venue
     FROM qr_codes q
     JOIN registrations r ON r.id = q.registration_id
     JOIN participants p ON p.id = r.participant_id
     JOIN events e ON e.id = r.event_id
     WHERE p.user_id = :uid AND q.invalidated_at IS NULL
     ORDER BY q.generated_at DESC'
);
$stmt->execute(['uid' => $userId]);
$qrCodes = $stmt->fetchAll();

$pageDescription = 'Show these QR codes at the event entrance to check in.';
$pageTitle = 'QR Codes';
$activeRoute = 'my-qr';
include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
?>
<?php if (count($qrCodes) > 1): ?>
<div class="qr-search mb-3">
    <div class="input-group">
        <span class="input-group-text"><i class="bi bi-search" aria-hidden="true"></i></span>
        <input type="search" class="form-control" placeholder="Search by event, venue or date" aria-label="Search your QR codes" autocomplete="off"
               data-filter-input="#qrList" data-filter-empty="#qrNoMatch" data-filter-count="#qrCount">
    </div>
    <div class="small text-muted mt-1" id="qrCount" aria-live="polite"><?= count($qrCodes) ?> QR codes</div>
</div>
<?php endif; ?>
<div class="card-evora mb-3 d-none" id="qrNoMatch"><?= emptyState('bi-search', 'No QR code matches your search. Try another event name, venue or date.') ?></div>
<div class="row g-3" id="qrList">
    <?php if (empty($qrCodes)): ?>
        <div class="col-12"><div class="card-evora"><?= emptyState('bi-qr-code', 'No QR codes yet. A QR code is issued once your registration (and payment, if needed) is approved.', 'View My Registrations', url('my-registrations')) ?></div></div>
    <?php endif; ?>
    <?php foreach ($qrCodes as $qr): ?>
        <?php $qrDate = strtotime($qr['event_date']); ?>
        <div class="col-md-4" data-filter-item="<?= e(mb_strtolower($qr['event_name'] . ' ' . $qr['venue'] . ' ' . date('D M d Y F j n/j/Y Y-m-d', $qrDate))) ?>">
            <div class="card-evora p-4 qr-ticket h-100">
                <h6 class="fw-semibold mb-1"><?= e($qr['event_name']) ?></h6>
                <p class="small text-muted mb-3"><i class="bi bi-calendar-event me-1" aria-hidden="true"></i><?= date('D, M d, Y', strtotime($qr['event_date'])) ?> &bull; <?= e($qr['venue']) ?></p>
                <button type="button" class="qr-frame" data-qr-fullscreen="<?= e(uploadUrl($qr['qr_image_path'])) ?>" data-qr-title="<?= e($qr['event_name']) ?>" aria-label="Show the QR code for <?= e($qr['event_name']) ?> full screen">
                    <img src="<?= uploadUrl($qr['qr_image_path']) ?>" alt="QR code for <?= e($qr['event_name']) ?>">
                </button>
                <p class="qr-tip"><i class="bi bi-brightness-high me-1" aria-hidden="true"></i>Tap the code to show it full screen. Turn your brightness up at the entrance.</p>
                <button type="button" class="btn btn-outline-primary btn-sm w-100" data-qr-fullscreen="<?= e(uploadUrl($qr['qr_image_path'])) ?>" data-qr-title="<?= e($qr['event_name']) ?>"><i class="bi bi-arrows-fullscreen me-1" aria-hidden="true"></i>Show Full Screen</button>
                <a href="<?= uploadUrl($qr['qr_image_path']) ?>" download="EVORA-QR-<?= (int) $qr['registration_id'] ?>.png" class="btn btn-outline-secondary btn-sm w-100 mt-2"><i class="bi bi-download me-1" aria-hidden="true"></i>Save QR Image</a>
                <a href="<?= url('my-ticket', ['registration_id' => $qr['registration_id']]) ?>" class="btn btn-evora-primary btn-sm w-100 mt-2"><i class="bi bi-ticket-perforated me-1" aria-hidden="true"></i>Download E-ticket (PDF)</a>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php'; ?>
