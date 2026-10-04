<?php
/**
 * Automatic database backup (v38, hosting-ready in v42).
 *
 * Usage:
 *   php scripts/backup_database.php [--keep=14] [--php]
 *   (XAMPP: C:\xampp\php\php.exe scripts\backup_database.php)
 *
 * Dumps the configured database (tables, data, triggers) to
 *   <BACKUP_DIR>/auto/<database>/<database>_auto_YYYYMMDD_HHMMSS.sql
 * keeps the newest --keep files there (default 14) and deletes older AUTO backups only —
 * other files in the backup folder are never touched.
 *
 * mysqldump is used when it can run (XAMPP path, MYSQLDUMP_PATH in evora.ini, or "mysqldump" on the
 * system path). Shared hosting often blocks it; then a built-in PHP dump is used instead (also
 * forced with --php). Meant for Task Scheduler / cron (daily). The result is shown to
 * administrators on the Error Log page; a failed backup alerts them.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once EVORA_ROOT . '/core/Database.php';
require_once EVORA_ROOT . '/core/Logger.php';
require_once EVORA_ROOT . '/core/NotificationService.php';
require_once EVORA_ROOT . '/core/MailService.php';
require_once EVORA_ROOT . '/core/helpers.php';

$keep = 14;
$forcePhp = false;
foreach ($argv as $arg) {
    if (preg_match('/^--keep=(\d+)$/', $arg, $m)) {
        $keep = max(3, (int) $m[1]);
    }
    if ($arg === '--php') {
        $forcePhp = true;
    }
}

$backupRoot = defined('BACKUP_DIR') ? BACKUP_DIR : dirname(EVORA_ROOT, 2) . DIRECTORY_SEPARATOR . 'evora_backups';
$dir = $backupRoot . DIRECTORY_SEPARATOR . 'auto' . DIRECTORY_SEPARATOR . DB_NAME;
if (!is_dir($dir) && !mkdir($dir, 0750, true)) {
    backupFinished(false, 'Could not create the backup folder.', null, 0);
}
$file = $dir . DIRECTORY_SEPARATOR . DB_NAME . '_auto_' . date('Ymd_His') . '.sql';

$method = 'php';
$mysqldump = $forcePhp ? null : findMysqldump();
if ($mysqldump !== null) {
    // The password (if any) goes through the environment, never the command line.
    if (DB_PASS !== '') {
        putenv('MYSQL_PWD=' . DB_PASS);
    }
    $cmd = escapeshellarg($mysqldump) . ' --host=' . escapeshellarg(DB_HOST) . ' --user=' . escapeshellarg(DB_USER)
        . ' --routines --triggers --single-transaction --default-character-set=utf8mb4 ' . escapeshellarg(DB_NAME)
        . ' --result-file=' . escapeshellarg($file) . ' 2>&1';
    exec($cmd, $output, $code);
    putenv('MYSQL_PWD');
    $method = 'mysqldump';
    if ($code !== 0 || !dumpComplete($file)) {
        @unlink($file);
        $method = 'php'; // fall back to the built-in dump
    }
}
if ($method === 'php') {
    try {
        phpDump(Database::connection(), $file);
    } catch (Throwable $e) {
        @unlink($file);
        backupFinished(false, 'Backup failed: ' . mb_substr($e->getMessage(), 0, 200), null, 0);
    }
    if (!dumpComplete($file)) {
        @unlink($file);
        backupFinished(false, 'Backup file is incomplete.', null, 0);
    }
}
$bytes = (int) filesize($file);

// Keep only the newest $keep automatic backups.
$autos = glob($dir . DIRECTORY_SEPARATOR . DB_NAME . '_auto_*.sql') ?: [];
rsort($autos);
foreach (array_slice($autos, $keep) as $old) {
    @unlink($old);
}
backupFinished(true, 'Backup completed (' . $method . ').', $file, $bytes);

/** mysqldump binary that can actually run here, or null. */
function findMysqldump(): ?string
{
    if (!function_exists('exec') || in_array('exec', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true)) {
        return null;
    }
    $candidates = array_filter([
        defined('MYSQLDUMP_PATH') ? MYSQLDUMP_PATH : '',
        dirname(EVORA_ROOT, 2) . DIRECTORY_SEPARATOR . 'mysql' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'mysqldump.exe', // XAMPP
        '/usr/bin/mysqldump', '/usr/local/bin/mysqldump', '/usr/bin/mariadb-dump',
    ]);
    foreach ($candidates as $c) {
        if (is_file($c) && is_executable($c)) {
            return $c;
        }
    }
    @exec('mysqldump --version 2>&1', $out, $code);

    return $code === 0 ? 'mysqldump' : null;
}

