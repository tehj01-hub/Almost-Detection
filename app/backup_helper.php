<?php
/**
 * Database Backup & Restore Engine
 * Security Surveillance System
 */

require_once __DIR__ . '/system_config.php';

/**
 * Returns the absolute path to the backups directory.
 * Automatically creates directory with protective .htaccess if missing.
 */
function secsysGetBackupDirectory(): string
{
    $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'backups';
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }

    $htaccess = $dir . DIRECTORY_SEPARATOR . '.htaccess';
    if (!file_exists($htaccess)) {
        @file_put_contents($htaccess, "# Protect backup files from direct web access\nDeny from all\n");
    }

    $indexHtml = $dir . DIRECTORY_SEPARATOR . 'index.html';
    if (!file_exists($indexHtml)) {
        @file_put_contents($indexHtml, "<!DOCTYPE html><html><head><title>403 Forbidden</title></head><body><h1>Directory access is forbidden.</h1></body></html>");
    }

    return $dir;
}

/**
 * Format bytes into human-readable string (KB, MB, GB).
 */
function secsysFormatBytes(int $bytes, int $precision = 2): string
{
    $units = ['B', 'KB', 'MB', 'GB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= (1 << (10 * $pow));
    return round($bytes, $precision) . ' ' . $units[$pow];
}

/**
 * Verifies if the provided password belongs to the current logged-in admin user,
 * or matches any active Administrator in the system.
 */
function secsysVerifyAdminPassword(mysqli $conn, string $username, string $plainPassword): bool
{
    if (trim($plainPassword) === '') {
        return false;
    }

    // First check for the specific logged-in user
    if ($username !== '') {
        $stmt = $conn->prepare("SELECT password, role FROM users WHERE username = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($row = $res->fetch_assoc()) {
                $stored = (string)($row['password'] ?? '');
                if (password_verify($plainPassword, $stored) || $plainPassword === $stored) {
                    $stmt->close();
                    return true;
                }
            }
            $stmt->close();
        }
    }

    // Fallback: check if password matches ANY active Administrator user
    $resAdmins = $conn->query("SELECT password FROM users WHERE LOWER(role) IN ('administrator', 'superadmin', 'admin')");
    if ($resAdmins) {
        while ($admin = $resAdmins->fetch_assoc()) {
            $stored = (string)($admin['password'] ?? '');
            if (password_verify($plainPassword, $stored) || $plainPassword === $stored) {
                return true;
            }
        }
    }

    return false;
}

/**
 * Generates a full SQL dump of all tables, schema DDL, and data in the database.
 * Returns an array with status, filename, filepath, size, table counts, and row counts.
 */
