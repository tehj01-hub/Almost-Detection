<?php

require_once __DIR__ . '/session_helper.php';

/* ===========================================================
   WHICH USER(S) ARE LOGGING OUT?
   -----------------------------------------------------------
   The logout links on each page pass ?role= so only that role's
   session is destroyed. If no role is given (direct access or a
   stale link), both sessions are cleaned up.
=========================================================== */

$roleParam = trim($_GET['role'] ?? '');

$wantGuard =
    ($roleParam === '' || $roleParam === 'Security Guard');

$wantAdmin =
    ($roleParam === '' || $roleParam === 'Administrator');


/* =========================================================
   SECURITY GUARD CLEANUP
   (Only when the guard session is being logged out)
========================================================= */

if ($wantGuard) {

    /* Open the guard session to read its saved data. */
    secsysStartRoleSession('Security Guard');

    $guardID =
        trim($_SESSION["guard_id"] ?? "");

    $partnerGuardID =
        trim($_SESSION["partner_guard_id"] ?? "");

    $role =
        trim($_SESSION["role"] ?? "");

    $username =
        trim($_SESSION["username"] ?? "");


    /* =====================================================
       DATABASE CONNECTION
    ===================================================== */

    $conn = new mysqli(
        "localhost:3307",
        "root",
        "",
        "security_system"
    );

    if (!$conn->connect_error && $role === "Security Guard") {

        $conn->set_charset("utf8mb4");


        /* =================================================
           SET LOGIN STATUS TO INACTIVE (PRIMARY & PARTNER)
        ================================================= */

        $guardsToLogout = array_values(array_filter(array_unique([$guardID, $partnerGuardID])));

        foreach ($guardsToLogout as $gid) {

            $stmt = $conn->prepare("
                UPDATE security_guard
                SET login_status = 'Inactive'
                WHERE guard_id = ?
            ");

            if ($stmt) {

                $stmt->bind_param(
                    "s",
                    $gid
                );

                $stmt->execute();

                $stmt->close();
            }


            /* =============================================
               RECORD LOGOUT ACTIVITY
            ============================================= */

            $checkActivity =
                $conn->query("
                    SHOW TABLES
                    LIKE 'guard_activity'
                ");

            if (
                $checkActivity &&
                $checkActivity->num_rows > 0
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
                            'Logout',
                            ?
                        )
                    ");

                if ($activity) {

                    $description =
                        "Security guard logged out.";

                    $activity->bind_param(
                        "ss",
                        $gid,
                        $description
                    );

                    $activity->execute();

                    $activity->close();
                }
            }
        }

        if ($guardID !== "") {
            @$conn->query("
                UPDATE guard_duty_pairs
                SET ended_at = NOW()
                WHERE primary_guard_id = '" . $conn->real_escape_string($guardID) . "'
                  AND ended_at IS NULL
            ");
        }
    }

    if ($conn) {
        $conn->close();
    }
}


/* =========================================================
   DESTROY SESSION(S)
========================================================= */

if ($roleParam === '') {

    /* No role specified: log out both sessions. */
    secsysDestroyAllSessions();

} else {

    /* Log out only the requested role's session. */
    secsysStartRoleSession($roleParam);

    $_SESSION = [];

    if (ini_get("session.use_cookies")) {

        $params =
            session_get_cookie_params();

        setcookie(
            secsysRoleSessionName($roleParam),
            '',
            time() - 42000,
            $params["path"],
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }

    session_destroy();
}


/* =========================================================
   RETURN TO LOGIN
========================================================= */

header(
    "Location: login.php"
);

exit();
