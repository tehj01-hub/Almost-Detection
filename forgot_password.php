<?php
/* =========================================================
   FORGOT PASSWORD (verified by contact number)
   ---------------------------------------------------------
   Step 1: username + the contact number on file
           - Security Guard: contact number on the guard record
             (Security Guard page)
           - Administrator: contact number in Profile
   Step 2: choose a new password (within 10 minutes)

   Protection: the same message for every failure (does not
   reveal which usernames exist), 5 failed tries per username
   or computer locks this page for 15 minutes, inactive or
   archived accounts cannot reset, and every reset is written
   to the audit trail.
========================================================= */

require_once __DIR__ . '/app/system_config.php';

date_default_timezone_set('Asia/Manila');

// Own session, separate from the Administrator / Security Guard sessions
session_name('secsys_password_reset');
session_start();

const RESET_MAX_FAILED_ATTEMPTS = 5;
const RESET_LOCK_MINUTES = 15;
const RESET_WINDOW_SECONDS = 600;
const RESET_MIN_PASSWORD_LENGTH = 6;

$conn = secsysConnectDb();
secsysEnsureSchema($conn);

$systemTitle    = secsysGetSetting($conn, 'system_title', "Lessandra’s Surveillance System");
$systemSubtitle = secsysGetSetting($conn, 'system_subtitle', 'Phase 2, Salinas 1, Bacoor, Cavite');
$systemIcon     = secsysGetSetting($conn, 'system_icon', '');
$loginBg        = secsysGetSetting($conn, 'login_background', '');

