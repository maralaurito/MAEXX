<?php
/**
 * MAEXX — input validation for every form that changes data.
 *
 * Each validator returns:
 *   ['ok' => bool, 'message' => string]   plus cleaned values when ok.
 *
 * The pages repeat the simple rules as HTML attributes (maxlength, min,
 * max, pattern) for instant feedback, but these server checks are the
 * ones that count — the browser ones can be bypassed.
 *
 * Included from auth.php.
 */

// Limits shared with the HTML attributes on the forms.
const V_NAME_MAX        = 100;
const V_CATEGORY_MAX    = 50;
const V_UNIT_MAX        = 20;
const V_TEXT_MAX        = 255;
const V_DESCRIPTION_MAX = 500;
const V_REF_MAX         = 30;
const V_QTY_MAX         = 100000;
const V_MONEY_MAX       = 10000000;
const V_ROLES           = ['Administrator', 'Product Inventory'];

// ===============================================================
// Small helpers
// ===============================================================

function v_fail(string $message, string $field = ''): array
{
    return ['ok' => false, 'message' => $message, 'field' => $field];
}

/** Collapse runs of whitespace so "Plywood  ½" and "Plywood ½" compare equal. */
function v_squash(string $s): string
{
    return trim(preg_replace('/\s+/u', ' ', $s));
}

function v_len(string $s): int
{
    return function_exists('mb_strlen') ? mb_strlen($s) : strlen($s);
}

/**
 * A whole number from raw form input, or null if it is not one.
 * intval() would quietly turn "5.5" into 5 and "abc" into 0.
 */
function v_int($raw): ?int
{
    $raw = trim((string) $raw);
    return preg_match('/^\d+$/', $raw) ? intval($raw) : null;
}

/** A money amount with at most two decimals, or null. */
function v_money($raw): ?float
{
    $raw = trim((string) $raw);
    if ($raw === '') {
        return 0.0;
    }
    return preg_match('/^\d+(\.\d{1,2})?$/', $raw) ? floatval($raw) : null;
}

/**
 * A real calendar date in Y-m-d that is not in the future.
 *
 * @return array ok + 'date', or failure
 */
function v_date(string $raw, string $label, ?string $notBefore = null, string $notBeforeLabel = ''): array
{
    $raw = trim($raw);
    $d   = DateTime::createFromFormat('Y-m-d', $raw);

    if (!$d || $d->format('Y-m-d') !== $raw) {
        return v_fail("{$label} is not a valid date.", 'date');
    }

    if ($raw > date('Y-m-d')) {
        return v_fail("{$label} cannot be in the future.", 'date');
    }

    if ($notBefore !== null && $raw < $notBefore) {
        return v_fail("{$label} cannot be earlier than {$notBeforeLabel} ({$notBefore}).", 'date');
    }

    return ['ok' => true, 'message' => '', 'date' => $raw];
}

// ===============================================================
// CSRF — every form that changes data carries a token
// ===============================================================

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '">';
}

/**
 * Stop the request unless it carries this session's token. Blocks
 * another website from submitting these forms on a signed-in user's behalf.
 */
function require_valid_csrf(string $backTo): void
{
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        set_flash('Your session expired or the form was submitted from elsewhere. Please try again.', 'warning');
        header('Location: ' . $backTo);
        exit;
    }
}

// ===============================================================
// Products
// ===============================================================

function count_pending_orders_for(int $productId): int
{
    $n = 0;
    foreach (load_transactions() as $tx) {
        if (intval($tx['product_id'] ?? 0) === $productId
            && !empty($tx['reference'])
            && strcasecmp($tx['status'] ?? 'Pending', 'Pending') === 0) {
            $n++;
        }
    }
    return $n;
}

/**
 * Create / update a product.
 *
 * @param array    $in           raw $_POST
 * @param int|null $id           product being edited, null when creating
 * @param bool     $confirmLoss  user accepted a selling price below cost
 */
