<?php
require 'auth.php';

$flash = get_flash();
$token = csrf_token();

// A reload abandons any half-finished verification.
clear_pending_signup();?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sign Up - MAEXX</title>

<link href="assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
<link href="assets/vendor/bootstrap-icons/bootstrap-icons.min.css" rel="stylesheet">

<!-- Poppins font (stored locally) -->
<link href="assets/fonts/poppins/poppins.css" rel="stylesheet">

<style>
body {
    margin: 0;
    min-height: 100vh;
    font-family: 'Poppins', sans-serif;
    background: #243f5f;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px 0;
}

/* container */
.auth-container {
    width: 900px;
    max-width: 95%;
    display: flex;
    border-radius: 20px;
    overflow: hidden;
    box-shadow: 0 25px 60px rgba(0,0,0,0.25);
}

/* LEFT */
.left-panel {
    flex: 1;
    background: linear-gradient(135deg, #243f5f, #1b2f47);
    color: white;
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
    padding: 40px;
    text-align: center;
}

.left-panel img {
    width: 120px;
    margin-bottom: 20px;
    border-radius: 50%;
}

.left-panel h2 {
    font-weight: 600;
}

.left-panel p {
    font-size: 14px;
    opacity: 0.8;
}

/* RIGHT */
.right-panel {
    flex: 1;
    background: #ffffff;
    padding: 40px;
    display: flex;
    flex-direction: column;
    justify-content: center;
}

/* form */
.form-group {
    position: relative;
    margin-bottom: 15px;
}

.form-control {
    border-radius: 12px;
    padding: 12px 40px 12px 42px;
    border: 1px solid #e5e7eb;
    font-size: 14px;
}

.form-control:focus {
    border-color: #243f5f;
    box-shadow: 0 0 0 2px rgba(36,63,95,0.15);
}

.form-control.is-invalid {
    border-color: #dc2626;
    box-shadow: 0 0 0 2px rgba(220,38,38,0.12);
}

/* icons */
.form-group > i:first-child {
    position: absolute;
    top: 12px;
    left: 12px;
    color: #9ca3af;
}

/* eye icon */
.toggle-password {
    position: absolute;
    top: 12px;
    right: 12px;
    cursor: pointer;
    color: #9ca3af;
}

/* field-level hint */
.field-hint {
    font-size: 11.5px;
    color: #94a3b8;
    margin: -10px 2px 14px;
    line-height: 1.5;
}

.field-hint.invalid {
    color: #dc2626;
}

.field-hint.valid {
    color: #16a34a;
}

/* password strength */
.strength-bar {
    height: 5px;
    border-radius: 99px;
    background: #e5e7eb;
    margin: -8px 2px 6px;
    overflow: hidden;
}

.strength-bar span {
    display: block;
    height: 100%;
    width: 0;
    border-radius: 99px;
    transition: width 0.25s ease, background 0.25s ease;
}

/* button */
.btn-register {
    border-radius: 12px;
    padding: 12px;
    background: #243f5f;
    border: none;
}

.btn-register:hover {
    background: #1b2f47;
}

.btn-register:disabled {
    background: #94a3b8;
    cursor: not-allowed;
}

/* login link */
.login-text {
    margin-top: 15px;
    text-align: center;
    font-size: 14px;
}

.login-text span {
    color: #9ca3af;
}

.login-text a {
    color: #243f5f;
    font-weight: 600;
    text-decoration: none;
}

.login-text a:hover {
    text-decoration: underline;
}

/* ---------- OTP MODAL ---------- */
.modal-content {
    border: none;
    border-radius: 22px;
    overflow: hidden;
    font-family: 'Poppins', sans-serif;
}

.modal-content::before {
    content: '';
    display: block;
    height: 6px;
    background: linear-gradient(to right, #243f5f, #1b2f47);
}

.otp-logo {
    width: 74px;
    height: 74px;
    border-radius: 50%;
    margin: 0 auto 18px;
    display: block;
    box-shadow: 0 6px 18px rgba(36,63,95,0.18);
}

.otp-title {
    text-align: center;
    font-size: 23px;
    font-weight: 700;
    color: #0f172a;
    margin-bottom: 6px;
}

.otp-subtitle {
    text-align: center;
    font-size: 13.5px;
    color: #64748b;
    line-height: 1.6;
    margin-bottom: 22px;
}

.otp-input {
    text-align: center;
    letter-spacing: 12px;
    font-size: 22px;
    font-weight: 600;
    padding: 14px 12px 14px 24px;
    border-radius: 14px;
    border: 1px solid #dbe2ea;
    width: 100%;
}

.otp-input:focus {
    border-color: #243f5f;
    box-shadow: 0 0 0 3px rgba(36,63,95,0.12);
    outline: none;
}

.otp-input.is-invalid {
    border-color: #dc2626;
    box-shadow: 0 0 0 3px rgba(220,38,38,0.12);
}

.otp-info {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 13px 15px;
    font-size: 12.5px;
    color: #475569;
    line-height: 1.6;
    margin-bottom: 20px;
}

.otp-dev {
    background: #fffbeb;
    border: 1px solid #fde68a;
    color: #b45309;
    border-radius: 12px;
    padding: 11px 14px;
    font-size: 12.5px;
    margin-bottom: 16px;
    text-align: center;
}

.btn-verify {
    border-radius: 12px;
    padding: 12px;
    background: #243f5f;
    border: none;
    font-weight: 600;
    width: 100%;
    color: #fff;
}

.btn-verify:hover { background: #1b2f47; color: #fff; }
.btn-verify:disabled { background: #94a3b8; cursor: not-allowed; }

.otp-foot {
    text-align: center;
    font-size: 12.5px;
    color: #64748b;
    margin-top: 16px;
}

.otp-foot a, .otp-foot button {
    color: #243f5f;
    font-weight: 600;
    text-decoration: none;
    background: none;
    border: none;
    padding: 0;
    cursor: pointer;
}

.otp-foot a:hover, .otp-foot button:hover { text-decoration: underline; }
.otp-foot button:disabled { color: #94a3b8; cursor: not-allowed; text-decoration: none; }

/* ---------- TOASTS ---------- */
.toast-stack {
    position: fixed;
    top: 24px;
    right: 24px;
    z-index: 1090;
    display: flex;
    flex-direction: column;
    gap: 10px;
    max-width: 380px;
}

.app-toast {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    background: #ffffff;
    border-radius: 14px;
    padding: 14px 16px;
    box-shadow: 0 12px 30px rgba(15,23,42,0.22);
    border-left: 5px solid #243f5f;
    font-size: 13.5px;
    line-height: 1.55;
    color: #334155;
    animation: toast-in 0.25s ease;
}

.app-toast.hiding { animation: toast-out 0.25s ease forwards; }
.app-toast .toast-icon { font-size: 18px; line-height: 1.3; }
.app-toast .toast-title { font-weight: 600; color: #0f172a; margin-bottom: 2px; }

.app-toast .toast-close {
    margin-left: auto;
    border: none;
    background: none;
    color: #94a3b8;
    font-size: 16px;
    line-height: 1;
    cursor: pointer;
    padding: 0 2px;
}

.app-toast.toast-danger  { border-left-color: #dc2626; }
.app-toast.toast-danger  .toast-icon { color: #dc2626; }
.app-toast.toast-warning { border-left-color: #d97706; }
.app-toast.toast-warning .toast-icon { color: #d97706; }
.app-toast.toast-success { border-left-color: #16a34a; }
.app-toast.toast-success .toast-icon { color: #16a34a; }
.app-toast.toast-info    { border-left-color: #243f5f; }
.app-toast.toast-info    .toast-icon { color: #243f5f; }

@keyframes toast-in {
    from { opacity: 0; transform: translateX(24px); }
    to   { opacity: 1; transform: translateX(0); }
}

@keyframes toast-out {
    from { opacity: 1; transform: translateX(0); }
    to   { opacity: 0; transform: translateX(24px); }
}

/* responsive */
@media(max-width: 768px) {
    .auth-container { flex-direction: column; }
    .left-panel { display: none; }
    .toast-stack { top: 12px; right: 12px; left: 12px; max-width: none; }
}
</style>
</head>

<body>

<div class="toast-stack" id="toastStack"></div>

<div class="auth-container">

    <!-- LEFT -->
    <div class="left-panel">
        <img src="img/LOGO.png" alt="Logo">
        <h2>MAEXX2 Enterprises Inc.</h2>
        <p>Inventory & Sales Monitoring System</p>
    </div>

    <!-- RIGHT -->
    <div class="right-panel">

        <h4 class="mb-2">Create Account</h4>
        <p class="text-muted mb-4">We'll email you a code to confirm your address</p>

        <form id="signupForm" novalidate>

            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($token); ?>">
            <input type="hidden" name="action" value="request_otp">

            <!-- NAME -->
            <div class="form-group">
                <i class="bi bi-person"></i>
                <input type="text" id="name" name="name" class="form-control" placeholder="Full Name" autocomplete="name" required>
            </div>

            <!-- EMAIL -->
            <div class="form-group">
                <i class="bi bi-envelope"></i>
                <input type="email" id="email" name="email" class="form-control" placeholder="Email" autocomplete="email" required>
            </div>
            <div class="field-hint" id="emailHint">A verification code will be sent to this address.</div>

            <!-- PASSWORD -->
            <div class="form-group">
                <i class="bi bi-lock"></i>
                <input type="password" id="password" name="password" class="form-control" placeholder="Password" autocomplete="new-password" required>
                <i class="bi bi-eye-slash toggle-password" id="togglePassword"></i>
            </div>
            <div class="strength-bar"><span id="strengthFill"></span></div>
            <div class="field-hint" id="passwordHint">At least 8 characters, with one letter and one number.</div>

            <!-- CONFIRM -->
            <div class="form-group">
                <i class="bi bi-shield-check"></i>
                <input type="password" id="confirm_password" name="confirm_password" class="form-control" placeholder="Confirm Password" autocomplete="new-password" required>
                <i class="bi bi-eye-slash toggle-password" id="toggleConfirm"></i>
            </div>
            <div class="field-hint" id="confirmHint">Re-enter the password exactly.</div>

            <!-- BUTTON -->
            <button type="submit" class="btn btn-register w-100 text-white" id="registerBtn">
                Register
            </button>

        </form>

        <!-- LOGIN -->
        <div class="login-text">
            <span>Already have an account?</span>
            <a href="login.php">Login</a>
        </div>

    </div>

</div>

<!-- =========================================================
     OTP MODAL
========================================================== -->
<div class="modal fade" id="otpModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-body p-4 pt-4">

        <img src="img/LOGO.png" alt="MAEXX" class="otp-logo">

        <div class="otp-title">Verify Your Email</div>
        <p class="otp-subtitle">
            <span id="otpLead">Enter the 6-digit code we sent to</span><br>
            <strong id="otpTarget" style="color:#0f172a;"></strong>
        </p>

        <div class="otp-dev d-none" id="otpDevBox"></div>

        <div class="otp-info">
            Your account is created only after this code is confirmed, so
            unverified addresses never get access. The code expires in
            <?php echo OTP_LIFETIME_MINUTES; ?> minutes.
        </div>

        <form id="otpForm">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($token); ?>">
            <input type="hidden" name="action" value="verify_otp">

            <input type="text"
                   id="otpCode"
                   name="otp"
                   class="otp-input mb-3"
                   placeholder="······"
                   inputmode="numeric"
                   autocomplete="one-time-code"
                   maxlength="6"
                   pattern="\d{6}"
                   required>

            <button type="submit" class="btn btn-verify" id="verifyBtn">
                <i class="bi bi-check-circle me-1"></i> Verify &amp; Create Account
            </button>
        </form>

        <div class="otp-foot">
            Didn't get it?
            <button type="button" id="resendBtn">Resend code</button>
        </div>

        <div class="otp-foot">
            <button type="button" id="cancelOtp">Use a different email</button>
        </div>

      </div>
    </div>
  </div>
</div>

<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script>
/* ---------------------------------------------------------------
   TOASTS
--------------------------------------------------------------- */
const TOAST_ICONS = {
    danger:  'bi-exclamation-octagon-fill',
    warning: 'bi-exclamation-triangle-fill',
    success: 'bi-check-circle-fill',
    info:    'bi-info-circle-fill'
};

const TOAST_TITLES = {
    danger:  'Please check',
    warning: 'Heads up',
    success: 'Success',
    info:    'Notice'
};

function showToast(message, type = 'info', timeout = 6000) {
    const stack = document.getElementById('toastStack');

    const toast = document.createElement('div');
    toast.className = `app-toast toast-${type}`;
    toast.setAttribute('role', 'alert');
    toast.innerHTML = `
        <i class="bi ${TOAST_ICONS[type] || TOAST_ICONS.info} toast-icon"></i>
        <div>
            <div class="toast-title">${TOAST_TITLES[type] || TOAST_TITLES.info}</div>
            <div class="toast-body"></div>
        </div>
        <button type="button" class="toast-close" aria-label="Close">&times;</button>
    `;
    toast.querySelector('.toast-body').textContent = message;

    const dismiss = () => {
        toast.classList.add('hiding');
        setTimeout(() => toast.remove(), 250);
    };

    toast.querySelector('.toast-close').addEventListener('click', dismiss);
    stack.appendChild(toast);
    setTimeout(dismiss, timeout);
}

<?php if ($flash): ?>
showToast(<?php echo json_encode($flash['message']); ?>, <?php echo json_encode($flash['type']); ?>);
<?php endif; ?>

/* ---------------------------------------------------------------
   PASSWORD TOGGLES
--------------------------------------------------------------- */
function wireToggle(toggleId, inputId) {
    const toggle = document.getElementById(toggleId);
    const input  = document.getElementById(inputId);

    toggle.addEventListener('click', function () {
        input.type = input.type === 'password' ? 'text' : 'password';
        this.classList.toggle('bi-eye');
        this.classList.toggle('bi-eye-slash');
    });
}

wireToggle('togglePassword', 'password');
wireToggle('toggleConfirm', 'confirm_password');

/* ---------------------------------------------------------------
   LIVE VALIDATION — mirrors the server rules in signup_process.php
--------------------------------------------------------------- */
const nameEl    = document.getElementById('name');
const emailEl   = document.getElementById('email');
const pwEl      = document.getElementById('password');
const confirmEl = document.getElementById('confirm_password');

const emailHint   = document.getElementById('emailHint');
const pwHint      = document.getElementById('passwordHint');
const confirmHint = document.getElementById('confirmHint');
const strengthFill = document.getElementById('strengthFill');

const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;

function setHint(el, hintEl, state, message) {
    hintEl.textContent = message;
    hintEl.classList.remove('valid', 'invalid');
    el.classList.remove('is-invalid');

    if (state === 'valid')   hintEl.classList.add('valid');
    if (state === 'invalid') { hintEl.classList.add('invalid'); el.classList.add('is-invalid'); }
}

function checkEmail(silent = false) {
    const value = emailEl.value.trim();

    if (value === '') {
        setHint(emailEl, emailHint, 'neutral', 'A verification code will be sent to this address.');
        return false;
    }

    if (!EMAIL_RE.test(value)) {
        if (!silent) setHint(emailEl, emailHint, 'invalid', 'That does not look like a valid email address.');
        return false;
    }

    setHint(emailEl, emailHint, 'valid', 'Looks good — the code goes here.');
    return true;
}

function passwordScore(value) {
    let score = 0;
    if (value.length >= 8)        score++;
    if (/[A-Za-z]/.test(value))   score++;
    if (/\d/.test(value))         score++;
    if (/[^A-Za-z0-9]/.test(value) && value.length >= 10) score++;
    return score;
}

function checkPassword(silent = false) {
    const value = pwEl.value;

    const score  = passwordScore(value);
    const widths = ['0%', '30%', '55%', '80%', '100%'];
    const colors = ['#e5e7eb', '#dc2626', '#d97706', '#16a34a', '#15803d'];

    strengthFill.style.width      = value === '' ? '0%' : widths[score];
    strengthFill.style.background = colors[score];

    if (value === '') {
        setHint(pwEl, pwHint, 'neutral', 'At least 8 characters, with one letter and one number.');
        return false;
    }

    if (value.length < 8) {
        if (!silent) setHint(pwEl, pwHint, 'invalid', 'Password must be at least 8 characters.');
        return false;
    }

    if (!/[A-Za-z]/.test(value) || !/\d/.test(value)) {
        if (!silent) setHint(pwEl, pwHint, 'invalid', 'Password must contain at least one letter and one number.');
        return false;
    }

    setHint(pwEl, pwHint, 'valid', score >= 4 ? 'Strong password.' : 'Password meets the requirements.');
    return true;
}

function checkConfirm(silent = false) {
    const value = confirmEl.value;

    if (value === '') {
        setHint(confirmEl, confirmHint, 'neutral', 'Re-enter the password exactly.');
        return false;
    }

    if (value !== pwEl.value) {
        if (!silent) setHint(confirmEl, confirmHint, 'invalid', 'Passwords do not match.');
        return false;
    }

    setHint(confirmEl, confirmHint, 'valid', 'Passwords match.');
    return true;
}

emailEl.addEventListener('input', () => checkEmail(true));
emailEl.addEventListener('blur',  () => checkEmail(false));
pwEl.addEventListener('input',    () => { checkPassword(true); if (confirmEl.value) checkConfirm(true); });
pwEl.addEventListener('blur',     () => checkPassword(false));
confirmEl.addEventListener('input', () => checkConfirm(true));
confirmEl.addEventListener('blur',  () => checkConfirm(false));

/* ---------------------------------------------------------------
   SIGNUP -> OTP MODAL
--------------------------------------------------------------- */
const signupForm = document.getElementById('signupForm');
const registerBtn = document.getElementById('registerBtn');
const otpModal = new bootstrap.Modal(document.getElementById('otpModal'));

const otpForm    = document.getElementById('otpForm');
const otpCode    = document.getElementById('otpCode');
const otpTarget  = document.getElementById('otpTarget');
const otpDevBox  = document.getElementById('otpDevBox');
const verifyBtn  = document.getElementById('verifyBtn');
const resendBtn  = document.getElementById('resendBtn');

let resendTimer = null;

function startResendCooldown(seconds) {
    clearInterval(resendTimer);

    let left = seconds;
    resendBtn.disabled = true;

    const tick = () => {
        resendBtn.textContent = left > 0 ? `Resend in ${left}s` : 'Resend code';

        if (left <= 0) {
            resendBtn.disabled = false;
            clearInterval(resendTimer);
        }

        left--;
    };

    tick();
    resendTimer = setInterval(tick, 1000);
}

/* Shown when the code could not be emailed (e.g. no internet). */
function showDevCode(code, reason) {
    const lead   = document.getElementById('otpLead');
    const target = document.getElementById('otpTarget');

    if (!code) {
        otpDevBox.classList.add('d-none');
        lead.textContent = 'Enter the 6-digit code we sent to';
        target.style.display = '';
        return;
    }

    lead.textContent = 'Enter the 6-digit code shown below.';
    target.style.display = 'none';

    otpDevBox.innerHTML = `
        <div style="font-weight:700;font-size:13.5px;margin-bottom:3px;">
            <i class="bi bi-wifi-off me-1"></i>We couldn't email your code
        </div>
        <div class="reason"></div>
        <div class="code" style="font-size:28px;font-weight:700;letter-spacing:8px;padding-left:8px;color:#0f172a;background:#fff;border:1px dashed #fcd34d;border-radius:12px;margin:10px auto 2px;max-width:230px;user-select:all;"></div>`;
    otpDevBox.querySelector('.reason').textContent = `${reason || 'The email could not be sent'}, so here is your code instead:`;
    otpDevBox.querySelector('.code').textContent = code;
    otpDevBox.classList.remove('d-none');
}

async function postForm(data) {
    const response = await fetch('signup_process.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: data
    });

    return response.json();
}

signupForm.addEventListener('submit', async function (event) {
    event.preventDefault();

    if (nameEl.value.trim().length < 2) {
        showToast('Name must be at least 2 characters.', 'danger');
        nameEl.focus();
        return;
    }

    if (!checkEmail() || !checkPassword() || !checkConfirm()) {
        showToast('Please fix the highlighted fields before continuing.', 'danger');
        return;
    }

    registerBtn.disabled = true;
    registerBtn.textContent = 'Sending code...';

    try {
        const result = await postForm(new FormData(signupForm));

        if (!result.success) {
            showToast(result.message, 'danger');

            if (result.field) {
                const field = document.getElementById(result.field);
                if (field) { field.classList.add('is-invalid'); field.focus(); }
            }

            return;
        }

        otpTarget.textContent = result.masked || result.email;
        otpCode.value = '';
        otpCode.classList.remove('is-invalid');

        showDevCode(result.dev_code, result.reason);
        startResendCooldown(result.cooldown || 60);

        otpModal.show();
        setTimeout(() => otpCode.focus(), 400);

        showToast(result.message, result.dev_code ? 'warning' : 'success');

    } catch (error) {
        showToast('Something went wrong. Please try again.', 'danger');
    } finally {
        registerBtn.disabled = false;
        registerBtn.textContent = 'Register';
    }
});

/* ---------------------------------------------------------------
   VERIFY
--------------------------------------------------------------- */
otpCode.addEventListener('input', function () {
    this.value = this.value.replace(/\D/g, '');
    this.classList.remove('is-invalid');
});

otpForm.addEventListener('submit', async function (event) {
    event.preventDefault();

    if (otpCode.value.length !== 6) {
        otpCode.classList.add('is-invalid');
        showToast('Please enter all 6 digits.', 'danger');
        return;
    }

    verifyBtn.disabled = true;
    verifyBtn.textContent = 'Verifying...';

    const data = new FormData(otpForm);

    try {
        const result = await postForm(data);

        if (!result.success) {
            otpCode.classList.add('is-invalid');
            otpCode.value = '';
            otpCode.focus();

            showToast(result.message, 'danger');

            if (result.expired) {
                clearInterval(resendTimer);
                otpModal.hide();
            }

            return;
        }

        clearInterval(resendTimer);
        showToast('Email verified. Redirecting to login...', 'success', 2500);

        setTimeout(() => { window.location.href = result.redirect || 'login.php'; }, 1200);

    } catch (error) {
        showToast('Something went wrong. Please try again.', 'danger');
    } finally {
        verifyBtn.disabled = false;
        verifyBtn.innerHTML = '<i class="bi bi-check-circle me-1"></i> Verify &amp; Create Account';
    }
});

/* ---------------------------------------------------------------
   RESEND / CANCEL
--------------------------------------------------------------- */
resendBtn.addEventListener('click', async function () {
    resendBtn.disabled = true;

    const data = new FormData();
    data.append('csrf_token', document.querySelector('#otpForm [name=csrf_token]').value);
    data.append('action', 'resend_otp');

    try {
        const result = await postForm(data);

        showToast(result.message, result.success ? 'success' : 'danger');

        if (result.success) {
            showDevCode(result.dev_code, result.reason);
            otpCode.value = '';
            otpCode.focus();
        }

        if (result.expired) {
            clearInterval(resendTimer);
            otpModal.hide();
            return;
        }

        startResendCooldown(result.cooldown || 60);

    } catch (error) {
        showToast('Could not resend the code.', 'danger');
        resendBtn.disabled = false;
    }
});

document.getElementById('cancelOtp').addEventListener('click', function () {
    clearInterval(resendTimer);
    otpModal.hide();
    emailEl.focus();
    emailEl.select();
});
</script>

</body>
</html>
