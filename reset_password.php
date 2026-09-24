<?php
require 'auth.php';
require_once 'mailer.php';

if (get_reset_email() === null) {

    set_flash('Please request a password reset first.', 'warning');
    header('Location: forgot_password.php');
    exit;
}

if (!is_reset_verified()) {

    set_flash('Please verify your code before setting a new password.', 'warning');
    header('Location: otp_verify.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!csrf_verify($_POST['csrf_token'] ?? null)) {

        set_flash('Your session expired. Please try again.', 'warning');
        header('Location: reset_password.php');
        exit;
    }

    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    if ($password === '') {

        set_flash('Please enter a new password.', 'danger');
        header('Location: reset_password.php');
        exit;
    }

    if ($password !== $confirm) {

        set_flash('Passwords do not match. Please try again.', 'danger');
        header('Location: reset_password.php');
        exit;
    }

    if (strlen($password) < 8) {

        set_flash('Password must be at least 8 characters.', 'danger');
        header('Location: reset_password.php');
        exit;
    }

    // Capture the account before reset_password() clears the session.
    $resetUser = get_reset_user();

    if (!reset_password($password)) {

        set_flash('Something went wrong. Please try again.', 'danger');
        header('Location: reset_password.php');
        exit;
    }

    if ($resetUser !== null) {

        // Completing a reset lifts any lockout — otherwise a locked-out
        // user would reset their password and still be unable to sign in.
        clear_login_failures($resetUser['username'] ?? $resetUser['email']);

        send_password_changed_email($resetUser, [
            'changed_at' => date('F d, Y g:i A'),
            'ip'         => client_ip(),
        ]);

        add_activity_log("Password reset completed for {$resetUser['username']}");
    }

    set_flash('Password reset successful! You can now login.', 'success');
    header('Location: login.php');
    exit;
}

$flash = get_flash();
$token = csrf_token();
?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Reset Password - MAEXX</title>

<link href="assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">

<link href="assets/vendor/bootstrap-icons/bootstrap-icons.min.css" rel="stylesheet">

<link href="assets/fonts/poppins/poppins.css" rel="stylesheet">

<style>

*{
    margin:0;
    padding:0;
    box-sizing:border-box;
}

