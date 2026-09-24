<?php
require 'auth.php';

if (get_reset_email() === null) {

    set_flash('Please request a verification code first.', 'warning');
    header('Location: forgot_password.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!csrf_verify($_POST['csrf_token'] ?? null)) {

        set_flash('Your session expired. Please submit the code again.', 'warning');
        header('Location: otp_verify.php');
        exit;
    }

    $otp = trim($_POST['otp'] ?? '');

    if ($otp === '') {

        set_flash('Please enter the verification code.', 'danger');
        header('Location: otp_verify.php');
        exit;
    }

    if (!verify_otp($otp)) {

        if (get_reset_email() === null) {

            set_flash('Your code expired or too many attempts were made. Please request a new one.', 'danger');
            header('Location: forgot_password.php');
            exit;
        }

        set_flash('Invalid code. ' . reset_attempts_left() . ' attempt(s) remaining.', 'danger');
        header('Location: otp_verify.php');
        exit;
    }

    set_flash('Code verified successfully. You may now set a new password.', 'success');
    header('Location: reset_password.php');
    exit;
}

$flash       = get_flash();
$token       = csrf_token();
$maskedEmail = mask_email((string) get_reset_email());

// Set when the email could not be sent (e.g. no internet): the code is
// shown on this page instead. Only trusted if it is still the live code.
$fallback = $_SESSION['reset_fallback'] ?? null;
if ($fallback && (string) ($fallback['code'] ?? '') !== (string) ($_SESSION['reset_code'] ?? '')) {
    $fallback = null;
}
?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Verify OTP - MAEXX</title>

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
    max-width:420px;
}

.auth-card{
    background:#fff;
    border-radius:22px;
    padding:38px 32px;
    box-shadow:0 10px 35px rgba(0,0,0,0.18);
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
    width:75px;
    height:75px;
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

.input-group-custom{
    position:relative;
    margin-bottom:20px;
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
    height:52px;
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

.btn-submit{
    height:52px;
    border:none;
    border-radius:14px;
    background:linear-gradient(to right,#2563eb,#1d4ed8);
    font-weight:600;
    font-size:15px;
    transition:0.2s ease;
}

.btn-submit:hover{
    transform:translateY(-1px);
    opacity:0.95;
}

.info-box{
    background:#f8fafc;
    border:1px solid #e2e8f0;
    border-radius:14px;
    padding:14px;
    margin-bottom:22px;
}

.info-box p{
    margin:0;
    font-size:13px;
    color:#475569;
    line-height:1.5;
}

.code-box{
    background:#fffbeb;
    border:1px solid #fde68a;
    border-radius:14px;
    padding:16px 16px 14px;
    margin-bottom:22px;
    text-align:center;
}

.code-box-head{
    font-size:14px;
    font-weight:700;
    color:#92400e;
    margin-bottom:4px;
    display:flex;
    align-items:center;
    justify-content:center;
    gap:8px;
}

.code-box p{
    margin:0;
    font-size:13px;
    color:#92400e;
    line-height:1.5;
}

.code-box-code{
    font-size:30px;
    font-weight:700;
    letter-spacing:8px;
    padding-left:8px;
    color:#0f172a;
    background:#fff;
    border:1px dashed #fcd34d;
    border-radius:12px;
    margin:12px auto 10px;
    padding-top:6px;
    padding-bottom:6px;
    max-width:240px;
    user-select:all;
}

.code-box .code-box-note{
    font-size:12px;
    color:#a16207;
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

.otp-input{
    text-align:center;
    letter-spacing:10px;
    font-weight:600;
    font-size:20px;
    padding-left:10px;
}

.back-text{
    margin-top:22px;
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

.otp-note{
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
            Verify Code
        </h1>

        <p class="auth-subtitle">
            <?php if ($fallback): ?>
            Enter the 6-digit code shown below to continue resetting your password.
            <?php else: ?>
            Enter the 6-digit verification code we sent to
            <strong style="color:#0f172a;"><?php echo htmlspecialchars($maskedEmail); ?></strong>
            to continue resetting your password.
            <?php endif; ?>
        </p>

        <div class="steps">
            <div class="step"><div class="dot"></div>Request</div>
            <div class="step active"><div class="dot"></div>Verify</div>
            <div class="step"><div class="dot"></div>Reset</div>
        </div>

        <?php if ($flash): ?>

            <div class="alert alert-<?php echo htmlspecialchars($flash['type']); ?>">

                <?php echo htmlspecialchars($flash['message']); ?>

            </div>

        <?php endif; ?>

        <?php if ($fallback): ?>

        <div class="code-box">
            <div class="code-box-head">
                <i class="bi bi-wifi-off"></i>
                We couldn't email your code
            </div>
            <p><?php echo htmlspecialchars($fallback['reason']); ?>, so here is your code instead:</p>
            <div class="code-box-code"><?php echo htmlspecialchars($fallback['code']); ?></div>
            <p class="code-box-note">Type it in the box below. It works for <?php echo OTP_LIFETIME_MINUTES; ?> minutes.</p>
        </div>

        <?php else: ?>

        <div class="info-box">

            <p>
                The code expires <?php echo OTP_LIFETIME_MINUTES; ?> minutes after it was sent and can be
                entered <?php echo OTP_MAX_ATTEMPTS; ?> times. Always use the most recent code in your inbox.
            </p>

        </div>

        <?php endif; ?>

        <form method="POST" action="otp_verify.php">

            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($token); ?>">

            <div class="input-group-custom">

                <i class="bi bi-key-fill"></i>

                <input type="text"
                       name="otp"
                       class="form-control otp-input"
                       placeholder="······"
                       inputmode="numeric"
                       autocomplete="one-time-code"
                       required
                       maxlength="6"
                       pattern="\d{6}">

            </div>

            <button type="submit"
                    class="btn btn-submit w-100 text-white">

                <i class="bi bi-check-circle me-1"></i>
                Verify Code

            </button>

        </form>

        <div class="otp-note">
            <?php echo reset_attempts_left(); ?> of <?php echo OTP_MAX_ATTEMPTS; ?> attempts remaining.
        </div>

        <div class="back-text">

            <?php if ($fallback): ?>
            Code expired?
            <a href="forgot_password.php">Get a new one</a>
            <?php else: ?>
            Didn’t receive the code?

            <a href="forgot_password.php">
                Request Again
            </a>
            <?php endif; ?>

        </div>

    </div>

</div>

</body>

</html>