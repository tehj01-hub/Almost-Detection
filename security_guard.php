<?php

require_once __DIR__ . '/session_helper.php';
require_once __DIR__ . '/system_config.php';
secsysStartRoleSession('Administrator');


/* =========================================================
   ADMIN LOGIN CHECK
========================================================= */

if (!isset($_SESSION['username'])) {

    header("Location: login.php");
    exit();

}


/*
   Security Guard management should normally be accessible
   by the Administrator.
*/

if (
    isset($_SESSION['role']) &&
    $_SESSION['role'] !== 'Administrator'
) {

    header("Location: guard_dashboard.php");
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

    die(
        "Database Connection Failed: " .
        $conn->connect_error
    );

}

$conn->set_charset("utf8mb4");
secsysEnsureSchema($conn);
$systemName = secsysGetSystemName($conn);
$systemIcon = secsysGetSystemIcon($conn);


/* =========================================================
   HELPER FUNCTIONS
========================================================= */

function e($value)
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );
}


function normalizeTime($time)
{
    if (!$time) {
        return "";
    }

    $timestamp = strtotime($time);

    if ($timestamp === false) {
        return "";
    }

    return date(
        "H:i:s",
        $timestamp
    );
}


function formatTime($time)
{
    if (!$time) {
        return "";
    }

    $timestamp = strtotime($time);

    if ($timestamp === false) {
        return "";
    }

    return date(
        "g:i A",
        $timestamp
    );
}