function secsysCreateDatabaseBackup(mysqli $conn, ?string $targetDir = null, string $label = 'backup'): array
{
    @set_time_limit(300);
    @ini_set('memory_limit', '512M');

    $backupDir = $targetDir ?: secsysGetBackupDirectory();
    $timestamp = date('Y-m-d_H-i-s');
    $dbName = 'security_system';
    // 'backup' = made by the Administrator, 'autobackup' = weekly automatic backup
    $label = preg_replace('/[^a-z]/', '', strtolower($label)) ?: 'backup';
    $filename = "security_system_{$label}_{$timestamp}.sql";
    $filepath = $backupDir . DIRECTORY_SEPARATOR . $filename;

    // Get list of all tables
    $tablesRes = $conn->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
    if (!$tablesRes) {
        return [
            'success' => false,
            'message' => 'Failed to retrieve tables: ' . $conn->error,
        ];
    }

    $tables = [];
    while ($row = $tablesRes->fetch_array()) {
        $tables[] = $row[0];
    }

    if (empty($tables)) {
        return [
            'success' => false,
            'message' => 'No tables found in database to backup.',
        ];
    }

    $handle = @fopen($filepath, 'w');
    if (!$handle) {
        return [
            'success' => false,
            'message' => 'Unable to open file for writing at: ' . $filepath,
        ];
    }

    // Write SQL Header
    $header = "-- ========================================================\n"
            . "-- Security Surveillance System - Full Database Backup\n"
            . "-- Database: {$dbName}\n"
            . "-- Created At: " . date('Y-m-d H:i:s') . "\n"
            . "-- Host: " . ($conn->host_info ?: '127.0.0.1') . "\n"
            . "-- Tables Count: " . count($tables) . "\n"
            . "-- ========================================================\n\n"
            . "SET FOREIGN_KEY_CHECKS = 0;\n"
            . "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n"
            . "SET AUTOCOMMIT = 0;\n"
            . "START TRANSACTION;\n"
            . "SET time_zone = \"+08:00\";\n\n";

    fwrite($handle, $header);

    $totalRows = 0;

    foreach ($tables as $table) {
        // Table structure DDL
        $createRes = $conn->query("SHOW CREATE TABLE `{$table}`");
        if (!$createRes) {
            continue;
        }
        $createRow = $createRes->fetch_array();
        $createSql = $createRow[1] ?? '';

        $tableHeader = "\n-- --------------------------------------------------------\n"
                     . "-- Table structure for table `{$table}`\n"
                     . "-- --------------------------------------------------------\n"
                     . "DROP TABLE IF EXISTS `{$table}`;\n"
                     . "{$createSql};\n\n";

        fwrite($handle, $tableHeader);

        // Table Data Dump
        $rowsRes = $conn->query("SELECT * FROM `{$table}`");
        if (!$rowsRes) {
            continue;
        }

        $numRows = $rowsRes->num_rows;
        $numFields = $rowsRes->field_count;

        if ($numRows > 0) {
            $tableDataHeader = "-- Dumping data for table `{$table}` ({$numRows} rows)\n"
                             . "LOCK TABLES `{$table}` WRITE;\n"
                             . "ALTER TABLE `{$table}` DISABLE KEYS;\n";
            fwrite($handle, $tableDataHeader);

            // Fetch field types/names
            $fields = [];
            $numericTypes = [
                MYSQLI_TYPE_TINY, MYSQLI_TYPE_SHORT, MYSQLI_TYPE_INT24, MYSQLI_TYPE_LONG,
                MYSQLI_TYPE_LONGLONG, MYSQLI_TYPE_DECIMAL, MYSQLI_TYPE_NEWDECIMAL,
                MYSQLI_TYPE_FLOAT, MYSQLI_TYPE_DOUBLE, MYSQLI_TYPE_YEAR,
            ];
            $isNumericColumn = [];
            while ($f = $rowsRes->fetch_field()) {
                $fields[] = "`" . str_replace('`', '``', $f->name) . "`";
                $isNumericColumn[] = in_array((int)$f->type, $numericTypes, true);
            }
            $colsSql = implode(", ", $fields);

            $batchSize = 100;
            $batchValues = [];
            $batchCount = 0;

            while ($row = $rowsRes->fetch_row()) {
                $totalRows++;
                $valSql = [];
                for ($i = 0; $i < $numFields; $i++) {
                    $val = $row[$i];
                    if ($val === null) {
                        $valSql[] = "NULL";
                    } elseif ($isNumericColumn[$i] && is_numeric($val)) {
                        // Only real number columns are written unquoted; text such
                        // as plate "1E5" or phone "0917..." must stay a string.
                        $valSql[] = $val;
                    } else {
                        $valSql[] = "'" . $conn->real_escape_string($val) . "'";
                    }
                }
                $batchValues[] = "(" . implode(", ", $valSql) . ")";
                $batchCount++;

                if ($batchCount >= $batchSize) {
                    $insertSql = "INSERT INTO `{$table}` ({$colsSql}) VALUES\n"
                               . implode(",\n", $batchValues) . ";\n";
                    fwrite($handle, $insertSql);
                    $batchValues = [];
                    $batchCount = 0;
                }
            }

            if (!empty($batchValues)) {
                $insertSql = "INSERT INTO `{$table}` ({$colsSql}) VALUES\n"
                           . implode(",\n", $batchValues) . ";\n";
                fwrite($handle, $insertSql);
            }

            $tableDataFooter = "ALTER TABLE `{$table}` ENABLE KEYS;\n"
                             . "UNLOCK TABLES;\n\n";
            fwrite($handle, $tableDataFooter);
        }
    }

    // Scheduled events (e.g. the daily archive jobs) are part of a full backup too
    try {
        $eventsRes = $conn->query("SHOW EVENTS WHERE Db = DATABASE()");
    } catch (mysqli_sql_exception $e) {
        $eventsRes = false;
    }
    if ($eventsRes && $eventsRes->num_rows > 0) {
        fwrite($handle, "\n-- --------------------------------------------------------\n"
                      . "-- Scheduled events\n"
                      . "-- --------------------------------------------------------\n");
        while ($ev = $eventsRes->fetch_assoc()) {
            $eventName = (string)$ev['Name'];
            try {
                $createEv = $conn->query("SHOW CREATE EVENT `" . str_replace('`', '``', $eventName) . "`");
            } catch (mysqli_sql_exception $e) {
                $createEv = false;
            }
            $evRow = $createEv ? $createEv->fetch_assoc() : null;
            if (!empty($evRow['Create Event'])) {
                fwrite($handle, "DROP EVENT IF EXISTS `" . str_replace('`', '``', $eventName) . "`;\n"
                              . "DELIMITER $$\n" . $evRow['Create Event'] . "$$\nDELIMITER ;\n\n");
            }
        }
    }

    // Write SQL Footer
    $footer = "\n-- ========================================================\n"
            . "-- Backup Complete\n"
            . "-- Total Data Rows: {$totalRows}\n"
            . "-- ========================================================\n"
            . "COMMIT;\n"
            . "SET FOREIGN_KEY_CHECKS = 1;\n";

    fwrite($handle, $footer);
    fclose($handle);

    $filesize = file_exists($filepath) ? (int)filesize($filepath) : 0;

    return [
        'success'      => true,
        'filename'     => $filename,
        'filepath'     => $filepath,
        'filesize'     => $filesize,
        'filesize_fmt' => secsysFormatBytes($filesize),
        'tables_count' => count($tables),
        'rows_count'   => $totalRows,
        'created_at'   => date('Y-m-d H:i:s'),
    ];
}

