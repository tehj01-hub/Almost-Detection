<?php
require_once __DIR__ . '/app/session_helper.php';
require_once __DIR__ . '/app/system_config.php';
require_once __DIR__ . '/app/backup_helper.php';
require_once __DIR__ . '/app/backup_merge.php';

secsysStartRoleSession('Administrator');

/* =========================================================
   LOGIN CHECK
========================================================= */
if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit();
}

$currentUsername = (string)$_SESSION['username'];
$conn = secsysConnectDb();
secsysEnsureSchema($conn);

$systemName = secsysGetSystemName($conn);
$systemIcon = secsysGetSystemIcon($conn);
$displayDate = date("l, F d, Y");

$flashSuccess = $_SESSION['flash_success'] ?? '';
$flashError   = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

if (!function_exists('e')) {
    function e($value)
    {
        return htmlspecialchars((string)($value ?? ""), ENT_QUOTES, "UTF-8");
    }
}

/* =========================================================
   HANDLE ACTIONS
========================================================= */
$backupDir = secsysGetBackupDirectory();

// 1. DIRECT DOWNLOAD ACTION (GET)
if (isset($_GET['action']) && $_GET['action'] === 'download') {
    $file = basename((string)($_GET['file'] ?? ''));
    $filePath = $backupDir . DIRECTORY_SEPARATOR . $file;

    if ($file !== '' && file_exists($filePath) && strtolower(pathinfo($filePath, PATHINFO_EXTENSION)) === 'sql') {
        header('Content-Description: File Transfer');
        header('Content-Type: application/sql');
        header('Content-Disposition: attachment; filename="' . $file . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($filePath));
        readfile($filePath);
        exit();
    } else {
        $_SESSION['flash_error'] = "The requested backup file was not found.";
        header("Location: backup.php");
        exit();
    }
}

/** Path of a stored backup file, or '' when the name is not a backup in the repository. */
function backupPathFor(string $backupDir, string $name): string
{
    $file = basename($name);
    $path = $backupDir . DIRECTORY_SEPARATOR . $file;
    return ($file !== '' && is_file($path) && strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'sql') ? $path : '';
}

/** Parsed + compared backup, cached per file for this admin session (the file is only read, never run). */
function backupComparisonFor(mysqli $conn, string $path): array
{
    $parsed = secsysParseBackupData((string)file_get_contents($path));
    return ['parsed' => $parsed, 'comparison' => secsysCompareBackupWithSystem($conn, $parsed)];
}

// 1B. VIEW A BACKUP (read-only JSON for the preview modal)
if (isset($_GET['action']) && in_array($_GET['action'], ['preview', 'preview_rows'], true)) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $path = backupPathFor($backupDir, (string)($_GET['file'] ?? ''));
    if ($path === '') {
        echo json_encode(['success' => false, 'message' => 'The backup file was not found.']);
        exit();
    }
    @set_time_limit(120);
    @ini_set('memory_limit', '512M');
    $data = backupComparisonFor($conn, $path);
    $comparison = $data['comparison'];

    if ($_GET['action'] === 'preview') {
        $tables = [];
        $totals = ['total' => 0, 'new' => 0, 'same' => 0, 'moved' => 0, 'different' => 0];
        foreach ($comparison as $name => $t) {
            if ($t['counts']['total'] === 0) {
                continue; // empty tables are not interesting to view
            }
            foreach ($totals as $k => $_) {
                $totals[$k] += $t['counts'][$k];
            }
            $tables[] = [
                'name'        => $name,
                'label'       => $t['label'],
                'counts'      => $t['counts'],
                'can_add'     => $t['can_add'],
                'can_restore' => !empty($t['can_restore']),
                'default_add' => $t['default_add'],
                'note'        => $t['note'],
                // Row numbers the viewer may tick: missing ones, and updated ones it can put back
                'new_rows'    => $t['can_add'] ? array_keys(array_filter($t['status'], function ($st) { return $st === 'new'; })) : [],
                'diff_rows'   => !empty($t['can_restore']) ? array_keys(array_filter($t['status'], function ($st) { return $st === 'different'; })) : [],
            ];
        }
        echo json_encode([
            'success'    => true,
            'file'       => basename($path),
            'size'       => secsysFormatBytes((int)filesize($path)),
            'created_at' => $data['parsed']['created_at'] !== '' ? date('M d, Y h:i A', (int)strtotime($data['parsed']['created_at'])) : date('M d, Y h:i A', (int)filemtime($path)),
            'totals'     => $totals,
            'tables'     => $tables,
        ], JSON_INVALID_UTF8_SUBSTITUTE);
        exit();
    }

    // Rows of one table (paged, filterable)
    $table = (string)($_GET['table'] ?? '');
    if (!isset($comparison[$table])) {
        echo json_encode(['success' => false, 'message' => 'That table is not in this backup.']);
        exit();
    }
    $t = $comparison[$table];
    $filter = (string)($_GET['filter'] ?? 'all');
    $q = strtolower(trim((string)($_GET['q'] ?? '')));
    $hidden = ['password']; // never send password hashes to the browser
    $columns = array_values(array_diff($t['columns'], $hidden));
    $matches = [];
    foreach ($t['rows'] as $i => $row) {
        $status = $t['status'][$i];
        if ($filter !== 'all' && $status !== $filter) {
            continue;
        }
        if ($q !== '') {
            $hay = strtolower(implode(' ', array_map('strval', array_diff_key($row, array_flip($hidden)))));
            if (strpos($hay, $q) === false) {
                continue;
            }
        }
        $matches[] = $i;
    }
    $perPage = 50;
    $pages = max(1, (int)ceil(count($matches) / $perPage));
    $page = min($pages, max(1, (int)($_GET['page'] ?? 1)));
    $rows = [];
    foreach (array_slice($matches, ($page - 1) * $perPage, $perPage) as $i) {
        $cells = [];
        foreach ($columns as $col) {
            $val = $t['rows'][$i][$col] ?? null;
            $cells[] = $val === null ? null : mb_strimwidth((string)$val, 0, 120, '…', 'UTF-8');
        }
        $st = $t['status'][$i];
        $rows[] = [
            'index'      => $i,
            'status'     => $st,
            'selectable' => ($st === 'new' && $t['can_add']) || ($st === 'different' && !empty($t['can_restore'])),
            'cells'      => $cells,
        ];
    }
    echo json_encode([
        'success' => true,
        'table'   => $table,
        'label'   => $t['label'],
        'columns' => $columns,
        'rows'    => $rows,
        'matches' => count($matches),
        'page'    => $page,
        'pages'   => $pages,
    ], JSON_INVALID_UTF8_SUBSTITUTE);
    exit();
}

