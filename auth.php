<?php
session_start();

require_once __DIR__ . '/mail_config.php';
require_once __DIR__ . '/db.php';

/* ===============================================================
 * FLASH MESSAGES
 * ============================================================ */

function set_flash(string $message, string $type = 'info'): void
{
    $_SESSION['flash'] = [
        'message' => $message,
        'type' => $type,
    ];
}

function get_flash(): ?array
{
    if (empty($_SESSION['flash'])) {
        return null;
    }

    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}

/* ===============================================================
 * USER FUNCTIONS (MySQL: users table)
 * ============================================================ */

function load_users(): array
{
    global $pdo;
    return $pdo->query(
        'SELECT user_id AS id, name, username, email, password, role, status, avatar, created_at FROM users ORDER BY user_id'
    )->fetchAll();
}

function find_user(string $login): ?array
{
    global $pdo;
    $stmt = $pdo->prepare(
        'SELECT user_id AS id, name, username, email, password, role, status, avatar, created_at
         FROM users WHERE LOWER(email) = LOWER(?) OR LOWER(username) = LOWER(?) LIMIT 1'
    );
    $stmt->execute([$login, $login]);
    return $stmt->fetch() ?: null;
}

function find_user_by_id(int $id): ?array
{
    global $pdo;
    $stmt = $pdo->prepare(
        'SELECT user_id AS id, name, username, email, password, role, status, avatar, created_at
         FROM users WHERE user_id = ?'
    );
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function username_exists(string $username, ?int $excludeId = null): bool
{
    global $pdo;
    if ($excludeId !== null) {
        $stmt = $pdo->prepare('SELECT 1 FROM users WHERE LOWER(username) = LOWER(?) AND user_id != ? LIMIT 1');
        $stmt->execute([$username, $excludeId]);
    } else {
        $stmt = $pdo->prepare('SELECT 1 FROM users WHERE LOWER(username) = LOWER(?) LIMIT 1');
        $stmt->execute([$username]);
    }
    return (bool) $stmt->fetch();
}

function user_exists(string $email): bool
{
    return find_user($email) !== null;
}

function verify_user(string $email, string $password): ?array
{
    $user = find_user($email);
    if ($user === null || ($user['status'] ?? 'active') !== 'active') {
        return null;
    }
    return password_verify($password, $user['password']) ? $user : null;
}

function create_user(string $name, string $username, string $password, string $role = 'Product Inventory'): array
{
    global $pdo;
    $username = trim($username);

    if ($username === '') {
        return ['success' => false, 'message' => 'Username is required.'];
    }
    if (username_exists($username)) {
        return ['success' => false, 'message' => "Username \"{$username}\" is already taken."];
    }

    $stmt = $pdo->prepare(
        'INSERT INTO users (name, username, email, password, role) VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([$name, $username, $username, password_hash($password, PASSWORD_DEFAULT), $role]);

    add_activity_log("User created: {$name} ({$username})");
    return ['success' => true, 'message' => "User \"{$name}\" created successfully."];
}

function update_user(int $id, string $name, string $username, string $role, ?string $password = null): array
{
    global $pdo;
    $username = trim($username);

    if ($username === '') {
        return ['success' => false, 'message' => 'Username is required.'];
    }
    if (username_exists($username, $id)) {
        return ['success' => false, 'message' => "Username \"{$username}\" is already taken."];
    }

    if ($password !== null && $password !== '') {
        $stmt = $pdo->prepare('UPDATE users SET name=?, username=?, role=?, password=? WHERE user_id=?');
        $stmt->execute([$name, $username, $role, password_hash($password, PASSWORD_DEFAULT), $id]);
    } else {
        $stmt = $pdo->prepare('UPDATE users SET name=?, username=?, role=? WHERE user_id=?');
        $stmt->execute([$name, $username, $role, $id]);
    }

    if ($stmt->rowCount() === 0) {
        return ['success' => false, 'message' => 'User not found.'];
    }

    add_activity_log("User updated: {$name} ({$username})");
    return ['success' => true, 'message' => 'User updated successfully.'];
}

function set_user_status_by_id(int $id, string $status): array
{
    global $pdo;
    $stmt = $pdo->prepare('UPDATE users SET status=? WHERE user_id=?');
    $stmt->execute([$status, $id]);

    if ($stmt->rowCount() === 0) {
        return ['success' => false, 'message' => 'User not found.'];
    }

    $user = find_user_by_id($id);
    add_activity_log("User status changed for " . ($user['username'] ?? 'unknown') . " to {$status}");
    return ['success' => true, 'message' => 'User status updated.'];
}

function deactivate_user(int $id): array
{
    return set_user_status_by_id($id, 'inactive');
}

function reactivate_user(int $id): array
{
    return set_user_status_by_id($id, 'active');
}

function update_user_status(string $email, string $status): bool
{
    global $pdo;
    $stmt = $pdo->prepare('UPDATE users SET status=? WHERE LOWER(email)=LOWER(?)');
    $stmt->execute([$status, $email]);

    if ($stmt->rowCount() > 0) {
        add_activity_log("User status changed for {$email} to {$status}");
        return true;
    }
    return false;
}

function update_user_role(string $email, string $role): bool
{
    global $pdo;
    $stmt = $pdo->prepare('UPDATE users SET role=? WHERE LOWER(email)=LOWER(?)');
    $stmt->execute([$role, $email]);

    if ($stmt->rowCount() > 0) {
        add_activity_log("User role updated for {$email} to {$role}");
        return true;
    }
    return false;
}

function delete_user(string $email): bool
{
    global $pdo;
    try {
        $stmt = $pdo->prepare('DELETE FROM users WHERE LOWER(email)=LOWER(?)');
        $stmt->execute([$email]);
        if ($stmt->rowCount() > 0) {
            add_activity_log("User deleted: {$email}");
            return true;
        }
    } catch (PDOException $e) {
        // FK constraint — user has related records
    }
    return false;
}

function update_user_profile(string $email, string $name): bool
{
    global $pdo;
    $stmt = $pdo->prepare('UPDATE users SET name=? WHERE LOWER(email)=LOWER(?)');
    $stmt->execute([$name, $email]);

    if ($stmt->rowCount() > 0) {
        add_activity_log("User profile updated for {$email}");
        return true;
    }
    return false;
}

function set_user_password(string $email, string $newPassword): bool
{
    global $pdo;
    $stmt = $pdo->prepare('UPDATE users SET password=? WHERE LOWER(email)=LOWER(?)');
    $stmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), $email]);
    return $stmt->rowCount() > 0;
}

