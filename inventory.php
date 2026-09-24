<?php
require 'auth.php';
require 'print_template.php';
require 'notifications.php';
require_login();

$user    = get_logged_in_user();
$isAdmin = is_admin_role($user['role']);

/* ===== HANDLE POST ===== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf('inventory.php');

    $action   = $_POST['action'] ?? '';
    $supplier = trim($_POST['supplier'] ?? '');
    $remarks  = trim($_POST['remarks'] ?? '');

    if ($action === 'stock_in' || $action === 'stock_out') {
        $isIn = $action === 'stock_in';
        $v    = validate_stock_movement($_POST, $isIn);

        // Stock Out also has to fit what is on hand (see validate_stock_out).
        if ($v['ok'] && !$isIn) {
            $check = validate_stock_out(intval($v['product']['id']), $v['quantity'], !empty($_POST['confirm_low']));
            if (!$check['ok']) {
                $v = $check;
            }
        }

        if (!$v['ok']) {
            set_flash($v['message'], 'danger');
        } else {
            $result = adjust_stock(intval($v['product']['id']), $v['quantity'], $isIn, $v['date'], $isIn ? $supplier : '', $remarks);
            set_flash($result['success']
                    ? ($isIn ? 'Stock IN' : 'Stock OUT') . " recorded: {$v['quantity']} " . ($v['product']['unit'] ?? 'pcs') . " of {$v['product']['name']}."
                    : $result['message'],
                $result['success'] ? 'success' : 'danger');
            if ($result['success']) {
                header('Location: inventory.php?highlight=' . urlencode($v['product']['name'])); exit;
            }
        }
    }

    header('Location: inventory.php'); exit;
}

$flash    = get_flash();
$products = load_products();
$activeProducts = array_values(array_filter($products, fn($p) => empty($p['archived'])));

// Order by status: In Stock first, then Low Stock, then Out of Stock.
$statusRank = function (array $p): int {
    if (intval($p['stock']) == 0) {
        return 2;
    }
    return intval($p['stock']) < intval($p['threshold']) ? 1 : 0;
};

usort($activeProducts, fn($a, $b) =>
    [$statusRank($a), strtolower($a['name'])] <=> [$statusRank($b), strtolower($b['name'])]
);

// Stock stats
$totalProducts = count($activeProducts);
$lowStock      = array_values(array_filter($activeProducts, fn($p) => $p['stock'] < $p['threshold'] && $p['stock'] > 0));
$outOfStock    = array_values(array_filter($activeProducts, fn($p) => $p['stock'] == 0));
$inStock       = array_values(array_filter($activeProducts, fn($p) => $p['stock'] >= $p['threshold']));

// Load stock movement logs
$logs = [];
if (function_exists('load_stock_logs')) {
    $logs = load_stock_logs();
} elseif (file_exists('data/stock_logs.json')) {
    $logs = json_decode(file_get_contents('data/stock_logs.json'), true) ?: [];
}
$recentLogs = array_slice(array_reverse($logs), 0, 50);

$dashboardLink = $isAdmin ? 'dashboard_admin.php' : 'dashboard_inventory.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Inventory - MAEXX</title>
<link rel="icon" type="image/png" href="img/LOGO.png">
<link href="assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
<link href="assets/vendor/bootstrap-icons/bootstrap-icons.min.css" rel="stylesheet">
<link href="assets/fonts/poppins/poppins.css" rel="stylesheet">
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Poppins', sans-serif; background: #f1f5f9; color: #1e293b; }

.sidebar {
    width: 260px; background: linear-gradient(180deg,#243f5f,#1a2f47);
    min-height: 100vh; position: fixed; left:0; top:0;
    display:flex; flex-direction:column; z-index:100;
    box-shadow:4px 0 20px rgba(0,0,0,.15);
}
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
.si-yellow { background:#fefce8;color:#ca8a04; }
.si-red    { background:#fff1f2;color:#ef4444; }
.stat-info .value { font-size:24px;font-weight:700;color:#1e293b;line-height:1; }
.stat-info .label { font-size:11px;color:#64748b;margin-top:3px; }

/* TABS */
.tab-bar { display:flex;gap:4px;padding:14px 22px 0;border-bottom:2px solid #f1f5f9; }
.tab-btn { padding:9px 18px;font-size:13px;font-weight:600;color:#64748b;border:none;background:none;cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-2px;transition:all .2s;font-family:'Poppins',sans-serif;display:flex;align-items:center;gap:6px; }
.tab-btn.active { color:#243f5f;border-bottom-color:#243f5f; }
.tab-btn:hover  { color:#243f5f; }
.tab-count { background:#f1f5f9;color:#64748b;padding:2px 7px;border-radius:999px;font-size:10px;font-weight:700; }
.tab-btn.active .tab-count { background:#243f5f;color:#fff; }

/* MAIN CARD */
.main-card { background:#fff;border-radius:18px;border:1px solid #e2e8f0;box-shadow:0 2px 10px rgba(0,0,0,.05);overflow:hidden;margin-bottom:20px; }
.card-toolbar { padding:18px 22px;display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid #f1f5f9;flex-wrap:wrap;gap:12px; }
.card-toolbar h5 { font-size:15px;font-weight:700;color:#1e293b;margin:0; }
.card-toolbar p  { font-size:11px;color:#94a3b8;margin:2px 0 0; }
.toolbar-btns { display:flex;gap:8px; }

/* SEARCH BAR */
.search-bar { padding:14px 22px;background:#f8fafc;border-bottom:1px solid #f1f5f9;display:flex;gap:10px;flex-wrap:wrap; }
.search-wrap { position:relative;flex:1;min-width:200px; }
.search-wrap i { position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:14px; }
.search-wrap input { padding-left:36px;width:100%; }
.form-control,.form-select { border-radius:10px;font-size:13px;padding:9px 13px;border:1.5px solid #e2e8f0;font-family:'Poppins',sans-serif;transition:border-color .2s; }
.form-control:focus,.form-select:focus { border-color:#243f5f;box-shadow:0 0 0 3px rgba(36,63,95,.08);outline:none; }

/* TABLE */
.table-responsive { overflow-x:auto; }
table { width:100%;border-collapse:collapse; }
thead th { font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:#94a3b8;padding:12px 16px;border-bottom:2px solid #f1f5f9;white-space:nowrap;background:#f8fafc;text-align:left; }
tbody td { font-size:12px;color:#475569;padding:13px 16px;border-bottom:1px solid #f8fafc;vertical-align:middle; }
tbody tr:hover { background:#f8fafc; }
tbody tr:last-child td { border-bottom:none; }

.product-name { font-weight:600;color:#1e293b;font-size:13px; }
.product-cat  { font-size:10px;color:#94a3b8;margin-top:2px; }
.sku-chip { font-family:monospace;background:#f1f5f9;color:#475569;padding:3px 8px;border-radius:6px;font-size:11px;font-weight:700; }

/* BADGES */
.badge-status { display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:999px;font-size:10px;font-weight:700; }
.bs-ok     { background:#dcfce7;color:#166534; }
.bs-low    { background:#fef9c3;color:#854d0e; }
.bs-out    { background:#fee2e2;color:#991b1b; }
.bs-in     { background:#dbeafe;color:#1d4ed8; }
.bs-log-in  { background:#dcfce7;color:#166534; }
.bs-log-out { background:#fee2e2;color:#991b1b; }

/* STOCK LEVEL BAR */
.stock-bar { height:6px;border-radius:999px;background:#f1f5f9;margin-top:5px;overflow:hidden; }
.stock-fill { height:100%;border-radius:999px; }
.fill-ok  { background:#22c55e; }
.fill-low { background:#eab308; }
.fill-out { background:#ef4444; }

/* BUTTONS */
.btn-primary-custom { background:#243f5f;color:#fff;border:none;border-radius:10px;padding:9px 18px;font-size:13px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:all .2s;font-family:'Poppins',sans-serif; }
.btn-primary-custom:hover { background:#1a2f47; }
.btn-success-custom { background:#16a34a;color:#fff;border:none;border-radius:10px;padding:9px 18px;font-size:13px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:all .2s;font-family:'Poppins',sans-serif; }
.btn-success-custom:hover { background:#15803d; }
.btn-danger-custom  { background:#dc2626;color:#fff;border:none;border-radius:10px;padding:9px 18px;font-size:13px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:all .2s;font-family:'Poppins',sans-serif; }
.btn-danger-custom:hover { background:#b91c1c; }

/* MODAL */
.modal-content { border:none;border-radius:20px;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,.15); }
.modal-header  { padding:20px 24px;border:none; }
.modal-title   { font-size:16px;font-weight:700; }
.modal-body    { padding:24px; }
.modal-footer  { padding:16px 24px;background:#f8fafc;border-top:1px solid #f1f5f9; }
.form-label    { font-size:12px;font-weight:600;color:#475569;margin-bottom:5px; }
.header-in  { background:linear-gradient(135deg,#16a34a,#15803d);color:#fff; }
.header-in .btn-close { filter:brightness(0) invert(1); }
.header-out { background:linear-gradient(135deg,#dc2626,#b91c1c);color:#fff; }
.header-out .btn-close { filter:brightness(0) invert(1); }

/* FLASH */
.flash-box { padding:12px 18px;border-radius:12px;margin-bottom:20px;font-size:13px;display:flex;align-items:center;gap:10px; }
.flash-success { background:#f0fdf4;color:#166534;border:1px solid #bbf7d0; }
.flash-danger  { background:#fff1f2;color:#991b1b;border:1px solid #fecaca; }

/* ALERT CARD */

/* LOG TABLE */
.log-badge-in  { background:#dcfce7;color:#166534;padding:3px 8px;border-radius:6px;font-size:10px;font-weight:700; }
.log-badge-out { background:#fee2e2;color:#991b1b;padding:3px 8px;border-radius:6px;font-size:10px;font-weight:700; }

.empty-state { text-align:center;padding:60px 20px;color:#94a3b8; }
.empty-state i { font-size:48px;display:block;margin-bottom:12px; }
.empty-state .title { font-size:14px;font-weight:600;color:#64748b; }
.empty-state .sub   { font-size:12px;margin-top:4px; }

.pg-wrap { padding:14px 22px;display:flex;justify-content:space-between;align-items:center;border-top:1px solid #f1f5f9; }
.pg-info { font-size:12px;color:#64748b; }
.pg-btns { display:flex;gap:4px; }
.pg-btn { width:30px;height:30px;border-radius:8px;border:1.5px solid #e2e8f0;background:#fff;color:#374151;font-size:12px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;transition:all .15s;font-family:'Poppins',sans-serif; }
.pg-btn:hover,.pg-btn.active { background:#243f5f;color:#fff;border-color:#243f5f; }
.pg-btn:disabled { opacity:.4;cursor:not-allowed; }
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
        <a href="product_management.php"><i class="bi bi-box-seam-fill"></i> Product Monitoring</a>
        <a href="inventory.php" class="active">
            <i class="bi bi-boxes"></i> Inventory
            <?php if (count($lowStock) + count($outOfStock) > 0): ?>
                <span class="badge-count"><?= count($lowStock) + count($outOfStock) ?></span>
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
    <div class="topbar">
        <div class="topbar-left">
            <h4>Inventory Monitoring</h4>
            <p>Monitor stock levels, record stock in/out, and track movement</p>
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
                <div class="stat-icon si-blue"><i class="bi bi-boxes"></i></div>
                <div class="stat-info">
                    <div class="value"><?= $totalProducts ?></div>
                    <div class="label">Total Products</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon si-green"><i class="bi bi-check-circle-fill"></i></div>
                <div class="stat-info">
                    <div class="value"><?= count($inStock) ?></div>
                    <div class="label">In Stock</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon si-yellow"><i class="bi bi-exclamation-triangle-fill"></i></div>
                <div class="stat-info">
                    <div class="value"><?= count($lowStock) ?></div>
                    <div class="label">Low Stock</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon si-red"><i class="bi bi-x-circle-fill"></i></div>
                <div class="stat-info">
                    <div class="value"><?= count($outOfStock) ?></div>
                    <div class="label">Out of Stock</div>
                </div>
            </div>
        </div>

        <!-- INVENTORY TABLE CARD -->
        <div class="main-card">
            <div class="card-toolbar">
                <div>
                    <h5>Inventory List</h5>
                    <p>Current stock levels for all products</p>
                </div>
                <div class="toolbar-btns">
                    <button class="btn-print-table no-print" onclick="printTable('inventoryTable', 'STOCK INVENTORY LIST', {stats:[{label:'Total Products',value:'<?= $totalProducts ?>'},{label:'In Stock',value:'<?= count($inStock) ?>'},{label:'Low Stock',value:'<?= count($lowStock) ?>'},{label:'Out of Stock',value:'<?= count($outOfStock) ?>'}]})">
                        <i class="bi bi-printer"></i> Print
                    </button>
                    <button class="btn-success-custom" onclick="openStockInModal()">
                        <i class="bi bi-arrow-down-circle"></i> Stock In
                    </button>
                    <button class="btn-danger-custom" onclick="openStockOutModal()">
                        <i class="bi bi-arrow-up-circle"></i> Stock Out
                    </button>
                </div>
            </div>

            <!-- TABS -->
            <div class="tab-bar">
                <button class="tab-btn active" onclick="switchTab('all', this)">
                    <i class="bi bi-list-ul"></i> All Products
                    <span class="tab-count"><?= $totalProducts ?></span>
                </button>
                <button class="tab-btn" onclick="switchTab('low', this)">
                    <i class="bi bi-exclamation-triangle"></i> Low Stock
                    <span class="tab-count"><?= count($lowStock) ?></span>
                </button>
                <button class="tab-btn" onclick="switchTab('out', this)">
                    <i class="bi bi-x-circle"></i> Out of Stock
                    <span class="tab-count"><?= count($outOfStock) ?></span>
                </button>
                <button class="tab-btn" onclick="switchTab('logs', this)">
                    <i class="bi bi-clock-history"></i> Stock Movement
                    <span class="tab-count"><?= count($recentLogs) ?></span>
                </button>
            </div>

            <!-- SEARCH BAR -->
            <div class="search-bar" id="inventorySearch">
                <div class="search-wrap">
                    <i class="bi bi-search"></i>
                    <input type="text" class="form-control" id="searchInput" placeholder="Search product name...">
                </div>
                <select class="form-select" id="filterCategory" style="width:160px;flex:none;">
                    <option value="">All Categories</option>
                    <?php
                    $cats = array_unique(array_filter(array_column($activeProducts, 'category')));
                    sort($cats);
                    foreach ($cats as $c): ?>
                    <option value="<?= htmlspecialchars(strtolower($c)) ?>"><?= htmlspecialchars($c) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- ALL PRODUCTS TAB -->
            <div id="tab-all">
                <div class="table-responsive">
                    <table id="inventoryTable">
                        <thead>
                            <tr>
                                <th>SKU</th>
                                <th>Product Name</th>
                                <th>Category</th>
                                <th>Unit</th>
                                <th>Min Level</th>
                                <th>Current Stock</th>
                                <th>Stock Level</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody id="inventoryTableBody">
                        <?php foreach ($activeProducts as $p):
                            if ($p['stock'] == 0) { $sc = 'bs-out'; $sl = 'Out of Stock'; $fc = 'fill-out'; }
                            elseif ($p['stock'] < $p['threshold']) { $sc = 'bs-low'; $sl = 'Low Stock'; $fc = 'fill-low'; }
                            else { $sc = 'bs-ok'; $sl = 'In Stock'; $fc = 'fill-ok'; }
                            $pct = $p['threshold'] > 0 ? min(100, round(($p['stock'] / ($p['threshold'] * 2)) * 100)) : 100;
                            $sku = 'SKU-' . str_pad($p['id'], 4, '0', STR_PAD_LEFT);
                        ?>
                        <tr data-name="<?= strtolower(htmlspecialchars($p['name'])) ?>"
                            data-category="<?= strtolower(htmlspecialchars($p['category'] ?? '')) ?>"
                            data-status="<?= $p['stock'] == 0 ? 'out' : ($p['stock'] < $p['threshold'] ? 'low' : 'ok') ?>">
                            <td><span class="sku-chip"><?= $sku ?></span></td>
                            <td>
                                <div class="product-name"><?= htmlspecialchars($p['name']) ?></div>
                                <div class="product-cat"><?= htmlspecialchars($p['category'] ?? '') ?></div>
                            </td>
                            <td><?= htmlspecialchars($p['category'] ?? 'General') ?></td>
                            <td><?= htmlspecialchars($p['unit'] ?? 'pcs') ?></td>
                            <td style="font-weight:600;color:#243f5f;"><?= intval($p['threshold']) ?></td>
                            <td style="font-weight:700;color:<?= $p['stock'] < $p['threshold'] ? '#ef4444' : '#22c55e' ?>;">
                                <?= intval($p['stock']) ?> <?= htmlspecialchars($p['unit'] ?? 'pcs') ?>
                            </td>
                            <td style="min-width:100px;">
                                <div class="stock-bar">
                                    <div class="stock-fill <?= $fc ?>" style="width:<?= $pct ?>%;"></div>
                                </div>
                                <div style="font-size:10px;color:#94a3b8;margin-top:3px;"><?= $pct ?>%</div>
                            </td>
                            <td><span class="badge-status <?= $sc ?>"><?= $sl ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div id="invEmpty" class="empty-state" style="display:none;">
                    <i class="bi bi-boxes"></i>
                    <div class="title">No products found</div>
                    <div class="sub">Try adjusting your search</div>
                </div>
                <div class="pg-wrap">
                    <div class="pg-info" id="pgInfo">Showing all products</div>
                    <div class="pg-btns" id="pgBtns"></div>
                </div>
            </div>

            <!-- LOW STOCK TAB -->
            <div id="tab-low" style="display:none;">
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr><th>SKU</th><th>Product</th><th>Unit</th><th>Min Level</th><th>Current Stock</th><th>Shortage</th></tr>
                        </thead>
                        <tbody>
                        <?php if (empty($lowStock)): ?>
                        <tr><td colspan="6" style="text-align:center;padding:40px;color:#94a3b8;">No low stock items. All good!</td></tr>
                        <?php else: ?>
                        <?php foreach ($lowStock as $p):
                            $shortage = $p['threshold'] - $p['stock'];
                            $sku = 'SKU-' . str_pad($p['id'], 4, '0', STR_PAD_LEFT);
                        ?>
                        <tr>
                            <td><span class="sku-chip"><?= $sku ?></span></td>
                            <td><div class="product-name"><?= htmlspecialchars($p['name']) ?></div></td>
                            <td><?= htmlspecialchars($p['unit'] ?? 'pcs') ?></td>
                            <td style="font-weight:600;color:#243f5f;"><?= $p['threshold'] ?></td>
                            <td style="font-weight:700;color:#ca8a04;"><?= $p['stock'] ?></td>
                            <td style="font-weight:700;color:#dc2626;">-<?= $shortage ?> <?= htmlspecialchars($p['unit'] ?? 'pcs') ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- OUT OF STOCK TAB -->
            <div id="tab-out" style="display:none;">
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr><th>SKU</th><th>Product</th><th>Category</th><th>Unit</th><th>Min Level</th><th>Status</th></tr>
                        </thead>
                        <tbody>
                        <?php if (empty($outOfStock)): ?>
                        <tr><td colspan="6" style="text-align:center;padding:40px;color:#94a3b8;">No out-of-stock items!</td></tr>
                        <?php else: ?>
                        <?php foreach ($outOfStock as $p):
                            $sku = 'SKU-' . str_pad($p['id'], 4, '0', STR_PAD_LEFT);
                        ?>
                        <tr>
                            <td><span class="sku-chip"><?= $sku ?></span></td>
                            <td><div class="product-name"><?= htmlspecialchars($p['name']) ?></div></td>
                            <td><?= htmlspecialchars($p['category'] ?? 'General') ?></td>
                            <td><?= htmlspecialchars($p['unit'] ?? 'pcs') ?></td>
                            <td><?= $p['threshold'] ?></td>
                            <td><span class="badge-status bs-out"><i class="bi bi-x-circle"></i> Out of Stock</span></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- STOCK MOVEMENT LOG TAB -->
            <div id="tab-logs" style="display:none;">
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr><th>Date & Time</th><th>Type</th><th>Product</th><th>Qty</th><th>Supplier / Remarks</th><th>By</th></tr>
                        </thead>
                        <tbody>
                        <?php if (empty($recentLogs)): ?>
                        <tr><td colspan="6" style="text-align:center;padding:40px;color:#94a3b8;">No stock movement recorded yet.</td></tr>
                        <?php else: ?>
                        <?php foreach ($recentLogs as $log): ?>
                        <tr>
                            <td style="white-space:nowrap;font-size:11px;"><?= htmlspecialchars($log['date'] ?? '—') ?></td>
                            <td>
                                <?php if (($log['type'] ?? '') === 'in'): ?>
                                <span class="log-badge-in"><i class="bi bi-arrow-down-circle me-1"></i>IN</span>
                                <?php else: ?>
                                <span class="log-badge-out"><i class="bi bi-arrow-up-circle me-1"></i>OUT</span>
                                <?php endif; ?>
                            </td>
                            <td class="product-name"><?= htmlspecialchars($log['product_name'] ?? '—') ?></td>
                            <td style="font-weight:700;color:<?= ($log['type'] ?? '') === 'in' ? '#16a34a' : '#dc2626' ?>;">
                                <?= ($log['type'] ?? '') === 'in' ? '+' : '-' ?><?= intval($log['quantity'] ?? 0) ?>
                            </td>
                            <td style="font-size:11px;color:#64748b;"><?= htmlspecialchars($log['supplier'] ?? $log['remarks'] ?? '—') ?></td>
                            <td style="font-size:11px;color:#94a3b8;"><?= htmlspecialchars($log['user_name'] ?? '—') ?></td>
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

<!-- STOCK IN MODAL -->
<div class="modal fade" id="stockInModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered" style="max-width:480px;">
        <div class="modal-content">
            <div class="modal-header header-in">
                <h5 class="modal-title"><i class="bi bi-arrow-down-circle me-2"></i>Record Stock In</h5>
                <button class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <?= csrf_field() ?>
                <div class="modal-body">
                    <input type="hidden" name="action" value="stock_in">
                    <div class="mb-3">
                        <label class="form-label">Select Product <span style="color:#ef4444;">*</span></label>
                        <select class="form-select" name="product_id" required>
                            <option value="">-- Choose Product --</option>
                            <?php foreach ($activeProducts as $p): ?>
                            <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?> (<?= $p['stock'] ?> <?= htmlspecialchars($p['unit'] ?? 'pcs') ?> in stock)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Quantity to Receive <span style="color:#ef4444;">*</span></label>
                        <input type="number" class="form-control" name="quantity" min="1" max="<?= V_QTY_MAX ?>" step="1" placeholder="e.g. 50" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Date Received <span style="color:#ef4444;">*</span></label>
                        <input type="date" class="form-control" name="date" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Supplier</label>
                        <input type="text" class="form-control" name="supplier" maxlength="<?= V_NAME_MAX ?>" placeholder="e.g. ABC Suppliers Inc.">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Remarks</label>
                        <textarea class="form-control" name="remarks" rows="2" maxlength="<?= V_TEXT_MAX ?>" placeholder="Optional notes..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light fw-semibold" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn-success-custom"><i class="bi bi-check-circle"></i> Save Stock In</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- STOCK OUT MODAL -->
<div class="modal fade" id="stockOutModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered" style="max-width:480px;">
        <div class="modal-content">
            <div class="modal-header header-out">
                <h5 class="modal-title"><i class="bi bi-arrow-up-circle me-2"></i>Record Stock Out</h5>
                <button class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="stockOutForm">
                <?= csrf_field() ?>
                <div class="modal-body">
                    <input type="hidden" name="action" value="stock_out">
                    <div class="mb-3">
                        <label class="form-label">Select Product <span style="color:#ef4444;">*</span></label>
                        <select class="form-select" name="product_id" id="stockOutProduct" required>
                            <option value="">-- Choose Product --</option>
                            <?php foreach ($activeProducts as $p): ?>
                            <option value="<?= $p['id'] ?>"
                                data-name="<?= htmlspecialchars($p['name']) ?>"
                                data-stock="<?= intval($p['stock']) ?>"
                                data-threshold="<?= intval($p['threshold']) ?>"
                                data-unit="<?= htmlspecialchars($p['unit'] ?? 'pcs') ?>"><?= htmlspecialchars($p['name']) ?> (<?= $p['stock'] ?> <?= htmlspecialchars($p['unit'] ?? 'pcs') ?> in stock<?= $p['stock'] == 0 ? ' — OUT' : ($p['stock'] < $p['threshold'] ? ' — LOW' : '') ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Quantity to Issue <span style="color:#ef4444;">*</span></label>
                        <input type="number" class="form-control" name="quantity" id="stockOutQty" min="1" step="1" placeholder="e.g. 10" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Date Issued <span style="color:#ef4444;">*</span></label>
                        <input type="date" class="form-control" name="date" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Remarks</label>
                        <textarea class="form-control" name="remarks" rows="2" maxlength="<?= V_TEXT_MAX ?>" placeholder="Reason for stock out..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light fw-semibold" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn-danger-custom" id="stockOutSubmit"><i class="bi bi-check-circle"></i> Save Stock Out</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="stock_guard.js?v=<?= filemtime(__DIR__ . '/stock_guard.js') ?>"></script>
<script>
StockGuard.attach({
    form: 'stockOutForm',
    select: 'stockOutProduct',
    quantity: 'stockOutQty',
    submit: 'stockOutSubmit'
});

function switchTab(tab, btn) {
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    ['all','low','out','logs'].forEach(t => {
        document.getElementById('tab-' + t).style.display = t === tab ? '' : 'none';
    });
    document.getElementById('inventorySearch').style.display = tab === 'all' ? '' : 'none';
}

function openStockInModal()  { new bootstrap.Modal(document.getElementById('stockInModal')).show(); }
function openStockOutModal() { new bootstrap.Modal(document.getElementById('stockOutModal')).show(); }

// Search & Filter
const ROWS_PER_PAGE = 10;
let currentPage = 1, filteredRows = [];

function applyFilters() {
    const q   = document.getElementById('searchInput').value.toLowerCase().trim();
    const cat = document.getElementById('filterCategory').value.toLowerCase();
    const rows = Array.from(document.querySelectorAll('#inventoryTableBody tr'));
    filteredRows = rows.filter(r => {
        const mq  = !q   || r.dataset.name.includes(q);
        const mc  = !cat || r.dataset.category === cat;
        return mq && mc;
    });
    currentPage = 1;
    renderPage();
}

function renderPage() {
    Array.from(document.querySelectorAll('#inventoryTableBody tr')).forEach(r => r.style.display = 'none');
    const start = (currentPage - 1) * ROWS_PER_PAGE;
    filteredRows.slice(start, start + ROWS_PER_PAGE).forEach(r => r.style.display = '');
    document.getElementById('invEmpty').style.display = filteredRows.length === 0 ? '' : 'none';
    const total = filteredRows.length;
    document.getElementById('pgInfo').textContent = total === 0 ? 'No products found' : `Showing ${Math.min(start+1,total)}–${Math.min(start+ROWS_PER_PAGE,total)} of ${total}`;
    const totalPages = Math.ceil(total / ROWS_PER_PAGE);
    const container = document.getElementById('pgBtns');
    container.innerHTML = '';
    if (totalPages <= 1) return;
    const prev = mkBtn('<i class="bi bi-chevron-left"></i>', currentPage > 1, () => { currentPage--; renderPage(); });
    container.appendChild(prev);
    for (let p = 1; p <= totalPages; p++) {
        const b = mkBtn(p, true, () => { currentPage = p; renderPage(); });
        if (p === currentPage) b.classList.add('active');
        container.appendChild(b);
    }
    container.appendChild(mkBtn('<i class="bi bi-chevron-right"></i>', currentPage < totalPages, () => { currentPage++; renderPage(); }));
}

function mkBtn(label, enabled, onclick) {
    const b = document.createElement('button');
    b.className = 'pg-btn'; b.innerHTML = label; b.disabled = !enabled;
    b.addEventListener('click', onclick);
    return b;
}

document.getElementById('searchInput').addEventListener('input', applyFilters);
document.getElementById('filterCategory').addEventListener('change', applyFilters);
document.addEventListener('DOMContentLoaded', applyFilters);
</script>
<script><?= maexx_print_js() ?></script>
<script><?= maexx_notif_js() ?></script>
</body>
</html>