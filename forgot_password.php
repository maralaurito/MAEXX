<?php
require 'auth.php';

$flash = get_flash();
$token = csrf_token();
?>
<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Forgot Password - MAEXX</title>

<link rel="icon" type="image/png" href="img/LOGO.png">
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
            <i class="bi bi-envelope-exclamation-fill"></i>
        </div>

        <h1 class="auth-title">
            Forgot Password
        </h1>

        <p class="auth-subtitle">
            Enter your registered email address. We will send you a verification code and notify your administrator.
        </p>

        <div class="steps">
            <div class="step active"><div class="dot"></div>Request</div>
            <div class="step"><div class="dot"></div>Verify</div>
            <div class="step"><div class="dot"></div>Reset</div>
        </div>

        <?php if ($flash): ?>

            <div class="alert alert-<?php echo htmlspecialchars($flash['type']); ?>">

                <?php echo htmlspecialchars($flash['message']); ?>

            </div>

        <?php endif; ?>

        <div class="info-box">

            <p>
                A 6-digit code will be emailed to you and stays valid for
                <?php echo OTP_LIFETIME_MINUTES; ?> minutes. Your system administrator
                receives a copy of this request for security monitoring.
            </p>

        </div>

        <form method="POST" action="forgot_password_process.php">

            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($token); ?>">

            <div class="input-group-custom">

                <i class="bi bi-envelope-fill"></i>

                <input type="email"
                       name="email"
                       class="form-control"
                       placeholder="Enter your registered email"
                       autocomplete="email"
                       required>

            </div>

            <button type="submit"
                    class="btn btn-submit w-100 text-white">

                <i class="bi bi-send-fill me-1"></i>
                Send Verification Code

            </button>

        </form>

        <div class="security-text">
            Reset requests are logged and reviewed by your administrator.
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
