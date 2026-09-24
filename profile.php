<?php
require 'auth.php';
require 'notifications.php';
require_login();

$user    = get_logged_in_user();
$isAdmin = is_admin_role($user['role']);
$userId  = intval($user['id']);

$_allProds      = array_filter(load_products(), fn($p) => empty($p['archived']));
$_sidebarAlerts = count(array_filter($_allProds, fn($p) => intval($p['stock']) === 0))
                + count(array_filter($_allProds, fn($p) => intval($p['stock']) > 0 && intval($p['stock']) < intval($p['threshold'])));
$fresh   = find_user_by_id($userId);

/* ===== HANDLE POST ===== */
$errors  = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf('profile.php');

    $name     = trim($_POST['name']     ?? '');
    $username = trim($_POST['username'] ?? '');
    $newPw    = $_POST['new_password']  ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';
    $current  = $_POST['current_password'] ?? '';

    // Validate current password
    if (!password_verify($current, $fresh['password'])) {
        $errors[] = 'Current password is incorrect.';
    }

    if ($newPw !== '' && $newPw !== $confirm) {
        $errors[] = 'New passwords do not match.';
    }

    // Avatar upload
    $avatarPath = '';
    if (!empty($_FILES['avatar']['name'])) {
        $avatarPath = save_user_avatar($userId, $_FILES['avatar']);
        if ($avatarPath === '') {
            $errors[] = 'Avatar upload failed. Use JPG/PNG/WEBP under 2 MB.';
        }
    }

    if (empty($errors)) {
        $result = update_own_profile(
            $userId,
            $name,
            $username,
            $newPw !== '' ? $newPw : null,
            $avatarPath
        );

        if ($result['success']) {
            set_flash($result['message'], 'success');
            header('Location: profile.php'); exit;
        } else {
            $errors[] = $result['message'];
        }
    }

    // Re-read fresh user so form reflects current DB values on error
    $fresh = find_user_by_id($userId);
}

$flash          = get_flash();
$dashboardLink  = $isAdmin ? 'dashboard_admin.php' : 'dashboard_inventory.php';
$avatarUrl      = get_user_avatar_url($fresh ?? $user);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Account - MAEXX</title>
<link rel="icon" type="image/png" href="img/LOGO.png">
<link href="assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
<link href="assets/vendor/bootstrap-icons/bootstrap-icons.min.css" rel="stylesheet">
<link href="assets/fonts/poppins/poppins.css" rel="stylesheet">
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Poppins', sans-serif; background: #f1f5f9; color: #1e293b; }