/**
 * Split a SQL dump into individual statements.
 * Respects quoted strings, backtick identifiers and comments, so a ";"
 * inside a value (or a value spanning several lines) does not break the
 * statement apart. MySQL conditional comments ("/*!...") are kept,
 * because phpMyAdmin/mysqldump put real SET statements in them.
 */
function secsysSplitSqlStatements(string $sql): array
{
    $statements = [];
    $current = '';
    $len = strlen($sql);
    $quote = null;
    $delimiter = ';';

    for ($i = 0; $i < $len; $i++) {
        $ch = $sql[$i];
        $next = $i + 1 < $len ? $sql[$i + 1] : '';

        if ($quote !== null) {
            $current .= $ch;
            if ($ch === '\\' && $quote !== '`') {
                if ($i + 1 < $len) {
                    $current .= $sql[++$i];
                }
            } elseif ($ch === $quote) {
                if ($next === $quote) {
                    $current .= $sql[++$i]; // doubled quote = escaped quote
                } else {
                    $quote = null;
                }
            }
            continue;
        }

        // "DELIMITER $$" lines (used around EVENT / TRIGGER / PROCEDURE bodies)
        if (($i === 0 || $sql[$i - 1] === "\n")
            && preg_match('/\GDELIMITER[ \t]+(\S+)[^\n]*/i', $sql, $dm, 0, $i)) {
            $stmt = trim($current);
            if ($stmt !== '') {
                $statements[] = $stmt;
            }
            $current = '';
            $delimiter = $dm[1];
            $i += strlen($dm[0]) - 1;
            continue;
        }

        if ($ch === "'" || $ch === '"' || $ch === '`') {
            $quote = $ch;
            $current .= $ch;
            continue;
        }

        // "-- " / "#" line comments
        if (($ch === '-' && $next === '-' && ($i + 2 >= $len || ctype_space($sql[$i + 2]))) || $ch === '#') {
            $eol = strpos($sql, "\n", $i);
            $i = ($eol === false) ? $len : $eol;
            $current .= "\n";
            continue;
        }

        // /* block comments */ (but keep /*! conditional statements */)
        if ($ch === '/' && $next === '*') {
            $close = strpos($sql, '*/', $i + 2);
            $close = ($close === false) ? $len : $close + 2;
            if ($i + 2 < $len && $sql[$i + 2] === '!') {
                $current .= substr($sql, $i, $close - $i);
            }
            $i = $close - 1;
            continue;
        }

        if ($ch === $delimiter[0] && substr_compare($sql, $delimiter, $i, strlen($delimiter)) === 0) {
            $stmt = trim($current);
            if ($stmt !== '') {
                $statements[] = $stmt;
            }
            $current = '';
            $i += strlen($delimiter) - 1;
            continue;
        }

        $current .= $ch;
    }

    $stmt = trim($current);
    if ($stmt !== '') {
        $statements[] = $stmt;
    }

    return $statements;
}