function validate_product_input(array $in, ?int $id, bool $confirmLoss): array
{
    $name        = v_squash($in['name'] ?? '');
    $category    = v_squash($in['category'] ?? '');
    $unit        = v_squash($in['unit'] ?? '');
    $description = trim($in['description'] ?? '');

    if ($id !== null) {
        $existing = find_product($id);
        if ($existing === null) {
            return v_fail('That product no longer exists.');
        }
        if (!empty($existing['archived'])) {
            return v_fail('Archived products cannot be edited. Restore it first.');
        }
    }

    if (v_len($name) < 2 || v_len($name) > V_NAME_MAX) {
        return v_fail('Product name must be 2–' . V_NAME_MAX . ' characters.', 'name');
    }
    if (!preg_match('/\p{L}/u', $name)) {
        return v_fail('Product name must contain letters, not just numbers or symbols.', 'name');
    }

    if (v_len($category) < 2 || v_len($category) > V_CATEGORY_MAX) {
        return v_fail('Category must be 2–' . V_CATEGORY_MAX . ' characters.', 'category');
    }

    if ($unit === '' || v_len($unit) > V_UNIT_MAX || !preg_match('/^[\p{L} .\/-]+$/u', $unit)) {
        return v_fail('Unit must be 1–' . V_UNIT_MAX . ' letters, e.g. Pcs, Kg, Bag, Roll.', 'unit');
    }

    if (v_len($description) > V_DESCRIPTION_MAX) {
        return v_fail('Description must be ' . V_DESCRIPTION_MAX . ' characters or fewer.', 'description');
    }

    $threshold = v_int($in['threshold'] ?? '');
    if ($threshold === null || $threshold < 1 || $threshold > V_QTY_MAX) {
        return v_fail('Minimum stock level must be a whole number from 1 to ' . number_format(V_QTY_MAX) . '.', 'threshold');
    }

    $cost  = v_money($in['cost'] ?? '');
    $price = v_money($in['price'] ?? '');

    if ($cost === null || $cost > V_MONEY_MAX) {
        return v_fail('Cost price must be a positive amount with at most 2 decimals.', 'cost');
    }
    if ($price === null || $price > V_MONEY_MAX) {
        return v_fail('Selling price must be a positive amount with at most 2 decimals.', 'price');
    }

    if ($price > 0 && $price < $cost && !$confirmLoss) {
        return v_fail(sprintf(
            'Selling price (₱%s) is lower than the cost (₱%s), so every sale loses ₱%s. Tick the confirmation box if this is intended.',
            number_format($price, 2), number_format($cost, 2), number_format($cost - $price, 2)
        ), 'price');
    }

    // Duplicate names confuse ordering and reports.
    $key = mb_strtolower($name);
    foreach (load_products() as $p) {
        if ($id !== null && intval($p['id']) === $id) {
            continue;
        }
        if (mb_strtolower(v_squash($p['name'] ?? '')) === $key) {
            return v_fail(!empty($p['archived'])
                ? "An archived product named \"{$p['name']}\" already exists. Restore it instead of creating a duplicate."
                : "A product named \"{$p['name']}\" already exists.", 'name');
        }
    }

    return [
        'ok' => true, 'message' => '',
        'name' => $name, 'category' => $category, 'unit' => $unit, 'description' => $description,
        'threshold' => $threshold, 'cost' => $cost, 'price' => $price,
    ];
}

function validate_product_archive(int $id): array
{
    $p = find_product($id);

    if ($p === null) {
        return v_fail('That product no longer exists.');
    }
    if (!empty($p['archived'])) {
        return v_fail("\"{$p['name']}\" is already archived.");
    }

    $pending = count_pending_orders_for($id);
    if ($pending > 0) {
        return v_fail("\"{$p['name']}\" has {$pending} pending order(s). Deliver or settle them before archiving.");
    }

    return ['ok' => true, 'message' => '', 'product' => $p];
}

function validate_product_restore(int $id): array
{
    $p = find_product($id);

    if ($p === null) {
        return v_fail('That product no longer exists.');
    }
    if (empty($p['archived'])) {
        return v_fail("\"{$p['name']}\" is not archived.");
    }

    $key = mb_strtolower(v_squash($p['name']));
    foreach (load_products() as $other) {
        if (intval($other['id']) !== $id && empty($other['archived'])
            && mb_strtolower(v_squash($other['name'] ?? '')) === $key) {
            return v_fail("An active product named \"{$other['name']}\" already exists. Rename or archive it first.");
        }
    }

    return ['ok' => true, 'message' => '', 'product' => $p];
}

// ===============================================================
// Inventory
// ===============================================================

/**
 * Checks shared by Stock In and Stock Out; the stock-level rules for
 * Stock Out live in validate_stock_out() (security.php).
 */
