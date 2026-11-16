<?php
/**
 * Database + application configuration.
 * Default values match a fresh XAMPP install (user "root", empty password).
 */

define('DB_HOST', 'localhost');
define('DB_NAME', 'financial_tracker');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

define('APP_NAME', 'PeraCheck!');
define('APP_TIMEZONE', 'Asia/Manila');
define('SESSION_TIMEOUT', 7200); // seconds of inactivity before auto logout

date_default_timezone_set(APP_TIMEZONE);

/**
 * Web path of the project folder, e.g. "/financial-expense-tracker".
 * Detected automatically; set manually here if you use a virtual host.
 */
if (!defined('BASE_URL')) {
    $root = str_replace('\\', '/', (string) realpath(__DIR__ . '/..'));
    $doc  = str_replace('\\', '/', (string) realpath($_SERVER['DOCUMENT_ROOT'] ?? ''));
    $base = '';
    if ($doc !== '' && stripos($root, $doc) === 0) {
        $base = substr($root, strlen($doc));
    }
    define('BASE_URL', rtrim($base, '/'));
}

/** Shared PDO connection (prepared statements, exceptions, no emulation). */
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $offset = (new DateTime('now', new DateTimeZone(APP_TIMEZONE)))->format('P');
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '" . $offset . "'",
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            exit('<h3>Database connection failed.</h3><p>Start MySQL in XAMPP, import '
                . '<code>database/financial_tracker.sql</code> and check <code>config/database.php</code>.</p>');
        }
    }
    return $pdo;
}