/**
 * Restores the database from a given raw SQL string.
 * Works with this system's own backups and with phpMyAdmin/mysqldump
 * exports: every table in the file replaces the current table of the
 * same name (DROP + CREATE), even when the file has no DROP statements.
 */
function secsysRestoreDatabaseSql(mysqli $conn, string $sqlContent): array
{
    @set_time_limit(600);
    @ini_set('memory_limit', '512M');

    if (trim($sqlContent) === '') {
        return [
            'success' => false,
            'message' => 'The backup file is empty.',
        ];
    }

    // Strip a UTF-8 BOM and normalize line endings
    if (strncmp($sqlContent, "\xEF\xBB\xBF", 3) === 0) {
        $sqlContent = substr($sqlContent, 3);
    }
    $sqlContent = str_replace(["\r\n", "\r"], "\n", $sqlContent);

    $statements = secsysSplitSqlStatements($sqlContent);

    $tablePattern = '/^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(`(?:[^`]|``)+`|[A-Za-z0-9_$]+)/i';
    $objectPattern = '/^CREATE\s+(?:OR\s+REPLACE\s+)?(?:DEFINER\s*=\s*\S+\s+)?(EVENT|TRIGGER|PROCEDURE|FUNCTION)\s+(?:IF\s+NOT\s+EXISTS\s+)?(`(?:[^`]|``)+`|[A-Za-z0-9_$]+)/i';
    $unquote = function (string $name): string {
        return str_replace('``', '`', trim($name, '`'));
    };
    $quoteName = function (string $name): string {
        return '`' . str_replace('`', '``', $name) . '`';
    };

    $tablesInDump = [];
    foreach ($statements as $stmtSql) {
        if (preg_match($tablePattern, $stmtSql, $m)) {
            $tablesInDump[$unquote($m[1])] = true;
        }
    }
    if (empty($tablesInDump)) {
        return [
            'success' => false,
            'message' => 'The file does not look like a database backup (no CREATE TABLE statements were found). Nothing was changed.',
        ];
    }

    $executedCount = 0;
    $errors = [];

    $run = function (string $sql) use ($conn, &$errors): bool {
        try {
            if ($conn->query($sql) !== false) {
                return true;
            }
            $errors[] = 'Query failed: ' . substr($sql, 0, 80) . ' ... Error: ' . $conn->error;
        } catch (mysqli_sql_exception $e) {
            $errors[] = 'Query failed: ' . substr($sql, 0, 80) . ' ... Error: ' . $e->getMessage();
        }
        return false;
    };

    $run("SET FOREIGN_KEY_CHECKS = 0");
    $run("SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO'");
    $errors = [];

    // Replace every table in the file, even if the dump has no DROP
    // statements (phpMyAdmin exports do not include them by default).
    // All are dropped up front so foreign keys between them cannot block
    // re-creating a table.
    foreach (array_keys($tablesInDump) as $table) {
        $run('DROP TABLE IF EXISTS ' . $quoteName($table));
    }

    foreach ($statements as $stmtSql) {
        // Scheduled events, triggers and routines in the file replace the current ones
        if (preg_match($objectPattern, $stmtSql, $m)) {
            $run('DROP ' . strtoupper($m[1]) . ' IF EXISTS ' . $quoteName($unquote($m[2])));
        }

        if ($run($stmtSql)) {
            $executedCount++;
        } elseif (count($errors) > 20) {
            break;
        }
    }

    // Make sure everything is saved, then restore normal session settings
    $run("COMMIT");
    $run("SET AUTOCOMMIT = 1");
    $run("SET FOREIGN_KEY_CHECKS = 1");
    $run("SET time_zone = '+08:00'");

    // Make sure latest schema enhancements remain in place
    try {
        secsysEnsureSchema($conn);
    } catch (Throwable $e) {
        $errors[] = 'Schema update after restore failed: ' . $e->getMessage();
    }

    if (!empty($errors)) {
        return [
            'success'          => false,
            'message'          => 'Restoration completed with ' . count($errors) . ' error(s): ' . implode("; ", array_slice($errors, 0, 3)),
            'executed_queries' => $executedCount,
        ];
    }

    return [
        'success'          => true,
        'message'          => "Database successfully restored ({$executedCount} statements executed).",
        'executed_queries' => $executedCount,
    ];
}