function validate_stock_movement(array $in, bool $isInbound): array
{
    $productId = v_int($in['product_id'] ?? '');
    $product   = $productId ? find_product($productId) : null;

    if ($product === null) {
        return v_fail('Please choose a product.', 'product_id');
    }
    if (!empty($product['archived'])) {
        return v_fail("\"{$product['name']}\" is archived. Restore it before recording stock movements.", 'product_id');
    }

    $qty = v_int($in['quantity'] ?? '');
    if ($qty === null || $qty < 1) {
        return v_fail('Quantity must be a whole number of at least 1.', 'quantity');
    }
    if ($qty > V_QTY_MAX) {
        return v_fail('Quantity cannot exceed ' . number_format(V_QTY_MAX) . ' in one entry. Check for a typo, or split it into several entries.', 'quantity');
    }

    $date = v_date($in['date'] ?? '', $isInbound ? 'Date received' : 'Date issued');
    if (!$date['ok']) {
        return $date;
    }

    if ($isInbound && v_len(trim($in['supplier'] ?? '')) > V_NAME_MAX) {
        return v_fail('Supplier name must be ' . V_NAME_MAX . ' characters or fewer.', 'supplier');
    }
    if (v_len(trim($in['remarks'] ?? '')) > V_TEXT_MAX) {
        return v_fail('Remarks must be ' . V_TEXT_MAX . ' characters or fewer.', 'remarks');
    }

    return ['ok' => true, 'message' => '', 'product' => $product, 'quantity' => $qty, 'date' => $date['date']];
}

// ===============================================================
// Sales
// ===============================================================

/** Is a PO / SI number already used on another transaction? */
function transaction_ref_taken(string $field, string $value, string $exceptReference = ''): ?array
{
    $value = mb_strtolower(trim($value));
    if ($value === '') {
        return null;
    }

    foreach (load_transactions() as $tx) {
        if ($exceptReference !== '' && ($tx['reference'] ?? '') === $exceptReference) {
            continue;
        }
        if (mb_strtolower(trim($tx[$field] ?? '')) === $value) {
            return $tx;
        }
    }
    return null;
}

/**
 * Customer order fields (stock checks are done by validate_stock_out()).
 */
function validate_order_input(array $in): array
{
    $customer = v_squash($in['customer_name'] ?? '');
    $po       = trim($in['po_number'] ?? '');
    $notes    = trim($in['notes'] ?? '');

    if (v_len($customer) < 2 || v_len($customer) > V_NAME_MAX) {
        return v_fail('Customer name must be 2–' . V_NAME_MAX . ' characters.', 'customer_name');
    }
    if (!preg_match('/\p{L}/u', $customer)) {
        return v_fail('Customer name must contain letters.', 'customer_name');
    }

    if ($po !== '') {
        if (v_len($po) > V_REF_MAX || !preg_match('/^[A-Za-z0-9\-\/ ]+$/', $po)) {
            return v_fail('PO number may only use letters, numbers, dashes and slashes (max ' . V_REF_MAX . ').', 'po_number');
        }
        if ($dup = transaction_ref_taken('po_number', $po)) {
            return v_fail("PO number {$po} is already used on order {$dup['reference']} ({$dup['customer_name']}).", 'po_number');
        }
    }

    if (v_len($notes) > V_TEXT_MAX) {
        return v_fail('Notes must be ' . V_TEXT_MAX . ' characters or fewer.', 'notes');
    }

    $productId = v_int($in['product_id'] ?? '');
    $product   = $productId ? find_product($productId) : null;

    if ($product === null) {
        return v_fail('Please choose a product.', 'product_id');
    }
    if (!empty($product['archived'])) {
        return v_fail("\"{$product['name']}\" is archived and cannot be sold.", 'product_id');
    }
    if (floatval($product['price'] ?? 0) <= 0) {
        return v_fail("\"{$product['name']}\" has no selling price yet, so the order total would be ₱0.00. Set a price in Product Monitoring first.", 'product_id');
    }

    $qty = v_int($in['quantity'] ?? '');
    if ($qty === null || $qty < 1) {
        return v_fail('Quantity must be a whole number of at least 1.', 'quantity');
    }

    return ['ok' => true, 'message' => '', 'customer' => $customer, 'po' => $po, 'notes' => $notes,
            'product' => $product, 'quantity' => $qty];
}

/**
 * Extra delivery checks on top of validate_delivery(): date and SI number.
 */
