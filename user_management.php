<?php
require 'auth.php';
require 'print_template.php';
require 'notifications.php';
require_role('Administrator');

$user  = get_logged_in_user();

/* stock alert count for sidebar badge */
$_allProds        = array_filter(load_products(), fn($p) => empty($p['archived']));
$_sidebarAlerts   = count(array_filter($_allProds, fn($p) => intval($p['stock']) === 0))
                  + count(array_filter($_allProds, fn($p) => intval($p['stock']) > 0 && intval($p['stock']) < intval($p['threshold'])));

/* ===== HANDLE POST ===== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf('user_management.php');

    $action = $_POST['action'] ?? '';
    $userId = intval($_POST['user_id'] ?? 0);

    if ($action === 'create' || $action === 'update') {
        // Passwords are not trimmed: leading/trailing spaces are part of them.
        $v = validate_user_input($_POST, $action === 'update' ? $userId : null);

        if (!$v['ok']) {
            set_flash($v['message'], 'danger');
        } else {
            $result = $action === 'create'
                ? create_user($v['fullname'], $v['username'], $v['password'], $v['role'])
                : update_user($userId, $v['fullname'], $v['username'], $v['role'], $v['password'] !== '' ? $v['password'] : null);
            set_flash($result['message'], $result['success'] ? 'success' : 'danger');
        }
        header('Location: user_management.php'); exit;
    }

    if ($action === 'deactivate') {
        $adminPass = $_POST['admin_password'] ?? '';
        $v         = validate_user_deactivation($userId);

        if (!$v['ok']) {
            set_flash($v['message'], 'danger');
        } elseif ($adminPass === '') {
            set_flash('Please enter your admin password to confirm.', 'danger');
        } elseif (!verify_current_user_password($adminPass)) {
            set_flash('Incorrect admin password. Deactivation cancelled.', 'danger');
        } else {
            $result = deactivate_user($userId);
            set_flash($result['success'] ? "{$v['user']['name']} has been deactivated." : $result['message'],
                      $result['success'] ? 'success' : 'danger');
        }
        header('Location: user_management.php'); exit;
    }

    if ($action === 'reactivate') {
        $v = validate_user_reactivation($userId);

        if (!$v['ok']) {
            set_flash($v['message'], 'danger');
        } else {
            $result = reactivate_user($userId);
            set_flash($result['success'] ? "{$v['user']['name']} has been reactivated." : $result['message'],
                      $result['success'] ? 'success' : 'danger');
        }
        header('Location: user_management.php'); exit;
    }
}

$flash = get_flash();
$users = load_users();

$activeUsers     = array_values(array_filter($users, fn($u) => ($u['status'] ?? 'active') === 'active'));
$inactiveUsers   = array_values(array_filter($users, fn($u) => ($u['status'] ?? 'active') === 'inactive'));
$adminCount      = count(array_filter($activeUsers, fn($u) => $u['role'] === 'Administrator'));
$staffCount      = count(array_filter($activeUsers, fn($u) => $u['role'] !== 'Administrator'));

/** The user record for the edit form, without the password hash. */
function user_for_js(array $u): string
{
    unset($u['password']);
    return htmlspecialchars(json_encode($u), ENT_QUOTES);
}