function generateGuardID($conn)
{

    $number = 1;

    $result = $conn->query("
        SELECT guard_id
        FROM security_guard
        ORDER BY ID DESC
        LIMIT 1
    ");

    if (
        $result &&
        $row = $result->fetch_assoc()
    ) {

        if (
            preg_match(
                '/(\d+)$/',
                $row['guard_id'],
                $match
            )
        ) {

            $number =
                (int)$match[1] + 1;

        }

    }


    do {

        $guardID =
            "SG-" .
            str_pad(
                $number,
                4,
                "0",
                STR_PAD_LEFT
            );


        $check = $conn->prepare("
            SELECT ID
            FROM security_guard
            WHERE guard_id = ?
            LIMIT 1
        ");

        $check->bind_param(
            "s",
            $guardID
        );

        $check->execute();

        $checkResult =
            $check->get_result();

        $exists =
            $checkResult->num_rows > 0;

        $check->close();


        if ($exists) {
            $number++;
        }

    } while ($exists);


    return $guardID;
}


/* =========================================================
   CREATE SECURITY GUARD TABLE
========================================================= */

$conn->query("
    CREATE TABLE IF NOT EXISTS security_guard (

        ID INT AUTO_INCREMENT PRIMARY KEY,

        guard_id VARCHAR(30)
        NOT NULL UNIQUE,

        full_name VARCHAR(150)
        NOT NULL,

        contact_number VARCHAR(30)
        DEFAULT '',

        address VARCHAR(255)
        DEFAULT '',

        date_hired DATE
        NOT NULL,

        shift_id INT
        DEFAULT NULL,

        assignment VARCHAR(100)
        DEFAULT '',

        status ENUM(
            'Active',
            'Inactive',
            'Archived'
        )
        NOT NULL DEFAULT 'Active',

        login_status ENUM(
            'Active',
            'Inactive'
        )
        NOT NULL DEFAULT 'Inactive',

        last_seen DATETIME
        DEFAULT NULL,

        date_created DATETIME
        NOT NULL DEFAULT CURRENT_TIMESTAMP,

        archived_at DATETIME
        DEFAULT NULL

    )
    ENGINE=InnoDB
    DEFAULT CHARSET=utf8mb4
");


/* =========================================================
   ADD MISSING COLUMNS
========================================================= */

$columnsToAdd = [

    "contact_number" =>
        "ALTER TABLE security_guard
         ADD COLUMN contact_number VARCHAR(30)
         DEFAULT '' AFTER full_name",

    "shift_id" =>
        "ALTER TABLE security_guard
         ADD COLUMN shift_id INT
         DEFAULT NULL AFTER date_hired",

    "assignment" =>
        "ALTER TABLE security_guard
         ADD COLUMN assignment VARCHAR(100)
         DEFAULT '' AFTER shift_id",

    "login_status" =>
        "ALTER TABLE security_guard
         ADD COLUMN login_status ENUM('Active','Inactive')
         NOT NULL DEFAULT 'Inactive' AFTER status",

    "last_seen" =>
        "ALTER TABLE security_guard
         ADD COLUMN last_seen DATETIME
         DEFAULT NULL AFTER login_status",

    "archived_at" =>
        "ALTER TABLE security_guard
         ADD COLUMN archived_at DATETIME
         DEFAULT NULL AFTER date_created"

];


foreach (
    $columnsToAdd
    as $column => $alterSQL
) {

    $checkColumn = $conn->query("
        SHOW COLUMNS
        FROM security_guard
        LIKE '$column'
    ");

    if (
        $checkColumn &&
        $checkColumn->num_rows === 0
    ) {

        $conn->query($alterSQL);

    }

}


/* =========================================================
   AUTO-OFFLINE / GUARD STATUS REFRESH
   A guard is considered offline when no heartbeat has
   been received for 30 seconds.
========================================================= */

$conn->query("
    UPDATE security_guard
    SET login_status = 'Inactive'
    WHERE status = 'Active'
    AND login_status = 'Active'
    AND last_seen IS NOT NULL
    AND last_seen < (NOW() - INTERVAL 30 SECOND)
");


/* =========================================================
   AJAX GUARD STATUS ENDPOINT
   Used by the Administrator page to update statuses
   automatically without a manual page refresh.
========================================================= */

if (
    isset($_GET['ajax']) &&
    $_GET['ajax'] === 'guard_status'
) {

    header("Content-Type: application/json; charset=utf-8");

    $statusData = [];

    $statusResult = $conn->query("
        SELECT
            guard_id,
            login_status
        FROM security_guard
        WHERE status = 'Active'
    ");

    if ($statusResult) {

        while (
            $statusRow =
            $statusResult->fetch_assoc()
        ) {

            $statusData[
                $statusRow['guard_id']
            ] =
                $statusRow['login_status'];

        }

    }

    $countResult = $conn->query("
        SELECT

            SUM(
                status = 'Active'
            ) AS active_count,

            SUM(
                status = 'Archived'
            ) AS archived_count,

            SUM(
                status = 'Inactive'
            ) AS inactive_count,

            SUM(
                status = 'Active'
                AND login_status = 'Active'
            ) AS logged_in_count

        FROM security_guard
    ");

    $counts = [
        'active' => 0,
        'archived' => 0,
        'inactive' => 0,
        'logged_in' => 0
    ];

    if (
        $countResult &&
        $countRow =
        $countResult->fetch_assoc()
    ) {

        $counts = [
            'active' =>
                (int)$countRow['active_count'],

            'archived' =>
                (int)$countRow['archived_count'],

            'inactive' =>
                (int)$countRow['inactive_count'],

            'logged_in' =>
                (int)$countRow['logged_in_count']
        ];

    }

    echo json_encode([
        'success' => true,
        'guards' => $statusData,
        'counts' => $counts
    ]);

    $conn->close();
    exit();
}


/* =========================================================
   CREATE SHIFT TABLE
========================================================= */

$conn->query("
    CREATE TABLE IF NOT EXISTS guard_shifts (

        id INT AUTO_INCREMENT PRIMARY KEY,

        shift_name VARCHAR(100)
        NOT NULL UNIQUE,

        start_time TIME
        NOT NULL,

        end_time TIME
        NOT NULL,

        status ENUM(
            'Active',
            'Inactive'
        )
        NOT NULL DEFAULT 'Active',

        date_created DATETIME
        NOT NULL DEFAULT CURRENT_TIMESTAMP,

        date_updated DATETIME
        DEFAULT NULL

    )
    ENGINE=InnoDB
    DEFAULT CHARSET=utf8mb4
");


/* =========================================================
   CREATE DEFAULT MORNING SHIFT
========================================================= */

$checkMorning = $conn->query("
    SELECT id
    FROM guard_shifts
    WHERE shift_name = 'Morning Shift'
    LIMIT 1
");

if (
    $checkMorning &&
    $checkMorning->num_rows === 0
) {

    $conn->query("
        INSERT INTO guard_shifts
        (
            shift_name,
            start_time,
            end_time,
            status
        )
        VALUES
        (
            'Morning Shift',
            '06:00:00',
            '18:00:00',
            'Active'
        )
    ");

}


/* =========================================================
   CREATE DEFAULT NIGHT SHIFT
========================================================= */

$checkNight = $conn->query("
    SELECT id
    FROM guard_shifts
    WHERE shift_name = 'Night Shift'
    LIMIT 1
");

if (
    $checkNight &&
    $checkNight->num_rows === 0
) {

    $conn->query("
        INSERT INTO guard_shifts
        (
            shift_name,
            start_time,
            end_time,
            status
        )
        VALUES
        (
            'Night Shift',
            '18:00:00',
            '06:00:00',
            'Active'
        )
    ");

}


/* =========================================================
   CREATE ACTIVITY TABLE
========================================================= */

$conn->query("
    CREATE TABLE IF NOT EXISTS guard_activity (

        id INT AUTO_INCREMENT PRIMARY KEY,

        guard_id VARCHAR(30)
        NOT NULL,

        activity_type VARCHAR(100)
        NOT NULL,

        activity_description TEXT
        DEFAULT '',

        date_created DATETIME
        NOT NULL DEFAULT CURRENT_TIMESTAMP

    )
    ENGINE=InnoDB
    DEFAULT CHARSET=utf8mb4
");


/* =========================================================
   USERS TABLE COLUMNS
========================================================= */

$userColumns = [];

$userColumnResult =
    $conn->query(
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


/* =========================================================
   ADD GUARD_ID TO USERS TABLE
========================================================= */

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
   MESSAGE
========================================================= */

$message = "";
$messageType = "";


/* =========================================================
   ADD SECURITY GUARD
========================================================= */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'add_guard'
) {

    $fullName =
        trim(
            $_POST['full_name'] ?? ''
        );

    $contactNumber =
        trim(
            $_POST['contact_number'] ?? ''
        );

    $address =
        trim(
            $_POST['address'] ?? ''
        );

    $dateHired =
        date('Y-m-d');

    $shiftID =
        (int)(
            $_POST['shift_id'] ?? 0
        );

    $assignment = '';

    $username =
        trim(
            $_POST['username'] ?? ''
        );

    $password =
        $_POST['password'] ?? '';

    $confirmPassword =
        $_POST['confirm_password'] ?? '';


    if (
        $fullName === '' ||
        $contactNumber === '' ||
        $address === '' ||
        $shiftID <= 0 ||
        $username === '' ||
        $password === ''
    ) {

        $message =
            "Please complete all required fields.";

        $messageType = "error";

    }

    elseif (
        $password !== $confirmPassword
    ) {

        $message =
            "Passwords do not match.";

        $messageType = "error";

    }

    elseif (
        strlen($password) < 6
    ) {

        $message =
            "Password must be at least 6 characters.";

        $messageType = "error";

    }

    else {

        /* =============================================
           CHECK USERNAME
        ============================================== */

        $usernameExists = false;

        if (
            in_array(
                'username',
                $userColumns
            )
        ) {

            $checkUser =
                $conn->prepare("
                    SELECT username
                    FROM users
                    WHERE username = ?
                    LIMIT 1
                ");

            if ($checkUser) {

                $checkUser->bind_param(
                    "s",
                    $username
                );

                $checkUser->execute();

                $userResult =
                    $checkUser->get_result();

                $usernameExists =
                    $userResult->num_rows > 0;

                $checkUser->close();

            }

        }


        if ($usernameExists) {

            $message =
                "That username is already in use.";

            $messageType = "error";

        }

        else {

            /* =========================================
               GENERATE GUARD ID
            ========================================== */

            $guardID =
                generateGuardID($conn);


            /* =========================================
               HASH PASSWORD
            ========================================== */

            $hashedPassword =
                password_hash(
                    $password,
                    PASSWORD_DEFAULT
                );


            /* =========================================
               START TRANSACTION
            ========================================== */

            $conn->begin_transaction();


            try {

                /* =====================================
                   INSERT SECURITY GUARD
                ====================================== */

                $stmt =
                    $conn->prepare("
                        INSERT INTO security_guard
                        (
                            guard_id,
                            full_name,
                            contact_number,
                            address,
                            date_hired,
                            shift_id,
                            assignment,
                            status,
                            login_status
                        )
                        VALUES
                        (
                            ?,
                            ?,
                            ?,
                            ?,
                            ?,
                            ?,
                            ?,
                            'Active',
                            'Inactive'
                        )
                    ");

                if (!$stmt) {
                    throw new Exception(
                        "Unable to prepare guard record."
                    );
                }


                $stmt->bind_param(
                    "sssssis",
                    $guardID,
                    $fullName,
                    $contactNumber,
                    $address,
                    $dateHired,
                    $shiftID,
                    $assignment
                );


                if (!$stmt->execute()) {

                    throw new Exception(
                        "Unable to add security guard: " .
                        $stmt->error
                    );

                }

                $stmt->close();


                /* =====================================
                   CREATE USER ACCOUNT
                ====================================== */

                if (
                    !in_array(
                        'username',
                        $userColumns
                    ) ||
                    !in_array(
                        'password',
                        $userColumns
                    )
                ) {

                    throw new Exception(
                        "The users table does not contain username/password columns."
                    );

                }


                $userFields = [
                    'username',
                    'password'
                ];

                $userValues = [
                    $username,
                    $hashedPassword
                ];

                $userTypes = "ss";


                /* ROLE */

                if (
                    in_array(
                        'role',
                        $userColumns
                    )
                ) {

                    $userFields[] = 'role';

                    $userValues[] =
                        'Security Guard';

                    $userTypes .= "s";

                }


                /* USER STATUS */

                if (
                    in_array(
                        'status',
                        $userColumns
                    )
                ) {

                    $userFields[] = 'status';

                    $userValues[] =
                        'Active';

                    $userTypes .= "s";

                }


                /* GUARD ID */

                if (
                    in_array(
                        'guard_id',
                        $userColumns
                    )
                ) {

                    $userFields[] =
                        'guard_id';

                    $userValues[] =
                        $guardID;

                    $userTypes .= "s";

                }


                /* DATE CREATED */

                if (
                    in_array(
                        'date_created',
                        $userColumns
                    )
                ) {

                    $userFields[] =
                        'date_created';

                    $userValues[] =
                        date(
                            "Y-m-d H:i:s"
                        );

                    $userTypes .= "s";

                }


                $fieldList =
                    implode(
                        ",",
                        $userFields
                    );

                $placeholders =
                    implode(
                        ",",
                        array_fill(
                            0,
                            count($userFields),
                            "?"
                        )
                    );


                $sqlUser = "
                    INSERT INTO users
                    ($fieldList)
                    VALUES
                    ($placeholders)
                ";


                $userStmt =
                    $conn->prepare(
                        $sqlUser
                    );

                if (!$userStmt) {

                    throw new Exception(
                        "Unable to create login account."
                    );

                }


                $bindParams = [];

                $bindParams[] =
                    $userTypes;


                foreach (
                    $userValues
                    as $key => $value
                ) {

                    $bindParams[] =
                        &$userValues[$key];

                }


                call_user_func_array(
                    [
                        $userStmt,
                        'bind_param'
                    ],
                    $bindParams
                );


                if (
                    !$userStmt->execute()
                ) {

                    throw new Exception(
                        "Unable to create login account: " .
                        $userStmt->error
                    );

                }


                $userStmt->close();


                /* =====================================
                   ACTIVITY
                ====================================== */

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
                            'Guard Added',
                            ?
                        )
                    ");

                if ($activity) {

                    $description =
                        "Security guard account created for " .
                        $fullName .
                        ".";

                    $activity->bind_param(
                        "ss",
                        $guardID,
                        $description
                    );

                    $activity->execute();

                    $activity->close();

                }


                /* =====================================
                   COMMIT
                ====================================== */

                $conn->commit();


                $message =
                    "Security guard added successfully. Guard ID: " .
                    $guardID .
                    ". Login account created.";

                $messageType = "success";


            }

            catch (Exception $e) {

                $conn->rollback();

                $message =
                    $e->getMessage();

                $messageType = "error";

            }

        }

    }

}


/* =========================================================
   UPDATE SECURITY GUARD
========================================================= */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'update_guard'
) {

    $id =
        (int)(
            $_POST['id'] ?? 0
        );

    $fullName =
        trim(
            $_POST['full_name'] ?? ''
        );

    $contactNumber =
        trim(
            $_POST['contact_number'] ?? ''
        );

    $address =
        trim(
            $_POST['address'] ?? ''
        );

    $dateHired =
        trim(
            $_POST['date_hired'] ?? ''
        );

    $shiftID =
        (int)(
            $_POST['shift_id'] ?? 0
        );

    $assignment =
        trim(
            $_POST['assignment'] ?? ''
        );


    if (
        $id <= 0 ||
        $fullName === '' ||
        $contactNumber === '' ||
        $address === '' ||
        $dateHired === '' ||
        $shiftID <= 0
    ) {

        $message =
            "Please complete all required fields.";

        $messageType = "error";

    }

    else {

        $stmt =
            $conn->prepare("
                UPDATE security_guard
                SET
                    full_name = ?,
                    contact_number = ?,
                    address = ?,
                    date_hired = ?,
                    shift_id = ?,
                    assignment = ?
                WHERE ID = ?
            ");


        $stmt->bind_param(
            "ssssisi",
            $fullName,
            $contactNumber,
            $address,
            $dateHired,
            $shiftID,
            $assignment,
            $id
        );


        if ($stmt->execute()) {

            $message =
                "Security guard information updated successfully.";

            $messageType = "success";

        }

        else {

            $message =
                "Unable to update security guard.";

            $messageType = "error";

        }


        $stmt->close();

    }

}


/* =========================================================
   CHANGE GUARD SHIFT
========================================================= */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'change_guard_shift'
) {

    $id =
        (int)(
            $_POST['id'] ?? 0
        );

    $shiftID =
        (int)(
            $_POST['shift_id'] ?? 0
        );


    if (
        $id > 0 &&
        $shiftID > 0
    ) {

        $stmt =
            $conn->prepare("
                UPDATE security_guard
                SET shift_id = ?
                WHERE ID = ?
                AND status = 'Active'
            ");


        $stmt->bind_param(
            "ii",
            $shiftID,
            $id
        );


        if ($stmt->execute()) {

            $message =
                "Guard shift changed successfully.";

            $messageType = "success";

        }

        else {

            $message =
                "Unable to change guard shift.";

            $messageType = "error";

        }


        $stmt->close();

    }

}


/* =========================================================
   UPDATE SHIFT TIME
========================================================= */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'update_shift'
) {

    $shiftID =
        (int)(
            $_POST['shift_id'] ?? 0
        );

    $startTime =
        normalizeTime(
            $_POST['start_time'] ?? ''
        );

    $endTime =
        normalizeTime(
            $_POST['end_time'] ?? ''
        );


    if (
        $shiftID <= 0 ||
        $startTime === '' ||
        $endTime === ''
    ) {

        $message =
            "Please provide valid shift times.";

        $messageType = "error";

    }

    else {

        $stmt =
            $conn->prepare("
                UPDATE guard_shifts
                SET
                    start_time = ?,
                    end_time = ?,
                    date_updated = NOW()
                WHERE id = ?
            ");


        $stmt->bind_param(
            "ssi",
            $startTime,
            $endTime,
            $shiftID
        );


        if ($stmt->execute()) {

            $message =
                "Shift schedule updated successfully.";

            $messageType = "success";

        }

        else {

            $message =
                "Unable to update shift schedule.";

            $messageType = "error";

        }


        $stmt->close();

    }

}


/* =========================================================
   ARCHIVE GUARD
========================================================= */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'archive_guard'
) {

    $id =
        (int)(
            $_POST['id'] ?? 0
        );

    $archiveReason =
        trim(
            $_POST['archive_reason']
            ?? 'Resigned'
        );


    if ($id > 0) {

        $guardQuery =
            $conn->prepare("
                SELECT
                    guard_id,
                    full_name
                FROM security_guard
                WHERE ID = ?
                LIMIT 1
            ");

        $guardQuery->bind_param(
            "i",
            $id
        );

        $guardQuery->execute();

        $guardResult =
            $guardQuery->get_result();


        if (
            $guardRow =
            $guardResult->fetch_assoc()
        ) {

            $guardID =
                $guardRow['guard_id'];

            $guardName =
                $guardRow['full_name'];

            $guardQuery->close();


            /*
               Archive guard AND force login status inactive.
            */

            $stmt =
                $conn->prepare("
                    UPDATE security_guard
                    SET
                        status = 'Archived',
                        login_status = 'Inactive',
                        last_seen = NULL,
                        archived_at = NOW()
                    WHERE ID = ?
                ");

            $stmt->bind_param(
                "i",
                $id
            );


            if ($stmt->execute()) {

                $stmt->close();


                /*
                   Disable login account.
                */

                if (
                    in_array(
                        'guard_id',
                        $userColumns
                    ) &&
                    in_array(
                        'status',
                        $userColumns
                    )
                ) {

                    $disableUser =
                        $conn->prepare("
                            UPDATE users
                            SET status = 'Inactive'
                            WHERE guard_id = ?
                        ");

                    $disableUser->bind_param(
                        "s",
                        $guardID
                    );

                    $disableUser->execute();

                    $disableUser->close();

                }


                /*
                   Activity.
                */

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
                            'Guard Archived',
                            ?
                        )
                    ");


                if ($activity) {

                    $description =
                        $guardName .
                        " was archived. Reason: " .
                        $archiveReason;

                    $activity->bind_param(
                        "ss",
                        $guardID,
                        $description
                    );

                    $activity->execute();

                    $activity->close();

                }


                $message =
                    "Security guard archived successfully. Their account has been disabled.";

                $messageType =
                    "success";

            }

            else {

                $message =
                    "Unable to archive security guard.";

                $messageType =
                    "error";

                $stmt->close();

            }

        }

        else {

            $guardQuery->close();

            $message =
                "Security guard could not be found.";

            $messageType =
                "error";

        }

    }

}


/* =========================================================
   RESTORE GUARD
========================================================= */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'restore_guard'
) {

    $id =
        (int)(
            $_POST['id'] ?? 0
        );


    if ($id > 0) {

        $stmt =
            $conn->prepare("
                UPDATE security_guard
                SET
                    status = 'Active',
                    login_status = 'Inactive',
                    last_seen = NULL,
                    archived_at = NULL
                WHERE ID = ?
            ");

        $stmt->bind_param(
            "i",
            $id
        );


        if ($stmt->execute()) {

            /*
               Re-enable associated user account.
            */

            if (
                in_array(
                    'guard_id',
                    $userColumns
                ) &&
                in_array(
                    'status',
                    $userColumns
                )
            ) {

                $getGuard =
                    $conn->prepare("
                        SELECT guard_id
                        FROM security_guard
                        WHERE ID = ?
                        LIMIT 1
                    ");

                $getGuard->bind_param(
                    "i",
                    $id
                );

                $getGuard->execute();

                $guardResult =
                    $getGuard->get_result();

                if (
                    $guardRow =
                    $guardResult->fetch_assoc()
                ) {

                    $guardID =
                        $guardRow['guard_id'];


                    $enableUser =
                        $conn->prepare("
                            UPDATE users
                            SET status = 'Active'
                            WHERE guard_id = ?
                        ");

                    $enableUser->bind_param(
                        "s",
                        $guardID
                    );

                    $enableUser->execute();

                    $enableUser->close();

                }

                $getGuard->close();

            }


            $message =
                "Security guard restored successfully.";

            $messageType =
                "success";

        }

        else {

            $message =
                "Unable to restore security guard.";

            $messageType =
                "error";

        }


        $stmt->close();

    }

}


/* =========================================================
   SEARCH
========================================================= */

$search =
    trim(
        $_GET['search'] ?? ''
    );

$searchSQL = "";

if ($search !== '') {

    $safeSearch =
        $conn->real_escape_string(
            $search
        );

    $searchSQL = "
        AND
        (
            sg.full_name LIKE '%$safeSearch%'
            OR sg.guard_id LIKE '%$safeSearch%'
            OR sg.contact_number LIKE '%$safeSearch%'
            OR sg.assignment LIKE '%$safeSearch%'
        )
    ";

}


/* =========================================================
   GET SHIFTS
========================================================= */

$shifts = [];

$shiftResult =
    $conn->query("
        SELECT *
        FROM guard_shifts
        WHERE status = 'Active'
        ORDER BY
            CASE
                WHEN shift_name = 'Morning Shift'
                    THEN 1
                WHEN shift_name = 'Night Shift'
                    THEN 2
                ELSE 3
            END,
            id
    ");


if ($shiftResult) {

    while (
        $row =
        $shiftResult->fetch_assoc()
    ) {

        $shifts[] =
            $row;

    }

}


/* =========================================================
   GET ACTIVE EMPLOYED GUARDS
========================================================= */

$guards = [];

$sqlGuards = "
    SELECT
        sg.*,
        gs.shift_name,
        gs.start_time,
        gs.end_time
    FROM security_guard sg
    LEFT JOIN guard_shifts gs
        ON sg.shift_id = gs.id
    WHERE sg.status = 'Active'
    $searchSQL
    ORDER BY sg.full_name ASC
";


$guardResult =
    $conn->query(
        $sqlGuards
    );


if ($guardResult) {

    while (
        $row =
        $guardResult->fetch_assoc()
    ) {

        $guards[] =
            $row;

    }

}


/* =========================================================
   GET ARCHIVED GUARDS
========================================================= */

$archivedGuards = [];

$archiveResult =
    $conn->query("
        SELECT
            sg.*,
            gs.shift_name,
            gs.start_time,
            gs.end_time
        FROM security_guard sg
        LEFT JOIN guard_shifts gs
            ON sg.shift_id = gs.id
        WHERE sg.status = 'Archived'
        ORDER BY sg.archived_at DESC
    ");


if ($archiveResult) {

    while (
        $row =
        $archiveResult->fetch_assoc()
    ) {

        $archivedGuards[] =
            $row;

    }

}


/* =========================================================
   STATISTICS
========================================================= */

$totalActive = 0;
$totalArchived = 0;
$totalInactive = 0;
$totalLoggedIn = 0;


$countResult =
    $conn->query("
        SELECT

            SUM(
                status = 'Active'
            ) AS active_count,

            SUM(
                status = 'Archived'
            ) AS archived_count,

            SUM(
                status = 'Inactive'
            ) AS inactive_count,

            SUM(
                status = 'Active'
                AND login_status = 'Active'
            ) AS logged_in_count

        FROM security_guard
    ");


if (
    $countResult &&
    $countRow =
    $countResult->fetch_assoc()
) {

    $totalActive =
        (int)$countRow['active_count'];

    $totalArchived =
        (int)$countRow['archived_count'];

    $totalInactive =
        (int)$countRow['inactive_count'];

    $totalLoggedIn =
        (int)$countRow['logged_in_count'];

}


/* =========================================================
   CURRENT SHIFT
========================================================= */

$currentTime =
    date("H:i:s");

$currentShift =
    null;


foreach (
    $shifts
    as $shift
) {

    $start =
        $shift['start_time'];

    $end =
        $shift['end_time'];


    /*
       Normal shift
       Example:
       06:00 -> 18:00
    */

    if ($start < $end) {

        if (
            $currentTime >= $start &&
            $currentTime < $end
        ) {

            $currentShift =
                $shift;

            break;

        }

    }

    /*
       Overnight shift
       Example:
       18:00 -> 06:00
    */

    else {

        if (
            $currentTime >= $start ||
            $currentTime < $end
        ) {

            $currentShift =
                $shift;

            break;

        }

    }

}


/* =========================================================
   GUARDS LOGGED IN ON CURRENT SHIFT
========================================================= */

$currentShiftGuardCount = 0;


if ($currentShift) {

    $currentShiftID =
        (int)$currentShift['id'];


    foreach (
        $guards
        as $guard
    ) {

        if (
            (int)$guard['shift_id'] ===
            $currentShiftID &&
            $guard['login_status'] ===
            'Active'
        ) {

            $currentShiftGuardCount++;

        }

    }

}


/* =========================================================
   CLOSE CONNECTION
========================================================= */

$conn->close();

?>

<!DOCTYPE html>

<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport"
   content="width=device-width, initial-scale=1.0">

<title>
    Security Guard | <?php echo htmlspecialchars($systemName); ?>
</title>

<style>

/* =========================================================
   RESET
========================================================= */

*{
    margin:0;
    padding:0;
    box-sizing:border-box;
}

body{
    font-family: Arial, Helvetica, sans-serif;
    background: #f7f7f7;
    color: #111;
}


/* =========================================================
   SIDEBAR
========================================================= */

.sidebar{
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

.logo{
    display: flex;
    align-items: center;
    gap: 10px;

    padding-left: 3px;

    margin-bottom: 38px;

    font-family: Arial, Helvetica, sans-serif;
}

.logo-shield{
    width: 34px;
    height: 38px;

    background: #1478e8;

    clip-path: polygon(
        50% 0%,
        90% 15%,
        90% 58%,
        75% 82%,
        50% 100%,
        25% 82%,
        10% 58%,
        10% 15%
    );

    display: flex;
    align-items: center;
    justify-content: center;

    color: white;

    font-size: 18px;
    font-weight: bold;
    flex-shrink: 0;
    overflow: hidden;
    font-family: Arial, Helvetica, sans-serif;
}

.logo-shield img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.logo-text{
    line-height: 17px;
    font-family: Arial, Helvetica, sans-serif;
}

.logo-title{
    font-size: 17px;
    font-weight: 500;

    white-space: nowrap;
    color: #111;
    font-family: Arial, Helvetica, sans-serif;
}

.logo-subtitle{
    font-size: 12px;
    color: #444;
    font-family: Arial, Helvetica, sans-serif;
}

.nav{
    display: flex;
    flex-direction: column;
    gap: 7px;

    width: 100%;
    font-family: Arial, Helvetica, sans-serif;
}

.nav a{
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

.nav a:hover{
    background: #edf5ff;
}

.nav a.active{
    background: #2f80ed;
    color: white;
}

.nav-icon{
    width: 21px;

    display: flex;
    justify-content: center;
    align-items: center;

    font-size: 18px;

    flex-shrink: 0;
    font-family: Arial, Helvetica, sans-serif;
}

.logout{
    margin-top: auto;

    width: 100%;
    min-height: 38px;

    border: 1px solid #dddddd;

    border-radius: 18px;

    background: white;

    box-shadow: 0 1px 3px rgba(0,0,0,.08);

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

.logout:hover{
    background: #f3f3f3;
}


/* =========================================================
   MAIN
========================================================= */

.main{
    margin-left: 212px;
    width: calc(100% - 212px);

    min-height: 100vh;

    padding: 32px 38px 40px;
    box-sizing: border-box;
}

.header{
    margin-bottom:20px;
}

.header h1{
    font-size:25px;
    font-weight:500;
}

.header p{
    color:#666;
    font-size:14px;
    margin-top:5px;
}


/* =========================================================
   MESSAGE
========================================================= */

.message{
    padding:13px 17px;

    border-radius:12px;

    margin-bottom:18px;

    font-size:14px;
}

.message.success{
    background:#e9f8ee;
    color:#20723b;

    border:1px solid #bfe8cb;
}

.message.error{
    background:#fff0f0;
    color:#b42318;

    border:1px solid #f0bebe;
}


/* =========================================================
   TOP ACTIONS
========================================================= */

.top-actions{
    display:flex;

    justify-content:space-between;
    align-items:center;

    gap:12px;

    margin-bottom:16px;
}

.search-form{
    display:flex;
    gap:8px;
}

.search-form input{
    width:270px;

    height:40px;

    border:1px solid #d7d7d7;

    border-radius:20px;

    padding:0 16px;

    outline:none;
}

.btn{
    border:none;

    min-height:40px;

    padding:0 18px;

    border-radius:20px;

    cursor:pointer;

    font-size:14px;
}

.btn-primary{
    background:#2f80ed;
    color:white;
}

.btn-primary:hover{
    background:#176bd1;
}

.btn-secondary{
    background:white;

    border:1px solid #ddd;

    color:#222;
}

.btn-danger{
    background:#fff0f0;

    border:1px solid #f0c5c5;

    color:#b42318;
}

.btn-success{
    background:#e9f8ee;

    border:1px solid #bfe8cb;

    color:#20723b;
}


/* =========================================================
   STATISTICS
========================================================= */

.stats-row{
    display:grid;

    grid-template-columns:
        repeat(4,minmax(150px,1fr));

    gap:12px;

    margin-bottom:18px;

    max-width:1100px;
}

.stat-card{
    background:white;

    border:1px solid #dedede;

    border-radius:17px;

    padding:18px;

    box-shadow:
        0 1px 4px rgba(0,0,0,.08);
}

.stat-card-label{
    color:#666;
    font-size:12px;
}

.stat-card-number{
    font-size:29px;

    color:#333;

    margin-top:5px;
}


/* =========================================================
   TABLE
========================================================= */

.table-card{
    background:white;

    border:1px solid #dedede;

    border-radius:20px;

    padding:20px;

    max-width:1350px;

    box-shadow:
        0 1px 4px rgba(0,0,0,.08);
}

.table-header{
    display:flex;

    align-items:center;

    justify-content:space-between;

    margin-bottom:15px;
}

.table-header h2{
    font-size:19px;
    font-weight:500;
}

.table-wrapper{
    overflow-x:auto;
}

table{
    width:100%;

    border-collapse:collapse;

    min-width:1100px;
}

th{
    background:#f5f7fa;

    color:#555;

    font-size:12px;

    text-align:center;

    padding:13px 11px;

    border-bottom:1px solid #ddd;

    vertical-align:middle;
}

td{
    padding:13px 11px;

    font-size:13px;

    border-bottom:1px solid #eee;

    vertical-align:middle;

    text-align:center;
}

tr:hover td{
    background:#fafcff;
}


/* =========================================================
   BADGES
========================================================= */

.badge{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    margin:0 auto;

    padding:5px 10px;

    border-radius:15px;

    font-size:11px;

    font-weight:500;
}

.badge-active{
    background:#e9f8ee;
    color:#20723b;
}

.badge-inactive{
    background:#f1f1f1;
    color:#777;
}

.badge-archived{
    background:#f0f0f0;
    color:#666;
}

.badge-shift{
    background:#edf5ff;
    color:#176bd1;
}


/* =========================================================
   LOGIN STATUS
========================================================= */

.login-status{
    display:inline-flex;

    align-items:center;
    justify-content:center;
    margin:0 auto;

    gap:6px;

    padding:5px 10px;

    border-radius:15px;

    font-size:11px;

    font-weight:500;
}

.login-status.online{
    background:#e9f8ee;
    color:#20723b;
}

.login-status.offline{
    background:#f1f1f1;
    color:#777;
}

.status-dot{
    width:7px;
    height:7px;

    border-radius:50%;

    background:currentColor;
}


/* =========================================================
   ACTIONS
========================================================= */

.action-buttons{
    display:flex;

    gap:6px;

    flex-wrap:wrap;
    justify-content:center;
}

.small-btn{
    border:1px solid #ddd;

    background:white;

    border-radius:15px;

    padding:6px 10px;

    font-size:11px;

    cursor:pointer;
}

.small-btn:hover{
    background:#f4f7fb;
}

.small-btn.archive{
    color:#b42318;
}


/* =========================================================
   SHIFT SETTINGS
========================================================= */

.shift-section{
    margin-top:18px;

    max-width:1350px;
}

.shift-grid{
    display:grid;

    grid-template-columns:
        repeat(2,minmax(280px,1fr));

    gap:14px;
}

.shift-card{
    background:white;

    border:1px solid #dedede;

    border-radius:18px;

    padding:18px;

    box-shadow:
        0 1px 4px rgba(0,0,0,.08);
}

.shift-card h3{
    font-size:17px;

    font-weight:500;

    margin-bottom:12px;
}

.shift-time{
    color:#555;

    font-size:14px;

    margin-bottom:14px;
}

.time-row{
    display:flex;

    gap:10px;
}

.time-field{
    flex:1;
}

.time-field label{
    display:block;

    font-size:11px;

    color:#666;

    margin-bottom:5px;
}

.time-field input{
    width:100%;

    height:37px;

    border:1px solid #ddd;

    border-radius:9px;

    padding:0 9px;
}


/* =========================================================
   MODALS
========================================================= */

.modal{
    display:none;

    position:fixed;

    inset:0;

    background:rgba(0,0,0,.38);

    z-index:2000;

    align-items:center;

    justify-content:center;

    padding:20px;
}

.modal.show{
    display:flex;
}

.modal-content{
    background:white;

    width:100%;

    max-width:650px;

    max-height:92vh;

    overflow-y:auto;

    border-radius:20px;

    padding:24px;

    box-shadow:
        0 10px 40px rgba(0,0,0,.2);
}

.modal-content.small{
    max-width:470px;
}

.modal-header{
    display:flex;

    justify-content:space-between;

    align-items:center;

    margin-bottom:20px;
}

.modal-header h2{
    font-size:20px;
    font-weight:500;
}

.close{
    width:32px;
    height:32px;

    border:none;

    border-radius:50%;

    background:#f2f2f2;

    cursor:pointer;

    font-size:18px;
}

.form-section-title{
    font-size:13px;

    color:#2f80ed;

    font-weight:600;

    margin:18px 0 10px;
}

.form-grid{
    display:grid;

    grid-template-columns:
        repeat(2,minmax(0,1fr));

    gap:12px;
}

.form-group{
    display:flex;

    flex-direction:column;
}

.form-group.full{
    grid-column:1/-1;
}

.form-group label{
    font-size:12px;

    color:#555;

    margin-bottom:5px;
}

.form-group input,
.form-group select,
.form-group textarea{
    width:100%;

    min-height:39px;

    border:1px solid #d7d7d7;

    border-radius:9px;

    padding:8px 10px;

    outline:none;

    font-family:inherit;

    font-size:13px;
}

.form-group textarea{
    min-height:75px;

    resize:vertical;
}

.form-actions{
    display:flex;

    justify-content:flex-end;

    gap:8px;

    margin-top:20px;
}

.archive-section{
    display:none;

    margin-top:18px;

    max-width:1350px;
}

.archive-section.show{
    display:block;
}


/* =========================================================
   RESPONSIVE
========================================================= */

@media(max-width:1000px){

    .stats-row{
        grid-template-columns:
            repeat(2,1fr);
    }

    .shift-grid{
        grid-template-columns:1fr;
    }

}

@media(max-width:800px){

    .sidebar{
        width:76px;

        padding:24px 8px;
    }

    .logo{
        justify-content:center;
    }

    .logo-text{
        display:none;
    }

    .nav a{
        justify-content:center;

        padding:0;
    }

    .nav a span:not(.nav-icon){
        display:none;
    }

    .logout{
        font-size:0;
    }

    .logout::before{
        content:"⇥";

        font-size:20px;
    }

    .main{
        margin-left: 76px;
        width: calc(100% - 76px);
        padding: 25px 20px 30px;
    }

}

@media(max-width:600px){

    .main{
        padding: 20px 14px 30px;
    }

    .top-actions{
        flex-direction:column;

        align-items:stretch;
    }

    .search-form{
        width:100%;
    }

    .search-form input{
        width:100%;
    }

    .stats-row{
        grid-template-columns:1fr;
    }

    .form-grid{
        grid-template-columns:1fr;
    }

    .form-group.full{
        grid-column:auto;
    }

    .table-card{
        padding:14px;
    }

}

@media print {

    body {
        background: white;
    }

    .sidebar {
        display: none !important;
    }

    .main {
        margin-left: 0 !important;
        width: 100% !important;
        padding: 20px !important;
    }

    .top-actions,
    .search-form,
    .btn-create,
    .btn-export {
        display: none !important;
    }

}

</style>

</head>

<body>

<!-- =========================================================
     SIDEBAR
========================================================= -->

<aside class="sidebar">

<div class="logo">

    <div class="logo-shield">
        <?php if (!empty($systemIcon)): ?>
            <img src="<?php echo htmlspecialchars($systemIcon); ?>" alt="Icon">
        <?php else: ?>
            ✓
        <?php endif; ?>
    </div>

    <div class="logo-text">

        <div class="logo-title">
            <?php echo htmlspecialchars($systemName); ?>
        </div>

        <div class="logo-subtitle">
            Administrator
        </div>

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

    <a href="security_guard.php"
       class="active">
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

    <a href="profile.php">
        <span class="nav-icon">⚙️</span>
        <span>Profile</span>
    </a>

</nav>


<a href="logout.php?role=Administrator"
   class="logout">

    🚪 Logout

</a>

</aside>

<!-- =========================================================
     MAIN
========================================================= -->

<main class="main">

<div class="header">

    <h1>
        Security Guard
    </h1>

    <p>
        Manage security personnel, accounts, shifts and assignments.
    </p>

</div>


<?php if ($message !== ""): ?>

    <div class="message <?php echo e($messageType); ?>">

        <?php echo e($message); ?>

    </div>

<?php endif; ?>


<!-- =====================================================
     STATISTICS
====================================================== -->

<div class="stats-row">

    <div class="stat-card">

        <div class="stat-card-label">
            Active Guards
        </div>

        <div class="stat-card-number" id="activeGuardsCount">
            <?php echo $totalActive; ?>
        </div>

    </div>


    <div class="stat-card">

        <div class="stat-card-label">
            Currently Logged In
        </div>

        <div class="stat-card-number" id="loggedInGuardsCount">
            <?php echo $totalLoggedIn; ?>
        </div>

    </div>


    <div class="stat-card">

        <div class="stat-card-label">
            Archived Guards
        </div>

        <div class="stat-card-number" id="archivedGuardsCount">
            <?php echo $totalArchived; ?>
        </div>

    </div>


    <div class="stat-card">

        <div class="stat-card-label">
            Logged In on Current Shift
        </div>

        <div class="stat-card-number" id="currentShiftGuardsCount">
            <?php echo $currentShiftGuardCount; ?>
        </div>

    </div>

</div>


<!-- =====================================================
     ACTIONS
====================================================== -->

<div class="top-actions">

    <form
        method="GET"
        class="search-form">

        <input
            type="text"
            name="search"
            placeholder="Search guard..."
            value="<?php echo e($search); ?>">

        <button
            type="submit"
            class="btn btn-secondary">

            🔍 Search

        </button>

    </form>


    <div style="display:flex; gap:10px; flex-wrap:wrap;">

        <button
            type="button"
            class="btn btn-primary"
            onclick="openModal('addGuardModal')">

            ➕ New Security Guard

        </button>

        <button
            type="button"
            class="btn btn-secondary"
            onclick="toggleGuardArchive()">

            📦 Archive

        </button>

    </div>

</div>


<!-- =====================================================
     ACTIVE GUARDS
====================================================== -->

<section class="table-card">

    <div class="table-header">

        <h2>
            Security Guards
        </h2>

    </div>


    <div class="table-wrapper">

        <table>

            <thead>

                <tr>

                    <th>
                        Guard ID
                    </th>

                    <th>
                        Full Name
                    </th>

                    <th>
                        Contact Number
                    </th>

                    <th>
                        Date Hired
                    </th>

                    <th>
                        Shift
                    </th>

                    <th>
                        Login Status
                    </th>

                    <th>
                        Actions
                    </th>

                </tr>

            </thead>


            <tbody>

            <?php if (
                count($guards) === 0
            ): ?>

                <tr>

                    <td
                        colspan="7"
                        style="
                            text-align:center;
                            color:#777;
                            padding:30px;
                        ">

                        No security guards found.

                    </td>

                </tr>

            <?php endif; ?>


            <?php foreach (
                $guards
                as $guard
            ): ?>

                <tr>

                    <td>

                        <strong>

                            <?php
                            echo e(
                                $guard['guard_id']
                            );
                            ?>

                        </strong>

                    </td>


                    <td>

                        <?php
                        echo e(
                            $guard['full_name']
                        );
                        ?>

                    </td>


                    <td>

                        <?php
                        echo e(
                            $guard['contact_number']
                        );
                        ?>

                    </td>


                    <td>

                        <?php
                        echo e(
                            date(
                                "M d, Y",
                                strtotime(
                                    $guard['date_hired']
                                )
                            )
                        );
                        ?>

                    </td>


                    <td>

                        <span class="badge badge-shift">

                            <?php
                            echo e(
                                $guard['shift_name']
                                ??
                                'Not Assigned'
                            );
                            ?>

                        </span>


                        <?php if (
                            !empty(
                                $guard['start_time']
                            )
                        ): ?>

                            <div style="
                                font-size:10px;
                                color:#777;
                                margin-top:4px;
                            ">

                                <?php
                                echo e(
                                    formatTime(
                                        $guard['start_time']
                                    )
                                );
                                ?>

                                -

                                <?php
                                echo e(
                                    formatTime(
                                        $guard['end_time']
                                    )
                                );
                                ?>

                            </div>

                        <?php endif; ?>

                    </td>


                    <!-- =================================
                         LOGIN STATUS
                    ================================== -->

                    <td>

                        <?php if (
                            $guard['login_status']
                            === 'Active'
                        ): ?>

                            <span
                                class="login-status online guard-login-status"
                                data-guard-id="<?php echo e($guard['guard_id']); ?>"
                            >

                                <span class="status-dot"></span>

                                Active

                            </span>

                        <?php else: ?>

                            <span
                                class="login-status offline guard-login-status"
                                data-guard-id="<?php echo e($guard['guard_id']); ?>"
                            >

                                <span class="status-dot"></span>

                                Inactive

                            </span>

                        <?php endif; ?>

                    </td>


                    <td>

                        <div class="action-buttons">

                            <button
                                type="button"
                                class="small-btn"
                                onclick='editGuard(
                                    <?php
                                    echo json_encode(
                                        $guard,
                                        JSON_HEX_TAG |
                                        JSON_HEX_APOS |
                                        JSON_HEX_AMP |
                                        JSON_HEX_QUOT
                                    );
                                    ?>
                                )'>

                                ✏️ Edit

                            </button>


                            <button
                                type="button"
                                class="small-btn"
                                onclick='changeShift(
                                    <?php
                                    echo (int)$guard["ID"];
                                    ?>,
                                    <?php
                                    echo json_encode(
                                        $guard["full_name"],
                                        JSON_HEX_TAG |
                                        JSON_HEX_APOS |
                                        JSON_HEX_AMP |
                                        JSON_HEX_QUOT
                                    );
                                    ?>
                                )'>

                                🔄 Change Shift

                            </button>


                            <button
                                type="button"
                                class="
                                    small-btn
                                    archive
                                "
                                onclick='archiveGuard(
                                    <?php
                                    echo (int)$guard["ID"];
                                    ?>,
                                    <?php
                                    echo json_encode(
                                        $guard["full_name"],
                                        JSON_HEX_TAG |
                                        JSON_HEX_APOS |
                                        JSON_HEX_AMP |
                                        JSON_HEX_QUOT
                                    );
                                    ?>
                                )'>

                                📦 Archive

                            </button>

                        </div>

                    </td>

                </tr>

            <?php endforeach; ?>

            </tbody>

        </table>

    </div>

</section>

</main>

<!-- =====================================================
     ARCHIVED GUARDS MODAL
====================================================== -->

<div class="modal" id="archiveGuardsSection">

    <div class="modal-content" style="max-width:1100px;">

        <div class="modal-header">

            <h2>
                Archived Security Guards
            </h2>

            <button
                type="button"
                class="close"
                onclick="closeModal('archiveGuardsSection')">
                ×
            </button>

        </div>

        <div class="table-card" style="box-shadow:none; margin:0;">

            <div class="table-wrapper">

            <table>

                <thead>

                    <tr>

                        <th>
                            Guard ID
                        </th>

                        <th>
                            Full Name
                        </th>

                        <th>
                            Date Hired
                        </th>

                        <th>
                            Shift
                        </th>

                        <th>
                            Archived Date
                        </th>

                        <th>
                            Action
                        </th>

                    </tr>

                </thead>


                <tbody>

                <?php if (
                    count($archivedGuards) === 0
                ): ?>

                    <tr>

                        <td
                            colspan="6"
                            style="
                                text-align:center;
                                color:#777;
                                padding:30px;
                            ">

                            No archived security guards.

                        </td>

                    </tr>

                <?php endif; ?>


                <?php foreach (
                    $archivedGuards
                    as $guard
                ): ?>

                    <tr>

                        <td>

                            <?php
                            echo e(
                                $guard['guard_id']
                            );
                            ?>

                        </td>


                        <td>

                            <?php
                            echo e(
                                $guard['full_name']
                            );
                            ?>

                        </td>


                        <td>

                            <?php
                            echo e(
                                date(
                                    "M d, Y",
                                    strtotime(
                                        $guard['date_hired']
                                    )
                                )
                            );
                            ?>

                        </td>


                        <td>

                            <?php
                            echo e(
                                $guard['shift_name']
                                ??
                                'Not Assigned'
                            );
                            ?>

                        </td>


                        <td>

                            <?php

                            echo !empty(
                                $guard['archived_at']
                            )
                            ?
                            e(
                                date(
                                    "M d, Y g:i A",
                                    strtotime(
                                        $guard['archived_at']
                                    )
                                )
                            )
                            :
                            '-';

                            ?>

                        </td>


                        <td>

                            <form
                                method="POST"
                                style="
                                    display:inline;
                                ">

                                <input
                                    type="hidden"
                                    name="action"
                                    value="restore_guard">


                                <input
                                    type="hidden"
                                    name="id"
                                    value="<?php
                                        echo (int)$guard['ID'];
                                    ?>">


                                <button
                                    type="submit"
                                    class="small-btn"
                                    onclick="
                                        return confirm(
                                            'Restore this security guard?'
                                        );
                                    ">

                                    ♻️ Restore

                                </button>

                            </form>

                        </td>

                    </tr>

                <?php endforeach; ?>

                </tbody>

            </table>

            </div>

        </div>

    </div>

</div>

<!-- =========================================================
     ADD GUARD MODAL
========================================================= -->

<div
    class="modal"
    id="addGuardModal">

<div class="modal-content">

    <div class="modal-header">

        <h2>
            New Security Guard
        </h2>

        <button
            class="close"
            type="button"
            onclick="
                closeModal('addGuardModal')
            ">

            ×

        </button>

    </div>


    <form method="POST">

        <input
            type="hidden"
            name="action"
            value="add_guard">


        <div class="form-section-title">
            PERSONAL INFORMATION
        </div>


        <div class="form-grid">

            <div class="form-group full">

                <label>
                    Full Name *
                </label>

                <input
                    type="text"
                    name="full_name"
                    required>

            </div>


            <div class="form-group">

                <label>
                    Contact Number *
                </label>

                <input
                    type="text"
                    name="contact_number"
                    required>

            </div>


            <div class="form-group full">

                <label>
                    Address *
                </label>

                <textarea
                    name="address"
                    required></textarea>

            </div>


            <div class="form-group full">

                <label>
                    Shift *
                </label>

                <select
                    name="shift_id"
                    required>

                    <option value="">
                        Select Shift
                    </option>

                    <?php foreach (
                        $shifts
                        as $shift
                    ): ?>

                        <option
                            value="<?php
                                echo (int)$shift['id'];
                            ?>">

                            <?php
                            echo e(
                                $shift['shift_name']
                            );
                            ?>

                            —

                            <?php
                            echo e(
                                formatTime(
                                    $shift['start_time']
                                )
                            );
                            ?>

                            -

                            <?php
                            echo e(
                                formatTime(
                                    $shift['end_time']
                                )
                            );
                            ?>

                        </option>

                    <?php endforeach; ?>

                </select>

            </div>

        </div>


        <div class="form-section-title">
            SYSTEM ACCOUNT
        </div>


        <div class="form-grid">

            <div class="form-group full">

                <label>
                    Username *
                </label>

                <input
                    type="text"
                    name="username"
                    required
                    autocomplete="off">

            </div>


            <div class="form-group">

                <label>
                    Password *
                </label>

                <input
                    type="password"
                    name="password"
                    required
                    minlength="6"
                    autocomplete="new-password">

            </div>


            <div class="form-group">

                <label>
                    Confirm Password *
                </label>

                <input
                    type="password"
                    name="confirm_password"
                    required
                    minlength="6"
                    autocomplete="new-password">

            </div>

        </div>


        <div class="form-actions">

            <button
                type="button"
                class="btn btn-secondary"
                onclick="
                    closeModal('addGuardModal')
                ">

                Cancel

            </button>


            <button
                type="submit"
                class="btn btn-primary">

                ➕ Create Security Guard

            </button>

        </div>

    </form>

</div>

</div>

<!-- =========================================================
     EDIT GUARD MODAL
========================================================= -->

<div
    class="modal"
    id="editGuardModal">

<div class="modal-content">

    <div class="modal-header">

        <h2>
            Edit Security Guard
        </h2>

        <button
            class="close"
            type="button"
            onclick="
                closeModal('editGuardModal')
            ">

            ×

        </button>

    </div>


    <form method="POST">

        <input
            type="hidden"
            name="action"
            value="update_guard">


        <input
            type="hidden"
            name="id"
            id="edit_id">


        <div class="form-grid">

            <div class="form-group full">

                <label>
                    Guard ID
                </label>

                <input
                    type="text"
                    id="edit_guard_id"
                    readonly
                    style="
                        background:#f5f5f5;
                    ">

            </div>


            <div class="form-group full">

                <label>
                    Full Name *
                </label>

                <input
                    type="text"
                    name="full_name"
                    id="edit_full_name"
                    required>

            </div>


            <div class="form-group">

                <label>
                    Contact Number *
                </label>

                <input
                    type="text"
                    name="contact_number"
                    id="edit_contact_number"
                    required>

            </div>


            <div class="form-group">

                <label>
                    Date Hired *
                </label>

                <input
                    type="date"
                    name="date_hired"
                    id="edit_date_hired"
                    required>

            </div>


            <div class="form-group full">

                <label>
                    Address *
                </label>

                <textarea
                    name="address"
                    id="edit_address"
                    required></textarea>

            </div>


            <div class="form-group full">

                <label>
                    Shift *
                </label>

                <select
                    name="shift_id"
                    id="edit_shift_id"
                    required>

                    <?php foreach (
                        $shifts
                        as $shift
                    ): ?>

                        <option
                            value="<?php
                                echo (int)$shift['id'];
                            ?>">

                            <?php
                            echo e(
                                $shift['shift_name']
                            );
                            ?>

                            —

                            <?php
                            echo e(
                                formatTime(
                                    $shift['start_time']
                                )
                            );
                            ?>

                            -

                            <?php
                            echo e(
                                formatTime(
                                    $shift['end_time']
                                )
                            );
                            ?>

                        </option>

                    <?php endforeach; ?>

                </select>

            </div>

        </div>


        <div class="form-actions">

            <button
                type="button"
                class="btn btn-secondary"
                onclick="
                    closeModal('editGuardModal')
                ">

                Cancel

            </button>


            <button
                type="submit"
                class="btn btn-primary">

                💾 Save Changes

            </button>

        </div>

    </form>

</div>

</div>

<!-- =========================================================
     CHANGE SHIFT MODAL
========================================================= -->

<div
    class="modal"
    id="changeShiftModal">

<div class="
    modal-content
    small
">

    <div class="modal-header">

        <h2>
            Change Guard Shift
        </h2>

        <button
            class="close"
            type="button"
            onclick="
                closeModal('changeShiftModal')
            ">

            ×

        </button>

    </div>


    <form method="POST">

        <input
            type="hidden"
            name="action"
            value="change_guard_shift">


        <input
            type="hidden"
            name="id"
            id="change_shift_guard_id">


        <p style="
            color:#666;
            font-size:13px;
            margin-bottom:15px;
        ">

            Change shift for:

            <strong
                id="change_shift_guard_name">
            </strong>

        </p>


        <div class="form-group">

            <label>
                Select Shift
            </label>

            <select
                name="shift_id"
                required>

                <?php foreach (
                    $shifts
                    as $shift
                ): ?>

                    <option
                        value="<?php
                            echo (int)$shift['id'];
                        ?>">

                        <?php
                        echo e(
                            $shift['shift_name']
                        );
                        ?>

                        —

                        <?php
                        echo e(
                            formatTime(
                                $shift['start_time']
                            )
                        );
                        ?>

                        -

                        <?php
                        echo e(
                            formatTime(
                                $shift['end_time']
                            )
                        );
                        ?>

                    </option>

                <?php endforeach; ?>

            </select>

        </div>


        <div class="form-actions">

            <button
                type="button"
                class="btn btn-secondary"
                onclick="
                    closeModal('changeShiftModal')
                ">

                Cancel

            </button>


            <button
                type="submit"
                class="btn btn-primary">

                🔄 Change Shift

            </button>

        </div>

    </form>

</div>

</div>

<!-- =========================================================
     ARCHIVE MODAL
========================================================= -->

<div
    class="modal"
    id="archiveGuardModal">

<div class="
    modal-content
    small
">

    <div class="modal-header">

        <h2>
            Archive Security Guard
        </h2>

        <button
            class="close"
            type="button"
            onclick="
                closeModal('archiveGuardModal')
            ">

            ×

        </button>

    </div>


    <form method="POST">

        <input
            type="hidden"
            name="action"
            value="archive_guard">


        <input
            type="hidden"
            name="id"
            id="archive_guard_id">


        <p style="
            font-size:14px;
            color:#555;
            margin-bottom:15px;
        ">

            Are you sure you want to archive

            <strong
                id="archive_guard_name">
            </strong>?

            <br><br>

            The guard will no longer appear in
            the active employee list and their
            login account will be disabled.

        </p>


        <div class="form-group">

            <label>
                Reason
            </label>

            <select
                name="archive_reason">

                <option value="Resigned">
                    Resigned
                </option>

                <option value="Terminated">
                    Terminated
                </option>

                <option value="Retired">
                    Retired
                </option>

                <option value="Other">
                    Other
                </option>

            </select>

        </div>


        <div class="form-actions">

            <button
                type="button"
                class="btn btn-secondary"
                onclick="
                    closeModal('archiveGuardModal')
                ">

                Cancel

            </button>


            <button
                type="submit"
                class="btn btn-danger">

                📦 Archive Guard

            </button>

        </div>

    </form>

</div>

</div>

<script>

/* =========================================================
   MODALS
========================================================= */

function openModal(id)
{
    document
        .getElementById(id)
        .classList.add("show");
}


function closeModal(id)
{
    document
        .getElementById(id)
        .classList.remove("show");
}


/* =========================================================
   EDIT GUARD
========================================================= */

function editGuard(guard)
{

    document.getElementById(
        "edit_id"
    ).value =
        guard.ID;


    document.getElementById(
        "edit_guard_id"
    ).value =
        guard.guard_id;


    document.getElementById(
        "edit_full_name"
    ).value =
        guard.full_name;


    document.getElementById(
        "edit_contact_number"
    ).value =
        guard.contact_number;


    document.getElementById(
        "edit_address"
    ).value =
        guard.address;


    document.getElementById(
        "edit_date_hired"
    ).value =
        guard.date_hired;


    document.getElementById(
        "edit_shift_id"
    ).value =
        guard.shift_id;


    openModal(
        "editGuardModal"
    );

}


/* =========================================================
   CHANGE SHIFT
========================================================= */

function changeShift(id,name)
{

    document.getElementById(
        "change_shift_guard_id"
    ).value =
        id;


    document.getElementById(
        "change_shift_guard_name"
    ).textContent =
        name;


    openModal(
        "changeShiftModal"
    );

}


/* =========================================================
   ARCHIVE
========================================================= */

function archiveGuard(id,name)
{

    document.getElementById(
        "archive_guard_id"
    ).value =
        id;


    document.getElementById(
        "archive_guard_name"
    ).textContent =
        name;


    openModal(
        "archiveGuardModal"
    );

}


/* =========================================================
   CLOSE MODAL OUTSIDE
========================================================= */

document
    .querySelectorAll(".modal")
    .forEach(function(modal){

        modal.addEventListener(
            "click",
            function(event){

                if (
                    event.target === modal
                ) {

                    modal.classList.remove(
                        "show"
                    );

                }

            }
        );

    });


/* =========================================================
   LIVE GUARD LOGIN STATUS
   Refreshes Administrator status automatically.
========================================================= */

function refreshGuardStatuses()
{
    fetch(
        "security_guard.php?ajax=guard_status",
        {
            method: "GET",
            credentials: "same-origin",
            cache: "no-store",
            headers: {
                "Accept": "application/json"
            }
        }
    )
    .then(function(response) {
        if (!response.ok) {
            throw new Error("Status request failed.");
        }

        return response.json();
    })
    .then(function(data) {

        if (
            !data ||
            !data.success
        ) {
            return;
        }

        const guards =
            data.guards || {};

        document
            .querySelectorAll(
                ".guard-login-status"
            )
            .forEach(function(statusElement) {

                const guardID =
                    statusElement.dataset.guardId;

                const status =
                    guards[guardID] || "Inactive";

                const dot =
                    statusElement.querySelector(
                        ".status-dot"
                    );

                if (
                    status === "Active"
                ) {

                    statusElement.classList
                        .remove("offline");

                    statusElement.classList
                        .add("online");

                    statusElement.innerHTML =
                        '<span class="status-dot"></span>Active';

                }
                else {

                    statusElement.classList
                        .remove("online");

                    statusElement.classList
                        .add("offline");

                    statusElement.innerHTML =
                        '<span class="status-dot"></span>Inactive';

                }

                if (dot) {
                    statusElement
                        .insertBefore(
                            dot,
                            statusElement.firstChild
                        );
                }

            });


        if (data.counts) {

            const activeCount =
                document.getElementById(
                    "activeGuardsCount"
                );

            const loggedInCount =
                document.getElementById(
                    "loggedInGuardsCount"
                );

            const archivedCount =
                document.getElementById(
                    "archivedGuardsCount"
                );

            if (activeCount) {
                activeCount.textContent =
                    data.counts.active;
            }

            if (loggedInCount) {
                loggedInCount.textContent =
                    data.counts.logged_in;
            }

            if (archivedCount) {
                archivedCount.textContent =
                    data.counts.archived;
            }

        }

    })
    .catch(function() {
        /* Keep the current display if the
           administrator's request temporarily fails. */
    });
}


function toggleGuardArchive()
{
    const section = document.getElementById("archiveGuardsSection");
    if (section) {
        section.classList.toggle("show");
    }
}

/* Initial refresh plus automatic polling. */
refreshGuardStatuses();

setInterval(
    refreshGuardStatuses,
    5000
);

</script>

</body>
</html>