function dumpComplete(string $file): bool
{
    if (!is_file($file) || filesize($file) === 0) {
        return false;
    }
    $tail = (string) file_get_contents($file, false, null, max(0, filesize($file) - 200));

    return str_contains($tail, 'Dump completed');
}

/** Plain-PHP dump: CREATE TABLE + data + triggers, restorable with the mysql client or phpMyAdmin. */
function phpDump(PDO $db, string $file): void
{
    $out = fopen($file, 'wb');
    $w = fn(string $s) => fwrite($out, $s);
    $w("-- EVORA database backup (PHP dump)\n-- Database: " . DB_NAME . "\n-- Created: " . date('Y-m-d H:i:s') . "\n\n");
    $w("SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\nSET UNIQUE_CHECKS = 0;\nSET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n");
    $tables = $db->query('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"')->fetchAll(PDO::FETCH_NUM);
    foreach ($tables as [$table]) {
        $create = $db->query('SHOW CREATE TABLE `' . str_replace('`', '``', $table) . '`')->fetch(PDO::FETCH_NUM)[1];
        $w("DROP TABLE IF EXISTS `$table`;\n$create;\n\n");
        // Generated columns are computed by MariaDB on restore and may not be given a value.
        $cols = [];
        foreach ($db->query('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '`')->fetchAll(PDO::FETCH_ASSOC) as $c) {
            if (!preg_match('/GENERATED|PERSISTENT|VIRTUAL/i', (string) $c['Extra'])) {
                $cols[] = '`' . str_replace('`', '``', $c['Field']) . '`';
            }
        }
        $colList = implode(',', $cols);
        $stmt = $db->query('SELECT ' . $colList . ' FROM `' . str_replace('`', '``', $table) . '`', PDO::FETCH_NUM);
        $batch = [];
        while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
            $batch[] = '(' . implode(',', array_map(fn($v) => $v === null ? 'NULL' : $db->quote((string) $v), $row)) . ')';
            if (count($batch) >= 200) {
                $w("INSERT INTO `$table` ($colList) VALUES\n" . implode(",\n", $batch) . ";\n");
                $batch = [];
            }
        }
        if ($batch) {
            $w("INSERT INTO `$table` ($colList) VALUES\n" . implode(",\n", $batch) . ";\n");
        }
        $w("\n");
    }
    foreach ($db->query('SHOW TRIGGERS')->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $create = $db->query('SHOW CREATE TRIGGER `' . str_replace('`', '``', $t['Trigger']) . '`')->fetch(PDO::FETCH_ASSOC);
        $sql = preg_replace('/^CREATE\s+DEFINER\s*=\s*\S+\s+TRIGGER/i', 'CREATE TRIGGER', (string) $create['SQL Original Statement']);
        $w("DROP TRIGGER IF EXISTS `{$t['Trigger']}`;\nDELIMITER ;;\n$sql;;\nDELIMITER ;\n\n");
    }
    $w("SET FOREIGN_KEY_CHECKS = 1;\nSET UNIQUE_CHECKS = 1;\n-- Dump completed " . date('Y-m-d H:i:s') . "\n");
    fclose($out);
}

function backupFinished(bool $ok, string $message, ?string $file, int $bytes): never
{
    $status = ['time' => date('Y-m-d H:i:s'), 'ok' => $ok, 'message' => $message, 'file' => $file ? basename($file) : null, 'bytes' => $bytes];
    @mkdir(EVORA_ROOT . '/storage/logs', 0775, true);
    file_put_contents(EVORA_ROOT . '/storage/logs/backup_status.json', json_encode($status));
    try {
        Logger::log(null, $ok ? 'database_backup' : 'database_backup_failed', $message . ($file ? ' ' . basename($file) . ' (' . round($bytes / 1024) . ' KB)' : ''));
        if (!$ok) {
            NotificationService::toAdmins(Database::connection(), 'Database Backup Failed', 'The automatic database backup failed: ' . $message, 'system_error',
                ['category' => 'system', 'notice_type' => 'action_required']);
        }
    } catch (Throwable) {
        // the database may be what failed
    }
    echo ($ok ? 'OK ' : 'FAILED ') . $message . ($file ? ' -> ' . $file : '') . "\n";
    exit($ok ? 0 : 1);
}
