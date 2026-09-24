<?php
require 'auth.php';
require 'print_template.php';
require 'notifications.php';
require_login();

$user    = get_logged_in_user();
$isAdmin = is_admin_role($user['role']);

$_allProds      = array_filter(load_products(), fn($p) => empty($p['archived']));
$_sidebarAlerts = count(array_filter($_allProds, fn($p) => intval($p['stock']) === 0))
                + count(array_filter($_allProds, fn($p) => intval($p['stock']) > 0 && intval($p['stock']) < intval($p['threshold'])));

/* ===== HANDLE POST ===== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf('sales.php');

    $action = $_POST['action'] ?? '';

    if ($action === 'record_order') {
        $v = validate_order_input($_POST);

        if ($v['ok']) {
            $product      = $v['product'];
            $productId    = intval($product['id']);
            $quantity     = $v['quantity'];
            $customerName = $v['customer'];
            $poNumber     = $v['po'];
            $notes        = $v['notes'];
            $check        = validate_stock_out($productId, $quantity, !empty($_POST['confirm_low']));
        }

        if (!$v['ok']) {
            set_flash($v['message'], 'danger');
        } elseif (!$check['ok']) {
            set_flash($check['message'], 'danger');
        } else {
            $total  = $product['price'] * $quantity;
            $refNo  = 'SO-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -5));
            $result = adjust_stock($productId, $quantity, false);
            if ($result['success']) {
                save_transaction([
                    'reference'     => $refNo,
                    'customer_name' => $customerName,
                    'po_number'     => $poNumber,
                    'product_id'    => $productId,
                    'product_name'  => $product['name'],
                    'quantity'      => $quantity,
                    'unit'          => $product['unit'] ?? 'pcs',
                    'unit_price'    => $product['price'],
                    'total'         => $total,
                    'status'        => 'Pending',
                    'notes'         => $notes,
                    'processed_by'  => $user['name'],
                    'timestamp'     => date('Y-m-d H:i:s'),
                ]);
                add_activity_log("Sale {$refNo}: {$customerName} ordered {$quantity}x {$product['name']} — ₱" . number_format($total, 2));
                set_flash("Order {$refNo} recorded successfully! Marked as Pending Delivery.", 'success');
                header('Location: sales.php?highlight=' . urlencode($refNo)); exit;
            } else {
                set_flash($result['message'], 'danger');
            }
        }
        header('Location: sales.php'); exit;
    }

    if ($action === 'mark_delivered') {
        $txRef   = trim($_POST['tx_ref'] ?? '');
        $siNum   = trim($_POST['si_number'] ?? '');
        $delDate = trim($_POST['delivery_date'] ?? date('Y-m-d'));
        $delQty  = intval($_POST['delivered_qty'] ?? 0);

        $check = validate_delivery($txRef, $delQty, $siNum, !empty($_POST['confirm_short']));

        if ($check['ok']) {
            $details = validate_delivery_details($check['tx'], $siNum, $delDate);
            if (!$details['ok']) {
                $check = $details;
            }
        }

        if (!$check['ok']) {
            set_flash($check['message'], 'danger');
            header('Location: sales.php'); exit;
        }

        $delDate = $details['date'];

        update_transaction_status($txRef, 'Delivered', $siNum, $delDate, $delQty);

        $tx        = $check['tx'];
        $unit      = $tx['unit'] ?? 'pcs';
        $shortfall = $check['shortfall'];

        if ($shortfall > 0) {
            // These units were deducted when the order was recorded but never
            // left the warehouse, so they go back on the shelf.
            $returned = adjust_stock(intval($tx['product_id']), $shortfall, true);

            add_activity_log("Order {$txRef} partially delivered: {$delQty} of {$tx['quantity']} {$unit}. SI: {$siNum}. "
                . ($returned['success'] ? "{$shortfall} {$unit} returned to stock." : 'Stock return failed: ' . $returned['message']));

            set_flash(
                $returned['success']
                    ? "Order {$txRef} marked as partially delivered ({$delQty} of {$tx['quantity']} {$unit}). {$shortfall} {$unit} returned to stock."
                    : "Order {$txRef} marked as delivered, but {$shortfall} {$unit} could not be returned to stock: {$returned['message']}",
                $returned['success'] ? 'success' : 'warning'
            );
        } else {
            add_activity_log("Order {$txRef} marked as Delivered. SI: {$siNum}");
            set_flash("Order {$txRef} marked as Delivered.", 'success');
        }

        header('Location: sales.php?highlight=' . urlencode($txRef)); exit;
    }
}

$flash    = get_flash();
$products = load_products();
$activeProducts = array_values(array_filter($products, fn($p) => empty($p['archived']) && $p['stock'] > 0));
$allTransactions = load_transactions();
$allTx   = array_reverse($allTransactions);
$pending = array_values(array_filter($allTx, fn($t) => ($t['status'] ?? 'pending') === 'pending'));
$delivered = array_values(array_filter($allTx, fn($t) => ($t['status'] ?? '') === 'delivered'));

// Summary
$thisMonth = date('Y-m');
$monthSales = array_sum(array_column(array_filter($allTx, fn($t) => substr($t['timestamp'] ?? '', 0, 7) === $thisMonth), 'total'));
$todaySales = array_sum(array_column(array_filter($allTx, fn($t) => substr($t['timestamp'] ?? '', 0, 10) === date('Y-m-d')), 'total'));

$dashboardLink = $isAdmin ? 'dashboard_admin.php' : 'dashboard_inventory.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sales - MAEXX</title>
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
.topbar-right { display:flex;align-items:center;gap:16px; }
.user-pill { display:flex;align-items:center;gap:10px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:999px;padding:6px 14px 6px 8px;text-decoration:none;color:inherit;cursor:pointer;transition:background .2s,border-color .2s; }
.user-pill:hover { background:#f1f5f9;border-color:#cbd5e1; }
.user-avatar { width:30px;height:30px;background:#243f5f;color:#fff;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700; }
.user-info .name { font-size:12px;font-weight:600;color:#1e293b; }
.user-info .role { font-size:10px;color:#94a3b8; }

.page-content { padding:24px 20px; }

.stats-grid { display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:20px; }
@media (max-width:1100px) { .stats-grid { grid-template-columns:repeat(2,1fr); } }
.stat-card { background:#fff;border-radius:16px;padding:18px 20px;border:1px solid #e2e8f0;box-shadow:0 2px 8px rgba(0,0,0,.04);display:flex;align-items:center;gap:14px; }
.stat-icon { width:44px;height:44px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0; }
.si-blue   { background:#eff6ff;color:#3b82f6; }
.si-green  { background:#f0fdf4;color:#22c55e; }
.si-yellow { background:#fefce8;color:#ca8a04; }
.si-purple { background:#f5f3ff;color:#7c3aed; }
.stat-info .value { font-size:20px;font-weight:700;color:#1e293b;line-height:1; }
.stat-info .label { font-size:11px;color:#64748b;margin-top:3px; }

.main-card { background:#fff;border-radius:18px;border:1px solid #e2e8f0;box-shadow:0 2px 10px rgba(0,0,0,.05);overflow:hidden;margin-bottom:20px; }
.card-toolbar { padding:16px 18px;display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid #f1f5f9;flex-wrap:wrap;gap:10px; }
.card-toolbar h5 { font-size:15px;font-weight:700;color:#1e293b;margin:0; }
.card-toolbar p  { font-size:11px;color:#94a3b8;margin:2px 0 0; }

.tab-bar { display:flex;gap:4px;padding:14px 22px 0;border-bottom:2px solid #f1f5f9; }
.tab-btn { padding:9px 18px;font-size:13px;font-weight:600;color:#64748b;border:none;background:none;cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-2px;transition:all .2s;font-family:'Poppins',sans-serif;display:flex;align-items:center;gap:6px; }
.tab-btn.active { color:#243f5f;border-bottom-color:#243f5f; }
.tab-count { background:#f1f5f9;color:#64748b;padding:2px 7px;border-radius:999px;font-size:10px;font-weight:700; }
.tab-btn.active .tab-count { background:#243f5f;color:#fff; }

.search-bar { padding:14px 22px;background:#f8fafc;border-bottom:1px solid #f1f5f9;display:flex;gap:10px;flex-wrap:wrap; }
.search-wrap { position:relative;flex:1;min-width:200px; }
.search-wrap i { position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:14px; }
.search-wrap input { padding-left:36px;width:100%; }
.form-control,.form-select { border-radius:10px;font-size:13px;padding:9px 13px;border:1.5px solid #e2e8f0;font-family:'Poppins',sans-serif;transition:border-color .2s; }
.form-control:focus,.form-select:focus { border-color:#243f5f;box-shadow:0 0 0 3px rgba(36,63,95,.08);outline:none; }

.table-responsive { overflow-x:auto;-webkit-overflow-scrolling:touch; }
table { width:100%;border-collapse:collapse;min-width:820px; }
thead th { font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:#94a3b8;padding:10px 10px;border-bottom:2px solid #f1f5f9;white-space:nowrap;background:#f8fafc;text-align:left; }
tbody td { font-size:12px;color:#475569;padding:10px 10px;border-bottom:1px solid #f8fafc;vertical-align:middle; }
tbody tr:hover { background:#f8fafc; }
tbody tr:last-child td { border-bottom:none; }
.col-customer,.col-product { max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap; }
.col-po { max-width:110px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap; }

.ref-chip { font-family:monospace;background:#eff6ff;color:#1d4ed8;padding:3px 8px;border-radius:6px;font-size:11px;font-weight:700;white-space:nowrap; }

.badge-status { display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:999px;font-size:10px;font-weight:700; }
.bs-pending   { background:#fef9c3;color:#854d0e; }
.bs-delivered { background:#dcfce7;color:#166534; }
.bs-cancelled { background:#fee2e2;color:#991b1b; }

.btn-primary-custom { background:#243f5f;color:#fff;border:none;border-radius:10px;padding:9px 18px;font-size:13px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:all .2s;font-family:'Poppins',sans-serif; }
.btn-primary-custom:hover { background:#1a2f47; }
.btn-sm-act { width:30px;height:30px;border-radius:8px;border:none;display:inline-flex;align-items:center;justify-content:center;font-size:13px;cursor:pointer;transition:all .15s; }
.btn-deliver { background:#dcfce7;color:#166534; }
.btn-deliver:hover { background:#bbf7d0; }
.btn-view    { background:#eff6ff;color:#1d4ed8; }
.btn-view:hover { background:#dbeafe; }

.modal-content { border:none;border-radius:20px;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,.15); }
.modal-header  { padding:20px 24px;border:none; }
.modal-title   { font-size:16px;font-weight:700; }
.modal-body    { padding:24px; }
.modal-footer  { padding:16px 24px;background:#f8fafc;border-top:1px solid #f1f5f9; }
.form-label    { font-size:12px;font-weight:600;color:#475569;margin-bottom:5px; }
.header-primary { background:linear-gradient(135deg,#243f5f,#1a2f47);color:#fff; }
.header-primary .btn-close { filter:brightness(0) invert(1); }
.header-green { background:linear-gradient(135deg,#16a34a,#15803d);color:#fff; }
.header-green .btn-close { filter:brightness(0) invert(1); }

.flash-box { padding:12px 18px;border-radius:12px;margin-bottom:20px;font-size:13px;display:flex;align-items:center;gap:10px; }
.flash-success { background:#f0fdf4;color:#166534;border:1px solid #bbf7d0; }
.flash-danger  { background:#fff1f2;color:#991b1b;border:1px solid #fecaca; }

.empty-state { text-align:center;padding:60px 20px;color:#94a3b8; }
.empty-state i { font-size:48px;display:block;margin-bottom:12px; }
.empty-state .title { font-size:14px;font-weight:600;color:#64748b; }
.empty-state .sub   { font-size:12px;margin-top:4px; }

/* ORDER SUMMARY PREVIEW */
.order-preview { background:#f8fafc;border-radius:12px;padding:14px;margin-top:12px;border:1px solid #e2e8f0;display:none; }
.order-preview .row-item { display:flex;justify-content:space-between;font-size:12px;padding:4px 0;border-bottom:1px solid #f1f5f9; }
.order-preview .row-item:last-child { border-bottom:none;font-weight:700;font-size:13px;color:#1e293b;padding-top:8px; }
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
        <a href="inventory.php"><i class="bi bi-boxes"></i> Inventory<?php if ($_sidebarAlerts > 0): ?> <span class="badge-count"><?= $_sidebarAlerts ?></span><?php endif; ?></a>
        <a href="sales.php" class="active"><i class="bi bi-cart-fill"></i> Sales</a>
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
            <h4>Sales Monitoring</h4>
            <p>Record customer orders, track deliveries, and view transaction history</p>
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
                <div class="stat-icon si-blue"><i class="bi bi-currency-dollar"></i></div>
                <div class="stat-info">
                    <div class="value">₱<?= number_format($todaySales, 0) ?></div>
                    <div class="label">Today's Sales</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon si-green"><i class="bi bi-graph-up-arrow"></i></div>
                <div class="stat-info">
                    <div class="value">₱<?= number_format($monthSales, 0) ?></div>
                    <div class="label">This Month's Sales</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon si-yellow"><i class="bi bi-hourglass-split"></i></div>
                <div class="stat-info">
                    <div class="value"><?= count($pending) ?></div>
                    <div class="label">Pending Orders</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon si-purple"><i class="bi bi-receipt"></i></div>
                <div class="stat-info">
                    <div class="value"><?= count($allTx) ?></div>
                    <div class="label">Total Transactions</div>
                </div>
            </div>
        </div>

        <!-- SALES TABLE CARD -->
        <div class="main-card">
            <div class="card-toolbar">
                <div>
                    <h5>Sales Transactions</h5>
                    <p>All recorded customer orders</p>
                </div>
                <div class="toolbar-btns" style="display:flex;gap:8px;">
                    <button class="btn-print-table no-print" onclick="printTable('salesTable', 'SALES TRANSACTIONS REPORT', {stats:[{label:'Total Transactions',value:'<?= count($allTx) ?>'},{label:'Pending',value:'<?= count($pending) ?>'},{label:'Delivered',value:'<?= count($delivered) ?>'},{label:'Month Sales',value:'₱<?= number_format($monthSales,0) ?>'}]})">
                        <i class="bi bi-printer"></i> Print
                    </button>
                    <button class="btn-primary-custom" onclick="openOrderModal()">
                        <i class="bi bi-cart-plus"></i> Record Customer Order
                    </button>
                </div>
            </div>

            <!-- TABS -->
            <div class="tab-bar">
                <button class="tab-btn active" onclick="switchTab('all', this)">
                    <i class="bi bi-list-ul"></i> All Transactions
                    <span class="tab-count"><?= count($allTx) ?></span>
                </button>
                <button class="tab-btn" onclick="switchTab('pending', this)">
                    <i class="bi bi-hourglass-split"></i> Pending
                    <span class="tab-count"><?= count($pending) ?></span>
                </button>
                <button class="tab-btn" onclick="switchTab('delivered', this)">
                    <i class="bi bi-check-circle"></i> Delivered
                    <span class="tab-count"><?= count($delivered) ?></span>
                </button>
            </div>

            <!-- SEARCH -->
            <div class="search-bar">
                <div class="search-wrap">
                    <i class="bi bi-search"></i>
                    <input type="text" class="form-control" id="searchInput" placeholder="Search reference, customer, or product...">
                </div>
                <input type="date" class="form-control" id="filterDate" style="width:160px;flex:none;" title="Filter by date">
            </div>

            <!-- ALL TAB -->
            <div id="tab-all">
                <div class="table-responsive">
                    <table id="salesTable">
                        <thead>
                            <tr>
                                <th>Reference</th>
                                <th>Date</th>
                                <th>Customer</th>
                                <th>PO No.</th>
                                <th>Product</th>
                                <th>Qty</th>
                                <th>Total</th>
                                <th>Status</th>
                                <th style="text-align:center;">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="salesTableBody">
                        <?php foreach ($allTx as $tx):
                            $status = $tx['status'] ?? 'pending';
                            $sc = $status === 'delivered' ? 'bs-delivered' : ($status === 'cancelled' ? 'bs-cancelled' : 'bs-pending');
                            $txDate = substr($tx['timestamp'] ?? '', 0, 10);
                        ?>
                        <tr data-search="<?= strtolower(htmlspecialchars(($tx['reference'] ?? '') . ' ' . ($tx['customer_name'] ?? '') . ' ' . ($tx['product_name'] ?? ''))) ?>"
                            data-date="<?= $txDate ?>">
                            <td><span class="ref-chip"><?= htmlspecialchars($tx['reference'] ?? '—') ?></span></td>
                            <td style="white-space:nowrap;font-size:11px;"><?= htmlspecialchars(substr($tx['timestamp'] ?? '—', 0, 16)) ?></td>
                            <td class="col-customer" style="font-weight:600;color:#1e293b;" title="<?= htmlspecialchars($tx['customer_name'] ?? '') ?>"><?= htmlspecialchars($tx['customer_name'] ?? '—') ?></td>
                            <td class="col-po" style="font-size:11px;color:#94a3b8;" title="<?= htmlspecialchars($tx['po_number'] ?? '') ?>"><?= htmlspecialchars($tx['po_number'] ?? '—') ?></td>
                            <td class="col-product" title="<?= htmlspecialchars($tx['product_name'] ?? '') ?>"><?= htmlspecialchars($tx['product_name'] ?? '—') ?></td>
                            <td style="white-space:nowrap;"><?= intval($tx['quantity'] ?? 0) ?> <?= htmlspecialchars($tx['unit'] ?? '') ?></td>
                            <td style="font-weight:700;color:#22c55e;white-space:nowrap;">₱<?= number_format($tx['total'] ?? 0, 2) ?></td>
                            <td><span class="badge-status <?= $sc ?>"><?= htmlspecialchars(ucfirst($status)) ?></span></td>
                            <td style="text-align:center;white-space:nowrap;">
                                <button class="btn-sm-act btn-view me-1" title="View details"
                                    onclick="viewTx(<?= htmlspecialchars(json_encode($tx), ENT_QUOTES) ?>)">
                                    <i class="bi bi-eye"></i>
                                </button>
                                <?php if ($status === 'pending'): ?>
                                <button class="btn-sm-act btn-deliver" title="Mark as Delivered"
                                    onclick="openDeliverModal(<?= htmlspecialchars(json_encode($tx), ENT_QUOTES) ?>)">
                                    <i class="bi bi-check-circle"></i>
                                </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if (empty($allTx)): ?>
                <div class="empty-state">
                    <i class="bi bi-receipt"></i>
                    <div class="title">No transactions yet</div>
                    <div class="sub">Click "Record Customer Order" to start</div>
                </div>
                <?php endif; ?>
                <div id="salesEmpty" class="empty-state" style="display:none;">
                    <i class="bi bi-search"></i>
                    <div class="title">No results found</div>
                    <div class="sub">Try adjusting your search</div>
                </div>
            </div>

            <!-- PENDING TAB -->
            <div id="tab-pending" style="display:none;">
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr><th>Reference</th><th>Date</th><th>Customer</th><th>PO No.</th><th>Product</th><th>Qty</th><th>Total</th><th style="text-align:center;">Action</th></tr>
                        </thead>
                        <tbody>
                        <?php if (empty($pending)): ?>
                        <tr><td colspan="8" style="text-align:center;padding:40px;color:#94a3b8;">No pending orders. All delivered!</td></tr>
                        <?php else: ?>
                        <?php foreach ($pending as $tx): ?>
                        <tr>
                            <td><span class="ref-chip"><?= htmlspecialchars($tx['reference'] ?? '—') ?></span></td>
                            <td style="font-size:11px;white-space:nowrap;"><?= htmlspecialchars(substr($tx['timestamp'] ?? '—', 0, 16)) ?></td>
                            <td class="col-customer" style="font-weight:600;color:#1e293b;" title="<?= htmlspecialchars($tx['customer_name'] ?? '') ?>"><?= htmlspecialchars($tx['customer_name'] ?? '—') ?></td>
                            <td class="col-po" style="font-size:11px;color:#94a3b8;" title="<?= htmlspecialchars($tx['po_number'] ?? '') ?>"><?= htmlspecialchars($tx['po_number'] ?? '—') ?></td>
                            <td class="col-product" title="<?= htmlspecialchars($tx['product_name'] ?? '') ?>"><?= htmlspecialchars($tx['product_name'] ?? '—') ?></td>
                            <td style="white-space:nowrap;"><?= intval($tx['quantity'] ?? 0) ?> <?= htmlspecialchars($tx['unit'] ?? '') ?></td>
                            <td style="font-weight:700;color:#22c55e;white-space:nowrap;">₱<?= number_format($tx['total'] ?? 0, 2) ?></td>
                            <td style="text-align:center;">
                                <button class="btn-sm-act btn-deliver" title="Mark as Delivered"
                                    onclick="openDeliverModal(<?= htmlspecialchars(json_encode($tx), ENT_QUOTES) ?>)">
                                    <i class="bi bi-check-circle"></i>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- DELIVERED TAB -->
            <div id="tab-delivered" style="display:none;">
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr><th>Reference</th><th>SI No.</th><th>Customer</th><th>Product</th><th>Qty Delivered</th><th>Total</th><th>Delivery Date</th></tr>
                        </thead>
                        <tbody>
                        <?php if (empty($delivered)): ?>
                        <tr><td colspan="7" style="text-align:center;padding:40px;color:#94a3b8;">No delivered orders yet.</td></tr>
                        <?php else: ?>
                        <?php foreach ($delivered as $tx): ?>
                        <tr>
                            <td><span class="ref-chip"><?= htmlspecialchars($tx['reference'] ?? '—') ?></span></td>
                            <td style="font-family:monospace;font-size:11px;"><?= htmlspecialchars($tx['si_number'] ?? '—') ?></td>
                            <td class="col-customer" style="font-weight:600;color:#1e293b;" title="<?= htmlspecialchars($tx['customer_name'] ?? '') ?>"><?= htmlspecialchars($tx['customer_name'] ?? '—') ?></td>
                            <td class="col-product" title="<?= htmlspecialchars($tx['product_name'] ?? '') ?>"><?= htmlspecialchars($tx['product_name'] ?? '—') ?></td>
                            <td style="white-space:nowrap;"><?= intval($tx['delivered_qty'] ?? $tx['quantity'] ?? 0) ?> <?= htmlspecialchars($tx['unit'] ?? '') ?></td>
                            <td style="font-weight:700;color:#22c55e;white-space:nowrap;">₱<?= number_format($tx['total'] ?? 0, 2) ?></td>
                            <td style="font-size:11px;white-space:nowrap;"><?= htmlspecialchars($tx['delivery_date'] ?? '—') ?></td>
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

<!-- RECORD ORDER MODAL -->
<div class="modal fade" id="orderModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered" style="max-width:520px;">
        <div class="modal-content">
            <div class="modal-header header-primary">
                <h5 class="modal-title"><i class="bi bi-cart-plus me-2"></i>Record Customer Order</h5>
                <button class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="orderForm">
                <?= csrf_field() ?>
                <div class="modal-body">
                    <input type="hidden" name="action" value="record_order">

                    <div class="mb-3">
                        <label class="form-label">Customer Name <span style="color:#ef4444;">*</span></label>
                        <input type="text" class="form-control" name="customer_name" placeholder="e.g. Juan Dela Cruz"
                               required minlength="2" maxlength="<?= V_NAME_MAX ?>">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">PO Number</label>
                        <input type="text" class="form-control" name="po_number" placeholder="e.g. PO-2024-001"
                               maxlength="<?= V_REF_MAX ?>" pattern="[A-Za-z0-9\-\/ ]+"
                               title="Letters, numbers, dashes and slashes only. Must not be used on another order.">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Select Product <span style="color:#ef4444;">*</span></label>
                        <select class="form-select" name="product_id" id="orderProduct" onchange="updatePreview()" required>
                            <option value="">-- Choose Product --</option>
                            <?php foreach ($activeProducts as $p): ?>
                            <option value="<?= $p['id'] ?>" <?= floatval($p['price'] ?? 0) <= 0 ? 'disabled' : '' ?>
                                data-name="<?= htmlspecialchars($p['name']) ?>"
                                data-price="<?= $p['price'] ?>"
                                data-stock="<?= intval($p['stock']) ?>"
                                data-threshold="<?= intval($p['threshold']) ?>"
                                data-unit="<?= htmlspecialchars($p['unit'] ?? 'pcs') ?>">
                                <?= htmlspecialchars($p['name']) ?> (<?= $p['stock'] ?> <?= htmlspecialchars($p['unit'] ?? 'pcs') ?> available<?= floatval($p['price'] ?? 0) <= 0 ? ' — NO PRICE SET' : ($p['stock'] < $p['threshold'] ? ' — LOW' : '') ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Quantity <span style="color:#ef4444;">*</span></label>
                        <input type="number" class="form-control" name="quantity" id="orderQty" min="1" placeholder="Enter quantity" oninput="updatePreview()" required>
                        <div id="stockHint" style="font-size:11px;color:#94a3b8;margin-top:4px;"></div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea class="form-control" name="notes" rows="2" maxlength="<?= V_TEXT_MAX ?>" placeholder="Optional delivery notes..."></textarea>
                    </div>

                    <!-- ORDER PREVIEW -->
                    <div class="order-preview" id="orderPreview">
                        <div class="row-item"><span>Product</span><span id="previewProduct">—</span></div>
                        <div class="row-item"><span>Unit Price</span><span id="previewPrice">—</span></div>
                        <div class="row-item"><span>Quantity</span><span id="previewQty">—</span></div>
                        <div class="row-item"><span>Total Amount</span><span id="previewTotal" style="color:#22c55e;">—</span></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light fw-semibold" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn-primary-custom" id="orderSubmit"><i class="bi bi-check-circle"></i> Save Order</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MARK DELIVERED MODAL -->
<div class="modal fade" id="deliverModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered" style="max-width:460px;">
        <div class="modal-content">
            <div class="modal-header header-green">
                <h5 class="modal-title"><i class="bi bi-check-circle me-2"></i>Confirm Delivery</h5>
                <button class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="deliverForm">
                <?= csrf_field() ?>
                <div class="modal-body">
                    <input type="hidden" name="action" value="mark_delivered">
                    <input type="hidden" name="tx_ref" id="deliverRef">
                    <div style="background:#f0fdf4;border-radius:12px;padding:14px;margin-bottom:18px;font-size:13px;">
                        <strong id="deliverSummary"></strong>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">SI Number <span style="color:#ef4444;">*</span></label>
                        <input type="text" class="form-control" name="si_number" placeholder="e.g. SI-2024-001" required
                               maxlength="<?= V_REF_MAX ?>" pattern="[A-Za-z0-9\-\/ ]+"
                               title="Letters, numbers, dashes and slashes only. Must not be used on another delivery.">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Delivery Date <span style="color:#ef4444;">*</span></label>
                        <input type="date" class="form-control" name="delivery_date" id="deliverDate" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Confirmed Delivery Quantity <span style="color:#ef4444;">*</span></label>
                        <input type="number" class="form-control" name="delivered_qty" id="deliverQty" min="1" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light fw-semibold" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" id="deliverSubmit" style="background:#16a34a;color:#fff;border:none;border-radius:10px;padding:9px 20px;font-weight:600;cursor:pointer;font-family:'Poppins',sans-serif;display:inline-flex;align-items:center;gap:6px;">
                        <i class="bi bi-check-circle"></i> Confirm Delivered
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- VIEW DETAILS MODAL -->
<div class="modal fade" id="viewModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered" style="max-width:460px;">
        <div class="modal-content">
            <div class="modal-header" style="background:#f8fafc;border-bottom:1px solid #f1f5f9;">
                <h5 class="modal-title" style="color:#1e293b;"><i class="bi bi-receipt me-2"></i>Transaction Details</h5>
                <button class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="viewModalBody"></div>
        </div>
    </div>
</div>

<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="stock_guard.js?v=<?= filemtime(__DIR__ . '/stock_guard.js') ?>"></script>
<script>
StockGuard.attach({
    form: 'orderForm',
    select: 'orderProduct',
    quantity: 'orderQty',
    submit: 'orderSubmit'
});

function switchTab(tab, btn) {
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    ['all','pending','delivered'].forEach(t => {
        document.getElementById('tab-' + t).style.display = t === tab ? '' : 'none';
    });
}

function openOrderModal() { new bootstrap.Modal(document.getElementById('orderModal')).show(); }

function updatePreview() {
    const sel = document.getElementById('orderProduct');
    const qty = parseInt(document.getElementById('orderQty').value) || 0;
    const opt = sel.options[sel.selectedIndex];
    const preview = document.getElementById('orderPreview');
    const hint    = document.getElementById('stockHint');

    if (!opt || !opt.value) { preview.style.display = 'none'; hint.textContent = ''; return; }

    const price = parseFloat(opt.dataset.price) || 0;
    const stock = parseInt(opt.dataset.stock) || 0;
    const unit  = opt.dataset.unit || 'pcs';
    const total = price * qty;

    // Stock checks are shown by StockGuard (stock_guard.js) below the field.
    hint.textContent = '';

    if (qty > 0) {
        preview.style.display = '';
        document.getElementById('previewProduct').textContent = opt.text.split('(')[0].trim();
        document.getElementById('previewPrice').textContent   = '₱' + price.toLocaleString('en-PH', {minimumFractionDigits:2});
        document.getElementById('previewQty').textContent     = qty + ' ' + unit;
        document.getElementById('previewTotal').textContent   = '₱' + total.toLocaleString('en-PH', {minimumFractionDigits:2});
    } else {
        preview.style.display = 'none';
    }
}

/* Current on-hand stock per product, so a partial delivery can show
   what the stock will become once the undelivered units return. */
const PRODUCT_STOCK = <?= json_encode(array_column(load_products(), 'stock', 'id')) ?>;

const deliveryGuard = StockGuard.attachDelivery({
    form: 'deliverForm',
    quantity: 'deliverQty',
    submit: 'deliverSubmit'
});

function openDeliverModal(tx) {
    document.getElementById('deliverRef').value = tx.reference || '';
    document.getElementById('deliverQty').value = tx.quantity || 0;
    document.getElementById('deliverSummary').textContent =
        `Order: ${tx.reference} — ${tx.customer_name} | ${tx.quantity} x ${tx.product_name}`;

    // A delivery cannot happen before the order was placed.
    document.getElementById('deliverDate').min = (tx.timestamp || '').slice(0, 10);

    deliveryGuard.load({
        ordered: tx.quantity,
        unit:    tx.unit,
        name:    tx.product_name,
        stock:   PRODUCT_STOCK[tx.product_id] ?? 0
    });

    new bootstrap.Modal(document.getElementById('deliverModal')).show();
}

function viewTx(tx) {
    const status = tx.status || 'pending';
    const sc = status === 'delivered' ? '#166534' : (status === 'cancelled' ? '#991b1b' : '#854d0e');
    document.getElementById('viewModalBody').innerHTML = `
        <div style="display:grid;gap:10px;">
            <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #f1f5f9;">
                <span style="font-size:12px;color:#64748b;">Reference</span>
                <span style="font-family:monospace;font-weight:700;color:#1d4ed8;">${tx.reference || '—'}</span>
            </div>
            <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #f1f5f9;">
                <span style="font-size:12px;color:#64748b;">Date</span>
                <span style="font-size:12px;font-weight:600;">${(tx.timestamp || '—').substring(0,16)}</span>
            </div>
            <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #f1f5f9;">
                <span style="font-size:12px;color:#64748b;">Customer</span>
                <span style="font-size:12px;font-weight:600;">${tx.customer_name || '—'}</span>
            </div>
            <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #f1f5f9;">
                <span style="font-size:12px;color:#64748b;">PO Number</span>
                <span style="font-size:12px;">${tx.po_number || '—'}</span>
            </div>
            <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #f1f5f9;">
                <span style="font-size:12px;color:#64748b;">Product</span>
                <span style="font-size:12px;font-weight:600;">${tx.product_name || '—'}</span>
            </div>
            <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #f1f5f9;">
                <span style="font-size:12px;color:#64748b;">Quantity</span>
                <span style="font-size:12px;font-weight:600;">${tx.quantity || 0} ${tx.unit || ''}</span>
            </div>
            <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #f1f5f9;">
                <span style="font-size:12px;color:#64748b;">Unit Price</span>
                <span style="font-size:12px;">₱${parseFloat(tx.unit_price||0).toLocaleString('en-PH',{minimumFractionDigits:2})}</span>
            </div>
            <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #f1f5f9;">
                <span style="font-size:12px;color:#64748b;">Total</span>
                <span style="font-size:14px;font-weight:700;color:#22c55e;">₱${parseFloat(tx.total||0).toLocaleString('en-PH',{minimumFractionDigits:2})}</span>
            </div>
            <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #f1f5f9;">
                <span style="font-size:12px;color:#64748b;">Status</span>
                <span style="font-size:12px;font-weight:700;color:${sc};">${status}</span>
            </div>
            <div style="display:flex;justify-content:space-between;padding:8px 0;">
                <span style="font-size:12px;color:#64748b;">Processed By</span>
                <span style="font-size:12px;">${tx.processed_by || '—'}</span>
            </div>
        </div>`;
    new bootstrap.Modal(document.getElementById('viewModal')).show();
}

/* Search & filter */
document.getElementById('searchInput').addEventListener('input', filterTable);
document.getElementById('filterDate').addEventListener('change', filterTable);

function filterTable() {
    const q    = document.getElementById('searchInput').value.toLowerCase().trim();
    const date = document.getElementById('filterDate').value;
    const rows = Array.from(document.querySelectorAll('#salesTableBody tr'));
    let visible = 0;
    rows.forEach(r => {
        const mq = !q    || r.dataset.search.includes(q);
        const md = !date || r.dataset.date === date;
        r.style.display = mq && md ? '' : 'none';
        if (mq && md) visible++;
    });
    document.getElementById('salesEmpty').style.display = visible === 0 && rows.length > 0 ? '' : 'none';
}
</script>
<script><?= maexx_print_js() ?></script>
<script><?= maexx_notif_js() ?></script>
</body>
</html>