/* ===============================================================
 * AVATAR HELPERS
 * ============================================================ */

const AVATARS_DIR = __DIR__ . '/img/avatars/';

function get_user_avatar_url(array $user): string
{
    $avatar = $user['avatar'] ?? ($_SESSION['user']['avatar'] ?? '');
    if ($avatar !== '' && file_exists(__DIR__ . '/' . ltrim($avatar, '/'))) {
        return htmlspecialchars($avatar);
    }
    return '';
}

function save_user_avatar(int $id, array $fileInput): string
{
    if (!isset($fileInput['tmp_name']) || $fileInput['error'] !== UPLOAD_ERR_OK) {
        return '';
    }

    $maxBytes = 2 * 1024 * 1024;
    if ($fileInput['size'] > $maxBytes) {
        return '';
    }

    $mime = mime_content_type($fileInput['tmp_name']);
    $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if (!in_array($mime, $allowed, true)) {
        return '';
    }

    if (!is_dir(AVATARS_DIR)) {
        mkdir(AVATARS_DIR, 0755, true);
    }

    $ext  = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'][$mime];
    $name = 'avatar_' . $id . '.' . $ext;
    $dest = AVATARS_DIR . $name;

    foreach (glob(AVATARS_DIR . 'avatar_' . $id . '.*') as $old) {
        @unlink($old);
    }

    if (!move_uploaded_file($fileInput['tmp_name'], $dest)) {
        return '';
    }

    return 'img/avatars/' . $name;
}

