<?php
/**
 * MAEXX — login throttling, signup email verification, password policy.
 *
 * Included from auth.php, so every page that requires auth.php gets
 * these helpers automatically. Uses MySQL via $pdo from db.php.
 */

// ===============================================================
// Login throttling (MySQL: login_attempts table)
// ===============================================================

function login_attempt_key(string $login): string
{
    $login = strtolower(trim($login));
    $user  = find_user($login);

    if ($user !== null) {
        return 'user:' . ($user['id'] ?? $user['username'] ?? $login);
    }

    return 'typed:' . $login;
}

function login_lockout_remaining(string $login): int
{
    global $pdo;
    $key  = login_attempt_key($login);
    $stmt = $pdo->prepare('SELECT attempt_id, locked_until, last_at FROM login_attempts WHERE identifier = ?');
    $stmt->execute([$key]);
    $entry = $stmt->fetch();

    if (!$entry) {
        return 0;
    }

    if ($entry['locked_until'] !== null) {
        $lockedUntil = strtotime($entry['locked_until']);
        if ($lockedUntil > time()) {
            return $lockedUntil - time();
        }
    }

    $window = LOGIN_ATTEMPT_WINDOW_MINUTES * 60;
    $lastAt = strtotime($entry['last_at']);

    if ($entry['locked_until'] !== null || (time() - $lastAt) > $window) {
        $pdo->prepare('DELETE FROM login_attempts WHERE attempt_id = ?')->execute([$entry['attempt_id']]);
    }

    return 0;
}

function record_login_failure(string $login): array
{
    global $pdo;
    $key  = login_attempt_key($login);
    $stmt = $pdo->prepare('SELECT attempt_id, attempt_count, last_at FROM login_attempts WHERE identifier = ?');
    $stmt->execute([$key]);
    $entry = $stmt->fetch();

    $window = LOGIN_ATTEMPT_WINDOW_MINUTES * 60;

    if ($entry && (time() - strtotime($entry['last_at'])) > $window) {
        $pdo->prepare('DELETE FROM login_attempts WHERE attempt_id = ?')->execute([$entry['attempt_id']]);
        $entry = null;
    }

    if (!$entry) {
        $stmt = $pdo->prepare(
            'INSERT INTO login_attempts (identifier, ip_address, attempt_count, first_at, last_at) VALUES (?, ?, 1, NOW(), NOW())'
        );
        $stmt->execute([$key, client_ip()]);
        $count = 1;
    } else {
        $count = $entry['attempt_count'] + 1;
        $pdo->prepare('UPDATE login_attempts SET attempt_count = ?, last_at = NOW(), ip_address = ? WHERE attempt_id = ?')
            ->execute([$count, client_ip(), $entry['attempt_id']]);
    }

    $locked      = false;
    $lockSeconds = 0;

    if ($count >= LOGIN_MAX_ATTEMPTS) {
        $lockSeconds = LOGIN_LOCKOUT_MINUTES * 60;
        $lockedUntil = date('Y-m-d H:i:s', time() + $lockSeconds);

        $pdo->prepare('UPDATE login_attempts SET locked_until = ?, attempt_count = 0 WHERE identifier = ?')
            ->execute([$lockedUntil, $key]);
        $locked = true;
        $count  = 0;

        $who = find_user($login);
        $who = $who ? "{$who['name']} (@{$who['username']})" : 'unknown login "' . login_attempt_key_label($key) . '"';
        add_activity_log('Account locked after ' . LOGIN_MAX_ATTEMPTS . " failed sign-in attempts: {$who}");
    }

    return [
        'attempts_left' => $locked ? 0 : max(0, LOGIN_MAX_ATTEMPTS - $count),
        'locked'        => $locked,
        'lock_seconds'  => $lockSeconds,
    ];
}

function login_attempt_key_label(string $key): string
{
    return preg_replace('/^(typed|user):/', '', $key);
}

function clear_login_failures(string $login): void
{
    global $pdo;
    $key = login_attempt_key($login);
    $pdo->prepare('DELETE FROM login_attempts WHERE identifier = ?')->execute([$key]);
}

