<?php
/**
 * Shared notification bell component for the topbar.
 *
 * Usage: include this file, then call:
 *   maexx_notif_css()  — inside <style>
 *   maexx_notif_bell() — inside .topbar-right, before the user-pill
 *   maexx_notif_js()   — before </body>
 */

function maexx_get_notifications(): array
{
    $notifs = [];
    $products = load_products();
    $active   = array_filter($products, fn($p) => empty($p['archived']));

    $outOfStock = array_filter($active, fn($p) => intval($p['stock']) === 0);
    foreach ($outOfStock as $p) {
        $notifs[] = [
            'icon'      => 'bi-x-circle-fill',
            'iconClass' => 'nb-ico-red',
            'title'     => htmlspecialchars($p['name']),
            'desc'      => 'Out of stock — needs immediate restock',
            'time'      => 'Stock: 0 ' . htmlspecialchars($p['unit'] ?? 'pcs'),
            'link'      => 'inventory.php',
        ];
    }

    $lowStock = array_filter($active, fn($p) => intval($p['stock']) > 0 && intval($p['stock']) < intval($p['threshold']));
    foreach ($lowStock as $p) {
        $notifs[] = [
            'icon'      => 'bi-exclamation-triangle-fill',
            'iconClass' => 'nb-ico-yellow',
            'title'     => htmlspecialchars($p['name']),
            'desc'      => 'Low stock — below minimum level (' . intval($p['threshold']) . ')',
            'time'      => 'Stock: ' . intval($p['stock']) . ' ' . htmlspecialchars($p['unit'] ?? 'pcs'),
            'link'      => 'inventory.php',
        ];
    }

    if (function_exists('load_transactions')) {
        $transactions = load_transactions();
        $pending = array_filter($transactions, fn($t) => ($t['status'] ?? 'Pending') === 'Pending');
        foreach ($pending as $t) {
            $notifs[] = [
                'icon'      => 'bi-hourglass-split',
                'iconClass' => 'nb-ico-blue',
                'title'     => 'Pending: ' . htmlspecialchars($t['reference'] ?? 'Order'),
                'desc'      => htmlspecialchars(($t['customer_name'] ?? 'Customer') . ' — ' . ($t['product_name'] ?? 'Product')),
                'time'      => htmlspecialchars(substr($t['timestamp'] ?? '', 0, 10)),
                'link'      => 'sales.php',
            ];
        }
    }

    return $notifs;
}

