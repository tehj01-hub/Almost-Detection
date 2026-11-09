<?php
require_once __DIR__ . '/app/session_helper.php';
require_once __DIR__ . '/app/system_config.php';

secsysStartRoleSession('Administrator');

/* =========================================================
   LOGIN CHECK
========================================================= */
if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit();
}

/* =========================================================
   DATABASE CONNECTION
========================================================= */
$conn = secsysConnectDb();
secsysEnsureSchema($conn);

/* =========================================================
   HELPER FUNCTIONS
========================================================= */
if (!function_exists('e')) {
    function e($value)
    {
        return htmlspecialchars((string)($value ?? ""), ENT_QUOTES, "UTF-8");
    }
}

$currentUsername = $_SESSION['username'];
$flashSuccess = $_SESSION['flash_success'] ?? '';
$flashError   = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

/* =========================================================
   HANDLE POST ACTIONS
========================================================= */
/** True when $password is the logged-in administrator's own password. */
function profileVerifyOwnPassword(mysqli $conn, string $username, string $password): bool
{
    if ($password === '') {
        return false;
    }
    $stmt = $conn->prepare("SELECT password FROM users WHERE username = ? LIMIT 1");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        return false;
    }
    $stored = (string)$row['password'];
    // Older accounts may still have a plain-text password
    return password_verify($password, $stored) || hash_equals($stored, $password);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = $_POST['action'] ?? '';

    // Every Profile change must be confirmed with the administrator's own password
    if (in_array($action, ['update_system_variables', 'update_admin_profile', 'update_security_contacts'], true)) {
        $verifyPassword = (string)($_POST['verify_password'] ?? '');
        if (!profileVerifyOwnPassword($conn, $currentUsername, $verifyPassword)) {
            secsysLogActivity(
                $conn,
                $currentUsername,
                'Profile Update',
                'A Profile change was refused: the password entered to confirm it was incorrect.'
            );
            $_SESSION['flash_error'] = $verifyPassword === ''
                ? 'Please enter your password to confirm the change. Nothing was saved.'
                : 'Incorrect password. Nothing was saved.';
            header("Location: profile.php");
            exit();
        }
    }

    // ACTION 1: UPDATE SYSTEM VARIABLES (BRANDING, ICON, BACKGROUND)
    if ($action === 'update_system_variables') {
        $name     = trim($_POST['system_name'] ?? '');
        $title    = trim($_POST['system_title'] ?? '');
        $subtitle = trim($_POST['system_subtitle'] ?? '');

        if ($name === '') {
            $name = 'L. Surveillance';
        }
        if ($title === '') {
            $title = "Lessandra’s Surveillance System";
        }
        if ($subtitle === '') {
            $subtitle = 'Phase 2, Salinas 1, Bacoor, Cavite';
        }

        secsysSetSetting($conn, 'system_name', $name);
        secsysSetSetting($conn, 'system_title', $title);
        secsysSetSetting($conn, 'system_subtitle', $subtitle);

        // Upload directory
        $uploadDir = __DIR__ . '/uploads/';
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0777, true);
        }

        // Reset Icon if requested
        if (isset($_POST['remove_icon']) && $_POST['remove_icon'] === '1') {
            secsysSetSetting($conn, 'system_icon', '');
        } elseif (isset($_FILES['system_icon']) && $_FILES['system_icon']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['system_icon'];
            $allowedExts = ['png', 'jpg', 'jpeg', 'svg', 'ico', 'webp'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (in_array($ext, $allowedExts, true)) {
                $newFileName = 'system_icon_' . time() . '_' . mt_rand(1000, 9999) . '.' . $ext;
                $targetPath = $uploadDir . $newFileName;
                if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                    secsysSetSetting($conn, 'system_icon', 'uploads/' . $newFileName);
                }
            }
        }

        // Reset Background if requested
        if (isset($_POST['remove_background']) && $_POST['remove_background'] === '1') {
            secsysSetSetting($conn, 'login_background', '');
        } elseif (isset($_FILES['login_background']) && $_FILES['login_background']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['login_background'];
            $allowedExts = ['jpg', 'jpeg', 'png', 'webp'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (in_array($ext, $allowedExts, true)) {
                $newFileName = 'login_bg_' . time() . '_' . mt_rand(1000, 9999) . '.' . $ext;
                $targetPath = $uploadDir . $newFileName;
                if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                    secsysSetSetting($conn, 'login_background', 'uploads/' . $newFileName);
                }
            }
        }

        secsysLogActivity(
            $conn,
            $currentUsername,
            'System Settings Update',
            'Updated system variables: name (' . $name . '), title, icon, and login background.'
        );

        $_SESSION['flash_success'] = 'System variables successfully updated!';
        header("Location: profile.php");
        exit();
    }

    // ACTION 2: UPDATE ADMINISTRATOR PROFILE & PASSWORD
    if ($action === 'update_admin_profile') {
        $fullName = trim($_POST['full_name'] ?? '');
        // The password confirmed in the verification window is the current password
        $currentPass = (string)($_POST['verify_password'] ?? '');
        $newPass = $_POST['new_password'] ?? '';
        $confirmPass = $_POST['confirm_password'] ?? '';

        if ($fullName === '') {
            $_SESSION['flash_error'] = 'Full name cannot be empty.';
            header("Location: profile.php");
            exit();
        }

        // Contact number: used to verify "Forgot password" on the login page
        $contactNumber = trim((string)($_POST['contact_number'] ?? ''));
        $contactDigits = preg_replace('/\D+/', '', $contactNumber);
        if ($contactNumber !== '' && (strlen($contactDigits) < 7 || strlen($contactDigits) > 15 || !preg_match('/^[0-9+\-\s()]+$/', $contactNumber))) {
            $_SESSION['flash_error'] = 'Please enter a valid contact number (digits only, e.g. 09171234567).';
            header("Location: profile.php");
            exit();
        }
        $contactToSave = $contactNumber !== '' ? $contactNumber : null;
        $stmtContact = $conn->prepare("UPDATE users SET contact_number = ? WHERE username = ?");
        $stmtContact->bind_param("ss", $contactToSave, $currentUsername);
        $stmtContact->execute();
        $stmtContact->close();

        // Verify current password if changing password
        if ($newPass !== '') {
            if ($newPass !== $confirmPass) {
                $_SESSION['flash_error'] = 'New passwords do not match.';
                header("Location: profile.php");
                exit();
            }
            if (strlen($newPass) < 4) {
                $_SESSION['flash_error'] = 'New password must be at least 4 characters long.';
                header("Location: profile.php");
                exit();
            }

            // Check current password from users table
            $stmtUser = $conn->prepare("SELECT password FROM users WHERE username = ? LIMIT 1");
            $stmtUser->bind_param("s", $currentUsername);
            $stmtUser->execute();
            $resUser = $stmtUser->get_result();
            $userRow = $resUser->fetch_assoc();
            $stmtUser->close();

            $verified = false;
            if ($userRow) {
                $dbPass = $userRow['password'];
                if (password_verify($currentPass, $dbPass) || $currentPass === $dbPass) {
                    $verified = true;
                }
            }

            if (!$verified) {
                $_SESSION['flash_error'] = 'Current password is incorrect.';
                header("Location: profile.php");
                exit();
            }

            $hashed = password_hash($newPass, PASSWORD_DEFAULT);
            $stmtUp = $conn->prepare("UPDATE users SET full_name = ?, password = ? WHERE username = ?");
            $stmtUp->bind_param("sss", $fullName, $hashed, $currentUsername);
            $stmtUp->execute();
            $stmtUp->close();

            secsysLogActivity(
                $conn,
                $currentUsername,
                'Password Change',
                'Administrator changed account password and updated full name.'
            );
        } else {
            $stmtUp = $conn->prepare("UPDATE users SET full_name = ? WHERE username = ?");
            $stmtUp->bind_param("ss", $fullName, $currentUsername);
            $stmtUp->execute();
            $stmtUp->close();

            secsysLogActivity(
                $conn,
                $currentUsername,
                'Profile Update',
                'Administrator updated account full name to ' . $fullName . '.'
            );
        }

        $_SESSION['flash_success'] = 'Administrator profile updated successfully!';
        header("Location: profile.php");
        exit();
    }

    // ACTION 3: UPDATE SECURITY CONTACTS (SECURITY OFFICE & SUBDIVISION ADMIN OFFICE)
    if ($action === 'update_security_contacts') {
        $secOfficeNum = trim((string)($_POST['security_office_number'] ?? ''));
        $admOfficeNum = trim((string)($_POST['subdivision_admin_office_number'] ?? ''));

        if ($secOfficeNum === '' || $admOfficeNum === '') {
            $_SESSION['flash_error'] = 'Both Security Office and Subdivision Admin Office contact numbers are required.';
            header("Location: profile.php");
            exit();
        }

        secsysUpdateSecurityOfficeContacts($conn, $secOfficeNum, $admOfficeNum);

        secsysLogActivity(
            $conn,
            $currentUsername,
            'Security Contacts Update',
            'Updated Security Office (' . $secOfficeNum . ') and Subdivision Admin Office (' . $admOfficeNum . ') contact numbers.'
        );

        $_SESSION['flash_success'] = 'Security Office and Subdivision Admin Office contact numbers updated!';
        header("Location: profile.php");
        exit();
    }
}