/**
 * Lists all existing .sql backup files from the backups directory.
 */
function secsysListBackups(?string $targetDir = null): array
{
    $backupDir = $targetDir ?: secsysGetBackupDirectory();
    $files = [];

    if (!is_dir($backupDir)) {
        return [];
    }

    $scan = scandir($backupDir);
    if (!$scan) {
        return [];
    }

    foreach ($scan as $file) {
        if ($file === '.' || $file === '..' || strtolower(pathinfo($file, PATHINFO_EXTENSION)) !== 'sql') {
            continue;
        }

        $filePath = $backupDir . DIRECTORY_SEPARATOR . $file;
        if (!is_file($filePath)) {
            continue;
        }

        $size = (int)@filesize($filePath);
        $mtime = (int)@filemtime($filePath);

        $files[] = [
            'filename'     => $file,
            'filepath'     => $filePath,
            'filesize'     => $size,
            'filesize_fmt' => secsysFormatBytes($size),
            'mtime'        => $mtime,
            'created_fmt'  => date('M d, Y - h:i:s A', $mtime),
        ];
    }

    // Sort descending by creation time (newest first)
    usort($files, function ($a, $b) {
        return $b['mtime'] <=> $a['mtime'];
    });

    return $files;
}



/* =====================================================================
   AUTOMATIC WEEKLY BACKUP + 6-MONTH RETENTION
   ---------------------------------------------------------------------
   - Every 7 days a full backup is created automatically
     (file: security_system_autobackup_YYYY-MM-DD_HH-ii-ss.sql).
   - Backups older than 6 months are deleted automatically
     (manual and automatic; the newest backup is always kept).
   Triggered by:
     1) page loads (secsysMaybeRunAutoBackup() in system_config.php,
        a cheap timestamp check; the backup itself runs at most weekly)
     2) tools/run_auto_backup.php for Windows Task Scheduler (optional)
   State is kept in system_settings:
     auto_backup_last_at, auto_backup_last_file, auto_backup_last_status,
     backup_last_purge_at, backup_last_purge_count
   ===================================================================== */

const SECSYS_AUTO_BACKUP_INTERVAL_DAYS = 7;
const SECSYS_BACKUP_RETENTION_MONTHS = 6;

/** When was this backup made? Uses the timestamp in the file name, else the file time. */
function secsysBackupCreatedAt(array $file): int
{
    if (preg_match('/_(\d{4}-\d{2}-\d{2})_(\d{2})-(\d{2})-(\d{2})\.sql$/i', $file['filename'] ?? '', $m)) {
        $ts = strtotime("{$m[1]} {$m[2]}:{$m[3]}:{$m[4]}");
        if ($ts !== false) {
            return $ts;
        }
    }
    return (int)($file['mtime'] ?? 0);
}

/** Is this an automatic (weekly) backup? */
function secsysIsAutoBackup(string $filename): bool
{
    return stripos($filename, 'security_system_autobackup_') === 0;
}

/** Cut-off timestamp: anything created before this is older than the retention period. */
function secsysBackupRetentionCutoff(?int $now = null): int
{
    $now = $now ?? time();
    return (int)strtotime('-' . SECSYS_BACKUP_RETENTION_MONTHS . ' months', $now);
}

/**
 * Delete backups older than SECSYS_BACKUP_RETENTION_MONTHS.
 * The newest backup is always kept, even if it is older (never leave zero backups).
 * Returns the list of deleted file names.
 */
