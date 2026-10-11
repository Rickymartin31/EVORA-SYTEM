<?php
/**
 * Maintenance Mode (v60): turn it on now, schedule a window (with a notice banner beforehand),
 * or end it. POST maintenance_action = start | schedule | end.
 */
Auth::requireRole('admin');
$userId = Auth::id();
$by = 'admin #' . $userId;

$toDb = function (string $local): ?string {
    $local = trim($local);
    if ($local === '') {
        return null;
    }
    $ts = strtotime($local);
    if ($ts === false) {
        throw new DomainException('Please enter a valid date and time.');
    }

    return date('Y-m-d H:i:s', $ts);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $action = $_POST['maintenance_action'] ?? '';
    $message = mb_substr(trim((string) ($_POST['message'] ?? '')), 0, 500);
    try {
        if ($action === 'start') {
            $until = $toDb((string) ($_POST['until'] ?? ''));
            if ($until !== null && strtotime($until) <= time()) {
                throw new DomainException('The expected end must be in the future (or leave it empty).');
            }
            MaintenanceService::startNow($by, $message, $until);
            Logger::log($userId, 'maintenance_started', 'Turned maintenance mode ON' . ($until ? ' until ' . $until : ''));
            flash('success', 'Maintenance mode is ON. Only administrators can use EVORA until you end it.');
        } elseif ($action === 'schedule') {
            $start = $toDb((string) ($_POST['start'] ?? ''));
            if ($start === null) {
                throw new DomainException('Please choose when the maintenance starts.');
            }
            $end = $toDb((string) ($_POST['end'] ?? ''));
            MaintenanceService::schedule($by, $start, $end, $message);
            Logger::log($userId, 'maintenance_scheduled', 'Scheduled maintenance from ' . $start . ($end ? ' to ' . $end : ''));
            flash('success', 'Maintenance scheduled. Everyone sees a notice banner, and it turns on by itself at ' . date('M d, g:i A', strtotime($start)) . '.');
        } elseif ($action === 'end') {
            MaintenanceService::end($by);
            Logger::log($userId, 'maintenance_ended', 'Ended maintenance mode (and cleared any schedule)');
            flash('success', 'Maintenance ended. EVORA is open to everyone again.');
        }
    } catch (DomainException | RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect('admin-maintenance');
}

$state = MaintenanceService::state();
$active = MaintenanceService::isActive();
$upcoming = MaintenanceService::upcoming();
$scheduled = !$active && $state['scheduled_start'];
$local = fn (?string $dt) => $dt ? date('Y-m-d\TH:i', strtotime($dt)) : '';

$pageDescription = 'Close EVORA to everyone except administrators while it is being fixed or updated.';
$pageTitle = 'Maintenance Mode';
$activeRoute = 'admin-maintenance';
include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
?>
<div class="row g-3">
    <div class="col-lg-7">
        <div class="card-evora p-4 mb-3">
            <div class="d-flex align-items-center gap-3 mb-3">
                <span class="maintenance-status <?= $active ? 'is-on' : ($scheduled ? 'is-scheduled' : 'is-off') ?>" aria-hidden="true"><i class="bi <?= $active ? 'bi-tools' : ($scheduled ? 'bi-calendar2-event' : 'bi-check-circle') ?>"></i></span>
                <div>
                    <h6 class="fw-semibold mb-0">
                        <?php if ($active): ?>Maintenance is ON
                        <?php elseif ($scheduled): ?>Maintenance is scheduled
                        <?php else: ?>EVORA is open to everyone<?php endif; ?>
                    </h6>
                    <div class="small text-muted">
                        <?php if ($active): ?>
                            Since <?= $state['started_at'] ? date('M d, g:i A', strtotime($state['started_at'])) : date('M d, g:i A', strtotime((string) $state['scheduled_start'])) ?>
                            <?= MaintenanceService::until() ? ' · expected back ' . date('M d, g:i A', strtotime(MaintenanceService::until())) : '' ?>
                            <?= MaintenanceService::isOverdue() ? ' · <span class="text-danger">past the expected end</span>' : '' ?>
                        <?php elseif ($scheduled): ?>
                            Starts <?= date('l, M d, g:i A', strtotime($state['scheduled_start'])) ?><?= $state['scheduled_end'] ? ' · ends ' . date('M d, g:i A', strtotime($state['scheduled_end'])) : '' ?>
                            <?= $upcoming ? ' · the notice banner is showing' : ' · the banner appears 7 days before' ?>
                        <?php else: ?>
                            Nobody is blocked.
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php if ($active || $scheduled): ?>
                <?php if ($state['message'] !== ''): ?><p class="small mb-3"><span class="text-muted">Message shown:</span> <?= e($state['message']) ?></p><?php endif; ?>
                <form method="post" action="<?= url('admin-maintenance') ?>" data-confirm="<?= $active ? 'Everyone can use EVORA again right away. Make sure the fix was tested.' : 'The scheduled maintenance and its banner are removed.' ?>" data-confirm-title="<?= $active ? 'End maintenance?' : 'Cancel the schedule?' ?>" data-confirm-button="<?= $active ? 'End Maintenance' : 'Cancel Schedule' ?>">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="maintenance_action" value="end">
                    <button type="submit" class="btn btn-success"><i class="bi bi-check2-circle"></i> <?= $active ? 'End Maintenance & Reopen EVORA' : 'Cancel Scheduled Maintenance' ?></button>
                </form>
            <?php endif; ?>
        </div>

        <?php if (!$active): ?>
        <div class="card-evora p-4 mb-3">
            <h6 class="fw-semibold mb-1"><i class="bi bi-power me-1"></i> Turn On Now</h6>
            <p class="small text-muted">For an urgent fix. Everyone except administrators sees the maintenance page immediately.</p>
            <form method="post" action="<?= url('admin-maintenance') ?>" data-confirm="Participants, organizers and staff are stopped right away. Payments already approved in PayPal can still finish." data-confirm-title="Turn on maintenance now?" data-confirm-button="Turn On" data-confirm-variant="danger">
                <?= Csrf::field() ?>
                <input type="hidden" name="maintenance_action" value="start">
                <div class="mb-2">
                    <label class="form-label small" for="m-msg-now">Message for users</label>
                    <textarea class="form-control form-control-sm" id="m-msg-now" name="message" rows="2" maxlength="500" placeholder="e.g. We are fixing a problem with registrations. Your data is safe."></textarea>
                </div>
                <div class="mb-3">
                    <label class="form-label small" for="m-until">Expected back (optional)</label>
                    <input type="datetime-local" class="form-control form-control-sm mw-320" id="m-until" name="until">
                </div>
                <button type="submit" class="btn btn-danger"><i class="bi bi-tools"></i> Turn On Maintenance</button>
            </form>
        </div>

        <div class="card-evora p-4">
            <h6 class="fw-semibold mb-1"><i class="bi bi-calendar2-event me-1"></i> Schedule Maintenance</h6>
            <p class="small text-muted">For a planned update. A notice banner appears on every page up to 7 days before, and maintenance turns on by itself at the start time. It stays on until you end it here.</p>
            <form method="post" action="<?= url('admin-maintenance') ?>">
                <?= Csrf::field() ?>
                <input type="hidden" name="maintenance_action" value="schedule">
                <div class="row g-2 mb-2">
                    <div class="col-sm-6">
                        <label class="form-label small" for="m-start">Starts *</label>
                        <input type="datetime-local" class="form-control form-control-sm" id="m-start" name="start" required value="<?= e($local($state['scheduled_start'])) ?>">
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label small" for="m-end">Expected end</label>
                        <input type="datetime-local" class="form-control form-control-sm" id="m-end" name="end" value="<?= e($local($state['scheduled_end'])) ?>">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label small" for="m-msg-sched">Message for users</label>
                    <textarea class="form-control form-control-sm" id="m-msg-sched" name="message" rows="2" maxlength="500" placeholder="e.g. Scheduled system update."><?= e($scheduled ? $state['message'] : '') ?></textarea>
                </div>
                <button type="submit" class="btn btn-evora-primary"><i class="bi bi-calendar-check"></i> <?= $scheduled ? 'Update Schedule' : 'Schedule' ?></button>
            </form>
        </div>
        <?php endif; ?>
    </div>

    <div class="col-lg-5">
        <div class="card-evora p-4 mb-3">
            <h6 class="fw-semibold mb-2"><i class="bi bi-info-circle me-1"></i> What happens during maintenance</h6>
            <ul class="small mb-0 ps-3">
                <li class="mb-1"><strong>Administrators</strong> keep full access, so you can test the fix before reopening.</li>
                <li class="mb-1"><strong>Everyone else</strong> sees the maintenance page. No new registration, checkout or form can be submitted.</li>
                <li class="mb-1">A <strong>PayPal payment already approved</strong> by the buyer can still finish, so no payment is left half done.</li>
                <li class="mb-1">Runners' <strong>live tracking</strong> positions stay on their phones and are sent after maintenance.</li>
                <li class="mb-1">Waitlist offers and unpaid-registration reminders/closing <strong>pause</strong>, since participants cannot act.</li>
                <li>The daily backup and the email queue keep running.</li>
            </ul>
        </div>
        <div class="card-evora p-4">
            <h6 class="fw-semibold mb-2"><i class="bi bi-terminal me-1"></i> If this page itself is broken</h6>
            <p class="small text-muted mb-2">Maintenance can also be switched on the server, even when the database is down:</p>
            <pre class="small bg-body-tertiary border rounded p-2 mb-0" style="white-space: pre-wrap;">php scripts/maintenance.php on 120 "Fixing registrations"
php scripts/maintenance.php status
php scripts/maintenance.php off</pre>
        </div>
    </div>
</div>
<?php include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php'; ?>