function update_own_profile(int $id, string $name, string $username, ?string $password, string $avatar): array
{
    global $pdo;
    $name     = trim($name);
    $username = trim($username);

    if ($name === '') {
        return ['success' => false, 'message' => 'Display name is required.'];
    }
    if ($username === '') {
        return ['success' => false, 'message' => 'Username is required.'];
    }
    if (username_exists($username, $id)) {
        return ['success' => false, 'message' => "Username \"{$username}\" is already taken."];
    }
    if ($password !== null && $password !== '' && strlen($password) < 8) {
        return ['success' => false, 'message' => 'New password must be at least 8 characters.'];
    }

    $fields = ['name=?', 'username=?'];
    $params = [$name, $username];

    if ($avatar !== '') {
        $fields[] = 'avatar=?';
        $params[] = $avatar;
    }

    if ($password !== null && $password !== '') {
        $fields[] = 'password=?';
        $params[] = password_hash($password, PASSWORD_DEFAULT);
    }

    $params[] = $id;
    $stmt = $pdo->prepare('UPDATE users SET ' . implode(', ', $fields) . ' WHERE user_id=?');
    $stmt->execute($params);

    if ($stmt->rowCount() === 0) {
        return ['success' => false, 'message' => 'User not found.'];
    }

    $_SESSION['user']['name'] = $name;
    if ($avatar !== '') {
        $_SESSION['user']['avatar'] = $avatar;
    }

    add_activity_log("Profile updated: {$name} ({$username})");
    return ['success' => true, 'message' => 'Profile updated successfully.'];
}

/* ===============================================================
 * SESSION & AUTH HELPERS
 * ============================================================ */

function create_session(array $user): void
{
    $_SESSION['user'] = [
        'id'     => $user['id'] ?? null,
        'name'   => $user['name'],
        'email'  => $user['email'] ?? ($user['username'] ?? ''),
        'role'   => $user['role'],
        'avatar' => $user['avatar'] ?? '',
    ];
}

function require_login(): void
{
    if (empty($_SESSION['user'])) {
        set_flash('Please login first.', 'warning');
        header('Location: login.php');
        exit;
    }
    refresh_session_user();
}

function is_admin_role(?string $role): bool
{
    return strcasecmp((string) $role, 'Administrator') === 0;
}

function refresh_session_user(): void
{
    if (empty($_SESSION['user']['id'])) {
        return;
    }

    $fresh = find_user_by_id(intval($_SESSION['user']['id']));

    if ($fresh === null || strcasecmp($fresh['status'] ?? 'active', 'active') !== 0) {
        unset($_SESSION['user']);
        set_flash('Your account is no longer active. Please contact your administrator.', 'warning');
        header('Location: login.php');
        exit;
    }

    $_SESSION['user']['role']   = $fresh['role'];
    $_SESSION['user']['name']   = $fresh['name'];
    $_SESSION['user']['avatar'] = $fresh['avatar'] ?? '';
}

function require_role(string $expectedRole): void
{
    require_login();

    if (strcasecmp($_SESSION['user']['role'] ?? '', $expectedRole) !== 0) {
        http_response_code(403);
        echo '<h1>Access denied</h1><p>Your role does not have access to this page. <a href="' . htmlspecialchars(dashboard_for_role($_SESSION['user']['role'] ?? '')) . '">Back to dashboard</a></p>';
        exit;
    }
}

function dashboard_for_role(string $role): string
{
    return is_admin_role($role) ? 'dashboard_admin.php' : 'dashboard_inventory.php';
}

function redirect_for_role(string $role): void
{
    header('Location: ' . dashboard_for_role($role));
    exit;
}

/* ===============================================================
 * CSRF & REQUEST HELPERS
 * ============================================================ */

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_verify(?string $token): bool
{
    return !empty($_SESSION['csrf_token'])
        && is_string($token)
        && hash_equals($_SESSION['csrf_token'], $token);
}

function client_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if ($ip === '::1' || $ip === '127.0.0.1') {
        return $ip . ' (local)';
    }
    return $ip !== '' ? $ip : 'Unknown';
}