/* =========================================================
   LOAD CURRENT SETTINGS & ADMIN USER
========================================================= */
$systemName     = secsysGetSetting($conn, 'system_name', 'L. Surveillance');
$systemTitle    = secsysGetSetting($conn, 'system_title', "Lessandra’s Surveillance System");
$systemSubtitle = secsysGetSetting($conn, 'system_subtitle', 'Phase 2, Salinas 1, Bacoor, Cavite');
$systemIcon     = secsysGetSetting($conn, 'system_icon', '');
$loginBg        = secsysGetSetting($conn, 'login_background', '');
$securityOfficeNumber         = secsysGetSecurityOfficeNumber($conn);
$subdivisionAdminOfficeNumber = secsysGetSubdivisionAdminOfficeNumber($conn);

$adminUser = [
    'username'     => $currentUsername,
    'full_name'    => 'Administrator',
    'role'         => 'Administrator',
    'date_created' => null,
    'last_login'   => null,
];

$stmtAdmin = $conn->prepare("SELECT * FROM users WHERE username = ? LIMIT 1");
if ($stmtAdmin) {
    $stmtAdmin->bind_param("s", $currentUsername);
    $stmtAdmin->execute();
    $resAdmin = $stmtAdmin->get_result();
    if ($u = $resAdmin->fetch_assoc()) {
        $adminUser = $u;
    }
    $stmtAdmin->close();
}
?>
<!DOCTYPE html>
<html lang="en" class="ui-scale-fixed">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Profile &amp; System Settings | <?php echo e($systemName); ?></title>
<?php if (!empty($systemIcon)): ?>
<link rel="icon" href="<?php echo e($systemIcon); ?>">
<?php endif; ?>
<style>
/* =========================================================
   RESET & LAYOUT
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
    font-family: Arial, Helvetica, sans-serif;
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
    font-family: Arial, Helvetica, sans-serif;
}

.logo-title {
    font-size: 17px;
    font-weight: 500;
    white-space: nowrap;
    color: #111;
    font-family: Arial, Helvetica, sans-serif;
}

.logo-subtitle {
    font-size: 12px;
    color: #444;
    font-family: Arial, Helvetica, sans-serif;
}

.nav {
    display: flex;
    flex-direction: column;
    gap: 7px;
    width: 100%;
    font-family: Arial, Helvetica, sans-serif;
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
    font-family: Arial, Helvetica, sans-serif;
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
    font-family: Arial, Helvetica, sans-serif;
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
    font-family: Arial, Helvetica, sans-serif;
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
    font-family: Arial, Helvetica, sans-serif;
}

.page-header {
    margin-bottom: 24px;
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

/* Flash Messages */
.alert {
    padding: 12px 16px;
    border-radius: 8px;
    font-size: 13px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 10px;
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

/* Layout Grid */
.profile-grid {
    display: grid;
    grid-template-columns: 1.15fr 0.85fr;
    gap: 24px;
    align-items: start;
}

@media (max-width: 1024px) {
    .profile-grid {
        grid-template-columns: 1fr;
    }
}

.card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 24px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
    margin-bottom: 24px;
}

