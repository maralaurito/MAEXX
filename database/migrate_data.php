<?php
/**
 * MAEXX — One-time migration: JSON flat files → MySQL (maexx2_db).
 *
 * Run once from CLI:   php database/migrate_data.php
 * Or open in browser:  http://localhost/MAEXX/database/migrate_data.php
 */

require_once __DIR__ . '/../db.php';

header('Content-Type: text/plain; charset=utf-8');

$base = __DIR__ . '/..';

// ─── USERS ────────────────────────────────────────────────
$file = $base . '/users.json';
if (file_exists($file)) {
    $users = json_decode(file_get_contents($file), true) ?: [];
    $stmt = $pdo->prepare(
        'INSERT INTO users (user_id, name, username, email, password, role, status, avatar, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );

    $count = 0;
    foreach ($users as $u) {
        try {
            $stmt->execute([
                $u['id'],
                $u['name'],
                $u['username'],
                $u['email'] ?? $u['username'],
                $u['password'],
                $u['role'] ?? 'Product Inventory',
                $u['status'] ?? 'active',
                $u['avatar'] ?? null,
                $u['created_at'] ?? date('Y-m-d'),
            ]);
            $count++;
        } catch (PDOException $e) {
            echo "  SKIP user {$u['username']}: {$e->getMessage()}\n";
        }
    }
    echo "Users: {$count} migrated\n";
} else {
    echo "Users: users.json not found, skipped\n";
}

// ─── PRODUCTS ─────────────────────────────────────────────
$file = $base . '/products.json';
if (file_exists($file)) {
    $products = json_decode(file_get_contents($file), true) ?: [];
    $stmt = $pdo->prepare(
        'INSERT INTO products (product_id, name, category, unit, description, price, cost, stock, threshold, archived)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );

    $count = 0;
    foreach ($products as $p) {
        try {
            $stmt->execute([
                $p['id'],
                $p['name'],
                $p['category'] ?? 'General',
                $p['unit'] ?? 'pcs',
                $p['description'] ?? '',
                $p['price'] ?? 0,
                $p['cost'] ?? 0,
                $p['stock'] ?? 0,
                $p['threshold'] ?? 0,
                !empty($p['archived']) ? 1 : 0,
            ]);
            $count++;
        } catch (PDOException $e) {
            echo "  SKIP product {$p['name']}: {$e->getMessage()}\n";
        }
    }
    echo "Products: {$count} migrated\n";

    // Create initial stock-in batches for existing stock (FIFO baseline)
    $batchStmt = $pdo->prepare(
        'INSERT INTO inventory_transactions (product_id, type, quantity, remaining_qty, date, remarks, user_id)
         VALUES (?, "in", ?, ?, ?, "Initial stock (migrated from JSON)", 1)'
    );
    $batchCount = 0;
    foreach ($products as $p) {
        $stock = intval($p['stock'] ?? 0);
        if ($stock > 0) {
            try {
                $batchStmt->execute([
                    $p['id'],
                    $stock,
                    $stock,
                    date('Y-m-d'),
                ]);
                $batchCount++;
            } catch (PDOException $e) {
                echo "  SKIP stock batch for {$p['name']}: {$e->getMessage()}\n";
            }
        }
    }
    echo "Stock batches: {$batchCount} created (FIFO baseline)\n";
} else {
    echo "Products: products.json not found, skipped\n";
}

// ─── SALES ORDERS (from transactions.json) ────────────────
$file = $base . '/transactions.json';
if (file_exists($file)) {
    $transactions = json_decode(file_get_contents($file), true) ?: [];
    $stmt = $pdo->prepare(
        'INSERT INTO sales_orders (reference, customer_name, po_number, product_id, product_name, quantity, unit, unit_price, total, status, notes, si_number, delivery_date, delivered_qty, user_id, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );

    $count = 0;
    foreach ($transactions as $tx) {
        if (($tx['type'] ?? '') !== 'order') continue;

        $status = ucfirst(strtolower($tx['status'] ?? 'Pending'));
        $userId = $tx['user_id'] ?? 1;
        $created = $tx['timestamp'] ?? ($tx['created_at'] ?? date('Y-m-d H:i:s'));

        try {
            $stmt->execute([
                $tx['reference'],
                $tx['customer_name'] ?? '',
                $tx['po_number'] ?? null,
                $tx['product_id'],
                $tx['product_name'] ?? '',
                $tx['quantity'],
                $tx['unit'] ?? 'pcs',
                $tx['unit_price'] ?? 0,
                $tx['total'] ?? 0,
                $status,
                $tx['notes'] ?? null,
                $tx['si_number'] ?? null,
                !empty($tx['delivery_date']) ? $tx['delivery_date'] : null,
                $tx['delivered_qty'] ?? ($tx['delivered_quantity'] ?? null),
                $userId,
                $created,
            ]);
            $count++;
        } catch (PDOException $e) {
            echo "  SKIP order {$tx['reference']}: {$e->getMessage()}\n";
        }
    }
    echo "Sales orders: {$count} migrated\n";
} else {
    echo "Transactions: transactions.json not found, skipped\n";
}

// ─── ACTIVITY LOGS ────────────────────────────────────────
$file = $base . '/activity_logs.json';
if (file_exists($file)) {
    $logs = json_decode(file_get_contents($file), true) ?: [];
    $stmt = $pdo->prepare(
        'INSERT INTO activity_logs (user_email, message, created_at) VALUES (?, ?, ?)'
    );

    $count = 0;
    foreach ($logs as $log) {
        try {
            $stmt->execute([
                $log['user'] ?? 'system',
                $log['message'] ?? '',
                $log['timestamp'] ?? date('Y-m-d H:i:s'),
            ]);
            $count++;
        } catch (PDOException $e) {
            echo "  SKIP log: {$e->getMessage()}\n";
        }
    }
    echo "Activity logs: {$count} migrated\n";
} else {
    echo "Activity logs: activity_logs.json not found, skipped\n";
}

echo "\nMigration complete!\n";
