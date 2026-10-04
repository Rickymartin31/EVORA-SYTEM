<?php
/**
 * "Today" panel (v54) for the organizer and staff dashboards: every event happening today with
 * live check-in numbers (refreshed from today-stats every 30 s) and the event-day shortcuts.
 * Expects $todayEvents from todayEventsFor().
 */
$todayEvents = $todayEvents ?? todayEventsFor(Database::connection());
if (empty($todayEvents)) {
    return;
}
$todayRole = Auth::role();
?>
<section class="card-evora today-panel mb-3" aria-labelledby="todayTitle" data-today-url="<?= e(url('today-stats')) ?>">
    <div class="today-head">
        <h2 class="h6 fw-bold mb-0" id="todayTitle"><span class="today-live" aria-hidden="true"></span>Today · <?= date('D, M d') ?></h2>
        <span class="small text-muted" aria-live="polite">Live · updated <span data-today-updated><?= date('g:i A') ?></span></span>
    </div>
    <?php foreach ($todayEvents as $te): ?>
        <?php $tePct = (int) $te['approved'] > 0 ? (int) min(100, round($te['checked_in'] * 100 / $te['approved'])) : 0; ?>
        <div class="today-event" data-today-event="<?= (int) $te['id'] ?>">
            <div class="today-event-info">
                <div class="today-event-name"><?= e($te['name']) ?></div>
                <div class="small text-muted"><?= $te['event_time'] ? date('g:i A', strtotime($te['event_time'])) . ' · ' : '' ?><?= e($te['venue']) ?> · <span class="badge badge-status-<?= e($te['status']) ?>"><?= ucfirst(e($te['status'])) ?></span></div>
            </div>
            <div class="today-stats">
                <div class="today-stat"><strong data-today-checked><?= (int) $te['checked_in'] ?></strong><span>checked in</span></div>
                <div class="today-stat"><strong data-today-waiting><?= max(0, (int) $te['approved'] - (int) $te['checked_in']) ?></strong><span>not yet arrived</span></div>
                <div class="today-stat"><strong data-today-total><?= (int) $te['approved'] ?></strong><span>registered</span></div>
            </div>
            <div class="progress today-progress" role="progressbar" aria-label="Checked in" aria-valuenow="<?= $tePct ?>" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar" data-today-bar style="width:<?= $tePct ?>%"></div></div>
            <div class="today-actions">
                <?php if ($todayRole === 'staff'): ?>
                    <?php if (in_array('scan_qr', $te['perms'], true)): ?><a href="<?= url('staff-scan') ?>" class="btn btn-evora-primary btn-sm"><i class="bi bi-qr-code-scan me-1" aria-hidden="true"></i>Scan QR</a><?php endif; ?>
                    <?php if (in_array('manage_walkin', $te['perms'], true)): ?><a href="<?= url('walkin-register') ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-person-plus me-1" aria-hidden="true"></i>Walk-in</a><?php endif; ?>
                    <?php if (in_array('record_attendance', $te['perms'], true)): ?><a href="<?= url('staff-attendance', ['event_id' => $te['id']]) ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-clipboard-data me-1" aria-hidden="true"></i>Attendance</a><?php endif; ?>
                    <?php if (in_array('view_announcements', $te['perms'], true)): ?><a href="<?= url('staff-announcements', ['event_id' => $te['id']]) ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-megaphone me-1" aria-hidden="true"></i>Announcements</a><?php endif; ?>
                <?php else: ?>
                    <a href="<?= url('organizer-registrations', ['event_id' => $te['id']]) ?>" class="btn btn-evora-primary btn-sm"><i class="bi bi-people me-1" aria-hidden="true"></i>Participants</a>
                    <a href="<?= url('walkin-register') ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-person-plus me-1" aria-hidden="true"></i>Walk-in</a>
                    <a href="<?= url('communication-center', ['event_id' => $te['id']]) ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-megaphone me-1" aria-hidden="true"></i>Announce</a>
                    <a href="<?= url('organizer-event-manage', ['id' => $te['id']]) ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-gear me-1" aria-hidden="true"></i>Manage</a>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
</section>
