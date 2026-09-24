<?php
/**
 * Forgot password — request handler.
 *
 * 1. Validates the submitted email.
 * 2. Emails a one-time code to the account owner over SMTP.
 * 3. Notifies every administrator that a reset was requested.
 */

require 'auth.php';
require_once 'mailer.php';

function fail_back(string $message, string $type = 'danger'): void
{
    set_flash($message, $type);
    header('Location: forgot_password.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: forgot_password.php');
    exit;
}

if (!csrf_verify($_POST['csrf_token'] ?? null)) {
    fail_back('Your session expired. Please submit the form again.', 'warning');
}

$email = strtolower(trim($_POST['email'] ?? ''));

if ($email === '') {
    fail_back('Please enter your email address.');
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fail_back('Please enter a valid email address.');
}

// --- Throttle repeat requests -----------------------------------
$cooldown = reset_otp_cooldown_remaining();
if ($cooldown > 0 && strcasecmp((string) get_reset_email(), $email) === 0) {
    fail_back("A code was already sent. Please wait {$cooldown} second(s) before requesting another one.", 'warning');
}

// --- Locate the account -----------------------------------------
$user = find_user($email);

if ($user === null) {
    add_activity_log("Password reset requested for unknown email: {$email}");
    fail_back('Email address not found. Please check and try again.');
}

$requestMeta = [
    'requested_at'   => date('F d, Y g:i A'),
    'ip'             => client_ip(),
    'account_status' => $user['status'] ?? 'active',
];

$adminRecipients = get_admin_recipients();

// --- Deactivated accounts: notify admin, issue no code ----------
if (strcasecmp($user['status'] ?? 'active', 'active') !== 0) {
    if ($adminRecipients) {
        send_admin_reset_notification($adminRecipients, $user, $requestMeta);
    }

    add_activity_log("Blocked password reset for deactivated account: {$user['username']}");
    fail_back('This account is deactivated. Your administrator has been notified and will contact you.', 'warning');
}

// --- Issue and deliver the OTP ----------------------------------
$otp = (string) set_reset_otp($user['email']);

$otpResult   = send_password_reset_otp_email($user, $otp, $requestMeta);
$adminResult = $adminRecipients
    ? send_admin_reset_notification($adminRecipients, $user, $requestMeta)
    : ['success' => false, 'message' => 'No administrator email address is configured.'];

add_activity_log(sprintf(
    'Password reset requested by %s (code email: %s, admin notice: %s)',
    $user['username'] ?? $user['email'],
    $otpResult['success'] ? 'sent' : 'failed',
    $adminResult['success'] ? 'sent' : 'failed'
));

// --- Outcome ----------------------------------------------------
unset($_SESSION['reset_fallback']);

if ($otpResult['success']) {
    $notice = $adminResult['success']
        ? 'Your administrator has also been notified of this request.'
        : '';

    set_flash(
        'We sent a verification code to ' . mask_email($user['email']) . '. It expires in ' . OTP_LIFETIME_MINUTES . ' minutes. ' . $notice,
        'success'
    );

    header('Location: otp_verify.php');
    exit;
}

// Delivery failed — keep the flow usable when no mail server is up.
if (MAIL_DEV_FALLBACK) {
    mail_log('DEV FALLBACK OTP', ['email' => $user['email'], 'otp' => $otp, 'error' => $otpResult['message']]);

    // otp_verify.php shows the code in a clear box instead of the email
    // wording; the technical error stays in mail_debug.log.
    $_SESSION['reset_fallback'] = [
        'code'   => $otp,
        'reason' => mail_failure_reason($otpResult['message']),
    ];

    header('Location: otp_verify.php');
    exit;
}

clear_reset_session();
fail_back('We could not send your verification code right now. Please contact your administrator.');
