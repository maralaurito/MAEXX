<?php
/**
 * Signup handler — JSON endpoint driven by the form on signup.php.
 *
 * Nothing is written to users.json until the emailed code is entered,
 * so unverified addresses never become accounts.
 *
 * Actions:
 *   request_otp  validate the form, email a code, hold the signup in session
 *   resend_otp   issue a fresh code for the held signup
 *   verify_otp   check the code and create the account
 */

require 'auth.php';
require_once 'mailer.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: signup.php');
    exit;
}

if (!csrf_verify($_POST['csrf_token'] ?? null)) {
    json_response(['success' => false, 'message' => 'Your session expired. Please reload the page.'], 419);
}
$action = $_POST['action'] ?? 'request_otp';

switch ($action) {

    // -----------------------------------------------------------
    case 'request_otp':

        $name     = trim($_POST['name'] ?? '');
        $email    = strtolower(trim($_POST['email'] ?? ''));
        $password = $_POST['password'] ?? '';
        $confirm  = $_POST['confirm_password'] ?? '';

        if ($name === '' || $email === '' || $password === '') {
            json_response(['success' => false, 'field' => 'name', 'message' => 'All fields are required.']);
        }

        if (strlen($name) < 2) {
            json_response(['success' => false, 'field' => 'name', 'message' => 'Name must be at least 2 characters.']);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            json_response(['success' => false, 'field' => 'email', 'message' => 'Please enter a valid email address.']);
        }

        // Duplicate check covers both the email and username columns.
        if (user_exists($email)) {
            json_response(['success' => false, 'field' => 'email', 'message' => 'This email is already registered. Please login instead.']);
        }

        $policy = validate_password_strength($password, $confirm);
        if (!$policy['valid']) {
            $field = $policy['message'] === 'Passwords do not match.' ? 'confirm_password' : 'password';
            json_response(['success' => false, 'field' => $field, 'message' => $policy['message']]);
        }

        // Throttle repeat requests for the same address.
        $pending = get_pending_signup();
        if ($pending !== null && strcasecmp($pending['email'], $email) === 0) {
            $wait = signup_resend_cooldown();
            if ($wait > 0) {
                json_response([
                    'success'  => false,
                    'message'  => "A code was already sent. Please wait {$wait} second(s) before requesting another.",
                    'cooldown' => $wait,
                ]);
            }
        }

        $otp  = start_signup_verification($name, $email, $password);
        $meta = ['requested_at' => date('F d, Y g:i A'), 'ip' => client_ip()];

        $result = send_signup_otp_email($name, $email, $otp, $meta);

        if (!$result['success']) {

            if (MAIL_DEV_FALLBACK) {
                mail_log('DEV FALLBACK SIGNUP OTP', ['email' => $email, 'otp' => $otp, 'error' => $result['message']]);

                json_response([
                    'success'   => true,
                    'message'   => "We couldn't email your code, so it's shown on screen instead.",
                    'reason'    => mail_failure_reason($result['message']),
                    'email'     => $email,
                    'masked'    => mask_email($email),
                    'dev_code'  => $otp,
                    'cooldown'  => OTP_RESEND_COOLDOWN,
                    'expires_in' => OTP_LIFETIME_MINUTES,
                ]);
            }

            clear_pending_signup();
            json_response(['success' => false, 'message' => 'We could not send your verification code. Please try again later.']);
        }

        json_response([
            'success'    => true,
            'message'    => 'We sent a 6-digit code to ' . mask_email($email) . '.',
            'email'      => $email,
            'masked'     => mask_email($email),
            'cooldown'   => OTP_RESEND_COOLDOWN,
            'expires_in' => OTP_LIFETIME_MINUTES,
        ]);

    // -----------------------------------------------------------
    case 'resend_otp':

        $pending = get_pending_signup();

        if ($pending === null) {
            json_response(['success' => false, 'expired' => true, 'message' => 'Your signup session expired. Please register again.']);
        }

        $wait = signup_resend_cooldown();
        if ($wait > 0) {
            json_response([
                'success'  => false,
                'message'  => "Please wait {$wait} second(s) before requesting another code.",
                'cooldown' => $wait,
            ]);
        }

        $otp = refresh_signup_otp();

        $result = send_signup_otp_email($pending['name'], $pending['email'], $otp, [
            'requested_at' => date('F d, Y g:i A'),
            'ip'           => client_ip(),
        ]);

        if (!$result['success'] && !MAIL_DEV_FALLBACK) {
            json_response(['success' => false, 'message' => 'We could not resend your code. Please try again later.']);
        }

        if (!$result['success']) {
            mail_log('DEV FALLBACK SIGNUP OTP', ['email' => $pending['email'], 'otp' => $otp, 'error' => $result['message']]);
        }

        json_response([
            'success'  => true,
            'message'  => $result['success']
                ? 'A new code is on its way to ' . mask_email($pending['email']) . '.'
                : "We couldn't email the new code, so it's shown on screen instead.",
            'reason'   => $result['success'] ? null : mail_failure_reason($result['message']),
            'dev_code' => $result['success'] ? null : $otp,
            'cooldown' => OTP_RESEND_COOLDOWN,
        ]);

    // -----------------------------------------------------------
    case 'verify_otp':

        $code = trim($_POST['otp'] ?? '');

        if ($code === '') {
            json_response(['success' => false, 'message' => 'Please enter the 6-digit code.']);
        }

        $check = verify_signup_otp($code);

        if ($check['status'] !== 'ok') {
            json_response([
                'success' => false,
                'expired' => in_array($check['status'], ['missing', 'expired', 'locked'], true),
                'message' => $check['message'],
            ]);
        }

        $created = create_user_from_pending_signup();

        if (!$created['success']) {
            json_response(['success' => false, 'expired' => true, 'message' => $created['message']]);
        }

        // Let the administrators know a new account exists.
        $admins = get_admin_recipients();
        if ($admins && !empty($created['user'])) {
            send_admin_new_account_notification($admins, $created['user'], [
                'created_at' => date('F d, Y g:i A'),
                'ip'         => client_ip(),
            ]);
        }

        set_flash('Account created and email verified. You can now login.', 'success');

        json_response([
            'success'  => true,
            'message'  => 'Account created successfully.',
            'redirect' => 'login.php',
        ]);

    // -----------------------------------------------------------
    default:
        json_response(['success' => false, 'message' => 'Unknown action.'], 400);
}