function format_duration(int $seconds): string
{
    if ($seconds >= 60) {
        $minutes = (int) ceil($seconds / 60);
        return $minutes . ' minute' . ($minutes === 1 ? '' : 's');
    }

    $seconds = max(1, $seconds);
    return $seconds . ' second' . ($seconds === 1 ? '' : 's');
}

// ===============================================================
// Password policy
// ===============================================================

function validate_password_strength(string $password, ?string $confirm = null): array
{
    if (strlen($password) < 8) {
        return ['valid' => false, 'message' => 'Password must be at least 8 characters.'];
    }

    if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
        return ['valid' => false, 'message' => 'Password must contain at least one letter and one number.'];
    }

    if ($confirm !== null && $password !== $confirm) {
        return ['valid' => false, 'message' => 'Passwords do not match.'];
    }

    return ['valid' => true, 'message' => 'Password looks good.'];
}

// ===============================================================
// Signup email verification
// ===============================================================

function start_signup_verification(string $name, string $email, string $password): string
{
    $otp = (string) random_int(100000, 999999);

    $_SESSION['signup'] = [
        'name'          => $name,
        'email'         => $email,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'code'          => $otp,
        'expires'       => time() + (OTP_LIFETIME_MINUTES * 60),
        'sent_at'       => time(),
        'attempts'      => 0,
    ];

    return $otp;
}

function get_pending_signup(): ?array
{
    return $_SESSION['signup'] ?? null;
}

function clear_pending_signup(): void
{
    unset($_SESSION['signup']);
}

function signup_resend_cooldown(): int
{
    $pending = get_pending_signup();
    if ($pending === null) {
        return 0;
    }
    return max(0, (intval($pending['sent_at']) + OTP_RESEND_COOLDOWN) - time());
}

function refresh_signup_otp(): ?string
{
    if (get_pending_signup() === null) {
        return null;
    }

    $otp = (string) random_int(100000, 999999);

    $_SESSION['signup']['code']     = $otp;
    $_SESSION['signup']['expires']  = time() + (OTP_LIFETIME_MINUTES * 60);
    $_SESSION['signup']['sent_at']  = time();
    $_SESSION['signup']['attempts'] = 0;

    return $otp;
}

function signup_attempts_left(): int
{
    $pending = get_pending_signup();
    if ($pending === null) {
        return 0;
    }
    return max(0, OTP_MAX_ATTEMPTS - intval($pending['attempts'] ?? 0));
}

function verify_signup_otp(string $code): array
{
    $pending = get_pending_signup();

    if ($pending === null) {
        return ['status' => 'missing', 'message' => 'Your signup session expired. Please register again.'];
    }

    if (time() > intval($pending['expires'])) {
        clear_pending_signup();
        return ['status' => 'expired', 'message' => 'This code expired. Please register again to get a new one.'];
    }

    if (intval($pending['attempts'] ?? 0) >= OTP_MAX_ATTEMPTS) {
        clear_pending_signup();
        return ['status' => 'locked', 'message' => 'Too many incorrect codes. Please register again.'];
    }

    if (!hash_equals((string) $pending['code'], trim($code))) {
        $_SESSION['signup']['attempts'] = intval($pending['attempts'] ?? 0) + 1;
        $left = signup_attempts_left();

        if ($left === 0) {
            clear_pending_signup();
            return ['status' => 'locked', 'message' => 'Too many incorrect codes. Please register again.'];
        }

        return ['status' => 'invalid', 'message' => "Incorrect code. {$left} attempt(s) remaining."];
    }

    return ['status' => 'ok', 'message' => 'Email verified.'];
}

function create_user_from_pending_signup(): array
{
    global $pdo;
    $pending = get_pending_signup();

    if ($pending === null) {
        return ['success' => false, 'message' => 'Your signup session expired. Please register again.'];
    }

    if (user_exists($pending['email'])) {
        clear_pending_signup();
        return ['success' => false, 'message' => 'This email was registered while you were verifying. Please login instead.'];
    }

    $stmt = $pdo->prepare(
        'INSERT INTO users (name, username, email, password, role) VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $pending['name'],
        $pending['email'],
        $pending['email'],
        $pending['password_hash'],
        'Product Inventory',
    ]);

    add_activity_log("Account created after email verification: {$pending['name']} ({$pending['email']})");

    $created = find_user($pending['email']);
    clear_pending_signup();

    return ['success' => true, 'message' => 'Account created successfully.', 'user' => $created];
}