/* SIDEBAR */
.sidebar { width:260px; background:linear-gradient(180deg,#243f5f,#1a2f47); min-height:100vh; position:fixed; left:0; top:0; display:flex; flex-direction:column; z-index:100; box-shadow:4px 0 20px rgba(0,0,0,.15); }
.sidebar-brand { padding:28px 20px 24px; text-align:center; border-bottom:1px solid rgba(255,255,255,.08); }
.sidebar-brand img { width:65px;height:65px;border-radius:50%;object-fit:cover;border:3px solid rgba(255,255,255,.2);margin-bottom:12px; }
.sidebar-brand-name { font-size:12px;font-weight:700;color:#fff;line-height:1.5; }
.sidebar-brand-sub  { font-size:10px;color:rgba(255,255,255,.5);margin-top:2px; }
.sidebar-nav { flex:1;padding:20px 12px; }
.nav-label { font-size:9px;font-weight:700;letter-spacing:1.5px;color:rgba(255,255,255,.35);text-transform:uppercase;padding:0 10px;margin:16px 0 6px; }
.sidebar-nav a { display:flex;align-items:center;gap:12px;padding:11px 14px;color:rgba(255,255,255,.65);text-decoration:none;font-size:13px;font-weight:500;border-radius:12px;margin-bottom:2px;transition:all .2s; }
.sidebar-nav a i { font-size:17px;width:20px;text-align:center; }
.sidebar-nav a:hover { background:rgba(255,255,255,.1);color:#fff; }
.sidebar-nav a.active { background:rgba(255,255,255,.15);color:#fff;font-weight:600; }
.sidebar-nav a .badge-count { margin-left:auto;background:#e11d48;color:#fff;font-size:10px;font-weight:700;padding:2px 7px;border-radius:999px; }
.sidebar-footer { padding:16px 12px;border-top:1px solid rgba(255,255,255,.08); }
.sidebar-footer a { display:flex;align-items:center;gap:10px;padding:11px 14px;color:rgba(255,255,255,.6);text-decoration:none;font-size:13px;border-radius:12px;transition:all .2s; }
.sidebar-footer a:hover { background:rgba(255,255,255,.1);color:#fff; }
.sidebar-footer a.active { background:rgba(255,255,255,.15);color:#fff;font-weight:600; }

/* MAIN */
.main-wrapper { margin-left:260px;min-height:100vh;display:flex;flex-direction:column; }

/* TOPBAR */
.topbar { background:#fff;padding:16px 28px;display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid #e2e8f0;position:sticky;top:0;z-index:50;box-shadow:0 2px 8px rgba(0,0,0,.04); }
.topbar-left h4 { font-size:18px;font-weight:700;color:#1e293b;margin:0; }
.topbar-left p  { font-size:12px;color:#94a3b8;margin:2px 0 0; }
.topbar-right   { display:flex;align-items:center;gap:16px; }
.user-pill { display:flex;align-items:center;gap:10px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:999px;padding:6px 14px 6px 8px;text-decoration:none;color:inherit;cursor:pointer;transition:background .2s,border-color .2s; }
.user-pill:hover { background:#f1f5f9;border-color:#cbd5e1; }
.user-avatar { width:30px;height:30px;background:#243f5f;color:#fff;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;overflow:hidden;flex-shrink:0; }
.user-avatar img { width:100%;height:100%;object-fit:cover; }
.user-info .name { font-size:12px;font-weight:600;color:#1e293b; }
.user-info .role { font-size:10px;color:#94a3b8; }

/* PAGE */
.page-content { padding:28px; }

/* TWO-COLUMN LAYOUT */
.profile-layout {
    display: grid;
    grid-template-columns: 300px 1fr;
    gap: 24px;
    align-items: start;
}

/* LEFT: PROFILE CARD */
.profile-left-card {
    background: linear-gradient(160deg, #243f5f, #1a2f47);
    border-radius: 20px;
    padding: 36px 24px 28px;
    color: #fff;
    text-align: center;
    position: sticky;
    top: 96px;
}
.profile-avatar-wrap { position: relative; display: inline-block; margin-bottom: 18px; }
.profile-avatar-img {
    width: 110px; height: 110px;
    border-radius: 50%;
    background: rgba(255,255,255,.18);
    display: flex; align-items: center; justify-content: center;
    font-size: 44px; font-weight: 700; color: #fff;
    border: 4px solid rgba(255,255,255,.3);
    overflow: hidden;
    margin: 0 auto;
}
.profile-avatar-img img { width: 100%; height: 100%; object-fit: cover; }
.avatar-upload-btn {
    position: absolute;
    bottom: 4px; right: 4px;
    width: 30px; height: 30px;
    background: #fff; color: #243f5f;
    border-radius: 50%; border: none;
    display: flex; align-items: center; justify-content: center;
    font-size: 13px; cursor: pointer;
    box-shadow: 0 2px 8px rgba(0,0,0,.3);
    transition: all .15s;
}
.avatar-upload-btn:hover { background: #f1f5f9; transform: scale(1.1); }
#avatarInput { display: none; }
.profile-left-card .hero-name { font-size: 18px; font-weight: 700; line-height: 1.3; margin-bottom: 4px; }
.profile-left-card .hero-username { font-size: 12px; color: rgba(255,255,255,.6); margin-bottom: 14px; }
.profile-left-card .hero-meta { font-size: 11px; color: rgba(255,255,255,.45); margin-top: 6px; }
#avatarPreviewHint { font-size:11px;color:rgba(255,255,255,.55);margin-top:8px; }

/* FORM CARD */
.form-card {
    background: #fff;
    border-radius: 18px;
    border: 1px solid #e2e8f0;
    box-shadow: 0 2px 10px rgba(0,0,0,.05);
    overflow: hidden;
    margin-bottom: 20px;
}
.form-card-header {
    padding: 18px 24px 14px;
    border-bottom: 1px solid #f1f5f9;
    display: flex; align-items: center; gap: 10px;
}
.form-card-header i { font-size: 18px; color: #243f5f; }
.form-card-header h5 { font-size: 14px; font-weight: 700; color: #1e293b; margin: 0; }
.form-card-header p  { font-size: 11px; color: #94a3b8; margin: 2px 0 0; }
.form-card-body { padding: 24px; }

/* FORM CONTROLS */
.form-label { font-size: 12px; font-weight: 600; color: #475569; margin-bottom: 5px; }
.form-control,.form-select {
    border-radius: 10px; font-size: 13px;
    padding: 9px 13px; border: 1.5px solid #e2e8f0;
    font-family: 'Poppins', sans-serif;
    transition: border-color .2s, box-shadow .2s; width: 100%;
}
.form-control:focus,.form-select:focus {
    border-color: #243f5f;
    box-shadow: 0 0 0 3px rgba(36,63,95,.08);
    outline: none;
}
.input-hint { font-size: 11px; color: #94a3b8; margin-top: 4px; }
.input-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
@media(max-width:576px) { .input-grid { grid-template-columns: 1fr; } }

/* ROLE BADGE */
.role-badge {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 5px 12px; border-radius: 999px;
    font-size: 11px; font-weight: 700;
    background: rgba(255,255,255,.15); color: #fff;
    border: 1px solid rgba(255,255,255,.25);
    margin-top: 8px;
}

/* BUTTONS */
.btn-save {
    background: #243f5f; color: #fff; border: none;
    border-radius: 10px; padding: 10px 22px;
    font-size: 13px; font-weight: 600; cursor: pointer;
    display: inline-flex; align-items: center; gap: 6px;
    transition: all .2s; font-family: 'Poppins', sans-serif;
}
.btn-save:hover { background: #1a2f47; }
.btn-cancel {
    background: #f1f5f9; color: #475569; border: none;
    border-radius: 10px; padding: 10px 18px;
    font-size: 13px; font-weight: 600; cursor: pointer;
    font-family: 'Poppins', sans-serif; text-decoration: none;
    display: inline-flex; align-items: center; gap: 6px;
    transition: all .2s;
}
.btn-cancel:hover { background: #e2e8f0; }

/* FLASH */
.flash-box { padding:12px 18px;border-radius:12px;margin-bottom:20px;font-size:13px;display:flex;align-items:center;gap:10px; }
.flash-success { background:#f0fdf4;color:#166534;border:1px solid #bbf7d0; }
.flash-danger  { background:#fff1f2;color:#991b1b;border:1px solid #fecaca; }

/* ERROR LIST */
.error-list { background:#fff1f2;border:1px solid #fecaca;border-radius:12px;padding:12px 16px;margin-bottom:20px; }
.error-list li { font-size:13px;color:#991b1b;margin-bottom:3px; }
.error-list li:last-child { margin-bottom:0; }

/* PASSWORD STRENGTH */
.pw-strength { height: 4px; border-radius: 99px; background: #e2e8f0; margin-top: 6px; overflow: hidden; }
.pw-strength-fill { height: 100%; border-radius: 99px; transition: width .3s, background .3s; }

/* AVATAR PREVIEW OVERLAY */
#avatarPreviewWrap { position:relative; display:inline-block; }
#avatarPreviewHint { font-size:11px;color:rgba(255,255,255,.55);margin-top:6px;text-align:center; }
<?= maexx_notif_css() ?>
</style>
</head>
<body>

<!-- SIDEBAR -->
<div class="sidebar">
    <div class="sidebar-brand">
        <img src="img/LOGO.png" alt="MAEXX Logo">
        <div class="sidebar-brand-name">MAEXX 2 ENTERPRISES INC.</div>
        <div class="sidebar-brand-sub">Inventory & Sales Monitoring System</div>
    </div>
    <nav class="sidebar-nav">
        <div class="nav-label">Main Menu</div>
        <a href="<?= $dashboardLink ?>"><i class="bi bi-grid-1x2-fill"></i> Dashboard</a>
        <a href="product_management.php"><i class="bi bi-box-seam-fill"></i> Product Monitoring</a>
        <a href="inventory.php"><i class="bi bi-boxes"></i> Inventory<?php if ($_sidebarAlerts > 0): ?> <span class="badge-count"><?= $_sidebarAlerts ?></span><?php endif; ?></a>
        <a href="sales.php"><i class="bi bi-cart-fill"></i> Sales</a>
        <a href="reports.php"><i class="bi bi-bar-chart-fill"></i> Reports</a>
        <?php if ($isAdmin): ?>
        <div class="nav-label">Management</div>
        <a href="user_management.php"><i class="bi bi-people-fill"></i> User Management</a>
        <?php endif; ?>
    </nav>
    <div class="sidebar-footer">
        <a href="profile.php" class="active"><i class="bi bi-person-circle"></i> My Account</a>
        <a href="logout.php"><i class="bi bi-box-arrow-left"></i> Logout</a>
    </div>
</div>

<!-- MAIN -->
<div class="main-wrapper">

    <!-- TOPBAR -->
    <div class="topbar">
        <div class="topbar-left">
            <h4>My Account</h4>
            <p>Manage your profile, name, and password</p>
        </div>
        <div class="topbar-right">
            <?= maexx_notif_bell() ?>
            <a href="profile.php" class="user-pill">
                <div class="user-avatar">
                    <?php if ($avatarUrl !== ''): ?>
                        <img src="<?= $avatarUrl ?>?v=<?= time() ?>" alt="">
                    <?php else: ?>
                        <?= strtoupper(($user['name'] ?? 'U')[0]) ?>
                    <?php endif; ?>
                </div>
                <div class="user-info">
                    <div class="name"><?= htmlspecialchars($user['name']) ?></div>
                    <div class="role"><?= htmlspecialchars($user['role']) ?></div>
                </div>
            </a>
        </div>
    </div>

    <!-- PAGE CONTENT -->
    <div class="page-content">

        <!-- FLASH -->
        <?php if ($flash): ?>
        <div class="flash-box flash-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>">
            <i class="bi bi-<?= $flash['type'] === 'success' ? 'check-circle-fill' : 'exclamation-circle-fill' ?>"></i>
            <?= htmlspecialchars($flash['message']) ?>
        </div>
        <?php endif; ?>

        <!-- ERRORS -->
        <?php if (!empty($errors)): ?>
        <ul class="error-list">
            <?php foreach ($errors as $e): ?>
            <li><i class="bi bi-exclamation-circle me-1"></i><?= htmlspecialchars($e) ?></li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>

        <div class="profile-layout">

            <!-- LEFT: PROFILE SUMMARY CARD -->
            <div class="profile-left-card">
                <div id="avatarPreviewWrap" class="profile-avatar-wrap">
                    <div class="profile-avatar-img" id="avatarDisplay">
                        <?php if ($avatarUrl !== ''): ?>
                            <img src="<?= $avatarUrl ?>?v=<?= time() ?>" alt="" id="avatarPreviewImg">
                        <?php else: ?>
                            <span id="avatarInitial"><?= strtoupper(($fresh['name'] ?? $user['name'] ?? 'U')[0]) ?></span>
                        <?php endif; ?>
                    </div>
                    <button type="button" class="avatar-upload-btn" onclick="document.getElementById('avatarInput').click()" title="Change photo">
                        <i class="bi bi-camera-fill"></i>
                    </button>
                </div>
                <div id="avatarPreviewHint" style="display:none;">Click <strong>Save Changes</strong> to apply photo</div>

                <div class="hero-name" id="heroName"><?= htmlspecialchars($fresh['name'] ?? $user['name']) ?></div>
                <div class="hero-username" id="heroUsername">@<?= htmlspecialchars($fresh['username'] ?? '') ?></div>

                <div class="role-badge"><i class="bi bi-shield-check"></i> <?= htmlspecialchars($user['role']) ?></div>
                <div class="hero-meta"><i class="bi bi-calendar3 me-1"></i>Member since <?= htmlspecialchars($fresh['created_at'] ?? 'N/A') ?></div>

                <!-- Divider -->
                <div style="border-top:1px solid rgba(255,255,255,.12);margin:20px 0;"></div>

                <!-- Quick info rows -->
                <div style="text-align:left;">
                    <div style="font-size:10px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:rgba(255,255,255,.4);margin-bottom:10px;">Account Info</div>
                    <div style="display:flex;align-items:center;gap:8px;font-size:12px;color:rgba(255,255,255,.7);margin-bottom:8px;">
                        <i class="bi bi-person" style="width:16px;text-align:center;"></i>
                        <span id="infoName"><?= htmlspecialchars($fresh['name'] ?? $user['name']) ?></span>
                    </div>
                    <div style="display:flex;align-items:center;gap:8px;font-size:12px;color:rgba(255,255,255,.7);margin-bottom:8px;">
                        <i class="bi bi-at" style="width:16px;text-align:center;"></i>
                        <span id="infoUsername"><?= htmlspecialchars($fresh['username'] ?? '') ?></span>
                    </div>
                    <div style="display:flex;align-items:center;gap:8px;font-size:12px;color:rgba(255,255,255,.7);">
                        <i class="bi bi-shield-check" style="width:16px;text-align:center;"></i>
                        <span><?= htmlspecialchars($user['role']) ?></span>
                    </div>
                </div>
            </div>

            <!-- RIGHT: EDIT FORMS -->
            <div>
                <form method="POST" enctype="multipart/form-data" id="profileForm">
                    <?= csrf_field() ?>
                    <input type="file" name="avatar" id="avatarInput" accept="image/jpeg,image/png,image/webp,image/gif">

                    <!-- PROFILE INFO -->
                    <div class="form-card">
                        <div class="form-card-header">
                            <i class="bi bi-person-fill"></i>
                            <div>
                                <h5>Profile Information</h5>
                                <p>Update your display name and username</p>
                            </div>
                        </div>
                        <div class="form-card-body">
                            <div class="input-grid mb-3">
                                <div>
                                    <label class="form-label">Display Name <span style="color:#ef4444;">*</span></label>
                                    <input type="text" class="form-control" name="name" id="nameInput"
                                           value="<?= htmlspecialchars($fresh['name'] ?? $user['name']) ?>"
                                           maxlength="<?= V_NAME_MAX ?>" required>
                                </div>
                                <div>
                                    <label class="form-label">Username <span style="color:#ef4444;">*</span></label>
                                    <input type="text" class="form-control" name="username" id="usernameInput"
                                           value="<?= htmlspecialchars($fresh['username'] ?? '') ?>"
                                           maxlength="<?= V_NAME_MAX ?>" required>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- CHANGE PASSWORD -->
                    <div class="form-card">
                        <div class="form-card-header">
                            <i class="bi bi-lock-fill"></i>
                            <div>
                                <h5>Change Password</h5>
                                <p>Leave new password blank to keep your current one</p>
                            </div>
                        </div>
                        <div class="form-card-body">
                            <div class="mb-3">
                                <label class="form-label">Current Password <span style="color:#ef4444;">*</span></label>
                                <div style="position:relative;">
                                    <input type="password" class="form-control" name="current_password" id="currentPw"
                                           placeholder="Enter your current password" required style="padding-right:42px;">
                                    <button type="button" onclick="togglePw('currentPw', this)"
                                            style="position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;color:#94a3b8;cursor:pointer;font-size:15px;">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="input-grid">
                                <div>
                                    <label class="form-label">New Password</label>
                                    <div style="position:relative;">
                                        <input type="password" class="form-control" name="new_password" id="newPw"
                                               placeholder="Min. 8 characters" minlength="8" style="padding-right:42px;">
                                        <button type="button" onclick="togglePw('newPw', this)"
                                                style="position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;color:#94a3b8;cursor:pointer;font-size:15px;">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                    </div>
                                    <div class="pw-strength"><div class="pw-strength-fill" id="pwStrengthFill" style="width:0%;"></div></div>
                                    <div class="input-hint" id="pwStrengthLabel"></div>
                                </div>
                                <div>
                                    <label class="form-label">Confirm New Password</label>
                                    <div style="position:relative;">
                                        <input type="password" class="form-control" name="confirm_password" id="confirmPw"
                                               placeholder="Repeat new password" style="padding-right:42px;">
                                        <button type="button" onclick="togglePw('confirmPw', this)"
                                                style="position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;color:#94a3b8;cursor:pointer;font-size:15px;">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                    </div>
                                    <div class="input-hint" id="pwMatchLabel"></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ACTIONS -->
                    <div style="display:flex;gap:10px;align-items:center;margin-bottom:40px;">
                        <button type="submit" class="btn-save">
                            <i class="bi bi-check-circle"></i> Save Changes
                        </button>
                        <a href="<?= $dashboardLink ?>" class="btn-cancel">
                            <i class="bi bi-x"></i> Cancel
                        </a>
                    </div>

                </form>
            </div><!-- end right col -->

        </div><!-- end profile-layout -->

    </div><!-- end page-content -->
</div><!-- end main-wrapper -->

<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script>
/* ===== AVATAR PREVIEW ===== */
document.getElementById('avatarInput').addEventListener('change', function () {
    const file = this.files[0];
    if (!file) return;

    if (file.size > 2 * 1024 * 1024) {
        alert('Image is too large. Please choose a file under 2 MB.');
        this.value = '';
        return;
    }

    const reader = new FileReader();
    reader.onload = e => {
        const display = document.getElementById('avatarDisplay');
        display.innerHTML = `<img src="${e.target.result}" alt="" id="avatarPreviewImg">`;
        document.getElementById('avatarPreviewHint').style.display = '';

        // Update hero initial -> image
        const initial = document.getElementById('avatarInitial');
        if (initial) initial.style.display = 'none';
    };
    reader.readAsDataURL(file);
});

/* ===== LIVE NAME/USERNAME → LEFT CARD ===== */
document.getElementById('nameInput').addEventListener('input', function () {
    const v = this.value || '—';
    document.getElementById('heroName').textContent = v;
    document.getElementById('infoName').textContent = v;
});
document.getElementById('usernameInput').addEventListener('input', function () {
    const v = this.value || '';
    document.getElementById('heroUsername').textContent = v ? '@' + v : '';
    document.getElementById('infoUsername').textContent = v;
});

/* ===== TOGGLE PASSWORD VISIBILITY ===== */
function togglePw(id, btn) {
    const inp = document.getElementById(id);
    const show = inp.type === 'password';
    inp.type = show ? 'text' : 'password';
    btn.querySelector('i').className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
}

/* ===== PASSWORD STRENGTH ===== */
document.getElementById('newPw').addEventListener('input', function () {
    const v = this.value;
    let score = 0;
    if (v.length >= 8)  score++;
    if (/[A-Z]/.test(v)) score++;
    if (/[0-9]/.test(v)) score++;
    if (/[^A-Za-z0-9]/.test(v)) score++;

    const fill  = document.getElementById('pwStrengthFill');
    const label = document.getElementById('pwStrengthLabel');
    const colors = ['#ef4444','#f59e0b','#f59e0b','#22c55e','#16a34a'];
    const labels = ['','Too short','Fair','Good','Strong'];
    fill.style.width      = (score * 25) + '%';
    fill.style.background = colors[score] || '#e2e8f0';
    label.textContent     = v.length === 0 ? '' : (labels[score] || '');
    checkMatch();
});

/* ===== PASSWORD MATCH ===== */
document.getElementById('confirmPw').addEventListener('input', checkMatch);

function checkMatch() {
    const nv = document.getElementById('newPw').value;
    const cv = document.getElementById('confirmPw').value;
    const lbl = document.getElementById('pwMatchLabel');
    if (!cv) { lbl.textContent = ''; return; }
    lbl.textContent = nv === cv ? '✓ Passwords match' : '✗ Passwords do not match';
    lbl.style.color = nv === cv ? '#16a34a' : '#ef4444';
}
</script>
<script><?= maexx_notif_js() ?></script>
</body>
</html>
