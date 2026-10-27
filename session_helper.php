<?php

/* =====================================================================
   SESSION HELPER
   ---------------------------------------------------------------------
   This system has two kinds of users that may be logged in at the
   same time from the same browser:
       - Administrator (Superadmin)
       - Security Guard

   PHP gives every browser only ONE default session (cookie PHPSESSID).
   When both users share the same browser, whichever one logs in last
   overwrites the shared session's "role", so the other user would be
   treated as the wrong person.

   To keep both of them logged in at the same time we give each role
   its OWN PHP session (its own cookie name):
       - secsys_administrator   -> Administrator / Superadmin
       - secsys_security_guard  -> Security Guard
   ===================================================================== */


date_default_timezone_set('Asia/Manila');

function secsysRoleSessionName($role)
{
    return 'secsys_' .
        strtolower(
            str_replace(' ', '_', trim($role))
        );
}


/**
 * Return the session id stored in the given role's cookie, or null.
 * PHP will only auto-use the cookie on the FIRST session_start() of a
 * request, so whenever more than one role session may be touched in a
 * single request (login, logout, redirect fallbacks) we must set the id
 * explicitly from the cookie ourselves.
 */
function secsysSessionIdFromCookie($name)
{
    if (empty($_COOKIE[$name])) {
        return null;
    }

    $id = (string)$_COOKIE[$name];

    /* Basic sanity check so a bogus cookie value cannot break
       session_id(). PHP session ids are limited to A-Z a-z 0-9
       plus "," and "-". */
    if (preg_match('/^[A-Za-z0-9,\-]{22,200}$/', $id)) {
        return $id;
    }

    return null;
}


/**
 * Start (or attach to) the session for a specific role.
 * Call this INSTEAD of session_start() so every page reads the
 * correct role-specific session cookie.
 */
function secsysStartRoleSession($role)
{
    $name = secsysRoleSessionName($role);

    if (
        session_status() === PHP_SESSION_ACTIVE &&
        session_id() !== '' &&
        session_name() === $name
    ) {
        // Already started for the correct role.
        return;
    }

    // If another role's session is already open in this request,
    // close it first so we can safely switch cookies.
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    // Explicitly pick the session id from this role's cookie, because
    // PHP only auto-detects the id on the very first session_start().
    $id = secsysSessionIdFromCookie($name);

    if ($id !== null) {
        session_id($id);
    } elseif (session_id() !== '') {
        session_id('');
    }

    session_name($name);
    session_start();
}


/**
 * Switch from the currently-open session to a different role's session.
 * Used by login.php after it discovers which role is logging in.
 */
function secsysSwitchRoleSession($role)
{
    $name = secsysRoleSessionName($role);

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $id = secsysSessionIdFromCookie($name);

    if ($id !== null) {
        session_id($id);
    } else {
        session_id('');
    }

    session_name($name);
    session_start();

    session_regenerate_id(true);
}


/**
 * Destroy BOTH role sessions. Used by logout.php.
 */
function secsysDestroyAllSessions()
{
    $roles = ['Administrator', 'Security Guard'];

    foreach ($roles as $role) {
        $name = secsysRoleSessionName($role);

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $id = secsysSessionIdFromCookie($name);

        if ($id !== null) {
            session_id($id);
        } else {
            session_id('');
        }

        session_name($name);
        session_start();

        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();

            setcookie(
                $name,
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        session_destroy();
    }
}
