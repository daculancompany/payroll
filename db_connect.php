<?php
/* ──────────────────────────────────────────────────────────────
 * Central error-reporting switch.
 * Change APP_ENV to 'prod' before deploying to a live server.
 *   dev  → show every error on screen (for debugging)
 *   prod → never show errors to users; log them to logs/php-error.log
 * ────────────────────────────────────────────────────────────── */
if (!defined('APP_ENV')) {
    define('APP_ENV', 'dev');   // 'dev' | 'prod'
}

// Application timezone — used by all PHP date()/DateTime calls.
date_default_timezone_set('Asia/Manila');

// ── Leave eligibility (GLOBAL) ──────────────────────────────────────────
// Only these employee classifications are entitled to leave / leave credits.
// Matched by NAME (case-insensitive) so it works regardless of table IDs.
// Change this ONE line to adjust who can have leave app-wide.
if (!defined('LEAVE_ELIGIBLE_CLASSIFICATIONS')) {
    define('LEAVE_ELIGIBLE_CLASSIFICATIONS', ['REGULAR', 'EXECUTIVE']);
}

// ── Payroll exclusions (GLOBAL) ─────────────────────────────────────────
// Employees with these classifications are SKIPPED when calculating payroll.
// Matched by NAME (case-insensitive). Change this ONE line to adjust.
if (!defined('PAYROLL_EXCLUDED_CLASSIFICATIONS')) {
    define('PAYROLL_EXCLUDED_CLASSIFICATIONS', ['INTERM', 'INTERN']);
}

// ── Classification badge colors (GLOBAL) ────────────────────────────────
// One source of truth for classification badge colors across all pages.
// Returns an inline style string. Edit the map to recolor app-wide.
if (!function_exists('clasif_badge_style')) {
    function clasif_badge_style($name)
    {
        $map = [
            'REGULAR'      => '#198754', // green
            'EXECUTIVE'    => '#6f42c1', // purple
            'INTERM'       => '#dc3545', // red
            'INTERN'       => '#dc3545', // red
            'PROBATIONARY' => '#fd7e14', // orange
            'CONTRACTUAL'  => '#0d6efd', // blue
            'TEMPORARY'    => '#6c757d', // gray
            'CONSULTANTS'  => '#20c997', // teal-green
            'ON-CALL'      => '#0dcaf0', // cyan
            'FULLTIME'     => '#198754', // green
        ];
        $color = $map[strtoupper(trim((string) $name))] ?? '#009688';
        return 'background:' . $color . ';color:#fff;';
    }
}

error_reporting(E_ALL);
if (APP_ENV === 'prod') {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    $__logdir = __DIR__ . '/logs';
    if (!is_dir($__logdir)) { @mkdir($__logdir, 0775, true); }
    ini_set('error_log', $__logdir . '/php-error.log');
} else {
    ini_set('display_errors', '1');
}

$servername = "localhost";
$username = "root";
$password = "";
$dbname = "payroll";

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Optionally, set charset
$conn->set_charset("utf8mb4");

// Align MySQL session time so NOW()/CURDATE() use Manila time (UTC+08:00).
$conn->query("SET time_zone = '+08:00'");

// Return connection object
return $conn;
?>