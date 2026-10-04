<?php
Auth::requireRole('staff');
$db = Database::connection();
$userId = Auth::id();

$assignmentsStmt = $db->prepare(
    'SELECT es.id AS event_staff_id, e.id AS event_id, e.name AS event_name, e.event_date, e.venue
     FROM event_staff es JOIN events e ON e.id = es.event_id
     WHERE es.user_id = :uid
     AND es.id IN (SELECT event_staff_id FROM staff_permissions WHERE permission_key = "scan_qr")
     AND e.status NOT IN ("removed", "archived") -- events an admin removed are not shown to staff
     ORDER BY (e.event_date < CURDATE()), CASE WHEN e.event_date >= CURDATE() THEN e.event_date END, e.event_date DESC'
);
$assignmentsStmt->execute(['uid' => $userId]);
$assignments = $assignmentsStmt->fetchAll();

// Recent check-ins this staff member recorded (name, event, time only).
$recent = [];
if ($assignments) {
    $recentStmt = $db->prepare(
        'SELECT u.full_name, e.name AS event_name, a.scanned_at
         FROM attendance a
         JOIN registrations r ON r.id = a.registration_id
         JOIN participants p ON p.id = r.participant_id
         JOIN users u ON u.id = p.user_id
         JOIN events e ON e.id = a.event_id
         WHERE a.staff_id = :uid
         ORDER BY a.scanned_at DESC, a.id DESC
         LIMIT 10'
    );
    $recentStmt->execute(['uid' => $userId]);
    $recent = $recentStmt->fetchAll();
}

$pageDescription = 'Scan a participant’s QR code to check them in.';
$pageTitle = 'Scan Participant QR';
$activeRoute = 'staff-scan';
include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
?>
<?php if (empty($assignments)): ?>
    <div class="card-evora p-4"><?= emptyState('bi-qr-code-scan', 'You do not have QR scanning permission for any assigned event yet. Ask the organizer to give you the "Scan QR" permission.') ?></div>