function fpEsc($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/** Compare phone numbers by digits; +63 9XX and 09XX count as the same number. */
function fpNormalizeContact(string $number): string
{
    $digits = preg_replace('/\D+/', '', $number);
    if (strlen($digits) === 12 && strpos($digits, '63') === 0) {
        $digits = '0' . substr($digits, 2);
    } elseif (strlen($digits) === 10 && $digits[0] === '9') {
        $digits = '0' . $digits;
    }
    return $digits;
}

function fpClientIp(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

function fpFailedAttempts(mysqli $conn, string $username): int
{
    $ip = fpClientIp();
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS cnt
        FROM password_reset_attempts
        WHERE succeeded = 0
          AND attempted_at >= NOW() - INTERVAL " . RESET_LOCK_MINUTES . " MINUTE
          AND (username = ? OR ip_address = ?)
    ");
    $stmt->bind_param('ss', $username, $ip);
    $stmt->execute();
    $count = (int)($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);
    $stmt->close();
    return $count;
}

function fpRecordAttempt(mysqli $conn, string $username, bool $succeeded): void
{
    $ip = fpClientIp();
    $ok = $succeeded ? 1 : 0;
    $stmt = $conn->prepare("INSERT INTO password_reset_attempts (username, ip_address, succeeded) VALUES (?, ?, ?)");
    $stmt->bind_param('ssi', $username, $ip, $ok);
    $stmt->execute();
    $stmt->close();
}

/**
 * The account for a username, with the contact number on file.
 * Returns null when the account cannot use password reset.
 */
function fpFindAccount(mysqli $conn, string $username): ?array
{
    $stmt = $conn->prepare("
        SELECT u.id, u.username, u.role, u.status, u.guard_id, u.contact_number AS user_contact,
               sg.contact_number AS guard_contact, sg.status AS guard_status
        FROM users u
        LEFT JOIN security_guard sg
            ON sg.guard_id COLLATE utf8mb4_unicode_ci = u.guard_id COLLATE utf8mb4_unicode_ci
        WHERE u.username = ?
        LIMIT 1
    ");
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row || strtolower(trim((string)$row['status'])) !== 'active') {
        return null;
    }
    if ($row['role'] === 'Security Guard') {
        if (trim((string)$row['guard_status']) !== 'Active') {
            return null;
        }
        $row['contact'] = (string)($row['guard_contact'] ?? '');
    } else {
        $row['contact'] = (string)($row['user_contact'] ?? '');
    }
    return $row;
}

if (empty($_SESSION['fp_csrf'])) {
    $_SESSION['fp_csrf'] = bin2hex(random_bytes(16));
}

$step = 'verify';
$error = '';
$success = '';
$usernameValue = '';

// A verified reset still within its time window
$pending = $_SESSION['fp_reset'] ?? null;
if (is_array($pending) && (int)($pending['expires'] ?? 0) < time()) {
    unset($_SESSION['fp_reset']);
    $pending = null;
    $error = 'Your reset session expired. Please verify again.';
}
if (is_array($pending)) {
    $step = 'reset';
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!hash_equals((string)$_SESSION['fp_csrf'], (string)($_POST['csrf'] ?? ''))) {
        $error = 'Your session expired. Please try again.';
    } elseif (($_POST['action'] ?? '') === 'cancel') {
        unset($_SESSION['fp_reset']);
        header('Location: login.php');
        exit();
    } elseif (($_POST['action'] ?? '') === 'verify') {
        $usernameValue = trim((string)($_POST['username'] ?? ''));
        $contactInput = (string)($_POST['contact_number'] ?? '');
        $genericError = 'The username and contact number do not match our records.';

        if ($usernameValue === '' || trim($contactInput) === '') {
            $error = 'Please enter your username and contact number.';
        } elseif (fpFailedAttempts($conn, $usernameValue) >= RESET_MAX_FAILED_ATTEMPTS) {
            $error = 'Too many failed attempts. Please wait ' . RESET_LOCK_MINUTES . ' minutes and try again, or ask an administrator to reset your password.';
        } else {
            $account = fpFindAccount($conn, $usernameValue);
            $onFile = $account ? fpNormalizeContact($account['contact']) : '';
            $entered = fpNormalizeContact($contactInput);

            if ($account && strlen($onFile) >= 7 && hash_equals($onFile, $entered)) {
                fpRecordAttempt($conn, $usernameValue, true);
                session_regenerate_id(true);
                $_SESSION['fp_reset'] = [
                    'user_id'  => (int)$account['id'],
                    'username' => $account['username'],
                    'role'     => $account['role'],
                    'guard_id' => (string)($account['guard_id'] ?? ''),
                    'expires'  => time() + RESET_WINDOW_SECONDS,
                ];
                $step = 'reset';
                $error = '';
            } else {
                fpRecordAttempt($conn, $usernameValue, false);
                $error = $genericError;
            }
        }
    } elseif (($_POST['action'] ?? '') === 'reset' && is_array($_SESSION['fp_reset'] ?? null)) {
        $reset = $_SESSION['fp_reset'];
        $newPassword = (string)($_POST['new_password'] ?? '');
        $confirmPassword = (string)($_POST['confirm_password'] ?? '');
        $step = 'reset';

        if (strlen($newPassword) < RESET_MIN_PASSWORD_LENGTH) {
            $error = 'Password must be at least ' . RESET_MIN_PASSWORD_LENGTH . ' characters.';
        } elseif ($newPassword !== $confirmPassword) {
            $error = 'Passwords do not match.';
        } else {
            $hash = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ? AND username = ? AND status = 'Active'");
            $stmt->bind_param('sis', $hash, $reset['user_id'], $reset['username']);
            $stmt->execute();
            $updated = $stmt->errno === 0 && $stmt->affected_rows === 1;
            $stmt->close();

            if ($updated) {
                // Guards are identified by guard ID in the audit trail, administrators by username
                $actor = ($reset['role'] === 'Security Guard' && $reset['guard_id'] !== '') ? $reset['guard_id'] : $reset['username'];
                secsysLogActivity($conn, $actor, 'Password Reset', 'Password reset through Forgot Password (verified by contact number).');
                unset($_SESSION['fp_reset']);
                $_SESSION['fp_csrf'] = bin2hex(random_bytes(16));
                $step = 'done';
                $success = 'Your password has been changed. You can now log in with your new password.';
            } else {
                $error = 'Unable to change the password. Please try again.';
            }
        }
    }
}

$resetUsername = (string)($_SESSION['fp_reset']['username'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Forgot Password | <?php echo fpEsc($systemTitle); ?></title>
<?php if (!empty($systemIcon)): ?>
<link rel="icon" href="<?php echo fpEsc($systemIcon); ?>">
<?php endif; ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
* { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Poppins', sans-serif; }
body {
    background: <?php echo !empty($loginBg) ? "url('" . fpEsc($loginBg) . "') center/cover no-repeat fixed, #ededed" : "#ededed"; ?>;
    min-height: 100vh;
    display: flex;
    justify-content: center;
    align-items: center;
}
.container { width: 100%; max-width: 900px; text-align: center; padding: 20px; }
.logo { font-size: 60px; margin-bottom: 5px; }
.title { font-size: 42px; font-weight: 500; color: #111; }
.subtitle { font-size: 24px; margin-bottom: 35px; }
.login-box {
    width: 440px;
    max-width: 100%;
    margin: auto;
    background: #fff;
    padding: 35px;
    border-radius: 28px;
    box-shadow: 0 8px 20px rgba(0,0,0,0.25);
}
.login-box h2 { font-size: 34px; font-weight: 500; margin-bottom: 8px; }
.hint { font-size: 14px; color: #555; margin-bottom: 10px; line-height: 1.5; }
label { display: block; text-align: left; font-size: 18px; margin-bottom: 6px; margin-top: 15px; }
.input-group { position: relative; }
input {
    width: 100%;
    height: 48px;
    border: none;
    background: #ddd;
    border-radius: 8px;
    padding: 0 15px;
    font-size: 18px;
    outline: none;
}
input:focus { box-shadow: 0 0 0 2px #2f80ed; }
.toggle { position: absolute; right: 15px; top: 12px; cursor: pointer; font-size: 22px; color: #888; }
button {
    margin-top: 25px;
    width: 70%;
    height: 48px;
    border: none;
    border-radius: 30px;
    background: #002f6c;
    color: white;
    font-size: 20px;
    cursor: pointer;
}
button:hover { background: #001f4d; }
.link-button {
    margin-top: 12px;
    width: auto;
    height: auto;
    background: none;
    color: #1d4ed8;
    font-size: 15px;
    border-radius: 0;
    text-decoration: underline;
}
.link-button:hover { background: none; color: #1e3a8a; }
.back-link { display: inline-block; margin-top: 16px; font-size: 15px; color: #1d4ed8; }
.error, .success, .note {
    padding: 10px;
    border-radius: 10px;
    margin-bottom: 10px;
    font-size: 14px;
    text-align: left;
}
.error { color: #b42318; background: #fff0f0; border: 1px solid #f0c5c5; }
.success { color: #166534; background: #f0fdf4; border: 1px solid #bbf7d0; }
.note { color: #475569; background: #f8fafc; border: 1px solid #e2e8f0; margin-top: 18px; margin-bottom: 0; font-size: 12.5px; }
@media (max-width: 600px) {
    .title { font-size: 28px; }
    .subtitle { font-size: 16px; }
    .login-box { padding: 25px; }
    .login-box h2 { font-size: 28px; }
}
</style>
</head>
<body>
<div class="container">
    <div class="logo">
        <?php if (!empty($systemIcon)): ?>
            <img src="<?php echo fpEsc($systemIcon); ?>" alt="System Logo" style="width:72px; height:72px; object-fit:contain; border-radius:14px; margin-bottom:6px;">
        <?php else: ?>
            🛡️
        <?php endif; ?>
    </div>
    <div class="title"><?php echo fpEsc($systemTitle); ?></div>
    <div class="subtitle"><?php echo fpEsc($systemSubtitle); ?></div>

    <div class="login-box">
        <h2>Forgot Password</h2>

        <?php if ($error !== ''): ?>
            <div class="error"><?php echo fpEsc($error); ?></div>
        <?php endif; ?>

        <?php if ($step === 'done'): ?>

            <div class="success"><?php echo fpEsc($success); ?></div>
            <a class="back-link" href="login.php">&larr; Back to Log in</a>

        <?php elseif ($step === 'reset'): ?>

            <p class="hint">
                Contact number verified for <strong><?php echo fpEsc($resetUsername); ?></strong>.
                Choose a new password (at least <?php echo RESET_MIN_PASSWORD_LENGTH; ?> characters).
            </p>
            <form method="POST" autocomplete="off">
                <input type="hidden" name="csrf" value="<?php echo fpEsc($_SESSION['fp_csrf']); ?>">
                <input type="hidden" name="action" value="reset">

                <label for="new_password">New Password</label>
                <div class="input-group">
                    <input type="password" name="new_password" id="new_password" required minlength="<?php echo RESET_MIN_PASSWORD_LENGTH; ?>" autocomplete="new-password">
                    <span class="toggle" onclick="togglePassword('new_password')">👁</span>
                </div>

                <label for="confirm_password">Confirm New Password</label>
                <div class="input-group">
                    <input type="password" name="confirm_password" id="confirm_password" required minlength="<?php echo RESET_MIN_PASSWORD_LENGTH; ?>" autocomplete="new-password">
                    <span class="toggle" onclick="togglePassword('confirm_password')">👁</span>
                </div>

                <button type="submit">Change Password</button>
            </form>
            <form method="POST">
                <input type="hidden" name="csrf" value="<?php echo fpEsc($_SESSION['fp_csrf']); ?>">
                <input type="hidden" name="action" value="cancel">
                <button type="submit" class="link-button">Cancel</button>
            </form>

        <?php else: ?>

            <p class="hint">Enter your username and the contact number registered to your account.</p>
            <form method="POST" autocomplete="off">
                <input type="hidden" name="csrf" value="<?php echo fpEsc($_SESSION['fp_csrf']); ?>">
                <input type="hidden" name="action" value="verify">

                <label for="username">Username</label>
                <input type="text" name="username" id="username" required autocomplete="username" value="<?php echo fpEsc($usernameValue); ?>">

                <label for="contact_number">Registered Contact Number</label>
                <input type="tel" name="contact_number" id="contact_number" required inputmode="tel" placeholder="e.g. 09XX XXX XXXX">

                <button type="submit">Verify</button>
            </form>
            <a class="back-link" href="login.php">&larr; Back to Log in</a>

            <div class="note">
                Security guards: your contact number is the one on your guard record.
                Administrators: set your contact number in <strong>Profile</strong>.
                No contact number on file? Ask an administrator to reset your password.
            </div>

        <?php endif; ?>
    </div>
</div>
<script>
function togglePassword(id) {
    const field = document.getElementById(id);
    field.type = field.type === "password" ? "text" : "password";
}
</script>
</body>
</html>
