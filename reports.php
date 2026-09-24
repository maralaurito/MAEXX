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

// Only known values are accepted; anything else falls back to the default
// with a notice, instead of being echoed back or silently reinterpreted.
$notices = [];

$reportType = $_GET['type'] ?? 'sales';
if (!in_array($reportType, ['sales', 'inventory'], true)) {
    $reportType = 'sales';
    $notices[]  = 'Unknown report type — showing the Sales report.';
}

$period = $_GET['period'] ?? 'monthly';
if (!in_array($period, ['daily', 'monthly', 'yearly'], true)) {
    $period    = 'monthly';
    $notices[] = 'Unknown period — showing the monthly report.';
}

$dateStr = $_GET['date'] ?? date('Y-m-d');
$checked = v_date((string) $dateStr, 'Report date');
if (!$checked['ok']) {
    $notices[] = $checked['message'] . ' Showing today instead.';
    $dateStr   = date('Y-m-d');
}

$reportNotice = implode(' ', $notices);

$ts = strtotime($dateStr);

// Date range
if ($period === 'daily') {
    $from  = $dateStr;
    $to    = $dateStr;
    $label = date('F j, Y', $ts);
} elseif ($period === 'monthly') {
    $from  = date('Y-m-01', $ts);
    $to    = date('Y-m-t',  $ts);
    $label = date('F Y', $ts);
} else {
    $from  = date('Y-01-01', $ts);
    $to    = date('Y-12-31', $ts);
    $label = date('Y', $ts);
}

$transactions = load_transactions();
$products     = load_products();
$activeProducts = array_values(array_filter($products, fn($p) => empty($p['archived'])));

// Filter transactions in range
$filtered = array_values(array_filter($transactions, function($t) use ($from, $to) {
    $d = substr($t['timestamp'] ?? '', 0, 10);
    return $d >= $from && $d <= $to;
}));

/* ===== SALES REPORT DATA ===== */
$totalRevenue  = array_sum(array_column($filtered, 'total'));
$totalQty      = array_sum(array_column($filtered, 'quantity'));
$txCount       = count($filtered);
$delivered     = array_values(array_filter($filtered, fn($t) => ($t['status'] ?? '') === 'delivered'));
$pending       = array_values(array_filter($filtered, fn($t) => ($t['status'] ?? 'pending') === 'pending'));

// Top products by qty sold
$productQty = [];
foreach ($filtered as $t) {
    $pn = $t['product_name'] ?? 'Unknown';
    $productQty[$pn] = ($productQty[$pn] ?? 0) + intval($t['quantity']);
}
arsort($productQty);
$topProducts = array_slice($productQty, 0, 5, true);

// Sales by day (for chart)
$salesByDay = [];
foreach ($filtered as $t) {
    $day = substr($t['timestamp'] ?? '', 0, 10);
    $salesByDay[$day] = ($salesByDay[$day] ?? 0) + floatval($t['total']);
}
ksort($salesByDay);

// Daily summary (for monthly print breakdown)
$dailySummary = [];
foreach ($filtered as $t) {
    $day = substr($t['timestamp'] ?? '', 0, 10);
    if (!isset($dailySummary[$day])) $dailySummary[$day] = ['revenue' => 0, 'qty' => 0, 'count' => 0];
    $dailySummary[$day]['revenue'] += floatval($t['total']);
    $dailySummary[$day]['qty'] += intval($t['quantity']);
    $dailySummary[$day]['count']++;
}
ksort($dailySummary);

// Monthly summary (for yearly print breakdown)
$monthlySummary = [];
foreach ($filtered as $t) {
    $mk = date('Y-m', strtotime($t['timestamp'] ?? ''));
    $ml = date('F Y', strtotime($t['timestamp'] ?? ''));
    if (!isset($monthlySummary[$mk])) $monthlySummary[$mk] = ['label' => $ml, 'revenue' => 0, 'qty' => 0, 'count' => 0];
    $monthlySummary[$mk]['revenue'] += floatval($t['total']);
    $monthlySummary[$mk]['qty'] += intval($t['quantity']);
    $monthlySummary[$mk]['count']++;
}
ksort($monthlySummary);

/* ===== INVENTORY REPORT DATA ===== */
$lowStock   = array_values(array_filter($activeProducts, fn($p) => $p['stock'] < $p['threshold'] && $p['stock'] > 0));
$outOfStock = array_values(array_filter($activeProducts, fn($p) => $p['stock'] == 0));
$inStock    = array_values(array_filter($activeProducts, fn($p) => $p['stock'] >= $p['threshold']));

// Stock value
$totalStockValue = 0;
foreach ($activeProducts as $p) {
    $totalStockValue += floatval($p['cost'] ?? $p['price'] ?? 0) * intval($p['stock']);
}

// Category breakdown
$catBreakdown = [];
foreach ($activeProducts as $p) {
    $cat = $p['category'] ?? 'General';
    if (!isset($catBreakdown[$cat])) $catBreakdown[$cat] = ['count' => 0, 'stock' => 0];
    $catBreakdown[$cat]['count']++;
    $catBreakdown[$cat]['stock'] += intval($p['stock']);
}