// Activity logs
global $pdo;
$logs = $pdo->query(
    'SELECT message, created_at AS timestamp, user_email AS user FROM activity_logs ORDER BY log_id DESC LIMIT 30'
)->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>User Management - MAEXX</title>
<link rel="icon" type="image/png" href="img/LOGO.png">
<link href="assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
<link href="assets/vendor/bootstrap-icons/bootstrap-icons.min.css" rel="stylesheet">
<link href="assets/fonts/poppins/poppins.css" rel="stylesheet">
<style>
* { box-sizing:border-box;margin:0;padding:0; }
body { font-family:'Poppins',sans-serif;background:#f1f5f9;color:#1e293b; }

.sidebar { width:260px;background:linear-gradient(180deg,#243f5f,#1a2f47);min-height:100vh;position:fixed;left:0;top:0;display:flex;flex-direction:column;z-index:100;box-shadow:4px 0 20px rgba(0,0,0,.15); }
.sidebar-brand { padding:28px 20px 24px;text-align:center;border-bottom:1px solid rgba(255,255,255,.08); }
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

.main-wrapper { margin-left:260px;min-height:100vh;display:flex;flex-direction:column; }
.topbar { background:#fff;padding:16px 28px;display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid #e2e8f0;position:sticky;top:0;z-index:50;box-shadow:0 2px 8px rgba(0,0,0,.04); }
.topbar-left h4 { font-size:18px;font-weight:700;color:#1e293b;margin:0; }
.topbar-left p  { font-size:12px;color:#94a3b8;margin:2px 0 0; }
.topbar-right   { display:flex;align-items:center;gap:16px; }
.user-pill { display:flex;align-items:center;gap:10px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:999px;padding:6px 14px 6px 8px;text-decoration:none;color:inherit;cursor:pointer;transition:background .2s,border-color .2s; }
.user-pill:hover { background:#f1f5f9;border-color:#cbd5e1; }
.user-avatar { width:30px;height:30px;background:#243f5f;color:#fff;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700; }
.user-info .name { font-size:12px;font-weight:600;color:#1e293b; }
.user-info .role { font-size:10px;color:#94a3b8; }

.page-content { padding:28px; }

/* STATS */
.stats-grid { display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:24px; }
.stat-card { background:#fff;border-radius:16px;padding:18px 20px;border:1px solid #e2e8f0;box-shadow:0 2px 8px rgba(0,0,0,.04);display:flex;align-items:center;gap:14px; }
.stat-icon { width:44px;height:44px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0; }
.si-blue   { background:#eff6ff;color:#3b82f6; }
.si-green  { background:#f0fdf4;color:#22c55e; }
.si-purple { background:#f5f3ff;color:#7c3aed; }
.si-red    { background:#fff1f2;color:#ef4444; }
.stat-info .value { font-size:24px;font-weight:700;color:#1e293b;line-height:1; }
.stat-info .label { font-size:11px;color:#64748b;margin-top:3px; }

/* LAYOUT */
.content-row { display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px; }

/* CARDS */
.main-card { background:#fff;border-radius:18px;border:1px solid #e2e8f0;box-shadow:0 2px 10px rgba(0,0,0,.05);overflow:hidden;margin-bottom:20px; }
.card-toolbar { padding:18px 22px;display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid #f1f5f9;flex-wrap:wrap;gap:12px; }
.card-toolbar h5 { font-size:15px;font-weight:700;color:#1e293b;margin:0; }
.card-toolbar p  { font-size:11px;color:#94a3b8;margin:2px 0 0; }

/* TABS */
.tab-bar { display:flex;gap:4px;padding:14px 22px 0;border-bottom:2px solid #f1f5f9; }
.tab-btn { padding:9px 18px;font-size:13px;font-weight:600;color:#64748b;border:none;background:none;cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-2px;transition:all .2s;font-family:'Poppins',sans-serif;display:flex;align-items:center;gap:6px; }
.tab-btn.active { color:#243f5f;border-bottom-color:#243f5f; }
.tab-count { background:#f1f5f9;color:#64748b;padding:2px 7px;border-radius:999px;font-size:10px;font-weight:700; }
.tab-btn.active .tab-count { background:#243f5f;color:#fff; }

/* SEARCH */
.search-bar { padding:14px 22px;background:#f8fafc;border-bottom:1px solid #f1f5f9;display:flex;gap:10px;flex-wrap:wrap; }
.search-wrap { position:relative;flex:1;min-width:200px; }
.search-wrap i { position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:14px; }
.search-wrap input { padding-left:36px;width:100%; }
.form-control,.form-select { border-radius:10px;font-size:13px;padding:9px 13px;border:1.5px solid #e2e8f0;font-family:'Poppins',sans-serif;transition:border-color .2s; }
.form-control:focus,.form-select:focus { border-color:#243f5f;box-shadow:0 0 0 3px rgba(36,63,95,.08);outline:none; }

/* TABLE */
table { width:100%;border-collapse:collapse; }
thead th { font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:#94a3b8;padding:12px 16px;border-bottom:2px solid #f1f5f9;background:#f8fafc;text-align:left;white-space:nowrap; }
tbody td { font-size:12px;color:#475569;padding:13px 16px;border-bottom:1px solid #f8fafc;vertical-align:middle; }
tbody tr:hover { background:#f8fafc; }
tbody tr:last-child td { border-bottom:none; }

/* USER AVATAR IN TABLE */
.user-cell { display:flex;align-items:center;gap:10px; }
.user-av { width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:700;color:#fff;flex-shrink:0; }

/* BADGES */
.badge-role { display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:999px;font-size:10px;font-weight:700; }
.br-admin  { background:#eff6ff;color:#1d4ed8; }
.br-staff  { background:#f0fdf4;color:#166534; }
.badge-status { display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:999px;font-size:10px;font-weight:700; }
.bs-active   { background:#dcfce7;color:#166534; }
.bs-inactive { background:#fee2e2;color:#991b1b; }

/* ACTION BTN */
.btn-act { width:30px;height:30px;border-radius:8px;border:none;display:inline-flex;align-items:center;justify-content:center;font-size:13px;cursor:pointer;transition:all .15s; }
.btn-edit       { background:#eff6ff;color:#2563eb; }
.btn-edit:hover { background:#dbeafe; }
.btn-deact       { background:#fff1f2;color:#dc2626; }
.btn-deact:hover { background:#fecaca; }
.btn-react       { background:#dcfce7;color:#16a34a; }
.btn-react:hover { background:#bbf7d0; }

/* PRIMARY BTN */
.btn-primary-custom { background:#243f5f;color:#fff;border:none;border-radius:10px;padding:9px 18px;font-size:13px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:all .2s;font-family:'Poppins',sans-serif; }
.btn-primary-custom:hover { background:#1a2f47; }

/* MODAL */
.modal-content { border:none;border-radius:20px;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,.15); }
.modal-header  { padding:20px 24px;border:none; }
.modal-title   { font-size:16px;font-weight:700; }
.modal-body    { padding:24px; }
.modal-footer  { padding:16px 24px;background:#f8fafc;border-top:1px solid #f1f5f9; }
.form-label    { font-size:12px;font-weight:600;color:#475569;margin-bottom:5px; }
.header-primary { background:linear-gradient(135deg,#243f5f,#1a2f47);color:#fff; }
.header-primary .btn-close { filter:brightness(0) invert(1); }
.header-red { background:linear-gradient(135deg,#dc2626,#b91c1c);color:#fff; }
.header-red .btn-close { filter:brightness(0) invert(1); }

/* INPUT GRID */
.input-grid { display:grid;grid-template-columns:1fr 1fr;gap:14px; }
@media(max-width:576px){ .input-grid { grid-template-columns:1fr; } }

/* FLASH */
.flash-box { padding:12px 18px;border-radius:12px;margin-bottom:20px;font-size:13px;display:flex;align-items:center;gap:10px; }
.flash-success { background:#f0fdf4;color:#166534;border:1px solid #bbf7d0; }
.flash-danger  { background:#fff1f2;color:#991b1b;border:1px solid #fecaca; }

/* DEACTIVATE WARNING */
.deact-icon { width:64px;height:64px;background:#fff1f2;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;font-size:28px;color:#dc2626; }

/* ACTIVITY LOG */
.log-item { display:flex;gap:12px;padding:10px 0;border-bottom:1px solid #f8fafc;align-items:flex-start; }
.log-item:last-child { border-bottom:none; }
.log-dot { width:8px;height:8px;border-radius:50%;background:#243f5f;margin-top:5px;flex-shrink:0; }
.log-text { font-size:12px;color:#475569;line-height:1.5; }
.log-time { font-size:10px;color:#94a3b8;margin-top:2px; }

/* EMPTY */
.empty-state { text-align:center;padding:50px 20px;color:#94a3b8; }
.empty-state i { font-size:48px;display:block;margin-bottom:12px; }
.empty-state .title { font-size:14px;font-weight:600;color:#64748b; }

/* PASSWORD STRENGTH */
.pw-strength { height:4px;border-radius:999px;background:#f1f5f9;margin-top:6px;overflow:hidden; }
.pw-fill { height:100%;border-radius:999px;transition:width .3s,background .3s; }

/* ROLE INFO */
.role-info { background:#f8fafc;border-radius:10px;padding:12px 14px;font-size:12px;color:#64748b;margin-top:8px;border:1px solid #f1f5f9; }
.role-info strong { color:#1e293b; }
.btn-print-table { background:#7c3aed;color:#fff;border:none;border-radius:10px;padding:9px 18px;font-size:13px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;font-family:'Poppins',sans-serif;transition:all .2s; }
.btn-print-table:hover { background:#6d28d9; }
<?= maexx_print_css() ?>
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
        <a href="dashboard_admin.php"><i class="bi bi-grid-1x2-fill"></i> Dashboard</a>
        <a href="product_management.php"><i class="bi bi-box-seam-fill"></i> Product Monitoring</a>
        <a href="inventory.php"><i class="bi bi-boxes"></i> Inventory<?php if ($_sidebarAlerts > 0): ?> <span class="badge-count"><?= $_sidebarAlerts ?></span><?php endif; ?></a>
        <a href="sales.php"><i class="bi bi-cart-fill"></i> Sales</a>
        <a href="reports.php"><i class="bi bi-bar-chart-fill"></i> Reports</a>
        <div class="nav-label">Management</div>
        <a href="user_management.php" class="active"><i class="bi bi-people-fill"></i> User Management</a>    </nav>
    <div class="sidebar-footer">
        <a href="profile.php"><i class="bi bi-person-circle"></i> My Account</a>
        <a href="logout.php"><i class="bi bi-box-arrow-left"></i> Logout</a>
    </div>
</div>

<!-- MAIN -->
<div class="main-wrapper">
    <div class="topbar">
        <div class="topbar-left">
            <h4>User Management</h4>
            <p>Manage system users, roles, and access levels</p>
        </div>
        <div class="topbar-right">
            <?= maexx_notif_bell() ?>
            <a href="profile.php" class="user-pill">
                <div class="user-avatar" style="overflow:hidden;">
                    <?php $av = get_user_avatar_url($user); ?>
                    <?php if ($av !== ''): ?><img src="<?= $av ?>" style="width:100%;height:100%;object-fit:cover;" alt="">
                    <?php else: ?><?= strtoupper($user['name'][0]) ?><?php endif; ?>
                </div>
                <div class="user-info">
                    <div class="name"><?= htmlspecialchars($user['name']) ?></div>
                    <div class="role">Administrator</div>
                </div>
            </a>
        </div>
    </div>

    <div class="page-content">

        <!-- FLASH -->
        <?php if ($flash): ?>
        <div class="flash-box flash-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>">
            <i class="bi bi-<?= $flash['type'] === 'success' ? 'check-circle-fill' : 'exclamation-circle-fill' ?>"></i>
            <?= htmlspecialchars($flash['message']) ?>
        </div>
        <?php endif; ?>

        <!-- STATS -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon si-blue"><i class="bi bi-people-fill"></i></div>
                <div class="stat-info">
                    <div class="value"><?= count($activeUsers) ?></div>
                    <div class="label">Active Users</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon si-purple"><i class="bi bi-shield-fill"></i></div>
                <div class="stat-info">
                    <div class="value"><?= $adminCount ?></div>
                    <div class="label">Administrators</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon si-green"><i class="bi bi-person-badge-fill"></i></div>
                <div class="stat-info">
                    <div class="value"><?= $staffCount ?></div>
                    <div class="label">Staff Users</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon si-red"><i class="bi bi-person-x-fill"></i></div>
                <div class="stat-info">
                    <div class="value"><?= count($inactiveUsers) ?></div>
                    <div class="label">Deactivated Users</div>
                </div>
            </div>
        </div>

        <!-- USER TABLE + ACTIVITY LOG -->
        <div class="main-card">
            <div class="card-toolbar">
                <div>
                    <h5>User List</h5>
                    <p>All registered system users</p>
                </div>
                <div class="toolbar-btns" style="display:flex;gap:8px;">
                    <button class="btn-print-table no-print" onclick="printTable('usersTable', 'USER MANAGEMENT LIST', {stats:[{label:'Active Users',value:'<?= count($activeUsers) ?>'},{label:'Administrators',value:'<?= $adminCount ?>'},{label:'Staff',value:'<?= $staffCount ?>'},{label:'Deactivated',value:'<?= count($inactiveUsers) ?>'}]})">
                        <i class="bi bi-printer"></i> Print
                    </button>
                    <button class="btn-primary-custom" onclick="openAddModal()">
                        <i class="bi bi-person-plus-fill"></i> Add New User
                    </button>
                </div>
            </div>

            <!-- TABS -->
            <div class="tab-bar">
                <button class="tab-btn active" onclick="switchTab('active', this)">
                    <i class="bi bi-person-check"></i> Active Users
                    <span class="tab-count"><?= count($activeUsers) ?></span>
                </button>
                <button class="tab-btn" onclick="switchTab('inactive', this)">
                    <i class="bi bi-person-x"></i> Deactivated
                    <span class="tab-count"><?= count($inactiveUsers) ?></span>
                </button>
                <button class="tab-btn" onclick="switchTab('logs', this)">
                    <i class="bi bi-clock-history"></i> Activity Log
                    <span class="tab-count"><?= count($logs) ?></span>
                </button>
            </div>

            <!-- SEARCH -->
            <div class="search-bar" id="searchBar">
                <div class="search-wrap">
                    <i class="bi bi-search"></i>
                    <input type="text" class="form-control" id="searchInput" placeholder="Search name or username...">
                </div>
                <select class="form-select" id="filterRole" style="width:160px;flex:none;">
                    <option value="">All Roles</option>
                    <option value="administrator">Administrator</option>
                    <option value="product inventory">Staff</option>
                </select>
            </div>

            <!-- ACTIVE USERS TAB -->
            <div id="tab-active">
                <?php if (empty($activeUsers)): ?>
                <div class="empty-state">
                    <i class="bi bi-people"></i>
                    <div class="title">No active users found</div>
                </div>
                <?php else: ?>
                <div style="overflow-x:auto;">
                    <table id="usersTable">
                        <thead>
                            <tr>
                                <th>User</th>
                                <th>Username</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th>Date Added</th>
                                <th style="text-align:center;">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="activeTableBody">
                        <?php
                        $avatarColors = ['#3b82f6','#22c55e','#f59e0b','#8b5cf6','#ef4444','#06b6d4','#ec4899'];
                        $ci = 0;
                        foreach ($activeUsers as $u):
                            $isCurrentUser = isset($u['id']) && isset($user['id']) && $u['id'] == $user['id'];
                            $color = $avatarColors[$ci % 7]; $ci++;
                        ?>
                        <tr data-name="<?= strtolower(htmlspecialchars($u['name'])) ?>"
                            data-username="<?= strtolower(htmlspecialchars($u['username'])) ?>"
                            data-role="<?= strtolower(htmlspecialchars($u['role'])) ?>">
                            <td>
                                <div class="user-cell">
                                    <div class="user-av" style="background:<?= $color ?>;"><?= strtoupper($u['name'][0]) ?></div>
                                    <div>
                                        <div style="font-weight:600;color:#1e293b;font-size:13px;">
                                            <?= htmlspecialchars($u['name']) ?>
                                            <?php if ($isCurrentUser): ?>
                                            <span style="font-size:10px;background:#eff6ff;color:#1d4ed8;padding:2px 6px;border-radius:999px;margin-left:4px;">You</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td style="font-family:monospace;font-size:12px;color:#64748b;">@<?= htmlspecialchars($u['username']) ?></td>
                            <td>
                                <span class="badge-role <?= $u['role'] === 'Administrator' ? 'br-admin' : 'br-staff' ?>">
                                    <i class="bi bi-<?= $u['role'] === 'Administrator' ? 'shield-fill' : 'person-badge-fill' ?>"></i>
                                    <?= htmlspecialchars($u['role'] === 'Administrator' ? 'Administrator' : 'Staff') ?>
                                </span>
                            </td>
                            <td><span class="badge-status bs-active"><i class="bi bi-check-circle-fill"></i> Active</span></td>
                            <td style="font-size:11px;color:#94a3b8;"><?= htmlspecialchars($u['created_at'] ?? '—') ?></td>
                            <td style="text-align:center;">
                                <button class="btn-act btn-edit me-1" title="Edit user"
                                    onclick="openEditModal(<?= user_for_js($u) ?>)">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <?php if (!$isCurrentUser): ?>
                                <button class="btn-act btn-deact" title="Deactivate user"
                                    onclick="openDeactivateModal(<?= $u['id'] ?? 0 ?>, '<?= htmlspecialchars($u['name'] ?? '', ENT_QUOTES) ?>')">
                                    <i class="bi bi-person-x"></i>
                                </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div id="activeEmpty" class="empty-state" style="display:none;">
                    <i class="bi bi-search"></i>
                    <div class="title">No users match your search</div>
                </div>
                <?php endif; ?>
            </div>

            <!-- INACTIVE USERS TAB -->
            <div id="tab-inactive" style="display:none;">
                <?php if (empty($inactiveUsers)): ?>
                <div class="empty-state">
                    <i class="bi bi-person-check"></i>
                    <div class="title">No deactivated users</div>
                </div>
                <?php else: ?>
                <div style="overflow-x:auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>User</th>
                                <th>Username</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th style="text-align:center;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($inactiveUsers as $u): ?>
                        <tr>
                            <td>
                                <div class="user-cell">
                                    <div class="user-av" style="background:#94a3b8;"><?= strtoupper($u['name'][0]) ?></div>
                                    <div style="font-weight:600;color:#94a3b8;font-size:13px;"><?= htmlspecialchars($u['name']) ?></div>
                                </div>
                            </td>
                            <td style="font-family:monospace;font-size:12px;color:#94a3b8;">@<?= htmlspecialchars($u['username']) ?></td>
                            <td>
                                <span class="badge-role" style="background:#f1f5f9;color:#94a3b8;">
                                    <?= htmlspecialchars($u['role'] === 'Administrator' ? 'Administrator' : 'Staff') ?>
                                </span>
                            </td>
                            <td><span class="badge-status bs-inactive"><i class="bi bi-x-circle-fill"></i> Inactive</span></td>
                            <td style="text-align:center;">
                                <form method="POST" style="display:inline;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="reactivate">
                                    <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                    <button type="submit" class="btn-act btn-react" title="Reactivate user">
                                        <i class="bi bi-arrow-counterclockwise"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <!-- ACTIVITY LOG TAB -->
            <div id="tab-logs" style="display:none;">
                <?php if (empty($logs)): ?>
                <div class="empty-state">
                    <i class="bi bi-clock-history"></i>
                    <div class="title">No activity recorded yet</div>
                </div>
                <?php else: ?>
                <div style="padding:20px 22px;">
                    <?php foreach ($logs as $log): ?>
                    <div class="log-item">
                        <div class="log-dot"></div>
                        <div>
                            <div class="log-text"><?= htmlspecialchars($log['message'] ?? '—') ?></div>
                            <div class="log-time"><i class="bi bi-clock me-1"></i><?= htmlspecialchars($log['timestamp'] ?? '—') ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

        </div><!-- end main-card -->
    </div><!-- end page-content -->
</div><!-- end main-wrapper -->

<!-- ADD USER MODAL -->
<div class="modal fade" id="userModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered" style="max-width:520px;">
        <div class="modal-content">
            <div class="modal-header header-primary">
                <h5 class="modal-title" id="modalTitle"><i class="bi bi-person-plus-fill me-2"></i>Add New User</h5>
                <button class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="userForm">
                <?= csrf_field() ?>
                <div class="modal-body">
                    <input type="hidden" name="action" id="formAction" value="create">
                    <input type="hidden" name="user_id" id="formUserId">

                    <div class="input-grid mb-3">
                        <div>
                            <label class="form-label">Full Name <span style="color:#ef4444;">*</span></label>
                            <input type="text" class="form-control" name="fullname" id="formFullname"
                                   placeholder="e.g. Juan Dela Cruz" required minlength="2" maxlength="<?= V_NAME_MAX ?>"
                                   pattern="[A-Za-zÀ-ÿÑñ .'\-]+" title="Letters, spaces, periods, apostrophes and hyphens only">
                        </div>
                        <div>
                            <label class="form-label">Username <span style="color:#ef4444;">*</span></label>
                            <input type="text" class="form-control" name="username" id="formUsername"
                                   placeholder="e.g. juan.delacruz" required minlength="3" maxlength="50"
                                   pattern="[A-Za-z0-9._@\-]+" title="Letters, numbers and . _ @ - only, no spaces"
                                   autocomplete="off">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Role <span style="color:#ef4444;">*</span></label>
                        <select class="form-select" name="role" id="formRole">
                            <option value="Product Inventory">Staff (Product Inventory)</option>
                            <option value="Administrator">Administrator</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" id="passLabel">Password <span style="color:#ef4444;">*</span></label>
                        <div style="position:relative;">
                            <input type="password" class="form-control" name="password" id="formPassword"
                                   placeholder="Enter password" oninput="checkStrength()">
                            <button type="button" onclick="togglePw()" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:#94a3b8;font-size:15px;">
                                <i class="bi bi-eye" id="eyeIcon"></i>
                            </button>
                        </div>
                        <div class="pw-strength"><div class="pw-fill" id="pwFill" style="width:0%;"></div></div>
                        <div style="font-size:10px;color:#94a3b8;margin-top:4px;" id="pwHint"></div>
                        <div id="editPassNote" style="display:none;font-size:11px;color:#94a3b8;margin-top:4px;">Leave blank to keep current password.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light fw-semibold" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn-primary-custom">
                        <i class="bi bi-check-circle"></i>
                        <span id="submitLabel">Create User</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- DEACTIVATE CONFIRM MODAL -->
<div class="modal fade" id="deactivateModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered" style="max-width:420px;">
        <div class="modal-content">
            <div class="modal-header header-red">
                <h5 class="modal-title"><i class="bi bi-person-x-fill me-2"></i>Deactivate User</h5>
                <button class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="deactivateForm">
                <?= csrf_field() ?>
                <div class="modal-body text-center">
                    <div class="deact-icon"><i class="bi bi-person-x-fill"></i></div>
                    <h5 style="font-weight:700;margin-bottom:8px;">Deactivate <span id="deactUserName"></span>?</h5>
                    <p style="color:#64748b;font-size:13px;margin-bottom:20px;">
                        This user will lose access to the system. You can reactivate them anytime.
                    </p>
                    <input type="hidden" name="action" value="deactivate">
                    <input type="hidden" name="user_id" id="deactUserId">
                    <div style="text-align:left;">
                        <label class="form-label">Enter your Admin Password to confirm <span style="color:#ef4444;">*</span></label>
                        <div style="position:relative;">
                            <input type="password" class="form-control" name="admin_password"
                                   placeholder="Your admin password" required>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light fw-semibold" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" style="background:#dc2626;color:#fff;border:none;border-radius:10px;padding:9px 20px;font-weight:600;cursor:pointer;font-family:'Poppins',sans-serif;display:inline-flex;align-items:center;gap:6px;">
                        <i class="bi bi-person-x"></i> Yes, Deactivate
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script>
/* ===== TABS ===== */
function switchTab(tab, btn) {
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    ['active','inactive','logs'].forEach(t => {
        document.getElementById('tab-' + t).style.display = t === tab ? '' : 'none';
    });
    document.getElementById('searchBar').style.display = tab === 'active' ? '' : 'none';
}

/* ===== MODALS ===== */
function openAddModal() {
    document.getElementById('modalTitle').innerHTML = '<i class="bi bi-person-plus-fill me-2"></i>Add New User';
    document.getElementById('submitLabel').textContent = 'Create User';
    document.getElementById('formAction').value = 'create';
    document.getElementById('formUserId').value = '';
    document.getElementById('userForm').reset();
    document.getElementById('editPassNote').style.display = 'none';
    document.getElementById('passLabel').innerHTML = 'Password <span style="color:#ef4444;">*</span>';
    document.getElementById('formPassword').required = true;
    new bootstrap.Modal(document.getElementById('userModal')).show();
}

function openEditModal(u) {
    document.getElementById('modalTitle').innerHTML = '<i class="bi bi-pencil-square me-2"></i>Edit User';
    document.getElementById('submitLabel').textContent = 'Save Changes';
    document.getElementById('formAction').value = 'update';
    document.getElementById('formUserId').value = u.id;
    document.getElementById('formFullname').value = u.name ?? '';
    document.getElementById('formUsername').value = u.username ?? '';
    document.getElementById('formRole').value = u.role ?? 'Product Inventory';
    document.getElementById('formPassword').value = '';
    document.getElementById('formPassword').required = false;
    document.getElementById('editPassNote').style.display = '';
    document.getElementById('passLabel').innerHTML = 'New Password';
    new bootstrap.Modal(document.getElementById('userModal')).show();
}

function openDeactivateModal(id, name) {
    document.getElementById('deactUserId').value = id;
    document.getElementById('deactUserName').textContent = name;
    new bootstrap.Modal(document.getElementById('deactivateModal')).show();
}

/* ===== PASSWORD TOGGLE ===== */
function togglePw() {
    const inp = document.getElementById('formPassword');
    const ico = document.getElementById('eyeIcon');
    if (inp.type === 'password') { inp.type = 'text'; ico.className = 'bi bi-eye-slash'; }
    else { inp.type = 'password'; ico.className = 'bi bi-eye'; }
}

/* ===== PASSWORD STRENGTH ===== */
function checkStrength() {
    const pw   = document.getElementById('formPassword').value;
    const fill = document.getElementById('pwFill');
    const hint = document.getElementById('pwHint');
    let score = 0;
    if (pw.length >= 8) score++;
    if (/[A-Z]/.test(pw)) score++;
    if (/[0-9]/.test(pw)) score++;
    if (/[^A-Za-z0-9]/.test(pw)) score++;
    const pct   = pw.length === 0 ? 0 : Math.max(20, score * 25);
    const color = ['','#ef4444','#f97316','#eab308','#22c55e'][score] || '#ef4444';
    const label = ['','Weak','Fair','Good','Strong'][score] || '';
    fill.style.width    = pct + '%';
    fill.style.background = color;

    // Minimum rules enforced by validate_password_strength() on the server.
    const missing = [];
    if (pw.length < 8)          missing.push('8+ characters');
    if (!/[A-Za-z]/.test(pw))   missing.push('a letter');
    if (!/[0-9]/.test(pw))      missing.push('a number');

    if (pw.length === 0) {
        hint.textContent = '';
    } else if (missing.length) {
        hint.textContent = `${label || 'Weak'} — still needs ${missing.join(', ')}`;
        hint.style.color = '#dc2626';
        return;
    } else {
        hint.textContent = `${label} — meets the requirements`;
    }
    hint.style.color = color;
}

/* ===== SEARCH ===== */
document.getElementById('searchInput').addEventListener('input', filterUsers);
document.getElementById('filterRole').addEventListener('change', filterUsers);

function filterUsers() {
    const q    = document.getElementById('searchInput').value.toLowerCase().trim();
    const role = document.getElementById('filterRole').value.toLowerCase();
    const rows = Array.from(document.querySelectorAll('#activeTableBody tr'));
    let visible = 0;
    rows.forEach(r => {
        const mq = !q    || r.dataset.name.includes(q) || r.dataset.username.includes(q);
        const mr = !role || r.dataset.role.includes(role);
        r.style.display = mq && mr ? '' : 'none';
        if (mq && mr) visible++;
    });
    const empty = document.getElementById('activeEmpty');
    if (empty) empty.style.display = visible === 0 && rows.length > 0 ? '' : 'none';
}
</script>
<script><?= maexx_print_js() ?></script>
<script><?= maexx_notif_js() ?></script>
</body>
</html>