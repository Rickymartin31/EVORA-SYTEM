<?php
// Admin: Error Log (v35) — recent PHP errors and crashes recorded by ErrorMonitor. Read-only.
Auth::requireRole('admin');

$levelFilter = (string) ($_GET['level'] ?? '');
$levels = ['exception' => ['Crash', 'bg-danger'], 'fatal' => ['Fatal', 'bg-danger'], 'error' => ['Error', 'bg-danger'],
    'warning' => ['Warning', 'bg-warning text-dark'], 'notice' => ['Notice', 'bg-secondary'], 'deprecated' => ['Deprecated', 'bg-light text-dark border']];
$entries = ErrorMonitor::recent(500);

// Group identical problems so one repeated warning doesn't bury everything else.
$groups = [];
foreach ($entries as $e) {
    if ($levelFilter !== '' && ($e['level'] ?? '') !== $levelFilter) {
        continue;
    }
    $key = ($e['level'] ?? '') . '|' . ($e['file'] ?? '') . ':' . ($e['line'] ?? '') . '|' . preg_replace('/\d+/', '#', (string) ($e['message'] ?? ''));
    if (!isset($groups[$key])) {
        $groups[$key] = $e + ['count' => 0, 'first' => $e['time'], 'routes' => []];
    }
    $groups[$key]['count']++;
    $groups[$key]['first'] = $e['time']; // entries are newest first, so the last one seen is the oldest
    $groups[$key]['routes'][$e['route'] ?: '—'] = true;
}
$crashes24h = count(array_filter($entries, fn($e) => in_array($e['level'] ?? '', ['exception', 'fatal'], true) && strtotime($e['time']) > time() - 86400));
$total24h = count(array_filter($entries, fn($e) => strtotime($e['time']) > time() - 86400));

// System health (v38): last automatic backup and the outgoing email queue.
$backup = is_file(EVORA_ROOT . '/storage/logs/backup_status.json') ? json_decode((string) file_get_contents(EVORA_ROOT . '/storage/logs/backup_status.json'), true) : null;
$backupStale = !$backup || !$backup['ok'] || strtotime($backup['time']) < time() - 2 * 86400;
$mailQueue = Database::connection()->query("SELECT SUM(status IN ('pending', 'sending')) AS waiting, SUM(status = 'failed') AS failed, MAX(sent_at) AS last_sent FROM email_queue")->fetch();

$pageTitle = 'Error Log';
$pageDescription = 'Recent system errors and crashes, so problems are noticed and fixed quickly.';
$activeRoute = 'admin-error-logs';
include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
?>
<?php renderBreadcrumb([['label' => 'Error Log']]); ?>
<div class="row g-3 mb-3">
    <div class="col-sm-6 col-lg-3"><div class="card-evora p-3"><div class="small text-muted">Crashes (last 24 hours)</div><div class="h4 fw-bold mb-0 <?= $crashes24h ? 'text-danger' : '' ?>"><?= $crashes24h ?></div></div></div>
    <div class="col-sm-6 col-lg-3"><div class="card-evora p-3"><div class="small text-muted">All entries (last 24 hours)</div><div class="h4 fw-bold mb-0"><?= $total24h ?></div></div></div>
    <div class="col-sm-6 col-lg-3"><div class="card-evora p-3"><div class="small text-muted">Last database backup</div>
        <div class="h6 fw-bold mb-0 mt-1 <?= $backupStale ? 'text-danger' : '' ?>"><?= $backup ? e(date('M d, g:i A', strtotime($backup['time']))) . ($backup['ok'] ? '' : ' — FAILED') : 'Never' ?></div>
        <div class="small text-muted"><?= $backup && $backup['ok'] ? round($backup['bytes'] / 1024) . ' KB, automatic daily' : ($backup ? e($backup['message']) : 'No automatic backup has run yet') ?></div></div></div>
    <div class="col-sm-6 col-lg-3"><div class="card-evora p-3"><div class="small text-muted">Email queue</div>
        <div class="h6 fw-bold mb-0 mt-1 <?= (int) $mailQueue['failed'] ? 'text-danger' : '' ?>"><?= (int) $mailQueue['waiting'] ?> waiting · <?= (int) $mailQueue['failed'] ?> failed</div>
        <div class="small text-muted"><?= MailService::enabled() ? ($mailQueue['last_sent'] ? 'Last sent ' . e(date('M d, g:i A', strtotime($mailQueue['last_sent']))) : 'Nothing sent yet') : 'Email is turned off' ?></div></div></div>
</div>
<?= evoraAlert('info', 'Each page crash shows the user a reference code (e.g. <code>3F9A12BC</code>) — search for it below. Administrators get a notification the first time a new crash happens (at most once an hour per problem). Request data, passwords and tokens are never recorded.', ['class' => 'small']) ?>
<section class="card-evora p-4" aria-labelledby="errHeading">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h2 class="h6 fw-semibold mb-0" id="errHeading">Problems (grouped, newest first)</h2>
        <form method="get" class="d-flex gap-2 align-items-center">
            <input type="hidden" name="route" value="admin-error-logs">
            <label for="errLevel" class="small text-muted">Level</label>
            <select name="level" id="errLevel" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="">All</option>
                <?php foreach ($levels as $key => [$label]): ?>
                    <option value="<?= e($key) ?>" <?= $levelFilter === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
    <?php if (!$groups): ?>
        <?= emptyState('bi-check-circle', 'No errors recorded. Everything looks fine.') ?>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-sm align-middle table-datatable" data-order='[[0,"desc"]]'>
            <thead><tr><th>Last seen</th><th>Level</th><th>Problem</th><th>Where</th><th class="text-center">Times</th><th>Pages</th><th>Last ref.</th></tr></thead>
            <tbody>
            <?php foreach ($groups as $g): ?>
                <?php [$label, $badge] = $levels[$g['level']] ?? [ucfirst((string) $g['level']), 'bg-secondary']; ?>
                <tr>
                    <td class="small text-nowrap" data-order="<?= strtotime($g['time']) ?>"><?= e(date('M d, g:i A', strtotime($g['time']))) ?><div class="text-muted">since <?= e(date('M d', strtotime($g['first']))) ?></div></td>
                    <td><span class="badge <?= $badge ?>"><?= e($label) ?></span></td>
                    <td class="small text-break" style="max-width:420px;"><?= e($g['message']) ?></td>
                    <td class="small"><code><?= e($g['file']) ?>:<?= (int) $g['line'] ?></code></td>
                    <td class="text-center"><?= (int) $g['count'] ?></td>
                    <td class="small"><?= e(implode(', ', array_slice(array_keys($g['routes']), 0, 3))) ?></td>
                    <td class="small"><code><?= e($g['ref']) ?></code></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</section>
<?php include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php'; ?>