$dashboardLink = $isAdmin ? 'dashboard_admin.php' : 'dashboard_inventory.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reports - MAEXX</title>
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
.topbar-right { display:flex;align-items:center;gap:12px; }
.user-pill { display:flex;align-items:center;gap:10px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:999px;padding:6px 14px 6px 8px;text-decoration:none;color:inherit;cursor:pointer;transition:background .2s,border-color .2s; }
.user-pill:hover { background:#f1f5f9;border-color:#cbd5e1; }
.user-avatar { width:30px;height:30px;background:#243f5f;color:#fff;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700; }
.user-info .name { font-size:12px;font-weight:600;color:#1e293b; }
.user-info .role { font-size:10px;color:#94a3b8; }

.page-content { padding:28px; }

/* FILTER BAR */
.filter-bar {
    background:#fff;border-radius:16px;padding:20px 24px;
    border:1px solid #e2e8f0;box-shadow:0 2px 8px rgba(0,0,0,.04);
    margin-bottom:24px;
    display:flex;gap:16px;align-items:flex-end;flex-wrap:wrap;
}
.filter-group { display:flex;flex-direction:column;gap:5px; }
.filter-group label { font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.5px; }
.form-control,.form-select { border-radius:10px;font-size:13px;padding:9px 13px;border:1.5px solid #e2e8f0;font-family:'Poppins',sans-serif;transition:border-color .2s; }
.form-control:focus,.form-select:focus { border-color:#243f5f;box-shadow:0 0 0 3px rgba(36,63,95,.08);outline:none; }

/* TYPE SWITCHER */
.type-switch { display:flex;gap:6px; }
.type-btn {
    padding:9px 18px;border-radius:10px;font-size:13px;font-weight:600;
    border:1.5px solid #e2e8f0;background:#f8fafc;color:#64748b;
    cursor:pointer;transition:all .2s;font-family:'Poppins',sans-serif;
    display:inline-flex;align-items:center;gap:6px;
}
.type-btn.active { background:#243f5f;color:#fff;border-color:#243f5f; }
.type-btn:hover:not(.active) { border-color:#243f5f;color:#243f5f; }

/* ACTION BTNS */
.btn-print  { background:#7c3aed;color:#fff;border:none;border-radius:10px;padding:9px 18px;font-size:13px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;font-family:'Poppins',sans-serif;transition:all .2s; }
.btn-export { background:#16a34a;color:#fff;border:none;border-radius:10px;padding:9px 18px;font-size:13px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;font-family:'Poppins',sans-serif;transition:all .2s; }
.btn-print:hover  { background:#6d28d9; }
.btn-export:hover { background:#15803d; }

/* STATS */
.stats-grid { display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:24px; }
.stat-card { background:#fff;border-radius:16px;padding:18px 20px;border:1px solid #e2e8f0;box-shadow:0 2px 8px rgba(0,0,0,.04);position:relative;overflow:hidden; }
.stat-card::before { content:'';position:absolute;top:0;left:0;right:0;height:4px; }
.sc-blue::before   { background:#3b82f6; }
.sc-green::before  { background:#22c55e; }
.sc-yellow::before { background:#eab308; }
.sc-purple::before { background:#7c3aed; }
.stat-icon { width:42px;height:42px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:18px;margin-bottom:12px; }
.si-blue   { background:#eff6ff;color:#3b82f6; }
.si-green  { background:#f0fdf4;color:#22c55e; }
.si-yellow { background:#fefce8;color:#ca8a04; }
.si-purple { background:#f5f3ff;color:#7c3aed; }
.stat-value { font-size:22px;font-weight:700;color:#1e293b;line-height:1; }
.stat-label { font-size:11px;color:#64748b;margin-top:4px; }

/* CARDS */
.content-row { display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px; }
.report-card { background:#fff;border-radius:18px;border:1px solid #e2e8f0;box-shadow:0 2px 10px rgba(0,0,0,.05);overflow:hidden;margin-bottom:20px; }
.card-head { padding:18px 22px;border-bottom:1px solid #f1f5f9;display:flex;justify-content:space-between;align-items:center; }
.card-head h5 { font-size:15px;font-weight:700;color:#1e293b;margin:0; }
.card-head p  { font-size:11px;color:#94a3b8;margin:3px 0 0; }

/* TABLE */
table { width:100%;border-collapse:collapse; }
thead th { font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:#94a3b8;padding:12px 16px;border-bottom:2px solid #f1f5f9;background:#f8fafc;text-align:left; }
tbody td { font-size:12px;color:#475569;padding:12px 16px;border-bottom:1px solid #f8fafc;vertical-align:middle; }
tbody tr:hover { background:#f8fafc; }
tbody tr:last-child td { border-bottom:none; }

.ref-chip { font-family:monospace;background:#eff6ff;color:#1d4ed8;padding:3px 8px;border-radius:6px;font-size:11px;font-weight:700; }
.badge-status { display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:999px;font-size:10px;font-weight:700; }
.bs-delivered { background:#dcfce7;color:#166534; }
.bs-pending   { background:#fef9c3;color:#854d0e; }
.bs-ok     { background:#dcfce7;color:#166534; }
.bs-low    { background:#fef9c3;color:#854d0e; }
.bs-out    { background:#fee2e2;color:#991b1b; }

/* CHART BAR */
.chart-wrap { padding:20px 22px; }
.bar-row { display:flex;align-items:center;gap:12px;margin-bottom:10px; }
.bar-label { font-size:11px;color:#64748b;width:90px;text-align:right;flex-shrink:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap; }
.bar-track { flex:1;height:28px;background:#f1f5f9;border-radius:8px;overflow:hidden; }
.bar-fill  { height:100%;border-radius:8px;display:flex;align-items:center;padding-left:10px;font-size:11px;font-weight:700;color:#fff;transition:width .6s; }
.bar-val   { font-size:11px;font-weight:700;color:#1e293b;width:80px;flex-shrink:0; }

/* REPORT PERIOD BADGE */
.period-badge {
    display:inline-flex;align-items:center;gap:6px;
    background:#eff6ff;color:#1d4ed8;
    padding:5px 12px;border-radius:999px;
    font-size:11px;font-weight:700;
}

/* EMPTY */
.empty-state { text-align:center;padding:50px 20px;color:#94a3b8; }
.empty-state i { font-size:48px;display:block;margin-bottom:12px; }
.empty-state .title { font-size:14px;font-weight:600;color:#64748b; }

<?= maexx_print_css() ?>
<?= maexx_notif_css() ?>
</style>
</head>
<body>

<!-- SIDEBAR -->
<div class="sidebar no-print">
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
        <a href="reports.php" class="active"><i class="bi bi-bar-chart-fill"></i> Reports</a>
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
    <div class="topbar no-print">
        <div class="topbar-left">
            <h4>Reports</h4>
            <p>Generate, view, and export inventory and sales reports</p>
        </div>
        <div class="topbar-right">
            <button class="btn-print" onclick="printCurrentReport()">
                <i class="bi bi-printer"></i> Print Report
            </button>
            <button class="btn-export" onclick="exportCSV()">
                <i class="bi bi-download"></i> Export CSV
            </button>
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

        <!-- PRINT HEADER -->
        <?= maexx_print_header_html(
            strtoupper($reportType) . ' REPORT',
            htmlspecialchars($label),
            htmlspecialchars($user['name'])
        ) ?>

        <!-- FILTER BAR -->
        <div class="filter-bar no-print">
            <div class="filter-group">
                <label>Report Type</label>
                <div class="type-switch">
                    <button class="type-btn <?= $reportType === 'sales' ? 'active' : '' ?>"
                        onclick="setFilter('type','sales')">
                        <i class="bi bi-cart-fill"></i> Sales
                    </button>
                    <button class="type-btn <?= $reportType === 'inventory' ? 'active' : '' ?>"
                        onclick="setFilter('type','inventory')">
                        <i class="bi bi-boxes"></i> Inventory
                    </button>
                </div>
            </div>

            <div class="filter-group">
                <label>Period</label>
                <div class="type-switch">
                    <button class="type-btn <?= $period === 'daily' ? 'active' : '' ?>" onclick="setFilter('period','daily')">Daily</button>
                    <button class="type-btn <?= $period === 'monthly' ? 'active' : '' ?>" onclick="setFilter('period','monthly')">Monthly</button>
                    <button class="type-btn <?= $period === 'yearly' ? 'active' : '' ?>" onclick="setFilter('period','yearly')">Yearly</button>
                </div>
            </div>
            <div class="filter-group">
                <label>Date</label>
                <input type="date" class="form-control" id="filterDate" value="<?= htmlspecialchars($dateStr) ?>" max="<?= date('Y-m-d') ?>" onchange="if (this.value && this.value <= this.max) setFilter('date', this.value)" style="width:160px;">
            </div>

            <div style="margin-left:auto;" class="period-badge">
                <i class="bi bi-calendar3"></i>
                <?= htmlspecialchars($label) ?>
            </div>
        </div>

        <?php if ($reportNotice !== ''): ?>
        <div class="no-print" style="background:#fffbeb;border:1px solid #fde68a;color:#92400e;border-radius:12px;padding:10px 14px;font-size:13px;margin-bottom:16px;">
            <i class="bi bi-exclamation-triangle-fill me-1"></i><?= htmlspecialchars($reportNotice) ?>
        </div>
        <?php endif; ?>

        <?php if ($reportType === 'sales'): ?>
        <!-- ===== SALES REPORT ===== -->

        <!-- STATS -->
        <div class="stats-grid">
            <div class="stat-card sc-blue">
                <div class="stat-icon si-blue"><i class="bi bi-currency-dollar"></i></div>
                <div class="stat-value">₱<?= number_format($totalRevenue, 2) ?></div>
                <div class="stat-label">Total Revenue</div>
            </div>
            <div class="stat-card sc-green">
                <div class="stat-icon si-green"><i class="bi bi-receipt"></i></div>
                <div class="stat-value"><?= $txCount ?></div>
                <div class="stat-label">Total Transactions</div>
            </div>
            <div class="stat-card sc-yellow">
                <div class="stat-icon si-yellow"><i class="bi bi-box-seam"></i></div>
                <div class="stat-value"><?= number_format($totalQty) ?></div>
                <div class="stat-label">Total Qty Sold</div>
            </div>
            <div class="stat-card sc-purple">
                <div class="stat-icon si-purple"><i class="bi bi-check-circle-fill"></i></div>
                <div class="stat-value"><?= count($delivered) ?></div>
                <div class="stat-label">Delivered Orders</div>
            </div>
        </div>

        <!-- TOP PRODUCTS + DAILY BREAKDOWN -->
        <div class="content-row">

            <!-- TOP PRODUCTS CHART -->
            <div class="report-card">
                <div class="card-head">
                    <div>
                        <h5>Top Products by Qty Sold</h5>
                        <p>Most ordered products this period</p>
                    </div>
                </div>
                <?php if (empty($topProducts)): ?>
                <div class="empty-state"><i class="bi bi-bar-chart"></i><div class="title">No data for this period</div></div>
                <?php else: ?>
                <div class="chart-wrap">
                    <?php
                    $maxQty = max(array_values($topProducts)) ?: 1;
                    $colors = ['#3b82f6','#22c55e','#f59e0b','#8b5cf6','#ef4444'];
                    $i = 0;
                    foreach ($topProducts as $name => $qty):
                        $pct = round(($qty / $maxQty) * 100);
                    ?>
                    <div class="bar-row">
                        <div class="bar-label" title="<?= htmlspecialchars($name) ?>"><?= htmlspecialchars(strlen($name) > 12 ? substr($name,0,12).'…' : $name) ?></div>
                        <div class="bar-track">
                            <div class="bar-fill" style="width:<?= $pct ?>%;background:<?= $colors[$i % 5] ?>;"><?= $qty ?></div>
                        </div>
                        <div class="bar-val"><?= number_format($qty) ?> units</div>
                    </div>
                    <?php $i++; endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- DAILY SALES CHART -->
            <div class="report-card">
                <div class="card-head">
                    <div>
                        <h5>Sales by Date</h5>
                        <p>Revenue breakdown per day</p>
                    </div>
                </div>
                <?php if (empty($salesByDay)): ?>
                <div class="empty-state"><i class="bi bi-graph-up"></i><div class="title">No data for this period</div></div>
                <?php else: ?>
                <div class="chart-wrap">
                    <?php
                    $maxRev = max(array_values($salesByDay)) ?: 1;
                    foreach ($salesByDay as $day => $rev):
                        $pct = round(($rev / $maxRev) * 100);
                        $shortDay = date('M j', strtotime($day));
                    ?>
                    <div class="bar-row">
                        <div class="bar-label"><?= $shortDay ?></div>
                        <div class="bar-track">
                            <div class="bar-fill" style="width:<?= $pct ?>%;background:#22c55e;">₱<?= number_format($rev, 0) ?></div>
                        </div>
                        <div class="bar-val" style="font-size:10px;">₱<?= number_format($rev, 0) ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

        </div>

        <!-- FULL TRANSACTION TABLE -->
        <div class="report-card">
            <div class="card-head">
                <div>
                    <h5>Sales Transaction Details</h5>
                    <p><?= $txCount ?> transaction(s) for <?= htmlspecialchars($label) ?></p>
                </div>
            </div>
            <?php if (empty($filtered)): ?>
            <div class="empty-state">
                <i class="bi bi-receipt"></i>
                <div class="title">No transactions found for this period</div>
            </div>
            <?php else: ?>
            <div style="overflow-x:auto;">
                <table id="salesReportTable">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Reference</th>
                            <th>Date</th>
                            <th>Customer</th>
                            <th>PO No.</th>
                            <th>Product</th>
                            <th>Qty</th>
                            <th>Unit Price</th>
                            <th>Total</th>
                            <th>Status</th>
                            <th>Processed By</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php $grandTotal = 0; $row = 1;
                    foreach ($filtered as $tx):
                        $grandTotal += floatval($tx['total'] ?? 0);
                        $status = $tx['status'] ?? 'pending';
                        $sc = $status === 'delivered' ? 'bs-delivered' : 'bs-pending';
                    ?>
                    <tr>
                        <td style="color:#94a3b8;font-size:11px;"><?= $row++ ?></td>
                        <td><span class="ref-chip"><?= htmlspecialchars($tx['reference'] ?? '—') ?></span></td>
                        <td style="font-size:11px;white-space:nowrap;"><?= htmlspecialchars(substr($tx['timestamp'] ?? '—', 0, 16)) ?></td>
                        <td style="font-weight:600;color:#1e293b;"><?= htmlspecialchars($tx['customer_name'] ?? '—') ?></td>
                        <td style="font-size:11px;color:#94a3b8;"><?= htmlspecialchars($tx['po_number'] ?? '—') ?></td>
                        <td><?= htmlspecialchars($tx['product_name'] ?? '—') ?></td>
                        <td><?= intval($tx['quantity'] ?? 0) ?> <?= htmlspecialchars($tx['unit'] ?? '') ?></td>
                        <td>₱<?= number_format($tx['unit_price'] ?? 0, 2) ?></td>
                        <td style="font-weight:700;color:#22c55e;">₱<?= number_format($tx['total'] ?? 0, 2) ?></td>
                        <td><span class="badge-status <?= $sc ?>"><?= htmlspecialchars(ucfirst($status)) ?></span></td>
                        <td style="font-size:11px;color:#94a3b8;"><?= htmlspecialchars($tx['processed_by'] ?? '—') ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr style="background:#f8fafc;">
                            <td colspan="8" style="text-align:right;font-weight:700;font-size:13px;padding:14px 16px;">GRAND TOTAL</td>
                            <td style="font-weight:700;font-size:14px;color:#22c55e;padding:14px 16px;">₱<?= number_format($grandTotal, 2) ?></td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <?php else: ?>
        <!-- ===== INVENTORY REPORT ===== -->

        <!-- STATS -->
        <div class="stats-grid">
            <div class="stat-card sc-blue">
                <div class="stat-icon si-blue"><i class="bi bi-boxes"></i></div>
                <div class="stat-value"><?= count($activeProducts) ?></div>
                <div class="stat-label">Total Active Products</div>
            </div>
            <div class="stat-card sc-green">
                <div class="stat-icon si-green"><i class="bi bi-check-circle-fill"></i></div>
                <div class="stat-value"><?= count($inStock) ?></div>
                <div class="stat-label">In Stock</div>
            </div>
            <div class="stat-card sc-yellow">
                <div class="stat-icon si-yellow"><i class="bi bi-exclamation-triangle-fill"></i></div>
                <div class="stat-value"><?= count($lowStock) ?></div>
                <div class="stat-label">Low Stock Items</div>
            </div>
            <div class="stat-card sc-purple">
                <div class="stat-icon si-purple"><i class="bi bi-currency-dollar"></i></div>
                <div class="stat-value">₱<?= number_format($totalStockValue, 0) ?></div>
                <div class="stat-label">Est. Stock Value</div>
            </div>
        </div>

        <!-- CATEGORY BREAKDOWN + LOW STOCK -->
        <div class="content-row">

            <!-- CATEGORY CHART -->
            <div class="report-card">
                <div class="card-head">
                    <div>
                        <h5>Stock by Category</h5>
                        <p>Total units per product category</p>
                    </div>
                </div>
                <?php if (empty($catBreakdown)): ?>
                <div class="empty-state"><i class="bi bi-bar-chart"></i><div class="title">No categories found</div></div>
                <?php else: ?>
                <div class="chart-wrap">
                    <?php
                    $maxStock = max(array_column($catBreakdown, 'stock')) ?: 1;
                    $colors   = ['#3b82f6','#22c55e','#f59e0b','#8b5cf6','#ef4444','#06b6d4','#ec4899'];
                    $ci = 0;
                    foreach ($catBreakdown as $cat => $data):
                        $pct = round(($data['stock'] / $maxStock) * 100);
                    ?>
                    <div class="bar-row">
                        <div class="bar-label" title="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars(strlen($cat) > 12 ? substr($cat,0,12).'…' : $cat) ?></div>
                        <div class="bar-track">
                            <div class="bar-fill" style="width:<?= max($pct,5) ?>%;background:<?= $colors[$ci % 7] ?>;"><?= $data['stock'] ?></div>
                        </div>
                        <div class="bar-val"><?= number_format($data['stock']) ?> units</div>
                    </div>
                    <?php $ci++; endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- LOW STOCK ALERT LIST -->
            <div class="report-card">
                <div class="card-head">
                    <div>
                        <h5>Stock Alerts</h5>
                        <p>Items needing immediate attention</p>
                    </div>
                </div>
                <?php if (empty($lowStock) && empty($outOfStock)): ?>
                <div class="empty-state">
                    <i class="bi bi-check-circle-fill" style="color:#22c55e;"></i>
                    <div class="title">All stocks are healthy!</div>
                </div>
                <?php else: ?>
                <div style="overflow-x:auto;">
                    <table id="stockAlertsTable">
                        <thead>
                            <tr><th>Product</th><th>Category</th><th>Current Stock</th><th>Min Level</th><th>Status</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($outOfStock as $p): ?>
                        <tr>
                            <td style="font-weight:600;color:#1e293b;"><?= htmlspecialchars($p['name']) ?></td>
                            <td><?= htmlspecialchars($p['category'] ?? '—') ?></td>
                            <td style="font-weight:700;color:#dc2626;">0</td>
                            <td><?= $p['threshold'] ?></td>
                            <td><span class="badge-status bs-out"><i class="bi bi-x-circle"></i> Out of Stock</span></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php foreach ($lowStock as $p): ?>
                        <tr>
                            <td style="font-weight:600;color:#1e293b;"><?= htmlspecialchars($p['name']) ?></td>
                            <td><?= htmlspecialchars($p['category'] ?? '—') ?></td>
                            <td style="font-weight:700;color:#ca8a04;"><?= $p['stock'] ?></td>
                            <td><?= $p['threshold'] ?></td>
                            <td><span class="badge-status bs-low"><i class="bi bi-exclamation-triangle"></i> Low Stock</span></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

        </div>

        <!-- FULL INVENTORY TABLE -->
        <div class="report-card">
            <div class="card-head">
                <div>
                    <h5>Full Inventory List</h5>
                    <p>All active products and their current stock levels</p>
                </div>
            </div>
            <div style="overflow-x:auto;">
                <table id="inventoryReportTable">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>SKU</th>
                            <th>Product Name</th>
                            <th>Category</th>
                            <th>Unit</th>
                            <th>Min Stock Level</th>
                            <th>Current Stock</th>
                            <th>Est. Value</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php $row = 1; foreach ($activeProducts as $p):
                        if ($p['stock'] == 0) { $sc = 'bs-out'; $sl = 'Out of Stock'; }
                        elseif ($p['stock'] < $p['threshold']) { $sc = 'bs-low'; $sl = 'Low Stock'; }
                        else { $sc = 'bs-ok'; $sl = 'In Stock'; }
                        $val  = floatval($p['cost'] ?? $p['price'] ?? 0) * intval($p['stock']);
                        $sku  = 'SKU-' . str_pad($p['id'], 4, '0', STR_PAD_LEFT);
                    ?>
                    <tr>
                        <td style="color:#94a3b8;font-size:11px;"><?= $row++ ?></td>
                        <td style="font-family:monospace;font-size:11px;background:#f1f5f9;border-radius:4px;padding:3px 6px;"><?= $sku ?></td>
                        <td style="font-weight:600;color:#1e293b;"><?= htmlspecialchars($p['name']) ?></td>
                        <td><?= htmlspecialchars($p['category'] ?? 'General') ?></td>
                        <td><?= htmlspecialchars($p['unit'] ?? 'pcs') ?></td>
                        <td style="font-weight:600;color:#243f5f;"><?= intval($p['threshold']) ?></td>
                        <td style="font-weight:700;color:<?= $p['stock'] < $p['threshold'] ? '#ef4444' : '#22c55e' ?>;">
                            <?= intval($p['stock']) ?> <?= htmlspecialchars($p['unit'] ?? '') ?>
                        </td>
                        <td>₱<?= number_format($val, 2) ?></td>
                        <td><span class="badge-status <?= $sc ?>"><?= $sl ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr style="background:#f8fafc;">
                            <td colspan="7" style="text-align:right;font-weight:700;font-size:13px;padding:14px 16px;">TOTAL EST. STOCK VALUE</td>
                            <td style="font-weight:700;font-size:14px;color:#22c55e;padding:14px 16px;">₱<?= number_format($totalStockValue, 2) ?></td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <?= maexx_print_footer_html(htmlspecialchars($user['name'])) ?>

    </div><!-- end page-content -->
</div><!-- end main-wrapper -->

<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script>
function setFilter(key, val) {
    const params = new URLSearchParams(window.location.search);
    params.set(key, val);
    window.location.href = 'reports.php?' + params.toString();
}

function exportCSV() {
    const type = '<?= $reportType ?>';
    let csv = '', filename = '';

    if (type === 'sales') {
        filename = 'sales_report_<?= $label ?>.csv';
        csv = 'Reference,Date,Customer,PO No.,Product,Qty,Unit Price,Total,Status,Processed By\n';
        <?php foreach ($filtered as $tx): ?>
        csv += `"<?= addslashes($tx['reference'] ?? '') ?>","<?= addslashes(substr($tx['timestamp'] ?? '', 0, 16)) ?>","<?= addslashes($tx['customer_name'] ?? '') ?>","<?= addslashes($tx['po_number'] ?? '') ?>","<?= addslashes($tx['product_name'] ?? '') ?>",<?= intval($tx['quantity'] ?? 0) ?>,<?= floatval($tx['unit_price'] ?? 0) ?>,<?= floatval($tx['total'] ?? 0) ?>,"<?= addslashes($tx['status'] ?? 'Pending') ?>","<?= addslashes($tx['processed_by'] ?? '') ?>"\n`;
        <?php endforeach; ?>
    } else {
        filename = 'inventory_report_<?= date('Y-m-d') ?>.csv';
        csv = 'SKU,Product Name,Category,Unit,Min Level,Current Stock,Est. Value,Status\n';
        <?php foreach ($activeProducts as $p):
            if ($p['stock'] == 0) $sl = 'Out of Stock';
            elseif ($p['stock'] < $p['threshold']) $sl = 'Low Stock';
            else $sl = 'In Stock';
            $val = floatval($p['cost'] ?? $p['price'] ?? 0) * intval($p['stock']);
            $sku = 'SKU-' . str_pad($p['id'], 4, '0', STR_PAD_LEFT);
        ?>
        csv += `"<?= $sku ?>","<?= addslashes($p['name']) ?>","<?= addslashes($p['category'] ?? '') ?>","<?= addslashes($p['unit'] ?? '') ?>",<?= intval($p['threshold']) ?>,<?= intval($p['stock']) ?>,<?= number_format($val, 2) ?>,"<?= $sl ?>"\n`;
        <?php endforeach; ?>
    }

    const blob = new Blob([csv], { type: 'text/csv' });
    const url  = URL.createObjectURL(blob);
    const a    = document.createElement('a');
    a.href     = url;
    a.download = filename.replace(/[^a-z0-9_\-.]/gi, '_');
    a.click();
    URL.revokeObjectURL(url);
}

const rptData = {
    type: <?= json_encode($reportType) ?>,
    period: <?= json_encode($period) ?>,
    label: <?= json_encode($label) ?>,
    totalRevenue: <?= json_encode($totalRevenue) ?>,
    totalQty: <?= json_encode($totalQty) ?>,
    txCount: <?= json_encode($txCount) ?>,
    deliveredCount: <?= json_encode(count($delivered)) ?>,
    pendingCount: <?= json_encode(count($pending)) ?>,
    topProducts: <?= json_encode($topProducts, JSON_FORCE_OBJECT) ?>,
    dailySummary: <?= json_encode($dailySummary, JSON_FORCE_OBJECT) ?>,
    monthlySummary: <?= json_encode(array_values($monthlySummary)) ?>,
    activeCount: <?= json_encode(count($activeProducts)) ?>,
    inStockCount: <?= json_encode(count($inStock)) ?>,
    lowStockCount: <?= json_encode(count($lowStock)) ?>,
    outOfStockCount: <?= json_encode(count($outOfStock)) ?>,
    totalStockValue: <?= json_encode($totalStockValue) ?>
};

function printCurrentReport() {
    const d = rptData;
    const now = new Date();
    const dateStr = now.toLocaleDateString('en-US', {year:'numeric',month:'long',day:'numeric'});
    const timeStr = now.toLocaleTimeString('en-US', {hour:'2-digit',minute:'2-digit'});
    const preparedBy = document.querySelector('.user-info .name')?.textContent || 'Administrator';

    let title;
    if (d.type === 'sales') {
        if (d.period === 'daily') title = 'DAILY SALES REPORT';
        else if (d.period === 'monthly') title = 'MONTHLY SALES REPORT';
        else title = 'ANNUAL SALES REPORT';
    } else {
        title = 'INVENTORY STATUS REPORT';
    }

    let stats;
    if (d.type === 'sales') {
        stats = [
            {label:'Total Revenue', value:'₱'+Number(d.totalRevenue).toLocaleString('en-US',{minimumFractionDigits:2}), color:'#3b82f6'},
            {label:'Transactions', value:d.txCount, color:'#22c55e'},
            {label:'Qty Sold', value:Number(d.totalQty).toLocaleString(), color:'#f59e0b'},
            {label:'Delivered', value:d.deliveredCount, color:'#16a34a'},
            {label:'Pending', value:d.pendingCount, color:'#ef4444'}
        ];
    } else {
        stats = [
            {label:'Active Products', value:d.activeCount, color:'#3b82f6'},
            {label:'In Stock', value:d.inStockCount, color:'#22c55e'},
            {label:'Low Stock', value:d.lowStockCount, color:'#f59e0b'},
            {label:'Out of Stock', value:d.outOfStockCount, color:'#ef4444'},
            {label:'Est. Stock Value', value:'₱'+Number(d.totalStockValue).toLocaleString('en-US',{minimumFractionDigits:2}), color:'#7c3aed'}
        ];
    }

    let statsHtml = '<div style="display:flex;gap:10px;margin-bottom:22px;">';
    stats.forEach(s => {
        statsHtml += '<div style="flex:1;border:2px solid '+s.color+';border-radius:10px;padding:12px 10px;text-align:center;">'
            +'<div style="font-size:18px;font-weight:800;color:'+s.color+';">'+s.value+'</div>'
            +'<div style="font-size:8px;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-top:3px;">'+s.label+'</div>'
            +'</div>';
    });
    statsHtml += '</div>';

    let breakdownHtml = '';
    if (d.type === 'sales') {
        if (d.period === 'monthly' && Object.keys(d.dailySummary).length > 0) {
            breakdownHtml += '<div style="margin-bottom:22px;">'
                +'<div class="section-title">Daily Sales Breakdown</div>'
                +'<table><thead><tr><th>Date</th><th style="text-align:center;">Transactions</th><th style="text-align:center;">Qty Sold</th><th style="text-align:right;">Revenue</th></tr></thead><tbody>';
            let tR=0,tT=0,tQ=0;
            Object.entries(d.dailySummary).forEach(([date, info]) => {
                tR+=info.revenue; tT+=info.count; tQ+=info.qty;
                const dt = new Date(date+'T00:00:00');
                breakdownHtml += '<tr>'
                    +'<td>'+dt.toLocaleDateString('en-US',{weekday:'short',month:'short',day:'numeric',year:'numeric'})+'</td>'
                    +'<td style="text-align:center;">'+info.count+'</td>'
                    +'<td style="text-align:center;">'+Number(info.qty).toLocaleString()+'</td>'
                    +'<td style="text-align:right;font-weight:600;">₱'+Number(info.revenue).toLocaleString('en-US',{minimumFractionDigits:2})+'</td>'
                    +'</tr>';
            });
            breakdownHtml += '</tbody><tfoot><tr>'
                +'<td style="font-weight:700;">TOTAL</td>'
                +'<td style="text-align:center;font-weight:700;">'+tT+'</td>'
                +'<td style="text-align:center;font-weight:700;">'+Number(tQ).toLocaleString()+'</td>'
                +'<td style="text-align:right;font-weight:700;">₱'+Number(tR).toLocaleString('en-US',{minimumFractionDigits:2})+'</td>'
                +'</tr></tfoot></table></div>';
        }

        if (d.period === 'yearly' && d.monthlySummary.length > 0) {
            breakdownHtml += '<div style="margin-bottom:22px;">'
                +'<div class="section-title">Monthly Sales Breakdown</div>'
                +'<table><thead><tr><th>Month</th><th style="text-align:center;">Transactions</th><th style="text-align:center;">Qty Sold</th><th style="text-align:right;">Revenue</th></tr></thead><tbody>';
            let tR=0,tT=0,tQ=0;
            d.monthlySummary.forEach(m => {
                tR+=m.revenue; tT+=m.count; tQ+=m.qty;
                breakdownHtml += '<tr>'
                    +'<td>'+m.label+'</td>'
                    +'<td style="text-align:center;">'+m.count+'</td>'
                    +'<td style="text-align:center;">'+Number(m.qty).toLocaleString()+'</td>'
                    +'<td style="text-align:right;font-weight:600;">₱'+Number(m.revenue).toLocaleString('en-US',{minimumFractionDigits:2})+'</td>'
                    +'</tr>';
            });
            breakdownHtml += '</tbody><tfoot><tr>'
                +'<td style="font-weight:700;">TOTAL</td>'
                +'<td style="text-align:center;font-weight:700;">'+tT+'</td>'
                +'<td style="text-align:center;font-weight:700;">'+Number(tQ).toLocaleString()+'</td>'
                +'<td style="text-align:right;font-weight:700;">₱'+Number(tR).toLocaleString('en-US',{minimumFractionDigits:2})+'</td>'
                +'</tr></tfoot></table></div>';
        }

        if (d.period !== 'daily' && Object.keys(d.topProducts).length > 0) {
            breakdownHtml += '<div style="margin-bottom:22px;">'
                +'<div class="section-title">Top Products by Quantity Sold</div>'
                +'<table><thead><tr><th style="width:40px;">#</th><th>Product Name</th><th style="text-align:right;">Qty Sold</th></tr></thead><tbody>';
            let rank = 1;
            Object.entries(d.topProducts).forEach(([name, qty]) => {
                breakdownHtml += '<tr>'
                    +'<td style="color:#94a3b8;">'+rank+++'</td>'
                    +'<td style="font-weight:600;">'+name+'</td>'
                    +'<td style="text-align:right;">'+Number(qty).toLocaleString()+' units</td>'
                    +'</tr>';
            });
            breakdownHtml += '</tbody></table></div>';
        }
    }

    let tableHtml = '';
    if (d.type === 'sales') {
        const tbl = document.getElementById('salesReportTable');
        if (tbl) {
            const c = tbl.cloneNode(true);
            c.removeAttribute('id');
            c.querySelectorAll('.no-print,.action-col').forEach(el => el.remove());
            tableHtml = '<div style="margin-bottom:20px;">'
                +'<div class="section-title">Transaction Details</div>'
                +c.outerHTML+'</div>';
        }
    } else {
        const alertsTbl = document.getElementById('stockAlertsTable');
        if (alertsTbl) {
            const c = alertsTbl.cloneNode(true);
            c.removeAttribute('id');
            c.querySelectorAll('.no-print,.action-col').forEach(el => el.remove());
            tableHtml += '<div style="margin-bottom:20px;">'
                +'<div class="section-title">Stock Alerts</div>'
                +c.outerHTML+'</div>';
        }
        const invTbl = document.getElementById('inventoryReportTable');
        if (invTbl) {
            const c = invTbl.cloneNode(true);
            c.removeAttribute('id');
            c.querySelectorAll('.no-print,.action-col').forEach(el => el.remove());
            tableHtml += '<div style="margin-bottom:20px;">'
                +'<div class="section-title">Complete Inventory List</div>'
                +c.outerHTML+'</div>';
        }
    }

    const html = '<!DOCTYPE html>'
+'<html><head>'
+'<meta charset="UTF-8">'
+'<title>'+title+' - MAEXX2 Enterprises</title>'
+'<style>'
+'@page { size: A4 portrait; margin: 15mm 12mm; }'
+'* { box-sizing:border-box; margin:0; padding:0; }'
+'body { font-family: Arial, "Helvetica Neue", sans-serif; font-size:11px; color:#1e293b; padding:0; }'
+'.header { margin-bottom:16px; }'
+'.header-top { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:6px; }'
+'.company-name { font-size:20px; font-weight:800; color:#243f5f; margin-bottom:4px; letter-spacing:-0.5px; }'
+'.company-details { font-size:9px; color:#64748b; line-height:1.7; }'
+'.logo { width:70px; height:70px; object-fit:contain; }'
+'.sep { border:none; border-top:3px solid #243f5f; margin:10px 0; }'
+'.meta-row { display:flex; justify-content:space-between; align-items:center; margin-bottom:4px; }'
+'.meta { font-size:10px; color:#475569; }'
+'.meta strong { color:#1e293b; }'
+'.report-title { font-size:16px; font-weight:800; color:#243f5f; text-transform:uppercase; margin:10px 0 6px; letter-spacing:0.5px; border-bottom:2px solid #e2e8f0; padding-bottom:8px; }'
+'.period-tag { display:inline-block; background:#eff6ff; color:#1d4ed8; padding:4px 14px; border-radius:999px; font-size:10px; font-weight:700; margin-bottom:16px; }'
+'.section-title { font-size:12px; font-weight:700; color:#243f5f; margin-bottom:8px; text-transform:uppercase; letter-spacing:0.5px; padding-bottom:4px; border-bottom:1px solid #e2e8f0; }'
+'table { width:100%; border-collapse:collapse; margin-bottom:16px; font-size:10px; }'
+'th { background:#243f5f; color:#fff; font-size:8px; font-weight:700; text-transform:uppercase; letter-spacing:0.8px; padding:8px 8px; text-align:left; }'
+'td { padding:6px 8px; border-bottom:1px solid #e2e8f0; color:#475569; }'
+'tr:nth-child(even) { background:#f8fafc; }'
+'tfoot td { font-weight:700; border-top:2px solid #243f5f; background:#f1f5f9; color:#1e293b; }'
+'.badge-status { display:inline-block; padding:2px 8px; border-radius:999px; font-size:9px; font-weight:700; }'
+'.bs-delivered { background:#dcfce7; color:#166534; }'
+'.bs-pending { background:#fef9c3; color:#854d0e; }'
+'.bs-ok { background:#dcfce7; color:#166534; }'
+'.bs-low { background:#fef9c3; color:#854d0e; }'
+'.bs-out { background:#fee2e2; color:#991b1b; }'
+'.ref-chip { font-family:monospace; background:#eff6ff; color:#1d4ed8; padding:2px 6px; border-radius:4px; font-size:9px; font-weight:700; }'
+'.footer { margin-top:50px; page-break-inside:avoid; }'
+'.prepared { font-size:10px; color:#64748b; }'
+'.sig-line { width:220px; border-top:2px solid #243f5f; margin-top:40px; padding-top:6px; font-size:11px; font-weight:700; color:#243f5f; }'
+'</style>'
+'</head><body>'
+'<div class="header">'
+'<div class="header-top">'
+'<div>'
+'<div class="company-name">MAEXX2 ENTERPRISES INC.</div>'
+'<div class="company-details">'
+'Inventory &amp; Sales Monitoring System<br>'
+'<strong>Email:</strong> maexx2enterprises@gmail.com<br>'
+'<strong>Generated:</strong> '+dateStr+' at '+timeStr
+'</div></div>'
+'<img src="img/LOGO.png" class="logo" onerror="this.style.display=\'none\'">'
+'</div>'
+'<hr class="sep">'
+'<div class="meta-row">'
+'<span class="meta"><strong>DATE:</strong> '+dateStr+'</span>'
+'<span class="meta"><strong>PERIOD:</strong> '+d.label+'</span>'
+'</div>'
+'<div class="report-title">'+title+'</div>'
+'<span class="period-tag">'+d.period.charAt(0).toUpperCase()+d.period.slice(1)+' Report &mdash; '+d.label+'</span>'
+'</div>'
+statsHtml
+breakdownHtml
+tableHtml
+'<div class="footer">'
+'<div class="prepared">Prepared by:</div>'
+'<div class="sig-line">'+preparedBy+'</div>'
+'</div>'
+'<script>window.onload=function(){window.print();}<\/script>'
+'</body></html>';

    const w = window.open('', '_blank');
    if (!w) { alert('Please allow popups for this site to print the report.'); return; }
    w.document.write(html);
    w.document.close();
}
</script>
<script><?= maexx_print_js() ?></script>
<script><?= maexx_notif_js() ?></script>
</body>
</html>