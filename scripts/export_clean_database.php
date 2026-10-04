<?php
/**
 * Builds a CLEAN database for a public deployment (v42).
 *
 * Usage:
 *   php scripts/export_clean_database.php
 *
 * Reads the structure of the configured database and writes, to BACKUP_DIR:
 *   evora_clean_production_YYYYMMDD_HHMMSS.sql
 * containing every table, foreign key and trigger, the reference data EVORA needs
 * (roles, event categories, sports, system settings) and ONE administrator account (id 1)
 * with a random, unknown password — set your own with "Forgot password" after deploying.
 * No events, registrations, payments, logs or other users are included.
 *
 * It also writes <secrets folder>/evora.production.ini (only if it does not exist yet) with a
 * new random QR_SECRET and placeholders for the hosting values. Nothing secret is printed.
 * The configured database itself is only READ, never changed.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/config.php';
require_once EVORA_ROOT . '/core/Database.php';

const REFERENCE_TABLES = ['roles', 'event_categories', 'sports', 'system_settings'];

$db = Database::connection();
$backupRoot = defined('BACKUP_DIR') ? BACKUP_DIR : dirname(EVORA_ROOT, 2) . DIRECTORY_SEPARATOR . 'evora_backups';
if (!is_dir($backupRoot)) {
    mkdir($backupRoot, 0750, true);
}
$file = $backupRoot . DIRECTORY_SEPARATOR . 'evora_clean_production_' . date('Ymd_His') . '.sql';
$out = fopen($file, 'wb');
$w = fn(string $s) => fwrite($out, $s);

$w("-- EVORA clean database for a public deployment\n-- Built " . date('Y-m-d H:i:s') . " from the structure of " . DB_NAME . "\n");
$w("-- Contains: all tables, foreign keys and triggers; reference data; one administrator (password must be reset).\n\n");
$w("SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\nSET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n");

$tables = $db->query('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"')->fetchAll(PDO::FETCH_NUM);
foreach ($tables as [$table]) {
    $q = '`' . str_replace('`', '``', $table) . '`';
    $create = $db->query('SHOW CREATE TABLE ' . $q)->fetch(PDO::FETCH_NUM)[1];
    $create = preg_replace('/\sAUTO_INCREMENT=\d+/', '', $create); // ids start from 1 again
    $w("DROP TABLE IF EXISTS $q;\n$create;\n\n");
    if (in_array($table, REFERENCE_TABLES, true)) {
        $rows = $db->query('SELECT * FROM ' . $q)->fetchAll(PDO::FETCH_NUM);
        if ($rows) {
            $values = array_map(fn($r) => '(' . implode(',', array_map(fn($v) => $v === null ? 'NULL' : $db->quote((string) $v), $r)) . ')', $rows);
            $w("INSERT INTO $q VALUES\n" . implode(",\n", $values) . ";\n\n");
        }
    }
}

// The one administrator: same name and email, a random unknown password, active and verified.
$admin = $db->query('SELECT u.full_name, u.email FROM users u JOIN roles r ON r.id = u.role_id WHERE r.name = "admin" ORDER BY u.id LIMIT 1')->fetch();
if (!$admin || !filter_var($admin['email'], FILTER_VALIDATE_EMAIL)) {
    fclose($out);
    unlink($file);
    exit("No administrator with a real email address was found — set one first (the clean database needs it for Forgot password and the login code).\n");
}
$adminRole = (int) $db->query('SELECT id FROM roles WHERE name = "admin"')->fetchColumn();
$w("INSERT INTO `users` (id, role_id, full_name, email, email_verified_at, password_hash, status) VALUES (1, $adminRole, "
    . $db->quote($admin['full_name']) . ', ' . $db->quote($admin['email']) . ', NOW(), '
    . $db->quote(password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT)) . ", 'active');\n\n");

foreach ($db->query('SHOW TRIGGERS')->fetchAll(PDO::FETCH_ASSOC) as $t) {
    $create = $db->query('SHOW CREATE TRIGGER `' . str_replace('`', '``', $t['Trigger']) . '`')->fetch(PDO::FETCH_ASSOC);
    $sql = preg_replace('/^CREATE\s+DEFINER\s*=\s*\S+\s+TRIGGER/i', 'CREATE TRIGGER', (string) $create['SQL Original Statement']);
    $w("DROP TRIGGER IF EXISTS `{$t['Trigger']}`;\nDELIMITER ;;\n$sql;;\nDELIMITER ;\n\n");
}
$w("SET FOREIGN_KEY_CHECKS = 1;\n-- Dump completed " . date('Y-m-d H:i:s') . "\n");
fclose($out);

// Production settings template with a NEW QR secret (never overwrites an existing file).
$iniFile = EVORA_SECRETS_DIR . DIRECTORY_SEPARATOR . 'evora.production.ini';
$iniNote = 'already exists — left unchanged';
if (!is_file($iniFile)) {
    file_put_contents($iniFile, implode("\n", [
        '; EVORA production settings — upload to the hosting as  <secrets folder>/evora.ini',
        '; (outside public_html). Keep private: never share it, commit it or paste it in chat.',
        'APP_ENV=production',
        'APP_URL=https://your-domain.example',
        'DB_HOST=localhost',
        'DB_NAME=',
        'DB_USER=',
        'DB_PASS=',
        '; Signs every QR code. Generated for this deployment — do not change it after the first real event.',
        'QR_SECRET=' . bin2hex(random_bytes(32)),
        '; Folder for automatic backups (outside public_html), e.g. /home/USER/evora_backups',
        'BACKUP_DIR=',
        '; Optional: full path of mysqldump if it is not found automatically',
        'MYSQLDUMP_PATH=',
        '',
    ]));
    $iniNote = 'created with a new random QR_SECRET';
}

echo "Clean database: $file (" . round(filesize($file) / 1024) . " KB, " . count($tables) . " tables, admin " . substr($admin['email'], 0, 2) . "***)\n";
echo "Production settings template: $iniFile ($iniNote)\n";