.card-title {
    font-size: 16px;
    font-weight: 700;
    color: #0f172a;
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 6px;
}

.card-subtitle {
    font-size: 12px;
    color: #64748b;
    margin-bottom: 20px;
}

.form-group {
    margin-bottom: 18px;
}

.form-label {
    display: block;
    font-size: 12px;
    font-weight: 600;
    color: #334155;
    margin-bottom: 6px;
}

.form-hint {
    font-size: 11px;
    color: #64748b;
    margin-top: 4px;
}

.form-input {
    width: 100%;
    height: 38px;
    padding: 0 12px;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    font-size: 13px;
    color: #0f172a;
    outline: none;
    background: #fff;
    transition: border-color 0.15s ease;
}

.form-input:focus {
    border-color: #0284c7;
    box-shadow: 0 0 0 3px rgba(2, 132, 199, 0.12);
}

.form-input[readonly] {
    background: #f8fafc;
    color: #64748b;
    cursor: not-allowed;
}

.upload-box {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 12px;
    background: #f8fafc;
    border: 1px dashed #cbd5e1;
    border-radius: 10px;
}

.preview-thumb {
    width: 64px;
    height: 64px;
    border-radius: 10px;
    background: #e2e8f0;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 26px;
    overflow: hidden;
    flex-shrink: 0;
    border: 1px solid #cbd5e1;
}