<?php else: ?>
<div id="staffScanner" data-csrf="<?= e(Csrf::token()) ?>" data-verify-url="<?= e(url('staff-scan-verify')) ?>"
     data-search-url="<?= e(url('staff-checkin-search')) ?>" data-stats-url="<?= e(url('staff-checkin-stats')) ?>" data-undo-url="<?= e(url('staff-checkin-undo')) ?>" data-undo-minutes="<?= CheckInToolsService::UNDO_MINUTES ?>">
    <section class="card-evora p-4 mb-3" aria-labelledby="scanEventHeading">
        <div class="row g-2 align-items-end">
            <div class="col-md-7">
                <h2 class="h6 fw-semibold mb-2" id="scanEventHeading"><i class="bi bi-calendar-event text-primary me-1" aria-hidden="true"></i> Event</h2>
                <label class="form-label small text-muted mb-1" for="eventSelect">Check participants in for</label>
                <select id="eventSelect" class="form-select">
                    <?php foreach ($assignments as $a): ?>
                        <option value="<?= (int) $a['event_id'] ?>"><?= e($a['event_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-5 small text-muted">
                <i class="bi bi-shield-check" aria-hidden="true"></i> Only events you are assigned to with the Scan QR permission are listed. EVORA checks every scan on the server.
            </div>
        </div>
    </section>

    <div class="row g-3">
        <div class="col-lg-7">
            <section class="card-evora p-4" aria-labelledby="scannerHeading">
                <div class="d-flex justify-content-between align-items-center gap-2 mb-3">
                    <h2 class="h6 fw-semibold mb-0" id="scannerHeading"><i class="bi bi-qr-code-scan text-primary me-1" aria-hidden="true"></i> QR Scanner</h2>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="cameraToggle" hidden><i class="bi bi-stop-circle me-1" aria-hidden="true"></i> Stop Camera</button>
                </div>

                <div class="scanner-frame">
                    <div id="qr-reader" aria-label="Camera preview"></div>
                    <div class="scanner-placeholder" aria-hidden="true">
                        <i class="bi bi-qr-code-scan"></i>
                        <span>Camera preview appears here</span>
                    </div>
                </div>
                <p class="small text-muted text-center mt-2 mb-3">Point the participant's QR code inside the frame · Scanning for <strong id="scanningEventName"><?= e($assignments[0]['event_name']) ?></strong></p>

                <div id="cameraStatus" class="scanner-status is-idle" role="status" aria-live="polite">
                    <div class="scanner-status-title"><i class="bi bi-camera" aria-hidden="true"></i> Preparing scanner…</div>
                </div>

                <div id="scanResult" class="scan-result" role="status" aria-live="assertive" aria-atomic="true" hidden></div>

                <form id="manualForm" class="mt-3" autocomplete="off">
                    <label class="form-label small mb-1" for="manualToken">Or enter QR text manually</label>
                    <div class="input-group">
                        <input type="text" id="manualToken" class="form-control" placeholder="Paste QR token here" aria-describedby="manualHelp">
                        <button type="submit" class="btn btn-outline-secondary" id="manualSubmit">Lookup</button>
                    </div>
                    <div class="form-text" id="manualHelp">Uses the same checks as the camera.</div>
                </form>

                <div class="mt-3 pt-3 border-top">
                    <label class="form-label small fw-semibold mb-1" for="findInput"><i class="bi bi-person-search me-1" aria-hidden="true"></i> No QR? Find the participant</label>
                    <input type="search" id="findInput" class="form-control" placeholder="Name, email, bib or registration #" autocomplete="off" aria-describedby="findHelp" maxlength="100">
                    <div class="form-text" id="findHelp">For a dead phone or a lost QR. Check their ID before confirming — the same rules as a QR scan apply.</div>
                    <div id="findResults" class="list-group mt-2" aria-live="polite"></div>
                </div>
            </section>
        </div>
        <div class="col-lg-5">
            <section class="card-evora p-4 mb-3" aria-labelledby="liveHeading">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h2 class="h6 fw-semibold mb-0" id="liveHeading"><i class="bi bi-people text-primary me-1" aria-hidden="true"></i> Live Check-in</h2>
                    <span class="small text-muted" id="liveUpdated"></span>
                </div>
                <div class="d-flex align-items-baseline gap-2"><span class="display-6 fw-bold" id="liveCount">–</span><span class="text-muted" id="liveOf"></span></div>
                <div class="progress my-2" style="height:10px" role="progressbar" aria-label="Checked-in share" aria-valuemin="0" aria-valuemax="100" id="liveBarWrap"><div class="progress-bar bg-success" id="liveBar" style="width:0%"></div></div>
                <details id="missingBox">
                    <summary class="small fw-semibold" style="cursor:pointer">Not yet arrived (<span id="missingCount">0</span>)</summary>
                    <input type="search" id="missingFilter" class="form-control form-control-sm mt-2" placeholder="Filter names…" aria-label="Filter the not-yet-arrived list">
                    <ul class="list-unstyled small mt-2 mb-0" id="missingList" style="max-height:260px;overflow:auto"></ul>
                </details>
                <div id="undoBox" class="mt-3" hidden>
                    <div class="small fw-semibold mb-1">Your check-ins you can still undo</div>
                    <ul class="list-unstyled small mb-0" id="undoList"></ul>
                </div>
            </section>
            <section class="card-evora p-4" aria-labelledby="recentHeading">
                <h2 class="h6 fw-semibold mb-3" id="recentHeading"><i class="bi bi-clock-history text-primary me-1" aria-hidden="true"></i> Recent Scans</h2>
                <ul class="list-unstyled mb-0 recent-scans" id="recentScans" aria-live="polite">
                    <?php if (!$recent): ?>
                        <li id="recentEmpty" class="text-muted small">No scans yet.</li>
                    <?php endif; ?>
                    <?php foreach ($recent as $rs): ?>
                        <li class="recent-scan">
                            <span class="badge bg-success">Checked in</span>
                            <div class="recent-scan-body">
                                <div class="fw-semibold"><?= e($rs['full_name']) ?></div>
                                <div class="small text-muted"><?= e($rs['event_name']) ?> · <?= date('M j, g:i A', strtotime($rs['scanned_at'])) ?></div>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
        </div>
    </div>
</div>

<script src="<?= assetUrl('vendor/html5-qrcode.min.js') ?>"></script>
<script src="<?= assetUrl('js/staff_scanner.js') ?>"></script>
<?php endif; ?>
<?php include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php'; ?>
