<?php
require 'auth.php';
require 'print_template.php';
require 'notifications.php';
require_login();

$user     = get_logged_in_user();
$isAdmin  = is_admin_role($user['role']);

/* ================= CRUD ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf('product_management.php');

    $action = $_POST['action'] ?? '';
    $id     = intval($_POST['product_id'] ?? 0);

    if ($action === 'create' || $action === 'update') {
        $v = validate_product_input($_POST, $action === 'update' ? $id : null, !empty($_POST['confirm_loss']));

        if (!$v['ok']) {
            set_flash($v['message'], 'danger');
        } elseif ($action === 'create') {
            create_product($v['name'], $v['price'], $v['cost'], 0, $v['threshold'], $v['category'], $v['unit'], $v['description']);
            set_flash('Product "' . $v['name'] . '" added successfully.', 'success');
        } else {
            update_product($id, $v['name'], $v['price'], $v['cost'], $v['threshold'], $v['category'], $v['unit'], $v['description']);
            set_flash('Product "' . $v['name'] . '" updated successfully.', 'success');
        }
        header('Location: product_management.php?highlight=' . urlencode($v['name'])); exit;
    }

    if ($action === 'archive') {
        $v = validate_product_archive($id);
        if ($v['ok'] && archive_product($id)) {
            set_flash('"' . $v['product']['name'] . '" archived successfully.', 'success');
        } else {
            set_flash($v['ok'] ? 'Could not archive product.' : $v['message'], 'danger');
        }
        header('Location: product_management.php'); exit;
    }

    if ($action === 'restore') {
        $v = validate_product_restore($id);
        if ($v['ok'] && restore_product($id)) {
            set_flash('"' . $v['product']['name'] . '" restored successfully.', 'success');
        } else {
            set_flash($v['ok'] ? 'Could not restore product.' : $v['message'], 'danger');
        }
        header('Location: product_management.php'); exit;
    }
}

$flash    = get_flash();
$products = load_products();

// Separate active and archived
$activeProducts   = array_values(array_filter($products, fn($p) => empty($p['archived'])));
$archivedProducts = array_values(array_filter($products, fn($p) => !empty($p['archived'])));

// Order by status: In Stock first, then Low Stock, then Out of Stock.
// Same rules as the status badge; ties are broken alphabetically.
$statusRank = function (array $p): int {
    if (intval($p['stock']) == 0) {
        return 2;
    }
    return intval($p['stock']) < intval($p['threshold']) ? 1 : 0;
};

usort($activeProducts, fn($a, $b) =>
    [$statusRank($a), strtolower($a['name'])] <=> [$statusRank($b), strtolower($b['name'])]
);

// Stats
$totalActive   = count($activeProducts);
$totalArchived = count($archivedProducts);
$lowStock      = count(array_filter($activeProducts, fn($p) => $p['stock'] < $p['threshold'] && $p['stock'] > 0));
$outOfStock    = count(array_filter($activeProducts, fn($p) => $p['stock'] == 0));

// Categories
$categories = array_unique(array_filter(array_column($activeProducts, 'category')));
sort($categories);

// Dashboard link
$dashboardLink = $isAdmin ? 'dashboard_admin.php' : 'dashboard_inventory.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Product Monitoring - MAEXX</title>
<link rel="icon" type="image/png" href="img/LOGO.png">
<link href="assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
<link href="assets/vendor/bootstrap-icons/bootstrap-icons.min.css" rel="stylesheet">
<link href="assets/fonts/poppins/poppins.css" rel="stylesheet">
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Poppins', sans-serif; background: #f1f5f9; color: #1e293b; }

/* SIDEBAR */
.sidebar {
    width: 260px;
    background: linear-gradient(180deg, #243f5f 0%, #1a2f47 100%);
    min-height: 100vh;
    position: fixed;
    left: 0; top: 0;
    display: flex;
    flex-direction: column;
    z-index: 100;
    box-shadow: 4px 0 20px rgba(0,0,0,0.15);
}
.sidebar-brand {
    padding: 28px 20px 24px;
    text-align: center;
    border-bottom: 1px solid rgba(255,255,255,0.08);
}
.sidebar-brand img {
    width: 65px; height: 65px;
    border-radius: 50%;
    object-fit: cover;
    border: 3px solid rgba(255,255,255,0.2);
    margin-bottom: 12px;
}
.sidebar-brand-name { font-size: 12px; font-weight: 700; color: #fff; line-height: 1.5; }
.sidebar-brand-sub  { font-size: 10px; color: rgba(255,255,255,0.5); margin-top: 2px; }
.sidebar-nav { flex: 1; padding: 20px 12px; }
.nav-label {
    font-size: 9px; font-weight: 700; letter-spacing: 1.5px;
    color: rgba(255,255,255,0.35); text-transform: uppercase;
    padding: 0 10px; margin: 16px 0 6px;
}
.sidebar-nav a {
    display: flex; align-items: center; gap: 12px;
    padding: 11px 14px; color: rgba(255,255,255,0.65);
    text-decoration: none; font-size: 13px; font-weight: 500;
    border-radius: 12px; margin-bottom: 2px; transition: all 0.2s;
}
.sidebar-nav a i { font-size: 17px; width: 20px; text-align: center; }
.sidebar-nav a:hover { background: rgba(255,255,255,0.1); color: #fff; }
.sidebar-nav a.active { background: rgba(255,255,255,0.15); color: #fff; font-weight: 600; }
.sidebar-nav a .badge-count {
    margin-left: auto; background: #e11d48; color: #fff;
    font-size: 10px; font-weight: 700; padding: 2px 7px; border-radius: 999px;
}
.sidebar-footer { padding: 16px 12px; border-top: 1px solid rgba(255,255,255,0.08); }
.sidebar-footer a {
    display: flex; align-items: center; gap: 10px;
    padding: 11px 14px; color: rgba(255,255,255,0.6);
    text-decoration: none; font-size: 13px; border-radius: 12px; transition: all 0.2s;
}
.sidebar-footer a:hover { background: rgba(255,255,255,0.1); color: #fff; }

/* MAIN */
.main-wrapper { margin-left: 260px; min-height: 100vh; display: flex; flex-direction: column; }

/* TOPBAR */
.topbar {
    background: #fff; padding: 16px 28px;
    display: flex; justify-content: space-between; align-items: center;
    border-bottom: 1px solid #e2e8f0;
    position: sticky; top: 0; z-index: 50;
    box-shadow: 0 2px 8px rgba(0,0,0,0.04);
}
.topbar-left h4 { font-size: 18px; font-weight: 700; color: #1e293b; margin: 0; }
.topbar-left p  { font-size: 12px; color: #94a3b8; margin: 2px 0 0; }
.topbar-right   { display: flex; align-items: center; gap: 16px; }
.user-pill {
    display: flex; align-items: center; gap: 10px;
    background: #f8fafc; border: 1px solid #e2e8f0;
    border-radius: 999px; padding: 6px 14px 6px 8px;
    text-decoration: none; color: inherit; cursor: pointer;
    transition: background .2s, border-color .2s;
}
.user-pill:hover { background: #f1f5f9; border-color: #cbd5e1; }
.user-avatar {
    width: 30px; height: 30px; background: #243f5f; color: #fff;
    border-radius: 50%; display: flex; align-items: center;
    justify-content: center; font-size: 13px; font-weight: 700;
}
.user-info .name { font-size: 12px; font-weight: 600; color: #1e293b; }
.user-info .role { font-size: 10px; color: #94a3b8; }

/* PAGE */
.page-content { padding: 28px; }

/* STATS */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-bottom: 24px;
}
.stat-card {
    background: #fff;
    border-radius: 16px;
    padding: 18px 20px;
    border: 1px solid #e2e8f0;
    box-shadow: 0 2px 8px rgba(0,0,0,0.04);
    display: flex;
    align-items: center;
    gap: 14px;
}
.stat-icon {
    width: 44px; height: 44px;
    border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 20px; flex-shrink: 0;
}
.si-blue   { background: #eff6ff; color: #3b82f6; }
.si-green  { background: #f0fdf4; color: #22c55e; }
.si-yellow { background: #fefce8; color: #ca8a04; }
.si-red    { background: #fff1f2; color: #ef4444; }
.stat-info .value { font-size: 24px; font-weight: 700; color: #1e293b; line-height: 1; }
.stat-info .label { font-size: 11px; color: #64748b; margin-top: 3px; }

/* MAIN CARD */
.main-card {
    background: #fff;
    border-radius: 18px;
    border: 1px solid #e2e8f0;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    overflow: hidden;
    margin-bottom: 20px;
}

/* CARD TOOLBAR */
.card-toolbar {
    padding: 18px 22px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-bottom: 1px solid #f1f5f9;
    flex-wrap: wrap;
    gap: 12px;
}
.card-toolbar h5 { font-size: 15px; font-weight: 700; color: #1e293b; margin: 0; }
.card-toolbar p  { font-size: 11px; color: #94a3b8; margin: 2px 0 0; }

/* SEARCH BAR */
.search-bar {
    padding: 14px 22px;
    background: #f8fafc;
    border-bottom: 1px solid #f1f5f9;
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}
.search-wrap { position: relative; flex: 1; min-width: 200px; }
.search-wrap i { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 14px; }
.search-wrap input { padding-left: 36px; width: 100%; }
.form-control, .form-select {
    border-radius: 10px; font-size: 13px;
    padding: 9px 13px; border: 1.5px solid #e2e8f0;
    font-family: 'Poppins', sans-serif;
    transition: border-color .2s, box-shadow .2s;
}
.form-control:focus, .form-select:focus {
    border-color: #243f5f;
    box-shadow: 0 0 0 3px rgba(36,63,95,0.08);
    outline: none;
}

/* TABLE */
.table-responsive { overflow-x: auto; }
table { width: 100%; border-collapse: collapse; }
thead th {
    font-size: 10px; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.8px;
    color: #94a3b8; padding: 12px 16px;
    border-bottom: 2px solid #f1f5f9;
    white-space: nowrap; background: #f8fafc;
    text-align: left;
}
tbody td {
    font-size: 12px; color: #475569;
    padding: 13px 16px;
    border-bottom: 1px solid #f8fafc;
    vertical-align: middle;
}
tbody tr:hover { background: #f8fafc; }
tbody tr:last-child td { border-bottom: none; }

/* SKU */
.sku-chip {
    font-family: monospace;
    background: #f1f5f9; color: #475569;
    padding: 3px 8px; border-radius: 6px;
    font-size: 11px; font-weight: 700;
}

/* PRODUCT NAME */
.product-name { font-weight: 600; color: #1e293b; font-size: 13px; }
.product-desc { font-size: 10px; color: #94a3b8; margin-top: 2px; }

/* BADGES */
.badge-status {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 4px 10px; border-radius: 999px;
    font-size: 10px; font-weight: 700;
}
.bs-ok       { background: #dcfce7; color: #166534; }
.bs-low      { background: #fef9c3; color: #854d0e; }
.bs-out      { background: #fee2e2; color: #991b1b; }
.bs-archived { background: #f1f5f9; color: #64748b; }

/* ACTION BUTTONS */
.btn-act {
    width: 30px; height: 30px;
    border-radius: 8px; border: none;
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 13px; cursor: pointer; transition: all .15s;
}
.btn-view    { background: #f1f5f9; color: #243f5f; }
.btn-view:hover { background: #e2e8f0; }
.btn-edit    { background: #eff6ff; color: #2563eb; }
.btn-edit:hover { background: #dbeafe; }
.btn-archive { background: #fef9c3; color: #854d0e; }
.btn-archive:hover { background: #fef08a; }
.btn-restore { background: #dcfce7; color: #166534; }
.btn-restore:hover { background: #bbf7d0; }

/* PRIMARY BTN */
.btn-primary-custom {
    background: #243f5f; color: #fff;
    border: none; border-radius: 10px;
    padding: 9px 18px; font-size: 13px;
    font-weight: 600; cursor: pointer;
    display: inline-flex; align-items: center; gap: 6px;
    transition: all 0.2s; font-family: 'Poppins', sans-serif;
}
.btn-primary-custom:hover { background: #1a2f47; }

/* TABS */
.tab-bar {
    display: flex;
    gap: 4px;
    padding: 14px 22px 0;
    border-bottom: 2px solid #f1f5f9;
}
.tab-btn {
    padding: 9px 18px;
    font-size: 13px; font-weight: 600;
    color: #64748b; border: none;
    background: none; cursor: pointer;
    border-bottom: 2px solid transparent;
    margin-bottom: -2px;
    transition: all 0.2s;
    font-family: 'Poppins', sans-serif;
    display: flex; align-items: center; gap: 6px;
}
.tab-btn.active { color: #243f5f; border-bottom-color: #243f5f; }
.tab-btn:hover  { color: #243f5f; }
.tab-count {
    background: #f1f5f9; color: #64748b;
    padding: 2px 7px; border-radius: 999px;
    font-size: 10px; font-weight: 700;
}
.tab-btn.active .tab-count { background: #243f5f; color: #fff; }

/* EMPTY STATE */
.empty-state {
    text-align: center; padding: 60px 20px; color: #94a3b8;
}
.empty-state i { font-size: 48px; display: block; margin-bottom: 12px; }
.empty-state .title { font-size: 14px; font-weight: 600; color: #64748b; }
.empty-state .sub   { font-size: 12px; margin-top: 4px; }

/* MODAL */
.modal-content { border: none; border-radius: 20px; overflow: hidden; box-shadow: 0 20px 60px rgba(0,0,0,0.15); }
.modal-header  { background: linear-gradient(135deg, #243f5f, #1a2f47); color: #fff; padding: 20px 24px; border: none; }
.modal-header .btn-close { filter: brightness(0) invert(1); opacity: .8; }
.modal-title   { font-size: 16px; font-weight: 700; }
.modal-body    { padding: 24px; }
.modal-footer  { padding: 16px 24px; background: #f8fafc; border-top: 1px solid #f1f5f9; }
.form-label    { font-size: 12px; font-weight: 600; color: #475569; margin-bottom: 5px; }
.input-grid    { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
@media(max-width:576px){ .input-grid { grid-template-columns: 1fr; } }

/* ===== VIEW DETAILS MODAL ===== */
.detail-hero {
    display: flex;
    align-items: flex-start;
    gap: 14px;
    padding-bottom: 18px;
    margin-bottom: 18px;
    border-bottom: 1px solid #f1f5f9;
}
.detail-hero .icon {
    width: 52px; height: 52px;
    flex: 0 0 52px;
    border-radius: 14px;
    background: #f1f5f9;
    color: #243f5f;
    display: flex; align-items: center; justify-content: center;
    font-size: 22px;
}
.detail-hero .name {
    font-size: 17px; font-weight: 700; color: #0f172a;
    line-height: 1.35; margin-bottom: 7px;
}
.detail-hero .meta { display: flex; flex-wrap: wrap; gap: 7px; align-items: center; }

.detail-section { margin-bottom: 18px; }
.detail-section > .sec-label {
    font-size: 10.5px; font-weight: 700; letter-spacing: 1.1px;
    text-transform: uppercase; color: #94a3b8; margin-bottom: 9px;
}
.detail-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
@media(max-width:576px){ .detail-grid { grid-template-columns: 1fr; } }

.detail-item {
    background: #f8fafc;
    border: 1px solid #eef2f7;
    border-radius: 12px;
    padding: 11px 13px;
}
.detail-item .k { font-size: 11px; color: #94a3b8; margin-bottom: 3px; }
.detail-item .v { font-size: 14px; font-weight: 600; color: #0f172a; }
.detail-item .v small { font-weight: 500; color: #94a3b8; font-size: 11.5px; }
.detail-item.accent { background: #f0f7ff; border-color: #dbeafe; }
.detail-item.accent .v { color: #1d4ed8; }

.stock-meter { margin-top: 10px; }
.stock-meter .track {
    height: 7px; border-radius: 99px; background: #e2e8f0; overflow: hidden;
}
.stock-meter .fill { height: 100%; border-radius: 99px; transition: width .3s ease; }
.stock-meter .cap {
    display: flex; justify-content: space-between;
    font-size: 11px; color: #94a3b8; margin-top: 6px;
}

.detail-desc {
    background: #f8fafc;
    border: 1px solid #eef2f7;
    border-radius: 12px;
    padding: 12px 14px;
    font-size: 13px; line-height: 1.65; color: #475569;
}
.detail-desc.empty { color: #94a3b8; font-style: italic; }

/* ARCHIVE MODAL */
.archive-icon {
    width: 64px; height: 64px;
    background: #fef9c3; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    margin: 0 auto 16px; font-size: 28px; color: #ca8a04;
}

/* FLASH */
.flash-box {
    padding: 12px 18px; border-radius: 12px;
    margin-bottom: 20px; font-size: 13px;
    display: flex; align-items: center; gap: 10px;
}
.flash-success { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
.flash-danger  { background: #fff1f2; color: #991b1b; border: 1px solid #fecaca; }

/* PAGINATION */
.pg-wrap { padding: 14px 22px; display: flex; justify-content: space-between; align-items: center; border-top: 1px solid #f1f5f9; }
.pg-info  { font-size: 12px; color: #64748b; }
.pg-btns  { display: flex; gap: 4px; }
.pg-btn {
    width: 30px; height: 30px; border-radius: 8px;
    border: 1.5px solid #e2e8f0; background: #fff;
    color: #374151; font-size: 12px; font-weight: 600;
    cursor: pointer; display: inline-flex; align-items: center; justify-content: center;
    transition: all .15s; font-family: 'Poppins', sans-serif;
}
.pg-btn:hover, .pg-btn.active { background: #243f5f; color: #fff; border-color: #243f5f; }
.pg-btn:disabled { opacity: .4; cursor: not-allowed; }
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
        <a href="<?= $dashboardLink ?>"><i class="bi bi-grid-1x2-fill"></i> Dashboard</a>
        <a href="product_management.php" class="active"><i class="bi bi-box-seam-fill"></i> Product Monitoring</a>
        <a href="inventory.php">
            <i class="bi bi-boxes"></i> Inventory
            <?php if ($lowStock + $outOfStock > 0): ?>
                <span class="badge-count"><?= $lowStock + $outOfStock ?></span>
            <?php endif; ?>
        </a>
        <a href="sales.php"><i class="bi bi-cart-fill"></i> Sales</a>
        <a href="reports.php"><i class="bi bi-bar-chart-fill"></i> Reports</a>
        <?php if ($isAdmin): ?>
        <div class="nav-label">Management</div>
        <a href="user_management.php"><i class="bi bi-people-fill"></i> User Management</a>
        <?php endif; ?>    </nav>
    <div class="sidebar-footer">
        <a href="profile.php"><i class="bi bi-person-circle"></i> My Account</a>
        <a href="logout.php"><i class="bi bi-box-arrow-left"></i> Logout</a>
    </div>
</div>

<!-- MAIN -->
<div class="main-wrapper">

    <!-- TOPBAR -->
    <div class="topbar">
        <div class="topbar-left">
            <h4>Product Monitoring</h4>
            <p>Monitor all product records, categories, and stock thresholds</p>
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

        <!-- STATS -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon si-blue"><i class="bi bi-box-seam-fill"></i></div>
                <div class="stat-info">
                    <div class="value"><?= $totalActive ?></div>
                    <div class="label">Active Products</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon si-yellow"><i class="bi bi-exclamation-triangle-fill"></i></div>
                <div class="stat-info">
                    <div class="value"><?= $lowStock ?></div>
                    <div class="label">Low Stock Items</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon si-red"><i class="bi bi-x-circle-fill"></i></div>
                <div class="stat-info">
                    <div class="value"><?= $outOfStock ?></div>
                    <div class="label">Out of Stock</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon si-green"><i class="bi bi-archive-fill"></i></div>
                <div class="stat-info">
                    <div class="value"><?= $totalArchived ?></div>
                    <div class="label">Archived Products</div>
                </div>
            </div>
        </div>

        <!-- PRODUCT TABLE CARD -->
        <div class="main-card">

            <!-- TOOLBAR -->
            <div class="card-toolbar">
                <div>
                    <h5>Product List</h5>
                    <p>All products registered in the system</p>
                </div>
                <div class="toolbar-btns" style="display:flex;gap:8px;">
                    <button class="btn-print-table no-print" onclick="printTable('activeProductTable', 'PRODUCT MONITORING LIST', {stats:[{label:'Active Products',value:'<?= $totalActive ?>'},{label:'Low Stock',value:'<?= $lowStock ?>'},{label:'Out of Stock',value:'<?= $outOfStock ?>'},{label:'Archived',value:'<?= $totalArchived ?>'}]})">
                        <i class="bi bi-printer"></i> Print
                    </button>
                    <button class="btn-primary-custom" onclick="openAddModal()">
                        <i class="bi bi-plus-circle"></i> Add New Product
                    </button>
                </div>
            </div>

            <!-- TABS -->
            <div class="tab-bar">
                <button class="tab-btn active" onclick="switchTab('active', this)">
                    <i class="bi bi-box-seam"></i> Active Products
                    <span class="tab-count"><?= $totalActive ?></span>
                </button>
                <button class="tab-btn" onclick="switchTab('archived', this)">
                    <i class="bi bi-archive"></i> Archived
                    <span class="tab-count"><?= $totalArchived ?></span>
                </button>
            </div>

            <!-- SEARCH BAR -->
            <div class="search-bar">
                <div class="search-wrap">
                    <i class="bi bi-search"></i>
                    <input type="text" class="form-control" id="searchInput" placeholder="Search product name or SKU...">
                </div>
                <select class="form-select" id="filterCategory" style="width:160px; flex:none;">
                    <option value="">All Categories</option>
                    <?php foreach ($categories as $cat): ?>
                    <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option>
                    <?php endforeach; ?>
                </select>
                <select class="form-select" id="filterStatus" style="width:140px; flex:none;">
                    <option value="">All Status</option>
                    <option value="in-stock">In Stock</option>
                    <option value="low-stock">Low Stock</option>
                    <option value="out">Out of Stock</option>
                </select>
                <button onclick="clearFilters()" style="border:1.5px solid #e2e8f0; background:#fff; border-radius:10px; padding:9px 14px; cursor:pointer; font-size:13px; color:#64748b;">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>

            <!-- ACTIVE PRODUCTS TAB -->
            <div id="tab-active">
                <div class="table-responsive">
                    <table id="activeProductTable">
                        <thead>
                            <tr>
                                <th>SKU</th>
                                <th>Product Name</th>
                                <th>Category</th>
                                <th>Unit</th>
                                <th>Min Stock Level</th>
                                <th>Current Stock</th>
                                <th>Status</th>
                                <th style="text-align:center;">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="activeTableBody">
                        <?php foreach ($activeProducts as $p):
                            if ($p['stock'] == 0) {
                                $statusLabel = 'Out of Stock'; $statusClass = 'bs-out'; $statusKey = 'out';
                            } elseif ($p['stock'] < $p['threshold']) {
                                $statusLabel = 'Low Stock'; $statusClass = 'bs-low'; $statusKey = 'low-stock';
                            } else {
                                $statusLabel = 'In Stock'; $statusClass = 'bs-ok'; $statusKey = 'in-stock';
                            }
                            $sku = 'SKU-' . str_pad($p['id'], 4, '0', STR_PAD_LEFT);
                        ?>
                        <tr
                            data-name="<?= strtolower(htmlspecialchars($p['name'])) ?>"
                            data-sku="<?= strtolower($sku) ?>"
                            data-category="<?= strtolower(htmlspecialchars($p['category'] ?? '')) ?>"
                            data-status="<?= $statusKey ?>">
                            <td><span class="sku-chip"><?= $sku ?></span></td>
                            <td>
                                <div class="product-name"><?= htmlspecialchars($p['name']) ?></div>
                                <?php if (!empty($p['description'])): ?>
                                <div class="product-desc"><?= htmlspecialchars(substr($p['description'], 0, 50)) ?>...</div>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($p['category'] ?? 'General') ?></td>
                            <td><?= htmlspecialchars($p['unit'] ?? 'pcs') ?></td>
                            <td style="font-weight:600; color:#243f5f;"><?= intval($p['threshold']) ?> <?= htmlspecialchars($p['unit'] ?? 'pcs') ?></td>
                            <td style="font-weight:700; color:<?= $p['stock'] < $p['threshold'] ? '#ef4444' : '#22c55e' ?>;">
                                <?= intval($p['stock']) ?> <?= htmlspecialchars($p['unit'] ?? 'pcs') ?>
                            </td>
                            <td><span class="badge-status <?= $statusClass ?>"><?= $statusLabel ?></span></td>
                            <td style="text-align:center;">
                                <?php
                                    $viewData = $p + [
                                        'sku'       => $sku,
                                        'status'    => $statusLabel,
                                        'statusKey' => $statusKey,
                                        'reserved'  => get_reserved_stock($p['id']),
                                        'available' => get_available_stock($p['id']),
                                    ];
                                ?>
                                <button class="btn-act btn-view me-1" title="View full details"
                                    onclick="openViewModal(<?= htmlspecialchars(json_encode($viewData), ENT_QUOTES) ?>)">
                                    <i class="bi bi-eye"></i>
                                </button>
                                <button class="btn-act btn-edit me-1" title="Edit product"
                                    onclick="openEditModal(<?= htmlspecialchars(json_encode($p), ENT_QUOTES) ?>)">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <button class="btn-act btn-archive" title="Archive product"
                                    onclick="openArchiveModal(<?= $p['id'] ?>, '<?= htmlspecialchars($p['name'], ENT_QUOTES) ?>')">
                                    <i class="bi bi-archive"></i>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div id="activeEmpty" class="empty-state" style="display:none;">
                    <i class="bi bi-box-seam"></i>
                    <div class="title">No products found</div>
                    <div class="sub">Try adjusting your search or filter</div>
                </div>
                <div class="pg-wrap">
                    <div class="pg-info" id="pgInfo">Showing all products</div>
                    <div class="pg-btns" id="pgBtns"></div>
                </div>
            </div>

            <!-- ARCHIVED PRODUCTS TAB -->
            <div id="tab-archived" style="display:none;">
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>SKU</th>
                                <th>Product Name</th>
                                <th>Category</th>
                                <th>Unit</th>
                                <th>Min Stock Level</th>
                                <th style="text-align:center;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($archivedProducts)): ?>
                        <tr><td colspan="6" style="text-align:center; padding:40px; color:#94a3b8;">No archived products.</td></tr>
                        <?php else: ?>
                        <?php foreach ($archivedProducts as $p):
                            $sku = 'SKU-' . str_pad($p['id'], 4, '0', STR_PAD_LEFT);
                        ?>
                        <tr>
                            <td><span class="sku-chip"><?= $sku ?></span></td>
                            <td>
                                <div class="product-name" style="color:#94a3b8;"><?= htmlspecialchars($p['name']) ?></div>
                            </td>
                            <td><?= htmlspecialchars($p['category'] ?? 'General') ?></td>
                            <td><?= htmlspecialchars($p['unit'] ?? 'pcs') ?></td>
                            <td><?= intval($p['threshold']) ?></td>
                            <td style="text-align:center;">
                                <form method="POST" style="display:inline;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="restore">
                                    <input type="hidden" name="product_id" value="<?= $p['id'] ?>">
                                    <button type="submit" class="btn-act btn-restore" title="Restore product">
                                        <i class="bi bi-arrow-counterclockwise"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div><!-- end main-card -->
    </div><!-- end page-content -->
</div><!-- end main-wrapper -->

<!-- VIEW DETAILS MODAL -->
<div class="modal fade" id="viewModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable" style="max-width:540px;">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-eye me-2"></i>Product Details</h5>
                <button class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body">

                <!-- NAME / SKU / STATUS -->
                <div class="detail-hero">
                    <div class="icon"><i class="bi bi-box-seam-fill"></i></div>
                    <div style="min-width:0;">
                        <div class="name" id="vName">—</div>
                        <div class="meta">
                            <span class="sku-chip" id="vSku">—</span>
                            <span class="badge-status" id="vStatus">—</span>
                        </div>
                    </div>
                </div>

                <!-- CLASSIFICATION -->
                <div class="detail-section">
                    <div class="sec-label">Classification</div>
                    <div class="detail-grid">
                        <div class="detail-item">
                            <div class="k">Category</div>
                            <div class="v" id="vCategory">—</div>
                        </div>
                        <div class="detail-item">
                            <div class="k">Unit of Measure</div>
                            <div class="v" id="vUnit">—</div>
                        </div>
                    </div>
                </div>

                <!-- STOCK -->
                <div class="detail-section">
                    <div class="sec-label">Stock Levels</div>
                    <div class="detail-grid">
                        <div class="detail-item">
                            <div class="k">Current Stock</div>
                            <div class="v" id="vStock">—</div>
                        </div>
                        <div class="detail-item">
                            <div class="k">Minimum Stock Level</div>
                            <div class="v" id="vThreshold">—</div>
                        </div>
                        <div class="detail-item">
                            <div class="k">Reserved <small>(pending orders)</small></div>
                            <div class="v" id="vReserved">—</div>
                        </div>
                        <div class="detail-item accent">
                            <div class="k">Available to Sell</div>
                            <div class="v" id="vAvailable">—</div>
                        </div>
                    </div>

                    <div class="stock-meter">
                        <div class="track"><div class="fill" id="vMeter" style="width:0;"></div></div>
                        <div class="cap">
                            <span id="vMeterNote">—</span>
                            <span id="vMeterPct">—</span>
                        </div>
                    </div>
                </div>

                <!-- PRICING -->
                <div class="detail-section">
                    <div class="sec-label">Pricing</div>
                    <div class="detail-grid">
                        <div class="detail-item">
                            <div class="k">Cost Price</div>
                            <div class="v" id="vCost">—</div>
                        </div>
                        <div class="detail-item">
                            <div class="k">Selling Price</div>
                            <div class="v" id="vPrice">—</div>
                        </div>
                        <div class="detail-item">
                            <div class="k">Profit per Unit</div>
                            <div class="v" id="vMargin">—</div>
                        </div>
                        <div class="detail-item">
                            <div class="k">Stock Value <small>(at cost)</small></div>
                            <div class="v" id="vValue">—</div>
                        </div>
                    </div>
                </div>

                <!-- DESCRIPTION -->
                <div class="detail-section" style="margin-bottom:0;">
                    <div class="sec-label">Description</div>
                    <div class="detail-desc" id="vDescription">—</div>
                </div>

            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-light fw-semibold" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn-primary-custom" id="vEditBtn">
                    <i class="bi bi-pencil-square"></i>
                    <span>Edit Product</span>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ADD / EDIT MODAL -->
<div class="modal fade" id="productModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered" style="max-width:540px;">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalTitle"><i class="bi bi-plus-circle me-2"></i>Add New Product</h5>
                <button class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="productForm">
                <?= csrf_field() ?>
                <div class="modal-body">
                    <input type="hidden" name="action" id="formAction" value="create">
                    <input type="hidden" name="product_id" id="formProductId">

                    <!-- Product Name -->
                    <div class="mb-3">
                        <label class="form-label">Product Name <span style="color:#ef4444;">*</span></label>
                        <input type="text" class="form-control" name="name" id="formName"
                               placeholder="e.g. Portland Cement 40kg" required minlength="2" maxlength="<?= V_NAME_MAX ?>">
                    </div>

                    <!-- Category & Unit -->
                    <div class="input-grid mb-3">
                        <div>
                            <label class="form-label">Product Category <span style="color:#ef4444;">*</span></label>
                            <input type="text" class="form-control" name="category" id="formCategory"
                                   placeholder="e.g. Cement" required minlength="2" maxlength="<?= V_CATEGORY_MAX ?>"
                                   list="categoryOptions">
                            <datalist id="categoryOptions">
                                <?php foreach ($categories as $cat): ?>
                                <option value="<?= htmlspecialchars($cat) ?>">
                                <?php endforeach; ?>
                            </datalist>
                        </div>
                        <div>
                            <label class="form-label">Unit <span style="color:#ef4444;">*</span></label>
                            <input type="text" class="form-control" name="unit" id="formUnit"
                                   placeholder="e.g. Bag, Pcs, Kg" required maxlength="<?= V_UNIT_MAX ?>"
                                   pattern="[A-Za-z .\/\-]+" title="Letters only, e.g. Pcs, Kg, Bag, Roll">
                        </div>
                    </div>

                    <!-- Description -->
                    <div class="mb-3">
                        <label class="form-label">Product Description</label>
                        <textarea class="form-control" name="description" id="formDescription"
                                  rows="2" maxlength="<?= V_DESCRIPTION_MAX ?>" placeholder="Brief description of the product..."></textarea>
                    </div>

                    <!-- Min Stock Level -->
                    <div class="mb-3">
                        <label class="form-label">Minimum Stock Level <span style="color:#ef4444;">*</span></label>
                        <input type="number" class="form-control" name="threshold" id="formThreshold"
                               placeholder="e.g. 10" min="1" max="<?= V_QTY_MAX ?>" step="1" value="5" required>
                        <div style="font-size:11px; color:#94a3b8; margin-top:4px;">Alert triggers when stock falls below this level</div>
                    </div>

                    <!-- Cost & Price (optional) -->
                    <div class="input-grid">
                        <div>
                            <label class="form-label">Cost Price (₱)</label>
                            <input type="number" class="form-control" name="cost" id="formCost"
                                   placeholder="0.00" min="0" max="<?= V_MONEY_MAX ?>" step="0.01" value="0">
                        </div>
                        <div>
                            <label class="form-label">Selling Price (₱)</label>
                            <input type="number" class="form-control" name="price" id="formPrice"
                                   placeholder="0.00" min="0" max="<?= V_MONEY_MAX ?>" step="0.01" value="0">
                        </div>
                    </div>

                    <!-- Selling below cost -->
                    <div id="lossWarning" style="display:none; margin-top:12px; background:#fffbeb; border:1px solid #fde68a; border-radius:12px; padding:11px 13px; font-size:12.5px; color:#92400e; line-height:1.55;">
                        <div><i class="bi bi-exclamation-triangle-fill me-1"></i><strong>Selling below cost.</strong> <span id="lossText"></span></div>
                        <label style="display:flex; gap:8px; align-items:flex-start; margin-top:8px; cursor:pointer;">
                            <input type="checkbox" name="confirm_loss" value="1" id="confirmLoss" style="margin-top:3px;">
                            <span>I understand this product will be sold at a loss.</span>
                        </label>
                    </div>
                    <div id="noPriceNote" style="display:none; margin-top:10px; font-size:11.5px; color:#94a3b8;">
                        <i class="bi bi-info-circle me-1"></i>Without a selling price this product cannot be ordered in Sales.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light fw-semibold" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn-primary-custom" id="productSubmit">
                        <i class="bi bi-check-circle"></i>
                        <span id="submitLabel">Add Product</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ARCHIVE CONFIRM MODAL -->
<div class="modal fade" id="archiveModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered" style="max-width:400px;">
        <div class="modal-content">
            <div class="modal-body text-center py-4 px-4">
                <div class="archive-icon"><i class="bi bi-archive-fill"></i></div>
                <h5 style="font-weight:700; margin-bottom:8px;">Archive Product?</h5>
                <p style="color:#64748b; font-size:13px; margin-bottom:24px;">
                    You are about to archive <strong id="archiveProductName"></strong>.
                    The product will be hidden from active lists but can be restored anytime.
                </p>
                <form method="POST" id="archiveForm">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="archive">
                    <input type="hidden" name="product_id" id="archiveProductId">
                    <div style="display:flex; gap:10px; justify-content:center;">
                        <button type="button" class="btn btn-light fw-semibold px-4" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" style="background:#ca8a04; color:#fff; border:none; border-radius:10px; padding:9px 20px; font-weight:600; cursor:pointer; font-family:'Poppins',sans-serif;">
                            <i class="bi bi-archive me-1"></i> Yes, Archive
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script>
/* ===== TABS ===== */
function switchTab(tab, btn) {
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById('tab-active').style.display   = tab === 'active'   ? '' : 'none';
    document.getElementById('tab-archived').style.display = tab === 'archived' ? '' : 'none';
    document.querySelector('.search-bar').style.display   = tab === 'active'   ? '' : 'none';
}

/* ===== MODAL HELPERS ===== */
function openAddModal() {
    document.getElementById('modalTitle').innerHTML = '<i class="bi bi-plus-circle me-2"></i>Add New Product';
    document.getElementById('submitLabel').textContent = 'Add Product';
    document.getElementById('formAction').value = 'create';
    document.getElementById('formProductId').value = '';
    document.getElementById('productForm').reset();
    document.getElementById('formThreshold').value = '5';
    new bootstrap.Modal(document.getElementById('productModal')).show();
}

/* ===== PRICE CHECK (mirrors validate_product_input) ===== */
function checkPricing() {
    const cost    = parseFloat(document.getElementById('formCost').value)  || 0;
    const price   = parseFloat(document.getElementById('formPrice').value) || 0;
    const warning = document.getElementById('lossWarning');
    const confirm = document.getElementById('confirmLoss');
    const submit  = document.getElementById('productSubmit');
    const losing  = price > 0 && price < cost;

    warning.style.display = losing ? '' : 'none';
    document.getElementById('noPriceNote').style.display = price <= 0 ? '' : 'none';

    if (losing) {
        const fmt = v => '₱' + v.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        document.getElementById('lossText').textContent =
            `Selling at ${fmt(price)} against a cost of ${fmt(cost)} loses ${fmt(cost - price)} on every unit.`;
    } else {
        confirm.checked = false;
    }

    const blocked = losing && !confirm.checked;
    submit.disabled = blocked;
    submit.style.opacity = blocked ? '.5' : '';
    submit.style.cursor  = blocked ? 'not-allowed' : '';
}

['formCost', 'formPrice'].forEach(id => document.getElementById(id)?.addEventListener('input', checkPricing));
document.getElementById('confirmLoss')?.addEventListener('change', checkPricing);
document.getElementById('productModal')?.addEventListener('shown.bs.modal', checkPricing);

/* ===== VIEW DETAILS ===== */
const peso = v => '₱' + Number(v || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

function openViewModal(p) {
    const unit   = p.unit || 'pcs';
    const stock  = Number(p.stock ?? 0);
    const min    = Number(p.threshold ?? 0);
    const cost   = Number(p.cost ?? 0);
    const price  = Number(p.price ?? 0);
    const set    = (id, text) => document.getElementById(id).textContent = text;

    set('vName', p.name || '—');
    set('vSku', p.sku || '—');
    set('vCategory', p.category || 'General');
    set('vUnit', unit);

    /* Status badge reuses the same colours as the table. */
    const badge = document.getElementById('vStatus');
    badge.textContent = p.status || '—';
    badge.className = 'badge-status ' +
        (p.statusKey === 'out' ? 'bs-out' : p.statusKey === 'low-stock' ? 'bs-low' : 'bs-ok');

    set('vStock', `${stock} ${unit}`);
    set('vThreshold', `${min} ${unit}`);
    set('vReserved', `${Number(p.reserved ?? 0)} ${unit}`);
    set('vAvailable', `${Number(p.available ?? stock)} ${unit}`);

    /* Meter shows current stock against the minimum level. Full bar
       means the product is at or above twice its minimum. */
    const ceiling = Math.max(min * 2, stock, 1);
    const pct     = Math.min(100, Math.round((stock / ceiling) * 100));
    const meter   = document.getElementById('vMeter');

    meter.style.width = pct + '%';
    meter.style.background =
        stock === 0 ? '#ef4444' : stock < min ? '#f59e0b' : '#22c55e';

    set('vMeterPct', pct + '%');
    set('vMeterNote',
        stock === 0      ? 'Out of stock — reorder required'
      : stock < min      ? `Below minimum by ${min - stock} ${unit}`
                         : `${stock - min} ${unit} above minimum`);

    set('vCost', peso(cost));
    set('vPrice', peso(price));

    const margin = price - cost;
    const pctStr = cost > 0 ? ` (${Math.round((margin / cost) * 100)}%)` : '';
    set('vMargin', price > 0 ? peso(margin) + pctStr : '—');
    set('vValue', peso(stock * cost));

    const desc = document.getElementById('vDescription');
    desc.textContent = p.description || 'No description provided for this product.';
    desc.classList.toggle('empty', !p.description);

    /* Jump straight from viewing to editing. */
    const viewModal = new bootstrap.Modal(document.getElementById('viewModal'));
    document.getElementById('vEditBtn').onclick = () => {
        viewModal.hide();
        setTimeout(() => openEditModal(p), 320);
    };

    viewModal.show();
}

function openEditModal(p) {
    document.getElementById('modalTitle').innerHTML = '<i class="bi bi-pencil-square me-2"></i>Edit Product';
    document.getElementById('submitLabel').textContent = 'Save Changes';
    document.getElementById('formAction').value = 'update';
    document.getElementById('formProductId').value = p.id;
    document.getElementById('formName').value        = p.name        ?? '';
    document.getElementById('formCategory').value    = p.category    ?? '';
    document.getElementById('formUnit').value        = p.unit        ?? '';
    document.getElementById('formDescription').value = p.description ?? '';
    document.getElementById('formThreshold').value   = p.threshold   ?? 5;
    document.getElementById('formCost').value        = p.cost        ?? 0;
    document.getElementById('formPrice').value       = p.price       ?? 0;
    new bootstrap.Modal(document.getElementById('productModal')).show();
}

function openArchiveModal(id, name) {
    document.getElementById('archiveProductId').value = id;
    document.getElementById('archiveProductName').textContent = name;
    new bootstrap.Modal(document.getElementById('archiveModal')).show();
}

/* ===== SEARCH & FILTER ===== */
const ROWS_PER_PAGE = 10;
let currentPage = 1;
let filteredRows = [];

function getRows() { return Array.from(document.querySelectorAll('#activeTableBody tr')); }

function applyFilters() {
    const q   = document.getElementById('searchInput').value.toLowerCase().trim();
    const cat = document.getElementById('filterCategory').value.toLowerCase();
    const st  = document.getElementById('filterStatus').value;
    const rows = getRows();

    filteredRows = rows.filter(row => {
        const matchQ   = !q   || row.dataset.name.includes(q) || row.dataset.sku.includes(q);
        const matchCat = !cat || row.dataset.category === cat;
        const matchSt  = !st  || row.dataset.status === st;
        return matchQ && matchCat && matchSt;
    });

    currentPage = 1;
    renderPage();
}

function renderPage() {
    const rows  = getRows();
    rows.forEach(r => r.style.display = 'none');

    const start    = (currentPage - 1) * ROWS_PER_PAGE;
    const end      = start + ROWS_PER_PAGE;
    const pageRows = filteredRows.slice(start, end);
    pageRows.forEach(r => r.style.display = '');

    const emptyEl = document.getElementById('activeEmpty');
    emptyEl.style.display = filteredRows.length === 0 ? '' : 'none';

    const total = filteredRows.length;
    const from  = total === 0 ? 0 : start + 1;
    const to    = Math.min(end, total);
    document.getElementById('pgInfo').textContent =
        total === 0 ? 'No products found' : `Showing ${from}–${to} of ${total} product${total !== 1 ? 's' : ''}`;

    const totalPages = Math.ceil(total / ROWS_PER_PAGE);
    const container  = document.getElementById('pgBtns');
    container.innerHTML = '';
    if (totalPages <= 1) return;

    const prev = makePgBtn('<i class="bi bi-chevron-left"></i>', currentPage > 1, () => { currentPage--; renderPage(); });
    container.appendChild(prev);
    for (let p = 1; p <= totalPages; p++) {
        const btn = makePgBtn(p, true, () => { currentPage = p; renderPage(); });
        if (p === currentPage) btn.classList.add('active');
        container.appendChild(btn);
    }
    const next = makePgBtn('<i class="bi bi-chevron-right"></i>', currentPage < totalPages, () => { currentPage++; renderPage(); });
    container.appendChild(next);
}

function makePgBtn(label, enabled, onclick) {
    const btn = document.createElement('button');
    btn.className = 'pg-btn';
    btn.innerHTML = label;
    btn.disabled  = !enabled;
    btn.addEventListener('click', onclick);
    return btn;
}

function clearFilters() {
    document.getElementById('searchInput').value    = '';
    document.getElementById('filterCategory').value = '';
    document.getElementById('filterStatus').value   = '';
    applyFilters();
}

document.getElementById('searchInput').addEventListener('input', applyFilters);
document.getElementById('filterCategory').addEventListener('change', applyFilters);
document.getElementById('filterStatus').addEventListener('change', applyFilters);
document.addEventListener('DOMContentLoaded', applyFilters);
</script>
<script><?= maexx_print_js() ?></script>
<script><?= maexx_notif_js() ?></script>
</body>
</html>