.preview-thumb img {
    width: 100%;
    height: 100%;
    object-fit: contain;
}

.preview-bg-thumb {
    width: 96px;
    height: 64px;
    border-radius: 8px;
    background: #e2e8f0;
    overflow: hidden;
    flex-shrink: 0;
    border: 1px solid #cbd5e1;
}

.preview-bg-thumb img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 0 16px;
    height: 38px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    border: none;
    transition: all 0.15s ease;
    text-decoration: none;
}

.btn-primary {
    background: #0284c7;
    color: #ffffff;
}

.btn-primary:hover {
    background: #0369a1;
}

.btn-secondary {
    background: #f1f5f9;
    color: #0f172a;
    border: 1px solid #cbd5e1;
}

.btn-secondary:hover {
    background: #e2e8f0;
}

.btn-outline-danger {
    background: #fff;
    color: #dc2626;
    border: 1px solid #fecaca;
    height: 30px;
    padding: 0 10px;
    font-size: 11px;
}

.btn-outline-danger:hover {
    background: #fee2e2;
}

/* Live Preview Widget */
.login-preview-box {
    background: <?php echo !empty($loginBg) ? "url('" . e($loginBg) . "') center/cover no-repeat" : "#ededed"; ?>;
    padding: 24px 16px;
    border-radius: 12px;
    text-align: center;
    border: 1px solid #cbd5e1;
    position: relative;
    overflow: hidden;
    margin-top: 10px;
}

.preview-inner {
    background: <?php echo !empty($loginBg) ? 'rgba(255, 255, 255, 0.94)' : '#ffffff'; ?>;
    border-radius: 14px;
    padding: 16px 20px;
    display: inline-block;
    max-width: 90%;
    box-shadow: 0 4px 14px rgba(0,0,0,0.15);
}

