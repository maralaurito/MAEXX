<?php
require 'auth.php';
require 'notifications.php';
require_role('Product Inventory');
$user = get_logged_in_user();
$products = load_products();
$transactions = load_transactions();

// Today's stats
$today = date('Y-m-d');
$todayTx = array_filter($transactions, fn($t) => substr($t['timestamp'] ?? '', 0, 10) === $today);
$todaySales = array_sum(array_column(array_values($todayTx), 'total'));
$todayCount = count($todayTx);

// Low stock
$lowStockProducts = array_values(array_filter($products, fn($p) => empty($p['archived']) && $p['stock'] < $p['threshold']));
usort($lowStockProducts, fn($a, $b) => $a['stock'] - $b['stock']);
$lowStockCount = count($lowStockProducts);
$outOfStock = count(array_filter($products, fn($p) => empty($p['archived']) && $p['stock'] == 0));

// Recent transactions
$recentTx = array_slice(array_reverse($transactions), 0, 5);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Staff Dashboard - MAEXX</title>
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

.sidebar-brand-name {
    font-size: 12px;
    font-weight: 700;
    color: #fff;
    line-height: 1.5;
}

.sidebar-brand-sub {
    font-size: 10px;
    color: rgba(255,255,255,0.5);
    margin-top: 2px;
}

.sidebar-nav {
    flex: 1;
    padding: 20px 12px;
}

.nav-label {
    font-size: 9px;
    font-weight: 700;
    letter-spacing: 1.5px;
    color: rgba(255,255,255,0.35);
    text-transform: uppercase;
    padding: 0 10px;
    margin: 16px 0 6px;
}

.sidebar-nav a {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 11px 14px;
    color: rgba(255,255,255,0.65);
    text-decoration: none;
    font-size: 13px;
    font-weight: 500;
    border-radius: 12px;
    margin-bottom: 2px;
    transition: all 0.2s;
}

.sidebar-nav a i { font-size: 17px; width: 20px; text-align: center; }

