<?php
/**
 * Maintenance mode from the command line (v60) — works even when the web app or the database is down.
 *
 *   php scripts/maintenance.php on [minutes] ["message"]   turn on (optional expected duration and message)
 *   php scripts/maintenance.php off                        end maintenance (also clears a schedule)
 *   php scripts/maintenance.php status                     show the current state
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require_once __DIR__ . '/../config/config.php';
require_once EVORA_ROOT . '/core/MaintenanceService.php';

$cmd = $argv[1] ?? 'status';
$by = 'command line (' . (getenv('USERNAME') ?: getenv('USER') ?: 'server') . ')';

if ($cmd === 'on') {
    $minutes = isset($argv[2]) && ctype_digit($argv[2]) ? (int) $argv[2] : 0;
    $message = $argv[$minutes ? 3 : 2] ?? '';
    MaintenanceService::startNow($by, $message, $minutes ? date('Y-m-d H:i:s', time() + $minutes * 60) : null);
    echo 'Maintenance is ON' . ($minutes ? ' (expected back ' . date('M d, g:i A', time() + $minutes * 60) . ')' : '') . ". Only administrators can use EVORA.\n";
} elseif ($cmd === 'off') {
    MaintenanceService::end($by);
    echo "Maintenance is OFF. EVORA is open to everyone.\n";
} elseif ($cmd === 'status') {
    $s = MaintenanceService::state();
    echo 'Maintenance: ' . (MaintenanceService::isActive() ? 'ON' : 'off') . "\n";
    foreach (['message', 'until', 'scheduled_start', 'scheduled_end', 'started_at', 'updated_by'] as $k) {
        if (!empty($s[$k])) {
            echo str_pad($k, 16) . ': ' . $s[$k] . "\n";
        }
    }
} else {
    fwrite(STDERR, "Usage: php scripts/maintenance.php on [minutes] [\"message\"] | off | status\n");
    exit(2);
}
