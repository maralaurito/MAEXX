<?php
/**
 * Login handler.
 *
 * Failed attempts always land back on login.php with a toast, never on
 * forgot_password.php, and repeated failures lock the account for a
 * while so the form cannot be brute-forced.
 */

require 'auth.php';

/**
 * Bounce back to the login form, keeping what was typed in the
 * username field so the user only has to retype the password.
 */
function login_back(string $message, string $type = 'danger', string $login = ''): void
{
    set_flash($message, $type);
    $_SESSION['login_prefill'] = $login;

    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: login.php');
    exit;
}

if (!csrf_verify($_POST['csrf_token'] ?? null)) {
    login_back('Your session expired. Please sign in again.', 'warning');
}

$login    = trim($_POST['login'] ?? '');
$password = $_POST['password'] ?? '';

if ($login === '' || $password === '') {
    login_back('Username/email and password are required.', 'danger', $login);
}

// --- Already locked out? ----------------------------------------
$lockRemaining = login_lockout_remaining($login);

if ($lockRemaining > 0) {
    login_back(
        'Too many failed attempts. Try again in ' . format_duration($lockRemaining) . ', or use Forgot Password.',
        'danger',
        $login
    );
}

// --- Deactivated accounts get a clear reason, not a wrong-password
//     message, and no failed attempt is counted against them.
$existing = find_user($login);

if ($existing !== null && strcasecmp($existing['status'] ?? 'active', 'active') !== 0) {
    login_back('This account is deactivated. Please contact your administrator.', 'warning', $login);
}

// --- Check the credentials --------------------------------------
$user = verify_user($login, $password);

if ($user === null) {
    $result = record_login_failure($login);

    if ($result['locked']) {
        login_back(
            'Too many failed attempts. This account is locked for ' . format_duration($result['lock_seconds']) . '.',
            'danger',
            $login
        );
    }

    // Only start counting down out loud once it matters.
    $warn = $result['attempts_left'] <= LOGIN_WARN_THRESHOLD
        ? ' ' . $result['attempts_left'] . ' attempt(s) left before this account is locked.'
        : '';

    login_back('Incorrect username or password.' . $warn, 'danger', $login);
}

// --- Success ----------------------------------------------------
clear_login_failures($login);
unset($_SESSION['login_prefill']);

create_session($user);
add_activity_log("Signed in: {$user['username']}");

redirect_for_role($user['role']);