.badge-admin {
    display: inline-block;
    background: #dbeafe;
    color: #1e40af;
    padding: 3px 8px;
    border-radius: 6px;
    font-size: 11px;
    font-weight: 600;
}
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
            <div class="logo-title"><?php echo e($systemName); ?></div>
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
        <a href="backup.php">
            <span class="nav-icon">💾</span>
            <span>Backup &amp; Retrieve</span>
        </a>
        <a href="profile.php" class="active">
            <span class="nav-icon">⚙️</span>
            <span>Profile</span>
        </a>
    </nav>

    <a href="logout.php?role=Administrator" class="logout">🚪 Logout</a>
</aside>

<!-- =====================================================
     MAIN CONTENT
====================================================== -->
<main class="main">

    <div class="page-header">
        <h1><span>⚙️</span> System Variables &amp; Administrator Profile</h1>
        <p>Edit global system variables (system name, icon, login background image) and manage administrator credentials.</p>
    </div>

    <?php if ($flashSuccess !== ''): ?>
        <div class="alert alert-success">
            <span>✓</span>
            <span><?php echo e($flashSuccess); ?></span>
        </div>
    <?php endif; ?>

    <?php if ($flashError !== ''): ?>
        <div class="alert alert-error">
            <span>⚠</span>
            <span><?php echo e($flashError); ?></span>
        </div>
    <?php endif; ?>

    <div class="profile-grid">

        <!-- COLUMN 1: SYSTEM VARIABLES / BRANDING -->
        <div>
            <div class="card">
                <div class="card-title">
                    <span>🏢</span>
                    <span>System Variables (Branding &amp; Appearance)</span>
                </div>
                <div class="card-subtitle">
                    Changes here immediately apply to sidebar titles, page headers, and the login page.
                </div>

                <form method="POST" action="profile.php" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="update_system_variables">

                    <!-- System Name (Short Brand) -->
                    <div class="form-group">
                        <label class="form-label" for="system_name">System Name (Short Brand):</label>
                        <input type="text" class="form-input" id="system_name" name="system_name" value="<?php echo e($systemName); ?>" required>
                        <div class="form-hint">Displayed in the sidebar, page titles, and headers (default: <code>L. Surveillance</code>).</div>
                    </div>

                    <!-- Full System Title (Login Header) -->
                    <div class="form-group">
                        <label class="form-label" for="system_title">Login Page System Title:</label>
                        <input type="text" class="form-input" id="system_title" name="system_title" value="<?php echo e($systemTitle); ?>" required>
                        <div class="form-hint">Large main title displayed on the login page (default: <code>Lessandra’s Surveillance System</code>).</div>
                    </div>

                    <!-- Subtitle / Location -->
                    <div class="form-group">
                        <label class="form-label" for="system_subtitle">Login Page Subtitle / Location:</label>
                        <input type="text" class="form-input" id="system_subtitle" name="system_subtitle" value="<?php echo e($systemSubtitle); ?>" required>
                        <div class="form-hint">Location or tagline shown below the title (default: <code>Phase 2, Salinas 1, Bacoor, Cavite</code>).</div>
                    </div>

                    <!-- System Icon / Logo -->
                    <div class="form-group">
                        <label class="form-label">System Icon / Logo:</label>
                        <div class="upload-box">
                            <div class="preview-thumb" id="iconThumb">
                                <?php if (!empty($systemIcon)): ?>
                                    <img src="<?php echo e($systemIcon); ?>" alt="Icon">
                                <?php else: ?>
                                    🛡️
                                <?php endif; ?>
                            </div>
                            <div style="flex:1;">
                                <input type="file" name="system_icon" accept="image/png,image/jpeg,image/svg+xml,image/x-icon,image/webp" style="font-size:12px;">
                                <div class="form-hint">Supports PNG, JPG, SVG, WEBP, or ICO. Recommended size: 128x128px or square.</div>
                                <?php if (!empty($systemIcon)): ?>
                                    <div style="margin-top:6px;">
                                        <label style="font-size:11px; color:#dc2626; cursor:pointer;">
                                            <input type="checkbox" name="remove_icon" value="1"> Reset / Remove custom icon (use default shield)
                                        </label>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Login Background Picture -->
                    <div class="form-group">
                        <label class="form-label">Login Page Background Picture:</label>
                        <div class="upload-box">
                            <div class="preview-bg-thumb" id="bgThumb">
                                <?php if (!empty($loginBg)): ?>
                                    <img src="<?php echo e($loginBg); ?>" alt="Background">
                                <?php else: ?>
                                    <div style="font-size:11px; color:#888; text-align:center; padding:18px 4px;">Default Gray (#ededed)</div>
                                <?php endif; ?>
                            </div>
                            <div style="flex:1;">
                                <input type="file" name="login_background" accept="image/jpeg,image/png,image/webp" style="font-size:12px;">
                                <div class="form-hint">Supports JPG, PNG, WEBP. High-resolution landscape photo recommended (e.g., 1920x1080).</div>
                                <?php if (!empty($loginBg)): ?>
                                    <div style="margin-top:6px;">
                                        <label style="font-size:11px; color:#dc2626; cursor:pointer;">
                                            <input type="checkbox" name="remove_background" value="1"> Reset / Remove background image (use default gray)
                                        </label>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary" style="margin-top:8px;">
                        <span>💾</span>
                        <span>Save System Variables</span>
                    </button>
                </form>
            </div>

            <!-- LIVE PREVIEW CARD -->
            <div class="card">
                <div class="card-title">
                    <span>👁️</span>
                    <span>Live Preview of Login Header</span>
                </div>
                <div class="card-subtitle">
                    How the login screen currently renders with your active icon and background:
                </div>

                <div class="login-preview-box">
                    <div class="preview-inner">
                        <div style="font-size:42px; margin-bottom:4px;">
                            <?php if (!empty($systemIcon)): ?>
                                <img src="<?php echo e($systemIcon); ?>" alt="Logo" style="width:52px; height:52px; object-fit:contain; border-radius:10px;">
                            <?php else: ?>
                                🛡️
                            <?php endif; ?>
                        </div>
                        <div style="font-size:18px; font-weight:700; color:#111;">
                            <?php echo e($systemTitle); ?>
                        </div>
                        <div style="font-size:12px; color:#555; margin-top:2px;">
                            <?php echo e($systemSubtitle); ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- COLUMN 2: ADMINISTRATOR PROFILE & CREDENTIALS -->
        <div>
            <div class="card">
                <div class="card-title">
                    <span>👤</span>
                    <span>Administrator Profile</span>
                </div>
                <div class="card-subtitle">
                    Account credentials and personal details for logged-in administrator.
                </div>

                <form method="POST" action="profile.php">
                    <input type="hidden" name="action" value="update_admin_profile">

                    <div class="form-group">
                        <label class="form-label">Username:</label>
                        <input type="text" class="form-input" value="<?php echo e($adminUser['username']); ?>" readonly>
                    </div>

                    <div class="form-group">
                        <label class="form-label">System Role:</label>
                        <div>
                            <span class="badge-admin">Administrator</span>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="full_name">Full Name:</label>
                        <input type="text" class="form-input" id="full_name" name="full_name" value="<?php echo e($adminUser['full_name']); ?>" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="contact_number">Contact Number:</label>
                        <input type="tel" class="form-input" id="contact_number" name="contact_number" value="<?php echo e($adminUser['contact_number'] ?? ''); ?>" placeholder="e.g. 09171234567" inputmode="tel" maxlength="30">
                        <div style="font-size:11px; color:#64748b; margin-top:4px;">Used to verify your identity if you forget your password.</div>
                    </div>

                    <hr style="border:none; border-top:1px solid #e2e8f0; margin:20px 0;">

                    <div style="font-size:13px; font-weight:700; color:#0f172a; margin-bottom:12px;">
                        Change Password <span style="font-size:11px; font-weight:400; color:#64748b;">(Leave blank to keep current)</span>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="new_password">New Password:</label>
                        <input type="password" class="form-input" id="new_password" name="new_password" autocomplete="new-password" placeholder="At least 4 characters">
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="confirm_password">Confirm New Password:</label>
                        <input type="password" class="form-input" id="confirm_password" name="confirm_password" autocomplete="new-password" placeholder="Confirm new password">
                    </div>

                    <button type="submit" class="btn btn-primary" style="margin-top:8px;">
                        <span>💾</span>
                        <span>Update Administrator Profile</span>
                    </button>
                </form>
            </div>

            <!-- SECURITY CONTACTS CARD -->
            <div class="card" id="security-contacts">
                <div class="card-title">
                    <span>📞</span>
                    <span>Security Contacts</span>
                </div>
                <div class="card-subtitle">
                    Contact numbers displayed under Security Contacts in the Security Information module.
                </div>

                <form method="POST" action="profile.php">
                    <input type="hidden" name="action" value="update_security_contacts">

                    <div class="form-group">
                        <label class="form-label" for="security_office_number">Security Office Contact Number:</label>
                        <input
                            type="tel"
                            class="form-input"
                            id="security_office_number"
                            name="security_office_number"
                            value="<?php echo e($securityOfficeNumber); ?>"
                            placeholder="e.g. 0917-123-4567"
                            maxlength="50"
                            required
                        >
                        <div class="form-hint">Displayed for <strong>Security Office</strong> (24/7 Main Gate Security).</div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="subdivision_admin_office_number">Subdivision Admin Office Contact Number:</label>
                        <input
                            type="tel"
                            class="form-input"
                            id="subdivision_admin_office_number"
                            name="subdivision_admin_office_number"
                            value="<?php echo e($subdivisionAdminOfficeNumber); ?>"
                            placeholder="e.g. 0918-765-4321"
                            maxlength="50"
                            required
                        >
                        <div class="form-hint">Displayed for <strong>Subdivision Admin Office</strong> (Subdivision Administration).</div>
                    </div>

                    <button type="submit" class="btn btn-primary" style="margin-top:8px;">
                        <span>💾</span>
                        <span>Save Security Contacts</span>
                    </button>
                </form>
            </div>
        </div>

    </div>

</main>

<!-- =========================================================
     PASSWORD CONFIRMATION before any Profile change is saved
========================================================= -->
<div class="pv-backdrop" id="profileVerifyModal" role="dialog" aria-modal="true" aria-labelledby="pvTitle">
    <form class="pv-box" id="profileVerifyForm" autocomplete="off">
        <div class="pv-head">
            <div class="pv-title" id="pvTitle">🔒 Confirm It's You</div>
            <button type="button" class="pv-close" onclick="closeProfileVerify()" aria-label="Close">&times;</button>
        </div>
        <p class="pv-hint">
            Enter your password to save the changes to <strong id="pvWhat">your profile</strong>.
        </p>
        <label class="pv-label" for="pvPassword">Password for <?php echo e($currentUsername); ?></label>
        <div class="pv-input-wrap">
            <input type="password" id="pvPassword" class="pv-input" autocomplete="current-password" required>
            <button type="button" class="pv-eye" onclick="var p=document.getElementById('pvPassword'); p.type = p.type === 'password' ? 'text' : 'password';" title="Show / hide password">👁</button>
        </div>
        <div class="pv-error" id="pvError" hidden></div>
        <div class="pv-actions">
            <button type="button" class="btn btn-secondary" onclick="closeProfileVerify()">Cancel</button>
            <button type="submit" class="btn btn-primary">Confirm &amp; Save</button>
        </div>
    </form>
</div>

<style>
.pv-backdrop {
    display: none; position: fixed; inset: 0; z-index: 10050; background: rgba(15, 23, 42, 0.55);
    align-items: center; justify-content: center; padding: 16px;
}
.pv-backdrop.open { display: flex; }
.pv-box {
    background: #fff; border-radius: 16px; width: 100%; max-width: 400px; padding: 18px 20px 16px;
    box-shadow: 0 20px 40px rgba(0, 0, 0, 0.25); box-sizing: border-box;
}
.pv-box * { box-sizing: border-box; }
.pv-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 6px; }
.pv-title { font-size: 17px; font-weight: 700; color: #0f172a; }
.pv-close { background: none; border: none; font-size: 24px; line-height: 1; color: #64748b; cursor: pointer; }
.pv-hint { font-size: 13px; color: #475569; margin: 0 0 12px; line-height: 1.45; }
.pv-label { display: block; font-size: 12.5px; font-weight: 700; color: #1e293b; margin-bottom: 6px; }
.pv-input-wrap { position: relative; }
.pv-input {
    width: 100%; height: 40px; border: 1px solid #cbd5e1; border-radius: 8px; padding: 0 40px 0 12px; font-size: 14px;
}
.pv-input:focus { outline: none; border-color: #0284c7; box-shadow: 0 0 0 3px rgba(2, 132, 199, 0.15); }
.pv-eye { position: absolute; right: 8px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; font-size: 16px; color: #64748b; }
.pv-error { margin-top: 10px; background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; border-radius: 8px; padding: 8px 10px; font-size: 12.5px; }
.pv-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 16px; }
</style>

<script>
(function () {
    // Profile forms that change something; each needs the password first
    const LABELS = {
        update_system_variables: "the system variables",
        update_admin_profile: "your administrator profile",
        update_security_contacts: "the security contacts"
    };
    let pendingForm = null;

    function actionOf(form) {
        const field = form.querySelector('input[name="action"]');
        return field ? field.value : "";
    }

    document.querySelectorAll('form[action="profile.php"]').forEach(function (form) {
        if (!LABELS[actionOf(form)]) return;
        form.addEventListener("submit", function (event) {
            if (form.dataset.verified === "1") return; // already confirmed: let it go through
            event.preventDefault();
            if (!form.reportValidity()) return;
            pendingForm = form;
            document.getElementById("pvWhat").textContent = LABELS[actionOf(form)];
            document.getElementById("pvError").hidden = true;
            const input = document.getElementById("pvPassword");
            input.value = "";
            input.type = "password";
            document.getElementById("profileVerifyModal").classList.add("open");
            setTimeout(function () { input.focus(); }, 60);
        });
    });

    document.getElementById("profileVerifyForm").addEventListener("submit", function (event) {
        event.preventDefault();
        const password = document.getElementById("pvPassword").value;
        if (!password) {
            const err = document.getElementById("pvError");
            err.textContent = "Please enter your password.";
            err.hidden = false;
            return;
        }
        if (!pendingForm) return;
        let hidden = pendingForm.querySelector('input[name="verify_password"]');
        if (!hidden) {
            hidden = document.createElement("input");
            hidden.type = "hidden";
            hidden.name = "verify_password";
            pendingForm.appendChild(hidden);
        }
        hidden.value = password;
        pendingForm.dataset.verified = "1";
        closeProfileVerify(true);
        pendingForm.submit();
    });

    window.closeProfileVerify = function (keepForm) {
        document.getElementById("profileVerifyModal").classList.remove("open");
        document.getElementById("pvPassword").value = "";
        if (!keepForm) pendingForm = null;
    };

    document.addEventListener("keydown", function (event) {
        if (event.key === "Escape") closeProfileVerify();
    });
    document.getElementById("profileVerifyModal").addEventListener("click", function (event) {
        if (event.target === this) closeProfileVerify();
    });
})();
</script>

<script src="assets/js/logout_confirm.js?v=<?php echo (int)@filemtime(__DIR__ . '/assets/js/logout_confirm.js'); ?>"></script>
</body>
</html>
<?php $conn->close(); ?>
