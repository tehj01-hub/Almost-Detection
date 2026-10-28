<?php

require_once __DIR__ . '/app/session_helper.php';
require_once __DIR__ . '/app/system_config.php';

/* The login page starts with the Administrator session by default.
   After it discovers which role is actually logging in (from the
   database), it switches to that role's own session via
   secsysSwitchRoleSession(). */
secsysStartRoleSession('Administrator');

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
    die("Connection failed: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");
secsysEnsureSchema($conn);

$systemName     = secsysGetSetting($conn, 'system_name', 'L. Surveillance');
$systemTitle    = secsysGetSetting($conn, 'system_title', "Lessandra’s Surveillance System");
$systemSubtitle = secsysGetSetting($conn, 'system_subtitle', 'Phase 2, Salinas 1, Bacoor, Cavite');
$systemIcon     = secsysGetSetting($conn, 'system_icon', '');
$loginBg        = secsysGetSetting($conn, 'login_background', '');


/* =========================================================
   HELPER
========================================================= */

function e($value)
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );
}


/* =========================================================
   MAKE SURE SECURITY GUARD TABLE HAS LOGIN STATUS
========================================================= */

$checkGuardTable = $conn->query(
    "SHOW TABLES LIKE 'security_guard'"
);

if (
    $checkGuardTable &&
    $checkGuardTable->num_rows > 0
) {

    $checkLoginStatus = $conn->query("
        SHOW COLUMNS
        FROM security_guard
        LIKE 'login_status'
    ");

    if (
        $checkLoginStatus &&
        $checkLoginStatus->num_rows === 0
    ) {

        $conn->query("
            ALTER TABLE security_guard
            ADD COLUMN login_status
            ENUM('Active','Inactive')
            NOT NULL DEFAULT 'Inactive'
            AFTER status
        ");
    }
}


/* =========================================================
   MAKE SURE USERS TABLE HAS GUARD_ID
========================================================= */

$userColumns = [];

$userColumnResult = $conn->query(
    "SHOW COLUMNS FROM users"
);

if ($userColumnResult) {

    while (
        $column =
        $userColumnResult->fetch_assoc()
    ) {

        $userColumns[] =
            $column['Field'];
    }
}

if (
    in_array('username', $userColumns) &&
    !in_array('guard_id', $userColumns)
) {

    $conn->query("
        ALTER TABLE users
        ADD COLUMN guard_id VARCHAR(30)
        DEFAULT NULL
        AFTER username
    ");

    $userColumns[] = 'guard_id';
}


/* =========================================================
   LOGIN PROCESS
========================================================= */

$message = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $username =
        trim($_POST["username"] ?? "");

    $password =
        $_POST["password"] ?? "";


    /* =====================================================
       VALIDATION
    ===================================================== */

    if (
        $username === "" ||
        $password === ""
    ) {

        $message =
            "Please enter your username and password.";

    } else {

        /* =================================================
           FIND USER
        ================================================= */

        $stmt = $conn->prepare("
            SELECT *
            FROM users
            WHERE username = ?
            LIMIT 1
        ");

        if (!$stmt) {

            $message =
                "Unable to process login.";

        } else {

            $stmt->bind_param(
                "s",
                $username
            );

            $stmt->execute();

            $result =
                $stmt->get_result();


            /* =============================================
               USER FOUND
            ============================================== */

            if ($result->num_rows > 0) {

                $row =
                    $result->fetch_assoc();


                /* =========================================
                   CHECK PASSWORD
                ========================================== */

                $storedPassword =
                    $row["password"] ?? "";

                $passwordCorrect =
                    password_verify(
                        $password,
                        $storedPassword
                    );


                /*
                   Backward compatibility for older
                   administrator accounts using plaintext.
                */

                if (
                    !$passwordCorrect &&
                    $password === $storedPassword
                ) {

                    $passwordCorrect = true;
                }


                if ($passwordCorrect) {


                    /* =====================================
                       CHECK USER STATUS
                    ====================================== */

                    if (
                        isset($row["status"]) &&
                        strtolower(
                            trim($row["status"])
                        ) !== "active"
                    ) {

                        $message =
                            "This account is inactive.";

                    } else {


                        /* =================================
                           NORMALIZE ROLE
                        ================================== */

                        $role =
                            trim(
                                $row["role"] ?? ""
                            );


                        /* =================================
                           ONLY ALLOW THE TWO ROLES
                        ================================== */

                        if (
                            $role !== "Administrator" &&
                            $role !== "Security Guard"
                        ) {

                            $message =
                                "Invalid user role.";

                        } else {


                            /* =================================
                               SECURITY GUARD
                            ================================== */

                            if (
                                $role ===
                                "Security Guard"
                            ) {

                                $guardID = "";


                                /* =============================
                                   GET GUARD ID FROM USERS
                                ============================== */

                                if (
                                    isset($row["guard_id"]) &&
                                    trim(
                                        $row["guard_id"]
                                    ) !== ""
                                ) {

                                    $guardID =
                                        trim(
                                            $row["guard_id"]
                                        );
                                }


                                /* =============================
                                   FALLBACK:
                                   MATCH USERNAME TO FULL NAME
                                ============================== */

                                if ($guardID === "") {

                                    $findGuard =
                                        $conn->prepare("
                                            SELECT guard_id
                                            FROM security_guard
                                            WHERE full_name = ?
                                            LIMIT 1
                                        ");

                                    if ($findGuard) {

                                        $findGuard->bind_param(
                                            "s",
                                            $username
                                        );

                                        $findGuard->execute();

                                        $guardResult =
                                            $findGuard->get_result();

                                        $guardRow =
                                            $guardResult->fetch_assoc();

                                        if ($guardRow) {

                                            $guardID =
                                                trim(
                                                    $guardRow["guard_id"] ??
                                                    ""
                                                );
                                        }

                                        $findGuard->close();
                                    }
                                }


                                /* =============================
                                   GUARD MUST BE LINKED
                                ============================== */

                                if ($guardID === "") {

                                    $message =
                                        "This security guard account is not linked to a security guard record.";

                                } else {


                                    /* =============================
                                       GET GUARD RECORD
                                    ============================== */

                                    $guardCheck =
                                        $conn->prepare("
                                            SELECT
                                                ID,
                                                guard_id,
                                                full_name,
                                                status
                                            FROM security_guard
                                            WHERE guard_id = ?
                                            LIMIT 1
                                        ");

                                    if (!$guardCheck) {

                                        $message =
                                            "Unable to verify security guard account.";

                                    } else {

                                        $guardCheck->bind_param(
                                            "s",
                                            $guardID
                                        );

                                        $guardCheck->execute();

                                        $guardCheckResult =
                                            $guardCheck->get_result();

                                        $guardRow =
                                            $guardCheckResult->fetch_assoc();


                                        if (!$guardRow) {

                                            $message =
                                                "Security guard record could not be found.";

                                        } elseif (
                                            trim(
                                                $guardRow["status"]
                                            ) !== "Active"
                                        ) {

                                            $message =
                                                "This security guard account is not active.";

                                        } else {


                                            /* =================
                                               PREVENT SESSION
                                               MIX-UP
                                            ================== */

                                            secsysSwitchRoleSession(
                                                "Security Guard"
                                            );


                                            /* =================
                                               CREATE SESSION
                                            ================== */

                                            $_SESSION = [];

                                            $_SESSION["id"] =
                                                $row["id"] ?? null;

                                            $_SESSION["username"] =
                                                $row["username"];

                                            $_SESSION["role"] =
                                                "Security Guard";

                                            $_SESSION["guard_id"] =
                                                $guardID;

                                            $_SESSION["guard_logged_in"] =
                                                true;

                                            $_SESSION["login_time"] =
                                                date(
                                                    "Y-m-d H:i:s"
                                                );


                                            /* =================
                                               SET ACTIVE
                                            ================== */

                                            $updateLoginStatus =
                                                $conn->prepare("
                                                    UPDATE security_guard
                                                    SET login_status = 'Active'
                                                    WHERE guard_id = ?
                                                ");

                                            if (
                                                $updateLoginStatus
                                            ) {

                                                $updateLoginStatus->bind_param(
                                                    "s",
                                                    $guardID
                                                );

                                                $updateLoginStatus->execute();

                                                $updateLoginStatus->close();
                                            }


                                            /* =================
                                               GUARD ACTIVITY
                                            ================== */

                                            $activityTable =
                                                $conn->query("
                                                    SHOW TABLES
                                                    LIKE 'guard_activity'
                                                ");

                                            if (
                                                $activityTable &&
                                                $activityTable->num_rows > 0
                                            ) {

                                                $activity =
                                                    $conn->prepare("
                                                        INSERT INTO guard_activity
                                                        (
                                                            guard_id,
                                                            activity_type,
                                                            activity_description
                                                        )
                                                        VALUES
                                                        (
                                                            ?,
                                                            'Login',
                                                            ?
                                                        )
                                                    ");

                                                if ($activity) {

                                                    $description =
                                                        "Security guard logged in.";

                                                    $activity->bind_param(
                                                        "ss",
                                                        $guardID,
                                                        $description
                                                    );

                                                    $activity->execute();

                                                    $activity->close();
                                                }
                                            }


                                            $guardCheck->close();


                                            /* =================
                                               GUARD DASHBOARD
                                            ================== */

                                            header(
                                                "Location: guard_select_partner.php"
                                            );

                                            exit();
                                        }

                                        $guardCheck->close();
                                    }
                                }


                            /*
                             =================================
                             ADMINISTRATOR
                             =================================
                            */

                            } elseif (
                                $role ===
                                "Administrator"
                            ) {


                                /* =============================
                                   REMOVE ANY OLD GUARD SESSION
                                ============================== */

                                unset(
                                    $_SESSION["guard_id"],
                                    $_SESSION["guard_logged_in"],
                                    $_SESSION["login_time"]
                                );


                                /* =============================
                                   SWITCH TO THE ADMIN SESSION
                                ============================== */

                                secsysSwitchRoleSession(
                                    "Administrator"
                                );


                                $_SESSION["id"] =
                                    $row["id"] ?? null;

                                $_SESSION["username"] =
                                    $row["username"];

                                $_SESSION["role"] =
                                    "Administrator";


                                /* =============================
                                   ADMIN DASHBOARD
                                ============================== */

                                header(
                                    "Location: superadmin_dashboard.php"
                                );

                                exit();
                            }
                        }
                    }

                } else {

                    $message =
                        "Wrong Password!";
                }

            } else {

                $message =
                    "Username not found!";
            }

            $stmt->close();
        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport"
      content="width=device-width, initial-scale=1.0">

<title><?php echo e($systemName); ?> - Login</title>
<?php if (!empty($systemIcon)): ?>
<link rel="icon" href="<?php echo e($systemIcon); ?>">
<?php endif; ?>

<link rel="preconnect"
      href="https://fonts.googleapis.com">

<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap"
      rel="stylesheet">

<style>

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
    font-family: 'Poppins', sans-serif;
}

body {
    background: <?php echo !empty($loginBg) ? "url('" . e($loginBg) . "') center/cover no-repeat fixed, #ededed" : "#ededed"; ?>;
    min-height: 100vh;

    display: flex;
    justify-content: center;
    align-items: center;
}

.container {
    width: 100%;
    max-width: 900px;

    text-align: center;

    padding: 20px;
}

.logo {
    font-size: 60px;
    margin-bottom: 5px;
}

.title {
    font-size: 42px;
    font-weight: 500;
    color: #111;
}

.subtitle {
    font-size: 24px;
    margin-bottom: 35px;
}

.login-box {
    width: 440px;
    max-width: 100%;

    margin: auto;

    background: #fff;

    padding: 35px;

    border-radius: 28px;

    box-shadow:
        0 8px 20px rgba(0,0,0,0.25);
}

.login-box h2 {
    font-size: 42px;
    font-weight: 500;
    margin-bottom: 20px;
}

label {
    display: block;

    text-align: left;

    font-size: 18px;

    margin-bottom: 6px;
    margin-top: 15px;
}

.input-group {
    position: relative;
}

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

input:focus {
    box-shadow:
        0 0 0 2px #2f80ed;
}

.toggle {
    position: absolute;

    right: 15px;
    top: 12px;

    cursor: pointer;

    font-size: 22px;

    color: #888;
}

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

button:hover {
    background: #001f4d;
}

.error {
    color: #b42318;

    background: #fff0f0;

    border: 1px solid #f0c5c5;

    padding: 10px;

    border-radius: 10px;

    margin-bottom: 10px;

    font-size: 14px;
}

@media(max-width:600px) {

    .title {
        font-size: 28px;
    }

    .subtitle {
        font-size: 16px;
    }

    .login-box {
        padding: 25px;
    }

    .login-box h2 {
        font-size: 32px;
    }
}

</style>

</head>

<body>

<div class="container">

    <div class="logo">
        <?php if (!empty($systemIcon)): ?>
            <img src="<?php echo e($systemIcon); ?>" alt="System Logo" style="width:72px; height:72px; object-fit:contain; border-radius:14px; margin-bottom:6px;">
        <?php else: ?>
            🛡️
        <?php endif; ?>
    </div>

    <div class="title">
        <?php echo e($systemTitle); ?>
    </div>

    <div class="subtitle">
        <?php echo e($systemSubtitle); ?>
    </div>

    <div class="login-box">

        <h2>Log in</h2>

        <?php if ($message !== ""): ?>

            <div class="error">
                <?php echo e($message); ?>
            </div>

        <?php endif; ?>

        <form method="POST">

            <label>
                Username
            </label>

            <input
                type="text"
                name="username"
                required
                autocomplete="username"
            >

            <label>
                Password
            </label>

            <div class="input-group">

                <input
                    type="password"
                    name="password"
                    id="password"
                    required
                    autocomplete="current-password"
                >

                <span
                    class="toggle"
                    onclick="togglePassword()"
                >
                    👁
                </span>

            </div>

            <button type="submit">
                Submit
            </button>

        </form>

    </div>

</div>

<script>

function togglePassword()
{
    const pass =
        document.getElementById("password");

    if (pass.type === "password") {

        pass.type = "text";

    } else {

        pass.type = "password";
    }
}

</script>

</body>

</html>

<?php

$conn->close();

?>