// ===============================================================
// Stock-out validation (Inventory stock out + Sales orders)
// ===============================================================

function validate_stock_out(int $productId, int $quantity, bool $confirmedLow): array
{
    $product = find_product($productId);

    if ($product === null) {
        return ['ok' => false, 'state' => 'missing', 'message' => 'Product not found.'];
    }

    if ($quantity <= 0) {
        return ['ok' => false, 'state' => 'invalid', 'message' => 'Please enter a quantity greater than zero.'];
    }

    $stock     = intval($product['stock']);
    $threshold = intval($product['threshold']);
    $unit      = $product['unit'] ?? 'pcs';
    $name      = $product['name'];
    $remaining = $stock - $quantity;

    if ($quantity > $stock) {
        return [
            'ok'      => false,
            'state'   => 'over',
            'message' => $stock === 0
                ? "{$name} is out of stock. Restock it before issuing or selling."
                : "Not enough stock for {$name}: only {$stock} {$unit} on hand, but {$quantity} {$unit} was requested ("
                  . ($quantity - $stock) . " {$unit} too many).",
        ];
    }

    if ($remaining === 0 && !$confirmedLow) {
        return [
            'ok'      => false,
            'state'   => 'empty',
            'message' => "This would use up all remaining stock of {$name}. Tick the confirmation box to proceed.",
        ];
    }

    if ($remaining < $threshold && !$confirmedLow) {
        return [
            'ok'      => false,
            'state'   => 'below',
            'message' => "This would leave only {$remaining} {$unit} of {$name}, below the minimum of {$threshold} {$unit}. "
                       . 'Tick the confirmation box to proceed.',
        ];
    }

    return ['ok' => true, 'state' => $remaining < $threshold ? 'below' : 'ok', 'message' => ''];
}

function validate_delivery(string $reference, int $deliveredQty, string $siNumber, bool $confirmedShort): array
{
    if ($reference === '') {
        return ['ok' => false, 'state' => 'missing',
            'message' => 'This order has no reference number, so it cannot be marked as delivered.'];
    }

    $tx = null;
    foreach (load_transactions() as $row) {
        if (($row['reference'] ?? '') === $reference) {
            $tx = $row;
            break;
        }
    }

    if ($tx === null) {
        return ['ok' => false, 'state' => 'missing', 'message' => "Order {$reference} was not found."];
    }

    if (strcasecmp($tx['status'] ?? 'Pending', 'Pending') !== 0) {
        return ['ok' => false, 'state' => 'done',
            'message' => "Order {$reference} is already marked as " . ($tx['status'] ?? 'processed') . '.'];
    }

    if (trim($siNumber) === '') {
        return ['ok' => false, 'state' => 'invalid', 'message' => 'Please enter the SI number for this delivery.'];
    }

    $ordered = intval($tx['quantity'] ?? 0);
    $unit    = $tx['unit'] ?? 'pcs';

    if ($deliveredQty <= 0) {
        return ['ok' => false, 'state' => 'invalid', 'message' => 'Delivered quantity must be at least 1.'];
    }

    if ($deliveredQty > $ordered) {
        return ['ok' => false, 'state' => 'over',
            'message' => "Cannot deliver {$deliveredQty} {$unit}: order {$reference} is for only {$ordered} {$unit}. "
                       . 'Record a separate order for the extra ' . ($deliveredQty - $ordered) . " {$unit}."];
    }

    if ($deliveredQty < $ordered && !$confirmedShort) {
        return ['ok' => false, 'state' => 'short',
            'message' => "Only {$deliveredQty} of {$ordered} {$unit} delivered. Tick the confirmation box to record a partial delivery."];
    }

    return [
        'ok'        => true,
        'state'     => $deliveredQty < $ordered ? 'short' : 'ok',
        'message'   => '',
        'tx'        => $tx,
        'shortfall' => $ordered - $deliveredQty,
    ];
}

function json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

