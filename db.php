<?php
/**
 * MAEXX — MySQL database connection (PDO).
 *
 * Included by auth.php. Every page that requires auth.php gets
 * the $pdo connection automatically.
 */

define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'maexx2_db');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

$dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;

try {
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo '<h1>Database connection failed</h1>';
    echo '<p>Make sure MySQL is running in XAMPP and the <code>maexx2_db</code> database exists.</p>';
    echo '<p>Error: ' . htmlspecialchars($e->getMessage()) . '</p>';
    exit;
}