// 2. POST ACTIONS
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    // ACTION: UPLOAD A BACKUP (saved to the repository, then opened for viewing; nothing is applied)
    if ($action === 'upload_backup') {
        if (!isset($_FILES['backup_upload']) || $_FILES['backup_upload']['error'] !== UPLOAD_ERR_OK) {
            $_SESSION['flash_error'] = "Please choose a valid .sql backup file to upload.";
            header("Location: backup.php");
            exit();
        }
        $uploaded = $_FILES['backup_upload'];
        if (strtolower(pathinfo($uploaded['name'], PATHINFO_EXTENSION)) !== 'sql') {
            $_SESSION['flash_error'] = "Invalid file type. Only .sql database backup files are supported.";
            header("Location: backup.php");
            exit();
        }
        $sqlContent = @file_get_contents($uploaded['tmp_name']);
        if ($sqlContent === false || trim($sqlContent) === '') {
            $_SESSION['flash_error'] = "The uploaded file is empty or could not be read.";
            header("Location: backup.php");
            exit();
        }
        if (empty(secsysParseBackupData($sqlContent)['tables'])) {
            $_SESSION['flash_error'] = "The uploaded file does not look like a database backup (no tables were found).";
            header("Location: backup.php");
            exit();
        }
        $savedFilename = 'security_system_imported_' . date('Y-m-d_H-i-s') . '_' . preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $uploaded['name']);
        if (strtolower(substr($savedFilename, -4)) !== '.sql') {
            $savedFilename .= '.sql';
        }
        @file_put_contents($backupDir . DIRECTORY_SEPARATOR . $savedFilename, $sqlContent);
        secsysLogActivity($conn, $currentUsername, 'Database Backup Upload', "Uploaded backup file {$uploaded['name']} (saved as {$savedFilename}) for viewing.");
        $_SESSION['flash_success'] = "Backup <strong>" . e($uploaded['name']) . "</strong> was uploaded. Review its data below, then choose what to add.";
        header("Location: backup.php?view=" . urlencode($savedFilename));
        exit();
    }

    // ACTION: ADD THE BACKUP'S MISSING RECORDS TO THE SYSTEM (insert only, nothing is overwritten)
    if ($action === 'merge_backup') {
        $file = basename((string)($_POST['backup_file'] ?? ''));
        $password = (string)($_POST['admin_password'] ?? '');
        $path = backupPathFor($backupDir, $file);
        // {"table": [row numbers in the backup], ...} picked in the viewer
        $selection = json_decode((string)($_POST['selection'] ?? ''), true);
        $selection = is_array($selection) ? array_filter($selection, 'is_array') : [];
        $chosenCount = array_sum(array_map('count', $selection));

        if (trim($password) === '' || !secsysVerifyAdminPassword($conn, $currentUsername, $password)) {
            $_SESSION['flash_error'] = "Authentication Failed: Incorrect administrator password. Nothing was added.";
            header("Location: backup.php?view=" . urlencode($file));
            exit();
        }
        if ($path === '') {
            $_SESSION['flash_error'] = "The selected backup file does not exist or is invalid.";
            header("Location: backup.php");
            exit();
        }
        if ($chosenCount === 0) {
            $_SESSION['flash_error'] = "Tick at least one record to retrieve.";
            header("Location: backup.php?view=" . urlencode($file));
            exit();
        }

        @set_time_limit(300);
        @ini_set('memory_limit', '512M');
        $data = backupComparisonFor($conn, $path);

        // Safety copy of the current data first, so the change can always be undone
        $safety = secsysCreateDatabaseBackup($conn, $backupDir, 'beforemerge');
        $merge = secsysMergeBackupIntoSystem($conn, $data['comparison'], $selection);

        if (!$merge['success']) {
            $_SESSION['flash_error'] = "Nothing was retrieved: " . e(implode('; ', $merge['errors']));
            header("Location: backup.php?view=" . urlencode($file));
            exit();
        }

        $describe = function (array $counts) use ($data) {
            $parts = [];
            foreach ($counts as $tbl => $n) {
                if ($n > 0) {
                    $parts[] = $n . ' ' . ($data['comparison'][$tbl]['label'] ?? $tbl);
                }
            }
            return implode(', ', $parts);
        };
        $addedText = $describe($merge['added']);
        $restoredText = $describe($merge['restored']);
        $skippedTotal = array_sum($merge['skipped']);
        $summary = trim(
            ($merge['total_added'] > 0 ? "added {$merge['total_added']} missing record(s) ({$addedText})" : '')
            . ($merge['total_added'] > 0 && $merge['total_restored'] > 0 ? '; ' : '')
            . ($merge['total_restored'] > 0 ? "put back the backup version of {$merge['total_restored']} record(s) ({$restoredText})" : '')
        );
        if ($summary === '') {
            $summary = 'nothing was changed';
        }
        secsysLogActivity(
            $conn,
            $currentUsername,
            'Database Merge',
            "Retrieved from backup {$file}: {$summary}."
            . ($skippedTotal > 0 ? " {$skippedTotal} could not be retrieved." : '')
            . (!empty($safety['success']) ? " Safety backup: {$safety['filename']}." : '')
        );
        $_SESSION['flash_success'] = "Retrieved from <strong>" . e($file) . "</strong>: " . e($summary) . ". Records you did not tick were not changed."
            . (!empty($safety['success']) ? " A safety backup of the data before retrieving was saved as <strong>" . e($safety['filename']) . "</strong>." : '');
        if ($skippedTotal > 0) {
            $_SESSION['flash_error'] = (int)$skippedTotal . " record(s) could not be retrieved: " . e(implode('; ', array_slice($merge['errors'], 0, 3)));
        }
        header("Location: backup.php");
        exit();
    }

    // ACTION: CREATE FULL BACKUP
    if ($action === 'create_backup') {
        $backupRes = secsysCreateDatabaseBackup($conn, $backupDir);

        if ($backupRes['success']) {
            secsysLogActivity(
                $conn,
                $currentUsername,
                'Database Backup',
                "Created full database backup: {$backupRes['filename']} ({$backupRes['filesize_fmt']}, {$backupRes['tables_count']} tables, {$backupRes['rows_count']} rows)."
            );

            // If user clicked "Create & Download"
            if (!empty($_POST['download_direct'])) {
                $filePath = $backupRes['filepath'];
                $fileName = $backupRes['filename'];
                header('Content-Description: File Transfer');
                header('Content-Type: application/sql');
                header('Content-Disposition: attachment; filename="' . $fileName . '"');
                header('Expires: 0');
                header('Cache-Control: must-revalidate');
                header('Pragma: public');
                header('Content-Length: ' . filesize($filePath));
                readfile($filePath);
                exit();
            }

            $_SESSION['flash_success'] = "Database backup created successfully! Saved as <strong>" . e($backupRes['filename']) . "</strong> (" . e($backupRes['filesize_fmt']) . ", " . (int)$backupRes['tables_count'] . " tables, " . (int)$backupRes['rows_count'] . " records).";
        } else {
            $_SESSION['flash_error'] = "Failed to create database backup: " . e($backupRes['message'] ?? 'Unknown error');
        }

        header("Location: backup.php");
        exit();
    }

    // ACTION: RETRIEVE / RESTORE FROM STORED BACKUP (REQUIRES ADMIN PASSWORD)
    if ($action === 'restore_backup') {
        $file = basename((string)($_POST['backup_file'] ?? ''));
        $password = (string)($_POST['admin_password'] ?? '');
        $filePath = $backupDir . DIRECTORY_SEPARATOR . $file;

        // Replacing everything is only done when the admin explicitly typed REPLACE
        if (strtoupper(trim((string)($_POST['confirm_replace'] ?? ''))) !== 'REPLACE') {
            $_SESSION['flash_error'] = "Nothing was replaced. To replace all current data with this backup, type REPLACE to confirm.";
            header("Location: backup.php?view=" . urlencode($file));
            exit();
        }

        // 1. Password Verification Requirement
        if (trim($password) === '') {
            $_SESSION['flash_error'] = "Authentication Failed: Administrator password is required to retrieve and restore data.";
            header("Location: backup.php");
            exit();
        }

        if (!secsysVerifyAdminPassword($conn, $currentUsername, $password)) {
            $_SESSION['flash_error'] = "Authentication Failed: Incorrect administrator password. Database retrieval and restoration was aborted for security.";
            header("Location: backup.php");
            exit();
        }

        // 2. Validate Backup File
        if ($file === '' || !file_exists($filePath) || strtolower(pathinfo($filePath, PATHINFO_EXTENSION)) !== 'sql') {
            $_SESSION['flash_error'] = "The selected backup file does not exist or is invalid.";
            header("Location: backup.php");
            exit();
        }

        $sqlContent = @file_get_contents($filePath);
        if ($sqlContent === false || trim($sqlContent) === '') {
            $_SESSION['flash_error'] = "Failed to read backup file contents or file is empty.";
            header("Location: backup.php");
            exit();
        }

        // 3. Execute Restore
        $restoreRes = secsysRestoreDatabaseSql($conn, $sqlContent);

        if ($restoreRes['success']) {
            secsysLogActivity(
                $conn,
                $currentUsername,
                'Database Restore',
                "Retrieved and restored database from backup: {$file} ({$restoreRes['executed_queries']} queries executed)."
            );
            $_SESSION['flash_success'] = "Database successfully retrieved and restored from <strong>" . e($file) . "</strong>! (" . (int)$restoreRes['executed_queries'] . " queries processed). All records have been restored.";
        } else {
            $_SESSION['flash_error'] = "Restore Warning: " . e($restoreRes['message'] ?? 'Errors occurred during restoration.');
        }

        header("Location: backup.php");
        exit();
    }

    // ACTION: DELETE STORED BACKUP
    if ($action === 'delete_backup') {
        $file = basename((string)($_POST['backup_file'] ?? ''));
        $filePath = $backupDir . DIRECTORY_SEPARATOR . $file;

        if ($file !== '' && file_exists($filePath) && strtolower(pathinfo($filePath, PATHINFO_EXTENSION)) === 'sql') {
            @unlink($filePath);
            secsysLogActivity($conn, $currentUsername, 'Database Backup Delete', "Deleted backup file: {$file}");
            $_SESSION['flash_success'] = "Backup file <strong>" . e($file) . "</strong> has been permanently removed.";
        } else {
            $_SESSION['flash_error'] = "Backup file not found.";
        }

        header("Location: backup.php");
        exit();
    }
}

