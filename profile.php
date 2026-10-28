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
$conn = new mysqli(
    "localhost:3307",
    "root",
    "",
    "security_system"
);

if ($conn->connect_error) {
    die("Database Connection Failed: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");
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
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = $_POST['action'] ?? '';

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
        $currentPass = $_POST['current_password'] ?? '';
        $newPass = $_POST['new_password'] ?? '';
        $confirmPass = $_POST['confirm_password'] ?? '';

        if ($fullName === '') {
            $_SESSION['flash_error'] = 'Full name cannot be empty.';
            header("Location: profile.php");
            exit();
        }

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
}

/* =========================================================
   LOAD CURRENT SETTINGS & ADMIN USER
========================================================= */
$systemName     = secsysGetSetting($conn, 'system_name', 'L. Surveillance');
$systemTitle    = secsysGetSetting($conn, 'system_title', "Lessandra’s Surveillance System");
$systemSubtitle = secsysGetSetting($conn, 'system_subtitle', 'Phase 2, Salinas 1, Bacoor, Cavite');
$systemIcon     = secsysGetSetting($conn, 'system_icon', '');
$loginBg        = secsysGetSetting($conn, 'login_background', '');

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
<html lang="en">
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
            <div class="logo-subtitle">Administrator</div>
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

                    <hr style="border:none; border-top:1px solid #e2e8f0; margin:20px 0;">

                    <div style="font-size:13px; font-weight:700; color:#0f172a; margin-bottom:12px;">
                        Change Password <span style="font-size:11px; font-weight:400; color:#64748b;">(Leave blank to keep current)</span>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="current_password">Current Password:</label>
                        <input type="password" class="form-input" id="current_password" name="current_password" autocomplete="current-password" placeholder="Enter current password to verify">
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
        </div>

    </div>

</main>

</body>
</html>
<?php $conn->close(); ?>
