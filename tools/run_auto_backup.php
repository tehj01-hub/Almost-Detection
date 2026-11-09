<?php
/**
 * Weekly automatic database backup + 6-month clean-up (command line).
 *
 * The website already runs this automatically on page loads once a week.
 * Use this script with Windows Task Scheduler if you also want it to run
 * when nobody opens the system (e.g. every Sunday 02:00):
 *
 *   C:\xampp\php\php.exe C:\xampp\htdocs\NewCapstone\tools\run_auto_backup.php
 *
 * Options:
 *   --force   create a backup now even if the last one is less than 7 days old
 *
 * Exit code: 0 = OK / nothing due, 1 = backup failed.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Command line only.');
}

define('SECSYS_ALLOW_CLI_AUTOBACKUP', true);
date_default_timezone_set('Asia/Manila');

require_once __DIR__ . '/../app/system_config.php';
require_once __DIR__ . '/../app/backup_helper.php';

$force = in_array('--force', $argv, true);

$conn = secsysConnectDb(true);
if (!$conn) {
    fwrite(STDERR, "[auto-backup] Cannot connect to the database.\n");
    exit(1);
}

$result = secsysRunAutoBackupIfDue($conn, $force);

echo '[auto-backup] ' . date('Y-m-d H:i:s') . ' - ' . $result['message'] . PHP_EOL;
if (!empty($result['deleted'])) {
    echo '[auto-backup] Deleted ' . count($result['deleted']) . ' backup(s) older than '
        . SECSYS_BACKUP_RETENTION_MONTHS . ' months: ' . implode(', ', $result['deleted']) . PHP_EOL;
}

$conn->close();
exit($result['success'] === false ? 1 : 0);