/* =========================================================
   STATISTICS & METRICS
========================================================= */
$tablesRes = $conn->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
$totalTables = $tablesRes ? $tablesRes->num_rows : 0;

$totalRows = 0;
$rowCountsRes = $conn->query("
    SELECT SUM(TABLE_ROWS) AS total_records
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
");
if ($rowCountsRes && $rowRow = $rowCountsRes->fetch_assoc()) {
    $totalRows = (int)($rowRow['total_records'] ?? 0);
}

$backupsList = secsysListBackups($backupDir);
$totalBackups = count($backupsList);
$lastBackupDate = !empty($backupsList) ? $backupsList[0]['created_fmt'] : 'None yet';

/* Weekly automatic backup status (see secsysRunAutoBackupIfDue in app/backup_helper.php) */
$autoLastAt     = secsysLastAutoBackupAt($conn);
$autoNextAt     = $autoLastAt > 0 ? $autoLastAt + SECSYS_AUTO_BACKUP_INTERVAL_DAYS * 86400 : 0;
$autoLastStatus = secsysGetSetting($conn, 'auto_backup_last_status', '');
$purgeLastAt    = (int)strtotime(secsysGetSetting($conn, 'backup_last_purge_at', ''));
$purgeLastCount = (int)secsysGetSetting($conn, 'backup_last_purge_count', '0');
$autoBackupCount = count(array_filter($backupsList, function ($b) { return secsysIsAutoBackup($b['filename']); }));
$retentionCutoffFmt = date('M d, Y', secsysBackupRetentionCutoff());
?>
<!DOCTYPE html>
<html lang="en" class="ui-scale-fixed">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Database Backup &amp; Restore | <?php echo e($systemName); ?></title>
<?php if (!empty($systemIcon)): ?>
<link rel="icon" href="<?php echo e($systemIcon); ?>">
<?php endif; ?>

<style>
/* =========================================================
   RESET & LAYOUT (MATCHING APPLICATION THEME)
========================================================= */
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
    font-family: Arial, Helvetica, sans-serif;
}

body {
    background: #f7f7f7;
    color: #111;
    display: flex;
    min-height: 100vh;
}

/* =========================================================
   SIDEBAR
========================================================= */
.sidebar {
    position: fixed;
    top: 0;
    left: 0;
    width: 212px;
    height: 100vh;
    background: #ffffff;
    border-right: 1px solid #dddddd;
    padding: 32px 14px;
    z-index: 1000;
    display: flex;
    flex-direction: column;
    font-family: Arial, Helvetica, sans-serif;
    box-sizing: border-box;
}

.logo {
    display: flex;
    align-items: center;
    gap: 10px;
    padding-left: 3px;
    margin-bottom: 38px;
}

.logo-shield {
    width: 34px;
    height: 38px;
    background: #1478e8;
    color: #ffffff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: bold;
    font-size: 18px;
    clip-path: polygon(50% 0%, 90% 15%, 90% 58%, 75% 82%, 50% 100%, 25% 82%, 10% 58%, 10% 15%);
    flex-shrink: 0;
    overflow: hidden;
}

.logo-shield img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.logo-text {
    line-height: 17px;
}

.logo-title {
    font-size: 17px;
    font-weight: 500;
    white-space: nowrap;
    color: #111;
}

.logo-subtitle {
    font-size: 12px;
    color: #444;
}

.nav {
    display: flex;
    flex-direction: column;
    gap: 7px;
    width: 100%;
}

.nav a {
    width: 100%;
    min-height: 43px;
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 0 14px;
    border-radius: 22px;
    text-decoration: none;
    color: #111;
    font-size: 16px;
    transition: background 0.2s ease, color 0.2s ease;
    box-sizing: border-box;
}

.nav a:hover {
    background: #edf5ff;
}

.nav a.active {
    background: #2f80ed;
    color: white;
}

.nav-icon {
    width: 21px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    flex-shrink: 0;
}

.logout {
    margin-top: auto;
    width: 100%;
    min-height: 38px;
    border: 1px solid #dddddd;
    border-radius: 18px;
    background: white;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
    display: flex;
    align-items: center;
    justify-content: center;
    color: #111;
    text-decoration: none;
    font-size: 15px;
    transition: background 0.2s ease;
    box-sizing: border-box;
}

.logout:hover {
    background: #f3f3f3;
}

/* =========================================================
   MAIN CONTENT
========================================================= */
.main {
    margin-left: 212px;
    min-height: 100vh;
    padding: 32px 38px 45px;
    width: calc(100% - 212px);
    box-sizing: border-box;
}

.page-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    margin-bottom: 24px;
    flex-wrap: wrap;
    gap: 12px;
}

.page-header h1 {
    font-size: 24px;
    font-weight: 700;
    color: #0f172a;
    display: flex;
    align-items: center;
    gap: 10px;
}

.page-header p {
    font-size: 13px;
    color: #64748b;
    margin-top: 4px;
}

/* =========================================================
   ALERTS
========================================================= */
.alert {
    padding: 13px 18px;
    border-radius: 10px;
    font-size: 13.5px;
    line-height: 1.45;
    margin-bottom: 22px;
    display: flex;
    align-items: center;
    gap: 12px;
}
.alert-success {
    background: #dcfce7;
    color: #166534;
    border: 1px solid #bbf7d0;
}
.alert-error {
    background: #fee2e2;
    color: #991b1b;
    border: 1px solid #fecaca;
}

/* =========================================================
   METRIC CARDS
========================================================= */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-bottom: 24px;
}

@media (max-width: 1100px) {
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}
@media (max-width: 600px) {
    .stats-grid {
        grid-template-columns: 1fr;
    }
}

.stat-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 18px 20px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.03);
    display: flex;
    align-items: center;
    gap: 14px;
}

.stat-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
    flex-shrink: 0;
}

.stat-content {
    line-height: 1.25;
}

.stat-label {
    font-size: 12px;
    font-weight: 600;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 4px;
}

.stat-value {
    font-size: 22px;
    font-weight: 700;
    color: #0f172a;
}

/* =========================================================
   ACTION SECTION CARDS
========================================================= */
.action-grid {
    display: grid;
    grid-template-columns: 1.2fr 0.8fr;
    gap: 20px;
    margin-bottom: 26px;
}

@media (max-width: 1024px) {
    .action-grid {
        grid-template-columns: 1fr;
    }
}

.card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 22px 24px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.03);
}

.card-title {
    font-size: 16px;
    font-weight: 700;
    color: #0f172a;
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 8px;
}

.card-subtitle {
    font-size: 12.5px;
    color: #64748b;
    line-height: 1.45;
    margin-bottom: 18px;
}

.btn-primary {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 10px 18px;
    background: #0284c7;
    color: #ffffff;
    border: none;
    border-radius: 8px;
    font-size: 13.5px;
    font-weight: 700;
    cursor: pointer;
    text-decoration: none;
    box-shadow: 0 2px 4px rgba(2, 132, 199, 0.25);
    transition: all 0.15s ease;
}
.btn-primary:hover {
    background: #0369a1;
    box-shadow: 0 4px 8px rgba(2, 132, 199, 0.35);
    transform: translateY(-1px);
}
.btn-primary:active {
    transform: translateY(0);
}

.btn-secondary {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 10px 18px;
    background: #f1f5f9;
    color: #1e293b;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    font-size: 13.5px;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    transition: all 0.15s ease;
}
.btn-secondary:hover {
    background: #e2e8f0;
    border-color: #94a3b8;
}

.btn-warning {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 6px 12px;
    background: #fef3c7;
    color: #b45309;
    border: 1px solid #fde68a;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
    text-decoration: none;
    transition: all 0.15s ease;
}
.btn-warning:hover {
    background: #fde68a;
    color: #92400e;
}

.btn-danger-sm {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 6px 10px;
    background: #fee2e2;
    color: #b91c1c;
    border: 1px solid #fecaca;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.15s ease;
}
.btn-danger-sm:hover {
    background: #fecaca;
}

.btn-download-sm {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 6px 12px;
    background: #e0f2fe;
    color: #0369a1;
    border: 1px solid #bae6fd;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
    text-decoration: none;
    transition: all 0.15s ease;
}
.btn-download-sm:hover {
    background: #bae6fd;
}