function validate_delivery_details(array $tx, string $siNumber, string $deliveryDate): array
{
    $si = trim($siNumber);

    if (v_len($si) > V_REF_MAX || !preg_match('/^[A-Za-z0-9\-\/ ]+$/', $si)) {
        return v_fail('SI number may only use letters, numbers, dashes and slashes (max ' . V_REF_MAX . ').', 'si_number');
    }
    if ($dup = transaction_ref_taken('si_number', $si, $tx['reference'] ?? '')) {
        return v_fail("SI number {$si} is already used on order {$dup['reference']}.", 'si_number');
    }

    $orderDate = substr($tx['timestamp'] ?? '', 0, 10);
    return v_date($deliveryDate, 'Delivery date', $orderDate !== '' ? $orderDate : null, 'the order date');
}

// ===============================================================
// Users
// ===============================================================

function count_active_admins(): int
{
    return count(array_filter(load_users(), fn($u) =>
        strcasecmp($u['role'] ?? '', 'Administrator') === 0
        && strcasecmp($u['status'] ?? 'active', 'active') === 0));
}

function is_current_user(array $u): bool
{
    $me = get_logged_in_user();
    return $me !== null && isset($me['id']) && intval($me['id']) === intval($u['id'] ?? 0);
}

/**
 * Create / update a user from User Management.
 *
 * @param int|null $id user being edited, null when creating
 */
function validate_user_input(array $in, ?int $id): array
{
    $fullname = v_squash($in['fullname'] ?? '');
    $username = trim($in['username'] ?? '');
    $role     = trim($in['role'] ?? '');
    $password = $in['password'] ?? '';

    $target = null;
    if ($id !== null) {
        $target = find_user_by_id($id);
        if ($target === null) {
            return v_fail('That user no longer exists.');
        }
    }

    if (v_len($fullname) < 2 || v_len($fullname) > V_NAME_MAX) {
        return v_fail('Full name must be 2–' . V_NAME_MAX . ' characters.', 'fullname');
    }
    if (!preg_match("/^[\p{L} .'\-]+$/u", $fullname)) {
        return v_fail('Full name may only contain letters, spaces, periods, apostrophes and hyphens.', 'fullname');
    }

    if (strlen($username) < 3 || strlen($username) > 50 || !preg_match('/^[A-Za-z0-9._@\-]+$/', $username)) {
        return v_fail('Username must be 3–50 characters using letters, numbers, and . _ @ - only (no spaces).', 'username');
    }
    if (username_exists($username, $id)) {
        return v_fail("Username \"{$username}\" is already taken.", 'username');
    }

    if (!in_array($role, V_ROLES, true)) {
        return v_fail('Please choose a valid role.', 'role');
    }

    if ($id === null || $password !== '') {
        $policy = validate_password_strength($password);
        if (!$policy['valid']) {
            return v_fail($policy['message'], 'password');
        }
    }

    // Guard against locking everyone out of User Management.
    if ($target !== null
        && strcasecmp($target['role'] ?? '', 'Administrator') === 0
        && $role !== 'Administrator') {

        if (is_current_user($target)) {
            return v_fail('You cannot remove your own Administrator role. Ask another administrator to do it.', 'role');
        }
        if (strcasecmp($target['status'] ?? 'active', 'active') === 0 && count_active_admins() <= 1) {
            return v_fail("{$target['name']} is the only active administrator. Promote someone else first.", 'role');
        }
    }

    return ['ok' => true, 'message' => '', 'fullname' => $fullname, 'username' => $username,
            'role' => $role, 'password' => $password];
}

function validate_user_deactivation(int $id): array
{
    $u = find_user_by_id($id);

    if ($u === null) {
        return v_fail('That user no longer exists.');
    }
    if (strcasecmp($u['status'] ?? 'active', 'active') !== 0) {
        return v_fail("{$u['name']} is already inactive.");
    }
    if (is_current_user($u)) {
        return v_fail('You cannot deactivate your own account while signed in to it.');
    }
    if (strcasecmp($u['role'] ?? '', 'Administrator') === 0 && count_active_admins() <= 1) {
        return v_fail("{$u['name']} is the only active administrator and cannot be deactivated.");
    }

    return ['ok' => true, 'message' => '', 'user' => $u];
}

function validate_user_reactivation(int $id): array
{
    $u = find_user_by_id($id);

    if ($u === null) {
        return v_fail('That user no longer exists.');
    }
    if (strcasecmp($u['status'] ?? 'active', 'active') === 0) {
        return v_fail("{$u['name']} is already active.");
    }
    return ['ok' => true, 'message' => '', 'user' => $u];
}