function mask_email(string $email): string
{
    $at = strpos($email, '@');
    if ($at === false) {
        return $email;
    }

    $local  = substr($email, 0, $at);
    $domain = substr($email, $at);
    $keep   = $local === '' ? '' : substr($local, 0, min(2, strlen($local)));
    return $keep . str_repeat('*', max(3, strlen($local) - strlen($keep))) . $domain;
}

function get_admin_recipients(): array
{
    global $pdo;
    $recipients = [];

    $stmt = $pdo->query("SELECT email, name FROM users WHERE role='Administrator' AND status='active'");
    foreach ($stmt as $row) {
        $email = trim($row['email']);
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $recipients[strtolower($email)] = ['email' => $email, 'name' => $row['name']];
        }
    }

    if (defined('MAIL_ADMIN_RECIPIENTS')) {
        foreach (MAIL_ADMIN_RECIPIENTS as $email) {
            $email = trim($email);
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $recipients[strtolower($email)] = ['email' => $email, 'name' => 'Administrator'];
            }
        }
    }

    return array_values($recipients);
}

/* ===============================================================
 * PASSWORD RESET (session-only, except set_user_password above)
 * ============================================================ */

function get_reset_email(): ?string
{
    return $_SESSION['reset_email'] ?? null;
}

function get_reset_user(): ?array
{
    $email = get_reset_email();
    return $email === null ? null : find_user($email);
}

function set_reset_otp(string $email): int
{
    $lifetime = defined('OTP_LIFETIME_MINUTES') ? OTP_LIFETIME_MINUTES * 60 : 600;

    $otp = random_int(100000, 999999);
    $_SESSION['reset_email']    = $email;
    $_SESSION['reset_code']     = $otp;
    $_SESSION['reset_expires']  = time() + $lifetime;
    $_SESSION['reset_sent_at']  = time();
    $_SESSION['reset_attempts'] = 0;
    $_SESSION['reset_verified'] = false;

    return $otp;
}

function reset_otp_cooldown_remaining(): int
{
    $cooldown = defined('OTP_RESEND_COOLDOWN') ? OTP_RESEND_COOLDOWN : 60;
    $sentAt   = $_SESSION['reset_sent_at'] ?? 0;
    if (!$sentAt) {
        return 0;
    }
    return max(0, ($sentAt + $cooldown) - time());
}

function get_reset_expiry(): ?int
{
    return $_SESSION['reset_expires'] ?? null;
}

function verify_reset_otp(string $code): bool
{
    if (empty($_SESSION['reset_code']) || empty($_SESSION['reset_expires'])) {
        return false;
    }
    if (time() > $_SESSION['reset_expires']) {
        clear_reset_session();
        return false;
    }

    $maxAttempts = defined('OTP_MAX_ATTEMPTS') ? OTP_MAX_ATTEMPTS : 5;
    $attempts    = intval($_SESSION['reset_attempts'] ?? 0);

    if ($attempts >= $maxAttempts) {
        clear_reset_session();
        return false;
    }

    if (hash_equals((string) $_SESSION['reset_code'], trim($code))) {
        $_SESSION['reset_verified'] = true;
        unset($_SESSION['reset_code'], $_SESSION['reset_attempts']);
        return true;
    }

    $_SESSION['reset_attempts'] = $attempts + 1;
    return false;
}

function reset_attempts_left(): int
{
    $maxAttempts = defined('OTP_MAX_ATTEMPTS') ? OTP_MAX_ATTEMPTS : 5;
    return max(0, $maxAttempts - intval($_SESSION['reset_attempts'] ?? 0));
}

function is_reset_verified(): bool
{
    return !empty($_SESSION['reset_verified']);
}

function verify_otp(string $code): bool
{
    return verify_reset_otp($code);
}

function clear_reset_session(): void
{
    unset(
        $_SESSION['reset_code'],
        $_SESSION['reset_expires'],
        $_SESSION['reset_email'],
        $_SESSION['reset_sent_at'],
        $_SESSION['reset_attempts'],
        $_SESSION['reset_verified'],
        $_SESSION['reset_fallback']
    );
}

function reset_password(string $newPassword): bool
{
    return reset_password_for_session($newPassword);
}

