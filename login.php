<?php
require 'auth.php';

$flash   = get_flash();
$token   = csrf_token();
$prefill = $_SESSION['login_prefill'] ?? '';
unset($_SESSION['login_prefill']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login - MAEXX</title>

<link rel="icon" type="image/png" href="img/LOGO.png">
<link href="assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
<link href="assets/vendor/bootstrap-icons/bootstrap-icons.min.css" rel="stylesheet">

<!-- Poppins font (stored locally) -->
<link href="assets/fonts/poppins/poppins.css" rel="stylesheet">

<style>
body {
    margin: 0;
    height: 100vh;
    font-family: 'Poppins', sans-serif;
    background: #243f5f;
    display: flex;
    align-items: center;
    justify-content: center;
}

/* container */
.auth-container {
    width: 900px;
    max-width: 95%;
    height: 520px;
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
.form-group i:first-child {
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

/* button */
.btn-login {
    border-radius: 12px;
    padding: 12px;
    background: #243f5f;
    border: none;
}

.btn-login:hover {
    background: #1b2f47;
}

.btn-login:disabled {
    background: #94a3b8;
    cursor: not-allowed;
}

/* forgot password */
.forgot-link {
    color: #243f5f;
    text-decoration: none;
    font-size: 14px;
}

.forgot-link:hover {
    text-decoration: underline;
}

/* signup */
.signup-text {
    margin-top: 15px;
    text-align: center;
    font-size: 14px;
}

.signup-text span {
    color: #9ca3af;
}

.signup-text a {
    color: #243f5f;
    font-weight: 600;
    text-decoration: none;
}

.signup-text a:hover {
    text-decoration: underline;
}

/* ✅ TOASTS */
.toast-stack {
    position: fixed;
    top: 24px;
    right: 24px;
    z-index: 1080;
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

.app-toast.hiding {
    animation: toast-out 0.25s ease forwards;
}

.app-toast .toast-icon {
    font-size: 18px;
    line-height: 1.3;
}

.app-toast .toast-title {
    font-weight: 600;
    color: #0f172a;
    margin-bottom: 2px;
}

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
    .auth-container {
        flex-direction: column;
        height: auto;
    }

    .left-panel {
        display: none;
    }

    .toast-stack {
        top: 12px;
        right: 12px;
        left: 12px;
        max-width: none;
    }
}
</style>
</head>

<body>

<!-- ✅ TOAST CONTAINER -->
<div class="toast-stack" id="toastStack"></div>

<div class="auth-container">

    <!-- LEFT -->
    <div class="left-panel">
        <img src="img/LOGO.png" alt="Logo" style="border-radius: 50%;">
        <h2>MAEXX2 Enterprises Inc.</h2>
        <p>Inventory & Sales Monitoring System</p>
    </div>

    <!-- RIGHT -->
    <div class="right-panel">

        <h4 class="mb-2">Welcome Back!</h4>
        <p class="text-muted mb-4">Enter your username and password to continue</p>

        <form method="POST" action="login_process.php" id="loginForm">

            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($token); ?>">

            <!-- USERNAME -->
            <div class="form-group">
                <i class="bi bi-person"></i>
                <input type="text"
                       id="login"
                       name="login"
                       class="form-control"
                       placeholder="Username or Email"
                       value="<?php echo htmlspecialchars($prefill); ?>"
                       autocomplete="username"
                       required>
            </div>

            <!-- PASSWORD -->
            <div class="form-group">
                <i class="bi bi-lock"></i>
                <input type="password" id="password" name="password" class="form-control" placeholder="Password" autocomplete="current-password" required>
                <i class="bi bi-eye-slash toggle-password" id="togglePassword"></i>
            </div>

            <!-- LOGIN BUTTON -->
            <button type="submit" class="btn btn-login w-100 text-white" id="loginBtn">
                Login
            </button>

        </form>

        <!-- FORGOT PASSWORD -->
        <div class="text-end mt-2 mb-1">
            <a href="forgot_password.php" class="forgot-link">Forgot password?</a>
        </div>

    </div>

</div>

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
    danger:  'Sign in failed',
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

/* Flash message from the server, shown as a toast instead of an
   inline alert so the form stays put and can be retried immediately. */
<?php if ($flash): ?>
showToast(
    <?php echo json_encode($flash['message']); ?>,
    <?php echo json_encode($flash['type']); ?>,
    <?php echo $flash['type'] === 'danger' ? 7000 : 5000; ?>
);

<?php if ($flash['type'] === 'danger'): ?>
/* Put the user straight back into the password field, cleared. */
window.addEventListener('DOMContentLoaded', () => {
    const pw = document.getElementById('password');
    const id = document.getElementById('login');

    pw.value = '';
    pw.classList.add('is-invalid');

    (id.value ? pw : id).focus();

    [id, pw].forEach(el => el.addEventListener('input', () => pw.classList.remove('is-invalid'), { once: true }));
});
<?php endif; ?>
<?php endif; ?>

/* ---------------------------------------------------------------
   PASSWORD TOGGLE
--------------------------------------------------------------- */
const togglePassword = document.querySelector("#togglePassword");
const password = document.querySelector("#password");

togglePassword.addEventListener("click", function () {
    const type = password.getAttribute("type") === "password" ? "text" : "password";
    password.setAttribute("type", type);

    this.classList.toggle("bi-eye");
    this.classList.toggle("bi-eye-slash");
});

/* Block double submits. */
document.getElementById('loginForm').addEventListener('submit', function () {
    const btn = document.getElementById('loginBtn');
    btn.disabled = true;
    btn.textContent = 'Signing in...';

    setTimeout(() => {
        btn.disabled = false;
        btn.textContent = 'Login';
    }, 4000);
});
</script>

</body>
</html>