.sidebar-nav a:hover { background: rgba(255,255,255,0.1); color: #fff; }
.sidebar-nav a.active { background: rgba(255,255,255,0.15); color: #fff; font-weight: 600; }

.sidebar-nav a .badge-count {
    margin-left: auto;
    background: #e11d48;
    color: #fff;
    font-size: 10px;
    font-weight: 700;
    padding: 2px 7px;
    border-radius: 999px;
}

.sidebar-footer {
    padding: 16px 12px;
    border-top: 1px solid rgba(255,255,255,0.08);
}

.sidebar-footer a {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 11px 14px;
    color: rgba(255,255,255,0.6);
    text-decoration: none;
    font-size: 13px;
    border-radius: 12px;
    transition: all 0.2s;
}

.sidebar-footer a:hover { background: rgba(255,255,255,0.1); color: #fff; }

/* MAIN */
.main-wrapper {
    margin-left: 260px;
    min-height: 100vh;
    display: flex;
    flex-direction: column;
}

/* TOPBAR */
.topbar {
    background: #fff;
    padding: 16px 28px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-bottom: 1px solid #e2e8f0;
    position: sticky;
    top: 0;
    z-index: 50;
    box-shadow: 0 2px 8px rgba(0,0,0,0.04);
}

.topbar-left h4 { font-size: 18px; font-weight: 700; color: #1e293b; margin: 0; }
.topbar-left p  { font-size: 12px; color: #94a3b8; margin: 2px 0 0; }

.topbar-right { display: flex; align-items: center; gap: 16px; }

.topbar-date {
    font-size: 12px;
    color: #64748b;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    padding: 6px 14px;
    border-radius: 999px;
}

.user-pill {
    display: flex;
    align-items: center;
    gap: 10px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 999px;
    padding: 6px 14px 6px 8px;
    text-decoration: none;
    color: inherit;
    cursor: pointer;
    transition: background .2s, border-color .2s;
}
.user-pill:hover { background: #f1f5f9; border-color: #cbd5e1; }

.user-avatar {
    width: 30px; height: 30px;
    background: #243f5f;
    color: #fff;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 13px;
    font-weight: 700;
}

.user-info .name { font-size: 12px; font-weight: 600; color: #1e293b; }
.user-info .role { font-size: 10px; color: #94a3b8; }

/* PAGE CONTENT */
.page-content { padding: 28px; }

/* WELCOME BANNER */
.welcome-banner {
    background: linear-gradient(135deg, #243f5f, #1a2f47);
    border-radius: 18px;
    padding: 24px 28px;
    color: #fff;
    margin-bottom: 24px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.wb-left h3 { font-size: 20px; font-weight: 700; margin-bottom: 4px; }
.wb-left p  { font-size: 13px; color: rgba(255,255,255,0.7); margin: 0; }
.wb-right   { font-size: 48px; opacity: 0.3; }

/* SUMMARY CARDS */
.summary-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 20px;
    margin-bottom: 28px;
}

.summary-card {
    background: #fff;
    border-radius: 18px;
    padding: 22px;
    border: 1px solid #e2e8f0;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    position: relative;
    overflow: hidden;
}

.summary-card::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 4px;
}

.card-blue::before   { background: #3b82f6; }
.card-red::before    { background: #ef4444; }
.card-orange::before { background: #f97316; }

.card-icon {
    width: 46px; height: 46px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    margin-bottom: 14px;
}

.icon-blue   { background: #eff6ff; color: #3b82f6; }
.icon-red    { background: #fff1f2; color: #ef4444; }
.icon-orange { background: #fff7ed; color: #f97316; }

.card-value  { font-size: 28px; font-weight: 700; color: #1e293b; line-height: 1; margin-bottom: 4px; }
.card-label  { font-size: 12px; color: #64748b; font-weight: 500; }
.card-sub    { font-size: 11px; color: #94a3b8; margin-top: 8px; }

/* CONTENT CARDS */
.content-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin-bottom: 20px;
}

.content-card {
    background: #fff;
    border-radius: 18px;
    padding: 22px;
    border: 1px solid #e2e8f0;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
}

.card-header-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 18px;
}

.card-header-row h5 { font-size: 15px; font-weight: 700; color: #1e293b; margin: 0; }
.card-header-row p  { font-size: 11px; color: #94a3b8; margin: 3px 0 0; }

.view-all-btn {
    font-size: 12px;
    color: #243f5f;
    text-decoration: none;
    font-weight: 600;
    padding: 5px 12px;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    transition: all 0.2s;
}

.view-all-btn:hover { background: #243f5f; color: #fff; }

/* TABLES */
.mini-table { width: 100%; border-collapse: collapse; }
.mini-table th {
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    color: #94a3b8;
    padding: 0 0 10px;
    border-bottom: 1px solid #f1f5f9;
    text-align: left;
}
.mini-table td {
    font-size: 12px;
    color: #475569;
    padding: 11px 0;
    border-bottom: 1px solid #f8fafc;
    vertical-align: middle;
}
.mini-table tr:last-child td { border-bottom: none; }

/* BADGES */
.badge-stock {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 10px;
    border-radius: 999px;
    font-size: 10px;
    font-weight: 700;
}
.badge-ok  { background: #dcfce7; color: #166534; }
.badge-low { background: #fef9c3; color: #854d0e; }
.badge-out { background: #fee2e2; color: #991b1b; }

<?= maexx_notif_css() ?>
</style>
</head>
<body>

<!-- SIDEBAR — no User Management for staff -->
<div class="sidebar">
    <div class="sidebar-brand">
        <img src="img/LOGO.png" alt="MAEXX Logo">
        <div class="sidebar-brand-name">MAEXX 2 ENTERPRISES INC.</div>
        <div class="sidebar-brand-sub">Inventory & Sales Monitoring System</div>
    </div>

    <nav class="sidebar-nav">
        <div class="nav-label">Main Menu</div>

        <a href="dashboard_inventory.php" class="active">
            <i class="bi bi-grid-1x2-fill"></i> Dashboard
        </a>

        <a href="product_management.php">
            <i class="bi bi-box-seam-fill"></i> Product Monitoring
        </a>

        <a href="inventory.php">
            <i class="bi bi-boxes"></i> Inventory
            <?php if ($lowStockCount > 0): ?>
                <span class="badge-count"><?= $lowStockCount ?></span>
            <?php endif; ?>
        </a>

        <a href="sales.php">
            <i class="bi bi-cart-fill"></i> Sales
        </a>

        <a href="reports.php">
            <i class="bi bi-bar-chart-fill"></i> Reports
        </a>
        <!-- NO User Management for staff -->
    </nav>

    <div class="sidebar-footer">
        <a href="profile.php"><i class="bi bi-person-circle"></i> My Account</a>
        <a href="logout.php">
            <i class="bi bi-box-arrow-left"></i> Logout
        </a>
    </div>
</div>

<!-- MAIN WRAPPER -->
<div class="main-wrapper">

    <!-- TOPBAR -->
    <div class="topbar">
        <div class="topbar-left">
            <h4>Staff Dashboard</h4>
            <p>Your workspace for today</p>
        </div>
        <div class="topbar-right">
            <span class="topbar-date">
                <i class="bi bi-calendar3 me-1"></i>
                <?= date('F d, Y') ?>
            </span>
            <?= maexx_notif_bell() ?>
            <a href="profile.php" class="user-pill">
                <div class="user-avatar" style="overflow:hidden;">
                    <?php $av = get_user_avatar_url($user); ?>
                    <?php if ($av !== ''): ?><img src="<?= $av ?>" style="width:100%;height:100%;object-fit:cover;" alt="">
                    <?php else: ?><?= strtoupper($user['name'][0]) ?><?php endif; ?>
                </div>
                <div class="user-info">
                    <div class="name"><?= htmlspecialchars($user['name']) ?></div>
                    <div class="role">Staff</div>
                </div>
            </a>
        </div>
    </div>

    <!-- PAGE CONTENT -->
    <div class="page-content">

        <!-- WELCOME BANNER -->
        <div class="welcome-banner">
            <div class="wb-left">
                <h3>Good <?= (date('H') < 12) ? 'Morning' : (date('H') < 18 ? 'Afternoon' : 'Evening') ?>, <?= htmlspecialchars(explode(' ', $user['name'])[0]) ?>! 👋</h3>
                <p>Here's your summary for today — <?= date('l, F d, Y') ?>.</p>
            </div>
            <div class="wb-right">
                <i class="bi bi-clipboard-data"></i>
            </div>
        </div>

        <!-- SUMMARY CARDS -->
        <div class="summary-grid">
            <div class="summary-card card-blue">
                <div class="card-icon icon-blue"><i class="bi bi-currency-dollar"></i></div>
                <div class="card-value">₱<?= number_format($todaySales, 0) ?></div>
                <div class="card-label">Today's Sales</div>
                <div class="card-sub"><?= $todayCount ?> transaction(s) today</div>
            </div>
            <div class="summary-card card-red">
                <div class="card-icon icon-red"><i class="bi bi-exclamation-triangle-fill"></i></div>
                <div class="card-value"><?= $lowStockCount ?></div>
                <div class="card-label">Low Stock Items</div>
                <div class="card-sub">Needs attention</div>
            </div>
            <div class="summary-card card-orange">
                <div class="card-icon icon-orange"><i class="bi bi-x-circle-fill"></i></div>
                <div class="card-value"><?= $outOfStock ?></div>
                <div class="card-label">Out of Stock</div>
                <div class="card-sub">Zero remaining stock</div>
            </div>
        </div>

        <!-- STOCK OVERVIEW + RECENT SALES -->
        <div class="content-row">
            <div class="content-card">
                <div class="card-header-row">
                    <div>
                        <h5>Stock Level Overview</h5>
                        <p>Products needing your attention</p>
                    </div>
                    <a href="inventory.php" class="view-all-btn">View All</a>
                </div>
                <?php if (empty($lowStockProducts)): ?>
                <div style="text-align:center; padding:30px; color:#94a3b8;">
                    <i class="bi bi-check-circle-fill" style="font-size:36px; color:#22c55e; display:block; margin-bottom:10px;"></i>
                    <div style="font-size:13px; font-weight:600;">All stocks are good!</div>
                    <div style="font-size:11px; margin-top:4px;">No low stock alerts at this time.</div>
                </div>
                <?php else: ?>
                <table class="mini-table">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Stock</th>
                            <th>Min Level</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_slice($lowStockProducts, 0, 5) as $p): ?>
                        <tr>
                            <td>
                                <div style="font-weight:600; color:#1e293b; font-size:12px;"><?= htmlspecialchars($p['name']) ?></div>
                                <div style="font-size:10px; color:#94a3b8;"><?= htmlspecialchars($p['category'] ?? '') ?></div>
                            </td>
                            <td style="font-weight:700; color:#ef4444;"><?= $p['stock'] ?> <?= htmlspecialchars($p['unit']) ?></td>
                            <td style="color:#64748b;"><?= $p['threshold'] ?></td>
                            <td>
                                <?php if ($p['stock'] == 0): ?>
                                    <span class="badge-stock badge-out"><i class="bi bi-x-circle"></i> Out</span>
                                <?php else: ?>
                                    <span class="badge-stock badge-low"><i class="bi bi-exclamation-triangle"></i> Low</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>

            <div class="content-card">
                <div class="card-header-row">
                    <div>
                        <h5>Recent Sales</h5>
                        <p>Latest recorded transactions</p>
                    </div>
                    <a href="sales.php" class="view-all-btn">View All</a>
                </div>
                <?php if (empty($recentTx)): ?>
                <div style="text-align:center; padding:30px; color:#94a3b8;">
                    <i class="bi bi-receipt" style="font-size:36px; display:block; margin-bottom:10px;"></i>
                    <div style="font-size:13px; font-weight:600;">No transactions yet</div>
                </div>
                <?php else: ?>
                <table class="mini-table">
                    <thead>
                        <tr>
                            <th>Reference</th>
                            <th>Customer</th>
                            <th>Product</th>
                            <th>Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentTx as $tx): ?>
                        <tr>
                            <td style="font-family:monospace; font-size:11px; color:#243f5f; font-weight:700;"><?= htmlspecialchars($tx['reference'] ?? '—') ?></td>
                            <td><?= htmlspecialchars($tx['customer_name'] ?? '—') ?></td>
                            <td style="max-width:100px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"><?= htmlspecialchars($tx['product_name'] ?? '—') ?></td>
                            <td style="font-weight:700; color:#22c55e;">₱<?= number_format($tx['total'] ?? 0, 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>

    </div><!-- end page-content -->
</div><!-- end main-wrapper -->

<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script><?= maexx_notif_js() ?></script>
</body>
</html>