function secsysPurgeOldBackups(?mysqli $conn = null, ?string $targetDir = null, ?int $now = null): array
{
    $files = secsysListBackups($targetDir);
    if (count($files) <= 1) {
        return [];
    }

    usort($files, function ($a, $b) {
        return secsysBackupCreatedAt($b) <=> secsysBackupCreatedAt($a);
    });
    $newest = $files[0]['filename'];
    $cutoff = secsysBackupRetentionCutoff($now);

    $deleted = [];
    foreach ($files as $file) {
        if ($file['filename'] === $newest) {
            continue;
        }
        if (secsysBackupCreatedAt($file) < $cutoff && is_file($file['filepath'])) {
            if (@unlink($file['filepath'])) {
                $deleted[] = $file['filename'];
            }
        }
    }

    if ($conn && $deleted) {
        @secsysLogActivity(
            $conn,
            'SYSTEM',
            'Database Backup Cleanup',
            'Automatically deleted ' . count($deleted) . ' backup(s) older than '
                . SECSYS_BACKUP_RETENTION_MONTHS . ' months: ' . implode(', ', $deleted)
        );
    }

    return $deleted;
}

/** Last automatic backup time (0 = never), from system_settings or the newest autobackup file. */
function secsysLastAutoBackupAt(mysqli $conn): int
{
    $res = @$conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'auto_backup_last_at' LIMIT 1");
    if ($res && ($row = $res->fetch_assoc())) {
        $ts = strtotime((string)$row['setting_value']);
        if ($ts) {
            return $ts;
        }
    }
    foreach (secsysListBackups() as $file) {
        if (secsysIsAutoBackup($file['filename'])) {
            return secsysBackupCreatedAt($file);
        }
    }
    return 0;
}

/**
 * Create the weekly backup if one is due, then remove backups older than 6 months.
 * $force = true runs the backup now regardless of the schedule.
 * Safe to call often: only one request at a time can run it (file lock).
 */
function secsysRunAutoBackupIfDue(mysqli $conn, bool $force = false): array
{
    $result = ['ran' => false, 'success' => null, 'message' => 'Not due yet.', 'deleted' => []];

    $lastAt = secsysLastAutoBackupAt($conn);
    $due = $force || $lastAt === 0 || (time() - $lastAt) >= SECSYS_AUTO_BACKUP_INTERVAL_DAYS * 86400;

    $lockPath = secsysGetBackupDirectory() . DIRECTORY_SEPARATOR . '.auto_backup.lock';
    $lock = @fopen($lockPath, 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        if ($lock) {
            fclose($lock);
        }
        $result['message'] = 'Another automatic backup is already running.';
        return $result;
    }

    try {
        if ($due) {
            // re-check inside the lock (another request may have just finished one)
            $lastAt = $force ? 0 : secsysLastAutoBackupAt($conn);
            if ($force || $lastAt === 0 || (time() - $lastAt) >= SECSYS_AUTO_BACKUP_INTERVAL_DAYS * 86400) {
                $backup = secsysCreateDatabaseBackup($conn, null, 'autobackup');
                $result['ran'] = true;
                $result['success'] = !empty($backup['success']);

                if ($result['success']) {
                    secsysSetSetting($conn, 'auto_backup_last_at', date('Y-m-d H:i:s'));
                    secsysSetSetting($conn, 'auto_backup_last_file', (string)$backup['filename']);
                    secsysSetSetting($conn, 'auto_backup_last_status', 'success');
                    $result['message'] = 'Automatic backup created: ' . $backup['filename']
                        . ' (' . $backup['filesize_fmt'] . ', ' . $backup['tables_count'] . ' tables).';
                    $result['file'] = $backup['filename'];
                    @secsysLogActivity($conn, 'SYSTEM', 'Database Backup', 'Weekly automatic backup created: ' . $backup['filename']);
                } else {
                    // remember the failure but retry on a later request (do not move last_at)
                    secsysSetSetting($conn, 'auto_backup_last_status', 'failed: ' . substr((string)($backup['message'] ?? 'unknown error'), 0, 200));
                    $result['message'] = 'Automatic backup failed: ' . ($backup['message'] ?? 'unknown error');
                }
            }
        }

        // Retention clean-up (at most once a day, or right after a backup)
        $lastPurge = 0;
        $res = @$conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'backup_last_purge_at' LIMIT 1");
        if ($res && ($row = $res->fetch_assoc())) {
            $lastPurge = (int)strtotime((string)$row['setting_value']);
        }
        if ($result['ran'] || $force || (time() - $lastPurge) >= 86400) {
            $result['deleted'] = secsysPurgeOldBackups($conn);
            secsysSetSetting($conn, 'backup_last_purge_at', date('Y-m-d H:i:s'));
            secsysSetSetting($conn, 'backup_last_purge_count', (string)count($result['deleted']));
        }
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }

    return $result;
}
