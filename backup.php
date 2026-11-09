<?php
require_once __DIR__ . '/app/session_helper.php';
require_once __DIR__ . '/app/system_config.php';
require_once __DIR__ . '/app/backup_helper.php';

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

// 2. POST ACTIONS
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string)($_POST['action'] ?? '');

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

    // ACTION: RETRIEVE / RESTORE FROM UPLOADED FILE (REQUIRES ADMIN PASSWORD)
    if ($action === 'restore_upload') {
        $password = (string)($_POST['admin_password'] ?? '');

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

        // 2. Validate Uploaded File
        if (!isset($_FILES['backup_upload']) || $_FILES['backup_upload']['error'] !== UPLOAD_ERR_OK) {
            $_SESSION['flash_error'] = "Please choose a valid .sql backup file to upload.";
            header("Location: backup.php");
            exit();
        }

        $uploaded = $_FILES['backup_upload'];
        $ext = strtolower(pathinfo($uploaded['name'], PATHINFO_EXTENSION));

        if ($ext !== 'sql') {
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

        // 3. Save copy into backups repository
        $savedFilename = 'security_system_imported_' . date('Y-m-d_H-i-s') . '_' . preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $uploaded['name']);
        @file_put_contents($backupDir . DIRECTORY_SEPARATOR . $savedFilename, $sqlContent);

        // 4. Execute Restore
        $restoreRes = secsysRestoreDatabaseSql($conn, $sqlContent);

        if ($restoreRes['success']) {
            secsysLogActivity(
                $conn,
                $currentUsername,
                'Database Restore',
                "Retrieved and restored database from uploaded backup: {$uploaded['name']} ({$restoreRes['executed_queries']} queries executed)."
            );
            $_SESSION['flash_success'] = "Database successfully retrieved and restored from uploaded file <strong>" . e($uploaded['name']) . "</strong>! (" . (int)$restoreRes['executed_queries'] . " queries executed). All records have been restored.";
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

                <button type="submit" class="btn-secondary" name="download_direct" value="1" title="Generates backup and downloads .sql directly">
                    <span>📥 Generate &amp; Download SQL File</span>
                </button>
            </form>
        </div>

        <!-- CARD 2: UPLOAD & RETRIEVE -->
        <div class="card">
            <div class="card-title">
                <span>🔄 Retrieve From External Backup</span>
            </div>
            <div class="card-subtitle">
                Upload a valid <code>.sql</code> backup file to retrieve and restore database records. Password authorization is strictly required.
            </div>

            <form id="uploadRestoreForm" method="POST" action="backup.php" enctype="multipart/form-data" onsubmit="promptUploadRestore(event)">
                <input type="hidden" name="action" value="restore_upload">
                <input type="hidden" name="admin_password" id="uploadAdminPassword" value="">

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
                        <span>🔄 Retrieve &amp; Restore</span>
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

                                <!-- Retrieve / Restore Button (Triggers Password Modal) -->
                                <button
                                    type="button"
                                    class="btn-warning"
                                    onclick="openRestoreModal('<?php echo e($b['filename']); ?>')"
                                    title="Retrieve and restore database using this backup"
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
     RETRIEVE / RESTORE CONFIRMATION MODAL (PASSWORD REQUIRED)
========================================================= -->
<div class="modal-overlay" id="retrieveModal">
    <div class="modal-box">
        <div class="modal-header">
            <div class="modal-title">
                <span>🔒 Security Authorization Required</span>
            </div>
            <button type="button" class="modal-close" onclick="closeRestoreModal()">&times;</button>
        </div>

        <form method="POST" action="backup.php" id="storedRestoreForm">
            <input type="hidden" name="action" value="restore_backup">
            <input type="hidden" name="backup_file" id="modalRestoreFilename" value="">

            <div class="modal-body">
                <div class="warning-callout">
                    <strong>⚠️ Caution: Irreversible Database Overwrite!</strong>
                    <div style="margin-top: 4px;">
                        You are retrieving data from: <strong id="modalTargetLabel" style="word-break: break-all; color: #991b1b;"></strong>.<br>
                        This will replace active tables and records with the data saved in this backup.
                    </div>
                </div>

                <div class="form-group">
                    <label for="modalAdminPassword">
                        Enter Administrator Password to Authorize Retrieval:
                    </label>
                    <div class="input-password-wrapper">
                        <input
                            type="password"
                            name="admin_password"
                            id="modalAdminPassword"
                            placeholder="Current admin password"
                            required
                            autocomplete="current-password"
                        >
                        <button type="button" class="toggle-pwd-btn" onclick="toggleModalPassword()" title="Show/Hide Password">
                            👁️
                        </button>
                    </div>
                    <div style="font-size: 11.5px; color: #64748b; margin-top: 5px;">
                        Identity verification: <strong><?php echo e($currentUsername); ?></strong>
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeRestoreModal()">
                    Cancel
                </button>
                <button type="submit" class="btn-restore-confirm" id="btnSubmitRestore">
                    <span>🔄 Confirm &amp; Retrieve Data</span>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openRestoreModal(filename) {
    const modal = document.getElementById("retrieveModal");
    const fileInput = document.getElementById("modalRestoreFilename");
    const label = document.getElementById("modalTargetLabel");
    const pwdInput = document.getElementById("modalAdminPassword");

    if (!modal) return;

    if (fileInput) fileInput.value = filename;
    if (label) label.textContent = filename;
    if (pwdInput) {
        pwdInput.value = "";
        setTimeout(function() { pwdInput.focus(); }, 120);
    }

    modal.classList.add("active");
}

function closeRestoreModal() {
    const modal = document.getElementById("retrieveModal");
    if (modal) modal.classList.remove("active");
}

function toggleModalPassword() {
    const pwd = document.getElementById("modalAdminPassword");
    if (!pwd) return;
    pwd.type = pwd.type === "password" ? "text" : "password";
}

// Prompt for upload restore
function promptUploadRestore(e) {
    e.preventDefault();
    const fileInput = document.getElementById("backupUploadInput");
    if (!fileInput || !fileInput.files || fileInput.files.length === 0) {
        alert("Please select a .sql file to upload.");
        return;
    }

    const fileName = fileInput.files[0].name;

    // Show modal configured for uploaded file
    const modal = document.getElementById("retrieveModal");
    const label = document.getElementById("modalTargetLabel");
    const storedForm = document.getElementById("storedRestoreForm");
    const uploadForm = document.getElementById("uploadRestoreForm");
    const pwdInput = document.getElementById("modalAdminPassword");

    if (label) label.textContent = "Uploaded file: " + fileName;

    // Temporarily intercept submission
    storedForm.onsubmit = function(ev) {
        ev.preventDefault();
        const pwd = pwdInput ? pwdInput.value.trim() : "";
        if (!pwd) {
            alert("Administrator password is required.");
            if (pwdInput) pwdInput.focus();
            return;
        }

        const uploadPwdField = document.getElementById("uploadAdminPassword");
        if (uploadPwdField) uploadPwdField.value = pwd;

        closeRestoreModal();
        uploadForm.submit();
    };

    if (pwdInput) {
        pwdInput.value = "";
        setTimeout(function() { pwdInput.focus(); }, 120);
    }

    modal.classList.add("active");
}

// Close modal on Escape
document.addEventListener("keydown", function(e) {
    if (e.key === "Escape") {
        closeRestoreModal();
    }
});

// Close modal on overlay click
window.addEventListener("click", function(e) {
    const modal = document.getElementById("retrieveModal");
    if (e.target === modal) {
        closeRestoreModal();
    }
});
</script>

</body>
</html>