function reset_password_for_session(string $newPassword): bool
{
    $email = get_reset_email();
    if ($email === null) {
        return false;
    }
    $success = set_user_password($email, $newPassword);
    clear_reset_session();
    return $success;
}

function get_logged_in_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function verify_current_user_password(string $password): bool
{
    $user = get_logged_in_user();
    if (!$user) {
        return false;
    }
    $stored = find_user($user['email'] ?? $user['username'] ?? '');
    if ($stored === null) {
        return false;
    }
    return password_verify($password, $stored['password']);
}

/* ===============================================================
 * PRODUCT FUNCTIONS (MySQL: products table)
 * ============================================================ */

function load_products(): array
{
    global $pdo;
    return $pdo->query(
        'SELECT product_id AS id, name, category, unit, description, price, cost, stock, threshold, archived, created_at
         FROM products ORDER BY product_id'
    )->fetchAll();
}

function find_product(int $id): ?array
{
    global $pdo;
    $stmt = $pdo->prepare(
        'SELECT product_id AS id, name, category, unit, description, price, cost, stock, threshold, archived, created_at
         FROM products WHERE product_id = ?'
    );
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function create_product(string $name, float $price, float $cost, int $stock, int $threshold, string $category = 'General', string $unit = 'pcs', string $description = ''): bool
{
    global $pdo;
    $stmt = $pdo->prepare(
        'INSERT INTO products (name, price, cost, stock, threshold, category, unit, description) VALUES (?,?,?,?,?,?,?,?)'
    );
    $stmt->execute([$name, $price, $cost, $stock, $threshold, $category, $unit, $description]);
    add_activity_log("Product created: {$name}");
    return true;
}

function update_product(int $id, string $name, float $price, float $cost, int $threshold, string $category = 'General', string $unit = 'pcs', string $description = ''): bool
{
    global $pdo;
    $stmt = $pdo->prepare(
        'UPDATE products SET name=?, price=?, cost=?, threshold=?, category=?, unit=?, description=? WHERE product_id=?'
    );
    $stmt->execute([$name, $price, $cost, $threshold, $category, $unit, $description, $id]);

    if ($stmt->rowCount() > 0) {
        add_activity_log("Product updated: {$name}");
        return true;
    }
    return false;
}

function archive_product(int $id): bool
{
    global $pdo;
    $product = find_product($id);
    if (!$product) return false;

    $stmt = $pdo->prepare('UPDATE products SET archived=1 WHERE product_id=?');
    $stmt->execute([$id]);
    add_activity_log("Product archived: {$product['name']}");
    return true;
}

function restore_product(int $id): bool
{
    global $pdo;
    $product = find_product($id);
    if (!$product || !$product['archived']) return false;

    $stmt = $pdo->prepare('UPDATE products SET archived=0 WHERE product_id=?');
    $stmt->execute([$id]);
    add_activity_log("Product restored: {$product['name']}");
    return true;
}

function delete_product(int $id): bool
{
    global $pdo;
    $product = find_product($id);
    if (!$product) return false;

    try {
        $stmt = $pdo->prepare('DELETE FROM products WHERE product_id=?');
        $stmt->execute([$id]);
        add_activity_log("Product deleted: {$product['name']}");
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

function load_active_products(): array
{
    global $pdo;
    return $pdo->query(
        'SELECT product_id AS id, name, category, unit, description, price, cost, stock, threshold, archived, created_at
         FROM products WHERE archived=0 ORDER BY product_id'
    )->fetchAll();
}

/* ===============================================================
 * INVENTORY & FIFO (MySQL: inventory_transactions + products)
 * ============================================================ */

function adjust_stock(int $productId, int $quantity, bool $isInbound, string $date = '', string $supplier = '', string $remarks = ''): array
{
    global $pdo;

    if ($quantity <= 0) {
        return ['success' => false, 'message' => 'Quantity must be greater than zero.'];
    }

    if ($date === '') {
        $date = date('Y-m-d');
    }

    $userId = $_SESSION['user']['id'] ?? 0;

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT product_id AS id, name, stock, threshold FROM products WHERE product_id=? FOR UPDATE');
        $stmt->execute([$productId]);
        $product = $stmt->fetch();

        if (!$product) {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Product not found.'];
        }

        if (!$isInbound && $quantity > $product['stock']) {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Insufficient stock available for this product.'];
        }

        if ($isInbound) {
            $stmt = $pdo->prepare(
                'INSERT INTO inventory_transactions (product_id, type, quantity, remaining_qty, date, supplier, remarks, user_id)
                 VALUES (?, "in", ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$productId, $quantity, $quantity, $date, $supplier ?: null, $remarks ?: null, $userId]);

            $pdo->prepare('UPDATE products SET stock = stock + ? WHERE product_id = ?')
                ->execute([$quantity, $productId]);
        } else {
            // FIFO: deduct from oldest stock-in batches
            $remaining = $quantity;
            $batches = $pdo->prepare(
                'SELECT transaction_id, remaining_qty FROM inventory_transactions
                 WHERE product_id = ? AND type = "in" AND remaining_qty > 0
                 ORDER BY date ASC, transaction_id ASC'
            );
            $batches->execute([$productId]);

            while ($remaining > 0 && ($batch = $batches->fetch())) {
                $deduct = min($remaining, $batch['remaining_qty']);
                $pdo->prepare('UPDATE inventory_transactions SET remaining_qty = remaining_qty - ? WHERE transaction_id = ?')
                    ->execute([$deduct, $batch['transaction_id']]);
                $remaining -= $deduct;
            }

            $stmt = $pdo->prepare(
                'INSERT INTO inventory_transactions (product_id, type, quantity, remaining_qty, date, supplier, remarks, user_id)
                 VALUES (?, "out", ?, NULL, ?, NULL, ?, ?)'
            );
            $stmt->execute([$productId, $quantity, $date, $remarks ?: null, $userId]);

            $pdo->prepare('UPDATE products SET stock = stock - ? WHERE product_id = ?')
                ->execute([$quantity, $productId]);
        }

        $pdo->commit();

        $product = find_product($productId);
        $action = $isInbound ? 'Stock In' : 'Stock Out';
        add_activity_log("{$action} processed for {$product['name']} ({$quantity} units). Current stock: {$product['stock']}");

        $alert = $product['stock'] < $product['threshold'];
        $message = $alert
            ? 'Stock updated. Low stock alert: product is below threshold.'
            : 'Stock updated successfully.';

        return ['success' => true, 'message' => $message, 'alert' => $alert, 'product' => $product];

    } catch (PDOException $e) {
        $pdo->rollBack();
        return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
    }
}

function load_stock_logs(?int $productId = null): array
{
    global $pdo;

    $sql = 'SELECT it.transaction_id, it.product_id, it.type, it.quantity, it.remaining_qty,
                   it.date, it.supplier, it.remarks, it.user_id, it.created_at,
                   p.name AS product_name, p.unit AS product_unit,
                   u.name AS user_name
            FROM inventory_transactions it
            JOIN products p ON it.product_id = p.product_id
            LEFT JOIN users u ON it.user_id = u.user_id';

    if ($productId !== null) {
        $sql .= ' WHERE it.product_id = ?';
        $sql .= ' ORDER BY it.date ASC, it.transaction_id ASC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$productId]);
    } else {
        $sql .= ' ORDER BY it.date ASC, it.transaction_id ASC';
        $stmt = $pdo->query($sql);
    }

    return $stmt->fetchAll();
}

/* ===============================================================
 * SALES ORDERS (MySQL: sales_orders table)
 * ============================================================ */

function save_transaction(array $tx): void
{
    global $pdo;
    $userId = $_SESSION['user']['id'] ?? 0;

    $status = ucfirst(strtolower($tx['status'] ?? 'Pending'));

    $stmt = $pdo->prepare(
        'INSERT INTO sales_orders (reference, customer_name, po_number, product_id, product_name, quantity, unit, unit_price, total, status, notes, user_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $tx['reference'],
        $tx['customer_name'],
        $tx['po_number'] ?? null,
        $tx['product_id'],
        $tx['product_name'],
        $tx['quantity'],
        $tx['unit'] ?? 'pcs',
        $tx['unit_price'],
        $tx['total'],
        $status,
        $tx['notes'] ?? null,
        $userId,
    ]);
}

function update_transaction_status(string $reference, string $status, string $siNumber = '', string $deliveryDate = '', int $deliveredQty = 0): void
{
    global $pdo;
    $status = ucfirst(strtolower($status));

    $stmt = $pdo->prepare(
        'UPDATE sales_orders SET status=?, si_number=?, delivery_date=?, delivered_qty=? WHERE reference=?'
    );
    $stmt->execute([
        $status,
        $siNumber ?: null,
        $deliveryDate ?: null,
        $deliveredQty ?: null,
        $reference,
    ]);
}

function load_transactions(): array
{
    global $pdo;
    $rows = $pdo->query(
        'SELECT so.order_id AS id, so.reference, so.customer_name, so.po_number,
                so.product_id, so.product_name, so.quantity, so.unit, so.unit_price,
                so.total, so.status, so.notes, so.si_number, so.delivery_date,
                so.delivered_qty, so.delivered_qty AS delivered_quantity,
                so.user_id, so.created_at, so.created_at AS timestamp,
                u.name AS processed_by
         FROM sales_orders so
         LEFT JOIN users u ON so.user_id = u.user_id
         ORDER BY so.created_at DESC'
    )->fetchAll();

    foreach ($rows as &$row) {
        $row['type'] = 'order';
        $row['status'] = strtolower($row['status']);
    }

    return $rows;
}

function get_reserved_stock(int $productId): int
{
    // Stock is already deducted at order creation, so no additional reservation
    return 0;
}

function get_available_stock(int $productId): int
{
    $product = find_product($productId);
    if ($product === null) {
        return 0;
    }
    return max(0, intval($product['stock']) - get_reserved_stock($productId));
}

function load_pending_orders(): array
{
    global $pdo;
    $rows = $pdo->query(
        "SELECT so.order_id AS id, so.reference, so.customer_name, so.po_number,
                so.product_id, so.product_name, so.quantity, so.unit, so.unit_price,
                so.total, so.status, so.notes, so.si_number, so.delivery_date,
                so.delivered_qty, so.user_id, so.created_at, so.created_at AS timestamp,
                u.name AS processed_by
         FROM sales_orders so
         LEFT JOIN users u ON so.user_id = u.user_id
         WHERE so.status = 'Pending'
         ORDER BY so.created_at DESC"
    )->fetchAll();

    foreach ($rows as &$row) {
        $row['type'] = 'order';
        $row['status'] = strtolower($row['status']);
    }

    return $rows;
}

function mark_order_delivered(string $reference, string $siNumber, string $deliveryDate, int $deliveredQty): bool
{
    global $pdo;

    $stmt = $pdo->prepare(
        "SELECT order_id, product_id, quantity FROM sales_orders WHERE reference = ? AND status = 'Pending'"
    );
    $stmt->execute([$reference]);
    $order = $stmt->fetch();

    if (!$order) {
        return false;
    }

    $stmt = $pdo->prepare(
        "UPDATE sales_orders SET status='Delivered', si_number=?, delivery_date=?, delivered_qty=? WHERE order_id=?"
    );
    $stmt->execute([$siNumber, $deliveryDate, $deliveredQty, $order['order_id']]);

    add_activity_log("Order delivered: {$reference} ({$deliveredQty} units)");
    return true;
}

/* ===============================================================
 * ACTIVITY LOG (MySQL: activity_logs table)
 * ============================================================ */

function add_activity_log(string $message): void
{
    global $pdo;
    $user = get_logged_in_user();
    $userId = $user['id'] ?? null;
    $email  = $user['email'] ?? 'system';

    $stmt = $pdo->prepare('INSERT INTO activity_logs (user_id, user_email, message) VALUES (?, ?, ?)');
    $stmt->execute([$userId, $email, $message]);
}

/* ===============================================================
 * REPORTS
 * ============================================================ */

function get_report_summary(string $type, string $period): array
{
    if ($type === 'inventory') {
        return ['title' => 'Inventory Report', 'rows' => load_products()];
    }
    if ($type === 'sales') {
        return ['title' => 'Sales Report', 'rows' => load_transactions()];
    }
    return ['title' => 'Transaction Report', 'rows' => []];
}

function format_currency(float $value): string
{
    return '₱' . number_format($value, 2);
}

/* ===============================================================
 * UI RENDERING
 * ============================================================ */

function render_page_header(string $title, string $subtitle = ''): string
{
    global $pdo;

    $user = get_logged_in_user();
    $isAdmin = is_admin_role($user['role'] ?? '');
    $current = basename($_SERVER['SCRIPT_NAME']);
    $activeDashboard = $current === 'dashboard_admin.php' ? ' active' : '';
    $activeInventory = $current === 'inventory.php' ? ' active' : '';
    $activeProduct = $current === 'product_management.php' ? ' active' : '';
    $activeSales = $current === 'sales.php' ? ' active' : '';
    $activeReports = $current === 'reports.php' ? ' active' : '';
    $activeUsers = $current === 'user_management.php' ? ' active' : '';

    $dashboardLink = $isAdmin ? 'dashboard_admin.php' : 'dashboard_inventory.php';

    $lowStockItems = (int) $pdo->query('SELECT COUNT(*) FROM products WHERE archived=0 AND stock < threshold')->fetchColumn();

    $usersNavLink = $isAdmin
        ? "<a href=\"user_management.php\" class=\"{$activeUsers}\"><i class=\"bi bi-people-fill\"></i> User Management</a>"
        : '';

    $inventoryBadge = $lowStockItems > 0
        ? "<span class=\"badge-count\">{$lowStockItems}</span>"
        : '';

    return <<<HTML
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>{$title} - MAEXX</title>
        <link href="assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
        <link rel="stylesheet" href="assets/vendor/bootstrap-icons/bootstrap-icons.min.css">
        <link href="assets/fonts/poppins/poppins.css" rel="stylesheet">
        <link href="styles.css" rel="stylesheet">
    </head>
    <body>
        <div class="sidebar">
            <div class="sidebar-brand">
                <img src="img/LOGO.png" alt="MAEXX Logo">
                <div class="sidebar-brand-name">MAEXX 2 ENTERPRISES INC.</div>
                <div class="sidebar-brand-sub">Inventory & Sales Monitoring System</div>
            </div>

            <nav class="sidebar-nav">
                <div class="nav-label">Main Menu</div>

                <a href="{$dashboardLink}" class="{$activeDashboard}">
                    <i class="bi bi-grid-1x2-fill"></i> Dashboard
                </a>

                <a href="product_management.php" class="{$activeProduct}">
                    <i class="bi bi-box-seam-fill"></i> Product Monitoring
                </a>

                <a href="inventory.php" class="{$activeInventory}">
                    <i class="bi bi-boxes"></i> Inventory
                    {$inventoryBadge}
                </a>

                <a href="sales.php" class="{$activeSales}">
                    <i class="bi bi-cart-fill"></i> Sales
                </a>

                <a href="reports.php" class="{$activeReports}">
                    <i class="bi bi-bar-chart-fill"></i> Reports
                </a>

                <div class="nav-label">Management</div>
                {$usersNavLink}
            </nav>

            <div class="sidebar-footer">
                <a href="logout.php">
                    <i class="bi bi-box-arrow-left"></i> Logout
                </a>
            </div>
        </div>

        <div class="main-wrapper">
            <div class="topbar">
                <div class="topbar-left">
                    <h4>{$title}</h4>
                    <p>{$subtitle}</p>
                </div>
                <div class="topbar-right">
                    <span class="topbar-date">
                        <i class="bi bi-calendar3 me-1"></i>
                        {date('F d, Y')}
                    </span>
                    <div class="user-pill">
                        <div class="user-avatar">{$user['name'][0]}</div>
                        <div class="user-info">
                            <div class="name">{$user['name']}</div>
                            <div class="role">{$user['role']}</div>
                        </div>
                    </div>
                </div>
            </div>
HTML;
}

function render_page_footer(): string
{
    return '</main></body></html>';
}

require_once __DIR__ . '/security.php';
require_once __DIR__ . '/validation.php';
