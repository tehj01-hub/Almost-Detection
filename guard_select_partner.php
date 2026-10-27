<?php
require_once __DIR__ . '/session_helper.php';
require_once __DIR__ . '/system_config.php';

secsysStartRoleSession('Security Guard');

if (
    !isset($_SESSION['username']) ||
    ($_SESSION['role'] ?? '') !== 'Security Guard' ||
    empty($_SESSION['guard_id'])
) {
    header("Location: login.php");
    exit();
}

$conn = new mysqli("localhost:3307", "root", "", "security_system");
if ($conn->connect_error) {
    die("Database Connection Failed: " . $conn->connect_error);
}
$conn->set_charset("utf8mb4");

secsysEnsureSchema($conn);
$systemName = secsysGetSystemName($conn);
$systemIcon = secsysGetSystemIcon($conn);

$primaryGuardId = trim($_SESSION['guard_id']);
$primaryUsername = trim($_SESSION['username']);
$primaryFullName = $primaryUsername;

$stmtPrimary = $conn->prepare("
    SELECT full_name
    FROM security_guard
    WHERE guard_id = ?
    LIMIT 1
");
if ($stmtPrimary) {
    $stmtPrimary->bind_param("s", $primaryGuardId);
    $stmtPrimary->execute();
    $resPrimary = $stmtPrimary->get_result();
    if ($rowPrimary = $resPrimary->fetch_assoc()) {
        if (!empty(trim($rowPrimary['full_name']))) {
            $primaryFullName = trim($rowPrimary['full_name']);
        }
    }
    $stmtPrimary->close();
}
$_SESSION['guard_full_name'] = $primaryFullName;

// Fetch all other security guards who have a user account or guard record
$otherGuards = [];
$stmtGuards = $conn->prepare("
    SELECT
        sg.guard_id,
        sg.full_name,
        sg.status AS guard_status,
        sg.login_status,
        u.username
    FROM security_guard sg
    LEFT JOIN users u
        ON (
            u.guard_id COLLATE utf8mb4_unicode_ci = sg.guard_id COLLATE utf8mb4_unicode_ci
            OR LOWER(u.username COLLATE utf8mb4_unicode_ci) = LOWER(sg.full_name COLLATE utf8mb4_unicode_ci)
        )
       AND u.role = 'Security Guard'
    WHERE sg.guard_id <> ?
    ORDER BY sg.status = 'Active' DESC, sg.full_name ASC
");
if ($stmtGuards) {
    $stmtGuards->bind_param("s", $primaryGuardId);
    $stmtGuards->execute();
    $resGuards = $stmtGuards->get_result();
    while ($gRow = $resGuards->fetch_assoc()) {
        $otherGuards[] = $gRow;
    }
    $stmtGuards->close();
}

$errorMsg = "";
$selectedPartnerId = trim($_POST['partner_guard_id'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mode = $_POST['duty_mode'] ?? 'partner';

    if ($mode === 'solo') {
        // Proceed solo on duty
        unset($_SESSION['partner_guard_id'], $_SESSION['partner_guard_name']);
        $_SESSION['duty_segmented'] = true;

        $insPair = $conn->prepare("
            INSERT INTO guard_duty_pairs (primary_guard_id, partner_guard_id, duty_date, started_at)
            VALUES (?, NULL, CURDATE(), NOW())
        ");
        if ($insPair) {
            $insPair->bind_param("s", $primaryGuardId);
            $insPair->execute();
            $insPair->close();
        }

        $conn->close();
        header("Location: guard_dashboard.php");
        exit();
    }

    $partnerPassword = $_POST['partner_password'] ?? '';

    if ($selectedPartnerId === '') {
        $errorMsg = "Please select a fellow security guard to be on duty with.";
    } elseif ($partnerPassword === '') {
        $errorMsg = "Please enter the selected security guard's password to verify co-duty.";
    } else {
        // Find partner guard & corresponding user record
        $stmtVerify = $conn->prepare("
            SELECT
                sg.guard_id,
                sg.full_name,
                u.id AS user_id,
                u.username,
                u.password
            FROM security_guard sg
            LEFT JOIN users u
                ON (
                    u.guard_id COLLATE utf8mb4_unicode_ci = sg.guard_id COLLATE utf8mb4_unicode_ci
                    OR LOWER(u.username COLLATE utf8mb4_unicode_ci) = LOWER(sg.full_name COLLATE utf8mb4_unicode_ci)
                )
               AND u.role = 'Security Guard'
            WHERE sg.guard_id = ?
            LIMIT 1
        ");

        if ($stmtVerify) {
            $stmtVerify->bind_param("s", $selectedPartnerId);
            $stmtVerify->execute();
            $resVerify = $stmtVerify->get_result();
            $partnerRow = $resVerify->fetch_assoc();
            $stmtVerify->close();

            if (!$partnerRow || empty($partnerRow['password'])) {
                $errorMsg = "Selected security guard does not have a valid login password configured.";
            } else {
                $storedHash = $partnerRow['password'];
                $pwValid = password_verify($partnerPassword, $storedHash) || ($partnerPassword === $storedHash);

                if (!$pwValid) {
                    $errorMsg = "Incorrect password for " . htmlspecialchars($partnerRow['full_name']) . ".";
                } else {
                    $partnerGuardId = trim($partnerRow['guard_id']);
                    $partnerFullName = trim($partnerRow['full_name']);

                    // Set BOTH guards as Active and on duty at the same time
                    $updBoth = $conn->prepare("
                        UPDATE security_guard
                        SET login_status = 'Active',
                            last_seen = NOW()
                        WHERE guard_id IN (?, ?)
                    ");
                    if ($updBoth) {
                        $updBoth->bind_param("ss", $primaryGuardId, $partnerGuardId);
                        $updBoth->execute();
                        $updBoth->close();
                    }

                    // Record co-duty pairing
                    $insPair = $conn->prepare("
                        INSERT INTO guard_duty_pairs (primary_guard_id, partner_guard_id, duty_date, started_at)
                        VALUES (?, ?, CURDATE(), NOW())
                    ");
                    if ($insPair) {
                        $insPair->bind_param("ss", $primaryGuardId, $partnerGuardId);
                        $insPair->execute();
                        $insPair->close();
                    }

                    // Log activity for partner guard as well
                    $actStmt = $conn->prepare("
                        INSERT INTO guard_activity (guard_id, activity_type, activity_description)
                        VALUES (?, 'Co-Duty Login', ?)
                    ");
                    if ($actStmt) {
                        $desc = "On duty together with " . $primaryFullName . " (" . $primaryGuardId . ").";
                        $actStmt->bind_param("ss", $partnerGuardId, $desc);
                        $actStmt->execute();
                        $actStmt->close();
                    }

                    $_SESSION['partner_guard_id'] = $partnerGuardId;
                    $_SESSION['partner_guard_name'] = $partnerFullName;
                    $_SESSION['duty_segmented'] = true;

                    $conn->close();
                    header("Location: guard_dashboard.php");
                    exit();
                }
            }
        } else {
            $errorMsg = "Unable to verify partner security guard.";
        }
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Select Duty Partner | <?php echo htmlspecialchars($systemName); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
    font-family: 'Poppins', sans-serif;
}
body {
    background: #ededed;
    min-height: 100vh;
    display: flex;
    justify-content: center;
    align-items: center;
    padding: 20px;
}
.segment-card {
    width: 100%;
    max-width: 520px;
    background: #ffffff;
    border-radius: 26px;
    padding: 34px 32px;
    box-shadow: 0 10px 28px rgba(0, 0, 0, 0.18);
}
.segment-header {
    text-align: center;
    margin-bottom: 22px;
}
.segment-badge {
    display: inline-block;
    background: #dbeafe;
    color: #1e40af;
    font-size: 12px;
    font-weight: 600;
    padding: 4px 12px;
    border-radius: 999px;
    margin-bottom: 8px;
}
.segment-header h2 {
    font-size: 24px;
    font-weight: 600;
    color: #111827;
}
.segment-header p {
    font-size: 13px;
    color: #4b5563;
    margin-top: 4px;
}
.primary-guard-box {
    background: #f0fdf4;
    border: 1px solid #bbf7d0;
    border-radius: 14px;
    padding: 12px 16px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 20px;
}
.primary-guard-info {
    display: flex;
    flex-direction: column;
}
.primary-guard-label {
    font-size: 11px;
    font-weight: 600;
    color: #15803d;
    text-transform: uppercase;
}
.primary-guard-name {
    font-size: 15px;
    font-weight: 600;
    color: #111827;
}
.status-pill-on {
    background: #22c55e;
    color: #fff;
    font-size: 11px;
    font-weight: 600;
    padding: 4px 10px;
    border-radius: 999px;
}
.error-alert {
    background: #fef2f2;
    border: 1px solid #fecaca;
    color: #b91c1c;
    font-size: 13px;
    padding: 10px 14px;
    border-radius: 10px;
    margin-bottom: 16px;
}
.guard-list-label {
    font-size: 13px;
    font-weight: 600;
    color: #1f2937;
    margin-bottom: 8px;
    display: block;
}
.guard-options {
    display: flex;
    flex-direction: column;
    gap: 10px;
    max-height: 220px;
    overflow-y: auto;
    margin-bottom: 18px;
}
.guard-option {
    border: 2px solid #e5e7eb;
    border-radius: 14px;
    padding: 12px 14px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    cursor: pointer;
    transition: all 0.15s ease;
    background: #f9fafb;
}
.guard-option:hover {
    border-color: #93c5fd;
    background: #eff6ff;
}
.guard-option.selected {
    border-color: #2563eb;
    background: #eff6ff;
}
.guard-option input[type="radio"] {
    accent-color: #2563eb;
    width: 18px;
    height: 18px;
    margin-right: 10px;
}
.guard-option-left {
    display: flex;
    align-items: center;
}
.guard-option-name {
    font-size: 14px;
    font-weight: 600;
    color: #111827;
}
.guard-option-id {
    font-size: 11px;
    color: #6b7280;
}
.password-section {
    background: #f8fafc;
    border: 1px solid #cbd5e1;
    border-radius: 14px;
    padding: 14px 16px;
    margin-bottom: 20px;
    display: none;
}
.password-section.visible {
    display: block;
}
.password-section label {
    display: block;
    font-size: 12px;
    font-weight: 600;
    color: #1e293b;
    margin-bottom: 6px;
}
.pw-input-wrap {
    position: relative;
}
.pw-input-wrap input {
    width: 100%;
    height: 44px;
    border: 1px solid #cbd5e1;
    border-radius: 10px;
    padding: 0 40px 0 12px;
    font-size: 14px;
    outline: none;
    background: #fff;
}
.pw-input-wrap input:focus {
    border-color: #2563eb;
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
}
.pw-toggle {
    position: absolute;
    right: 12px;
    top: 10px;
    cursor: pointer;
    font-size: 18px;
    color: #64748b;
}
.btn-group {
    display: flex;
    flex-direction: column;
    gap: 10px;
}
.btn-confirm-partner {
    width: 100%;
    height: 46px;
    border: none;
    border-radius: 25px;
    background: #002f6c;
    color: #ffffff;
    font-size: 15px;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.15s ease;
}
.btn-confirm-partner:hover {
    background: #001f4d;
}
.btn-solo {
    width: 100%;
    height: 42px;
    border: 1px solid #cbd5e1;
    border-radius: 25px;
    background: #ffffff;
    color: #374151;
    font-size: 13px;
    font-weight: 500;
    cursor: pointer;
    transition: background 0.15s ease;
}
.btn-solo:hover {
    background: #f1f5f9;
}
</style>
</head>
<body>

<div class="segment-card">

    <div class="segment-header">
        <span class="segment-badge">🛡️ <?php echo htmlspecialchars($systemName); ?> &bull; Guard Duty</span>
        <h2>Choose Duty Partner</h2>
        <p>Select a fellow security guard to be on duty with you</p>
    </div>

    <div class="primary-guard-box">
        <div class="primary-guard-info">
            <span class="primary-guard-label">Logged-In Guard</span>
            <span class="primary-guard-name"><?php echo htmlspecialchars($primaryFullName . ' (' . $primaryGuardId . ')'); ?></span>
        </div>
        <span class="status-pill-on">On Duty</span>
    </div>

    <?php if ($errorMsg !== ''): ?>
        <div class="error-alert">
            <?php echo htmlspecialchars($errorMsg); ?>
        </div>
    <?php endif; ?>

    <form method="POST" id="partnerForm">
        <input type="hidden" name="duty_mode" id="dutyModeInput" value="partner">

        <span class="guard-list-label">Select Partner Security Guard:</span>

        <?php if (!empty($otherGuards)): ?>
            <div class="guard-options">
                <?php foreach ($otherGuards as $g):
                    $gid = $g['guard_id'];
                    $gname = $g['full_name'];
                    $isChecked = ($selectedPartnerId === $gid);
                ?>
                    <label class="guard-option <?php echo $isChecked ? 'selected' : ''; ?>" onclick="selectPartnerGuard('<?php echo htmlspecialchars($gid, ENT_QUOTES); ?>', '<?php echo htmlspecialchars($gname, ENT_QUOTES); ?>', this)">
                        <div class="guard-option-left">
                            <input
                                type="radio"
                                name="partner_guard_id"
                                value="<?php echo htmlspecialchars($gid); ?>"
                                <?php echo $isChecked ? 'checked' : ''; ?>
                            >
                            <div>
                                <div class="guard-option-name"><?php echo htmlspecialchars($gname); ?></div>
                                <div class="guard-option-id">ID: <?php echo htmlspecialchars($gid); ?></div>
                            </div>
                        </div>
                        <span style="font-size:12px; color:#2563eb; font-weight:600;">Select &rarr;</span>
                    </label>
                <?php endforeach; ?>
            </div>

            <div class="password-section <?php echo $selectedPartnerId !== '' ? 'visible' : ''; ?>" id="partnerPwSection">
                <label id="partnerPwLabel" for="partnerPasswordInput">
                    Enter Partner Guard's Password to Confirm Co-Duty:
                </label>
                <div class="pw-input-wrap">
                    <input
                        type="password"
                        name="partner_password"
                        id="partnerPasswordInput"
                        placeholder="Partner guard password"
                        autocomplete="off"
                    >
                    <span class="pw-toggle" onclick="togglePartnerPw()">👁</span>
                </div>
            </div>

            <div class="btn-group">
                <button type="submit" class="btn-confirm-partner" onclick="document.getElementById('dutyModeInput').value='partner';">
                    Verify Password &amp; Start Co-Duty
                </button>
                <button type="submit" class="btn-solo" formnovalidate onclick="document.getElementById('dutyModeInput').value='solo';">
                    Continue On Duty Alone
                </button>
            </div>
        <?php else: ?>
            <div style="text-align:center; padding:18px; color:#6b7280; font-size:13px; background:#f8fafc; border-radius:12px; margin-bottom:16px;">
                No other security guard accounts found in the system.
            </div>
            <button type="submit" class="btn-confirm-partner" onclick="document.getElementById('dutyModeInput').value='solo';">
                Continue to Guard Dashboard
            </button>
        <?php endif; ?>
    </form>

</div>

<script>
function selectPartnerGuard(guardId, guardName, labelEl) {
    const allOptions = document.querySelectorAll('.guard-option');
    allOptions.forEach(function(el) {
        el.classList.remove('selected');
    });
    if (labelEl) {
        labelEl.classList.add('selected');
    }
    const pwSection = document.getElementById('partnerPwSection');
    const pwLabel = document.getElementById('partnerPwLabel');
    const pwInput = document.getElementById('partnerPasswordInput');
    if (pwSection) {
        pwSection.classList.add('visible');
    }
    if (pwLabel) {
        pwLabel.textContent = "Enter Password for " + guardName + " (" + guardId + "):";
    }
    if (pwInput) {
        setTimeout(function() { pwInput.focus(); }, 50);
    }
}

function togglePartnerPw() {
    const inp = document.getElementById('partnerPasswordInput');
    if (!inp) return;
    inp.type = inp.type === 'password' ? 'text' : 'password';
}
</script>

</body>
</html>