/* =========================================================
   TABLE STYLING
========================================================= */
.table-container {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    overflow: hidden;
    box-shadow: 0 2px 4px rgba(0,0,0,0.03);
}

.table-header-bar {
    padding: 16px 22px;
    background: #ffffff;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.table-header-title {
    font-size: 15px;
    font-weight: 700;
    color: #0f172a;
    display: flex;
    align-items: center;
    gap: 8px;
}

.backup-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
    text-align: left;
}

.backup-table th {
    background: #f8fafc;
    color: #475569;
    font-weight: 700;
    padding: 12px 18px;
    border-bottom: 1px solid #e2e8f0;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.backup-table td {
    padding: 14px 18px;
    border-bottom: 1px solid #f1f5f9;
    color: #334155;
    vertical-align: middle;
}

.backup-table tr:hover {
    background: #f8fafc;
}

.badge-sql {
    display: inline-block;
    padding: 2px 8px;
    background: #eff6ff;
    color: #1d4ed8;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 700;
    font-family: monospace;
}

.empty-state {
    padding: 45px 20px;
    text-align: center;
    color: #64748b;
    font-size: 14px;
}
.empty-state span {
    font-size: 32px;
    display: block;
    margin-bottom: 10px;
}

/* =========================================================
   SECURITY RESTORE MODAL (PASSWORD REQUIRED)
========================================================= */
.modal-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(15, 23, 42, 0.65);
    backdrop-filter: blur(4px);
    z-index: 9999;
    align-items: center;
    justify-content: center;
    padding: 16px;
}

.modal-overlay.active {
    display: flex;
}

.modal-box {
    background: #ffffff;
    border-radius: 16px;
    max-width: 500px;
    width: 100%;
    box-shadow: 0 20px 40px rgba(0, 0, 0, 0.25);
    overflow: hidden;
    animation: modalIn 0.2s ease-out;
}

@keyframes modalIn {
    from { opacity: 0; transform: scale(0.95); }
    to { opacity: 1; transform: scale(1); }
}

.modal-header {
    padding: 16px 20px;
    background: #fef2f2;
    border-bottom: 1px solid #fee2e2;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.modal-title {
    font-size: 15.5px;
    font-weight: 700;
    color: #991b1b;
    display: flex;
    align-items: center;
    gap: 8px;
}

.modal-close {
    background: transparent;
    border: none;
    font-size: 22px;
    line-height: 1;
    color: #991b1b;
    cursor: pointer;
}

.modal-body {
    padding: 20px 22px;
}