function maexx_notif_css(): string
{
    return <<<'CSS'
/* ===== NOTIFICATION BELL ===== */
.nb-wrap { position: relative; }
.nb-btn {
    width: 40px; height: 40px;
    border-radius: 12px; border: 1.5px solid #e2e8f0;
    background: #f8fafc; color: #64748b;
    display: flex; align-items: center; justify-content: center;
    font-size: 18px; cursor: pointer;
    transition: all 0.2s; position: relative;
}
.nb-btn:hover { background: #e2e8f0; color: #243f5f; }
.nb-btn.has-notifs { color: #243f5f; }
.nb-badge {
    position: absolute; top: -4px; right: -4px;
    background: #ef4444; color: #fff;
    font-size: 9px; font-weight: 700;
    min-width: 18px; height: 18px;
    border-radius: 999px; display: flex;
    align-items: center; justify-content: center;
    padding: 0 4px; border: 2px solid #fff;
    line-height: 1;
}
.nb-dropdown {
    display: none; position: absolute;
    top: calc(100% + 8px); right: 0;
    width: 360px; max-height: 420px;
    background: #fff; border-radius: 16px;
    border: 1px solid #e2e8f0;
    box-shadow: 0 10px 40px rgba(0,0,0,0.12);
    z-index: 999; overflow: hidden;
}
.nb-dropdown.show { display: block; }
.nb-head {
    padding: 14px 18px;
    border-bottom: 1px solid #f1f5f9;
    display: flex; justify-content: space-between;
    align-items: center;
}
.nb-head h6 {
    font-size: 14px; font-weight: 700;
    color: #1e293b; margin: 0;
}
.nb-head .nb-count {
    background: #243f5f; color: #fff;
    font-size: 10px; font-weight: 700;
    padding: 2px 8px; border-radius: 999px;
}
.nb-list {
    max-height: 320px; overflow-y: auto;
    padding: 6px 0;
}
.nb-row {
    display: flex; gap: 12px; padding: 10px 18px;
    text-decoration: none; color: inherit;
    transition: background 0.15s; cursor: pointer;
    border: none; border-bottom: 1px solid #f1f5f9;
    border-left: none; border-radius: 0;
    margin: 0; background: transparent;
}
.nb-row:hover { background: #f8fafc; }
.nb-row:last-child { border-bottom: none; }
.nb-row-icon {
    width: 34px; height: 34px; flex-shrink: 0;
    border-radius: 10px; display: flex;
    align-items: center; justify-content: center;
    font-size: 14px;
}
.nb-ico-red    { background: #fff1f2; color: #ef4444; }
.nb-ico-yellow { background: #fefce8; color: #ca8a04; }
.nb-ico-blue   { background: #eff6ff; color: #3b82f6; }
.nb-ico-green  { background: #f0fdf4; color: #22c55e; }
.nb-row-body { flex: 1; min-width: 0; }
.nb-row-title {
    font-size: 12px; font-weight: 600;
    color: #1e293b; line-height: 1.3;
    white-space: nowrap; overflow: hidden;
    text-overflow: ellipsis;
}
.nb-row-desc {
    font-size: 11px; color: #64748b;
    margin-top: 2px; line-height: 1.3;
    white-space: nowrap; overflow: hidden;
    text-overflow: ellipsis;
}
.nb-row-meta {
    font-size: 10px; color: #94a3b8;
    margin-top: 3px;
}
.nb-empty {
    text-align: center; padding: 30px 20px;
    color: #94a3b8;
}
.nb-empty i { font-size: 32px; display: block; margin-bottom: 8px; }
.nb-empty .nb-empty-title { font-size: 13px; font-weight: 600; color: #64748b; }
.nb-foot {
    padding: 10px 18px; border-top: 1px solid #f1f5f9;
    text-align: center;
}
.nb-foot a {
    font-size: 12px; font-weight: 600;
    color: #243f5f; text-decoration: none;
}
.nb-foot a:hover { text-decoration: underline; }

@media print { .nb-wrap { display: none !important; } }
CSS;
}

function maexx_notif_bell(): string
{
    $notifs = maexx_get_notifications();
    $count  = count($notifs);

    $html = '<div class="nb-wrap">';
    $html .= '<button class="nb-btn' . ($count > 0 ? ' has-notifs' : '') . '" onclick="toggleNotifs()" title="Notifications">';
    $html .= '<i class="bi bi-bell-fill"></i>';
    if ($count > 0) {
        $html .= '<span class="nb-badge">' . ($count > 99 ? '99+' : $count) . '</span>';
    }
    $html .= '</button>';

    $html .= '<div class="nb-dropdown" id="notifDropdown">';
    $html .= '<div class="nb-head">';
    $html .= '<h6>Notifications</h6>';
    if ($count > 0) {
        $html .= '<span class="nb-count">' . $count . ' alert' . ($count !== 1 ? 's' : '') . '</span>';
    }
    $html .= '</div>';

    $html .= '<div class="nb-list">';
    if ($count === 0) {
        $html .= '<div class="nb-empty">';
        $html .= '<i class="bi bi-bell"></i>';
        $html .= '<div class="nb-empty-title">All clear!</div>';
        $html .= '</div>';
    } else {
        $shown = array_slice($notifs, 0, 15);
        foreach ($shown as $n) {
            $html .= '<a class="nb-row" href="' . $n['link'] . '">';
            $html .= '<div class="nb-row-icon ' . $n['iconClass'] . '"><i class="bi ' . $n['icon'] . '"></i></div>';
            $html .= '<div class="nb-row-body">';
            $html .= '<div class="nb-row-title">' . $n['title'] . '</div>';
            $html .= '<div class="nb-row-desc">' . $n['desc'] . '</div>';
            $html .= '<div class="nb-row-meta">' . $n['time'] . '</div>';
            $html .= '</div>';
            $html .= '</a>';
        }
    }
    $html .= '</div>';

    if ($count > 15) {
        $html .= '<div class="nb-foot"><a href="inventory.php">View all ' . $count . ' alerts</a></div>';
    }

    $html .= '</div>';
    $html .= '</div>';

    return $html;
}

function maexx_notif_js(): string
{
    return <<<'JS'
function toggleNotifs() {
    var dd = document.getElementById('notifDropdown');
    dd.classList.toggle('show');
}
document.addEventListener('click', function(e) {
    var wrap = document.querySelector('.nb-wrap');
    var dd = document.getElementById('notifDropdown');
    if (dd && wrap && !wrap.contains(e.target)) {
        dd.classList.remove('show');
    }
});
JS;
}