body{
    font-family:'Poppins',sans-serif;
    background:linear-gradient(135deg,#1e3a5f,#294d73);
    min-height:100vh;
    display:flex;
    align-items:center;
    justify-content:center;
    padding:20px;
}

.auth-wrapper{
    width:100%;
    max-width:440px;
}

.auth-card{
    background:#fff;
    border-radius:24px;
    padding:38px 34px;
    box-shadow:0 12px 35px rgba(0,0,0,0.18);
    position:relative;
    overflow:hidden;
}

.auth-card::before{
    content:'';
    position:absolute;
    top:0;
    left:0;
    width:100%;
    height:6px;
    background:linear-gradient(to right,#2563eb,#1d4ed8);
}

.logo-circle{
    width:78px;
    height:78px;
    border-radius:50%;
    background:#eff6ff;
    color:#2563eb;
    display:flex;
    align-items:center;
    justify-content:center;
    margin:0 auto 20px;
    font-size:30px;
}

.auth-title{
    text-align:center;
    font-size:28px;
    font-weight:700;
    color:#0f172a;
    margin-bottom:6px;
}

.auth-subtitle{
    text-align:center;
    color:#64748b;
    font-size:14px;
    margin-bottom:28px;
    line-height:1.5;
}

.alert{
    border-radius:12px;
    font-size:13px;
    padding:12px 14px;
}

.info-box{
    background:#f8fafc;
    border:1px solid #e2e8f0;
    border-radius:14px;
    padding:14px;
    margin-bottom:24px;
}

.info-box p{
    margin:0;
    font-size:13px;
    color:#475569;
    line-height:1.5;
}

.steps{
    display:flex;
    gap:8px;
    margin-bottom:22px;
}

.step{
    flex:1;
    text-align:center;
    font-size:11px;
    font-weight:600;
    color:#94a3b8;
    letter-spacing:0.3px;
}

.step .dot{
    height:5px;
    border-radius:99px;
    background:#e2e8f0;
    margin-bottom:8px;
}

.step.active{
    color:#2563eb;
}

.step.active .dot{
    background:linear-gradient(to right,#2563eb,#1d4ed8);
}

.input-group-custom{
    position:relative;
    margin-bottom:18px;
}

.input-group-custom i{
    position:absolute;
    top:50%;
    left:16px;
    transform:translateY(-50%);
    color:#64748b;
    font-size:16px;
}

.form-control{
    height:54px;
    border-radius:14px;
    border:1px solid #dbe2ea;
    padding-left:48px;
    font-size:15px;
    transition:0.2s ease;
    box-shadow:none;
}

.form-control:focus{
    border-color:#2563eb;
    box-shadow:0 0 0 4px rgba(37,99,235,0.12);
}

.password-note{
    font-size:12px;
    color:#94a3b8;
    margin-top:-8px;
    margin-bottom:18px;
}

.btn-submit{
    height:54px;
    border:none;
    border-radius:14px;
    background:linear-gradient(to right,#2563eb,#1d4ed8);
    font-size:15px;
    font-weight:600;
    transition:0.2s ease;
}

.btn-submit:hover{
    transform:translateY(-1px);
    opacity:0.96;
}

.back-text{
    margin-top:24px;
    text-align:center;
    font-size:13px;
    color:#64748b;
}

.back-text a{
    color:#2563eb;
    text-decoration:none;
    font-weight:600;
}

.back-text a:hover{
    text-decoration:underline;
}

.security-text{
    text-align:center;
    font-size:12px;
    color:#94a3b8;
    margin-top:14px;
}

</style>

</head>

<body>

<div class="auth-wrapper">

    <div class="auth-card">

        <div class="logo-circle">
            <i class="bi bi-shield-lock-fill"></i>
        </div>

        <h1 class="auth-title">
            Reset Password
        </h1>

        <p class="auth-subtitle">
            Create a strong new password to secure your MAEXX account and continue safely.
        </p>

        <div class="steps">
            <div class="step"><div class="dot"></div>Request</div>
            <div class="step"><div class="dot"></div>Verify</div>
            <div class="step active"><div class="dot"></div>Reset</div>
        </div>

        <?php if ($flash): ?>

            <div class="alert alert-<?php echo htmlspecialchars($flash['type']); ?>">

                <?php echo htmlspecialchars($flash['message']); ?>

            </div>

        <?php endif; ?>

        <div class="info-box">

            <p>
                Your new password must contain at least 8 characters. Make sure to use a secure and memorable password.
            </p>

        </div>

        <form method="POST" action="reset_password.php">

            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($token); ?>">

            <!-- PASSWORD -->
            <div class="input-group-custom">

                <i class="bi bi-lock-fill"></i>

                <input type="password"
                       name="password"
                       class="form-control"
                       placeholder="Enter new password"
                       required>

            </div>

            <!-- CONFIRM -->
            <div class="input-group-custom">

                <i class="bi bi-shield-check"></i>

                <input type="password"
                       name="confirm_password"
                       class="form-control"
                       placeholder="Confirm new password"
                       required>

            </div>

            <div class="password-note">
                Password must be at least 8 characters long.
            </div>

            <button type="submit"
                    class="btn btn-submit w-100 text-white">

                <i class="bi bi-check-circle me-1"></i>
                Reset Password

            </button>

        </form>

        <div class="security-text">
            Your account security is important to us.
        </div>

        <div class="back-text">

            Remembered your password?

            <a href="login.php">
                Back to Login
            </a>

        </div>

    </div>

</div>

</body>

</html>