/* ---------- View Backup modal ---------- */
.modal-box.bv-box {
    max-width: 1180px;
    max-height: calc(100vh - 32px);
    display: flex;
    flex-direction: column;
}
.bv-header { background: #eff6ff; border-bottom-color: #dbeafe; }
.bv-title { color: #1e3a8a; flex-wrap: wrap; min-width: 0; }
.bv-close { color: #1e3a8a; }
.bv-file { font-size: 12px; font-weight: 600; color: #475569; word-break: break-all; }
.bv-body { padding: 16px 20px; overflow-y: auto; }
.bv-loading, .bv-empty { text-align: center; color: #64748b; font-size: 13px; padding: 28px 10px; }
.bv-error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; border-radius: 8px; padding: 10px 12px; font-size: 13px; }
.bv-meta { display: flex; flex-wrap: wrap; gap: 6px 18px; font-size: 12.5px; color: #475569; margin-bottom: 10px; }
.bv-chips { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 14px; }
.bv-chip { font-size: 12px; padding: 4px 10px; border-radius: 999px; border: 1px solid; }
.bv-chip.bv-new, .bv-badge-new { background: #dcfce7; color: #166534; border-color: #86efac; }
.bv-chip.bv-same, .bv-badge-same { background: #f1f5f9; color: #475569; border-color: #cbd5e1; }
.bv-chip.bv-moved, .bv-badge-moved { background: #e0f2fe; color: #075985; border-color: #7dd3fc; }
.bv-chip.bv-different, .bv-badge-different { background: #fef3c7; color: #92400e; border-color: #fcd34d; }
.bv-main { display: grid; grid-template-columns: 280px minmax(0, 1fr); gap: 14px; }
.bv-section-label { font-size: 12px; font-weight: 700; color: #334155; text-transform: uppercase; letter-spacing: .3px; margin-bottom: 8px; }
.bv-tables { max-height: 440px; overflow-y: auto; padding-right: 2px; }
.bv-table-item { display: flex; gap: 9px; align-items: flex-start; padding: 8px 10px; border: 1px solid #e2e8f0; border-radius: 8px; margin-bottom: 6px; cursor: pointer; background: #fff; }
.bv-table-item:hover { border-color: #93c5fd; }
.bv-table-item.active { border-color: #2563eb; background: #eff6ff; box-shadow: 0 0 0 2px rgba(37, 99, 235, .12); }
.bv-table-item:focus-visible { outline: 2px solid #2563eb; outline-offset: 2px; }
.bv-check { margin-top: 2px; width: 16px; height: 16px; accent-color: #16a34a; flex-shrink: 0; }
.bv-check-placeholder { width: 16px; text-align: center; color: #cbd5e1; flex-shrink: 0; }
.bv-table-text { min-width: 0; }
.bv-table-name { font-size: 13px; font-weight: 700; color: #0f172a; }
.bv-table-counts { font-size: 11.5px; color: #64748b; margin-top: 2px; }
.bv-table-note { font-size: 11px; color: #94a3b8; margin-top: 2px; }
.bv-txt-new { color: #15803d; font-weight: 700; }
.bv-txt-same { color: #94a3b8; }
.bv-txt-diff { color: #b45309; font-weight: 700; }
.bv-sel-count { font-size: 11px; font-weight: 600; color: #15803d; }
.bv-col-check { width: 30px; text-align: center !important; }
.bv-col-check input { width: 15px; height: 15px; accent-color: #16a34a; cursor: pointer; }
.bv-grid tr.bv-row-different td { background: #fffbeb; }
.bv-nothing { margin-top: 16px; padding: 12px 14px; border: 1px solid #bbf7d0; background: #f0fdf4; border-radius: 10px; font-size: 13px; color: #14532d; }
.bv-rows { min-width: 0; display: flex; flex-direction: column; }
.bv-rows-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 8px; flex-wrap: wrap; }
.bv-rows-tools { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 8px; }
.bv-rows-tools select, .bv-rows-tools input, .bv-input {
    font-size: 12.5px; padding: 7px 9px; border: 1px solid #cbd5e1; border-radius: 7px; background: #fff;
}
.bv-grid-wrap { border: 1px solid #e2e8f0; border-radius: 8px; overflow: auto; max-height: 400px; }
.bv-grid { width: 100%; border-collapse: collapse; font-size: 12px; }
.bv-grid th { position: sticky; top: 0; background: #f8fafc; text-align: left; padding: 7px 9px; color: #334155; text-transform: capitalize; white-space: nowrap; border-bottom: 1px solid #e2e8f0; z-index: 1; }
.bv-grid td { padding: 6px 9px; border-bottom: 1px solid #f1f5f9; white-space: nowrap; max-width: 260px; overflow: hidden; text-overflow: ellipsis; color: #1e293b; }
.bv-grid tr.bv-row-new td { background: #f0fdf4; }
.bv-null { color: #cbd5e1; }
.bv-badge { display: inline-block; font-size: 10.5px; font-weight: 700; padding: 2px 7px; border-radius: 999px; border: 1px solid; }
.bv-pager { display: flex; align-items: center; justify-content: flex-end; gap: 8px; font-size: 12px; color: #475569; margin-top: 8px; }
.bv-pager:empty { display: none; }
.bv-add { margin-top: 16px; padding: 12px 14px; border: 1px solid #bbf7d0; background: #f0fdf4; border-radius: 10px; display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 10px; }
.bv-add-text { display: flex; flex-direction: column; gap: 2px; font-size: 12.5px; color: #14532d; max-width: 560px; }
.bv-add-controls { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
.bv-pwd { width: 230px; }
.bv-add-btn:disabled { opacity: .55; cursor: not-allowed; }
.bv-replace { margin-top: 12px; border: 1px solid #fecaca; border-radius: 10px; padding: 10px 14px; background: #fff; }
.bv-replace summary { cursor: pointer; font-size: 12.5px; font-weight: 700; color: #b91c1c; }
.bv-replace p { font-size: 12.5px; color: #7f1d1d; margin: 8px 0 10px; }

@media (max-width: 860px) {
    .bv-main { grid-template-columns: 1fr; }
    .bv-tables { max-height: 220px; }
    .bv-pwd { width: 100%; }
}

.warning-callout {
    background: #fffbeb;
    border: 1px solid #fde68a;
    border-left: 4px solid #f59e0b;
    border-radius: 8px;
    padding: 12px 14px;
    font-size: 12.5px;
    color: #78350f;
    line-height: 1.45;
    margin-bottom: 18px;
}

.form-group {
    margin-bottom: 16px;
}

.form-group label {
    display: block;
    font-size: 12.5px;
    font-weight: 700;
    color: #1e293b;
    margin-bottom: 6px;
}

.input-password-wrapper {
    position: relative;
    display: flex;
    align-items: center;
}

.input-password-wrapper input {
    width: 100%;
    padding: 10px 40px 10px 12px;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    font-size: 13.5px;
    background: #ffffff;
    outline: none;
    transition: border-color 0.15s ease;
}

.input-password-wrapper input:focus {
    border-color: #0284c7;
    box-shadow: 0 0 0 3px rgba(2, 132, 199, 0.15);
}

.toggle-pwd-btn {
    position: absolute;
    right: 10px;
    background: none;
    border: none;
    cursor: pointer;
    font-size: 16px;
    color: #64748b;
}

.modal-footer {
    padding: 14px 22px;
    background: #f8fafc;
    border-top: 1px solid #e2e8f0;
    display: flex;
    justify-content: flex-end;
    gap: 10px;
}

.btn-restore-confirm {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 9px 18px;
    background: #dc2626;
    color: #ffffff;
    border: none;
    border-radius: 7px;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    box-shadow: 0 2px 4px rgba(220, 38, 38, 0.25);
    transition: all 0.15s ease;
}
.btn-restore-confirm:hover {
    background: #b91c1c;
}

/* ===== Automatic weekly backup panel ===== */
.auto-backup-card { margin-bottom: 18px; }
.auto-backup-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 12px; }
.auto-backup-title { font-size: 15px; font-weight: 700; color: #0f172a; }
.auto-badge { font-size: 11px; font-weight: 700; padding: 3px 10px; border-radius: 9999px; }
.auto-badge-ok { background: #dcfce7; color: #15803d; border: 1px solid #86efac; }
.auto-badge-bad { background: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5; }
.auto-backup-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 14px; }
.auto-label { font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.4px; margin-bottom: 4px; }
.auto-value { font-size: 13px; font-weight: 600; color: #1e293b; }
.auto-sub { font-size: 11px; font-weight: 500; color: #64748b; }
.auto-note { margin-top: 12px; font-size: 12px; color: #64748b; }
.backup-row-meta { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 4px; }
.backup-kind { font-size: 10.5px; font-weight: 700; padding: 1px 8px; border-radius: 9999px; }
.backup-kind-auto { background: #e0f2fe; color: #0369a1; border: 1px solid #7dd3fc; }
.backup-kind-manual { background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; }
.backup-expiry { font-size: 10.5px; color: #94a3b8; }
@media (max-width: 1100px) { .auto-backup-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
</style>
<!-- Same look as the Security Guard dashboard for every Administrator page -->
<link rel="stylesheet" href="assets/css/admin_uniform.css?v=<?php echo (int)@filemtime(__DIR__ . '/assets/css/admin_uniform.css'); ?>">
<!-- Enlarges the page 25% (logs +25% more) on laptops/desktops for easier reading (loaded after the page styles) -->
<link rel="stylesheet" href="assets/css/ui_scale.css?v=<?php echo (int)@filemtime(__DIR__ . '/assets/css/ui_scale.css'); ?>">

</head>
<body>

<!-- =====================================================
     SIDEBAR
====================================================== -->
<aside class="sidebar">

    <div class="logo">
        <div class="logo-shield">
            <?php if (!empty($systemIcon)): ?>
                <img src="<?php echo e($systemIcon); ?>" alt="Icon">
            <?php else: ?>
                ✓
            <?php endif; ?>
        </div>
        <div class="logo-text">
            <div class="logo-title">
                <?php echo e($systemName); ?>
            </div>
            <div class="logo-subtitle"><?php echo secsysAdminSidebarSubtitleHtml(); ?></div>
        </div>
    </div>

    <nav class="nav">
        <a href="superadmin_dashboard.php">
            <span class="nav-icon">🖥️</span>
            <span>Dashboard</span>
        </a>

        <a href="logs.php">
            <span class="nav-icon">📋</span>
            <span>Logs</span>
        </a>

        <a href="registered.php">
            <span class="nav-icon">🚗</span>
            <span>Registered</span>
        </a>

        <a href="visitor.php">
            <span class="nav-icon">👥</span>
            <span>Visitor</span>
        </a>

        <a href="security_guard.php">
            <span class="nav-icon">🛡️</span>
            <span>Security Guard</span>
        </a>

        <a href="security_information.php">
            <span class="nav-icon">📑</span>
            <span>Security Information</span>
        </a>

        <a href="report.php">
            <span class="nav-icon">📈</span>
            <span>Report</span>
        </a>

        <a href="backup.php" class="active">
            <span class="nav-icon">💾</span>
            <span>Backup &amp; Retrieve</span>
        </a>

        <a href="profile.php">
            <span class="nav-icon">⚙️</span>
            <span>Profile</span>
        </a>
    </nav>

    <a href="logout.php?role=Administrator" class="logout">
        <span class="nav-icon">🚪</span>
        <span>Logout</span>
    </a>

</aside>

<!-- =====================================================
     MAIN
====================================================== -->
<main class="main">

    <div class="page-header">
        <div>
            <h1>💾 Database Backup &amp; Retrieve Module</h1>
            <p><?php echo e($displayDate); ?> &bull; Automated full-system data backup and password-protected retrieval.</p>
        </div>
    </div>

    <!-- FLASH MESSAGES -->
    <?php if ($flashSuccess !== ''): ?>
        <div class="alert alert-success">
            <span style="font-size: 18px;">✅</span>
            <div><?php echo $flashSuccess; ?></div>
        </div>
    <?php endif; ?>

    <?php if ($flashError !== ''): ?>
        <div class="alert alert-error">
            <span style="font-size: 18px;">❌</span>
            <div><?php echo $flashError; ?></div>
        </div>
    <?php endif; ?>

    <!-- SYSTEM METRIC TILES -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon" style="background: #e0f2fe; color: #0284c7;">🗄️</div>
            <div class="stat-content">
                <div class="stat-label">Total Tables</div>
                <div class="stat-value"><?php echo (int)$totalTables; ?></div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon" style="background: #dcfce7; color: #16a34a;">📊</div>
            <div class="stat-content">
                <div class="stat-label">Total Records</div>
                <div class="stat-value"><?php echo number_format($totalRows); ?></div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon" style="background: #fef3c7; color: #d97706;">💾</div>
            <div class="stat-content">
                <div class="stat-label">Stored Backups</div>
                <div class="stat-value"><?php echo (int)$totalBackups; ?></div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon" style="background: #f3e8ff; color: #9333ea;">🕒</div>
            <div class="stat-content">
                <div class="stat-label">Last Backup</div>
                <div class="stat-value" style="font-size: 13px; font-weight: 600; color: #475569; margin-top: 5px;">
                    <?php echo e($lastBackupDate); ?>
                </div>
            </div>
        </div>
    </div>

    <!-- AUTOMATIC WEEKLY BACKUP STATUS -->
    <div class="card auto-backup-card">
        <div class="auto-backup-head">
            <span class="auto-backup-title">🔁 Automatic Weekly Backup</span>
            <?php if (str_starts_with($autoLastStatus, 'failed')): ?>
                <span class="auto-badge auto-badge-bad" title="<?php echo e($autoLastStatus); ?>">Last attempt failed - will retry</span>
            <?php else: ?>
                <span class="auto-badge auto-badge-ok">Active</span>
            <?php endif; ?>
        </div>
        <div class="auto-backup-grid">
            <div>
                <div class="auto-label">Last automatic backup</div>
                <div class="auto-value"><?php echo $autoLastAt > 0 ? e(date('M d, Y - h:i A', $autoLastAt)) : 'Not yet (runs on the next page load)'; ?></div>
            </div>
            <div>
                <div class="auto-label">Next automatic backup</div>
                <div class="auto-value"><?php echo $autoNextAt > 0 ? e(date('M d, Y', $autoNextAt)) . ' <span class="auto-sub">(every ' . SECSYS_AUTO_BACKUP_INTERVAL_DAYS . ' days)</span>' : 'Due now'; ?></div>
            </div>
            <div>
                <div class="auto-label">Auto-delete</div>
                <div class="auto-value">Backups older than <?php echo (int)SECSYS_BACKUP_RETENTION_MONTHS; ?> months <span class="auto-sub">(before <?php echo e($retentionCutoffFmt); ?>)</span></div>
            </div>
            <div>
                <div class="auto-label">Last clean-up</div>
                <div class="auto-value"><?php echo $purgeLastAt > 0 ? e(date('M d, Y - h:i A', $purgeLastAt)) . ' <span class="auto-sub">(' . $purgeLastCount . ' deleted)</span>' : 'Not yet'; ?></div>
            </div>
        </div>
        <div class="auto-note">
            Runs by itself once a week while the system is in use, and keeps the newest backup even if it is older than <?php echo (int)SECSYS_BACKUP_RETENTION_MONTHS; ?> months.
            <?php echo (int)$autoBackupCount; ?> automatic backup(s) currently stored.
        </div>
    </div>

    <!-- ACTION CARDS: CREATE BACKUP & UPLOAD RESTORE -->
    <div class="action-grid">

        <!-- CARD 1: CREATE BACKUP -->
        <div class="card">
            <div class="card-title">
                <span>💾 Create Full Database Backup</span>
            </div>
            <div class="card-subtitle">
                Safeguards all vehicle logs, registered vehicles, visitors, duty shifts, guard assignments, and configuration tables into an atomic SQL archive file.
            </div>

            <form method="POST" action="backup.php" style="display: flex; gap: 10px; flex-wrap: wrap;">
                <input type="hidden" name="action" value="create_backup">

                <button type="submit" class="btn-primary" name="save_repo" value="1">
                    <span>💾 Save to Repository</span>
                </button>

            </form>
        </div>

        <!-- CARD 2: UPLOAD & RETRIEVE -->
        <div class="card">
            <div class="card-title">
                <span>🔄 Retrieve From External Backup</span>
            </div>
            <div class="card-subtitle">
                Upload a <code>.sql</code> backup file to view its data first. Nothing is applied until you choose which missing records to add (administrator password required).
            </div>

            <form id="uploadRestoreForm" method="POST" action="backup.php" enctype="multipart/form-data">
                <input type="hidden" name="action" value="upload_backup">

                <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                    <input
                        type="file"
                        name="backup_upload"
                        id="backupUploadInput"
                        accept=".sql"
                        required
                        style="font-size: 12.5px; border: 1px solid #cbd5e1; padding: 7px 10px; border-radius: 7px; background: #f8fafc; flex: 1;"
                    >
                    <button type="submit" class="btn-warning" style="padding: 9px 14px; font-size: 13px;">
                        <span>🔍 Upload &amp; View</span>
                    </button>
                </div>
            </form>
        </div>

    </div>

    <!-- REPOSITORY TABLE: STORED BACKUPS -->
    <div class="table-container">
        <div class="table-header-bar">
            <div class="table-header-title">
                <span>🗄️ Available Database Backups Repository</span>
                <span style="font-size: 11px; background: #e0f2fe; color: #0369a1; padding: 2px 8px; border-radius: 10px; font-weight: 700;">
                    <?php echo count($backupsList); ?> Saved
                </span>
            </div>
            <div style="font-size: 12px; color: #64748b;">
                Directory: <code>/backups/</code> (Protected by .htaccess)
            </div>
        </div>

        <?php if (!empty($backupsList)): ?>
            <table class="backup-table">
                <thead>
                    <tr>
                        <th>Backup Filename</th>
                        <th>Created Date &amp; Time</th>
                        <th>File Size</th>
                        <th>Format</th>
                        <th style="text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($backupsList as $b): ?>
                        <tr>
                            <td>
                                <strong style="color: #0f172a;"><?php echo e($b['filename']); ?></strong>
                                <div class="backup-row-meta">
                                    <?php if (secsysIsAutoBackup($b['filename'])): ?>
                                        <span class="backup-kind backup-kind-auto">🔁 Auto (weekly)</span>
                                    <?php else: ?>
                                        <span class="backup-kind backup-kind-manual">👤 Manual</span>
                                    <?php endif; ?>
                                    <span class="backup-expiry" title="Backups are deleted automatically after <?php echo (int)SECSYS_BACKUP_RETENTION_MONTHS; ?> months">
                                        Auto-deletes <?php echo e(date('M d, Y', (int)strtotime('+' . SECSYS_BACKUP_RETENTION_MONTHS . ' months', secsysBackupCreatedAt($b)))); ?>
                                    </span>
                                </div>
                            </td>
                            <td>
                                <?php echo e($b['created_fmt']); ?>
                            </td>
                            <td>
                                <span style="font-weight: 600;"><?php echo e($b['filesize_fmt']); ?></span>
                            </td>
                            <td>
                                <span class="badge-sql">SQL</span>
                            </td>
                            <td style="text-align: right; white-space: nowrap;">
                                <!-- Download -->
                                <a href="backup.php?action=download&file=<?php echo urlencode($b['filename']); ?>" class="btn-download-sm" title="Download SQL backup to your computer">
                                    📥 Download
                                </a>

                                <!-- Retrieve: view the backup's data, then choose what to add -->
                                <button
                                    type="button"
                                    class="btn-warning"
                                    onclick="openBackupViewer(<?php echo e(json_encode($b['filename'])); ?>)"
                                    title="View this backup's data and add missing records to the system"
                                >
                                    🔄 Retrieve
                                </button>

                                <!-- Delete -->
                                <form method="POST" action="backup.php" style="display: inline-block;" onsubmit="return confirm('Are you sure you want to permanently delete this backup file?');">
                                    <input type="hidden" name="action" value="delete_backup">
                                    <input type="hidden" name="backup_file" value="<?php echo e($b['filename']); ?>">
                                    <button type="submit" class="btn-danger-sm" title="Delete backup file">
                                        🗑️
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="empty-state">
                <span>🗄️</span>
                <strong>No database backups found in the repository.</strong>
                <p style="margin-top: 6px; font-size: 12.5px;">Click <strong>"Save to Repository"</strong> above to generate your first backup.</p>
            </div>
        <?php endif; ?>
    </div>

</main>

<!-- =========================================================
     VIEW BACKUP MODAL: look at the data first, then choose to add
     the missing records to the system (nothing is overwritten)
========================================================= -->
<div class="modal-overlay" id="backupViewerModal" role="dialog" aria-modal="true" aria-labelledby="bvTitle">
    <div class="modal-box bv-box">
        <div class="modal-header bv-header">
            <div class="modal-title bv-title" id="bvTitle">
                <span>🗂️ View Backup</span>
                <span class="bv-file" id="bvFileName"></span>
            </div>
            <button type="button" class="modal-close bv-close" onclick="closeBackupViewer()" aria-label="Close">&times;</button>
        </div>

        <div class="bv-body">
            <?php if ($flashError !== '' && isset($_GET['view'])): ?>
                <div class="bv-error" style="margin-bottom:12px;" role="alert">❌ <?php echo $flashError; ?></div>
            <?php endif; ?>
            <div class="bv-loading" id="bvLoading">Reading backup… (the backup is only read, nothing is changed)</div>
            <div class="bv-error" id="bvError" hidden></div>

            <div id="bvContent" hidden>
                <div class="bv-meta">
                    <span>📅 Backup made: <strong id="bvCreated"></strong></span>
                    <span>💾 Size: <strong id="bvSize"></strong></span>
                </div>
                <div class="bv-chips">
                    <span class="bv-chip bv-new"><strong id="bvTotNew">0</strong> not in the system</span>
                    <span class="bv-chip bv-same"><strong id="bvTotSame">0</strong> already in the system</span>
                    <span class="bv-chip bv-moved"><strong id="bvTotMoved">0</strong> archived / unarchived since</span>
                    <span class="bv-chip bv-different"><strong id="bvTotDifferent">0</strong> updated since the backup</span>
                </div>

                <div class="bv-main">
                    <div class="bv-tables">
                        <div class="bv-section-label">Data in this backup</div>
                        <div id="bvTableList"></div>
                    </div>

                    <div class="bv-rows">
                        <div class="bv-rows-head">
                            <div class="bv-section-label" id="bvRowsTitle">Select a table to view its records</div>
                            <div class="bv-rows-tools">
                                <select id="bvFilter" onchange="loadBackupRows(1)" aria-label="Show">
                                    <option value="all">All records</option>
                                    <option value="new">Not in the system</option>
                                    <option value="same">Already in the system</option>
                                    <option value="moved">Archived / unarchived since</option>
                                    <option value="different">Updated since the backup (can be put back)</option>
                                </select>
                                <input type="search" id="bvSearch" placeholder="Search plate, name…" aria-label="Search records">
                            </div>
                        </div>
                        <div class="bv-grid-wrap" id="bvGridWrap"><div class="bv-empty">Pick a table on the left.</div></div>
                        <div class="bv-pager" id="bvPager"></div>
                    </div>
                </div>

                <div class="bv-nothing" id="bvNothing" hidden>
                    ✅ <strong>Everything in this backup is already in the system.</strong>
                    There are no missing records to retrieve. Records marked <em>In system</em> or <em>Archived / unarchived</em> are already there.
                </div>

                <form method="POST" action="backup.php" class="bv-add" id="bvAddForm" onsubmit="return submitBackupMerge(event)">
                    <input type="hidden" name="action" value="merge_backup">
                    <input type="hidden" name="backup_file" id="bvAddFile" value="">
                    <input type="hidden" name="selection" id="bvSelection" value="">
                    <div class="bv-add-text">
                        <strong>📥 Retrieve to System</strong>
                        <span id="bvSelSummary">Tick the records to retrieve.</span>
                        <span>Ticked records <em>not in the system</em> are added; ticked records <em>updated since</em> the backup are put back to the backup's version. Records you don't tick are not changed, and a safety backup is saved first.</span>
                    </div>
                    <div class="bv-add-controls">
                        <div class="input-password-wrapper bv-pwd">
                            <input type="password" name="admin_password" id="bvAddPassword" placeholder="Administrator password" autocomplete="current-password">
                            <button type="button" class="toggle-pwd-btn" onclick="togglePwd('bvAddPassword')" title="Show/Hide Password">👁️</button>
                        </div>
                        <button type="submit" class="btn-primary bv-add-btn" id="bvAddBtn" disabled>📥 Retrieve 0 Records</button>
                    </div>
                </form>

                <details class="bv-replace">
                    <summary>Advanced: replace ALL current data with this backup</summary>
                    <form method="POST" action="backup.php" onsubmit="return confirmBackupReplace(event)">
                        <input type="hidden" name="action" value="restore_backup">
                        <input type="hidden" name="backup_file" id="bvReplaceFile" value="">
                        <p>⚠️ This deletes everything recorded after the backup was made (logs, visitors, guards, accounts) and puts the backup back exactly as it was. Use it only to recover from a damaged database.</p>
                        <div class="bv-add-controls">
                            <input type="text" name="confirm_replace" id="bvReplaceConfirm" placeholder="Type REPLACE" autocomplete="off" class="bv-input">
                            <div class="input-password-wrapper bv-pwd">
                                <input type="password" name="admin_password" id="bvReplacePassword" placeholder="Administrator password" autocomplete="current-password">
                                <button type="button" class="toggle-pwd-btn" onclick="togglePwd('bvReplacePassword')" title="Show/Hide Password">👁️</button>
                            </div>
                            <button type="submit" class="btn-restore-confirm">Replace All Data</button>
                        </div>
                    </form>
                </details>
            </div>
        </div>
    </div>
</div>

<script>
let bvState = { file: "", tables: [], table: "", page: 1, searchTimer: null };

const BV_STATUS_TEXT = {
    "new": "Not in system",
    "same": "In system",
    "moved": "Archived / unarchived",
    "different": "Updated since backup"
};

function bvEsc(s) {
    const d = document.createElement("div");
    d.textContent = s == null ? "" : String(s);
    return d.innerHTML;
}

function togglePwd(id) {
    const el = document.getElementById(id);
    if (el) el.type = el.type === "password" ? "text" : "password";
}

async function openBackupViewer(filename) {
    const modal = document.getElementById("backupViewerModal");
    if (!modal) return;
    bvState = { file: filename, tables: [], table: "", page: 1, searchTimer: null };
    document.getElementById("bvFileName").textContent = filename;
    document.getElementById("bvAddFile").value = filename;
    document.getElementById("bvReplaceFile").value = filename;
    document.getElementById("bvAddPassword").value = "";
    document.getElementById("bvReplacePassword").value = "";
    document.getElementById("bvReplaceConfirm").value = "";
    document.getElementById("bvSearch").value = "";
    document.getElementById("bvFilter").value = "all";
    document.getElementById("bvLoading").hidden = false;
    document.getElementById("bvError").hidden = true;
    document.getElementById("bvContent").hidden = true;
    modal.classList.add("active");

    try {
        const res = await fetch("backup.php?action=preview&file=" + encodeURIComponent(filename), { credentials: "same-origin", cache: "no-store" });
        const data = await res.json();
        if (!data || !data.success) throw new Error((data && data.message) || "Could not read this backup.");
        bvState.tables = data.tables;
        document.getElementById("bvCreated").textContent = data.created_at;
        document.getElementById("bvSize").textContent = data.size;
        ["new", "same", "moved", "different"].forEach(function (k) {
            document.getElementById("bvTot" + k.charAt(0).toUpperCase() + k.slice(1)).textContent = data.totals[k];
        });
        renderBackupTableList();
        document.getElementById("bvLoading").hidden = true;
        document.getElementById("bvContent").hidden = false;
        const first = data.tables.find(function (t) { return t.counts["new"] > 0; }) || data.tables[0];
        if (first) selectBackupTable(first.name);
    } catch (e) {
        document.getElementById("bvLoading").hidden = true;
        const err = document.getElementById("bvError");
        err.textContent = e.message || "Could not read this backup.";
        err.hidden = false;
    }
}

function renderBackupTableList() {
    const list = document.getElementById("bvTableList");
    // Start with the missing records of the usual tables ticked (accounts are opt-in)
    bvState.sel = {};
    bvState.tables.forEach(function (t) {
        bvState.sel[t.name] = new Set(t.default_add ? t.new_rows : []);
    });
    list.innerHTML = bvState.tables.map(function (t) {
        const c = t.counts;
        const selectable = t.new_rows.length + t.diff_rows.length > 0;
        const check = selectable
            ? '<input type="checkbox" class="bv-check" data-table="' + bvEsc(t.name) + '"' +
              ' onclick="event.stopPropagation()" onchange="toggleBackupTable(this)" aria-label="Retrieve ' + bvEsc(t.label) + '">'
            : '<span class="bv-check-placeholder" title="' + bvEsc(t.note || "Everything here is already in the system") + '">–</span>';
        const parts = [];
        if (c["new"]) parts.push('<span class="bv-txt-new">' + c["new"] + ' not in system</span>');
        if (c.different) parts.push('<span class="bv-txt-diff">' + c.different + ' updated since</span>');
        if (!parts.length) parts.push('<span class="bv-txt-same">already in system</span>');
        return '<div class="bv-table-item" data-table="' + bvEsc(t.name) + '" role="button" tabindex="0" onclick="selectBackupTable(\'' + bvEsc(t.name) + '\')" onkeydown="if(event.key===\'Enter\')selectBackupTable(\'' + bvEsc(t.name) + '\')">' +
            check +
            '<div class="bv-table-text">' +
                '<div class="bv-table-name">' + bvEsc(t.label) + ' <span class="bv-sel-count" data-sel-count="' + bvEsc(t.name) + '"></span></div>' +
                '<div class="bv-table-counts">' + c.total + (c.total === 1 ? ' record' : ' records') + ' · ' + parts.join(' · ') + '</div>' +
                (t.note ? '<div class="bv-table-note">' + bvEsc(t.note) + '</div>' : '') +
            '</div>' +
        '</div>';
    }).join("");

    const anything = bvState.tables.some(function (t) { return t.new_rows.length + t.diff_rows.length > 0; });
    document.getElementById("bvNothing").hidden = anything;
    document.getElementById("bvAddForm").hidden = !anything;
    updateBackupAddButton();
}

function tableInfo(name) {
    return bvState.tables.find(function (t) { return t.name === name; });
}

// Table checkbox: tick = all its missing records (updated ones only when the table has nothing missing)
function toggleBackupTable(box) {
    const t = tableInfo(box.getAttribute("data-table"));
    if (!t) return;
    const set = bvState.sel[t.name];
    if (box.checked) {
        (t.new_rows.length ? t.new_rows : t.diff_rows).forEach(function (i) { set.add(i); });
    } else {
        set.clear();
    }
    updateBackupAddButton();
}

function toggleBackupRow(box) {
    const set = bvState.sel[bvState.table];
    const i = Number(box.value);
    if (box.checked) set.add(i); else set.delete(i);
    updateBackupAddButton();
}

function syncRowChecks() {
    const set = bvState.sel[bvState.table] || new Set();
    document.querySelectorAll("#bvGridWrap .bv-row-check").forEach(function (b) {
        b.checked = set.has(Number(b.value));
    });
    const all = document.getElementById("bvRowCheckAll");
    if (all) {
        const boxes = document.querySelectorAll("#bvGridWrap .bv-row-check");
        const ticked = document.querySelectorAll("#bvGridWrap .bv-row-check:checked").length;
        all.checked = boxes.length > 0 && ticked === boxes.length;
        all.indeterminate = ticked > 0 && ticked < boxes.length;
    }
}

function toggleBackupPageRows(box) {
    const set = bvState.sel[bvState.table];
    document.querySelectorAll("#bvGridWrap .bv-row-check").forEach(function (b) {
        if (box.checked) set.add(Number(b.value)); else set.delete(Number(b.value));
    });
    updateBackupAddButton();
}

function updateBackupAddButton() {
    let adds = 0, restores = 0;
    bvState.tables.forEach(function (t) {
        const set = bvState.sel[t.name] || new Set();
        const diff = new Set(t.diff_rows);
        set.forEach(function (i) { if (diff.has(i)) restores++; else adds++; });
        const box = document.querySelector('#bvTableList .bv-check[data-table="' + t.name + '"]');
        if (box) {
            const target = t.new_rows.length ? t.new_rows : t.diff_rows;
            const allTarget = target.length > 0 && target.every(function (i) { return set.has(i); });
            box.checked = allTarget;
            box.indeterminate = set.size > 0 && !allTarget;
        }
        const label = document.querySelector('[data-sel-count="' + t.name + '"]');
        if (label) label.textContent = set.size ? "(" + set.size + " ticked)" : "";
    });
    if (bvState.table) syncRowChecks();
    const count = adds + restores;
    const btn = document.getElementById("bvAddBtn");
    btn.textContent = "📥 Retrieve " + count + " Record" + (count === 1 ? "" : "s");
    btn.disabled = count === 0;
    const parts = [];
    if (adds) parts.push(adds + " to add");
    if (restores) parts.push(restores + " to put back to the backup version");
    document.getElementById("bvSelSummary").textContent = parts.length ? "Ticked: " + parts.join(", ") + "." : "Tick the records to retrieve.";
}

function selectBackupTable(name) {
    bvState.table = name;
    document.querySelectorAll("#bvTableList .bv-table-item").forEach(function (el) {
        el.classList.toggle("active", el.getAttribute("data-table") === name);
    });
    loadBackupRows(1);
}

async function loadBackupRows(page) {
    if (!bvState.table) return;
    bvState.page = page || 1;
    const wrap = document.getElementById("bvGridWrap");
    const pager = document.getElementById("bvPager");
    const url = "backup.php?action=preview_rows&file=" + encodeURIComponent(bvState.file) +
        "&table=" + encodeURIComponent(bvState.table) +
        "&filter=" + encodeURIComponent(document.getElementById("bvFilter").value) +
        "&q=" + encodeURIComponent(document.getElementById("bvSearch").value.trim()) +
        "&page=" + bvState.page;
    wrap.innerHTML = '<div class="bv-empty">Loading…</div>';
    const requestId = (bvState.requestId = (bvState.requestId || 0) + 1);
    try {
        const res = await fetch(url, { credentials: "same-origin", cache: "no-store" });
        const data = await res.json();
        if (requestId !== bvState.requestId) return; // a newer table / page / search was asked for
        if (!data || !data.success) throw new Error((data && data.message) || "Could not load records.");
        document.getElementById("bvRowsTitle").textContent = data.label + " — " + data.matches + " record" + (data.matches === 1 ? "" : "s");
        if (!data.rows.length) {
            wrap.innerHTML = '<div class="bv-empty">No records match.</div>';
        } else {
            const anySelectable = data.rows.some(function (r) { return r.selectable; });
            wrap.innerHTML = '<table class="bv-grid"><thead><tr>' +
                '<th class="bv-col-check">' + (anySelectable ? '<input type="checkbox" id="bvRowCheckAll" onchange="toggleBackupPageRows(this)" aria-label="Tick all on this page" title="Tick all on this page">' : '') + '</th>' +
                '<th>Status</th>' +
                data.columns.map(function (c) { return "<th>" + bvEsc(c.replace(/_/g, " ")) + "</th>"; }).join("") +
                "</tr></thead><tbody>" +
                data.rows.map(function (r) {
                    const cb = r.selectable
                        ? '<input type="checkbox" class="bv-row-check" value="' + r.index + '" onchange="toggleBackupRow(this)" aria-label="Retrieve this record"' +
                          (r.status === "different" ? ' title="Put this record back to the version in the backup"' : ' title="Add this record to the system"') + '>'
                        : "";
                    return '<tr class="bv-row-' + r.status + '"><td class="bv-col-check">' + cb + '</td><td><span class="bv-badge bv-badge-' + r.status + '">' + BV_STATUS_TEXT[r.status] + "</span></td>" +
                        r.cells.map(function (v) { return v === null ? '<td class="bv-null">—</td>' : "<td>" + bvEsc(v) + "</td>"; }).join("") + "</tr>";
                }).join("") + "</tbody></table>";
            syncRowChecks();
        }
        pager.innerHTML = data.pages > 1
            ? '<button type="button" class="btn-secondary" ' + (data.page <= 1 ? "disabled" : "") + ' onclick="loadBackupRows(' + (data.page - 1) + ')">‹ Prev</button>' +
              "<span>Page " + data.page + " of " + data.pages + "</span>" +
              '<button type="button" class="btn-secondary" ' + (data.page >= data.pages ? "disabled" : "") + ' onclick="loadBackupRows(' + (data.page + 1) + ')">Next ›</button>'
            : "";
    } catch (e) {
        wrap.innerHTML = '<div class="bv-empty">' + bvEsc(e.message) + "</div>";
        pager.innerHTML = "";
    }
}

document.getElementById("bvSearch").addEventListener("input", function () {
    clearTimeout(bvState.searchTimer);
    bvState.searchTimer = setTimeout(function () { loadBackupRows(1); }, 300);
});

function submitBackupMerge(ev) {
    const selection = {};
    let count = 0;
    Object.keys(bvState.sel || {}).forEach(function (t) {
        const list = Array.from(bvState.sel[t]);
        if (list.length) {
            selection[t] = list;
            count += list.length;
        }
    });
    const pwd = document.getElementById("bvAddPassword");
    if (!count) {
        ev.preventDefault();
        alert("Tick at least one record to retrieve.");
        return false;
    }
    if (!pwd.value.trim()) {
        ev.preventDefault();
        alert("Enter the administrator password to retrieve records.");
        pwd.focus();
        return false;
    }
    document.getElementById("bvSelection").value = JSON.stringify(selection);
    const btn = document.getElementById("bvAddBtn");
    btn.disabled = true;
    btn.textContent = "Retrieving…";
    return true;
}

function confirmBackupReplace(ev) {
    const typed = document.getElementById("bvReplaceConfirm").value.trim().toUpperCase();
    const pwd = document.getElementById("bvReplacePassword").value.trim();
    if (typed !== "REPLACE" || !pwd) {
        ev.preventDefault();
        alert("Type REPLACE and enter the administrator password to replace all data.");
        return false;
    }
    return confirm("Replace ALL current data with this backup? Everything recorded after the backup was made will be lost.");
}

function closeBackupViewer() {
    const modal = document.getElementById("backupViewerModal");
    if (modal) modal.classList.remove("active");
    if (location.search.indexOf("view=") !== -1) {
        history.replaceState(null, "", "backup.php");
    }
}

document.addEventListener("keydown", function (e) {
    if (e.key === "Escape") closeBackupViewer();
});

window.addEventListener("click", function (e) {
    if (e.target === document.getElementById("backupViewerModal")) closeBackupViewer();
});

// Opened right after an upload (or from a link): show that backup
(function () {
    const params = new URLSearchParams(location.search);
    const file = params.get("view");
    if (file) openBackupViewer(file);
})();
</script>

<script src="assets/js/logout_confirm.js?v=<?php echo (int)@filemtime(__DIR__ . '/assets/js/logout_confirm.js'); ?>"></script>
</body>
</html>

